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
