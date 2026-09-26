<?php
// Loaded by run.php after contracts.php (reuses TestContractStore and ContractTestWriter).
// Issue #316: a contract call took 35–108 s. What one call costs, measured only when asked — and
// proof that measuring it, or any saving made from what it showed, changes nothing a caller reads.

$costDir = sys_get_temp_dir() . '/cowork-cost-' . bin2hex(random_bytes(6));
mkdir($costDir); mkdir($costDir . '/assets');
file_put_contents($costDir . '/assets/demo.css', '.hero { color: red }');
// Several slots per column on purpose: three in one HTML body, three in one nested JSON config.
$costHero = ['title' => 'Home hero', 'module' => 'mod_custom', 'position' => 'masthead', 'published' => '1', 'access' => '1',
    'language' => '*', 'client_id' => '0', 'content' => '<h1>Demo title</h1><p>Lead text</p><a href="/start">Start</a>', 'params' => '{"style":"0"}'];
$costConfig = [':type' => 'tracy_business:site-identity', 'site-identity' => ['site-name' => 'Northgate Industrial', 'phone' => '0123 456', 'city' => 'Hanoi']];
$costIdentity = ['title' => '[Tracy] Site identity', 'module' => 'mod_ja_acm', 'position' => '', 'published' => '1', 'access' => '1',
    'language' => '*', 'client_id' => '0', 'content' => '', 'params' => json_encode(['jatools-config' => json_encode($costConfig)])];
$costSlots = [];
foreach (ContentSlots::htmlSlots($costHero['content']) as $n => $s) $costSlots[] = ['key' => 'hero.' . $n, 'entity' => 'hero', 'column' => 'content', 'maxCharacters' => 80] + $s;
foreach ($costConfig['site-identity'] as $field => $sample)
    $costSlots[] = ['key' => 'identity.' . $field, 'entity' => 'identity', 'column' => 'params', 'type' => 'text', 'sample' => $sample,
        'maxCharacters' => 255, 'jsonPath' => ['site-identity', $field], 'nestedJson' => 'jatools-config'];
$costStore = new TestContractStore();
$costFiles = [
    'manifest' => ['id' => 'cost/v1'],
    'content-map' => ['entities' => [
        ['key' => 'hero', 'kind' => 'module', 'sourceId' => 10, 'identity' => ['title' => 'Home hero']],
        ['key' => 'identity', 'kind' => 'module', 'sourceId' => 11, 'identity' => ['title' => '[Tracy] Site identity']],
    ], 'slots' => $costSlots, 'pages' => []],
    'presentation-lock' => ['entities' => ['hero' => $costHero, 'identity' => $costIdentity], 'assignments' => [['moduleid' => 10, 'menuid' => 0]],
        'fileRoots' => ['assets'], 'files' => ['assets/demo.css' => hash_file('sha256', $costDir . '/assets/demo.css')],
        'inventoryCounts' => ['module' => 2], 'access' => $costStore->acl],
];
foreach ($costFiles as $name => $body) file_put_contents($costDir . '/' . $name . '.json', json_encode($body));
$costLog = new FakeApplyLog();
$costWriter = new ContractTestWriter($costLog, $costStore);
$costWriter->store['module'][110] = ['id' => '110'] + $costHero;
$costWriter->store['module'][111] = ['id' => '111'] + $costIdentity;
$costWriter->store['moduleAssignment'][110] = ['menuids' => '[0]'];
$costWriter->store['moduleAssignment'][111] = ['menuids' => '[]'];
$costContract = new QuickstartContract($costWriter, $costStore, $costDir, $costDir);
$costEngine = new Engine($WTOKEN, [], null, null, null, null, $costWriter, null, $costLog, null, null, null, $costContract);
check('the cost fixture binds', $costEngine->handle(['token' => $WTOKEN, 'action' => 'content.contract', 'params' => ['operation' => 'bind']])['bound'] ?? null, true);
// The door's body for one request, exactly as EngineFactory::answer() writes it.
$costAnswer = fn(array $request): string => Timing::body($request, fn() => $costEngine->handle($request));
$costInspect = ['token' => $WTOKEN, 'action' => 'content.contract', 'params' => ['operation' => 'inspect']];
$costTimed = $costInspect; $costTimed['params']['timing'] = true;

// ── R7: a timing block, on request only ──────────────────────────────────────────────────────────
$costPlain = $costAnswer($costInspect);
check('an untimed body is the encoding the door always sent', $costPlain, json_encode($costEngine->handle($costInspect)));
checkTrue('an untimed body has no timing key', !array_key_exists('timing', json_decode($costPlain, true)));
$costMarks = [];
Timing::body($costInspect, function () use (&$costMarks) { $costMarks[] = Timing::begin(); return ['ok' => true]; });
check('an untimed call reads no clock', $costMarks, [0]);
check('the engine answers a timed request exactly as an untimed one', $costEngine->handle($costTimed), $costEngine->handle($costInspect));
$costTimedBody = $costAnswer($costTimed);
check('a timed body is the untimed one with one key added at its end', substr($costTimedBody, 0, strlen($costPlain) - 1), substr($costPlain, 0, -1));
$costTiming = json_decode($costTimedBody, true)['timing'];
check('asking for timing changes nothing else in the answer', array_diff_key(json_decode($costTimedBody, true), ['timing' => 0]), json_decode($costPlain, true));
// No contractHash here: this instance hashed its profile when it bound, and hashes it once.
check('an inspect is timed phase by phase',
    array_values(array_diff(['files', 'inventory', 'presentation', 'assignments', 'access', 'counts', 'slots', 'digest', 'inspect', 'encode'], array_keys($costTiming))), []);
