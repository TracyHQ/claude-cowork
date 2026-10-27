<?php
require_once __DIR__ . '/Php8Functions.php';
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
require_once __DIR__ . '/LanguageOverrides.php';
require_once __DIR__ . '/TemplateFiles.php';
require_once __DIR__ . '/LanguageStrings.php';
require_once __DIR__ . '/SiteSettingRows.php';

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
    /**
     * db.dump_many's budgets. The bytes are raw SQL (the answer carries it base64, a third more), in
     * the range one db.dump page of 5000 rows comes to; the seconds stay well under the thirty a
     * shared host gives PHP, leaving room for the one page that runs over.
     */
    private const DUMP_MANY_BYTES = 4194304; // 4 MiB
    private const MIN_DUMP_MANY_BYTES = 1024;
    private const MAX_DUMP_MANY_BYTES = 8388608; // 8 MiB
    private const DUMP_MANY_SECONDS = 10;
    private const MAX_DUMP_MANY_SECONDS = 20;
    private const MAX_DUMP_MANY_TABLES = 5000;
    private const MAX_FILE_LIMIT = 500;
    /** files.list without the sha1: a stat per file and no read, so a page can be ten times longer. */
    private const MAX_FILE_STAT_LIMIT = 5000;
    /** files.hash: the paths one call may ask about, each hashed in full. */
    private const MAX_HASH_PATHS = 500;
    /** files.pack `paths`: the files one pack may be cut to. */
    private const MAX_PACK_PATHS = 2000;
    /** site.fingerprint: the walk paths per page, by default and at most. A count, never a clock. */
    private const FINGERPRINT_FILES = 20000;
    private const MAX_FINGERPRINT_FILES = 50000;
    /** The version of the fingerprint's shape: a digest kept under another version is not comparable. */
    private const FINGERPRINT_VERSION = 2;
    /** site.fingerprint `skip_columns`: how many names, and the one shape a name may have. */
    private const MAX_SKIP_COLUMNS = 50;
    /** db.range_checksums: how many ranges one call may name (2 000 rows a range is 10 M rows). */
    private const MAX_RANGE_STARTS = 5000;
    private const COLUMN_NAME = '/^[A-Za-z_][A-Za-z0-9_]{0,63}$/D';
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

    /** The override files a content batch wrote (each write's undo rows), restored when the batch fails. */
    private array $batchFiles = [];

    /** The T4 profile files a content batch wrote (each write's undo rows), restored when the batch fails. */
    private array $batchSiteFiles = [];

    /** The site's language override files (`template.languageOverrides`); null answers 'unavailable'. */
    private ?LanguageOverrides $overrides = null;

    /** Let `template.languageOverrides` read and write the site's language overrides (lib/LanguageOverrides.php). */
    public function languageOverrides(?LanguageOverrides $overrides): self
    {
        $this->overrides = $overrides;
        return $this;
    }

    /** The files of the site's installed templates (`template.files.*`); null answers 'unavailable'. */
    private ?TemplateFiles $templateFiles = null;

    /** Let `template.files.*` read and write the files of the site's templates (lib/TemplateFiles.php). */
    public function templateFiles(?TemplateFiles $files): self
    {
        $this->templateFiles = $files;
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
            ['content.contract', 'content.batch', 'content.update', 'content.delete', 'media.upload', 'media.delete', 'apply.revert', 'extension.install', 'extension.enable', 'db.restore', 'db.rollback', 'db.cleanup', 'db.purge', 'core.upgrade', 'files.restore', 'template.files.write', 'template.files.undo'], true)
            || (in_array($action, ['site.identity', 'template.siteSettings', 'template.languageOverrides'], true) && ($params['operation'] ?? null) === 'set');
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
            $own = array_intersect(array_column($this->log->entries($params['apply_id'] ?? ''), 'op'), ['visibility', 'relabel', 'languageDefaults', 'alias', 'showcaseNav']);
            if ($own !== []) return $this->err('bad_params', 'This apply_id is a demo trim, a showcase-navigation change, a language step or a source relabel: take it back with its own operation (demoTrim.revert, showcaseNav.revert, multilingual.restore, siteLanguage.revert, sourceLanguage.revert)');
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
                // What the engine itself can do rides beside what the host says, so a caller can tell a
                // site that takes db.dump_many from an older one and fall back to db.dump there.
                // The same for the cheap questions a sync asks first: site.fingerprint, files.list without
                // the sha1, and files.hash. An engine without these entries answers none of them.
                return $this->ok(['info' => $this->info + ['db_dump_many' => [
                    'max_tables' => self::MAX_DUMP_MANY_TABLES,
                    'max_bytes' => self::MAX_DUMP_MANY_BYTES,
                    'max_seconds' => self::MAX_DUMP_MANY_SECONDS,
                ], 'db_range_checksums' => [
                    'version' => 1,
                    'max_starts' => self::MAX_RANGE_STARTS,
                    'max_limit' => self::MAX_DB_LIMIT,
                ], 'site_fingerprint' => [
                    'version' => self::FINGERPRINT_VERSION,
                    'max_files' => self::MAX_FINGERPRINT_FILES,
                ], 'files_list' => [
                    'stat' => true,
                    'max_stat_limit' => self::MAX_FILE_STAT_LIMIT,
                ], 'files_hash' => [
                    'max_paths' => self::MAX_HASH_PATHS,
                ], 'files_pack' => [
                    'max_paths' => self::MAX_PACK_PATHS,
                ]] + ($this->templateFiles === null ? [] : ['template_files' => $this->templateFiles->info()])]);
            case 'site.stats':
                return $this->siteStats();
            case 'site.fingerprint':
                return $this->siteFingerprint($params);
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
            case 'db.range_checksums':
                return $this->dbRangeChecksums($params);
            case 'db.dump_after':
                return $this->dbDumpAfter($params);
            case 'db.dump_many':
                return $this->dbDumpMany($params);
            case 'db.snapshot':
                return $this->dbSnapshot($params);
            case 'db.rollback':
                return $this->dbRollback($params);
            case 'files.list':
                return $this->filesList($params);
            case 'files.hash':
                return $this->filesHash($params);
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
            case 'media.delete':
                return $this->mediaDelete($params);
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
            case 'template.languageOverrides':
                return $this->languageOverridesDoor($params);
            case 'template.files.status':
            case 'template.files.write':
            case 'template.files.undo':
                return $this->templateFilesDoor($action, $params);
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
        // `prefix` is the site's own table prefix, so a caller reading one site out of a database
        // several sites share can pick its tables. The list itself stays whole: db.cleanup,
        // db.restore and db.purge work on `_tracy_trash_` and `_tracy_snap_` names outside any prefix.
        $answer = ['tables' => $tables, 'details' => $details];
        if (isset($this->info['db_prefix']) && is_string($this->info['db_prefix'])) {
            $answer['prefix'] = $this->info['db_prefix'];
        }
        return $this->ok($answer);
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

    /** What db.dump_many measures its time budget with: `fn(): float` seconds. Absent, microtime(true). */
    private $dumpClock = null;

    /** Let db.dump_many read another clock (tests stand one in, to stop on time without waiting). */
    public function dumpClock(callable $now): self
    {
        $this->dumpClock = $now;
        return $this;
    }

    /**
     * The substance checksum of each range of one table's rows by an integer key column
     * ({@see RowSource::rangeChecksums}): `table` (one db.tables lists), `key` (a column), `starts`
     * (the first keys of ranges 1..n, strictly ascending integers, at most MAX_RANGE_STARTS) and
     * `skip_columns` as site.fingerprint takes them. `ranges`: n+1 "<rows>:<xor>". A caller that
     * kept an earlier read's ranges reads again only those that moved ({@see dbDumpAfter}).
     */
    private function dbRangeChecksums(array $p): array
    {
        if ($this->dumper === null) {
            return $this->err('unavailable', 'db dump not wired');
        }
        $table = $p['table'] ?? null;
        $key = $p['key'] ?? null;
        $starts = $p['starts'] ?? [];
        $skip = $p['skip_columns'] ?? [];
        if (!is_string($table) || $table === '') {
            return $this->err('bad_params', 'table required');
        }
        if (!is_string($key) || !preg_match(self::COLUMN_NAME, $key)) {
            return $this->err('bad_params', 'key must be a plain column name');
        }
        if (!is_array($starts) || count($starts) > self::MAX_RANGE_STARTS) {
            return $this->err('bad_params', 'starts must be a list of at most ' . self::MAX_RANGE_STARTS . ' integers');
        }
        $previous = null;
        foreach ($starts as $start) {
            if (!is_int($start) || ($previous !== null && $start <= $previous)) {
                return $this->err('bad_params', 'starts must be strictly ascending integers');
            }
            $previous = $start;
        }
        if (!is_array($skip) || count($skip) > self::MAX_SKIP_COLUMNS) {
            return $this->err('bad_params', 'skip_columns must be a list of at most ' . self::MAX_SKIP_COLUMNS . ' column names');
        }
        foreach ($skip as $column) {
            if (!is_string($column) || !preg_match(self::COLUMN_NAME, $column)) {
                return $this->err('bad_params', 'every skip_columns entry must be a plain column name');
            }
        }
        try {
            if (!in_array($table, $this->dumper->tables(), true)) {
                return $this->err('bad_params', "table {$table} is not one db.tables lists");
            }
            $ranges = $this->dumper->rangeChecksums($table, array_values($skip), $key, array_values($starts));
        } catch (Throwable $e) {
            return $this->err('dump_failed', $e->getMessage());
        }
        if ($ranges === null) {
            return $this->err('dump_failed', "table {$table} could not be read");
        }
        return $this->ok(['table' => $table, 'key' => $key, 'ranges' => $ranges]);
    }

    /**
     * One range of a table's rows read again ({@see DbDumper::rowsAfter}): `table`, `key` (its one
     * integer key column), `after` (an integer, or null for the first row) and `limit` (at most
     * MAX_DB_LIMIT). The INSERTs as `sql_b64`, with `rows` and `last`.
     */
    private function dbDumpAfter(array $p): array
    {
        if ($this->dumper === null) {
            return $this->err('unavailable', 'db dump not wired');
        }
        $table = $p['table'] ?? null;
        $key = $p['key'] ?? null;
        $after = $p['after'] ?? null;
        $limit = (int) ($p['limit'] ?? 1000);
        if (!is_string($table) || $table === '' || !is_string($key) || !preg_match(self::COLUMN_NAME, $key)) {
            return $this->err('bad_params', 'table and key required');
        }
        if ($after !== null && !is_int($after)) {
            return $this->err('bad_params', 'after must be an integer or null');
        }
        $limit = max(1, min($limit, self::MAX_DB_LIMIT));
        try {
            if (!in_array($table, $this->dumper->tables(), true)) {
                return $this->err('bad_params', "table {$table} is not one db.tables lists");
            }
            $chunk = $this->dumper->rowsAfter($table, $key, $after, $limit);
        } catch (InvalidArgumentException $e) {
            return $this->err('bad_params', $e->getMessage());
        } catch (Throwable $e) {
            return $this->err('dump_failed', $e->getMessage());
        }
        return $this->ok(['table' => $table, 'sql_b64' => base64_encode($chunk['sql']), 'rows' => $chunk['rows'], 'last' => $chunk['last']]);
    }

    /**
     * Several tables in one answer, for a site whose firewall counts requests.
     *
     * db.dump answers one page of one table, so a site with hundreds of small tables costs hundreds
     * of requests, and some hosts ban the caller's address for less. This walks an ordered list of
     * tables, one dumpChunkFrom page after another, and stops at whichever comes first: the end of
     * the list, `max_bytes` of SQL, or `max_seconds` on the clock. A table larger than the budget is
     * carried across calls by its row cursor, so nothing here holds state between requests either.
     *
     * The budgets are checked after every row, so the answer ends after the row that crosses one,
     * with a row cursor that resumes at the next. Across calls that is the same rows in the same
     * order, none missed and none twice; each answer is standalone SQL (a page cut short becomes two
     * INSERTs), each segment is exactly db.dump at that cursor with `limit` set to the rows it took,
     * and where no page was cut the SQL is byte for byte the db.dump pages concatenated. At least one
     * row is read per call, so a call always moves: a row larger than `max_bytes` comes back alone.
     *
     * `max_bytes` bounds the answer, not the read. A page of `limit` rows is still fetched whole, so
     * the peak memory is about one page, as db.dump costs for the same `limit`; a cut on a keyed
     * table re-reads the rows it keeps (DbDumper::dumpChunkFrom). A page that fails after others were
     * read ends the answer early with what it has and a cursor at the failing table; the next call
     * starts there and reports the failure itself.
     *
     * `cursor` is `{table, row}`: the index into `tables` and the db.dump cursor inside that table
     * (null to start it). Pass the same `tables` with every call; `next_cursor` is null when done.
     */
    private function dbDumpMany(array $p): array
    {
        if ($this->dumper === null) {
            return $this->err('unavailable', 'db dump not wired');
        }
        $tables = $p['tables'] ?? null;
        if (!is_array($tables) || $tables === []) {
            return $this->err('bad_params', 'tables required: a list of table names');
        }
        if (count($tables) > self::MAX_DUMP_MANY_TABLES) {
            return $this->err('bad_params', 'at most ' . self::MAX_DUMP_MANY_TABLES . ' tables per list');
        }
        $tables = array_values($tables);
        foreach ($tables as $table) {
            if (!is_string($table) || $table === '') {
                return $this->err('bad_params', 'every table must be a non-empty string');
            }
        }
        $limit = (int) ($p['limit'] ?? 1000);
        $limit = max(1, min($limit, self::MAX_DB_LIMIT));
        $maxBytes = (int) ($p['max_bytes'] ?? self::DUMP_MANY_BYTES);
        $maxBytes = max(self::MIN_DUMP_MANY_BYTES, min($maxBytes, self::MAX_DUMP_MANY_BYTES));
        $maxSeconds = (float) ($p['max_seconds'] ?? self::DUMP_MANY_SECONDS);
        $maxSeconds = max(1.0, min($maxSeconds, (float) self::MAX_DUMP_MANY_SECONDS));

        $index = 0;
        $row = null;
        $cursor = $p['cursor'] ?? null;
        if ($cursor !== null) {
            if (!is_array($cursor)) {
                return $this->err('bad_params', 'cursor must be {table, row}');
            }
            $at = $cursor['table'] ?? 0;
            if (is_string($at) && ctype_digit($at)) {
                $at = (int) $at;
            }
            if (!is_int($at) || $at < 0 || $at > count($tables)) {
                return $this->err('bad_params', 'cursor.table must be an index into tables');
            }
            $index = $at;
            $row = $cursor['row'] ?? null;
            if ($row !== null && !is_string($row)) {
                return $this->err('bad_params', 'cursor.row must be a string or null');
            }
            $row = $row === '' ? null : $row;
        }

        $now = $this->dumpClock ?? static function (): float { return microtime(true); };
        $started = $now();
        $count = count($tables);
        $sql = '';
        $rows = 0;
        $pages = 0;
        $tablesDone = 0;
        $progress = [];
        $stopped = 'done';
        while ($index < $count) {
            $table = $tables[$index];
            try {
                // Asked between the rows of a page too, so one page of large rows cannot carry the
                // answer far past either budget: it ends after the row that crosses.
                $chunk = $this->dumper->dumpChunkFrom($table, $row, $limit, function (int $bytes) use ($sql, $maxBytes, $now, $started, $maxSeconds): bool {
                    return strlen($sql) + $bytes < $maxBytes && $now() - $started < $maxSeconds;
                });
            } catch (Throwable $e) {
                if ($pages === 0) {
                    return $this->err('dump_failed', $e->getMessage(), ['table' => $table, 'cursor' => ['table' => $index, 'row' => $row]]);
                }
                $stopped = 'error';
                break;
            }
            $pages++;
            $sql .= $chunk['sql'];
            $rows += $chunk['rows'];
            $last = count($progress) - 1;
            if ($last >= 0 && $progress[$last]['index'] === $index) {
                $progress[$last]['rows'] += $chunk['rows'];
                $progress[$last]['total'] = $chunk['total'];
                $progress[$last]['done'] = $chunk['done'];
            } else {
                $progress[] = ['index' => $index, 'table' => $table, 'rows' => $chunk['rows'], 'total' => $chunk['total'], 'done' => $chunk['done']];
            }
            if ($chunk['done']) {
                $index++;
                $row = null;
                $tablesDone++;
            } else {
                $row = $chunk['next_cursor'];
            }
            if ($index >= $count) {
                break;
            }
            if (strlen($sql) >= $maxBytes) {
                $stopped = 'bytes';
                break;
            }
            if ($now() - $started >= $maxSeconds) {
                $stopped = 'time';
                break;
            }
        }
        $done = $index >= $count;
        return $this->ok([
            'sql_b64'     => base64_encode($sql),
            'next_cursor' => $done ? null : ['table' => $index, 'row' => $row],
            'done'        => $done,
            'stopped'     => $stopped,
            'rows'        => $rows,
            'tables_done' => $tablesDone,
            'bytes'       => strlen($sql),
            'progress'    => $progress,
        ]);
    }

    /**
     * One page of the walk. `hash` (default true) is the sha1 of every file, which reads every byte;
     * `hash: false` answers `{path, size, mtime}` instead — the same walk, order and cursor — at a stat
     * per file, so its page may run to MAX_FILE_STAT_LIMIT paths.
     */
    private function filesList(array $p): array
    {
        if ($this->walker === null) {
            return $this->err('unavailable', 'file walk not wired');
        }
        $hash = $p['hash'] ?? true;
        if (!is_bool($hash)) {
            return $this->err('bad_params', 'hash must be true or false');
        }
        $after = isset($p['after']) && is_string($p['after']) ? $p['after'] : '';
        $limit = (int) ($p['limit'] ?? 100);
        $limit = max(1, min($limit, $hash ? self::MAX_FILE_LIMIT : self::MAX_FILE_STAT_LIMIT));
        try {
            $batch = $hash ? $this->walker->listBatch($after, $limit) : $this->walker->statBatch($after, $limit);
        } catch (Throwable $e) {
            return $this->err('walk_failed', $e->getMessage());
        }
        return $this->ok($batch);
    }

    /**
     * The sha1 of the files named — the expensive question, asked only for the paths a cheap one
     * (files.list without hash, site.fingerprint) said may have changed.
     *
     * `paths` is 1..MAX_HASH_PATHS relative paths, as files.list names them. Each answers its sha1,
     * or null where there is no file the walk lists and file.read would serve ({@see
     * FileWalker::hashPaths}): one missing or refused path does not fail the rest. `hashes` is an
     * object keyed by path, always — a path such as `0` must not turn it into a list.
     */
    private function filesHash(array $p): array
    {
        if ($this->walker === null) {
            return $this->err('unavailable', 'file walk not wired');
        }
        $paths = $p['paths'] ?? null;
        if (!is_array($paths) || $paths === []) {
            return $this->err('bad_params', 'paths required: a list of relative paths');
        }
        if (count($paths) > self::MAX_HASH_PATHS) {
            return $this->err('bad_params', 'at most ' . self::MAX_HASH_PATHS . ' paths per call');
        }
        foreach ($paths as $path) {
            if (!is_string($path) || $path === '') {
                return $this->err('bad_params', 'every path must be a non-empty string');
            }
        }
        try {
            $hashes = $this->walker->hashPaths(array_values($paths));
        } catch (Throwable $e) {
            return $this->err('walk_failed', $e->getMessage());
        }
        return $this->ok(['hashes' => (object) $hashes]);
    }

    /**
     * The cheapest answer to "has this site changed since I last read it?": one digest per page of
     * the file walk and one CHECKSUM TABLE value per table, and no byte of either copied.
     *
     * Files: the walk of files.list, from the first path after `after`, at most `limit` paths
     * (default FINGERPRINT_FILES, at most MAX_FINGERPRINT_FILES) — a fixed count, so the same tree
     * cuts into the same pages every time. `count` and `bytes` are the page's, `digest` is sha1 over
     * `path NUL size NUL mtime LF` per path ({@see FileWalker::fingerprintPage}), and `next_after`
     * the page's last path while more remain, else null. `files: false` leaves the walk out.
     *
     * Tables: CHECKSUM TABLE over the tables db.tables lists, or over `tables` (each of them in that
     * list), in batches; a value is the checksum as a string, null where the server answers NULL.
     * `checksums: false` leaves the database out. The tables are answered in full on every page;
     * a caller paging the files asks for them once, with the first.
     *
     * `skip_columns` (version 2): up to MAX_SKIP_COLUMNS column names. Given and not empty, EVERY
     * table of the call is checksummed by its substance instead ({@see
     * RowSource::substanceChecksum}, "<rows>:<xor>"), those columns left out wherever they occur,
     * so a hit counter or a last-visit date no longer reads as a change. The names used come back
     * sorted as `fingerprint.skip_columns`; without them the answer is CHECKSUM TABLE's, as before.
     */
    private function siteFingerprint(array $p): array
    {
        $started = microtime(true);
        $wantFiles = $p['files'] ?? true;
        $wantSums = $p['checksums'] ?? true;
        if (!is_bool($wantFiles) || !is_bool($wantSums)) {
            return $this->err('bad_params', 'files and checksums must be true or false');
        }
        $after = isset($p['after']) && is_string($p['after']) ? $p['after'] : '';
        $limit = (int) ($p['limit'] ?? self::FINGERPRINT_FILES);
        $limit = max(1, min($limit, self::MAX_FINGERPRINT_FILES));
        $skip = $p['skip_columns'] ?? [];
        if (!is_array($skip) || count($skip) > self::MAX_SKIP_COLUMNS) {
            return $this->err('bad_params', 'skip_columns must be a list of at most ' . self::MAX_SKIP_COLUMNS . ' column names');
        }
        foreach ($skip as $column) {
            if (!is_string($column) || !preg_match(self::COLUMN_NAME, $column)) {
                return $this->err('bad_params', 'every skip_columns entry must be a plain column name: a letter or _, then up to 63 letters, digits or _');
            }
        }
        $skip = array_values(array_unique($skip));
        sort($skip, SORT_STRING);
        $only = $p['tables'] ?? null;
        if ($only !== null) {
            if (!is_array($only) || $only === []) {
                return $this->err('bad_params', 'tables must be a non-empty list of names');
            }
            foreach ($only as $table) {
                if (!is_string($table) || $table === '') {
                    return $this->err('bad_params', 'every table must be a non-empty string');
                }
            }
        }

        $files = null;
        if ($wantFiles) {
            if ($this->walker === null) {
                return $this->err('unavailable', 'file walk not wired');
            }
            try {
                $files = $this->walker->fingerprintPage($after, $limit);
            } catch (Throwable $e) {
                return $this->err('walk_failed', $e->getMessage());
            }
        }

        $tables = null;
        if ($wantSums) {
            if ($this->dumper === null) {
                return $this->err('unavailable', 'db dump not wired');
            }
            try {
                $known = $this->dumper->tables();
            } catch (Throwable $e) {
                return $this->err('dump_failed', $e->getMessage());
            }
            $names = $known;
            if ($only !== null) {
                $names = [];
                foreach ($only as $table) {
                    if (!in_array($table, $known, true)) {
                        return $this->err('bad_params', "table {$table} is not one db.tables lists");
                    }
                    if (!in_array($table, $names, true)) {
                        $names[] = $table;
                    }
                }
            }
            try {
                $tables = (object) ($skip === []
                    ? $this->dumper->checksums($names)
                    : $this->dumper->substanceChecksums($names, $skip));
            } catch (Throwable $e) {
                return $this->err('checksum_failed', $e->getMessage());
            }
        }

        $answer = ['version' => self::FINGERPRINT_VERSION, 'files' => $files, 'tables' => $tables];
        if ($skip !== []) {
            $answer['skip_columns'] = $skip;
        }
        $answer['seconds'] = round(microtime(true) - $started, 3);
        return $this->ok(['fingerprint' => $answer]);
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
     * `paths` (1..MAX_PACK_PATHS relative paths) cuts the pack to those files, in the walk's order;
     * the cursor then moves through them alone. The same files and bytes a whole pack would carry
     * ({@see FileWalker::archivePath}, so `configuration.php` included): a sync that knows which
     * files moved takes the ones file.read will not serve — a protected file, one too large — without
     * the rest of the tree. On a customer's site a changed Global Configuration made the sync read
     * all 578 MB to take one file (22/10/2026).
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
        $only = null;
        if (array_key_exists('paths', $p)) {
            $asked = $p['paths'];
            if (!is_array($asked) || $asked === []) {
                return $this->err('bad_params', 'paths must be a non-empty list of relative paths');
            }
            if (count($asked) > self::MAX_PACK_PATHS) {
                return $this->err('bad_params', 'at most ' . self::MAX_PACK_PATHS . ' paths per pack');
            }
            foreach ($asked as $one) {
                if (!is_string($one) || $one === '') {
                    return $this->err('bad_params', 'every path must be a non-empty string');
                }
            }
        }

        try {
            // Fetched once and walked in memory. The loop used to ask the walker for "the next
            // file" per file, and each of those was a search across the whole list — quadratic.
            // On a real webroot of 19,971 files PHP hit its thirty-second limit during the first
            // part. A fixture of five files never came close.
            if (isset($asked)) {
                $only = $this->walker->listedPaths(array_values($asked));
                $at = $path === '' ? 0 : array_search($path, $only, true);
                if ($at === false) {
                    return $this->err('bad_params', 'the cursor names a path outside paths');
                }
                $queue = array_slice($only, $at);
            } else {
                $queue = $path === ''
                    ? $this->walker->pathsAfter('', self::PACK_LOOKAHEAD)
                    : array_merge([$path], $this->walker->pathsAfter($path, self::PACK_LOOKAHEAD));
            }
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
                if ($only === null && $qi >= count($queue)) {
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
            if (strpos((string)($p['operation'] ?? ''), 'showcaseNav.') === 0) return $this->showcaseNav($p);
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
            // The links to empty and the kept pictures asked for are part of what a request asks; each named only
            // when sent, so an apply without them hashes as it always did and a retry from before them still replays.
            $asked=array_key_exists('empty_links',$p)?[$p['changes']??null,$p['evidence']??[],$p['empty_links']]:[$p['changes']??null,$p['evidence']??[]];
            if(array_key_exists('replace_kept',$p))$asked['replace_kept']=$p['replace_kept'];
            $hash=hash('sha256',json_encode($asked));
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
        // A source WRITTEN in another language traded tags with the archive's hidden edition of it
        // (writtenSourceSet): no language is built on top of that until it is taken back.
        $relabel = ($this->contract->binding() ?? [])['sourceRelabel'] ?? null;
        if (in_array($operation, ['plan', 'package', 'apply'], true) && !empty($relabel['written']))
            return $this->err('conflict', 'This site’s source edition is written in ' . $relabel['to'] . ' (sourceLanguage.set with `written`); take that back with sourceLanguage.revert before adding a language. Nothing has been written.');
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
                $trim = $state['demoTrim'] === null ? null : $state['binding']['demoTrim'];
                $remaining = count($this->demoTrimPending($state, true, $trim));
                $status = ($state['binding']['demoTrim']['status'] ?? null) === 'reverted' ? 'reverted' : ($state['demoTrim'] ?? 'none');
                // 🔒 NEVER COMPLETE WHILE A LANGUAGE STILL SHOWS A DEMO ROW: a translation in a listed row's
                // group published or associated since the trim keeps it `incomplete`, and the next apply takes it.
                if ($status === 'complete' && $remaining > 0) $status = 'incomplete';
                $showing = $trim === null || in_array($status, ['reverted', 'reverting'], true) ? [] : $this->demoTrimShowing($state, $trim);
                $refusal = null;
                if ($status !== 'reverting')
                    try {
                        $this->refuseLanguageJob($state, 'a demo trim', true);
                        foreach ($this->demoTrimPending($state, true, $trim) as $key => $row) $this->languageCopies($state, $key, true, true);
                        // The same check the apply makes before its record lands: no row it would move is open in an editor.
                        $this->refuseLocked($this->demoTrimRows($state, true, $trim));
                    } catch (Throwable $error) { $refusal = $error->getMessage(); }
                return $this->ok([
                    'hides' => $this->contract->demoTrim()->counts(),
                    'status' => $status,
                    'showing' => $showing,
                    'refusal' => $refusal,
                    'remaining' => $remaining,
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
        $checked = $refused = false;
        $trim = null;
        try {
            $binding = $this->contract->binding();
            $trim = $binding['demoTrim'] ?? null;
            // A record written before a revert kept the apply it undid names the revert's own request.
            $revertRequest = $trim['revert']['requestId'] ?? $trim['requestId'] ?? null;
            $reopen = false;
            if ($operation === 'apply') {
                // 🔒 A COMPLETE TRIM IS TAKEN UP AGAIN WHEN A LANGUAGE SHOWS ITS DEMO ROWS SINCE: the next
                // apply hides what the plan names as showing (demoTrimShowing) — a translation, a copy, or
                // the row itself — under its own request, and the record names each translation for the revert.
                if (($trim['status'] ?? null) === 'complete') {
                    if ($this->demoTrimPending($this->contract->inspect(), true, $trim) === [])
                        return $this->ok(['status' => 'completed', 'remaining' => 0, 'alreadyTrimmed' => true, 'applyId' => $trim['applyId']]);
                    $reopen = true;
                }
                // A reverted trim's request is spent: hiding again is a different change, under a new one.
                elseif ($trim !== null && ($trim['status'] === 'reverted' ? $trim['requestId'] === $request : ($trim['status'] !== 'applying' || $trim['requestId'] !== $request)))
                    return $this->err('conflict', 'A demo trim is already ' . $trim['status'] . ' under request ' . $trim['requestId']);
                $record = $reopen ? ['status' => 'applying', 'requestId' => $request, 'reopenedAt' => gmdate('c')] + $trim
                    : ['status' => 'applying', 'applyId' => $apply, 'requestId' => $request,
                    'profileHash' => $this->contract->demoTrim()->hash(), 'profileVersion' => $this->contract->demoTrim()->version(), 'at' => gmdate('c')];
            } else {
                // 🔒 A REVERTED TRIM ANSWERS ITS OWN REVERT AGAIN, AND WRITES NOTHING: a retry after a
                // lost answer is told it is done, not that there is nothing to bring back.
                if (($trim['status'] ?? null) === 'reverted' && $revertRequest === $request)
                    return $this->ok(['status' => 'reverted', 'moved' => 0, 'remaining' => 0, 'alreadyReverted' => true, 'applyId' => $trim['revert']['applyId'] ?? $trim['applyId']]);
                if ($trim === null || $trim['status'] === 'reverted') return $this->err('contract_failed', 'This site has no hidden demo rows to bring back');
                if ($trim['status'] === 'reverting' && $revertRequest !== $request)
                    return $this->err('conflict', 'A demo trim revert is already running under request ' . $revertRequest);
                // The record keeps the apply it undoes: which rows were the trim's, and under which request.
                $record = ['status' => 'reverting', 'revert' => $trim['revert'] ?? ['applyId' => $apply, 'requestId' => $request, 'at' => gmdate('c')]] + $trim;
            }
            // 🔒 THE RECORD LANDS BEFORE ANY ROW MOVES, in its own commit. A batch that dies after
            // Joomla committed some rows must leave a site that says a trim is in flight — otherwise
            // those rows read as drift, and the retry that would finish them is refused with the rest.
            // 🔒 NOT UNDER AN OPEN EDITOR, AND NOT EVEN THE RECORD. The rows this call would move are
            // checked before the first write of the call, the record included: a refused call leaves
            // the site, and a trim in flight, exactly where they were, so the same request resumes.
            $hide = $operation === 'apply';
            if ($trim === null || $reopen || $trim['status'] !== $record['status']) {
                $this->writer->transaction(function () use ($record, $hide, $reopen) {
                    $state = $this->contract->inspect();
                    $this->refuseLanguageJob($state, 'a demo trim', true);
                    // 🔒 THE TRIM OWNS ONLY THE ROWS IT FINDS WHERE THE LOCK HAS THEM. A listed row the
                    // customer already hid is theirs: it is not written, not rebased, and not shown on revert.
                    // Taken up again, it owns the rows it owned: only their translations are looked at.
                    if ($hide && !$reopen) $record['rows'] = array_keys($this->demoTrimPending($state, true, null));
                    // 🔒 THE REVERT OWNS ONLY THE TRIM'S ROWS IT FINDS STILL HIDDEN. One the customer showed
                    // again after the trim is theirs: it is not written, and its baseline stays the hidden
                    // value, so the change is still reported after the revert.
                    else $record['revert']['rows'] = array_keys($this->demoTrimPending($state, false, $record));
                    // 🔒 EVERY COPY A HIDE WILL TAKE IS PROVEN ITS SOURCE'S TRANSLATION BEFORE THE RECORD LANDS.
                    if ($hide) foreach ($this->demoTrimPending($state, true, $record) as $key => $row) $this->languageCopies($state, $key, true, true);
                    $this->refuseLocked($this->demoTrimRows($state, $hide, $record));
                    if (!$state['binding']) $this->contract->bind($state['snapshot']);
                    $this->contract->rebind($this->contract->bindingWithTrim($record));
                    return [];
                });
                $checked = true;
            }
            $this->batching = true;
            $step = $this->writer->transaction(function () use ($hide, $apply, $checked, &$refused) {
                $state = $this->contract->inspect();
                $record = $state['binding']['demoTrim'];
                $this->refuseLanguageJob($state, 'a demo trim', false);
                if (!$checked) $this->refuseLocked($this->demoTrimRows($state, $hide, $record));
                $reopened = $hide && isset($record['reopenedAt']) && ($record['completedAt'] ?? '') !== '';
                $pending = $this->demoTrimPending($state, $hide, $record);
                $batch = array_slice($pending, 0, self::DEMO_TRIM_BATCH, true);
                $copies = [];
                foreach ($batch as $key => $row) $copies[$key] = $this->languageCopies($state, $key, $hide, false);
                $owned = $this->demoTrimOwned($record, $hide);
                $moved = [];
                foreach ($batch as $key => [$kind, $field, $value]) {
                    // 🔒 EVERY TRANSLATION IN THE ROW'S GROUP STILL SHOWING IT GOES WITH IT, by a compare-and-set
                    // in the same batch; the record names each one it hid, and a revert brings back those alone,
                    // where they still stand hidden.
                    foreach ($this->demoTrimMembers($state, $key, $owned[$key], $hide, $record) as $mid) {
                        $from = $hide ? $owned[$key]['from'] : $owned[$key]['to'];
                        if (!$this->writer->setVisibilityIf($kind, $mid, $field, $from, $value)) {
                            $refused = true;
                            throw new RuntimeException('The translation ' . $mid . ' of ' . $key . ' changed while it was being written. Nothing has been written.');
                        }
                        $this->log->record($apply, ['op' => 'visibility', 'kind' => $kind, 'id' => $mid, 'column' => $field, 'before' => $from]);
                        if ($hide && !in_array($mid, $record['members'][$key] ?? [], true)) $record['members'][$key][] = $mid;
                    }
                    // 🔒 A COPY GOES WITH ITS SOURCE, IN THE SAME BATCH: its visibility is derived from the
                    // source's, so the two never stand apart past this transaction.
                    // A revert that finds the source already shown (the customer's) takes back only what
                    // followed it: the source is not written, and its baseline stays the hidden value.
                    $sourceShown = !$hide && (string) ($state['rows'][$key][$field] ?? '') === $value;
                    foreach (['' => $key] + $copies[$key] as $rowKey) {
                        $before = (string) ($state['rows'][$rowKey][$field] ?? '');
                        if (($rowKey !== $key || $sourceShown) && $before === $value) continue;
                        // Taken up again, a trim moves only what shows the row again — what the plan named: a
                        // copy a language bound or restored since brought back where the trim moves it from,
                        // or the row itself. The undo entry under this apply is its receipt.
                        if ($reopened && $before !== $owned[$key]['from']) continue;
                        // One column, raw — never write(): Joomla's Table would mint `#__assets` rows
                        // for demo posts that ship without them, and move the ACL the contract holds.
                        $id = (int) $state['ids'][$rowKey];
                        $this->writer->setVisibility($kind, $id, $field, $value);
                        $this->log->record($apply, ['op' => 'visibility', 'kind' => $kind, 'id' => $id, 'column' => $field, 'before' => $before]);
                    }
                    if (!$sourceShown) $moved[$key] = [$field, $value];
                }
                $remaining = count($pending) - count($batch);
                if ($remaining === 0) {
                    // A finished apply expects every row it owns hidden, derived from the profile and the
                    // record, never read back from the site: a batch Joomla committed behind its own
                    // rollback left rows hidden that no surviving call wrote.
                    // 🔒 A REVERT REBASES ONLY THE ROWS THIS CALL WROTE, in $moved already. A row of the trim
                    // the customer showed between two calls was written by neither: its baseline stays
                    // hidden, and it is still reported.
                    if ($hide) foreach ($this->demoTrimOwned($record) as $key => $row) $moved[$key] = [$row['field'], $row['to']];
                    if ($hide) $record = ['status' => 'complete', 'completedAt' => gmdate('c')] + $record;
                    else $record = ['status' => 'reverted', 'revert' => ['completedAt' => gmdate('c')] + ($record['revert'] ?? [])] + $record;
                }
                // 🔒 THE BASELINE MOVES ONLY WHERE THE TRIM WROTE, to the value it wrote — never a fresh
                // snapshot, which would take a change the customer made into the baseline unseen.
                $this->contract->rebind($this->contract->bindingWithTrim($record, $moved));
                // Proven: the full read checks every row against the baseline just derived.
                $this->contract->inspect();
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
            // 🔒 A REFUSAL MET MID-APPLY TAKES BACK THE RECORD THIS CALL LANDED. The step rolled back whole
            // (raw column writes, no Table::store() to commit behind it), so no row of this call is on the
            // site: the trim is again what it was, and is not left `applying` under a request that would
            // only meet the same refusal. A crash keeps the record — Joomla may have committed rows of it.
            if ($checked && $refused)
                try {
                    $this->writer->transaction(function () use ($trim) {
                        $binding = $this->contract->binding();
                        if ($trim === null) unset($binding['demoTrim']); else $binding['demoTrim'] = $trim;
                        $this->contract->rebind($binding);
                        return [];
                    });
                } catch (Throwable $ignored) {}
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
                // The headings the contract has no slot for, which a site written in `$locale` gives in it with `set`.
                $titles = $this->contract->binding() !== null ? $this->writtenWordsPlan() : [];
                return $this->ok([
                    'locale' => $locale, 'current' => $this->writer->readLanguageDefaults(),
                    'package' => $pack === null ? null : ['tag' => $pack['tag'], 'version' => $pack['version'], 'bytes' => $pack['bytes']],
                    'installed' => $pack === null || $this->languagePackPresent($locale),
                    'onRecord' => $this->contract->binding()['siteLanguage'] ?? null,
                    'titles' => $titles,
                    'dates' => $this->contract->binding() !== null ? $this->languageDatesPlan($locale) : [],
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
                // The headings it gave go back to what they were.
                $titlesBack = is_array($onRecord['titles'] ?? null) ? $onRecord['titles'] : [];
                $textsBack = is_array($onRecord['texts'] ?? null) ? $onRecord['texts'] : [];
                [$idsBack, $textIdsBack] = self::wordsById($binding, $titlesBack, $textsBack);
                $this->refuseLocked(array_map(fn (int $id) => ['module', $id], array_unique(array_merge(array_keys($idsBack), array_keys($textIdsBack)))));
                $this->writer->transaction(function () use ($apply, $previous, $idsBack, $titlesBack, $textIdsBack, $textsBack) {
                    $before = $this->writer->readLanguageDefaults();
                    $this->writer->writeLanguageDefaults((string) $previous['site'], (string) $previous['administrator']);
                    if ($idsBack !== []) $this->writer->writeModuleTitles($idsBack);
                    if ($textIdsBack !== []) $this->writer->writeModuleParams($textIdsBack);
                    $this->log->record($apply, ['op' => 'languageDefaults', 'before' => $before]);
                    // The baseline moves only where the revert wrote — never a fresh snapshot, which would take a
                    // change the customer made into the baseline unseen. Proven by the full read.
                    $this->contract->rebind($this->contract->bindingWithSiteLanguage(null, $titlesBack, $textsBack));
                    $this->contract->inspect();
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
            $titles = $p['titles'] ?? [];
            if (!is_array($titles)) return $this->err('bad_params', 'titles must map a heading key to its words');
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
            // 🔒 THE HEADINGS AND THE MODULE WORDS FOLLOW THE LANGUAGE (TCH #1013, LANG-2): a module that shows its title
            // ("Newsletter", "Tags cloud", "Follow me" on JA Essence) has no slot, nor do the words a module keeps in its
            // params ("Join 70,000 subscribers!", "Sign up"), so a one-edition site written in another language gives them
            // here, as a written source relabel does. One left out keeps its words; the old ones are kept for revert.
            $words = $this->writtenWords($titles, 'siteLanguage.plan');
            if (is_string($words)) return $this->err('bad_params', $words);
            // The dates too, in the language's own order: read from the pack, so after it is installed.
            [$words, $dates] = $this->withLanguageDates($words, $locale);
            ['titlesById' => $byId, 'textsById' => $textsById, 'titlesBefore' => $before, 'titlesAfter' => $after, 'textsBefore' => $textsBefore, 'textsAfter' => $textsAfter] = $words;
            $this->refuseLocked(array_map(fn (int $id) => ['module', $id], array_unique(array_merge(array_keys($byId), array_keys($textsById)))));
            $this->writer->transaction(function () use ($apply, $request, $locale, $pack, $byId, $before, $after, $textsById, $textsBefore, $textsAfter) {
                $defaults = $this->writer->readLanguageDefaults();
                $this->writer->writeLanguageDefaults($locale, $locale);
                if ($byId !== []) $this->writer->writeModuleTitles($byId);
                if ($textsById !== []) $this->writer->writeModuleParams($textsById);
                $this->log->record($apply, ['op' => 'languageDefaults', 'before' => $defaults]);
                $this->contract->rebind($this->contract->bindingWithSiteLanguage([
                    'locale' => $locale, 'previous' => $defaults, 'applyId' => $apply, 'requestId' => $request,
                    'packVersion' => $pack['version'] ?? null, 'at' => gmdate('c'),
                ] + ($before !== [] ? ['titles' => $before] : []) + ($textsBefore !== [] ? ['texts' => $textsBefore] : []), $after, $textsAfter));
                // 🔒 THE BASELINE MOVES ONLY WHERE THE LANGUAGE WROTE — never a fresh snapshot, which would take a
                // change the customer made into the baseline unseen. Proven: the full read checks every row.
                $this->contract->inspect();
                return [];
            });
            try { $this->writer->purgeCache(); } catch (Throwable $ignored) {}
            $this->stamped('content');
            return $this->ok(['status' => 'completed', 'locale' => $locale, 'packVersion' => $pack['version'] ?? null,
                'titles' => count($byId) + array_sum(array_map('count', $textsById)) - $dates, 'dates' => $dates]);
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
            $written = ($p['written'] ?? false) === true;
            if ($operation === 'plan') {
                if ($written) return $this->writtenSourcePlan($locale, $major, $published, $current);
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
                if (!empty($onRecord['written'])) return $this->writtenSourceRevert($apply, $onRecord, $published);
                $this->refuseLocked($this->rowsInLanguage((string) $onRecord['to']));
                $this->relabelSource($apply, (string) $onRecord['to'], $published, null, $onRecord);
                return $this->ok(['status' => 'reverted', 'source' => $published]);
            }
            if ($written) return $this->writtenSourceSet($p, $locale, $major, $apply, $request, $binding, $published, $current);
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

    /** The longest heading a written relabel takes: a module title is a VARCHAR(100). */
    private const WRITTEN_TITLE_MAX = 100;
    /** The longest module words a written language takes (an AcyMailing intro line, a login module's text). */
    private const WRITTEN_TEXT_MAX = 400;

    /**
     * What a site written in another language gives in it: the headings it shows (`<key>.title`) and the words modules
     * keep in their params (`<key>.params.<name>`, QuickstartContract::writtenModuleTexts), with their words and limit.
     */
    private function writtenWordsPlan(): array
    {
        $out = [];
        foreach ($this->contract->writtenTitles() as $key => $row)
            $out[] = ['key' => $key . '.title', 'source' => $row['title'], 'maxCharacters' => self::WRITTEN_TITLE_MAX];
        foreach ($this->contract->writtenModuleTexts() as $key => $row)
            foreach ($row['texts'] as $name => $text)
                $out[] = ['key' => $key . '.params.' . $name, 'source' => $text, 'maxCharacters' => self::WRITTEN_TEXT_MAX];
        return $out;
    }

    /**
     * The module date formats in English order (QuickstartContract::writtenDateFormats) and the format each would get:
     * the language's own (LanguageOverrides::dateFormat), null while its pack is not installed.
     */
    private function languageDatesPlan(string $locale): array
    {
        $format = $this->overrides !== null ? $this->overrides->dateFormat($locale) : null;
        $out = [];
        foreach ($this->contract->writtenDateFormats() as $key => $row)
            foreach ($row['formats'] as $name => $source)
                $out[] = ['key' => $key . '.params.' . $name, 'source' => $source, 'format' => $format];
        return $out;
    }

    /**
     * `$words` (writtenWords) with every module date format in English order set to the language's own, recorded as
     * module words are (`texts`), so a revert gives each its format back. Nothing is added when the language has no
     * format of its own on this site. Answers the words and how many formats were added.
     *
     * @return array{0:array,1:int}
     */
    private function withLanguageDates(array $words, string $locale): array
    {
        $format = $this->overrides !== null ? $this->overrides->dateFormat($locale) : null;
        if ($format === null) return [$words, 0];
        $count = 0;
        foreach ($this->contract->writtenDateFormats() as $key => $row)
            foreach ($row['formats'] as $name => $source) {
                if ($source === $format) continue;
                $words['textsById'][$row['id']][$name] = $format;
                $words['textsBefore'][$key][$name] = $source;
                $words['textsAfter'][$key][$name] = $format;
                $count++;
            }
        return [$words, $count];
    }

    /**
     * The words a `titles` map gives, each checked against what the plan lists: by module id to write, and by base key
     * what they were and become. A string is the refusal to answer with; nothing has been written then.
     *
     * @param mixed $titles
     * @return array{titlesById:array<int,string>,textsById:array<int,array<string,string>>,titlesBefore:array<string,string>,titlesAfter:array<string,string>,textsBefore:array<string,array<string,string>>,textsAfter:array<string,array<string,string>>}|string
     */
    private function writtenWords($titles, string $lister)
    {
        if (!is_array($titles)) return 'titles must map a heading key to its words';
        $headings = $this->contract->writtenTitles();
        $modules = $this->contract->writtenModuleTexts();
        $w = ['titlesById' => [], 'textsById' => [], 'titlesBefore' => [], 'titlesAfter' => [], 'textsBefore' => [], 'textsAfter' => []];
        $plain = fn ($words, int $max) => is_string($words) && trim($words) !== '' && mb_strlen($words) <= $max && !preg_match('/[<>]/', $words);
        foreach ($titles as $slot => $words) {
            $slot = (string) $slot;
            if (preg_match('/^(.+)\.title$/D', $slot, $m) && isset($headings[$m[1]])) {
                if (!$plain($words, self::WRITTEN_TITLE_MAX)) return 'A heading is plain text of 1 to ' . self::WRITTEN_TITLE_MAX . ' characters: ' . $slot;
                $key = $m[1];
                $w['titlesById'][$headings[$key]['id']] = $words; $w['titlesBefore'][$key] = $headings[$key]['title']; $w['titlesAfter'][$key] = $words;
            } elseif (preg_match('/^(.+)\.params\.([A-Za-z0-9_-]+)$/D', $slot, $m) && isset($modules[$m[1]]['texts'][$m[2]])) {
                if (!$plain($words, self::WRITTEN_TEXT_MAX)) return 'Module words are plain text of 1 to ' . self::WRITTEN_TEXT_MAX . ' characters: ' . $slot;
                [, $key, $name] = $m;
                $w['textsById'][$modules[$key]['id']][$name] = $words;
                $w['textsBefore'][$key][$name] = $modules[$key]['texts'][$name];
                $w['textsAfter'][$key][$name] = $words;
            } else return 'Not a heading of this site: ' . $slot . ' (' . $lister . ' lists them)';
        }
        return $w;
    }

    /**
     * Recorded words (base key => title, base key => param => words) by the module ids the binding names: what a revert
     * writes back. A key the binding no longer names is skipped.
     *
     * @return array{0:array<int,string>,1:array<int,array<string,string>>}
     */
    private static function wordsById(?array $binding, array $titles, array $texts): array
    {
        $titlesById = []; $textsById = [];
        foreach ($titles as $key => $title)
            if (isset($binding['ids'][$key])) $titlesById[(int) $binding['ids'][$key]] = (string) $title;
        foreach ($texts as $key => $params)
            if (isset($binding['ids'][$key]) && is_array($params))
                foreach ($params as $name => $words) $textsById[(int) $binding['ids'][$key]][(string) $name] = (string) $words;
        return [$titlesById, $textsById];
    }

    /** Whether `$locale` is a tag of another language than the published source's: what `written` is for. */
    private static function otherLanguage(string $locale, string $published): bool
    {
        return (bool) preg_match('/^[a-z]{2,3}-[A-Z]{2,4}$/D', $locale) && explode('-', $locale)[0] !== explode('-', $published)[0];
    }

    /**
     * `sourceLanguage.plan` with `written`: what a written relabel to `$locale` would install, and the
     * headings it would ask to be given in that language (`<baseKey>.title`, the source's current
     * title, at most WRITTEN_TITLE_MAX characters).
     */
    private function writtenSourcePlan(string $locale, int $major, string $published, string $current): array
    {
        if (!self::otherLanguage($locale, $published))
            return $this->err('bad_params', ($locale ?: 'That') . ' is not another language than ' . $published . '; a variant of it is a plain relabel, without `written`');
        $pack = $this->contract->languagePackage($locale, $major);
        $titles = $this->writtenWordsPlan();
        return $this->ok([
            'published' => $published, 'current' => $current, 'locale' => $locale, 'written' => true,
            'package' => $pack === null ? null : ['tag' => $pack['tag'], 'version' => $pack['version'], 'bytes' => $pack['bytes']],
            'installed' => $pack === null || $this->languagePackPresent($locale),
            'onRecord' => $this->contract->binding()['sourceRelabel'] ?? null,
            'titles' => $titles,
        ]);
    }

    /**
     * `sourceLanguage.set` with `written`: the source edition's words are now WRITTEN in `$locale`, a
     * language other than the archive's (one language asked for, TCH #1013 D10), so it is called by
     * that tag — `<html lang>`, Joomla's own words, the content language — and its headings are given
     * in it (`titles`, keyed as the written plan names them; a heading left out keeps its title).
     *
     * 🔒 THE URL PREFIX STAYS. The source keeps its content-language row, so its `sef` and every URL
     * (`/en/…`) with it: the T4 navigation, the `tb-main-<sef>` menus and the footer menus are keyed
     * by that `sef` in hash-locked template files, and a new prefix would empty them. What changes is
     * the tag the prefix answers with.
     *
     * 🔒 ONLY A SOURCE THAT IS THE SITE'S ONE EDITION. Refused while any language has been derived (a
     * copy translated from the source's earlier words), while the contract governs rows of its own in
     * `$locale` (an edition the archive ships under the contract), and — in the writer — while the
     * site still routes or shows `$locale`. An archive's hidden edition of `$locale` trades tags with
     * the source (SiteWriter::swapLanguage); nothing is merged and nothing is deleted.
     */
    private function writtenSourceSet(array $p, string $locale, int $major, string $apply, string $request, ?array $binding, string $published, string $current): array
    {
        if (!self::otherLanguage($locale, $published))
            return $this->err('bad_params', ($locale ?: 'That') . ' is not another language than ' . $published . '; a variant of it is a plain relabel, without `written`');
        $onRecord = $binding['sourceRelabel'] ?? null;
        if ($onRecord !== null) {
            if (!empty($onRecord['written']) && $onRecord['to'] === $locale)
                return $this->ok(['status' => 'completed', 'source' => $locale, 'written' => true, 'alreadySet' => true]);
            return $this->err('conflict', 'This site’s source edition is already called ' . $current . '; take that back with sourceLanguage.revert before choosing another');
        }
        if (!empty($binding['multilingual']['languages']))
            return $this->err('conflict', 'This site already has a language edition made from its source; the source cannot be renamed under it. Nothing has been written.');
        foreach ($binding['presentation'] ?? [] as $key => $fields)
            if (($fields['language'] ?? null) === $locale)
                return $this->err('conflict', 'The contract governs this site’s own ' . $locale . ' edition (' . $key . '); its source cannot take that tag. Nothing has been written.');
        $words = $this->writtenWords($p['titles'] ?? [], 'sourceLanguage.plan with `written`');
        if (is_string($words)) return $this->err('bad_params', $words);
        ['titlesById' => $byId, 'textsById' => $textsById, 'titlesBefore' => $before, 'titlesAfter' => $after, 'textsBefore' => $textsBefore, 'textsAfter' => $textsAfter] = $words;
        // Before the pack too: an installer is a write, and a relabel refused afterwards would leave it behind for nothing.
        $this->refuseLocked(array_merge($this->rowsInLanguage($current),
            array_map(fn (int $id) => ['module', $id], array_unique(array_merge(array_keys($byId), array_keys($textsById))))));
        $pack = $this->contract->languagePackage($locale, $major);
        if ($pack !== null && !$this->languagePackPresent($locale)) {
            if ($this->extensions === null || !method_exists($this->extensions, 'installVerifiedFromUrl')) return $this->err('unavailable', 'verified installer not installed');
            $result = $this->extensions->installVerifiedFromUrl($pack['url'], $pack['sha256'], (int) $pack['bytes']);
            if (($result['ok'] ?? false) !== true) return $this->err('install_failed', (string) ($result['error'] ?? 'installer refused the package'));
            if (!$this->languagePackPresent($locale)) return $this->err('install_failed', 'The ' . $locale . ' pack installed but Joomla does not list it');
            $this->stamped('extension');
        }
        if ($binding === null) $this->writer->transaction(function () {
            $this->contract->bind($this->contract->inspect()['snapshot']);
            return [];
        });
        // The dates in the language's own order, read from the pack now that it is there.
        [$words, $dates] = $this->withLanguageDates($words, $locale);
        $this->refuseLocked(array_map(fn (int $id) => ['module', $id], array_keys($words['textsById'])));
        ['textsById' => $textsById, 'textsBefore' => $textsBefore, 'textsAfter' => $textsAfter] = $words;
        $swapped = $this->writtenRelabel($apply, $current, $locale, null, $byId, $after, [
            'from' => $current, 'to' => $locale, 'written' => true, 'titles' => $before, 'applyId' => $apply, 'requestId' => $request,
            'packVersion' => $pack['version'] ?? null, 'at' => gmdate('c'),
        ] + ($textsBefore !== [] ? ['texts' => $textsBefore] : []), $textsById, $textsAfter);
        return $this->ok(['status' => 'completed', 'source' => $locale, 'written' => true, 'swapped' => $swapped,
            'titles' => count($byId) + array_sum(array_map('count', $textsById)) - $dates, 'dates' => $dates, 'packVersion' => $pack['version'] ?? null]);
    }

    /** A written relabel taken back: the tags swapped back, the headings and the defaults as they were. */
    private function writtenSourceRevert(string $apply, array $onRecord, string $published): array
    {
        $binding = $this->contract->binding() ?? [];
        $textsBack = is_array($onRecord['texts'] ?? null) ? $onRecord['texts'] : [];
        [$byId, $textsById] = self::wordsById($binding, $onRecord['titles'] ?? [], $textsBack);
        $this->refuseLocked(array_merge($this->rowsInLanguage((string) $onRecord['to']),
            array_map(fn (int $id) => ['module', $id], array_unique(array_merge(array_keys($byId), array_keys($textsById))))));
        $this->writtenRelabel($apply, (string) $onRecord['to'], $published, $onRecord['label'] ?? null, $byId, $onRecord['titles'] ?? [], null, $textsById, $textsBack);
        return $this->ok(['status' => 'reverted', 'source' => $published]);
    }

    /** One written relabel or its undo, logged, recorded and proven in a single transaction; $record null takes it back. */
    private function writtenRelabel(string $apply, string $from, string $to, ?array $label, array $titlesById, array $titlesByKey, ?array $record,
        array $textsById = [], array $textsByKey = []): bool
    {
        $swapped = $this->writer->transaction(function () use ($apply, $from, $to, $label, $titlesById, $titlesByKey, $record, $textsById, $textsByKey) {
            $defaults = $this->writer->readLanguageDefaults();
            $moved = $this->writer->swapLanguage($from, $to, $label);
            if ($titlesById !== []) $this->writer->writeModuleTitles($titlesById);
            if ($textsById !== []) $this->writer->writeModuleParams($textsById);
            $this->writer->writeLanguageDefaults(
                $defaults['site'] === $from ? $to : $defaults['site'],
                $defaults['administrator'] === $from ? $to : $defaults['administrator']
            );
            if ($record !== null) $record += ['label' => $moved['previous'], 'swapped' => $moved['swapped']];
            $this->log->record($apply, ['op' => 'relabel', 'from' => $from, 'to' => $to, 'written' => true, 'swapped' => $moved['swapped'],
                'label' => $moved['previous'], 'titles' => $titlesByKey, 'texts' => $textsByKey, 'defaults' => $defaults]);
            $this->contract->rebind($this->contract->bindingWithSourceRelabel($record, $titlesByKey, $textsByKey));
            // Proven, then stored — the same two steps every other sealed write ends with.
            $this->contract->rebind($this->contract->inspect()['snapshot']);
            return ['swapped' => $moved['swapped']];
        });
        try { $this->writer->purgeCache(); } catch (Throwable $ignored) {}
        $this->stamped('content');
        return (bool) ($swapped['swapped'] ?? false);
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
    private function demoTrimRows(array $state, bool $hide, ?array $record): array
    {
        $rows = [];
        $owned = $this->demoTrimOwned($record, $hide);
        foreach (array_slice($this->demoTrimPending($state, $hide, $record), 0, self::DEMO_TRIM_BATCH, true) as $key => [$kind]) {
            $rows[] = [$kind, (int) $state['ids'][$key]];
            foreach ($this->languageCopies($state, $key, false, false) as $copy) $rows[] = [$kind, (int) $state['ids'][$copy]];
            foreach ($this->demoTrimMembers($state, $key, $owned[$key], $hide, $record) as $mid) $rows[] = [$kind, $mid];
        }
        return $rows;
    }

    /**
     * The trim's rows still at the end this direction moves them from, with the value each should get.
     * With no trim on record, the listed rows a new trim would own: those still where the lock has them.
     * A record from before rows were named owns every listed row, and moves each not yet at its target.
     *
     * @return array<string,array{0:string,1:string,2:string}> key => [kind, field, value]
     */
    private function demoTrimPending(array $state, bool $hide, ?array $record): array
    {
        $out = [];
        $named = $record === null || isset($record['rows']);
        foreach ($this->demoTrimOwned($record, $hide) as $key => $row) {
            $want = $hide ? $row['to'] : $row['from'];
            // A row is pending while it, or a copy or translation of it in another language, still stands where it moves from.
            foreach (array_merge([$key], array_values($this->languageCopies($state, $key, false, false))) as $rowKey) {
                $actual = (string) ($state['rows'][$rowKey][$row['field']] ?? '');
                if ($named ? $actual === ($hide ? $row['from'] : $row['to']) : $actual !== $want) { $out[$key] = [$row['kind'], $row['field'], $want]; break; }
            }
            if (!isset($out[$key]) && $this->demoTrimMembers($state, $key, $row, $hide, $record) !== []) $out[$key] = [$row['kind'], $row['field'], $want];
        }
        return $out;
    }

    /**
     * What a trim on record still leaves showing, as a hide reads it: the listed row, a copy of it, or a
     * translation in its group, each still where the trim moves it from.
     *
     * @return array<string,array<string,int>> listed key => language => row id
     */
    private function demoTrimShowing(array $state, array $record): array
    {
        $out = [];
        foreach ($this->demoTrimOwned($record) as $key => $row) {
            foreach (array_merge([$key], array_values($this->languageCopies($state, $key, false, false))) as $rowKey)
                if ((string) ($state['rows'][$rowKey][$row['field']] ?? '') === $row['from'] && isset($state['ids'][$rowKey]))
                    $out[$key][(string) ($state['rows'][$rowKey]['language'] ?? '')] = (int) $state['ids'][$rowKey];
            $members = $this->languageMembers($state, $key);
            foreach ($this->demoTrimMembers($state, $key, $row, true, $record) as $mid) $out[$key][$members[$mid]['language']] = $mid;
        }
        return $out;
    }

    /**
     * The translations of a listed row a demo-trim step writes, by id: on a hide, the members of its
     * association group still standing where the row moves from (an unpublished edition shows nothing,
     * and is not written); on a revert, those the trim hid that still stand hidden.
     *
     * @return list<int>
     */
    private function demoTrimMembers(array $state, string $key, array $row, bool $hide, ?array $record): array
    {
        $out = [];
        if ($hide) {
            foreach ($this->languageMembers($state, $key) as $mid => $member)
                if ((string) ($member['row'][$row['field']] ?? '') === $row['from']) $out[] = $mid;
            return $out;
        }
        foreach ($record['members'][$key] ?? [] as $mid)
            if ((string) (($this->writer->read($row['kind'], (int) $mid) ?? [])[$row['field']] ?? '') === $row['to']) $out[] = (int) $mid;
        return $out;
    }

    /**
     * The listed rows a trim record owns: those it names, or every listed row for a record that names none.
     * A revert owns those of them it named when it began; one begun before reverts named rows owns them all.
     */
    private function demoTrimOwned(?array $record, bool $hide = true): array
    {
        $rows = $this->contract->demoTrim()->rows();
        if (isset($record['rows'])) $rows = array_intersect_key($rows, array_flip($record['rows']));
        if (!$hide && isset($record['revert']['rows'])) $rows = array_intersect_key($rows, array_flip($record['revert']['rows']));
        return $rows;
    }

    /** Rows moved per committed call of showcaseNav.apply and .revert. */
    private const SHOWCASE_NAV_BATCH = 100;
    /** Why a row was left alone when its compare-and-set found it changed between the read and the write. */
    private const SHOWCASE_NAV_RACED = 'it changed while it was being written';

    /**
     * Taking a quickstart's TEMPLATE SHOWCASE out of a bound site's menus, and putting it back.
     *
     * `status` reads, `apply` hides one committed batch per call until nothing is left, `revert` puts
     * back exactly what its apply hid. What may be hidden is decided in review, in the contract's
     * `showcase-nav-map.json`; a request names no rows. A menu item only loses "Display in Menu"
     * (`menu_show` "0"): the page stays published, its URL keeps working, and no article, category or
     * ACL is touched. A module listed there is unpublished on its visibility column, as a demo trim does.
     *
     * 🔒 EVERY WRITE IS A COMPARE-AND-SET, AND ITS RECEIPT IS THE REVERT. A row is written only from the
     * value the map starts from; one the customer already moved is left alone and reported. The value
     * found, and whether the key existed at all, is kept in the site's own baseline in the same commit
     * as the write, so a revert puts back exactly that — or, when the customer changed the row since,
     * leaves it and says so. The map is not read again to revert.
     */
    private function showcaseNav(array $p): array
    {
        $operation = substr((string) $p['operation'], strlen('showcaseNav.'));
        if (!in_array($operation, ['status', 'apply', 'revert'], true)) return $this->err('bad_params', 'Unknown showcaseNav operation');
        try {
            $binding = $this->contract->binding();
            $nav = $this->contract->showcaseNav();
        } catch (Throwable $error) { return $this->contractFailed($error); }
        $record = $binding['showcaseNav'] ?? null;
        // A finished revert leaves only what it could not put back (the receipts of its conflicts) and
        // the answer of each revert request: no row is expected moved, the same revert retried after a
        // lost answer gets its answer again, and a new revert request looks at the conflicts once more.
        $reverted = ($record['status'] ?? null) === 'reverted' ? $record : null;
        if ($reverted !== null) $record = null;
        $usable = $nav !== null && $nav->covered();
        $unsupported = function () use ($nav) {
            if ($nav === null && $this->contract->showcaseNavProblem() !== null)
                return $this->err('unsupported', 'This quickstart contract ships a showcase-navigation map this receiver refuses (' . $this->contract->showcaseNavProblem() . '). Nothing has been written.');
            if ($nav === null)
                return $this->err('unsupported', 'This quickstart contract carries no showcase-navigation map; its showcase menu entries cannot be hidden by this receiver');
            return $this->err('unsupported', 'The showcase-navigation map does not cover this design' . ($nav->reason() !== '' ? ' (' . $nav->reason() . ')' : '') . '; nothing can be hidden by it');
        };
        if ($operation === 'status') {
            if (!$usable && $record === null) return $unsupported();
            try {
                $state = $this->contract->inspect();
                $receipts = $state['binding']['showcaseNav']['receipts'] ?? [];
                // 🔒 NEVER COMPLETE WHILE A LANGUAGE STILL SHOWS AN ENTRY THIS JOB HID: a translation in its
                // group published or associated since the apply is named here, and the job is `incomplete`.
                $showing = [];
                if ($record !== null && $usable)
                    foreach ($nav->operations() as $key => $op)
                        if ($op['kind'] === 'menuItem' && in_array($receipts[$key]['outcome'] ?? null, ['written', 'already'], true))
                            foreach ($this->showcaseNavShowing($state, $key, $receipts[$key]) as $language => $mid) $showing[$key][$language] = $mid;
                $current = $reverted !== null ? 'reverted' : ($record['status'] ?? 'none');
                if ($current === 'complete' && $showing !== []) $current = 'incomplete';
                // What the next apply would be refused for, read the way the apply reads it.
                $refusal = null;
                if ($usable && ($record['status'] ?? 'applying') !== 'reverting')
                    try {
                        $this->refuseLanguageJob($state, 'showcase navigation', true);
                        $this->showcaseNavCheck($state, $this->showcaseNavPending($state, $record, true), true);
                    } catch (Throwable $error) { $refusal = $error->getMessage(); }
                return $this->ok([
                    // A finished revert is a finished job: `reverted`, nothing remaining. What an apply
                    // would look at is `owners`, in every state.
                    'status' => $current,
                    'showing' => $showing,
                    'refusal' => $refusal,
                    'hides' => $usable ? $nav->counts() : [],
                    'owners' => $usable ? count($nav->operations()) : 0,
                    'remaining' => $reverted !== null ? 0 : count($this->showcaseNavPending($state, $record, ($record['status'] ?? 'applying') !== 'reverting')),
                    'skipped' => $this->showcaseNavSkipped($receipts),
                    // Rows a finished revert left as the customer set them; a new revert request looks again.
                    'conflicts' => $reverted !== null ? $this->showcaseNavSkipped($reverted['receipts'] ?? [], true) : [],
                    'applyId' => $record['applyId'] ?? $reverted['applyId'] ?? null,
                    'profileVersion' => $usable ? $nav->version() : null, 'profileHash' => $usable ? $nav->hash() : null,
                    // A map published after this site's apply lists rows its receipts do not; a revert then an apply takes it.
                    'profileChanged' => $record !== null && (!$usable || !hash_equals((string) $record['profileHash'], $nav->hash())),
                ]);
            } catch (Throwable $error) { return $this->contractFailed($error); }
        }
        $apply = $this->applyId($p);
        $request = $p['request_id'] ?? '';
        if (!$apply || strpos($apply, 'snav-') !== 0) return $this->err('bad_params', 'apply_id must start with "snav-"');
        if (!is_string($request) || !preg_match('/^[a-zA-Z0-9._:-]{1,100}$/D', $request))
            return $this->err('bad_params', 'request_id required: any string, the same on a retry and new for a different change');
        $hide = $operation === 'apply';
        if ($hide && !$usable) return $unsupported();
        try {
            if ($hide) {
                if ($reverted !== null && $reverted['requestId'] === $request)
                    return $this->err('conflict', 'Request ' . $request . ' applied showcase navigation that has since been reverted; a new apply needs a new request_id');
                // 🔒 A COMPLETE APPLY IS TAKEN UP AGAIN WHEN A LANGUAGE SHOWS ITS ENTRIES SINCE: a translation
                // published or associated after it is hidden by the next apply, under that apply's request,
                // and its receipt joins the record a revert reads.
                $reopen = ($record['status'] ?? null) === 'complete' && hash_equals((string) $record['profileHash'], $nav->hash())
                    && $this->showcaseNavPending($this->contract->inspect(), $record, true) !== [];
                if (($record['status'] ?? null) === 'complete' && !$reopen)
                    return $this->ok(['status' => 'completed', 'remaining' => 0, 'alreadyApplied' => true, 'applyId' => $record['applyId'],
                        'skipped' => $this->showcaseNavSkipped($record['receipts']),
                        'profileChanged' => !hash_equals((string) $record['profileHash'], $nav->hash())]);
                if ($reopen) $record = ['status' => 'applying', 'requestId' => $request, 'reopenedAt' => gmdate('c')] + $record;
                elseif ($record !== null && ($record['status'] !== 'applying' || $record['requestId'] !== $request))
                    return $this->err('conflict', 'Showcase navigation is already ' . $record['status'] . ' under request ' . ($record['status'] === 'reverting' ? $record['revert']['requestId'] : $record['requestId']));
                if ($record !== null && !hash_equals((string) $record['profileHash'], $nav->hash()))
                    return $this->err('conflict', 'The showcase-navigation map changed while it was being applied; revert what was applied, then apply again');
                $next = $record ?? ['status' => 'applying', 'applyId' => $apply, 'requestId' => $request,
                    'profileHash' => $nav->hash(), 'profileVersion' => $nav->version(), 'at' => gmdate('c'), 'receipts' => []];
            } else {
                if ($record === null && isset($reverted['answers'][$request]))
                    return $this->ok(['status' => 'reverted', 'moved' => 0, 'remaining' => 0, 'alreadyReverted' => true] + $reverted['answers'][$request]);
                // 🔒 A CONFLICT IS LOOKED AT AGAIN, NEVER OVERWRITTEN. A new request takes the rows the last
                // revert left — and only those — through the same per-row rules: put back by hand, put back
                // by the same compare-and-set, or still the customer's, untouched.
                if ($record === null && !empty($reverted['receipts'])) {
                    $receipts = [];
                    foreach ($reverted['receipts'] as $key => $receipt) { unset($receipt['reverted'], $receipt['revertWhy'], $receipt['memberConflicts']); $receipts[$key] = $receipt; }
                    $record = ['status' => 'reverting', 'applyId' => $reverted['applyId'], 'requestId' => $reverted['requestId'],
                        'profileHash' => $reverted['profileHash'] ?? null, 'revert' => ['applyId' => $apply, 'requestId' => $request, 'at' => gmdate('c')],
                        'receipts' => $receipts, 'answers' => $reverted['answers'] ?? []];
                    $next = $record;
                    $record = null;
                } elseif ($record === null) return $this->err('contract_failed', 'This site has no showcase menu entries to bring back');
                if ($record !== null && $record['status'] === 'reverting' && $record['revert']['requestId'] !== $request)
                    return $this->err('conflict', 'A showcase-navigation revert is already running under request ' . $record['revert']['requestId']);
                if ($record !== null) $next = ['status' => 'reverting', 'revert' => $record['revert'] ?? ['applyId' => $apply, 'requestId' => $request, 'at' => gmdate('c')]] + $record;
            }
            // 🔒 THE RECORD LANDS BEFORE ANY ROW MOVES, in its own commit, and not under an open editor:
            // the rows this call would move are checked first, so a refused call leaves the site — and
            // a phase in flight — exactly where they were, and the same request resumes it.
            $checked = false;
            if ($record === null || !empty($reopen) || $record['status'] !== $next['status']) {
                $this->writer->transaction(function () use ($next, $hide) {
                    $state = $this->contract->inspect();
                    $this->refuseLanguageJob($state, 'showcase navigation', true);
                    // 🔒 EVERY COPY AND TRANSLATION AN APPLY WILL TAKE IS PROVEN ITS SOURCE'S, AND ABLE TO
                    // FOLLOW IT, BEFORE THE RECORD LANDS.
                    if ($hide) $this->showcaseNavCheck($state, $this->showcaseNavPending($state, $next, true), true);
                    $this->refuseLocked($this->showcaseNavRows($state, $next, $hide));
                    if (!$state['binding']) $this->contract->bind($state['snapshot']);
                    $this->contract->rebind($this->contract->bindingWithShowcaseNav($next));
                    return [];
                });
                $checked = true;
            }
            $this->batching = true;
            $step = $this->writer->transaction(function () use ($hide, $checked) {
                $state = $this->contract->inspect();
                $this->refuseLanguageJob($state, 'showcase navigation', false);
                $record = $state['binding']['showcaseNav'];
                if (!$checked) $this->refuseLocked($this->showcaseNavRows($state, $record, $hide));
                $pending = $this->showcaseNavPending($state, $record, $hide);
                $batch = array_slice($pending, 0, self::SHOWCASE_NAV_BATCH, true);
                if ($hide) $this->showcaseNavCheck($state, $batch, false);
                $moved = [];
                $done = 0;
                $followed = 0;
                $log = $hide ? $record['applyId'] : $record['revert']['applyId'];
                foreach ($batch as $key => $row) {
                    // 🔒 A STYLE WAITS FOR THE HIDES OF ITS ITEM'S CHILDREN: their receipts decide whether its sub goes.
                    if ($hide && $row['kind'] === 'templateStyle' && !$this->showcaseNavChildrenDone($record, $row)) break;
                    $done++;
                    if ($hide && !empty($row['refollow'])) $receipt = ['receipt' => $row['receipt'], 'moved' => null];
                    elseif (!$hide && $row['receipt']['outcome'] === 'already') $receipt = ['receipt' => ['reverted' => 'restored', 'by' => 'left'], 'moved' => null];
                    else $receipt = !$hide ? $this->showcaseNavRestore($state, $key, $row)
                        : ($row['kind'] === 'templateStyle' ? $this->showcaseNavStyle($state, $key, $row, $record) : $this->showcaseNavHide($state, $key, $row));
                    $receipt = $this->showcaseNavFollow($state, $key, $row, $receipt, $hide);
                    $followed += $receipt['followed'];
                    if ($hide) $record['receipts'][$key] = $receipt['receipt'];
                    else $record['receipts'][$key] = $receipt['receipt'] + $record['receipts'][$key];
                    if ($receipt['moved'] !== null) $moved[] = $receipt['moved'];
                    $this->log->record($log, ['op' => 'showcaseNav', 'key' => $key, 'direction' => $hide ? 'hide' : 'restore'] + $receipt['receipt']);
                }
                $remaining = count($pending) - $done;
                $skipped = $this->showcaseNavSkipped($record['receipts'], !$hide);
                if ($remaining === 0 && $hide) { $record['status'] = 'complete'; $record['completedAt'] = gmdate('c'); }
                // A finished revert keeps the receipts of its conflicts only — the rows a later revert may
                // still put back — and what each revert request is answered when it is retried.
                if ($remaining === 0 && !$hide)
                    $record = ['status' => 'reverted', 'applyId' => $record['applyId'], 'requestId' => $record['requestId'],
                        'profileHash' => $record['profileHash'] ?? null, 'revert' => $record['revert'] + ['completedAt' => gmdate('c')],
                        'receipts' => array_intersect_key($record['receipts'], $skipped),
                        'answers' => ($record['answers'] ?? []) + [$record['revert']['requestId'] => ['applyId' => $record['revert']['applyId'], 'conflicts' => $skipped]]];
                // 🔒 ONLY THE ROWS THIS BATCH WROTE MOVE IN THE BASELINE. The observed snapshot is never
                // stored: it would adopt whatever else already differs on the site, and inspect would
                // stop reporting it. The full read still runs, and refuses any difference this batch
                // made that the baseline does not now expect.
                $this->contract->rebind($this->contract->bindingWithShowcaseNav($record, $moved));
                $this->contract->inspect();
                // A translation outside the contract moves no baseline; the answer counts it as moved.
                return ['remaining' => $remaining, 'moved' => count($moved) + $followed, 'skipped' => $skipped];
            });
            $this->batching = false;
            try { $this->writer->purgeCache(); } catch (Throwable $ignored) {}
            $this->stamped('content');
            $finished = $step['remaining'] === 0;
            return $this->ok([
                'status' => $finished ? ($hide ? 'completed' : 'reverted') : 'running',
                'moved' => $step['moved'], 'remaining' => $step['remaining'], 'applyId' => $apply,
                ($hide ? 'skipped' : 'conflicts') => $step['skipped'],
            ]);
        } catch (Throwable $error) {
            $this->batching = false;
            return $this->contractFailed($error);
        }
    }

    /** A language job half-done is refused: its copies are still being made, or taken back. */
    private function refuseLanguageJob(array $state, string $what, bool $first): void
    {
        if ($state['job'] !== null && ($state['job']['phase'] ?? '') !== 'completed')
            throw new RuntimeException('A language job is in flight on this site; ' . $what . ' waits until it is finished or reverted. '
                . ($first ? 'Nothing has been written.' : 'Nothing more has been written.'));
    }

    /**
     * The copies of one source row in the site's other languages: derived key => derived key.
     *
     * 🔒 A COPY IS FOLLOWED ONLY WHEN ITS SOURCE'S TRANSLATION GROUP HOLDS IT. With `$strict`, a translated
     * row with no copy in a bound language, or a copy Joomla's association group of its source does not
     * name, refuses the whole call: hiding the source alone would leave that language showing the entry,
     * and hiding a row nothing links to the source would be a guess. The group's other translations,
     * which no language job of this receiver made, are languageMembers().
     *
     * @return array<string,string>
     */
    private function languageCopies(array $state, string $key, bool $strict, bool $first): array
    {
        $copies = [];
        foreach ($state['keys'] ?? [] as $other => $meta)
            if (($meta['base'] ?? null) === $key && isset($meta['locale'], $state['ids'][$other])) $copies[$meta['locale']] = $other;
        if (!$strict || ($state['languages'] ?? []) === []) return array_combine(array_values($copies), array_values($copies)) ?: [];
        $profile = $this->contract->profile();
        $kind = (string) ($state['keys'][$key]['kind'] ?? '');
        $refuse = static fn (string $why) => new RuntimeException($why . ' ' . ($first ? 'Nothing has been written.' : 'Nothing more has been written.'));
        // The groups a language job writes (MultilingualApply::phaseRelations); a module has none.
        $relation = ['menuItem' => 'menuAssociation', 'article' => 'articleAssociation'][$kind] ?? null;
        $members = [];
        if ($relation !== null)
            foreach (json_decode((string) (($this->writer->read($relation, (int) $state['ids'][$key]) ?? [])['members'] ?? '[]'), true) ?: [] as $member)
                $members[(int) ($member['id'] ?? 0)] = true;
        foreach ($state['languages'] as $locale) {
            if (!isset($copies[$locale])) {
                if ($profile->isTranslated($key))
                    throw $refuse('Row ' . $key . ' has no ' . $locale . ' copy to follow it, so its association group cannot be resolved.');
                continue;
            }
            $id = (int) $state['ids'][$copies[$locale]];
            if ($relation !== null && !isset($members[$id]))
                throw $refuse('The ' . $locale . ' copy of ' . $key . ' (id ' . $id . ') is not in the association group of its source (id ' . (int) $state['ids'][$key] . '); which row translates it cannot be resolved.');
        }
        return array_combine(array_values($copies), array_values($copies)) ?: [];
    }

    /** The association group each translatable kind is linked through (MultilingualApply::phaseRelations); a module has none. */
    private const ASSOCIATIONS = ['menuItem' => 'menuAssociation', 'article' => 'articleAssociation'];

    /**
     * Every row id that is the listed row `$key` in some language, by its groups: the source's association
     * group, and the group of each row the archive's reviewed `editions.json` names for `$key` — that row
     * itself included, in a group of its own or in none (see languageMembers()). The strict check on a
     * bound copy stays on the source's group alone: a language job links each copy it makes there.
     *
     * @return array<int,true>
     */
    private function entryGroup(string $key, string $kind, string $relation, int $id): array
    {
        $out = [];
        $read = function (int $at) use ($relation, &$out): void {
            foreach (json_decode((string) (($this->writer->read($relation, $at) ?? [])['members'] ?? '[]'), true) ?: [] as $member)
                if ((int) ($member['id'] ?? 0) > 0) $out[(int) $member['id']] = true;
        };
        $read($id);
        $out[$id] = true;
        $profile = $this->contract->multilingualAvailable() ? $this->contract->profile() : null;
        foreach ($profile ? $profile->editionLocales() : [] as $locale) {
            $eid = (int) ($profile->edition($locale)['ids'][$key] ?? 0);
            if ($eid < 1 || isset($out[$eid])) continue;
            // An id is only the archive's row while it still holds that edition's language; a site whose
            // ids moved is not followed by id.
            if ((string) (($this->writer->read($kind, $eid) ?? [])['language'] ?? '') !== $locale) continue;
            $out[$eid] = true;
            $read($eid);
        }
        return $out;
    }

    /**
     * The translations of one listed row that no language job of this receiver made: the other members
     * of its Joomla association group, each in a language of its own — a shipped edition, or a language
     * the customer added in Joomla and associated — that are no row the contract governs (a bound copy,
     * or another language's source row, which has its own entry in the profile).
     *
     * 🔒 THE ASSOCIATION GROUP IS THE LINK, AND THE ARCHIVE'S EDITIONS NAME THEIR OWN. A row in another
     * language that no group of the entry holds is not followed: nothing ties it to the source, and hiding
     * it would be a guess. The entry's groups are its source's, and the group of each row the reviewed
     * `editions.json` names for its key: Business 1.2.6 ships {en-GB, ru-RU} and its 41 editions as two
     * groups (measured on a fresh stand, 25/10/2026), which a language job only merges once it runs.
     *
     * @return array<int,array{language:string,row:array}> member id => its language and its live row
     */
    private function languageMembers(array $state, string $key): array
    {
        $kind = (string) ($state['keys'][$key]['kind'] ?? explode('-', $key)[0]);
        $relation = self::ASSOCIATIONS[$kind] ?? null;
        $id = (int) ($state['ids'][$key] ?? 0);
        if ($relation === null || $id < 1) return [];
        $group = array_map(static fn (int $mid): array => ['id' => $mid], array_keys($this->entryGroup($key, $kind, $relation, $id)));
        if (count($group) < 2) return [];
        $governed = [];
        foreach ($state['ids'] as $other => $oid)
            if ((string) ($state['keys'][$other]['kind'] ?? explode('-', (string) $other)[0]) === $kind)
                $governed[(int) $oid] = !isset($state['keys'][$other]['base']);
        // A group that holds more than one governed source row (Business ships en-GB and ru-RU, each listed)
        // gives its translations to one of them, the lowest id, so no translation is hidden, or reported,
        // twice. A bound copy is no source: its id says nothing about who owns the group.
        foreach ($group as $member) if (!empty($governed[(int) ($member['id'] ?? 0)]) && (int) $member['id'] < $id) return [];
        $own = (string) ($state['rows'][$key]['language'] ?? '');
        $out = [];
        foreach ($group as $member) {
            $mid = (int) ($member['id'] ?? 0);
            if ($mid < 1 || isset($governed[$mid])) continue;
            $row = $this->writer->read($kind, $mid);
            $language = (string) ($row['language'] ?? '');
            if ($row === null || $language === '' || $language === '*' || $language === $own) continue;
            $out[$mid] = ['language' => $language, 'row' => $row];
        }
        ksort($out);
        return $out;
    }

    /**
     * Every language that shows a listed menu item this job hid, as the site stands now: language => row id.
     *
     * 🔒 WHAT THE SITE SHOWS NOW DECIDES, NOT THE RECEIPT. A translation, or a bound copy, the apply
     * wrote and the customer put back in its menu shows the entry again, and is named.
     */
    private function showcaseNavShowing(array $state, string $key, array $receipt): array
    {
        $out = [];
        foreach ($this->showcaseNavCopiesShowing($state, $key, $receipt) as $copy) $out[$copy['language']] = $copy['id'];
        foreach ($this->showcaseNavMembers($state, $key) as $mid => $member) $out[$member['language']] = $mid;
        return $out;
    }

    /**
     * The bound copies of a listed menu item this job hid that show it, as the site stands now.
     *
     * 🔒 EVERY COPY BOUND NOW, NOT ONLY THE ONES THE RECEIPT NAMES: a language a language job bound, or
     * bound again, after the apply builds its copy in the menu, and no receipt of this job knows it.
     *
     * @return array<string,array{language:string,id:int}> copy key => its language and id
     */
    private function showcaseNavCopiesShowing(array $state, string $key, ?array $receipt): array
    {
        $out = [];
        foreach (array_keys($this->languageCopies($state, $key, false, false) + ($receipt['copies'] ?? [])) as $copy) {
            $copyReceipt = $receipt['copies'][$copy] ?? null;
            if ($copyReceipt !== null && !in_array($copyReceipt['outcome'], ['written', 'already'], true)) continue;
            if (isset($state['rows'][$copy]) && ShowcaseNavProfile::shown($state['rows'][$copy]))
                $out[$copy] = ['language' => (string) ($state['rows'][$copy]['language'] ?? ''), 'id' => (int) ($state['ids'][$copy] ?? $copyReceipt['id'] ?? 0)];
        }
        return $out;
    }

    /**
     * The members of a listed menu item's group still showing it: published, and in their menu.
     *
     * 🔒 AN UNPUBLISHED TRANSLATION SHOWS NOTHING AND IS NOT WRITTEN — a shipped edition no language job
     * has taken, or one the customer switched off. Published later, it shows the entry again: status
     * says so, and the next apply takes it.
     *
     * @return array<int,array{language:string,row:array}>
     */
    private function showcaseNavMembers(array $state, string $key): array
    {
        return array_filter($this->languageMembers($state, $key), static fn (array $member): bool => ShowcaseNavProfile::shown($member['row']));
    }

    /**
     * Refuse, before anything moves, a showcase-navigation step that could not take every language with
     * it: a bound copy its group does not hold, a T3 style on a translated site, or a translation whose
     * entry is not where a hide starts.
     */
    private function showcaseNavCheck(array $state, array $pending, bool $first): void
    {
        foreach ($pending as $key => $row) {
            // A copy no receipt names — a language bound since — is proven its source's translation too.
            if (empty($row['refollow']) || array_diff_key($this->showcaseNavCopiesShowing($state, $key, $row['receipt']), $row['receipt']['copies'] ?? []) !== [])
                $this->showcaseNavCopies($state, $key, $row, $first);
            if ($row['kind'] !== 'menuItem') continue;
            foreach ($this->showcaseNavMembers($state, $key) as $mid => $member) {
                $probe = $this->showcaseNavHide($state, $key, ['kind' => 'menuItem', 'id' => $mid], $member['row'], true);
                if (!in_array($probe['receipt']['outcome'], ['written', 'already'], true))
                    throw new RuntimeException('The ' . $member['language'] . ' translation of ' . $key . ' (menu item ' . $mid . ') cannot follow it out of the menu ('
                        . $probe['receipt']['why'] . '). ' . ($first ? 'Nothing has been written.' : 'Nothing more has been written.'));
            }
        }
    }

    /**
     * The copies a showcase-navigation row takes with it. A T3 mega-menu style is one row for every
     * language, and the subs it keeps for a translated menu are a rule the map does not carry: refused.
     */
    private function showcaseNavCopies(array $state, string $key, array $row, bool $first): array
    {
        if ($row['kind'] === 'templateStyle') {
            // Bound languages, every language a listed entry's association group shows it in, and, on
            // a site publishing more than one content language, every one of them: a second language
            // is refused whatever its menus hold today. An unpublished content language is served nowhere.
            $languages = array_values($state['languages'] ?? []);
            foreach ($this->contract->showcaseNav()->operations() as $other => $op)
                if ($op['kind'] === 'menuItem') foreach ($this->showcaseNavMembers($state, $other) as $member) $languages[] = $member['language'];
            $published = $this->publishedContentLanguages();
            if (count($published) > 1) array_push($languages, ...$published);
            $languages = array_values(array_unique($languages));
            sort($languages);
            if ($languages !== [])
                throw new RuntimeException('Template style ' . $key . ' keeps mega-menu subs for the source menu only; on a site with ' . implode(', ', $languages)
                    . ' they cannot be followed. ' . ($first ? 'Nothing has been written.' : 'Nothing more has been written.'));
            return [];
        }
        return $this->languageCopies($state, $key, true, $first);
    }

    /** The `lang_code` of every published Joomla content language, in the order Joomla lists them. */
    private function publishedContentLanguages(): array
    {
        $out = [];
        for ($offset = 0; $offset < 500; $offset += 100) {
            $page = $this->writer->list('language', $offset, 100);
            foreach ($page as $row) if ((string) ($row['published'] ?? '') === '1' && (string) ($row['lang_code'] ?? '') !== '') $out[] = (string) $row['lang_code'];
            if (count($page) < 100) break;
        }
        return $out;
    }

    /**
     * What the next showcase-navigation step still has to look at: on apply, every listed row with no
     * receipt yet; on revert, every row the apply wrote that has not been looked at again.
     *
     * @return array<string,array{kind:string,id:int,receipt:?array}>
     */
    private function showcaseNavPending(array $state, ?array $record, bool $hide): array
    {
        $out = [];
        if ($hide) {
            $nav = $this->contract->showcaseNav();
            if ($nav === null) return [];
            foreach ($nav->operations() as $key => $row) {
                $receipt = $record['receipts'][$key] ?? null;
                if ($receipt === null) $out[$key] = ['kind' => $row['kind'], 'id' => (int) ($state['ids'][$key] ?? 0), 'receipt' => null, 'steps' => $row['steps'] ?? []];
                // A row this job already hid whose group shows it again in some language — a translation
                // published, or associated, since, or a copy or translation put back in its menu — is
                // looked at again for that language alone.
                elseif ($row['kind'] === 'menuItem' && in_array($receipt['outcome'], ['written', 'already'], true) && $this->showcaseNavShowing($state, $key, $receipt) !== [])
                    $out[$key] = ['kind' => $row['kind'], 'id' => (int) $receipt['id'], 'receipt' => $receipt, 'refollow' => true];
            }
            return $out;
        }
        foreach ($record['receipts'] ?? [] as $key => $receipt) {
            if (isset($receipt['reverted'])) continue;
            // A row found already hidden is not written back, but the copies and translations that
            // followed it are.
            $followers = array_filter($receipt['copies'] ?? [], fn (array $copy) => $copy['outcome'] === 'written') + ($receipt['members'] ?? []);
            if ($receipt['outcome'] === 'written' || ($receipt['outcome'] === 'already' && $followers !== []))
                $out[$key] = ['kind' => $receipt['kind'], 'id' => (int) $receipt['id'], 'receipt' => $receipt];
        }
        return $out;
    }

    /** The rows the next showcase-navigation batch would write, as (kind, id). */
    private function showcaseNavRows(array $state, array $record, bool $hide): array
    {
        $rows = [];
        foreach (array_slice($this->showcaseNavPending($state, $record, $hide), 0, self::SHOWCASE_NAV_BATCH, true) as $key => $row) {
            if ($row['id'] > 0) $rows[] = [$row['kind'], $row['id']];
            if ($row['kind'] !== 'menuItem') continue;
            foreach (array_keys($hide ? $this->showcaseNavMembers($state, $key) : ($row['receipt']['members'] ?? [])) as $mid) $rows[] = ['menuItem', (int) $mid];
            if ($hide && !empty($row['refollow'])) foreach ($this->showcaseNavCopiesShowing($state, $key, $row['receipt']) as $copy) $rows[] = ['menuItem', $copy['id']];
        }
        return $rows;
    }

    /** Rows an apply left alone, with why: key => already-hidden | conflict reason. On a revert, the rows it could not put back. */
    private function showcaseNavSkipped(array $receipts, bool $revert = false): array
    {
        $out = [];
        foreach ($receipts as $key => $receipt) {
            if ($revert) {
                if (($receipt['reverted'] ?? null) === 'conflict') $out[$key] = $receipt['revertWhy'] ?? 'changed since it was hidden';
                elseif (!empty($receipt['memberConflicts'])) $out[$key] = 'its translations were changed since they were hidden (' . implode('; ', $receipt['memberConflicts']) . ')';
            }
            elseif ($receipt['outcome'] !== 'written') $out[$key] = $receipt['why'];
            elseif (isset($receipt['kept'])) $out[$key] = $receipt['kept'];
        }
        return $out;
    }

    /**
     * Hide one listed row, if it still stands where the map says it starts. A translation outside the
     * contract is passed as its live `$current` row; `$dry` says what the hide would do, writing nothing.
     *
     * @return array{receipt:array, moved:?array}
     */
    private function showcaseNavHide(array $state, string $key, array $row, ?array $current = null, bool $dry = false): array
    {
        $id = $row['id'];
        $base = ['kind' => $row['kind'], 'id' => $id];
        $current = $current ?? ($id > 0 ? ($state['rows'][$key] ?? null) : null);
        if ($current === null) return ['receipt' => $base + ['outcome' => 'conflict', 'why' => 'the row is gone from this site'], 'moved' => null];
        if ($row['kind'] === 'module') {
            $published = (string) ($current['published'] ?? '');
            if ($published === '0') return ['receipt' => $base + ['outcome' => 'already', 'why' => 'already unpublished'], 'moved' => null];
            if ($published !== '1') return ['receipt' => $base + ['outcome' => 'conflict', 'why' => 'published is ' . $published . ', not 1'], 'moved' => null];
            if ($dry) return ['receipt' => $base + ['outcome' => 'written'], 'moved' => null];
            if (!$this->writer->setVisibilityIf('module', $id, 'published', '1', '0'))
                return ['receipt' => $base + ['outcome' => 'conflict', 'why' => self::SHOWCASE_NAV_RACED], 'moved' => null];
            return ['receipt' => $base + ['outcome' => 'written', 'existed' => true, 'before' => '1', 'after' => '0'],
                'moved' => ['key' => $key, 'kind' => 'module', 'exists' => true, 'value' => '0']];
        }
        $params = (string) ($current['params'] ?? '');
        // An empty column, or exactly `[]` (what an empty PHP array encodes to), holds no params at all:
        // Joomla's Registry reads either as {}, and writes {"menu_show":"0"} into it.
        $blank = trim($params) === '' || $params === '[]';
        try { $found = $blank ? ['exists' => false, 'literal' => null, 'value' => null] : JsonObjectKey::get($params, ShowcaseNavProfile::MENU_PARAM); }
        catch (Throwable $error) { return ['receipt' => $base + ['outcome' => 'conflict', 'why' => 'its params are not a JSON object'], 'moved' => null]; }
        $value = $found['value'];
        // Absent is shown: Joomla reads a missing menu_show as 1.
        $shown = !$found['exists'] || (is_scalar($value) && (string) $value === '1');
        if (!$shown && is_scalar($value) && (string) $value === '0')
            return ['receipt' => $base + ['outcome' => 'already', 'why' => 'already out of the menu'], 'moved' => null];
        if (!$shown) return ['receipt' => $base + ['outcome' => 'conflict', 'why' => 'menu_show is ' . $found['literal'] . ', not shown'], 'moved' => null];
        if ($dry) return ['receipt' => $base + ['outcome' => 'written'], 'moved' => null];
        // The value keeps the type the row already gives it; a new key is written as the admin form writes it.
        $after = $found['exists'] && is_int($value) ? '0' : '"0"';
        $written = $blank ? '{' . json_encode(ShowcaseNavProfile::MENU_PARAM) . ':' . $after . '}' : JsonObjectKey::set($params, ShowcaseNavProfile::MENU_PARAM, $after);
        if (!$this->writer->replaceMenuItemParams($id, $params, $written))
            return ['receipt' => $base + ['outcome' => 'conflict', 'why' => self::SHOWCASE_NAV_RACED], 'moved' => null];
        $receipt = ['outcome' => 'written', 'existed' => $found['exists'], 'before' => $found['literal'], 'after' => $after];
        // The column's own text, when it held none: a revert that leaves nothing else hands it back empty.
        if ($blank) $receipt['blank'] = $params;
        return ['receipt' => $base + $receipt,
            'moved' => ['key' => $key, 'kind' => 'menuItem', 'exists' => true, 'value' => json_decode($after, true), 'blank' => $blank ? $params : false]];
    }

    /**
     * Take a row's copies in the other languages where its source went: hidden with it, or brought back
     * with it, each by the same compare-and-set and in the same batch, its receipt kept in the source's.
     *
     * 🔒 A COPY THAT CANNOT FOLLOW STOPS THE BATCH. A copy is derived from its source, so a source moved
     * without it is a site its next read refuses; the step rolls back whole, and nothing of it is written.
     */
    private function showcaseNavFollow(array $state, string $key, array $row, array $result, bool $hide): array
    {
        $result['followed'] = 0;
        if ($row['kind'] === 'templateStyle') return $result;
        if ($hide) {
            if (!in_array($result['receipt']['outcome'], ['written', 'already'], true)) return $result;
            if (empty($row['refollow'])) foreach ($this->languageCopies($state, $key, true, false) as $copy) {
                $followed = $this->showcaseNavHide($state, $copy, ['kind' => $row['kind'], 'id' => (int) $state['ids'][$copy]]);
                if (!in_array($followed['receipt']['outcome'], ['written', 'already'], true))
                    throw new RuntimeException('The copy ' . $copy . ' of ' . $key . ' cannot follow it out of the menu (' . $followed['receipt']['why'] . '). Nothing more has been written.');
                $result['receipt']['copies'][$copy] = $followed['receipt'];
            }
            // A copy this job hid that the customer put back in its menu goes again, by the same
            // compare-and-set, and so does one a language bound since builds showing it. One it wrote
            // keeps that first receipt: it holds what the row was before this job.
            if (!empty($row['refollow'])) foreach ($this->showcaseNavCopiesShowing($state, $key, $row['receipt']) as $copy => $found) {
                $followed = $this->showcaseNavHide($state, $copy, ['kind' => $row['kind'], 'id' => $found['id']]);
                if ($followed['receipt']['outcome'] !== 'written')
                    throw new RuntimeException('The copy ' . $copy . ' of ' . $key . ' cannot follow it out of the menu again (' . $followed['receipt']['why'] . '). Nothing more has been written.');
                $result['followed']++;
                if (($result['receipt']['copies'][$copy]['outcome'] ?? null) !== 'written') $result['receipt']['copies'][$copy] = $followed['receipt'];
            }
            // 🔒 EVERY TRANSLATION IN THE GROUP STILL SHOWING THE ENTRY GOES WITH IT, in the same write and
            // by the same compare-and-set, its receipt kept in the source's. A translation hidden by an
            // earlier apply keeps that first receipt: it holds what the row was before this job.
            if ($row['kind'] === 'menuItem') foreach ($this->showcaseNavMembers($state, $key) as $mid => $member) {
                $followed = $this->showcaseNavHide($state, $key, ['kind' => 'menuItem', 'id' => $mid], $member['row']);
                if (!in_array($followed['receipt']['outcome'], ['written', 'already'], true))
                    throw new RuntimeException('The ' . $member['language'] . ' translation of ' . $key . ' (menu item ' . $mid . ') cannot follow it out of the menu ('
                        . $followed['receipt']['why'] . '). Nothing more has been written.');
                if ($followed['receipt']['outcome'] !== 'written') continue;
                $result['followed']++;
                if (!isset($result['receipt']['members'][$mid])) $result['receipt']['members'][$mid] = ['language' => $member['language']] + $followed['receipt'];
            }
            return $result;
        }
        if (($result['receipt']['reverted'] ?? null) !== 'restored') return $result;
        foreach ($row['receipt']['copies'] ?? [] as $copy => $receipt) {
            if ($receipt['outcome'] !== 'written') continue;
            $followed = $this->showcaseNavRestore($state, $copy, ['kind' => $receipt['kind'], 'id' => (int) $receipt['id'], 'receipt' => $receipt]);
            if ($followed['receipt']['reverted'] !== 'restored')
                throw new RuntimeException('The copy ' . $copy . ' of ' . $key . ' cannot follow it back into the menu (' . $followed['receipt']['revertWhy'] . '). Nothing more has been written.');
        }
        // A translation the customer changed since it was hidden is theirs: left as it stands, and
        // reported with its source, which a later revert request looks at again.
        $result['receipt']['memberConflicts'] = [];
        foreach ($row['receipt']['members'] ?? [] as $mid => $receipt) {
            $followed = $this->showcaseNavRestore($state, $key, ['kind' => 'menuItem', 'id' => (int) $mid, 'receipt' => $receipt], true, $this->writer->read('menuItem', (int) $mid));
            if ($followed['receipt']['reverted'] !== 'restored') { $result['receipt']['memberConflicts'][$mid] = $receipt['language'] . ': ' . $followed['receipt']['revertWhy']; continue; }
            if (($followed['receipt']['by'] ?? null) === null) $result['followed']++;
        }
        if ($result['receipt']['memberConflicts'] === []) unset($result['receipt']['memberConflicts']);
        return $result;
    }

    /** Whether every child hide a style's steps wait on has its receipt. */
    private function showcaseNavChildrenDone(array $record, array $row): bool
    {
        foreach ($row['steps'] as $step)
            foreach ($step['children'] as $child) if (!isset($record['receipts'][$child])) return false;
        return true;
    }

    /**
     * Drop the T3 mega-menu subs — or the columns of them — a style keeps for the items this apply
     * emptied, if its params still stand, byte for byte, where the map says they start.
     *
     * 🔒 THE MAP NAMES THE BYTES, THE ENGINE MAKES THEM. Each step is computed here from the live column
     * (T3MegaSub) and written only when the result hashes to the map's `after`; the whole column is one
     * compare-and-set. A step whose item kept a child in the menu (its hide a conflict) is not taken, nor
     * any after it: the next step's bytes start from this one's, so only the prefix is proven.
     *
     * @return array{receipt:array, moved:?array}
     */
    private function showcaseNavStyle(array $state, string $key, array $row, array $record): array
    {
        $id = $row['id'];
        $base = ['kind' => 'templateStyle', 'id' => $id];
        $current = $id > 0 ? ($state['rows'][$key] ?? null) : null;
        if ($current === null) return ['receipt' => $base + ['outcome' => 'conflict', 'why' => 'the row is gone from this site'], 'moved' => null];
        $steps = $row['steps'];
        $take = count($steps);
        $kept = null;
        // 🔒 THE LIVE MENU HAS THE LAST WORD. The map knows the children the release shipped; the site may
        // have more — a page the customer added under the item, in any language and whatever menu its row
        // names — and one of them still shown keeps the sub, so the dropdown opens on it. Read now, after
        // this batch's hides, with a LOCKING read: this transaction's snapshot would miss a child committed
        // since its first read, and the lock holds a new one back until the style write commits.
        foreach ($steps as $n => $step) {
            $kept = ($step['column'] ?? null) === null ? $this->showcaseNavSubKept($record, $step) : $this->showcaseNavColumnKept($record, $step);
            if ($kept !== null) { $take = $n; break; }
        }
        $params = (string) ($current['params'] ?? '');
        $live = hash('sha256', $params);
        // Already where the steps it may take end — or further, by hand: the sub is gone, nothing is written.
        foreach (array_slice($steps, max($take, 1) - 1) as $step)
            if (hash_equals($step['after'], $live))
                return ['receipt' => $base + ['outcome' => 'already', 'why' => 'the mega-menu sub is already gone'], 'moved' => null];
        if ($take === 0) return ['receipt' => $base + ['outcome' => 'skipped', 'why' => $kept], 'moved' => null];
        if (!hash_equals($steps[0]['before'], $live))
            return ['receipt' => $base + ['outcome' => 'conflict', 'why' => 'its params differ from the release'], 'moved' => null];
        $written = $params;
        foreach (array_slice($steps, 0, $take) as $step) {
            $column = $step['column'] ?? null;
            $edit = $column === null ? T3MegaSub::remove($written, $step['menutype'], $step['item']) : T3MegaSub::removeColumn($written, $step['menutype'], $step['item'], $column);
            if (!isset($edit['raw']) || !hash_equals($step['after'], hash('sha256', $edit['raw'])))
                return ['receipt' => $base + ['outcome' => 'conflict', 'why' => 'removing the mega-menu ' . ($column === null ? 'sub' : 'column ' . $column) . ' of ' . $step['itemKey'] . ' does not give the bytes the map names'], 'moved' => null];
            $written = $edit['raw'];
        }
        if (!$this->writer->replaceTemplateStyleParams($id, $params, $written))
            return ['receipt' => $base + ['outcome' => 'conflict', 'why' => self::SHOWCASE_NAV_RACED], 'moved' => null];
        $taken = array_slice($steps, 0, $take);
        $receipt = ['outcome' => 'written', 'before' => $params, 'after' => hash('sha256', $written), 'items' => array_column($taken, 'itemKey'), 'paths' => array_column($taken, 'path')];
        if ($kept !== null) $receipt['kept'] = $kept;
        return ['receipt' => $base + $receipt, 'moved' => ['key' => $key, 'kind' => 'templateStyle', 'raw' => $written]];
    }

    /** Why a megaSubRemove step is not taken — a child of its item this job did not hide, or one still shown — or null. */
    private function showcaseNavSubKept(array $record, array $step): ?string
    {
        foreach ($step['children'] as $child)
            if (!in_array($record['receipts'][$child]['outcome'] ?? null, ['written', 'already'], true))
                return 'kept the mega-menu sub of ' . $step['itemKey'] . ': its child ' . $child . ' was not hidden by this job (' . ($record['receipts'][$child]['why'] ?? 'not hidden') . ')';
        foreach ($this->writer->lockMenuChildren($step['item']) as $child)
            if ((int) ($child['parent_id'] ?? 0) === $step['item'] && ShowcaseNavProfile::shown($child))
                return 'kept the mega-menu sub of ' . $step['itemKey'] . ': its child menu item ' . (int) ($child['id'] ?? 0) . ' is still shown on this site';
        return null;
    }

    /**
     * Why a megaColumnRemove step is not taken, or null. The column is read on the live menu as T3's
     * `mega()` reads it: the item's children in menu order (`lft`), from the column's head — from the
     * first child, for the first column of items — up to the head of the column after it in the sub. Every
     * child in that range must be out of the menu, or the column still lists something and stays.
     */
    private function showcaseNavColumnKept(array $record, array $step): ?string
    {
        $what = 'kept the mega-menu column ' . $step['column'] . ' of ' . $step['itemKey'];
        foreach ($step['children'] as $child)
            if ((int) substr($child, strlen('menuItem-')) === $step['column'] && !in_array($record['receipts'][$child]['outcome'] ?? null, ['written', 'already'], true))
                return $what . ': its head ' . $child . ' was not hidden by this job (' . ($record['receipts'][$child]['why'] ?? 'not hidden') . ')';
        $live = array_values(array_filter($this->writer->lockMenuChildren($step['item']), fn ($child) => (int) ($child['parent_id'] ?? 0) === $step['item']));
        usort($live, fn ($a, $b) => [(int) ($a['lft'] ?? 0), (int) ($a['id'] ?? 0)] <=> [(int) ($b['lft'] ?? 0), (int) ($b['id'] ?? 0)]);
        $ids = array_map(fn ($child) => (string) (int) ($child['id'] ?? 0), $live);
        $at = array_search((string) $step['column'], $step['heads'], true);
        $head = array_search((string) $step['column'], $ids, true);
        if ($at === false || $head === false) return $what . ': its head is no longer a child of the item on this site';
        $from = $at === 0 ? 0 : $head;
        $next = $step['heads'][$at + 1] ?? null;
        $to = count($ids);
        for ($i = $from + 1; $next !== null && $i < count($ids); $i++) if ($ids[$i] === $next) { $to = $i; break; }
        for ($i = $from; $i < $to; $i++)
            if (ShowcaseNavProfile::shown($live[$i]))
                return $what . ': menu item ' . $ids[$i] . ' in that column is still shown on this site';
        return null;
    }

    /**
     * Put back one row this apply hid: written only while it still holds what the apply wrote; already
     * holding, byte for byte, what the apply found, it is restored with nothing written; anything else
     * is the customer's, and left.
     *
     * @return array{receipt:array{reverted:string}, moved:?array}
     */
    private function showcaseNavRestore(array $state, string $key, array $row, bool $member = false, ?array $live = null): array
    {
        $receipt = $row['receipt'];
        // A translation outside the contract is read live, by its id; a governed row is the one inspect read.
        $current = $member ? $live : ($state['rows'][$key] ?? null);
        $conflict = fn (string $why) => ['receipt' => ['reverted' => 'conflict', 'revertWhy' => $why], 'moved' => null];
        if ($current === null || (!$member && (int) ($state['ids'][$key] ?? 0) !== $row['id'])) return $conflict('the row is gone from this site');
        if ($row['kind'] === 'module') {
            $moved = ['key' => $key, 'kind' => 'module', 'exists' => true, 'value' => $receipt['before']];
            $published = (string) ($current['published'] ?? '');
            // Put back already (by the customer, or by hand): the baseline follows; nothing is written.
            if ($published === $receipt['before']) return ['receipt' => ['reverted' => 'restored', 'by' => 'found'], 'moved' => $moved];
            if ($published !== $receipt['after']) return $conflict('published was changed since it was hidden');
            if (!$this->writer->setVisibilityIf('module', $row['id'], 'published', $receipt['after'], $receipt['before'])) return $conflict(self::SHOWCASE_NAV_RACED);
            return ['receipt' => ['reverted' => 'restored'], 'moved' => $moved];
        }
        if ($row['kind'] === 'templateStyle') {
            $params = (string) ($current['params'] ?? '');
            $moved = ['key' => $key, 'kind' => 'templateStyle', 'raw' => $receipt['before']];
            if ($params === $receipt['before']) return ['receipt' => ['reverted' => 'restored', 'by' => 'found'], 'moved' => $moved];
            if (!hash_equals($receipt['after'], hash('sha256', $params))) return $conflict('its params were changed since the mega-menu sub was removed');
            if (!$this->writer->replaceTemplateStyleParams($row['id'], $params, $receipt['before'])) return $conflict(self::SHOWCASE_NAV_RACED);
            return ['receipt' => ['reverted' => 'restored'], 'moved' => $moved];
        }
        $params = (string) ($current['params'] ?? '');
        $before = ['key' => $key, 'kind' => 'menuItem', 'exists' => $receipt['existed'], 'value' => $receipt['existed'] ? json_decode($receipt['before'], true) : null];
        if (isset($receipt['blank']) && $params === $receipt['blank'])
            return ['receipt' => ['reverted' => 'restored', 'by' => 'found'], 'moved' => ['blank' => $receipt['blank']] + $before];
        try { $found = trim($params) === '' ? ['exists' => false, 'literal' => null] : JsonObjectKey::get($params, ShowcaseNavProfile::MENU_PARAM); }
        catch (Throwable $error) { return $conflict('its params are no longer a JSON object'); }
        // menu_show already what the apply found — the same literal, or absent as it was — so the baseline follows; nothing is written.
        if ($found['exists'] === $receipt['existed'] && (!$found['exists'] || $found['literal'] === $receipt['before']))
            return ['receipt' => ['reverted' => 'restored', 'by' => 'found'], 'moved' => ['blank' => false] + $before];
        if (!$found['exists'] || $found['literal'] !== $receipt['after']) return $conflict('menu_show was changed since it was hidden');
        $restored = JsonObjectKey::set($params, ShowcaseNavProfile::MENU_PARAM, $receipt['existed'] ? $receipt['before'] : null);
        // Back to the empty column it was, unless someone has given the row other params since.
        $blank = isset($receipt['blank']) && json_decode($restored, true) === [];
        if ($blank) $restored = $receipt['blank'];
        if (!$this->writer->replaceMenuItemParams($row['id'], $params, $restored)) return $conflict(self::SHOWCASE_NAV_RACED);
        return ['receipt' => ['reverted' => 'restored'], 'moved' => ['blank' => $blank ? $receipt['blank'] : false] + $before];
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
        $this->batchFiles = [];
        $this->batchSiteFiles = [];
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
                    // A template's language string is a line of the override file, not a row: only the content contract
                    // writes it here (template.languageOverrides is its own door), and only its `value`.
                    if ($kind === LanguageStrings::KIND) {
                        if ($verify === null || !is_array($step['fields']) || array_keys($step['fields']) !== ['value']) throw new RuntimeException('kind not allowed in a content batch');
                        $key = $step['key'] ?? (string) $index;
                        if (!is_string($key) || isset($ids[$key])) throw new RuntimeException('operation keys must be unique strings');
                        $ids[$key] = $this->writeLanguageString($apply, (int) ($step['id'] ?? 0), (string) $step['fields']['value'], is_array($step['expected'] ?? null) ? $step['expected'] : null);
                        continue;
                    }
                    // A T4 logo is a key of a profile file, not a row: only the content contract writes it here
                    // (template.siteSettings is its own door), and only its `value`.
                    if ($kind === SiteSettingRows::KIND) {
                        if ($verify === null || !is_array($step['fields']) || array_keys($step['fields']) !== ['value']) throw new RuntimeException('kind not allowed in a content batch');
                        $key = $step['key'] ?? (string) $index;
                        if (!is_string($key) || isset($ids[$key])) throw new RuntimeException('operation keys must be unique strings');
                        $ids[$key] = $this->writeSiteSetting($apply, (int) ($step['id'] ?? 0), (string) $step['fields']['value'], is_array($step['expected'] ?? null) ? $step['expected'] : null);
                        continue;
                    }
                    // Identity and installed executable settings require their own privileged door. One exception: the
                    // content contract (it alone passes $verify) writes an author's display NAME, a slot the profile
                    // declares because visitors read it under every post; never another column of the account.
                    $authorName = $kind === 'user' && $verify !== null && is_array($step['fields']) && array_keys($step['fields']) === ['name'];
                    if (in_array($kind, ['user', 'extensionParams'], true) && !$authorName) throw new RuntimeException('kind not allowed in a content batch');
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
            // A file is outside the database transaction: the override lines this batch wrote go back first,
            // whatever the rollback kept of their undo records.
            $files = $this->batchFiles;
            $this->batchFiles = [];
            $fileLeft = null;
            try {
                if ($files !== [] && $this->overrides !== null) $this->overrides->restore(array_merge(...$files));
            } catch (Throwable $e) {
                $fileLeft = $e->getMessage();
            }
            // The T4 profile files this batch wrote go back too.
            $siteFiles = $this->batchSiteFiles;
            $this->batchSiteFiles = [];
            try {
                if ($siteFiles !== [] && $this->templateSite !== null) $this->templateSite->restore(array_merge(...$siteFiles));
            } catch (Throwable $e) {
                $fileLeft = ($fileLeft === null ? '' : $fileLeft . '; ') . 'T4 site profile file: ' . $e->getMessage();
            }
            $undo = $fresh ? $this->undoFailedBatch($apply) : null;
            if ($fileLeft !== null) $undo = array_merge($undo ?? [], ['language override file: ' . $fileLeft]);
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
            $this->batchFiles = [];
            $this->batchSiteFiles = [];
        }
    }

    /**
     * Write one template language string of a contract apply into its language's override file, and record the
     * file's previous bytes under the apply_id like any undo (op `languageOverrides`: apply.revert puts them back,
     * deleting a file that did not exist). Answers the string's row id.
     */
    private function writeLanguageString(string $apply, int $id, string $value, ?array $expected): int
    {
        if ($this->overrides === null) throw new RuntimeException('language overrides not wired');
        $planned = $this->contract->languageStringChange($id, $value, $expected);
        if ($planned['change'] === null) return $id;
        $files = $this->overrides->write($planned['change']);
        $this->batchFiles[] = $files;
        $this->log->record($apply, ['op' => 'languageOverrides', 'locale' => $planned['locale'], 'files' => $files]);
        return $id;
    }

    /**
     * Write one T4 site setting (a logo) of a contract apply into the profile's `local/` copy, and record the files' previous
     * bytes under the apply_id like the `template.siteSettings` door does (op `siteSettings`: apply.revert puts them back,
     * deleting a file, and the folders, that did not exist). Answers the setting's row id.
     */
    private function writeSiteSetting(string $apply, int $id, string $value, ?array $expected): int
    {
        if ($this->templateSite === null) throw new RuntimeException('template site settings not wired');
        $planned = $this->contract->siteSettingChange($id, $value, $expected);
        if ($planned['change'] === null) return $id;
        $files = $this->templateSite->write([$planned['change']]);
        $this->batchSiteFiles[] = $files;
        $this->log->record($apply, ['op' => 'siteSettings', 'files' => $files]);
        $this->templateSite->clearOptimize();
        return $id;
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
                    ? $this->err('write_failed', 'the move landed (revert via apply.revert); the field update failed: ' . $e->getMessage(), ['landed' => true])
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

    /** Delete one image under images/, keeping its exact bytes in the ordinary Apply log. */
    private function mediaDelete(array $p): array
    {
        if ($this->media === null || $this->log === null || !method_exists($this->media, 'snapshotForDelete')) return $this->err('unavailable', 'media delete writer not wired');
        $applyId = $this->applyId($p);
        if ($applyId === null) return $this->err('bad_params', 'apply_id required');
        $path = isset($p['path']) && is_string($p['path']) ? $p['path'] : '';
        if ($path === '' || strpos($path, 'images/') !== 0 || !self::safeMediaPath($path)) return $this->err('bad_params', 'path must be a safe relative file beneath images/');
        if (!in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif', 'ico'], true)) return $this->err('bad_params', 'media.delete accepts image files only');
        try {
            $snapshot = $this->media->snapshotForDelete($path);
            $usage = $this->writer !== null && method_exists($this->writer, 'mediaUsage') ? $this->writer->mediaUsage($path) : [];
        } catch (Throwable $e) {
            return $this->err('write_failed', 'could not make and verify the undo copy; nothing was deleted: ' . $e->getMessage());
        }
        $total = (int) ($snapshot['size'] ?? strlen((string) ($snapshot['bytes'] ?? '')));
        $shown = [['path' => $path, 'size' => $total, 'sha256' => (string) ($snapshot['sha256'] ?? '')]];
        $underCap = $total <= self::MAX_MEDIA_BYTES;
        $scope = 'articles introtext/fulltext/images JSON, modules content/params, template style params and menu item params';
        if (!empty($p['dry_run'])) return $this->ok(['deleted' => ['path' => $path, 'files' => $shown], 'totalBytes' => $total, 'undoCopyUnderCap' => $underCap, 'usedIn' => $usage, 'usedInScope' => $scope]);
        if (!$underCap) return $this->err('too_large', 'this picture and its sizes are too large to keep an undo copy; nothing was deleted', ['totalBytes' => $total]);
        $bytes = (string) ($snapshot['bytes'] ?? '');
        if (strlen($bytes) !== $total || !hash_equals((string) ($snapshot['sha256'] ?? ''), hash('sha256', $bytes))) return $this->err('write_failed', 'image bytes failed verification; nothing was deleted');
        try {
            $this->media->delete($path);
            $left = $this->media->deletedFilePathExists($path) ? [$path] : [];
        } catch (Throwable $e) {
            try { $this->media->restoreDeletedFile($snapshot, true); } catch (Throwable $undo) {}
            return $this->err('write_failed', 'could not delete the image: ' . $e->getMessage());
        }
        try {
            $this->log->record($applyId, ['op' => 'media.delete', 'path' => $path, 'snapshot' => $snapshot]);
        } catch (Throwable $e) {
            try { $this->media->restoreDeletedFile($snapshot, true); } catch (Throwable $undo) {
                return $this->err('write_failed', 'undo could not be recorded and the image could not be fully restored: ' . $undo->getMessage());
            }
            return $this->err('write_failed', 'change was rolled back: could not record its undo');
        }
        try { if (!$this->batching && $this->writer) $this->writer->purgeCache(); } catch (Throwable $e) {}
        if (!$this->batching) $this->stamped('media');
        return $this->ok(['deleted' => ['path' => $path, 'files' => $shown], 'storageDeleted' => $left === [], 'filesLeft' => $left, 'usedIn' => $usage, 'usedInScope' => $scope, 'undo' => 'apply.revert ' . $applyId, 'cdnCache' => 'a CDN or browser may still serve a cached copy of the old URL for a while']);
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
        foreach (array_reverse($entries) as $entry) {
            if (($entry['op'] ?? '') !== 'media.delete') continue;
            $path = (string) ($entry['path'] ?? '');
            $snapshot = is_array($entry['snapshot'] ?? null) ? $entry['snapshot'] : [];
            if ($this->media !== null && method_exists($this->media, 'deletedFilePathExists') && $this->media->deletedFilePathExists($path)
                && (!method_exists($this->media, 'deletedFilePathMatchesSnapshot') || !$this->media->deletedFilePathMatchesSnapshot($snapshot))) {
                $message = 'File path ' . $path . ' exists now; it was not overwritten.';
                return $this->err('conflict', $message, ['errors' => [ContractProblem::plain('CONFLICT', $message)], 'next' => 'Move the conflicting file, then apply.revert again.']);
            }
        }
        // A template file goes back only where the live file still holds what the write left there.
        if ($this->templateFiles !== null && ($blocked = $this->templateFiles->revertBlocked($entries)) !== []) {
            $message = 'Template files changed on the site since they were written: ' . implode(', ', array_column($blocked, 'path')) . '. Nothing was reverted.';
            return $this->err('conflict', $message, ['errors' => [ContractProblem::plain('CONFLICT', $message)], 'kept' => $blocked,
                'next' => 'template.files.undo puts back the files that can go back and names the rest.']);
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
            } elseif ($op === 'media.delete') {
                $step['created'] = false;
                $step['path'] = $entry['path'] ?? null;
                $step['files'] = [$entry['path'] ?? null];
            } elseif ($op === 'siteIdentity') {
                // Which of the two it changed, never the words: a listing is for verifying.
                $step['fields'] = array_keys(self::identityShown(self::identityValues($entry['before'] ?? null)));
            } elseif ($op === TemplateFiles::LOG_OP) {
                $step['path'] = $entry['path'] ?? null;
                $step['undo_id'] = $entry['undo_id'] ?? null;
                $step['deleted'] = ($entry['after'] ?? null) === null;
            } elseif ($op === 'siteSettings' || $op === 'languageOverrides') {
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
        if ($op === 'media.delete') {
            if ($this->media === null || !method_exists($this->media, 'restoreDeletedFile')) throw new RuntimeException('media delete writer not wired');
            $this->media->restoreDeletedFile(is_array($entry['snapshot'] ?? null) ? $entry['snapshot'] : []);
            return;
        }
        if ($op === 'siteIdentity') {
            if ($this->siteIdentity === null) {
                throw new RuntimeException('site identity store not wired');
            }
            $this->siteIdentity->write(self::identityValues($entry['before'] ?? null));
            return;
        }
        if ($op === 'languageOverrides') {
            if ($this->overrides === null) {
                throw new RuntimeException('language overrides not wired');
            }
            $this->overrides->restore(is_array($entry['files'] ?? null) ? $entry['files'] : []);
            return;
        }
        if ($op === TemplateFiles::LOG_OP) {
            if ($this->templateFiles === null) {
                throw new RuntimeException('template files not wired');
            }
            $this->templateFiles->revertEntry($entry);
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
     * `template.files.status`, `template.files.write`, `template.files.undo` — the files of the
     * site's installed templates (lib/TemplateFiles.php). A write and an undo run under the write
     * lock (handle()), need an `apply_id`, and record in the Apply log; status only reads.
     */
    private function templateFilesDoor(string $action, array $p): array
    {
        if ($this->templateFiles === null) return $this->err('unavailable', 'template files are not wired on this site');
        if ($action === 'template.files.status' || !$this->templateFiles->isEnabled()) return $this->templateFiles->status($p);
        if ($this->log === null) return $this->err('unavailable', 'apply log not wired');
        $applyId = $this->applyId($p);
        if ($applyId === null) return $this->err('bad_params', 'apply_id required');
        $answer = $action === 'template.files.write' ? $this->templateFiles->write($p, $applyId, $this->log) : $this->templateFiles->undo($p, $applyId, $this->log);
        if (($answer['ok'] ?? false) && (!empty($answer['changed']) || !empty($answer['restored'])) && empty($answer['dry_run'])) {
            try { if ($this->writer) $this->writer->purgeCache(); } catch (Throwable $ignored) {}
            $this->stamped('template');
        }
        return $answer;
    }

    /**
     * `site.identity` — Global Configuration's site name (`sitename`), site description (`MetaDesc`)
     * and whether page titles carry the site name (`sitename_pagetitles`), and nothing else of
     * configuration.php (lib/SiteIdentity.php).
     *
     * - `operation: read` (the default): `{fields: {sitename, MetaDesc, sitename_pagetitles}, writable}`
     *   — the values as the site holds them, and whether a `set` would land now.
     * - `operation: set`, `apply_id`, `fields`: any of the three. The two texts are strings, cleaned
     *   as Joomla's own form cleans them (tags removed, one line) and refused past their length;
     *   `sitename_pagetitles` is 0, 1 or 2 (no, before, after), stored as an integer.
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
                    return $this->err('unsupported', 'Only ' . self::identityList() . ' can be read through site.identity');
                }
            }
            try {
                return $this->ok(['fields' => self::identityShown($this->siteIdentity->read()), 'writable' => $this->siteIdentity->writable()]);
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
            return $this->err('bad_params', 'fields required: an object with any of ' . self::identityList());
        }
        $clean = [];
        foreach ($fields as $name => $value) {
            if (!in_array($name, SiteIdentity::FIELDS, true)) {
                return $this->err('unsupported', substr((string) $name, 0, 60) . ' cannot be written through site.identity: only ' . self::identityList() . ' can. Nothing was written');
            }
            $cleaned = SiteIdentity::clean($name, $value);
            if (isset($cleaned['error'])) {
                return $this->err('bad_params', $cleaned['error']);
            }
            $clean[$name] = $cleaned['value'];
        }
        // Tracy's mark follows every set of the switch, in the same write and the same undo step, so the
        // system plugin knows the switch is Tracy's (lib/HomeTitle.php) until `apply.revert` takes both back.
        if (array_key_exists(SiteIdentity::PAGE_TITLES, $clean)) $clean[HomeTitle::MARK] = $clean[SiteIdentity::PAGE_TITLES];
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
            return $this->ok(['fields' => self::identityShown($before), 'changed' => [], 'unchanged' => true]);
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
                return $this->err('write_failed', 'the change landed but its undo could not be recorded, and it could not be rolled back: ' . $undo->getMessage(), ['landed' => true]);
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
        return $this->ok(['fields' => self::identityShown(array_merge($before, $changes)), 'changed' => array_keys(self::identityShown($changes))]);
    }

    /**
     * The door's keys of what the store holds, in their order: Tracy's mark is the store's own and never
     * leaves the door.
     *
     * @param array<string,mixed> $values
     * @return array<string,mixed>
     */
    private static function identityShown(array $values): array
    {
        return array_intersect_key($values, array_flip(SiteIdentity::FIELDS));
    }

    /**
     * `template.siteSettings` — a template's logo, name, slogan and favicon (TCH #1013, T18;
     * lib/TemplateSiteSettings.php, lib/TemplateSiteFiles.php).
     *
     * - `operation: read` (the default), `template`: for a T4 template each site profile a style
     *   uses (`profiles: {name: {source, settings}}`, `missing`) and `settings` with its share
     *   image; for any other `settings` with its favicon and share image. `framework` says which, `keys` what a set may write.
     * - `operation: set`, `apply_id`, `template`, `fields` and/or `profiles`: on T4, `fields` go into
     *   every profile a style uses and `profiles: {name: {...}}` into one (its values win). Each
     *   profile is copied from where T4 reads it and only those keys change, written to
     *   `templates/<t>/local/etc/site/<profile>.json`. `other_shareImage` (png, jpg, webp or gif)
     *   is one for the whole site, in `fields` only, and kept in `tracy-favicon.json` beside the
     *   profiles: the system plugin prints it as `og:image`, on T4 only on a page without one of its
     *   own. On any other template the keys are `other_faviconFile` and `other_shareImage`, both
     *   printed by the system plugin (there the share image replaces any other). A picture must
     *   already be on the site.
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
        $keys = TemplateSiteSettings::keysFor($framework);
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
        foreach ($groups as $group => $values) foreach (array_keys($values) as $name) {
            if ($group !== '' && in_array($name, TemplateSiteSettings::SITE_KEYS, true)) {
                $what = $name === TemplateSiteSettings::SHARE_KEY ? 'one share image' : 'one set of faces';
                return $this->err('unsupported', $name . ' is ' . $what . ' for the whole site: send it in fields, not in a profile. Nothing was written');
            }
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
                return $this->err('write_failed', 'the change landed but its undo could not be recorded, and it could not be rolled back: ' . $undo->getMessage(), ['landed' => true]);
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
     * `template.languageOverrides` — the site's front-end language strings that still read in en-GB for one language,
     * and its language override file for that language (TCH #1013; lib/LanguageOverrides.php).
     *
     * - `operation: read` (the default), `template`, `locale`, optional `offset` (0) and `limit` (1..1500, 1500): one
     *   page of `strings`, each `{key, source, reason, file, rank}` (reason `missing`, `untranslated` or `suspect`;
     *   rank `template`, `used`, `core`, `other`, in that order: the words a visitor reads first); `overrides`, what the
     *   override file holds now; `total`, every string offered; `truncated` and `nextOffset` when more follow.
     *   `DATE_FORMAT_*` keys are never offered: a format is configuration, not words. A date format a layout passes through
     *   Text::_ in English order (`Text::_('F j, Y')`) is offered under its upper-cased literal with `reason: date-format`
     *   and `value`, the language's own format; a set writes that value for it or refuses it. Plugin strings a visitor reads
     *   (`administrator/language/en-GB/plg_*.ini`, the keys of a plugin's `tmpl/` or of a visitor's form) are offered too.
     * - `operation: set`, `apply_id`, `template`, `locale`, `strings: {KEY: words}`: each key one the read offers, each
     *   value one line of plain text with the en-GB words' placeholders. A key or value that is not is left out and
     *   named in `refused`, never written. The file's previous bytes are recorded under the `apply_id`, so `apply.revert`
     *   puts them back (deleting a file that did not exist). Nothing changes → nothing is written (`unchanged: true`).
     *
     * Not part of a `content.batch`: a file is not inside the database's transaction.
     */
    private function languageOverridesDoor(array $p): array
    {
        if ($this->overrides === null) return $this->err('unavailable', 'language overrides not wired');
        $template = $p['template'] ?? null;
        $locale = $p['locale'] ?? null;
        if (!is_string($template) || !preg_match(LanguageOverrides::TEMPLATE, $template))
            return $this->err('bad_params', 'template required: the folder name of a site template, e.g. ja_essence');
        if (!is_string($locale) || !preg_match(LanguageOverrides::LOCALE, $locale) || $locale === 'en-GB')
            return $this->err('bad_params', 'locale required: a Joomla language tag other than en-GB, e.g. vi-VN');
        if (!$this->overrides->hasTemplate($template)) return $this->err('not_found', 'No site template ' . $template . ' is installed');
        $head = ['template' => $template, 'locale' => $locale, 'path' => LanguageOverrides::path($locale)];
        $operation = $p['operation'] ?? 'read';
        if ($operation === 'read') {
            $offset = $p['offset'] ?? 0;
            $limit = $p['limit'] ?? LanguageOverrides::MAX_STRINGS;
            if (!is_int($offset) || $offset < 0) return $this->err('bad_params', 'offset is a whole number from 0: the nextOffset of the page before');
            if (!is_int($limit) || $limit < 1 || $limit > LanguageOverrides::MAX_STRINGS)
                return $this->err('bad_params', 'limit is a whole number from 1 to ' . LanguageOverrides::MAX_STRINGS);
            try {
                return $this->ok($head + $this->overrides->plan($template, $locale, $offset, $limit));
            } catch (Throwable $e) {
                return $this->err('read_failed', $e->getMessage());
            }
        }
        if ($operation !== 'set') return $this->err('bad_params', 'Unknown template.languageOverrides operation: use read or set');
        try {
            // Every page's strings, not one page's: a key read on a later page is as writable as one on the first.
            $plan = ['strings' => $this->overrides->offered($template, $locale)];
        } catch (Throwable $e) {
            return $this->err('read_failed', $e->getMessage());
        }
        if ($this->log === null) return $this->err('unavailable', 'apply log not wired');
        $applyId = $this->applyId($p);
        if ($applyId === null) return $this->err('bad_params', 'apply_id required');
        $strings = $p['strings'] ?? null;
        if (!is_array($strings) || $strings === [] || array_keys($strings) === range(0, count($strings) - 1))
            return $this->err('bad_params', 'strings required: an object of language keys to their words');
        $offered = [];
        $formats = [];
        foreach ($plan['strings'] as $row) {
            $offered[$row['key']] = $row['source'];
            if (($row['reason'] ?? null) === 'date-format') $formats[$row['key']] = $row['value'];
        }
        $values = [];
        $refused = [];
        foreach ($strings as $key => $value) {
            $key = strtoupper((string) $key);
            if (!isset($offered[$key])) { $refused[] = ['key' => substr($key, 0, 120), 'reason' => 'not a string this site shows in en-GB']; continue; }
            // A date format is the language's own, never words: the read named it, and nothing else is written.
            if (isset($formats[$key]) && (!is_string($value) || trim($value) !== $formats[$key])) { $refused[] = ['key' => $key, 'reason' => 'a date format takes the language\'s own (' . $formats[$key] . ')']; continue; }
            $why = LanguageOverrides::refusal($offered[$key], $value);
            if ($why !== null) { $refused[] = ['key' => $key, 'reason' => $why]; continue; }
            $values[$key] = trim($value);
        }
        $answer = static fn(array $extra): array => $head + $extra + ($refused !== [] ? ['refused' => $refused] : []);
        $change = $values === [] ? null : $this->overrides->change($locale, $values);
        if ($change === null) return $this->ok($answer(['written' => 0, 'unchanged' => true]));
        try {
            $files = $this->overrides->write($change);
        } catch (Throwable $e) {
            return $this->err('write_failed', $e->getMessage(), ['code' => 'LANGUAGE_NOT_WRITABLE']);
        }
        try {
            $this->log->record($applyId, ['op' => 'languageOverrides', 'locale' => $locale, 'files' => $files]);
        } catch (Throwable $e) {
            try {
                $this->overrides->restore($files);
            } catch (Throwable $undo) {
                return $this->err('write_failed', 'the change landed but its undo could not be recorded, and it could not be rolled back: ' . $undo->getMessage(), ['landed' => true]);
            }
            return $this->err('write_failed', 'change was rolled back: could not record its undo');
        }
        if ($this->writer !== null) {
            try {
                if (!$this->batching) $this->writer->purgeCache();
            } catch (Throwable $e) {
                // Best-effort by contract.
            }
        }
        $this->stamped('content');
        return $this->ok($answer(['written' => count($values)]));
    }

    /**
     * The identity values an undo entry holds, and only those, each in its type: a log row is data,
     * and whatever else it might carry never reaches configuration.php.
     *
     * @return array<string,string|int>
     */
    private static function identityValues($before): array
    {
        $out = [];
        if (!is_array($before)) return $out;
        foreach (SiteIdentity::FIELDS as $field) {
            if (array_key_exists($field, $before) && is_scalar($before[$field])) $out[$field] = SiteIdentity::typed($field, $before[$field]);
        }
        // The mark comes back as it was, absence included: null takes it out of the file.
        if (array_key_exists(HomeTitle::MARK, $before) && ($before[HomeTitle::MARK] === null || is_scalar($before[HomeTitle::MARK])))
            $out[HomeTitle::MARK] = SiteIdentity::typed(HomeTitle::MARK, $before[HomeTitle::MARK]);
        return $out;
    }

    /** The identity keys as a refusal names them: "sitename, MetaDesc and sitename_pagetitles". */
    private static function identityList(): string
    {
        $fields = SiteIdentity::FIELDS;
        $last = array_pop($fields);
        return implode(', ', $fields) . ' and ' . $last;
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
        if (strpos($path, '\\') !== false || preg_match('/[\x00-\x1F\x7F]/', $path) === 1 || preg_match('//u', $path) !== 1) {
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
