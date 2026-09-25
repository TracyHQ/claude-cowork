<?php
// Loaded by run.php after contracts.php (reuses TestContractStore and ContractTestWriter).
// Per-content revisions (content.read) and the apply that trusts them instead of an inspect.
require_once __DIR__ . '/../lib/ContentProjection.php';

$rvDir = sys_get_temp_dir() . '/cowork-revisions-' . bin2hex(random_bytes(6));
mkdir($rvDir); mkdir($rvDir . '/assets');
file_put_contents($rvDir . '/assets/demo.css', '.hero { color: red }');
$rvModule = fn(string $title, string $content, string $ordering) => ['title' => $title, 'module' => 'mod_custom', 'position' => 'masthead', 'published' => '1',
    'publish_up' => null, 'publish_down' => null, 'ordering' => $ordering, 'access' => '1', 'showtitle' => '0', 'language' => '*', 'client_id' => '0',
    'content' => $content, 'params' => '{"style":"0"}'];
$rvHero = $rvModule('Home hero', '<h1>Demo title</h1><a href="/start">Start</a>', '1');
$rvFoot = $rvModule('Footer', '<p>Footer text</p>', '2');
$rvMenu = fn(string $title, string $path) => ['title' => $title, 'alias' => $path, 'path' => $path, 'published' => '1', 'access' => '1', 'language' => '*'];
$rvEntities = [
    ['key' => 'hero', 'kind' => 'module', 'sourceId' => 10, 'identity' => ['title' => 'Home hero']],
    ['key' => 'foot', 'kind' => 'module', 'sourceId' => 11, 'identity' => ['title' => 'Footer']],
    ['key' => 'menu-20', 'kind' => 'menuItem', 'sourceId' => 20, 'identity' => ['path' => 'home']],
    ['key' => 'menu-21', 'kind' => 'menuItem', 'sourceId' => 21, 'identity' => ['path' => 'about']],
];
$rvProtected = ['hero' => $rvHero, 'foot' => $rvFoot, 'menu-20' => $rvMenu('Home', 'home'), 'menu-21' => $rvMenu('About', 'about')];
$rvSlots = [];
foreach (ContentSlots::htmlSlots($rvHero['content']) as $n => $s) $rvSlots[] = ['key' => 'hero.' . $n, 'entity' => 'hero', 'column' => 'content', 'maxCharacters' => 20] + $s;
foreach (ContentSlots::htmlSlots($rvFoot['content']) as $n => $s) $rvSlots[] = ['key' => 'foot.' . $n, 'entity' => 'foot', 'column' => 'content', 'maxCharacters' => 80, 'requiresEvidence' => true] + $s;
$rvSlots[] = ['key' => 'menu-21.title', 'entity' => 'menu-21', 'column' => 'title', 'type' => 'text', 'sample' => 'About', 'maxCharacters' => 40];
$rvStore = new TestContractStore();
$rvData = ['manifest' => ['id' => 'test-revisions/v1', 'quickstart' => ['release' => 'test', 'version' => '1.0.0']],
    'content-map' => ['entities' => $rvEntities, 'slots' => $rvSlots, 'pages' => []],
    'presentation-lock' => ['entities' => $rvProtected, 'assignments' => [['moduleid' => 10, 'menuid' => 20], ['moduleid' => 11, 'menuid' => 0]],
        'fileRoots' => ['assets'], 'files' => ['assets/demo.css' => hash_file('sha256', $rvDir . '/assets/demo.css')],
        'inventoryCounts' => ['module' => 2, 'menuItem' => 2], 'access' => $rvStore->acl]];
foreach ($rvData as $name => $body) file_put_contents($rvDir . '/' . $name . '.json', json_encode($body));

$rvLog = new FakeApplyLog();
$rvWriter = new ContractTestWriter($rvLog, $rvStore);
$rvWriter->store['module'][110] = ['id' => '110'] + $rvHero;
$rvWriter->store['module'][111] = ['id' => '111'] + $rvFoot;
$rvWriter->store['menuItem'][120] = ['id' => '120', 'parent_id' => '1', 'home' => '1', 'client_id' => '0'] + $rvProtected['menu-20'];
$rvWriter->store['menuItem'][121] = ['id' => '121', 'parent_id' => '1', 'home' => '0', 'client_id' => '0'] + $rvProtected['menu-21'];
$rvWriter->store['moduleAssignment'][110] = ['menuids' => '[120]'];
$rvWriter->store['moduleAssignment'][111] = ['menuids' => '[0]'];
$rvContract = new QuickstartContract($rvWriter, $rvStore, $rvDir, $rvDir);
$rvContract->bind($rvContract->inspect()['snapshot']);

