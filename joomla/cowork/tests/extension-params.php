<?php
// Loaded by run.php after page-create.php (reuses TestContractStore, ContractTestWriter, FakeSiteWriter, FakeApplyLog, $WTOKEN).
// `extension.params` (lib/ExtensionParams.php): the map of System - JA Google Map moves to the business through the named
// keys of its params (`locations`, `zoom`), every other key kept byte for byte and never answered (the plugin keeps its Google
// API key there); any other extension or key is refused before anything is written, and `apply.revert` takes the set back.
// All places, keys and words here are made up.

$epParams = json_encode([
    'api_key' => 'MADE-UP-KEY-0000', 'disable_map' => '0', 'mode' => 'normal',
    'locations' => ['location' => ['1 Sample Street'], 'latitude' => ['10.5'], 'longitude' => ['-20.25'], 'info' => ['']],
    'zoom' => '10', 'map_width' => '1200', 'center' => 'all',
    'code_container' => "{jamap mode='normal' zoom='10' }{/jamap}", 'list_params' => ['mode', 'locations', 'zoom'],
], JSON_UNESCAPED_SLASHES);
$epRow = ['name' => 'System - JA Google Map', 'type' => 'plugin', 'element' => 'jagooglemap', 'folder' => 'system', 'client_id' => '0', 'enabled' => '1', 'params' => $epParams];
$epMap = ['type' => 'plugin', 'folder' => 'system', 'element' => 'jagooglemap'];

$epWriter = new FakeSiteWriter();
$epWriter->store['extensionParams'][425] = ['extension_id' => '425'] + $epRow;
$epWriter->store['extensionParams'][426] = ['extension_id' => '426', 'name' => 'Made-up', 'type' => 'plugin', 'element' => 'madeup', 'folder' => 'system', 'client_id' => '0', 'enabled' => '1', 'params' => '{"secret":"x"}'];
$epLog = new FakeApplyLog();
$epEngine = new Engine($WTOKEN, [], null, null, null, null, $epWriter, null, $epLog);
$epCall = fn(array $params) => $epEngine->handle(['token' => $WTOKEN, 'action' => 'extension.params', 'params' => $params]);
$epHeld = fn() => $epWriter->store['extensionParams'][425]['params'];

check('info names the extensions and keys extension.params takes',
    $epEngine->handle(['token' => $WTOKEN, 'action' => 'info'])['info']['extension_params'] ?? null,
    ['version' => 1, 'extensions' => ['plugin/system/jagooglemap' => ['locations', 'zoom']]]);

$epRead = $epCall(['operation' => 'read'] + $epMap);
check('read answers the allowed keys as the site holds them', $epRead['params'] ?? null, [
    'locations' => ['location' => ['1 Sample Street'], 'latitude' => ['10.5'], 'longitude' => ['-20.25'], 'info' => ['']], 'zoom' => '10']);
check('read names the row it read', $epRead['extension'] ?? null, ['type' => 'plugin', 'folder' => 'system', 'element' => 'jagooglemap', 'id' => 425, 'enabled' => true]);
checkTrue('read never answers the API key', strpos(json_encode($epRead), 'MADE-UP-KEY') === false);

