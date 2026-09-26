<?php
// content.read byte budget (`maxBytes`): the reader pages BY CONTENT inside the budget the caller
// names, and never answers an empty page while a content exists — a content that alone overflows
// is sent once, cut, and marked `truncated`. Pure protocol tests at the reader's seam.
require_once __DIR__.'/../lib/ContentReader.php';
$bgSite=['id'=>'site_budget','locales'=>['en-US']];
$bgMake=static function(string $id, int $bytes, array $extra=[]): array {
    return array_replace(['id'=>$id,'type'=>'article','locale'=>'en-US','title'=>'Title '.$id,'slug'=>$id,'summary'=>str_repeat('s',$bytes),
        'bodyHtml'=>'<p>body</p>','fields'=>[],'tags'=>[],'images'=>[],'relations'=>[],'revision'=>'rev_'.$id,
        'links'=>['self'=>'https://fixture.invalid/content.json?id='.$id],'blocks'=>[]],$extra);
};
$bgRows=['c1'=>$bgMake('c1',2000),'c2'=>$bgMake('c2',40000),'c3'=>$bgMake('c3',3000)];
$bgReader=static fn(array $rows=null,$revision='v1')=>new ContentReader($bgSite,[],$rows??$bgRows,$revision,'unit-secret','service',100);
$bgError=static function(callable $call): ?string { try { $call(); return null; } catch(ContentReadError $e) { return $e->reason.': '.$e->getMessage(); } };
$bgBytes=static fn(array $page): int => strlen(ContentReader::encode($page));
$bgIds=static fn(array $page): array => array_column($page['contents'],'id');

// The conformance case (spec step A): 2 KB, 40 KB, 3 KB at maxBytes 8192.
$bgP1=$bgReader()->read(['maxBytes'=>'8192']);
check('budget page 1 carries only the first whole content',$bgIds($bgP1),['c1']);
check('budget page 1 is not truncated',array_key_exists('truncated',$bgP1['contents'][0]),false);
checkTrue('budget page 1 fits the budget',$bgBytes($bgP1)<=8192);
check('budget page 1 names the budget applied',$bgP1['pagination']['budget'],8192);
check('budget page 1 states its own encoded size',$bgP1['pagination']['bytes'],$bgBytes($bgP1));
check('budget page 1 total keeps its meaning',$bgP1['pagination']['total'],3);
checkTrue('budget page 1 continues',is_string($bgP1['pagination']['nextCursor']));

$bgP2=$bgReader()->read(['maxBytes'=>8192,'cursor'=>$bgP1['pagination']['nextCursor']]);
check('budget page 2 starts at the first unsent content, alone',$bgIds($bgP2),['c2']);
check('budget page 2 marks the oversized content truncated',$bgP2['contents'][0]['truncated']??null,true);
check('budget page 2 cuts the longest field to maxBytes/2 characters',strlen($bgP2['contents'][0]['summary']),4096);
check('budget page 2 names the cut field',$bgP2['contents'][0]['truncatedFields']??null,[['blockId'=>null,'itemId'=>null,'key'=>'summary']]);
check('budget page 2 keeps the revision computed before the cut',$bgP2['contents'][0]['revision'],'rev_c2');
checkTrue('budget page 2 fits the budget',$bgBytes($bgP2)<=8192);
check('budget page 2 states its own encoded size',$bgP2['pagination']['bytes'],$bgBytes($bgP2));
checkTrue('budget page 2 continues',is_string($bgP2['pagination']['nextCursor']));

$bgP3=$bgReader()->read(['maxBytes'=>'8192','cursor'=>$bgP2['pagination']['nextCursor']]);
check('budget page 3 carries the last content',$bgIds($bgP3),['c3']);
check('budget page 3 ends the scan',$bgP3['pagination']['nextCursor'],null);
checkTrue('budget page 3 fits the budget',$bgBytes($bgP3)<=8192);
$bgAll=array_merge($bgIds($bgP1),$bgIds($bgP2),$bgIds($bgP3));
check('budget pages cover total exactly once',[count($bgAll),count(array_unique($bgAll))],[3,3]);

