<?php
/**
 * QuickstartContract — the content-only seal on a WordPress site built from a Tracy quickstart.
 *
 * A sealed site keeps the design the release shipped and lets the customer change WORDS: the
 * value of every slot the contract's `content-map.json` names, and nothing around them. This
 * class holds that boundary. It loads a profile from `lib/contracts/<id>/` (trusted package data,
 * never a caller-supplied allow-list), checks the site against its `presentation-lock.json`,
 * reads and writes slot values inside block markup, and keeps the binding — which profile the
 * site is sealed to and the revision of its governed content — in one option.
 *
 * Ported by IDEA from the Joomla receiver's `QuickstartContract`, not by code. WordPress keeps
 * words in block markup (a slot is a block named by `metadata.name` and one attribute of it) and
 * identity in a slug plus a Polylang language, so the checks are different in kind: a page is
 * held by the sha256 of its content with every slot MASKED (the skeleton), and the theme by the
 * hashes of its files.
 *
 * The one rule every method keeps: a profile that cannot be loaded REFUSES, it never reads as an
 * unbound site. Unbound is the one state where every structural write is allowed, and a receiver
 * too old to carry the profile a site names must not fall into it by accident.
 */

require_once __DIR__ . '/SiteWriter.php';
require_once __DIR__ . '/IdentityTokens.php';
require_once __DIR__ . '/DemoTrimProfile.php';
require_once __DIR__ . '/ContractProblem.php';
require_once __DIR__ . '/LeafCodec.php';
require_once __DIR__ . '/DerivedMap.php';
require_once __DIR__ . '/DerivedCache.php';

/**
 * Where the binding lives. The plugin keeps it in an option; the tests keep it in memory.
 * `load()` throws when the store holds something that is not a binding — a corrupt store locks
 * the site, it never opens it.
 */
interface ContractStore
{
    public function load(): ?array;

    /** The first binding. Refuses to replace a different one: only `replace()` may move a baseline. */
    public function save(array $binding): void;

    /** A new baseline after a validated apply, trim or relabel. */
    public function replace(array $binding): void;
}

/**
 * The `content.read` identity and revision of what a contract write touches, answered by the
 * content reader itself, so `expected_content_revisions` compares against the very value
 * `contents[].revision` showed and no second formula exists to drift from it.
 */
interface ContentRevisions
{
    /**
     * @param array<int|string,array{kind:string,id:int,key:string}> $targets one row a write lands on:
     *        `post`/`templatePart` by id (a theme-file part by its slug), `option`/`optionTranslation` by name
     * @return array<int|string,?array{id:string,revision:string}> per target, same keys; null when the
     *         reader lists no content for it
     */
    public function of(array $targets): array;
}

/** The profile this site names cannot be used at all — as opposed to the site failing its checks. */
final class ContractUnavailable extends RuntimeException
{
}

final class QuickstartContract
{
    public const STORE_OPTION = '_tracy_content_contract';
    public const SETTING_OPTION = 'claude_cowork_contract';
    public const SCHEMA_VERSION = 1;
    /** `<design>/wp<major>/<version>` and nothing that could climb out of `lib/contracts/`. */
    public const ID_SHAPE = '~^[a-z][a-z0-9-]{1,40}/wp[0-9]{1,2}/[0-9]+\.[0-9]+\.[0-9]+$~D';
    /**
     * A DERIVED contract: an imported site bound to the map its own rows make (DerivedMap), named
     * by the label its derive was given. Never a directory under `lib/contracts/`: only a binding
     * made by `content.contract derive` names one.
     */
    public const DERIVED_SHAPE = '~^derived/[a-z0-9-]{3,80}$~D';
    /** A derived link or picture address: sized as an address, not from the words it replaced. */
    private const DERIVED_LINK_BYTES = 2048;
    public const EDITIONS_SCHEMA = 'tracy-quickstart-editions/wordpress/v1';
    public const SUPERSEDED_SCHEMA = 'tracy-quickstart-superseded/wordpress/v1';
    /** The published source edition: Polylang slug and WordPress locale. */
    public const SOURCE_LANGUAGE = 'en';
    public const SOURCE_LOCALE = 'en_US';
    /** The only locales the source edition may be relabelled to: same words, another spelling. */
    public const SOURCE_VARIANTS = ['en_US', 'en_GB', 'en_AU', 'en_CA', 'en_NZ'];
    /** The mask every slot wears when a page's skeleton is hashed. */
    public const SLOT_MASK = '{{slot}}';
    private const MAX_CHANGES = 1500;

    private SiteWriter $writer;
    private ContractStore $store;
    private string $root;
    private string $contractsDir;
    private string $configured;

    private ?string $id = null;
    private array $manifest = [];
    private array $map = [];
    private array $lock = [];
    private string $contractHash = '';
    private ?DemoTrimProfile $demoTrim = null;
    private ?array $editions = null;
    /** Base hashes of earlier bytes of THIS profile a binding may still name (superseded.json). */
    private array $acceptedBases = [];
    /** Demo-trim profile hashes those earlier bytes carried. */
    private array $acceptedTrims = [];
    /** Options resolved once per inspect, so the URL allow-list does not read `home` per slot. */
    private ?string $homeHost = null;
    private ?ContentRevisions $revisions;
    private ?ImageLibrary $images;
    /** @var array<string,int> attachment id of each picture an apply's checks resolved, by path */
    private array $imageIds = [];

    /** @var callable|null `fn(): list<row>|Generator` the site's own rows, in DerivedMap's shape, or their batches (WordPressDerivedRows) */
    private $derivedRows = null;
    /** Where a built derived map is kept between requests; null builds it on every request. */
    private ?DerivedCache $derivedCache = null;
    /** Whether the resolved contract is a derived one. */
    private bool $derived = false;
    /** Whether a derived contract's map has been built (lazily: see ensureDerived()). */
    private bool $derivedReady = false;
    /** @var array{manifest:array,map:array}|null a map handed over already built (by bindDerived) */
    private ?array $derivedBuilt = null;

    /**
     * An image slot's value: a picture already in this site's uploads. The same folder
     * `media.upload` writes to; `..` is refused separately because the pattern allows dots.
     */
    private const IMAGE_PATH = '~^wp-content/uploads/[A-Za-z0-9/_.-]+\.(png|jpe?g|webp)$~D';

    /**
     * @param string $root         The webroot (no trailing slash), where `fileRoots` are checked.
     * @param string $contractsDir `lib/contracts`, holding one directory per profile id.
     * @param string $configured   The `claude_cowork_contract` setting, or '' when none is set.
     */
    public function __construct(SiteWriter $writer, ContractStore $store, string $root, string $contractsDir, string $configured = '', ?ContentRevisions $revisions = null, ?ImageLibrary $images = null)
    {
        $this->revisions = $revisions;
        $this->images = $images;
        $this->writer = $writer;
        $this->store = $store;
        $this->root = rtrim($root, '/');
        $this->contractsDir = rtrim($contractsDir, '/');
        $this->configured = trim($configured);
    }

    /**
     * Where a derived contract's rows come from. Called only the first time a derived map is needed
     * (inspect, plan, the slots): an Engine answering `db.*`, `files.*` or `info` on a derived site
     * resolves the binding and never scans the tables.
     *
     * @param callable $rows `fn(): list<array{kind,id,identity,core,html,nested}>`, or a generator of such lists
     *        (WordPressDerivedRows::batches) so a large site is never held whole
     * @param DerivedCache|null $cache keeps the built map between requests, keyed by the tables' fingerprint
     */
    public function withDerivedRows(callable $rows, ?DerivedCache $cache = null): self
    {
        $this->derivedRows = $rows;
        $this->derivedCache = $cache;
        return $this;
    }

    /**
     * Drop the derived map kept between requests: after a write this receiver made, whatever the
     * fingerprint would say, the next read builds from the rows as they are now.
     */
    public function forgetDerived(): void
    {
        if ($this->derivedCache !== null) {
            $this->derivedCache->clear();
        }
    }

    /** Whether this site is held to a derived contract (known once the binding was resolved). */
    public function isDerived(): bool
    {
        return $this->derived;
    }

    /**
     * Whether a quickstart profile is configured for this site (`claude_cowork_contract`) while it is
     * not yet bound: the provision of a quickstart build sets it and binds next.
     */
    public function quickstartConfigured(): bool
    {
        return $this->configured !== '' && (bool) preg_match(self::ID_SHAPE, $this->configured);
    }

    // ---- profile ----------------------------------------------------------------------------

    /**
     * Which profile this site is held to: the bound one, else the configured one, else the one
     * the caller names (bind and a planning inspect). Loads it, and refuses when it cannot.
     */
    private function resolve(?string $requested): void
    {
        $binding = $this->store->load();
        $id = isset($binding['contract']) && is_string($binding['contract']) ? $binding['contract'] : '';
        if ($id === '') {
            $id = $this->configured;
        }
        if ($id === '' && $requested !== null && $requested !== '') {
            $id = $requested;
        }
        if ($id === '') {
            throw new ContractUnavailable('No content contract is configured for this site; name one with the contract parameter');
        }
        if (preg_match(self::DERIVED_SHAPE, $id)) {
            // Only the binding a derive made names a derived contract; nothing is loaded here, the
            // map is built the first time something reads it.
            if (($binding['mode'] ?? null) !== 'derived' || ($binding['contract'] ?? null) !== $id) {
                throw new ContractUnavailable('A derived contract is made by content.contract derive, never named: ' . $id);
            }
            if ($requested !== null && $requested !== '' && $requested !== $id) {
                throw new ContractUnavailable('This site is held to ' . $id . ', not ' . $requested);
            }
            if ($this->id !== $id || !$this->derived) {
                $this->useDerived($id, (int) ($binding['algorithm'] ?? DerivedMap::ALGORITHM), null);
            }
            return;
        }
        if (!preg_match(self::ID_SHAPE, $id)) {
            throw new ContractUnavailable('Not a contract id: ' . substr($id, 0, 80));
        }
        if ($requested !== null && $requested !== '' && $requested !== $id) {
            throw new ContractUnavailable('This site is held to ' . $id . ', not ' . $requested);
        }
        if ($this->id === $id) {
            return;
        }
        $this->load($id);
    }

    private function load(string $id): void
    {
        $directory = $this->contractsDir . '/' . $id;
        $files = [];
        foreach (['manifest', 'content-map', 'presentation-lock'] as $name) {
            $file = $directory . '/' . $name . '.json';
            if (!is_file($file)) {
                throw new ContractUnavailable('This site names a content contract this plugin does not carry: ' . $id);
            }
            $files[$name] = self::json((string) file_get_contents($file), $name . '.json');
        }
        if (($files['manifest']['id'] ?? null) !== $id) {
            throw new ContractUnavailable('The profile at ' . $id . ' names itself ' . (string) ($files['manifest']['id'] ?? '?'));
        }
        if (!isset($files['content-map']['entities'], $files['content-map']['slots'])
            || !is_array($files['content-map']['entities']) || !is_array($files['content-map']['slots'])) {
            throw new ContractUnavailable('The content map of ' . $id . ' has no entities or slots');
        }
        $this->manifest = $files['manifest'];
        $this->map = $files['content-map'];
        $this->lock = $files['presentation-lock'];
        $this->derived = false;
        $this->derivedReady = false;
        $this->derivedBuilt = null;
        $this->contractHash = DemoTrimProfile::baseHash($directory);
        $this->demoTrim = null;
        $this->editions = null;
        $this->acceptedBases = [];
        $this->acceptedTrims = [];

        // Both extensions are optional and pinned to the base bytes. A profile whose extension does
        // not belong to it is refused WHOLE: a receiver that ignored the bad file would seal sites
        // against three files while claiming a fourth it cannot vouch for.
        $trimFile = $directory . '/demo-trim-map.json';
        if (is_file($trimFile)) {
            $raw = (string) file_get_contents($trimFile);
            try {
                $this->demoTrim = new DemoTrimProfile(self::json($raw, 'demo-trim-map.json'), $directory, $raw);
            } catch (RuntimeException $e) {
                throw new ContractUnavailable($id . ': ' . $e->getMessage());
            }
        }
        $editionsFile = $directory . '/editions.json';
        if (is_file($editionsFile)) {
            $editions = self::json((string) file_get_contents($editionsFile), 'editions.json');
            if (($editions['schemaVersion'] ?? '') !== self::EDITIONS_SCHEMA
                || !hash_equals($this->contractHash, (string) ($editions['baseHash'] ?? ''))
                || !isset($editions['locales']) || !is_array($editions['locales'])) {
                throw new ContractUnavailable($id . ': the editions profile does not belong to this contract');
            }
            foreach ($editions['locales'] as $slug => $edition) {
                if (!is_string($slug) || !is_array($edition) || !isset($edition['ids']) || !is_array($edition['ids'])) {
                    throw new ContractUnavailable($id . ': the editions profile lists a locale without ids');
                }
            }
            $this->editions = $editions;
        }

        // A released profile corrected in place (a slot's limit or input) keeps the sites already
        // sealed to its earlier bytes: `superseded.json` names those bytes' base hash, and it is
        // honoured only when the seal they bound — the presentation lock — is these bytes exactly.
        // A declaration that does not hold is refused whole, like any extension of the profile.
        $supersededFile = $directory . '/superseded.json';
        if (is_file($supersededFile)) {
            $superseded = self::json((string) file_get_contents($supersededFile), 'superseded.json');
            $lock = (string) hash_file('sha256', $directory . '/presentation-lock.json');
            if (($superseded['schemaVersion'] ?? '') !== self::SUPERSEDED_SCHEMA || ($superseded['contract'] ?? '') !== $id
                || !hash_equals($this->contractHash, (string) ($superseded['baseHash'] ?? ''))
                || !isset($superseded['accepts']) || !is_array($superseded['accepts'])) {
                throw new ContractUnavailable($id . ': the superseded profile list does not belong to this contract');
            }
            foreach ($superseded['accepts'] as $entry) {
                $base = is_array($entry) ? (string) ($entry['baseHash'] ?? '') : '';
                $trim = is_array($entry) && isset($entry['demoTrimHash']) ? (string) $entry['demoTrimHash'] : null;
                if (!preg_match('/^[a-f0-9]{64}$/D', $base) || ($trim !== null && !preg_match('/^[a-f0-9]{64}$/D', $trim))
                    || !hash_equals($lock, (string) ($entry['presentationLock'] ?? ''))) {
                    throw new ContractUnavailable($id . ': a superseded profile was sealed to another presentation lock');
                }
                $this->acceptedBases[] = $base;
                if ($trim !== null) {
                    $this->acceptedTrims[] = $trim;
                }
            }
        }
        $this->id = $id;
    }

