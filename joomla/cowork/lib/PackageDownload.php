<?php
/**
 * PackageDownload — downloads a package straight to a file, a chunk at a time, and hashes it from
 * that file.
 *
 * Two callers, one copy: the self-updater's worker (plg_system_claudecoworkupdate, which requires
 * this file from the component's lib/ the way it requires LoopbackRoute) and the API door's
 * `extension.install` (JoomlaExtensions::installFromUrl). Both used to take the whole body into
 * memory; the door moved here in 0.24.29, after the updater in 0.24.28.
 *
 * ## Why not InstallerHelper::downloadPackage
 *
 * Joomla's helper asks its HTTP client for the whole body as one string and then writes that
 * string out. The package is about 42 MB, and on its way through laminas' Stream::getContents it
 * exists more than once in memory: measured 14/10/2026 on 20 of 24 fleet demos, 61 times in a day,
 * "Allowed memory size of 134217728 bytes exhausted (tried to allocate 42582440 bytes)". That is a
 * fatal error, which no try/catch sees, so the update never finished and the next try failed the
 * same way. Here the bytes go from the socket to the disk through curl's own buffer (or an 8 KiB
 * stream copy without curl), so memory stays flat whatever the package weighs.
 *
 * Plain PHP with no CMS and no namespace, like the rest of lib/, so tests/updater-out-of-request.php
 * and tests/extension-install-streaming.php run it on their own.
 */
final class PackageDownload
{
    /** Bytes copied per step when curl is not there. */
    public const CHUNK = 8192;

    /**
     * Fetch $url into $target. Never throws: every outcome is in the answer.
     *
     * @param array<int,mixed> $curlOptions extra curl options (the site's proxy), applied last
     * @param bool|null        $useCurl     null: curl when this PHP has it; false: the stream copy
     *
     * @return array{ok:bool, bytes:int, sha256:?string, error:?string}
     */
    public static function toFile(string $url, string $target, int $maxBytes, ?string $sha256 = null, array $curlOptions = [], ?bool $useCurl = null): array
    {
        $useCurl = $useCurl ?? \function_exists('curl_init');
        $out     = @fopen($target, 'wb');

        if ($out === false) {
            return self::fail($target, 'cannot write ' . $target);
        }

        $error = $useCurl ? self::viaCurl($url, $out, $maxBytes, $curlOptions) : self::viaStream($url, $out, $maxBytes);
        fclose($out);

        if ($error !== null) {
            return self::fail($target, $error);
        }

        $bytes = (int) filesize($target);

        if ($bytes === 0) {
            return self::fail($target, 'the download was empty');
        }

        if ($bytes > $maxBytes) {
            return self::fail($target, 'the package is larger than ' . $maxBytes . ' bytes');
        }

        // Hashed from the file, which reads it in blocks: the whole point is never holding it.
        $hash = hash_file('sha256', $target);

        if ($sha256 !== null && $sha256 !== '' && !hash_equals(strtolower($sha256), (string) $hash)) {
            return self::fail($target, 'sha256 ' . $hash . ' is not the announced ' . strtolower($sha256));
        }

        return ['ok' => true, 'bytes' => $bytes, 'sha256' => $hash, 'error' => null];
    }

    /** @param resource $out */
    private static function viaCurl(string $url, $out, int $maxBytes, array $curlOptions): ?string
    {
        $ch = curl_init($url);
        $options = [
            // CURLOPT_FILE is the point: curl writes each block it receives to the open file.
            CURLOPT_FILE           => $out,
            CURLOPT_FOLLOWLOCATION => true, // a release asset answers with a redirect to its storage
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => 600,
            CURLOPT_FAILONERROR    => true,
            CURLOPT_USERAGENT      => 'Tracy Claude Cowork',
            CURLOPT_NOPROGRESS     => false,
            // Stops a body that turns out larger than the limit while it arrives, not after.
            CURLOPT_PROGRESSFUNCTION => static function ($handle, $downloadTotal, $downloaded) use ($maxBytes): int {
                return ($downloadTotal > $maxBytes || $downloaded > $maxBytes) ? 1 : 0;
            },
        ];

        if (\defined('CURLOPT_REDIR_PROTOCOLS') && \defined('CURLPROTO_HTTPS')) {
            $options[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTPS;
        }

        curl_setopt_array($ch, $curlOptions + $options);
        $ok    = curl_exec($ch);
        $error = $ok === false ? ('download failed: ' . curl_error($ch)) : null;
        if (\PHP_VERSION_ID < 80000) {
            curl_close($ch); // a no-op since PHP 8.0, deprecated in 8.5
        }

        return $error;
    }

    /** @param resource $out */
    private static function viaStream(string $url, $out, int $maxBytes): ?string
    {
        $context = stream_context_create(['http' => ['timeout' => 600, 'follow_location' => 1, 'max_redirects' => 5, 'user_agent' => 'Tracy Claude Cowork']]);
        $in      = @fopen($url, 'rb', false, $context);

        if ($in === false) {
            return 'download failed: cannot open ' . $url;
        }

        $copied = 0;
        $error  = null;

        while (!feof($in)) {
            $chunk = fread($in, self::CHUNK);

            if ($chunk === false) {
                $error = 'download failed: read error';
                break;
            }

            $copied += \strlen($chunk);

            if ($copied > $maxBytes) {
                $error = 'the package is larger than ' . $maxBytes . ' bytes';
                break;
            }

            if (fwrite($out, $chunk) !== \strlen($chunk)) {
                $error = 'download failed: disk write error';
                break;
            }
        }

        fclose($in);

        return $error;
    }

    /**
     * The site's own proxy (Global Configuration -> Server) as curl options, the one Joomla's HTTP
     * client would use. Takes the configuration values, not the application, so it stays CMS-free.
     *
     * @return array<int,mixed>
     */
    public static function proxyOptions($enabled, $host, $port, $user, $pass): array
    {
        // No curl, no curl constants: the stream copy then goes without the proxy, as it always has.
        if (!(int) $enabled || (string) $host === '' || !\defined('CURLOPT_PROXY')) {
            return [];
        }

        $options = [CURLOPT_PROXY => $host . ':' . (int) $port];

        if ((string) $user !== '') {
            $options[CURLOPT_PROXYUSERPWD] = $user . ':' . $pass;
        }

        return $options;
    }

    private static function fail(string $target, string $error): array
    {
        if (is_file($target)) {
            @unlink($target);
        }

        return ['ok' => false, 'bytes' => 0, 'sha256' => null, 'error' => $error];
    }
}
