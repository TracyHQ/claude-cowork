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
    public array $acl=['viewlevels'=>[['id'=>'1','rules'=>[1]]]];
    public function load(): ?array { return $this->binding; }
    public function save(array $value): void { $this->binding=$value; }
    public function access(): array { return $this->acl; }
}
final class ContractTestWriter extends FakeSiteWriter {
    private FakeApplyLog $log; private TestContractStore $binding;
    public bool $drift=false;
    public function __construct(FakeApplyLog $log,TestContractStore $binding){$this->log=$log;$this->binding=$binding;}
    public function write(string $kind,int $id,array $fields):int {
        $fields=array_merge($this->read($kind,$id)??[],$fields);
        if($this->drift && $kind==='module')$fields['position']='wrong-position';
        return parent::write($kind,$id,$fields);
    }
    public function transaction(callable $work):array {
        $before=[$this->store,$this->log->log,$this->binding->binding];
        try{return $work();}catch(Throwable $error){
            [$this->store,$this->log->log,$this->binding->binding]=$before;throw $error;
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
$contract=new QuickstartContract($cw,$cs,$contractDir,$contractDir);
check('Joomla loadposition directives are never editable text',ContentSlots::htmlSlots('<p>{loadposition about-page}</p>'),[]);
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
$first=['token'=>$WTOKEN,'action'=>'content.contract','params'=>['operation'=>'apply','apply_id'=>'contract-first','request_id'=>'first','expected_revision'=>$receiverContract->inspect()['revision'],'changes'=>['hero.0'=>'First customer title']]];
$firstResult=$receiver->handle($first);
check('contract receiver commits content in place',$firstResult['ok'],true);
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
foreach(array_keys($data) as $name)unlink($contractDir.'/'.$name.'.json');
unlink($contractDir.'/assets/demo.css');rmdir($contractDir.'/assets');rmdir($contractDir);
