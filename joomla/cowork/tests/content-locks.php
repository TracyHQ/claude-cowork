<?php
// Loaded by run.php after contracts.php (reuses TestContractStore and ContractTestWriter).
// lockedBy: who holds a record open in the Joomla editor, served by content.read and honoured by
// every write — a live check-out refuses the write, a stale one (a closed browser) does not.
require_once __DIR__ . '/../lib/ContentProjection.php';
require_once __DIR__ . '/../lib/JoomlaLocks.php';

/* ------------------------------------------------------------ L1: live or stale, decided purely */
$lkNow = 1790000000; // 2026-09-21T13:33:20Z
$lkCheckouts = [
    'article:1' => ['checked_out' => '42', 'checked_out_time' => '2026-09-26 10:00:00'],
    'article:2' => ['checked_out' => '43', 'checked_out_time' => '2026-09-26 10:00:00'],
    'article:3' => ['checked_out' => '44', 'checked_out_time' => '2026-09-26 10:00:00'],
    'article:4' => ['checked_out' => '0', 'checked_out_time' => null],
    'article:5' => ['checked_out' => '45', 'checked_out_time' => '2026-09-26 10:00:00'],
    'article:6' => ['checked_out' => '46', 'checked_out_time' => '2026-09-26 10:00:00'],
    'article:7' => ['checked_out' => '47', 'checked_out_time' => '0000-00-00 00:00:00'],
    'menuItem:8' => ['checked_out' => null, 'checked_out_time' => null],
    'module:9' => ['checked_out' => '48', 'checked_out_time' => '2026-09-26 09:30:00'],
];
$lkSessions = [
    ['userid' => '42', 'client_id' => '1', 'time' => (string) ($lkNow - 60)],
    ['userid' => '43', 'client_id' => '1', 'time' => (string) ($lkNow - 15 * 60 - 1)],
    ['userid' => '44', 'client_id' => '1', 'time' => (string) ($lkNow - 15 * 60)],
    ['userid' => '45', 'client_id' => '0', 'time' => (string) $lkNow],
    ['userid' => '47', 'client_id' => '1', 'time' => (string) $lkNow],
    // One user, two admin sessions (two browsers): the freshest decides.
    ['userid' => '48', 'client_id' => '1', 'time' => (string) ($lkNow - 3600)],
    ['userid' => '48', 'client_id' => '1', 'time' => (string) ($lkNow - 30)],
];
$lkNames = ['42' => 'Jane Admin', '43' => 'Old Tab', '44' => 'Edge Case', '45' => 'Site Visitor', '48' => 'Two Browsers'];
$lkLive = JoomlaLocks::live($lkCheckouts, $lkSessions, $lkNames, $lkNow, 15);
check('locks: only live check-outs are locks', array_keys($lkLive), ['article:1', 'article:3', 'article:7', 'module:9']);
check('locks: a live check-out names who and since when', $lkLive['article:1'],
    ['kind' => 'admin-user', 'name' => 'Jane Admin', 'since' => '2026-09-26T10:00:00Z', 'until' => null]);
checkTrue('locks: an admin session older than the lifetime is a closed browser, not a lock', !isset($lkLive['article:2']));
checkTrue('locks: a session exactly one lifetime old is still live', isset($lkLive['article:3']));
checkTrue('locks: no check-out (0 or NULL) is no lock', !isset($lkLive['article:4']) && !isset($lkLive['menuItem:8']));
checkTrue('locks: a front-end session is not an editor', !isset($lkLive['article:5']));
checkTrue('locks: a check-out whose user has no session at all is stale', !isset($lkLive['article:6']));
check('locks: a user with no name and no check-out time stays a lock, with nulls', $lkLive['article:7'],
    ['kind' => 'admin-user', 'name' => null, 'since' => null, 'until' => null]);
check('locks: the freshest of a user\'s sessions decides', $lkLive['module:9']['name'], 'Two Browsers');
check('locks: a longer lifetime revives an older session', array_keys(JoomlaLocks::live($lkCheckouts, $lkSessions, $lkNames, $lkNow, 16)),
    ['article:1', 'article:2', 'article:3', 'article:7', 'module:9']);
