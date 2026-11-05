<?php
// Loaded by run.php after contracts.php (reuses TestContractStore, ContractTestWriter, FakeApplyLog, $WTOKEN).
// content.contract `rows.remove`: a slot the plan hides leaves the stored field with its row or its own element.
// Every business word and slot here is made up.

$rrRoot = sys_get_temp_dir() . '/cowork-rows-remove-' . bin2hex(random_bytes(6));
$rrDir = $rrRoot . '/lib/contracts/test-rows/j6/1.0.0';
mkdir($rrDir, 0777, true);
$rrModule = fn(string $title, string $ordering, string $content, array $params) => ['title' => $title, 'module' => 'mod_custom', 'position' => 'header', 'published' => '1',
    'publish_up' => null, 'publish_down' => null, 'ordering' => $ordering, 'access' => '1', 'showtitle' => '0', 'language' => '*', 'client_id' => '0', 'content' => $content, 'params' => json_encode($params)];
$rrGrid = $rrModule('Made-up grid', '1', '', ['jatools-config' => json_encode([':type' => 'tpl:grid', 'grid' => ['style' => 's1', 'grid[columns]' => ['3'],
    'grid[title]' => ['Ann', 'Bob', 'Cid', 'Dee'], 'grid[link]' => ['#a', '#b', '#c', '#d'], 'grid[ids]' => [['1'], ['2'], ['3'], ['4']]]]), 'style' => 'keep-me']);
$rrPlan = $rrModule('Made-up plan', '2', '', ['plan' => ['plan0' => ['head' => 'First', 'body' => '<ul><li>One</li><li>Two</li></ul>'], 'plan1' => ['head' => 'Second', 'body' => '<ul><li>Three</li></ul>']], 'style' => 'keep-me']);
$rrBox = $rrModule('Made-up box', '3', "<a href=\"#one\">Call</a>\n<p>A street</p>\n<a href=\"#two\">Write</a>\n<ul class=\"x\"><li><i class=\"y\"></i>Red</li><li><i class=\"y\"></i>Blue</li></ul><div class=\"z\"><i class=\"q\"></i><b>Lone</b></div><div class=\"keep\">Left <img src=\"a.png\" alt=\"Pic\" /> right</div>", []);
$rrLone = $rrModule('Made-up lone', '4', '<p>Only words</p>', []);
$rrJson = fn(string $key, string $entity, array $path, string $sample, string $type = 'text', ?string $nested = 'jatools-config', ?string $xpath = null) => array_filter(['key' => $key, 'entity' => $entity, 'column' => 'params', 'type' => $type, 'sample' => $sample,
    'maxCharacters' => 200, 'requiresEvidence' => false, 'jsonPath' => $path, 'nestedJson' => $nested, 'xpath' => $xpath], static fn($v) => $v !== null);
