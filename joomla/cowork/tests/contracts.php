<?php
// Loaded by run.php: exercise the write boundary, not generated JSON's spelling.

$aclInventory=['viewlevels'=>[['id'=>'1','rules'=>'[1]']],'usergroups'=>[['id'=>'1','parent_id'=>'0']],
    'assets'=>[['id'=>'1','parent_id'=>'0','name'=>'root.1','rules'=>'{}'],['id'=>'18','parent_id'=>'1','name'=>'com_modules','rules'=>'{"core.edit":{"7":1}}']],
    'modules'=>[['id'=>'178','asset_id'=>'0']],'content'=>[],'categories'=>[]];
$beforeAcl=ContractAccess::snapshot($aclInventory);
$aclInventory['assets'][]=['id'=>'236','parent_id'=>'18','name'=>'com_modules.module.178','rules'=>'{}'];
$aclInventory['modules'][0]['asset_id']='236';
check('Joomla may create an inherited ACL asset without changing permissions',ContractAccess::snapshot($aclInventory),$beforeAcl);
$aclInventory['assets'][2]['parent_id']='1';
checkTrue('reparenting the asset cannot silently change effective ACL',ContractAccess::snapshot($aclInventory)!=$beforeAcl);

final class TestContractStore implements ContractStore {
    public ?array $binding=null;
    public ?array $job=null;
    public array $acl=['viewlevels'=>[['id'=>'1','rules'=>[1]]]];
    public function load(): ?array { return $this->binding; }
    public function save(array $value): void {
        if($this->binding!==null){ if($this->binding!=$value)throw new RuntimeException('Cannot replace a content-only baseline'); return; }
        $this->binding=$value;
    }
    public function replace(array $value): void { $this->binding=$value; }
    public function job(): ?array { return $this->job; }
    public function saveJob(?array $job): void { $this->job=$job; }
    public function access(): array { return $this->acl; }
}
final class ContractTestWriter extends FakeSiteWriter {
    private FakeApplyLog $log; private ContractStore $binding;
    public bool $drift=false;
    /** [kind, id] whose next write throws, to stand in for a row Joomla refuses mid-batch. */
    public ?array $failOn=null;
    /** [kind, id] whose next write (once) LANDS and then throws: a Joomla write that fails after Table::store() (tags, assets). */
    public ?array $failAfter=null;
    /** [kind, id] that refuses every write made outside a transaction: an undo that cannot be written back. */
    public ?array $failOutside=null;
    /**
     * Joomla's Table::store() on an article or a module stores its `#__assets` row through
     * Table\Nested::store(), which takes `LOCK TABLES #__assets WRITE`: an implicit COMMIT in MySQL
     * and MariaDB, after which every statement autocommits. Measured 08/10/2026 on a JA Podcast j6
     * stand (TCH #1013, D3): a failed contract apply kept every write before the failing one. On,
     * this double's rollback does the same: once such a write ran, nothing is rolled back.
     */
    public bool $tableCommits=false;
    private bool $committed=false;
    /**
     * Joomla's Table::store() on an article writes its `#__assets` row as a side effect —
     * creating one, parented at the root, for a row that had none. Measured 23/09/2026 on
     * `j-1pd0de` (ja-kinetic): the demo posts ship without assets, and one trim through write()
     * minted six, which moved their effective ACL. On, this double does the same to the ACL.
     */
    public bool $tableAssets=false;
    /** Joomla's Table\Menu writes a new item's `path` from its parent's and its alias; on, this does too. */
    public bool $nestedPaths=false;
    /** `#__extensions` as JoomlaSiteWriter::componentIdFor() reads it: component element => extension_id. */
    public array $components=[];
    /** Each table's columns and their defaults: kind => [column => default]. */
    public array $columns=[];
    public function __construct(FakeApplyLog $log,ContractStore $binding){$this->log=$log;$this->binding=$binding;}
    /** @var array<string,array<int,string>> association context => member id => group key, as #__associations holds it */
    public array $groups=[];
    /** The gate's shipped editions, per relation and base key, before they are laid into $groups. */
    public array $editionGroups=[];
    private const RELATIONS=['menuAssociation'=>'com_menus.item','articleAssociation'=>'com_content.item'];
    public function read(string $kind,int $id):?array {
        if(!$this->nestedPaths||!isset(self::RELATIONS[$kind]))return parent::read($kind,$id);
        $key=$this->groups[$kind][$id]??null;$members=[];
        if($key!==null)foreach($this->groups[$kind] as $mid=>$k)if($k===$key)$members[]=['id'=>$mid,'context'=>self::RELATIONS[$kind],'key'=>$k];
        return ['members'=>json_encode($members)];
    }
    public function write(string $kind,int $id,array $fields):int {
        if($this->failOn===[$kind,$id])throw new RuntimeException('The site refused '.$kind.' '.$id);
        if($this->failOutside===[$kind,$id] && !$this->open)throw new RuntimeException('The site refused '.$kind.' '.$id.' outside a transaction');
        if($this->tableCommits && $this->open && in_array($kind,['article','module'],true))$this->committed=true;
        if($this->failAfter===[$kind,$id]){$this->failAfter=null;parent::write($kind,$id,array_merge($this->read($kind,$id)??[],$fields));throw new RuntimeException('The site refused '.$kind.' '.$id.' after storing it');}
        // JoomlaRelations' rules: one group per item, and a new group never swallows part of another.
        if($this->nestedPaths && isset(self::RELATIONS[$kind])){
            $old=json_decode($this->read($kind,$id)['members'],true);
            if(isset($fields['members']))$members=json_decode((string)$fields['members'],true);
            else{
                $ids=json_decode((string)$fields['ids'],true);sort($ids);$key=md5(json_encode($ids));
                $langs=array_map(fn($m)=>(string)(parent::read($kind==='menuAssociation'?'menuItem':'article',$m)['language']??''),$ids);
                if(in_array('*',$langs,true)||count(array_unique($langs))!==count($ids))throw new RuntimeException('association requires one item per specific language');
                foreach($ids as $m)foreach(json_decode($this->read($kind,$m)['members'],true) as $e)
                    if(!in_array((int)$e['id'],$ids,true))throw new RuntimeException('association already belongs to another group');
                $members=array_map(fn($m)=>['id'=>$m,'key'=>$key],$ids);
            }
            foreach(array_merge([['id'=>$id]],$old,$members) as $e)unset($this->groups[$kind][(int)$e['id']]);
            foreach($members as $e)$this->groups[$kind][(int)$e['id']]=$e['key'];
            return $id;
        }
        if($this->tableAssets && $kind==='article')$this->binding->acl['entityRules']['content.'.$id]='minted by Table::store';
        $fields=array_merge($this->read($kind,$id)??[],$fields);
        // The real writer's create defaults for a menu item (JoomlaSiteWriter::MAP['menuItem']['defaults']),
        // under whatever the caller sent — the same order it merges them in.
        if($this->nestedPaths && $kind==='menuItem' && $id===0)
            $fields=array_merge(['type'=>'url','published'=>1,'access'=>1,'language'=>'*','browserNav'=>0,'note'=>'','params'=>'{}',
                'img'=>'','template_style_id'=>0,'component_id'=>0,'client_id'=>0,'publish_up'=>null,'publish_down'=>null],$fields);
        // A created row reads back every column its table has, at the table's default.
        if($id===0 && isset($this->columns[$kind]))$fields=array_merge($this->columns[$kind],$fields);
        // The real writer resolves a component item's component_id from its link, whatever was sent.
        if($this->nestedPaths && $kind==='menuItem' && $id===0 && ($fields['type']??'')==='component')
            $fields['component_id']=preg_match('/option=([a-z0-9_]+)/i',(string)($fields['link']??''),$m)?(int)($this->components[$m[1]]??0):0;
        if($this->nestedPaths && $kind==='menuItem' && $id===0 && (!isset($fields['path']) || $fields['path']==='')){
            $parent=$this->read('menuItem',(int)($fields['parent_id']??1));
            $fields['path']=($parent && (int)($fields['parent_id']??1)!==1 ? $parent['path'].'/' : '').($fields['alias']??'');
            $fields['level']=(string)(($parent && (int)($fields['parent_id']??1)!==1 ? (int)$parent['level'] : 0)+1);
        }
        // MariaDB's idx_client_id_parent_id_alias_language, which the real insert meets.
        if($this->nestedPaths && $kind==='menuItem' && $id===0)foreach($this->store['menuItem']??[] as $other)
            if((int)($other['client_id']??0)===(int)($fields['client_id']??0) && (int)($other['parent_id']??1)===(int)($fields['parent_id']??1)
                && (string)($other['alias']??'')===(string)($fields['alias']??'') && (string)($other['language']??'')===(string)($fields['language']??''))
                throw new RuntimeException("Duplicate entry '".(int)($fields['client_id']??0).'-'.(int)($fields['parent_id']??1).'-'.$fields['alias'].'-'.$fields['language']."'");
        if($this->drift && $kind==='module')$fields['position']='wrong-position';
        return parent::write($kind,$id,$fields);
    }
    public function setVisibility(string $kind,int $id,string $column,string $value):void {
        if($this->failOn===[$kind,$id])throw new RuntimeException('The site refused '.$kind.' '.$id);
        parent::setVisibility($kind,$id,$column,$value);
    }
    /**
     * Run just before a compare-and-set reaches the row, with this writer and the row's [kind, id]:
     * a save that lands between the caller's read and its write.
     */
    public $beforeCas=null;
    public function setVisibilityIf(string $kind,int $id,string $column,string $expected,string $value):bool {
        if($this->failOn===[$kind,$id])throw new RuntimeException('The site refused '.$kind.' '.$id);
        if($this->beforeCas)($this->beforeCas)($this,$kind,$id);
        return parent::setVisibilityIf($kind,$id,$column,$expected,$value);
    }
    public function replaceMenuItemParams(int $id,string $expected,string $params):bool {
        if($this->failOn===['menuItem',$id])throw new RuntimeException('The site refused menuItem '.$id);
        if($this->beforeCas)($this->beforeCas)($this,'menuItem',$id);
        return parent::replaceMenuItemParams($id,$expected,$params);
    }
    public function replaceTemplateStyleParams(int $id,string $expected,string $params):bool {
        if($this->failOn===['templateStyle',$id])throw new RuntimeException('The site refused templateStyle '.$id);
        if($this->beforeCas)($this->beforeCas)($this,'templateStyle',$id);
        return parent::replaceTemplateStyleParams($id,$expected,$params);
    }
    /** Rows another connection committed after this transaction's snapshot: only a locking read sees them. */
    public ?Closure $lateChildren=null;
    /** @var list<bool> whether a transaction was open at each lockMenuChildren() */
    public array $lockedOpen=[];
    public function lockMenuChildren(int $parent):array {
        $this->lockedOpen[]=$this->open;
        $rows=parent::lockMenuChildren($parent);
        return $this->lateChildren?array_merge($rows,($this->lateChildren)($parent)):$rows;
    }
    /** Whether a transaction is open right now: work that must follow the commit can say it did. */
    public bool $open=false;
    public function transaction(callable $work):array {
        $before=[$this->store,$this->log->log,$this->binding->binding,$this->binding->job];
        $this->open=true;$this->committed=false;
        try{return $work();}catch(Throwable $error){
            if(!$this->committed)[$this->store,$this->log->log,$this->binding->binding,$this->binding->job]=$before;
            throw $error;
        }finally{$this->open=false;}
    }
}
$contractDir=sys_get_temp_dir().'/cowork-contract-'.bin2hex(random_bytes(6));
mkdir($contractDir);mkdir($contractDir.'/assets');
file_put_contents($contractDir.'/assets/demo.css','.hero { color: red }');
$module=['title'=>'Home hero','module'=>'mod_custom','position'=>'masthead','published'=>'1','publish_up'=>null,'publish_down'=>null,'ordering'=>'1','access'=>'1','showtitle'=>'0','language'=>'*','client_id'=>'0','content'=>'<h1 class="hero">Demo title</h1><a href="/start">Start</a>','params'=>'{"moduleclass_sfx":"original","module_tag":"div","header_tag":"h3","style":"0","cache":"0"}'];
$entities=[['key'=>'hero','kind'=>'module','sourceId'=>10,'identity'=>['title'=>'Home hero']]];
$protected=['hero'=>$module];
$cw=new FakeSiteWriter();$cw->store['module'][110]=['id'=>'110']+$module;
foreach([20=>120,21=>121] as $old=>$actual) {
    $key='menu-'.$old;$row=['title'=>$key,'path'=>$key,'published'=>'1','access'=>'1','language'=>'*'];
    $entities[]=['key'=>$key,'kind'=>'menuItem','sourceId'=>$old,'identity'=>['path'=>$key]];
    $protected[$key]=$row;$cw->store['menuItem'][$actual]=['id'=>(string)$actual]+$row;
}
$cw->store['moduleAssignment'][110]=['menuids'=>'[-121]'];
$slots=[];
foreach(ContentSlots::htmlSlots($module['content']) as $n=>$s)$slots[]=['key'=>'hero.'.$n,'entity'=>'hero','column'=>'content','maxCharacters'=>80]+$s;
$cs=new TestContractStore();
$data=['manifest'=>['id'=>'test/v1'],'content-map'=>['entities'=>$entities,'slots'=>$slots,'pages'=>[]],'presentation-lock'=>['entities'=>$protected,'assignments'=>[['moduleid'=>10,'menuid'=>-21]],'fileRoots'=>['assets'],'files'=>['assets/demo.css'=>hash_file('sha256',$contractDir.'/assets/demo.css')],'inventoryCounts'=>['module'=>1,'menuItem'=>2],'access'=>$cs->acl]];
foreach($data as $name=>$body)file_put_contents($contractDir.'/'.$name.'.json',json_encode($body));
mkdir($contractDir.'/media/t4/optimize/css',0777,true);
$cache='media/t4/optimize/css/'.str_repeat('a',32).'.css';
file_put_contents($contractDir.'/'.$cache,'derived');
$data['presentation-lock']['fileRoots'][]='media';
$data['presentation-lock']['files'][$cache]=hash_file('sha256',$contractDir.'/'.$cache);
file_put_contents($contractDir.'/presentation-lock.json',json_encode($data['presentation-lock']));
$contract=new QuickstartContract($cw,$cs,$contractDir,$contractDir);
check('Joomla loadposition directives are never editable text',ContentSlots::htmlSlots('<p>{loadposition about-page}</p>'),[]);
unlink($contractDir.'/'.$cache);
$newCache='media/t4/optimize/css/'.str_repeat('b',32).'.css';
file_put_contents($contractDir.'/'.$newCache,'regenerated from locked sources');
$state=$contract->inspect();
check('contract maps negative excluded menu IDs onto the installed site',$state['snapshot']['assignments']['hero'],[-121]);
$contract->bind($state['snapshot']);
$readBefore=[$cw->store,$cs->binding,$cs->job];
$readMapping=$contract->readMapping();
check('read mapping preserves binding, rows and jobs',[$cw->store,$cs->binding,$cs->job],$readBefore);
check('read mapping uses bound IDs',$readMapping['ids'],$state['ids']);
checkTrue('read mapping never exports sample values',!isset($readMapping['slots']['hero'][0]['sample']));
// Slot roles: a fictional contract whose slots carry role and group answers with them; the one above does not.
checkTrue('a contract without roles answers without role fields',!isset($readMapping['slots']['hero'][0]['role']) && !isset($readMapping['slots']['hero'][0]['group']));
$rolesDir=sys_get_temp_dir().'/cowork-contract-roles-'.bin2hex(random_bytes(6));
mkdir($rolesDir);mkdir($rolesDir.'/assets');mkdir($rolesDir.'/media/t4/optimize/css',0777,true);
copy($contractDir.'/assets/demo.css',$rolesDir.'/assets/demo.css');
$roleData=$data;
$roleData['content-map']['slots'][0]['role']='editorial.heading';
$roleData['content-map']['slots'][0]['group']=['id'=>'module-10.item.1','item'=>1,'kind'=>'module'];
$roleData['content-map']['roleVocabularyVersion']='example-roles/1';
unset($roleData['presentation-lock']['files'][$cache],$roleData['presentation-lock']['files'][$newCache]);
$roleData['presentation-lock']['fileRoots']=['assets'];
foreach($roleData as $name=>$body)file_put_contents($rolesDir.'/'.$name.'.json',json_encode($body));
$roleStore=new TestContractStore();
$roleContract=new QuickstartContract($cw,$roleStore,$rolesDir,$rolesDir);
$roleContract->bind($roleContract->inspect()['snapshot']);
$roleMapping=$roleContract->readMapping();
check('a contract with roles keeps the role in the slot list',$roleMapping['slots']['hero'][0]['role'],'editorial.heading');
check('and the group',$roleMapping['slots']['hero'][0]['group'],['id'=>'module-10.item.1','item'=>1,'kind'=>'module']);
check('and tells the agent how to use them',$roleMapping['roleGuidance'],QuickstartContract::ROLE_GUIDANCE);
checkTrue('a contract without roles gives no guidance',!isset($readMapping['roleGuidance']));
checkTrue('a slot without a role stays without one',count($roleMapping['slots']['hero'])<2 || !isset($roleMapping['slots']['hero'][1]['role']));
checkTrue('roles do not reveal the demo sample',!isset($roleMapping['slots']['hero'][0]['sample']));
// Slot fit (ADR 0039): a fictional contract whose slots carry a fit answers with it, compact; the one above does not.
checkTrue('a contract without fit answers without fit fields',!isset($readMapping['slots']['hero'][0]['fit']) && !isset($readMapping['fitGuidance']));
$fitDir=sys_get_temp_dir().'/cowork-contract-fit-'.bin2hex(random_bytes(6));
mkdir($fitDir);mkdir($fitDir.'/assets');
copy($contractDir.'/assets/demo.css',$fitDir.'/assets/demo.css');
$fitData=$roleData;
$fitData['content-map']['slotFitVersion']='slot-fit/1';
$fitData['content-map']['slots'][0]['fit']=['measured'=>true,'boxes'=>['desktop'=>['width'=>400,'height'=>40],'phone'=>['width'=>300,'height'=>80]],
    'lines'=>['desktop'=>1,'phone'=>2],'maxChars'=>['desktop'=>20,'phone'=>16],'maxCharacters'=>16,'casing'=>'none','overflow'=>'rewrite'];
