<?php
// Loaded by run.php: a sealed quickstart hides its own demo rows through the demo-trim extension,
// and the contract keeps holding the site to what it is afterwards — hidden, not merely unchecked.

$trimDir = sys_get_temp_dir() . '/cowork-demo-trim-' . bin2hex(random_bytes(6));
mkdir($trimDir);
$trimHero = ['title' => 'Home hero', 'module' => 'mod_custom', 'position' => 'masthead', 'published' => '1', 'access' => '1', 'language' => '*', 'client_id' => '0',
    'content' => '<h1>Demo title</h1>', 'params' => '{"style":"0"}'];
$trimRows = [
    'hero' => ['module', 10, 110, ['title' => 'Home hero'], $trimHero],
    'article-page' => ['article', 1, 201, ['alias' => 'about'], ['title' => 'About', 'alias' => 'about', 'state' => '1', 'access' => '1', 'language' => '*']],
    'article-post' => ['article', 2, 202, ['alias' => 'post'], ['title' => 'A demo post', 'alias' => 'post', 'state' => '1', 'access' => '1', 'language' => '*']],
    'article-old' => ['article', 3, 203, ['alias' => 'old'], ['title' => 'An older post', 'alias' => 'old', 'state' => '1', 'access' => '1', 'language' => '*']],
    'menu-blog' => ['menuItem', 30, 330, ['path' => 'blog'], ['title' => 'Blog', 'path' => 'blog', 'published' => '1', 'access' => '1', 'language' => '*']],
];
$trimEntities = []; $trimLock = [];
$trimStore = new TestContractStore();
$trimLog = new FakeApplyLog();
$trimWriter = new ContractTestWriter($trimLog, $trimStore);
foreach ($trimRows as $key => [$kind, $sourceId, $installed, $identity, $row]) {
    $trimEntities[] = ['key' => $key, 'kind' => $kind, 'sourceId' => $sourceId, 'identity' => $identity];
    $trimLock[$key] = $row;
    $trimWriter->store[$kind][$installed] = ['id' => (string) $installed] + $row;
}
$trimWriter->store['moduleAssignment'][110] = ['menuids' => '[0]'];
$trimSlots = [];
foreach (ContentSlots::htmlSlots($trimHero['content']) as $n => $s) $trimSlots[] = ['key' => 'hero.' . $n, 'entity' => 'hero', 'column' => 'content', 'maxCharacters' => 80, 'sample' => 'Demo title'] + $s;
$trimFiles = [
    'manifest' => ['id' => 'trim/v1'],
    'content-map' => ['entities' => $trimEntities, 'slots' => $trimSlots, 'pages' => []],
    'presentation-lock' => ['entities' => $trimLock, 'assignments' => [['moduleid' => 10, 'menuid' => 0]], 'fileRoots' => [], 'files' => [],
        'inventoryCounts' => ['module' => 1, 'article' => 3, 'menuItem' => 1], 'access' => $trimStore->acl],
];
foreach ($trimFiles as $name => $body) file_put_contents($trimDir . '/' . $name . '.json', json_encode($body));
$trimBase = '';
foreach (['manifest.json', 'content-map.json', 'presentation-lock.json'] as $name) $trimBase .= $name . ':' . hash_file('sha256', $trimDir . '/' . $name) . "\n";
$trimProfile = [
    'schemaVersion' => 'tracy-quickstart-demo-trim/v1', 'extensionVersion' => '1.0.0', 'contract' => 'trim/v1',
    'baseHash' => hash('sha256', $trimBase),
    'hide' => [
        ['key' => 'article-post', 'kind' => 'article', 'field' => 'state', 'from' => '1', 'to' => '0', 'reason' => 'demo blog post'],
        ['key' => 'article-old', 'kind' => 'article', 'field' => 'state', 'from' => '1', 'to' => '0', 'reason' => 'demo blog post'],
        ['key' => 'menu-blog', 'kind' => 'menuItem', 'field' => 'published', 'from' => '1', 'to' => '0', 'reason' => 'lists only demo posts'],
    ],
];