check('locks: nobody checked anything out, nothing is locked', JoomlaLocks::live([], $lkSessions, $lkNames, $lkNow, 15), []);
// A check-out left behind days ago (a demo build, a closed tab) by a user who is signed in NOW is
// not an editor at work: measured on dev capiv2j, 26/09/2026 — Home checked out since 13/09 by the
// build's admin read as locked the moment that admin signed in.
$lkOld = ['menuItem:30' => ['checked_out' => '42', 'checked_out_time' => gmdate('Y-m-d H:i:s', $lkNow - 13 * 86400)],
    'menuItem:31' => ['checked_out' => '42', 'checked_out_time' => gmdate('Y-m-d H:i:s', $lkNow - JoomlaLocks::MAX_CHECKOUT_AGE + 60)]];
check('locks: a check-out older than MAX_CHECKOUT_AGE is left behind, not an editor at work',
    array_keys(JoomlaLocks::live($lkOld, $lkSessions, $lkNames, $lkNow, 15)), ['menuItem:31']);
check('locks: the refusal says who, since when, and what to do',
    JoomlaLocks::message('About', $lkLive['article:1']),
    '"About" is open in the Joomla editor by Jane Admin since 2026-09-26T10:00:00Z: ask them to save and close it, then try again.');
check('locks: without a name or a time the sentence still reads',
    JoomlaLocks::message(null, $lkLive['article:7']),
    'This item is open in the Joomla editor by another administrator: ask them to save and close it, then try again.');
check('locks: SLOT_LOCKED_BY_USER is recoverable', ContractProblem::severityOf('SLOT_LOCKED_BY_USER'), 'recoverable');

/* ------------------------------------------------- the fixture: a home, an about page, a footer */
$lkDir = sys_get_temp_dir() . '/cowork-locks-' . bin2hex(random_bytes(6));
mkdir($lkDir);
$lkModule = fn(string $title, string $content, string $ordering) => ['title' => $title, 'module' => 'mod_custom', 'position' => 'masthead', 'published' => '1',
    'publish_up' => null, 'publish_down' => null, 'ordering' => $ordering, 'access' => '1', 'showtitle' => '0', 'language' => '*', 'client_id' => '0',
    'content' => $content, 'params' => '{"style":"0"}'];
$lkHero = $lkModule('Home hero', '<h1>Demo title</h1>', '1');
$lkFoot = $lkModule('Footer', '<p>Footer text</p>', '2');
$lkMenu = fn(string $title, string $path) => ['title' => $title, 'alias' => $path, 'path' => $path, 'published' => '1', 'access' => '1', 'language' => '*'];
$lkProtected = ['hero' => $lkHero, 'foot' => $lkFoot, 'menu-20' => $lkMenu('Home', 'home'), 'menu-21' => $lkMenu('About', 'about')];
$lkSlots = [];
foreach (ContentSlots::htmlSlots($lkHero['content']) as $n => $s) $lkSlots[] = ['key' => 'hero.' . $n, 'entity' => 'hero', 'column' => 'content', 'maxCharacters' => 40] + $s;
foreach (ContentSlots::htmlSlots($lkFoot['content']) as $n => $s) $lkSlots[] = ['key' => 'foot.' . $n, 'entity' => 'foot', 'column' => 'content', 'maxCharacters' => 80] + $s;
$lkSlots[] = ['key' => 'menu-21.title', 'entity' => 'menu-21', 'column' => 'title', 'type' => 'text', 'sample' => 'About', 'maxCharacters' => 40];
$lkStore = new TestContractStore();
$lkData = ['manifest' => ['id' => 'test-locks/v1', 'quickstart' => ['release' => 'test', 'version' => '1.0.0']],
    'content-map' => ['entities' => [
        ['key' => 'hero', 'kind' => 'module', 'sourceId' => 10, 'identity' => ['title' => 'Home hero']],
        ['key' => 'foot', 'kind' => 'module', 'sourceId' => 11, 'identity' => ['title' => 'Footer']],
        ['key' => 'menu-20', 'kind' => 'menuItem', 'sourceId' => 20, 'identity' => ['path' => 'home']],
        ['key' => 'menu-21', 'kind' => 'menuItem', 'sourceId' => 21, 'identity' => ['path' => 'about']],
    ], 'slots' => $lkSlots, 'pages' => []],
    'presentation-lock' => ['entities' => $lkProtected, 'assignments' => [['moduleid' => 10, 'menuid' => 20], ['moduleid' => 11, 'menuid' => 0]],
        'fileRoots' => [], 'files' => [], 'inventoryCounts' => ['module' => 2, 'menuItem' => 2], 'access' => $lkStore->acl]];
