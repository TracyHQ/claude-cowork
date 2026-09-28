<?php
// content.read `ids`: many details from ONE projection. Building the projection is the whole cost
// of a Joomla read (every mapped table, the contract, the router: ~0.8 s on the stand's Northgate
// site, 27/09/2026), and a relay indexing a site used to pay it once per record — 101-123 times for
// one snapshot. A batch answers each listed content exactly as `{id}` would (same shape, same
// revision), fills the byte budget in the order asked, and names what it left out: `pending` (did
// not fit, or needs block segments — ask again, or read it alone with `{id}`) and `missing` (no
// such content now). Pure protocol tests at the reader's seam.
require_once __DIR__.'/../lib/ContentReader.php';
$btSite=['id'=>'site_batch','locales'=>['en-US']];
$btMake=static function(string $id, int $bytes, int $blocks=1): array {
    $content=['id'=>$id,'type'=>'article','locale'=>'en-US','title'=>'Title '.$id,'summary'=>null,'bodyHtml'=>str_repeat('b',$bytes),
        'fields'=>[],'tags'=>[],'images'=>[],'relations'=>[],'revision'=>'rev_'.$id,
        'links'=>['self'=>'https://fixture.invalid/content.json?id='.$id],'blocks'=>[]];
    for ($i=0;$i<$blocks;$i++) $content['blocks'][]=['id'=>$id.'_block_'.$i,'position'=>$i,'fields'=>[['key'=>'text','type'=>'text','value'=>'v'.$i]],'items'=>[]];
    return $content;
};
$btRows=['c1'=>$btMake('c1',100),'c2'=>$btMake('c2',3000),'c3'=>$btMake('c3',200),'c4'=>$btMake('c4',50,105),'c5'=>$btMake('c5',9000)];
$btReader=static fn(array $rows=null)=>new ContentReader($btSite,[],$rows??$btRows,'v1','unit-secret','service',100);
$btError=static function(callable $call): ?string { try { $call(); return null; } catch(ContentReadError $e) { return $e->reason; } };
$btIds=static fn(array $page): array => array_column($page['contents'],'id');
$btBytes=static fn(array $page): int => strlen(ContentReader::encode($page));

$bt=$btReader()->read(['ids'=>['c3','c1','c2']]);
check('batch answers the listed contents in the order asked',$btIds($bt),['c3','c1','c2']);
check('batch: each content is exactly what a single read answers',$bt['contents'][1],$btReader()->read(['id'=>'c1'])['contents'][0]);
check('batch: every content is complete',array_unique(array_column($bt['contents'],'detailState')),['complete']);
check('batch: each content keeps its own revision',array_column($bt['contents'],'revision'),['rev_c3','rev_c1','rev_c2']);
check('batch: nothing pending, nothing missing',[$bt['pagination']['pending'],$bt['pagination']['missing']],[[],[]]);
check('batch: the same snapshot envelope as every read',[$bt['schemaVersion'],$bt['snapshot']['revision']],['tracy-content/v1','v1']);
check('batch: no cursor — what was left out is named, not paged',$bt['pagination']['nextCursor'],null);
check('batch states its own encoded size',$bt['pagination']['bytes'],$btBytes($bt));
check('batch with no budget named answers up to the detail ceiling',$bt['pagination']['budget'],ContentReader::MAX_BYTES);

check('batch over GET: ids is one comma-separated value',
    $btIds($btReader()->read(ContentReader::parse('ids='.rawurlencode('c1,c3')))),['c1','c3']);

$btGone=$btReader()->read(['ids'=>['c1','gone','c3']]);
check('batch: an id with no content is missing, the rest are answered',[$btIds($btGone),$btGone['pagination']['missing']],[['c1','c3'],['gone']]);
$btLong=$btReader()->read(['ids'=>['c4','c1']]);
check('batch: a content needing block segments is pending (read it alone), the rest answered',
    [$btIds($btLong),$btLong['pagination']['pending']],[['c1'],['c4']]);

// The budget fills greedily in the order asked; what does not fit is pending, never cut.
$btTight=$btReader()->read(['ids'=>['c1','c5','c2','c3'],'maxBytes'=>8192]);
check('batch fills the budget in order and leaves out what does not fit',[$btIds($btTight),$btTight['pagination']['pending']],[['c1','c2','c3'],['c5']]);
checkTrue('batch fits the budget it was given',$btBytes($btTight)<=8192);
check('batch states its own size under a budget',$btTight['pagination']['bytes'],$btBytes($btTight));
check('batch never truncates a content',array_filter(array_column($btTight['contents'],'truncated')),[]);
$btNone=$btReader()->read(['ids'=>['c5'],'maxBytes'=>8192]);
check('batch: a content that alone overflows is pending, not an empty refusal',[$btIds($btNone),$btNone['pagination']['pending']],[[],['c5']]);
// A budget exactly the size of the answer still fits; one byte less leaves the last content out.
// (Measured under a five-digit budget: the budget is part of the answer, so its width counts.)
$btSize=$btReader()->read(['ids'=>['c1','c5'],'maxBytes'=>20000])['pagination']['bytes'];
checkTrue('batch fixture: the answer is five digits long too',$btSize>=10000 && $btSize<20000);
check('batch: a budget of exactly the answer size fits it',$btIds($btReader()->read(['ids'=>['c1','c5'],'maxBytes'=>$btSize])),['c1','c5']);
check('batch: one byte less leaves the last content pending',$btReader()->read(['ids'=>['c1','c5'],'maxBytes'=>$btSize-1])['pagination']['pending'],['c5']);

check('batch refuses an id listed twice',$btError(fn()=>$btReader()->read(['ids'=>['c1','c1']])),'CONTENT_BAD_QUERY');
check('batch refuses an empty list',$btError(fn()=>$btReader()->read(['ids'=>[]])),'CONTENT_BAD_QUERY');
check('batch refuses more than 100 ids',$btError(fn()=>$btReader()->read(['ids'=>array_map(fn($i)=>'c'.$i,range(1,101))])),'CONTENT_BAD_QUERY');
check('batch refuses a non-string id',$btError(fn()=>$btReader()->read(['ids'=>['c1',7]])),'CONTENT_BAD_QUERY');
check('batch refuses a map in place of a list',$btError(fn()=>$btReader()->read(['ids'=>['a'=>'c1']])),'CONTENT_BAD_QUERY');
check('batch refuses an empty id in the GET form',$btError(fn()=>$btReader()->read(['ids'=>'c1,,c3'])),'CONTENT_BAD_QUERY');
foreach (['id'=>'c1','type'=>'article','limit'=>'2','cursor'=>'x','blockId'=>'b','blocksCursor'=>'x'] as $btKey=>$btValue)
    check('batch does not combine with '.$btKey,$btError(fn()=>$btReader()->read(['ids'=>['c1'],$btKey=>$btValue])),'CONTENT_BAD_QUERY');
check('batch accepts a protocol announcement',$btIds($btReader()->read(['ids'=>['c1'],'protocolVersions'=>'tracy-content/v1'])),['c1']);