function trimRefuses(string $label, array $profile, string $dir): void {
    $raw = json_encode($profile);
    try { new DemoTrimProfile($profile, json_decode(file_get_contents($dir . '/content-map.json'), true), json_decode(file_get_contents($dir . '/presentation-lock.json'), true), $dir, $raw); check($label, 'accepted', 'refused'); }
    catch (RuntimeException $error) { check($label, 'refused', 'refused'); }
}
$wrongBase = $trimProfile; $wrongBase['baseHash'] = str_repeat('0', 64);
trimRefuses('a demo-trim profile pinned to another contract is refused', $wrongBase, $trimDir);
$wrongFrom = $trimProfile; $wrongFrom['hide'][0]['from'] = '0';
trimRefuses('a demo-trim row whose "from" is not what the contract locked is refused', $wrongFrom, $trimDir);
$wrongField = $trimProfile; $wrongField['hide'][0]['field'] = 'title';
trimRefuses('a demo-trim row may only change a visibility column', $wrongField, $trimDir);
$wrongKind = $trimProfile; $wrongKind['hide'][2]['kind'] = 'article';
trimRefuses('a demo-trim row must name the kind the contract gives its entity', $wrongKind, $trimDir);
$unknown = $trimProfile; $unknown['hide'][] = ['key' => 'article-nope', 'kind' => 'article', 'field' => 'state', 'from' => '1', 'to' => '0', 'reason' => 'x'];
trimRefuses('a demo-trim row naming an entity outside the contract is refused', $unknown, $trimDir);

$trimContract = new QuickstartContract($trimWriter, $trimStore, $trimDir, $trimDir);
$trimEngine = new Engine($WTOKEN, [], null, null, null, null, $trimWriter, null, $trimLog, null, null, null, $trimContract);
$trimCall = fn(array $params) => $trimEngine->handle(['token' => $WTOKEN, 'action' => 'content.contract', 'params' => $params]);

check('a contract without the file offers no demo trim', $trimCall(['operation' => 'demoTrim.apply', 'apply_id' => 'dtrim-a', 'request_id' => 'r1'])['error'], 'unsupported');
file_put_contents($trimDir . '/demo-trim-map.json', json_encode($trimProfile));
$trimContract = new QuickstartContract($trimWriter, $trimStore, $trimDir, $trimDir);
$trimEngine = new Engine($WTOKEN, [], null, null, null, null, $trimWriter, null, $trimLog, null, null, null, $trimContract);
// An arrow function captures by value, so the call has to be rebuilt around the new engine.
$trimCall = fn(array $params) => $trimEngine->handle(['token' => $WTOKEN, 'action' => 'content.contract', 'params' => $params]);

$plan = $trimCall(['operation' => 'demoTrim.plan']);
check('plan says how much a trim would hide, before anything is written', [$plan['ok'], $plan['hides'], $plan['status']], [true, ['article' => 2, 'menuItem' => 1], 'none']);
check('an apply without the dtrim- prefix is refused by name', $trimCall(['operation' => 'demoTrim.apply', 'apply_id' => 'contract-x', 'request_id' => 'r1'])['message'], 'apply_id must start with "dtrim-"');
check('an apply without a request_id is refused', $trimCall(['operation' => 'demoTrim.apply', 'apply_id' => 'dtrim-a'])['error'], 'bad_params');

$trimStore->binding = ['multilingual' => ['languages' => ['fr-FR' => ['ids' => []]]]];
check('a site that already has a translation is not trimmed', $trimCall(['operation' => 'demoTrim.apply', 'apply_id' => 'dtrim-a', 'request_id' => 'r1'])['message'],
    'This site already has a second language; hiding its demo rows would leave the translated copies showing. Nothing has been written.');
$trimStore->binding = null;