foreach ($lkData as $name => $body) file_put_contents($lkDir . '/' . $name . '.json', json_encode($body));
$lkLog = new FakeApplyLog();
$lkWriter = new ContractTestWriter($lkLog, $lkStore);
$lkWriter->store['module'][110] = ['id' => '110'] + $lkHero;
$lkWriter->store['module'][111] = ['id' => '111'] + $lkFoot;
$lkWriter->store['menuItem'][120] = ['id' => '120', 'parent_id' => '1', 'home' => '1', 'client_id' => '0'] + $lkProtected['menu-20'];
$lkWriter->store['menuItem'][121] = ['id' => '121', 'parent_id' => '1', 'home' => '0', 'client_id' => '0'] + $lkProtected['menu-21'];
$lkWriter->store['moduleAssignment'][110] = ['menuids' => '[120]'];
$lkWriter->store['moduleAssignment'][111] = ['menuids' => '[0]'];
$lkContract = new QuickstartContract($lkWriter, $lkStore, $lkDir, $lkDir);
$lkContract->bind($lkContract->inspect()['snapshot']);
$lkTables = function () use ($lkWriter): array {
    $assign = [];
    foreach ($lkWriter->store['moduleAssignment'] as $module => $row)
        foreach (json_decode($row['menuids'], true) as $menu) $assign[] = ['moduleid' => (string) $module, 'menuid' => (string) $menu];
    return ['menu' => array_values($lkWriter->store['menuItem']), 'modules' => array_values($lkWriter->store['module']), 'modules_menu' => $assign,
        'categories' => [], 'viewlevels' => [['id' => '1', 'rules' => '[1]']], 'associations' => [],
        'claudecowork_content_identity' => [['kind' => 'page', 'native_id' => '120', 'uid' => 'u-120'], ['kind' => 'page', 'native_id' => '121', 'uid' => 'u-121'],
            ['kind' => 'shared', 'native_id' => '110', 'uid' => 'u-110'], ['kind' => 'shared', 'native_id' => '111', 'uid' => 'u-111']]];
};
$lkProject = fn() => ContentProjection::build($lkTables(), $lkContract->readMapping(), 'site-secret', 'https://fixture.invalid/', 1000000, [$lkContract, 'slotValue']);
$lkOracle = function () use ($lkProject): array { $p = $lkProject(); return ['revisions' => $p['revisions'], 'owners' => $p['owners']]; };
$lkId = ContentProjection::opaque('site-secret');
$lkHome = $lkId('content', 'u-120'); $lkAbout = $lkId('content', 'u-121'); $lkShared = $lkId('content', 'u-111');
// The site's check-outs right now, as the host would answer them: "kind:id" => lockedBy. `$lkAsked` records each question.
$lkHeld = []; $lkAsked = [];
$lkJane = ['kind' => 'admin-user', 'name' => 'Jane Admin', 'since' => '2026-09-26T10:00:00Z', 'until' => null];
$lkLockOf = function (array $targets) use (&$lkHeld, &$lkAsked): array {
    $lkAsked[] = $targets;
    $out = [];
    foreach ($targets as [$kind, $id]) if (isset($lkHeld[$kind . ':' . $id])) $out[$kind . ':' . $id] = $lkHeld[$kind . ':' . $id];
    return $out;
};

/* ------------------------------------------------------------ L2: content.read carries lockedBy */
$lk0 = $lkProject();
check('rows: each content names the native rows it is read from, its own first',
    [$lk0['rows'][$lkHome], $lk0['rows'][$lkAbout], $lk0['rows'][$lkShared]],
    [[['menuItem', 120], ['module', 110]], [['menuItem', 121]], [['module', 111]]]);
