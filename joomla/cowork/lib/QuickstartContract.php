<?php
require_once __DIR__ . '/ContentSlots.php';
require_once __DIR__ . '/ContractRows.php';
require_once __DIR__ . '/ContractAccess.php';
require_once __DIR__ . '/MultilingualProfile.php';
require_once __DIR__ . '/LanguagePackCatalog.php';
require_once __DIR__ . '/MultilingualApply.php';
require_once __DIR__ . '/DemoTrimProfile.php';
require_once __DIR__ . '/ContractProblem.php';
require_once __DIR__ . '/Timing.php';

interface ContractStore {
    public function load(): ?array;
    public function save(array $binding): void;
    /** Replace a baseline wholesale. Only a validated language apply or its revert may call this. */
    public function replace(array $binding): void;
    /**
     * The language job in flight, or null.
     *
     * Separate from the baseline because it is a DIFFERENT kind of fact: the baseline says what the
     * site is, the job says what is being done to it. Keeping the two apart is what lets a crashed
     * run be read back — a half-finished language is a job with a phase, not a corrupt baseline.
     */
    public function job(): ?array;
    public function saveJob(?array $job): void;
    public function access(): array;
}

/** Trusted package data, never a caller-supplied field allowlist. */
final class QuickstartContract
{
    private array $manifest; private array $map; private array $lock;
    private SiteWriter $writer;
    private ContractStore $store;
    private string $root;
    /**
     * Why a missing profile is carried rather than thrown from the constructor: the caller decides
     * whether a site has a contract by whether it was handed one, and a receiver too old to carry
     * the profile a site was provisioned for would otherwise look like a site with NO contract —
     * which is the one state where every structural write is allowed. Refusing from every method
     * keeps that mistake closed and says which half is out of date.
     */
    private ?string $unavailable = null;
    private ?MultilingualProfile $multilingual = null;
    private ?LanguagePackCatalog $packs = null;
    private ?DemoTrimProfile $demoTrim = null;

    /**
     * 🔒 THE DESIGN IS A BASELINE TO COMPARE, NOT A LOCK (Tracy ADR 0022, 26/09/2026). A site may
     * change its template, layout, modules or files through Joomla itself; the contract then says
     * where it differs from the quickstart — as warnings — and keeps its own door working. What it
     * still refuses is a difference its OWN write made: the first inspect of a request records the
     * differences already there (`$tolerated`), and every later inspect in the same request — the
     * check after an apply, a revert, a language step — throws on any difference that was not.
     * `newRequest()`/`endRequest()` (Engine::handle) bound the request; outside one, an inspect only reports.
     *
     * @var list<string>|null
     */
    private ?array $tolerated = null;
    /** @var list<string> */
    private array $drift = [];

    /** Whether an Engine request is running: only then is a new difference a refusal. */
    private bool $inRequest = false;

    /** Start a request: the next inspect records what already differs. */
    public function newRequest(): void { $this->inRequest = true; $this->tolerated = null; }

    /** End it: an inspect outside a request only reports. */
    public function endRequest(): void { $this->inRequest = false; $this->tolerated = null; }

    /**
     * The differences from the quickstart's design this request began with — what an apply's answer
     * warns about, although its own write moved the baseline past them — or, outside a request, what
     * the last inspect found. @return list<string>
     */
    public function driftWarnings(): array { return $this->tolerated ?? $this->drift; }

    private function drift(string $message): void { $this->drift[] = $message; }

    /** After an inspect has collected its differences: remember them, or refuse the new ones. */
    private function settleDrift(): void {
        if (!$this->inRequest) return;
        if ($this->tolerated === null) { $this->tolerated = $this->drift; return; }
        $new = array_values(array_diff($this->drift, $this->tolerated));
        if ($new) throw new ContractProblem('PRESENTATION_DRIFT', $new[0]);
    }

