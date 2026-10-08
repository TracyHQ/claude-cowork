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
require_once __DIR__ . '/ContractRows.php';
require_once __DIR__ . '/ChangeStamp.php';
require_once __DIR__ . '/CoreUpgrader.php';
require_once __DIR__ . '/FilesRestorer.php';
require_once __DIR__ . '/QuickstartContract.php';
require_once __DIR__ . '/VisibleText.php';
require_once __DIR__ . '/JoomlaLocks.php';
require_once __DIR__ . '/Timing.php';
require_once __DIR__ . '/SiteIdentity.php';
require_once __DIR__ . '/TemplateSiteSettings.php';
require_once __DIR__ . '/TemplateSiteFiles.php';

final class Engine
{
    private ?QuickstartContract $contract;
    private bool $batching = false;
    /**
     * The batch write running now, as [kind, id, before] until its undo is recorded: a write that
     * fails can have landed first (Joomla stored the row, then its tags or asset step threw), and
     * its undo is in no log yet. Only an update has one; a create that failed has no id to take back.
     */
    private ?array $inFlight = null;
    private bool $writing = false;
    /** Inside an unlocked read (a contract inspect under a consistent snapshot). */
    private bool $reading = false;
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
    /** The global joomla/cowork/script.php reads to name who installed the package (`door` here). */
    public const INSTALL_CONTEXT = 'claudecowork_install_context';

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

    /**
     * What `content.read` would serve right now, for apply's `expected_content_revisions`:
     * `fn(): array{revisions:array<string,string>,owners:array<string,string>}`. Wired by the host,
     * which alone can read the CMS tables the projection needs; absent, only `expected_revision` works.
     */
    private $contentRevisions = null;

    /** Let content.contract apply check per-content revisions against the reader's projection. */
    public function contentRevisions(callable $current): self
    {
        $this->contentRevisions = $current;
        return $this;
    }

    /**
     * Who holds rows open in the Joomla editor: `fn(list<array{0:string,1:int}> $rows): array`
     * answering "kind:id" => lockedBy for the LIVE check-outs among them (JoomlaLocks). Wired by
     * the host, which alone can read `#__session`; absent, nothing is refused for a lock.
     */
    private $locks = null;

    /** Let every write refuse a row an administrator has open in the Joomla editor. */
    public function locks(callable $lockOf): self
    {
        $this->locks = $lockOf;
        return $this;
    }

    /**
     * The open surface's refusal for rows open in the Joomla editor, or null when none is. Its own
     * shape (`error`, `message`), plus the contract door's `code` and `lockedBy` so one reader of
     * both surfaces knows it by the same name; `locked` lists every open row, not just the first.
     * There is no flag to write anyway: the admin's next Save would put the old words back.
     *
     * @param list<array{0:string,1:int}> $rows (kind, id) this write would change
     */
    private function lockRefusal(array $rows): ?array
    {
        $rows = array_values(array_filter($rows, fn($row) => $row[1] > 0 && isset(JoomlaLocks::TABLES[$row[0]])));
        if (!$this->locks || $rows === []) return null;
        $held = ($this->locks)($rows);
        $locked = []; $messages = [];
        foreach ($rows as [$kind, $id]) {
            $lockedBy = $held[JoomlaLocks::key($kind, $id)] ?? null;
            if (!$lockedBy || isset($locked[JoomlaLocks::key($kind, $id)])) continue;
            $locked[JoomlaLocks::key($kind, $id)] = ['kind' => $kind, 'id' => $id, 'lockedBy' => $lockedBy];
            $row = $this->writer ? $this->writer->read($kind, $id) : null;
            $title = $row['title'] ?? $row['name'] ?? null;
            $messages[] = JoomlaLocks::message(is_string($title) ? $title : null, $lockedBy);
        }
        if ($locked === []) return null;
        $locked = array_values($locked);
        return $this->err('locked', implode('; ', $messages), ['code' => 'SLOT_LOCKED_BY_USER', 'lockedBy' => $locked[0]['lockedBy'], 'locked' => $locked]);
    }

    /**
     * The contract door's refusal for rows open in the Joomla editor: throws SLOT_LOCKED_BY_USER, one
     * problem per open row, before the operation writes anything — so a demo trim, a retire, a
     * relabel or a language phase is refused whole, exactly as `apply` is (QuickstartContract::plan).
     * Each problem names the record (`record`: kind, id), the content it is read in when the row is
     * a contract entity (`field.contentId`), and `lockedBy`; `$extra` adds what the operation knows
     * (a language job's `phase`). No flag skips it: the admin's next Save would undo the write.
     *
     * @param list<array{0:string,1:int}> $rows (kind, id) of existing rows the operation would write
     */
    private function refuseLocked(array $rows, array $extra = []): void
    {
        $unique = [];
        foreach ($rows as [$kind, $id])
            if ((int) $id > 0 && isset(JoomlaLocks::TABLES[$kind])) $unique[JoomlaLocks::key($kind, (int) $id)] = [$kind, (int) $id];
        if (!$this->locks || $unique === []) return;
        $held = ($this->locks)(array_values($unique));
        if (!$held) return;
        $owners = null; $problems = [];
        foreach ($unique as $key => [$kind, $id]) {
            $lockedBy = $held[$key] ?? null;
            if (!$lockedBy) continue;
            $entity = null;
            try { $entity = $this->contract ? $this->contract->entityAt($kind, $id) : null; } catch (Throwable $ignored) {}
            // A failed read only costs the refusal its contentId; the refusal itself stands.
            if ($entity !== null && $owners === null) {
                $owners = [];
                try { $owners = $this->contentRevisions ? (($this->contentRevisions)()['owners'] ?? []) : []; } catch (Throwable $ignored) {}
            }
            $row = $this->writer ? $this->writer->read($kind, $id) : null;
            $title = $row['title'] ?? $row['name'] ?? null;
            $problems[] = new ContractProblem('SLOT_LOCKED_BY_USER', JoomlaLocks::message(is_string($title) ? $title : null, $lockedBy),
                null, $entity !== null ? ($owners[$entity] ?? null) : null, ['lockedBy' => $lockedBy, 'record' => ['kind' => $kind, 'id' => $id]] + $extra);
        }
        if ($problems) throw ContractProblem::all($problems);
    }

    /** Where `content.contract derive` binds, and what it reads: `fn(): array{rows:list, pages:list<string>, unresolved:list<string>}`. */
    private ?ContractStore $deriveStore = null;
    private $deriveSource = null;
    /** After a derived apply: `fn(list<string> $paths): array<string,string>` pages that loaded, by path; and the caches only a derived site needs dropped. */
    private $deriveFetch = null;
    private $derivePurge = null;
    /** `fn(int $moduleId): ?string` a page the module is assigned to ('' the home page), or null when none is known. */
    private $deriveModulePage = null;
    private bool $deriveQuickstart = false;
    /** Pages the render check after a derived apply may fetch: it runs under the write lock. */
    private const RENDER_CHECK_PAGES = 3;
    private string $deriveRoot = '';
    /** The request id a derive is replayed by: the shape content.batch takes for `request_id`. */
    private const REQUEST_ID_SHAPE = '/^[a-zA-Z0-9._:-]{1,100}$/D';

    /**
     * Let `content.contract derive` bind an imported site to the map its own rows make. Wired by the
     * host, which alone reads the CMS tables and fetches the rendered pages (JoomlaDerivedRows).
     * `$quickstart`: the component's params name a quickstart contract — a provision whose bind is still
     * to come, or failed; an unbound such site refuses derive (like WordPress' quickstartConfigured()).
     */
    public function derivedSource(ContractStore $store, string $root, callable $source, ?callable $fetch = null, ?callable $purge = null, ?callable $modulePage = null, bool $quickstart = false): self
    {
        $this->deriveQuickstart = $quickstart;
        $this->deriveStore = $store; $this->deriveRoot = $root; $this->deriveSource = $source;
        $this->deriveFetch = $fetch; $this->derivePurge = $purge; $this->deriveModulePage = $modulePage;
        return $this;
    }

    /** Global Configuration's site name and site description (`site.identity`); null answers 'unavailable'. */
    private ?SiteIdentityStore $siteIdentity = null;

    /** Let `site.identity` read and write the site name and site description (lib/SiteIdentity.php). */
    public function siteIdentity(?SiteIdentityStore $store): self
    {
        $this->siteIdentity = $store;
        return $this;
    }

    /** A template's logo, name, slogan and favicon files (`template.siteSettings`); null answers 'unavailable'. */
    private ?TemplateSiteFiles $templateSite = null;

    /** Let `template.siteSettings` read and write a template's site settings (lib/TemplateSiteFiles.php). */
    public function templateSiteSettings(?TemplateSiteFiles $files): self
    {
        $this->templateSite = $files;
        return $this;
    }

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
    /** Whether this receiver is inside a request already (a serialized write re-enters handle). */
    private bool $inRequest = false;

    public function handle(array $req): array
    {
        if (!$this->contract || $this->inRequest) return $this->handleRequest($req);
        // One request, one comparison with the quickstart (QuickstartContract::$tolerated).
        $this->inRequest = true;
        $this->contract->newRequest();
        try { return $this->handleRequest($req); }
        finally { $this->inRequest = false; $this->contract->endRequest(); }
    }