$fitData['content-map']['slots'][2]['fit']=['not','an','object'];
foreach($fitData as $name=>$body)file_put_contents($fitDir.'/'.$name.'.json',json_encode($body));
$fitStore=new TestContractStore();
$fitBound=new QuickstartContract($cw,$fitStore,$fitDir,$fitDir);
$fitBound->bind($fitBound->inspect()['snapshot']);
$fitMapping=$fitBound->readMapping();
check('a contract with fit carries it compact in the slot list',$fitMapping['slots']['hero'][0]['fit'],
    ['measured'=>true,'lines'=>['desktop'=>1,'phone'=>2],'maxChars'=>['desktop'=>20,'phone'=>16],'maxCharacters'=>16,'casing'=>'none','overflow'=>'rewrite']);
check('beside its role',$fitMapping['slots']['hero'][0]['role'],'editorial.heading');
check('and tells the agent how to use it',$fitMapping['fitGuidance'],SlotFit::GUIDANCE);
checkTrue('a malformed fit is left out of the slot list',!array_key_exists('fit',$fitMapping['slots']['hero'][2]));
$fitRevision=$fitBound->inspect()['revision'];
try { $fitBound->plan(['expected_revision'=>$fitRevision,'changes'=>['hero.0'=>'A heading far too long for its box']]); check('an apply over the fit is refused',true,false); }
catch (ContractProblem $e) { check('an apply over the fit is refused',[$e->reason,$e->extra],['SLOT_TOO_LONG',['limit'=>16,'actual'=>34]]); }
check('an apply within the fit is planned',count($fitBound->plan(['expected_revision'=>$fitRevision,'changes'=>['hero.0'=>'Short heading']])['operations'])>0,true);
try { $fitBound->plan(['expected_revision'=>$fitRevision,'changes'=>['hero.0'=>'Short heading'],'replace_kept'=>['hero.0']]); check('replace_kept must be a map',true,false); }
catch (ContractProblem $e) { check('replace_kept must be a map',$e->reason,'CHANGES_INVALID'); }
$cs->job=['phase'=>'copy','locale'=>'vi-VN','ids'=>[]];
try { $contract->readMapping(); check('unfinished job refuses pure reader',true,false); }
catch (RuntimeException $e) { check('unfinished job refuses pure reader',$e->getMessage(),'Content mapping has an unfinished language job'); }
check('pure reader does not adopt unfinished jobs',$cs->job,['phase'=>'copy','locale'=>'vi-VN','ids'=>[]]);
$cs->job=null;