$rrHtml = fn(string $key, string $entity, string $xpath, string $sample, string $type = 'text') => ['key' => $key, 'entity' => $entity, 'column' => 'content', 'type' => $type, 'sample' => $sample, 'maxCharacters' => 200, 'requiresEvidence' => false, 'xpath' => $xpath];
$rrSlots = [];
foreach (['Ann', 'Bob', 'Cid', 'Dee'] as $i => $name) {
    $rrSlots[] = $rrJson("grid.t$i", 'grid', ['grid', 'grid[title]', $i], $name);
    $rrSlots[] = $rrJson("grid.l$i", 'grid', ['grid', 'grid[link]', $i], ['#a', '#b', '#c', '#d'][$i], 'url');
}
$rrSlots[] = $rrJson('plan.h0', 'plan', ['plan', 'plan0', 'head'], 'First', 'text', null);
$rrSlots[] = $rrJson('plan.b0a', 'plan', ['plan', 'plan0', 'body'], 'One', 'text', null, '/html/body/div/ul/li[1]/text()');
$rrSlots[] = $rrJson('plan.b0b', 'plan', ['plan', 'plan0', 'body'], 'Two', 'text', null, '/html/body/div/ul/li[2]/text()');
$rrSlots[] = $rrJson('plan.h1', 'plan', ['plan', 'plan1', 'head'], 'Second', 'text', null);
$rrSlots[] = $rrJson('plan.b1a', 'plan', ['plan', 'plan1', 'body'], 'Three', 'text', null, '/html/body/div/ul/li/text()');
$rrSlots = array_merge($rrSlots, [
    $rrHtml('box.h1', 'box', '/html/body/div/a[1]/@href', '#one', 'url'), $rrHtml('box.t1', 'box', '/html/body/div/a[1]/text()', 'Call'),
    $rrHtml('box.p', 'box', '/html/body/div/p', 'A street'),
    $rrHtml('box.h2', 'box', '/html/body/div/a[2]/@href', '#two', 'url'), $rrHtml('box.t2', 'box', '/html/body/div/a[2]/text()', 'Write'),
    $rrHtml('box.red', 'box', '/html/body/div/ul/li[1]/text()', 'Red'), $rrHtml('box.blue', 'box', '/html/body/div/ul/li[2]/text()', 'Blue'),
    $rrHtml('box.lone', 'box', '/html/body/div/div[1]/b/text()', 'Lone'),
    $rrHtml('box.left', 'box', '/html/body/div/div[2]/text()[1]', 'Left '), $rrHtml('box.src', 'box', '/html/body/div/div[2]/img/@src', 'a.png', 'image'),
    $rrHtml('box.alt', 'box', '/html/body/div/div[2]/img/@alt', 'Pic'), $rrHtml('box.right', 'box', '/html/body/div/div[2]/text()[2]', ' right'),
    $rrHtml('lone.p', 'lone', '/html/body/div', 'Only words'),
]);
$rrStore = new TestContractStore();
$rrEntities = [];
foreach (['grid' => [10, 'Made-up grid'], 'plan' => [11, 'Made-up plan'], 'box' => [12, 'Made-up box'], 'lone' => [13, 'Made-up lone']] as $key => [$id, $title])
    $rrEntities[] = ['key' => $key, 'kind' => 'module', 'sourceId' => $id, 'identity' => ['title' => $title]];
file_put_contents($rrDir . '/manifest.json', json_encode(['id' => 'test-rows/j6/1.0.0']));
file_put_contents($rrDir . '/content-map.json', json_encode(['entities' => $rrEntities, 'slots' => $rrSlots, 'pages' => []]));
file_put_contents($rrDir . '/presentation-lock.json', json_encode(['entities' => ['grid' => $rrGrid, 'plan' => $rrPlan, 'box' => $rrBox, 'lone' => $rrLone],
    'assignments' => [['moduleid' => 10, 'menuid' => 0], ['moduleid' => 11, 'menuid' => 0], ['moduleid' => 12, 'menuid' => 0], ['moduleid' => 13, 'menuid' => 0]],
    'fileRoots' => [], 'files' => [], 'inventoryCounts' => ['module' => 4], 'access' => $rrStore->acl]));
$rrRows = ['grid' => [['column' => 'params', 'nestedJson' => 'jatools-config', 'parent' => ['grid'], 'arrays' => ['grid[title]', 'grid[link]', 'grid[ids]']]],
    'plan' => [['column' => 'params', 'parent' => ['plan'], 'keys' => ['plan0', 'plan1']]]];
file_put_contents($rrRoot . '/lib/hideable-slots.json', json_encode(['contracts' => ['test-rows/j6/1.0.0' => ['contentMapSha256' => hash_file('sha256', $rrDir . '/content-map.json'), 'measured' => [], 'unmeasured' => [], 'withRow' => [], 'rows' => $rrRows]]]));

