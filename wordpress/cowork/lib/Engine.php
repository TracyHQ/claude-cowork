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
require_once __DIR__ . '/QuickstartContract.php';

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
    /**
     * The content-only seal. Null on a site wired without one (every existing test), in which case
     * the site is unbound and nothing below changes. On the plugin it is always wired, and whether
     * the site is SEALED is a fact of its store, not of this object being present.
     */
    private ?QuickstartContract $contract;
    /** Set while a request runs under the writer lock, so the re-entrant call does not take it twice. */
    private bool $writing = false;

    private const MAX_DB_LIMIT = 5000;
    /** Writes that go through the contract's own rules on a bound site (see handle()). */
    private const CONTRACT_RULED = ['media.upload', 'apply.revert', 'content.contract'];
    /** Actions that change the site, and so run one at a time under the writer's lock. */
    private const SERIALIZED = [
        'content.contract', 'content.update', 'content.delete', 'content.language',
        'media.upload', 'apply.revert', 'db.cleanup', 'db.restore', 'db.purge',
    ];
    /** The only path an image may take onto a sealed site: content-addressed, under one folder. */
    private const CONTRACT_MEDIA_PATH = '~^wp-content/uploads/tracy-content/[a-f0-9]{64}\.(png|jpg|webp)$~D';
    private const DEMO_TRIM_BATCH = 300;
    /** Rows one `multilingual.retire` call moves (hidden plus brought back) before it answers `running`. */
    private const MULTILINGUAL_BATCH = 300;
    /** What a retire drafts: the post types an edition is made of. Navigation twins carry no Polylang language (see `retirableRows`). */
    private const EDITION_POST_TYPES = ['page', 'post', 'wp_navigation'];
    private const REQUEST_ID_SHAPE = '/^[a-zA-Z0-9._:-]{1,100}$/D';
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
        $stamp = null,
        ?QuickstartContract $contract = null
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
        $this->contract = $contract;
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

        // One writer at a time. Two agents applying to one site interleave reads and writes, and
        // a content apply that verified the site before its write is only sound if nothing else
        // wrote in between. The writers that cannot lock (the in-memory doubles) run as before.
        if (!$this->writing && $this->writer !== null && method_exists($this->writer, 'serialize')
            && in_array($action, self::SERIALIZED, true)) {
            try {
                return $this->writer->serialize(function () use ($req): array {
                    $this->writing = true;
                    try {
                        return $this->handle($req);
                    } finally {
                        $this->writing = false;
                    }
                });
            } catch (Throwable $e) {
                $busy = $this->err('writer_busy', $e->getMessage());
                return $action === 'content.contract' ? self::withErrors($busy) : $busy;
            }
        }

        // The seal. A store that cannot be read, or a bound profile this plugin does not carry,
        // LOCKS every write: the one thing a sealed site must never do is read as unbound.
        $bound = false;
        if ($this->contract !== null) {
            try {
                $bound = $this->contract->bound();
            } catch (Throwable $e) {
                if (in_array($action, self::CONTRACT_RULED, true) || in_array($action, self::SERIALIZED, true)) {
                    return $this->err('contract_unavailable', $e->getMessage());
                }
            }
        }
        // The contract's own record is not an option like the others: written through content.update,
        // one write could unbind the site or name another profile. It moves only through its door.
        if (in_array($action, ['content.update', 'content.delete'], true) && ($params['kind'] ?? '') === 'option'
            && in_array((string) ($params['key'] ?? ''), [QuickstartContract::STORE_OPTION, 'claude_cowork_contract'], true)) {
            return $this->err('bad_params', "This option is the contract's own record; it changes only through content.contract");
        }
        // A contract RECOMMENDS how to keep the quickstart's design; it locks nothing (Tracy ADR 0022,
        // 26/09/2026). A bound site takes every action an unbound one does; the contract's own rules
        // stay on its own door and on the picture folder its image slots use.
        if ($bound) {
            if ($action === 'media.upload') {
                $path = isset($params['path']) && is_string($params['path']) ? $params['path'] : '';
                if (strpos($path, 'wp-content/uploads/tracy-content/') === 0) {
                    if (!preg_match(self::CONTRACT_MEDIA_PATH, $path)) {
                        return $this->err('bad_params', 'A picture under wp-content/uploads/tracy-content/ is named <sha256>.<png|jpg|webp>');
                    }
                    $b64 = isset($params['content_b64']) && is_string($params['content_b64']) ? $params['content_b64'] : '';
                    $bytes = base64_decode($b64, true);
                    if ($bytes === false || !hash_equals(hash('sha256', $bytes), (string) pathinfo($path, PATHINFO_FILENAME))) {
                        return $this->err('bad_params', 'The image name must be the sha256 of its bytes');
                    }
                    if (strpos((string) ($params['apply_id'] ?? ''), 'contract-') === 0) {
                        return $this->err('bad_params', 'Media uploads must use a separate apply receipt, not a contract- apply_id');
                    }
                }
            }
            if ($action === 'apply.revert') {
                // A contract apply, a trim, a language step or a relabel is taken back through the
                // contract; any other receipt takes the ordinary revert below.
                $ops = $this->log === null ? [] : array_column($this->log->entries((string) ($params['apply_id'] ?? '')), 'op');
                if (array_intersect($ops, ['contract', 'visibility', 'language', 'siteLanguage', 'sourceLocale']) !== []) {
                    return $this->contractRevert($params);
                }
            }
        }

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
            case 'core.manifest':
                return $this->coreManifest();
            case 'plugin.selfUpdate':
                return $this->pluginSelfUpdate();
            case 'language.install':
                return $this->languageInstall($params);
            case 'theme.install':
                return $this->themeInstall($params);
            case 'theme.activate':
                return $this->themeActivate($params);
            case 'theme.style':
                return $this->themeStyle($params);
            case 'theme.palette':
                return $this->themePalette($params);
            case 'content.list':
                return $this->contentList($params);
            case 'content.get':
                return $this->contentGet($params);
            case 'content.update':
                return $this->contentUpdate($params);
            case 'content.language':
                return $this->contentLanguage($params);
            case 'content.delete':
                return $this->contentDelete($params);
            case 'db.cleanup':
                return $this->dbCleanup($params);
            case 'db.restore':
                return $this->dbRestore($params);
            case 'db.purge':
                return $this->dbPurge($params);
            case 'media.upload':
                return $this->mediaUpload($params);
            case 'apply.revert':
                return $this->applyRevert($params);
            case 'apply.list':
                return $this->applyList($params);
            case 'content.contract':
                return $this->contentContract($params);
            default:
                return $this->err('bad_action', "unknown action: {$action}");
        }
    }

    // ---- The content contract ---------------------------------------------------------------
    //
    // One action, several operations, because the relay admits actions by NAME and a sealed site
    // must not need a second door opened for any of this. Every operation says which of these it
    // is: `inspect` reads, `bind` seals, `apply` writes slot values, `demoTrim.*` hides the
    // vendor's demo, `sourceLanguage.*` respells the source edition's locale, `siteLanguage.*`
    // makes one edition the site's default, `multilingual.*` sets which of the archive's
    // editions are live.

    private function contentContract(array $p): array
    {
        return self::withErrors($this->contentContractAnswer($p));
    }

    /**
     * Every refusal of `content.contract` also carries `errors[]`: a code, the slot and content it
     * is about, and whether a changed request can pass. A refusal that was not raised as a
     * `ContractProblem` is one `CONTRACT_FAILED`; an inspect refused by drift is one
     * `PRESENTATION_DRIFT` per problem. `error`, `message` and `problems` stay for older relays.
     */
    private static function withErrors(array $answer): array
    {
        if (($answer['ok'] ?? true) !== false || isset($answer['errors'])) {
            return $answer;
        }
        if (isset($answer['problems']) && is_array($answer['problems']) && $answer['problems'] !== []) {
            $answer['errors'] = array_map(static fn($problem) => ContractProblem::entry(ContractProblem::PRESENTATION_DRIFT, (string) $problem), $answer['problems']);
            return $answer;
        }
        $code = ($answer['error'] ?? '') === 'writer_busy' ? ContractProblem::WRITER_BUSY : ContractProblem::CONTRACT_FAILED;
        $answer['errors'] = [ContractProblem::entry($code, (string) ($answer['message'] ?? ''))];
        return $answer;
    }

    private function contentContractAnswer(array $p): array
    {
        if ($this->contract === null || $this->log === null || $this->writer === null) {
            return $this->err('unavailable', 'content contract receiver not wired');
        }
        $operation = isset($p['operation']) && is_string($p['operation']) && $p['operation'] !== '' ? $p['operation'] : 'inspect';
        $requested = isset($p['contract']) && is_string($p['contract']) && trim($p['contract']) !== '' ? trim($p['contract']) : null;
        try {
            if ($operation === 'inspect') {
                return $this->inspectAnswer($this->contract->inspect($requested));
            }
            if ($operation === 'bind') {
                $state = $this->contract->inspect($requested);
                if ($state['bound']) {
                    return $this->err('conflict', 'This site is already bound to ' . $state['contract']);
                }
                if ($state['problems'] !== []) {
                    return $this->inspectAnswer($state);
                }
                $this->contract->bind($state);
                $drift = $state['drift'] ?? [];
                return $this->ok(['bound' => true, 'contract' => $state['contract'], 'revision' => $state['revision'], 'ids' => $state['ids']]
                    + ($drift === [] ? [] : ['warnings' => self::driftWarnings($drift)]));
            }
            if (strpos($operation, 'demoTrim.') === 0) {
                return $this->demoTrim($p, substr($operation, strlen('demoTrim.')));
            }
            if (strpos($operation, 'sourceLanguage.') === 0) {
                return $this->sourceLanguage($p, substr($operation, strlen('sourceLanguage.')));
            }
            if (strpos($operation, 'siteLanguage.') === 0) {
                return $this->siteLanguage($p, substr($operation, strlen('siteLanguage.')));
            }
            if (strpos($operation, 'multilingual.') === 0) {
                return $this->multilingual($p, substr($operation, strlen('multilingual.')));
            }
            if ($operation !== 'apply') {
                return $this->err('bad_params', 'unknown contract operation: ' . $operation);
            }
            return $this->contractApply($p);
        } catch (ContractUnavailable $e) {
            return $this->err('contract_unavailable', $e->getMessage());
        } catch (ContractProblems $e) {
            $answer = $this->err('contract_failed', $e->getMessage());
            if ($e->drift() !== []) {
                $answer['problems'] = $e->drift();
            }
            $answer['errors'] = $e->errors();
            return $answer;
        } catch (ContractProblem $e) {
            return $this->err('contract_failed', $e->getMessage()) + ['errors' => [$e->toArray()]];
        } catch (Throwable $e) {
            return $this->err('contract_failed', $e->getMessage());
        }
    }

    /** The public shape of an inspect: the state without its row images, or a refusal naming every problem. */
    private function inspectAnswer(array $state): array
    {
        unset($state['rows']);
        $drift = $state['drift'] ?? [];
        unset($state['drift']);
        if ($drift !== []) {
            $state['warnings'] = self::driftWarnings($drift);
        }
        if ($state['problems'] !== []) {
            return array_merge(
                ['ok' => false, 'error' => 'contract_failed', 'message' => implode('; ', $state['problems'])],
                ['bound' => $state['bound'], 'contract' => $state['contract'], 'problems' => $state['problems']]
            );
        }
        return $this->ok($state);
    }

    /**
     * Slot values onto a bound site: checked whole, written per row, proven, then receipted.
     *
     * The receipt is what makes a retry safe: the same request_id with the same content answers
     * the stored result, and a different request under an apply_id already used is refused, so
     * an agent that lost a reply can ask again without writing twice. One apply_id per revision.
     */
    /**
     * Where the site differs from its quickstart's design, as warnings (Tracy ADR 0022).
     * @param string[] $drift
     * @return list<array{code:string,message:string,severity:string}>
     */
    private static function driftWarnings(array $drift): array
    {
        return array_map(static fn(string $m) => ['code' => ContractProblem::PRESENTATION_DRIFT, 'message' => $m, 'severity' => 'warning'], array_values($drift));
    }

    private function contractApply(array $p): array
    {
        $apply = $this->applyId($p);
        $request = $p['request_id'] ?? '';
        if ($apply === null) {
            throw new RuntimeException('apply_id required; it must start with "contract-"');
        }
        if (strpos($apply, 'contract-') !== 0) {
            throw new RuntimeException('apply_id must start with "contract-", got "' . substr($apply, 0, 60) . '"');
        }
        if (!is_string($request) || !preg_match(self::REQUEST_ID_SHAPE, $request)) {
            throw new RuntimeException('request_id required: any string, the same on a retry and new for a different change');
        }
        if (!$this->contract->bound()) {
            throw new RuntimeException('Bind this site to its contract before applying content');
        }
        $hash = hash('sha256', (string) json_encode([$p['changes'] ?? null, $p['evidence'] ?? []]));
        $entries = $this->log->entries($apply);
        foreach ($entries as $entry) {
            if (($entry['op'] ?? '') === 'contract' && ($entry['request'] ?? null) === $request) {
                if (!hash_equals((string) ($entry['hash'] ?? ''), $hash)) {
                    throw new RuntimeException('request_id reused with different content');
                }
                $this->contract->inspectClean();
                return $entry['result'];
            }
        }
        if ($entries !== []) {
            throw new RuntimeException('Use one new apply_id per content revision');
        }

        $plan = $this->contract->plan($p);
        // What already differs from the design is a warning on this answer; what this write would
        // add to it is refused below (Tracy ADR 0022).
        $tolerated = $plan['state']['drift'] ?? [];
        $warn = $tolerated === [] ? [] : ['warnings' => self::driftWarnings($tolerated)];
        if ($plan['operations'] === []) {
            return $this->ok(['unchanged' => true, 'revision' => $plan['state']['revision']] + $warn);
        }

        // Written in order, each step logged before the next; on any failure every step already
        // taken is put back and the log cleared, so the site is exactly as it was and the apply_id
        // can be used again. WordPress has no transaction to lean on here.
        $done = [];
        $written = [];
        try {
            foreach ($plan['operations'] as $op) {
                if ($op['kind'] === 'optionTranslation') {
                    // One language's words for a translated option (`<locale>::site.tagline`): the
                    // option row stays, only that language's string translation moves — keyed by
                    // the row's value, the profile's demo value and the new value, as below.
                    $row = $this->writer->read('option', 0, $op['key']);
                    $originals = $this->translatedOptionOriginals($op, $row);
                    $translations = QuickstartContract::stringTranslationsBefore($originals, [$op['locale']]);
                    QuickstartContract::stringTranslationsWrite($originals, (string) $op['fields']['value'], [$op['locale']]);
                    $done[] = ['optionTranslation', 0, $op['key'], null, $translations];
                    $this->log->record($apply, ['op' => 'content', 'kind' => 'optionTranslation', 'id' => 0, 'key' => $op['key'], 'locale' => $op['locale'], 'before' => null, 'translations' => $translations]);
                    $written[] = ['kind' => 'optionTranslation', 'id' => 0, 'key' => $op['key'], 'locale' => $op['locale']];
                    continue;
                }
                $before = $this->writer->read($op['kind'], $op['id'], $op['key']);
                // Polylang serves blogname/blogdescription from its per-language STRING
                // translations, not the option row: read those before the write (Polylang's own
                // option hook may re-key them during it), write the same words into every
                // language after, and keep the before-values in the undo entry.
                $originals = $this->translatedOptionOriginals($op, $before);
                $translations = QuickstartContract::stringTranslationsBefore($originals);
                $id = $this->writer->write($op['kind'], $op['id'], $op['fields'], $op['key']);
                $done[] = [$op['kind'], $id, $op['key'], $before, $translations];
                if ($originals !== []) {
                    QuickstartContract::stringTranslationsWrite($originals, (string) $op['fields']['value']);
                }
                $entry = ['op' => 'content', 'kind' => $op['kind'], 'id' => $id, 'key' => $op['key'], 'before' => $before];
                if ($translations !== []) {
                    $entry['translations'] = $translations;
                }
                $this->log->record($apply, $entry);
                $written[] = ['kind' => $op['kind'], 'id' => $id, 'key' => $op['key'] === '' ? null : $op['key']];
            }
            $state = $this->contract->inspectClean(null, $tolerated);
            $this->contract->rebind($state);
        } catch (Throwable $e) {
            foreach (array_reverse($done) as [$kind, $id, $key, $before, $translations]) {
                try {
                    if ($kind !== 'optionTranslation') {
                        $this->rollbackContent($kind, $id, $key, $before);
                    }
                    QuickstartContract::stringTranslationsRestore($translations);
                } catch (Throwable $ignored) {
                    // Keep going: every other step still deserves its undo.
                }
            }
            try {
                $this->log->clear($apply);
            } catch (Throwable $ignored) {
            }
            throw new RuntimeException('Nothing was applied: ' . $e->getMessage());
        }

        try {
            $this->writer->purgeCache();
        } catch (Throwable $ignored) {
        }
        $result = $this->ok(['apply_id' => $apply, 'request_id' => $request, 'written' => $written, 'revision' => $state['revision']] + $warn);
        // The revision `content.read` now lists for every content this apply touched, read by the
        // reader itself after the write, so the next apply can hold exactly these. Left out when
        // the reader cannot answer on this site (no content identity yet): the write stands.
        $revisions = [];
        foreach ($this->contract->revisionsOf(array_map(static fn(array $w) => ['kind' => $w['kind'], 'id' => (int) $w['id'], 'key' => (string) ($w['key'] ?? '')], $written)) ?? [] as $found) {
            if ($found !== null) {
                $revisions[$found['id']] = $found['revision'];
            }
        }
        if ($revisions !== []) {
            $result['contentRevisions'] = $revisions;
        }
        $this->log->record($apply, ['op' => 'contract', 'request' => $request, 'hash' => $hash, 'result' => $result, 'afterRevision' => $state['revision']]);
        $this->stamped('content');
        return $result;
    }

    /**
     * `apply.revert` on a sealed site: only a content-contract apply, only the latest one, and
     * the site must pass its inspect afterwards.
     */
    private function contractRevert(array $p): array
    {
        if ($this->log === null || $this->contract === null) {
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
        $receipts = [];
        foreach ($entries as $entry) {
            if (($entry['op'] ?? '') === 'contract') {
                $receipts[] = $entry;
            }
        }
        if (count($receipts) !== 1) {
            return $this->err('bad_params', 'Only content-contract applies can be reverted through the contract; demo trims, source relabels, retired editions and the site language have their own way back (demoTrim.revert, sourceLanguage.revert, multilingual.restore, siteLanguage.revert)');
        }
        try {
            $state = $this->contract->inspect();
            if ($state['problems'] !== []) {
                throw new RuntimeException(implode('; ', $state['problems']));
            }
            $tolerated = $state['drift'];
            if (($receipts[0]['afterRevision'] ?? null) !== $state['revision']) {
                return $this->err('conflict', 'Later content exists; revert the latest revision first');
            }
            $result = $this->applyRevert($p);
            if (!$result['ok'] || !empty($result['failed'])) {
                throw new RuntimeException('Contract revert failed: ' . json_encode($result['failed'] ?? $result));
            }
            $state = $this->contract->inspectClean(null, $tolerated);
            $this->contract->rebind($state);
            $result['revision'] = $state['revision'];
            return $result;
        } catch (ContractUnavailable $e) {
            return $this->err('contract_unavailable', $e->getMessage());
        } catch (Throwable $e) {
            return $this->err('contract_failed', $e->getMessage());
        }
    }

    /**
     * Hiding the vendor's demo posts, and showing them again.
     *
     * What may be hidden is decided in review, in the profile's `demo-trim-map.json`; a request
     * names no rows, so it cannot hide anything the review did not list. A row the customer has
     * already moved somewhere else is skipped and reported, never forced.
     */
    private function demoTrim(array $p, string $operation): array
    {
        if (!$this->contract->bound()) {
            return $this->err('contract_failed', 'A demo trim needs a bound site');
        }
        if (!$this->contract->demoTrimAvailable()) {
            return $this->err('unsupported', 'This quickstart contract carries no demo-trim profile; its demo rows cannot be hidden by this plugin');
        }
        $profile = $this->contract->demoTrim();
        if ($operation === 'plan') {
            $state = $this->contract->inspectClean();
            $rows = [];
            foreach ($profile->rows() as $row) {
                $current = $this->writer->read('post', $row['id']);
                $status = $current === null ? null : (string) ($current['post_status'] ?? '');
                $rows[] = [
                    'key' => $row['key'], 'kind' => $row['kind'], 'id' => $row['id'], 'language' => $row['language'],
                    'from' => $row['from'], 'to' => $row['to'], 'current' => $status,
                    'state' => $status === null ? 'missing' : ($status === $row['to'] ? 'hidden' : ($status === $row['from'] ? 'pending' : 'edited')),
                ];
            }
            return $this->ok([
                'hides' => $profile->counts(), 'rows' => $rows,
                'status' => $state['demoTrim']['status'] ?? 'none',
                'languages' => QuickstartContract::languages(), 'editions' => $this->contract->editionLanguages(),
                'profileVersion' => $profile->version(), 'profileHash' => $profile->hash(),
            ]);
        }
        if ($operation !== 'apply' && $operation !== 'revert') {
            return $this->err('bad_params', 'Unknown demoTrim operation: ' . $operation);
        }
        $apply = $this->applyId($p);
        $request = $p['request_id'] ?? '';
        if ($apply === null || strpos($apply, 'dtrim-') !== 0) {
            return $this->err('bad_params', 'apply_id must start with "dtrim-"');
        }
        if (!is_string($request) || !preg_match(self::REQUEST_ID_SHAPE, $request)) {
            return $this->err('bad_params', 'request_id required: any string, the same on a retry and new for a different change');
        }
        // A language this contract ships no edition for has copies of the demo this profile does
        // not list; hiding the source alone would leave them showing. Say so, write nothing.
        $foreign = array_diff(QuickstartContract::languages(), $this->contract->editionLanguages());
        if ($foreign !== []) {
            return $this->err('contract_failed', 'This site has a language this contract carries no edition for: ' . implode(', ', $foreign) . '. Nothing has been written.');
        }
        $state = $this->contract->inspectClean();
        $trim = $state['demoTrim'];
        $hide = $operation === 'apply';
        if ($hide) {
            if (($trim['status'] ?? null) === 'complete') {
                return $this->ok(['status' => 'completed', 'remaining' => 0, 'moved' => 0, 'alreadyTrimmed' => true, 'applyId' => $trim['applyId'] ?? null]);
            }
            if ($trim !== null && (($trim['status'] ?? '') !== 'applying' || ($trim['requestId'] ?? '') !== $request)) {
                return $this->err('conflict', 'A demo trim is already ' . (string) ($trim['status'] ?? '?') . ' under request ' . (string) ($trim['requestId'] ?? '?'));
            }
        } else {
            if ($trim === null) {
                return $this->err('contract_failed', 'This site has no hidden demo rows to bring back');
            }
            if (($trim['status'] ?? '') === 'reverting' && ($trim['requestId'] ?? '') !== $request) {
                return $this->err('conflict', 'A demo trim revert is already running under request ' . (string) ($trim['requestId'] ?? '?'));
            }
        }
        $record = [
            'status' => $hide ? 'applying' : 'reverting', 'applyId' => $apply, 'requestId' => $request,
            'profileHash' => $profile->hash(), 'profileVersion' => $profile->version(), 'at' => gmdate('c'),
        ];
        if ($hide) {
            $record['hidden'] = (int) ($trim['hidden'] ?? 0);
            $record['skipped'] = is_array($trim['skipped'] ?? null) ? $trim['skipped'] : [];
        }
        // The record lands BEFORE any row moves: a call that dies mid-batch leaves a site that
        // says a trim is in flight, so its retry is welcome rather than refused as drift.
        if ($trim === null || ($trim['status'] ?? '') !== $record['status'] || ($trim['requestId'] ?? '') !== $request) {
            $this->contract->record('demoTrim', $record);
        } else {
            $record = $trim;
        }

        $moved = 0;
        $pending = 0;
        $skipped = [];
        // A row of a retired edition is `multilingual.retire`'s to hide and `multilingual.restore`'s
        // to bring back; a trim neither moves it nor records it, and says so.
        $retired = QuickstartContract::retiredLanguages($this->contract->binding());
        foreach ($profile->rows() as $row) {
            $want = $hide ? $row['to'] : $row['from'];
            $other = $hide ? $row['from'] : $row['to'];
            $current = $this->writer->read('post', $row['id']);
            $status = $current === null ? null : (string) ($current['post_status'] ?? '');
            if (in_array($row['language'], $retired, true)) {
                $skipped[] = ['key' => $row['key'], 'id' => $row['id'], 'status' => $status, 'retired' => true];
                continue;
            }
            if ($status === $want) {
                continue;
            }
            if ($status !== $other) {
                // Customer-edited (or gone): not this profile's row any more. Reported, not forced.
                $skipped[] = ['key' => $row['key'], 'id' => $row['id'], 'status' => $status];
                continue;
            }
            if ($moved >= self::DEMO_TRIM_BATCH) {
                $pending++;
                continue;
            }
            QuickstartContract::setPostStatus($row['id'], $want);
            $this->log->record($apply, ['op' => 'visibility', 'kind' => $row['kind'], 'id' => $row['id'], 'column' => 'post_status', 'before' => $status]);
            $moved++;
        }
        if ($pending === 0) {
            if ($hide) {
                $record['status'] = 'complete';
                $record['hidden'] = (int) $record['hidden'] + $moved;
                $record['skipped'] = $skipped;
                $record['completedAt'] = gmdate('c');
                $this->contract->record('demoTrim', $record);
            } else {
                $this->contract->record('demoTrim', null);
            }
        } elseif ($hide) {
            $record['hidden'] = (int) $record['hidden'] + $moved;
            $this->contract->record('demoTrim', $record);
        }
        // Proven after the move: a governed page that is also a demo row is allowed at either end,
        // and anything else that changed is not this operation's doing — but it is still drift.
        $this->contract->rebind($this->contract->inspectClean());
        try {
            $this->writer->purgeCache();
        } catch (Throwable $ignored) {
        }
        if ($moved > 0) {
            $this->stamped('content');
        }
        return $this->ok([
            'status' => $pending === 0 ? ($hide ? 'completed' : 'reverted') : 'running',
            'moved' => $moved, 'remaining' => $pending, 'skipped' => $skipped, 'applyId' => $apply,
        ]);
    }

    /**
     * Respell the source edition's locale — `en_US` to `en_GB`, `en_AU`, `en_CA` or `en_NZ` and
     * back. Same words, another spelling and date format; nothing translated, no second edition.
     * Polylang holds the language's locale and WordPress holds `WPLANG`; both move together.
     */
    private function sourceLanguage(array $p, string $operation): array
    {
        if (QuickstartContract::polylang() === null) {
            return $this->err('unavailable', 'this site has no translation plugin, so its source language has no locale to respell');
        }
        $language = QuickstartContract::language(QuickstartContract::SOURCE_LANGUAGE);
        if ($language === null) {
            return $this->err('contract_failed', 'This site has no Polylang language ' . QuickstartContract::SOURCE_LANGUAGE);
        }
        $current = $language['locale'];
        $locale = isset($p['locale']) && is_string($p['locale']) ? trim($p['locale']) : '';
        $binding = $this->contract->binding();
        $onRecord = isset($binding['sourceLanguage']) && is_array($binding['sourceLanguage']) ? $binding['sourceLanguage'] : null;
        if ($operation === 'plan') {
            if ($locale !== '' && !in_array($locale, QuickstartContract::SOURCE_VARIANTS, true)) {
                return $this->err('bad_params', $locale . ' is not a variant of ' . QuickstartContract::SOURCE_LOCALE . '; the source edition can only be respelled as one of ' . implode(', ', QuickstartContract::SOURCE_VARIANTS));
            }
            return $this->ok([
                'language' => QuickstartContract::SOURCE_LANGUAGE, 'published' => QuickstartContract::SOURCE_LOCALE,
                'current' => $current, 'locale' => $locale === '' ? null : $locale,
                'wplang' => (string) (($this->writer->read('option', 0, 'WPLANG') ?? ['value' => ''])['value']),
                'variants' => QuickstartContract::SOURCE_VARIANTS, 'onRecord' => $onRecord,
                'noop' => $locale !== '' && $locale === $current,
            ]);
        }
        if ($operation !== 'set' && $operation !== 'revert') {
            return $this->err('bad_params', 'Unknown sourceLanguage operation: ' . $operation);
        }
        $apply = $this->applyId($p);
        $request = $p['request_id'] ?? '';
        if ($apply === null || strpos($apply, 'srclang-') !== 0) {
            return $this->err('bad_params', 'apply_id must start with "srclang-"');
        }
        if (!is_string($request) || !preg_match(self::REQUEST_ID_SHAPE, $request)) {
            return $this->err('bad_params', 'request_id required: any string, the same on a retry and new for a different change');
        }
        if (!$this->contract->bound()) {
            return $this->err('contract_failed', 'A source relabel needs a bound site');
        }
        if ($operation === 'revert') {
            if ($onRecord === null) {
                return $this->err('contract_failed', 'This site\'s source edition still carries its published locale; there is nothing to take back');
            }
            // WPLANG goes back to what it WAS, not to the locale's name: a site shipped with the
            // option absent (WordPress's own spelling of en_US) gets it absent again.
            $this->relabelSource($apply, (string) $onRecord['from'], $onRecord['wplang'] ?? null);
            $this->contract->record('sourceLanguage', null);
            return $this->ok(['status' => 'reverted', 'locale' => (string) $onRecord['from']]);
        }
        if (!in_array($locale, QuickstartContract::SOURCE_VARIANTS, true)) {
            return $this->err('bad_params', ($locale === '' ? 'A locale' : $locale) . ' is not a variant of ' . QuickstartContract::SOURCE_LOCALE . '; the source edition can only be respelled as one of ' . implode(', ', QuickstartContract::SOURCE_VARIANTS));
        }
        if ($locale === $current) {
            return $this->ok(['status' => 'completed', 'locale' => $locale, 'alreadySet' => true]);
        }
        if ($onRecord !== null) {
            return $this->err('conflict', 'This site\'s source edition is already respelled as ' . (string) $onRecord['to'] . '; take that back with sourceLanguage.revert before choosing another');
        }
        $wplang = $this->relabelSource($apply, $locale, ['value' => $locale]);
        $this->contract->record('sourceLanguage', [
            'from' => $current, 'to' => $locale, 'wplang' => $wplang,
            'applyId' => $apply, 'requestId' => $request, 'at' => gmdate('c'),
        ]);
        return $this->ok(['status' => 'completed', 'locale' => $locale, 'from' => $current]);
    }

    /**
     * Which edition is the site's DEFAULT language: the one Polylang serves at `/` (the others
     * live under `/<slug>/` when `force_lang` is on, and stay there — the URL shape is
     * Polylang's, this door only moves the default), the locale WordPress itself speaks
     * (`WPLANG`), and the front and posts pages shown at the root, moved to the chosen edition's
     * copies when Polylang's translation groups name them. Nothing is translated and no row
     * moves: a customer who asked for a Vietnamese site gets the Vietnamese edition first.
     *
     * Five values, one undo entry, one record on the binding. `revert` puts all five back as
     * they were — `WPLANG` absent again when it was absent — and takes the record off.
     *
     * The fifth is Polylang's `hide_default`: the archive is captured with it on, so the source
     * edition lives at `/` and every other under `/<slug>/`. Making another edition the default
     * with it still on moves that edition to `/` and the source edition to `/<source>/` — and
     * every link the archive baked into its navigation blocks (`/about/`, `/vi/news/`) answers
     * 404. So a non-source default turns it off: every edition keeps its prefix, `/` is
     * Polylang's default-language home, and the baked links keep working. `force_lang` stays.
     */
    private function siteLanguage(array $p, string $operation): array
    {
        if (QuickstartContract::polylang() === null) {
            return $this->err('unavailable', 'this site has no translation plugin, so it has no editions to choose a default from');
        }
        if (!$this->contract->bound()) {
            return $this->err('contract_failed', 'Choosing the site language needs a bound site');
        }
        if ($this->contract->editions() === null) {
            return $this->err('unsupported', 'This quickstart contract carries no editions profile; it has no editions to choose a default from');
        }
        $binding = $this->contract->binding();
        $onRecord = isset($binding['siteLanguage']) && is_array($binding['siteLanguage']) ? $binding['siteLanguage'] : null;
        $settings = self::polylangSettings();
        $current = isset($settings['default_lang']) && is_string($settings['default_lang']) && $settings['default_lang'] !== '' ? $settings['default_lang'] : null;
        if ($operation === 'plan') {
            return $this->ok(['current' => $current, 'editions' => $this->contract->editionLanguages(), 'onRecord' => $onRecord]);
        }
        if ($operation !== 'set' && $operation !== 'revert') {
            return $this->err('bad_params', 'Unknown siteLanguage operation: ' . $operation);
        }
        $apply = $this->applyId($p);
        $request = $p['request_id'] ?? '';
        if ($apply === null || strpos($apply, 'slang-') !== 0) {
            return $this->err('bad_params', 'apply_id must start with "slang-"');
        }
        if ($operation === 'revert') {
            if ($onRecord === null) {
                return $this->err('contract_failed', 'This site\'s default language was not set by this door; there is nothing to take back');
            }
            $was = [
                'default_lang' => $onRecord['from'] ?? null, 'WPLANG' => $onRecord['wplangFrom'] ?? null,
                'page_on_front' => $onRecord['pageOnFrontFrom'] ?? null, 'page_for_posts' => $onRecord['pageForPostsFrom'] ?? null,
            ];
            // A record written before hide_default was part of this step leaves it as it is.
            if (array_key_exists('hideDefaultFrom', $onRecord)) {
                $was['hide_default'] = $onRecord['hideDefaultFrom'];
            }
            $this->restoreSiteLanguage($was);
            $this->log->clear((string) ($onRecord['applyId'] ?? $apply));
            $this->contract->record('siteLanguage', null);
            $this->siteLanguageSettled();
            return $this->ok(['status' => 'reverted', 'language' => (string) ($onRecord['from'] ?? '')]);
        }
        if (!is_string($request) || !preg_match(self::REQUEST_ID_SHAPE, $request)) {
            return $this->err('bad_params', 'request_id required: any string, the same on a retry and new for a different change');
        }
        $tag = isset($p['language']) && is_string($p['language']) ? trim($p['language']) : '';
        if ($tag === '' || !preg_match('/^[a-zA-Z]{2,3}(-[a-zA-Z0-9]{2,8})*$/D', $tag)) {
            return $this->err('bad_params', 'language names the edition to make the default, as a tag like en-us, vi, de-de or its Polylang slug');
        }
        // The same matching a retire's keep list gets: exact tag or slug, else the primary
        // subtag; a tag naming nothing is named back, never guessed at.
        $named = $this->contract->editionsKept([$tag]);
        if ($named['kept'] === []) {
            return $this->err('bad_params', 'This archive ships no edition of: ' . implode(', ', $named['unknown'] === [] ? [$tag] : $named['unknown']) . '. Nothing has been written.');
        }
        $slug = (string) $named['kept'][0];
        if ($slug === $current) {
            return $this->ok(['status' => 'completed', 'language' => $slug, 'alreadySet' => true]);
        }
        if ($onRecord !== null) {
            if ((string) ($onRecord['applyId'] ?? '') === $apply && (string) ($onRecord['language'] ?? '') === $slug) {
                return $this->ok(['status' => 'completed', 'language' => $slug, 'alreadySet' => true]);
            }
            return $this->err('conflict', 'This site\'s default language is already set to ' . (string) ($onRecord['language'] ?? '?') . ' under ' . (string) ($onRecord['applyId'] ?? '?') . '; take that back with siteLanguage.revert before choosing another');
        }
        if (QuickstartContract::language($slug) === null) {
            return $this->err('contract_failed', 'This site has no Polylang language ' . $slug . '; the edition is in the archive but not on the site');
        }
        $edition = (array) ($this->contract->editions()['locales'][$slug] ?? []);
        $wplang = isset($edition['wpLocale']) && is_string($edition['wpLocale']) && $edition['wpLocale'] !== '' ? $edition['wpLocale'] : (string) ($edition['locale'] ?? '');

        $before = [
            'default_lang' => $current,
            'WPLANG' => get_option('WPLANG', null),
            'page_on_front' => get_option('page_on_front', null),
            'page_for_posts' => get_option('page_for_posts', null),
            'hide_default' => isset($settings['hide_default']) ? (int) $settings['hide_default'] : null,
        ];
        // The undo lands BEFORE anything moves: a write that dies halfway leaves a log entry
        // `apply.revert` can read on an unbound site, and is put back here on a sealed one.
        $this->log->record($apply, ['op' => 'siteLanguage', 'language' => $slug, 'before' => $before]);
        try {
            $settings['default_lang'] = $slug;
            if ($slug !== QuickstartContract::SOURCE_LANGUAGE) {
                $settings['hide_default'] = 0;
            }
            update_option('polylang', $settings);
            foreach (['page_on_front', 'page_for_posts'] as $key) {
                $copy = self::editionCopy((int) $before[$key], $slug);
                if ($copy !== null) {
                    update_option($key, $copy);
                }
            }
            if ($wplang !== '') {
                update_option('WPLANG', $wplang);
            }
        } catch (Throwable $e) {
            try {
                $this->restoreSiteLanguage($before);
                $this->log->clear($apply);
            } catch (Throwable $ignored) {
            }
            throw new RuntimeException('Nothing was applied: ' . $e->getMessage());
        }
        $this->contract->record('siteLanguage', [
            'language' => $slug, 'from' => $current, 'wplangFrom' => $before['WPLANG'],
            'pageOnFrontFrom' => $before['page_on_front'], 'pageForPostsFrom' => $before['page_for_posts'],
            'hideDefaultFrom' => $before['hide_default'],
            'applyId' => $apply, 'requestId' => $request, 'at' => gmdate('c'),
        ]);
        $this->siteLanguageSettled();
        return $this->ok([
            'status' => 'completed', 'language' => $slug, 'from' => $current, 'wplang' => $wplang === '' ? null : $wplang,
            'rewrite' => [
                'force_lang' => isset($settings['force_lang']) ? (int) $settings['force_lang'] : null,
                'hide_default' => isset($settings['hide_default']) ? (int) $settings['hide_default'] : null,
                'rewrite' => isset($settings['rewrite']) ? (int) $settings['rewrite'] : null,
            ],
        ]);
    }

    /** Polylang's settings as it stores them: the `polylang` option, an array or nothing. */
    private static function polylangSettings(): array
    {
        $settings = get_option('polylang', []);
        return is_array($settings) ? $settings : [];
    }

    /** The chosen edition's copy of a page, when Polylang's translation group names one; null when it does not, or the page is that copy already. */
    private static function editionCopy(int $id, string $slug): ?int
    {
        if ($id <= 0 || !function_exists('pll_get_post')) {
            return null;
        }
        $copy = pll_get_post($id, $slug);
        $copy = is_numeric($copy) ? (int) $copy : 0;
        return $copy > 0 && $copy !== $id ? $copy : null;
    }

    /**
     * The values of a site-language step, put back as recorded. `WPLANG` null means the option
     * was absent, and goes absent again; a page option is written as it was, `0` included;
     * `hide_default` goes back to the recorded value, absent again when it was absent, and is
     * left alone when the step (recorded before it was part of one) says nothing about it.
     *
     * @param array<string,mixed> $before
     */
    private function restoreSiteLanguage(array $before): void
    {
        $settings = self::polylangSettings();
        $default = $before['default_lang'] ?? null;
        if (is_string($default) && $default !== '') {
            $settings['default_lang'] = $default;
        } else {
            unset($settings['default_lang']);
        }
        if (array_key_exists('hide_default', $before)) {
            if ($before['hide_default'] === null) {
                unset($settings['hide_default']);
            } else {
                $settings['hide_default'] = (int) $before['hide_default'];
            }
        }
        update_option('polylang', $settings);
        foreach (['page_on_front', 'page_for_posts'] as $key) {
            if (array_key_exists($key, $before) && $before[$key] !== null) {
                update_option($key, $before[$key]);
            }
        }
        if (($before['WPLANG'] ?? null) === null) {
            delete_option('WPLANG');
        } else {
            update_option('WPLANG', $before['WPLANG']);
        }
    }

    /** After the default language moved either way: Polylang's language cache, the rewrite rules, the page cache, the stamp. */
    private function siteLanguageSettled(): void
    {
        $model = QuickstartContract::polylang();
        if ($model !== null && method_exists($model, 'clean_languages_cache')) {
            $model->clean_languages_cache();
        }
        if (function_exists('flush_rewrite_rules')) {
            flush_rewrite_rules();
        }
        try {
            $this->writer->purgeCache();
        } catch (Throwable $ignored) {
        }
        $this->stamped('content');
    }

    /**
     * Which of the archive's editions are live. `retire` takes `keep` — the languages the
     * customer asked for, as the questionnaire spells them (`en-us`, `vi`, `de-de`) — and makes
     * that the live set: every other edition the archive ships is RETIRED (each of its published
     * pages, posts and navigation twins drafted, never deleted), and an edition an earlier call
     * under the same receipt retired comes back when it is kept again. The source edition is
     * always live. Batched: repeat the call until it answers `completed`. `restore` takes the
     * whole pass back by its receipt.
     *
     * Rows the customer added — no Polylang language, or one the archive ships no edition of —
     * are never touched; rows a demo trim already hid are left to the trim. Every move is a
     * `visibility` undo under the `mlang-` apply id; on a sealed site `apply.revert` takes
     * contract receipts only, so `restore` is the way back.
     */
    private function multilingual(array $p, string $operation): array
    {
        if ($operation !== 'retire' && $operation !== 'restore') {
            return $this->err('bad_params', 'Unknown multilingual operation: ' . $operation);
        }
        $apply = $this->applyId($p);
        if ($apply === null || strpos($apply, 'mlang-') !== 0) {
            return $this->err('bad_params', 'apply_id must start with "mlang-"');
        }
        if (QuickstartContract::polylang() === null) {
            return $this->err('unavailable', 'this site has no translation plugin, so it has no editions to retire');
        }
        if (!$this->contract->bound()) {
            return $this->err('contract_failed', 'Retiring an edition needs a bound site');
        }
        if ($this->contract->editions() === null) {
            return $this->err('unsupported', 'This quickstart contract carries no editions profile; it has no editions to retire');
        }
        $binding = $this->contract->binding();
        $onRecord = isset($binding['multilingual']) && is_array($binding['multilingual']) ? $binding['multilingual'] : null;

        if ($operation === 'restore') {
            if ($onRecord === null) {
                return $this->err('contract_failed', 'This site has no retired editions to bring back');
            }
            if ((string) ($onRecord['applyId'] ?? '') !== $apply) {
                return $this->err('conflict', 'The retired editions are on record under ' . (string) ($onRecord['applyId'] ?? '?') . ', not ' . $apply);
            }
            $restored = 0;
            foreach (array_reverse($this->log->entries($apply)) as $entry) {
                if (($entry['op'] ?? '') !== 'visibility') {
                    continue;
                }
                $this->revertOne($entry);
                $restored++;
            }
            $this->log->clear($apply);
            $this->contract->record('multilingual', null);
            try {
                $this->writer->purgeCache();
            } catch (Throwable $ignored) {
            }
            if ($restored > 0) {
                $this->stamped('revert');
            }
            return $this->ok(['restored' => $restored, 'applyId' => $apply]);
        }

        $request = $p['request_id'] ?? '';
        if (!is_string($request) || !preg_match(self::REQUEST_ID_SHAPE, $request)) {
            return $this->err('bad_params', 'request_id required: any string, the same on a retry and new for a different change');
        }
        $keep = $p['keep'] ?? null;
        if (!is_array($keep)) {
            return $this->err('bad_params', 'keep must list the languages the site keeps, as tags like en-us, vi, de-de');
        }
        foreach ($keep as $tag) {
            if (!is_string($tag) || !preg_match('/^[a-zA-Z]{2,3}(-[a-zA-Z0-9]{2,8})*$/D', trim($tag))) {
                return $this->err('bad_params', 'keep holds language tags like en-us, vi, de-de');
            }
        }
        $named = $this->contract->editionsKept($keep);
        if ($named['unknown'] !== []) {
            return $this->err('bad_params', 'This archive ships no edition of: ' . implode(', ', $named['unknown']) . '. Nothing has been written.');
        }
        if ($onRecord !== null && (string) ($onRecord['applyId'] ?? '') !== $apply) {
            return $this->err('conflict', 'Editions are already retired under ' . (string) ($onRecord['applyId'] ?? '?') . '; bring them back with multilingual.restore before retiring under another apply_id');
        }
        $source = $this->contract->sourceLanguage();
        $kept = array_flip($named['kept']);
        $kept[$source] = true;
        $retired = [];
        $live = [];
        foreach ($this->contract->editionLanguages() as $slug) {
            if (isset($kept[$slug])) {
                $live[] = $slug;
            } else {
                $retired[] = $slug;
            }
        }
        // The record lands BEFORE any row moves, so a call that dies mid-batch leaves a site
        // that says which set it was moving toward, and its retry is welcome.
        $record = ['status' => 'running', 'applyId' => $apply, 'requestId' => $request, 'retired' => $retired, 'live' => $live, 'at' => gmdate('c')];
        $this->contract->record('multilingual', $record);

        $budget = self::MULTILINGUAL_BATCH;
        $moved = 0;
        $restored = 0;
        $remaining = 0;

        // 1. Editions kept again: put back the before-image of each row this receipt hid, newest
        //    first, and drop those steps from the log — what stays is exactly what is still hidden.
        $entries = $this->log->entries($apply);
        $restorable = [];
        foreach ($entries as $index => $entry) {
            if (($entry['op'] ?? '') === 'visibility' && isset($kept[(string) ($entry['language'] ?? '')])) {
                $restorable[] = $index;
            }
        }
        $dropped = [];
        foreach (array_reverse($restorable) as $index) {
            if ($budget <= 0) {
                $remaining++;
                continue;
            }
            $this->revertOne($entries[$index]);
            $dropped[$index] = true;
            $restored++;
            $budget--;
        }
        if ($dropped !== []) {
            $this->log->clear($apply);
            foreach ($entries as $index => $entry) {
                if (!isset($dropped[$index])) {
                    $this->log->record($apply, $entry);
                }
            }
        }

        // 2. Editions retired: draft every published row that is still standing.
        foreach ($this->retirableRows($retired, $source) as [$kind, $id, $language]) {
            if ($budget <= 0) {
                $remaining++;
                continue;
            }
            // Undo first, then the change: a row whose undo could not be recorded is never hidden.
            $this->log->record($apply, ['op' => 'visibility', 'kind' => $kind, 'id' => $id, 'column' => 'post_status', 'before' => 'publish', 'language' => $language]);
            QuickstartContract::setPostStatus($id, 'draft');
            $moved++;
            $budget--;
        }

        if ($remaining === 0) {
            if ($retired === [] && $this->log->entries($apply) === []) {
                // Everything is live again and nothing is hidden: the same end as a restore.
                $this->contract->record('multilingual', null);
            } else {
                $record['status'] = 'complete';
                $record['completedAt'] = gmdate('c');
                $this->contract->record('multilingual', $record);
            }
        }
        try {
            $this->writer->purgeCache();
        } catch (Throwable $ignored) {
        }
        if ($moved + $restored > 0) {
            $this->stamped('content');
        }
        return $this->ok([
            'status' => $remaining === 0 ? 'completed' : 'running',
            'moved' => $moved, 'restored' => $restored, 'remaining' => $remaining,
            'retired' => $retired, 'live' => $live, 'applyId' => $apply,
        ]);
    }

    /**
     * Every published page, post and navigation row of the retired editions, in profile order.
     *
     * A page or post belongs to the edition Polylang says it does. A `wp_navigation` row carries
     * no Polylang language (measured on a live Tracy Business site, 25/09/2026: 0 of 27); the
     * theme serves the twin whose slug is the source menu's plus `-<slug>` (`tracy` → `tracy-de`),
     * so that is how a twin is attributed here — and only when the menu it twins exists, so a
     * customer's own `careers-de` navigation with no `careers` beside it is never taken for one.
     *
     * @param string[] $retired
     * @return array<int,array{0:string,1:int,2:string}> [post type, id, edition slug]
     */
    private function retirableRows(array $retired, string $source): array
    {
        if ($retired === [] || !function_exists('get_posts')) {
            return [];
        }
        $byEdition = [];
        foreach ($retired as $slug) {
            $byEdition[$slug] = [];
        }
        $navigationSlugs = [];
        foreach (self::EDITION_POST_TYPES as $type) {
            $posts = get_posts([
                'post_type' => $type,
                'post_status' => 'publish',
                'numberposts' => -1,
                'no_found_rows' => true,
                'suppress_filters' => true,
                // Every language, not the current one: without this Polylang answers only the
                // request's language (14 pages instead of 574, measured 25/09/2026).
                'lang' => '',
                'orderby' => 'ID',
                'order' => 'ASC',
            ]);
            $rows = [];
            foreach ((array) $posts as $post) {
                $id = (int) (is_object($post) ? $post->ID : ($post['ID'] ?? 0));
                $status = (string) (is_object($post) ? $post->post_status : ($post['post_status'] ?? ''));
                $name = (string) (is_object($post) ? $post->post_name : ($post['post_name'] ?? ''));
                if ($id <= 0 || $status !== 'publish') {
                    continue;
                }
                $rows[$id] = $name;
                if ($type === 'wp_navigation') {
                    $navigationSlugs[$name] = true;
                }
            }
            foreach ($rows as $id => $name) {
                $language = QuickstartContract::postLanguage($id);
                if ($language === null && $type === 'wp_navigation') {
                    $language = self::navigationEdition($name, $retired, $navigationSlugs);
                }
                if ($language !== null && $language !== $source && isset($byEdition[$language])) {
                    $byEdition[$language][] = [$type, $id, $language];
                }
            }
        }
        $out = [];
        foreach ($byEdition as $rows) {
            foreach ($rows as $row) {
                $out[] = $row;
            }
        }
        return $out;
    }

    /** The retired edition a navigation twin's slug names (`tracy-pt-pt` → `pt-pt`), longest slug first; null when none. */
    private static function navigationEdition(string $name, array $retired, array $navigationSlugs): ?string
    {
        $found = null;
        foreach ($retired as $slug) {
            $suffix = '-' . $slug;
            if (strlen($name) <= strlen($suffix) || substr($name, -strlen($suffix)) !== $suffix) {
                continue;
            }
            if (!isset($navigationSlugs[substr($name, 0, -strlen($suffix))])) {
                continue;
            }
            if ($found === null || strlen($slug) > strlen($found)) {
                $found = $slug;
            }
        }
        return $found;
    }

    /**
     * One relabel: Polylang's locale for the source language, and `WPLANG` set to `$wplang`
     * (null removes the option). Both halves logged, so `apply.revert` outside the seal could
     * still undo them. Returns the WPLANG before-image, for the record a revert reads.
     */
    private function relabelSource(string $apply, string $locale, ?array $wplang): ?array
    {
        $before = QuickstartContract::language(QuickstartContract::SOURCE_LANGUAGE);
        QuickstartContract::setLanguageLocale(QuickstartContract::SOURCE_LANGUAGE, $locale);
        $this->log->record($apply, ['op' => 'sourceLocale', 'slug' => QuickstartContract::SOURCE_LANGUAGE, 'before' => $before === null ? null : $before['locale']]);
        $was = $this->writer->read('option', 0, 'WPLANG');
        if ($wplang === null) {
            $this->writer->delete('option', 0, 'WPLANG');
        } else {
            $this->writer->write('option', 0, $wplang, 'WPLANG');
        }
        $this->log->record($apply, ['op' => 'content', 'kind' => 'option', 'id' => 0, 'key' => 'WPLANG', 'before' => $was]);
        try {
            $this->writer->purgeCache();
        } catch (Throwable $ignored) {
        }
        $this->stamped('content');
        return $was;
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
    /** Where a retired table goes: renamed, never dropped (ADR 0083). */
    private const TRASH_PREFIX = '_tracy_trash_';

    /**
     * Core suffixes no cleanup may touch, matched against the END of the table name so the
     * site's install-time prefix does not matter — `wp_`, `wp_2_` on a multisite, or whatever a
     * hardening plugin randomised it to.
     *
     * This list is WordPress's, not a copy of the Joomla one: the two CMSs share not a single
     * table name, and a list ported without reading would refuse `_menu` (which WordPress does
     * not have) while waving through `_postmeta` (which holds every page's SEO and builder data).
     *
     * Deny-side false positives are the safe direction. A third-party `acme_comments` wrongly
     * refused stays exactly where it is and somebody asks again; a core table wrongly renamed
     * takes the site down.
     */
    private const CORE_TABLE_SUFFIXES = [
        '_posts', '_postmeta', '_users', '_usermeta', '_options',
        '_terms', '_termmeta', '_term_taxonomy', '_term_relationships',
        '_comments', '_commentmeta', '_links',
        // Multisite's own furniture: present only on a network, fatal to lose when it is.
        '_blogs', '_site', '_sitemeta', '_signups', '_registration_log', '_blogmeta',
    ];

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

    /** The per-site core source (ADR 0070 addendum) — see Packages::core_manifest. */
    private function coreManifest(): array
    {
        if ($refusal = $this->packagesReady()) {
            return $refusal;
        }
        return $this->ok(['manifest' => $this->packages->core_manifest()]);
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

    /**
     * Take the newest announced version of this plugin now, instead of on WordPress's schedule.
     *
     * The schedule is right for a site nobody watches and wrong for the minutes after a release:
     * the manifest answer is cached six hours and the cron that acts on it runs twice a day, so a
     * fix can be published and still be half a day from the site it was written for.
     *
     * Takes no parameters on purpose. Which version to install is not a caller's decision — it is
     * whatever `update.json` announces, checked by the same filter that draws the notice on the
     * Plugins screen. This action only removes the waiting, so there is nothing to pass in and
     * nothing a caller could get wrong.
     */
    private function pluginSelfUpdate(): array
    {
        if ($refusal = $this->packagesReady()) {
            return $refusal;
        }
        $result = $this->packages->self_update();
        return ($result['ok'] ?? false) === true
            ? $this->ok([
                'updated' => (bool) ($result['updated'] ?? false),
                'before' => (string) ($result['before'] ?? ''),
                'after' => (string) ($result['after'] ?? ''),
            ])
            : $this->err('update_failed', (string) ($result['error'] ?? 'update failed'));
    }

    /**
     * Make this WordPress speak a language: fetch its core translation.
     *
     * 🔒 WITHOUT THIS VERB A TRANSLATED SITE IS HALF TRANSLATED. A site built through this door can
     * have its pages MARKED per language (`content.language`, Polylang), and nothing here could
     * make WordPress itself fetch `vi` — so a customer who asked for Vietnamese got Vietnamese
     * pages under an English admin, an English theme and English dates. On a machine that holds the
     * webroot the answer is one wp-cli call; through this door there was no answer at all, and the
     * seeder's only honest move was a refusal in words.
     *
     * The locale is WORDPRESS'S spelling — `pt_BR`, not `pt-br`. That is the one edge where the
     * product's tag and WordPress's locale meet, and the caller translates before it gets here so
     * this side never has to guess which convention it was handed.
     *
     * Idempotent on purpose: a locale already on disk answers ok with `already: true`. Seeding
     * reruns, and a rerun that fails on work already done is a rerun nobody can use.
     */
    private function languageInstall(array $p): array
    {
        if ($refusal = $this->packagesReady()) {
            return $refusal;
        }
        $locale = isset($p['locale']) && is_string($p['locale']) ? trim($p['locale']) : '';
        if ($locale === '') {
            return $this->err('bad_params', 'locale required, e.g. vi or pt_BR');
        }
        // Shape first, so a path or a wildcard never reaches WordPress's own list — the same
        // reason `theme.style` checks its id here rather than trusting the layer below.
        if (!preg_match('/^[a-z]{2,3}(_[A-Za-z0-9]+){0,2}$/', $locale)) {
            return $this->err('bad_params', 'locale must be a WordPress locale, e.g. vi, pt_BR, de_DE_formal');
        }
        $result = $this->packages->install_language($locale);
        return ($result['ok'] ?? false) === true
            ? $this->ok([
                'locale' => (string) ($result['locale'] ?? $locale),
                'already' => (bool) ($result['already'] ?? false),
            ])
            : $this->err('install_failed', (string) ($result['error'] ?? 'language install failed'));
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

    /**
     * Wear one of the active theme's style variations.
     *
     * A look and a style are two steps because they are two decisions: `theme.install` and
     * `theme.activate` put a theme on the site, and this chooses which of its variations the site
     * wears. Tracy's own theme ships 152 of them — one per design inspiration — so building a site
     * from an inspiration is exactly this call after those two.
     */
    private function themeStyle(array $p): array
    {
        if ($refusal = $this->packagesReady()) {
            return $refusal;
        }
        $style = isset($p['style']) && is_string($p['style']) ? trim($p['style']) : '';
        if ($style === '') {
            return $this->err('bad_params', 'style required, e.g. airbnb');
        }
        $result = $this->packages->wear_style($style);
        return ($result['ok'] ?? false) === true
            ? $this->ok(['style' => $result['style'] ?? $style, 'post' => $result['post'] ?? null])
            : $this->err('style_failed', (string) ($result['error'] ?? 'the style did not go on'));
    }

    /**
     * Read this site's colour palette, or change named colours in it.
     *
     * 🔒 ONE COLOUR IS NOT ONE STYLE, AND UNTIL NOW ONLY THE STYLE HAD A DOOR. `theme.style` wears
     * a whole variation — `airbnb`, `apple`, one of the theme's 152 — which is the right call when
     * a site is being dressed. It is the wrong one, and the only one, when the customer says "make
     * the primary colour terracotta": wearing a variation to change one value replaces every other
     * value with it.
     *
     * Measured 20/09/2026 on a customer site: asked for `#bf5b3d`, the agent found `theme.style`,
     * was told `style required, e.g. airbnb`, and went instead to
     * `/wp-json/wp/v2/global-styles/...` — which answered 401 to an unauthenticated read — and then
     * to a dozen shell calls editing theme files by hand. There was no door, so it made one.
     *
     * ⚠ SLUGS ARE THE THEME'S, NOT OURS. Which slug means "primary" is a fact about the active
     * theme, so this never guesses: called with no `colors` it RETURNS the palette, slugs and all,
     * and the caller names what it wants changed. A door that guessed would write `primary` into a
     * theme whose accent is called `contrast` and report success.
     *
     * The previous value of every colour it writes comes back in the answer, so the change can be
     * put back without reading the site again.
     */
    private function themePalette(array $p): array
    {
        if ($refusal = $this->packagesReady()) {
            return $refusal;
        }
        if (!array_key_exists('colors', $p)) {
            return $this->ok(['palette' => $this->packages->palette()]);
        }
        if (!is_array($p['colors']) || $p['colors'] === []) {
            return $this->err('bad_params', 'colors must be an object of slug => "#rrggbb"; omit it to read the palette');
        }
        $wanted = [];
        foreach ($p['colors'] as $slug => $color) {
            if (!is_string($slug) || !preg_match('/^[a-z0-9-]+$/', $slug)) {
                return $this->err('bad_params', 'a colour slug is a-z, 0-9 and dashes — read the palette first for this theme\'s own slugs');
            }
            if (!is_string($color) || !preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $color)) {
                return $this->err('bad_params', sprintf('%s must be a hex colour like #bf5b3d', $slug));
            }
            $wanted[$slug] = $color;
        }
        $result = $this->packages->set_palette($wanted);
        return ($result['ok'] ?? false) === true
            ? $this->ok(['changed' => $result['changed'] ?? [], 'was' => $result['was'] ?? [], 'post' => $result['post'] ?? null])
            : $this->err('palette_failed', (string) ($result['error'] ?? 'the palette did not go on'));
    }

    // ---- Applying an approved change, and undoing it ------------------------------------------
    //
    // Every step here records how to reverse itself BEFORE it is called done, under an apply_id
    // the caller chose for the whole deliverable. That is what `apply.revert` replays, and it is
    // the condition ADR 0048 puts on a change being guaranteed at all.

    /** Add discovery only when the installed writer can prove its accepted fields. */
    private function writeSchema(string $kind): array
    {
        if ($this->writer === null || !method_exists($this->writer, 'describeWrites')) return [];
        $schema = $this->writer->describeWrites($kind);
        return $schema === null ? [] : ['writeSchema' => $schema];
    }

    /**
     * Edit one piece of the site's content: a post, one of its meta values, or an option.
     *
     * Three kinds because WordPress keeps three shapes, not because three seemed like enough. A
     * post is a row with an id. A meta is a NAMED value hanging off a post — where every SEO
     * plugin keeps the description somebody is usually here to fix. An option is a name with no id
     * at all. Hence `key` beside `id`: the Joomla original needed only an id because all three of
     * its kinds were rows.
     */
    /**
     * One page of posts, as the content mirror reads them (ADR 0071).
     *
     * Read-only: no apply_id, nothing recorded. A page that carries bodies is capped smaller,
     * because the alternative — one request per post — spends a caller's whole hourly allowance
     * at the relay on a single site's list.
     */
    private function contentList(array $p): array
    {
        if ($this->writer === null) {
            return $this->err('unavailable', 'site writer not wired');
        }
        if (!method_exists($this->writer, 'list_posts')) {
            return $this->err('unavailable', 'this plugin is too old to list content');
        }
        /**
         * 🔒 THIS LISTS POSTS AND NOTHING ELSE, AND IT NOW SAYS SO INSTEAD OF PRETENDING.
         *
         * `kind` was read nowhere in this method: every call ran `list_posts()` and answered
         * `"kind":"post"` whatever was asked for. Measured 19/09/2026 across two sites,
         * `content.list` with `kind` of `option`, `menuItem`, `user`, `menutype` and `page` each
         * came back with the site's posts and an `ok:true` — the one shape this component refuses
         * everywhere else, a wrong answer reporting success. An agent reading it concluded the site
         * had no options and went looking in the webroot's PHP.
         *
         * The writer has one list, `list_posts`. Growing the others is a bigger piece of work than
         * this; until then the honest answer is a refusal that NAMES the door which does serve the
         * kind asked for, because a refusal an agent can act on costs one call and a wrong list
         * costs a wrong theory.
         */
        $kind = isset($p['kind']) && is_string($p['kind']) && trim($p['kind']) !== ''
            ? trim($p['kind'])
            : 'post';
        if ($kind !== 'post') {
            return $this->err(
                'bad_params',
                "content.list serves kind \"post\" only; \"{$kind}\" is not listed here. "
                    . 'Read one with content.get {kind, id or key}, and use post_type to narrow posts.'
            );
        }
        $offset = max(0, (int) ($p['offset'] ?? 0));
        $withBody = !empty($p['include_body']);
        $ceiling = $withBody ? 25 : 200;
        $limit = min($ceiling, max(1, (int) ($p['limit'] ?? ($withBody ? 25 : 100))));

        // `name` asks one question — is this slug already on the site? — instead of paging through
        // everything to find out. A seeder that cannot ask it creates its pages twice on a rerun.
        $name = isset($p['name']) && is_string($p['name']) ? trim($p['name']) : '';
        if ($name !== '' && !preg_match('/^[a-z0-9._-]+$/i', $name)) {
            return $this->err('bad_params', 'name must be a slug');
        }
        $type = isset($p['post_type']) && is_string($p['post_type']) ? trim($p['post_type']) : '';
        if ($type !== '' && !preg_match('/^[a-z0-9_-]+$/i', $type)) {
            return $this->err('bad_params', 'post_type must be a post type name');
        }

        try {
            $items = $this->writer->list_posts($offset, $limit, $withBody, $name, $type);
        } catch (Throwable $e) {
            return $this->err('read_failed', $e->getMessage());
        }

        return $this->ok(['kind' => 'post', 'offset' => $offset, 'items' => $items] + $this->writeSchema('post'));
    }

    /**
     * One record, exactly as the site holds it — the same read the undo log takes before a write.
     *
     * 🔒 IT READS EVERY KIND THE WRITER WRITES, AND UNTIL 19/09/2026 IT READ ONLY POSTS. This took
     * `id` alone and called `read('post', $id)` with the kind hardcoded, so `content.get
     * {kind:"option", key:"WPLANG"}` answered `bad_params: id required` — an option has no numeric
     * id — and there was no way to read one back at all. `contentUpdate` three hundred lines below
     * has always passed `kind`, `id` AND `key` to the same `SiteWriter::read()`, because it needs
     * the before-image for the undo log; the read path simply never caught up.
     *
     * ⚠ WHAT THE GAP COST, MEASURED ON A REAL SITE. An agent asked to translate `demowpnam` needed
     * to know an option's value, could not read it, and so WROTE
     * `show_comments_cookies_opt_in = 1` and put it back to `0` — two writes to a customer's live
     * site whose only purpose was to discover the old value. It named that apply `vi-check-noop`.
     * An earlier turn on another site spent fifteen of its eighteen steps reading this component's
     * own PHP out of the webroot to work out that options can be written and not read.
     *
     * 🔒 `kind` DEFAULTS TO `post`, SO EVERY CALLER THAT EXISTS TODAY IS UNTOUCHED. The old shape
     * `{id: 7}` still returns that post, and the answer still carries `kind`, so a caller reading
     * that field sees what it always saw.
     */
    private function contentGet(array $p): array
    {
        if ($this->writer === null) {
            return $this->err('unavailable', 'site writer not wired');
        }
        $kind = isset($p['kind']) && is_string($p['kind']) && trim($p['kind']) !== ''
            ? trim($p['kind'])
            : 'post';
        if (!in_array($kind, SiteWriter::KINDS, true)) {
            return $this->err('bad_params', 'kind must be one of: ' . implode(', ', SiteWriter::KINDS));
        }
        $id = max(0, (int) ($p['id'] ?? 0));
        $key = isset($p['key']) && is_string($p['key']) ? trim($p['key']) : '';
        // Each kind is addressed its own way and `SiteWriter::read()` holds that knowledge — a post
        // by id, an option by key, a postmeta and a term by both. Refusing here on a second copy of
        // that table is how the two would drift; the refusal below names what was actually asked.
        if ($id === 0 && $key === '') {
            return $this->err('bad_params', "id or key required to name which {$kind} to read");
        }

        try {
            $item = $this->writer->read($kind, $id, $key);
        } catch (Throwable $e) {
            return $this->err('read_failed', $e->getMessage());
        }
        if ($item === null) {
            // Both halves of the address, because a null here means either "no such record" or
            // "that kind is not addressed the way you addressed it", and the caller cannot tell
            // which from a message that repeats back only the half it happened to send.
            return $this->err('not_found', sprintf('no %s at id %d, key "%s"', $kind, $id, $key));
        }

        return $this->ok(['kind' => $kind, 'id' => $id, 'key' => $key, 'item' => $item] + $this->writeSchema($kind));
    }

    /**
     * What language a page is in, and which pages are each other's translations.
     *
     * WordPress has no notion of a translated page, so this is Polylang's — the plugin Tracy
     * installs when a customer asks for more than one language. Everything below is its published
     * API, measured on Polylang 3.8.8 (2026-09-08):
     *
     *   - a language that does not exist yet is CREATED here, because "this page is Vietnamese"
     *     has no meaning on a site with no Vietnamese. `PLL()->model->languages->add()` is the
     *     3.x way in; `PLL()->model->add_language()` is the 2.x name every tutorial still prints
     *     and it does not exist any more.
     *   - the cache must be cleaned after adding, or `pll_languages_list()` keeps answering the
     *     list it had at the top of the request.
     *
     * A site with no Polylang is not an error: it answers `unavailable`, the caller writes a
     * warning, and the site keeps the one language it has.
     */
    private function contentLanguage(array $p): array
    {
        if ($this->log === null) {
            return $this->err('unavailable', 'site writer not wired');
        }
        $applyId = $this->applyId($p);
        if ($applyId === null) {
            return $this->err('bad_params', 'apply_id required');
        }
        if (!function_exists('pll_set_post_language')) {
            return $this->err('unavailable', 'this site has no translation plugin');
        }
        $id = max(0, (int) ($p['id'] ?? 0));
        if ($id === 0) {
            return $this->err('bad_params', 'id required');
        }
        $lang = isset($p['lang']) && is_string($p['lang']) ? trim($p['lang']) : '';
        if ($lang === '' || !preg_match('/^[a-z]{2}(-[a-z]{2})?$/i', $lang)) {
            return $this->err('bad_params', 'lang must be a language code');
        }
        $slug = strtolower(substr($lang, 0, 2));

        $before = function_exists('pll_get_post_language') ? pll_get_post_language($id) : null;

        try {
            $this->ensureLanguage($slug);
            pll_set_post_language($id, $slug);
            $translations = [];
            foreach ((array) ($p['translations'] ?? []) as $code => $postId) {
                $code = strtolower(substr((string) $code, 0, 2));
                $postId = (int) $postId;
                if ($code !== '' && $postId > 0) {
                    $this->ensureLanguage($code);
                    $translations[$code] = $postId;
                }
            }
            if (count($translations) > 1 && function_exists('pll_save_post_translations')) {
                pll_save_post_translations($translations);
            }
        } catch (Throwable $e) {
            return $this->err('write_failed', $e->getMessage());
        }

        try {
            $this->log->record($applyId, [
                'op' => 'language',
                'id' => $id,
                'before' => $before === false ? null : $before,
            ]);
        } catch (Throwable $e) {
            // The language of a page is not a destructive change and Polylang holds no history of
            // it; a lost undo entry is not worth undoing a page that is now correctly filed.
        }

        $this->stamped('content');

        return $this->ok(['id' => $id, 'lang' => $slug]);
    }

    /**
     * Make sure Polylang knows a language, using its own defaults for everything a form never asks
     * for. The locale is the one Polylang ships for that slug when it has one — its predefined list
     * is the only place that knows `vi` is `vi_VN` and `en` is `en_US` — and `<slug>_<SLUG>` only
     * when it does not, which is a guess the caller is told about by the language simply appearing
     * under that name.
     */
    private function ensureLanguage(string $slug): void
    {
        if (!function_exists('PLL') || !PLL() || !isset(PLL()->model)) {
            return;
        }
        $existing = function_exists('pll_languages_list') ? (array) pll_languages_list() : [];
        if (in_array($slug, $existing, true)) {
            return;
        }
        $locale = $slug . '_' . strtoupper($slug);
        $flag = $slug;
        if (class_exists('PLL_Settings') && method_exists('PLL_Settings', 'get_predefined_languages')) {
            foreach (PLL_Settings::get_predefined_languages() as $code => $row) {
                if (isset($row['code']) && $row['code'] === $slug) {
                    $locale = is_string($code) ? $code : $locale;
                    $flag = isset($row['flag']) ? (string) $row['flag'] : $flag;
                    break;
                }
            }
        }
        $model = PLL()->model;
        $add = [
            'name' => $slug,
            'slug' => $slug,
            'locale' => $locale,
            'rtl' => 0,
            'term_group' => count($existing),
            'flag' => $flag,
        ];
        if (isset($model->languages) && method_exists($model->languages, 'add')) {
            $model->languages->add($add);
        } elseif (method_exists($model, 'add_language')) {
            // Polylang 2.x. Kept because a customer's site is whatever version they installed.
            $model->add_language($add);
        }
        if (method_exists($model, 'clean_languages_cache')) {
            $model->clean_languages_cache();
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
    /**
     * Send one row to the site's trash, recorded so the Apply can put it back.
     *
     * Joomla writes -2 into a trash column; WordPress has a trash of its own, and using it is the
     * point — the page stays recoverable from the admin screens long after this Apply's revert
     * window closes, so Tracy is never the only way back from a delete.
     *
     * Only kinds that HAVE somewhere to go can be deleted. An option or a meta value has no trash
     * to sit in, and a caller who wants one gone is writing an empty value rather than removing a
     * row — refused here with a sentence rather than half-done in the writer.
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
        if (!$this->writer->canTrash($kind)) {
            return $this->err('unsupported', "a {$kind} cannot be deleted through Apply");
        }
        $key = isset($p['key']) && is_string($p['key']) ? trim($p['key']) : '';
        $id = (int) ($p['id'] ?? 0);

        // A template part is addressed by slug, the way it is written; a post by id. Reading the
        // before-state settles which row is meant before anything is touched.
        $before = $this->writer->read($kind, $id, $key);
        if ($before === null) {
            return $this->err('not_found', 'templatePart' === $kind
                ? "no templatePart with slug {$key}"
                : "no {$kind} with id {$id}");
        }
        if ('templatePart' === $kind) {
            $id = (int) ($before['id'] ?? 0);
        }

        try {
            $this->writer->trash($kind, $id);
        } catch (Throwable $e) {
            return $this->err('write_failed', $e->getMessage());
        }

        try {
            $this->log->record($applyId, [
                'op' => 'content',
                'kind' => $kind,
                'id' => $id,
                'key' => $key,
                'before' => $before,
            ]);
        } catch (Throwable $e) {
            $this->rollbackContent($kind, $id, $key, $before);
            return $this->err('write_failed', 'change was rolled back: could not record its undo');
        }

        try {
            $this->writer->purgeCache();
        } catch (Throwable $e) {
            // Best-effort by contract, as everywhere else here.
        }

        $this->stamped('content');

        return $this->ok(['kind' => $kind, 'id' => $id, 'key' => $key === '' ? null : $key]);
    }

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
     * The trash's second step (ADR 0083: purge): DROP, accepted ONLY for `_tracy_trash_*`
     * names — a plain table name is refused outright, which is what confines the one
     * destructive operation this engine has to tables a cleanup already parked.
     */

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

        // What the bytes were before they left the caller. base64 carries no integrity of its own,
        // so a file transcribed by hand — which is what an agent does when it types content_b64
        // rather than naming a file — can arrive the exact right length and still be broken. On
        // 2026-08-27 three characters of a 4,935-byte PNG came through wrong: the upload reported
        // success, the attachment was created, and every visitor saw a mangled logo. Optional, so
        // an older caller that cannot compute it is not locked out; when it is sent, it decides.
        $expected = isset($p['sha256']) && is_string($p['sha256']) ? strtolower(trim($p['sha256'])) : '';
        if ($expected !== '') {
            $actual = hash('sha256', $bytes);
            if (!hash_equals($expected, $actual)) {
                return $this->err(
                    'corrupt_upload',
                    sprintf(
                        'the bytes that arrived are not the bytes that were sent: expected sha256 %s, got %s (%d bytes). '
                        . 'Nothing was written. Send the file by path rather than transcribing it.',
                        substr($expected, 0, 12),
                        substr($actual, 0, 12),
                        strlen($bytes)
                    )
                );
            }
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
            } elseif ($op === 'contract') {
                // The receipt of a content-contract apply: not a change of its own, so never
                // "created"; what it carries is which request it answered and the revision after.
                $step['created'] = false;
                $step['request'] = $entry['request'] ?? null;
                $step['afterRevision'] = $entry['afterRevision'] ?? null;
            } elseif ($op === 'visibility') {
                $step['kind'] = $entry['kind'] ?? null;
                $step['id'] = $entry['id'] ?? null;
                $step['column'] = $entry['column'] ?? null;
            } elseif ($op === 'sourceLocale') {
                $step['slug'] = $entry['slug'] ?? null;
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
        if ($op === 'content' && ($entry['kind'] ?? '') === 'optionTranslation') {
            // No row moved: only one language's string translation did.
            if (isset($entry['translations']) && is_array($entry['translations'])) {
                QuickstartContract::stringTranslationsRestore($entry['translations']);
            }
            return;
        }
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
            // After the row, so Polylang's own re-keying on that write cannot undo the restore.
            if (isset($entry['translations']) && is_array($entry['translations'])) {
                QuickstartContract::stringTranslationsRestore($entry['translations']);
            }
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
        if ($op === 'contract') {
            // A receipt records that an apply happened; the content entries beside it are what
            // is undone. Nothing to put back here.
            return;
        }
        if ($op === 'visibility') {
            QuickstartContract::setPostStatus((int) ($entry['id'] ?? 0), (string) ($entry['before'] ?? ''));
            return;
        }
        if ($op === 'sourceLocale') {
            QuickstartContract::setLanguageLocale((string) ($entry['slug'] ?? ''), (string) ($entry['before'] ?? ''));
            return;
        }
        if ($op === 'siteLanguage') {
            $this->restoreSiteLanguage(is_array($entry['before'] ?? null) ? $entry['before'] : []);
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
     * The strings Polylang may key a translation of one of its translated options by, for a
     * contract write of that option: the option's value before the write, the profile's demo
     * value(s) for it, and the value being written. `[]` for every other write.
     *
     * @param array<string,mixed> $op
     * @param array<string,mixed>|null $before
     * @return string[]
     */
    private function translatedOptionOriginals(array $op, ?array $before): array
    {
        if (!in_array($op['kind'] ?? '', ['option', 'optionTranslation'], true) || !QuickstartContract::isTranslatedOption((string) ($op['key'] ?? ''))) {
            return [];
        }
        $value = $op['fields']['value'] ?? null;
        if (!is_string($value)) {
            return [];
        }
        $originals = [];
        $was = $before['value'] ?? null;
        if (is_string($was) && $was !== '') {
            $originals[] = $was;
        }
        foreach ($this->contract->optionSamples((string) $op['key']) as $sample) {
            $originals[] = $sample;
        }
        if ($value !== '') {
            $originals[] = $value;
        }
        return array_values(array_unique($originals));
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