    private static function json(string $raw, string $name): array
    {
        $value = json_decode($raw, true);
        if (!is_array($value)) {
            throw new ContractUnavailable('Not JSON: ' . $name);
        }
        return $value;
    }

    /** The profile's id, once one is resolved. */
    public function id(): ?string
    {
        return $this->id;
    }

    /** @return array<int,array<string,mixed>> the content map's entities, in profile order */
    public function entities(): array
    {
        $this->ensureDerived();
        return $this->map['entities'] ?? [];
    }

    /** @return array<int,array<string,mixed>> the content map's slots, in profile order */
    public function slots(): array
    {
        $this->ensureDerived();
        return $this->map['slots'] ?? [];
    }

    public function demoTrimAvailable(): bool
    {
        return $this->demoTrim !== null;
    }

    public function demoTrim(): DemoTrimProfile
    {
        if ($this->demoTrim === null) {
            throw new RuntimeException('This contract has no demo-trim profile');
        }
        return $this->demoTrim;
    }

    /** Polylang slugs this contract ships an edition for; the source alone when there is no editions profile. */
    public function editionLanguages(): array
    {
        return $this->editions === null ? [self::SOURCE_LANGUAGE] : array_map('strval', array_keys($this->editions['locales']));
    }

    /** The editions profile as shipped, or null when the contract carries none. */
    public function editions(): ?array
    {
        return $this->editions;
    }

    /** The Polylang slug of the source edition — the one a retire never touches. */
    public function sourceLanguage(): string
    {
        $source = $this->editions['source']['language'] ?? null;
        return is_string($source) && $source !== '' ? $source : self::SOURCE_LANGUAGE;
    }

    /**
     * Which editions a keep list names. A tag is matched as the questionnaire spells it: exactly
     * (`de-de`, `pt-br`, or the Polylang slug), else by primary subtag — `de` names `de-de`,
     * `pt` names both Portuguese editions, and a region the archive does not spell (`de-at`)
     * falls back to the language it belongs to. A tag naming nothing is reported, never
     * silently dropped: a customer who asked for a language must not get a site without it and
     * no word about why.
     *
     * @return array{kept:string[],unknown:string[]} slugs in profile order, and the tags with no edition
     */
    public function editionsKept(array $keep): array
    {
        $editions = [];
        foreach ((array) ($this->editions['locales'] ?? []) as $slug => $edition) {
            $editions[(string) $slug] = strtolower((string) ($edition['tag'] ?? $slug));
        }
        $kept = [];
        $unknown = [];
        foreach ($keep as $tag) {
            $tag = strtolower(trim((string) $tag));
            $matched = [];
            foreach ($editions as $slug => $editionTag) {
                if ($tag === $editionTag || $tag === strtolower($slug)) {
                    $matched[] = $slug;
                }
            }
            if ($matched === []) {
                $primary = explode('-', $tag, 2)[0];
                foreach ($editions as $slug => $editionTag) {
                    if (explode('-', $editionTag, 2)[0] === $primary) {
                        $matched[] = $slug;
                    }
                }
            }
            if ($matched === []) {
                $unknown[] = $tag;
            }
            foreach ($matched as $slug) {
                $kept[$slug] = true;
            }
        }
        $ordered = [];
        foreach (array_keys($editions) as $slug) {
            if (isset($kept[$slug])) {
                $ordered[] = $slug;
            }
        }
        return ['kept' => $ordered, 'unknown' => $unknown];
    }

    /** @return string[] Polylang slugs of the editions a binding holds retired; [] when none */
    public static function retiredLanguages(?array $binding): array
    {
        $record = isset($binding['multilingual']) && is_array($binding['multilingual']) ? $binding['multilingual'] : null;
        $retired = isset($record['retired']) && is_array($record['retired']) ? $record['retired'] : [];
        return array_values(array_map('strval', $retired));
    }

    /** Load the profile a caller names, without a site to check — what a receiver's own tests do. */
    public function preview(string $id): void
    {
        if (!preg_match(self::ID_SHAPE, $id)) {
            throw new ContractUnavailable('Not a contract id: ' . substr($id, 0, 80));
        }
        $this->load($id);
    }

    // ---- binding ----------------------------------------------------------------------------

    /**
     * Whether this site is sealed. Throws — it never answers false — when the store is corrupt or
     * the bound profile cannot be loaded; the engine turns that into `contract_unavailable`.
     */
    public function bound(): bool
    {
        $binding = $this->store->load();
        if ($binding === null) {
            return false;
        }
        $this->resolve(null);
        return true;
    }

    /** The stored binding, or null on an unbound site. */
    public function binding(): ?array
    {
        return $this->store->load();
    }

    /** Seal the site as the clean inspect describes it. */
    public function bind(array $state): void
    {
        if ($this->store->load() !== null) {
            throw new RuntimeException('This site is already bound to ' . (string) $this->store->load()['contract']);
        }
        if ($state['problems'] !== []) {
            throw new RuntimeException('This site does not match ' . $state['contract'] . ': ' . implode('; ', $state['problems']));
        }
        $this->store->save([
            'schemaVersion' => self::SCHEMA_VERSION,
            'contract' => $state['contract'],
            'contractHash' => $this->contractHash,
            'ids' => $state['ids'],
            'revision' => $state['revision'],
            'demoTrim' => null,
            'sourceLanguage' => null,
            'siteLanguage' => null,
            'boundAt' => gmdate('c'),
        ]);
    }

    /** The baseline after a validated content apply or revert: same seal, new revision. */
    public function rebind(array $state): void
    {
        $binding = $this->store->load();
        if ($binding === null) {
            throw new RuntimeException('A content apply needs a bound site');
        }
        $binding['ids'] = $state['ids'];
        $binding['revision'] = $state['revision'];
        $this->store->replace($this->current($binding));
    }

    /**
     * A binding named by a declared predecessor (superseded.json) moves to the current profile on
     * the first validated write: the same seal, the current write rules. Anything else is as stored.
     */
    private function current(array $binding): array
    {
        if (in_array((string) ($binding['contractHash'] ?? ''), $this->acceptedBases, true)) {
            $binding['contractHash'] = $this->contractHash;
            if (isset($binding['demoTrim']) && is_array($binding['demoTrim']) && $this->demoTrim !== null
                && in_array((string) ($binding['demoTrim']['profileHash'] ?? ''), $this->acceptedTrims, true)) {
                $binding['demoTrim']['profileHash'] = $this->demoTrim->hash();
            }
        }
        return $binding;
    }

    /** Put a record on the binding under one key (`demoTrim`, `sourceLanguage`, `siteLanguage`), or take it off with null. */
    public function record(string $field, ?array $record): void
    {
        $binding = $this->store->load();
        if ($binding === null) {
            throw new RuntimeException('A ' . $field . ' record needs a bound site');
        }
        $binding[$field] = $record;
        $this->store->replace($this->current($binding));
    }

    // ---- inspect ----------------------------------------------------------------------------

