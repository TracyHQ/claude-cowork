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

// 🔒 VISIBILITY ONLY, AND NOTHING JOOMLA DOES AROUND IT. From here on the double behaves like the
// real site: storing an article or a module through Table mints its asset. A trim that went that
// way would change the ACL the contract holds and fail every read after it.
$trimWriter->tableAssets = true;
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
contractDrifts('a hidden row that reappears is reported as drift', $trimContract, fn() => $trimContract->inspect());
$trimWriter->store['article'][202]['state'] = '0';
$trimWriter->store['article'][201]['state'] = '0';
contractDrifts('a row the profile does not list is still compared to its locked state', $trimContract, fn() => $trimContract->inspect());
$trimWriter->store['article'][201]['state'] = '1';

$copy = $trimCall(['operation' => 'apply', 'apply_id' => 'contract-t1', 'request_id' => 't1', 'expected_revision' => $trimContract->inspect()['revision'], 'changes' => ['hero.0' => 'Customer title']]);
check('an ordinary content apply still lands on a trimmed site', $copy['ok'], true);
check('and it does not bring the demo rows back', [$trimWriter->store['article'][202]['state'], $trimStore->binding['demoTrim']['status']], ['0', 'complete']);
check('the generic revert does not take a trim apart', $trimEngine->handle(['token' => $WTOKEN, 'action' => 'apply.revert', 'params' => ['apply_id' => 'dtrim-a']])['error'], 'bad_params');

$back = $trimCall(['operation' => 'demoTrim.revert', 'apply_id' => 'dtrim-undo', 'request_id' => 'u1']);
check('revert brings every hidden row back', [$back['ok'], $back['status'], $trimWriter->store['article'][202]['state'], $trimWriter->store['article'][203]['state'], $trimWriter->store['menuItem'][330]['published']], [true, 'reverted', '1', '1', '1']);
check('and keeps the trim on record, marked reverted', [$trimStore->binding['demoTrim']['status'] ?? null, $trimStore->binding['presentation']['article-post']['state']], ['reverted', '1']);
check('with its history: the apply and the revert that took it back', [$trimStore->binding['demoTrim']['applyId'] ?? null, $trimStore->binding['demoTrim']['requestId'] ?? null, $trimStore->binding['demoTrim']['revert']['requestId'] ?? null], ['dtrim-a', 'r1', 'u1']);
check('plan says the trim was reverted, with nothing left to hide back', [$trimCall(['operation' => 'demoTrim.plan'])['status'], $trimContract->inspect()['demoTrim']], ['reverted', null]);
$trimWriter->tableAssets = false;
check('the customer copy survives the revert', $trimWriter->store['module'][110]['content'], '<h1>Customer title</h1>');
check('the reverted site inspects clean', $trimContract->inspect()['contract'], 'trim/v1');
check('revert with nothing trimmed says so', $trimCall(['operation' => 'demoTrim.revert', 'apply_id' => 'dtrim-undo2', 'request_id' => 'u2'])['error'], 'contract_failed');
// 🔒 A LOST ANSWER IS ANSWERED AGAIN, NOT REFUSED: the same revert retried is already reverted and writes nothing.
$trimRows0 = $trimWriter->store;
$again = $trimCall(['operation' => 'demoTrim.revert', 'apply_id' => 'dtrim-undo', 'request_id' => 'u1']);
check('the same revert after a lost answer is already reverted', [$again['ok'] ?? null, $again['status'] ?? $again, $again['moved'] ?? null, $again['remaining'] ?? null, $again['alreadyReverted'] ?? null], [true, 'reverted', 0, 0, true]);
check('and writes nothing', $trimWriter->store, $trimRows0);
check('the reverted apply request cannot trim again', $trimCall(['operation' => 'demoTrim.apply', 'apply_id' => 'dtrim-a', 'request_id' => 'r1'])['error'] ?? null, 'conflict');
check('and nothing was hidden by it', $trimWriter->store, $trimRows0);

