<?php
/**
 * Engine — dieu phoi mot HTTP call thanh mot mieng viec BOUNDED, tra JSON-able array.
 *
 * "Plugin ngu, Tracy khon": engine khong giu vong lap, khong giu trang thai giua cac
 * call. Moi call: kiem token -> route action -> lam ≤1 chunk -> tra ket qua + con tro.
 * Tracy giu con tro va lai vong. Loi tra ve dang {ok:false, error} chu khong nem ra HTTP
 * 500, de Tracy retry theo ma loi.
 *
 * KHONG phu thuoc Joomla. Plugin adapter (tracymigration.php) bom deps that vao.
 */

require_once __DIR__ . '/Token.php';
require_once __DIR__ . '/DbDumper.php';
require_once __DIR__ . '/FileWalker.php';
require_once __DIR__ . '/TarStream.php';
require_once __DIR__ . '/Uploader.php';

final class Engine
{
    private ?string $token;
    /** @var array<string,mixed> thong tin moi truong cho action 'info' */
    private array $info;
    private ?DbDumper $dumper;
    private ?FileWalker $walker;
    private ?Uploader $uploader;

    private const MAX_DB_LIMIT = 5000;
    private const MAX_FILE_LIMIT = 500;
    private const MAX_READ_BYTES = 8388608; // 8 MiB/1 file cho duong pull prototype
    /** S3/R2 doi moi part (tru part cuoi) >= 5 MiB — day la san, khong phai tuy chon. */
    private const MIN_PART_BYTES = 5242880;
    /** Tran buffer giu trong RAM. Shared hosting hay dat memory_limit 128M. */
    private const MAX_PART_BYTES = 33554432; // 32 MiB
    /** Doc file theo mieng, de mot file lon khong bao gio nam tron trong RAM. */
    private const READ_CHUNK = 1048576;
    /** So duong dan lay san moi call, de khong phai hoi walker theo tung file. */
    private const PACK_LOOKAHEAD = 100000;

    public function __construct(
        ?string $token,
        array $info = [],
        ?DbDumper $dumper = null,
        ?FileWalker $walker = null,
        ?Uploader $uploader = null
    ) {
        $this->token = $token;
        $this->info = $info;
        $this->dumper = $dumper;
        $this->walker = $walker;
        $this->uploader = $uploader;
    }

    /**
     * @param array<string,mixed> $req  {token, action, params:{}}
     * @return array<string,mixed>
     */
    public function handle(array $req): array
    {
        $provided = isset($req['token']) && is_string($req['token']) ? $req['token'] : null;
        if (!Token::check($this->token, $provided)) {
            return $this->err('unauthorized', 'invalid or missing token');
        }

        $action = isset($req['action']) && is_string($req['action']) ? $req['action'] : '';
        $params = isset($req['params']) && is_array($req['params']) ? $req['params'] : [];

        switch ($action) {
            case 'info':
                return $this->ok(['info' => $this->info]);
            case 'db.tables':
                return $this->dbTables();
            case 'db.dump':
                return $this->dbDump($params);
            case 'files.list':
                return $this->filesList($params);
            case 'files.pack':
                return $this->filesPack($params);
            case 'file.read':
                return $this->fileRead($params);
            default:
                return $this->err('bad_action', "unknown action: {$action}");
        }
    }

    /**
     * Liet ke ten bang — de Tracy (orchestrator) tu kham pha schema thay vi phai biet
     * truoc ten tung bang. Tra ve cung so dong hien co, de orchestrator uoc luong tien do
     * truoc khi dump tung bang.
     */
    private function dbTables(): array
    {
        if ($this->dumper === null) {
            return $this->err('unavailable', 'db dump not wired');
        }
        try {
            $tables = $this->dumper->tables();
        } catch (Throwable $e) {
            return $this->err('dump_failed', $e->getMessage());
        }
        return $this->ok(['tables' => $tables]);
    }