$rrLog = new FakeApplyLog();
$rrWriter = new ContractTestWriter($rrLog, $rrStore);
foreach ([110 => $rrGrid, 111 => $rrPlan, 112 => $rrBox, 113 => $rrLone] as $id => $row) {
    $rrWriter->store['module'][$id] = ['id' => (string) $id] + $row;
    $rrWriter->store['moduleAssignment'][$id] = ['menuids' => '[0]'];
}
$rrContract = new QuickstartContract($rrWriter, $rrStore, $rrRoot, $rrDir);
$rrContract->bind($rrContract->inspect()['snapshot']);
$rrEngine = new Engine($WTOKEN, [], null, null, null, null, $rrWriter, null, $rrLog, null, null, null, $rrContract);
$rrDoor = fn(array $params) => $rrEngine->handle(['token' => $WTOKEN, 'action' => 'content.contract', 'params' => $params]);
$rrN = 0;
$rrRemove = function (array $slots, array $keep = []) use ($rrDoor, $rrContract, &$rrN) {
    $rrN++;
    return $rrDoor(['operation' => 'rows.remove', 'apply_id' => 'contract-rr' . $rrN, 'request_id' => 'rr' . $rrN, 'expected_revision' => $rrContract->inspect()['revision'], 'slots' => $slots, 'keep' => $keep]);
};
$rrCodes = fn(array $answer) => [$answer['ok'], array_column($answer['errors'] ?? [], 'code')];
$rrInspect = fn() => $rrDoor(['operation' => 'inspect']);
$rrValues = function () use ($rrInspect) { $out = []; foreach ($rrInspect()['slots'] as $slot) $out[$slot['key']] = $slot['current']; return $out; };
$rrGridNow = fn() => json_decode(json_decode($rrWriter->store['module'][110]['params'], true)['jatools-config'], true)['grid'];
$rrPlanNow = fn() => json_decode($rrWriter->store['module'][111]['params'], true)['plan'];
$rrContent = fn(int $id) => $rrWriter->store['module'][$id]['content'];

check('info says this engine removes rows', $rrEngine->handle(['token' => $WTOKEN, 'action' => 'info'])['info']['hide_ops']['rows_remove'] ?? null, 1);
$rrSlotRows = array_column($rrInspect()['slots'], 'row', 'key');
check('inspect names the reviewed row of each slot in one, and no other', [$rrSlotRows['grid.t2'] === $rrSlotRows['grid.l2'], $rrSlotRows['grid.t2'] === $rrSlotRows['grid.t3'], $rrSlotRows['plan.b0a'] === $rrSlotRows['plan.h0'], isset($rrSlotRows['box.p'])], [true, false, true, false]);

/* ------------------------------------------------ refusals: nothing is written */
$rrStart = $rrContract->inspect()['revision'];
check('a slot that is unknown is refused by name', $rrCodes($rrRemove(['nope.1'])), [false, ['SLOT_UNKNOWN']]);
check('a slot both kept and removed is refused', $rrCodes($rrRemove(['grid.t2'], ['grid.t2'])), [false, ['CHANGES_INVALID']]);
check('a row holding a kept slot stays, and its plain slot is refused by name', $rrCodes($rrRemove(['grid.t2'], ['grid.l2'])), [false, ['SLOT_NOT_REMOVABLE']]);
check('the refusal names the slot that stays', strpos($rrRemove(['grid.t2'], ['grid.l2'])['errors'][0]['message'] ?? '', 'grid.l2') !== false, true);
check('a link in an element that keeps its words is refused, not left dead', $rrCodes($rrRemove(['box.h1'], ['box.t1'])), [false, ['SLOT_NOT_REMOVABLE']]);
check('a box left with only decoration and no repeated sibling is refused, naming the slot', [$rrCodes($rrRemove(['box.lone'])), strpos($rrRemove(['box.lone'])['errors'][0]['message'] ?? '', 'box.lone') !== false], [[false, ['SLOT_NOT_REMOVABLE']], true]);
check('a stale revision is refused', $rrCodes($rrDoor(['operation' => 'rows.remove', 'apply_id' => 'contract-stale', 'request_id' => 's', 'expected_revision' => str_repeat('a', 64), 'slots' => ['grid.t2']])), [false, ['REVISION_STALE']]);
check('slots must be a list', $rrCodes($rrDoor(['operation' => 'rows.remove', 'apply_id' => 'contract-shape', 'request_id' => 's', 'expected_revision' => $rrStart, 'slots' => 'grid.t2'])), [false, ['CHANGES_INVALID']]);
check('refusals wrote nothing', $rrContract->inspect()['revision'], $rrStart);

