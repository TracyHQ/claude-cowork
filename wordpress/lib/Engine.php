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

final class Engine
{
    private ?string $token;
    /** @var array<string,mixed> What the host says about itself, returned by the 'info' action. */
    private array $info;
    private ?DbDumper $dumper;
    private ?FileWalker $walker;
    private ?Uploader $uploader;

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
        $offset = max(0, (int) ($p['offset'] ?? 0));
        // The size this file's header already declared, handed back by the caller with the rest
        // of the cursor. See where it is used for why re-reading it would be wrong.
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

            while (true) {
                $path = $queue[$qi] ?? '';
                if ($path === '') {
                    // Out of files: the two empty blocks that close an archive, and only ever
                    // on the final part.
                    $buf .= TarStream::endOfArchive();
                    $done = true;
                    break;
                }

                // Stop BEFORE writing a header unless there is room for the header AND at
                // least one byte after it.
                //
                // Writing a header and only then noticing the part is full means the content
                // loop never runs, so $offset stays 0, so the returned cursor points back at
                // this same file at offset 0 — and the next call writes its header a SECOND
                // time. That spare 512 bytes shifts everything after it: tar finds content
                // where a header should be, loses an entry, and exits non-zero.
                //
                // The room for the header is the part this originally missed. Testing only
                // `strlen($buf) >= $target` catches a part that was ALREADY full and not one
                // that the header itself fills — which is the same bug one step earlier, and it
                // fires whenever a file's header happens to land within 512 bytes of the
                // boundary. On a real 11.205-file webroot that was twice in one run, at exactly
                // 48 MiB and 160 MiB. A fixture of five small files never reaches either edge.
                if ($offset === 0 && strlen($buf) + TarStream::BLOCK_BYTES >= $target) {
                    break;
                }

                $abs  = $this->walker->absolutePath($path);

                // A tar entry must be EXACTLY as long as its own header says, and the header for
                // a file spanning parts was written in an earlier request. A webroot is not
                // frozen while a pack runs — it takes minutes on a real site, and a log grows, a
                // cache file is rewritten, a session is dropped. Re-reading filesize() on the
                // second part therefore streams a different number of bytes than the header
                // declared, and everything after that entry shifts by the difference: tar finds
                // content where a header should be, reports "Skipping to next header", loses an
                // entry and exits non-zero. Measured on a real site (2026-08-10): 11.203 of
                // 11.204 entries survived, the break exactly at a part seam.
                $size = ($offset > 0 && $declared > 0) ? $declared : (int) filesize($abs);

                if ($offset === 0) {
                    $buf .= TarStream::fileHeader($path, $size, fileperms($abs) & 0777, (int) filemtime($abs));
                    $files++;
                }

                // Read in pieces, stopping the moment the part is full. The whole file is
                // never loaded.
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
                        // The file is now SHORTER than its header declared — truncated or
                        // rewritten while the pack was running. Zero-fill the remainder rather
                        // than stopping short: the entry has to match its header, and an archive
                        // holding a file padded with nulls is recoverable where a shifted one is
                        // not.
                        $fill = min($size - $offset, $target - strlen($buf));
                        $buf    .= str_repeat("\0", $fill);
                        $offset += $fill;
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
                    // The look-ahead ran out but the tree has not: ask for more, starting after
                    // the file just finished.
                    if ($qi >= count($queue)) {
                        $more = $this->walker->pathsAfter($path, self::PACK_LOOKAHEAD);
                        if ($more !== []) {
                            $queue = $more;
                            $qi = 0;
                        }
                    }
                }

                // Full, so stop — but only in the middle of a file ($offset > 0, header already
                // written). Having just finished one, the loop goes round and leaves at the check
                // above instead, which keeps the cursor on the next file at offset 0.
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
            'next_offset' => $offset,
            // Only meaningful mid-file, which is the only time the caller must hand it back.
            'next_size'   => $offset > 0 ? $size : 0,
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