// A crash in the middle of the first batch rolls back the rows, but not the record that a trim began.
$trimWriter->failOn = ['article', 203];
$crashed = $trimCall(['operation' => 'demoTrim.apply', 'apply_id' => 'dtrim-a', 'request_id' => 'r1']);
check('a batch that fails reports it', $crashed['ok'], false);
check('the failed batch wrote no row', [$trimWriter->store['article'][202]['state'], $trimWriter->store['menuItem'][330]['published']], ['1', '1']);
check('an unbound site is bound first, and the begun trim is on record', $trimStore->binding['demoTrim']['status'] ?? null, 'applying');
$trimWriter->failOn = null;
// Joomla's nested tables commit implicitly, so a batch can leave a row changed behind its own rollback.
$trimWriter->store['menuItem'][330]['published'] = '0';
check('mid-trim, a row already at its target is not drift', $trimContract->inspect()['contract'], 'trim/v1');
check('a different request cannot take over a trim in flight', $trimCall(['operation' => 'demoTrim.apply', 'apply_id' => 'dtrim-b', 'request_id' => 'r2'])['error'], 'conflict');

$done = $trimCall(['operation' => 'demoTrim.apply', 'apply_id' => 'dtrim-a', 'request_id' => 'r1']);
check('the retried trim completes', [$done['ok'], $done['status'], $done['remaining']], [true, 'completed', 0]);
check('every listed row is hidden, and nothing else', [
    $trimWriter->store['article'][201]['state'], $trimWriter->store['article'][202]['state'], $trimWriter->store['article'][203]['state'], $trimWriter->store['menuItem'][330]['published'],
], ['1', '0', '0', '0']);
check('the baseline now expects the hidden state', [$trimStore->binding['presentation']['article-post']['state'], $trimStore->binding['demoTrim']['status']], ['0', 'complete']);
check('the trimmed site inspects clean', $trimContract->inspect()['contract'], 'trim/v1');
check('asking again is a no-op, not a second trim', [$trimCall(['operation' => 'demoTrim.apply', 'apply_id' => 'dtrim-c', 'request_id' => 'r3'])['status']], ['completed']);

$trimWriter->store['article'][202]['state'] = '1';
contractRejects('a hidden row that reappears is drift', fn() => $trimContract->inspect());
$trimWriter->store['article'][202]['state'] = '0';
$trimWriter->store['article'][201]['state'] = '0';
contractRejects('a row the profile does not list is still held to its locked state', fn() => $trimContract->inspect());
$trimWriter->store['article'][201]['state'] = '1';

$copy = $trimCall(['operation' => 'apply', 'apply_id' => 'contract-t1', 'request_id' => 't1', 'expected_revision' => $trimContract->inspect()['revision'], 'changes' => ['hero.0' => 'Customer title']]);
check('an ordinary content apply still lands on a trimmed site', $copy['ok'], true);
check('and it does not bring the demo rows back', [$trimWriter->store['article'][202]['state'], $trimStore->binding['demoTrim']['status']], ['0', 'complete']);
check('the generic revert does not take a trim apart', $trimEngine->handle(['token' => $WTOKEN, 'action' => 'apply.revert', 'params' => ['apply_id' => 'dtrim-a']])['error'], 'content_only');

$back = $trimCall(['operation' => 'demoTrim.revert', 'apply_id' => 'dtrim-undo', 'request_id' => 'u1']);
check('revert brings every hidden row back', [$back['ok'], $back['status'], $trimWriter->store['article'][202]['state'], $trimWriter->store['article'][203]['state'], $trimWriter->store['menuItem'][330]['published']], [true, 'reverted', '1', '1', '1']);
check('and leaves no trim on record', [isset($trimStore->binding['demoTrim']), $trimStore->binding['presentation']['article-post']['state']], [false, '1']);
check('the customer copy survives the revert', $trimWriter->store['module'][110]['content'], '<h1>Customer title</h1>');
check('the reverted site inspects clean', $trimContract->inspect()['contract'], 'trim/v1');
check('revert with nothing trimmed says so', $trimCall(['operation' => 'demoTrim.revert', 'apply_id' => 'dtrim-undo2', 'request_id' => 'u2'])['error'], 'contract_failed');
