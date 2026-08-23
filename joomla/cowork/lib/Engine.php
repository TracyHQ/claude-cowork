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
require_once __DIR__ . '/Extensions.php';
require_once __DIR__ . '/SiteWriter.php';
require_once __DIR__ . '/ChangeStamp.php';
require_once __DIR__ . '/CoreUpgrader.php';
require_once __DIR__ . '/FilesRestorer.php';

final class Engine
{
    private ?string $token;
    /** @var array<string,mixed> What the host says about itself, returned by the 'info' action. */
    private array $info;
    private ?DbDumper $dumper;
    private ?FileWalker $walker;
    private ?Uploader $uploader;
    private ?ExtensionManager $extensions;
    private ?SiteWriter $writer;
    private ?MediaWriter $media;
    private ?ApplyLog $log;
    /** Optional: absent in tests and on a host whose webroot cannot be written. */
    private ?ChangeStamp $stamp;
    private ?CoreUpgrader $upgrader;
    private ?FilesRestorer $filesRestorer;

    private const MAX_DB_LIMIT = 5000;
    private const MAX_FILE_LIMIT = 500;
    private const MAX_READ_BYTES = 8388608; // 8 MiB, for reading a single file
    /** Object stores require every part but the last to be at least 5 MiB. A floor, not a preference. */
    private const MIN_PART_BYTES = 5242880;
    /** The ceiling on what is held in memory at once. Shared hosts commonly allow 128M in total. */
    private const MAX_PART_BYTES = 33554432; // 32 MiB
    /** Files are read in pieces, so a large one is never resident in memory in full. */
    private const READ_CHUNK = 1048576;
    /** A single media upload carried inline as base64. Larger assets belong on the signed-URL path. */
    private const MAX_MEDIA_BYTES = 8388608; // 8 MiB
    /**
     * media.upload may only land a file under Joomla's two conventional media trees — never code.
     * A template's PHP override is not media: it reaches a site as part of an extension package or
     * through git, not this action. Without this a well-formed path like `configuration.php` passes
     * every other check and overwrites live code.
     */
    private const MEDIA_ROOTS = ['images/', 'media/'];
    /** How many paths to fetch at once, so the pack loop never asks the walker file by file. */
    private const PACK_LOOKAHEAD = 100000;