    public function __construct(SiteWriter $writer, ContractStore $store, string $root, string $directory) {
        $this->writer=$writer;$this->store=$store;$this->root=$root;
        foreach (['manifest','content-map','presentation-lock'] as $name) {
            $file = $directory . '/' . $name . '.json';
            if (!is_file($file)) { $this->unavailable = 'This site names a content contract this receiver does not carry'; return; }
            $value = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            if ($name === 'manifest') $this->manifest=$value;
            elseif ($name === 'content-map') $this->map=$value;
            else $this->lock=$value;
        }
        // The multilingual extension is OPTIONAL and separately versioned: a contract published
        // before it exists keeps working untouched, and a receiver carrying it does not claim the
        // capability for a contract whose profile is absent.
        $profileFile = $directory . '/multilingual-map.json';
        // 🔒 THE CATALOG IS NOT CONTRACT BYTES, SO IT DOES NOT LIVE WITH THEM. `lib/contracts/<id>/`
        // holds what a SITE is held to — the files whose hashes are pinned and whose drift refuses
        // the site. A list of language packages this receiver may download is receiver-wide and
        // verifies nothing about the site, so it sits beside the engine instead. Keeping it in a
        // profile directory would have made the contract gate demand it be pinned as contract
        // bytes, which would be the gate telling the reader something untrue.
        $catalogFile = dirname($directory, 4) . '/language-packs.json';
        if (is_file($profileFile)) {
            $raw = file_get_contents($profileFile);
            $this->multilingual = new MultilingualProfile(
                json_decode($raw, true, 512, JSON_THROW_ON_ERROR), $this->map, $this->lock, $directory, $raw
            );
        }
        // The catalog is the receiver's reviewed list of packs, not part of any profile, so a contract
        // with no multilingual profile can still be given ONE language from it (siteLanguage.*).
        if (is_file($catalogFile))
            $this->packs = new LanguagePackCatalog(
                json_decode(file_get_contents($catalogFile), true, 512, JSON_THROW_ON_ERROR),
                $this->multilingual ? $this->multilingual->publishedSourceLanguage() : self::DEFAULT_SOURCE
            );
        // The demo-trim extension is optional in the same way, and for the same reason: a contract
        // published before it keeps working, and a receiver carrying the code claims nothing for a
        // contract that ships no list of what its demo is.
        $this->syncSourceRelabel();
        $trimFile = $directory . '/demo-trim-map.json';
        if (is_file($trimFile)) {
            $raw = file_get_contents($trimFile);
            $this->demoTrim = new DemoTrimProfile(json_decode($raw, true, 512, JSON_THROW_ON_ERROR), $this->map, $this->lock, $directory, $raw);
        }
    }
    private function ready(): void { if ($this->unavailable !== null) throw new RuntimeException($this->unavailable); }
    public function bound(): bool { $this->ready(); return $this->store->load() !== null; }
    /**
     * Whether THIS SITE's profile carries the multilingual extension.
     *
     * Not a property of the receiver. One base archive now serves more than one design, so a site
     * bound to a profile that ships no `multilingual-map.json` answers no here while the site next
     * to it answers yes. A receiver that cannot load the site's profile at all answers no too —
     * asking it anything further would be asking a half that has already said it is the wrong one.
     */
    public function multilingualAvailable(): bool {
        return $this->unavailable === null && $this->multilingual !== null && $this->packs !== null;
    }
    public function profile(): MultilingualProfile {
        $this->ready();
        if (!$this->multilingual) throw new RuntimeException('This contract has no multilingual profile');
        return $this->multilingual;
    }
    /** Whether THIS SITE's profile lists demo rows it may hide — a property of the contract, not the receiver. */
    /** Whether this site can be given one default language from the reviewed catalog. */
    public function siteLanguageAvailable(): bool { return $this->unavailable === null && $this->packs !== null; }
    public function demoTrimAvailable(): bool { return $this->unavailable === null && $this->demoTrim !== null; }
    public function demoTrim(): DemoTrimProfile {
        $this->ready();
        if (!$this->demoTrim) throw new RuntimeException('This contract has no demo-trim profile');
        return $this->demoTrim;
    }
    /** The stored baseline, or null on an unbound site. Read-only: only a validated apply replaces it. */
    public function binding(): ?array { $this->ready(); return $this->store->load(); }
    public function catalog(): LanguagePackCatalog {
        $this->ready();
        if (!$this->packs) throw new RuntimeException('This receiver carries no language-pack catalog');
        return $this->packs;
    }
    private function digest($value): string { return hash('sha256', json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)); }
    /**
     * Hashed once per instance: the three files are read in the constructor and never change after,
     * and re-encoding ~10 MB of profile JSON ran twice per inspect and twice per readMapping (#316).
     */
    private ?string $contractHash = null;
    private function contractHash(): string {
        if($this->contractHash!==null)return $this->contractHash;
        $t=Timing::begin();$this->contractHash=$this->digest([$this->manifest,$this->map,$this->lock]);Timing::end('contractHash',$t);
        return $this->contractHash;
    }
    /** Derived once per instance from the immutable package map — it was rebuilt on every call, inside loops over every copy. */
    private ?array $baseEntities = null;
    private function baseEntities(): array { return $this->baseEntities ??= array_column($this->map['entities'], null, 'key'); }
    private ?array $slotsByEntity = null;
    private function slotsFor(string $key): array {
        if ($this->slotsByEntity === null) {
            $this->slotsByEntity = [];
            foreach ($this->map['slots'] as $slot) $this->slotsByEntity[$slot['entity']][] = $slot;
        }
        return $this->slotsByEntity[$key] ?? [];
    }

    /**
     * Every entity this contract governs RIGHT NOW: the published quickstart's, plus one copy per
     * bound language, plus the switcher when any language exists.
     *
     * Each record carries where its protected field NAMES come from (`lockKey`) — a copy is checked
     * against its source's field set, not against a set of its own — and, for a copy, which source
     * and locale it derives from.
     *
     * @return array<string,array{kind:string,lockKey:string,locale?:string,base?:string,switcher?:bool}>
     */
    private function inventoryKeys(array $languages, ?int $switcher): array {
        $out = [];
        foreach ($this->baseEntities() as $key => $entity) $out[$key] = ['kind'=>$entity['kind'], 'lockKey'=>$key];
        foreach ($languages as $locale => $state) {
            foreach ($state['ids'] as $baseKey => $id) {
                if (!isset($this->baseEntities()[$baseKey])) throw new RuntimeException('Translation of an unknown entity: '.$baseKey);
                $out[MultilingualProfile::derivedKey($locale, $baseKey)] =
                    ['kind'=>$this->baseEntities()[$baseKey]['kind'], 'lockKey'=>$baseKey, 'locale'=>$locale, 'base'=>$baseKey];
            }
        }
        if ($switcher !== null)
            $out[MultilingualProfile::switcherKey()] = ['kind'=>'module', 'lockKey'=>$this->multilingual->switcherAnchor(), 'switcher'=>true];
        return $out;
    }

    /**
     * Everything that exists in a language right now: what the baseline records, plus what a job in
     * flight has already committed.
     *
     * Merging the two is what keeps the contract continuously checkable. A job that dies between
     * phases has left real rows behind; if inspect only knew about the baseline it would call those
     * rows an unexplained inventory change and refuse every subsequent read, including the revert
     * that would clean them up.
     */
    private function effectiveLanguages(?array $binding, ?array $job): array {
        $languages = $binding['multilingual']['languages'] ?? [];
        if ($job && $job['phase'] !== 'completed' && $job['ids'])
            $languages[$job['locale']]['ids'] = ($languages[$job['locale']]['ids'] ?? []) + $job['ids'];
        return $languages;
    }


    /**
     * Rows a half-finished language left behind that its checkpoint does not name yet.
     *
     * A phase is bounded, not atomic: Joomla places tree and `#__assets` rows through
     * `Table\Nested::store()`, whose `LOCK TABLES` is an implicit COMMIT in MySQL, so a phase that
     * threw can leave real rows standing while its own checkpoint rolled back. Without this, the
     * very next `inspect()` calls those rows an unexplained inventory change and refuses — including
     * for the resume and the revert that would clean them up. The site would be stuck in a state
     * only a database console could leave.
     *
     * 🔒 THIS IS NOT A GENERAL AMNESTY. It adopts only rows carrying the provenance note THIS
     * profile writes, only for the locale of the job that is actually in flight, and only while
     * that job exists — the job row is private receiver state, so there is no request that can put
     * the contract into this mode. An article has no note column an Apply may write, so it is
     * matched on the alias this profile derives for it plus its language, which no other row has.
     *
     * @return array{0:array<string,array{ids:array<string,int>}>,1:?int}
     */
    private function adoptOrphans(ContractRows $source, array $job, array $languages, ?int $switcher, ?array $binding): array {
        $locale=$job['locale'];
        $missing=[];
        foreach($this->baseEntities() as $key=>$entity) {
            if(!$this->multilingual->isTranslated($key))continue;
            if(isset($languages[$locale]['ids'][$key]))continue;
            $missing[$entity['kind']][$key]=$entity;
        }
        if(!$missing && $switcher!==null)return [$languages,$switcher];
        $switcherNote=$this->multilingual->switcherPresentation()['note'];
        foreach(['module','menuItem','article'] as $kind) {
            if(!isset($missing[$kind]) && !($kind==='module' && $switcher===null))continue;
            $rows=$source->summaries($kind);
            // An id already claimed by another key is never claimed twice.
            $claimed=array_flip(array_map('intval',$languages[$locale]['ids']??[]));
            $byId=array_column($rows,null,'id');
            foreach($rows as $row) {
                $id=(int)$row['id'];
                if(isset($claimed[$id]))continue;
                $note=(string)($row['note']??'');
                if($kind==='module' && $switcher===null && $note===$switcherNote){$switcher=$id;continue;}
                foreach($missing[$kind]??[] as $key=>$entity) {
                    if($kind==='article') {
                        // ⚠ ALIAS ALONE IS NOT AN IDENTITY HERE. Joomla requires an article alias to
                        // be unique within its CATEGORY, and this quickstart genuinely reuses six of
                        // them across categories — `about-us` is both article-1 and article-45. The
                        // category has to be part of the match or a copy gets adopted by the wrong
                        // source and every protected field then reads as drift.
                        $sourceId=(int)($binding['ids'][$key]??0);
                        $sourceCatid=(int)($byId[$sourceId]['catid']??-1);
                        $found=(string)($row['alias']??'')===$this->multilingual->derivedAlias('article',(string)$this->lock['entities'][$key]['alias'],$locale)
                            && (string)($row['language']??'')===$locale
                            && (int)($row['catid']??-2)===$sourceCatid;
                    } else $found=$note===$this->multilingual->marker($key,$locale);
                    if($found){$languages[$locale]['ids'][$key]=$id;$claimed[$id]=true;unset($missing[$kind][$key]);break;}
                }
            }
        }
        return [$languages,$switcher];
    }

    /** The slots of one entity, base or derived — the derived ones re-keyed from their source's. */
    private function slotsOf(string $key, array $meta): array {
        if (!empty($meta['switcher'])) return [];
        if (!isset($meta['locale'])) return $this->slotsFor($key);
        return $this->multilingual->derivedSlots($meta['locale'], $meta['base'], $this->lock['entities'][$meta['base']]);
    }

    private function changeRow(array $row, array $slots, array $values): array {
        $html=[]; $json=[];
        foreach ($slots as $slot) {
            if (!array_key_exists($slot['key'],$values)) continue;
            $value=$values[$slot['key']]; $col=$slot['column'];
            if (isset($slot['xpath'])) $html[$col][]=['xpath'=>$slot['xpath'],'value'=>$value];
            elseif (isset($slot['jsonPath'])) {
                $data=$json[$col]??json_decode($row[$col],true,512,JSON_THROW_ON_ERROR);
                $inner=$slot['nestedJson']; $body=json_decode($data[$inner],true,512,JSON_THROW_ON_ERROR);
                $data[$inner]=json_encode(ContentSlots::jsonPatch($body,$slot['jsonPath'],$value),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                $json[$col]=$data;
            } else $row[$col]=$value;
        }
        foreach($html as $col=>$changes)$row[$col]=ContentSlots::htmlPatch($row[$col],$changes);
        foreach($json as $col=>$data)$row[$col]=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        return $row;
    }
    /**
     * @param array $parsed this ROW's columns already parsed, kept by the caller across its slots: an
     * HTML body or nested JSON config holding many slots was parsed again for each one of them, on
     * every inspect (Business 1.2.0: 1,509 JSON and 124 HTML slots; #316). Omitted, nothing is kept.
     */
    private function currentValue(array $row, array $slot, array &$parsed = []): string {
        $column=$slot['column'];$value=$row[$column];
        if(isset($slot['xpath'])) {
            $nodes=($parsed['dom'][$column] ??= new DOMXPath(ContentSlots::html($value)))->query($slot['xpath']);
            if(!$nodes || $nodes->length!==1)throw new RuntimeException('Content slot is missing or ambiguous');
            return $nodes->item(0)->nodeValue;
        }
        if(isset($slot['jsonPath'])) {
            $outer=$parsed['json'][$column] ??= json_decode($value,true,512,JSON_THROW_ON_ERROR);
            $value=$parsed['nested'][$column][$slot['nestedJson']] ??= json_decode($outer[$slot['nestedJson']],true,512,JSON_THROW_ON_ERROR);
            foreach($slot['jsonPath'] as $key)$value=$value[$key];
        }
        if(!is_string($value))throw new RuntimeException('Content slot is not text');
        return $value;
    }
    /** @param array<string,mixed> $meta the record from {@see inventoryKeys} */
    private function presentation(string $key, array $row, array $meta): array {
        $fields=$this->lock['entities'][$meta['lockKey']]; $filtered=[];
        foreach($fields as $name=>$v) {
            if(!array_key_exists($name,$row))throw new RuntimeException('Missing protected field '.$name);
            $filtered[$name]=$row[$name];
        }
        $slots=$this->slotsOf($key, $meta);
        $filtered=$this->changeRow($filtered,$slots,array_fill_keys(array_column($slots,'key'),MultilingualProfile::CONTENT_SLOT));
        // Compare semantic JSON, independent of whitespace or key serialization used by Joomla.
        foreach(['params','attribs','metadata','images','urls'] as $col)if(isset($filtered[$col])&&$filtered[$col]!=='') {
            $decoded=json_decode($filtered[$col],true);
            if(is_array($decoded)) {
                if(isset($decoded['jatools-config']))$decoded['jatools-config']=json_decode($decoded['jatools-config'],true,512,JSON_THROW_ON_ERROR);
                $filtered[$col]=$decoded;
            }
        }
        return $filtered;
    }
    private function generatedCache(string $path): bool {
        // T4 produces these from the separately hash-locked sources when a route is first viewed.
        // `media/t4/css/<styleId>-sub.css` is the same kind of file: `Template::getCustomCssFilename()`
        // compiles it the first time a style renders a page that is not its menu item's own target
        // (`Layout::isSubpage()`). A capture only holds the ones its crawl happened to open — some
        // Tracy locks list two or three, the JoomlArt ones none — so the first customer to open any
        // other such page locked the site (measured 23/09/2026 on j-cr4l1l, ja-kinetic: 36-sub.css).
        return (bool)preg_match('~^media/t4/(optimize/(css/[a-f0-9]{32}\.css|js/[a-f0-9]{32}\.js)|css/[0-9]+-sub\.css)$~D',$path);
    }
    /**
     * Whether this door call has already proved the locked files: null outside one, where every
     * inspect hashes them all; false until the first inspect inside one has; then true.
     */
    private ?bool $filesProved = null;
    /**
     * Prove the locked files once for the rest of one door call: apply's plan and verify, revert's
     * inspect before and after (#316: 4,145 files hashed twice per apply). Only for a call that
     * writes no file between its inspects — a bound receiver writes none — so what this gives up is
     * an edit from outside landing in the seconds between two of them, which the next call refuses.
     */
    public function beginCall(): void { $this->filesProved = false; }
    public function endCall(): void { $this->filesProved = null; }
    private function files(): void {
        if($this->filesProved)return;
        $t=Timing::begin();
        foreach($this->lock['files'] as $path=>$hash) {
            $file=$this->root.'/'.$path;
            if($this->generatedCache($path) && !is_link($file))continue;
            if(is_link($file)||!is_file($file)||!hash_equals($hash,hash_file('sha256',$file)))$this->drift('Presentation asset changed: '.$path);
        }
        foreach($this->lock['fileRoots'] as $prefix) {
            // A folder removed through Joomla is a difference like any other (Tracy ADR 0022).
            if(!is_dir($this->root.'/'.$prefix)){$this->drift('Presentation folder missing: '.$prefix);continue;}
            $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root.'/'.$prefix,FilesystemIterator::SKIP_DOTS));
            foreach($iterator as $file)if($file->isFile()) {
                $relative=substr($file->getPathname(),strlen($this->root)+1);
                if($this->generatedCache($relative) && !$file->isLink())continue;
                if(!isset($this->lock['files'][$relative]))$this->drift('Unexpected presentation file: '.$relative);
            }
        }
        Timing::end('files',$t);
        if($this->filesProved===false)$this->filesProved=true;
    }

    /**
     * What the ACL must look like once copies exist: the captured baseline, plus one entry per copy
     * carrying its SOURCE's effective rules.
     *
     * Derived rather than remembered on purpose. A copy inheriting a different asset chain is a copy
     * a different audience can read — the exact mistake a stored snapshot would bless forever, and
     * the one a label like "Public" does not reveal.
     */
    private function expectedAccess(array $keys, array $ids): array {
        $expected = $this->lock['access'];
        // A capture with no per-entity rules has nothing to extend; every real one has them, and
        // the tests deliberately run a minimal ACL to prove the base path is untouched.
        if (!isset($expected['entityRules'])) return $expected;
        $table = ['module'=>'modules','article'=>'content','category'=>'categories'];
        $added = false;
        foreach ($keys as $key => $meta) {
            if (!isset($meta['locale']) && empty($meta['switcher'])) continue;
            $name = $table[$meta['kind']] ?? null;
            if ($name === null) continue;
            $source = !empty($meta['switcher']) ? $meta['lockKey'] : $meta['base'];
            $from = $name.'.'.$ids[$source];
            if (!isset($expected['entityRules'][$from])) throw new RuntimeException('No baseline ACL for '.$source);
            $expected['entityRules'][$name.'.'.$ids[$key]] = $expected['entityRules'][$from];
            $added = true;
        }
        if ($added) ksort($expected['entityRules']);
        return $expected;
    }

    /** Resolve physical identity without adopting rows, binding, or checking write invariants. */
    private function resolveRows(ContractRows $source, array $keys, ?array $binding, array $languages, ?int $switcher, bool $inventory = false): array {
        $ids=[];$rows=[];$lists=[];$missing=[];
        // A copy is ALWAYS resolved through the binding: two rows that differ only by language
        // cannot be told apart by the identity fields the base contract uses.
        $boundId = fn(string $key, array $meta): int => (isset($meta['locale']) || !empty($meta['switcher']))
            ? (int)(!empty($meta['switcher']) ? $switcher : $languages[$meta['locale']]['ids'][$meta['base']])
            : (int)($binding['ids'][$key]??0);
        // Without an inventory walk the bound rows are still hundreds of single reads; ask for
        // each kind's ids at once instead. With one, every row is already in hand.
        if(!$inventory && $binding) {
            $wanted=[];
            foreach($keys as $key=>$meta)$wanted[$meta['kind']][]=$boundId($key,$meta);
            foreach($wanted as $kind=>$list)$source->prefetch($kind,$list);
        }
        foreach($keys as $key=>$meta) {
            $kind=$meta['kind'];
            if(($inventory || !$binding) && !isset($lists[$kind])) $lists[$kind]=$source->all($kind);
            $derived = isset($meta['locale']) || !empty($meta['switcher']);
            if($binding || $derived) {
                $id = $boundId($key,$meta);
                $row = $source->row($kind,$id);
                // 🔒 A BOUND ROW REMOVED THROUGH JOOMLA CLOSES ITS OWN SLOTS, NOT THE DOOR (Tracy ADR
                // 0022): the site's other words still change. A copy made by a language job has no
                // life of its own apart from its source, so a lost copy still refuses.
                if(!$row && $binding && !$derived){$missing[]=$key;continue;}
                if(!$row)throw new RuntimeException('Bound entity disappeared: '.$key);
            } else {
                $entity=$this->baseEntities()[$key];
                $matches=array_values(array_filter($lists[$kind],function($row)use($entity,$ids){
                    foreach($entity['identityReferences']??[] as $field=>$ref)if((int)($row[$field]??0)!==($ids[$ref]??-1))return false;
                    foreach($entity['identity'] as $field=>$value)if((string)($row[$field]??'')!==(string)$value)return false;
                    return true;
                }));
                if(count($matches)!==1)throw new RuntimeException('Missing or ambiguous entity: '.$key);
                $row=$matches[0];$id=(int)$row['id'];
            }
            $ids[$key]=$id;$rows[$key]=$row;
        }
        return ['ids'=>$ids, 'rows'=>$rows, 'lists'=>$lists, 'missing'=>$missing];
    }

    /**
     * Read-only mapping. A binding is mandatory: an unbound site's labels are not identities.
     * An interrupted language job must be resumed through the existing write door, never GET.
     * Values are extracted by the reader only for authorized rows; no samples leave this method.
     */
    public function readMapping(): array {
        $this->ready();
        $binding = $this->store->load();
        if (!$binding) throw new RuntimeException('Content reader requires a bound contract');
        if (($binding['contractHash'] ?? null) !== $this->contractHash()) throw new RuntimeException('Installed content contract changed');
        $job = $this->store->job();
        if ($job && ($job['phase'] ?? '') !== 'completed') throw new RuntimeException('Content mapping has an unfinished language job');
        $languages = $this->effectiveLanguages($binding, null);
        $switcher = $binding['multilingual']['switcher'] ?? null;
        $keys = $this->inventoryKeys($languages, $switcher === null ? null : (int)$switcher);
        $resolved = $this->resolveRows(new ContractRows($this->writer), $keys, $binding, $languages, $switcher);
        foreach ($resolved['missing'] as $gone) unset($keys[$gone]);
        unset($resolved['missing']);
        $slots = [];
        foreach ($keys as $key=>$meta) {
            $slots[$key] = array_map(static function ($slot) { unset($slot['sample'], $slot['label']); return $slot; }, $this->slotsOf($key, $meta));
        }
        return $resolved + ['keys'=>$keys, 'slots'=>$slots, 'manifest'=>$this->manifest, 'contractHash'=>$this->contractHash()];
    }

    public function inspect(): array {
        $this->ready();
        $inspect=Timing::begin();
        $this->drift = [];
        $this->files();
        $binding=$this->store->load();
        if($binding && $binding['contractHash']!==$this->contractHash())throw new RuntimeException('Installed content contract changed');
        $job = $this->store->job();
        // This call's rows, read in bulk and dropped when it returns (see ContractRows).
        $source = new ContractRows($this->writer);
        $languages = $this->effectiveLanguages($binding, $job);
        $switcher = $binding['multilingual']['switcher'] ?? ($job['switcher'] ?? null);
        // A taken edition creates no rows, so a half-run job of one leaves none unnamed behind it.
        if ($job && $job['phase'] !== 'completed' && !($this->multilingual && $this->multilingual->edition((string) $job['locale'])))
            [$languages, $switcher] = $this->adoptOrphans($source, $job, $languages, $switcher, $binding);
        if ($languages || $switcher !== null) {
            if (!$this->multilingual) throw new RuntimeException('This site has translations but the receiver carries no multilingual profile');
            foreach ([$binding['multilingual']['profileHash'] ?? null, $job['profileHash'] ?? null] as $seen)
                if ($seen !== null && !hash_equals((string)$seen, $this->multilingual->hash()))
                    throw new RuntimeException('The installed multilingual profile changed');
        }
        $trim = $binding['demoTrim'] ?? null;
        if ($trim !== null) {
            if (!$this->demoTrim) throw new RuntimeException('This site has hidden demo rows but the receiver carries no demo-trim profile');
            if (!hash_equals((string) $trim['profileHash'], $this->demoTrim->hash())) throw new RuntimeException('The installed demo-trim profile changed');
        }
        // While a trim or its revert is in flight, a listed row may stand at either end of its move:
        // a batch is bounded, not atomic (Joomla's nested tables commit implicitly), so the site can
        // genuinely be half-moved. Only the listed rows, only on their visibility column, and only
        // between the two values the profile names — everything else is held exactly as before.
        $trimMoving = $trim !== null && in_array($trim['status'] ?? null, ['applying', 'reverting'], true);
        // The one phase in which a translated source row may legitimately still be at `*`: the
        // retag is chunked, so mid-phase the site is genuinely half-moved. Every other phase, and
        // every state with no job at all, demands the finished answer.
        $transitional = $job !== null && $job['phase'] === 'prepare';
        $retagged = $languages !== [] && ($binding['multilingual']['languages'] ?? []) !== [] || ($job['retagged'] ?? false);
        $t=Timing::begin();
        $keys = $this->inventoryKeys($languages, $switcher === null ? null : (int)$switcher);
        ['ids'=>$ids, 'rows'=>$rows, 'lists'=>$lists, 'missing'=>$missing] = $this->resolveRows($source, $keys, $binding, $languages, $switcher, true);
        foreach($missing as $gone){unset($keys[$gone]);$this->drift('Bound entity disappeared: '.$gone);}
        Timing::end('inventory',$t);
        // Resolve foreign keys from the archive's IDs to this installation's IDs.
        $idMaps=[];foreach($this->baseEntities() as $key=>$entity)if(isset($ids[$key]))$idMaps[$entity['kind']][$entity['sourceId']]=$ids[$key];
        // And, per language, from an INSTALLED source id to the id of its copy — what a copied
        // link and a copied menu parent are rewritten with.
        $localeMaps=[];
        foreach($languages as $locale=>$state) {
            foreach($state['ids'] as $baseKey=>$id)
                if(isset($ids[$baseKey]))$localeMaps[$locale][$this->baseEntities()[$baseKey]['kind']][$ids[$baseKey]]=(int)$id;
            // A shipped edition's links and assignments point at ITS rows everywhere, the untranslated
            // ones included (a kit page, a category), so its whole map stands behind the job's ids.
            if($this->multilingual && $this->multilingual->edition($locale))
                $localeMaps[$locale]=$this->multilingual->editionMaps($locale,$localeMaps[$locale]??[]);
        }
        // 🔒 A SWITCHER THE ARCHIVE ALREADY SHIPS IS A GOVERNED MODULE, NOT A NEW ONE. A profile may
        // reuse it (Business: module-425, note `tb:pilot`) so the topbar never carries two; that row is
        // then checked field by field, assigned and counted under its own base key, and holding it to
        // the new-switcher shape too ("Languages", all pages, one module more) refused every Business
        // site that gained a language — measured 23/09/2026 on `j-ee6vsk`.
        $anchorKey=$this->multilingual ? $this->multilingual->switcherAnchor() : null;
        $reusedSwitcher=$switcher!==null && $anchorKey!==null && isset($ids[$anchorKey]) && (int)$ids[$anchorKey]===(int)$switcher;
        $t=Timing::begin();
        $protected=[];
        foreach($keys as $key=>$meta) {
            $actual=$this->presentation($key,$rows[$key],$meta);
            if (!empty($meta['switcher'])) {
                $expected=$reusedSwitcher ? $actual : $this->multilingual->switcherPresentation();
            } elseif (isset($meta['locale'])) {
                // Recomputed from the SOURCE as it stands now, never from what was stored when the
                // copy was made: a copy that drifted is caught on the next read.
                $expected=$this->multilingual->{$this->multilingual->edition($meta['locale']) ? 'editionPresentation' : 'derivedPresentation'}(
                    $meta['kind'], $meta['base'],
                    $this->presentation($meta['base'],$rows[$meta['base']],$keys[$meta['base']]),
                    $meta['locale'], $localeMaps[$meta['locale']] ?? [], $this->lock['entities'][$meta['base']], $actual
                );
            } else {
                $expected=$binding['presentation'][$key]??$this->presentation($key,$this->lock['entities'][$key],$meta);
                // A translated source leaves `*` for its own language, because `*` means "shows in
                // every language" — the English hero would otherwise sit on the Chinese page.
                if($this->multilingual && ($expected['language']??null)==='*' && $this->multilingual->isTranslated($key)) {
                    $allowed=$transitional?['*',$this->multilingual->sourceLanguage()]:($retagged?[$this->multilingual->sourceLanguage()]:['*']);
                    if(in_array((string)$actual['language'],$allowed,true))$expected['language']=(string)$actual['language'];
                }
                $trimRow = $trimMoving ? $this->demoTrim->row($key) : null;
                if ($trimRow !== null && in_array((string) ($actual[$trimRow['field']] ?? ''), [$trimRow['from'], $trimRow['to']], true))
                    $expected[$trimRow['field']] = (string) $actual[$trimRow['field']];
                if(!$binding)foreach(['template_style_id'=>'templateStyle','catid'=>'category','parent_id'=>$meta['kind']==='category'?'category':'menuItem'] as $field=>$kind) {
                    if(isset($expected[$field],$idMaps[$kind][(int)$expected[$field]]))$expected[$field]=(string)$idMaps[$kind][(int)$expected[$field]];
                }
            }
            if($actual!=$expected) {
                // NAME THE FIELDS. "Presentation drift: module-174" tells whoever is holding the
                // failure nothing they can act on, and the fields are the site's own column names,
                // not its words — every content value is already masked by the time it gets here.
                $differ=[];
                foreach($expected as $field=>$value)if(!array_key_exists($field,$actual)||$actual[$field]!=$value)$differ[]=$field.' [want '.substr(json_encode($value),0,60).' got '.substr(json_encode($actual[$field]??null),0,60).']';
                foreach($actual as $field=>$value)if(!array_key_exists($field,$expected))$differ[]=$field.' (unexpected)';
                $this->drift('Presentation drift: '.$key.' — '.implode(', ',$differ));
            }
            $protected[$key]=$actual;
        }
        Timing::end('presentation',$t);
        $t=Timing::begin();
        $assignments=[];
        $source->prefetch('moduleAssignment',array_map(fn($key)=>$ids[$key],array_keys(array_filter($keys,fn($meta)=>$meta['kind']==='module'))));
        foreach($keys as $key=>$meta)if($meta['kind']==='module') {
            $actual=$source->row('moduleAssignment',$ids[$key]);
            $menus=json_decode($actual['menuids']??'[]',true,512,JSON_THROW_ON_ERROR);sort($menus);
            if (!empty($meta['switcher'])) {
                $expected=$reusedSwitcher ? $assignments[$anchorKey] : [0];
            } elseif (isset($meta['locale'])) {
                // The same pages as the source, named by the copies of those pages. A negative id
                // is Joomla's "everywhere except this one" and keeps its sign through the map.
                $expected=[];
                foreach($assignments[$meta['base']] as $menu) {
                    $n=abs($menu);
                    $expected[]=($menu<0?-1:1)*($n===0?0:($localeMaps[$meta['locale']]['menuItem'][$n]??$n));
                }
            } else {
                $expected=[];foreach($this->lock['assignments'] as $a)if((int)$a['moduleid']===$this->baseEntities()[$key]['sourceId']) {
                    $n=(int)$a['menuid'];$expected[]=($n<0?-1:1)*($idMaps['menuItem'][abs($n)]??abs($n));
                }
            }
            sort($expected);
            if($menus!=$expected)$this->drift('Module assignment drift: '.$key);
            $assignments[$key]=$menus;
        }
        Timing::end('assignments',$t);
        $t=Timing::begin();
        $actualAccess=$this->store->access();$expectedAccess=$this->expectedAccess($keys,$ids);
        if($actualAccess != $expectedAccess) {
            // Say WHICH audience moved. "Access-level or ACL definition changed" is true of a
            // view level, a user group, a component rule and any one of a few hundred entity
            // rules, and the reader has no way to tell them apart without this.
            $where=[];
            foreach($expectedAccess as $part=>$value)if(($actualAccess[$part]??null)!=$value) {
                if(!is_array($value)||!is_array($actualAccess[$part]??null)){$where[]=$part;continue;}
                $keysDiffer=array_slice(array_unique(array_merge(
                    array_keys(array_diff_key($value,$actualAccess[$part])),
                    array_keys(array_diff_key($actualAccess[$part],$value)),
                    array_keys(array_filter($value,fn($v,$k)=>isset($actualAccess[$part][$k])&&$actualAccess[$part][$k]!=$v,ARRAY_FILTER_USE_BOTH))
                )),0,6);
                $where[]=$part.'('.implode(' ',$keysDiffer).')';
            }
            $this->drift('Access-level or ACL definition changed: '.implode(', ',$where));
        }
        Timing::end('access',$t);
        $t=Timing::begin();
        $counts=array_map('count',$lists);
        $expectedCounts=$this->lock['inventoryCounts'];
        foreach($languages as $locale=>$state) {
            // A taken edition's rows were already in the archive's count; only a copy adds one.
            if($this->multilingual && $this->multilingual->edition($locale))continue;
            foreach($state['ids'] as $baseKey=>$id)$expectedCounts[$this->baseEntities()[$baseKey]['kind']]++;
        }
        if($switcher !== null && !$reusedSwitcher)$expectedCounts['module']++;
        if($counts!=$expectedCounts)$this->drift('Quickstart inventory changed');
        Timing::end('counts',$t);
        $this->settleDrift();
        $snapshot=['contractHash'=>$this->contractHash(),'ids'=>$ids,'presentation'=>$protected,'assignments'=>$assignments,'counts'=>$counts,'access'=>$this->lock['access']];
        if(isset($binding['multilingual']))$snapshot['multilingual']=$binding['multilingual'];
        // Carried through every rebind, or the next content edit would store a baseline that no
        // longer knows the demo was hidden — and the one after that would call it drift.
        if(isset($binding['demoTrim']))$snapshot['demoTrim']=$binding['demoTrim'];
        if(isset($binding['siteLanguage']))$snapshot['siteLanguage']=$binding['siteLanguage'];
        if(isset($binding['sourceRelabel']))$snapshot['sourceRelabel']=$binding['sourceRelabel'];
        $revisionRows=[];
        foreach($rows as $key=>$row)$revisionRows[$key]=array_intersect_key($row,$this->lock['entities'][$keys[$key]['lockKey']]);
        $t=Timing::begin();
        $slots=[];
        foreach($keys as $key=>$meta){$parsed=[];foreach($this->slotsOf($key,$meta) as $slot){$slot['current']=$this->currentValue($rows[$key],$slot,$parsed);$slots[]=$slot;}}
        $slotValues=[];foreach($slots as $slot)$slotValues[$slot['key']]=$slot['current'];
        Timing::end('slots',$t);
        $t=Timing::begin();$revision=$this->digest($revisionRows);Timing::end('digest',$t);
        Timing::end('inspect',$inspect);
        return ['contract'=>$this->manifest['id'],'snapshot'=>$snapshot,'revision'=>$revision,
            'slots'=>$slots,'pages'=>$this->map['pages'],'rows'=>$rows,'ids'=>$ids,'keys'=>$keys,'localeMaps'=>$localeMaps,
            'assignments'=>$assignments,'slotValues'=>$slotValues,
            'languages'=>array_keys($binding['multilingual']['languages'] ?? []),
            'binding'=>$binding,'job'=>$job,'switcher'=>$switcher,'demoTrim'=>$trim['status']??null];
    }

    /**
     * Scalar content rules, applied identically to an original and to a translation of it.
     *
     * Throws the slot's FIRST problem as a ContractProblem; the message is the one this rule has
     * always said, the code and numbers are what an agent acts on.
     */
    private function checkValue(array $slot, string $value, array $params): void {
        $key=$slot['key'];
        $refuse=fn(string $code,string $message,array $extra=[])=>new ContractProblem($code,$message,$key,null,$extra);
        if (!empty($slot['requiresEvidence']) && $value!==$slot['sample']) {
            $evidence=$params['evidence'][$key]??null;
            if(!is_string($evidence)||trim($evidence)===''||strlen($evidence)>8000)throw $refuse('SLOT_EVIDENCE_REQUIRED','Customer evidence required: '.$key);
        }
        // Empty ACM fields control conditional markup; changing occupancy changes layout. The one
        // exception is the identity module: it renders nothing itself, and a fact the customer does
        // not have (a TikTok page, a legal name) must be emptied, or the demo's value is shown instead.
        if(empty($slot['siteIdentity'])) {
            if(trim($value)==='' && trim($slot['sample'])!=='')throw $refuse('SLOT_EMPTY_STATE','Content cannot remove an occupied slot: '.$key);
            if(trim($value)!=='' && trim($slot['sample'])==='')throw $refuse('SLOT_EMPTY_STATE','Content cannot activate an empty slot: '.$key);
        }
        if(preg_match('/[<>\x00-\x08\x0b\x0c\x0e-\x1f]/u',$value))throw $refuse('SLOT_NOT_CONTENT','Markup and control characters are not content: '.$key);
        if(IdentityTokens::hasDirective($value))throw $refuse('SLOT_NOT_CONTENT','Joomla plugin directives are not content: '.$key);
        if(mb_strlen($value)>$slot['maxCharacters'])throw $refuse('SLOT_TOO_LONG','Content too long: '.$key,['limit'=>(int)$slot['maxCharacters'],'actual'=>mb_strlen($value)]);
        if($slot['type']==='url' && (strpos($value,'//')===0 || strpos($value,'\\')!==false))throw $refuse('SLOT_LINK_UNSUPPORTED','Unsupported CTA URL: '.$key);
        if($slot['type']==='url'&&$value!==''&&!preg_match('~^(https://[^\s]+|mailto:[^\s]+|tel:[+0-9 ()-]+|index\.php\?Itemid=[0-9]+|/[a-zA-Z0-9/_?&=.%#-]*|#[a-zA-Z0-9_-]+)$~D',$value))throw $refuse('SLOT_LINK_UNSUPPORTED','Unsupported CTA URL: '.$key);
        if($slot['type']==='image'&&$value!=='') {
            if(!preg_match('~^images/[a-zA-Z0-9/_-]+\.(png|jpe?g|webp)$~D',$value)||!is_file($this->root.'/'.$value))throw $refuse('SLOT_IMAGE_INVALID','Image must already exist in the site media library: '.$key);
            $resolved=realpath($this->root.'/'.$value);$imageRoot=realpath($this->root.'/images');
            if(!$resolved||!$imageRoot||strpos($resolved,$imageRoot.DIRECTORY_SEPARATOR)!==0)throw $refuse('SLOT_IMAGE_INVALID','Image escapes the site media library: '.$key);
            $before=@getimagesize($this->root.'/'.$slot['sample']);$after=@getimagesize($this->root.'/'.$value);
            if(!$after||($before&&abs($before[0]/$before[1]-$after[0]/$after[1])>0.02))throw $refuse('SLOT_IMAGE_INVALID','Image aspect ratio does not match its slot');
        }
    }

    /**
     * Check an apply against the site as it stands, and turn it into row operations.
     *
     * Two ways to say what the change was based on. `expected_revision` is the whole-contract
     * revision from inspect, which costs an inspect to learn (10–80 s on Joomla).
     * `expected_content_revisions` maps each content `content.read` served to the revision it
     * served, and is enough on its own: every changed slot's content must be named, and match.
     * With both, both must hold. `$current` is that projection now ({@see ContentProjection::build}
     * `revisions` + `owners`), handed in by the engine that can read the site's tables.
     *
     * 🔒 EVERY PROBLEM, THEN NOTHING WRITTEN. All changes are checked before any operation is built,
     * and every refusal found is thrown together, so an agent fixes three slots in one round
     * instead of three.
     */
    public function plan(array $params, ?array $current = null): array {
        $state=$this->inspect();
        $byContent=$params['expected_content_revisions']??null;
        if($byContent!==null) {
            $valid=is_array($byContent);
            if($valid)foreach($byContent as $id=>$revision)if(!is_string($id)||!is_string($revision)){$valid=false;break;}
            if(!$valid)throw new ContractProblem('CONTRACT_FAILED','expected_content_revisions must map content ids to revisions');
            if(!$byContent)$byContent=null;
        }
        $expected=$params['expected_revision']??null;
        $problems=[];
        if($byContent===null) {
            // The only basis before per-content revisions existed, refused exactly as it always was.
            if($expected===null)throw new ContractProblem('REVISION_REQUIRED','Content changed; inspect again');
            if(!is_string($expected)||!hash_equals($state['revision'],$expected))
                throw new ContractProblem('REVISION_STALE','Content changed; inspect again',null,null,['current'=>$state['revision']]);
        } else {
            if($expected!==null&&(!is_string($expected)||!hash_equals($state['revision'],$expected)))
                $problems[]=new ContractProblem('REVISION_STALE','Content changed; inspect again',null,null,['current'=>$state['revision']]);
            if($current===null)throw new ContractProblem('CONTRACT_FAILED','Content revisions are unavailable on this site; send expected_revision from inspect');
        }
        $changes=$params['changes']??null;
        if(!is_array($changes)||!count($changes)||count($changes)>1500)throw new ContractProblem('CHANGES_INVALID','Expected 1–1500 scalar content changes');
        $allowed=array_column($state['slots'],null,'key');$values=[];
        $owners=$current['owners']??[];$checked=[];
        foreach ($changes as $key => $value) {
            $key=(string)$key;
            $slot=$allowed[$key]??null;
            $owner=$slot===null?null:($owners[$slot['entity']]??null);
            if($slot===null||!is_string($value)){$problems[]=new ContractProblem('SLOT_UNKNOWN','Unknown content slot or non-string value',$key,$owner);continue;}
            // One revision check per content, named by the first changed slot that lives in it.
            if($byContent!==null&&!isset($checked[$owner??"\0".$key])) {
                $checked[$owner??"\0".$key]=true;
                // A slot content.read does not project (a hidden row, a category) has no content
                // revision anyone could have read; only the whole-contract revision covers it.
                if($owner===null){ if($expected===null)$problems[]=new ContractProblem('REVISION_REQUIRED','Content revision required: '.$key.' is not in content.read; send expected_revision from inspect',$key); }
                elseif(!isset($byContent[$owner]))$problems[]=new ContractProblem('REVISION_REQUIRED','Content revision required: '.$owner,$key,$owner);
                elseif(!hash_equals($current['revisions'][$owner],$byContent[$owner]))
                    $problems[]=new ContractProblem('REVISION_STALE','Content changed; read it again: '.$owner,$key,$owner,['current'=>$current['revisions'][$owner]]);
            }
            try { $this->checkValue($slot,$value,$params); }
            catch (ContractProblem $problem) { $problem->contentId=$owner; $problems[]=$problem; continue; }
            $values[$key]=$value;
        }
        if($problems)throw ContractProblem::all($problems);
        // 🔒 A PICTURE IS THE SAME PICTURE IN EVERY LANGUAGE. Words are translated, so a new source
        // sentence waits for its language job; a new source picture has nothing to wait for, and left
        // on the source alone it showed on /en/ only — measured 23/09/2026 on j-ee6vsk, a drawn photo
        // on /en/careers and the demo's on /vi/ and /fr/. So every language the site carries takes it
        // in the same apply, through the same slot the language's own row derives from the source.
        $locales=[];
        foreach($state['keys'] as $meta)if(isset($meta['locale']))$locales[$meta['locale']]=true;
        foreach($values as $key=>$value)
            if(($allowed[$key]['type']??'')==='image')
                foreach(array_keys($locales) as $locale)$values[MultilingualProfile::derivedKey($locale,$key)]=$value;
        $operations=[];$touched=[];
        foreach($state['keys'] as $key=>$meta) {
            $row=$state['rows'][$key];$next=$this->changeRow($row,$this->slotsOf($key,$meta),$values);$fields=[];$expected=[];
            foreach($next as $field=>$value)if($value!==$row[$field]){$fields[$field]=$value;$expected[$field]=$row[$field];}
            if($fields){$operations[]=['kind'=>$meta['kind'],'id'=>$state['ids'][$key],'fields'=>$fields,'expected'=>$expected];$touched[]=$key;}
        }
        return ['operations'=>$operations,'snapshot'=>$state['snapshot'],'touched'=>$touched];
    }
    /** The protected field set the base contract captured for one entity, by name and value. */
    public function lockFields(string $baseKey): array {
        if(!isset($this->lock['entities'][$baseKey]))throw new RuntimeException('Unknown contract entity: '.$baseKey);
        return $this->lock['entities'][$baseKey];
    }
    /**
     * Write slot values into a row without touching anything around them.
     *
     * This is the one operation that makes a translation structurally honest: values land on the
     * SAME xpaths and JSON leaves the source uses, so a copy can differ in words and cannot differ
     * in markup. Public because the language executor builds every copy with it.
     */
    public function patch(array $row, array $slots, array $values): array { return $this->changeRow($row,$slots,$values); }
    /**
     * What a slot currently holds in one row.
     *
     * Public because a copy's slots are read from the SOURCE row, and one of them — the title of a
     * module that shows its title — has no counterpart in the base contract's slot list: it exists
     * only for the copy. Reading it through the same resolver as every other slot is what stopped
     * it arriving empty, which Joomla then refused with "Module must have a title".
     */
    public function slotValue(array $row, array $slot): string { return $this->currentValue($row,$slot); }
    /** The slots of one entity's copy in a language, keyed for that copy. */
    public function derivedSlotsFor(string $locale, string $baseKey): array {
        return $this->profile()->derivedSlots($locale,$baseKey,$this->lockFields($baseKey));
    }

    /* ------------------------------------------------------------ multilingual */

    /**
     * What adding one language would do, without doing any of it.
     *
     * Read-only on purpose and complete on purpose: the caller needs the SOURCE TEXT of every slot
     * it must translate, the package it would install, and the list of what is deliberately not
     * covered — because an agent that is told only "yes you can" will promise the parts this
     * contract has no way to deliver.
     */
    public function languagePlan(string $locale, int $major, array $state): array {
        $profile=$this->profile(); $catalog=$this->catalog();
        if(!preg_match('/^[a-z]{2,3}-[A-Z]{2,4}$/D',$locale))throw new RuntimeException('Not a Joomla language tag: '.$locale);
        // 🔒 THE SEF SEGMENT IS DERIVED, AND `#__languages.sef` IS UNIQUE. `zh-CN` and `zh-TW` both
        // derive `zh`, so a site that has one cannot take the other — and the plan is where that has
        // to be said, because the caller translates ~1000 strings BEFORE the first write. Refusing at
        // the insert would mean the customer pays for a whole edition and then loses it.
        $clash=MultilingualProfile::sefClash($locale,array_merge([$profile->sourceLanguage()],$state['languages']));
        if($clash!==null)throw new RuntimeException('This site already publishes '.$clash.' at /'.MultilingualProfile::sefOf($locale).'/, and '.$locale.' would need the same address. Joomla gives one language each URL segment, so the two cannot both be on this site. Nothing has been written.');
        $pack=$catalog->pack($locale,$major);
        $already=in_array($locale,$state['languages'],true);
        $creates=[];$slots=[];
        foreach($state['keys'] as $key=>$meta) {
            if(isset($meta['locale'])||!empty($meta['switcher'])||!$profile->isTranslated($key))continue;
            $creates[$meta['kind']]=($creates[$meta['kind']]??0)+1;
            foreach($this->derivedSlotsFor($locale,$key) as $slot) {
                $slot['source']=$this->slotValue($state['rows'][$key],$slot);
                unset($slot['sample']);
                $slots[]=$slot;
            }
        }
        return [
            'locale'=>$locale,'sourceLanguage'=>$profile->sourceLanguage(),'sef'=>MultilingualProfile::sefOf($locale),
            'contract'=>$this->manifest['id'],'profileVersion'=>$profile->version(),'profileHash'=>$profile->hash(),
            'revision'=>$state['revision'],'alreadyPresent'=>$already,
            'package'=>$pack===null?null:['tag'=>$pack['tag'],'version'=>$pack['version'],'bytes'=>$pack['bytes'],'sha256'=>$pack['sha256']],
            'creates'=>$creates+['switcher'=>$state['switcher']===null?1:0],
            'coverage'=>$profile->coverage(),'unsupported'=>$profile->unsupported(),
            'slots'=>$slots,
        ];
    }

    public function job(): ?array { return $this->store->job(); }
    public function saveJob(?array $job): void { $this->store->saveJob($job); }

    /** The archive for a locale, from the reviewed catalog — never from the request. */
    public function languagePackage(string $locale, int $major): ?array {
        return $this->catalog()->pack($locale,$major);
    }

    /**
     * The baseline as it stands once a language has finished.
     *
     * Recomputed from the previous baseline plus the job's own ids. Nothing here is read back from
     * the site: a binding that copied whatever the site happened to hold would bless a bad apply.
     */
    public function bindingAfterLanguage(array $job): array {
        $binding=$this->store->load();
        if(!$binding)throw new RuntimeException('A language needs a bound site');
        $profile=$this->profile();
        $binding['multilingual']=($binding['multilingual']??[])+['profileHash'=>$profile->hash(),'profileVersion'=>$profile->version(),'source'=>$profile->sourceLanguage(),'languages'=>[]];
        $binding['multilingual']['languages'][$job['locale']]=[
            'ids'=>$job['ids'],'applyId'=>$job['applyId'],'requestId'=>$job['requestId'],
            'contentLanguage'=>$job['contentLanguage'],'at'=>gmdate('c'),
        ];
        if($job['switcher']!==null)$binding['multilingual']['switcher']=$job['switcher'];
        foreach($binding['presentation'] as $key=>$fields)
            if(($fields['language']??null)==='*' && $profile->isTranslated($key))
                $binding['presentation'][$key]['language']=$profile->sourceLanguage();
        return $binding;
    }

    /**
     * The baseline with one language taken back out.
     *
     * The last language leaving also returns every source row to `*`, because that is what the
     * published quickstart is; a site reverted to one language must be indistinguishable from a
     * site that never had two.
     */
    public function bindingAfterRevert(string $locale): array {
        $binding=$this->store->load();
        if(!$binding)throw new RuntimeException('A revert needs a bound site');
        unset($binding['multilingual']['languages'][$locale]);
        if(empty($binding['multilingual']['languages'])) {
            $profile=$this->profile();
            // Back to what the ARCHIVE shipped, not to `*` by assumption: Business ships most of its
            // translated modules in en-GB already (module-428), and a revert that called them `*`
            // left the site failing its own inspect — every later call refused.
            foreach($binding['presentation'] as $key=>$fields)
                if(($fields['language']??null)===$profile->sourceLanguage() && $profile->isTranslated($key))
                    $binding['presentation'][$key]['language']=$this->lockedLanguage($key);
            unset($binding['multilingual']);
        }
        return $binding;
    }

    /* ------------------------------------------------------------ demo trim */

    /** The baseline with a trim on record, before or while rows move. */
    public function bindingWithTrim(array $record): array {
        $binding=$this->store->load();
        if(!$binding)throw new RuntimeException('A demo trim needs a bound site');
        $binding['demoTrim']=$record;
        return $binding;
    }
    /** Every listed row expected hidden from now on. Derived from the profile, never read back from the site. */
    public function bindingAfterTrim(): array {
        $binding=$this->store->load();
        if(!$binding || !isset($binding['demoTrim']))throw new RuntimeException('No demo trim is on record');
        foreach($this->demoTrim()->rows() as $key=>$row)$binding['presentation'][$key][$row['field']]=$row['to'];
        $binding['demoTrim']['status']='complete';
        $binding['demoTrim']['completedAt']=gmdate('c');
        return $binding;
    }
    /** Every listed row expected at its locked value again, and no trim on record — as if it never happened. */
    public function bindingAfterTrimRevert(): array {
        $binding=$this->store->load();
        if(!$binding)throw new RuntimeException('A revert needs a bound site');
        foreach($this->demoTrim()->rows() as $key=>$row)$binding['presentation'][$key][$row['field']]=$row['from'];
        unset($binding['demoTrim']);
        return $binding;
    }

    /* ------------------------------------------------------------ site language */

    /** The baseline with the site's one language on record — or with none, when $record is null. */
    public function bindingWithSiteLanguage(?array $record): array {
        $binding=$this->store->load();
        if(!$binding)throw new RuntimeException('A site language needs a bound site');
        if($record===null)unset($binding['siteLanguage']);else $binding['siteLanguage']=$record;
        return $binding;
    }

    /**
     * Per kind, every id the contract governs on this site — the bound base entities, every
     * derived copy and the switcher — read from the binding, without an inspect. What a retire
     * pass must leave alone; cheap because a retire runs in chunks and an inspect costs ~40 s on
     * a 43-language archive.
     *
     * @return array<string,int[]>
     */
    public function governedIds(): array {
        $this->ready();
        $binding = $this->store->load();
        if ($binding === null) throw new RuntimeException('A retire needs a bound site');
        $out = [];
        foreach ($binding['ids'] ?? [] as $key => $id)
            if (isset($this->baseEntities()[$key])) $out[$this->baseEntities()[$key]['kind']][] = (int) $id;
        foreach ($binding['multilingual']['languages'] ?? [] as $state)
            foreach ($state['ids'] ?? [] as $baseKey => $id)
                if (isset($this->baseEntities()[$baseKey])) $out[$this->baseEntities()[$baseKey]['kind']][] = (int) $id;
        if (isset($binding['multilingual']['switcher'])) $out['module'][] = (int) $binding['multilingual']['switcher'];
        return $out;
    }

    /** The content languages this site has been given copies of, per its binding. */
    public function derivedLanguages(): array {
        $this->ready();
        return array_keys($this->store->load()['multilingual']['languages'] ?? []);
    }

    /**
     * Save the baseline after a content write. It must equal the stored one — a content apply changes
     * no structure — unless this request began on a site that already differs from its baseline
     * (changed through Joomla itself, Tracy ADR 0022): the baseline then moves to where the site
     * stands, and the check after the write still refuses any difference the write itself made.
     */
    public function bind(array $snapshot): void {
        $this->ready();
        if ($this->tolerated) $this->store->replace($snapshot); else $this->store->save($snapshot);
        $this->syncSourceRelabel();
    }
    /** Only a validated language apply or revert replaces a baseline; content applies re-save an identical one. */
    public function rebind(array $binding): void { $this->ready(); $this->store->replace($binding); $this->syncSourceRelabel(); }

    /* ------------------------------------------------------------ source relabel */

    /** The tag the archive's source edition was published under. */
    public function publishedSourceLanguage(): string {
        return $this->multilingual ? $this->multilingual->publishedSourceLanguage() : self::DEFAULT_SOURCE;
    }
    /** The tag the site's source edition carries now: the published one, or what `sourceLanguage.set` renamed it to. */
    public function sourceLanguage(): string {
        return $this->sourceRelabel['to'] ?? $this->publishedSourceLanguage();
    }
    private const DEFAULT_SOURCE = 'en-GB';
    private ?array $sourceRelabel = null;
    private function syncSourceRelabel(): void {
        if ($this->unavailable !== null) return;
        $record = $this->store->load()['sourceRelabel'] ?? null;
        $this->sourceRelabel = is_array($record) ? $record : null;
        if ($this->multilingual) $this->multilingual->relabelSource($this->sourceRelabel['to'] ?? null);
    }

    /**
     * The baseline with the source edition called `$record['to']` — or back to its published tag,
     * when $record is null. Every recorded row that carried the old tag carries the new one; nothing
     * else moves, because a relabel moves nothing else.
     */
    public function bindingWithSourceRelabel(?array $record): array {
        $binding=$this->store->load();
        if(!$binding)throw new RuntimeException('A source relabel needs a bound site');
        $from=$this->sourceLanguage();
        $to=$record['to'] ?? $this->publishedSourceLanguage();
        // `home` too: a template style set per language names its language there.
        foreach($binding['presentation'] as $key=>$fields)foreach(['language','home'] as $field)
            if(($fields[$field]??null)===$from)$binding['presentation'][$key][$field]=$to;
        if(isset($binding['multilingual']['source']))$binding['multilingual']['source']=$to;
        if($record===null)unset($binding['sourceRelabel']);else $binding['sourceRelabel']=$record;
        return $binding;
    }
    /** A locked row's language as this site names it: the published source tag reads as the relabelled one. */
    private function lockedLanguage(string $key): string {
        $language=(string)($this->lock['entities'][$key]['language']??'*');
        return $language===$this->publishedSourceLanguage() ? $this->sourceLanguage() : $language;
    }
}