/* ------------------------------------------------ a row of parallel lists */
$rrGone = $rrRemove(['grid.t2', 'grid.l2']);
check('the row leaves every list that runs beside it, and only those', [$rrGone['ok'], $rrGridNow()['grid[title]'], $rrGridNow()['grid[link]'], $rrGridNow()['grid[ids]'], $rrGridNow()['grid[columns]']],
    [true, ['Ann', 'Bob', 'Dee'], ['#a', '#b', '#d'], [['1'], ['2'], ['4']], ['3']]);
check('the answer names what left', $rrGone['removed'], ['grid.t2', 'grid.l2']);
$rrNow = $rrValues();
check('the slots after it still read their own words, under their own keys', [$rrNow['grid.t3'], $rrNow['grid.l3'], isset($rrNow['grid.t2'])], ['Dee', '#d', false]);
check('no drift: the baseline moved with it', [$rrInspect()['warnings'] ?? [], $rrContract->driftWarnings()], [[], []]);
check('inspect lists what left', array_keys($rrInspect()['removedSlots']), ['grid.t2', 'grid.l2']);
$rrAgain = $rrRemove(['grid.t2']);
check('removing it again changes nothing', [$rrAgain['ok'], $rrAgain['unchanged'] ?? null, $rrAgain['already']], [true, true, ['grid.t2']]);
$rrWrite = $rrDoor(['operation' => 'apply', 'apply_id' => 'contract-write1', 'request_id' => 'w1', 'expected_revision' => $rrContract->inspect()['revision'], 'changes' => ['grid.t3' => 'New Dee']]);
check('a write after it lands on the row that stepped down', [$rrWrite['ok'], $rrGridNow()['grid[title]']], [true, ['Ann', 'Bob', 'New Dee']]);
$rrBack = $rrDoor(['operation' => 'apply', 'apply_id' => 'contract-write2', 'request_id' => 'w2', 'expected_revision' => $rrContract->inspect()['revision'], 'changes' => ['grid.t2' => 'Cid again']]);
check('a removed slot takes no write, and says it comes back only on a new site', [$rrCodes($rrBack), strpos($rrBack['errors'][0]['message'] ?? '', 'new site') !== false], [[false, ['SLOT_UNKNOWN']], true]);
check('a written slot not named keeps its row', $rrCodes($rrRemove(['grid.l3'])), [false, ['SLOT_NOT_REMOVABLE']]);
$rrGridBefore = $rrWriter->store['module'][110]['params'];
$rrParams = json_decode($rrWriter->store['module'][110]['params'], true); $rrParams['style'] = 'changed-by-hand';
$rrWriter->store['module'][110]['params'] = json_encode($rrParams);
$rrContract->inspect();
checkTrue('the rest of the module still compares: a hand change beside the row is drift', count(array_filter($rrContract->driftWarnings(), static fn($w) => strpos($w, 'grid') !== false)) === 1);
$rrWriter->store['module'][110]['params'] = $rrGridBefore;
$rrContract->inspect();
check('and without it the module is clean again', $rrContract->driftWarnings(), []);

/* ------------------------------------------------ no way back */

