<?php
require_once __DIR__ . '/ContentSlots.php';
require_once __DIR__ . '/ContractAccess.php';

interface ContractStore {
    public function load(): ?array;
    public function save(array $binding): void;
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
    }
    private function ready(): void { if ($this->unavailable !== null) throw new RuntimeException($this->unavailable); }
    public function bound(): bool { $this->ready(); return $this->store->load() !== null; }
    private function digest($value): string { return hash('sha256', json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)); }
    private function contractHash(): string { return $this->digest([$this->manifest,$this->map,$this->lock]); }
    private function entities(): array { return array_column($this->map['entities'], null, 'key'); }
    private function slotsFor(string $key): array { return array_values(array_filter($this->map['slots'], fn($s)=>$s['entity']===$key)); }
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
    private function presentation(string $key, array $row): array {
        $fields=$this->lock['entities'][$key]; $filtered=[];
        foreach($fields as $name=>$v) {
            if(!array_key_exists($name,$row))throw new RuntimeException('Missing protected field '.$name);
            $filtered[$name]=$row[$name];
        }
        $slots=$this->slotsFor($key);
        $filtered=$this->changeRow($filtered,$slots,array_fill_keys(array_column($slots,'key'),'__CONTENT_SLOT__'));
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
    public function inspect(): array {
        $this->ready();
        $this->files(); $binding=$this->store->load();
        if ($this->store->access() != $this->lock['access']) throw new RuntimeException('Access-level or ACL definition changed');
        if($binding && $binding['contractHash']!==$this->contractHash())throw new RuntimeException('Installed content contract changed');
        $ids=[];$rows=[];$lists=[];
        foreach($this->entities() as $key=>$entity) {
            $kind=$entity['kind'];
            if(!isset($lists[$kind])) {
                $lists[$kind]=[];
                for($offset=0;$offset<10000;$offset+=100) {
                    $page=$this->writer->list($kind,$offset,100);
                    foreach($page as $item)$lists[$kind][]=$this->writer->read($kind,(int)$item['id']);
                    if(count($page)<100)break;
                    if($offset===9900)throw new RuntimeException('Inventory limit exceeded');
                }
            }
            if($binding) {
                $id=$binding['ids'][$key]??0; $row=$this->writer->read($kind,$id);
                if(!$row)throw new RuntimeException('Bound entity disappeared: '.$key);
            } else {
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
        $idMaps=[];foreach($this->entities() as $key=>$entity)$idMaps[$entity['kind']][$entity['sourceId']]=$ids[$key];
        $protected=[];
        foreach($this->entities() as $key=>$entity) {
            $expected=$binding['presentation'][$key]??$this->presentation($key,$this->lock['entities'][$key]);
            if(!$binding)foreach(['template_style_id'=>'templateStyle','catid'=>'category','parent_id'=>$entity['kind']==='category'?'category':'menuItem'] as $field=>$kind) {
                if(isset($expected[$field],$idMaps[$kind][(int)$expected[$field]]))$expected[$field]=(string)$idMaps[$kind][(int)$expected[$field]];
            }
            $actual=$this->presentation($key,$rows[$key]);
            if($actual!=$expected)throw new RuntimeException('Presentation drift: '.$key);
            $protected[$key]=$actual;
        }
        $assignments=[];
        foreach($this->entities() as $key=>$e)if($e['kind']==='module') {
            $actual=$this->writer->read('moduleAssignment',$ids[$key]);
            $menus=json_decode($actual['menuids']??'[]',true,512,JSON_THROW_ON_ERROR);sort($menus);
            $expected=[];foreach($this->lock['assignments'] as $a)if((int)$a['moduleid']===$e['sourceId']) {
                $n=(int)$a['menuid'];$expected[]=($n<0?-1:1)*($idMaps['menuItem'][abs($n)]??abs($n));
            }
            sort($expected);
            if($menus!=$expected)throw new RuntimeException('Module assignment drift: '.$key);
            $assignments[$key]=$menus;
        }
        $counts=array_map('count',$lists);
        if($counts!=$this->lock['inventoryCounts'])throw new RuntimeException('Quickstart inventory changed');
        if($binding && $counts!=$binding['counts'])throw new RuntimeException('Content structure changed');
        $snapshot=['contractHash'=>$this->contractHash(),'ids'=>$ids,'presentation'=>$protected,'assignments'=>$assignments,'counts'=>$counts,'access'=>$this->lock['access']];
        $revisionRows=[];
        foreach($rows as $key=>$row)$revisionRows[$key]=array_intersect_key($row,$this->lock['entities'][$key]);
        return ['contract'=>$this->manifest['id'],'snapshot'=>$snapshot,'revision'=>$this->digest($revisionRows),'slots'=>array_map(function($slot)use($rows){$slot['current']=$this->currentValue($rows[$slot['entity']],$slot);return $slot;},$this->map['slots']),'pages'=>$this->map['pages'],'rows'=>$rows];
    }
    public function plan(array $params): array {
        $state=$this->inspect();
        if(!isset($params['expected_revision'])||!hash_equals($state['revision'],$params['expected_revision']))throw new RuntimeException('Content changed; inspect again');
        $changes=$params['changes']??null;
        if(!is_array($changes)||!count($changes)||count($changes)>1500)throw new RuntimeException('Expected 1–1500 scalar content changes');
        $allowed=array_column($this->map['slots'],null,'key');$values=[];
        foreach($changes as $key=>$value) {
            if(!isset($allowed[$key])||!is_string($value))throw new RuntimeException('Unknown content slot or non-string value');
            $slot=$allowed[$key];
            if(!empty($slot['requiresEvidence']) && $value!==$slot['sample']) {
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
            $values[$key]=$value;
        }
        $operations=[];
        foreach($this->entities() as $key=>$e) {
            $row=$state['rows'][$key];$next=$this->changeRow($row,$this->slotsFor($key),$values);$fields=[];$expected=[];
            foreach($next as $field=>$value)if($value!==$row[$field]){$fields[$field]=$value;$expected[$field]=$row[$field];}
            if($fields)$operations[]=['kind'=>$e['kind'],'id'=>$state['snapshot']['ids'][$key],'fields'=>$fields,'expected'=>$expected];
        }
        return ['operations'=>$operations,'snapshot'=>$state['snapshot']];
    }
    public function bind(array $snapshot): void { $this->ready(); $this->store->save($snapshot); }
}
