<?php
// Exact reviewed HTML slot wrappers: no CMS, external service, renderer hook or template-file write.
$plRoot = sys_get_temp_dir() . '/phone-links-' . bin2hex(random_bytes(5));
$plDir = $plRoot . '/contract'; mkdir($plDir, 0777, true); mkdir($plRoot . '/lib');
$plHtml = '<div><span>+97143535071<br>+97143535072</span><a href="https://example.test">Keep</a></div>';
$plArticle = '<h3>+97143535071</h3><p class="keep">Unrelated words</p>';
$plSlot = static fn($key, $entity, $column, $xpath, $sample) => array_filter(['key'=>$key,'entity'=>$entity,'column'=>$column,'xpath'=>$xpath,'sample'=>$sample,'type'=>'text','role'=>'contact.phone','maxCharacters'=>80,'requiresEvidence'=>false], static fn($v)=>$v!==null);
$plMap = ['entities'=>[['key'=>'footer','kind'=>'module','sourceId'=>11,'identity'=>['title'=>'Footer']],['key'=>'booking','kind'=>'article','sourceId'=>28,'identity'=>['title'=>'Booking']]], 'slots'=>[
    $plSlot('footer.0','footer','content','/html/body/div/div/span/text()[1]','+97143535071'),
    $plSlot('footer.1','footer','content','/html/body/div/div/span/text()[2]','+97143535072'),
    $plSlot('booking.0','booking','introtext','/html/body/div/h3/text()','+97143535071'),
    $plSlot('footer.scalar','footer','title',null,'Footer')], 'pages'=>[]];