$rrFirst = null; foreach (range(1, $rrN) as $rrI) if (in_array('rows', array_column($rrLog->entries('contract-rr' . $rrI), 'op'), true)) { $rrFirst = 'contract-rr' . $rrI; break; }
$rrUndo = $rrEngine->handle(['token' => $WTOKEN, 'action' => 'apply.revert', 'params' => ['apply_id' => $rrFirst]]);
check('apply.revert refuses a removal: only a new site brings it back', [$rrUndo['ok'], strpos($rrUndo['message'] ?? '', 'new site') !== false, $rrGridNow()['grid[title]']], [false, true, ['Ann', 'Bob', 'New Dee']]);

/* ------------------------------------------------ a subform row, with the HTML inside it */
$rrSub = $rrRemove(['plan.h0', 'plan.b0a', 'plan.b0b']);
check('a subform row leaves by its key, its HTML field with it', [$rrSub['ok'], array_keys($rrPlanNow())], [true, ['plan1']]);
check('an unnamed slot of its row, still as shipped, leaves too', isset($rrValues()['plan.b0b']), false);
$rrLast = $rrRemove(['plan.h1', 'plan.b1a']);
check('the last row leaves an empty list, which a template loops over and its `if` skips', [$rrLast['ok'], $rrPlanNow()], [true, []]);
check('still no drift', $rrContract->driftWarnings(), []);

/* ------------------------------------------------ an HTML field that stays */
$rrStreet = $rrRemove(['box.p']);
check('a whole unit takes its own element', [$rrStreet['ok'], strpos($rrContent(112), 'A street'), strpos($rrContent(112), '<p')], [true, false, false]);
check('an address after it is unmoved, no drift', [$rrValues()['box.t2'], $rrContract->driftWarnings()], ['Write', []]);
$rrMail = $rrRemove(['box.t2']);
check('a link whose words go takes its element and its still-shipped address', [$rrMail['ok'], strpos($rrContent(112), 'Write'), strpos($rrContent(112), '#two'), $rrMail['removed']], [true, false, false, ['box.h2', 'box.t2']]);
$rrRed = $rrRemove(['box.red']);
check('one of a repeat goes with its icon; the next steps down and reads its words', [$rrRed['ok'], substr_count($rrContent(112), '<li'), $rrValues()['box.blue'], $rrContract->driftWarnings()], [true, 1, 'Blue', []]);
$rrBlueWrite = $rrDoor(['operation' => 'apply', 'apply_id' => 'contract-write3', 'request_id' => 'w3', 'expected_revision' => $rrContract->inspect()['revision'], 'changes' => ['box.blue' => 'Teal']]);
check('and takes a write at its new place', [$rrBlueWrite['ok'], strpos($rrContent(112), 'Teal') !== false], [true, true]);
$rrWord = $rrRemove(['box.left'], ['box.src', 'box.right']);
check('a slot beside a kept one gives up only its own text, and the next text steps down', [$rrWord['ok'], $rrValues()['box.right'], strpos($rrContent(112), 'Left'), $rrContract->driftWarnings()], [true, ' right', false, []]);
$rrAlt = $rrRemove(['box.alt'], ['box.src', 'box.right']);
check('an alt beside a kept picture gives up only itself', [$rrAlt['ok'], strpos($rrContent(112), 'alt='), strpos($rrContent(112), 'a.png') !== false], [true, false, true]);
$rrPic = $rrRemove(['box.src'], ['box.right']);
check('a picture with no slot left goes with its element', [$rrPic['ok'], strpos($rrContent(112), '<img'), $rrValues()['box.right'], $rrContract->driftWarnings()], [true, false, ' right', []]);
$rrWhole = $rrRemove(['lone.p']);
check('a field with nothing left is the empty string, so its box is not drawn', [$rrWhole['ok'], $rrContent(113), $rrContract->driftWarnings()], [true, '', []]);