checkTrue('each phase is {ms, n}', !array_filter($costTiming, fn($p) => array_keys($p) !== ['ms', 'n'] || !is_numeric($p['ms']) || $p['ms'] < 0 || !is_int($p['n']) || $p['n'] < 1));
check('one inspect ran', $costTiming['inspect']['n'], 1);
$costNoToken = $costTimed; $costNoToken['token'] = 'wrong';
check('a caller without the token gets no timing, only the refusal it always got',
    $costAnswer($costNoToken), json_encode($costEngine->handle($costNoToken)));
checkTrue('only timing: true asks — not 1, not "true"', !Timing::wanted(['params' => ['timing' => 1]]) && !Timing::wanted(['params' => ['timing' => 'true']]));
putenv('CLAUDECOWORK_TIMING=1');
checkTrue('the environment can force timing on for every call', array_key_exists('timing', json_decode($costAnswer($costInspect), true)));
putenv('CLAUDECOWORK_TIMING');

$costApply = ['token' => $WTOKEN, 'action' => 'content.contract', 'params' => ['operation' => 'apply', 'apply_id' => 'contract-cost-1', 'request_id' => 'cost-1',
    'expected_revision' => $costContract->inspect()['revision'], 'changes' => ['hero.0' => 'Customer title'], 'timing' => true]];
$costApplied = json_decode($costAnswer($costApply), true);
check('a timed apply commits', $costApplied['ok'], true);
check('a timed apply is timed phase by phase',
    array_values(array_diff(['plan', 'write', 'bind', 'verify', 'revisionsAfter', 'log', 'purge', 'inspect', 'encode'], array_keys($costApplied['timing']))), []);
check('an apply runs two inspects: its plan and its verify', $costApplied['timing']['inspect']['n'], 2);
checkTrue('no stored receipt carries a timing', !str_contains(json_encode($costLog->log), 'timing'));
$costReplayed = json_decode($costAnswer($costApply), true);
check('a replay answers the committed receipt', array_diff_key($costReplayed, ['timing' => 0]), array_diff_key($costApplied, ['timing' => 0]));
checkTrue('a replay is timed afresh, never with the timings it was recorded with', !isset($costReplayed['timing']['write']));
$costReverted = json_decode($costAnswer(['token' => $WTOKEN, 'action' => 'apply.revert', 'params' => ['apply_id' => 'contract-cost-1', 'timing' => true]]), true);
check('a timed revert succeeds', $costReverted['ok'], true);
check('a timed revert is timed phase by phase',
    array_values(array_diff(['revertPre', 'revert', 'revertPost', 'purge'], array_keys($costReverted['timing']))), []);

// ── The profile hash, once per instance ──────────────────────────────────────────────────────────
// Manifest, content map and lock are read in the constructor and never change after; the hash of
// them was re-encoded twice per inspect and twice per readMapping.
$costFresh = new QuickstartContract($costWriter, $costStore, $costDir, $costDir);
$costTwice = json_decode(Timing::body(['params' => ['timing' => true]], function () use ($costFresh) {
    $first = $costFresh->inspect(); $second = $costFresh->inspect(); $costFresh->readMapping();
    return ['ok' => true, 'hashes' => [$first['snapshot']['contractHash'], $second['snapshot']['contractHash']], 'revisions' => [$first['revision'], $second['revision']]];
}), true);
check('two inspects and a read mapping on one instance hash the profile once', $costTwice['timing']['contractHash']['n'], 1);
check('and every one of them carries the same hash, the one the site is bound to',
    $costTwice['hashes'], [$costStore->binding['contractHash'], $costStore->binding['contractHash']]);
check('and the same revision', $costTwice['revisions'][0], $costTwice['revisions'][1]);

// ── The locked files, proved once per door call ─────────────────────────────────────────────────
// An apply inspects twice (plan, verify) and a revert twice (before, after) with no file written in
// between, so one proof of the locked files serves the whole call; the next call proves them again.
$costOutside = $costContract->inspect();
$costContract->beginCall(); $costInside = $costContract->inspect(); $costInside2 = $costContract->inspect(); $costContract->endCall();
check('an inspect inside a door call answers exactly as one outside it', [$costInside, $costInside2], [$costOutside, $costOutside]);
$costApply2 = $costApply; $costApply2['params']['apply_id'] = 'contract-cost-2'; $costApply2['params']['request_id'] = 'cost-2';
$costApply2['params']['expected_revision'] = $costOutside['revision'];
$costApplied2 = json_decode($costAnswer($costApply2), true);
check('an apply proves the locked files once across its two inspects',
    [$costApplied2['ok'], $costApplied2['timing']['inspect']['n'], $costApplied2['timing']['files']['n']], [true, 2, 1]);
