<?php
/**
 * FileWalker — duyet webroot CO THU TU, resume duoc, loc thu muc vo nghia/nhay cam.
 *
 * Tra ve tung LO duong file (tuong doi so voi webroot) + size + sha1. Tracy giu con tro
 * (duong file cuoi cua lo truoc) va goi lai — plugin khong giu trang thai duyet. Thu tu
 * on dinh (sort) de resume xac dinh.
 *
 * readFile() la cong doc mot file cho Tracy KEO ve (prototype dung pull; ban production
 * lat sang push len presigned URL). CO CHAN PATH TRAVERSAL: duong phai nam trong webroot.
 */
final class FileWalker
{
    private string $root;
    /** @var string[] ten thu muc bo qua (khop bat ky cap nao) */
    private array $skipDirs;

    public function __construct(string $webroot, ?array $skipDirs = null)
    {
        $real = realpath($webroot);
        if ($real === false || !is_dir($real)) {
            throw new InvalidArgumentException("webroot not a dir: {$webroot}");
        }
        $this->root = $real;
        // Duong tuong doi tu goc webroot, khong phai ten thu muc. 'log' bien mat khoi danh
        // sach vi khong co /log o goc Joomla, trong khi co nhieu thu muc ten 'log' o sau
        // (psr/log, plugins/system/log) la code that phai giu.
        $this->skipDirs = $skipDirs ?? [
            'cache', 'tmp', 'logs', '.git', 'administrator/cache', 'administrator/logs',
            'node_modules', 'administrator/components/com_akeebabackup/backup',
        ];
    }

    /**
     * Toan bo duong file tuong doi, da loc, da sort — NHO LAI trong doi song cua object.
     *
     * Truoc do moi lan goi la mot lan duyet lai ca cay. Trong vong dong goi tar, moi file
     * lai hoi "file ke tiep la gi", nen thanh O(n^2): tren webroot that cua wisdeaf.org
     * (19.971 file) PHP chet vi "Maximum execution time of 30 seconds exceeded" ngay o
     * part dau. Bo test 5 file khong bao gio lo ra dieu nay.
     *
     * Nho lai chi trong MOT request — moi HTTP call van duyet lai mot lan, dung y do:
     * site khach co the doi file giua cac call, va con tro la duong dan chu khong phai
     * chi so, nen mot lan duyet moi la cai giu cho con tro con dung nghia.
     *
     * @var string[]|null
     */
    private ?array $pathCache = null;

    /** @return string[] */
    private function allPaths(): array
    {
        if ($this->pathCache !== null) {
            return $this->pathCache;
        }
        $out = [];
        $it = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
                function ($current) {
                    if ($current->isDir()) {
                        $rel = $this->relative($current->getPathname());
                        foreach ($this->skipDirs as $skip) {
                            // CHI khop duong tuong doi tu goc webroot. Truoc day con khop ca
                            // getFilename() — tuc la BAT KY thu muc nao ten 'cache'/'log'/'tmp'
                            // o BAT KY cap nao cung bi bo. Tren site that no nuot mat
                            // libraries/vendor/psr/log va psr/cache (PSR-3/PSR-6, Joomla BAT
                            // BUOC phai co) cung plugins/system/log va plugins/system/cache —
                            // 273 file, va site dung tu ban do se fatal error. Bo test tren cay
                            // thu muc tu tao khong bao gio co nhung duong dan nhu vay.
                            if ($rel === $skip) {
                                return false;
                            }
                        }
                    }
                    return true;
                }
            ),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($it as $file) {
            if ($file->isFile()) {
                $out[] = $this->relative($file->getPathname());
            }
        }
        sort($out, SORT_STRING);
        $this->pathCache = $out;
        return $out;
    }

    private function relative(string $abs): string
    {
        $rel = substr($abs, strlen($this->root));
        return ltrim(str_replace('\\', '/', $rel), '/');
    }

    /**
     * Mot lo file sau con tro $after (duong tuong doi; '' = tu dau).
     * @return array{files:array<int,array{path:string,size:int,sha1:string}>, next_cursor:?string, done:bool}
     */
    public function listBatch(string $after, int $limit): array
    {
        if ($limit < 1) {
            throw new InvalidArgumentException('limit must be >= 1');
        }
        $all = $this->allPaths();
        $start = 0;
        if ($after !== '') {
            $pos = array_search($after, $all, true);
            $start = ($pos === false) ? 0 : $pos + 1;
        }
        $slice = array_slice($all, $start, $limit);
        $files = [];
        foreach ($slice as $rel) {
            $abs = $this->root . '/' . $rel;
            $files[] = [
                'path' => $rel,
                'size' => (int) filesize($abs),
                'sha1' => sha1_file($abs),
            ];
        }
        $nextIndex = $start + count($slice);
        $done = $nextIndex >= count($all);
        return [
            'files'       => $files,
            'next_cursor' => count($slice) ? end($slice) : null,
            'done'        => $done,
        ];
    }

    /**
     * Chi ten file sau con tro — KHONG stat, KHONG sha1.
     *
     * listBatch() bam sha1_file() moi file, tuc la doc het noi dung chi de bam. Voi
     * files.list (Tracy can checksum de doi chieu) thi dung; voi vong dong goi tar thi
     * la doc moi file HAI lan. Day la ban nhe cho duong pack.
     *
     * @return string[]
     */
    public function pathsAfter(string $after, int $limit): array
    {
        if ($limit < 1) {
            throw new InvalidArgumentException('limit must be >= 1');
        }
        $all = $this->allPaths();
        $start = 0;
        if ($after !== '') {
            $pos = array_search($after, $all, true);
            $start = ($pos === false) ? 0 : $pos + 1;
        }
        return array_slice($all, $start, $limit);
    }

    /** Duong tuyet doi cua mot file tuong doi, da chan thoat webroot. */
    public function absolutePath(string $rel): string
    {
        $rel = ltrim(str_replace('\\', '/', $rel), '/');
        $abs = realpath($this->root . '/' . $rel);
        if ($abs === false) {
            throw new RuntimeException("not found: {$rel}");
        }
        if ($abs !== $this->root && strpos($abs, $this->root . DIRECTORY_SEPARATOR) !== 0) {
            throw new RuntimeException("outside webroot: {$rel}");
        }
        return $abs;
    }

    /** Doc noi dung mot file (tuong doi). Chan thoat webroot. */
    public function readFile(string $rel): string
    {
        $rel = ltrim(str_replace('\\', '/', $rel), '/');
        $abs = realpath($this->root . '/' . $rel);
        if ($abs === false) {
            throw new RuntimeException("not found: {$rel}");
        }
        // phai nam trong webroot (chong ../../etc/passwd va symlink thoat)
        if ($abs !== $this->root && strpos($abs, $this->root . DIRECTORY_SEPARATOR) !== 0) {
            throw new RuntimeException("path escapes webroot: {$rel}");
        }
        if (!is_file($abs)) {
            throw new RuntimeException("not a file: {$rel}");
        }
        $data = file_get_contents($abs);
        if ($data === false) {
            throw new RuntimeException("unreadable: {$rel}");
        }
        return $data;
    }
}