/* ------------------------------------------------ what the baseline does with several ops, read fresh */
$rrFresh = new QuickstartContract($rrWriter, $rrStore, $rrRoot, $rrDir);
$rrFresh->inspect();
check('a fresh reader of the binding sees the same slots and no drift', [$rrFresh->driftWarnings(), isset(array_column($rrFresh->inspect()['slots'], null, 'key')['grid.t3']), isset(array_column($rrFresh->inspect()['slots'], null, 'key')['grid.t2'])], [[], true, false]);

/* ------------------------------------------------ the shipped review */
$rrShipped = json_decode((string) file_get_contents(__DIR__ . '/../lib/hideable-slots.json'), true);
$rrBad = []; $rrCount = 0;
foreach ($rrShipped['contracts'] as $rrId => $rrEntry) {
    $rrMap = json_decode((string) file_get_contents(__DIR__ . '/../lib/contracts/' . $rrId . '/content-map.json'), true);
    $rrLock = json_decode((string) file_get_contents(__DIR__ . '/../lib/contracts/' . $rrId . '/presentation-lock.json'), true);
    foreach ($rrEntry['rows'] ?? [] as $rrEntity => $rrLists) foreach ($rrLists as $rrList) {
        $rrText = $rrLock['entities'][$rrEntity][$rrList['column']] ?? null;
        $rrBody = is_string($rrText) ? json_decode($rrText, true) : null;
        if (isset($rrList['nestedJson'])) $rrBody = json_decode($rrBody[$rrList['nestedJson']] ?? 'null', true);
        $rrParent = SlotRemoval::at((array) $rrBody, $rrList['parent']);
        if (!is_array($rrParent)) { $rrBad[] = $rrEntity . ': no parent'; continue; }
        $rrCount++;
        // Each listed row taken out of the lock's own row: the row's slots are gone, every other slot of the entity still reads its sample.
        foreach ($rrList['arrays'] ?? [] as $rrName) if (count($rrParent[$rrName]) !== $rrList['count']) $rrBad[] = $rrEntity . ': ' . $rrName . ' does not run with its row';
        foreach ($rrList['keys'] ?? [] as $rrKey) if (!array_key_exists($rrKey, $rrParent)) $rrBad[] = $rrEntity . ': ' . $rrKey . ' is no row';
    }
}
check('the shipped rows are what the contract locks hold', $rrBad, []);
checkTrue('and they cover JA Boxing j6 1.0.10', $rrCount >= 10);