$plan=$contract->plan(['expected_revision'=>$state['revision'],'changes'=>['hero.0'=>'Customer & partners']]);
check('contract updates the original module ID',$plan['operations'][0]['id'],110);
checkTrue('HTML content stays escaped',str_contains($plan['operations'][0]['fields']['content'],'Customer &amp; partners'));
// An attribute written with `&` in it (a link's query, an alt text) is stored whole and reads back as
// written. It used to go through DOMAttr::$nodeValue, which reads `&` as the start of an entity
// reference: libxml 2.9 stored an empty attribute (with an "unterminated entity reference" warning),
// libxml 2.13 dropped the `&`. A reference the value already spells out (`&amp;`) is still one
// reference, so it is not escaped twice.
$ampPlan=function(string $url) use($contract,$state):string {
    try { return $contract->plan(['expected_revision'=>$state['revision'],'changes'=>['hero.1'=>$url]])['operations'][0]['fields']['content']; }
    catch (Throwable $error) { return 'refused: '.$error->getMessage(); }
};
foreach(['/path?a=1&b=2'=>'/path?a=1&amp;b=2','https://x.test/search?q=a+b&lang=en&page=2'=>'https://x.test/search?q=a+b&amp;lang=en&amp;page=2'] as $url=>$stored) {
    $content=$ampPlan($url);
    check('a link with & in its query is stored whole: '.$url,$content,'<h1 class="hero">Demo title</h1><a href="'.$stored.'">Start</a>');
    check('a link with & in its query reads back as written: '.$url,ContentSlots::htmlSlots($content)[1]['sample']??null,$url);
}
$ampPatch=function(string $html,string $xpath,string $value):array {
    $out=ContentSlots::htmlPatch($html,[['xpath'=>$xpath,'value'=>$value]]);
    return [$out,(new DOMXPath(ContentSlots::html($out)))->query($xpath)->item(0)->nodeValue];
};
check('a Joomla article link keeps every & of its query',
    $ampPatch('<a href="/old">Read</a>','/html/body/div/a/@href','index.php?option=com_content&view=article&id=5'),
    ['<a href="index.php?option=com_content&amp;view=article&amp;id=5">Read</a>','index.php?option=com_content&view=article&id=5']);
check('a form action keeps every & of its query',
    $ampPatch('<form action="/old"><button>Go</button></form>','/html/body/div/form/@action','/search?q=1&r=2'),
    ['<form action="/search?q=1&amp;r=2"><button>Go</button></form>','/search?q=1&r=2']);
check('an alt text keeps its &',
    $ampPatch('<img src="/team.jpg" alt="Team">','/html/body/div/img/@alt','Sales & Marketing team'),
    ['<img src="/team.jpg" alt="Sales &amp; Marketing team">','Sales & Marketing team']);
check('a name that only looks like an entity, with no ;, stays text',
    $ampPatch('<a href="/old">Go</a>','/html/body/div/a/@href','/p?a=1&copy=2&lang=en&amp=3'),
    ['<a href="/p?a=1&amp;copy=2&amp;lang=en&amp;amp=3">Go</a>','/p?a=1&copy=2&lang=en&amp=3']);
check('a link that already spells & as &amp; is not escaped twice',
    $ampPatch('<a href="/old">Go</a>','/html/body/div/a/@href','/p?a=1&amp;b=2'),
    ['<a href="/p?a=1&amp;b=2">Go</a>','/p?a=1&b=2']);