// Refusals: nothing is written, nothing is logged.
$epRefusals = [
    'an extension not listed' => [['operation' => 'read', 'type' => 'plugin', 'folder' => 'system', 'element' => 'madeup'], 'unsupported'],
    'the right element in another folder' => [['operation' => 'read', 'type' => 'plugin', 'folder' => 'content', 'element' => 'jagooglemap'], 'unsupported'],
    'a key not listed (the API key)' => [['operation' => 'set', 'apply_id' => 'ep-1', 'set' => ['api_key' => 'other']] + $epMap, 'unsupported'],
    'no apply_id' => [['operation' => 'set', 'set' => ['zoom' => 12]] + $epMap, 'bad_params'],
    'an empty set' => [['operation' => 'set', 'apply_id' => 'ep-1', 'set' => []] + $epMap, 'bad_params'],
    'a latitude out of range' => [['operation' => 'set', 'apply_id' => 'ep-1', 'set' => ['locations' => [['location' => '2 Sample Road', 'latitude' => 95, 'longitude' => 10]]]] + $epMap, 'bad_params'],
    'a latitude without its longitude' => [['operation' => 'set', 'apply_id' => 'ep-1', 'set' => ['locations' => [['location' => '2 Sample Road', 'latitude' => 25.1]]]] + $epMap, 'bad_params'],
    'a coordinate that is not a number' => [['operation' => 'set', 'apply_id' => 'ep-1', 'set' => ['locations' => [['location' => '2 Sample Road', 'latitude' => '25.1N', 'longitude' => '10']]]] + $epMap, 'bad_params'],
    'markup in an address' => [['operation' => 'set', 'apply_id' => 'ep-1', 'set' => ['locations' => [['location' => '<b>2 Sample Road</b>']]]] + $epMap, 'bad_params'],
    'a line break in an address' => [['operation' => 'set', 'apply_id' => 'ep-1', 'set' => ['locations' => [['location' => "2 Sample\nRoad"]]]] + $epMap, 'bad_params'],
    'no places' => [['operation' => 'set', 'apply_id' => 'ep-1', 'set' => ['locations' => []]] + $epMap, 'bad_params'],
    'a field a place does not have' => [['operation' => 'set', 'apply_id' => 'ep-1', 'set' => ['locations' => [['location' => '2 Sample Road', 'icon' => 'x']]]] + $epMap, 'bad_params'],
    'a zoom past 20' => [['operation' => 'set', 'apply_id' => 'ep-1', 'set' => ['zoom' => 25]] + $epMap, 'bad_params'],
    'a good zoom beside a bad place' => [['operation' => 'set', 'apply_id' => 'ep-1', 'set' => ['zoom' => 12, 'locations' => [['location' => '']]]] + $epMap, 'bad_params'],
    'an unknown operation' => [['operation' => 'replace'] + $epMap, 'bad_params'],
];
foreach ($epRefusals as $name => [$params, $code]) check("refused: {$name}", $epCall($params)['error'] ?? null, $code);
check('a refusal writes nothing', $epHeld(), $epParams);
check('a refusal logs nothing', $epLog->entries('ep-1'), []);
check('an extension that is not installed is not found',
    (function () use ($WTOKEN) {
        $empty = new FakeSiteWriter();
        $engine = new Engine($WTOKEN, [], null, null, null, null, $empty, null, new FakeApplyLog());
        return $engine->handle(['token' => $WTOKEN, 'action' => 'extension.params', 'params' => ['operation' => 'set', 'apply_id' => 'ep-0',
            'type' => 'plugin', 'folder' => 'system', 'element' => 'jagooglemap', 'set' => ['zoom' => 12]]])['error'] ?? null;
    })(), 'not_found');

// The set: the map moves to the business, as the plugin's form would store it.
$epPurges = $epWriter->purges;
$epSet = $epCall(['operation' => 'set', 'apply_id' => 'ep-2', 'set' => [
    'locations' => [['location' => '7 Made-up Avenue, Sample City', 'latitude' => 25.2048493, 'longitude' => '55.2707828']],
    'zoom' => '14',
]] + $epMap);
check('set answers ok', $epSet['ok'] ?? null, true);
check('set answers the values it wrote', $epSet['params'] ?? null, [
    'locations' => ['location' => ['7 Made-up Avenue, Sample City'], 'latitude' => ['25.2048493'], 'longitude' => ['55.2707828'], 'info' => ['']],
    'zoom' => '14']);
check('set says it changed the row', $epSet['changed'] ?? null, true);
checkTrue('set never answers the API key', strpos(json_encode($epSet), 'MADE-UP-KEY') === false);
$epAfter = json_decode($epHeld(), true);
$epBefore = json_decode($epParams, true);
check('the map keys hold the new place', [$epAfter['locations'], $epAfter['zoom']], [
    ['location' => ['7 Made-up Avenue, Sample City'], 'latitude' => ['25.2048493'], 'longitude' => ['55.2707828'], 'info' => ['']], '14']);
unset($epAfter['locations'], $epAfter['zoom'], $epBefore['locations'], $epBefore['zoom']);
check('every other key is kept as it was, the API key included', $epAfter, $epBefore);
check('the write is one undo entry of its apply_id', array_column($epLog->entries('ep-2'), 'op'), ['content']);
checkTrue('the cache is purged after the set', $epWriter->purges > $epPurges);

