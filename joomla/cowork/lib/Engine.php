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
require_once __DIR__ . '/QuickstartContract.php';

final class Engine
{
    private ?QuickstartContract $contract;
    private bool $batching = false;
    private bool $writing = false;
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
        ?FilesRestorer $filesRestorer = null,
        ?QuickstartContract $contract = null) {
        $this->contract = $contract;
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
     * The profile a site under construction was provisioned FROM, when it has no contract yet.
     *
     * A Base site is a site whose template is still to be built: the provisioner writes
     * `tracy_build_baseline` and leaves `contract` empty on purpose, because a contract names the
     * presentation to protect and there is none yet. Such a site must answer the contract door
     * honestly — unbound, here is the baseline — rather than fall back to a default profile and
     * verify the Base files against another design's lock. Measured 14/09 on a fresh Base site: the
     * first `inspect` failed with "Presentation asset changed: templates/tracy/acm/accordion/css/
     * style.css", a file nobody had touched, and every build from a design with no template of its
     * own died on that line.
     */
    private ?string $constructionBaseline = null;

    /** Mark this receiver as serving a site under construction (no contract, a baseline profile). */
    public function underConstruction(string $baseline): self
    {
        $this->constructionBaseline = $baseline;
        return $this;
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

        if (!$this->writing && $this->writer && method_exists($this->writer, 'serialize') && in_array($action,
            ['content.contract', 'content.batch', 'content.update', 'content.delete', 'media.upload', 'apply.revert', 'extension.install', 'extension.enable', 'db.restore', 'db.rollback', 'db.cleanup', 'db.purge', 'core.upgrade', 'files.restore'], true)) {
            try {
                return $this->writer->serialize(function () use ($req) {
                    $this->writing = true;
                    try { return $this->handle($req); } finally { $this->writing = false; }
                });
            } catch (Throwable $error) { return $this->err('writer_busy', $error->getMessage()); }
        }

        try { $contractBound=$this->contract && $this->contract->bound(); }
        // The reason travels: since one base archive serves several designs, "unavailable" now also
        // means "this receiver does not carry the profile this site names", and an operator who
        // reads only "repair the component installation" goes looking in the wrong half.
        catch(Throwable $error) { return $this->err('contract_unavailable', $error->getMessage() ?: 'The private contract store is unavailable; repair the component installation'); }
        if ($contractBound && in_array($action, ['content.batch','content.update','content.delete','extension.install','extension.enable','db.restore','db.rollback','db.cleanup','db.purge','core.upgrade','files.restore'], true)) {
            return $this->err('content_only', 'This site is bound to a content-only quickstart contract');
        }
        if ($contractBound && $action === 'media.upload') {
            $path = $params['path'] ?? '';
            if (!is_string($path) || !preg_match('~^images/tracy-content/[a-f0-9]{64}\.(png|jpg|webp)$~D', $path)) return $this->err('content_only', 'Use a new content-addressed image in images/tracy-content');
            if (strpos((string)($params['apply_id']??''),'contract-')===0) return $this->err('content_only', 'Media uploads must use a separate apply receipt');
        }
        if ($contractBound && $action === 'apply.revert') {
            $entries = $this->log ? $this->log->entries($params['apply_id'] ?? '') : [];
            $receipts=array_values(array_filter($entries, fn($e) => ($e['op'] ?? '') === 'contract'));
            if (count($receipts)!==1) return $this->err('content_only', 'Only content-contract applies can be reverted in this mode');
            try {
                $state=$this->contract->inspect();
                if(($receipts[0]['afterRevision']??null)!==$state['revision']) return $this->err('content_only', 'Later content exists; revert the latest revision first');
                $this->batching=true;
                $result=$this->writer->transaction(function()use($params){
                    $result=$this->applyRevert($params);
                    if(!$result['ok'] || !empty($result['failed']))throw new RuntimeException('Contract revert failed');
                    $this->contract->inspect();
                    return $result;
                });
                $this->batching=false;
                try { $this->writer->purgeCache(); } catch(Throwable $ignored) {}
                $this->stamped('revert');
                return $result;
            } catch(Throwable $error) { return $this->err('contract_failed',$error->getMessage()); }
            finally { $this->batching=false; }
        }
        switch ($action) {
            case 'info':
                return $this->ok(['info' => $this->info]);
            case 'site.stats':
                return $this->siteStats();
            case 'db.tables':
                return $this->dbTables();
            case 'db.cleanup':
                return $this->dbCleanup($params);
            case 'db.restore':
                return $this->dbRestore($params);
            case 'db.purge':
                return $this->dbPurge($params);
            case 'db.dump':
                return $this->dbDump($params);
            case 'db.snapshot':
                return $this->dbSnapshot($params);
            case 'db.rollback':
                return $this->dbRollback($params);
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
            case 'extension.enable':
                return $this->extensionEnable($params);
            case 'content.list':
                return $this->contentList($params);
            case 'content.get':
                return $this->contentGet($params);
            case 'content.contract':
                return $this->contentContract($params);
            case 'content.batch':
                return $this->contentBatch($params);
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
        // `details` rides beside `tables` rather than replacing it (ADR 0083 §4): every desk
        // already deployed reads `tables: string[]`, and the row estimates are one query.
        $details = [];
        try {
            $stats = $this->dumper->tableStats();
            foreach ($tables as $name) {
                $details[] = ['name' => $name, 'rows' => (int) ($stats[$name]['rows'] ?? 0)];
            }
        } catch (Throwable $e) {
            $details = [];
        }
        return $this->ok(['tables' => $tables, 'details' => $details]);
    }

    /**
     * The trash prefix of ADR 0083. A cleaned table keeps its whole old name after the second
     * separator, which is what lets db.restore rebuild it without a ledger.
     */
    private const TRASH_PREFIX = '_tracy_trash_';

    /**
     * Where a snapshot parks a copy. A separate prefix from the trash on purpose: the trash holds
     * tables on their way OUT and `db.purge` may drop anything wearing that name, while these hold
     * the only copy of a row somebody may still need back. One prefix for both would put a
     * snapshot one `db.purge` away from being gone.
     */
    private const SNAP_PREFIX = '_tracy_snap_';

    /**
     * Core suffixes no cleanup may touch, matched against the end of the table name so the
     * site's install-time prefix does not matter. Deny-side false positives (a third-party
     * `foo_users`) are the safe direction: an extension table wrongly refused stays where it
     * is, while a core table wrongly renamed takes the site down.
     */
    private const CORE_TABLE_SUFFIXES = [
        '_users', '_session', '_extensions', '_assets', '_menu', '_menu_types', '_content',
        '_categories', '_modules', '_template_styles', '_usergroups', '_user_usergroup_map',
        '_schemas', '_update_sites', '_updates'
    ];

    /**
     * Trash-not-drop cleanup (ADR 0083): each table is RENAMEd to
     * `_tracy_trash_<YmdHis>__<old name>` — instant, reversible, never a DROP. Mechanics only
     * are guarded here; whether a table truly is residue is the check's and the person's call.
     */
    private function dbCleanup(array $p): array
    {
        if ($this->dumper === null) {
            return $this->err('unavailable', 'db dump not wired');
        }
        $tables = isset($p['tables']) && is_array($p['tables']) ? $p['tables'] : [];
        if ($tables === [] || $tables !== array_filter($tables, 'is_string')) {
            return $this->err('bad_params', 'tables must be a non-empty list of names');
        }
        try {
            $existing = array_flip($this->dumper->tables());
        } catch (Throwable $e) {
            return $this->err('dump_failed', $e->getMessage());
        }
        // Validate the WHOLE batch before renaming anything: half a cleanup is a worse state
        // than no cleanup.
        foreach ($tables as $table) {
            if (!isset($existing[$table])) {
                return $this->err('not_found', "table {$table} does not exist");
            }
            if (strpos($table, self::TRASH_PREFIX) === 0) {
                return $this->err('bad_params', "table {$table} is already in the trash");
            }
            foreach (self::CORE_TABLE_SUFFIXES as $suffix) {
                if (substr($table, -strlen($suffix)) === $suffix) {
                    return $this->err('refused', "table {$table} looks like a core table");
                }
            }
        }
        $stamp = gmdate('YmdHis');
        $renamed = [];
        foreach ($tables as $table) {
            $to = self::TRASH_PREFIX . $stamp . '__' . $table;
            try {
                $this->dumper->renameTable($table, $to);
            } catch (Throwable $e) {
                // Report exactly how far it got — the caller can restore or retry the rest.
                return $this->err('rename_failed', $e->getMessage(), ['renamed' => $renamed]);
            }
            $renamed[] = ['from' => $table, 'to' => $to];
        }
        return $this->ok(['renamed' => $renamed]);
    }

    /** The way back out of the trash: rename to the original name parsed from the suffix. */
    private function dbRestore(array $p): array
    {
        if ($this->dumper === null) {
            return $this->err('unavailable', 'db dump not wired');
        }
        $tables = isset($p['tables']) && is_array($p['tables']) ? $p['tables'] : [];
        if ($tables === [] || $tables !== array_filter($tables, 'is_string')) {
            return $this->err('bad_params', 'tables must be a non-empty list of names');
        }
        try {
            $existing = array_flip($this->dumper->tables());
        } catch (Throwable $e) {
            return $this->err('dump_failed', $e->getMessage());
        }
        $plan = [];
        foreach ($tables as $table) {
            if (strpos($table, self::TRASH_PREFIX) !== 0) {
                return $this->err('bad_params', "table {$table} is not in the trash");
            }
            if (!isset($existing[$table])) {
                return $this->err('not_found', "table {$table} does not exist");
            }
            $rest = substr($table, strlen(self::TRASH_PREFIX));
            $sep = strpos($rest, '__');
            $original = $sep === false ? '' : substr($rest, $sep + 2);
            if ($original === '') {
                return $this->err('bad_params', "table {$table} does not carry its original name");
            }
            if (isset($existing[$original])) {
                return $this->err('refused', "table {$original} already exists");
            }
            $plan[] = ['from' => $table, 'to' => $original];
        }
        $restored = [];
        foreach ($plan as $step) {
            try {
                $this->dumper->renameTable($step['from'], $step['to']);
            } catch (Throwable $e) {
                return $this->err('rename_failed', $e->getMessage(), ['restored' => $restored]);
            }
            $restored[] = $step;
        }
        return $this->ok(['restored' => $restored]);
    }

    /**
     * Copy every table aside, so a step that rewrites the schema has a way back.
     *
     * Exists because the way back was missing. `db.restore` reads like the other half of a backup
     * and is not: it renames a table OUT of the trash, and nothing in this engine could ever put a
     * dump back in. So a `core.upgrade` that died between `prepare` and `finalise` left files that
     * `files.restore` could return and a schema that nothing could — measured against the catalog,
     * not guessed.
     *
     * A copy rather than a dump because of what restoring costs. A dump has to be written out,
     * carried, and replayed statement by statement, which on a real site is tens of minutes and a
     * SQL parser this component deliberately does not have. A copy is a table sitting next to the
     * original, and putting it back is two renames: metadata, instant, and reversible again.
     *
     * The price is disk — briefly twice the database — which is why `tables` narrows it and why a
     * caller drops the snapshot once the upgrade has been accepted.
     */
    private function dbSnapshot(array $p): array
    {
        if ($this->dumper === null) {
            return $this->err('unavailable', 'db dump not wired');
        }
        try {
            $existing = $this->dumper->tables();
        } catch (Throwable $e) {
            return $this->err('dump_failed', $e->getMessage());
        }
        $have = array_flip($existing);

        $tables = isset($p['tables']) && is_array($p['tables']) ? $p['tables'] : null;
        if ($tables === null) {
            // Everything the site itself owns. Copies of copies are not a snapshot, so anything
            // already parked under either prefix is skipped rather than doubled.
            $tables = [];
            foreach ($existing as $name) {
                if (strpos($name, self::TRASH_PREFIX) === 0 || strpos($name, self::SNAP_PREFIX) === 0) {
                    continue;
                }
                $tables[] = $name;
            }
        }
        if ($tables === [] || $tables !== array_filter($tables, 'is_string')) {
            return $this->err('bad_params', 'tables must be a non-empty list of names');
        }

        $stamp = gmdate('YmdHis');
        // Validate the WHOLE batch first: half a snapshot is worse than none, because it reads
        // like a way back that is not there.
        $plan = [];
        foreach ($tables as $table) {
            if (!isset($have[$table])) {
                return $this->err('not_found', "table {$table} does not exist");
            }
            if (strpos($table, self::TRASH_PREFIX) === 0 || strpos($table, self::SNAP_PREFIX) === 0) {
                return $this->err('bad_params', "table {$table} is already a copy");
            }
            $to = self::SNAP_PREFIX . $stamp . '__' . $table;
            if (isset($have[$to])) {
                return $this->err('refused', "table {$to} already exists");
            }
            $plan[] = ['from' => $table, 'to' => $to];
        }

        $copied = [];
        foreach ($plan as $step) {
            try {
                $this->dumper->copyTable($step['from'], $step['to']);
            } catch (Throwable $e) {
                // Say exactly how far it got: the caller rolls the partial copies away itself
                // rather than believing in a snapshot that covers only some tables.
                return $this->err('copy_failed', $e->getMessage(), ['tag' => $stamp, 'copied' => $copied]);
            }
            $copied[] = $step;
        }
        return $this->ok(['tag' => $stamp, 'copied' => $copied]);
    }

    /**
     * Put a snapshot back: for each copy, the live table goes to the trash and the copy takes its
     * name. Two renames per table, so the whole thing is metadata and finishes in one request.
     *
     * The live table is trashed rather than dropped for the reason ADR 0083 gives: a rollback runs
     * when something has already gone wrong, and that is the worst moment to destroy the only
     * evidence of what went wrong. What it displaces stays readable under `_tracy_trash_*` until
     * somebody purges it deliberately.
     */
    private function dbRollback(array $p): array
    {
        if ($this->dumper === null) {
            return $this->err('unavailable', 'db dump not wired');
        }
        $tag = isset($p['tag']) && is_string($p['tag']) ? trim($p['tag']) : '';
        // The tag names a batch and goes straight into a table name. Digits only, and exactly the
        // width gmdate('YmdHis') produces, so nothing a caller sends can shape the identifier.
        if (strlen($tag) !== 14 || !ctype_digit($tag)) {
            return $this->err('bad_params', 'tag must be the 14-digit stamp a snapshot returned');
        }
        try {
            $existing = $this->dumper->tables();
        } catch (Throwable $e) {
            return $this->err('dump_failed', $e->getMessage());
        }
        $have = array_flip($existing);

        $prefix = self::SNAP_PREFIX . $tag . '__';
        $plan = [];
        foreach ($existing as $name) {
            if (strpos($name, $prefix) !== 0) {
                continue;
            }
            $original = substr($name, strlen($prefix));
            if ($original === '') {
                return $this->err('bad_params', "table {$name} does not carry its original name");
            }
            $plan[] = ['from' => $name, 'to' => $original];
        }
        if ($plan === []) {
            return $this->err('not_found', "no snapshot carries the tag {$tag}");
        }

        $stamp = gmdate('YmdHis');
        $restored = [];
        $trashed = [];
        foreach ($plan as $step) {
            try {
                if (isset($have[$step['to']])) {
                    $aside = self::TRASH_PREFIX . $stamp . '__' . $step['to'];
                    $this->dumper->renameTable($step['to'], $aside);
                    $trashed[] = ['from' => $step['to'], 'to' => $aside];
                }
                $this->dumper->renameTable($step['from'], $step['to']);
            } catch (Throwable $e) {
                return $this->err('rename_failed', $e->getMessage(), ['restored' => $restored, 'trashed' => $trashed]);
            }
            $restored[] = $step;
        }
        $this->stamped('rollback');
        return $this->ok(['restored' => $restored, 'trashed' => $trashed]);
    }

    /**
     * Publish or unpublish one installed extension.
     *
     * Addressed by `type` + `element` + `folder` rather than by row id, because those are the three
     * fields `extension.list` and `core.manifest` already hand back, and because the core check
     * below is a lookup in the manifest — asking a caller for an id it would have to guess, to name
     * a row this engine then has to find again, buys nothing.
     *
     * Two refusals, and both are about not handing over a way to break the site quietly:
     * a core row, which Joomla itself will not let you disable and whose absence takes the site
     * with it; and this component, which is the door the caller is standing in.
     */
    private function extensionEnable(array $p): array
    {
        if ($this->extensions === null) {
            return $this->err('unavailable', 'extension manager not wired');
        }
        if ($this->log === null) {
            return $this->err('unavailable', 'apply log not wired');
        }
        $applyId = $this->applyId($p);
        if ($applyId === null) {
            return $this->err('bad_params', 'apply_id required');
        }
        $type = isset($p['type']) && is_string($p['type']) ? trim($p['type']) : '';
        $element = isset($p['element']) && is_string($p['element']) ? trim($p['element']) : '';
        if ($type === '' || $element === '') {
            return $this->err('bad_params', 'type and element required');
        }
        $folder = isset($p['folder']) && is_string($p['folder']) && trim($p['folder']) !== ''
            ? trim($p['folder']) : null;
        if (!isset($p['enabled']) || !is_bool($p['enabled'])) {
            return $this->err('bad_params', 'enabled must be true or false');
        }
        $enabled = $p['enabled'];

        if ($element === 'com_claudecowork') {
            return $this->err('refused', 'this component cannot switch itself off');
        }

        try {
            $manifest = $this->extensions->coreManifest();
        } catch (Throwable $e) {
            return $this->err('manifest_failed', $e->getMessage());
        }
        $rows = isset($manifest['extensions']) && is_array($manifest['extensions']) ? $manifest['extensions'] : [];
        $found = null;
        foreach ($rows as $row) {
            $rowFolder = isset($row['folder']) && $row['folder'] !== '' ? (string) $row['folder'] : null;
            if ((string) ($row['type'] ?? '') === $type
                && (string) ($row['element'] ?? '') === $element
                && $rowFolder === $folder) {
                $found = $row;
                break;
            }
        }
        if ($found === null) {
            return $this->err('not_found', "no extension {$type}/{$element} is installed");
        }
        if (!empty($found['core'])) {
            return $this->err('refused', "extension {$element} is core");
        }

        try {
            $result = $this->extensions->setEnabled($type, $element, $folder, $enabled);
        } catch (Throwable $e) {
            return $this->err('enable_failed', $e->getMessage());
        }
        if (empty($result['ok'])) {
            return $this->err('enable_failed', (string) ($result['error'] ?? 'unknown error'));
        }

        $before = isset($result['before']) ? (bool) $result['before'] : !$enabled;
        try {
            $this->log->record($applyId, [
                'op' => 'extension',
                'type' => $type,
                'element' => $element,
                'folder' => $folder,
                'before' => $before,
            ]);
        } catch (Throwable $e) {
            // The write landed. A log that would not record is worth saying out loud, because the
            // caller's undo will not cover this step.
            return $this->err('log_failed', $e->getMessage(), ['enabled' => $enabled, 'before' => $before]);
        }

        if ($this->writer !== null) {
            try {
                if (!$this->batching) $this->writer->purgeCache();
            } catch (Throwable $e) {
                // Best-effort by contract.
            }
        }
        $this->stamped('extension');
        return $this->ok(['enabled' => $enabled, 'before' => $before]);
    }

    /**
     * The trash's second step (ADR 0083: purge): DROP, accepted ONLY for `_tracy_trash_*`
     * names — a plain table name is refused outright, which is what confines the one
     * destructive operation this engine has to tables a cleanup already parked.
     */
    private function dbPurge(array $p): array
    {
        if ($this->dumper === null) {
            return $this->err('unavailable', 'db dump not wired');
        }
        $tables = isset($p['tables']) && is_array($p['tables']) ? $p['tables'] : [];
        if ($tables === [] || $tables !== array_filter($tables, 'is_string')) {
            return $this->err('bad_params', 'tables must be a non-empty list of names');
        }
        try {
            $existing = array_flip($this->dumper->tables());
        } catch (Throwable $e) {
            return $this->err('dump_failed', $e->getMessage());
        }
        foreach ($tables as $table) {
            if (strpos($table, self::TRASH_PREFIX) !== 0) {
                return $this->err('refused', "table {$table} is not in the trash — purge only empties the trash");
            }
            if (!isset($existing[$table])) {
                return $this->err('not_found', "table {$table} does not exist");
            }
        }
        $dropped = [];
        foreach ($tables as $table) {
            try {
                $this->dumper->dropTable($table);
            } catch (Throwable $e) {
                return $this->err('drop_failed', $e->getMessage(), ['dropped' => $dropped]);
            }
            $dropped[] = $table;
        }
        return $this->ok(['dropped' => $dropped]);
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
        // Two ways out for a part. `put_url`: this host PUTs it to the store itself, the usual way.
        // `inline`: the part comes back in the answer, base64, and the caller stores it — for a
        // host that cannot open an outbound connection at all (no ext/curl AND allow_url_fopen
        // off; measured on a live Joomla 6.0.3 site, 2026-09-04). The database has always
        // travelled inline; this gives files the same road when the direct one is closed.
        $inline = !empty($p['inline']);
        $putUrl = isset($p['put_url']) && is_string($p['put_url']) ? $p['put_url'] : '';
        if (!$inline) {
            if ($this->uploader === null) {
                return $this->err('unavailable', 'uploader not wired');
            }
            if ($putUrl === '') {
                return $this->err('bad_params', 'put_url required');
            }
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
                // Measured, not assumed: a path USTAR cannot hold arrives as a PAX header, its
                // record, then the ordinary header — three blocks, not one (TarStream::entry).
                // A 116-character image filename on a live Joomla 6 site killed a run on the
                // one-block assumption (2026-09-04).
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
        $etag = '';
        $partB64 = null;
        if ($inline) {
            $partB64 = base64_encode($buf);
        } else {
            $put = $this->uploader->put($putUrl, $buf);
            if (!$put['ok']) {
                // The cursor does not move when the upload fails: the caller signs a fresh URL and
                // asks for this same part again.
                return $this->err('upload_failed', $put['error']);
            }
            $etag = $put['etag'];
        }

        return $this->ok([
            'bytes'       => strlen($buf),
            'files'       => $files,
            'sha256'      => $sha,
            'etag'        => $etag,
            'part_b64'    => $partB64,
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
            // `platform` first, and named the same word WordPress uses: a caller holding a token
            // for a site it has never seen asks this one question to learn which CMS it is talking
            // to. Without it the answer is only readable by somebody who already knows.
            return $this->ok(['platform' => 'joomla', 'manifest' => $this->extensions->coreManifest()]);
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
        $sha = $p['sha256'] ?? null;
        if ($sha !== null && (!is_string($sha) || !preg_match('/^[a-f0-9]{64}$/D', $sha))) return $this->err('bad_params', 'invalid sha256');
        // A pinned archive can use an official download endpoint with a query: its bytes, not
        // a URL suffix, identify it; the adapter supplies Joomla a safe .zip filename.
        $shape = $sha !== null && parse_url($url, PHP_URL_SCHEME) === 'https' && parse_url($url, PHP_URL_HOST)
            ? ['ok' => true] : PackageUrl::check($url);
        if ($shape['ok'] !== true) {
            return $this->err('bad_params', $shape['error']);
        }

        try {
            if ($sha !== null) {
                if (!method_exists($this->extensions, 'installVerifiedFromUrl')) return $this->err('unavailable', 'verified installer not installed');
                $result = $this->extensions->installVerifiedFromUrl($url, $sha, isset($p['bytes']) ? (int) $p['bytes'] : null);
            } else $result = $this->extensions->installFromUrl($url);
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

    /** A bounded transaction: the same request id returns its committed result after a lost reply.
     * Expected fields are checked under the site writer lock; all row changes and undo entries
     * share one database transaction. Files and extension installers are deliberately excluded.
     */
    private function contentContract(array $p): array
    {
        if (!$this->contract && $this->constructionBaseline !== null) {
            // Under construction there is nothing to verify and nothing to write through: the door
            // says so, names the baseline, and the builder goes on to capture and name a profile.
            if (($p['operation'] ?? 'inspect') === 'inspect')
                return $this->ok(['bound' => false, 'contract' => '', 'baseline' => $this->constructionBaseline, 'construction' => true]);
            return $this->err('contract_unbound', 'This site is under construction (baseline ' . $this->constructionBaseline . '): capture and name its profile before binding or applying content');
        }
        if (!$this->contract || !$this->log) return $this->err('unavailable', 'Quickstart contract receiver is unavailable');
        try {
            if (($p['operation'] ?? '') === 'bind') {
                if(!$this->writer || !method_exists($this->writer,'transaction'))throw new RuntimeException('Transactional writer required');
                return $this->writer->transaction(function(){
                    $state=$this->contract->inspect();
                    $this->contract->bind($state['snapshot']);
                    return $this->ok(['contract'=>$state['contract'],'revision'=>$state['revision'],'bound'=>true]);
                });
            }
            if (($p['operation'] ?? 'inspect') === 'inspect') {
                $state=$this->contract->inspect();
                foreach(['rows','snapshot','keys','ids','localeMaps','assignments','slotValues','binding'] as $internal)unset($state[$internal]);
                if($state['job'])$state['job']=['locale'=>$state['job']['locale'],'phase'=>$state['job']['phase']];
                return $this->ok($state);
            }
            if (strpos((string)($p['operation'] ?? ''), 'multilingual.') === 0) return $this->multilingual($p);
            if (strpos((string)($p['operation'] ?? ''), 'demoTrim.') === 0) return $this->demoTrim($p);
            if (strpos((string)($p['operation'] ?? ''), 'siteLanguage.') === 0) return $this->siteLanguage($p);
            if (($p['operation'] ?? '') !== 'apply') throw new RuntimeException('Unknown contract operation');
            $apply=$this->applyId($p);$request=$p['request_id']??'';
            // Two refusals, each naming the id it is about. One sentence for both read as "neither id
            // arrived" to an agent that had sent both: it retried fourteen times with an apply_id that
            // never carried the prefix, and wrote nothing (tch, 23/09/2026, j-1ibzqi: 1.1M tokens).
            if (!$apply) throw new RuntimeException('apply_id required; it must start with "contract-"');
            if (strpos($apply,'contract-')!==0) throw new RuntimeException('apply_id must start with "contract-", got "'.substr($apply,0,60).'"');
            if (!is_string($request) || !$request) throw new RuntimeException('request_id required: any string, the same on a retry and new for a different change');
            $hash=hash('sha256',json_encode([$p['changes']??null,$p['evidence']??[]]));
            foreach($this->log->entries($apply) as $entry) {
                if (($entry['op']??'')==='contract' && $entry['request']===$request) {
                    if(!hash_equals($entry['hash'],$hash))throw new RuntimeException('request_id reused with different content');
                    $this->contract->inspect();
                    return $entry['result'];
                }
            }
            if ($this->log->entries($apply)) throw new RuntimeException('Use one new apply_id per content revision');
            $plan=$this->contract->plan($p);
            if(count($plan['operations'])>300)throw new RuntimeException('Split the revision into at most 300 entities');
            if(!$plan['operations'])return $this->ok(['unchanged'=>true]);
            return $this->contentBatch(['apply_id'=>$apply,'request_id'=>$request,'operations'=>$plan['operations']], function($result)use($plan,$apply,$request,$hash){
                $this->contract->bind($plan['snapshot']);
                $state=$this->contract->inspect();
                $this->log->record($apply,['op'=>'contract','request'=>$request,'hash'=>$hash,'result'=>$result,'afterRevision'=>$state['revision']]);
            });
        } catch(Throwable $error) { return $this->err('contract_failed',$error->getMessage()); }
    }


    /**
     * Adding, checking and taking back a language version of a bound quickstart.
     *
     * Five operations behind one action, because the relay admits actions by NAME and a site under
     * a content-only contract must not need a second door opened for this. They are deliberately
     * not one call: `plan` reads, `package` moves files, `apply` moves rows one committed phase at
     * a time, `verify` re-reads, `revert` takes it back. Anything that cannot say which of those it
     * is has no business changing a customer's site.
     */
    private function multilingual(array $p): array
    {
        if (!$this->contract->multilingualAvailable())
            return $this->err('unsupported', 'This quickstart contract carries no multilingual profile; the site cannot be given a second language by this receiver');
        if (!$this->contract->bound()) return $this->err('contract_failed', 'A language needs a bound site');
        $operation = substr((string) $p['operation'], strlen('multilingual.'));
        $locale = isset($p['locale']) && is_string($p['locale']) ? $p['locale'] : '';
        // 🔒 REFUSED, NOT IGNORED. A request that names its own archive is refused even when the
        // values happen to be right: accepting the SHAPE is accepting a request that could carry
        // wrong ones, and silently dropping the fields would let a caller believe it chose the
        // bytes. On a bound site what may arrive is decided in review, in language-packs.json.
        foreach (['url', 'sha256', 'bytes', 'package'] as $mine)
            if (isset($p[$mine]))
                return $this->err('bad_params', 'Name a locale, not a package: `' . $mine . '` is decided by the receiver’s reviewed catalog');
        $major = (int) (explode('.', (string) ($this->info['joomla'] ?? '0'))[0]);
        if ($major < 1) return $this->err('contract_failed', 'The site did not report its Joomla version');
        switch ($operation) {
            case 'plan':
                return $this->ok($this->contract->languagePlan($locale, $major, $this->contract->inspect()));
            case 'package':
                return $this->multilingualPackage($locale, $major);
            case 'apply':
                return $this->multilingualApply($p, $locale, $major);
            case 'verify':
                return $this->multilingualVerify($locale);
            case 'revert':
                return $this->multilingualRevert($p, $locale);
            default:
                return $this->err('bad_params', 'Unknown multilingual operation');
        }
    }

    /** Rows moved per committed call. Bounded so one call stays well inside PHP's execution limit. */
    private const DEMO_TRIM_BATCH = 300;

    /**
     * Hiding a quickstart's own demo rows on a bound site, and bringing them back.
     *
     * Three operations behind the contract door, like the language ones: `plan` reads, `apply` hides
     * one committed batch per call until nothing is left, `revert` shows them again the same way.
     * What may be hidden is decided in review, in the contract's `demo-trim-map.json`; a request
     * names no rows, so it cannot hide anything the review did not list.
     */
    private function demoTrim(array $p): array
    {
        if (!$this->contract->demoTrimAvailable())
            return $this->err('unsupported', 'This quickstart contract carries no demo-trim profile; its demo rows cannot be hidden by this receiver');
        $operation = substr((string) $p['operation'], strlen('demoTrim.'));
        if ($operation === 'plan') {
            try {
                $state = $this->contract->inspect();
                return $this->ok([
                    'hides' => $this->contract->demoTrim()->counts(), 'status' => $state['demoTrim'] ?? 'none',
                    'remaining' => count($this->demoTrimPending($state, true)),
                    'profileVersion' => $this->contract->demoTrim()->version(), 'profileHash' => $this->contract->demoTrim()->hash(),
                ]);
            } catch (Throwable $error) { return $this->err('contract_failed', $error->getMessage()); }
        }
        if ($operation !== 'apply' && $operation !== 'revert') return $this->err('bad_params', 'Unknown demoTrim operation');
        $apply = $this->applyId($p);
        $request = $p['request_id'] ?? '';
        if (!$apply || strpos($apply, 'dtrim-') !== 0) return $this->err('bad_params', 'apply_id must start with "dtrim-"');
        if (!is_string($request) || !preg_match('/^[a-zA-Z0-9._:-]{1,100}$/D', $request))
            return $this->err('bad_params', 'request_id required: any string, the same on a retry and new for a different change');
        try {
            $binding = $this->contract->binding();
            // 🔒 A TRANSLATED SITE IS REFUSED, NOT HALF-TRIMMED. A copy's visibility is derived from
            // its source, so hiding a source would make every copy of it drift — and hiding copies too
            // is a rule this profile does not carry yet. Saying so beats a site that fails its next read.
            if (!empty($binding['multilingual']['languages']))
                return $this->err('contract_failed', 'This site already has a second language; hiding its demo rows would leave the translated copies showing. Nothing has been written.');
            $trim = $binding['demoTrim'] ?? null;
            $record = ['status' => $operation === 'apply' ? 'applying' : 'reverting', 'applyId' => $apply, 'requestId' => $request,
                'profileHash' => $this->contract->demoTrim()->hash(), 'profileVersion' => $this->contract->demoTrim()->version(), 'at' => gmdate('c')];
            if ($operation === 'apply') {
                if (($trim['status'] ?? null) === 'complete')
                    return $this->ok(['status' => 'completed', 'remaining' => 0, 'alreadyTrimmed' => true, 'applyId' => $trim['applyId']]);
                if ($trim !== null && ($trim['status'] !== 'applying' || $trim['requestId'] !== $request))
                    return $this->err('conflict', 'A demo trim is already ' . $trim['status'] . ' under request ' . $trim['requestId']);
            } else {
                if ($trim === null) return $this->err('contract_failed', 'This site has no hidden demo rows to bring back');
                if ($trim['status'] === 'reverting' && $trim['requestId'] !== $request)
                    return $this->err('conflict', 'A demo trim revert is already running under request ' . $trim['requestId']);
            }
            // 🔒 THE RECORD LANDS BEFORE ANY ROW MOVES, in its own commit. A batch that dies after
            // Joomla committed some rows must leave a site that says a trim is in flight — otherwise
            // those rows read as drift, and the retry that would finish them is refused with the rest.
            if ($trim === null || $trim['status'] !== $record['status'] || $trim['requestId'] !== $request) {
                $this->writer->transaction(function () use ($record) {
                    $state = $this->contract->inspect();
                    if (!$state['binding']) $this->contract->bind($state['snapshot']);
                    $this->contract->rebind($this->contract->bindingWithTrim($record));
                    return [];
                });
            }
            $hide = $operation === 'apply';
            $this->batching = true;
            $step = $this->writer->transaction(function () use ($hide, $apply) {
                $state = $this->contract->inspect();
                $pending = $this->demoTrimPending($state, $hide);
                $batch = array_slice($pending, 0, self::DEMO_TRIM_BATCH, true);
                foreach ($batch as $key => [$kind, $field, $value]) {
                    // One column, raw — never write(): Joomla's Table would mint `#__assets` rows
                    // for demo posts that ship without them, and move the ACL the contract holds.
                    $id = (int) $state['ids'][$key];
                    $before = (string) ($state['rows'][$key][$field] ?? '');
                    $this->writer->setVisibility($kind, $id, $field, $value);
                    $this->log->record($apply, ['op' => 'visibility', 'kind' => $kind, 'id' => $id, 'column' => $field, 'before' => $before]);
                }
                $remaining = count($pending) - count($batch);
                if ($remaining === 0)
                    $this->contract->rebind($hide ? $this->contract->bindingAfterTrim() : $this->contract->bindingAfterTrimRevert());
                // Proven, then stored: the full read checks every row against the baseline just
                // derived, and only a site that passes it becomes the next baseline.
                $this->contract->rebind($this->contract->inspect()['snapshot']);
                return ['remaining' => $remaining, 'moved' => count($batch)];
            });
            $this->batching = false;
            try { $this->writer->purgeCache(); } catch (Throwable $ignored) {}
            $this->stamped('content');
            $finished = $step['remaining'] === 0;
            return $this->ok([
                'status' => $finished ? ($hide ? 'completed' : 'reverted') : 'running',
                'moved' => $step['moved'], 'remaining' => $step['remaining'], 'applyId' => $apply,
            ]);
        } catch (Throwable $error) {
            $this->batching = false;
            return $this->err('contract_failed', $error->getMessage());
        }
    }

    /**
     * One language for a sealed site that has no second edition: its pack, from the reviewed
     * catalog, installed and made the default for the site and the administrator.
     *
     * The multilingual operations need the contract's multilingual profile, because they build a
     * second edition. A quickstart that cannot have one (ja-kinetic) still has to be able to BE in
     * the customer's language — this is that, and nothing more: no copies, no filter, no switcher.
     * Nothing it touches is under the contract (language/ is no file root; #__languages and
     * com_languages params are not inspected), and the site is re-proven after it anyway.
     */
    private function siteLanguage(array $p): array
    {
        if (!$this->contract->siteLanguageAvailable())
            return $this->err('unsupported', 'This receiver carries no language-pack catalog for this site');
        $operation = substr((string) $p['operation'], strlen('siteLanguage.'));
        foreach (['url', 'sha256', 'bytes', 'package'] as $mine)
            if (isset($p[$mine]))
                return $this->err('bad_params', 'Name a locale, not a package: `' . $mine . '` is decided by the receiver’s reviewed catalog');
        $major = (int) (explode('.', (string) ($this->info['joomla'] ?? '0'))[0]);
        if ($major < 1) return $this->err('contract_failed', 'The site did not report its Joomla version');
        $locale = isset($p['locale']) && is_string($p['locale']) ? $p['locale'] : '';
        try {
            if ($operation === 'plan') {
                if (!preg_match('/^[a-z]{2,3}-[A-Z]{2,4}$/D', $locale)) return $this->err('bad_params', 'Not a Joomla language tag: ' . $locale);
                $pack = $this->contract->languagePackage($locale, $major);
                return $this->ok([
                    'locale' => $locale, 'current' => $this->writer->readLanguageDefaults(),
                    'package' => $pack === null ? null : ['tag' => $pack['tag'], 'version' => $pack['version'], 'bytes' => $pack['bytes']],
                    'installed' => $pack === null || $this->languagePackPresent($locale),
                    'onRecord' => $this->contract->binding()['siteLanguage'] ?? null,
                ]);
            }
            if ($operation !== 'set' && $operation !== 'revert') return $this->err('bad_params', 'Unknown siteLanguage operation');
            $apply = $this->applyId($p);
            $request = $p['request_id'] ?? '';
            if (!$apply || strpos($apply, 'slang-') !== 0) return $this->err('bad_params', 'apply_id must start with "slang-"');
            if (!is_string($request) || !preg_match('/^[a-zA-Z0-9._:-]{1,100}$/D', $request))
                return $this->err('bad_params', 'request_id required: any string, the same on a retry and new for a different change');
            $binding = $this->contract->binding();
            if (!empty($binding['multilingual']['languages']))
                return $this->err('contract_failed', 'This site already has a second language edition; its languages are managed by the multilingual operations. Nothing has been written.');
            $onRecord = $binding['siteLanguage'] ?? null;
            if ($operation === 'revert') {
                if ($onRecord === null) return $this->err('contract_failed', 'This site has no language set by this door to take back');
                $previous = $onRecord['previous'];
                $this->writer->transaction(function () use ($apply, $previous) {
                    $before = $this->writer->readLanguageDefaults();
                    $this->writer->writeLanguageDefaults((string) $previous['site'], (string) $previous['administrator']);
                    $this->log->record($apply, ['op' => 'languageDefaults', 'before' => $before]);
                    $this->contract->rebind($this->contract->bindingWithSiteLanguage(null));
                    $this->contract->rebind($this->contract->inspect()['snapshot']);
                    return [];
                });
                try { $this->writer->purgeCache(); } catch (Throwable $ignored) {}
                $this->stamped('content');
                return $this->ok(['status' => 'reverted', 'current' => $this->writer->readLanguageDefaults()]);
            }
            if (!preg_match('/^[a-z]{2,3}-[A-Z]{2,4}$/D', $locale)) return $this->err('bad_params', 'Not a Joomla language tag: ' . $locale);
            if ($onRecord !== null) {
                if ($onRecord['locale'] === $locale) return $this->ok(['status' => 'completed', 'locale' => $locale, 'alreadySet' => true]);
                return $this->err('conflict', 'This site is already set to ' . $onRecord['locale'] . '; take that back with siteLanguage.revert before choosing another');
            }
            $pack = $this->contract->languagePackage($locale, $major);
            if ($pack !== null && !$this->languagePackPresent($locale)) {
                if ($this->extensions === null || !method_exists($this->extensions, 'installVerifiedFromUrl')) return $this->err('unavailable', 'verified installer not installed');
                $result = $this->extensions->installVerifiedFromUrl($pack['url'], $pack['sha256'], (int) $pack['bytes']);
                if (($result['ok'] ?? false) !== true) return $this->err('install_failed', (string) ($result['error'] ?? 'installer refused the package'));
                if (!$this->languagePackPresent($locale)) return $this->err('install_failed', 'The ' . $locale . ' pack installed but Joomla does not list it');
                $this->stamped('extension');
            }
            // A site bound for the first time here is bound as it stands, before anything is set.
            if ($binding === null) $this->writer->transaction(function () {
                $this->contract->bind($this->contract->inspect()['snapshot']);
                return [];
            });
            $this->writer->transaction(function () use ($apply, $request, $locale, $pack) {
                $before = $this->writer->readLanguageDefaults();
                $this->writer->writeLanguageDefaults($locale, $locale);
                $this->log->record($apply, ['op' => 'languageDefaults', 'before' => $before]);
                $this->contract->rebind($this->contract->bindingWithSiteLanguage([
                    'locale' => $locale, 'previous' => $before, 'applyId' => $apply, 'requestId' => $request,
                    'packVersion' => $pack['version'] ?? null, 'at' => gmdate('c'),
                ]));
                // Proven, then stored — the same two steps every other sealed write ends with.
                $this->contract->rebind($this->contract->inspect()['snapshot']);
                return [];
            });
            try { $this->writer->purgeCache(); } catch (Throwable $ignored) {}
            $this->stamped('content');
            return $this->ok(['status' => 'completed', 'locale' => $locale, 'packVersion' => $pack['version'] ?? null]);
        } catch (Throwable $error) {
            return $this->err('contract_failed', $error->getMessage());
        }
    }

    /**
     * Listed rows not yet where this direction wants them, with the value each should get.
     *
     * @return array<string,array{0:string,1:string,2:string}> key => [kind, field, value]
     */
    private function demoTrimPending(array $state, bool $hide): array
    {
        $out = [];
        foreach ($this->contract->demoTrim()->rows() as $key => $row) {
            $want = $hide ? $row['to'] : $row['from'];
            if ((string) ($state['rows'][$key][$row['field']] ?? '') !== $want) $out[$key] = [$row['kind'], $row['field'], $want];
        }
        return $out;
    }

    /**
     * Install the language pack this receiver has pinned for a locale.
     *
     * The caller names a LOCALE. It does not get to name a URL, a hash or a size even correctly:
     * accepting those fields would accept the shape of a request that could carry wrong ones, and
     * the whole point of a bound site is that what may reach it was decided in review.
     */
    private function multilingualPackage(string $locale, int $major): array
    {
        if ($this->extensions === null) return $this->err('unavailable', 'extension manager not wired');
        try {
            $pack = $this->contract->languagePackage($locale, $major);
        } catch (Throwable $error) { return $this->err('contract_failed', $error->getMessage()); }
        if ($pack === null) return $this->ok(['locale' => $locale, 'installed' => true, 'source' => true]);
        try { $installed = $this->languagePackPresent($locale); }
        catch (Throwable $error) { return $this->err('read_failed', $error->getMessage()); }
        if (!$installed) {
            if (!method_exists($this->extensions, 'installVerifiedFromUrl')) return $this->err('unavailable', 'verified installer not installed');
            try {
                $result = $this->extensions->installVerifiedFromUrl($pack['url'], $pack['sha256'], (int) $pack['bytes']);
            } catch (Throwable $error) { return $this->err('install_failed', $error->getMessage()); }
            if (($result['ok'] ?? false) !== true) return $this->err('install_failed', (string) ($result['error'] ?? 'installer refused the package'));
        }
        // An installer writes FILES, and files are half of what this contract protects. Re-reading
        // here is what turns "the installer returned ok" into "the site is still the site".
        try { $this->contract->inspect(); }
        catch (Throwable $error) { return $this->err('contract_failed', 'The package installed but the site no longer matches its contract: ' . $error->getMessage()); }
        $this->stamped('extension');
        return $this->ok(['locale' => $locale, 'installed' => true, 'version' => $pack['version'], 'alreadyPresent' => $installed]);
    }

    /**
     * One phase of one language, committed.
     *
     * Repeating the same request id continues the same job rather than starting a second one, and a
     * request id that arrives with different words is a conflict, not an update: a caller whose
     * reply was lost must be able to ask again without translating the site twice, and must not be
     * able to change what a half-applied job is applying.
     */
    private function multilingualApply(array $p, string $locale, int $major): array
    {
        $apply = $this->applyId($p);
        $request = $p['request_id'] ?? '';
        $translations = $p['translations'] ?? null;
        if (!$apply || strpos($apply, 'mlang-') !== 0 || !is_string($request) || !preg_match('/^[a-zA-Z0-9._:-]{1,100}$/D', $request))
            return $this->err('bad_params', 'an mlang- apply_id and a request_id are required');
        if (!is_array($translations) || !$translations) return $this->err('bad_params', 'translations are required');
        $hash = hash('sha256', json_encode([$locale, $translations], JSON_THROW_ON_ERROR));
        try {
            $job = $this->contract->job();
            if ($job !== null && ($job['locale'] !== $locale || $job['requestId'] !== $request))
                return $this->err('conflict', 'Another language job is in flight for ' . $job['locale'] . ' at phase ' . $job['phase']);
            if ($job !== null && !hash_equals($job['translationHash'], $hash))
                return $this->err('conflict', 'This request id is already applying different words');
            // Reading the whole contract costs seconds — 3,900 file hashes and every governed row —
            // so it is done ONCE outside the transaction, and only when a job is being STARTED and
            // its revision has to be checked. A continuing call gets its fresh read inside the
            // transaction, where it is needed anyway.
            $state = $job === null ? $this->contract->inspect() : null;
            if ($job === null) {
                if (in_array($locale, $state['languages'], true))
                    return $this->ok(['locale' => $locale, 'status' => 'completed', 'phase' => 'completed', 'alreadyPresent' => true]);
                if (!isset($p['expected_revision']) || !hash_equals($state['revision'], (string) $p['expected_revision']))
                    return $this->err('conflict', 'The site changed since it was planned; inspect again');
                $plan = $this->contract->languagePlan($locale, $major, $state);
                // The words can be ready before the files are, and usually are; what must not happen
                // is rows tagged `zh-CN` on a site where Joomla has never heard of `zh-CN`.
                if ($plan['package'] !== null && !$this->languagePackPresent($locale))
                    return $this->err('contract_failed', 'Install the ' . $locale . ' language pack before applying it');
                $missing = $this->translationProblems($plan['slots'], $translations);
                // The full list, not a sample of it: the caller's only way to fix a translation is
                // to ask again for exactly the slots that failed, and a truncated list turns that
                // into guess-and-retry over a thousand of them.
                if ($missing) return $this->err(
                    'contract_failed',
                    'The translation is not usable: ' . implode('; ', array_slice(array_column($missing, 'problem'), 0, 3))
                        . (count($missing) > 3 ? ' (and ' . (count($missing) - 3) . ' more)' : ''),
                    ['problems' => $missing]
                );
                $job = MultilingualApply::start($locale, $apply, $request, $hash, $state['snapshot']['contractHash'], $plan['profileHash'], $state['revision']);
            }
            $executor = new MultilingualApply($this->contract, $this->writer, function (string $kind, int $id, array $fields) use ($apply): int {
                $answer = $this->contentUpdate(['apply_id' => $apply, 'kind' => $kind, 'id' => $id, 'fields' => $fields]);
                if (empty($answer['ok'])) throw new RuntimeException($kind . ' ' . $id . ': ' . ($answer['message'] ?? json_encode($answer['error'])));
                return (int) $answer['id'];
            });
            $this->batching = true;
            $done = $this->writer->transaction(function () use ($executor, $job, $translations, $locale) {
                // A fresh read inside the transaction: the phase must act on the site as it is now,
                // not on a picture taken before another writer had its turn.
                $state = $this->contract->inspect();
                $next = $executor->step($job, $state, $translations);
                if ($next['phase'] === 'completed') {
                    $this->contract->saveJob(null);
                    // Two steps, and the order is what makes the second one safe. First the
                    // derived baseline: which ids belong to which source, and that the translated
                    // sources have left `*`. Then a full inspect, which PROVES every copy against
                    // the derivation rules — and only then is its snapshot stored. Storing the
                    // proven snapshot is not "copying whatever the site holds": it is recording a
                    // state that has just been checked field by field. Without it the stored
                    // baseline lacks the copies' own presentation, and the next ordinary content
                    // edit fails with "Cannot replace a content-only baseline" — measured while
                    // editing a Chinese headline after the language landed.
                    $this->contract->rebind($this->contract->bindingAfterLanguage($next));
                    $this->contract->rebind($this->contract->inspect()['snapshot']);
                } else $this->contract->saveJob($next);
                return ['job' => $next];
            })['job'];
            $this->batching = false;
            try { $this->writer->purgeCache(); } catch (Throwable $ignored) {}
            $this->stamped('content');
            return $this->ok([
                'locale' => $locale,
                'status' => $done['phase'] === 'completed' ? 'completed' : 'running',
                'phase' => $done['phase'], 'cursor' => $done['cursor'],
                'created' => count($done['ids']), 'applyId' => $apply,
                'phases' => MultilingualApply::PHASES,
            ]);
        } catch (Throwable $error) {
            $this->batching = false;
            return $this->err('contract_failed', $error->getMessage());
        }
    }



    /**
     * Rows an unfinished language left on the site, grouped by kind, newest first.
     *
     * Newest first because a menu item cannot be removed before its children: ids rise with
     * creation order, so descending order takes the tree apart from the leaves.
     *
     * @return array<string,int[]>
     */
    private function orphansOf(array $state, string $locale): array
    {
        $out = [];
        foreach ($state['keys'] as $key => $meta) {
            if (($meta['locale'] ?? null) !== $locale) continue;
            $out[$meta['kind']][] = (int) $state['ids'][$key];
        }
        if ($state['switcher'] !== null) $out['module'][] = (int) $state['switcher'];
        foreach ($out as $kind => $ids) { rsort($ids); $out[$kind] = $ids; }
        return $out;
    }

    /** Whether Joomla itself already carries this language, as the site reports it. */
    private function languagePackPresent(string $locale): bool
    {
        if ($this->extensions === null) return false;
        foreach ($this->extensions->listInstalled() as $row)
            if (($row['type'] ?? '') === 'language' && ($row['element'] ?? '') === $locale) return true;
        return false;
    }

    /** Every reason a supplied translation cannot be used, named one slot at a time. */
    private function translationProblems(array $slots, array $translations): array
    {
        $out = [];
        foreach ($slots as $slot) {
            if ($slot['type'] !== 'text') continue;
            $key = $slot['key'];
            $say = function (string $problem) use (&$out, $key): void { $out[] = ['slot' => $key, 'problem' => $key . ': ' . $problem]; };
            if (!array_key_exists($key, $translations) || !is_string($translations[$key])) { $say('no translation was supplied'); continue; }
            $value = $translations[$key];
            $source = (string) $slot['source'];
            if (trim($value) === '' && trim($source) !== '') { $say('is empty but its source is not'); continue; }
            if (trim($value) !== '' && trim($source) === '') { $say('fills a slot the source leaves empty'); continue; }
            if (mb_strlen($value) > $slot['maxCharacters']) { $say('is ' . mb_strlen($value) . ' characters, over the slot limit of ' . $slot['maxCharacters']); continue; }
            if (preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/u', $value)) { $say('carries control characters'); continue; }
            // An angle bracket is markup only where the source has none — the rule, and the
            // measurement behind it, are in MultilingualProfile::markupIntroduced().
            if (MultilingualProfile::markupIntroduced($source, $value)) { $say('carries markup its source does not'); continue; }
            if (preg_match('/\{\/?[a-z][^{}]*\}/i', $value)) { $say('carries a Joomla plugin directive'); continue; }
            foreach (MultilingualProfile::preservationErrors($source, $value) as $lost) $say($lost);
        }
        return $out;
    }

    /** Re-read the whole contract, then report what the language actually consists of. */
    private function multilingualVerify(string $locale): array
    {
        try { $state = $this->contract->inspect(); }
        catch (Throwable $error) { return $this->err('contract_failed', $error->getMessage()); }
        if ($locale !== '' && !in_array($locale, $state['languages'], true))
            return $this->err('contract_failed', $locale . ' is not a language of this site' . ($state['job'] ? ' yet; a job is at phase ' . $state['job']['phase'] : ''));
        $binding = $state['binding']['multilingual'] ?? [];
        $per = [];
        foreach ($binding['languages'] ?? [] as $tag => $language) {
            $kinds = [];
            foreach ($language['ids'] as $baseKey => $id) {
                $kind = $state['keys'][MultilingualProfile::derivedKey($tag, $baseKey)]['kind'];
                $kinds[$kind] = ($kinds[$kind] ?? 0) + 1;
            }
            $per[$tag] = ['entities' => $kinds, 'contentLanguage' => $language['contentLanguage'], 'applyId' => $language['applyId']];
        }
        return $this->ok([
            'contract' => $state['contract'], 'revision' => $state['revision'],
            'languages' => $state['languages'], 'perLanguage' => $per,
            'switcher' => $state['switcher'], 'verified' => true,
        ]);
    }

    /**
     * Take one language back out, and nothing else.
     *
     * Replaying this language's own apply log in reverse is what makes the scope exact: it removes
     * the rows this run created and restores the fields this run changed, so a language pack that
     * was already on the site, and anything a person did afterwards under a different apply id,
     * are not its business. A revert that does not verify afterwards is rolled back whole.
     */
    private function multilingualRevert(array $p, string $locale): array
    {
        try {
            $state = $this->contract->inspect();
            $language = $state['binding']['multilingual']['languages'][$locale] ?? null;
            $job = $state['job'];
            // A language that never finished is taken back through the SAME door. Without this a
            // job whose words can no longer be supplied — the caller lost them, or changed them —
            // could only be cleared from a database console, and until it was, the site refused
            // every other content change.
            $abandon = $language === null && $job !== null && $job['locale'] === $locale;
            if ($language === null && !$abandon) return $this->err('contract_failed', $locale . ' is not a language of this site');
            if (isset($p['expected_revision']) && !hash_equals($state['revision'], (string) $p['expected_revision']))
                return $this->err('conflict', 'The site changed since it was read; inspect again');
            $applyId = $abandon ? $job['applyId'] : $language['applyId'];
            $leftovers = $abandon ? ($state['languages'] === [] ? $this->orphansOf($state, $locale) : []) : [];
            $this->batching = true;
            $result = $this->writer->transaction(function () use ($applyId, $locale, $abandon, $leftovers) {
                $reverted = $this->applyRevert(['apply_id' => $applyId]);
                if (empty($reverted['ok']) || !empty($reverted['failed'])) throw new RuntimeException('The language could not be fully taken back');
                // The stored baseline currently describes the site WITH the language, so the
                // expectations have to come off before anything is re-read against them.
                // Rows a committed-but-unlogged phase left behind (see MultilingualApply: a phase
                // is bounded, not atomic). The undo log cannot name them because its own entries
                // rolled back with the phase, so they are removed by the identity that found them.
                foreach ($leftovers as $kind => $ids) foreach ($ids as $id) $this->writer->delete($kind, (int) $id);
                if ($abandon) $this->contract->saveJob(null);
                else $this->contract->rebind($this->contract->bindingAfterRevert($locale));
                $this->contract->rebind($this->contract->inspect()['snapshot']);
                return $reverted;
            });
            $this->batching = false;
            try { $this->writer->purgeCache(); } catch (Throwable $ignored) {}
            $this->stamped('revert');
            return $this->ok(['locale' => $locale, 'reverted' => $result['reverted'] ?? 0, 'removed' => true]);
        } catch (Throwable $error) {
            $this->batching = false;
            return $this->err('contract_failed', $error->getMessage());
        }
    }

    private function contentBatch(array $p, ?callable $verify = null): array
    {
        if (!$this->writer || !$this->log || !method_exists($this->writer, 'transaction')) {
            return $this->err('unavailable', 'transactional site writer not installed');
        }
        $apply = $this->applyId($p);
        $request = $p['request_id'] ?? '';
        $steps = $p['operations'] ?? null;
        if (!$apply || !is_string($request) || !preg_match('/^[a-zA-Z0-9._:-]{1,100}$/D', $request)
            || !is_array($steps) || count($steps) < 1 || count($steps) > ($verify ? 300 : 100)) {
            return $this->err('bad_params', 'apply_id, request_id and 1–100 operations required');
        }
        $hash = hash('sha256', json_encode($steps, JSON_THROW_ON_ERROR));
        try {
            $result = $this->writer->transaction(function () use ($apply, $request, $steps, $hash, $verify) {
                foreach ($this->log->entries($apply) as $entry) {
                    if (($entry['op'] ?? '') === 'batch' && ($entry['request'] ?? '') === $request) {
                        if (!hash_equals($entry['hash'], $hash)) throw new RuntimeException('request_id already used with different operations');
                        return $entry['result'];
                    }
                }
                $this->batching = true;
                $ids = [];
                foreach ($steps as $index => $step) {
                    if (!is_array($step) || !isset($step['kind'], $step['fields'])) throw new RuntimeException("invalid operation {$index}");
                    $kind = $step['kind'];
                    // Identity and installed executable settings require their own privileged door.
                    if (in_array($kind, ['user', 'extensionParams'], true)) throw new RuntimeException('kind not allowed in a content batch');
                    $id = $step['id'] ?? 0;
                    if (is_string($id) && str_starts_with($id, '$')) $id = $ids[substr($id, 1)] ?? -1;
                    if (!is_numeric($id) || (int) $id < 0) throw new RuntimeException('unresolved id');
                    $fields = $step['fields'];
                    foreach ($fields as $column => $value) {
                        if (is_string($value) && preg_match('/^\$([a-zA-Z0-9_-]+)$/D', $value, $match)) {
                            if (!isset($ids[$match[1]])) throw new RuntimeException('unresolved field reference');
                            $fields[$column] = $ids[$match[1]];
                        }
                    }
                    if (isset($step['expected'])) {
                        $before = $this->writer->read($kind, (int) $id);
                        foreach ($step['expected'] as $key => $value) {
                            if (!$before || !array_key_exists($key, $before) || (string) $before[$key] !== (string) $value) {
                                throw new RuntimeException("conflict at operation {$index}: {$key} changed");
                            }
                        }
                    }
                    $answer = $this->contentUpdate(['apply_id' => $apply, 'kind' => $kind, 'id' => (int) $id, 'fields' => $fields]);
                    if (empty($answer['ok'])) throw new RuntimeException("operation {$index}: " . ($answer['message'] ?? json_encode($answer['error'])));
                    $key = $step['key'] ?? (string) $index;
                    if (!is_string($key) || isset($ids[$key])) throw new RuntimeException('operation keys must be unique strings');
                    $ids[$key] = $answer['id'];
                }
                $result = $this->ok(['ids' => $ids, 'revision' => hash('sha256', $apply . ':' . $request . ':' . $hash)]);
                if ($verify) $verify($result);
                $this->log->record($apply, ['op' => 'batch', 'request' => $request, 'hash' => $hash, 'result' => $result]);
                return $result;
            });
            $this->batching = false;
            $this->writer->purgeCache();
            $this->stamped('content');
            return $result;
        } catch (Throwable $error) {
            $this->batching = false;
            return $this->err('batch_failed', $error->getMessage());
        }
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
            if (!$this->batching) $this->writer->purgeCache();
        } catch (Throwable $e) {
            // Best-effort by contract: a stale cache is not worth failing a change that landed.
        }

        if (!$this->batching) $this->stamped('content');

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
            if (!$this->batching) $this->writer->purgeCache();
        } catch (Throwable $e) {
            // Best-effort by contract.
        }

        if (!$this->batching) $this->stamped('content');

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

        if ($this->contract && $this->contract->bound() && basename($path, '.' . pathinfo($path, PATHINFO_EXTENSION)) !== hash('sha256', $bytes)) return $this->err('content_only', 'Image filename must match its SHA-256');

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
                if (!$this->batching) $this->writer->purgeCache();
            } catch (Throwable $e) {
                // Best-effort by contract.
            }
        }

        if ($reverted > 0 && !$this->batching) {
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
        if ($op === 'batch' || $op === 'contract') { return; }
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
        if ($op === 'extension') {
            if ($this->extensions === null) {
                throw new RuntimeException('extension manager not wired');
            }
            $folder = isset($entry['folder']) && is_string($entry['folder']) ? $entry['folder'] : null;
            $result = $this->extensions->setEnabled(
                (string) ($entry['type'] ?? ''),
                (string) ($entry['element'] ?? ''),
                $folder,
                (bool) ($entry['before'] ?? false)
            );
            if (empty($result['ok'])) {
                throw new RuntimeException((string) ($result['error'] ?? 'setEnabled failed'));
            }
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

    /** @param array<string,mixed> $extra facts the caller needs even on failure (e.g. how far a batch got) */
    private function err(string $code, string $message, array $extra = []): array
    {
        return array_merge(['ok' => false, 'error' => $code, 'message' => $message], $extra);
    }
}
