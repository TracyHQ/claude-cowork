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
    public function transaction(callable $work):array {
        $before=[$this->store,$this->log->log,$this->binding->binding,$this->binding->job];
        try{return $work();}catch(Throwable $error){
            [$this->store,$this->log->log,$this->binding->binding,$this->binding->job]=$before;throw $error;
        }
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
$plan=$contract->plan(['expected_revision'=>$state['revision'],'changes'=>['hero.0'=>'Customer & partners']]);
check('contract updates the original module ID',$plan['operations'][0]['id'],110);
checkTrue('HTML content stays escaped',str_contains($plan['operations'][0]['fields']['content'],'Customer &amp; partners'));
function contractRejects(string $label, callable $work): void {
    try { $work();check($label,'accepted','rejected'); }
    catch (RuntimeException $error) { check($label,'rejected','rejected'); }
}
check('T4 cache regeneration keeps the source contract valid',$contract->inspect()['contract'],'test/v1');
file_put_contents($contractDir.'/media/t4/optimize/css/injected.php','unexpected executable');
contractRejects('cache exception never allows executable files',fn()=>$contract->inspect());
unlink($contractDir.'/media/t4/optimize/css/injected.php');
// T4 compiles `media/t4/css/<styleId>-sub.css` the first time a style renders a "subpage" (a page
// that is not its menu item's own target) — measured 23/09/2026 on j-cr4l1l (ja-kinetic): one
// visit wrote 36-sub.css, and inspect refused the site with "Unexpected presentation file".
mkdir($contractDir.'/media/t4/css',0777,true);
file_put_contents($contractDir.'/media/t4/css/36-sub.css','compiled from locked sources');
check('a T4 subpage stylesheet is cache, not a presentation change',$contract->inspect()['contract'],'test/v1');
foreach(['36-sub.css.php','evil.css','36-sub.js'] as $name) {
    file_put_contents($contractDir.'/media/t4/css/'.$name,'not a compiled stylesheet');
    contractRejects('the subpage exception does not admit '.$name,fn()=>$contract->inspect());
    unlink($contractDir.'/media/t4/css/'.$name);
}
unlink($contractDir.'/media/t4/css/36-sub.css');rmdir($contractDir.'/media/t4/css');
foreach(['module'=>'mod_ja_acm','position'=>'section-1','published'=>'0','publish_up'=>'2099-01-01 00:00:00','publish_down'=>'2000-01-01 00:00:00','ordering'=>'2','access'=>'2','showtitle'=>'1','language'=>'vi-VN','client_id'=>'1','params'=>'{"moduleclass_sfx":"replacement"}'] as $field=>$value) {
    $before=$cw->store['module'][110][$field];$cw->store['module'][110][$field]=$value;
    contractRejects('contract rejects module '.$field.' drift',fn()=>$contract->inspect());
    $cw->store['module'][110][$field]=$before;
}
$cw->store['moduleAssignment'][110]['menuids']='[0]';
contractRejects('contract rejects changing exclusions to all pages',fn()=>$contract->inspect());
$cw->store['moduleAssignment'][110]['menuids']='[-121]';
$cw->store['menuItem'][120]['access']='2';
contractRejects('contract rejects menu access drift',fn()=>$contract->inspect());
$cw->store['menuItem'][120]['access']='1';
$cs->acl['viewlevels'][0]['rules']=[2];
contractRejects('same access ID cannot silently change its audience',fn()=>$contract->inspect());
$cs->acl['viewlevels'][0]['rules']=[1];
$cw->store['module'][111]=['id'=>'111']+$module;
contractRejects('contract rejects an extra module',fn()=>$contract->inspect());
unset($cw->store['module'][111]);
file_put_contents($contractDir.'/assets/demo.css','.hero { display: none }');
contractRejects('contract rejects CSS drift',fn()=>$contract->inspect());
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
check('bootstrap immediately blocks generic writes',$receiver->handle(['token'=>$WTOKEN,'action'=>'content.update','params'=>['kind'=>'module','id'=>110,'fields'=>['published'=>'0']]])['error'],'content_only');
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
check('bound receiver refuses generic module unpublishing',$receiver->handle(['token'=>$WTOKEN,'action'=>'content.update','params'=>['kind'=>'module','id'=>110,'apply_id'=>'generic','fields'=>['published'=>'0']]])['error'],'content_only');
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
contractRejects('a reused switcher that drifts is still refused',fn()=>$reuse->inspect());

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
foreach(['tracy-apple/j6/1.2.0','tracy-airbnb/j6/1.1.0','ja-voyara/j6/1.0.2','ja-kinetic/j6/1.0.0','tracy-business/j6/1.0.0','tracy-business/j6/1.1.0'] as $id)
    checkTrue("the package carries $id",in_array($id,$bundled,true));
// ja-kinetic's demo is 240 blog posts about a company that does not exist; without this file every
// customer site built on it shows them, and nothing anywhere reports that.
checkTrue('the package carries the ja-kinetic demo-trim map',in_array('ja-kinetic/j6/1.0.0',$trimmed,true));
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