/* ------------------------------------------------ on the shipped archive's own rows */
// Every row the review lists, and every HTML slot, taken out of the row the lock holds. What is left must read what it held,
// and the row removed then masked must be the masked row removed: that is the drift check, on every shape a write will meet.
$rrDirBox = __DIR__ . '/../lib/contracts/ja-boxing/j6/1.0.10';
$rrLock = json_decode((string) file_get_contents($rrDirBox . '/presentation-lock.json'), true);
$rrMap = json_decode((string) file_get_contents($rrDirBox . '/content-map.json'), true);
$rrEntry = json_decode((string) file_get_contents(__DIR__ . '/../lib/hideable-slots.json'), true)['contracts']['ja-boxing/j6/1.0.10'];
$rrBoxStore = new TestContractStore();
$rrBox = new QuickstartContract(new ContractTestWriter(new FakeApplyLog(), $rrBoxStore), $rrBoxStore, $rrRoot, $rrDirBox);
$rrBy = [];
foreach ($rrMap['slots'] as $rrSlot) $rrBy[$rrSlot['entity']][] = $rrSlot;
$rrDrift = []; $rrMoved = []; $rrTaken = 0;
$rrProve = function (string $entity, array $ops) use ($rrBox, $rrBy, $rrLock, &$rrDrift, &$rrMoved, &$rrTaken) {
    $row = $rrLock['entities'][$entity]; $slots = $rrBy[$entity];
    $before = []; foreach ($slots as $slot) $before[$slot['key']] = $rrBox->slotValue($row, $slot);
    $mask = array_fill_keys(array_keys($before), MultilingualProfile::CONTENT_SLOT);
    $masked = $rrBox->patch($row, $slots, $mask);
    $live = $row; $base = $masked;
    foreach ($ops as $op) { $live[$op['column']] = SlotRemoval::apply((string) $live[$op['column']], $op); $base[$op['column']] = SlotRemoval::apply((string) $base[$op['column']], $op); }
    $left = []; foreach ($slots as $slot) { $moved = SlotRemoval::slot($slot, $ops); if ($moved !== null) $left[] = $moved; else $rrTaken++; }
    foreach ($left as $slot) {
        try { $got = $rrBox->slotValue($live, $slot); } catch (Throwable $error) { $got = 'unreadable: ' . $error->getMessage(); }
        if ($got !== $before[$slot['key']]) $rrMoved[] = $slot['key'] . ' reads ' . json_encode(substr($got, 0, 40));
    }
    $again = $rrBox->patch($live, $left, array_intersect_key($mask, array_flip(array_column($left, 'key'))));
    $decoded = static fn($row) => array_map(static fn($v) => is_string($v) && is_array(json_decode($v, true)) ? json_decode($v, true) : $v, $row);
    if ($decoded($again) != $decoded($base)) $rrDrift[] = $entity . ' ' . json_encode($ops[0]);
};
foreach ($rrEntry['rows'] as $rrEntity => $rrLists) foreach ($rrLists as $rrList) {
    $rrBase = ['column' => $rrList['column'], 'parent' => $rrList['parent']] + (isset($rrList['nestedJson']) ? ['nestedJson' => $rrList['nestedJson']] : []);
    foreach ($rrList['keys'] ?? [] as $rrKey) $rrProve($rrEntity, [['op' => 'key', 'key' => $rrKey] + $rrBase]);
    for ($rrI = 0; $rrI < ($rrList['count'] ?? 0); $rrI++) $rrProve($rrEntity, [['op' => 'index', 'arrays' => $rrList['arrays'], 'index' => $rrI] + $rrBase]);
}
$rrRefused = 0; $rrNodes = 0;
foreach ($rrBy as $rrEntity => $rrSlots) {
    $rrFields = [];
    foreach ($rrSlots as $rrSlot) if (isset($rrSlot['xpath'])) $rrFields[json_encode([$rrSlot['column'], $rrSlot['nestedJson'] ?? null, $rrSlot['jsonPath'] ?? null])][] = $rrSlot;
    foreach ($rrFields as $rrField) {
        $rrAny = $rrField[0];
        $rrText = $rrLock['entities'][$rrEntity][$rrAny['column']];
        if (isset($rrAny['jsonPath'])) { $rrOuter = json_decode($rrText, true); if (isset($rrAny['nestedJson'])) $rrOuter = json_decode($rrOuter[$rrAny['nestedJson']], true); $rrText = SlotRemoval::at($rrOuter, $rrAny['jsonPath']); }
        foreach ($rrField as $rrSlot) {
            $rrFound = ContentSlots::removalOps((string) $rrText, $rrField, [$rrSlot['key'] => true], array_fill_keys(array_diff(array_column($rrField, 'key'), [$rrSlot['key']]), true));
            if ($rrFound['refused']) { $rrRefused++; continue; }
            $rrWhere = array_intersect_key($rrAny, array_flip(['column', 'nestedJson', 'jsonPath']));
            $rrProve($rrEntity, array_map(static fn($op) => $op + $rrWhere, $rrFound['ops']));
            $rrNodes++;
        }
    }
}
check('every shipped row and HTML slot removed: what is left reads what it held', array_slice($rrMoved, 0, 5), []);
check('and the row removed then masked is the masked row removed', array_slice($rrDrift, 0, 5), []);
checkTrue('and that covered the shipped archive', $rrTaken > 500 && $rrNodes > 100);
unset($rrShipped, $rrEntry, $rrMap, $rrLock, $rrBody, $rrParent, $rrBy, $rrBox);
gc_collect_cycles();