$costReverted2 = json_decode($costAnswer(['token' => $WTOKEN, 'action' => 'apply.revert', 'params' => ['apply_id' => 'contract-cost-2', 'timing' => true]]), true);
check('so does a revert', [$costReverted2['ok'], $costReverted2['timing']['inspect']['n'], $costReverted2['timing']['files']['n']], [true, 2, 1]);
file_put_contents($costDir . '/assets/demo.css', '.hero { display: none }');
// Tracy ADR 0022: a changed locked file is reported, never refused — but it IS seen again.
contractDrifts('once the call is over, the next inspect proves the files again', $costContract, fn() => $costContract->inspect());
$costNext = $costEngine->handle($costInspect);
check('and so does the next door call', [$costNext['ok'], $costNext['warnings'][0]['message'] ?? null], [true, 'Presentation asset changed: assets/demo.css']);
file_put_contents($costDir . '/assets/demo.css', '.hero { color: red }');
check('the files restored, the site reads again', $costEngine->handle($costInspect)['ok'], true);

// ── Each row's columns parsed once per inspect ───────────────────────────────────────────────────
// slotValue() still reads a slot the way inspect always did — its column parsed afresh for that one
// slot — so it is the oracle for what inspect now reads with each row's columns parsed once.
// Tracy Business 1.2.0 as shipped is three of them (1,797 slots, 1,509 in nested JSON); the content
// revisions fixture is not, because its own tests end on a drifted stylesheet.
$costFixtures = ['cost' => $costContract, 'identity' => $idContract, 'bulk rows' => $bc, 'paged rows' => $pc,
    'a shipped profile after its gate run' => $gc, 'demo trim' => $trimContract, 'site language' => $langContract, 'source language' => $relContract];
$costSlotCount = 0;
foreach ($costFixtures as $costName => $costFixture) {
    $costState = $costFixture->inspect();
    $costSlotCount += count($costState['slots']);
    check('parsing each row once reads every slot as before: ' . $costName,
        array_column($costState['slots'], 'current'),
        array_map(fn($slot) => $costFixture->slotValue($costState['rows'][$slot['entity']], $slot), $costState['slots']));
}
checkTrue('the fixtures hold several slots per column, in HTML and in nested JSON', $costSlotCount > 3000
    && count(array_filter($costContract->inspect()['slots'], fn($s) => isset($s['jsonPath']))) === 3);

// ── The revision an apply leaves ─────────────────────────────────────────────────────────────────
// Returned so the next apply needs no inspect to learn it; `revision` stays the receipt's own hash.
$costBefore = $costContract->inspect()['revision'];
$costApply3 = $costApply; unset($costApply3['params']['timing']);
$costApply3['params']['apply_id'] = 'contract-cost-3'; $costApply3['params']['request_id'] = 'cost-3';
$costApply3['params']['expected_revision'] = $costBefore; $costApply3['params']['changes'] = ['identity.city' => 'Da Nang'];
$costApplied3 = $costEngine->handle($costApply3);
check('an apply answers the revision it left, the one an inspect right after reads',
    [$costApplied3['ok'], $costApplied3['afterRevision'] ?? null], [true, $costContract->inspect()['revision']]);
checkTrue('which is a new revision, and not the receipt hash', $costApplied3['afterRevision'] !== $costBefore && $costApplied3['afterRevision'] !== $costApplied3['revision']);
check('a replay answers it too', $costEngine->handle($costApply3)['afterRevision'] ?? null, $costApplied3['afterRevision']);
$costNext = $costApply3; $costNext['params']['apply_id'] = 'contract-cost-4'; $costNext['params']['request_id'] = 'cost-4';
$costNext['params']['expected_revision'] = $costApplied3['afterRevision']; $costNext['params']['changes'] = ['identity.city' => 'Hue'];
$costApplied4 = $costEngine->handle($costNext);
check('the next apply can be based on it with no inspect between', $costApplied4['ok'], true);
$costReplay3 = $costEngine->handle($costApply3);
check('a replay after the site moved on answers without the revision it no longer stands at',
    [$costReplay3['ok'], array_key_exists('afterRevision', $costReplay3), $costReplay3['revision']], [true, false, $costApplied3['revision']]);
$costReceipt = current(array_filter($costLog->entries('contract-cost-4'), fn($e) => ($e['op'] ?? '') === 'contract'));
check('it is the revision the receipt keeps for its revert', $costReceipt['afterRevision'], $costApplied4['afterRevision']);
$costRevert = fn(string $apply) => $costEngine->handle(['token' => $WTOKEN, 'action' => 'apply.revert', 'params' => ['apply_id' => $apply]])['ok'];
check('so both applies revert, latest first', [$costRevert('contract-cost-4'), $costRevert('contract-cost-3')], [true, true]);
check('back to the revision before them', $costContract->inspect()['revision'], $costBefore);