// The CMS tables content.read reads, as the fake site holds them right now.
$rvTables = function () use ($rvWriter): array {
    $assign = [];
    foreach ($rvWriter->store['moduleAssignment'] as $module => $row)
        foreach (json_decode($row['menuids'], true) as $menu) $assign[] = ['moduleid' => (string)$module, 'menuid' => (string)$menu];
    return ['menu' => array_values($rvWriter->store['menuItem']), 'modules' => array_values($rvWriter->store['module']), 'modules_menu' => $assign,
        'categories' => [], 'viewlevels' => [['id' => '1', 'rules' => '[1]']], 'associations' => [],
        'claudecowork_content_identity' => [['kind' => 'page', 'native_id' => '120', 'uid' => 'u-120'], ['kind' => 'page', 'native_id' => '121', 'uid' => 'u-121'],
            ['kind' => 'shared', 'native_id' => '110', 'uid' => 'u-110'], ['kind' => 'shared', 'native_id' => '111', 'uid' => 'u-111']]];
};
// Exactly what JoomlaContentReader::revisions() does, with the tables handed in.
$rvProject = fn() => ContentProjection::build($rvTables(), $rvContract->readMapping(), 'site-secret', 'https://fixture.invalid/', 1000000, [$rvContract, 'slotValue']);
$rvOracle = function () use ($rvProject): array { $p = $rvProject(); return ['revisions' => $p['revisions'], 'owners' => $p['owners']]; };
$rvId = ContentProjection::opaque('site-secret');
$rvHome = $rvId('content', 'u-120'); $rvAbout = $rvId('content', 'u-121'); $rvShared = $rvId('content', 'u-111');

/* ---------------------------------------------------------------- W1: revision per content */
$rv0 = $rvProject();
check('projection: a module one page places is inlined; one on every page stays shared', array_keys($rv0['contents']), (function () use ($rvHome, $rvAbout, $rvShared) { $k = [$rvHome, $rvAbout, $rvShared]; sort($k); return $k; })());
check('projection: each slot is owned by the content it is read in',
    [$rv0['owners']['hero'], $rv0['owners']['menu-20'], $rv0['owners']['menu-21'], $rv0['owners']['foot']], [$rvHome, $rvHome, $rvAbout, $rvShared]);
check('projection: contents stay pending for the snapshot hash', array_unique(array_column($rv0['contents'], 'revision')), ['pending']);
check('projection: a revision is the content hashed with the contract', $rv0['revisions'][$rvAbout],
    hash('sha256', ContentReader::encode([array_diff_key($rv0['contents'][$rvAbout], ['revision' => 1]), $rvContract->readMapping()['contractHash']])));
checkTrue('two contents never share a revision', count(array_unique($rv0['revisions'])) === 3 && preg_match('/^[a-f0-9]{64}$/D', $rv0['revisions'][$rvHome]) === 1);
$rvSnapshot = fn(array $p) => hash('sha256', ContentReader::encode([$p['contents'], 'contract', []]));
$rvWriter->store['menuItem'][121]['title'] = 'About us';
$rv1 = $rvProject();
check('editing one page moves only that page', [$rv1['revisions'][$rvHome] === $rv0['revisions'][$rvHome], $rv1['revisions'][$rvAbout] === $rv0['revisions'][$rvAbout], $rv1['revisions'][$rvShared] === $rv0['revisions'][$rvShared]], [true, false, true]);
checkTrue('and still moves the snapshot', $rvSnapshot($rv1) !== $rvSnapshot($rv0));
$rvWriter->store['menuItem'][121]['title'] = 'About';
$rvWriter->store['module'][110]['content'] = '<h1>Other title</h1><a href="/start">Start</a>';
$rv2 = $rvProject();
check('editing an inlined module moves the page carrying it, alone', [$rv2['revisions'][$rvHome] === $rv0['revisions'][$rvHome], $rv2['revisions'][$rvAbout] === $rv0['revisions'][$rvAbout], $rv2['revisions'][$rvShared] === $rv0['revisions'][$rvShared]], [false, true, true]);
$rvWriter->store['module'][110]['content'] = $rvHero['content'];
$rvWriter->store['module'][111]['content'] = '<p>Other footer</p>';
$rv3 = $rvProject();
check('editing a shared module\'s text moves the shared content, not the pages naming it', [$rv3['revisions'][$rvHome] === $rv0['revisions'][$rvHome], $rv3['revisions'][$rvAbout] === $rv0['revisions'][$rvAbout], $rv3['revisions'][$rvShared] === $rv0['revisions'][$rvShared]], [true, true, false]);
$rvWriter->store['module'][111]['content'] = $rvFoot['content'];
check('the same rows give the same revisions', $rvProject()['revisions'], $rv0['revisions']);