    public function __construct(
        ?string $token,
        array $info = [],
        ?DbDumper $dumper = null,
        ?FileWalker $walker = null,
        ?Uploader $uploader = null,
        ?ExtensionManager $extensions = null,
        ?SiteWriter $writer = null,
        ?MediaWriter $media = null,
        ?ApplyLog $log = null,
        ?ChangeStamp $stamp = null,
        ?CoreUpgrader $upgrader = null,
        ?FilesRestorer $filesRestorer = null) {
        $this->token = $token;
        $this->info = $info;
        $this->dumper = $dumper;
        $this->walker = $walker;
        $this->uploader = $uploader;
        $this->extensions = $extensions;
        $this->writer = $writer;
        $this->media = $media;
        $this->log = $log;
        $this->stamp = $stamp;
        $this->upgrader = $upgrader;
        $this->filesRestorer = $filesRestorer;
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
            case 'extension.list':
                return $this->extensionList();
            case 'core.manifest':
                return $this->coreManifest();
            case 'extension.install':
                return $this->extensionInstall($params);
            case 'content.list':
                return $this->contentList($params);
            case 'content.get':
                return $this->contentGet($params);
            case 'content.update':
                return $this->contentUpdate($params);
            case 'content.delete':
                return $this->contentDelete($params);
            case 'media.upload':
                return $this->mediaUpload($params);
            case 'apply.revert':
                return $this->applyRevert($params);
            case 'apply.list':
                return $this->applyList($params);
            case 'core.upgrade':
                return $this->coreUpgrade($params);
            case 'files.restore':
                return $this->filesRestore($params);
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

                // For the ARCHIVE, not for a caller — see FileWalker::archivePath.
                $abs = $this->walker->archivePath($path);

                // A tar entry must be EXACTLY as long as its own header says, and the header for
                // an entry spanning parts was written in an earlier request. A webroot is not
                // frozen while a pack runs — it takes minutes on a real site, and a log grows, a
                // cache file is rewritten, a session is dropped. Re-reading filesize() on a later
                // part would stream a different number of bytes than the header declared, and
                // everything after that entry would shift.
                $size = ($entryOffset > 0 && $declared > 0) ? $declared : (int) filesize($abs);
                $declared = $size;
                $headerEnd  = TarStream::BLOCK_BYTES;
                $contentEnd = $headerEnd + $size;
                $entryEnd   = $contentEnd + strlen(TarStream::pad($size));

                // ── the header, possibly a slice of it ──────────────────────────────────────
                if ($entryOffset < $headerEnd) {
                    if ($entryOffset === 0) {
                        $files++;
                    }
                    $header = TarStream::fileHeader($path, $size, fileperms($abs) & 0777, (int) filemtime($abs));
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

    /**
     * What the site already has. Answered before an install so a caller can decide whether one
     * is needed at all, and after it to prove the package took.
     */
    private function extensionList(): array
    {
        if ($this->extensions === null) {
            return $this->err('unavailable', 'extension manager not wired');
        }
        try {
            $installed = $this->extensions->listInstalled();
        } catch (Throwable $e) {
            return $this->err('list_failed', $e->getMessage());
        }
        return $this->ok(['extensions' => $installed]);
    }

    /**
     * The per-site core source (ADR 0070 addendum): which of this site's extensions ship with
     * the CMS. Tracy's zone gate reads this instead of a static list, because a static list is
     * a copy of one install on one day — the site's own records are neither.
     */
    private function coreManifest(): array
    {
        if ($this->extensions === null) {
            return $this->err('unavailable', 'extension manager not wired');
        }
        try {
            return $this->ok(['manifest' => $this->extensions->coreManifest()]);
        } catch (Throwable $e) {
            return $this->err('manifest_failed', $e->getMessage());
        }
    }

    /**
     * The only action that changes the site.
     *
     * Bounded on purpose: one https `.zip`, fetched by the site, installed through Joomla's own
     * installer. There is no uninstall and no way to name a local path — a caller holding this
     * token can add to a site, never quietly remove from it or read a file off its disk by
     * pointing the installer somewhere odd.
     */
    private function extensionInstall(array $p): array
    {
        if ($this->extensions === null) {
            return $this->err('unavailable', 'extension manager not wired');
        }
        $url = isset($p['url']) && is_string($p['url']) ? trim($p['url']) : '';
        if ($url === '') {
            return $this->err('bad_params', 'url required');
        }
        $shape = PackageUrl::check($url);
        if ($shape['ok'] !== true) {
            return $this->err('bad_params', $shape['error']);
        }

        try {
            $result = $this->extensions->installFromUrl($url);
        } catch (Throwable $e) {
            return $this->err('install_failed', $e->getMessage());
        }
        if (($result['ok'] ?? false) !== true) {
            return $this->err('install_failed', (string) ($result['error'] ?? 'installer refused the package'));
        }

        $this->stamped('extension');

        return $this->ok([
            'installed' => [
                'name'    => $result['name'] ?? null,
                'type'    => $result['type'] ?? null,
                'version' => $result['version'] ?? null,
            ],
        ]);
    }

    /**
     * Apply one edit to the site's content, and remember how to undo it in the same breath.
     *
     * The order is deliberate: read the before-state, write, then record the undo. A write that
     * cannot have its undo recorded is rolled back on the spot rather than left standing — an
     * un-revertible change is exactly what ADR 0048 forbids an Apply from making. Both the writer
     * and the log must be wired: a site that can be written but not reverted is not one this action
     * will touch.
     */
    /**
     * One page of a kind's rows, as summaries — the read half of the content mirror (ADR 0071).
     *
     * Read-only: no apply_id, nothing recorded, nothing stamped. The page size is capped so a
     * caller cannot ask a shared host for its whole content table in one request; paging to the
     * end is the caller's loop. An empty page is the answer "you are past the end", not an error.
     */
    private function contentList(array $p): array
    {
        if ($this->writer === null) {
            return $this->err('unavailable', 'site writer not wired');
        }
        $kind = isset($p['kind']) && is_string($p['kind']) ? $p['kind'] : 'article';
        if (!in_array($kind, SiteWriter::KINDS, true)) {
            return $this->err('bad_params', 'kind must be one of: ' . implode(', ', SiteWriter::KINDS));
        }
        $offset = max(0, (int) ($p['offset'] ?? 0));
        // Bodies make a page heavy, so a page that carries them is a smaller page. The caller
        // asks for them because the alternative — one request per row — spends a caller's whole
        // hourly allowance on a single site's article list.
        $withBody = !empty($p['include_body']);
        $ceiling = $withBody ? 25 : 200;
        $limit = min($ceiling, max(1, (int) ($p['limit'] ?? ($withBody ? 25 : 100))));

        try {
            $items = $this->writer->list($kind, $offset, $limit);
            if ($withBody) {
                foreach ($items as $index => $row) {
                    $full = $this->writer->read($kind, (int) $row['id']);
                    if ($full !== null) {
                        $items[$index] = $full + $row; // summary keeps category_title/category_path
                    }
                }
            }
        } catch (Throwable $e) {
            return $this->err('read_failed', $e->getMessage());
        }

        return $this->ok(['kind' => $kind, 'offset' => $offset, 'items' => $items]);
    }

    /**
     * One full row, exactly as the site holds it right now. The same read the undo log takes
     * before a write, offered to a caller — which is what makes the mirror's checksum honest:
     * export and apply hash the same bytes this returns.
     */
    private function contentGet(array $p): array
    {
        if ($this->writer === null) {
            return $this->err('unavailable', 'site writer not wired');
        }
        $kind = isset($p['kind']) && is_string($p['kind']) ? $p['kind'] : 'article';
        if (!in_array($kind, SiteWriter::KINDS, true)) {
            return $this->err('bad_params', 'kind must be one of: ' . implode(', ', SiteWriter::KINDS));
        }
        $id = max(0, (int) ($p['id'] ?? 0));
        if ($id === 0) {
            return $this->err('bad_params', 'id required');
        }

        try {
            $item = $this->writer->read($kind, $id);
        } catch (Throwable $e) {
            return $this->err('read_failed', $e->getMessage());
        }
        if ($item === null) {
            return $this->err('not_found', "no {$kind} with id {$id}");
        }

        return $this->ok(['kind' => $kind, 'id' => $id, 'item' => $item]);
    }

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
        if (!isset($p['fields']) || !is_array($p['fields'])) {
            return $this->err('bad_params', 'fields required');
        }
        $fields = $p['fields'];
        $id = max(0, (int) ($p['id'] ?? 0));
        if ($id === 0 && !$this->writer->canCreate($kind)) {
            // The refusal must NAME the boundary (ADR 0080 §4): the agent has no other door to
            // wander to, so this sentence is all it gets to explain itself to the user.
            return $this->err('unsupported', "a {$kind} cannot be created through Apply — only existing ones can be edited");
        }

        // On an EXISTING tree node, parent_id / move_after are a MOVE — re-hanging the node, with
        // everything that drags along (lft/rgt, the path chain of every descendant) — not columns
        // to write. Peeled off here so they never reach the raw whitelist; on a create (id 0)
        // parent_id stays in $fields, where it is placement, not movement. Note flat re-homing
        // (an article to another category) is NOT this: catid is an ordinary whitelisted column.
        $moveTo = null;
        if ($id > 0 && (array_key_exists('parent_id', $fields) || array_key_exists('move_after', $fields))) {
            $moveTo = [
                'parent' => (int) ($fields['parent_id'] ?? 0),
                'after'  => array_key_exists('move_after', $fields) ? (int) $fields['move_after'] : -1,
            ];
            unset($fields['parent_id'], $fields['move_after']);
        }
        if ($fields === [] && $moveTo === null) {
            return $this->err('bad_params', 'fields required');
        }

        if ($moveTo !== null) {
            if ($moveTo['after'] <= 0 && $moveTo['parent'] <= 0) {
                return $this->err('bad_params', 'moving needs parent_id (the new parent) or move_after (the sibling to follow)');
            }
            try {
                $posBefore = $this->writer->positionOf($kind, $id);
            } catch (Throwable $e) {
                return $this->err('read_failed', $e->getMessage());
            }
            if ($posBefore === null) {
                return $this->err('unsupported', "a {$kind} cannot be moved — only tree kinds (menuItem, category, tag) with an existing id can");
            }
            try {
                $this->writer->move($kind, $id, $moveTo['parent'], $moveTo['after']);
            } catch (Throwable $e) {
                return $this->err('write_failed', $e->getMessage());
            }
            try {
                $this->log->record($applyId, ['op' => 'move', 'kind' => $kind, 'id' => $id, 'before' => $posBefore]);
            } catch (Throwable $e) {
                try {
                    $this->writer->move($kind, $id, (int) $posBefore['parent_id'], (int) $posBefore['after']);
                } catch (Throwable $undo) {
                    // The recorder is down and so is the way back — the failure below says so.
                }
                return $this->err('write_failed', 'change was rolled back: could not record its undo');
            }
        }

        $before = null;
        $newId = $id;
        if ($fields !== []) {
            try {
                $before = $this->writer->read($kind, $id); // null => this is an insert, so its undo is a delete
                $newId = $this->writer->write($kind, $id, $fields);
            } catch (Throwable $e) {
                return $moveTo !== null
                    ? $this->err('write_failed', 'the move landed (revert via apply.revert); the field update failed: ' . $e->getMessage())
                    : $this->err('write_failed', $e->getMessage());
            }

            try {
                $this->log->record($applyId, ['op' => 'content', 'kind' => $kind, 'id' => $newId, 'before' => $before]);
            } catch (Throwable $e) {
                $this->rollbackContent($kind, $newId, $before);
                return $this->err('write_failed', 'change was rolled back: could not record its undo');
            }
        }

        try {
            $this->writer->purgeCache();
        } catch (Throwable $e) {
            // Best-effort by contract: a stale cache is not worth failing a change that landed.
        }

        $this->stamped('content');

        $out = ['kind' => $kind, 'id' => $newId, 'created' => $fields !== [] && $before === null && $id === 0];
        if ($moveTo !== null) {
            $out['moved'] = true;
        }
        return $this->ok($out);
    }

    /**
     * Soft-delete one target: a write of -2 into the kind's trash column (Joomla's own trash),
     * which is what keeps a delete revertable through the same undo log as any field edit —
     * `apply.revert` restores the previous state because that column sits on the kind's
     * whitelist. A kind with no trash column cannot be deleted through Apply at all: a menutype
     * holds a whole menu, a user is an identity, a template style may be the one serving the
     * home page. Hard deletes stay off this door on purpose.
     */
    private function contentDelete(array $p): array
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
        $trash = $this->writer->trashColumn($kind);
        if ($trash === null) {
            return $this->err('unsupported', "a {$kind} cannot be deleted through Apply");
        }
        $id = (int) ($p['id'] ?? 0);
        if ($id <= 0) {
            return $this->err('bad_params', 'id required');
        }

        try {
            $before = $this->writer->read($kind, $id);
            if ($before === null) {
                return $this->err('not_found', "no {$kind} with id {$id}");
            }
            $this->writer->write($kind, $id, [$trash => -2]);
        } catch (Throwable $e) {
            return $this->err('write_failed', $e->getMessage());
        }

        try {
            $this->log->record($applyId, ['op' => 'content', 'kind' => $kind, 'id' => $id, 'before' => $before]);
        } catch (Throwable $e) {
            $this->rollbackContent($kind, $id, $before);
            return $this->err('write_failed', 'change was rolled back: could not record its undo');
        }

        try {
            $this->writer->purgeCache();
        } catch (Throwable $e) {
            // Best-effort by contract.
        }

        $this->stamped('content');

        return $this->ok(['kind' => $kind, 'id' => $id, 'trashed' => true]);
    }

