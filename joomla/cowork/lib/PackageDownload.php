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
     * Where this repository's release assets are downloaded from: `<RELEASES>joomla-v<version>/<asset>`.
     * The one copy: the self-updater installs nothing from anywhere else, and a site fetches its
     * contract profile from here (ContractFetch).
     */
    public const RELEASES = 'https://github.com/TracyHQ/claude-cowork/releases/download/';

    /**
     * Fetch $url into $target. Never throws: every outcome is in the answer.
     *
     * @param array<int,mixed> $curlOptions extra curl options (the site's proxy), applied last
     * @param bool|null        $useCurl     null: curl when this PHP has it; false: the stream copy
     * @param int              $timeout     seconds for the whole transfer (a contract profile asks for far less)
     *
     * @return array{ok:bool, bytes:int, sha256:?string, error:?string}
     */
    public static function toFile(string $url, string $target, int $maxBytes, ?string $sha256 = null, array $curlOptions = [], ?bool $useCurl = null, int $timeout = 600): array
    {
        $useCurl = $useCurl ?? \function_exists('curl_init');
        $out     = @fopen($target, 'wb');

        if ($out === false) {
            return self::fail($target, 'cannot write ' . $target);
        }

        $error = $useCurl ? self::viaCurl($url, $out, $maxBytes, $curlOptions, $timeout) : self::viaStream($url, $out, $maxBytes, $timeout);
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
    private static function viaCurl(string $url, $out, int $maxBytes, array $curlOptions, int $timeout): ?string
    {
        $ch = curl_init($url);
        $options = [
            // CURLOPT_FILE is the point: curl writes each block it receives to the open file.
            CURLOPT_FILE           => $out,
            CURLOPT_FOLLOWLOCATION => true, // a release asset answers with a redirect to its storage
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_CONNECTTIMEOUT => min(15, $timeout),
            CURLOPT_TIMEOUT        => $timeout,
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
    private static function viaStream(string $url, $out, int $maxBytes, int $timeout): ?string
    {
        // One deadline for the whole transfer: every redirect, the open of each, and the body. The
        // context's and the stream's timeouts bound a single wait, so each is set to what is left;
        // past the deadline the download failed, whatever arrived.
        $deadline = microtime(true) + $timeout;
        $late     = 'download failed: timed out after ' . $timeout . ' s';
        $in       = self::openStream($url, $deadline);

        if (!\is_resource($in)) {
            return (microtime(true) > $deadline || $in === 'download failed: timed out') ? $late : $in;
        }

        $copied = 0;
        $error  = null;

        while (!feof($in)) {
            $left = $deadline - microtime(true);

            if ($left <= 0) {
                $error = $late;
                break;
            }

            stream_set_timeout($in, (int) $left, (int) (($left - (int) $left) * 1e6));
            $chunk = fread($in, self::CHUNK);

            if (!empty(stream_get_meta_data($in)['timed_out'])) {
                $error = $late;
                break;
            }

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

        if ($error === null && microtime(true) > $deadline) {
            $error = $late;
        }

        fclose($in);

        return $error;
    }

    /**
     * Opens `$url` for the stream copy, following redirects here rather than in PHP's http wrapper:
     * the wrapper gives every hop the whole timeout, so five slow redirects could take five times the
     * budget. Each hop gets what is left until `$deadline`. Like the curl path (CURLOPT_MAXREDIRS 5,
     * CURLOPT_REDIR_PROTOCOLS https), at most five redirects, and a redirect may only lead to https —
     * or, from a plain http start, to http as well: a download that starts on https never leaves it,
     * and every caller starts there. Any other scheme (a local file, a test wrapper) is opened once.
     *
     * @return resource|string the open body, or why it could not be opened
     */
    private static function openStream(string $url, float $deadline)
    {
        $start = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if ($start !== 'http' && $start !== 'https') {
            $in = @fopen($url, 'rb');

            return $in === false ? 'download failed: cannot open ' . $url : $in;
        }

        $allowed = $start === 'https' ? ['https'] : ['http', 'https'];

        for ($redirects = 0; ; $redirects++) {
            $left = $deadline - microtime(true);

            if ($left <= 0) {
                return 'download failed: timed out';
            }

            $context = stream_context_create(['http' => ['timeout' => $left, 'follow_location' => 0, 'ignore_errors' => true, 'user_agent' => 'Tracy Claude Cowork']]);
            $opened  = microtime(true);
            $in      = @fopen($url, 'rb', false, $context);

            if ($in === false) {
                // An open that used up what was left timed out, even when the clock reads a hair
                // short of the deadline (the wrapper's timer and microtime() round differently).
                return microtime(true) - $opened >= $left - 0.05 ? 'download failed: timed out' : 'download failed: cannot open ' . $url;
            }

            $headers = stream_get_meta_data($in)['wrapper_data'] ?? null;

            // Only PHP's own http wrapper answers with header lines; a wrapper registered in its
            // place (a test fixture) has no status or redirect to read, so its body is the answer.
            if (!\is_array($headers)) {
                return $in;
            }

            $status   = 0;
            $location = null;

            foreach ($headers as $line) {
                if (preg_match('~^HTTP/\S+\s+(\d{3})~', (string) $line, $m)) {
                    $status = (int) $m[1];
                } elseif (stripos((string) $line, 'location:') === 0) {
                    $location = trim(substr((string) $line, 9));
                }
            }

            if ($status >= 200 && $status < 300) {
                return $in;
            }

            fclose($in);

            if ($status < 300 || $status >= 400 || $location === null || $location === '') {
                return 'download failed: cannot open ' . $url . ' (HTTP ' . $status . ')';
            }

            if ($redirects >= 5) {
                return 'download failed: more than 5 redirects from ' . $url;
            }

            $next = self::resolve($url, $location);

            if (!\in_array(strtolower((string) parse_url($next, PHP_URL_SCHEME)), $allowed, true)) {
                return 'download failed: a redirect to ' . $next . ' is refused';
            }

            $url = $next;
        }
    }

    /** `$location` from a response to `$base`, as an absolute URL. */
    private static function resolve(string $base, string $location): string
    {
        if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $location)) {
            return $location;
        }

        $p      = parse_url($base);
        $scheme = $p['scheme'] ?? 'https';

        if (strpos($location, '//') === 0) {
            return $scheme . ':' . $location;
        }

        $origin = $scheme . '://' . (isset($p['user']) ? $p['user'] . (isset($p['pass']) ? ':' . $p['pass'] : '') . '@' : '')
            . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');

        if (strpos($location, '/') === 0) {
            return $origin . $location;
        }

        $path = $p['path'] ?? '/';
        $dir  = substr($path, 0, (int) strrpos($path, '/') + 1);

        return $origin . ($dir === '' ? '/' : $dir) . $location;
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
