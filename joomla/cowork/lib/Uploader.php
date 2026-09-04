<?php
/**
 * Uploader — sends one part to a URL that was signed elsewhere.
 *
 * An interface for two reasons. It lets the engine be tested without a network. And it keeps the
 * shape of the trust: this component never holds an object-store credential. It receives one
 * signed URL, good for one part and expiring shortly, and it could not upload anywhere else if
 * it wanted to. The signing belongs to the caller.
 */
interface Uploader
{
    /**
     * @return array{ok:bool, status:int, etag:string, error:string}
     */
    public function put(string $url, string $body): array;
}

/**
 * The real one, over cURL. Not file_get_contents(): shared hosts commonly disable
 * allow_url_fopen, while ext/curl is all but always present — Joomla itself requires it.
 */
final class CurlUploader implements Uploader
{
    private int $timeout;

    public function __construct(int $timeout = 120)
    {
        $this->timeout = $timeout;
    }

    public function put(string $url, string $body): array
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'status' => 0, 'etag' => '', 'error' => 'ext/curl not available'];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'PUT',
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            // The signature covers a specific set of headers. Adding one that was not signed
            // for invalidates it, so both of these are cleared rather than left to cURL.
            CURLOPT_HTTPHEADER     => ['Content-Type:', 'Expect:'],
        ]);
        $raw    = curl_exec($ch);
        $errno  = curl_errno($ch);
        $err    = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $hlen   = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if ($errno !== 0) {
            return ['ok' => false, 'status' => $status, 'etag' => '', 'error' => "curl {$errno}: {$err}"];
        }

        $headers = substr((string) $raw, 0, $hlen);
        $etag    = '';
        if (preg_match('/^ETag:\s*"?([^"\r\n]+)"?/mi', $headers, $m)) {
            $etag = $m[1];
        }

        if ($status < 200 || $status >= 300) {
            $bodyText = substr((string) $raw, $hlen);
            return [
                'ok'     => false,
                'status' => $status,
                'etag'   => '',
                // The store answers failures with XML. Truncated, because this ends up in the
                // customer's own log and a wall of it helps nobody.
                'error'  => 'HTTP ' . $status . ': ' . substr(trim($bodyText), 0, 300),
            ];
        }

        // The caller collects these to finish the multipart upload. A part that landed without
        // one cannot be completed later, so it is reported here rather than at the end, when the
        // only remedy left would be to start over.
        if ($etag === '') {
            return ['ok' => false, 'status' => $status, 'etag' => '', 'error' => 'response carried no ETag'];
        }

        return ['ok' => true, 'status' => $status, 'etag' => $etag, 'error' => ''];
    }
}

/**
 * The same PUT over PHP's own HTTP stream wrapper, for a host without ext/curl.
 *
 * Measured 2026-09-04 on a live Joomla 6.0.3 site: `files.pack` answered
 * `upload_failed: ext/curl not available`, and the whole import died there — after the database
 * had already been read and stored. curl is common, not universal; `allow_url_fopen` with the
 * `https://` wrapper is what Joomla itself relies on, so it is the floor this code can stand on.
 *
 * The part is already a string in memory (5–32 MiB, bounded by the engine), so nothing is lost by
 * handing it to the wrapper as `content` instead of streaming it.
 */
final class StreamUploader implements Uploader
{
    private int $timeout;

    public function __construct(int $timeout = 120)
    {
        $this->timeout = $timeout;
    }

    /** Whether the wrapper can be used at all — false means the host disabled remote fopen. */
    public static function available(): bool
    {
        return (bool) ini_get('allow_url_fopen') && in_array('https', stream_get_wrappers(), true);
    }

    public function put(string $url, string $body): array
    {
        if (!self::available()) {
            return ['ok' => false, 'status' => 0, 'etag' => '', 'error' => 'allow_url_fopen is off and ext/curl not available'];
        }

        $context = stream_context_create([
            'http' => [
                'method'        => 'PUT',
                'content'       => $body,
                // No Content-Type: the presigned URL was signed without one, and a wrapper default
                // of application/x-www-form-urlencoded would break the signature on some stores.
                'header'        => "Content-Length: " . strlen($body) . "\r\n",
                'timeout'       => $this->timeout,
                'ignore_errors' => true,
                'follow_location' => 0,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $http_response_header = [];
        $raw = @file_get_contents($url, false, $context);
        $error = error_get_last();

        return self::parseResponse($http_response_header, $raw, $raw === false ? (string) ($error['message'] ?? 'request failed') : '');
    }

    /**
     * Turns what the wrapper hands back into the same answer `CurlUploader` gives.
     *
     * Pure, so the test runner can pin it: `$headers` is `$http_response_header` as PHP fills it
     * (status line first, and again after any redirect), `$body` is what `file_get_contents`
     * returned, `$failure` the last PHP warning when it returned false.
     * @param string[] $headers
     * @param string|false $body
     */
    public static function parseResponse(array $headers, $body, string $failure = ''): array
    {
        if ($body === false && $headers === []) {
            return ['ok' => false, 'status' => 0, 'etag' => '', 'error' => 'stream: ' . ($failure !== '' ? $failure : 'request failed')];
        }

        $status = 0;
        $etag = '';
        foreach ($headers as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                // The last status line wins: the wrapper appends the headers of every hop.
                $status = (int) $m[1];
                $etag = '';
                continue;
            }
            if (preg_match('/^ETag:\s*"?([^"\r\n]+)"?/i', $line, $m)) {
                $etag = $m[1];
            }
        }

        if ($status < 200 || $status >= 300) {
            return [
                'ok'     => false,
                'status' => $status,
                'etag'   => '',
                'error'  => 'HTTP ' . $status . ': ' . substr(trim((string) $body), 0, 300),
            ];
        }

        if ($etag === '') {
            return ['ok' => false, 'status' => $status, 'etag' => '', 'error' => 'response carried no ETag'];
        }

        return ['ok' => true, 'status' => $status, 'etag' => $etag, 'error' => ''];
    }
}

/**
 * Whatever this host can upload with: curl when the extension is there, the stream wrapper when it
 * is not. Decided once, at construction, so every part of one upload goes the same way.
 */
final class AutoUploader implements Uploader
{
    private Uploader $chosen;

    public function __construct(int $timeout = 120)
    {
        $this->chosen = function_exists('curl_init') ? new CurlUploader($timeout) : new StreamUploader($timeout);
    }

    /** Which way this host uploads — surfaced for diagnostics, never for a decision. */
    public function via(): string
    {
        return $this->chosen instanceof CurlUploader ? 'curl' : 'stream';
    }

    /**
     * What this host can do at all: 'curl', 'stream', or 'none' when it cannot open an outbound
     * connection either way. Reported by `info` so a caller can choose the inline road for
     * `files.pack` before the first part, instead of learning it from a failed upload.
     */
    public static function capability(): string
    {
        if (function_exists('curl_init')) {
            return 'curl';
        }
        return StreamUploader::available() ? 'stream' : 'none';
    }

    public function put(string $url, string $body): array
    {
        return $this->chosen->put($url, $body);
    }
}