function contractRejects(string $label, callable $work): void {
    try { $work();check($label,'accepted','rejected'); }
    catch (RuntimeException $error) { check($label,'rejected','rejected'); }
}
// Tracy ADR 0022 (26/09/2026): a site that differs from its quickstart's design is REPORTED, never
// refused — the inspect answers, and the difference is in its warnings.
function contractDrifts(string $label, QuickstartContract $contract, callable $work): void {
    try { $work();check($label,$contract->driftWarnings()!==[]?'reported':'silent','reported'); }
    catch (RuntimeException $error) { check($label,'refused: '.$error->getMessage(),'reported'); }
}
check('T4 cache regeneration keeps the source contract valid',$contract->inspect()['contract'],'test/v1');
file_put_contents($contractDir.'/media/t4/optimize/css/injected.php','unexpected executable');
contractDrifts('cache exception never allows executable files',$contract,fn()=>$contract->inspect());
unlink($contractDir.'/media/t4/optimize/css/injected.php');
// T4 compiles `media/t4/css/<styleId>-sub.css` the first time a style renders a "subpage" (a page
// that is not its menu item's own target) — measured 23/09/2026 on j-cr4l1l (ja-kinetic): one
// visit wrote 36-sub.css, and inspect refused the site with "Unexpected presentation file".
mkdir($contractDir.'/media/t4/css',0777,true);
file_put_contents($contractDir.'/media/t4/css/36-sub.css','compiled from locked sources');
check('a T4 subpage stylesheet is cache, not a presentation change',$contract->inspect()['contract'],'test/v1');
foreach(['36-sub.css.php','evil.css','36-sub.js'] as $name) {
    file_put_contents($contractDir.'/media/t4/css/'.$name,'not a compiled stylesheet');
    contractDrifts('the subpage exception does not admit '.$name,$contract,fn()=>$contract->inspect());
    unlink($contractDir.'/media/t4/css/'.$name);
}
// A main style compiled after a clean restore is not an authored change. Pinned files still are.
$styleDir=sys_get_temp_dir().'/cowork-style-cache-'.bin2hex(random_bytes(6));
mkdir($styleDir.'/media/t4/css',0777,true);
$styleData=$data;
$styleRow=['title'=>'QA style','template'=>'qa_template','client_id'=>'0'];
$styleData['content-map']['entities'][]=['key'=>'qa-style','kind'=>'templateStyle','sourceId'=>9,'identity'=>$styleRow];
$styleData['presentation-lock']['entities']['qa-style']=$styleRow;
$styleData['presentation-lock']['fileRoots']=['media'];
$styleData['presentation-lock']['files']=[];
foreach($styleData as $name=>$body)file_put_contents($styleDir.'/'.$name.'.json',json_encode($body));
$styleWriter=clone $cw;$styleWriter->store['templateStyle'][9]=['id'=>'9']+$styleRow;
$styleStore=new TestContractStore();
$styleContract=new QuickstartContract($styleWriter,$styleStore,$styleDir,$styleDir);
file_put_contents($styleDir.'/media/t4/css/9.css','compiled known style');
$styleContract->inspect();
checkTrue('first render of a known unlisted T4 style is not reported as a user edit',!str_contains(implode(' ', $styleContract->driftWarnings()),'9.css'));
file_put_contents($styleDir.'/media/t4/css/999.css','unknown style');
$styleContract->inspect();
checkTrue('a numeric CSS file of an unknown style is still reported',str_contains(implode(' ', $styleContract->driftWarnings()),'Unexpected presentation file: media/t4/css/999.css'));
unlink($styleDir.'/media/t4/css/999.css');unlink($styleDir.'/media/t4/css/9.css');
symlink($contractDir.'/assets/demo.css',$styleDir.'/media/t4/css/9.css');
$styleContract->inspect();
checkTrue('a known style name never exempts a symlink',str_contains(implode(' ', $styleContract->driftWarnings()),'Unexpected presentation file: media/t4/css/9.css'));
unlink($styleDir.'/media/t4/css/9.css');file_put_contents($styleDir.'/media/t4/css/9.css','pinned style');
$styleData['presentation-lock']['files']['media/t4/css/9.css']=hash_file('sha256',$styleDir.'/media/t4/css/9.css');
file_put_contents($styleDir.'/presentation-lock.json',json_encode($styleData['presentation-lock']));
$styleContract=new QuickstartContract($styleWriter,$styleStore,$styleDir,$styleDir);
file_put_contents($styleDir.'/media/t4/css/9.css','changed pinned style');
$styleContract->inspect();
checkTrue('an explicitly pinned numeric style keeps its hash check',str_contains(implode(' ', $styleContract->driftWarnings()),'Presentation asset changed: media/t4/css/9.css'));
// This plugin's own media (the T3 off-canvas keyboard script) is installed under `media/`, which
// T3 locks list as a file root: measured on every T3 stand, inspect warned about it right after install.
$ownMedia='media/plg_system_claudecoworkapi/js/t3-offcanvas-a11y.js';
$ownWarned=function(string $path)use($styleWriter,$styleStore,$styleDir):bool{$c=new QuickstartContract($styleWriter,$styleStore,$styleDir,$styleDir);$c->inspect();return in_array('Unexpected presentation file: '.$path,$c->driftWarnings(),true);};
mkdir($styleDir.'/media/plg_system_claudecoworkapi/js',0777,true);
copy(__DIR__.'/../plg_system_claudecoworkapi/media/js/t3-offcanvas-a11y.js',$styleDir.'/'.$ownMedia);
checkTrue('without the plugin installed, its media folder is anyone\'s file',$ownWarned($ownMedia));
mkdir($styleDir.'/plugins/system/claudecoworkapi',0777,true);
copy(__DIR__.'/../plg_system_claudecoworkapi/claudecoworkapi.xml',$styleDir.'/plugins/system/claudecoworkapi/claudecoworkapi.xml');
checkTrue('the plugin\'s own shipped media is not presentation drift',!$ownWarned($ownMedia));
foreach(['media/plg_system_claudecoworkapi/js/unshipped.js','media/plg_system_claudecoworkapi/js/evil.php','media/plg_system_claudecoworkapi/other/x.js','media/plg_system_claudecoworkapi/x.js','media/plg_system_claudecoworkapix/js/x.js','media/templates/site/x/js/x.js'] as $path) {
    @mkdir(dirname($styleDir.'/'.$path),0777,true);file_put_contents($styleDir.'/'.$path,'not shipped by the plugin');
    checkTrue('a file the plugin does not ship is still reported: '.$path,$ownWarned($path));
    unlink($styleDir.'/'.$path);
}
// Exempt are the bytes this version ships, not the file name: an edited copy is drift like any other.
file_put_contents($styleDir.'/'.$ownMedia,"\n// edited on the site",FILE_APPEND);
checkTrue('a changed copy of the plugin\'s shipped media is still reported',$ownWarned($ownMedia));
// The list the engine holds is the media the plugin's manifest installs, file for file and byte for byte
// (build.sh refuses a package on the same rule: scripts/joomla-own-media.mjs).
$ownManifest=file_get_contents(__DIR__.'/../plg_system_claudecoworkapi/claudecoworkapi.xml');
preg_match('~<media\b[^>]*\bfolder="([^"]+)"[^>]*\bdestination="([^"]+)"[^>]*>(.*?)</media>~s',$ownManifest,$ownMediaBlock);
preg_match_all('~<(folder|filename)>\s*([^<\s]+)\s*</\1>~',$ownMediaBlock[3],$ownEntries,PREG_SET_ORDER);
$ownShipped=[];$ownSource=__DIR__.'/../plg_system_claudecoworkapi/'.$ownMediaBlock[1];
foreach($ownEntries as [,$kind,$entry]) {
    $sources=$kind==='folder' ? iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($ownSource.'/'.$entry,FilesystemIterator::SKIP_DOTS))) : [new SplFileInfo($ownSource.'/'.$entry)];
    foreach($sources as $source)if($source->isFile() && $source->getFilename()!=='.DS_Store')
        $ownShipped['media/'.$ownMediaBlock[2].'/'.substr($source->getPathname(),strlen($ownSource)+1)]=hash_file('sha256',$source->getPathname());
}
ksort($ownShipped);
checkTrue('the engine exempts exactly the media the plugin ships, with their current hashes',(new ReflectionClassConstant(QuickstartContract::class,'OWN_MEDIA'))->getValue()===$ownShipped);
unlink($styleDir.'/'.$ownMedia);symlink($contractDir.'/assets/demo.css',$styleDir.'/'.$ownMedia);
checkTrue('a link in the plugin\'s media folder is still reported',$ownWarned($ownMedia));
unlink($styleDir.'/'.$ownMedia);
// A linked folder is never walked into, so it is reported itself: whatever it holds is not the lock's.
$ownLinkedDir='media/plg_system_claudecoworkapi/js/linked';
symlink($contractDir.'/assets',$styleDir.'/'.$ownLinkedDir);
checkTrue('a linked folder in the plugin\'s media folder is reported',$ownWarned($ownLinkedDir));
$c=new QuickstartContract($styleWriter,$styleStore,$styleDir,$styleDir);$c->inspect();
checkTrue('a linked folder is not walked into',!str_contains(implode(' ',$c->driftWarnings()),$ownLinkedDir.'/'));
unlink($styleDir.'/'.$ownLinkedDir);
rmdir($styleDir.'/media/plg_system_claudecoworkapi/other');rmdir($styleDir.'/media/plg_system_claudecoworkapi/js');rmdir($styleDir.'/media/plg_system_claudecoworkapi');
rmdir($styleDir.'/media/plg_system_claudecoworkapix/js');rmdir($styleDir.'/media/plg_system_claudecoworkapix');
rmdir($styleDir.'/media/templates/site/x/js');rmdir($styleDir.'/media/templates/site/x');rmdir($styleDir.'/media/templates/site');rmdir($styleDir.'/media/templates');
unlink($styleDir.'/plugins/system/claudecoworkapi/claudecoworkapi.xml');rmdir($styleDir.'/plugins/system/claudecoworkapi');rmdir($styleDir.'/plugins/system');rmdir($styleDir.'/plugins');
unlink($styleDir.'/media/t4/css/9.css');rmdir($styleDir.'/media/t4/css');rmdir($styleDir.'/media/t4');rmdir($styleDir.'/media');
foreach(array_keys($styleData) as $name)unlink($styleDir.'/'.$name.'.json');rmdir($styleDir);
// A locked root that is itself a link, or sits under one, is walked as its target: every file there
// reads as no link, and the walk names files outside the webroot. The link is reported instead.
$rootLinkDir=sys_get_temp_dir().'/cowork-root-link-'.bin2hex(random_bytes(6));
try {
    mkdir($rootLinkDir.'/web',0777,true);mkdir($rootLinkDir.'/outside/assets',0777,true);mkdir($rootLinkDir.'/outside/media/t4',0777,true);
    copy($contractDir.'/assets/demo.css',$rootLinkDir.'/outside/assets/demo.css');
    file_put_contents($rootLinkDir.'/outside/assets/foreign.php','<?php');file_put_contents($rootLinkDir.'/outside/media/t4/foreign.css','x');
    $rootLinkData=$data;$rootLinkData['presentation-lock']['fileRoots']=['assets','media/t4'];
    foreach($rootLinkData as $name=>$body)file_put_contents($rootLinkDir.'/'.$name.'.json',json_encode($body));
    symlink($rootLinkDir.'/outside/assets',$rootLinkDir.'/web/assets');symlink($rootLinkDir.'/outside/media',$rootLinkDir.'/web/media');
    $c=new QuickstartContract(clone $cw,new TestContractStore(),$rootLinkDir.'/web',$rootLinkDir);$c->inspect();
    $rootLinkWarnings=$c->driftWarnings();
    checkTrue('a locked root that is a link is reported',in_array('Presentation folder is a link: assets',$rootLinkWarnings,true));
    checkTrue('a locked root under a linked folder is reported',in_array('Presentation folder is a link: media (holds media/t4)',$rootLinkWarnings,true));
    checkTrue('a linked locked root is not walked',!str_contains(implode(' ',$rootLinkWarnings),'foreign'));
} finally {
    foreach(['web/assets','web/media','outside/assets/demo.css','outside/assets/foreign.php','outside/media/t4/foreign.css'] as $path)if(is_link($rootLinkDir.'/'.$path)||is_file($rootLinkDir.'/'.$path))unlink($rootLinkDir.'/'.$path);
    foreach(array_keys($data) as $name)if(is_file($rootLinkDir.'/'.$name.'.json'))unlink($rootLinkDir.'/'.$name.'.json');
    foreach(['outside/media/t4','outside/media','outside/assets','outside','web',''] as $path)if(is_dir($rootLinkDir.'/'.$path))rmdir($rootLinkDir.'/'.$path);
}
unlink($contractDir.'/media/t4/css/36-sub.css');rmdir($contractDir.'/media/t4/css');
foreach(['module'=>'mod_ja_acm','position'=>'section-1','published'=>'0','publish_up'=>'2099-01-01 00:00:00','publish_down'=>'2000-01-01 00:00:00','ordering'=>'2','access'=>'2','showtitle'=>'1','language'=>'vi-VN','client_id'=>'1','params'=>'{"moduleclass_sfx":"replacement"}'] as $field=>$value) {
    $before=$cw->store['module'][110][$field];$cw->store['module'][110][$field]=$value;
    contractDrifts('contract reports module '.$field.' drift',$contract,fn()=>$contract->inspect());
    $cw->store['module'][110][$field]=$before;
}
$cw->store['moduleAssignment'][110]['menuids']='[0]';
contractDrifts('contract reports changing exclusions to all pages',$contract,fn()=>$contract->inspect());
$cw->store['moduleAssignment'][110]['menuids']='[-121]';
$cw->store['menuItem'][120]['access']='2';
contractDrifts('contract reports menu access drift',$contract,fn()=>$contract->inspect());
$cw->store['menuItem'][120]['access']='1';
$cs->acl['viewlevels'][0]['rules']=[2];
contractDrifts('same access ID cannot silently change its audience',$contract,fn()=>$contract->inspect());
$cs->acl['viewlevels'][0]['rules']=[1];
$cw->store['module'][111]=['id'=>'111']+$module;
contractDrifts('contract reports an extra module',$contract,fn()=>$contract->inspect());
unset($cw->store['module'][111]);
file_put_contents($contractDir.'/assets/demo.css','.hero { display: none }');
contractDrifts('contract reports CSS drift',$contract,fn()=>$contract->inspect());
file_put_contents($contractDir.'/assets/demo.css','.hero { color: red }');
foreach(['hero.0'=>'','hero.99'=>'unknown'] as $key=>$value)contractRejects('contract rejects empty or unknown slot '.$key,fn()=>$contract->plan(['expected_revision'=>$state['revision'],'changes'=>[$key=>$value]]));
$urlSlot=current(array_filter($slots,fn($s)=>$s['type']==='url'))['key'];
foreach(['javascript:alert(1)','//other.example/','/\\other.example/'] as $url)contractRejects('contract rejects unsafe CTA '.$url,fn()=>$contract->plan(['expected_revision'=>$state['revision'],'changes'=>[$urlSlot=>$url]]));
contractRejects('contract rejects stale revision',fn()=>$contract->plan(['expected_revision'=>'old','changes'=>['hero.0'=>'New title']]));
contractRejects('contract rejects injected Joomla module directives',fn()=>$contract->plan(['expected_revision'=>$state['revision'],'changes'=>['hero.0'=>'{loadposition new-position}']]));

