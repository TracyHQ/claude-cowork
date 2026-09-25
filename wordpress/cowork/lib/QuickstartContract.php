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
    public const EDITIONS_SCHEMA = 'tracy-quickstart-editions/wordpress/v1';
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
    /** Options resolved once per inspect, so the URL allow-list does not read `home` per slot. */
    private ?string $homeHost = null;

    /**
     * @param string $root         The webroot (no trailing slash), where `fileRoots` are checked.
     * @param string $contractsDir `lib/contracts`, holding one directory per profile id.
     * @param string $configured   The `claude_cowork_contract` setting, or '' when none is set.
     */
    public function __construct(SiteWriter $writer, ContractStore $store, string $root, string $contractsDir, string $configured = '')
    {
        $this->writer = $writer;
        $this->store = $store;
        $this->root = rtrim($root, '/');
        $this->contractsDir = rtrim($contractsDir, '/');
        $this->configured = trim($configured);
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
        $this->contractHash = DemoTrimProfile::baseHash($directory);
        $this->demoTrim = null;
        $this->editions = null;

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
        return $this->map['entities'] ?? [];
    }

    /** @return array<int,array<string,mixed>> the content map's slots, in profile order */
    public function slots(): array
    {
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
        $this->store->replace($binding);
    }

    /** Put a record on the binding under one key (`demoTrim`, `sourceLanguage`, `siteLanguage`), or take it off with null. */
    public function record(string $field, ?array $record): void
    {
        $binding = $this->store->load();
        if ($binding === null) {
            throw new RuntimeException('A ' . $field . ' record needs a bound site');
        }
        $binding[$field] = $record;
        $this->store->replace($binding);
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
        $binding = $this->store->load();
        $problems = [];
        $this->homeHost = null;

        if ($binding !== null && !hash_equals($this->contractHash, (string) ($binding['contractHash'] ?? ''))) {
            $problems[] = 'Installed content contract changed';
        }
        $trim = isset($binding['demoTrim']) && is_array($binding['demoTrim']) ? $binding['demoTrim'] : null;
        if ($trim !== null) {
            if ($this->demoTrim === null) {
                $problems[] = 'This site has hidden demo rows but the plugin carries no demo-trim profile';
            } elseif (!hash_equals($this->demoTrim->hash(), (string) ($trim['profileHash'] ?? ''))) {
                $problems[] = 'The installed demo-trim profile changed';
            }
        }

        foreach ($this->files() as $problem) {
            $problems[] = $problem;
        }

        // Option values the lock pins. Old profiles spell the key `options`; both are read.
        $optionValues = $this->lock['optionValues'] ?? ($this->lock['options'] ?? []);
        foreach (is_array($optionValues) ? $optionValues : [] as $name => $want) {
            $have = $this->writer->read('option', 0, (string) $name);
            if ($have === null || (string) $have['value'] !== (string) $want) {
                $problems[] = 'Option ' . $name . ' differs from the lock';
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
                $problems[] = 'Template part ' . $slug . ' is missing (the theme file is in charge)';
            } elseif (!hash_equals((string) ($pin['sha256'] ?? ''), hash('sha256', (string) $part['content']))) {
                $problems[] = 'Template part ' . $slug . ' was edited';
            }
        }

        $ids = [];
        $rows = [];
        $entities = [];
        $slotValues = [];
        $lockEntities = isset($this->lock['entities']) && is_array($this->lock['entities']) ? $this->lock['entities'] : [];
        foreach ($this->entities() as $entity) {
            $key = (string) ($entity['key'] ?? '');
            $kind = (string) ($entity['kind'] ?? '');
            $identity = is_array($entity['identity'] ?? null) ? $entity['identity'] : [];
            $found = $this->find($kind, $identity);
            if (isset($found['problem'])) {
                $problems[] = $found['problem'] . ': ' . $key;
                continue;
            }
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
                        $problems[] = 'Entity ' . $key . ' has another ' . $field;
                    }
                }
                if (isset($pin['post_parent']) && (int) ($row['post_parent'] ?? 0) !== (int) $pin['post_parent']) {
                    $problems[] = 'Entity ' . $key . ' has another parent';
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
                        $problems[] = 'Entity ' . $key . ' has another status';
                    }
                }
            }

            $content = $kind === 'templatePart' ? (string) ($row['content'] ?? '') : (string) ($row['post_content'] ?? '');
            $slots = $this->slotsFor($key);
            if ($kind === 'option') {
                foreach ($slots as $slot) {
                    $value = $this->optionSlotValue($row['value'], $slot);
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
                    $masked = self::setBlockValue($masked, $block, $attr, self::SLOT_MASK);
                } catch (RuntimeException $e) {
                    $problems[] = 'Slot ' . $slot['key'] . ': ' . $e->getMessage();
                    $broken = true;
                }
            }
            if ($pin !== null && isset($pin['skeleton']) && !$broken
                && !hash_equals((string) $pin['skeleton'], hash('sha256', $masked))) {
                $problems[] = 'Entity ' . $key . ' differs from the lock outside its slots';
            }
        }

        return [
            'bound' => $binding !== null,
            'contract' => (string) $this->id,
            'revision' => $this->revision($rows),
            'ids' => $ids,
            'entities' => $entities,
            'slots' => $slotValues,
            'rows' => $rows,
            'demoTrim' => $trim,
            'sourceLanguage' => isset($binding['sourceLanguage']) && is_array($binding['sourceLanguage']) ? $binding['sourceLanguage'] : null,
            'multilingual' => self::multilingualState($binding),
            'siteLanguage' => self::siteLanguageState($binding),
            'problems' => $problems,
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

    /** An inspect that must be clean — what every write is conditioned on. */
    public function inspectClean(?string $requested = null): array
    {
        $state = $this->inspect($requested);
        if ($state['problems'] !== []) {
            throw new RuntimeException(implode('; ', $state['problems']));
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
    private function find(string $kind, array $identity): array
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

    /** What an option slot holds now: the whole value, or one key of an array value. Null when not text. */
    private function optionSlotValue($value, array $slot): ?string
    {
        if (isset($slot['target']['field'])) {
            $field = (string) $slot['target']['field'];
            $value = is_array($value) && array_key_exists($field, $value) ? $value[$field] : null;
        }
        return is_string($value) || is_numeric($value) ? (string) $value : null;
    }

    // ---- apply ------------------------------------------------------------------------------

    /**
     * What one apply would write, checked entirely before anything is. Every change is a known
     * slot with a value the rules accept, and the site is at the revision the caller saw.
     *
     * @return array{operations:array<int,array{kind:string,id:int,key:string,fields:array}>,state:array}
     */
    public function plan(array $params): array
    {
        $state = $this->inspectClean();
        $expected = $params['expected_revision'] ?? null;
        if (!is_string($expected) || !hash_equals($state['revision'], $expected)) {
            throw new RuntimeException('Content changed; inspect again');
        }
        $changes = $params['changes'] ?? null;
        if (!is_array($changes) || $changes === [] || count($changes) > self::MAX_CHANGES) {
            throw new RuntimeException('Expected 1-' . self::MAX_CHANGES . ' scalar content changes');
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
        foreach ($changes as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                throw new RuntimeException('Unknown content slot or non-string value: ' . (string) $key);
            }
            $locale = null;
            $slotKey = $key;
            if (strpos($key, '::') !== false) {
                [$locale, $slotKey] = explode('::', $key, 2);
            }
            if (!isset($slots[$slotKey])) {
                throw new RuntimeException('Unknown content slot: ' . $key);
            }
            $slot = $slots[$slotKey];
            $this->checkValue($slot, $value, $params, $key);
            $entityKey = (string) $slot['entity'];
            $entity = $entities[$entityKey];
            $kind = (string) $entity['kind'];
            if ($locale !== null) {
                if ($kind === 'option') {
                    throw new RuntimeException('An option has no edition: ' . $key);
                }
                $copy = $this->editions['locales'][$locale]['ids'][$entityKey] ?? null;
                if ($this->editions === null || !is_int($copy) && !ctype_digit((string) $copy)) {
                    throw new RuntimeException('No ' . ($locale === '' ? '?' : $locale) . ' edition of ' . $entityKey . ': ' . $key);
                }
                // An edition can ship without a block the source has (a translation that dropped
                // a section). The profile names those; a change aimed at one is refused by name
                // rather than found missing halfway through a write.
                $missing = $this->editions['locales'][$locale]['missing'] ?? [];
                if (is_array($missing) && in_array($entityKey . ':' . (string) ($slot['target']['block'] ?? ''), $missing, true)) {
                    throw new RuntimeException('The ' . $locale . ' edition has no block for ' . $key);
                }
                $target = 'post:' . (int) $copy;
                $byTarget[$target] = $byTarget[$target] ?? ['kind' => 'post', 'id' => (int) $copy, 'key' => '', 'changes' => []];
            } elseif ($kind === 'option') {
                $target = 'option:' . $entity['identity']['name'];
                $byTarget[$target] = $byTarget[$target] ?? ['kind' => 'option', 'id' => 0, 'key' => (string) $entity['identity']['name'], 'changes' => []];
            } elseif ($kind === 'templatePart') {
                $target = 'templatePart:' . $entity['identity']['slug'];
                $byTarget[$target] = $byTarget[$target] ?? ['kind' => 'templatePart', 'id' => (int) $state['ids'][$entityKey], 'key' => (string) $entity['identity']['slug'], 'changes' => []];
            } else {
                $target = 'post:' . $state['ids'][$entityKey];
                $byTarget[$target] = $byTarget[$target] ?? ['kind' => 'post', 'id' => (int) $state['ids'][$entityKey], 'key' => '', 'changes' => []];
            }
            $byTarget[$target]['changes'][] = [$slot, $value];
        }

        $operations = [];
        foreach ($byTarget as $target) {
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
                    throw new RuntimeException('Target post is missing: ' . $target['id']);
                }
                $content = (string) ($row['post_content'] ?? '');
                $field = 'post_content';
            }
            $next = $content;
            foreach ($target['changes'] as [$slot, $new]) {
                $next = self::setBlockValue($next, (string) ($slot['target']['block'] ?? ''), (string) ($slot['target']['attr'] ?? 'content'), $new);
            }
            if ($next !== $content) {
                $operations[] = ['kind' => $target['kind'], 'id' => $target['id'], 'key' => $target['key'], 'fields' => [$field => $next]];
            }
        }
        return ['operations' => $operations, 'state' => $state];
    }

    /** Scalar content rules, applied identically to a source slot and to an edition of it. */
    private function checkValue(array $slot, string $value, array $params, string $key): void
    {
        if (!empty($slot['requiresEvidence']) && $value !== (string) ($slot['sample'] ?? '')) {
            $evidence = $params['evidence'][$key] ?? null;
            if (!is_string($evidence) || trim($evidence) === '' || strlen($evidence) > 8000) {
                throw new RuntimeException('Customer evidence required: ' . $key);
            }
        }
        if (preg_match('/[<>\x00-\x08\x0b\x0c\x0e-\x1f]/u', $value)) {
            throw new RuntimeException('Markup and control characters are not content: ' . $key);
        }
        if (IdentityTokens::hasDirective($value)) {
            throw new RuntimeException('Template directives are not content: ' . $key);
        }
        $max = (int) ($slot['maxCharacters'] ?? 1000);
        if (mb_strlen($value) > $max) {
            throw new RuntimeException('Content too long: ' . $key . ' (' . mb_strlen($value) . ' > ' . $max . ')');
        }
        $isUrl = ($slot['type'] ?? '') === 'url' || ($slot['target']['attr'] ?? '') === 'url';
        if ($isUrl && $value !== '' && !$this->urlAllowed($value)) {
            throw new RuntimeException('Unsupported link: ' . $key);
        }
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
    public static function setBlockValue(string $markup, string $name, string $attr, string $value): string
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

    /**
     * What every language's string translation of each original says now: `[slug => [original
     * => translation|null]]`, null for an original that language has no entry for. `[]` without
     * Polylang, so a site without it records nothing and restores nothing.
     *
     * @param string[] $originals
     * @return array<string,array<string,string|null>>
     */
    public static function stringTranslationsBefore(array $originals): array
    {
        if ($originals === [] || !self::stringTranslationsAvailable()) {
            return [];
        }
        $before = [];
        foreach (self::languages() as $slug) {
            $mo = new PLL_MO();
            $mo->import_from_db($slug);
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
    public static function stringTranslationsWrite(array $originals, string $value): void
    {
        if ($originals === [] || !self::stringTranslationsAvailable()) {
            return;
        }
        foreach (self::languages() as $slug) {
            $mo = new PLL_MO();
            $mo->import_from_db($slug);
            foreach ($originals as $original) {
                $mo->add_entry($mo->make_entry($original, $value));
            }
            $mo->export_to_db($slug);
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
            $mo = new PLL_MO();
            $mo->import_from_db((string) $slug);
            foreach ($entries as $original => $translation) {
                if ($translation === null) {
                    unset($mo->entries[(string) $original]);
                } else {
                    $mo->add_entry($mo->make_entry((string) $original, (string) $translation));
                }
            }
            $mo->export_to_db((string) $slug);
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