$epAgain = $epCall(['operation' => 'set', 'apply_id' => 'ep-3', 'set' => ['zoom' => 14]] + $epMap);
check('the same value again changes nothing and logs nothing', [$epAgain['changed'] ?? null, $epLog->entries('ep-3')], [false, []]);

$epBoth = $epCall(['operation' => 'set', 'apply_id' => 'ep-4', 'set' => ['locations' => [
    ['location' => 'Only an address, Sample City'], ['location' => 'Second place', 'latitude' => '-0', 'longitude' => 0, 'info' => 'Back door'],
]]] + $epMap);
check('a place without coordinates is stored with both empty, and a second place beside it',
    $epBoth['params']['locations'] ?? null,
    ['location' => ['Only an address, Sample City', 'Second place'], 'latitude' => ['', '0'], 'longitude' => ['', '0'], 'info' => ['', 'Back door']]);

check('apply.revert takes the later set back', $epEngine->handle(['token' => $WTOKEN, 'action' => 'apply.revert', 'params' => ['apply_id' => 'ep-4']])['ok'] ?? null, true);
check('apply.revert takes the first set back', $epEngine->handle(['token' => $WTOKEN, 'action' => 'apply.revert', 'params' => ['apply_id' => 'ep-2']])['ok'] ?? null, true);
check('after both reverts the params are byte for byte what the site held', $epHeld(), $epParams);

// On a site bound to a quickstart contract the set lands the same way, and the contract reads no drift: an extension's
// params are no row of the contract.
$epRoot = sys_get_temp_dir() . '/cowork-extension-params-' . bin2hex(random_bytes(6));
$epDir = $epRoot . '/lib/contracts/test-map/j6/1.0.0';
mkdir($epDir, 0777, true);
$epModule = ['title' => 'Made-up map', 'module' => 'mod_custom', 'position' => 'map', 'published' => '1', 'publish_up' => null, 'publish_down' => null,
    'ordering' => '1', 'access' => '1', 'showtitle' => '0', 'language' => '*', 'client_id' => '0', 'content' => "{jamap mode='normal' zoom='10' }{/jamap}", 'params' => '{}'];
$epStore = new TestContractStore();
foreach ([
    'manifest' => ['id' => 'test-map/j6/1.0.0'],
    'content-map' => ['entities' => [['key' => 'map', 'kind' => 'module', 'sourceId' => 10, 'identity' => ['title' => 'Made-up map']]], 'slots' => [], 'pages' => []],
    'presentation-lock' => ['entities' => ['map' => $epModule], 'assignments' => [['moduleid' => 10, 'menuid' => 0]], 'fileRoots' => [], 'files' => [],
        'inventoryCounts' => ['module' => 1], 'access' => $epStore->acl],
] as $name => $body) file_put_contents($epDir . '/' . $name . '.json', json_encode($body));
$epBoundLog = new FakeApplyLog();
$epBoundWriter = new ContractTestWriter($epBoundLog, $epStore);
$epBoundWriter->store['module'][110] = ['id' => '110'] + $epModule;
$epBoundWriter->store['moduleAssignment'][110] = ['menuids' => '[0]'];
$epBoundWriter->store['extensionParams'][425] = ['extension_id' => '425'] + $epRow;
$epContract = new QuickstartContract($epBoundWriter, $epStore, $epRoot, $epDir);
$epContract->bind($epContract->inspect()['snapshot']);
$epBound = new Engine($WTOKEN, [], null, null, null, null, $epBoundWriter, null, $epBoundLog, null, null, null, $epContract);
$epBoundSet = $epBound->handle(['token' => $WTOKEN, 'action' => 'extension.params', 'params' => ['operation' => 'set', 'apply_id' => 'ep-bound',
    'set' => ['locations' => [['location' => '7 Made-up Avenue', 'latitude' => 25.2, 'longitude' => 55.27]]]] + $epMap]);
check('a bound site takes the set', $epBoundSet['ok'] ?? null, true);
check('a bound site holds the new place', json_decode($epBoundWriter->store['extensionParams'][425]['params'], true)['locations']['latitude'] ?? null, ['25.2']);
check('a bound site reads no drift after the set',
    array_column($epBound->handle(['token' => $WTOKEN, 'action' => 'content.contract', 'params' => ['operation' => 'inspect']])['warnings'] ?? [], 'message'), []);