$contractLog=new FakeApplyLog();$transactional=new ContractTestWriter($contractLog,$cs);$transactional->store=$cw->store;
$receiverContract=new QuickstartContract($transactional,$cs,$contractDir,$contractDir);
$receiver=new Engine($WTOKEN,[],null,null,null,null,$transactional,null,$contractLog,null,null,null,$receiverContract);
$cs->binding=null;
$bind=['token'=>$WTOKEN,'action'=>'content.contract','params'=>['operation'=>'bind']];
check('bootstrap binds before any customer write',$receiver->handle($bind)['bound'],true);
check('bootstrap binding is idempotent',$receiver->handle($bind)['bound'],true);
// Tracy ADR 0022: a bound site takes the writes any site takes; the ordinary revert takes them back.
check('a bound site takes a generic write',$receiver->handle(['token'=>$WTOKEN,'action'=>'content.update','params'=>['kind'=>'module','id'=>110,'apply_id'=>'generic-0','fields'=>['published'=>'0']]])['ok'],true);
check('and the ordinary revert takes it back',$receiver->handle(['token'=>$WTOKEN,'action'=>'apply.revert','params'=>['apply_id'=>'generic-0']])['ok'],true);
$first=['token'=>$WTOKEN,'action'=>'content.contract','params'=>['operation'=>'apply','apply_id'=>'contract-first','request_id'=>'first','expected_revision'=>$receiverContract->inspect()['revision'],'changes'=>['hero.0'=>'First customer title']]];
// Each refusal names the one id that is wrong: a single sentence for both sent an agent that had
// both round fourteen retries with an apply_id that never carried the prefix.
$untouched=$receiverContract->inspect()['revision'];
$unprefixed=$first;$unprefixed['params']['apply_id']='h1-home-toyota';$refused=$receiver->handle($unprefixed);
check('an apply_id without the contract- prefix is refused by name',[$refused['ok'],$refused['error'],$refused['message']],[false,'contract_failed','apply_id must start with "contract-", got "h1-home-toyota"']);
$unnamed=$first;unset($unnamed['params']['apply_id']);
check('a missing apply_id is refused by name',$receiver->handle($unnamed)['message'],'apply_id required; it must start with "contract-"');
$unrequested=$first;unset($unrequested['params']['request_id']);
check('a missing request_id is refused on its own',$receiver->handle($unrequested)['message'],'request_id required: any string, the same on a retry and new for a different change');
check('a refused apply writes nothing',$receiverContract->inspect()['revision'],$untouched);
$firstResult=$receiver->handle($first);
check('contract receiver commits content in place',$firstResult['ok'],true);
check('inspect exposes current content separately from immutable demo samples',$receiverContract->inspect()['slots'][0]['current'],'First customer title');
check('contract receiver replays the identical committed receipt',$receiver->handle($first),$firstResult);
check('a bound receiver unpublishes a module through the ordinary door',$receiver->handle(['token'=>$WTOKEN,'action'=>'content.update','params'=>['kind'=>'module','id'=>110,'apply_id'=>'generic','fields'=>['published'=>'0']]])['ok'],true);
check('and puts it back through the ordinary revert',$receiver->handle(['token'=>$WTOKEN,'action'=>'apply.revert','params'=>['apply_id'=>'generic']])['ok'],true);
$second=$first;$second['params']['apply_id']='contract-second';$second['params']['request_id']='second';
$second['params']['expected_revision']=$receiverContract->inspect()['revision'];$second['params']['changes']['hero.0']='Second customer title';
check('contract receiver commits a later revision',$receiver->handle($second)['ok'],true);
$revert=['token'=>$WTOKEN,'action'=>'apply.revert','params'=>['apply_id'=>'contract-first']];
check('contract undo cannot overwrite a later revision',$receiver->handle($revert)['ok'],false);
$revert['params']['apply_id']='contract-second';check('latest contract revision can be undone',$receiver->handle($revert)['ok'],true);
$revert['params']['apply_id']='contract-first';check('earlier revision can then be undone',$receiver->handle($revert)['ok'],true);
check('contract undo restores original HTML byte for byte',$transactional->store['module'][110]['content'],$module['content']);

$beforeFailure=[$transactional->store,$contractLog->log,$cs->binding];
$transactional->drift=true;
check('post-write presentation drift fails the whole apply',$receiver->handle($first)['ok'],false);
check('failed apply rolls back rows, receipt and binding',[$transactional->store,$contractLog->log,$cs->binding],$beforeFailure);
// ── A base archive that serves more than one design ──────────────────────────────────────────
// Apple and Airbnb are the same quickstart with a different template activated on top, so the
// receiver now carries several profiles and picks one from the component's own params. Two things
// have to hold: a site bound to one profile must refuse to be read under another, and a receiver
// that does not carry the profile a site names must refuse everything rather than read as a site
// with no contract at all — the one state where every structural write is allowed.
$secondDir=sys_get_temp_dir().'/cowork-contract-'.bin2hex(random_bytes(6));
mkdir($secondDir);mkdir($secondDir.'/assets');
copy($contractDir.'/assets/demo.css',$secondDir.'/assets/demo.css');
$secondData=$data;$secondData['manifest']=['id'=>'test-airbnb/v1'];
foreach($secondData as $name=>$body)file_put_contents($secondDir.'/'.$name.'.json',json_encode($body));
$secondContract=new QuickstartContract($transactional,$cs,$contractDir,$secondDir);
$cs->binding=null;
check('a second design profile reads the same site on its own terms',$secondContract->inspect()['contract'],'test-airbnb/v1');
$cs->binding=$receiverContract->inspect()['snapshot'];
check('the first design profile still reads the site it is bound to',$receiverContract->inspect()['contract'],'test/v1');
contractRejects('a site bound to one design refuses to be read under another',fn()=>$secondContract->inspect());