// 🔒 THE BASELINE MOVES ONLY WHERE THE TRIM WROTE. A difference the customer made — before the trim, during
// it or after it — is still reported after the apply and after the revert; a listed row the customer
// already hid is not the trim's, so it is still drift, and the revert leaves it hidden.
$trimSite = function () use ($trimRows, $trimDir, $WTOKEN) {
    $store = new TestContractStore();
    $log = new FakeApplyLog();
    $writer = new ContractTestWriter($log, $store);
    foreach ($trimRows as [$kind, , $installed, , $row]) $writer->store[$kind][$installed] = ['id' => (string) $installed] + $row;
    $writer->store['moduleAssignment'][110] = ['menuids' => '[0]'];
    $contract = new QuickstartContract($writer, $store, $trimDir, $trimDir);
    $engine = new Engine($WTOKEN, [], null, null, null, null, $writer, null, $log, null, null, null, $contract);
    return [$store, $writer, $contract, fn (array $params) => $engine->handle(['token' => $WTOKEN, 'action' => 'content.contract', 'params' => $params])];
};
$trimDrifted = function (QuickstartContract $contract): array {
    try { $contract->inspect(); } catch (Throwable $e) { return ['refused: ' . $e->getMessage()]; }
    $keys = [];
    foreach ($contract->driftWarnings() as $warning) if (preg_match('/drift: (\S+) /', $warning, $m)) $keys[] = $m[1];
    sort($keys);
    return array_values(array_unique($keys));
};
[$dStore, $dw, $dContract, $dCall] = $trimSite();
check('a bound site', $dCall(['operation' => 'bind'])['ok'], true);
$dw->store['article'][201]['access'] = '2';
$dw->store['article'][203]['state'] = '0';
check('the drift is reported before the trim', $trimDrifted($dContract), ['article-old', 'article-page']);
$dApplied = $dCall(['operation' => 'demoTrim.apply', 'apply_id' => 'dtrim-d', 'request_id' => 'd1']);
check('the trim still runs, and writes only the rows still where the lock has them', [$dApplied['ok'], $dApplied['moved'] ?? $dApplied, $dw->store['article'][202]['state'], $dw->store['menuItem'][330]['published']], [true, 2, '0', '0']);
check('and the drift it did not make is still reported after it', $trimDrifted($dContract), ['article-old', 'article-page']);
check('the baseline holds what the trim wrote, and only that', [$dStore->binding['presentation']['article-post']['state'], $dStore->binding['presentation']['article-old']['state'], $dStore->binding['presentation']['article-page']['access']], ['0', '1', '1']);
$dw->store['article'][202]['access'] = '2';
check('a change after the trim is drift too', $trimDrifted($dContract), ['article-old', 'article-page', 'article-post']);
$dBack = $dCall(['operation' => 'demoTrim.revert', 'apply_id' => 'dtrim-du', 'request_id' => 'd2']);
check('the revert still runs, and shows only what the trim hid', [$dBack['ok'], $dBack['moved'] ?? $dBack, $dw->store['article'][202]['state'], $dw->store['article'][203]['state'], $dw->store['menuItem'][330]['published']], [true, 2, '1', '0', '1']);
check('and every change the customer made is still reported after it', $trimDrifted($dContract), ['article-old', 'article-page', 'article-post']);

// 🔒 AN OLD SITE: a record written before the trim kept its rows (no `rows`, no `revert`). Every listed row
// is the trim's, as it was then: it completes, reports, and reverts as before.
[$oStore, $ow, $oContract, $oCall] = $trimSite();
$ow->failOn = ['article', 203];
check('an old site: a trim in flight', $oCall(['operation' => 'demoTrim.apply', 'apply_id' => 'dtrim-o', 'request_id' => 'o1'])['ok'], false);
$ow->failOn = null;
unset($oStore->binding['demoTrim']['rows']);
$ow->store['article'][203]['state'] = '0';
$oDone = $oCall(['operation' => 'demoTrim.apply', 'apply_id' => 'dtrim-o', 'request_id' => 'o1']);
check('an old record in flight completes under its own request', [$oDone['status'] ?? $oDone, $ow->store['article'][202]['state'], $ow->store['menuItem'][330]['published']], ['completed', '0', '0']);
check('every listed row is its own, as before', [$oStore->binding['presentation']['article-old']['state'], $trimDrifted($oContract)], ['0', []]);
check('its plan says it is complete', [$oCall(['operation' => 'demoTrim.plan'])['status'], $oCall(['operation' => 'demoTrim.plan'])['remaining']], ['complete', 0]);
// A revert begun by the old receiver: the record names the revert's own request, and no `revert`.
$oStore->binding['demoTrim'] = ['status' => 'reverting', 'applyId' => 'dtrim-ou', 'requestId' => 'ou1'] + $oStore->binding['demoTrim'];
check('an old revert in flight is not taken over by another request', $oCall(['operation' => 'demoTrim.revert', 'apply_id' => 'dtrim-ox', 'request_id' => 'ox1'])['error'] ?? null, 'conflict');
$oBack = $oCall(['operation' => 'demoTrim.revert', 'apply_id' => 'dtrim-ou', 'request_id' => 'ou1']);
check('and completes under its own request', [$oBack['status'] ?? $oBack, $ow->store['article'][202]['state'], $ow->store['article'][203]['state'], $ow->store['menuItem'][330]['published'], $trimDrifted($oContract)], ['reverted', '1', '1', '1', []]);
check('answering its retry', $oCall(['operation' => 'demoTrim.revert', 'apply_id' => 'dtrim-ou', 'request_id' => 'ou1'])['alreadyReverted'] ?? null, true);
$oAgain = $oCall(['operation' => 'demoTrim.apply', 'apply_id' => 'dtrim-o2', 'request_id' => 'o2']);
check('a new request trims a reverted site again', [$oAgain['status'] ?? $oAgain, $ow->store['article'][202]['state'], $oStore->binding['demoTrim']['applyId']], ['completed', '0', 'dtrim-o2']);

