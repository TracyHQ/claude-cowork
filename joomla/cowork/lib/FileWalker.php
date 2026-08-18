<?php
/**
 * FileWalker — an ordered, resumable walk of the webroot that leaves out what nobody wants.
 *
 * Returns one batch of paths at a time, relative to the webroot, with size and sha1. The caller
 * keeps the cursor — the last path of the previous batch — and asks again; nothing about the
 * walk is remembered between requests. The order is sorted and therefore stable, which is what
 * makes resuming from a path mean anything at all.
 *
 * Every path that leaves this class has been checked to be inside the webroot, so a caller
 * cannot reach `../../etc/passwd` and a symlink cannot lead out.
 */
final class FileWalker
{
    private string $root;
    /** @var string[] Directories left out, given as paths relative to the webroot. */
    private array $skipDirs;

    public function __construct(string $webroot, ?array $skipDirs = null)
    {
        $real = realpath($webroot);
        if ($real === false || !is_dir($real)) {
            throw new InvalidArgumentException("webroot not a dir: {$webroot}");
        }
        $this->root = $real;
        // Paths from the webroot, never bare directory names. 'log' is absent from this list
        // because Joomla has no /log at its root, while it does have several directories called
        // 'log' further down (psr/log, plugins/system/log) that are code the site needs.
        $this->skipDirs = $skipDirs ?? [
            'cache', 'tmp', 'logs', '.git', 'administrator/cache', 'administrator/logs',
            'node_modules',
            // Akeeba Backup's output — multi-hundred-MB .jpa/.sql archives of the site, written
            // INTO the webroot, and rewritten while a backup runs. Useless in a clone (it is the
            // site's own backup of itself) and the worst possible thing to tar: on
            // www.joomlart.com a live backup left a 603 MB .sql in here that bloated the archive
            // and shifted it (2026-08-11). BOTH component names: com_akeeba is Akeeba 7.x and
            // earlier — the one joomlart.com runs — and com_akeebabackup is 9.x+.
            'administrator/components/com_akeeba/backup',
            'administrator/components/com_akeebabackup/backup',
        ];
    }

    /**
     * Every path, filtered and sorted, remembered for as long as this object lives.
     *
     * It did not always: each call used to walk the tree again. Inside the tar loop that means
     * asking "what comes after this file?" once per file, which is quadratic — and on a real
     * webroot of 19,971 files, PHP died with "Maximum execution time of 30 seconds exceeded"
     * during the very first part. A fixture of five files never showed it.
     *
     * Remembered within ONE request only. Each HTTP call walks afresh, deliberately: the site
     * can change between calls, and because the cursor is a path rather than an index, a fresh
     * walk is exactly what keeps that cursor meaning what it meant.
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
                            // Matched against the path from the webroot, and nothing else. It
                            // used to also match the bare directory name, so ANY directory
                            // called 'cache' or 'log' or 'tmp' at ANY depth was dropped. On a
                            // real site that silently swallowed libraries/vendor/psr/log and
                            // psr/cache — PSR-3 and PSR-6, which Joomla requires — along with
                            // plugins/system/log and plugins/system/cache: 273 files, and a
                            // site rebuilt from the result fatals on boot. A fixture tree
                            // invented for a test never contains paths shaped like that.
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
     * One batch of files after the cursor $after (a relative path; '' starts from the beginning).
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
     * How many files, how many bytes, and when the newest of them changed.
     *
     * Two jobs in one walk. It sizes the work, so a caller can say "about eleven minutes" before
     * starting rather than leaving someone watching an unmarked bar. And the three numbers
     * together are a cheap fingerprint of the tree: an archive made earlier still describes this
     * site if they have not moved, whatever the clock says, and does not if they have.
     *
     * No sha1 here, deliberately. Hashing means reading every byte of every file, which is the
     * expensive thing this is meant to let a caller avoid.
     *
     * @return array{files:int,bytes:int,newest:int}
     */
    public function stats(): array
    {
        $files = 0;
        $bytes = 0;
        $newest = 0;
        foreach ($this->allPaths() as $rel) {
            $abs = $this->root . '/' . $rel;
            $files++;
            $bytes += (int) filesize($abs);
            $mtime = (int) filemtime($abs);
            if ($mtime > $newest) {
                $newest = $mtime;
            }
        }
        return ['files' => $files, 'bytes' => $bytes, 'newest' => $newest];
    }