$missingDir=sys_get_temp_dir().'/cowork-contract-'.bin2hex(random_bytes(6));
$absent=new QuickstartContract($transactional,$cs,$contractDir,$missingDir);
contractRejects('a receiver without the named profile refuses to answer bound()',fn()=>$absent->bound());
contractRejects('a receiver without the named profile refuses to inspect',fn()=>$absent->inspect());
contractRejects('a receiver without the named profile refuses to bind',fn()=>$absent->bind([]));
$absentEngine=new Engine($WTOKEN,[],null,null,null,null,$transactional,null,$contractLog,null,null,null,$absent);
check('a receiver without the named profile refuses generic writes instead of allowing them',
    $absentEngine->handle(['token'=>$WTOKEN,'action'=>'content.update','params'=>['kind'=>'module','id'=>110,'fields'=>['published'=>'0']]])['error'],'contract_unavailable');
check('a receiver without the named profile refuses the contract door too',
    $absentEngine->handle(['token'=>$WTOKEN,'action'=>'content.contract','params'=>['operation'=>'bind']])['error'],'contract_unavailable');

// A site under construction: provisioned from the Base archive with `tracy_build_baseline` and no
// `contract` yet, because the template is still to be built. Measured 14/09 on a fresh Base site:
// the receiver fell back to the Apple profile, hashed Base's files against Apple's lock and refused
// the first inspection with "Presentation asset changed: templates/tracy/acm/accordion/css/style.css"
// — a file nobody had touched. Under construction there is no lock to verify and no default to guess.
$construction=(new Engine($WTOKEN,[],null,null,null,null,$transactional,null,$contractLog))->underConstruction('tracy-base/j6/1.0.0');
$unbound=$construction->handle(['token'=>$WTOKEN,'action'=>'content.contract','params'=>['operation'=>'inspect']]);
check('a site under construction answers inspect as unbound, naming its baseline',
    [$unbound['ok']??null,$unbound['bound']??null,$unbound['contract']??null,$unbound['baseline']??null,$unbound['construction']??null],
    [true,false,'','tracy-base/j6/1.0.0',true]);
check('a site under construction cannot be bound before it names a profile',
    $construction->handle(['token'=>$WTOKEN,'action'=>'content.contract','params'=>['operation'=>'bind']])['error'],'contract_unbound');
check('nor written through the contract door',
    $construction->handle(['token'=>$WTOKEN,'action'=>'content.contract','params'=>['operation'=>'apply','apply_id'=>'contract-x','request_id'=>'x','changes'=>[]]])['error'],'contract_unbound');
checkTrue('structural writes stay open while the template is being built',
    ($construction->handle(['token'=>$WTOKEN,'action'=>'content.update','params'=>['kind'=>'module','id'=>110,'fields'=>['published'=>'0']]])['error']??'')!=='content_only');
foreach(array_keys($secondData) as $name)unlink($secondDir.'/'.$name.'.json');
unlink($secondDir.'/assets/demo.css');rmdir($secondDir.'/assets');rmdir($secondDir);

/* ------------------------------------------------ a switcher the archive already ships, reused */

// 🔒 THE BUSINESS PROFILE REUSES THE ARCHIVE'S OWN SWITCHER (module-425, note `tb:pilot`), so the
// topbar never carries two. That module is a governed base entity with its own title, assignment and
// place in the inventory count — and inspect held it to the NEW-switcher shape instead: title
// "Languages", assignment "all pages", one module more than the lock. Measured 23/09/2026 on
// `j-ee6vsk`: the vi-VN language job died at its last step with "Presentation drift:
// multilingual::switcher — title [want "Languages" got "[Tracy Business] Topbar - Language switcher"]".
// A reused switcher is held to its own lock, which the base entity check has already enforced.
$reuseDir=sys_get_temp_dir().'/cowork-reuse-'.bin2hex(random_bytes(6));
mkdir($reuseDir);mkdir($reuseDir.'/assets');
foreach(['manifest','content-map','presentation-lock'] as $name)copy($contractDir.'/'.$name.'.json',$reuseDir.'/'.$name.'.json');
copy($contractDir.'/assets/demo.css',$reuseDir.'/assets/demo.css');
$reuseLock=json_decode(file_get_contents($reuseDir.'/presentation-lock.json'),true);
$reuseLock['fileRoots']=['assets'];$reuseLock['files']=['assets/demo.css'=>hash_file('sha256',$reuseDir.'/assets/demo.css')];
file_put_contents($reuseDir.'/presentation-lock.json',json_encode($reuseLock));
$reuseBase=hash('sha256',implode('',array_map(fn($n)=>$n.':'.hash_file('sha256',$reuseDir.'/'.$n)."\n",['manifest.json','content-map.json','presentation-lock.json'])));
file_put_contents($reuseDir.'/multilingual-map.json',json_encode([
    'schemaVersion'=>'tracy-quickstart-multilingual/v1','extensionVersion'=>'1.1.0','contract'=>'test/v1','baseHash'=>$reuseBase,
    'sourceLanguage'=>'en-GB',
    'derive'=>['module'=>['create'=>'row','carry'=>['position'],'translate'=>['content'],'note'=>'tracy-ml:{sourceKey}:{locale}','assignments'=>'map']],
    'sourceDelta'=>['language'=>['from'=>'*','to'=>'en-GB','entities'=>[]]],
    'policies'=>['hero'=>['kind'=>'module','policy'=>'shared','reason'=>'the switcher itself'],
        'menu-20'=>['kind'=>'menuItem','policy'=>'shared','reason'=>'test'],'menu-21'=>['kind'=>'menuItem','policy'=>'shared','reason'=>'test']],
    'switcher'=>['module'=>'mod_custom','position'=>'masthead','anchorEntity'=>'hero','language'=>'*','showtitle'=>'0','access'=>'1',
        'published'=>'1','ordering'=>'1','assignment'=>'all','note'=>'','params'=>['moduleclass_sfx'=>'original']],
    'languageFilter'=>['element'=>'languagefilter','folder'=>'system','enabled'=>1,'params'=>['item_associations'=>1]],
    'unsupported'=>[],'aliasPolicy'=>'shared-with-source',
]));
$rw=new FakeSiteWriter();$rw->store=$cw->store;
$rs=new TestContractStore();
$reuse=new QuickstartContract($rw,$rs,$reuseDir,$reuseDir);
$reuse->bind($reuse->inspect()['snapshot']);
// The binding a completed language job leaves when it found the archive's switcher: the switcher IS
// module 110, the governed `hero`.
$rs->binding['multilingual']=['profileHash'=>$reuse->profile()->hash(),'profileVersion'=>'1.1.0','source'=>'en-GB','languages'=>[],'switcher'=>110];
$reuseState=null;
try { $reuseState=$reuse->inspect(); } catch (RuntimeException $error) { echo '  (', $error->getMessage(), ")\n"; }
check('a reused archive switcher is held to its own lock, not the new-switcher shape',$reuseState['switcher']??null,110);
// Still strict: the reused module drifting from its lock is refused, as any governed module is.
$rw->store['module'][110]['title']='Somebody renamed it';
contractDrifts('a reused switcher that drifts is still reported',$reuse,fn()=>$reuse->inspect());

foreach(['manifest','content-map','presentation-lock','multilingual-map'] as $name)unlink($reuseDir.'/'.$name.'.json');
unlink($reuseDir.'/assets/demo.css');rmdir($reuseDir.'/assets');rmdir($reuseDir);

unlink($contractDir.'/'.$newCache);rmdir($contractDir.'/media/t4/optimize/css');rmdir($contractDir.'/media/t4/optimize');rmdir($contractDir.'/media/t4');rmdir($contractDir.'/media');
foreach(array_keys($data) as $name)unlink($contractDir.'/'.$name.'.json');
unlink($contractDir.'/assets/demo.css');rmdir($contractDir.'/assets');rmdir($contractDir);