// A block field is the longest: it is the one cut, and the field itself says so (detail read).
$bgBlocks=$bgMake('c9',10,['bodyHtml'=>str_repeat('b',3000),'blocks'=>[
    ['id'=>'block_small','position'=>0,'fields'=>[['key'=>'heading','type'=>'text','value'=>'Hello']],'items'=>[]],
    ['id'=>'block_big','position'=>1,'fields'=>[['key'=>'html','type'=>'html','value'=>str_repeat('<p>é</p>',6000)]],'items'=>[]]]]);
$bgDetailReader=$bgReader(['c9'=>$bgBlocks]);
$bgWalk=['id'=>'c9','maxBytes'=>8192]; $bgSegments=[];
do {
    $bgSeg=$bgDetailReader->read($bgWalk); $bgSegments[]=$bgSeg;
    checkTrue('budget detail segment '.count($bgSegments).' fits the budget',$bgBytes($bgSeg)<=8192);
    $bgNext=$bgSeg['contents'][0]['blocksPagination']['nextCursor']??null;
    $bgWalk=$bgNext===null?null:['id'=>'c9','maxBytes'=>8192,'blocksCursor'=>$bgNext];
} while($bgWalk!==null && count($bgSegments)<10);
$bgCut=null;
foreach ($bgSegments as $bgSeg) foreach ($bgSeg['contents'][0]['blocks'] as $bgBlock) if ($bgBlock['id']==='block_big') $bgCut=$bgSeg['contents'][0];
checkTrue('budget detail delivers the oversized block',$bgCut!==null);
check('budget detail marks the content truncated',$bgCut['truncated']??null,true);
check('budget detail marks the cut block field',$bgCut['blocks'][0]['fields'][0]['truncated']??null,true);
check('budget detail cuts the block field to maxBytes/2 characters, never mid-codepoint',mb_strlen($bgCut['blocks'][0]['fields'][0]['value'],'UTF-8'),4096);
checkTrue('budget detail cut is valid UTF-8',mb_check_encoding($bgCut['blocks'][0]['fields'][0]['value'],'UTF-8'));
check('budget detail names the cut block field',$bgCut['truncatedFields'],[['blockId'=>'block_big','itemId'=>null,'key'=>'html']]);
check('budget detail keeps the revision',$bgCut['revision'],'rev_c9');
$bgBodies=array_values(array_filter(array_map(fn($s)=>$s['contents'][0]['bodyHtml']??null,$bgSegments),'is_string'));
check('budget detail delivers the body, and never a cut one',[$bgBodies!==[],array_unique($bgBodies)],[true,[str_repeat('b',3000)]]);

// A body alone over the budget (no blocks): the body is cut; no 413.
$bgBody=$bgReader(['c8'=>$bgMake('c8',10,['bodyHtml'=>str_repeat('x',50000)])])->read(['id'=>'c8','maxBytes'=>'8192'])['contents'][0];
check('budget detail cuts an oversized body instead of refusing',[strlen($bgBody['bodyHtml']),$bgBody['truncated']??null],[4096,true]);

// Defaults, range and limit.
$bgMany=[]; for ($i=0;$i<120;$i++) $bgMany['m'.$i]=$bgMake('m'.$i,1000);
$bgDefault=$bgReader($bgMany)->read(['limit'=>100]);
check('budget default is 65536 when the caller names none',$bgDefault['pagination']['budget'],65536);
checkTrue('budget default page fits 65536',$bgBytes($bgDefault)<=65536);
checkTrue('budget default stops before limit when bytes run out',count($bgDefault['contents'])<100 && count($bgDefault['contents'])>0);
$bgLimited=$bgReader($bgMany)->read(['limit'=>'2','maxBytes'=>'262144']);
check('budget still honours limit',$bgIds($bgLimited),['m0','m1']);
foreach (['8191','262145','abc','8192.5','0','-8192'] as $bgBad)
    check('budget refuses maxBytes '.$bgBad,$bgError(fn()=>$bgReader()->read(['maxBytes'=>$bgBad])),'CONTENT_BAD_QUERY: maxBytes must be an integer from 8192 to 262144');
