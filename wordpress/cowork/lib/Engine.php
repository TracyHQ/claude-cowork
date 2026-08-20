<?php
/**
 * Engine — turns one HTTP call into one bounded piece of work and answers with a plain array.
 *
 * The guiding split is that this side stays simple and the caller stays clever. The engine holds
 * no loop and remembers nothing between calls: check the token, route the action, do at most one
 * chunk, return the result and where to carry on from. The caller keeps the cursor and comes
 * back. That is what lets an arbitrarily large site finish on a host that stops PHP after thirty
 * seconds, and it is why nothing here has to be restarted from the beginning when a request dies.
 *
 * Failures come back as {ok:false, error} rather than an HTTP 500, so a caller can tell a wrong
 * token from a missing table and retry only what is worth retrying.
 *
 * No Joomla dependency: the component wires the real implementations in, and the tests wire
 * fakes, which is why the whole engine can be exercised without a web server or a database.
 */

require_once __DIR__ . '/Token.php';
require_once __DIR__ . '/DbDumper.php';
require_once __DIR__ . '/FileWalker.php';
require_once __DIR__ . '/TarStream.php';
require_once __DIR__ . '/Uploader.php';
require_once __DIR__ . '/SiteWriter.php';

final class Engine
{
    private ?string $token;
    /** @var array<string,mixed> What the host says about itself, returned by the 'info' action. */
    private array $info;
    private ?DbDumper $dumper;
    private ?FileWalker $walker;
    private ?Uploader $uploader;
    /** Plugins and themes. Null on a site wired for reading only — every write action then refuses. */
    private $packages;
    /** The three halves of an Apply. Null on a site wired for reading only, same as $packages. */
    private ?SiteWriter $writer;
    private ?MediaWriter $media;
    private ?ApplyLog $log;
    /** Where to say the site moved, when anyone is watching. Null is silence, not an error. */
    private $stamp;

    private const MAX_DB_LIMIT = 5000;
    private const MAX_FILE_LIMIT = 500;
    private const MAX_READ_BYTES = 8388608; // 8 MiB, for reading a single file
    /** Object stores require every part but the last to be at least 5 MiB. A floor, not a preference. */
    private const MIN_PART_BYTES = 5242880;
    /** The ceiling on what is held in memory at once. Shared hosts commonly allow 128M in total. */
    private const MAX_PART_BYTES = 33554432; // 32 MiB
    /** Files are read in pieces, so a large one is never resident in memory in full. */
    private const READ_CHUNK = 1048576;
    /** How many paths to fetch at once, so the pack loop never asks the walker file by file. */
    private const PACK_LOOKAHEAD = 100000;
    /** The ceiling on a file carried inline as base64. Anything larger belongs on the signed-URL path. */
    private const MAX_MEDIA_BYTES = 8388608; // 8 MiB
    /**
     * Where an Apply may put a file. One root, because WordPress has one: everything a site adds
     * after installation goes under `uploads/`, and a path outside it is either code or somebody
     * else's plugin.
     */
    private const MEDIA_ROOTS = ['wp-content/uploads/'];