// Exactly what JoomlaContentReader::read() does after the revisions.
$lkServed = function (array $p) use ($lkLockOf): array {
    $contents = $p['contents'];
    foreach ($contents as $id => &$content) $content['revision'] = $p['revisions'][$id];
    unset($content);
    return ContentProjection::locks($contents, $p['rows'], $lkLockOf);
};
$lkHeld = ['menuItem:121' => $lkJane]; $lkAsked = [];
$lkRead = $lkServed($lk0);
check('read: the page whose menu item is open carries lockedBy', $lkRead[$lkAbout]['lockedBy'], $lkJane);
check('read: every other content says lockedBy null', [$lkRead[$lkHome]['lockedBy'], $lkRead[$lkShared]['lockedBy']], [null, null]);
check('read: the host is asked once, for every row', $lkAsked, [[['menuItem', 120], ['module', 110], ['menuItem', 121], ['module', 111]]]);
check('read: lockedBy moves no revision', array_column($lkRead, 'revision', 'id'), $lk0['revisions']);
check('read: and the door projects the same revisions with a lock held', $lkProject()['revisions'], $lk0['revisions']);
$lkHeld = ['module:110' => $lkJane];
check('read: a section module inlined into a page locks that page (its slots are written there)', $lkServed($lk0)[$lkHome]['lockedBy'], $lkJane);
$lkHeld = [];
check('read: nobody editing, nobody named', array_unique(array_column($lkServed($lk0), 'lockedBy')), [null]);

/* ---------------------------------------------- L3: a contract apply never overwrites an open record */
$lkEngine = (new Engine($WTOKEN, [], null, null, null, null, $lkWriter, null, $lkLog, null, null, null, $lkContract))->contentRevisions($lkOracle)->locks($lkLockOf);
$lkApply = fn(string $id, array $params) => $lkEngine->handle(['token' => $WTOKEN, 'action' => 'content.contract',
    'params' => ['operation' => 'apply', 'apply_id' => 'contract-' . $id, 'request_id' => $id] + $params]);
$lkHeld = ['menuItem:121' => $lkJane];
$lkBefore = [$lkWriter->store, $lkLog->log, $lkStore->binding];
$lkRefused = $lkApply('locked', ['expected_revision' => $lkContract->inspect()['revision'], 'changes' => ['hero.0' => 'New title', 'menu-21.title' => 'About us']]);
$lkSentence = '"About" is open in the Joomla editor by Jane Admin since 2026-09-26T10:00:00Z: ask them to save and close it, then try again.';
check('apply: an entity open in the editor refuses the whole apply', [$lkRefused['ok'], $lkRefused['error'], $lkRefused['message']], [false, 'contract_failed', $lkSentence]);
check('apply: the refusal names the slot, the content and who holds it', $lkRefused['errors'] ?? null, [
    ['code' => 'SLOT_LOCKED_BY_USER', 'message' => $lkSentence, 'field' => ['slotKey' => 'menu-21.title', 'contentId' => $lkAbout], 'severity' => 'recoverable', 'lockedBy' => $lkJane]]);
check('apply: nothing was written, not even the unlocked hero', [$lkWriter->store, $lkLog->log, $lkStore->binding], $lkBefore);
$lkFree = $lkApply('elsewhere', ['expected_revision' => $lkContract->inspect()['revision'], 'changes' => ['hero.0' => 'New title']]);
check('apply: a lock on another entity does not stop this one', [$lkFree['ok'], $lkWriter->store['module'][110]['content']], [true, '<h1>New title</h1>']);
$lkHeld = [];
$lkAfter = $lkApply('closed', ['expected_revision' => $lkContract->inspect()['revision'], 'changes' => ['menu-21.title' => 'About us']]);
check('apply: once the editor closes, the same change goes through', [$lkAfter['ok'], $lkWriter->store['menuItem'][121]['title']], [true, 'About us']);
$lkHeld = ['menuItem:121' => $lkJane];
$lkReverted = $lkEngine->handle(['token' => $WTOKEN, 'action' => 'apply.revert', 'params' => ['apply_id' => 'contract-closed']]);
check('revert: taking back a change to an open record is refused too', [$lkReverted['ok'], $lkReverted['errors'][0]['code'] ?? null, $lkReverted['errors'][0]['lockedBy'] ?? null],
    [false, 'SLOT_LOCKED_BY_USER', $lkJane]);
check('revert: and wrote nothing', $lkWriter->store['menuItem'][121]['title'], 'About us');
$lkHeld = [];

