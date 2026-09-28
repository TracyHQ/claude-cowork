<?php
// Pure protocol tests supplement, and never replace, the real CMS/DB acceptance suite.
require_once __DIR__.'/../lib/ContentReader.php';
$crSite=['id'=>'site_test','locales'=>['en-US']];
$crContent=['id'=>'content_a','type'=>'article','locale'=>'en-US','title'=>'A','bodyHtml'=>'live',
    'summary'=>null,'fields'=>[],'tags'=>[],'images'=>[],'relations'=>[],
    'links'=>['self'=>'https://fixture.invalid/content.json?id=content_a'],'blocks'=>[]];
for ($i=0;$i<105;$i++) $crContent['blocks'][]=['id'=>'block_'.$i,'position'=>$i,'fields'=>[],'items'=>[]];
$crRows=['content_a'=>$crContent,'content_b'=>array_replace($crContent,['id'=>'content_b'])];
$crMake=static fn($time=100,$principal='service',$revision='v1')=>new ContentReader($crSite,[],$crRows,$revision,'unit-secret',$principal,$time);
$crError=static function(callable $call): ?string { try { $call(); return null; } catch(ContentReadError $e) { return $e->reason; } };
$crList=$crMake()->read(['limit'=>1]);
check('read protocol summary excludes heavy body',array_key_exists('bodyHtml',$crList['contents'][0]),false);
$crCursor=$crList['pagination']['nextCursor'];
check('read protocol continuation advances',$crMake()->read(['limit'=>1,'cursor'=>$crCursor])['contents'][0]['id'],'content_b');
check('read protocol rejects principal change',$crError(fn()=>$crMake(100,'other')->read(['limit'=>1,'cursor'=>$crCursor])),'CONTENT_BAD_QUERY');
check('read protocol rejects filter change',$crError(fn()=>$crMake()->read(['limit'=>2,'cursor'=>$crCursor])),'CONTENT_BAD_QUERY');
check('read protocol rejects changed revision',$crError(fn()=>$crMake(100,'service','v2')->read(['limit'=>1,'cursor'=>$crCursor])),'CONTENT_SNAPSHOT_EXPIRED');
check('read protocol expires at boundary (unit clock only)',$crError(fn()=>$crMake(400)->read(['limit'=>1,'cursor'=>$crCursor])),'CONTENT_SNAPSHOT_EXPIRED');
check('read protocol rejects duplicate query',$crError(fn()=>ContentReader::parse('limit=1&limit=2')),'CONTENT_BAD_QUERY');
$crDetail=$crMake()->read(['id'=>'content_a'])['contents'][0];
check('read protocol segments blocks',count($crDetail['blocks']),100);
check('read protocol partial state',$crDetail['detailState'],'partial');
$crNext=$crDetail['blocksPagination']['nextCursor'];
$crFinal=$crMake()->read(['id'=>'content_a','blocksCursor'=>$crNext])['contents'][0];
check('read protocol terminal block segment',count($crFinal['blocks']),5);
check('read protocol absolute block positions',$crFinal['blocks'][0]['position'],100);
check('read protocol binds detail owner',$crError(fn()=>$crMake()->read(['id'=>'content_b','blocksCursor'=>$crNext])),'CONTENT_BAD_QUERY');
$crHuge=$crContent; $crHuge['bodyHtml']=str_repeat('x',270000);
$crHugeReader=new ContentReader($crSite,[],['content_a'=>$crHuge],'v1','unit-secret','service',100);
check('read protocol refuses oversized scalar',$crError(fn()=>$crHugeReader->read(['id'=>'content_a'])),'CONTENT_FIELD_TOO_LARGE');

$crCombined=$crContent; $crCombined['bodyHtml']=str_repeat('b',180000);
$crCombined['blocks']=[['id'=>'block_large','position'=>0,'fields'=>[['key'=>'text','value'=>str_repeat('v',180000)]],'items'=>[]]];
$crCombinedReader=new ContentReader($crSite,[],['content_a'=>$crCombined],'v1','unit-secret','service',100);
$crFirst=$crCombinedReader->read(['id'=>'content_a'])['contents'][0];
check('read protocol defers body instead of false scalar overflow',$crFirst['detailState'],'partial');
check('read protocol unloaded body is absent',array_key_exists('bodyHtml',$crFirst),false);
$crLast=$crCombinedReader->read(['id'=>'content_a','blocksCursor'=>$crFirst['blocksPagination']['nextCursor']])['contents'][0];
check('read protocol terminal deferred body is complete',$crLast['bodyHtml'],$crCombined['bodyHtml']);
check('read protocol terminal deferred body does not repeat blocks',count($crLast['blocks']),0);

check('read protocol cursor alone retains limit',$crMake()->read(['cursor'=>$crCursor])['contents'][0]['id'],'content_b');

$crDiscovery=$crHuge; $crDiscovery['blocks']=[];
for($i=0;$i<400;$i++) $crDiscovery['blocks'][]=['id'=>'discover_'.$i,'position'=>$i,'fields'=>[],'items'=>[]];
$crDiscover=static fn($principal='service',$time=100,$revision='v1')=>new ContentReader($crSite,[],['content_a'=>$crDiscovery],$revision,'unit-secret',$principal,$time);
try { $crDiscover()->read(['id'=>'content_a']); throw new RuntimeException('Expected body overflow'); }
catch(ContentReadError $e) { $crDiscoveryError=$e->body(); }
$crFirstLink=$crDiscoveryError['error']['links']['firstBlock'];
check('discovery error carries snapshot',$crDiscoveryError['error']['snapshot']['revision'],'v1');
$crFirstQuery=ContentReader::parse(parse_url($crFirstLink,PHP_URL_QUERY));
check('discovery cursor rejects another principal',$crError(fn()=>$crDiscover('other')->read($crFirstQuery)),'CONTENT_BAD_QUERY');
check('discovery cursor expires',$crError(fn()=>$crDiscover('service',400)->read($crFirstQuery)),'CONTENT_SNAPSHOT_EXPIRED');
check('discovery cursor rejects changed snapshot',$crError(fn()=>$crDiscover('service',100,'v2')->read($crFirstQuery)),'CONTENT_SNAPSHOT_EXPIRED');
$crBadQuery=$crFirstQuery; $crBadQuery['blockId']='discover_1';
check('discovery cursor binds block',$crError(fn()=>$crDiscover()->read($crBadQuery)),'CONTENT_BAD_QUERY');
$crCount=0; $crWalk=$crFirstQuery;
do {
    $crPart=$crDiscover()->read($crWalk)['contents'][0];
    check('discovery absolute position '.$crCount,$crPart['blocks'][0]['position'],$crCount++);
    $crWalk=$crPart['blocksPagination']['nextCursor']===null?null:ContentReader::parse(parse_url($crPart['links']['next'],PHP_URL_QUERY));
} while($crWalk!==null);
check('discovery walks all 400 blocks without looping to body',$crCount,400);