    public function __construct(
        ?string $token,
        array $info = [],
        ?DbDumper $dumper = null,
        ?FileWalker $walker = null,
        ?Uploader $uploader = null,
        $packages = null,
        ?SiteWriter $writer = null,
        ?MediaWriter $media = null,
        ?ApplyLog $log = null,
        $stamp = null
    ) {
        $this->token = $token;
        $this->info = $info;
        $this->dumper = $dumper;
        $this->walker = $walker;
        $this->uploader = $uploader;
        $this->packages = $packages;
        $this->writer = $writer;
        $this->media = $media;
        $this->log = $log;
        $this->stamp = $stamp;
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
            case 'site.stats':
                return $this->siteStats();
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
            case 'plugin.list':
                return $this->pluginList();
            case 'plugin.install':
                return $this->pluginInstall($params);
            case 'plugin.activate':
                return $this->pluginActivate($params);
            case 'theme.list':
                return $this->themeList();
            case 'theme.install':
                return $this->themeInstall($params);
            case 'theme.activate':
                return $this->themeActivate($params);
            case 'content.update':
                return $this->contentUpdate($params);
            case 'media.upload':
                return $this->mediaUpload($params);
            case 'apply.revert':
                return $this->applyRevert($params);
            case 'apply.list':
                return $this->applyList($params);
            default:
                return $this->err('bad_action', "unknown action: {$action}");
        }
    }

    /**
     * What this site is made of, before anything is copied: how many files and bytes, when the
     * newest file changed, and how many tables and rows there are.
     *
     * Everything here is a measurement, not a copy. It answers the two questions a caller has to
     * settle before committing anyone to a wait: how long will this take, and has the site moved
     * since the last time it was read.
     *
     * Each half is optional. A site whose database is unreachable can still be sized by its
     * files, and reporting what could be measured beats refusing to answer at all.
     */
    private function siteStats(): array
    {
        $out = [];

        if ($this->walker !== null) {
            try {
                $out['files'] = $this->walker->stats();
            } catch (Throwable $e) {
                $out['files_error'] = $e->getMessage();
            }
        }

        if ($this->dumper !== null) {
            try {
                $tables = $this->dumper->tableStats();
                $rows = 0;
                $bytes = 0;
                foreach ($tables as $t) {
                    $rows += $t['rows'];
                    $bytes += $t['bytes'];
                }
                $out['db'] = ['tables' => count($tables), 'rows' => $rows, 'bytes' => $bytes];
            } catch (Throwable $e) {
                $out['db_error'] = $e->getMessage();
            }
        }

        return $this->ok($out);
    }

    /**
     * The table names, so the caller discovers the schema instead of having to know it in
     * advance. Table prefixes are chosen at install time and cannot be assumed.
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
        $limit = (int) ($p['limit'] ?? 1000);
        $limit = max(1, min($limit, self::MAX_DB_LIMIT));

        // Which paging the caller asked for, decided by whether it mentioned `cursor` AT ALL —
        // not by whether the value is empty, because an empty cursor is how a new caller says
        // "start this table". A caller built against the older wire sends only `offset` and gets
        // the older answer, so a site can be updated before the desk that talks to it is.
        //
        // The reverse pairing is the dangerous one and is handled on the desk instead: a NEW
        // caller against an OLD site gets no `next_cursor` back, and if it kept sending `cursor`
        // while the site kept reading `offset` (always absent, so always 0) the loop would dump
        // the first batch forever. The desk checks for `next_cursor` in the answer and falls back.
        if (array_key_exists('cursor', $p)) {
            $cursor = is_string($p['cursor']) && $p['cursor'] !== '' ? $p['cursor'] : null;
            try {
                $chunk = $this->dumper->dumpChunkFrom($table, $cursor, $limit);
            } catch (Throwable $e) {
                return $this->err('dump_failed', $e->getMessage());
            }
            return $this->ok([
                'table'       => $table,
                'sql_b64'     => base64_encode($chunk['sql']),
                'next_cursor' => $chunk['next_cursor'],
                'done'        => $chunk['done'],
                'rows'        => $chunk['rows'],
                'total'       => $chunk['total'],
            ]);
        }

        $offset = max(0, (int) ($p['offset'] ?? 0));
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
     * Packs ONE part of a tar and sends it straight to a signed URL. No temporary file, and no
     * credential held here.
     *
     * Not one signed PUT for the whole archive: PHP being stopped halfway through sending 300 MB
     * on a shared host means starting again from nothing, which is the exact failure this design
     * exists to avoid. Multipart gives each part its own URL, so every call is bounded and
     * resumable. The caller keeps the upload id and the list of ETags and completes the upload
     * itself; this side knows nothing about any of that.
     *
     * The cursor has TWO parts — `path` and `offset`, the number of content bytes of that path
     * already sent. The offset is what lets a file larger than a whole part work: its header goes
     * in one part, its content spans several, and memory still holds only one part. With a
     * per-file cursor a 500 MB file would force an entire part into memory, which is fatal on
     * exactly the hosting this is built for.
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
        // Bytes already emitted of the ENTRY this cursor is inside — header, then content, then
        // padding. Not an offset within the file: a part must be able to stop inside a header.
        $entryOffset = max(0, (int) ($p['offset'] ?? 0));
        // The size that entry's header declared, handed back with the rest of the cursor.
        $declared = max(0, (int) ($p['size'] ?? 0));

        try {
            // Fetched once and walked in memory. The loop used to ask the walker for "the next
            // file" per file, and each of those was a search across the whole list — quadratic.
            // On a real webroot of 19,971 files PHP hit its thirty-second limit during the first
            // part. A fixture of five files never came close.
            $queue = $path === ''
                ? $this->walker->pathsAfter('', self::PACK_LOOKAHEAD)
                : array_merge([$path], $this->walker->pathsAfter($path, self::PACK_LOOKAHEAD));
            $qi = 0;

            $buf   = '';
            $files = 0;
            $done  = false;

            // Every part is EXACTLY $target bytes, except the last.
            //
            // Not a nicety: R2 refuses to assemble an upload whose non-trailing parts differ in
            // length ("All non-trailing parts must have the same length"), which S3 permits. The
            // only way to hit an exact size every time is to be able to stop anywhere — including
            // halfway through a 512-byte header — so the cursor counts bytes within an ENTRY
            // (header + content + padding) rather than bytes within a file.
            //
            // That also makes the older bug structurally impossible rather than guarded against:
            // a header can no longer be written twice, because a part that ends inside one
            // resumes inside it.
            while (strlen($buf) < $target) {
                $path = $queue[$qi] ?? '';
                if ($path === '') {
                    // Out of files: the two empty blocks that close an archive, and only ever
                    // on the final part.
                    $buf .= TarStream::endOfArchive();
                    $done = true;
                    break;
                }

                $abs = $this->walker->absolutePath($path);

                // A tar entry must be EXACTLY as long as its own header says, and the header for
                // an entry spanning parts was written in an earlier request. A webroot is not
                // frozen while a pack runs — it takes minutes on a real site, and a log grows, a
                // cache file is rewritten, a session is dropped. Re-reading filesize() on a later
                // part would stream a different number of bytes than the header declared, and
                // everything after that entry would shift.
                $size = ($entryOffset > 0 && $declared > 0) ? $declared : (int) filesize($abs);
                $declared = $size;
                // Built before it is measured, because it is no longer always one block: a path
                // USTAR cannot hold arrives as a PAX header, its record, and then the ordinary
                // header. Counting blocks instead of measuring would put the content at the wrong
                // offset for exactly the entries this exists to rescue.
                $header     = TarStream::fileHeader($path, $size, fileperms($abs) & 0777, (int) filemtime($abs));
                $headerEnd  = strlen($header);
                $contentEnd = $headerEnd + $size;
                $entryEnd   = $contentEnd + strlen(TarStream::pad($size));

                // ── the header, possibly a slice of it ──────────────────────────────────────
                if ($entryOffset < $headerEnd) {
                    if ($entryOffset === 0) {
                        $files++;
                    }
                    $take   = min($headerEnd - $entryOffset, $target - strlen($buf));
                    $buf         .= substr($header, $entryOffset, $take);
                    $entryOffset += $take;
                    if (strlen($buf) >= $target) {
                        break;
                    }
                }

                // ── the content ────────────────────────────────────────────────────────────
                if ($entryOffset < $contentEnd) {
                    $fh = fopen($abs, 'rb');
                    if ($fh === false) {
                        return $this->err('read_failed', "cannot open {$path}");
                    }
                    fseek($fh, $entryOffset - $headerEnd);
                    while ($entryOffset < $contentEnd && strlen($buf) < $target) {
                        $want  = min(self::READ_CHUNK, $contentEnd - $entryOffset, $target - strlen($buf));
                        $chunk = fread($fh, $want);
                        if ($chunk === false || $chunk === '') {
                            // The file is now SHORTER than its header declared — truncated or
                            // rewritten while the pack was running. Zero-fill the remainder
                            // rather than stopping short: the entry has to match its header, and
                            // an archive holding a file padded with nulls is recoverable where a
                            // shifted one is not.
                            $buf         .= str_repeat("\0", $want);
                            $entryOffset += $want;
                            continue;
                        }
                        $buf         .= $chunk;
                        $entryOffset += strlen($chunk);
                    }
                    fclose($fh);
                    if (strlen($buf) >= $target) {
                        break;
                    }
                }

                // ── the padding that rounds the entry to a whole block ──────────────────────
                if ($entryOffset < $entryEnd) {
                    $take = min($entryEnd - $entryOffset, $target - strlen($buf));
                    $buf         .= str_repeat("\0", $take);
                    $entryOffset += $take;
                    if (strlen($buf) >= $target) {
                        break;
                    }
                }

                // Entry finished: move on, and forget the size it declared.
                $qi++;
                $entryOffset = 0;
                $declared    = 0;
                // The look-ahead ran out but the tree has not: ask for more, starting after the
                // file just finished.
                if ($qi >= count($queue)) {
                    $more = $this->walker->pathsAfter($path, self::PACK_LOOKAHEAD);
                    if ($more !== []) {
                        $queue = $more;
                        $qi = 0;
                    }
                }
            }

            // A part that stopped exactly at an entry boundary carries no entry into the next
            // one, so the cursor names the file that is about to start.
            if ($entryOffset === 0 && !$done) {
                $path = $queue[$qi] ?? $path;
            }
        } catch (Throwable $e) {
            return $this->err('pack_failed', $e->getMessage());
        }

        $sha = hash('sha256', $buf);
        $put = $this->uploader->put($putUrl, $buf);
        if (!$put['ok']) {
            // The cursor does not move when the upload fails: the caller signs a fresh URL and
            // asks for this same part again.
            return $this->err('upload_failed', $put['error']);
        }

        return $this->ok([
            'bytes'       => strlen($buf),
            'files'       => $files,
            'sha256'      => $sha,
            'etag'        => $put['etag'],
            'next_path'   => $path,
            'next_offset' => $entryOffset,
            // Only meaningful mid-entry, which is the only time the caller must hand it back.
            'next_size'   => $entryOffset > 0 ? $declared : 0,
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


    /**
     * The shape a package URL must have before this site is asked to fetch it: one https `.zip`.
     *
     * Checked here rather than only inside the package manager so a caller's mistake is reported
     * as `bad_params` — something they can fix — instead of `install_failed`, which reads as the
     * site having tried and failed. The manager checks again; this is the classification.
     */
    private function packageUrl(array $p): array
    {
        $url = isset($p['url']) && is_string($p['url']) ? trim($p['url']) : '';
        if ($url === '') {
            return ['ok' => false, 'error' => 'url required'];
        }
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return ['ok' => false, 'error' => 'not a URL'];
        }
        if (strtolower((string) $parts['scheme']) !== 'https') {
            return ['ok' => false, 'error' => 'https required'];
        }
        if (substr(strtolower((string) ($parts['path'] ?? '')), -4) !== '.zip') {
            return ['ok' => false, 'error' => 'package URL must end in .zip'];
        }
        return ['ok' => true, 'url' => $url];
    }

    // ---- Plugins and themes -----------------------------------------------------------------
    //
    // WordPress keeps these apart and so does this: a theme is switched to and replaces the one
    // before it, a plugin is turned on beside the others. Flattening them into one "extension"
    // action the way Joomla does would hide that difference behind a `kind` parameter and make
    // every caller learn it anyway.

    /** @return array<string,mixed> */
    private function packagesReady(): ?array
    {
        return $this->packages === null
            ? $this->err('unavailable', 'package manager not wired')
            : null;
    }

    /** What is installed, before deciding whether an install is needed at all. */
    private function pluginList(): array
    {
        if ($refusal = $this->packagesReady()) {
            return $refusal;
        }
        return $this->ok(['plugins' => $this->packages->list_plugins()]);
    }

    private function themeList(): array
    {
        if ($refusal = $this->packagesReady()) {
            return $refusal;
        }
        return $this->ok(['themes' => $this->packages->list_themes()]);
    }

    /**
     * Install, and stop there.
     *
     * Activation is its own action because the two fail differently: a package can install and
     * still refuse to run (a PHP version it needs, a fatal on load). A site left with something
     * installed and off is recoverable; one left half-activated is a white screen with no admin
     * to undo it from.
     */
    private function pluginInstall(array $p): array
    {
        if ($refusal = $this->packagesReady()) {
            return $refusal;
        }
        $shape = $this->packageUrl($p);
        if ($shape['ok'] !== true) {
            return $this->err('bad_params', (string) $shape['error']);
        }
        $result = $this->packages->install_plugin($shape['url']);
        return ($result['ok'] ?? false) === true
            ? $this->ok(['installed' => $result])
            : $this->err('install_failed', (string) ($result['error'] ?? 'install failed'));
    }

    private function themeInstall(array $p): array
    {
        if ($refusal = $this->packagesReady()) {
            return $refusal;
        }
        $shape = $this->packageUrl($p);
        if ($shape['ok'] !== true) {
            return $this->err('bad_params', (string) $shape['error']);
        }
        $result = $this->packages->install_theme($shape['url']);
        return ($result['ok'] ?? false) === true
            ? $this->ok(['installed' => $result])
            : $this->err('install_failed', (string) ($result['error'] ?? 'install failed'));
    }

    private function pluginActivate(array $p): array
    {
        if ($refusal = $this->packagesReady()) {
            return $refusal;
        }
        $file = isset($p['file']) && is_string($p['file']) ? trim($p['file']) : '';
        if ($file === '') {
            return $this->err('bad_params', 'file required, e.g. akismet/akismet.php');
        }
        $result = $this->packages->activate_plugin_file($file);
        return ($result['ok'] ?? false) === true
            ? $this->ok(['activated' => $file, 'was_active' => $result['was_active'] ?? null])
            : $this->err('activate_failed', (string) ($result['error'] ?? 'activate failed'));
    }

    /**
     * Switch the live theme, and say what it was.
     *
     * `previous` is returned so the caller can put it back without having read the site first —
     * the one piece of state a theme switch destroys, handed over in the same breath.
     */
    private function themeActivate(array $p): array
    {
        if ($refusal = $this->packagesReady()) {
            return $refusal;
        }
        $stylesheet = isset($p['stylesheet']) && is_string($p['stylesheet']) ? trim($p['stylesheet']) : '';
        if ($stylesheet === '') {
            return $this->err('bad_params', 'stylesheet required, e.g. twentytwentytwo');
        }
        $result = $this->packages->activate_theme($stylesheet);
        return ($result['ok'] ?? false) === true
            ? $this->ok(['activated' => $stylesheet, 'previous' => $result['previous'] ?? null])
            : $this->err('activate_failed', (string) ($result['error'] ?? 'activate failed'));
    }

    // ---- Applying an approved change, and undoing it ------------------------------------------
    //
    // Every step here records how to reverse itself BEFORE it is called done, under an apply_id
    // the caller chose for the whole deliverable. That is what `apply.revert` replays, and it is
    // the condition ADR 0048 puts on a change being guaranteed at all.

    /**
     * Edit one piece of the site's content: a post, one of its meta values, or an option.
     *
     * Three kinds because WordPress keeps three shapes, not because three seemed like enough. A
     * post is a row with an id. A meta is a NAMED value hanging off a post — where every SEO
     * plugin keeps the description somebody is usually here to fix. An option is a name with no id
     * at all. Hence `key` beside `id`: the Joomla original needed only an id because all three of
     * its kinds were rows.
     */
    private function contentUpdate(array $p): array
    {
        if ($this->writer === null || $this->log === null) {
            return $this->err('unavailable', 'site writer not wired');
        }
        $applyId = $this->applyId($p);
        if ($applyId === null) {
            return $this->err('bad_params', 'apply_id required');
        }
        $kind = isset($p['kind']) && is_string($p['kind']) ? $p['kind'] : '';
        if (!in_array($kind, SiteWriter::KINDS, true)) {
            return $this->err('bad_params', 'kind must be one of: ' . implode(', ', SiteWriter::KINDS));
        }
        if (!isset($p['fields']) || !is_array($p['fields']) || $p['fields'] === []) {
            return $this->err('bad_params', 'fields required');
        }
        $id = max(0, (int) ($p['id'] ?? 0));
        $key = isset($p['key']) && is_string($p['key']) ? trim($p['key']) : '';

        try {
            $before = $this->writer->read($kind, $id, $key); // null => a create, so its undo is a delete
            $newId = $this->writer->write($kind, $id, $p['fields'], $key);
        } catch (Throwable $e) {
            return $this->err('write_failed', $e->getMessage());
        }

        try {
            $this->log->record($applyId, [
                'op' => 'content',
                'kind' => $kind,
                'id' => $newId,
                'key' => $key,
                'before' => $before,
            ]);
        } catch (Throwable $e) {
            $this->rollbackContent($kind, $newId, $key, $before);
            return $this->err('write_failed', 'change was rolled back: could not record its undo');
        }

        try {
            $this->writer->purgeCache();
        } catch (Throwable $e) {
            // Best-effort by contract: a stale cache is not worth failing a change that landed.
        }

        $this->stamped('content');

        return $this->ok([
            'kind' => $kind,
            'id' => $newId,
            'key' => $key === '' ? null : $key,
            'created' => $before === null,
        ]);
    }

    /**
     * Put one file into the site's uploads folder and into its Media Library, and remember how to
     * undo both. Same rollback rule as contentUpdate: if the undo cannot be recorded, the upload is
     * reversed rather than left behind. Anything past the inline ceiling belongs on the signed-URL
     * path, so the request the relay has to proxy stays small.
     */
    private function mediaUpload(array $p): array
    {
        if ($this->media === null || $this->log === null) {
            return $this->err('unavailable', 'media writer not wired');
        }
        $applyId = $this->applyId($p);
        if ($applyId === null) {
            return $this->err('bad_params', 'apply_id required');
        }
        $path = isset($p['path']) && is_string($p['path']) ? $p['path'] : '';
        if (!self::safeMediaPath($path)) {
            return $this->err('bad_params', 'unusable media path');
        }
        $b64 = isset($p['content_b64']) && is_string($p['content_b64']) ? $p['content_b64'] : '';
        $bytes = base64_decode($b64, true);
        if ($bytes === false) {
            return $this->err('bad_params', 'content_b64 is not valid base64');
        }
        if (strlen($bytes) > self::MAX_MEDIA_BYTES) {
            return $this->err('too_large', 'media exceeds the inline limit; use the signed-URL path');
        }

        try {
            $before = $this->media->read($path); // null => new file, so its undo is a delete
            $attachment = $this->media->write($path, $bytes);
        } catch (Throwable $e) {
            return $this->err('write_failed', $e->getMessage());
        }

        try {
            $this->log->record($applyId, [
                'op' => 'media',
                'path' => $path,
                'attachment' => $attachment,
                'before' => $before,
            ]);
        } catch (Throwable $e) {
            $this->rollbackMedia($path, $attachment, $before);
            return $this->err('write_failed', 'upload was rolled back: could not record its undo');
        }

        $this->stamped('media');

        return $this->ok([
            'path' => $path,
            'bytes' => strlen($bytes),
            'attachment' => $attachment,
            'created' => $before === null,
        ]);
    }

    /**
     * Undo every step of one Apply, newest first. A step whose before-state was null was a create,
     * so it is removed; otherwise the recorded before-state is written back.
     *
     * The Apply is only forgotten when every step came back. A revert that could not finish leaves
     * the log in place and names what is still standing, so the caller sees the truth rather than
     * a clean answer over a half-reverted site.
     */
    private function applyRevert(array $p): array
    {
        if ($this->log === null) {
            return $this->err('unavailable', 'apply log not wired');
        }
        $applyId = $this->applyId($p);
        if ($applyId === null) {
            return $this->err('bad_params', 'apply_id required');
        }
        try {
            $entries = $this->log->entries($applyId);
        } catch (Throwable $e) {
            return $this->err('revert_failed', $e->getMessage());
        }

        $reverted = 0;
        $failed = [];
        foreach (array_reverse($entries) as $entry) {
            try {
                $this->revertOne($entry);
                $reverted++;
            } catch (Throwable $e) {
                $failed[] = ['op' => $entry['op'] ?? '?', 'error' => $e->getMessage()];
            }
        }

        if ($this->writer !== null) {
            try {
                $this->writer->purgeCache();
            } catch (Throwable $e) {
                // Best-effort by contract.
            }
        }

        if ($reverted > 0) {
            $this->stamped('revert');
        }

        if ($failed === []) {
            try {
                $this->log->clear($applyId);
            } catch (Throwable $e) {
                // The site is back; a log row that would not clear is the caller's to reconcile.
            }
            return $this->ok(['reverted' => $reverted]);
        }
        return $this->ok(['reverted' => $reverted, 'failed' => $failed]);
    }

    /** What one Apply touched, without the before-payload — enough to verify, not to haul old bytes back. */
    private function applyList(array $p): array
    {
        if ($this->log === null) {
            return $this->err('unavailable', 'apply log not wired');
        }
        $applyId = $this->applyId($p);
        if ($applyId === null) {
            return $this->err('bad_params', 'apply_id required');
        }
        try {
            $entries = $this->log->entries($applyId);
        } catch (Throwable $e) {
            return $this->err('list_failed', $e->getMessage());
        }
        $steps = array_map(static function (array $entry): array {
            $op = $entry['op'] ?? '';
            $step = ['op' => $op, 'created' => ($entry['before'] ?? null) === null];
            if ($op === 'content') {
                $step['kind'] = $entry['kind'] ?? null;
                $step['id'] = $entry['id'] ?? null;
                $step['key'] = ($entry['key'] ?? '') === '' ? null : $entry['key'];
            } elseif ($op === 'media') {
                $step['path'] = $entry['path'] ?? null;
            }
            return $step;
        }, $entries);
        return $this->ok(['apply_id' => $applyId, 'steps' => $steps]);
    }

    /**
     * Say the site moved, if anyone gave us somewhere to say it.
     *
     * Called only after a change has actually landed — never on a refusal, and never before the
     * undo is recorded. A preview reloaded for a change that was rolled back shows the customer
     * the old site with a fresh timestamp, which reads as "something happened" when nothing did.
     *
     * A post edit stamps twice: once through `save_post`, once here. Both write the same file, so
     * the cost is one extra write and the alternative — leaving this out — would leave a meta or
     * an option change telling nobody, since neither fires a hook the plugin listens to.
     */
    private function stamped(string $reason): void
    {
        if ($this->stamp !== null) {
            $this->stamp->touch($reason);
        }
    }

    /** @param array<string,mixed> $entry */
    private function revertOne(array $entry): void
    {
        $op = isset($entry['op']) && is_string($entry['op']) ? $entry['op'] : '';
        if ($op === 'content') {
            if ($this->writer === null) {
                throw new RuntimeException('site writer not wired');
            }
            $this->rollbackContent(
                (string) ($entry['kind'] ?? ''),
                (int) ($entry['id'] ?? 0),
                (string) ($entry['key'] ?? ''),
                $entry['before'] ?? null
            );
            return;
        }
        if ($op === 'media') {
            if ($this->media === null) {
                throw new RuntimeException('media writer not wired');
            }
            $this->rollbackMedia(
                (string) ($entry['path'] ?? ''),
                (int) ($entry['attachment'] ?? 0),
                $entry['before'] ?? null
            );
            return;
        }
        throw new RuntimeException("unknown step: {$op}");
    }

    /** @param array<string,mixed>|null $before */
    private function rollbackContent(string $kind, int $id, string $key, ?array $before): void
    {
        if ($before === null) {
            $this->writer->delete($kind, $id, $key);
        } else {
            $this->writer->write($kind, $id, $before, $key);
        }
    }

    /**
     * Undo one upload. A file that was new goes away with its attachment — through the attachment
     * when there is one, because that is what takes the generated thumbnails with it. A file that
     * replaced another gets the old bytes back and keeps its attachment, whose dimensions the
     * writer rebuilds.
     */
    private function rollbackMedia(string $path, int $attachment, ?string $before): void
    {
        if ($before !== null) {
            $this->media->write($path, $before);
            return;
        }
        if ($attachment > 0) {
            $this->media->deleteAttachment($attachment);
        }
        // Called either way: an attachment delete that left the file, or a file that never had an
        // attachment, both end here with nothing on disk.
        $this->media->delete($path);
    }

    private function applyId(array $p): ?string
    {
        $id = isset($p['apply_id']) && is_string($p['apply_id']) ? trim($p['apply_id']) : '';
        return $id === '' ? null : $id;
    }

    /**
     * A media path is a relative path under the uploads folder and nothing else: no leading slash,
     * no `..`, no drive letter, no null byte, a conservative character set, and a first segment
     * that is the uploads tree. The real MediaWriter confines writes to the folder as well — this
     * is the cheap refusal that keeps a hostile path from ever reaching it, and the uploads-root
     * requirement is what stops a valid-looking `wp-config.php` from being treated as an asset.
     */
    private static function safeMediaPath(string $path): bool
    {
        if ($path === '' || strlen($path) > 1024) {
            return false;
        }
        if ($path[0] === '/' || $path[0] === '\\') {
            return false;
        }
        if (strpos($path, '..') !== false || strpos($path, "\0") !== false) {
            return false;
        }
        if (preg_match('#^[A-Za-z]:#', $path) === 1) {
            return false;
        }
        if (preg_match('#^[\w\-./]+$#', $path) !== 1) {
            return false;
        }
        foreach (self::MEDIA_ROOTS as $root) {
            if (strncmp($path, $root, strlen($root)) === 0) {
                return true;
            }
        }
        return false;
    }

    private function ok(array $extra): array
    {
        return array_merge(['ok' => true], $extra);
    }

    private function err(string $code, string $message): array
    {
        return ['ok' => false, 'error' => $code, 'message' => $message];
    }
}