$rvReader = new ContentReader(['id' => 'site_test', 'locales' => []], [], $rv0['contents'], 'snap', 'unit-secret', 'service', 100);
$rvList = $rvReader->read(['limit' => '1', 'protocolVersions' => 'tracy-content/v1']);
check('content.read accepts protocolVersions and ignores it', count($rvList['contents']), 1);
check('protocolVersions never binds a cursor', count($rvReader->read(['cursor' => $rvList['pagination']['nextCursor']])['contents']), 1);

/* ------------------------------------------------ W2: apply trusting content.read revisions */
$rvEngine = (new Engine($WTOKEN, [], null, null, null, null, $rvWriter, null, $rvLog, null, null, null, $rvContract))->contentRevisions($rvOracle);
$rvApply = fn(string $id, array $params) => $rvEngine->handle(['token' => $WTOKEN, 'action' => 'content.contract',
    'params' => ['operation' => 'apply', 'apply_id' => 'contract-' . $id, 'request_id' => $id] + $params]);
$rvRead = $rvOracle()['revisions'];
$rvOk = $rvApply('by-content', ['expected_content_revisions' => [$rvHome => $rvRead[$rvHome]], 'changes' => ['hero.0' => 'Customer title']]);
check('an apply naming its content revision writes without an inspect revision', [$rvOk['ok'], $rvWriter->store['module'][110]['content']],
    [true, '<h1>Customer title</h1><a href="/start">Start</a>']);
check('its receipt carries the new revision of the content it changed', $rvOk['contentRevisions'] ?? null, [$rvHome => $rvOracle()['revisions'][$rvHome]]);
checkTrue('and keeps the old receipt fields', isset($rvOk['ids'], $rvOk['revision']));
check('a lost reply replays the same receipt', $rvApply('by-content', ['expected_content_revisions' => [$rvHome => $rvRead[$rvHome]], 'changes' => ['hero.0' => 'Customer title']]), $rvOk);

$rvRefusal = fn(array $r) => [$r['ok'], $r['error'], $r['message'], $r['errors'] ?? null];
$rvStale = $rvApply('stale', ['expected_content_revisions' => [$rvHome => $rvRead[$rvHome]], 'changes' => ['hero.0' => 'Later title']]);
check('a stale content revision is refused with the current one', $rvRefusal($rvStale), [false, 'contract_failed', 'Content changed; read it again: ' . $rvHome,
    [['code' => 'REVISION_STALE', 'message' => 'Content changed; read it again: ' . $rvHome, 'field' => ['slotKey' => 'hero.0', 'contentId' => $rvHome], 'severity' => 'recoverable', 'current' => $rvOracle()['revisions'][$rvHome]]]]);
$rvMissing = $rvApply('missing', ['expected_content_revisions' => [$rvAbout => $rvOracle()['revisions'][$rvAbout]], 'changes' => ['hero.0' => 'Later title']]);
check('a changed slot whose content is not named is refused', $rvRefusal($rvMissing), [false, 'contract_failed', 'Content revision required: ' . $rvHome,
    [['code' => 'REVISION_REQUIRED', 'message' => 'Content revision required: ' . $rvHome, 'field' => ['slotKey' => 'hero.0', 'contentId' => $rvHome], 'severity' => 'recoverable']]]);
$rvBoth = $rvApply('both', ['expected_revision' => 'old', 'expected_content_revisions' => [$rvHome => $rvOracle()['revisions'][$rvHome]], 'changes' => ['hero.0' => 'Later title']]);
check('with both, a wrong inspect revision still refuses', [$rvBoth['ok'], array_column($rvBoth['errors'], 'code'), $rvBoth['errors'][0]['current'] ?? null],
    [false, ['REVISION_STALE'], $rvContract->inspect()['revision']]);