/* ------------------------------------------------- every profile the package carries loads */
// The receiver reads a profile from disk on every request, and the constructor above throws on a
// multilingual map whose shape it does not know (`policies`, `sourceDelta`), on one pinned to other
// bytes, or on a missing file. A profile committed in that state is a site that refuses every
// action after the upgrade that was meant to seal it — the one failure the customer meets first.
// So every directory under lib/contracts/ is loaded here, exactly the way the engine loads it.
$bundledRoot=realpath(__DIR__.'/../lib/contracts');
// Loaded the way the door loads them: behind the memory reserve. Without it this file dies at
// PHP's 128M default on the Business lock — the same fatal the receiver had before the reserve.
Door::reserveMemory();
$entityRules=[];
$bundled=[];
$trimmed=[];
foreach(glob($bundledRoot.'/*/j6/*',GLOB_ONLYDIR) as $dir){
    $id=basename(dirname($dir,2)).'/j6/'.basename($dir);
    $bundled[]=$id;
    foreach(['manifest','content-map','presentation-lock'] as $name)
        checkTrue("$id carries $name.json",is_file("$dir/$name.json"));
    $manifest=json_decode(file_get_contents("$dir/manifest.json"),true,512,JSON_THROW_ON_ERROR);
    check("$id names itself in its manifest",$manifest['id']??null,$id);
    check("$id names the version it carries",$manifest['quickstart']['version']??null,basename($dir));
    $lock=json_decode(file_get_contents("$dir/presentation-lock.json"),true,512,JSON_THROW_ON_ERROR);
    checkTrue("$id lock carries an access snapshot",isset($lock['access']['entityRules'])&&is_array($lock['access']['entityRules']));
    $entityRules[$id]=count($lock['access']['entityRules']??[]);
    unset($lock);
    if(is_file("$dir/demo-trim-map.json")) {
        $raw=file_get_contents("$dir/demo-trim-map.json");
        $loaded=null;
        try{
            new DemoTrimProfile(json_decode($raw,true,512,JSON_THROW_ON_ERROR),
                json_decode(file_get_contents("$dir/content-map.json"),true,512,JSON_THROW_ON_ERROR),
                json_decode(file_get_contents("$dir/presentation-lock.json"),true,512,JSON_THROW_ON_ERROR),$dir,$raw);
            $loaded=true;
        }catch(Throwable $e){$loaded=$e->getMessage();}
        check("$id demo-trim map is one this receiver can load",$loaded,true);
        $trimmed[]=$id;
    }
    if(is_file("$dir/showcase-nav-map.json")) {
        $raw=file_get_contents("$dir/showcase-nav-map.json");
        $loaded=null;
        try{
            new ShowcaseNavProfile(json_decode($raw,true,512,JSON_THROW_ON_ERROR),
                json_decode(file_get_contents("$dir/content-map.json"),true,512,JSON_THROW_ON_ERROR),
                json_decode(file_get_contents("$dir/presentation-lock.json"),true,512,JSON_THROW_ON_ERROR),$dir,$raw,$id);
            $loaded=true;
        }catch(Throwable $e){$loaded=$e->getMessage();}
        check("$id showcase-navigation map is one this receiver can load",$loaded,true);
    }
    if(!is_file("$dir/multilingual-map.json"))continue;
    $raw=file_get_contents("$dir/multilingual-map.json");
    $loaded=null;
    try{
        new MultilingualProfile(json_decode($raw,true,512,JSON_THROW_ON_ERROR),
            json_decode(file_get_contents("$dir/content-map.json"),true,512,JSON_THROW_ON_ERROR),
            json_decode(file_get_contents("$dir/presentation-lock.json"),true,512,JSON_THROW_ON_ERROR),$dir,$raw);
        $loaded=true;
    }catch(Throwable $e){$loaded=$e->getMessage();}
    check("$id multilingual map is one this receiver can load",$loaded,true);
}
foreach(['tracy-apple/j6/1.2.0','tracy-airbnb/j6/1.1.0','ja-voyara/j6/1.0.2','ja-voyara/j6/1.0.3','tracy-base/j6/1.0.1','ja-kinetic/j6/1.0.0','tracy-business/j6/1.0.0','tracy-business/j6/1.1.0','ja-spa/j6/1.0.1','ja-trip/j6/1.0.2','ja-space/j6/1.0.2','ja-essence/j6/1.0.1','ja-vital/j6/1.0.2','ja-vogue/j6/1.0.2','ja-phio/j6/1.0.3','ja-trip/j6/1.0.3','ja-kinetic/j6/1.0.3','ja-voyara/j6/1.0.4','ja-morgan/j6/1.0.2','tracy-base/j6/1.0.2','ja-social-ii/j6/1.0.1','ja-donate/j6/1.0.1','ja-rent/j6/1.0.1','ja-essence/j6/1.0.2','ja-vogue/j6/1.0.3','tracy-business/j6/1.2.1','ja-essence/j6/1.0.3','ja-voyara/j6/1.0.5','ja-kinetic/j6/1.0.4','ja-impact/j6/1.0.1','ja-donate/j6/1.0.2','ja-morgan/j6/1.0.3','ja-phio/j6/1.0.4','ja-podcast/j6/1.0.2','ja-trip/j6/1.0.4','ja-mood/j6/1.0.1','ja-nova/j6/1.0.5','ja-space/j6/1.0.3','ja-vega/j6/1.0.4','ja-smallbiz/j6/1.0.1','ja-social-ii/j6/1.0.2','ja-spa/j6/1.0.2','ja-morgan/j6/1.0.4','ja-vogue/j6/1.0.4','ja-essence/j6/1.0.4','ja-essence/j6/1.0.5','ja-voyara/j6/1.0.6','ja-spa/j6/1.0.3','ja-spa/j6/1.0.4','ja-restaurant/j6/1.0.3','ja-directory/j6/1.0.1','ja-prodex/j6/1.0.1','ja-rent/j6/1.0.2','ja-resume/j6/1.0.1','ja-sensei/j6/1.0.1','ja-seven/j6/1.0.1','ja-tour/j6/1.0.1','ja-conf/j6/1.0.2','ja-vital/j6/1.0.3','ja-symphony/j6/1.0.1','ja-resort/j6/1.0.2','ja-essence/j6/1.0.6','ja-restaurant/j6/1.0.4','ja-resume/j6/1.0.2','ja-sensei/j6/1.0.2','ja-directory/j6/1.0.2','ja-donate/j6/1.0.3','ja-essence/j6/1.0.7','ja-impact/j6/1.0.2','ja-kinetic/j6/1.0.5','ja-mood/j6/1.0.2','ja-morgan/j6/1.0.5','ja-nova/j6/1.0.6','ja-phio/j6/1.0.5','ja-podcast/j6/1.0.3','ja-prodex/j6/1.0.2','ja-rent/j6/1.0.3','ja-resort/j6/1.0.3','ja-resume/j6/1.0.3','ja-sensei/j6/1.0.3','ja-seven/j6/1.0.2','ja-smallbiz/j6/1.0.2','ja-social-ii/j6/1.0.3','ja-spa/j6/1.0.5','ja-space/j6/1.0.4','ja-symphony/j6/1.0.2','ja-tour/j6/1.0.2','ja-trip/j6/1.0.5','ja-vega/j6/1.0.5','ja-vital/j6/1.0.4','ja-vogue/j6/1.0.5','ja-voyara/j6/1.0.7','tracy-base/j6/1.0.3','tracy-business/j6/1.2.2','ja-resort/j6/1.0.4','ja-resort/j6/1.0.5','ja-resort/j6/1.0.6','ja-donate/j6/1.0.4','ja-kinetic/j6/1.0.6','ja-podcast/j6/1.0.4','ja-donate/j6/1.0.5','ja-voyara/j6/1.0.8','tracy-business/j6/1.2.3','tracy-business/j6/1.2.4','tracy-base/j6/1.0.4','ja-kinetic/j6/1.0.8','ja-conf/j6/1.0.3','ja-directory/j6/1.0.3','ja-donate/j6/1.0.6','ja-essence/j6/1.0.8','ja-impact/j6/1.0.3','ja-kinetic/j6/1.0.7','ja-mood/j6/1.0.3','ja-morgan/j6/1.0.6','ja-nova/j6/1.0.7','ja-phio/j6/1.0.6','ja-podcast/j6/1.0.5','ja-prodex/j6/1.0.3','ja-rent/j6/1.0.4','ja-restaurant/j6/1.0.5','ja-resume/j6/1.0.4','ja-sensei/j6/1.0.4','ja-seven/j6/1.0.3','ja-smallbiz/j6/1.0.3','ja-social-ii/j6/1.0.4','ja-spa/j6/1.0.6','ja-space/j6/1.0.5','ja-symphony/j6/1.0.3','ja-tour/j6/1.0.3','ja-trip/j6/1.0.6','ja-vega/j6/1.0.6','ja-vital/j6/1.0.5','ja-vogue/j6/1.0.6','ja-voyara/j6/1.0.9','tracy-base/j6/1.0.5','tracy-business/j6/1.2.5','ja-essence/j6/1.0.9','ja-impact/j6/1.0.4','ja-morgan/j6/1.0.7','ja-nova/j6/1.0.8','ja-phio/j6/1.0.7','ja-podcast/j6/1.0.6','ja-resume/j6/1.0.5','ja-sensei/j6/1.0.5','ja-seven/j6/1.0.4','ja-spa/j6/1.0.7','ja-tour/j6/1.0.4','ja-trip/j6/1.0.7','ja-vega/j6/1.0.7','ja-vital/j6/1.0.6','ja-vogue/j6/1.0.7','ja-voyara/j6/1.0.10','tracy-apple/j6/1.3.1','ja-morgan/j6/1.0.8','ja-resume/j6/1.0.6','ja-resort/j6/1.0.7','ja-kinetic/j6/1.0.9','tracy-business/j6/1.2.6','ja-prodex/j6/1.0.4','ja-smallbiz/j6/1.0.4','ja-rent/j6/1.0.5','ja-mood/j6/1.0.4','ja-symphony/j6/1.0.4','ja-donate/j6/1.0.7','ja-space/j6/1.0.6','ja-social-ii/j6/1.0.5','ja-restaurant/j6/1.0.6','ja-fresco/j6/1.0.2','ja-diner/j6/1.0.2','ja-fresco/j6/1.0.3','ja-diner/j6/1.0.3','ja-fresco/j6/1.0.4','ja-diner/j6/1.0.4','ja-symphony/j6/1.0.5','ja-kinetic/j6/1.0.10','ja-space/j6/1.0.7','ja-sensei/j6/1.0.6','ja-impact/j6/1.0.5','ja-trip/j6/1.0.8','ja-morgan/j6/1.0.9','ja-podcast/j6/1.0.7','ja-resort/j6/1.0.8','ja-tour/j6/1.0.5','ja-vital/j6/1.0.7','ja-spa/j6/1.0.8','ja-resume/j6/1.0.7','ja-seven/j6/1.0.5','ja-prodex/j6/1.0.5','ja-phio/j6/1.0.8','ja-voyara/j6/1.0.11','ja-vega/j6/1.0.8','ja-essence/j6/1.0.10','ja-nova/j6/1.0.9','ja-vogue/j6/1.0.8','ja-boxing/j6/1.0.5','ja-boxing/j6/1.0.6','ja-boxing/j6/1.0.7','ja-boxing/j6/1.0.8'] as $id)
    checkTrue("the package carries $id",in_array($id,$bundled,true));
