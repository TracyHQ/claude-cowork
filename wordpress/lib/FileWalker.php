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
        // Paths from the webroot, never bare directory names — and WordPress's own, not Joomla's.
        // (This walker was seeded from the Joomla one and carried its `administrator/...` paths,
        // which mean nothing under a WordPress root and let WordPress's real bloat through.)
        //
        // What must not reach a clone: caching-plugin output and, above all, backup-plugin
        // archives — multi-hundred-MB dumps a plugin writes INTO wp-content and rewrites while a
        // backup runs, the same trap Akeeba is on the Joomla side. `wp-content/uploads` itself is
        // the media library and stays; only the backup subtrees some plugins put under it are cut.
        $this->skipDirs = $skipDirs ?? [
            '.git', 'node_modules',
            'wp-content/cache',            // W3 Total Cache, WP Super Cache, WP Rocket, LiteSpeed
            'wp-content/upgrade',          // WordPress's own temp dir for core/plugin updates
            'wp-content/updraft',          // UpdraftPlus
            'wp-content/ai1wm-backups',    // All-in-One WP Migration
            'wp-content/backups-dup-lite', // Duplicator
            'wp-content/uploads/backwpup',  // BackWPup
            'wp-content/uploads/backup-guard', // Backup Guard
        ];
    }

    /**
     * Archives that live in the webroot itself rather than under a plugin's directory.
     *
     * The list above catches backups a plugin files away tidily. It cannot catch the other habit:
     * a one-off archive dropped straight in the webroot, named after the site and the day. On
     * juneflower.vn that was `backup-juneflower.vn-1-19-2026.tar.gz` at 1.6 GB — larger than
     * everything else on the site put together, and most of the twenty minutes a pack spent before
     * it (2026-08-14).
     *
     * A clone has no use for one. It is a snapshot of the very thing being copied, taken months
     * earlier, and nothing on the site ever serves it. Matched only at the top level, so a theme
     * that legitimately ships a `.zip` asset deep in its own folder is untouched.
     */
    private const ROOT_ARCHIVE_PATTERNS = [
        '/^backup[-_.].*\.(tar\.gz|tgz|tar|zip|gz)$/i',
        '/^.*-backup\.(tar\.gz|tgz|tar|zip|gz)$/i',
        '/\.(sql|sql\.gz|sql\.zip)$/i',
        '/\.wpress$/i',           // All-in-One WP Migration
        '/^wp-content\.(zip|tar\.gz)$/i',
    ];

    /** Whether a path is an archive sitting loose in the webroot. */
    private function isRootArchive(string $rel): bool
    {
        if (strpos($rel, '/') !== false) {
            return false;
        }
        foreach (self::ROOT_ARCHIVE_PATTERNS as $pattern) {
            if (preg_match($pattern, $rel) === 1) {
                return true;
            }
        }
        return false;
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
                $rel = $this->relative($file->getPathname());
                if ($this->isRootArchive($rel)) {
                    continue;
                }
                $out[] = $rel;
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

    /** The absolute path of a relative one, having checked it does not leave the webroot. */
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

    /** Reads one file, by relative path. Refuses anything outside the webroot. */
    public function readFile(string $rel): string
    {
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