$rvNeither = $rvApply('neither', ['changes' => ['hero.0' => 'Later title']]);
check('with neither, the old refusal word for word', [$rvNeither['error'], $rvNeither['message'], $rvNeither['errors'][0]['code']], ['contract_failed', 'Content changed; inspect again', 'REVISION_REQUIRED']);
$rvOld = $rvApply('old-stale', ['expected_revision' => 'old', 'changes' => ['hero.0' => 'Later title']]);
check('a stale inspect revision alone keeps its old message', [$rvOld['message'], $rvOld['errors'][0]['code']], ['Content changed; inspect again', 'REVISION_STALE']);
check('refusals wrote nothing', $rvWriter->store['module'][110]['content'], '<h1>Customer title</h1><a href="/start">Start</a>');
$rvInspectOnly = $rvApply('inspect-only', ['expected_revision' => $rvContract->inspect()['revision'], 'changes' => ['menu-21.title' => 'About us']]);
check('an inspect revision alone still applies, and reports content revisions too', [$rvInspectOnly['ok'], array_keys($rvInspectOnly['contentRevisions'] ?? [])], [true, [$rvAbout]]);

// A slot content.read does not project has no content revision to name; the inspect revision covers it.
$rvUnprojected = function () use ($rvOracle): array { $c = $rvOracle(); unset($c['owners']['menu-21']); return $c; };
$rvHidden = (new Engine($WTOKEN, [], null, null, null, null, $rvWriter, null, $rvLog, null, null, null, $rvContract))->contentRevisions($rvUnprojected);
$rvHiddenApply = fn(string $id, array $params) => $rvHidden->handle(['token' => $WTOKEN, 'action' => 'content.contract',
    'params' => ['operation' => 'apply', 'apply_id' => 'contract-' . $id, 'request_id' => $id] + $params]);
$rvH = $rvHiddenApply('hidden', ['expected_content_revisions' => [$rvHome => $rvOracle()['revisions'][$rvHome]], 'changes' => ['menu-21.title' => 'About them']]);
check('a slot outside content.read needs the inspect revision', [$rvH['ok'], $rvH['errors'][0]['code'], $rvH['errors'][0]['field']], [false, 'REVISION_REQUIRED', ['slotKey' => 'menu-21.title', 'contentId' => null]]);
$rvH2 = $rvHiddenApply('hidden-2', ['expected_revision' => $rvContract->inspect()['revision'], 'expected_content_revisions' => [$rvHome => $rvOracle()['revisions'][$rvHome]], 'changes' => ['menu-21.title' => 'About them']]);
check('and applies with it', $rvH2['ok'], true);

// Presentation is held exactly as inspect holds it, whatever revisions are named.
$rvFresh = fn() => [$rvHome => $rvOracle()['revisions'][$rvHome]];
$rvWriter->store['module'][110]['position'] = 'elsewhere';
$rvDrift = $rvApply('drift', ['expected_content_revisions' => $rvFresh(), 'changes' => ['hero.0' => 'Drift title']]);
check('presentation drift refuses an apply with fresh content revisions', [$rvDrift['ok'], $rvDrift['errors'][0]['code'], $rvDrift['errors'][0]['severity']], [false, 'PRESENTATION_DRIFT', 'unrecoverable']);
$rvWriter->store['module'][110]['position'] = 'masthead';
file_put_contents($rvDir . '/assets/demo.css', '.hero { display: none }');
$rvFiles = $rvApply('files', ['expected_content_revisions' => $rvFresh(), 'changes' => ['hero.0' => 'Drift title']]);
check('a changed presentation file refuses it too', [$rvFiles['ok'], $rvFiles['message'], $rvFiles['errors'][0]['code']], [false, 'Presentation asset changed: assets/demo.css', 'PRESENTATION_DRIFT']);
file_put_contents($rvDir . '/assets/demo.css', '.hero { color: red }');
$rvNoOracle = new Engine($WTOKEN, [], null, null, null, null, $rvWriter, null, $rvLog, null, null, null, $rvContract);
$rvN = $rvNoOracle->handle(['token' => $WTOKEN, 'action' => 'content.contract', 'params' => ['operation' => 'apply', 'apply_id' => 'contract-no-oracle', 'request_id' => 'n',
    'expected_content_revisions' => $rvFresh(), 'changes' => ['hero.0' => 'Some title']]]);
