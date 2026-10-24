<?php
/**
 * FileWalker — an ordered, resumable walk of the webroot that leaves out what nobody wants.
 *
 * Returns one batch of paths at a time, relative to the webroot, with size and sha1 — or size and
 * mtime, or one digest over a page of them, neither of which reads a file. The caller keeps the
 * cursor — the last path of the previous batch — and asks again; nothing about the walk is
 * remembered between requests. The order is sorted and therefore stable, which is what
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
            'wp-content/ew-backup',        // EWWW Image Optimizer — the pre-compression originals
            'wp-content/wpvivid',          // WPvivid
            'wp-content/backuply',         // Backuply
            'wp-content/backup',           // several plugins, and hand-made
            'wp-content/backups',
            'wp-content/litespeed',        // LiteSpeed cache
            'wp-content/et-cache',         // Divi
            'wp-content/uploads/wp-clone',
        ];
    }

    /**
     * A clone needs what the LIVE site serves, and nothing else.
     *
     * `ew-backup` is the case that taught this: EWWW Image Optimizer compresses what is in
     * `uploads` and files the untouched original away, so a site with it installed carries a
     * second, heavier copy of its whole media library that no page ever requests. On
     * juneflower.vn that sat behind a 1.6 GB webroot archive, and between them they were nearly
     * the entire copy (2026-08-14).
     *
     * The rule the list follows: a directory is cut when nothing the site serves reads from it.
     * Caches rebuild themselves, backups are snapshots of the very thing being copied, and
     * originals kept "just in case" are for restoring the source, not for running the clone.
     */

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
        '/^wp-content\.(zip|tar\.gz)$/i',
    ];

    /**
     * Archives no running site reads, wherever they sit.
     *
     * A `.tar.gz`, a `.sql` dump or a `.wpress` package is never served to a visitor and never
     * read by PHP at runtime — they exist to move a site, not to run one. `.zip` is deliberately
     * NOT here: plugins and themes do ship legitimate ones inside their own folders, so it stays
     * a top-level-only match under ROOT_ARCHIVE_PATTERNS.
     */
    private const DEAD_WEIGHT_PATTERNS = [
        '/\.(tar\.gz|tgz|tar)$/i',
        '/\.sql(\.gz|\.zip|\.bz2)?$/i',
        '/\.wpress$/i',           // All-in-One WP Migration
    ];

    /** Whether a path is an archive the live site has no use for. */
    private function isRootArchive(string $rel): bool
    {
        foreach (self::DEAD_WEIGHT_PATTERNS as $pattern) {
            if (preg_match($pattern, $rel) === 1) {
                return true;
            }
        }
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
     * The same batch as {@see listBatch}, with the modification time in place of the sha1.
     *
     * Same walk, same exclusions, same order and the same cursor rule, so a caller can switch
     * between the two answers page for page. Nothing is read here: size and mtime are one stat
     * per file, where a sha1 is every byte of it. On a real site of 34 000 files that difference
     * is what makes "has anything changed?" a question worth asking on every sync.
     *
     * @return array{files:array<int,array{path:string,size:int,mtime:int}>, next_cursor:?string, done:bool}
     */
    public function statBatch(string $after, int $limit): array
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
            [$size, $mtime] = $this->sizeAndTime($rel);
            $files[] = ['path' => $rel, 'size' => $size, 'mtime' => $mtime];
        }
        return [
            'files'       => $files,
            'next_cursor' => count($slice) ? end($slice) : null,
            'done'        => $start + count($slice) >= count($all),
        ];
    }

    /**
     * One page of the walk folded into a single digest: what a caller compares with the digest it
     * kept from the last time, to learn whether this stretch of the tree moved without listing it.
     *
     * The page starts at the first path that sorts AFTER `$after` and holds at most `$limit` paths.
     * A fixed count, never a clock, so the same tree cuts into the same pages every time and a
     * digest from last week still describes the same stretch. "After" is a comparison, not a
     * lookup: listBatch() starts again from the top when its cursor is no longer in the tree, and
     * here a deleted cursor file would then turn every later page into a different one.
     *
     * The digest is sha1 over `path NUL size NUL mtime LF` for each path of the page, in order. No
     * byte of any file is read, so a change that keeps both the size and the mtime goes unseen;
     * {@see hashPaths} is the expensive question for the files a caller does want to be sure of.
     *
     * @return array{count:int, bytes:int, digest:string, next_after:?string}
     */
    public function fingerprintPage(string $after, int $limit): array
    {
        if ($limit < 1) {
            throw new InvalidArgumentException('limit must be >= 1');
        }
        $all = $this->allPaths();
        $total = count($all);
        $start = 0;
        if ($after !== '') {
            // The first path greater than the cursor, by the same byte order sort(SORT_STRING) used.
            $lo = 0;
            $hi = $total;
            while ($lo < $hi) {
                $mid = intdiv($lo + $hi, 2);
                if (strcmp($all[$mid], $after) <= 0) {
                    $lo = $mid + 1;
                } else {
                    $hi = $mid;
                }
            }
            $start = $lo;
        }
        $slice = array_slice($all, $start, $limit);
        $ctx = hash_init('sha1');
        $bytes = 0;
        foreach ($slice as $rel) {
            [$size, $mtime] = $this->sizeAndTime($rel);
            $bytes += $size;
            hash_update($ctx, $rel . "\0" . $size . "\0" . $mtime . "\n");
        }
        $more = $start + count($slice) < $total;
        return [
            'count'      => count($slice),
            'bytes'      => $bytes,
            'digest'     => hash_final($ctx),
            'next_after' => $more && $slice !== [] ? end($slice) : null,
        ];
    }

    /**
     * The sha1 of each path asked for, or null where there is no file this walker would list and
     * file.read would serve.
     *
     * Null rather than an error per path, so one stale name in a list of five hundred does not
     * cost the other 499. The checks are file.read's and then some: no absolute path, no `.` or
     * `..` segment, no protected path ({@see isSecretPath}); no archive the walk leaves out
     * ({@see isRootArchive}), nothing under a directory it leaves out or a symlinked directory it
     * does not descend into; and the real path must stay inside the webroot and be a regular
     * file, which is what stops a symlink leading out.
     *
     * @param string[] $paths
     * @return array<string,?string>
     */
    public function hashPaths(array $paths): array
    {
        $out = [];
        foreach ($paths as $rel) {
            $abs = $this->walkedFile($rel);
            $sha = $abs === null ? false : @sha1_file($abs);
            $out[$rel] = $sha === false ? null : $sha;
        }
        return $out;
    }

    /** The absolute path of a file the walk lists and file.read serves, or null. */
    private function walkedFile(string $rel): ?string
    {
        if ($rel === '' || strpos($rel, "\0") !== false || strpos($rel, '\\') !== false) {
            return null;
        }
        if ($rel[0] === '/' || preg_match('~^[A-Za-z]:~', $rel)) {
            return null;
        }
        $parts = explode('/', $rel);
        foreach ($parts as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                return null;
            }
        }
        if ($this->isSecretPath($rel) || $this->isRootArchive($rel)) {
            return null;
        }
        $prefix = '';
        $last = count($parts) - 1;
        for ($i = 0; $i < $last; $i++) {
            $prefix = $i === 0 ? $parts[0] : $prefix . '/' . $parts[$i];
            foreach ($this->skipDirs as $skip) {
                // Lowercased, unlike the walk: on a filesystem that ignores case, `Cache/x` is the
                // file the walk leaves out under `cache/`. Refusing too much is the safe side.
                if (strtolower($prefix) === strtolower($skip)) {
                    return null;
                }
            }
            if (is_link($this->root . '/' . $prefix)) {
                return null;
            }
        }
        $abs = realpath($this->root . '/' . $rel);
        if ($abs === false || strpos($abs, $this->root . DIRECTORY_SEPARATOR) !== 0) {
            return null;
        }
        return is_file($abs) ? $abs : null;
    }

    /**
     * Size and mtime of one walked path, from one stat. A file removed since the walk reads as
     * 0 and 0, as `(int) filesize()` already does in listBatch().
     *
     * @return array{0:int,1:int}
     */
    private function sizeAndTime(string $rel): array
    {
        $st = @stat($this->root . '/' . $rel);
        if ($st === false) {
            return [0, 0];
        }
        return [(int) $st['size'], (int) $st['mtime']];
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
     * The paths of `$paths` this walk lists, in the walk's own order: what files.pack packs when it
     * is handed `paths`. One the walk does not list (gone since, or under a skipped directory) is
     * left out, never an error — the same tree a whole pack would have taken, cut to the names asked.
     *
     * @param string[] $paths
     * @return string[]
     */
    public function listedPaths(array $paths): array
    {
        $want = [];
        foreach ($paths as $path) {
            $want[(string) $path] = true;
        }
        return array_values(array_filter($this->allPaths(), static function (string $path) use ($want): bool {
            return isset($want[$path]);
        }));
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
     * well-formed path that stays inside, and `wp-config.php` is exactly that shape: the database
     * password and the eight keys and salts every login cookie is signed with. The Joomla engine
     * has refused its `configuration.php` since it had a read action; this one handed
     * `wp-config.php` to file.read until the cheap sync questions (site.fingerprint, files.hash)
     * came here, which list it by size and mtime and must not hash it either.
     *
     * Entries ending in '/' are directory prefixes. Everything is compared lowercased, so the
     * check does not depend on whether the host filesystem is case-sensitive. A rotated copy keeps
     * the secrets and the stem: `wp-config.php.bak`, `wp-config.php-2026-01-01` (and, at the top
     * level, any `wp-config…` but core's placeholder-only `wp-config-sample.php`: {@see
     * isSecretPath}).
     */
    public const SECRET_PATHS = [
        'wp-config.php',
        '.env',
        '.env.local',
        '.htpasswd',
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

    /** True when a relative path is one this plugin must never hand back. */
    public function isSecretPath(string $rel): bool
    {
        $norm = strtolower($this->normalise($rel));
        // The configuration's other names at the top: `wp-config-old.php`, `wp-config.bak`. Only
        // there, so a plugin's own `wp-config-editor.php` deep in its folder is still read alone.
        if (strpos($norm, '/') === false && strpos($norm, 'wp-config') === 0 && $norm !== 'wp-config-sample.php') {
            return true;
        }
        // A copy of the configuration in a folder below (a staging copy, a WordPress in a
        // subdirectory) holds a database password too.
        $base = basename($norm);
        if ($base === 'wp-config.php' || strpos($base, 'wp-config.php.') === 0 || strpos($base, 'wp-config.php-') === 0) {
            return true;
        }
        foreach (self::SECRET_PATHS as $deny) {
            if (substr($deny, -1) === '/') {
                if (strpos($norm, $deny) === 0) {
                    return true;
                }
                continue;
            }
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
        return $this->archivePath($rel);
    }

    /**
     * The same path check, for a file being packed into the site's OWN archive.
     *
     * Two questions, as on Joomla, where one method answering both broke every export:
     *
     *   1. May a caller ask this plugin to hand back `wp-config.php`? **No** — that is what
     *      {@see absolutePath} and {@see readFile} refuse.
     *   2. May the site's own backup contain `wp-config.php`? **It must.** Import and Sync from
     *      live merge the customer's own file (its table prefix, its constants) into the copy's;
     *      a pack that left it out would rebuild the site on the wrong settings.
     *
     * What this does NOT relax: the confinement below. `realpath` plus the prefix test still
     * stop `../../etc/passwd` and a symlink pointing out of the tree. The caller of this is the
     * packer, and a token holder could already pack the whole site.
     */
    public function archivePath(string $rel): string
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