$plStore = new TestContractStore();
$plLock = ['entities'=>['footer'=>['title'=>'Footer','content'=>$plHtml], 'booking'=>['title'=>'Booking','introtext'=>$plArticle]],'assignments'=>[['moduleid'=>11,'menuid'=>0]],'fileRoots'=>[],'files'=>[],'inventoryCounts'=>['module'=>1,'article'=>1],'access'=>$plStore->acl];
file_put_contents($plDir.'/manifest.json',json_encode(['id'=>'test-phone/j6/1.0.0']));
file_put_contents($plDir.'/content-map.json',json_encode($plMap));
file_put_contents($plDir.'/presentation-lock.json',json_encode($plLock));
file_put_contents($plRoot.'/lib/phone-links.json',json_encode(['version'=>1,'contracts'=>['test-phone/j6/1.0.0'=>['contentMapSha256'=>hash_file('sha256',$plDir.'/content-map.json'),'slots'=>['footer.0','footer.1','booking.0','footer.scalar']]]]));
$plLog = new FakeApplyLog(); $plWriter = new ContractTestWriter($plLog,$plStore);
$plWriter->store['module'][11] = ['id'=>'11']+$plLock['entities']['footer'];
$plWriter->store['moduleAssignment'][11] = ['menuids'=>'[0]'];
$plWriter->store['article'][28] = ['id'=>'28']+$plLock['entities']['booking'];
$plContract = new QuickstartContract($plWriter,$plStore,$plRoot,$plDir,null,$plRoot.'/lib');
$plContract->bind($plContract->inspect()['snapshot']);
$plEngine = new Engine($WTOKEN,[],null,null,null,null,$plWriter,null,$plLog,null,null,null,$plContract);
$plN=0;
$plParams = function($key='footer.0',$expected='+97143535071',$tel='+97143535071') use($plContract,&$plN) {
    $n=++$plN;return ['operation'=>'phone.link','contract'=>'test-phone/j6/1.0.0','contract_hash'=>$plContract->contractHash(),'expected_revision'=>$plContract->inspect()['revision'],'key'=>$key,'expected'=>$expected,'tel'=>$tel,'apply_id'=>'contract-phone-'.$n,'request_id'=>'phone-'.$n];
};
$plCall = fn($p) => $plEngine->handle(['token'=>$WTOKEN,'action'=>'content.contract','params'=>$p]);
$plUndo = fn($id) => $plEngine->handle(['token'=>$WTOKEN,'action'=>'apply.revert','params'=>['apply_id'=>$id]]);
$plInitial=[$plWriter->store,$plStore->binding];
check('public inspect exposes the immutable identity required by phone.link',$plCall(['operation'=>'inspect'])['contractHash'],$plContract->contractHash());
check('public inspect advertises exact HTML keys and whole-slot matching',$plCall(['operation'=>'inspect'])['phoneLinks'],['version'=>1,'storage'=>'html','scalar'=>false,'match'=>'whole-slot','supportedKeys'=>['footer.0','footer.1','booking.0']]);
foreach ([['footer.scalar','Footer','+97143535071'],['missing','+97143535071','+97143535071'],['footer.0','+97143535071','+97143535072'],['footer.0','Call +97143535071','+97143535071'],['footer.0','+97143535071','javascript:1']] as $plBad) {
    $answer=$plCall($plParams(...$plBad)); check('phone link refuses unsafe scalar, key or target '.implode(' ',$plBad),$answer['ok'],false);
    check('phone refusal writes no row or baseline',[$plWriter->store,$plStore->binding],$plInitial);
}
$plBad=$plParams();$plBad['contract_hash']='wrong';check('phone link requires exact contract hash',$plCall($plBad)['ok'],false);
$plBad=$plParams();$plBad['expected_revision']='stale';check('phone link requires fresh revision',$plCall($plBad)['ok'],false);
$plBad=$plParams();$plBad['expected']='+97143535070';$plBad['tel']=$plBad['expected'];check('phone link requires actual expected text',$plCall($plBad)['ok'],false);
$plOne=$plParams();$plAnswer=$plCall($plOne);if (!$plAnswer['ok']) echo json_encode($plAnswer)."\n";check('reviewed first footer phone wraps',$plAnswer['ok'],true);
checkTrue('phone wrapper leaves unrelated anchor intact',str_contains($plWriter->store['module'][11]['content'],'<a href="https://example.test">Keep</a>'));
check('phone wrapper reads original exact phone through remapped slot',$plContract->inspect()['slotValues']['footer.0'],'+97143535071');
check('wrapping first footer phone preserves second text slot',$plContract->inspect()['slotValues']['footer.1'],'+97143535072');
check('same request replays exact answer',$plCall($plOne),$plAnswer);
$plReplay=$plOne;$plReplay['tel']='+97143535072';check('same request with changed content refuses',$plCall($plReplay)['ok'],false);
check('new identical request is idempotent',$plCall($plParams())['already'],true);
$plBeforeSame=[$plWriter->store,$plStore->binding];
$plSame=$plCall(['operation'=>'apply','apply_id'=>'contract-phone-same','request_id'=>'same-phone','expected_revision'=>$plContract->inspect()['revision'],'changes'=>['footer.0'=>'+97143535071']]);
check('normal contract apply of the same phone succeeds',$plSame['ok'],true);
check('same phone text preserves exact anchor rows and presentation baseline',[$plWriter->store,$plStore->binding],$plBeforeSame);
check('same-text apply undo succeeds without removing the wrapper',$plUndo('contract-phone-same')['ok'],true);
check('same-text apply undo preserves exact wrapper baseline',[$plWriter->store,$plStore->binding],$plBeforeSame);
foreach (['empty_slots','empty_links'] as $emptyKind) {
    $plEmpty=$plCall(['operation'=>'apply','apply_id'=>'contract-phone-'.$emptyKind,'request_id'=>'empty-phone','expected_revision'=>$plContract->inspect()['revision'],$emptyKind=>['footer.0']]);
    check('active phone wrapper refuses '.$emptyKind.' before ordinary hide handling',$plEmpty['errors'][0]['code']??null,'PHONE_LINK_CONFLICT');
}
check('refused phone emptying preserves exact anchor and baseline',[$plWriter->store,$plStore->binding],$plBeforeSame);


$plLinked=$plWriter->store['module'][11]['content'];
$plWriter->store['module'][11]['content']=str_replace('tel:+97143535071','tel:+97143535079',$plLinked);
check('idempotency refuses an existing wrapper with a different target',$plCall($plParams())['ok'],false);
$plWriter->store['module'][11]['content']=$plLinked;
check('active wrappers refuse structural contract jobs',$plCall(['operation'=>'multilingual.prepare','locale'=>'de-DE'])['ok'],false);

