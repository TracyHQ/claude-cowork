<?php
/**
 * Uploader — day mot part len URL da ky san (presigned).
 *
 * Tach thanh interface vi hai ly do: (1) test duoc Engine ma khong can mang, (2) plugin
 * KHONG BAO GIO cam credential R2 — no chi nhan mot URL da ky, dung han, cho dung mot part.
 * Viec ky URL nam o Tracy/relay (spec fleet Item 4: "relay chi mint presigned URL, khong
 * thay byte nao").
 */
interface Uploader
{
    /**
     * @return array{ok:bool, status:int, etag:string, error:string}
     */
    public function put(string $url, string $body): array;
}

/**
 * Ban chay that bang cURL. Khong dung file_get_contents(): nhieu shared hosting tat
 * allow_url_fopen, trong khi ext/curl gan nhu luon co (Joomla cung yeu cau no).
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
            // Presigned URL da mang chu ky trong query string; them header la hong chu ky.
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
                // S3/R2 tra loi bang XML — cat ngan de khong nhoi log cua khach.
                'error'  => 'HTTP ' . $status . ': ' . substr(trim($bodyText), 0, 300),
            ];
        }

        // ETag la thu Tracy phai gom lai de goi CompleteMultipartUpload; thieu no la
        // khong ket thuc duoc upload, nen bao loi ngay thay vi de phat hien o buoc cuoi.
        if ($etag === '') {
            return ['ok' => false, 'status' => $status, 'etag' => '', 'error' => 'response carried no ETag'];
        }

        return ['ok' => true, 'status' => $status, 'etag' => $etag, 'error' => ''];
    }
}