/* ------------------------------------------------ L4: the open surface refuses a live lock as well */
$lkOpenLog = new FakeApplyLog();
$lkOpenWriter = new TransactionWriter($lkOpenLog);
$lkOpenWriter->store['article'][5] = ['title' => 'Opening hours', 'introtext' => 'Old'];
$lkOpenWriter->store['article'][6] = ['title' => 'Prices', 'introtext' => 'Old'];
$lkOpen = (new Engine($WTOKEN, [], null, null, null, null, $lkOpenWriter, null, $lkOpenLog))->locks($lkLockOf);
$lkHeld = ['article:5' => $lkJane];
$lkU = $lkOpen->handle(['token' => $WTOKEN, 'action' => 'content.update', 'params' => ['apply_id' => 'open-1', 'kind' => 'article', 'id' => 5, 'fields' => ['introtext' => 'New']]]);
$lkOpenSentence = '"Opening hours" is open in the Joomla editor by Jane Admin since 2026-09-26T10:00:00Z: ask them to save and close it, then try again.';
check('update: a record open in the editor is refused, in the open surface\'s own shape', $lkU,
    ['ok' => false, 'error' => 'locked', 'message' => $lkOpenSentence, 'code' => 'SLOT_LOCKED_BY_USER', 'lockedBy' => $lkJane,
        'locked' => [['kind' => 'article', 'id' => 5, 'lockedBy' => $lkJane]]]);
check('update: and nothing moved', [$lkOpenWriter->store['article'][5]['introtext'], $lkOpenLog->log], ['Old', []]);
$lkU2 = $lkOpen->handle(['token' => $WTOKEN, 'action' => 'content.update', 'params' => ['apply_id' => 'open-2', 'kind' => 'article', 'id' => 6, 'fields' => ['introtext' => 'New']]]);
check('update: a record nobody has open is written', [$lkU2['ok'], $lkOpenWriter->store['article'][6]['introtext']], [true, 'New']);
$lkD = $lkOpen->handle(['token' => $WTOKEN, 'action' => 'content.delete', 'params' => ['apply_id' => 'open-3', 'kind' => 'article', 'id' => 5]]);
check('delete: an open record is not trashed', [$lkD['ok'], $lkD['code'] ?? null, $lkOpenWriter->store['article'][5]['state'] ?? null], [false, 'SLOT_LOCKED_BY_USER', null]);
$lkBatchBefore = $lkOpenWriter->store;
$lkB = $lkOpen->handle(['token' => $WTOKEN, 'action' => 'content.batch', 'params' => ['apply_id' => 'open-4', 'request_id' => 'b1', 'operations' => [
    ['kind' => 'article', 'id' => 6, 'fields' => ['introtext' => 'Newer']],
    ['kind' => 'article', 'id' => 5, 'fields' => ['introtext' => 'Newer']],
]]]);
check('batch: one open record refuses the whole batch', [$lkB['ok'], $lkB['error'], $lkB['code'] ?? null, $lkB['locked'] ?? null],
    [false, 'locked', 'SLOT_LOCKED_BY_USER', [['kind' => 'article', 'id' => 5, 'lockedBy' => $lkJane]]]);
check('batch: and wrote nothing', $lkOpenWriter->store, $lkBatchBefore);
$lkAsked = [];
$lkNew = $lkOpen->handle(['token' => $WTOKEN, 'action' => 'content.update', 'params' => ['apply_id' => 'open-5', 'kind' => 'article', 'fields' => ['title' => 'Brand new']]]);
check('update: a new record has nobody editing it, so nobody is asked', [$lkNew['ok'], $lkAsked], [true, []]);
$lkHeld = ['article:6' => $lkJane];
$lkR = $lkOpen->handle(['token' => $WTOKEN, 'action' => 'apply.revert', 'params' => ['apply_id' => 'open-2']]);
check('revert: an open record is not rolled back under its editor', [$lkR['ok'], $lkR['code'] ?? null, $lkOpenWriter->store['article'][6]['introtext']], [false, 'SLOT_LOCKED_BY_USER', 'New']);
$lkHeld = [];
check('revert: once closed it rolls back', [$lkOpen->handle(['token' => $WTOKEN, 'action' => 'apply.revert', 'params' => ['apply_id' => 'open-2']])['ok'], $lkOpenWriter->store['article'][6]['introtext']], [true, 'Old']);
$lkNoLocks = new Engine($WTOKEN, [], null, null, null, null, $lkOpenWriter, null, $lkOpenLog);
check('update: a receiver with no lock lookup writes as before', $lkNoLocks->handle(['token' => $WTOKEN, 'action' => 'content.update', 'params' => ['apply_id' => 'open-6', 'kind' => 'article', 'id' => 5, 'fields' => ['introtext' => 'New']]])['ok'], true);

foreach (array_keys($lkData) as $name) unlink($lkDir . '/' . $name . '.json');
rmdir($lkDir);
