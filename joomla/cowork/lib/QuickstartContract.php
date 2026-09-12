<?php
require_once __DIR__ . '/ContentSlots.php';
require_once __DIR__ . '/ContractAccess.php';
require_once __DIR__ . '/MultilingualProfile.php';
require_once __DIR__ . '/LanguagePackCatalog.php';
require_once __DIR__ . '/MultilingualApply.php';

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
            if (is_file($catalogFile))
                $this->packs = new LanguagePackCatalog(
                    json_decode(file_get_contents($catalogFile), true, 512, JSON_THROW_ON_ERROR),
                    $this->multilingual->sourceLanguage()
                );
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
    public function catalog(): LanguagePackCatalog {
        $this->ready();
        if (!$this->packs) throw new RuntimeException('This receiver carries no language-pack catalog');
        return $this->packs;
    }
    private function digest($value): string { return hash('sha256', json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)); }
    private function contractHash(): string { return $this->digest([$this->manifest,$this->map,$this->lock]); }
    private function baseEntities(): array { return array_column($this->map['entities'], null, 'key'); }
    private function slotsFor(string $key): array { return array_values(array_filter($this->map['slots'], fn($s)=>$s['entity']===$key)); }

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
    private function adoptOrphans(array $job, array $languages, ?int $switcher, ?array $binding): array {
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
            $rows=[];
            for($offset=0;$offset<20000;$offset+=100) {
                $page=$this->writer->list($kind,$offset,100);
                foreach($page as $row)$rows[]=$row;
                if(count($page)<100)break;
            }
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
    private function currentValue(array $row, array $slot): string {
        $value=$row[$slot['column']];
        if(isset($slot['xpath'])) {
            $nodes=(new DOMXPath(ContentSlots::html($value)))->query($slot['xpath']);
            if(!$nodes || $nodes->length!==1)throw new RuntimeException('Content slot is missing or ambiguous');
            return $nodes->item(0)->nodeValue;
        }
        if(isset($slot['jsonPath'])) {
            $outer=json_decode($value,true,512,JSON_THROW_ON_ERROR);
            $value=json_decode($outer[$slot['nestedJson']],true,512,JSON_THROW_ON_ERROR);
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
        return (bool)preg_match('~^media/t4/optimize/(css/[a-f0-9]{32}\.css|js/[a-f0-9]{32}\.js)$~D',$path);
    }
    private function files(): void {
        foreach($this->lock['files'] as $path=>$hash) {
            $file=$this->root.'/'.$path;
            if($this->generatedCache($path) && !is_link($file))continue;
            if(is_link($file)||!is_file($file)||!hash_equals($hash,hash_file('sha256',$file)))throw new RuntimeException('Presentation asset changed: '.$path);
        }
        foreach($this->lock['fileRoots'] as $prefix) {
            $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root.'/'.$prefix,FilesystemIterator::SKIP_DOTS));
            foreach($iterator as $file)if($file->isFile()) {
                $relative=substr($file->getPathname(),strlen($this->root)+1);
                if($this->generatedCache($relative) && !$file->isLink())continue;
                if(!isset($this->lock['files'][$relative]))throw new RuntimeException('Unexpected presentation file: '.$relative);
            }
        }
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

    public function inspect(): array {
        $this->ready();
        $this->files(); $binding=$this->store->load();
        if($binding && $binding['contractHash']!==$this->contractHash())throw new RuntimeException('Installed content contract changed');
        $job = $this->store->job();
        $languages = $this->effectiveLanguages($binding, $job);
        $switcher = $binding['multilingual']['switcher'] ?? ($job['switcher'] ?? null);
        if ($job && $job['phase'] !== 'completed') [$languages, $switcher] = $this->adoptOrphans($job, $languages, $switcher, $binding);
        if ($languages || $switcher !== null) {
            if (!$this->multilingual) throw new RuntimeException('This site has translations but the receiver carries no multilingual profile');
            foreach ([$binding['multilingual']['profileHash'] ?? null, $job['profileHash'] ?? null] as $seen)
                if ($seen !== null && !hash_equals((string)$seen, $this->multilingual->hash()))
                    throw new RuntimeException('The installed multilingual profile changed');
        }
        // The one phase in which a translated source row may legitimately still be at `*`: the
        // retag is chunked, so mid-phase the site is genuinely half-moved. Every other phase, and
        // every state with no job at all, demands the finished answer.
        $transitional = $job !== null && $job['phase'] === 'prepare';
        $retagged = $languages !== [] && ($binding['multilingual']['languages'] ?? []) !== [] || ($job['retagged'] ?? false);
        $keys = $this->inventoryKeys($languages, $switcher === null ? null : (int)$switcher);
        $ids=[];$rows=[];$lists=[];
        foreach($keys as $key=>$meta) {
            $kind=$meta['kind'];
            if(!isset($lists[$kind])) {
                $lists[$kind]=[];
                for($offset=0;$offset<20000;$offset+=100) {
                    $page=$this->writer->list($kind,$offset,100);
                    foreach($page as $item)$lists[$kind][]=$this->writer->read($kind,(int)$item['id']);
                    if(count($page)<100)break;
                    if($offset===19900)throw new RuntimeException('Inventory limit exceeded');
                }
            }
            $derived = isset($meta['locale']) || !empty($meta['switcher']);
            if($binding || $derived) {
                // A copy is ALWAYS resolved through the binding: two rows that differ only by
                // language cannot be told apart by the identity fields the base contract uses.
                $id = $derived
                    ? (int)(!empty($meta['switcher']) ? $switcher : $languages[$meta['locale']]['ids'][$meta['base']])
                    : (int)($binding['ids'][$key]??0);
                $row = $this->writer->read($kind,$id);
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
        // Resolve foreign keys from the archive's IDs to this installation's IDs.
        $idMaps=[];foreach($this->baseEntities() as $key=>$entity)$idMaps[$entity['kind']][$entity['sourceId']]=$ids[$key];
        // And, per language, from an INSTALLED source id to the id of its copy — what a copied
        // link and a copied menu parent are rewritten with.
        $localeMaps=[];
        foreach($languages as $locale=>$state)
            foreach($state['ids'] as $baseKey=>$id)
                $localeMaps[$locale][$this->baseEntities()[$baseKey]['kind']][$ids[$baseKey]]=(int)$id;
        $protected=[];
        foreach($keys as $key=>$meta) {
            $actual=$this->presentation($key,$rows[$key],$meta);
            if (!empty($meta['switcher'])) {
                $expected=$this->multilingual->switcherPresentation();
            } elseif (isset($meta['locale'])) {
                // Recomputed from the SOURCE as it stands now, never from what was stored when the
                // copy was made: a copy that drifted is caught on the next read.
                $expected=$this->multilingual->derivedPresentation(
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
                throw new RuntimeException('Presentation drift: '.$key.' — '.implode(', ',$differ));
            }
            $protected[$key]=$actual;
        }
        $assignments=[];
        foreach($keys as $key=>$meta)if($meta['kind']==='module') {
            $actual=$this->writer->read('moduleAssignment',$ids[$key]);
            $menus=json_decode($actual['menuids']??'[]',true,512,JSON_THROW_ON_ERROR);sort($menus);
            if (!empty($meta['switcher'])) {
                $expected=[0];
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
            if($menus!=$expected)throw new RuntimeException('Module assignment drift: '.$key);
            $assignments[$key]=$menus;
        }
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
            throw new RuntimeException('Access-level or ACL definition changed: '.implode(', ',$where));
        }
        $counts=array_map('count',$lists);
        $expectedCounts=$this->lock['inventoryCounts'];
        foreach($languages as $locale=>$state)
            foreach($state['ids'] as $baseKey=>$id)$expectedCounts[$this->baseEntities()[$baseKey]['kind']]++;
        if($switcher !== null)$expectedCounts['module']++;
        if($counts!=$expectedCounts)throw new RuntimeException('Quickstart inventory changed');
        $snapshot=['contractHash'=>$this->contractHash(),'ids'=>$ids,'presentation'=>$protected,'assignments'=>$assignments,'counts'=>$counts,'access'=>$this->lock['access']];
        if(isset($binding['multilingual']))$snapshot['multilingual']=$binding['multilingual'];
        $revisionRows=[];
        foreach($rows as $key=>$row)$revisionRows[$key]=array_intersect_key($row,$this->lock['entities'][$keys[$key]['lockKey']]);
        $slots=[];
        foreach($keys as $key=>$meta)foreach($this->slotsOf($key,$meta) as $slot){$slot['current']=$this->currentValue($rows[$key],$slot);$slots[]=$slot;}
        $slotValues=[];foreach($slots as $slot)$slotValues[$slot['key']]=$slot['current'];
        return ['contract'=>$this->manifest['id'],'snapshot'=>$snapshot,'revision'=>$this->digest($revisionRows),
            'slots'=>$slots,'pages'=>$this->map['pages'],'rows'=>$rows,'ids'=>$ids,'keys'=>$keys,'localeMaps'=>$localeMaps,
            'assignments'=>$assignments,'slotValues'=>$slotValues,
            'languages'=>array_keys($binding['multilingual']['languages'] ?? []),
            'binding'=>$binding,'job'=>$job,'switcher'=>$switcher];
    }

    /** Scalar content rules, applied identically to an original and to a translation of it. */
    private function checkValue(array $slot, string $value, array $params): void {
        $key=$slot['key'];
        if (!empty($slot['requiresEvidence']) && $value!==$slot['sample']) {
            $evidence=$params['evidence'][$key]??null;
            if(!is_string($evidence)||trim($evidence)===''||strlen($evidence)>8000)throw new RuntimeException('Customer evidence required: '.$key);
        }
        // Empty ACM fields control conditional markup; changing occupancy changes layout.
        if(trim($value)==='' && trim($slot['sample'])!=='')throw new RuntimeException('Content cannot remove an occupied slot');
        if(trim($value)!=='' && trim($slot['sample'])==='')throw new RuntimeException('Content cannot activate an empty slot');
        if(preg_match('/[<>\x00-\x08\x0b\x0c\x0e-\x1f]/u',$value))throw new RuntimeException('Markup and control characters are not content');
        if(preg_match('/\{\/?[a-z][^{}]*\}/i',$value))throw new RuntimeException('Joomla plugin directives are not content');
        if(mb_strlen($value)>$slot['maxCharacters'])throw new RuntimeException('Content too long: '.$key);
        if($slot['type']==='url' && (strpos($value,'//')===0 || strpos($value,'\\')!==false))throw new RuntimeException('Unsupported CTA URL');
        if($slot['type']==='url'&&$value!==''&&!preg_match('~^(https://[^\s]+|mailto:[^\s]+|tel:[+0-9 ()-]+|index\.php\?Itemid=[0-9]+|/[a-zA-Z0-9/_?&=.%#-]*|#[a-zA-Z0-9_-]+)$~D',$value))throw new RuntimeException('Unsupported CTA URL');
        if($slot['type']==='image'&&$value!=='') {
            if(!preg_match('~^images/[a-zA-Z0-9/_-]+\.(png|jpe?g|webp)$~D',$value)||!is_file($this->root.'/'.$value))throw new RuntimeException('Image must already exist in the site media library');
            $resolved=realpath($this->root.'/'.$value);$imageRoot=realpath($this->root.'/images');
            if(!$resolved||!$imageRoot||strpos($resolved,$imageRoot.DIRECTORY_SEPARATOR)!==0)throw new RuntimeException('Image escapes the site media library');
            $before=@getimagesize($this->root.'/'.$slot['sample']);$after=@getimagesize($this->root.'/'.$value);
            if(!$after||($before&&abs($before[0]/$before[1]-$after[0]/$after[1])>0.02))throw new RuntimeException('Image aspect ratio does not match its slot');
        }
    }

    public function plan(array $params): array {
        $state=$this->inspect();
        if(!isset($params['expected_revision'])||!hash_equals($state['revision'],$params['expected_revision']))throw new RuntimeException('Content changed; inspect again');
        $changes=$params['changes']??null;
        if(!is_array($changes)||!count($changes)||count($changes)>1500)throw new RuntimeException('Expected 1–1500 scalar content changes');
        $allowed=array_column($state['slots'],null,'key');$values=[];
        foreach ($changes as $key => $value) {
            if(!isset($allowed[$key])||!is_string($value))throw new RuntimeException('Unknown content slot or non-string value');
            $this->checkValue($allowed[$key],$value,$params);
            $values[$key]=$value;
        }
        $operations=[];
        foreach($state['keys'] as $key=>$meta) {
            $row=$state['rows'][$key];$next=$this->changeRow($row,$this->slotsOf($key,$meta),$values);$fields=[];$expected=[];
            foreach($next as $field=>$value)if($value!==$row[$field]){$fields[$field]=$value;$expected[$field]=$row[$field];}
            if($fields)$operations[]=['kind'=>$meta['kind'],'id'=>$state['ids'][$key],'fields'=>$fields,'expected'=>$expected];
        }
        return ['operations'=>$operations,'snapshot'=>$state['snapshot']];
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
            foreach($binding['presentation'] as $key=>$fields)
                if(($fields['language']??null)===$profile->sourceLanguage() && $profile->isTranslated($key))
                    $binding['presentation'][$key]['language']='*';
            unset($binding['multilingual']);
        }
        return $binding;
    }

    public function bind(array $snapshot): void { $this->ready(); $this->store->save($snapshot); }
    /** Only a validated language apply or revert replaces a baseline; content applies re-save an identical one. */
    public function rebind(array $binding): void { $this->ready(); $this->store->replace($binding); }
}