    /**
     * Put one file into the site's media folder, carried inline as base64, and remember how to
     * undo it. Same rollback rule as contentUpdate: if the undo cannot be recorded, the upload is
     * reversed rather than left behind. Anything past the inline ceiling belongs on the signed-URL
     * path, so the request the relay would have to proxy stays small.
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
            $this->media->write($path, $bytes);
        } catch (Throwable $e) {
            return $this->err('write_failed', $e->getMessage());
        }

        try {
            $this->log->record($applyId, ['op' => 'media', 'path' => $path, 'before' => $before]);
        } catch (Throwable $e) {
            $this->rollbackMedia($path, $before);
            return $this->err('write_failed', 'upload was rolled back: could not record its undo');
        }

        $this->stamped('media');

        return $this->ok(['path' => $path, 'bytes' => strlen($bytes), 'created' => $before === null]);
    }

    /**
     * Undo every step of one Apply, newest first. A step whose before-state was null was an
     * insert, so it is deleted; otherwise the recorded before-state is written back.
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

    /**
     * Say the site moved, if anyone gave us somewhere to say it.
     *
     * Called only after a change has actually landed — never on a refusal, and never before the
     * undo is recorded. A preview reloaded for a change that was rolled back shows the customer
     * the old site with a fresh timestamp, which reads as "something happened" when nothing did.
     */
    private function stamped(string $reason): void
    {
        if ($this->stamp !== null) {
            $this->stamp->touch($reason);
        }
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
            if ($op === 'content' || $op === 'move') {
                $step['kind'] = $entry['kind'] ?? null;
                $step['id'] = $entry['id'] ?? null;
            } elseif ($op === 'media') {
                $step['path'] = $entry['path'] ?? null;
            }
            return $step;
        }, $entries);
        return $this->ok(['apply_id' => $applyId, 'steps' => $steps]);
    }

    /** @param array<string,mixed> $entry */
    private function revertOne(array $entry): void
    {
        $op = isset($entry['op']) && is_string($entry['op']) ? $entry['op'] : '';
        if ($op === 'content') {
            if ($this->writer === null) {
                throw new RuntimeException('site writer not wired');
            }
            $this->rollbackContent((string) ($entry['kind'] ?? ''), (int) ($entry['id'] ?? 0), $entry['before'] ?? null);
            return;
        }
        if ($op === 'move') {
            if ($this->writer === null) {
                throw new RuntimeException('site writer not wired');
            }
            $before = is_array($entry['before'] ?? null) ? $entry['before'] : [];
            // `after` 0 means "it was the first child" — move() turns that into first-child of
            // the recorded parent, so the node returns to its exact old slot, not just its parent.
            $this->writer->move(
                (string) ($entry['kind'] ?? ''),
                (int) ($entry['id'] ?? 0),
                (int) ($before['parent_id'] ?? 0),
                (int) ($before['after'] ?? -1)
            );
            return;
        }
        if ($op === 'media') {
            if ($this->media === null) {
                throw new RuntimeException('media writer not wired');
            }
            $this->rollbackMedia((string) ($entry['path'] ?? ''), $entry['before'] ?? null);
            return;
        }
        throw new RuntimeException("unknown step: {$op}");
    }

    /** @param array<string,?scalar>|null $before */
    private function rollbackContent(string $kind, int $id, ?array $before): void
    {
        if ($before === null) {
            $this->writer->delete($kind, $id);
        } else {
            $this->writer->write($kind, $id, $before);
        }
    }

    private function rollbackMedia(string $path, ?string $before): void
    {
        if ($before === null) {
            $this->media->delete($path);
        } else {
            $this->media->write($path, $before);
        }
    }

    private function applyId(array $p): ?string
    {
        $id = isset($p['apply_id']) && is_string($p['apply_id']) ? trim($p['apply_id']) : '';
        return $id === '' ? null : $id;
    }

    /**
     * A media path is a relative path under one of the media roots and nothing else: no leading
     * slash, no `..`, no drive letter, no null byte, a conservative character set, and a first
     * segment that is a media tree. The real MediaWriter confines writes to the root as well — this
     * is the cheap refusal that keeps a hostile path from ever reaching it, and the media-root
     * requirement is what stops a valid-looking `configuration.php` from being treated as an asset.
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

    /** @param array<string,mixed> $extra */
    /**
     * One hop of a core upgrade. The engine validates the target and delegates the write to the
     * injected {@see CoreUpgrader}; the site-touching work lives in the real implementation, so
     * this stays testable with a fake.
     */
    /**
     * Lay a files.pack snapshot back over the web root. The reverse of files.pack, and the safe
     * half of a restore: it touches files, never the database. The engine validates the URL and
     * delegates the write to the injected {@see FilesRestorer}, so it stays testable with a fake.
     */
    private function filesRestore(array $p): array
    {
        if ($this->filesRestorer === null) {
            return $this->err('unavailable', 'files restorer not wired');
        }
        $getUrl = (isset($p['get_url']) && \is_string($p['get_url'])) ? $p['get_url'] : '';
        if ($getUrl === '') {
            return $this->err('bad_params', 'get_url required');
        }
        if (\strncmp($getUrl, 'https://', 8) !== 0 && \strncmp($getUrl, 'http://', 7) !== 0) {
            return $this->err('bad_params', 'get_url must be http(s)');
        }
        try {
            $result = $this->filesRestorer->restore($getUrl);
        } catch (\Throwable $e) {
            return $this->err('restore_failed', $e->getMessage());
        }
        if (($result['ok'] ?? false) !== true) {
            return $this->err('restore_failed', (string) ($result['error'] ?? 'the restorer refused'));
        }
        $this->stamped('files');
        return $this->ok(['files' => (int) ($result['files'] ?? 0)]);
    }

    private function coreUpgrade(array $p): array
    {
        if ($this->upgrader === null) {
            return $this->err('unavailable', 'core upgrader not wired');
        }
        $to = (isset($p['to']) && \is_string($p['to'])) ? $p['to'] : '';
        if (!\in_array($to, ['4.4', '5.4', '6.1'], true)) {
            return $this->err('bad_params', 'to must be one of: 4.4, 5.4, 6.1');
        }
        $step = (isset($p['step']) && \is_string($p['step'])) ? $p['step'] : '';
        if (!\in_array($step, ['prepare', 'finalise'], true)) {
            return $this->err('bad_params', 'step must be prepare or finalise');
        }
        try {
            $result = $this->upgrader->upgrade($to, $step);
        } catch (\Throwable $e) {
            return $this->err('upgrade_failed', $e->getMessage());
        }
        if (($result['ok'] ?? false) !== true) {
            return $this->err('upgrade_failed', (string) ($result['error'] ?? 'the upgrader refused'));
        }
        $this->stamped('core');
        return $this->ok([
            'to'      => $to,
            'step'    => $step,
            'version' => (string) ($result['version'] ?? ''),
        ]);
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