// tch generates a showcase-navigation map for the newest profile of every product line
// (scripts/build-joomla-showcase-nav.mjs). A profile copied here without it ships a receiver that answers
// "carries no showcase-navigation map", and every new site keeps the template's demo kit in its menus:
// ja-boxing 1.0.3 and 1.0.4 went out so in 0.24.60-0.24.63 (dev site accept-boxing-104, 25/10/2026).
$newest=[];
foreach($bundled as $id){
    [$line,,$version]=explode('/',$id);
    if(!isset($newest[$line])||version_compare($version,$newest[$line],'>'))$newest[$line]=$version;
}
foreach($newest as $line=>$version)
    checkTrue("$line/j6/$version, the newest $line profile, carries showcase-nav-map.json",is_file("$bundledRoot/$line/j6/$version/showcase-nav-map.json"));
// ja-kinetic's demo is 240 blog posts about a company that does not exist; without this file every
// customer site built on it shows them, and nothing anywhere reports that.
checkTrue('the package carries the ja-kinetic demo-trim map',in_array('ja-kinetic/j6/1.0.0',$trimmed,true));
checkTrue('the package carries the ja-kinetic 1.0.4 demo-trim map',in_array('ja-kinetic/j6/1.0.4',$trimmed,true));
// 1.0.8's map keeps pages/kineticql and the article it opens (a listed page of the demo, TCQR PAGE-2).
checkTrue('the package carries the ja-kinetic 1.0.8 demo-trim map',in_array('ja-kinetic/j6/1.0.8',$trimmed,true));
// 1.1.0 is the 43-language archive, and its lock was captured from THAT archive: an ACL chain for
// every one of its 6,144 entities. The 1.0.0 lock's 322 refused bind on every Business site with
// "Access-level or ACL definition changed: entityRules(categories.1000 …)" (measured 2026-09-21).
check('tracy-business/j6/1.1.0 lock carries ACL rules for the whole 1.1.0 archive',$entityRules['tracy-business/j6/1.1.0']??null,6144);

/* --------------------------- the Business profile knows the archive is ALREADY two editions */
// 🔒 THIS IS THE ONE PROFILE WHOSE SOURCE IS NOT THE WHOLE SITE. Apple and Airbnb ship every row at
// `language='*'`; Business ships an en-GB edition AND a ru-RU edition its customer authored — 180
// Russian rows carrying their own legal identifiers (INN, OGRN, SRO), Russian client names and a
// Moscow address. Nothing here can derive or check those words, so a profile that treated them as
// translatable source would build a second Vietnamese copy of every block and put two editions of
// the same module on one page. It fails no gate and throws nothing: it is only visible on the
// customer's site. These four checks are what keeps a regenerated map from losing that guard.
$bizDir=$bundledRoot.'/tracy-business/j6/1.1.0';
$bizRaw=file_get_contents($bizDir.'/multilingual-map.json');
$bizMap=json_decode(file_get_contents($bizDir.'/content-map.json'),true,512,JSON_THROW_ON_ERROR);
$bizLock=json_decode(file_get_contents($bizDir.'/presentation-lock.json'),true,512,JSON_THROW_ON_ERROR);
$biz=new MultilingualProfile(json_decode($bizRaw,true,512,JSON_THROW_ON_ERROR),$bizMap,$bizLock,$bizDir,$bizRaw);
check('the Business profile is the 1.1.0 extension shape',$biz->version(),'1.1.0');
$bizCoverage=$biz->coverage();
check('the Business profile classifies every entity of the contract',
    count($bizCoverage['translate'])+count($bizCoverage['shared']),count($bizMap['entities']));
// Every row the archive authored in another language, and every row it left at `*`, stays put: the
// first because its words are facts the source does not hold, the second because Joomla filters
// `language IN (tag, '*')` and pulling it to en-GB would take it off the Russian pages it serves.
$copiedNonSource=[];
foreach($bizMap['entities'] as $entity){
    $tag=(string)($bizLock['entities'][$entity['key']]['language']??'');
    if($tag!==''&&$tag!=='en-GB'&&$biz->isTranslated($entity['key']))$copiedNonSource[]=$entity['key'].'@'.$tag;
}
check('the Business profile copies no row outside the en-GB edition',$copiedNonSource,[]);
// The archive already publishes a `mod_languages` module, with the template's own layout. The
// receiver finds an existing switcher by the profile's `note` and only creates one when it finds
// none, so this note is what stands between one switcher in the topbar and two.
check('the Business profile reuses the switcher the archive ships',
    [$biz->switcherPresentation()['note'],$biz->switcherPresentation()['position'],$biz->switcherAnchor()],
    ['tb:pilot','language-switcher','module-425']);
check('and that switcher is a row the profile never copies',$biz->isTranslated('module-425'),false);
unset($biz,$bizMap,$bizLock,$bizRaw);


// 🔒 TAKING BACK AN UNFINISHED LANGUAGE NEVER DELETES A GOVERNED MODULE. `orphansOf` lists the rows a
// job left behind for deletion, and it listed the switcher unconditionally — measured 23/09/2026 on
// `j-ee6vsk`: abandoning a vi-VN job deleted module-425, the Business archive's own switcher, and the
// next inspect died "Bound entity disappeared: module-425". Only a switcher the job CREATED is its.
$orphans=new ReflectionMethod(Engine::class,'orphansOf');
$orphans->setAccessible(true);
$bare=(new ReflectionClass(Engine::class))->newInstanceWithoutConstructor();
$heldState=['keys'=>['hero'=>['kind'=>'module'],'vi-VN::hero'=>['kind'=>'module','locale'=>'vi-VN','base'=>'hero']],
    'ids'=>['hero'=>425,'vi-VN::hero'=>4139],'switcher'=>425];
check('a reused archive switcher is never an orphan',$orphans->invoke($bare,$heldState,'vi-VN'),['module'=>[4139]]);
$heldState['switcher']=5000;
check('a switcher the job created still goes with it',$orphans->invoke($bare,$heldState,'vi-VN'),['module'=>[5000,4139]]);
// Ids are per table: an ARTICLE 5000 being governed says nothing about the module 5000 the job made.
$heldState['keys']['post']=['kind'=>'article'];$heldState['ids']['post']=5000;
check('a governed article does not shield a created switcher of the same id',$orphans->invoke($bare,$heldState,'vi-VN'),['module'=>[5000,4139]]);

// A captured picture slot keeps its shape: its sample is stored the way Joomla writes media values, with the
// `#joomlaImage://` suffix, and the shape check must read the file under it (TCH #1298, 14/10/2026: every shape
// was taken, since the suffixed path named no file).
$shapeRoot=sys_get_temp_dir().'/cowork-shape-'.bin2hex(random_bytes(6));
mkdir($shapeRoot.'/images/demo',0777,true);
$png=function(int $w,int $h):string{
    $ihdr=pack('NNCCCCC',$w,$h,8,2,0,0,0);
    return "\x89PNG\r\n\x1a\n".pack('N',13).'IHDR'.$ihdr.pack('N',crc32('IHDR'.$ihdr)).pack('N',0).'IEND'.pack('N',crc32('IEND'));
};
file_put_contents($shapeRoot.'/images/demo/wide.png',$png(1200,800));
file_put_contents($shapeRoot.'/images/demo/same.png',$png(600,400));
file_put_contents($shapeRoot.'/images/demo/tall.png',$png(400,1200));
$shape=(new ReflectionClass(QuickstartContract::class))->newInstanceWithoutConstructor();
$shapeRootProp=new ReflectionProperty(QuickstartContract::class,'root');$shapeRootProp->setAccessible(true);$shapeRootProp->setValue($shape,$shapeRoot);
$shapeCheck=new ReflectionMethod(QuickstartContract::class,'checkValue');$shapeCheck->setAccessible(true);
$shapeSlot=['key'=>'gallery.2','type'=>'image','maxCharacters'=>255,'sample'=>'images/demo/wide.png#joomlaImage://local-images/demo/wide.png?width=1200&height=800'];
$shapeCode=function(string $value) use ($shape,$shapeCheck,$shapeSlot):?string{
    try{$shapeCheck->invoke($shape,$shapeSlot,$value,[]);return null;}catch(ContractProblem $e){return $e->reason;}catch(Throwable $e){return get_class($e).': '.$e->getMessage();}
};
check('a picture of its slot\'s shape is taken, the slot stored with Joomla\'s media suffix',$shapeCode('images/demo/same.png'),null);
check('a picture of another shape is refused, the slot stored with Joomla\'s media suffix',$shapeCode('images/demo/tall.png'),'SLOT_IMAGE_INVALID');