check('budget refuses a non-integer JSON maxBytes',$bgError(fn()=>$bgReader()->read(['maxBytes'=>8192.0])),'CONTENT_BAD_QUERY: maxBytes must be an integer from 8192 to 262144');
check('budget accepts the bounds',[$bgReader()->read(['maxBytes'=>8192])['pagination']['budget'],$bgReader()->read(['maxBytes'=>262144])['pagination']['budget']],[8192,262144]);

// maxBytes is NOT bound into the cursor: a later page may ask for another budget.
$bgSwitch=$bgReader()->read(['maxBytes'=>'65536','cursor'=>$bgP1['pagination']['nextCursor']]);
check('budget cursor continues under a different maxBytes',$bgIds($bgSwitch),['c2','c3']);
check('budget larger page is not truncated',array_key_exists('truncated',$bgSwitch['contents'][0]),false);

// Compatibility: a caller that names NO maxBytes never gets a cut content.
$bgWide=$bgReader(['w1'=>$bgMake('w1',100000),'w2'=>$bgMake('w2',10)])->read([]);
check('no maxBytes: an overflowing first summary is sent whole, as before',[$bgIds($bgWide),strlen($bgWide['contents'][0]['summary']),array_key_exists('truncated',$bgWide['contents'][0])],[['w1'],100000,false]);
check('no maxBytes: that page names the pre-budget ceiling it used',$bgWide['pagination']['budget'],262144);
check('no maxBytes: an oversized summary keeps the 413',$bgError(fn()=>$bgReader(['w1'=>$bgMake('w1',300000)])->read([])),'CONTENT_FIELD_TOO_LARGE: A content field exceeds the response budget');
$bgOld=$bgReader(['c8'=>$bgMake('c8',10,['bodyHtml'=>str_repeat('x',50000)])])->read(['id'=>'c8'])['contents'][0];
check('no maxBytes: a detail read is not cut',[strlen($bgOld['bodyHtml']),array_key_exists('truncated',$bgOld)],[50000,false]);
check('no maxBytes: an oversized detail keeps the 413',$bgError(fn()=>$bgReader(['c7'=>$bgMake('c7',10,['bodyHtml'=>str_repeat('x',270000)])])->read(['id'=>'c7'])),'CONTENT_FIELD_TOO_LARGE: A content field exceeds the response budget');

// Multibyte: half the budget in characters is still over budget in bytes, so the cut goes on.
$bgCjk=$bgReader(['k1'=>$bgMake('k1',0,['summary'=>str_repeat('中',40000)])])->read(['maxBytes'=>'8192']);
checkTrue('budget multibyte cut fits the budget',$bgBytes($bgCjk)<=8192 && $bgCjk['pagination']['bytes']===$bgBytes($bgCjk));
checkTrue('budget multibyte cut is valid UTF-8 and non-empty',mb_check_encoding($bgCjk['contents'][0]['summary'],'UTF-8') && $bgCjk['contents'][0]['summary']!=='');
// Many small contents at the floor budget: every page fits, the union is total, no duplicate.
$bgWalk=['maxBytes'=>'8192','limit'=>'100']; $bgSeen=[]; $bgFit=true; $bgPages=0;
do { $bgPage=$bgReader($bgMany)->read($bgWalk); $bgPages++; $bgFit=$bgFit && $bgBytes($bgPage)<=8192 && $bgPage['contents']!==[];
    $bgSeen=array_merge($bgSeen,$bgIds($bgPage)); $bgWalk=$bgPage['pagination']['nextCursor']===null?null:['maxBytes'=>'8192','cursor'=>$bgPage['pagination']['nextCursor']];
} while($bgWalk!==null && $bgPages<200);
check('budget walk: every page fits and is non-empty',$bgFit,true);
check('budget walk: union equals total, no duplicates',[count($bgSeen),count(array_unique($bgSeen))],[120,120]);