    /**
     * The site against the profile, and everything the door answers about it.
     *
     * Collects EVERY problem rather than stopping at the first: whoever holds a refused site needs
     * the whole list, not one item per round trip. Throws only when the profile itself cannot be
     * used (`ContractUnavailable`).
     *
     * @return array{bound:bool,contract:string,revision:string,ids:array<string,int>,entities:array,slots:array<string,string>,
     *               rows:array<string,array>,demoTrim:?array,sourceLanguage:?array,multilingual:?array,siteLanguage:?array,problems:string[]}
     */
    public function inspect(?string $requested = null): array
    {
        $this->resolve($requested);
        if ($this->derived) {
            return $this->inspectDerived();
        }
        $binding = $this->store->load();
        $problems = [];
        // 🔒 WHERE THE SITE DIFFERS FROM ITS QUICKSTART'S DESIGN IS A WARNING, NOT A PROBLEM (Tracy ADR
        // 0022, 26/09/2026): a theme file, an option, a template part or a page's blocks changed through
        // WordPress itself. `problems` keeps what makes a slot unsafe to write (the profile changed, a
        // bound entity moved or vanished, a slot that cannot be read); `drift` is reported, and a write
        // is refused only for drift it made itself (`inspectClean`'s `$tolerated`).
        $drift = [];
        $missing = [];
        $this->homeHost = null;

        $lineage = null;
        $boundHash = (string) ($binding['contractHash'] ?? '');
        if ($binding !== null && !hash_equals($this->contractHash, $boundHash)) {
            if (in_array($boundHash, $this->acceptedBases, true)) {
                $lineage = ['from' => $boundHash, 'to' => $this->contractHash];
            } else {
                $problems[] = 'Installed content contract changed';
            }
        }
        $trim = isset($binding['demoTrim']) && is_array($binding['demoTrim']) ? $binding['demoTrim'] : null;
        if ($trim !== null) {
            if ($this->demoTrim === null) {
                $problems[] = 'This site has hidden demo rows but the plugin carries no demo-trim profile';
            } elseif (!hash_equals($this->demoTrim->hash(), (string) ($trim['profileHash'] ?? ''))
                && !($lineage !== null && in_array((string) ($trim['profileHash'] ?? ''), $this->acceptedTrims, true))) {
                $problems[] = 'The installed demo-trim profile changed';
            }
        }

        foreach ($this->files() as $problem) {
            $drift[] = $problem;
        }

        // Option values the lock pins. Old profiles spell the key `options`; both are read.
        $optionValues = $this->lock['optionValues'] ?? ($this->lock['options'] ?? []);
        foreach (is_array($optionValues) ? $optionValues : [] as $name => $want) {
            $have = $this->writer->read('option', 0, (string) $name);
            if ($have === null || (string) $have['value'] !== (string) $want) {
                $drift[] = 'Option ' . $name . ' differs from the lock';
            }
        }

        $governedParts = [];
        foreach ($this->entities() as $entity) {
            if (($entity['kind'] ?? '') === 'templatePart') {
                $governedParts[(string) ($entity['identity']['slug'] ?? '')] = true;
            }
        }
        $templateParts = $this->lock['templateParts'] ?? [];
        foreach (is_array($templateParts) ? $templateParts : [] as $slug => $pin) {
            if (isset($governedParts[$slug])) {
                continue; // held by its skeleton below, not by a whole-content hash
            }
            $part = $this->writer->read('templatePart', 0, (string) $slug);
            if ($part === null) {
                $drift[] = 'Template part ' . $slug . ' is missing (the theme file is in charge)';
            } elseif (!hash_equals((string) ($pin['sha256'] ?? ''), hash('sha256', (string) $part['content']))) {
                $drift[] = 'Template part ' . $slug . ' was edited';
            }
        }

        $ids = [];
        $rows = [];
        $entities = [];
        $slotValues = [];
        $imageSlots = [];
        $lockEntities = isset($this->lock['entities']) && is_array($this->lock['entities']) ? $this->lock['entities'] : [];
        foreach ($this->entities() as $entity) {
            $key = (string) ($entity['key'] ?? '');
            $kind = (string) ($entity['kind'] ?? '');
            $identity = is_array($entity['identity'] ?? null) ? $entity['identity'] : [];
            $found = $this->find($kind, $identity, $binding !== null && $kind !== 'option' ? (int) ($binding['ids'][$key] ?? 0) : 0);
            if (isset($found['problem']) && $kind === 'option' && $this->isSiteIdentity($key)) {
                // The released archive carries no identity row (Tracy Business wp7 1.2.0, 1.3.0):
                // absent is the all-empty identity the theme renders, and the first apply creates it.
                $entities[] = ['key' => $key, 'kind' => $kind, 'id' => null, 'language' => null, 'status' => null];
                foreach ($this->slotsFor($key) as $slot) {
                    $slotValues[$slot['key']] = '';
                }
                continue;
            }
            if (isset($found['problem'])) {
                // Gone from the site: its own slots cannot be written, every other slot still can.
                if ($binding !== null) {
                    $missing[] = $key;
                    $drift[] = $found['problem'] . ': ' . $key;
                } else {
                    $problems[] = $found['problem'] . ': ' . $key;
                }
                continue;
            }
            // A row found by its bound id after its slug changed: the pin below names the new slug.
            $id = (int) $found['id'];
            $row = $found['row'];
            if ($binding !== null && $kind !== 'option' && (int) ($binding['ids'][$key] ?? 0) !== $id) {
                $problems[] = 'Bound entity moved: ' . $key;
            }
            $rows[$key] = $row;
            if ($kind !== 'option') {
                $ids[$key] = $id;
            }
            $entities[] = [
                'key' => $key, 'kind' => $kind, 'id' => $kind === 'option' ? null : $id,
                'language' => $kind === 'option' ? null : (isset($identity['language']) ? (string) $identity['language'] : null),
                'status' => $kind === 'option' ? null : (string) ($row['post_status'] ?? 'publish'),
            ];

            $pin = isset($lockEntities[$key]) && is_array($lockEntities[$key]) ? $lockEntities[$key] : null;
            if ($pin !== null && $kind !== 'option') {
                foreach (['post_type', 'post_name'] as $field) {
                    if (isset($pin[$field]) && (string) ($row[$field] ?? '') !== (string) $pin[$field]) {
                        $drift[] = 'Entity ' . $key . ' has another ' . $field;
                    }
                }
                if (isset($pin['post_parent']) && (int) ($row['post_parent'] ?? 0) !== (int) $pin['post_parent']) {
                    $drift[] = 'Entity ' . $key . ' has another parent';
                }
                if (isset($pin['post_status'])) {
                    $allowed = [(string) $pin['post_status']];
                    // A governed row that is also a listed demo row may sit at either end of its
                    // move once a trim is on record; everything else is held exactly.
                    $trimRow = $this->demoTrim !== null && $trim !== null ? $this->demoTrim->rowForPost($id) : null;
                    if ($trimRow !== null) {
                        $allowed = [$trimRow['from'], $trimRow['to']];
                    }
                    if (!in_array((string) ($row['post_status'] ?? ''), $allowed, true)) {
                        $drift[] = 'Entity ' . $key . ' has another status';
                    }
                }
            }

            $content = $kind === 'templatePart' ? (string) ($row['content'] ?? '') : (string) ($row['post_content'] ?? '');
            $slots = $this->slotsFor($key);
            if ($kind === 'option') {
                $siteIdentity = $this->isSiteIdentity($key);
                foreach ($slots as $slot) {
                    $value = $this->optionSlotValue($row['value'], $slot, $siteIdentity);
                    if ($value === null) {
                        $problems[] = 'Option slot is not text: ' . $slot['key'];
                        continue;
                    }
                    $slotValues[$slot['key']] = $value;
                }
                continue;
            }
            $masked = $content;
            $broken = false;
            foreach ($slots as $slot) {
                $block = (string) ($slot['target']['block'] ?? '');
                $attr = (string) ($slot['target']['attr'] ?? 'content');
                try {
                    $slotValues[$slot['key']] = self::getBlockValue($content, $block, $attr);
                    if (($slot['type'] ?? '') === 'image') {
                        // Not masked: the demo picture goes back, so a page nobody touched hashes
                        // to the bytes it shipped as and a profile that gains image slots keeps
                        // its presentation lock (TCH `capture-inventory.mjs` does the same).
                        if (!is_string($slot['sample'] ?? null) || !is_int($slot['sampleId'] ?? null)) {
                            throw new RuntimeException('an image slot needs sample and sampleId');
                        }
                        $imageSlots[$slot['key']] = $slot['sample'];
                        $masked = self::setBlockValue($masked, $block, $attr, $slot['sample'], $slot['sampleId']);
                    } else {
                        $masked = self::setBlockValue($masked, $block, $attr, self::SLOT_MASK);
                    }
                } catch (RuntimeException $e) {
                    $problems[] = 'Slot ' . $slot['key'] . ': ' . $e->getMessage();
                    $broken = true;
                }
            }
            if ($pin !== null && isset($pin['skeleton']) && !$broken
                && !hash_equals((string) $pin['skeleton'], hash('sha256', $masked))) {
                $drift[] = 'Entity ' . $key . ' differs from the lock outside its slots';
            }
        }

        return [
            'bound' => $binding !== null,
            'contract' => (string) $this->id,
            'revision' => $this->revision($rows),
            'ids' => $ids,
            'entities' => $entities,
            'slots' => $slotValues,
            // Which slots take a picture, and the demo picture each replaces: its shape is the
            // shape a new one must have.
            'imageSlots' => $imageSlots,
            'rows' => $rows,
            'demoTrim' => $trim,
            'sourceLanguage' => isset($binding['sourceLanguage']) && is_array($binding['sourceLanguage']) ? $binding['sourceLanguage'] : null,
            'multilingual' => self::multilingualState($binding),
            'siteLanguage' => self::siteLanguageState($binding),
            'contractLineage' => $lineage,
            'problems' => $problems,
            'drift' => $drift,
            'missing' => $missing,
        ];
    }

    /**
     * The retired edition set a binding holds, in the shape the door answers — or null. A page
     * of a retired edition is only a copy, never a governed entity of the source, so nothing
     * above needs to allow for it; this is a report, not a check.
     *
     * @return array{retired:string[],live:string[],applyId:?string}|null
     */
    private static function multilingualState(?array $binding): ?array
    {
        $record = isset($binding['multilingual']) && is_array($binding['multilingual']) ? $binding['multilingual'] : null;
        if ($record === null) {
            return null;
        }
        return [
            'retired' => self::retiredLanguages($binding),
            'live' => array_values(array_map('strval', is_array($record['live'] ?? null) ? $record['live'] : [])),
            'applyId' => isset($record['applyId']) ? (string) $record['applyId'] : null,
        ];
    }

    /**
     * The default language a binding holds set, in the shape the door answers — or null. Like
     * the retired set, a report: which edition is served at `/` changes no governed row.
     *
     * @return array{language:string,from:?string,applyId:?string}|null
     */
    private static function siteLanguageState(?array $binding): ?array
    {
        $record = isset($binding['siteLanguage']) && is_array($binding['siteLanguage']) ? $binding['siteLanguage'] : null;
        if ($record === null) {
            return null;
        }
        return [
            'language' => (string) ($record['language'] ?? ''),
            'from' => isset($record['from']) && is_string($record['from']) ? $record['from'] : null,
            'applyId' => isset($record['applyId']) ? (string) $record['applyId'] : null,
        ];
    }

    /**
     * An inspect that must be clean — what every write is conditioned on. With `$tolerated` (the
     * drift a write began with), a difference from the design the write itself made is a problem too.
     *
     * @param string[]|null $tolerated
     */
    public function inspectClean(?string $requested = null, ?array $tolerated = null): array
    {
        $state = $this->inspect($requested);
        $made = $tolerated === null ? [] : array_values(array_diff($state['drift'], $tolerated));
        if ($state['problems'] !== [] || $made !== []) {
            throw new RuntimeException(implode('; ', array_merge($state['problems'], $made)));
        }
        return $state;
    }