$plTwo=$plParams('footer.1','+97143535072','+97143535072');check('second footer phone wraps after first remapped its index',$plCall($plTwo)['ok'],true);
check('older undo refuses before changing rows',$plUndo($plOne['apply_id'])['ok'],false);
check('second wrapper undo succeeds',$plUndo($plTwo['apply_id'])['ok'],true);
check('first wrapper undo succeeds',$plUndo($plOne['apply_id'])['ok'],true);
check('footer wrappers restore exact rows and baseline',[$plWriter->store,$plStore->binding],$plInitial);
$plArticleParams=$plParams('booking.0');check('reviewed original article phone wraps semantically',$plCall($plArticleParams)['ok'],true);
check('original article unrelated words preserved',str_contains($plWriter->store['article'][28]['introtext'],'<p class="keep">Unrelated words</p>'),true);
$plGuard=$plCall(['operation'=>'apply','apply_id'=>'contract-phone-change','request_id'=>'change','expected_revision'=>$plContract->inspect()['revision'],'changes'=>['booking.0'=>'+97143535072']]);
check('ordinary text apply cannot silently leave old dial target',$plGuard['ok'],false);
check('article wrapper undo succeeds',$plUndo($plArticleParams['apply_id'])['ok'],true);
check('article restores exact original rows and baseline',[$plWriter->store,$plStore->binding],$plInitial);
foreach ([['<p><a href="https://example.test">+97143535071</a></p>','/html/body/div/p/a/text()'],['<script>+97143535071</script>','/html/body/div/script/text()'],['<p>+97143535071</p><p>+97143535071</p>','/html/body/div/p/text()']] as [$html,$xpath]) {
    $refused=false;try{ContentSlots::phoneLink($html,['key'=>'bad','column'=>'content','xpath'=>$xpath],[],'+97143535071','+97143535071');}catch(ContractProblem $e){$refused=true;}check('phone HTML primitive refuses linked, executable or ambiguous nodes',$refused,true);
}
$plWriter->failOn=['module',11];check('writer refusal returns failure',$plCall($plParams())['ok'],false);$plWriter->failOn=null;
check('failed wrapper restores baseline and rows',[$plWriter->store,$plStore->binding],$plInitial);
$plWriter->tableCommits=true;$plWriter->failAfter=['module',11];
check('a write that lands before throwing fails honestly',$plCall($plParams())['ok'],false);
check('implicit-commit failure restores original rows and baseline',[$plWriter->store,$plStore->binding],$plInitial);
$plWriter->tableCommits=false;
check('native advertised review includes only three stored HTML Diner keys',PhoneLinks::ability(__DIR__.'/../lib')['contracts']['ja-diner/j6/1.0.8']['slots'],['module-111.0','module-111.1','article-28.16']);

$plWriter->store['module'][11]['content']=str_replace('+97143535071','0845550110',$plHtml);
$plNational=$plParams('footer.0','0845550110','0845550110');$plNationalAnswer=$plCall($plNational);
check('a verified national phone keeps its exact national dial target',$plNationalAnswer['readBack']??null,['text'=>'0845550110','href'=>'tel:0845550110']);
check('national phone wrapper undo succeeds',$plUndo($plNational['apply_id'])['ok'],true);
$plInvented=$plParams('footer.0','0845550110','+668455550110');check('national phone never invents a country code',$plCall($plInvented)['ok'],false);
$plWriter->store['module'][11]['content']=$plHtml;
check('national fixture restores exact outer baseline',[$plWriter->store,$plStore->binding],$plInitial);
foreach ([['(084) 555-0110','0845550110'],['+44 (20) 7946 0123','+442079460123'],['0044 (20) 7946 0123','+442079460123']] as [$shown,$tel]) {
    $plWriter->store['module'][11]['content']=str_replace('+97143535071',$shown,$plHtml);
    $formatted=$plParams('footer.0',$shown,$tel);$answer=$plCall($formatted);
    check('formatted phone wraps exact text and dial target '.$shown,$answer['readBack']??null,['text'=>$shown,'href'=>'tel:'.$tel]);
    check('formatted phone wrapper restores through undo '.$shown,$plUndo($formatted['apply_id'])['ok'],true);
}
foreach (['Call 0845550110','tel:0845550110','<b>0845550110</b>',"0845550110\n", "084\t5550110",'(084 5550110','084) 5550110','()0845550110'] as $shown) {
    $refused=false;try{PhoneLinks::target($shown,'0845550110');}catch(ContractProblem $e){$refused=true;}
    check('formatted phone refuses prose markup controls or unbalanced groups '.json_encode($shown),$refused,true);
}
$plWriter->store['module'][11]['content']=$plHtml;
check('formatted phone fixtures restore exact outer baseline',[$plWriter->store,$plStore->binding],$plInitial);
foreach (glob($plDir.'/*') as $f)unlink($f);unlink($plRoot.'/lib/phone-links.json');rmdir($plDir);rmdir($plRoot.'/lib');rmdir($plRoot);