    private function dbDump(array $p): array
    {
        if ($this->dumper === null) {
            return $this->err('unavailable', 'db dump not wired');
        }
        $table = isset($p['table']) && is_string($p['table']) ? $p['table'] : '';
        if ($table === '') {
            return $this->err('bad_params', 'table required');
        }
        $offset = max(0, (int) ($p['offset'] ?? 0));
        $limit = (int) ($p['limit'] ?? 1000);
        $limit = max(1, min($limit, self::MAX_DB_LIMIT));
        try {
            $chunk = $this->dumper->dumpChunk($table, $offset, $limit);
        } catch (Throwable $e) {
            return $this->err('dump_failed', $e->getMessage());
        }
        return $this->ok([
            'table'       => $table,
            'sql_b64'     => base64_encode($chunk['sql']),
            'next_offset' => $chunk['next_offset'],
            'done'        => $chunk['done'],
            'rows'        => $chunk['rows'],
            'total'       => $chunk['total'],
        ]);
    }

    private function filesList(array $p): array
    {
        if ($this->walker === null) {
            return $this->err('unavailable', 'file walk not wired');
        }
        $after = isset($p['after']) && is_string($p['after']) ? $p['after'] : '';
        $limit = (int) ($p['limit'] ?? 100);
        $limit = max(1, min($limit, self::MAX_FILE_LIMIT));
        try {
            $batch = $this->walker->listBatch($after, $limit);
        } catch (Throwable $e) {
            return $this->err('walk_failed', $e->getMessage());
        }
        return $this->ok($batch);
    }