check('a receiver that cannot project refuses content revisions instead of trusting them', [$rvN['ok'], $rvN['errors'][0]['code']], [false, 'CONTRACT_FAILED']);

/* ---------------------------------------------------- W3: every problem, one round, no write */
$rvBefore = [$rvWriter->store, $rvLog->log, $rvStore->binding];
$rvLong = str_repeat('x', 25);
$rvThree = $rvApply('three', ['expected_content_revisions' => $rvFresh() + [$rvShared => $rvOracle()['revisions'][$rvShared]],
    'changes' => ['hero.0' => $rvLong, 'nope.9' => 'Anything', 'foot.0' => 'New footer']]);
check('three bad slots come back as three errors in one refusal', $rvThree['errors'] ?? null, [
    ['code' => 'SLOT_TOO_LONG', 'message' => 'Content too long: hero.0', 'field' => ['slotKey' => 'hero.0', 'contentId' => $rvHome], 'severity' => 'recoverable', 'limit' => 20, 'actual' => 25],
    ['code' => 'SLOT_UNKNOWN', 'message' => 'Unknown content slot or non-string value', 'field' => ['slotKey' => 'nope.9', 'contentId' => null], 'severity' => 'unrecoverable'],
    ['code' => 'SLOT_EVIDENCE_REQUIRED', 'message' => 'Customer evidence required: foot.0', 'field' => ['slotKey' => 'foot.0', 'contentId' => $rvShared], 'severity' => 'recoverable'],
]);
check('the old error and message are still there', [$rvThree['ok'], $rvThree['error'], $rvThree['message']],
    [false, 'contract_failed', 'Content too long: hero.0; Unknown content slot or non-string value; Customer evidence required: foot.0']);
check('and nothing was written', [$rvWriter->store, $rvLog->log, $rvStore->binding], $rvBefore);
$rvOne = $rvApply('one', ['expected_revision' => $rvContract->inspect()['revision'], 'changes' => ['hero.0' => 'Bad <b>markup</b>']]);
check('one bad slot keeps its old message alone', [$rvOne['message'], $rvOne['errors'][0]['code']], ['Markup and control characters are not content: hero.0', 'SLOT_NOT_CONTENT']);
$rvLink = $rvApply('link', ['expected_revision' => $rvContract->inspect()['revision'], 'changes' => ['hero.1' => 'javascript:alert(1)']]);
check('an unsafe link is named as a link problem', [$rvLink['errors'][0]['code'], $rvLink['errors'][0]['field']['slotKey']], ['SLOT_LINK_UNSUPPORTED', 'hero.1']);
$rvEmpty = $rvApply('empty', ['expected_revision' => $rvContract->inspect()['revision'], 'changes' => ['hero.0' => '']]);
check('emptying an occupied slot is a layout change nobody can retry', [$rvEmpty['errors'][0]['code'], $rvEmpty['errors'][0]['severity']], ['SLOT_EMPTY_STATE', 'unrecoverable']);
$rvNone = $rvApply('none', ['expected_revision' => $rvContract->inspect()['revision'], 'changes' => []]);
check('an empty change list is invalid', [$rvNone['message'], $rvNone['errors'][0]['code']], ['Expected 1–1500 scalar content changes', 'CHANGES_INVALID']);
$rvLate = $rvEngine->handle(['token' => $WTOKEN, 'action' => 'apply.revert', 'params' => ['apply_id' => 'contract-by-content']]);
check('a revert under a later revision says it conflicts', [$rvLate['ok'], $rvLate['errors'][0]['code']], [false, 'CONFLICT']);
$rvBusy = new class extends FakeSiteWriter { public function serialize(callable $work) { throw new RuntimeException('Another write holds the site'); } };
$rvBusyEngine = new Engine($WTOKEN, [], null, null, null, null, $rvBusy, null, $rvLog, null, null, null, $rvContract);
$rvB = $rvBusyEngine->handle(['token' => $WTOKEN, 'action' => 'content.contract', 'params' => ['operation' => 'inspect']]);
check('a busy writer is a recoverable refusal', [$rvB['error'], $rvB['errors'][0]['code'], $rvB['errors'][0]['severity']], ['writer_busy', 'WRITER_BUSY', 'recoverable']);

foreach (array_keys($rvData) as $name) unlink($rvDir . '/' . $name . '.json');
unlink($rvDir . '/assets/demo.css'); rmdir($rvDir . '/assets'); rmdir($rvDir);