    /**
     * Paths only, after the cursor. No stat, no sha1.
     *
     * listBatch() hashes every file, which means reading all of it just to hash it. That is what
     * a caller comparing checksums wants, and exactly what the tar loop does not: there it would
     * read every file twice. This is the cheap door for packing.
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


    /**
     * Files inside the webroot that no read action may return.
     *
     * The confinement below is about DIRECTION: realpath plus a prefix test stops
     * `../../etc/passwd` and a symlink pointing out of the tree. It says nothing about a
     * well-formed path that stays inside, and the site's own configuration is exactly that
     * shape.
     *
     * The write side already carries this list. `Engine::MEDIA_ROOTS` and the validation
     * around it exist because, in its own words, "without this a well-formed path like
     * configuration.php passes every other check and overwrites live code". The read side
     * had the confinement and not the list.
     *
     * Entries ending in '/' are directory prefixes. Everything is compared lowercased, so
     * the check does not depend on whether the host filesystem is case-sensitive.
     */
    public const SECRET_PATHS = [
        'configuration.php',
        '.env',
        '.env.local',
        '.htpasswd',
        'administrator/logs/',
        'logs/',
        'cli/',
    ];

    /** The relative path, normalised the one way every check here agrees on. */
    private function normalise(string $rel): string
    {
        $norm = str_replace('\\', '/', $rel);
        // Strip a leading './' as a PREFIX, not as a character set. ltrim($rel, './')
        // would eat the leading dot of '.env' and turn it into 'env', which matches
        // nothing and lets the file straight through.
        while (strpos($norm, './') === 0) {
            $norm = substr($norm, 2);
        }
        return ltrim($norm, '/');
    }

    /** True when a relative path is one this component must never hand back. */
    public function isSecretPath(string $rel): bool
    {
        $norm = strtolower($this->normalise($rel));
        foreach (self::SECRET_PATHS as $deny) {
            if (substr($deny, -1) === '/') {
                if (strpos($norm, $deny) === 0) {
                    return true;
                }
                continue;
            }
            // A rotated copy keeps the secrets and the stem: configuration.php.bak,
            // configuration.php.save, configuration.php-2026-01-01.
            if ($norm === $deny
                || strpos($norm, $deny . '.') === 0
                || strpos($norm, $deny . '-') === 0) {
                return true;
            }
        }
        return false;
    }

    /** The absolute path of a relative one, having checked it does not leave the webroot. */
    public function absolutePath(string $rel): string
    {
        // Refused before realpath, so a refusal cannot be used to learn what exists. The
        // message is fixed and does not repeat the path, for the same reason.
        if ($this->isSecretPath($rel)) {
            throw new RuntimeException('refused: protected path');
        }
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

    /** Reads one file, by relative path. Refuses anything outside the webroot. */
    public function readFile(string $rel): string
    {
        // Refused before realpath, so a refusal cannot be used to learn what exists. The
        // message is fixed and does not repeat the path, for the same reason.
        if ($this->isSecretPath($rel)) {
            throw new RuntimeException('refused: protected path');
        }
        $rel = ltrim(str_replace('\\', '/', $rel), '/');
        $abs = realpath($this->root . '/' . $rel);
        if ($abs === false) {
            throw new RuntimeException("not found: {$rel}");
        }
        // Inside the webroot or nothing: this is what stops both `../../etc/passwd` and a
        // symlink that points out of the tree.
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