    /**
     * One string for the state of every governed entity, in profile order: a post by its status
     * and the hash of its content, an option by its value, a template part by its content hash.
     * Any change to any of them is a new revision, which is what `expected_revision` compares to.
     */
    private function revision(array $rows): string
    {
        $parts = [];
        foreach ($this->entities() as $entity) {
            $key = (string) ($entity['key'] ?? '');
            $row = $rows[$key] ?? null;
            if ($row === null) {
                $parts[$key] = null;
            } elseif (($entity['kind'] ?? '') === 'option') {
                $parts[$key] = ['option', $row['value']];
            } elseif (($entity['kind'] ?? '') === 'templatePart') {
                $parts[$key] = ['templatePart', hash('sha256', (string) ($row['content'] ?? ''))];
            } else {
                $parts[$key] = [(string) ($row['post_status'] ?? ''), hash('sha256', (string) ($row['post_content'] ?? ''))];
            }
        }
        return hash('sha256', (string) json_encode($parts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Every file under the locked roots, hashed. An empty `files` (old profiles wrote `[]`) means
     * the lock carries no file hashes, and the roots are not walked.
     *
     * @return string[] problems
     */
    private function files(): array
    {
        $files = $this->lock['files'] ?? [];
        if (!is_array($files) || $files === []) {
            return [];
        }
        $problems = [];
        foreach ($files as $path => $hash) {
            $file = $this->root . '/' . $path;
            if (is_link($file) || !is_file($file) || !hash_equals((string) $hash, (string) hash_file('sha256', $file))) {
                $problems[] = 'Theme file changed: ' . $path;
            }
        }
        foreach ((array) ($this->lock['fileRoots'] ?? []) as $prefix) {
            $dir = $this->root . '/' . $prefix;
            if (!is_dir($dir)) {
                $problems[] = 'Theme folder missing: ' . $prefix;
                continue;
            }
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $relative = substr($file->getPathname(), strlen($this->root) + 1);
                if (!isset($files[$relative])) {
                    $problems[] = 'Unexpected theme file: ' . $relative;
                }
            }
        }
        return $problems;
    }

    /**
     * The one row an entity names, or why there is not exactly one.
     *
     * A page is (post type, slug, Polylang language). Without Polylang the language is not a fact
     * the site can state, so the slug must be unique on its own; with it, the row in the named
     * language is the one — which is how a translated site keeps `about` in `en` and `about` in
     * `de` apart.
     *
     * @return array{id?:int,row?:array,problem?:string}
     */
    private function find(string $kind, array $identity, int $boundId = 0): array
    {
        $found = $this->findBySlug($kind, $identity);
        // 🔒 A BOUND PAGE IS ITS ID, NOT ITS SLUG (Tracy ADR 0022). A slug may change through WordPress
        // itself; measured 26/09 on the local stand, renaming Services to industrial-services made every
        // apply on the site refuse "Missing or ambiguous entity (0 rows): page-services". The row the
        // site was bound to, still of its type, is the entity — and the new slug is a warning.
        if (isset($found['problem']) && $boundId > 0 && ($kind === 'page' || $kind === 'post')) {
            $row = $this->writer->read('post', $boundId);
            if ($row !== null && (string) ($row['post_type'] ?? '') === (string) ($identity['postType'] ?? $kind)) {
                return ['id' => $boundId, 'row' => $row, 'moved' => true];
            }
        }
        return $found;
    }

    private function findBySlug(string $kind, array $identity): array
    {
        if ($kind === 'option') {
            $name = (string) ($identity['name'] ?? '');
            $row = $name === '' ? null : $this->writer->read('option', 0, $name);
            return $row === null ? ['problem' => 'Missing option'] : ['id' => 0, 'row' => $row];
        }
        if ($kind === 'templatePart') {
            $slug = (string) ($identity['slug'] ?? '');
            $row = $slug === '' ? null : $this->writer->read('templatePart', 0, $slug);
            return $row === null ? ['problem' => 'Missing template part'] : ['id' => (int) $row['id'], 'row' => $row];
        }
        if ($kind !== 'page' && $kind !== 'post') {
            return ['problem' => 'Unknown entity kind ' . $kind];
        }
        $type = (string) ($identity['postType'] ?? $kind);
        $slug = (string) ($identity['slug'] ?? '');
        if ($slug === '' || !function_exists('get_posts')) {
            return ['problem' => 'Entity has no slug, or the site cannot be queried'];
        }
        // `lang => ''`: every language. Polylang narrows a query to the REQUEST's language through
        // `parse_query`, which `suppress_filters` does not switch off — once the site's default was
        // Vietnamese, inspect resolved 0 rows for every English entity (dev machine, 25/09/2026).
        $matches = get_posts([
            'post_type' => $type,
            'name' => $slug,
            'post_status' => 'any',
            'numberposts' => -1,
            'no_found_rows' => true,
            'suppress_filters' => true,
            'lang' => '',
        ]);
        $language = isset($identity['language']) ? (string) $identity['language'] : '';
        $ids = [];
        foreach ((array) $matches as $post) {
            $id = (int) (is_object($post) ? $post->ID : ($post['ID'] ?? 0));
            if ($id <= 0) {
                continue;
            }
            if ($language !== '' && self::polylang() !== null && self::postLanguage($id) !== $language) {
                continue;
            }
            $ids[] = $id;
        }
        if (count($ids) !== 1) {
            return ['problem' => 'Missing or ambiguous entity (' . count($ids) . ' rows)'];
        }
        $row = $this->writer->read('post', $ids[0]);
        return $row === null ? ['problem' => 'Entity vanished while reading'] : ['id' => $ids[0], 'row' => $row];
    }

    /** @return array<int,array<string,mixed>> */
    private function slotsFor(string $key): array
    {
        $out = [];
        foreach ($this->slots() as $slot) {
            if (($slot['entity'] ?? '') === $key) {
                $out[] = $slot;
            }
        }
        return $out;
    }

    /**
     * Is this option entity a site identity — every slot of it a `siteIdentity` field of one array
     * option? Such an option may be absent (the released archive does not carry it; the first apply
     * creates it) and may lack fields: both read empty, as the theme renders them (Tracy
     * `tasks/spec-danh-tinh-site-wordpress.md`: a missing field is empty, never the demo value).
     */
    private function isSiteIdentity(string $key): bool
    {
        $slots = $this->slotsFor($key);
        foreach ($slots as $slot) {
            if (($slot['siteIdentity'] ?? false) !== true || !isset($slot['target']['field'])) {
                return false;
            }
        }
        return $slots !== [];
    }

    /**
     * What an option slot holds now: the whole value, or one key of an array value. Null when not
     * text. A field a site identity does not hold is empty.
     */
    private function optionSlotValue($value, array $slot, bool $siteIdentity = false): ?string
    {
        if (isset($slot['target']['field'])) {
            $field = (string) $slot['target']['field'];
            if ($siteIdentity && is_array($value) && !array_key_exists($field, $value)) {
                return '';
            }
            $value = is_array($value) && array_key_exists($field, $value) ? $value[$field] : null;
        }
        return is_string($value) || is_numeric($value) ? (string) $value : null;
    }

    // ---- apply ------------------------------------------------------------------------------

    /**
     * What one apply would write, checked entirely before anything is. Every change is a known
     * slot with a value the rules accept, and the site is at the revision the caller saw — the
     * whole site's (`expected_revision`), or each touched content's as `content.read` listed it
     * (`expected_content_revisions`), or both.
     *
     * Every change is checked before any is refused: the refusal names each bad slot, not only
     * the first, because WordPress gives the apply no transaction and the only safe order is
     * "validate all, then write".
     *
     * @return array{operations:array<int,array{kind:string,id:int,key:string,fields:array}>,state:array}
     */
    public function plan(array $params): array
    {
        $state = $this->inspect();
        if ($state['problems'] !== []) {
            throw new ContractProblems(array_map(
                static fn(string $problem) => new ContractProblem(ContractProblem::PRESENTATION_DRIFT, $problem),
                $state['problems']
            ), $state['problems']);
        }
        $errors = [];
        $this->imageIds = [];
        $expected = $params['expected_revision'] ?? null;
        $perContent = $params['expected_content_revisions'] ?? null;
        if ($perContent !== null && !self::isRevisionMap($perContent)) {
            throw new ContractProblems([new ContractProblem(ContractProblem::CHANGES_INVALID,
                'expected_content_revisions must map content ids to revisions')]);
        }
        $perContent = $perContent === [] ? null : $perContent;
        if ($perContent === null || $expected !== null) {
            if (!is_string($expected) || !hash_equals($state['revision'], $expected)) {
                $errors[] = new ContractProblem(
                    $expected === null ? ContractProblem::REVISION_REQUIRED : ContractProblem::REVISION_STALE,
                    'Content changed; inspect again', null, null, ['current' => $state['revision']]
                );
            }
        }
        $changes = $params['changes'] ?? null;
        if (!is_array($changes) || $changes === [] || count($changes) > self::MAX_CHANGES) {
            $errors[] = new ContractProblem(ContractProblem::CHANGES_INVALID, 'Expected 1-' . self::MAX_CHANGES . ' scalar content changes');
            throw new ContractProblems($errors);
        }
        $slots = [];
        foreach ($this->slots() as $slot) {
            $slots[(string) $slot['key']] = $slot;
        }
        $entities = [];
        foreach ($this->entities() as $entity) {
            $entities[(string) $entity['key']] = $entity;
        }
        // Grouped per target row: one read and one write per post, however many of its slots move.
        $byTarget = [];
        // The row each change key lands on, for the per-content revision check and to name the
        // content an error is about.
        $owners = [];
        foreach ($changes as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                $errors[] = new ContractProblem(ContractProblem::CHANGES_INVALID, 'Unknown content slot or non-string value: ' . (string) $key, is_string($key) ? $key : null);
                continue;
            }
            $locale = null;
            $slotKey = $key;
            if (strpos($key, '::') !== false) {
                [$locale, $slotKey] = explode('::', $key, 2);
            }
            if (!isset($slots[$slotKey])) {
                $errors[] = new ContractProblem(ContractProblem::SLOT_UNKNOWN, 'Unknown content slot: ' . $key, $key);
                continue;
            }
            $slot = $slots[$slotKey];
            if (in_array((string) $slot['key'], $state['unreadable'] ?? [], true)) {
                $errors[] = new ContractProblem(ContractProblem::SLOT_UNWRITABLE, 'This slot cannot be read on the site now; read the content again: ' . $key, $key);
                continue;
            }
            if (in_array((string) $slot['entity'], $state['missing'] ?? [], true)) {
                $errors[] = new ContractProblem(ContractProblem::SLOT_UNKNOWN, 'The page or part that held this slot is not on the site any more: ' . $key, $key);
                continue;
            }
            $edition = null;
            $target = null;
            try {
                $target = $this->changeTarget($key, $locale, $slot, $entities[(string) $slot['entity']], $state);
                $owners[$key] = ['kind' => $target['row']['kind'], 'id' => $target['row']['id'], 'key' => $target['row']['key']];
            } catch (ContractProblem $e) {
                $edition = $e;
            }
            try {
                $this->checkValue($slot, $value, $params, $key);
            } catch (ContractProblem $e) {
                $errors[] = $e;
                continue;
            }
            if ($edition !== null) {
                $errors[] = $edition;
                continue;
            }
            $byTarget[$target['name']] = $byTarget[$target['name']] ?? $target['row'] + ['changes' => []];
            $byTarget[$target['name']]['changes'][] = [$slot, $value, $key];
        }

        $contentIds = [];
        if ($perContent !== null) {
            $contentIds = $this->checkContentRevisions($owners, $perContent, $expected !== null, $errors);
            foreach ($errors as $error) {
                if ($error->slotKey !== null && $error->contentId === null && isset($contentIds[$error->slotKey])) {
                    $error->contentId = $contentIds[$error->slotKey];
                }
            }
        }
        $this->checkEditLocks($owners, $contentIds, $errors);
        if ($errors !== []) {
            throw new ContractProblems($errors);
        }

        $operations = [];
        if ($this->derived) {
            $operations = $this->derivedOperations($byTarget, $state, $errors);
            $byTarget = [];
        }
        foreach ($byTarget as $target) {
            if ($target['kind'] === 'optionTranslation') {
                $new = null;
                foreach ($target['changes'] as [, $value]) {
                    $new = $value;
                }
                $operations[] = ['kind' => 'optionTranslation', 'id' => 0, 'key' => $target['key'], 'locale' => $target['locale'], 'fields' => ['value' => (string) $new]];
                continue;
            }
            if ($target['kind'] === 'option') {
                $current = $this->writer->read('option', 0, $target['key']);
                $value = $current === null ? null : $current['value'];
                $next = $value;
                foreach ($target['changes'] as [$slot, $new]) {
                    if (isset($slot['target']['field'])) {
                        if (!is_array($next)) {
                            $next = [];
                        }
                        $next[(string) $slot['target']['field']] = $new;
                    } else {
                        $next = $new;
                    }
                }
                if ($next !== $value) {
                    $operations[] = ['kind' => 'option', 'id' => 0, 'key' => $target['key'], 'fields' => ['value' => $next]];
                }
                continue;
            }
            if ($target['kind'] === 'templatePart') {
                $row = $this->writer->read('templatePart', 0, $target['key']);
                $content = $row === null ? '' : (string) $row['content'];
                $field = 'content';
            } else {
                $row = $this->writer->read('post', $target['id']);
                if ($row === null) {
                    $errors[] = new ContractProblem(ContractProblem::CONTRACT_FAILED, 'Target post is missing: ' . $target['id']);
                    continue;
                }
                $content = (string) ($row['post_content'] ?? '');
                $field = 'post_content';
            }
            $next = $content;
            foreach ($target['changes'] as [$slot, $new, $key]) {
                try {
                    $next = self::setBlockValue($next, (string) ($slot['target']['block'] ?? ''), (string) ($slot['target']['attr'] ?? 'content'), $new,
                        ($slot['type'] ?? '') === 'image' ? ($this->imageIds[$new] ?? null) : null);
                } catch (RuntimeException $e) {
                    $errors[] = new ContractProblem(ContractProblem::CONTRACT_FAILED, $e->getMessage(), $key);
                }
            }
            if ($next !== $content) {
                $operations[] = ['kind' => $target['kind'], 'id' => $target['id'], 'key' => $target['key'], 'fields' => [$field => $next]];
            }
        }
        if ($errors !== []) {
            throw new ContractProblems($errors);
        }
        return ['operations' => $operations, 'state' => $state];
    }

    /**
     * The row one change key lands on: `name` groups changes per row, `row` starts that group.
     * Throws the edition refusals by name, before any write is planned.
     *
     * @return array{name:string,row:array{kind:string,id:int,key:string,locale?:string}}
     */
    private function changeTarget(string $key, ?string $locale, array $slot, array $entity, array $state): array
    {
        if ($this->derived) {
            return $this->derivedTarget($key, $locale, $entity);
        }
        $entityKey = (string) $slot['entity'];
        $kind = (string) $entity['kind'];
        if ($locale !== null && $kind === 'option') {
            // `<locale>::site.tagline`: the words ONE language's front end shows for an option
            // Polylang serves through its string translations. The option row itself has no
            // edition; a translated option without a field is the only kind that has one.
            $optionName = (string) ($entity['identity']['name'] ?? '');
            if (!self::isTranslatedOption($optionName) || isset($slot['target']['field'])) {
                throw new ContractProblem(ContractProblem::SLOT_EDITION_MISSING, 'An option has no edition: ' . $key, $key);
            }
            if ($this->editions === null || !isset($this->editions['locales'][$locale])) {
                throw new ContractProblem(ContractProblem::SLOT_EDITION_MISSING, 'No ' . ($locale === '' ? '?' : $locale) . ' edition of ' . $entityKey . ': ' . $key, $key);
            }
            return ['name' => 'optionTranslation:' . $optionName . ':' . $locale,
                'row' => ['kind' => 'optionTranslation', 'id' => 0, 'key' => $optionName, 'locale' => $locale]];
        }
        if ($locale !== null) {
            $copy = $this->editions['locales'][$locale]['ids'][$entityKey] ?? null;
            if ($this->editions === null || !is_int($copy) && !ctype_digit((string) $copy)) {
                throw new ContractProblem(ContractProblem::SLOT_EDITION_MISSING, 'No ' . ($locale === '' ? '?' : $locale) . ' edition of ' . $entityKey . ': ' . $key, $key);
            }
            // An edition can ship without a block the source has (a translation that dropped
            // a section). The profile names those; a change aimed at one is refused by name
            // rather than found missing halfway through a write.
            $missing = $this->editions['locales'][$locale]['missing'] ?? [];
            if (is_array($missing) && in_array($entityKey . ':' . (string) ($slot['target']['block'] ?? ''), $missing, true)) {
                throw new ContractProblem(ContractProblem::SLOT_EDITION_MISSING, 'The ' . $locale . ' edition has no block for ' . $key, $key);
            }
            return ['name' => 'post:' . (int) $copy, 'row' => ['kind' => 'post', 'id' => (int) $copy, 'key' => '']];
        }
        if ($kind === 'option') {
            $name = (string) $entity['identity']['name'];
            return ['name' => 'option:' . $name, 'row' => ['kind' => 'option', 'id' => 0, 'key' => $name]];
        }
        if ($kind === 'templatePart') {
            $slug = (string) $entity['identity']['slug'];
            return ['name' => 'templatePart:' . $slug, 'row' => ['kind' => 'templatePart', 'id' => (int) $state['ids'][$entityKey], 'key' => $slug]];
        }
        return ['name' => 'post:' . $state['ids'][$entityKey], 'row' => ['kind' => 'post', 'id' => (int) $state['ids'][$entityKey], 'key' => '']];
    }

    /**
     * Refuses every change key whose row someone has open in the WordPress editor, before any
     * write is planned, so a locked page refuses the whole apply and the rows beside it stay
     * untouched. Only posts and database template parts carry an editor lock; an option or a
     * theme-file part has none. The content id is named even when the caller held the apply by
     * the site-wide revision alone, so the agent can say which page to ask about.
     *
     * @param array<string,array{kind:string,id:int,key:string}> $owners
     * @param array<string,string> $contentIds change key → content id, as far as already known
     * @param ContractProblem[] $errors
     */
    private function checkEditLocks(array $owners, array $contentIds, array &$errors): void
    {
        $locks = [];
        $locked = [];
        foreach ($owners as $key => $owner) {
            $id = (int) $owner['id'];
            if ($id <= 0 || !in_array($owner['kind'], ['post', 'templatePart', 'postmeta'], true)) {
                continue;
            }
            if (!array_key_exists($id, $locks)) {
                $locks[$id] = $this->writer->editLock($id);
            }
            if ($locks[$id] !== null) {
                $locked[(string) $key] = $owner;
            }
        }
        if ($locked === []) {
            return;
        }
        $unnamed = array_diff_key($locked, $contentIds);
        if ($unnamed !== []) {
            foreach ($this->revisionsOf($unnamed) ?? [] as $key => $found) {
                if (is_array($found)) {
                    $contentIds[(string) $key] = $found['id'];
                }
            }
        }
        foreach ($locked as $key => $owner) {
            $lock = $locks[(int) $owner['id']];
            $row = $this->writer->read('post', (int) $owner['id']);
            $errors[] = new ContractProblem(ContractProblem::SLOT_LOCKED_BY_USER, EditLock::message((string) ($row['post_title'] ?? ''), $lock),
                $key, $contentIds[$key] ?? null, ['lockedBy' => $lock]);
        }
    }

    /**
     * The same refusal for an operation that writes whole posts rather than slots — a demo trim,
     * a retire or restore of editions: every post it is about to write is asked BEFORE the first
     * moves, and one open in the editor refuses the whole call with SLOT_LOCKED_BY_USER, its
     * content id when the reader lists it, and `lockedBy`. No slot key: the operation named none.
     * A batched operation asks per call, about the rows that call would write.
     *
     * @param int[] $postIds
     * @throws ContractProblems
     */
    public function refuseEditLocked(array $postIds): void
    {
        $locked = [];
        foreach (array_unique(array_map('intval', $postIds)) as $id) {
            if ($id <= 0) {
                continue;
            }
            $lock = $this->writer->editLock($id);
            if ($lock !== null) {
                $locked[$id] = $lock;
            }
        }
        if ($locked === []) {
            return;
        }
        $targets = [];
        foreach (array_keys($locked) as $id) {
            $targets[$id] = ['kind' => 'post', 'id' => $id, 'key' => ''];
        }
        $found = $this->revisionsOf($targets) ?? [];
        $errors = [];
        foreach ($locked as $id => $lock) {
            $row = $this->writer->read('post', $id);
            $contentId = isset($found[$id]) && is_array($found[$id]) ? (string) $found[$id]['id'] : null;
            $errors[] = new ContractProblem(ContractProblem::SLOT_LOCKED_BY_USER, EditLock::message((string) ($row['post_title'] ?? ''), $lock),
                null, $contentId, ['lockedBy' => $lock]);
        }
        throw new ContractProblems($errors);
    }

    /** `{contentId: revision}`, both strings, as `content.read` hands them out. */
    private static function isRevisionMap($map): bool
    {
        if (!is_array($map)) {
            return false;
        }
        foreach ($map as $id => $revision) {
            if (!is_string($id) || $id === '' || !is_string($revision) || $revision === '') {
                return false;
            }
        }
        return true;
    }

    /**
     * Each change key against the revision its content had when the caller read it. Appends
     * the refusals to `$errors`; answers the content id of every key the reader resolved.
     *
     * A row the reader lists no content for (no content identity yet) cannot be held by id: it
     * passes only when the site-wide `expected_revision` was also sent and matched.
     *
     * @param array<string,array{kind:string,id:int,key:string}> $owners
     * @param array<string,string> $expected
     * @param ContractProblem[] $errors
     * @return array<string,string> change key → content id
     */
    private function checkContentRevisions(array $owners, array $expected, bool $siteRevisionSent, array &$errors): array
    {
        if ($owners === []) {
            return [];
        }
        $siteRevisionHeld = $siteRevisionSent && !array_filter($errors, static fn(ContractProblem $e) => $e->slotKey === null
            && in_array($e->errorCode, [ContractProblem::REVISION_STALE, ContractProblem::REVISION_REQUIRED], true));
        $current = $this->revisionsOf($owners);
        if ($current === null) {
            $errors[] = new ContractProblem(ContractProblem::CONTRACT_FAILED, 'This site cannot read content revisions; send expected_revision instead');
            return [];
        }
        $ids = [];
        foreach ($owners as $key => $owner) {
            $found = $current[$key] ?? null;
            if ($found === null) {
                if (!$siteRevisionHeld) {
                    $errors[] = new ContractProblem(ContractProblem::CONTRACT_FAILED, 'No content.read id holds ' . $key . '; send expected_revision', $key);
                }
                continue;
            }
            $ids[$key] = $found['id'];
            $given = $expected[$found['id']] ?? null;
            if ($given === null) {
                $errors[] = new ContractProblem(ContractProblem::REVISION_REQUIRED, 'Revision of ' . $found['id'] . ' required: ' . $key,
                    $key, $found['id'], ['current' => $found['revision']]);
            } elseif (!hash_equals($found['revision'], $given)) {
                $errors[] = new ContractProblem(ContractProblem::REVISION_STALE, 'Content ' . $found['id'] . ' changed; read it again: ' . $key,
                    $key, $found['id'], ['current' => $found['revision']]);
            }
        }
        return $ids;
    }

    /**
     * The `content.read` id and revision of each row, from the reader — or null when no reader
     * is wired or it cannot answer on this site (no content identity, an object cache).
     *
     * @param array<int|string,array{kind:string,id:int,key:string}> $targets
     * @return array<int|string,?array{id:string,revision:string}>|null
     */
    public function revisionsOf(array $targets): ?array
    {
        if ($this->revisions === null) {
            return null;
        }
        try {
            return $this->revisions->of($targets);
        } catch (Throwable $e) {
            return null;
        }
    }

    /** Scalar content rules, applied identically to a source slot and to an edition of it. */
    private function checkValue(array $slot, string $value, array $params, string $key): void
    {
        if ($this->derived && in_array($slot['type'] ?? '', ['url', 'image'], true)) {
            // First, as for every other value: a link or a picture a derived slot holds may sit in a
            // shortcode or a block comment, which LeafCodec writes as it is, unescaped.
            if (preg_match('/[<>\x00-\x08\x0b\x0c\x0e-\x1f]/u', $value)) {
                throw new ContractProblem(ContractProblem::SLOT_NOT_CONTENT, 'Markup and control characters are not content: ' . $key, $key);
            }
            if (IdentityTokens::hasDirective($value)) {
                throw new ContractProblem(ContractProblem::SLOT_NOT_CONTENT, 'Template directives are not content: ' . $key, $key);
            }
            $this->checkDerivedLink($slot, $value, $key);
            return;
        }
        if (($slot['type'] ?? '') === 'image') {
            $this->checkImage($slot, $value, $key);
            return;
        }
        if (!empty($slot['requiresEvidence']) && $value !== (string) ($slot['sample'] ?? '')) {
            $evidence = $params['evidence'][$key] ?? null;
            if (!is_string($evidence) || trim($evidence) === '' || strlen($evidence) > 8000) {
                throw new ContractProblem(ContractProblem::SLOT_EVIDENCE_REQUIRED, 'Customer evidence required: ' . $key, $key);
            }
        }
        if (preg_match('/[<>\x00-\x08\x0b\x0c\x0e-\x1f]/u', $value)) {
            throw new ContractProblem(ContractProblem::SLOT_NOT_CONTENT, 'Markup and control characters are not content: ' . $key, $key);
        }
        if (IdentityTokens::hasDirective($value)) {
            throw new ContractProblem(ContractProblem::SLOT_NOT_CONTENT, 'Template directives are not content: ' . $key, $key);
        }
        $max = (int) ($slot['maxCharacters'] ?? 1000);
        if (mb_strlen($value) > $max) {
            throw new ContractProblem(ContractProblem::SLOT_TOO_LONG, 'Content too long: ' . $key . ' (' . mb_strlen($value) . ' > ' . $max . ')', $key, null,
                ['limit' => $max, 'actual' => mb_strlen($value)]);
        }
        $isUrl = ($slot['type'] ?? '') === 'url' || ($slot['target']['attr'] ?? '') === 'url';
        if ($isUrl && $value !== '' && !$this->urlAllowed($value)) {
            throw new ContractProblem(ContractProblem::SLOT_LINK_UNSUPPORTED, 'Unsupported link: ' . $key, $key);
        }
    }

    /**
     * A picture for an image slot: a file in this site's uploads that the media library knows,
     * in the shape of the demo picture it replaces (±0.02, the Joomla receiver's tolerance).
     * Remembers the attachment id for the write.
     */
    private function checkImage(array $slot, string $value, string $key): void
    {
        $refuse = static function (string $why) use ($key): ContractProblem {
            return new ContractProblem(ContractProblem::SLOT_IMAGE_INVALID, $why . ': ' . $key, $key);
        };
        if (!preg_match(self::IMAGE_PATH, $value) || strpos($value, '..') !== false) {
            throw $refuse('Image must be a png, jpg or webp under wp-content/uploads/');
        }
        $found = $this->images === null ? null : $this->images->find($value);
        if ($found === null) {
            throw $refuse('Image must already be in the site media library');
        }
        $demo = is_string($slot['sample'] ?? null) && $this->images !== null ? $this->images->find((string) $slot['sample']) : null;
        if ($demo !== null && $demo['height'] > 0 && $found['height'] > 0
            && abs($demo['width'] / $demo['height'] - $found['width'] / $found['height']) > 0.02) {
            throw $refuse('Image aspect ratio does not match its slot');
        }
        $this->imageIds[$value] = $found['id'];
    }

    /** `https://`, `http://` on this site's own host, `mailto:`, `tel:`, a path, or an anchor. */
    private function urlAllowed(string $value): bool
    {
        if (strpos($value, '//') === 0 || strpos($value, '\\') !== false || preg_match('/\s/', $value)) {
            return false;
        }
        if (preg_match('~^(https://[^\s]+|mailto:[^\s]+|tel:[+0-9 ()-]+|/[a-zA-Z0-9/_?&=.%#-]*|#[a-zA-Z0-9_-]+)$~D', $value)) {
            return true;
        }
        if (strpos($value, 'http://') === 0) {
            if ($this->homeHost === null) {
                $home = $this->writer->read('option', 0, 'home');
                $this->homeHost = strtolower((string) parse_url((string) ($home['value'] ?? ''), PHP_URL_HOST));
            }
            $host = strtolower((string) parse_url($value, PHP_URL_HOST));
            return $this->homeHost !== '' && $host === $this->homeHost;
        }
        return false;
    }

    // ---- derived contracts ------------------------------------------------------------------
    //
    // An imported site has no profile: its map is its own rows, read through five codecs
    // (LeafCodec) and calibrated at derive time by its rendered pages (DerivedMap). A slot names a
    // row (`entity`), a column of it and a leaf path inside that column (`leaf`, null = the whole
    // column), so the same `inspect`, `plan` and Apply door serve it. Nothing about the design is
    // held: there is no lock, no file, no skeleton, and so no drift.

    /** Switch this object to the derived contract `$id`; the map is built when first needed. */
    private function useDerived(string $id, int $algorithm, ?array $built): void
    {
        $this->id = $id;
        $this->derived = true;
        $this->derivedReady = false;
        $this->derivedBuilt = $built;
        $this->manifest = ['id' => $id, 'mode' => 'derived', 'algorithm' => $algorithm];
        $this->map = [];
        $this->lock = [];
        $this->contractHash = self::derivedHash($algorithm);
        $this->demoTrim = null;
        $this->editions = null;
        $this->acceptedBases = [];
        $this->acceptedTrims = [];
    }

    /** What a derived binding is held to: the algorithm, never the rows, which every apply changes. */
    public static function derivedHash(int $algorithm = DerivedMap::ALGORITHM): string
    {
        return hash('sha256', (string) json_encode(DerivedMap::hashBasis($algorithm)));
    }

    /**
     * Build the derived map, once per object: the site's rows through DerivedMap, keeping only the
     * nested leaves the derive found on a page (`keep` in the binding; null keeps them all).
     */
    private function ensureDerived(): void
    {
        if (!$this->derived || $this->derivedReady) {
            return;
        }
        $built = $this->derivedBuilt;
        if ($built === null) {
            if ($this->derivedRows === null) {
                throw new ContractUnavailable('This receiver cannot read a derived contract');
            }
            $binding = $this->store->load() ?? [];
            $id = (string) $this->id;
            // Built with the rows that carry a slot, so the content reader finds a whole entry in the cache.
            $make = function () use ($binding, $id): array {
                return self::buildDerivedFor($this->writer, ($this->derivedRows)(), $binding, $id);
            };
            $built = $this->derivedCache !== null ? $this->derivedCache->get(self::derivedBasis($binding, $id), $make)['built'] : $make()['built'];
        }
        $this->manifest = $built['manifest'];
        $this->map = $built['map'];
        $this->derivedBuilt = null;
        $this->derivedReady = true;
    }

    /**
     * The rows a derived map may be made of, by the rules both the derive and every later read keep:
     *
     * - A row that does not read back through the writer exactly as the table holds it is left out
     *   and named in `$unresolved`: an option a filter answers for (`option_*`, `pre_option_*`, as
     *   Polylang and WPML strings do), a value a plugin wrote serialized by hand. Its leaf paths were
     *   read from bytes a write would not start from; one such row must not lock every other slot.
     * - Uncalibrated (no page could be fetched), the options are only the ones that are words by
     *   nature: blogname, blogdescription, theme_mods_*, widget_*. Posts, meta, terms and menus stay.
     *
     * @param list<array> $rows in DerivedMap's shape
     * @param list<string> $unresolved gains one line per row left out for not reading back
     */
    public static function prepareDerivedRows(SiteWriter $writer, array $rows, bool $calibrated, array &$unresolved): array
    {
        $out = [];
        foreach ($rows as $row) {
            $kind = (string) $row['kind'];
            if (!$calibrated && $kind === 'option' && !self::wordOption((string) ($row['identity']['name'] ?? ''))) {
                continue;
            }
            $entity = ['kind' => $kind, 'sourceId' => $row['id'], 'identity' => (array) $row['identity']];
            $read = self::derivedRow($writer, $entity);
            $differs = null;
            foreach (['core', 'html', 'nested'] as $class) {
                foreach ($row[$class] as $column => $value) {
                    if ($differs === null && ($read === null || self::derivedRaw($writer, $entity, $read, (string) $column) !== (string) $value)) {
                        $differs = (string) $column;
                    }
                }
            }
            if ($differs !== null) {
                $unresolved[] = $kind . ' ' . $row['id'] . '.' . $differs . ' does not read back as stored (a filter or a plugin rewrites it), not offered';
                continue;
            }
            $out[] = $row;
        }
        return $out;
    }

    /**
     * A derived map built from the site's rows batch by batch (DerivedMap::buildBatches), each batch
     * kept to what prepareDerivedRows() allows, so a large import is never held whole. `$rows` is a
     * list of rows (one batch), or a generator of batches that returns its own `unresolved` lines
     * (WordPressDerivedRows::batches).
     *
     * @param list<string> $unresolved gains the source's lines, then the rows left out for not reading back
     * @param list<string>|null $texts when an array, gains every candidate leaf's words (what derive counts against the pages)
     * @param array<string,array>|null $mapped when an array, gains the rows that carry a slot, by entity key
     * @return array{manifest:array,map:array}
     */
    public static function buildDerived(SiteWriter $writer, iterable $rows, bool $calibrated, ?array $seen, string $label, int $algorithm, ?array $keep,
        array &$unresolved, ?array &$texts = null, ?array &$mapped = null): array
    {
        $left = [];
        $collect = $texts !== null;
        $built = DerivedMap::buildBatches(is_array($rows) ? [$rows] : $rows, $seen, $label, $algorithm, $keep,
            static function (array $batch) use ($writer, $calibrated, &$left, &$texts, $collect): array {
                $batch = self::prepareDerivedRows($writer, $batch, $calibrated, $left);
                if ($collect) {
                    foreach ($batch as $row) {
                        foreach ($row['core'] as $value) {
                            $texts[] = (string) $value;
                        }
                        foreach ([$row['html'], $row['nested']] as $columns) {
                            foreach ($columns as $value) {
                                foreach (LeafCodec::leaves((string) $value) as $leaf) {
                                    $texts[] = $leaf['text'];
                                }
                            }
                        }
                    }
                }
                return $batch;
            }, $mapped);
        if ($rows instanceof Generator) {
            array_push($unresolved, ...array_map('strval', (array) $rows->getReturn()));
        }
        array_push($unresolved, ...$left);
        return $built;
    }

    /**
     * The map a read of a derived binding builds, without calibrating again (the derive's pages
     * chose `keep`), with the rows that carry a slot.
     *
     * @return array{built:array{manifest:array,map:array},rows:array<string,array>}
     */
    public static function buildDerivedFor(SiteWriter $writer, iterable $rows, array $binding, string $id): array
    {
        $basis = self::derivedBasis($binding, $id);
        $keep = isset($binding['keep']) && is_array($binding['keep']) ? array_values(array_map('strval', $binding['keep'])) : null;
        $unresolved = [];
        $texts = null;
        $mapped = [];
        $built = self::buildDerived($writer, $rows, $basis['calibrated'], null, substr($id, strlen('derived/')), $basis['algorithm'], $keep, $unresolved, $texts, $mapped);
        return ['built' => $built, 'rows' => $mapped];
    }

    /** What a built derived map depends on besides the rows: DerivedCache keys it by this and the tables' fingerprint. */
    public static function derivedBasis(array $binding, string $id): array
    {
        $keep = isset($binding['keep']) && is_array($binding['keep']) ? array_values(array_map('strval', $binding['keep'])) : null;
        return ['contract' => $id, 'algorithm' => (int) ($binding['algorithm'] ?? DerivedMap::ALGORITHM), 'calibrated' => ($binding['calibrated'] ?? true) !== false,
            'keep' => $keep === null ? null : hash('sha256', implode("\n", $keep))];
    }

    /** The options an uncalibrated derive keeps: the ones that hold a visitor's words by nature. */
    private static function wordOption(string $name): bool
    {
        return in_array($name, ['blogname', 'blogdescription'], true) || strpos($name, 'theme_mods_') === 0 || strpos($name, 'widget_') === 0;
    }

    /**
     * Bind the site to a derived map, replacing whatever derived binding stood (a re-derive moves it).
     * The caller has refused a quickstart binding already. `$record` adds requestId, derivedAt, keep
     * and the derive's answer (replayed as it was for the same requestId).
     *
     * @param array{manifest:array,map:array} $built
     */
    public function bindDerived(array $built, array $record): array
    {
        $this->useDerived((string) $built['manifest']['id'], (int) $built['manifest']['algorithm'], $built);
        $state = $this->inspectDerived(false);
        if ($state['problems'] !== []) {
            throw new RuntimeException('The derived map does not read back: ' . implode('; ', $state['problems']));
        }
        $binding = [
            'schemaVersion' => self::SCHEMA_VERSION,
            'contract' => $this->id,
            'contractHash' => $this->contractHash,
            'ids' => $state['ids'],
            'revision' => $state['revision'],
            'mode' => 'derived',
            'algorithm' => (int) $built['manifest']['algorithm'],
            'calibrated' => (bool) $built['manifest']['calibrated'],
            'demoTrim' => null,
            'sourceLanguage' => null,
            'siteLanguage' => null,
            'boundAt' => gmdate('c'),
        ] + $record;
        $this->store->replace($binding);
        return $binding;
    }

    /**
     * The row, kind and type each of the given slot keys writes: what the render check after a
     * derived apply needs to find the page that shows the words.
     *
     * @return array<string,array{type:string,kind:string,id:int,identity:array,nested:bool}>
     */
    public function derivedOwners(array $slotKeys): array
    {
        if (!$this->derived) {
            return [];
        }
        $want = array_flip(array_map('strval', $slotKeys));
        $entities = array_column($this->entities(), null, 'key');
        $out = [];
        foreach ($this->slots() as $slot) {
            if (!isset($want[$slot['key']], $entities[$slot['entity']])) {
                continue;
            }
            $entity = $entities[$slot['entity']];
            $out[$slot['key']] = ['type' => (string) $slot['type'], 'kind' => (string) $entity['kind'], 'id' => (int) $entity['sourceId'],
                'identity' => (array) $entity['identity'], 'nested' => !empty($slot['nested'])];
        }
        return $out;
    }

    /**
     * inspect() for a derived contract, in the same shape, plus `slotDetails`: the map's slots with
     * their current value, since no profile file describes them to the caller. A row gone from the
     * site is drift (its slots cannot be written, every other slot can); a slot whose leaf cannot be
     * read is a problem.
     */
    private function inspectDerived(bool $checkBinding = true): array
    {
        $binding = $this->store->load();
        $problems = [];
        $drift = [];
        $missing = [];
        if ($checkBinding && $binding !== null && !hash_equals($this->contractHash, (string) ($binding['contractHash'] ?? ''))) {
            $problems[] = 'Installed content contract changed';
        }
        $ids = [];
        $rows = [];
        $entities = [];
        $slotValues = [];
        $details = [];
        $parts = [];
        $unreadable = [];
        foreach ($this->entities() as $entity) {
            $key = (string) $entity['key'];
            $kind = (string) $entity['kind'];
            $row = self::derivedRow($this->writer, $entity);
            if ($row === null) {
                $missing[] = $key;
                $drift[] = 'Derived entity disappeared: ' . $key;
                $parts[$key] = null;
                continue;
            }
            $rows[$key] = $row;
            $ids[$key] = (int) $entity['sourceId'];
            $entities[] = ['key' => $key, 'kind' => $kind, 'id' => (int) $entity['sourceId'],
                'language' => isset($entity['identity']['language']) ? (string) $entity['identity']['language'] : null,
                'status' => $kind === 'post' ? (string) ($row['post_status'] ?? 'publish') : null];
            $raws = [];
            foreach ($this->slotsFor($key) as $slot) {
                $column = (string) $slot['column'];
                $raws[$column] ??= self::derivedRaw($this->writer, $entity, $row, $column);
                try {
                    $current = $slot['leaf'] === null ? $raws[$column] : LeafCodec::get($raws[$column], (string) $slot['leaf']);
                } catch (LeafCodecError $e) {
                    // This slot only: the row moved under it (another writer, a filter). Every other
                    // slot stays writable; this one is refused by name until the next read drops it.
                    $unreadable[] = (string) $slot['key'];
                    $drift[] = 'Slot ' . $slot['key'] . ' cannot be read: ' . $e->getMessage();
                    continue;
                }
                $slotValues[$slot['key']] = $current;
                $details[] = ['key' => $slot['key'], 'entity' => $key, 'kind' => $kind, 'column' => $column, 'leaf' => $slot['leaf'],
                    'type' => $slot['type'], 'maxCharacters' => $slot['maxCharacters'], 'current' => $current];
            }
            // The revision holds the words the slots show, not whole columns: a counter or a cache kept
            // beside them in the same option must not make every read stale.
            $mine = [];
            foreach ($this->slotsFor($key) as $slot) {
                $mine[$slot['key']] = $slotValues[$slot['key']] ?? null;
            }
            $parts[$key] = [$kind === 'post' ? (string) ($row['post_status'] ?? '') : null, $mine];
        }
        return [
            'bound' => $binding !== null,
            'contract' => (string) $this->id,
            'revision' => hash('sha256', (string) json_encode($parts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            'ids' => $ids,
            'entities' => $entities,
            'slots' => $slotValues,
            'slotDetails' => $details,
            'imageSlots' => [],
            'rows' => $rows,
            'demoTrim' => null,
            'sourceLanguage' => null,
            'multilingual' => null,
            'siteLanguage' => null,
            'contractLineage' => null,
            'mode' => 'derived',
            'calibrated' => isset($binding['calibrated']) ? (bool) $binding['calibrated'] : null,
            'problems' => $problems,
            'drift' => $drift,
            'missing' => $missing,
            'unreadable' => $unreadable,
        ];
    }

    /** The row a derived entity names, through the writer (so a write reads back as it will be served), or null. */
    private static function derivedRow(SiteWriter $writer, array $entity): ?array
    {
        $id = (int) $entity['sourceId'];
        $identity = (array) $entity['identity'];
        switch ((string) $entity['kind']) {
            case 'post':
                return $writer->read('post', $id);
            case 'postmeta':
                return $writer->read('postmeta', (int) ($identity['postId'] ?? 0), (string) ($identity['key'] ?? ''));
            case 'option':
                return $writer->read('option', 0, (string) ($identity['name'] ?? ''));
            case 'term':
                return $writer->read('term', (int) ($identity['termId'] ?? $id), (string) ($identity['taxonomy'] ?? ''));
            case 'menuItem':
                return $writer->read('menuItem', $id);
        }
        return null;
    }

    /**
     * One column of a derived row as the site stores it — the bytes LeafCodec paths address. A meta or
     * an option is read unserialized by WordPress, so it is serialized back here: the slot's `ser:`
     * path was read from the raw row (WordPressDerivedRows), and a write must never serialize twice.
     */
    private static function derivedRaw(SiteWriter $writer, array $entity, array $row, string $column): string
    {
        switch ((string) $entity['kind']) {
            case 'postmeta':
            case 'option':
                return self::rawOf($row['value'] ?? '');
            case 'menuItem':
                if ($column === '_menu_item_url') {
                    return (string) ($row['url'] ?? '');
                }
                $title = (string) ($row['title'] ?? '');
                return $title !== '' ? $title : self::pointedTitle($writer, $row);
            case 'term':
                // Stored escaped (`A &amp; B`), shown decoded: the slot holds what a visitor reads.
                return $column === 'name' ? html_entity_decode((string) ($row['name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8') : (string) ($row[$column] ?? '');
        }
        return (string) ($row[$column] ?? '');
    }

    /** The title a menu entry without one shows: what it points at. */
    private static function pointedTitle(SiteWriter $writer, array $item): string
    {
        $object = (int) ($item['object_id'] ?? 0);
        if ($object <= 0) {
            return '';
        }
        if (($item['type'] ?? '') === 'post_type') {
            return (string) ($writer->read('post', $object)['post_title'] ?? '');
        }
        if (($item['type'] ?? '') === 'taxonomy') {
            return html_entity_decode((string) ($writer->read('term', $object, (string) ($item['object'] ?? ''))['name'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return '';
    }

    /** WordPress's `maybe_serialize`, without WordPress: what the database holds for a value read back. */
    public static function rawOf($value): string
    {
        if (is_array($value) || is_object($value)) {
            return serialize($value);
        }
        $value = (string) $value;
        // A string that itself looks serialized is stored serialized again (WordPress does the same).
        return LeafCodec::detect($value) === 'ser' ? serialize($value) : $value;
    }

    /** WordPress's `maybe_unserialize`, objects refused: what to hand the writer for raw bytes. */
    public static function valueOf(string $raw)
    {
        if (LeafCodec::detect($raw) !== 'ser') {
            return $raw;
        }
        return unserialize($raw, ['allowed_classes' => false]);
    }

    /** The row one change key of a derived contract lands on. A derived contract has no editions. */
    private function derivedTarget(string $key, ?string $locale, array $entity): array
    {
        if ($locale !== null) {
            throw new ContractProblem(ContractProblem::SLOT_EDITION_MISSING, 'A derived contract has no editions: ' . $key, $key);
        }
        $id = (int) $entity['sourceId'];
        $identity = (array) $entity['identity'];
        switch ((string) $entity['kind']) {
            case 'postmeta':
                $row = ['kind' => 'postmeta', 'id' => (int) $identity['postId'], 'key' => (string) $identity['key']];
                break;
            case 'option':
                $row = ['kind' => 'option', 'id' => 0, 'key' => (string) $identity['name']];
                break;
            case 'term':
                $row = ['kind' => 'term', 'id' => (int) ($identity['termId'] ?? $id), 'key' => (string) $identity['taxonomy']];
                break;
            case 'menuItem':
                $row = ['kind' => 'menuItem', 'id' => $id, 'key' => (string) $identity['menu']];
                break;
            default:
                $row = ['kind' => 'post', 'id' => $id, 'key' => ''];
        }
        return ['name' => $row['kind'] . ':' . $row['id'] . ':' . $row['key'], 'row' => $row + ['entity' => $entity]];
    }

    /**
     * The writes of a derived apply: per row, every changed leaf set in its column (LeafCodec
     * re-encodes each layer, and refuses a value that would not read back), then the columns handed
     * to the writer in the fields it takes for that kind.
     *
     * @param ContractProblem[] $errors
     */
    private function derivedOperations(array $byTarget, array $state, array &$errors): array
    {
        $operations = [];
        foreach ($byTarget as $target) {
            $entity = $target['entity'];
            $row = self::derivedRow($this->writer, $entity);
            if ($row === null) {
                $errors[] = new ContractProblem(ContractProblem::CONTRACT_FAILED, 'Target row is missing: ' . $entity['key']);
                continue;
            }
            $before = [];
            $after = [];
            foreach ($target['changes'] as [$slot, $new, $key]) {
                $column = (string) $slot['column'];
                $before[$column] ??= self::derivedRaw($this->writer, $entity, $row, $column);
                $after[$column] ??= $before[$column];
                if ($slot['type'] === 'image' && $new !== '') {
                    $new = $this->storedImage($new, (string) ($state['slots'][$key] ?? ''));
                }
                try {
                    $after[$column] = $slot['leaf'] === null ? $new : LeafCodec::set($after[$column], (string) $slot['leaf'], $new);
                } catch (LeafCodecError $e) {
                    $errors[] = new ContractProblem(ContractProblem::SLOT_UNWRITABLE, 'Content slot cannot be rewritten in place: ' . $key . ' (' . $e->getMessage() . ')', $key);
                }
            }
            $fields = [];
            foreach ($after as $column => $raw) {
                if ($raw === $before[$column]) {
                    continue;
                }
                switch ($target['kind']) {
                    case 'postmeta':
                    case 'option':
                        $fields['value'] = self::valueOf($raw);
                        break;
                    case 'menuItem':
                        $fields[$column === '_menu_item_url' ? 'url' : 'title'] = $raw;
                        break;
                    case 'term':
                        // Escaped as WordPress stores a name; wp_update_term's own escaping leaves an
                        // entity alone (no double encoding), so the stored bytes are the same either way.
                        $fields[$column] = $column === 'name' ? htmlspecialchars($raw, ENT_QUOTES, 'UTF-8', false) : $raw;
                        break;
                    default:
                        $fields[$column] = $raw;
                }
            }
            if ($fields !== []) {
                $operations[] = ['kind' => $target['kind'], 'id' => $target['id'], 'key' => $target['key'], 'fields' => $fields];
            }
        }
        return $operations;
    }

    /**
     * A derived link: `https://…`, `http://…`, a path, a query, `mailto:`, `tel:` or an anchor, at most
     * DERIVED_LINK_BYTES; never protocol-relative, a backslash, a space or a script. A derived picture:
     * a png, jpg, webp, gif, avif or svg already in this site's uploads, named by its path, root-relative
     * or with this site's own origin. An imported slot has no captured design, so no shape is held. SVG
     * is allowed because the file must already be there: the site's own upload, never one this door
     * writes (as on Joomla, where the file must already be under images/).
     */
    private function checkDerivedLink(array $slot, string $value, string $key): void
    {
        if (strlen($value) > self::DERIVED_LINK_BYTES) {
            throw new ContractProblem(ContractProblem::SLOT_TOO_LONG, 'Content too long: ' . $key, $key, null,
                ['limit' => self::DERIVED_LINK_BYTES, 'actual' => strlen($value)]);
        }
        if ($value === '') {
            return;
        }
        if ($slot['type'] === 'url') {
            if (strpos($value, '//') === 0 || strpos($value, '\\') !== false
                || !preg_match('~^(https?://\S+|mailto:\S+|tel:[+0-9 ()-]+|/\S*|\?\S*|#[a-zA-Z0-9_-]+)$~D', $value)) {
                throw new ContractProblem(ContractProblem::SLOT_LINK_UNSUPPORTED, 'Unsupported link: ' . $key, $key);
            }
            return;
        }
        $path = $this->uploadPath($value);
        if ($path === null || !is_file($this->root . '/' . $path)) {
            throw new ContractProblem(ContractProblem::SLOT_IMAGE_INVALID, 'Image must already be in this site\'s uploads (wp-content/uploads/…): ' . $key, $key);
        }
        $resolved = realpath($this->root . '/' . $path);
        $uploads = realpath($this->root . '/wp-content/uploads');
        if ($resolved === false || $uploads === false || strpos($resolved, $uploads . DIRECTORY_SEPARATOR) !== 0) {
            throw new ContractProblem(ContractProblem::SLOT_IMAGE_INVALID, 'Image escapes the site uploads: ' . $key, $key);
        }
    }

    /** A picture value reduced to its path under the webroot, or null when it is not one of this site's uploads. */
    private function uploadPath(string $value): ?string
    {
        if (preg_match('~^https?://~i', $value)) {
            if ($this->homeHost === null) {
                $home = $this->writer->read('option', 0, 'home');
                $this->homeHost = strtolower((string) parse_url((string) ($home['value'] ?? ''), PHP_URL_HOST));
            }
            if ($this->homeHost === '' || strtolower((string) parse_url($value, PHP_URL_HOST)) !== $this->homeHost) {
                return null;
            }
            $value = (string) parse_url($value, PHP_URL_PATH);
        }
        $path = ltrim($value, '/');
        if (strpos($path, '..') !== false || !preg_match('~^wp-content/uploads/[A-Za-z0-9/_.-]+\.(png|jpe?g|webp|gif|avif|svg)$~iD', $path)) {
            return null;
        }
        return $path;
    }

    /** A derived picture written in the form its slot already holds: with the site's origin, root-relative, or bare. */
    private function storedImage(string $value, string $old): string
    {
        $path = $this->uploadPath($value) ?? ltrim($value, '/');
        if (preg_match('~^(https?://[^/]+)/~i', $old, $m)) {
            return $m[1] . '/' . $path;
        }
        return (strpos($old, '/') === 0 ? '/' : '') . $path;
    }

    // ---- block slots ------------------------------------------------------------------------

    /** `&`, `<`, `>`, `"` and `'` as entities — what the seeder writes into a block's text. */
    public static function escapeHtml(string $value): string
    {
        return strtr($value, ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;', '"' => '&quot;', "'" => '&#39;']);
    }

    /**
     * Find the block named `$name` (its `metadata.name` in the opening comment) and return the
     * opening comment match, the inner markup and where it sits.
     *
     * @return array{opening:string,ns:string,block:string,attrs:string,start:int,innerStart:int,innerEnd:int}
     */
    private static function findBlock(string $markup, string $name): array
    {
        $open = '/<!-- wp:([a-z0-9-]+\/)?([a-z0-9-]+) (\{[^\n]*"name":"' . preg_quote($name, '/') . '"[^\n]*\}) -->/';
        if (!preg_match($open, $markup, $m, PREG_OFFSET_CAPTURE)) {
            throw new RuntimeException('block ' . $name . ' is not in this pattern');
        }
        $ns = isset($m[1]) && $m[1][1] !== -1 ? $m[1][0] : '';
        $block = $m[2][0];
        $start = $m[0][1];
        $innerStart = $start + strlen($m[0][0]);
        $closeTag = '<!-- /wp:' . $ns . $block . ' -->';
        $innerEnd = strpos($markup, $closeTag, $innerStart);
        if ($innerEnd === false) {
            throw new RuntimeException('block ' . $name . ' has no closing comment');
        }
        return [
            'opening' => $m[0][0], 'ns' => $ns, 'block' => $block, 'attrs' => $m[3][0],
            'start' => $start, 'innerStart' => $innerStart, 'innerEnd' => $innerEnd,
        ];
    }

    /**
     * Write one value into one block, leaving every byte around it alone. The same algorithm as
     * the seeder's `setBlockValue` (TCH `apply-wordpress-contract.mjs`), so a skeleton hashed on
     * either side is the same hash.
     */
    public static function setBlockValue(string $markup, string $name, string $attr, string $value, ?int $imageId = null): string
    {
        $found = self::findBlock($markup, $name);
        $inner = substr($markup, $found['innerStart'], $found['innerEnd'] - $found['innerStart']);
        $opening = $found['opening'];
        if ($attr === 'content' || $attr === 'text') {
            $text = self::escapeHtml($value);
            $replaced = $found['block'] === 'button'
                ? preg_replace_callback('/(<a\b[^>]*>)[\s\S]*?(<\/a>)/', static function (array $m) use ($text): string {
                    return $m[1] . $text . $m[2];
                }, $inner, 1)
                : preg_replace_callback('/^(\s*<([a-z0-9]+)\b[^>]*>)[\s\S]*?(<\/\2>\s*)$/D', static function (array $m) use ($text): string {
                    return $m[1] . $text . $m[3];
                }, $inner, 1);
            if ($replaced === null) {
                throw new RuntimeException('block ' . $name . ' could not be rewritten');
            }
            if ($replaced === $inner && $text !== '' && strpos($inner, $text) === false) {
                throw new RuntimeException('block ' . $name . ' has no text element to write');
            }
            $inner = $replaced;
        } elseif ($attr === 'url') {
            $href = self::escapeHtml($value);
            $inner = (string) preg_replace_callback('/(<a\b[^>]*\bhref=")[^"]*(")/', static function (array $m) use ($href): string {
                return $m[1] . $href . $m[2];
            }, $inner, 1);
            if (strpos($inner, 'href="' . $href . '"') === false) {
                throw new RuntimeException('block ' . $name . ' has no link to write');
            }
            // Objects, not arrays: `{}` must come back as `{}`, and the seeder's JSON.stringify
            // does not escape `/` or non-ASCII, so neither does this.
            $attrs = json_decode($found['attrs'], false);
            if (is_object($attrs) && property_exists($attrs, 'url')) {
                $attrs->url = $value;
                $opening = '<!-- wp:' . $found['ns'] . $found['block'] . ' ' . json_encode($attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ' -->';
            }
        } elseif ($attr === 'src') {
            // A picture lives in three places: the img src, the block's `id` and the
            // `wp-image-N` class. Each is rewritten in place, so bytes around them never move.
            if (!preg_match('/<img\b[^>]*\bsrc="[^"]*"/', $inner)) {
                throw new RuntimeException('block ' . $name . ' has no picture to write');
            }
            if ($imageId === null) {
                throw new RuntimeException('block ' . $name . ' needs the attachment id of its picture');
            }
            $src = self::escapeHtml('/' . ltrim($value, '/'));
            $inner = (string) preg_replace_callback('/(<img\b[^>]*\bsrc=")[^"]*(")/', static function (array $m) use ($src): string {
                return $m[1] . $src . $m[2];
            }, $inner, 1);
            $inner = (string) preg_replace('/\bwp-image-\d+\b/', 'wp-image-' . $imageId, $inner, 1);
            $attrs = json_decode($found['attrs'], true);
            if (is_array($attrs) && isset($attrs['id']) && is_int($attrs['id'])) {
                $was = '"id":' . $attrs['id'];
                $at = strrpos($found['attrs'], $was);
                if ($at !== false) {
                    $rewritten = substr_replace($found['attrs'], '"id":' . $imageId, $at, strlen($was));
                    $opening = str_replace($found['attrs'], $rewritten, $opening);
                }
            }
        } else {
            throw new RuntimeException('unsupported block attribute ' . $attr);
        }
        return substr($markup, 0, $found['start']) . $opening . $inner . substr($markup, $found['innerEnd']);
    }

    /** The inverse of setBlockValue: the text of the element (or of a button's link), or the link's href. */
    public static function getBlockValue(string $markup, string $name, string $attr): string
    {
        $found = self::findBlock($markup, $name);
        $inner = substr($markup, $found['innerStart'], $found['innerEnd'] - $found['innerStart']);
        if ($attr === 'content' || $attr === 'text') {
            $pattern = $found['block'] === 'button'
                ? '/<a\b[^>]*>([\s\S]*?)<\/a>/'
                : '/^\s*<([a-z0-9]+)\b[^>]*>([\s\S]*?)<\/\1>\s*$/D';
            if (!preg_match($pattern, $inner, $m)) {
                throw new RuntimeException('block ' . $name . ' has no text element to read');
            }
            return html_entity_decode($found['block'] === 'button' ? $m[1] : $m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        if ($attr === 'url') {
            $attrs = json_decode($found['attrs'], true);
            if (is_array($attrs) && isset($attrs['url']) && is_string($attrs['url'])) {
                return $attrs['url'];
            }
            if (!preg_match('/<a\b[^>]*\bhref="([^"]*)"/', $inner, $m)) {
                throw new RuntimeException('block ' . $name . ' has no link to read');
            }
            return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        if ($attr === 'src') {
            if (!preg_match('/<img\b[^>]*\bsrc="([^"]*)"/', $inner, $m)) {
                throw new RuntimeException('block ' . $name . ' has no picture to read');
            }
            // The webroot path, whether the src was written root-relative or with the origin.
            $src = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $path = (string) (parse_url($src, PHP_URL_PATH) ?? '');
            return ltrim($path, '/');
        }
        throw new RuntimeException('unsupported block attribute ' . $attr);
    }

    // ---- visibility and Polylang ------------------------------------------------------------

    /**
     * One column, raw. Not `wp_update_post()`: that runs every save hook, mints a revision and
     * re-saves fields a demo post may not survive — and a demo trim changes nothing but whether
     * the post is served.
     */
    public static function setPostStatus(int $id, string $status): void
    {
        $db = $GLOBALS['wpdb'] ?? null;
        if (!is_object($db) || !method_exists($db, 'update')) {
            throw new RuntimeException('the database is not wired');
        }
        $table = isset($db->posts) ? (string) $db->posts : ((string) ($db->prefix ?? 'wp_')) . 'posts';
        $done = $db->update($table, ['post_status' => $status], ['ID' => $id], ['%s'], ['%d']);
        if ($done === false) {
            throw new RuntimeException('could not change the status of post ' . $id);
        }
        if (function_exists('clean_post_cache')) {
            clean_post_cache($id);
        }
    }

    /** Polylang's model when the plugin is active, else null. */
    public static function polylang()
    {
        if (!function_exists('PLL')) {
            return null;
        }
        $pll = PLL();
        return is_object($pll) && isset($pll->model) && is_object($pll->model) ? $pll->model : null;
    }

    public static function postLanguage(int $id): ?string
    {
        if (self::polylang() === null || !function_exists('pll_get_post_language')) {
            return null;
        }
        $language = pll_get_post_language($id);
        return is_string($language) && $language !== '' ? $language : null;
    }

    /** @return string[] Polylang slugs on this site, [] without Polylang */
    public static function languages(): array
    {
        if (self::polylang() === null || !function_exists('pll_languages_list')) {
            return [];
        }
        return array_values(array_map('strval', (array) pll_languages_list()));
    }

    /**
     * The options Polylang serves through its STRING translations rather than the option row:
     * on the front end `option_blogname` / `option_blogdescription` go through `pll__()`, which
     * answers the current language's entry for the option's value (the `polylang_mo` post of
     * each language). A customer's name written to the option alone is then shown only where
     * no entry matches; the archive's per-language entries keep serving the vendor's demo
     * words everywhere else.
     */
    public const TRANSLATED_OPTIONS = ['blogname', 'blogdescription'];

    /** @return string[] the demo values the profile's scalar slots of one option carry (`sample`), unique, in profile order */
    public function optionSamples(string $name): array
    {
        $entities = [];
        foreach ($this->entities() as $entity) {
            if (($entity['kind'] ?? '') === 'option' && (string) ($entity['identity']['name'] ?? '') === $name) {
                $entities[(string) $entity['key']] = true;
            }
        }
        $samples = [];
        foreach ($this->slots() as $slot) {
            if (!isset($entities[(string) ($slot['entity'] ?? '')]) || isset($slot['target']['field'])) {
                continue;
            }
            $sample = $slot['sample'] ?? null;
            if (is_string($sample) && $sample !== '' && !in_array($sample, $samples, true)) {
                $samples[] = $sample;
            }
        }
        return $samples;
    }

    /** Whether this site can hold Polylang string translations: the plugin, its languages and its `PLL_MO` class. */
    private static function stringTranslationsAvailable(): bool
    {
        return class_exists('PLL_MO') && function_exists('pll_languages_list') && self::languages() !== [];
    }

    /** Whether Polylang serves this option through its string translations (see TRANSLATED_OPTIONS). */
    public static function isTranslatedOption(string $name): bool
    {
        return in_array($name, self::TRANSLATED_OPTIONS, true);
    }

    /**
     * Polylang's language OBJECT for a slug — what `PLL_MO::import_from_db()` / `export_to_db()`
     * take (they read `->slug` and `->term_id`). Polylang 3.8 measured on 25/09/2026: handed the
     * slug string instead, both emit "Attempt to read property on string" and read/write NOTHING,
     * so every translation write below was a silent no-op while the tests' fake accepted the string.
     */
    private static function languageObject(string $slug): ?object
    {
        $model = self::polylang();
        if ($model === null) {
            return null;
        }
        $row = null;
        if (isset($model->languages) && is_object($model->languages) && method_exists($model->languages, 'get')) {
            $row = $model->languages->get($slug);
        } elseif (method_exists($model, 'get_language')) {
            $row = $model->get_language($slug);
        }
        return is_object($row) && isset($row->slug, $row->term_id) ? $row : null;
    }

    /** The languages a translation write reaches: the named ones the site has, or every language of the site. */
    private static function translationLanguages(?array $languages): array
    {
        $known = self::languages();
        if ($languages === null) {
            return $known;
        }
        return array_values(array_filter(array_map('strval', $languages), static fn(string $slug): bool => in_array($slug, $known, true)));
    }

    /**
     * What every language's string translation of each original says now: `[slug => [original
     * => translation|null]]`, null for an original that language has no entry for. `[]` without
     * Polylang, so a site without it records nothing and restores nothing.
     *
     * @param string[] $originals
     * @return array<string,array<string,string|null>>
     */
    public static function stringTranslationsBefore(array $originals, ?array $languages = null): array
    {
        if ($originals === [] || !self::stringTranslationsAvailable()) {
            return [];
        }
        $before = [];
        foreach (self::translationLanguages($languages) as $slug) {
            $language = self::languageObject($slug);
            if ($language === null) {
                continue;
            }
            $mo = new PLL_MO();
            $mo->import_from_db($language);
            $before[$slug] = [];
            foreach ($originals as $original) {
                $entry = $mo->entries[$original] ?? null;
                $before[$slug][$original] = is_object($entry) && isset($entry->translations[0]) ? (string) $entry->translations[0] : null;
            }
        }
        return $before;
    }

    /**
     * Give every language the same string translation of each original. Polylang keys an
     * entry by the string it translates, so the option's value before the write, the archive's
     * demo value and the new value each get one: whichever of them the front end looks up
     * (Polylang re-keys the entry to the new value when its own option hook ran during the
     * write) answers the customer's words.
     *
     * @param string[] $originals
     */
    public static function stringTranslationsWrite(array $originals, string $value, ?array $languages = null): void
    {
        if ($originals === [] || !self::stringTranslationsAvailable()) {
            return;
        }
        foreach (self::translationLanguages($languages) as $slug) {
            $language = self::languageObject($slug);
            if ($language === null) {
                continue;
            }
            $mo = new PLL_MO();
            $mo->import_from_db($language);
            foreach ($originals as $original) {
                $mo->add_entry($mo->make_entry($original, $value));
            }
            $mo->export_to_db($language);
        }
    }

    /**
     * Put string translations back as `stringTranslationsBefore()` read them: an entry that was
     * absent goes absent again. A language the site no longer has is skipped.
     *
     * @param array<string,array<string,string|null>> $before
     */
    public static function stringTranslationsRestore(array $before): void
    {
        if ($before === [] || !self::stringTranslationsAvailable()) {
            return;
        }
        $known = self::languages();
        foreach ($before as $slug => $entries) {
            if (!is_array($entries) || !in_array((string) $slug, $known, true)) {
                continue;
            }
            $language = self::languageObject((string) $slug);
            if ($language === null) {
                continue;
            }
            $mo = new PLL_MO();
            $mo->import_from_db($language);
            foreach ($entries as $original => $translation) {
                if ($translation === null) {
                    unset($mo->entries[(string) $original]);
                } else {
                    $mo->add_entry($mo->make_entry((string) $original, (string) $translation));
                }
            }
            $mo->export_to_db($language);
        }
    }

    /** One Polylang language as a plain array (term_id, name, slug, locale, rtl, term_group, flag), or null. */
    public static function language(string $slug): ?array
    {
        $model = self::polylang();
        if ($model === null) {
            return null;
        }
        $row = null;
        if (isset($model->languages) && is_object($model->languages) && method_exists($model->languages, 'get')) {
            $row = $model->languages->get($slug);
        } elseif (method_exists($model, 'get_language')) {
            $row = $model->get_language($slug);
        }
        if (!is_object($row)) {
            return null;
        }
        return [
            'term_id' => (int) ($row->term_id ?? 0),
            'name' => (string) ($row->name ?? $slug),
            'slug' => (string) ($row->slug ?? $slug),
            'locale' => (string) ($row->locale ?? ''),
            'rtl' => (int) ($row->is_rtl ?? 0),
            'term_group' => (int) ($row->term_group ?? 0),
            'flag' => (string) ($row->flag_code ?? $slug),
        ];
    }

    /** Give one Polylang language another WordPress locale; the slug and everything else stay. */
    public static function setLanguageLocale(string $slug, string $locale): void
    {
        $model = self::polylang();
        $row = self::language($slug);
        if ($model === null || $row === null) {
            throw new RuntimeException('this site has no Polylang language ' . $slug);
        }
        $args = [
            'lang_id' => $row['term_id'], 'name' => $row['name'], 'slug' => $row['slug'], 'locale' => $locale,
            'rtl' => $row['rtl'], 'term_group' => $row['term_group'], 'flag' => $row['flag'],
        ];
        if (isset($model->languages) && is_object($model->languages) && method_exists($model->languages, 'update')) {
            $result = $model->languages->update($args);
        } elseif (method_exists($model, 'update_language')) {
            // Polylang 2.x. Kept because a customer's site is whatever version they installed.
            $result = $model->update_language($args);
        } else {
            throw new RuntimeException('this Polylang cannot update a language');
        }
        if (function_exists('is_wp_error') && is_wp_error($result)) {
            throw new RuntimeException($result->get_error_message());
        }
        if (isset($model->languages) && is_object($model->languages) && method_exists($model->languages, 'clean_cache')) {
            $model->languages->clean_cache();
        } elseif (method_exists($model, 'clean_languages_cache')) {
            $model->clean_languages_cache();
        }
    }
}