    /**
     * Dong goi MOT PART tar roi day thang len presigned URL — khong ghi file tam, khong
     * cam credential.
     *
     * Vi sao khong dung presigned PUT mot phat cho ca archive: PHP timeout giua chung khi
     * day 300 MB tren shared host la LAM LAI TU DAU — dung cai benh ma plugin nay sinh ra
     * de tranh. S3/R2 multipart cho phep moi part mot URL rieng, nen moi call la mot mieng
     * bounded, resume duoc. Tracy giu uploadId + danh sach ETag roi goi
     * CompleteMultipartUpload; plugin khong biet gi ve chuyen do.
     *
     * Con tro co HAI phan — `path` va `offset` (so byte NOI DUNG cua path da gui). Nho
     * offset ma mot file lon hon ca part van chay duoc: header ghi o part truoc, noi dung
     * trai qua nhieu part, va RAM chi giu toi da mot part. Neu chi co con tro theo file
     * thi mot file 500 MB se ep ca part vao RAM — chet tren shared hosting.
     *
     * @param array<string,mixed> $p
     */
    private function filesPack(array $p): array
    {
        if ($this->walker === null) {
            return $this->err('unavailable', 'file walk not wired');
        }
        if ($this->uploader === null) {
            return $this->err('unavailable', 'uploader not wired');
        }
        $putUrl = isset($p['put_url']) && is_string($p['put_url']) ? $p['put_url'] : '';
        if ($putUrl === '') {
            return $this->err('bad_params', 'put_url required');
        }

        $target = (int) ($p['target_bytes'] ?? self::MIN_PART_BYTES);
        $target = max(self::MIN_PART_BYTES, min($target, self::MAX_PART_BYTES));
        $path   = isset($p['path']) && is_string($p['path']) ? $p['path'] : '';
        $offset = max(0, (int) ($p['offset'] ?? 0));

        try {
            // Lay danh sach MOT LAN roi duyet trong bo nho. Truoc do vong lap hoi walker
            // "file ke tiep la gi" cho TUNG file, ma moi lan hoi la mot array_search tren
            // ca danh sach -> O(n^2). Tren webroot that (19.971 file) PHP chet o 30 giay
            // ngay part dau; bo test 5 file khong he lo ra.
            $queue = $path === ''
                ? $this->walker->pathsAfter('', self::PACK_LOOKAHEAD)
                : array_merge([$path], $this->walker->pathsAfter($path, self::PACK_LOOKAHEAD));
            $qi = 0;

            $buf   = '';
            $files = 0;
            $done  = false;

            while (true) {
                $path = $queue[$qi] ?? '';
                if ($path === '') {
                    // Het file: hai khoi rong dong archive, CHI o part cuoi.
                    $buf .= TarStream::endOfArchive();
                    $done = true;
                    break;
                }

                // Dung TRUOC khi ghi header neu part da day. Neu ghi header roi moi phat
                // hien day, vong doc noi dung khong chay lan nao -> $offset van 0 -> con tro
                // tra ve tro lai chinh file nay voi offset 0 -> call sau ghi header LAN HAI.
                // Do la mot header thua 512 byte lam lech ca dong tar: `tar` bao "Damaged
                // tar archive" va file do giai nen ra bat dau bang chinh duong dan cua no.
                // Bug that, tim duoc khi chay tren webroot 19.941 file — bo test 5 file cho
                // qua vi khong bao gio cham dung bien nay.
                if ($offset === 0 && strlen($buf) >= $target) {
                    break;
                }

                $abs  = $this->walker->absolutePath($path);
                $size = (int) filesize($abs);

                if ($offset === 0) {
                    $buf .= TarStream::fileHeader($path, $size, fileperms($abs) & 0777, (int) filemtime($abs));
                    $files++;
                }

                // Doc theo mieng, dung ngay khi day part — khong bao gio nap ca file.
                $fh = fopen($abs, 'rb');
                if ($fh === false) {
                    return $this->err('read_failed', "cannot open {$path}");
                }
                if ($offset > 0) {
                    fseek($fh, $offset);
                }
                while ($offset < $size && strlen($buf) < $target) {
                    $want  = min(self::READ_CHUNK, $size - $offset, $target - strlen($buf));
                    $chunk = fread($fh, $want);
                    if ($chunk === false || $chunk === '') {
                        break;
                    }
                    $buf    .= $chunk;
                    $offset += strlen($chunk);
                }
                fclose($fh);

                if ($offset >= $size) {
                    $buf .= TarStream::pad($size);
                    $qi++;
                    $offset = 0;
                    // Danh sach nhin truoc da het nhung cay chua het: xin tiep tu file vua xong.
                    if ($qi >= count($queue)) {
                        $more = $this->walker->pathsAfter($path, self::PACK_LOOKAHEAD);
                        if ($more !== []) {
                            $queue = $more;
                            $qi = 0;
                        }
                    }
                }

                // Day thi dung — nhung chi khi dang do mot file ($offset > 0, header da ghi).
                // Neu vua xong mot file, vong lap quay len dau va thoat o cho kiem tra trước
                // header, giu con tro o file ke tiep voi offset 0.
                if ($offset > 0 && strlen($buf) >= $target) {
                    break;
                }
            }
        } catch (Throwable $e) {
            return $this->err('pack_failed', $e->getMessage());
        }

        $sha = hash('sha256', $buf);
        $put = $this->uploader->put($putUrl, $buf);
        if (!$put['ok']) {
            // KHONG nhich con tro khi day that bai — Tracy ky lai URL va goi lai dung part nay.
            return $this->err('upload_failed', $put['error']);
        }

        return $this->ok([
            'bytes'       => strlen($buf),
            'files'       => $files,
            'sha256'      => $sha,
            'etag'        => $put['etag'],
            'next_path'   => $path,
            'next_offset' => $offset,
            'done'        => $done,
        ]);
    }

    private function fileRead(array $p): array
    {
        if ($this->walker === null) {
            return $this->err('unavailable', 'file walk not wired');
        }
        $path = isset($p['path']) && is_string($p['path']) ? $p['path'] : '';
        if ($path === '') {
            return $this->err('bad_params', 'path required');
        }
        try {
            $data = $this->walker->readFile($path);
        } catch (Throwable $e) {
            return $this->err('read_failed', $e->getMessage());
        }
        if (strlen($data) > self::MAX_READ_BYTES) {
            return $this->err('too_large', 'file exceeds pull limit; use push in production');
        }
        return $this->ok([
            'path'         => $path,
            'size'         => strlen($data),
            'sha1'         => sha1($data),
            'content_b64'  => base64_encode($data),
        ]);
    }

    /** @param array<string,mixed> $extra */
    private function ok(array $extra): array
    {
        return array_merge(['ok' => true], $extra);
    }

    private function err(string $code, string $message): array
    {
        return ['ok' => false, 'error' => $code, 'message' => $message];
    }
}