    private function handleRequest(array $req): array
    {
        $provided = isset($req['token']) && is_string($req['token']) ? $req['token'] : null;
        if (!Token::check($this->token, $provided)) {
            return $this->err('unauthorized', 'invalid or missing token');
        }

        $action = isset($req['action']) && is_string($req['action']) ? $req['action'] : '';
        $params = isset($req['params']) && is_array($req['params']) ? $req['params'] : [];

        // 🔒 A CONTRACT INSPECT ONLY READS, SO IT TAKES NO WRITE LOCK. It held the lock for its whole
        // run (5–8 s on the Business lock before rc.12), so an apply arriving meanwhile — or a second
        // inspect — was answered writer_busy, and Tracy had to serialise every contract call per site.
        // It reads under one InnoDB snapshot (the writer's readSnapshot), so a commit between two of its
        // SELECTs is never half seen; the revision it answers is advisory, and the apply re-checks it
        // under the lock (REVISION_STALE). Its one write is the file-proof upsert, idempotent.
        $readOnly = $action === 'content.contract' && (($params['operation'] ?? 'inspect') === 'inspect');
        if ($readOnly && !$this->writing && !$this->reading && $this->writer && method_exists($this->writer, 'readSnapshot')) {
            try {
                return $this->writer->readSnapshot(function () use ($req) {
                    $this->reading = true;
                    try { return $this->handle($req); } finally { $this->reading = false; }
                });
            } catch (Throwable $ignored) {
                // No snapshot to be had (a driver that refuses it): read as before, under the lock.
                $readOnly = false;
            }
        }
        // `site.identity` and `template.siteSettings` write only on `set`; a read takes no lock, so it never waits on an apply.
        $writes = in_array($action,
            ['content.contract', 'content.batch', 'content.update', 'content.delete', 'media.upload', 'apply.revert', 'extension.install', 'extension.enable', 'db.restore', 'db.rollback', 'db.cleanup', 'db.purge', 'core.upgrade', 'files.restore'], true)
            || (in_array($action, ['site.identity', 'template.siteSettings'], true) && ($params['operation'] ?? null) === 'set');
        if (!$this->writing && !$this->reading && !$readOnly && $this->writer && method_exists($this->writer, 'serialize') && $writes) {
            $holder = ['action' => $action, 'operation' => is_string($params['operation'] ?? null) ? $params['operation'] : null,
                'applyId' => is_string($params['apply_id'] ?? null) ? $params['apply_id'] : null];
            try {
                return $this->writer->serialize(function () use ($req) {
                    $this->writing = true;
                    try { return $this->handle($req); } finally { $this->writing = false; }
                }, $holder);
            } catch (WriterBusy $busy) {
                // Same message the Tracy tools match on; who holds the lock, since when, and when to ask again beside it.
                return $this->err('writer_busy', $busy->getMessage(), $busy->facts(time())
                    + (in_array($action, ['content.contract', 'apply.revert'], true) ? ['errors' => [ContractProblem::plain('WRITER_BUSY', $busy->getMessage())]] : []));
            } catch (Throwable $error) {
                return $this->err('writer_busy', $error->getMessage(),
                    in_array($action, ['content.contract', 'apply.revert'], true) ? ['errors' => [ContractProblem::plain('WRITER_BUSY', $error->getMessage())]] : []);
            }
        }

        try { $contractBound=$this->contract && $this->contract->bound(); }
        // The reason travels: since one base archive serves several designs, "unavailable" now also
        // means "this receiver does not carry the profile this site names", and an operator who
        // reads only "repair the component installation" goes looking in the wrong half.
        catch(Throwable $error) { return $this->err('contract_unavailable', $error->getMessage() ?: 'The private contract store is unavailable; repair the component installation'); }
        // A contract RECOMMENDS how to keep the quickstart's design; it locks nothing (Tracy ADR 0022,
        // 26/09/2026). Every action a site without a contract takes, a bound site takes too; the
        // contract's own rules stay on its own door (`content.contract`) and on the picture slots.
        if ($contractBound && $action === 'media.upload') {
            $path = $params['path'] ?? '';
            // Only a picture for a contract slot has the slot's rules: content-addressed, and its own receipt.
            if (is_string($path) && strpos($path, 'images/tracy-content/') === 0) {
                if (!preg_match('~^images/tracy-content/[a-f0-9]{64}\.(png|jpg|webp)$~D', $path)) return $this->err('bad_params', 'A picture under images/tracy-content/ is named by the sha256 of its bytes (<sha256>.png|jpg|webp)');
                if (strpos((string)($params['apply_id']??''),'contract-')===0) return $this->err('bad_params', 'Media uploads must use a separate apply receipt');
            }
        }
        // A trim, a language step or a relabel has its own way back; the ordinary revert would take it
        // apart one row at a time and leave the baseline naming a state the site is no longer in.
        if ($contractBound && $action === 'apply.revert' && $this->log) {
            $own = array_intersect(array_column($this->log->entries($params['apply_id'] ?? ''), 'op'), ['visibility', 'relabel', 'languageDefaults', 'alias']);
            if ($own !== []) return $this->err('bad_params', 'This apply_id is a demo trim, a language step or a source relabel: take it back with its own operation (demoTrim.revert, multilingual.restore, siteLanguage.revert, sourceLanguage.revert)');
        }
        $contractReceipts = $contractBound && $action === 'apply.revert' && $this->log
            ? array_values(array_filter($this->log->entries($params['apply_id'] ?? ''), fn($e) => ($e['op'] ?? '') === 'contract'))
            : [];
        // A contract apply is taken back through the contract (its baseline moves with it); any other
        // receipt on a bound site takes the ordinary revert below.
        if ($contractReceipts !== []) {
            $receipts = $contractReceipts;
            if (count($receipts)!==1) return $this->err('conflict', 'One apply_id holds several contract receipts', ['errors'=>[ContractProblem::plain('CONTRACT_FAILED','One apply_id holds several contract receipts')]]);
            try {
                $this->contract->beginCall();
                $t=Timing::begin();$state=$this->contract->inspect();Timing::end('revertPre',$t);
                if(($receipts[0]['afterRevision']??null)!==$state['revision']) return $this->err('conflict', 'Later content exists; revert the latest revision first', ['errors'=>[ContractProblem::plain('CONFLICT','Later content exists; revert the latest revision first')]]);
                $this->batching=true;
                $result=$this->writer->transaction(function()use($params){
                    $t=Timing::begin();$result=$this->applyRevert($params);Timing::end('revert',$t);
                    if(($result['code']??null)==='SLOT_LOCKED_BY_USER')throw new ContractProblem('SLOT_LOCKED_BY_USER',$result['message'],null,null,['lockedBy'=>$result['lockedBy']]);
                    if(!$result['ok'] || !empty($result['failed']))throw new RuntimeException('Contract revert failed');
                    $t=Timing::begin();$this->contract->inspect();Timing::end('revertPost',$t);
                    return $result;
                });
                $this->batching=false;
                $t=Timing::begin();
                try { $this->writer->purgeCache(); } catch(Throwable $ignored) {}
                Timing::end('purge',$t);
                $this->stamped('revert');
                return $result;
            } catch(Throwable $error) { return $this->contractFailed($error); }
            finally { $this->batching=false; $this->contract->endCall(); }
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
            case 'site.identity':
                return $this->siteIdentityDoor($params);
            case 'template.siteSettings':
                return $this->templateSiteSettingsDoor($params);
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
        // The one core extension an Apply may switch, and only ON: a redirect row does nothing while
        // System - Redirect is off, and the agent cannot open the administrator to fix that itself.
        $redirectOn = $enabled && $type === 'plugin' && $folder === 'system' && $element === 'redirect';
        if (!empty($found['core']) && !$redirectOn) {
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

        // Who is installing, for a package that keeps a receipt of its own installs (this package's
        // script.php writes `door` and this apply_id). Installs stay out of the undo log; the
        // apply_id only says which deliverable asked. Set for this call only.
        $previous = $GLOBALS[self::INSTALL_CONTEXT] ?? null;
        $GLOBALS[self::INSTALL_CONTEXT] = ['trigger' => 'door', 'apply_id' => $this->applyId($p)];
        try {
            if ($sha !== null) {
                if (!method_exists($this->extensions, 'installVerifiedFromUrl')) return $this->err('unavailable', 'verified installer not installed');
                $result = $this->extensions->installVerifiedFromUrl($url, $sha, isset($p['bytes']) ? (int) $p['bytes'] : null);
            } else $result = $this->extensions->installFromUrl($url);
        } catch (Throwable $e) {
            return $this->err('install_failed', $e->getMessage());
        } finally {
            $GLOBALS[self::INSTALL_CONTEXT] = $previous;
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
     *
     * `search` narrows the list to the rows whose title (or name, and alias) holds the words, ignoring
     * case in the alias as in the title (the writer sees to it, whatever the column's collation), so a
     * caller that knows a page by its title asks once instead of paging to it. The answer CARRIES
     * THE WORDS BACK (`search`, the request's once cleaned) and, for a non-empty needle, how many
     * rows match in all (`matched`). The key is the proof that this plugin read the request: a
     * plugin that predates `search` ignored it and answered the whole list as ok, and an echo is the
     * only way a caller can tell the two apart. Hence one rule for every kind: it is filtered or it
     * is refused, never quietly left unfiltered. A request without `search` gets the answer it
     * always got, key for key.
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

        // Present means present: `array_key_exists`, not isset, so a `null` is refused as the
        // non-string it is instead of being read as "no search" and answered unfiltered.
        $needle = null;
        if (array_key_exists('search', $p)) {
            $needle = SearchNeedle::clean($p['search']);
            if (!$needle['ok']) {
                return $this->err('bad_params', $needle['message']);
            }
            if (!$this->writer instanceof SearchableSiteWriter) {
                return $this->err('bad_params', 'this site cannot filter a list by search; leave search out and page the list with offset and limit');
            }
            $searchable = $this->writer->searchableKinds();
            if (!in_array($kind, $searchable, true)) {
                return $this->err('bad_params', 'search does not cover kind "' . $kind . '". '
                    . 'It covers: ' . implode(', ', $searchable) . '. Leave search out and page the list with offset and limit');
            }
        }
        // An empty needle filters nothing: the plain list, still answered with the echo.
        $words = $needle !== null && $needle['variants'] !== [] ? $needle['variants'] : null;

        try {
            $items = $words === null
                ? $this->writer->list($kind, $offset, $limit)
                : $this->writer->searchRows($kind, $words, $offset, $limit);
            if ($withBody) {
                foreach ($items as $index => $row) {
                    $full = $this->writer->read($kind, (int) $row['id']);
                    if ($full !== null) {
                        $items[$index] = $full + $row; // summary keeps category_title/category_path
                    }
                }
            }
            // The count is a second query, so it is not asked when the first page already says it:
            // a first page that did not fill is the whole set.
            $matched = $words === null ? null
                : ($offset === 0 && count($items) < $limit ? count($items) : $this->writer->countMatches($kind, $words));
        } catch (Throwable $e) {
            return $this->err('read_failed', $e->getMessage());
        }

        if ($needle === null) {
            return $this->ok(['kind' => $kind, 'offset' => $offset, 'items' => $items]);
        }
        return $this->ok(['kind' => $kind, 'offset' => $offset, 'search' => $needle['text']]
            + ($matched === null ? [] : ['matched' => $matched])
            + ['items' => $items]);
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

    /**
     * Every refusal of the contract door carries `errors[]` beside the `error`/`message` it always
     * had: a thrown ContractProblem brings its own; a returned refusal is classified by its code.
     */
    private function contentContract(array $p): array
    {
        $result = $this->contractDoor($p);
        // Every answer the door gives while the site differs from its quickstart says where (ADR 0022).
        if (($result['ok'] ?? false) === true && !isset($result['warnings']) && ($warnings = $this->driftWarnings()) !== [])
            $result['warnings'] = $warnings;
        if (($result['ok'] ?? true) === false && !isset($result['errors'])) {
            $code = ['conflict' => 'CONFLICT', 'writer_busy' => 'WRITER_BUSY'][$result['error'] ?? ''] ?? 'CONTRACT_FAILED';
            $result['errors'] = [ContractProblem::plain($code, (string) ($result['message'] ?? ''))];
        }
        return $result;
    }

    /**
     * Where the site differs from its quickstart's design, as warnings — never a refusal (Tracy
     * ADR 0022). Each is `{code: PRESENTATION_DRIFT, message, severity: warning}`.
     * @return list<array{code:string,message:string,severity:string}>
     */
    private function driftWarnings(): array
    {
        return array_map(static fn(string $m) => ['code' => 'PRESENTATION_DRIFT', 'message' => $m, 'severity' => 'warning'],
            $this->contract ? $this->contract->driftWarnings() : []);
    }

    /** A caught contract failure: the message it always had, and what it was, as `errors[]`. */
    private function contractFailed(Throwable $error): array
    {
        return $this->err('contract_failed', $error->getMessage(), ['errors' => ContractProblem::errorsOf($error)]);
    }

    /** A bounded transaction: the same request id returns its committed result after a lost reply.
     * Expected fields are checked under the site writer lock; all row changes and undo entries
     * share one database transaction. Files and extension installers are deliberately excluded.
     */
    private function contractDoor(array $p): array
    {
        // Before the early answers below: an imported site is exactly one with no contract of its own,
        // and the factory may still have handed it the default profile (EngineFactory::buildContract).
        if (($p['operation'] ?? '') === 'derive') return $this->derive($p);
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
                // The reverse of derive's refusal: a site an import derive bound is not a quickstart's to take.
                // Whatever contract this receiver holds: on such a site the factory hands the derived one,
                // and only `derive` (re)binds it.
                if((($this->contract->binding()['mode'] ?? null) === 'derived'))
                    return $this->err('conflict', 'This site is bound to a derived contract; a quickstart bind refuses to replace it');
                if(!$this->writer || !method_exists($this->writer,'transaction'))throw new RuntimeException('Transactional writer required');
                return $this->writer->transaction(function(){
                    $state=$this->contract->inspect();
                    // A site already bound is locked once its inspect is clean — the inspect just
                    // proved it against the stored baseline. Saving again demanded the snapshot equal
                    // that baseline byte for byte, which a half-made language never does: a second
                    // Build on j-ee6vsk died at `style` on "Cannot replace a content-only baseline".
                    if($state['binding']===null)$this->contract->bind($state['snapshot']);
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
            if (strpos((string)($p['operation'] ?? ''), 'sourceLanguage.') === 0) return $this->sourceLanguage($p);
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
                    // The stored afterRevision only while the site still stands there: after another
                    // writer it names a state that is gone, and an apply based on it is refused.
                    $state=$this->contract->inspect();
                    $result=$entry['result'];
                    if(($result['afterRevision']??null)!==$state['revision'])unset($result['afterRevision']);
                    return $result;
                }
            }
            if ($this->log->entries($apply)) throw new RuntimeException('Use one new apply_id per content revision');
            // New source words under a half-made language leave its copies translated from text the
            // site no longer holds, and the baseline cannot be re-saved mid-job anyway — so say which
            // job and how to clear it (j-ee6vsk: a second Build's seed died on "Cannot replace a
            // content-only baseline").
            if (($job = $this->contract->job()) !== null)
                return $this->err('conflict', 'A language job is in flight for ' . $job['locale'] . ' at phase ' . $job['phase'] . ': finish it, or take it back with multilingual.revert locale ' . $job['locale']);
            // Per-content revisions as content.read serves them, read only when the caller named
            // some: an apply based on the inspect revision alone never pays for, or fails on, them.
            $byContent=is_array($p['expected_content_revisions']??null) && $p['expected_content_revisions'];
            // Plan and verify are two inspects with no file written between them: one proof of the files.
            $this->contract->beginCall();
            $t=Timing::begin();$before=$byContent && $this->contentRevisions ? ($this->contentRevisions)() : null;Timing::end('contentRevisions',$t);
            $t=Timing::begin();$plan=$this->contract->plan($p,$before,$this->locks,$this->contentRevisions);Timing::end('plan',$t);
            if(count($plan['operations'])>300)throw new ContractProblem('CHANGES_INVALID','Split the revision into at most 300 entities');
            if(!$plan['operations'])return $this->ok(['unchanged'=>true]);
            $applied = $this->contentBatch(['apply_id'=>$apply,'request_id'=>$request,'operations'=>$plan['operations']], function($result)use($plan,$apply,$request,$hash,$before){
                $t=Timing::begin();$this->contract->bind($plan['snapshot']);Timing::end('bind',$t);
                $t=Timing::begin();$state=$this->contract->inspect();Timing::end('verify',$t);
                $t=Timing::begin();$revisions=$this->revisionsAfter($plan['touched'],$before);Timing::end('revisionsAfter',$t);
                if($revisions!==null)$result['contentRevisions']=$revisions;
                // The inspect revision this apply left, so the next one needs no inspect to learn it.
                // Not `revision`: that name is already this receipt's hash. In the stored result, so a
                // replay answers it too.
                $result['afterRevision']=$state['revision'];
                $t=Timing::begin();
                $this->log->record($apply,['op'=>'contract','request'=>$request,'hash'=>$hash,'result'=>$result,'afterRevision'=>$state['revision']]);
                Timing::end('log',$t);
                return $result;
            });
            // Committed by now: what follows reads the site as a visitor would, outside any transaction.
            if(($applied['ok'] ?? false) === true && $this->contract->isDerived()) $applied = $this->afterDerivedApply($applied, is_array($p['changes'] ?? null) ? $p['changes'] : []);
            return $applied;
        } catch(Throwable $error) { return $this->contractFailed($error); }
        finally { $this->contract->endCall(); }
    }


    /**
     * Bind an imported site to a DERIVED contract: its own rows, read through five codecs, calibrated
     * by its rendered pages (a nested leaf is kept only when a page shows it). Refuses a site bound to
     * a quickstart and a site a quickstart Build is making — that Build's own bind comes next and must
     * find the site unbound. The same requestId again answers what it bound; a new one re-derives.
     */
    private function derive(array $p): array
    {
        if (!$this->deriveStore || !$this->deriveSource || !$this->writer) return $this->err('unavailable', 'Derived contracts are not wired on this receiver');
        $label = $p['label'] ?? null; $requestId = $p['requestId'] ?? null;
        if (!is_string($label) || !preg_match('/^[a-z0-9-]{3,80}$/D', $label)) return $this->err('bad_params', 'label required: 3-80 characters of a-z, 0-9 and -');
        if (!is_string($requestId) || !preg_match(self::REQUEST_ID_SHAPE, $requestId)) return $this->err('bad_params', 'requestId required: 1-100 characters of a-z, A-Z, 0-9 and ._:-, the same on a retry');
        if ($this->constructionBaseline !== null) return $this->err('conflict', 'This site is being built from a quickstart; derive refuses');
        try {
            $binding = $this->deriveStore->load();
            if ($binding !== null && ($binding['mode'] ?? null) !== 'derived')
                return $this->err('conflict', 'This site is bound to a quickstart contract; derive refuses to replace it');
            // Its quickstart bind is still to come: a derive now would take the site from it for good.
            if ($binding === null && $this->deriveQuickstart)
                return $this->err('conflict', 'This site is being built from a quickstart; derive refuses');
            if ($binding !== null && ($binding['requestId'] ?? null) === $requestId)
                return $this->ok(['replayed' => true] + ($binding['derive'] ?? []));
            $source = ($this->deriveSource)();
            $rows = $source['rows'] ?? []; $pages = $source['pages'] ?? [];
            // The rows arrive as a list or in batches (JoomlaDerivedRows::batches): built batch by batch, never
            // held whole. `$texts` gathers what some candidate leaf holds; what a visitor reads beyond it — words
            // in theme files, language strings, text a module makes at run time — is counted, not located (the
            // agent greps for those).
            $texts = [];
            $built = DerivedMap::buildBatches(is_array($rows) ? [$rows] : $rows, $pages ? VisibleText::fromPages($pages) : null, $label, DerivedMap::ALGORITHM, null,
                static function (array $batch) use (&$texts): array {
                    foreach ($batch as $row) {
                        foreach ($row['core'] as $value) $texts[] = (string) $value;
                        foreach ([$row['html'], $row['nested']] as $columns) foreach ($columns as $value) foreach (LeafCodec::leaves((string) $value) as $leaf) $texts[] = $leaf['text'];
                    }
                    return $batch;
                });
            $unresolved = array_values(array_map('strval', $source['unresolved'] ?? []));
            if ($rows instanceof Generator) array_push($unresolved, ...array_map('strval', (array) $rows->getReturn()));
            $nested = count(array_filter($built['map']['slots'], static fn($slot) => $slot['nested']));
            $answer = ['contract' => $built['manifest']['id'], 'entities' => count($built['map']['entities']), 'slots' => count($built['map']['slots']),
                'calibrated' => $built['manifest']['calibrated'],
                'byClass' => ['db' => count($built['map']['slots']) - $nested, 'nested' => $nested, 'unmatched' => $pages ? VisibleText::unmatched($pages, $texts) : 0],
                'unresolved' => $unresolved];
            $contract = QuickstartContract::derived($this->writer, $this->deriveStore, $this->deriveRoot, static fn() => $built);
            $contract->bindDerived(['requestId' => $requestId, 'derivedAt' => gmdate('c'), 'keep' => DerivedMap::keepOf($built), 'derive' => $answer]);
            // The rest of this request (and a test's next call) sees the site as it is now bound.
            $this->contract = $contract;
            return $this->ok($answer + ['replayed' => false]);
        } catch (Throwable $error) { return $this->contractFailed($error); }
    }

    /**
     * After a derived apply: drop what only a derived site caches (the writer's own purge already ran),
     * then fetch the pages that own the written words ({@see ownerPage}, at most three) and warn
     * `WRITTEN_NOT_VISIBLE` (shaped like a drift warning) for words the page does not show.
     * A warning, never a refusal: the write stands, and its apply_id takes it back. A page that could
     * not be fetched says nothing. Imported sites print words through paths no row describes (a
     * template override, a language string), and this is how the agent learns its write missed.
     */
    private function afterDerivedApply(array $result, array $changes): array
    {
        if ($this->derivePurge) { try { ($this->derivePurge)(); } catch (Throwable $ignored) {} }
        if (!$this->deriveFetch) return $result;
        $checks = [];
        // Uncalibrated, the map kept every nested leaf whether a page shows it or not (a meta description,
        // a setting): only a row's own text (core and HTML columns) is worth checking then.
        $calibrated = ($this->contract->binding()['calibrated'] ?? true) !== false;
        foreach ($this->contract->derivedOwners(array_keys($changes)) as $key => $owner) {
            $value = $changes[$key] ?? null;
            if ($owner['type'] !== 'text' || !is_string($value) || trim($value) === '' || (!$calibrated && $owner['nested'])) continue;
            $page = $this->ownerPage($owner['kind'], $owner['id']);
            if ($page !== null) $checks[$page][] = [$key, $value];
        }
        $checks = array_slice($checks, 0, self::RENDER_CHECK_PAGES, true);
        if (!$checks) return $result;
        try { $pages = ($this->deriveFetch)(array_map('strval', array_keys($checks))); }
        catch (Throwable $ignored) { return $result; }
        $warnings = [];
        foreach ($checks as $url => $slots) {
            $html = $pages[(string) $url] ?? null;
            if (!is_string($html) || $html === '') continue;
            $seen = VisibleText::fromPages([$html]);
            foreach ($slots as [$key, $value])
                if (!VisibleText::shows($seen, ['type' => 'text', 'text' => $value]))
                    $warnings[] = ['code' => 'WRITTEN_NOT_VISIBLE', 'message' => 'Written, but ' . $url . ' does not show it', 'severity' => 'warning', 'slotKey' => (string) $key, 'url' => (string) $url];
        }
        if ($warnings) $result['warnings'] = array_merge($this->driftWarnings(), $warnings);
        return $result;
    }

    /**
     * The page a derived entity's words show on, as a path from the site root ('' the home page), or
     * null when no page is known: an unchecked write is better than a false warning from the wrong page.
     * An article (and its custom field values) on its own page, a category on its list, a menu item on
     * its own page, a module on a page it is assigned to (the host reads #__modules_menu), a template
     * style on the home page.
     */
    private function ownerPage(string $kind, int $id): ?string
    {
        switch ($kind) {
            case 'article': return 'index.php?option=com_content&view=article&id=' . $id;
            case 'fieldValue': return 'index.php?option=com_content&view=article&id=' . FieldValueKey::decode($id)[1];
            case 'category': return 'index.php?option=com_content&view=category&id=' . $id;
            case 'module':
                if (!$this->deriveModulePage) return null;
                try { $page = ($this->deriveModulePage)($id); } catch (Throwable $ignored) { return null; }
                return is_string($page) ? $page : null;
            case 'menuItem': return 'index.php?Itemid=' . $id;
            case 'templateStyle': return '';
            default: return null;
        }
    }

    /**
     * The revision of every content this apply changed, read inside its transaction after the write:
     * the contents owning the entities written and, when the revisions before are known, any other
     * content whose projection moved with them (a page naming a shared module by its title). Null
     * when the projection cannot be read — a receipt is not worth rolling back a checked write for.
     *
     * @param list<string> $touched contract entity keys the apply wrote
     * @return array<string,string>|null
     */
    private function revisionsAfter(array $touched, ?array $before): ?array
    {
        if (!$this->contentRevisions) return null;
        try { $after = ($this->contentRevisions)(); } catch (Throwable $ignored) { return null; }
        $out = [];
        foreach ($touched as $key) {
            $id = $after['owners'][$key] ?? null;
            if ($id !== null && isset($after['revisions'][$id])) $out[$id] = $after['revisions'][$id];
        }
        if ($before !== null)
            foreach ($after['revisions'] as $id => $revision)
                if (($before['revisions'][$id] ?? null) !== $revision) $out[$id] = $revision;
        ksort($out);
        return $out;
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
            case 'retire':
                return $this->multilingualRetire($p);
            case 'restore':
                return $this->multilingualRestore($p);
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
            } catch (Throwable $error) { return $this->contractFailed($error); }
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
            // 🔒 NOT UNDER AN OPEN EDITOR, AND NOT EVEN THE RECORD. The rows this call would move are
            // checked before the first write of the call, the record included: a refused call leaves
            // the site, and a trim in flight, exactly where they were, so the same request resumes.
            $hide = $operation === 'apply';
            $checked = false;
            if ($trim === null || $trim['status'] !== $record['status'] || $trim['requestId'] !== $request) {
                $this->writer->transaction(function () use ($record, $hide) {
                    $state = $this->contract->inspect();
                    $this->refuseLocked($this->demoTrimRows($state, $hide));
                    if (!$state['binding']) $this->contract->bind($state['snapshot']);
                    $this->contract->rebind($this->contract->bindingWithTrim($record));
                    return [];
                });
                $checked = true;
            }
            $this->batching = true;
            $step = $this->writer->transaction(function () use ($hide, $apply, $checked) {
                $state = $this->contract->inspect();
                if (!$checked) $this->refuseLocked($this->demoTrimRows($state, $hide));
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
            return $this->contractFailed($error);
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
            return $this->contractFailed($error);
        }
    }

    /**
     * `content.contract` sourceLanguage.plan | set | revert — call the source edition by another tag
     * of the SAME language: en-GB → en-US, en-AU, en-CA, en-NZ.
     *
     * 🔒 A RELABEL, NOT A COPY. Tracy Business is written in en-GB and ships no en-US edition; a
     * customer who wants "English (United States)" wants THAT text under the US tag. A copy would
     * duplicate every row and lose the main menu (the template reads `tb-main-<sef>`, and a copy's
     * menus are not the archive's) — measured on `j-ee6vsk`, 23/09/2026. So the tag changes and the
     * `sef` does not: `/en/…`, `tb-main-en` and the megamenu keep working untouched, and `<html lang>`
     * and hreflang say en-US. Another LANGUAGE is not a relabel; that is multilingual.* or siteLanguage.*.
     */
    private function sourceLanguage(array $p): array
    {
        if (!$this->contract->siteLanguageAvailable())
            return $this->err('unsupported', 'This receiver carries no language-pack catalog for this site');
        $operation = substr((string) $p['operation'], strlen('sourceLanguage.'));
        foreach (['url', 'sha256', 'bytes', 'package'] as $mine)
            if (isset($p[$mine]))
                return $this->err('bad_params', 'Name a locale, not a package: `' . $mine . '` is decided by the receiver’s reviewed catalog');
        $major = (int) (explode('.', (string) ($this->info['joomla'] ?? '0'))[0]);
        if ($major < 1) return $this->err('contract_failed', 'The site did not report its Joomla version');
        $locale = isset($p['locale']) && is_string($p['locale']) ? $p['locale'] : '';
        try {
            $published = $this->contract->publishedSourceLanguage();
            $current = $this->contract->sourceLanguage();
            $sameLanguage = fn (string $tag) => preg_match('/^[a-z]{2,3}-[A-Z]{2,4}$/D', $tag) && explode('-', $tag)[0] === explode('-', $published)[0];
            if ($operation === 'plan') {
                if ($locale !== '' && !$sameLanguage($locale))
                    return $this->err('bad_params', $locale . ' is not a variant of ' . $published . '; another language is added with multilingual.* or set with siteLanguage.*');
                $pack = $locale === '' || $locale === $published ? null : $this->contract->languagePackage($locale, $major);
                return $this->ok([
                    'published' => $published, 'current' => $current, 'locale' => $locale ?: null,
                    'package' => $pack === null ? null : ['tag' => $pack['tag'], 'version' => $pack['version'], 'bytes' => $pack['bytes']],
                    'installed' => $pack === null || $this->languagePackPresent($locale),
                    'onRecord' => $this->contract->binding()['sourceRelabel'] ?? null,
                ]);
            }
            if ($operation !== 'set' && $operation !== 'revert') return $this->err('bad_params', 'Unknown sourceLanguage operation');
            $apply = $this->applyId($p);
            $request = $p['request_id'] ?? '';
            if (!$apply || strpos($apply, 'srclang-') !== 0) return $this->err('bad_params', 'apply_id must start with "srclang-"');
            if (!is_string($request) || !preg_match('/^[a-zA-Z0-9._:-]{1,100}$/D', $request))
                return $this->err('bad_params', 'request_id required: any string, the same on a retry and new for a different change');
            if (($job = $this->contract->job()) !== null)
                return $this->err('conflict', 'A language job is in flight for ' . $job['locale'] . ' at phase ' . $job['phase'] . '; finish or revert it before relabelling the source');
            $binding = $this->contract->binding();
            $onRecord = $binding['sourceRelabel'] ?? null;
            if ($operation === 'revert') {
                if ($onRecord === null) return $this->err('contract_failed', 'This site’s source edition still carries its published tag; there is nothing to take back');
                $this->refuseLocked($this->rowsInLanguage((string) $onRecord['to']));
                $this->relabelSource($apply, (string) $onRecord['to'], $published, null, $onRecord);
                return $this->ok(['status' => 'reverted', 'source' => $published]);
            }
            if (!$sameLanguage($locale))
                return $this->err('bad_params', ($locale ?: 'That') . ' is not a variant of ' . $published . '; another language is added with multilingual.* or set with siteLanguage.*');
            if ($locale === $current) return $this->ok(['status' => 'completed', 'source' => $locale, 'alreadySet' => true]);
            if ($onRecord !== null)
                return $this->err('conflict', 'This site’s source edition is already called ' . $current . '; take that back with sourceLanguage.revert before choosing another');
            if (in_array($locale, $this->contract->derivedLanguages(), true))
                return $this->err('conflict', 'This site already has a ' . $locale . ' edition; the source cannot take its name');
            // Before the pack too: an installer is a write, and a relabel refused afterwards would
            // leave its content language behind for nothing.
            $this->refuseLocked($this->rowsInLanguage($current));
            $pack = $this->contract->languagePackage($locale, $major);
            if ($pack !== null && !$this->languagePackPresent($locale)) {
                if ($this->extensions === null || !method_exists($this->extensions, 'installVerifiedFromUrl')) return $this->err('unavailable', 'verified installer not installed');
                $result = $this->extensions->installVerifiedFromUrl($pack['url'], $pack['sha256'], (int) $pack['bytes']);
                if (($result['ok'] ?? false) !== true) return $this->err('install_failed', (string) ($result['error'] ?? 'installer refused the package'));
                if (!$this->languagePackPresent($locale)) return $this->err('install_failed', 'The ' . $locale . ' pack installed but Joomla does not list it');
                $this->stamped('extension');
            }
            // A site relabelled for the first time here is bound as it stands, before anything moves.
            if ($binding === null) $this->writer->transaction(function () {
                $this->contract->bind($this->contract->inspect()['snapshot']);
                return [];
            });
            $this->relabelSource($apply, $current, $locale, [
                'from' => $current, 'to' => $locale, 'applyId' => $apply, 'requestId' => $request,
                'packVersion' => $pack['version'] ?? null, 'at' => gmdate('c'),
            ], null);
            return $this->ok(['status' => 'completed', 'source' => $locale, 'packVersion' => $pack['version'] ?? null]);
        } catch (Throwable $error) {
            return $this->contractFailed($error);
        }
    }

    /**
     * Every row a relabel from `$tag` rewrites (SiteWriter::relabelLanguage), as (kind, id): each
     * kind the editor checks out, read by its listing, kept where it carries the tag. A relabel is
     * rare and one-off, so a listing of every such table is what its lock check costs.
     */
    private function rowsInLanguage(string $tag): array
    {
        $rows = [];
        if (!$this->locks) return $rows;
        foreach (['article', 'category', 'tag', 'field', 'contact', 'newsfeed', 'banner', 'module', 'menuItem'] as $kind)
            for ($offset = 0; $offset < 20000; $offset += 100) {
                $page = $this->writer->list($kind, $offset, 100);
                foreach ($page as $row)
                    if ((string) ($row['language'] ?? '') === $tag && (int) ($row['client_id'] ?? 0) === 0) $rows[] = [$kind, (int) $row['id']];
                if (count($page) < 100) break;
            }
        return $rows;
    }

    /** One relabel, logged, recorded and proven in a single transaction; $record null takes it back. */
    private function relabelSource(string $apply, string $from, string $to, ?array $record, ?array $undoing): void
    {
        $this->writer->transaction(function () use ($apply, $from, $to, $record, $undoing) {
            $defaults = $this->writer->readLanguageDefaults();
            $moved = $this->writer->relabelLanguage($from, $to, $undoing['label'] ?? null);
            if ($record !== null) $record['label'] = $moved['previous'];
            $this->writer->writeLanguageDefaults(
                $defaults['site'] === $from ? $to : $defaults['site'],
                $defaults['administrator'] === $from ? $to : $defaults['administrator']
            );
            $this->log->record($apply, ['op' => 'relabel', 'from' => $from, 'to' => $to, 'label' => $moved['previous'], 'defaults' => $defaults]);
            $this->contract->rebind($this->contract->bindingWithSourceRelabel($record));
            // Proven, then stored — the same two steps every other sealed write ends with.
            $this->contract->rebind($this->contract->inspect()['snapshot']);
            return [];
        });
        try { $this->writer->purgeCache(); } catch (Throwable $ignored) {}
        $this->stamped('content');
    }

    /** The rows the next demo-trim batch would move, as (kind, id). */
    private function demoTrimRows(array $state, bool $hide): array
    {
        $rows = [];
        foreach (array_slice($this->demoTrimPending($state, $hide), 0, self::DEMO_TRIM_BATCH, true) as $key => [$kind])
            $rows[] = [$kind, (int) $state['ids'][$key]];
        return $rows;
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
        } catch (Throwable $error) { return $this->contractFailed($error); }
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
            // Rows the phase was checked for before it began (see the transaction below). A write to an
            // existing row outside that list is checked on its own before it lands, so no path through
            // a phase writes under an open editor, even one pendingRows() did not foresee.
            $checked = [];
            $phase = $job['phase'];
            $guard = function (string $kind, int $id) use (&$checked, $phase): void {
                if ($id > 0 && !isset($checked[JoomlaLocks::key($kind, $id)])) $this->refuseLocked([[$kind, $id]], ['phase' => $phase]);
            };
            $executor = new MultilingualApply($this->contract, $this->writer, function (string $kind, int $id, array $fields) use ($apply, $guard): int {
                $guard($kind, $id);
                $answer = $this->contentUpdate(['apply_id' => $apply, 'kind' => $kind, 'id' => $id, 'fields' => $fields]);
                if (empty($answer['ok'])) throw new RuntimeException($kind . ' ' . $id . ': ' . ($answer['message'] ?? json_encode($answer['error'])));
                return (int) $answer['id'];
            }, function (int $id, string $before, string $after) use ($apply, $guard): void {
                $guard('menuItem', $id);
                // Undo first, as retire does: a row whose way back was not recorded is never moved.
                $this->log->record($apply, ['op' => 'alias', 'kind' => 'menuItem', 'id' => $id, 'before' => $before]);
                $this->writer->realiasMenuItem($id, $after);
            }, function (string $kind, int $id, string $column, int $value) use ($apply, $guard): void {
                $guard($kind, $id);
                $before = (int) (($this->writer->read($kind, $id) ?? [])[$column] ?? 0);
                $this->log->record($apply, ['op' => 'visibility', 'kind' => $kind, 'id' => $id, 'column' => $column, 'before' => $before]);
                $this->setVisible($kind, $id, $column, $value);
            });
            $this->batching = true;
            $done = $this->writer->transaction(function () use ($executor, $job, $translations, $locale, &$checked) {
                // A fresh read inside the transaction: the phase must act on the site as it is now,
                // not on a picture taken before another writer had its turn.
                $state = $this->contract->inspect();
                // 🔒 THE PHASE THAT WOULD WRITE AN OPEN RECORD IS REFUSED BEFORE ITS FIRST WRITE. Every
                // existing row the phase writes, not just this chunk's; the job is not advanced and not
                // saved, so the same request resumes at the same phase once the editor is closed.
                $rows = $executor->pendingRows($job, $state);
                $this->refuseLocked($rows, ['phase' => $job['phase']]);
                foreach ($rows as [$kind, $id]) $checked[JoomlaLocks::key($kind, (int) $id)] = true;
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
            return $this->contractFailed($error);
        }
    }



    /**
     * Bring back every row one retire pass hid, by its apply id. The sealed site's `apply.revert` takes
     * contract receipts only, so a retire has its own way back — through the same column it moved.
     */
    private function multilingualRestore(array $p): array
    {
        if ($this->writer === null || $this->log === null) return $this->err('unavailable', 'site writer not wired');
        $apply = $this->applyId($p);
        if (!$apply || strpos($apply, 'mlang-') !== 0) return $this->err('bad_params', 'the mlang- apply_id of the retire pass is required');
        try {
            $t = Timing::begin();
            $entries = array_values(array_filter($this->log->entries($apply), fn ($e) => ($e['op'] ?? '') === 'visibility'));
            Timing::end('restoreEntries', $t);
            Timing::count('restoreRows', count($entries));
            if (!$entries) return $this->err('contract_failed', 'No retire pass is recorded under ' . $apply);
            $t = Timing::begin();
            $this->refuseLocked($this->undoRows($entries));
            Timing::end('restoreLocks', $t);
            $t = Timing::begin();
            $this->writer->transaction(function () use ($entries) {
                $t = Timing::begin();
                // Newest first, as revertOne() replays any log: where one row was recorded twice, the
                // oldest entry's `before` is the value it is left with.
                $this->setVisibleMany(array_map(fn (array $entry): array => [(string) ($entry['kind'] ?? ''), (int) ($entry['id'] ?? 0),
                    (string) ($entry['column'] ?? ''), (int) ($entry['before'] ?? 1)], array_reverse($entries)));
                Timing::end('restoreShow', $t);
                return [];
            });
            Timing::end('restoreTransaction', $t);
            $t = Timing::begin();
            $this->log->clear($apply);
            Timing::end('restoreClear', $t);
            $t = Timing::begin();
            try { $this->writer->purgeCache(); } catch (Throwable $ignored) {}
            Timing::end('purge', $t);
            $t = Timing::begin();
            $this->stamped('revert');
            Timing::end('stamped', $t);
            return $this->ok(['restored' => count($entries), 'applyId' => $apply]);
        } catch (Throwable $error) {
            return $this->contractFailed($error);
        }
    }

    /**
     * How many rows one retire call hides before it answers `running`. Through the full write path
     * the same pass ran ~6 rows a second and outlived the 60 s its callers allowed (measured
     * 23/09/2026). One read and one UPDATE per row (`setVisible`) still cost ~0.5 ms a row, and its
     * undo row ~0.7 ms: 7 to 8 s a Business pass on an idle local stand (05/10/2026). A chunk is now
     * written in bulk (`setVisibleMany`, `ApplyLog::recordMany`): 0.2 s for the same pass.
     */
    private const RETIRE_CHUNK = 3000;

    /** Every column `MultilingualApply::retireWrites` reads of a row, of any of the four kinds. */
    private const RETIRE_COLUMNS = ['id' => true, 'lang_id' => true, 'lang_code' => true, 'published' => true, 'state' => true, 'language' => true, 'client_id' => true];

    /**
     * Show or hide one row by its visibility column, and nothing else. Not write(): for an article or
     * a module that goes through Joomla's Table, which mints an `#__assets` row and moves the ACL
     * (0.16.9, measured on j-1pd0de). A content language has no asset, so it is the one kind that
     * does go through write() — setVisibility() does not serve it.
     */
    private function setVisible(string $kind, int $id, string $column, int $value): void
    {
        if ($kind === 'language') {
            if ($column !== 'published') throw new RuntimeException('published is the visibility column of a language');
            $this->writer->write('language', $id, ['published' => $value]);
            return;
        }
        $this->writer->setVisibility($kind, $id, $column, (string) $value);
    }

    /**
     * setVisible() for many rows, each [kind, id, column, value]: the site is left as that many
     * setVisible() calls in that order would leave it, so where one row is named twice the later
     * value stands. A language still goes through setVisible() one row at a time (write(), see
     * above; a site has a few dozen); every other kind in one setVisibilityMany() per kind, column
     * and value, which refuses the whole set when one row is not there. The caller holds the
     * transaction, so a refusal takes back whatever this wrote before it.
     *
     * @param list<array{0:string,1:int,2:string,3:int}> $rows
     */
    private function setVisibleMany(array $rows): void
    {
        $last = [];
        foreach ($rows as [$kind, $id, $column, $value]) {
            if ($kind === 'language') { $this->setVisible($kind, $id, $column, $value); continue; }
            $last[$kind . "\0" . $column . "\0" . $id] = [$kind, $id, $column, $value];
        }
        $groups = [];
        foreach ($last as [$kind, $id, $column, $value]) {
            $group = $kind . "\0" . $column . "\0" . $value;
            $groups[$group] ??= [$kind, $column, $value, []];
            $groups[$group][3][] = $id;
        }
        foreach ($groups as [$kind, $column, $value, $ids]) $this->writer->setVisibilityMany($kind, $ids, $column, (string) $value);
    }

    /**
     * Hide every language the customer did not ask for, and every row the quickstart shipped in a
     * language the contract does not govern (why, and what is never touched:
     * `MultilingualApply::retireWrites`). `keep` names the languages the site should route; only
     * those that have been DERIVED stay published, so a language still waiting for its words is
     * not routed onto the archive's edition of it. Chunked: repeat the call until it answers
     * `completed`. Every row is recorded under `apply_id` as a `visibility` undo; on a sealed site, where
     * `apply.revert` takes contract receipts only, `multilingual.restore` with the same id brings the
     * pass back.
     */
    private function multilingualRetire(array $p): array
    {
        $apply = $this->applyId($p);
        if (!$apply || strpos($apply, 'mlang-') !== 0) return $this->err('bad_params', 'an mlang- apply_id is required');
        $keep = $p['keep'] ?? null;
        if (!is_array($keep)) return $this->err('bad_params', 'keep must list the languages the site routes');
        foreach ($keep as $tag)
            if (!is_string($tag) || !preg_match('/^[a-z]{2,3}-[A-Za-z]{2,4}$/D', $tag)) return $this->err('bad_params', 'keep holds language tags like zh-CN');
        try {
            if (($job = $this->contract->job()) !== null)
                return $this->err('conflict', 'A language job is in flight for ' . $job['locale'] . ' at phase ' . $job['phase']);
            $t = Timing::begin();
            $governed = $this->contract->governedIds();
            $routed = array_values(array_intersect($this->contract->derivedLanguages(), $keep));
            Timing::end('retireGoverned', $t);
            $t = Timing::begin();
            // The rows read as an inspect reads them (ContractRows): with a bulk reader, one SELECT per
            // kind. list() also works out, for every article, its routed URL, a menu lookup, its author,
            // its tags and its access level — none of which this pass reads, and 3.4 to 5 s of every
            // call on Business (05/10/2026). A writer without a bulk reader walks list() as before.
            // Each kind is cut down to the columns retireWrites() reads as soon as it is read, so the
            // full rows (an article's body, a module's content) are gone before the next kind and the
            // transaction: one kind's full rows at a time, never all four.
            $rows = [];
            foreach (['language', 'article', 'menuItem', 'module'] as $kind) {
                $rows[$kind] = [];
                foreach ((new ContractRows($this->writer))->summaries($kind) as $row) $rows[$kind][] = array_intersect_key($row, self::RETIRE_COLUMNS);
            }
            Timing::end('retireList', $t);
            $t = Timing::begin();
            // A kept language the archive ships an edition of stays exactly as shipped: that edition IS
            // the language the customer asked for, and its rows receive the translation in place.
            $spared = array_values(array_intersect($this->contract->profile()->editionLocales(), $keep));
            $writes = MultilingualApply::retireWrites($rows, $governed, $this->contract->profile()->sourceLanguage(), array_values(array_unique(array_merge($routed, $spared))), $spared);
            $slice = array_slice($writes, 0, self::RETIRE_CHUNK);
            Timing::end('retireWrites', $t);
            Timing::count('retireRows', count($slice));
            $t = Timing::begin();
            // Every row this pass hides, before the first: one open in the editor refuses the pass.
            $this->refuseLocked(array_map(fn ($write) => [$write[0], (int) $write[1]], $slice));
            Timing::end('retireLocks', $t);
            $t = Timing::begin();
            $this->writer->transaction(function () use ($slice, $apply) {
                // Undo first, then the change: a row whose undo could not be recorded is never hidden.
                // Every undo is written, in the order the rows are listed, before the first row moves;
                // one transaction holds both, so a row refused takes every undo and every row back.
                $entries = []; $hide = [];
                foreach ($slice as [$kind, $id, $fields]) {
                    $column = (string) array_key_first($fields);
                    $entries[] = ['op' => 'visibility', 'kind' => $kind, 'id' => $id, 'column' => $column, 'before' => 1];
                    $hide[] = [$kind, $id, $column, 0];
                }
                $t = Timing::begin();
                $this->log->recordMany($apply, $entries);
                Timing::end('retireLog', $t);
                $t = Timing::begin();
                $this->setVisibleMany($hide);
                Timing::end('retireHide', $t);
                return [];
            });
            Timing::end('retireTransaction', $t);
            $t = Timing::begin();
            try { $this->writer->purgeCache(); } catch (Throwable $ignored) {}
            Timing::end('purge', $t);
            $t = Timing::begin();
            if ($slice) $this->stamped('content');
            Timing::end('stamped', $t);
            $hidden = [];
            foreach ($slice as [$kind]) $hidden[$kind] = ($hidden[$kind] ?? 0) + 1;
            return $this->ok([
                'status' => count($writes) > count($slice) ? 'running' : 'completed',
                'hidden' => $hidden, 'remaining' => count($writes) - count($slice),
                'routed' => array_merge([$this->contract->profile()->sourceLanguage()], $routed),
                'applyId' => $apply,
            ]);
        } catch (Throwable $error) {
            return $this->contractFailed($error);
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
        // A taken edition's rows are the archive's own: taking the language back returns their words
        // and visibility through the undo log, and deletes nothing.
        if (isset($this->contract) && $this->contract->profile()->edition($locale)) return [];
        foreach ($state['keys'] as $key => $meta) {
            if (($meta['locale'] ?? null) !== $locale) continue;
            $out[$meta['kind']][] = (int) $state['ids'][$key];
        }
        // Only a switcher the job CREATED is its to take back. A profile may reuse the archive's own
        // (Business: module-425), and that row is a governed base entity — deleting it with the
        // orphans left the site without its switcher and every later inspect dead on "Bound entity
        // disappeared: module-425" (measured 23/09/2026 on j-ee6vsk).
        $governed = [];
        foreach ($state['keys'] as $key => $meta)
            if ($meta['kind'] === 'module' && !isset($meta['locale']) && empty($meta['switcher']) && isset($state['ids'][$key]))
                $governed[] = (int) $state['ids'][$key];
        if ($state['switcher'] !== null && !in_array((int) $state['switcher'], $governed, true)) $out['module'][] = (int) $state['switcher'];
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
            if (IdentityTokens::hasDirective($value)) { $say('carries a Joomla plugin directive'); continue; }
            foreach (MultilingualProfile::preservationErrors($source, $value) as $lost) $say($lost);
        }
        return $out;
    }

    /** Re-read the whole contract, then report what the language actually consists of. */
    private function multilingualVerify(string $locale): array
    {
        try { $state = $this->contract->inspect(); }
        catch (Throwable $error) { return $this->contractFailed($error); }
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
            // Every row the undo log would put back and every leftover it would remove, before any.
            $rows = $this->undoRows($this->log->entries($applyId));
            foreach ($leftovers as $kind => $ids) foreach ($ids as $id) $rows[] = [$kind, (int) $id];
            $this->refuseLocked($rows);
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
            return $this->contractFailed($error);
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
        // Every existing row the batch names, checked before the first write so an open one refuses
        // the whole batch. The contract door checked its own rows in plan(), with slot and content.
        if (!$verify) {
            $rows = [];
            foreach ($steps as $step)
                if (is_array($step) && is_string($step['kind'] ?? null) && is_numeric($step['id'] ?? null)) $rows[] = [$step['kind'], (int) $step['id']];
            if (($refusal = $this->lockRefusal($rows)) !== null) return $refusal;
        }
        // Whether this apply_id holds nothing yet: then every entry found after a failure is this batch's.
        try { $fresh = $this->log->entries($apply) === []; } catch (Throwable $error) { $fresh = false; }
        $this->inFlight = null;
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
                $t = Timing::begin();
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
                                throw new ContractProblem('CONFLICT', "conflict at operation {$index}: {$key} changed");
                            }
                        }
                    }
                    $answer = $this->contentUpdate(['apply_id' => $apply, 'kind' => $kind, 'id' => (int) $id, 'fields' => $fields]);
                    if (empty($answer['ok'])) throw new RuntimeException("operation {$index}: " . ($answer['message'] ?? json_encode($answer['error'])));
                    $key = $step['key'] ?? (string) $index;
                    if (!is_string($key) || isset($ids[$key])) throw new RuntimeException('operation keys must be unique strings');
                    $ids[$key] = $answer['id'];
                }
                Timing::end('write', $t);
                $result = $this->ok(['ids' => $ids, 'revision' => hash('sha256', $apply . ':' . $request . ':' . $hash)]);
                // The contract door's check may add to the receipt (its content revisions).
                if ($verify) $result = $verify($result) ?? $result;
                $this->log->record($apply, ['op' => 'batch', 'request' => $request, 'hash' => $hash, 'result' => $result]);
                return $result;
            });
            $this->batching = false;
            $t = Timing::begin();
            $this->writer->purgeCache();
            Timing::end('purge', $t);
            $this->stamped('content');
            return $result;
        } catch (Throwable $error) {
            $this->batching = false;
            $message = $error->getMessage();
            $errors = $verify ? ContractProblem::errorsOf($error) : [];
            $undo = $fresh ? $this->undoFailedBatch($apply) : null;
            if ($undo === []) {
                $message .= '; every write of this batch was taken back';
            } elseif ($undo !== null) {
                $left = 'Some writes of this batch stay on the site and could not be taken back (' . implode('; ', $undo) . '): apply.revert ' . $apply . ' finishes it';
                $message .= '; ' . $left;
                if ($verify) $errors[] = ContractProblem::plain('PARTIAL_APPLY', $left);
            }
            return $this->err('batch_failed', $message, $verify ? ['errors' => $errors] : []);
        } finally {
            $this->inFlight = null;
        }
    }

    /**
     * Take back what a failed batch left on the site, after its transaction rolled back.
     *
     * 🔒 THE ROLLBACK DOES NOT HOLD A JOOMLA TABLE WRITE. Table::store() on an article or a module
     * stores its `#__assets` row through Table\Nested::store(), which takes `LOCK TABLES #__assets
     * WRITE`, and LOCK TABLES is an implicit COMMIT in MySQL and MariaDB: every write before it is
     * committed, and every statement after it autocommits. Measured 08/10/2026 on a JA Podcast j6
     * stand (TCH #1013, D3): a contract apply whose third write failed kept the first two, and the
     * ROLLBACK at the end found nothing to roll back. Each write recorded its undo as it went, and
     * those records were committed with it, so what the rollback missed is exactly what the log still
     * holds for this apply_id. The write that failed comes first, if it landed before it threw.
     *
     * Returns null when nothing was left behind (the rollback held), [] when everything was taken
     * back, or what could not be.
     *
     * @return list<string>|null
     */
    private function undoFailedBatch(string $apply): ?array
    {
        $inFlight = $this->inFlight;
        $this->inFlight = null;
        $left = [];
        $undone = false;
        if ($inFlight !== null) {
            [$kind, $id, $before] = $inFlight;
            try {
                if ($this->writer->read($kind, $id) != $before) {
                    $this->rollbackContent($kind, $id, $before);
                    $undone = true;
                }
            } catch (Throwable $e) {
                $left[] = "{$kind} {$id}: " . $e->getMessage();
            }
        }
        try {
            $entries = $this->log->entries($apply);
        } catch (Throwable $e) {
            return array_merge($left, ['the undo log: ' . $e->getMessage()]);
        }
        foreach (array_reverse($entries) as $entry) {
            try {
                $this->revertOne($entry);
                $undone = true;
            } catch (Throwable $e) {
                $left[] = ($entry['op'] ?? '?') . ' ' . ($entry['kind'] ?? '') . ' ' . ($entry['id'] ?? '') . ': ' . $e->getMessage();
            }
        }
        if (!$undone && $left === []) return null;
        // A visitor may have been served the half-written site while the writes stood.
        try { $this->writer->purgeCache(); } catch (Throwable $e) {}
        if ($left === []) {
            try { $this->log->clear($apply); }
            catch (Throwable $e) { $left[] = 'the undo log could not be cleared: ' . $e->getMessage(); }
        } else {
            $this->stamped('content');
        }
        return $left;
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
        // A batch checked all its rows before writing any; a lone update checks its own.
        if (!$this->batching && ($refusal = $this->lockRefusal([[$kind, $id]])) !== null) return $refusal;
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
                if ($this->batching && $before !== null) $this->inFlight = [$kind, $id, $before];
                $newId = $this->writer->write($kind, $id, $fields);
            } catch (Throwable $e) {
                return $moveTo !== null
                    ? $this->err('write_failed', 'the move landed (revert via apply.revert); the field update failed: ' . $e->getMessage())
                    : $this->err('write_failed', $e->getMessage());
            }

            try {
                $this->log->record($applyId, ['op' => 'content', 'kind' => $kind, 'id' => $newId, 'before' => $before]);
                $this->inFlight = null;
            } catch (Throwable $e) {
                $this->rollbackContent($kind, $newId, $before);
                $this->inFlight = null;
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
        if ($kind === 'redirect' && ($warning = $this->redirectPluginWarning()) !== null) {
            $out['warnings'] = [$warning];
        }
        return $this->ok($out);
    }

    /**
     * A redirect row does nothing while the System - Redirect plugin is off: Joomla reads
     * `#__redirect_links` only from that plugin's error handler. The row is saved either way, so
     * the answer says so instead of leaving a caller to believe the old address now forwards.
     * Read-only, and silent when the extension manager is not wired or cannot tell.
     */
    private function redirectPluginWarning(): ?array
    {
        if ($this->extensions === null) {
            return null;
        }
        try {
            $rows = (array) ($this->extensions->coreManifest()['extensions'] ?? []);
        } catch (Throwable $e) {
            return null;
        }
        foreach ($rows as $row) {
            if (($row['type'] ?? '') === 'plugin' && ($row['folder'] ?? '') === 'system' && ($row['element'] ?? '') === 'redirect') {
                if (!empty($row['enabled'])) {
                    return null;
                }
                return ['code' => 'REDIRECT_PLUGIN_DISABLED', 'severity' => 'warning',
                    'message' => 'The redirect is saved, but the System - Redirect plugin (plg_system_redirect) is disabled, so it does nothing yet. '
                        . 'Next step: extension.enable {type: plugin, folder: system, element: redirect, enabled: true}.'];
            }
        }
        return null;
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
        if (($refusal = $this->lockRefusal([[$kind, $id]])) !== null) return $refusal;

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

        // Only a contract slot's picture folder is content-addressed (Tracy ADR 0022).
        if ($this->contract && $this->contract->bound() && strpos($path, 'images/tracy-content/') === 0 && basename($path, '.' . pathinfo($path, PATHINFO_EXTENSION)) !== hash('sha256', $bytes)) return $this->err('bad_params', 'Image filename must match its SHA-256');

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
        // Taking a change back is a write too: not under an open editor, and not half of it.
        if (($refusal = $this->lockRefusal($this->undoRows($entries))) !== null) return $refusal;

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
     * The rows an undo log would write back, as (kind, id): a content write, a move, and the raw
     * alias and visibility changes a language step or a retire records.
     */
    private function undoRows(array $entries): array
    {
        $rows = [];
        foreach ($entries as $entry)
            if (in_array($entry['op'] ?? '', ['content', 'move', 'alias', 'visibility'], true) && is_string($entry['kind'] ?? null))
                $rows[] = [$entry['kind'], (int) ($entry['id'] ?? 0)];
        return $rows;
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
            } elseif ($op === 'siteIdentity') {
                // Which of the two it changed, never the words: a listing is for verifying.
                $step['fields'] = array_keys(self::identityValues($entry['before'] ?? null));
            } elseif ($op === 'siteSettings') {
                // Which files, never their bytes; created when none of them existed before.
                $files = is_array($entry['files'] ?? null) ? $entry['files'] : [];
                $step['created'] = $files !== [] && array_filter($files, static fn($f) => !is_array($f) || ($f['before'] ?? null) !== null) === [];
                $step['files'] = array_map(static fn($f) => is_array($f) ? ($f['path'] ?? null) : null, array_values($files));
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
        if ($op === 'alias') {
            $this->writer->realiasMenuItem((int) ($entry['id'] ?? 0), (string) ($entry['before'] ?? ''));
            return;
        }
        if ($op === 'relabel') {
            $this->writer->relabelLanguage((string) $entry['to'], (string) $entry['from'], $entry['label'] ?? null);
            $defaults = $entry['defaults'] ?? null;
            if (is_array($defaults)) $this->writer->writeLanguageDefaults((string) $defaults['site'], (string) $defaults['administrator']);
            return;
        }
        if ($op === 'visibility') {
            $this->setVisible((string) ($entry['kind'] ?? ''), (int) ($entry['id'] ?? 0), (string) ($entry['column'] ?? ''), (int) ($entry['before'] ?? 1));
            return;
        }
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
        if ($op === 'siteIdentity') {
            if ($this->siteIdentity === null) {
                throw new RuntimeException('site identity store not wired');
            }
            $this->siteIdentity->write(self::identityValues($entry['before'] ?? null));
            return;
        }
        if ($op === 'siteSettings') {
            if ($this->templateSite === null) {
                throw new RuntimeException('template site settings not wired');
            }
            $this->templateSite->restore(is_array($entry['files'] ?? null) ? $entry['files'] : []);
            // The combined CSS may still carry the logo just taken back.
            $this->templateSite->clearOptimize();
            return;
        }
        throw new RuntimeException("unknown step: {$op}");
    }

    /**
     * `site.identity` — Global Configuration's site name (`sitename`) and site description
     * (`MetaDesc`), and nothing else of configuration.php (lib/SiteIdentity.php).
     *
     * - `operation: read` (the default): `{fields: {sitename, MetaDesc}, writable}` — the two values
     *   as the site holds them, and whether a `set` would land now.
     * - `operation: set`, `apply_id`, `fields`: one or both of the two. Each value is a string,
     *   cleaned as Joomla's own form cleans it (tags removed, one line) and refused past its length.
     *   A field already holding that value is not written; when none changes, nothing is written or
     *   recorded (`unchanged: true`), so a retry after a lost reply adds no second undo step. What
     *   changes is recorded under the `apply_id` with its previous value, so `apply.revert` puts the
     *   site's own words back. Answers the two values as now stored and which of them `changed`.
     *
     * Any other key — in `fields` or as the thing to read — is refused with `unsupported`, never
     * quietly ignored: the file also holds the database password and the site secret. A file the web
     * server cannot write is refused the same way, with `code: CONFIG_NOT_WRITABLE`, and nothing is
     * written. Not part of a `content.batch`: a file is not inside the database's transaction.
     */
    private function siteIdentityDoor(array $p): array
    {
        if ($this->siteIdentity === null) {
            return $this->err('unavailable', 'site identity store not wired');
        }
        $operation = $p['operation'] ?? 'read';
        if ($operation === 'read') {
            // Asking for another key is refused, not answered with these two as if it had been read.
            if (array_key_exists('fields', $p)) {
                $asked = $p['fields'];
                if (!is_array($asked) || array_diff(array_map(static fn($name) => is_string($name) ? $name : '', $asked), SiteIdentity::FIELDS) !== []) {
                    return $this->err('unsupported', 'Only sitename and MetaDesc can be read through site.identity');
                }
            }
            try {
                return $this->ok(['fields' => $this->siteIdentity->read(), 'writable' => $this->siteIdentity->writable()]);
            } catch (Throwable $e) {
                return $this->err('read_failed', $e->getMessage());
            }
        }
        if ($operation !== 'set') {
            return $this->err('bad_params', 'Unknown site.identity operation: use read or set');
        }
        if ($this->log === null) {
            return $this->err('unavailable', 'apply log not wired');
        }
        $applyId = $this->applyId($p);
        if ($applyId === null) {
            return $this->err('bad_params', 'apply_id required');
        }
        $fields = $p['fields'] ?? null;
        if (!is_array($fields) || $fields === [] || array_keys($fields) === range(0, count($fields) - 1)) {
            return $this->err('bad_params', 'fields required: an object with sitename, MetaDesc or both');
        }
        $clean = [];
        foreach ($fields as $name => $value) {
            if (!in_array($name, SiteIdentity::FIELDS, true)) {
                return $this->err('unsupported', substr((string) $name, 0, 60) . ' cannot be written through site.identity: only sitename and MetaDesc can. Nothing was written');
            }
            $cleaned = SiteIdentity::clean($name, $value);
            if (isset($cleaned['error'])) {
                return $this->err('bad_params', $cleaned['error']);
            }
            $clean[$name] = $cleaned['value'];
        }
        try {
            $before = $this->siteIdentity->read();
        } catch (Throwable $e) {
            return $this->err('read_failed', $e->getMessage());
        }
        $changes = [];
        foreach ($clean as $name => $value) {
            if ($before[$name] !== $value) $changes[$name] = $value;
        }
        if ($changes === []) {
            return $this->ok(['fields' => $before, 'changed' => [], 'unchanged' => true]);
        }
        $previous = array_intersect_key($before, $changes);
        $t = Timing::begin();
        try {
            $this->siteIdentity->write($changes);
            Timing::end('write', $t);
        } catch (SiteIdentityUnwritable $e) {
            return $this->err('unsupported', $e->getMessage(), ['code' => 'CONFIG_NOT_WRITABLE']);
        } catch (Throwable $e) {
            return $this->err('write_failed', $e->getMessage());
        }
        try {
            $this->log->record($applyId, ['op' => 'siteIdentity', 'before' => $previous]);
        } catch (Throwable $e) {
            try {
                $this->siteIdentity->write($previous);
            } catch (Throwable $undo) {
                // The recorder is down and so is the way back — the failure below says so.
                return $this->err('write_failed', 'the change landed but its undo could not be recorded, and it could not be rolled back: ' . $undo->getMessage());
            }
            return $this->err('write_failed', 'change was rolled back: could not record its undo');
        }
        if ($this->writer !== null) {
            $t = Timing::begin();
            try {
                // A cached page still carries the old <title> and description.
                if (!$this->batching) $this->writer->purgeCache();
            } catch (Throwable $e) {
                // Best-effort by contract.
            }
            Timing::end('purge', $t);
        }
        $this->stamped('content');
        return $this->ok(['fields' => array_merge($before, $changes), 'changed' => array_keys($changes)]);
    }

    /**
     * `template.siteSettings` — a template's logo, name, slogan and favicon (TCH #1013, T18;
     * lib/TemplateSiteSettings.php, lib/TemplateSiteFiles.php).
     *
     * - `operation: read` (the default), `template`: for a T4 template each site profile a style
     *   uses (`profiles: {name: {source, settings}}`, `missing`); for any other `settings` with its
     *   favicon. `framework` says which, `keys` what a set may write.
     * - `operation: set`, `apply_id`, `template`, `fields` and/or `profiles`: on T4, `fields` go into
     *   every profile a style uses and `profiles: {name: {...}}` into one (its values win). Each
     *   profile is copied from where T4 reads it and only those keys change, written to
     *   `templates/<t>/local/etc/site/<profile>.json`. On any other template the one key is
     *   `other_faviconFile`, printed by the system plugin. A picture must already be on the site.
     *   The files' previous state is recorded under the `apply_id` as one step, so `apply.revert`
     *   puts them back — deleting what did not exist, `local/` included. T4's optimize cache is
     *   emptied after the write and after the revert. Nothing changes → nothing is written or
     *   recorded (`unchanged: true`).
     *
     * A key outside the whitelist is refused with `unsupported`, never ignored. Not part of a
     * `content.batch`: a file is not inside the database's transaction.
     */
    private function templateSiteSettingsDoor(array $p): array
    {
        if ($this->templateSite === null) {
            return $this->err('unavailable', 'template site settings not wired');
        }
        $template = $p['template'] ?? null;
        if (!is_string($template) || !preg_match(TemplateSiteSettings::TEMPLATE, $template)) {
            return $this->err('bad_params', 'template required: the folder name of a site template, e.g. ja_spa');
        }
        try {
            $framework = $this->templateSite->framework($template);
        } catch (Throwable $e) {
            return $this->err('read_failed', $e->getMessage());
        }
        if ($framework === null) {
            return $this->err('not_found', 'No site template ' . $template . ' is installed');
        }
        $keys = $framework === 't4' ? TemplateSiteSettings::T4_KEYS : TemplateSiteSettings::OTHER_KEYS;
        $head = ['template' => $template, 'framework' => $framework, 'keys' => $keys];
        $operation = $p['operation'] ?? 'read';
        if ($operation === 'read') {
            try {
                return $this->ok($head + $this->templateSite->read($template, $framework));
            } catch (Throwable $e) {
                return $this->err('read_failed', $e->getMessage());
            }
        }
        if ($operation !== 'set') {
            return $this->err('bad_params', 'Unknown template.siteSettings operation: use read or set');
        }
        if ($this->log === null) {
            return $this->err('unavailable', 'apply log not wired');
        }
        $applyId = $this->applyId($p);
        if ($applyId === null) {
            return $this->err('bad_params', 'apply_id required');
        }
        $isObject = static fn($value): bool => is_array($value) && $value !== [] && array_keys($value) !== range(0, count($value) - 1);
        $fields = $p['fields'] ?? [];
        $byProfile = $p['profiles'] ?? [];
        if (($fields !== [] && !$isObject($fields)) || ($byProfile !== [] && !$isObject($byProfile)) || ($fields === [] && $byProfile === [])) {
            return $this->err('bad_params', 'fields or profiles required: an object of settings, or of profile names to settings');
        }
        if ($byProfile !== [] && $framework !== 't4') {
            return $this->err('unsupported', 'profiles are T4 site profiles; ' . $template . ' is not a T4 template. Nothing was written');
        }
        // Every key is checked before any value: a refusal names the boundary, whatever came first.
        $groups = ['' => $fields];
        foreach ($byProfile as $profile => $values) {
            if (!$isObject($values)) return $this->err('bad_params', 'profiles.' . substr((string) $profile, 0, 60) . ' must be an object of settings');
            $groups[(string) $profile] = $values;
        }
        foreach ($groups as $values) foreach (array_keys($values) as $name) {
            if (!in_array($name, $keys, true)) {
                $what = $framework === 't4' ? '' : ' on a template without T4 (its logo is a template style param: templateStyle)';
                return $this->err('unsupported', substr((string) $name, 0, 60) . ' cannot be written through template.siteSettings' . $what . ': only ' . implode(', ', $keys) . ' can. Nothing was written');
            }
        }
        $clean = [];
        foreach ($groups as $group => $values) foreach ($values as $name => $value) {
            $cleaned = TemplateSiteSettings::clean($name, $value, $this->templateSite->root());
            if (isset($cleaned['error'])) return $this->err('bad_params', $cleaned['error']);
            $clean[$group][$name] = $cleaned['value'];
        }
        $cleanFields = $clean[''] ?? [];
        unset($clean['']);
        try {
            $plan = $this->templateSite->plan($template, $framework, $cleanFields, $clean);
        } catch (Throwable $e) {
            return $this->err('read_failed', $e->getMessage());
        }
        if (isset($plan['error'])) {
            return $this->err($plan['error'], $plan['message']);
        }
        $answer = static function (array $extra) use ($head, $plan): array {
            return $head + $extra + ($plan['skipped'] !== [] ? ['skipped' => $plan['skipped']] : []);
        };
        if ($plan['changes'] === []) {
            return $this->ok($answer(['changed' => [], 'unchanged' => true]));
        }
        $t = Timing::begin();
        try {
            $files = $this->templateSite->write($plan['changes']);
            Timing::end('write', $t);
        } catch (Throwable $e) {
            return $this->err('write_failed', $e->getMessage(), ['code' => 'TEMPLATE_NOT_WRITABLE']);
        }
        try {
            $this->log->record($applyId, ['op' => 'siteSettings', 'template' => $template, 'files' => $files]);
        } catch (Throwable $e) {
            try {
                $this->templateSite->restore($files);
            } catch (Throwable $undo) {
                return $this->err('write_failed', 'the change landed but its undo could not be recorded, and it could not be rolled back: ' . $undo->getMessage());
            }
            return $this->err('write_failed', 'change was rolled back: could not record its undo');
        }
        // The combined CSS may carry the old logo (a dark-mode content:url rule); T4 rebuilds it.
        $cleared = $this->templateSite->clearOptimize();
        if ($this->writer !== null) {
            try {
                if (!$this->batching) $this->writer->purgeCache();
            } catch (Throwable $e) {
                // Best-effort by contract.
            }
        }
        $this->stamped('content');
        try {
            $now = $this->templateSite->read($template, $framework);
        } catch (Throwable $e) {
            $now = [];
        }
        unset($now['missing']);
        return $this->ok($answer(['changed' => array_column($plan['changes'], 'path'), 'cleared' => $cleared] + $now));
    }

    /**
     * The two identity values an undo entry holds, and only those, as strings: a log row is data,
     * and whatever else it might carry never reaches configuration.php.
     *
     * @return array<string,string>
     */
    private static function identityValues($before): array
    {
        $out = [];
        if (!is_array($before)) return $out;
        foreach (SiteIdentity::FIELDS as $field) {
            if (array_key_exists($field, $before) && is_scalar($before[$field])) $out[$field] = (string) $before[$field];
        }
        return $out;
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
