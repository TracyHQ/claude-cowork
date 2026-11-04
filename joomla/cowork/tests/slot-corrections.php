<?php
// Loaded by run.php after hide-ops.php. Slot corrections: what Tracy's kit knows better than a released contract rides
// beside it as receiver data, tied to the sha256 of its content map. Every word and picture here is made up, and the
// pictures are real files the write check decodes, so the corner reading runs for real (GD is required by this file).

$scRoot = sys_get_temp_dir() . '/cowork-slot-corrections-' . bin2hex(random_bytes(6));
$scDir = $scRoot . '/lib/contracts/test-fix/j6/1.0.0';
mkdir($scDir, 0777, true);
mkdir($scRoot . '/images/made-up', 0777, true);

// A picture drawn here: a clear ground with a mark in its middle, or a ground filled to its corners.
$scDraw = function (string $name, int $w, int $h, bool $clear) use ($scRoot): string {
    $image = imagecreatetruecolor($w, $h);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagefill($image, 0, 0, imagecolorallocatealpha($image, 200, 60, 40, $clear ? 127 : 0));
    imagefilledellipse($image, (int) ($w / 2), (int) ($h / 2), (int) ($w / 2), (int) ($h / 2), imagecolorallocatealpha($image, 20, 90, 80, 0));
    $path = 'images/made-up/' . $name;
    $file = $scRoot . '/' . $path;
    $ext = pathinfo($name, PATHINFO_EXTENSION);
    if ($ext === 'jpg') imagejpeg($image, $file);
    elseif ($ext === 'webp') imagewebp($image, $file);
    else imagepng($image, $file);
    return $path;
};
$scDemo = $scDraw('demo.png', 150, 150, true);
$scIcon = $scDraw('icon.png', 100, 100, true);
$scIconWebp = $scDraw('icon.webp', 100, 100, true);
$scOpaque = $scDraw('opaque.png', 100, 100, false);
$scPhoto = $scDraw('photo.jpg', 100, 100, false);
$scTall = $scDraw('tall.png', 100, 300, true);

$scKept = ['measured' => false, 'kind' => 'icon', 'replace' => 'keep', 'transparent' => true];
$scWords = ['measured' => true, 'lines' => ['desktop' => 2, 'phone' => 1], 'maxChars' => ['desktop' => 54, 'phone' => 28], 'maxCharacters' => 28];
$scSlot = fn(string $key, string $type, string $sample, int $limit, array $path, ?array $fit, array $more = []) => ['key' => $key, 'entity' => 'block', 'column' => 'params', 'type' => $type, 'sample' => $sample,
    'maxCharacters' => $limit, 'requiresEvidence' => false, 'jsonPath' => ['cards', 'cards[' . $path[0] . ']', $path[1]], 'nestedJson' => 'jatools-config'] + ($fit === null ? [] : ['fit' => $fit]) + $more;
$scSlots = [
    $scSlot('block.0', 'text', 'A made-up heading', 46, ['title', 0], $scWords),
    $scSlot('block.1', 'text', 'Another made-up heading', 46, ['title', 1], $scWords),
    $scSlot('block.2', 'image', $scDemo, 255, ['icon', 0], $scKept),
    $scSlot('block.3', 'image', $scDemo, 255, ['icon', 1], $scKept),
    $scSlot('block.4', 'text', '', 40, ['alt', 0], ['measured' => false]),
    $scSlot('block.5', 'text', '', 40, ['alt', 1], ['measured' => false]),
];
$scRow = ['title' => 'Made-up block', 'module' => 'mod_ja_acm', 'position' => 'header', 'published' => '1', 'publish_up' => null, 'publish_down' => null, 'ordering' => '1', 'access' => '1',
    'showtitle' => '0', 'language' => '*', 'client_id' => '0', 'content' => '', 'params' => json_encode(['jatools-config' => json_encode([':type' => 'tpl:cards', 'cards' => [
        'cards[title]' => ['A made-up heading', 'Another made-up heading'], 'cards[icon]' => [$scDemo, $scDemo], 'cards[alt]' => ['', ''], 'jatools-layout-cards' => 'style-1']]), 'moduleclass_sfx' => ''])];
$scStore = new TestContractStore();
foreach (['manifest' => ['id' => 'test-fix/j6/1.0.0'],
    'content-map' => ['entities' => [['key' => 'block', 'kind' => 'module', 'sourceId' => 10, 'identity' => ['title' => 'Made-up block', 'module' => 'mod_ja_acm', 'position' => 'header']]], 'slots' => $scSlots, 'pages' => []],
    'presentation-lock' => ['entities' => ['block' => $scRow], 'assignments' => [['moduleid' => 10, 'menuid' => 0]], 'fileRoots' => [], 'files' => [], 'inventoryCounts' => ['module' => 1], 'access' => $scStore->acl],
] as $scName => $scBody) file_put_contents($scDir . '/' . $scName . '.json', json_encode($scBody));
$scMapHash = hash_file('sha256', $scDir . '/content-map.json');
$scList = fn(?string $hash = null, string $id = 'test-fix/j6/1.0.0') => json_encode(['contracts' => [$id => ['contentMapSha256' => $hash ?? $scMapHash,
    'limits' => ['block.0' => 46], 'icons' => ['block.2'], 'alts' => ['block.4' => 125]]]]);
$scListFile = $scRoot . '/lib/slot-corrections.json';

$scCheck = new ReflectionMethod(QuickstartContract::class, 'checkValue');
$scSlotsFor = new ReflectionMethod(QuickstartContract::class, 'slotsFor');
if (PHP_VERSION_ID < 80100) { $scCheck->setAccessible(true); $scSlotsFor->setAccessible(true); }
$scOpen = fn() => new QuickstartContract(new ContractTestWriter(new FakeApplyLog(), new TestContractStore()), new TestContractStore(), $scRoot, $scDir);
/** The refusal code of a value on a slot as a contract loaded now holds it; null when taken. */
$scAnswer = function ($contract, string $key, string $value, array $params = []) use ($scCheck, $scSlotsFor): ?string {
    foreach ($scSlotsFor->invoke($contract, 'block') as $slot) if ($slot['key'] === $key) {
        try { $scCheck->invoke($contract, $slot, $value, $params); return null; }
        catch (ContractProblem $e) { return $e->reason; }
    }
    return 'no such slot';
};
$scBy = function ($contract, string $key) use ($scSlotsFor): array {
    return array_column($scSlotsFor->invoke($contract, 'block'), null, 'key')[$key];
};

/* ------------------------------------------------ no list: nothing changes */
$scToday = $scOpen();
$scHashToday = $scToday->contractHash();
$scAsToday = fn($c) => [
    'over the fit' => $scAnswer($c, 'block.0', str_repeat('a', 36)),
    'a drawn icon' => $scAnswer($c, 'block.2', $scIcon),
    'an empty alt filled' => $scAnswer($c, 'block.4', 'A made-up picture'),
];
$scRefusedToday = ['over the fit' => 'SLOT_TOO_LONG', 'a drawn icon' => 'SLOT_PICTURE_KEPT', 'an empty alt filled' => 'SLOT_EMPTY_STATE'];
check('without a list every slot answers as it always did', $scAsToday($scToday), $scRefusedToday);

/* ------------------------------------------------ a list that does not hold is ignored */
foreach ([['another content map', $scList(str_repeat('0', 64))], ['another contract', $scList(null, 'test-other/j6/1.0.0')], ['an unreadable file', '{not json'], ['no map hash', json_encode(['contracts' => ['test-fix/j6/1.0.0' => ['limits' => ['block.0' => 46]]]])]] as [$scWhy, $scBytes]) {
    file_put_contents($scListFile, $scBytes);
    check('a list of ' . $scWhy . ' is ignored', $scAsToday($scOpen()), $scRefusedToday);
}

/* ------------------------------------------------ the list */
file_put_contents($scListFile, $scList());
$scFixed = $scOpen();
check('the list moves no contract hash', $scFixed->contractHash(), $scHashToday);

check('a listed text takes the limit of the list', $scAnswer($scFixed, 'block.0', str_repeat('a', 46)), null);
check('over the listed limit, the door still refuses (the map holds it)', $scAnswer($scFixed, 'block.0', str_repeat('a', 47)), 'SLOT_TOO_LONG');
check('the slot list says the listed limit, not the old box', [$scBy($scFixed, 'block.0')['fit']['maxCharacters'], isset($scBy($scFixed, 'block.0')['fit']['maxChars'])], [46, false]);
check('a text the list does not name keeps its fit', [$scAnswer($scFixed, 'block.1', str_repeat('a', 36)), $scBy($scFixed, 'block.1'), ], ['SLOT_TOO_LONG', $scSlots[1]]);

check('a listed icon slot says so', $scBy($scFixed, 'block.2')['fit']['replace'], 'icon');
check('a listed icon slot takes a transparent drawing', $scAnswer($scFixed, 'block.2', $scIcon), null);
check('and in WebP', $scAnswer($scFixed, 'block.2', $scIconWebp), null);
check('an opaque photo is still refused as a kept picture', $scAnswer($scFixed, 'block.2', $scPhoto), 'SLOT_PICTURE_KEPT');
check('an opaque PNG with an alpha channel is too: its corners decide', $scAnswer($scFixed, 'block.2', $scOpaque), 'SLOT_PICTURE_KEPT');
check('the customer asking by name still opens it, and an opaque picture still meets the transparent rule', $scAnswer($scFixed, 'block.2', $scOpaque, ['replace_kept' => ['block.2' => 'use my own icon']]), 'SLOT_IMAGE_OPAQUE');
check('the words of a customer asking for a transparent one still take it', $scAnswer($scFixed, 'block.2', $scIcon, ['replace_kept' => ['block.2' => 'use my own icon']]), null);
check('a transparent picture of another shape is refused', $scAnswer($scFixed, 'block.2', $scTall), 'SLOT_IMAGE_INVALID');
check('a file that is not on the site is refused', $scAnswer($scFixed, 'block.2', 'images/made-up/none.png'), 'SLOT_IMAGE_INVALID');
check('the demo icon goes back', $scAnswer($scFixed, 'block.2', $scDemo), null);
check('an icon the list does not name stays kept', [$scAnswer($scFixed, 'block.3', $scIcon), $scBy($scFixed, 'block.3')], ['SLOT_PICTURE_KEPT', $scSlots[3]]);

check('a listed alt fills its empty sample', $scAnswer($scFixed, 'block.4', 'A made-up picture of a made-up court at dusk, longer than the old limit of forty'), null);
check('up to the listed limit', [$scAnswer($scFixed, 'block.4', str_repeat('a', 125)), $scAnswer($scFixed, 'block.4', str_repeat('a', 126))], [null, 'SLOT_TOO_LONG']);
check('a double quote is refused: the template prints an alt unescaped', $scAnswer($scFixed, 'block.4', 'A "made-up" picture'), 'SLOT_NOT_CONTENT');
check('markup is refused as everywhere', $scAnswer($scFixed, 'block.4', 'A <b>made-up</b> picture'), 'SLOT_NOT_CONTENT');
check('an alt the list does not name stays closed', [$scAnswer($scFixed, 'block.5', 'A made-up picture'), $scBy($scFixed, 'block.5')], ['SLOT_EMPTY_STATE', $scSlots[5]]);

/* ------------------------------------------------ the whole door */
$scLog = new FakeApplyLog();
$scDoorStore = new TestContractStore();
$scWriter = new ContractTestWriter($scLog, $scDoorStore);
$scWriter->store['module'][110] = ['id' => '110'] + $scRow;
$scWriter->store['moduleAssignment'][110] = ['menuids' => '[0]'];
$scContract = new QuickstartContract($scWriter, $scDoorStore, $scRoot, $scDir);
$scContract->bind($scContract->inspect()['snapshot']);
$scEngine = new Engine($WTOKEN, [], null, null, null, null, $scWriter, null, $scLog, null, null, null, $scContract);
$scDoor = fn(array $params) => $scEngine->handle(['token' => $WTOKEN, 'action' => 'content.contract', 'params' => $params]);
$scApply = fn(string $id, array $changes) => $scDoor(['operation' => 'apply', 'apply_id' => 'contract-' . $id, 'request_id' => $id, 'expected_revision' => $scContract->inspect()['revision'], 'changes' => $changes]);
$scHeld = fn() => json_decode(json_decode($scWriter->store['module'][110]['params'], true)['jatools-config'], true)['cards'];

$scInspect = array_column($scDoor(['operation' => 'inspect'])['slots'], null, 'key');
check('inspect tells the agent the listed limit and the icon rule', [$scInspect['block.0']['fit']['maxCharacters'], $scInspect['block.2']['fit']['replace']], [46, 'icon']);
$scRefused = $scApply('photo', ['block.2' => $scPhoto, 'block.4' => 'A made-up picture']);
check('one apply with an opaque photo is refused whole, in the kept picture words', [$scRefused['ok'], array_column($scRefused['errors'] ?? [], 'code'), $scHeld()['cards[alt]'][0]], [false, ['SLOT_PICTURE_KEPT'], '']);
$scWrote = $scApply('fill', ['block.0' => str_repeat('a', 46), 'block.2' => $scIcon, 'block.4' => 'A made-up picture']);
check('one apply takes the longer words, the drawn icon and the alt', $scWrote['ok'] ?? $scWrote, true);
check('and they are what the site holds', [$scHeld()['cards[title]'][0], $scHeld()['cards[icon]'][0], $scHeld()['cards[alt]'][0], $scHeld()['cards[alt]'][1]], [str_repeat('a', 46), $scIcon, 'A made-up picture', '']);
$scRead = array_column($scContract->inspect()['slots'], null, 'key');
check('and inspect reads them back', [$scRead['block.2']['current'], $scRead['block.4']['current']], [$scIcon, 'A made-up picture']);
$scGuidance = $scContract->readMapping()['fitGuidance'] ?? '';
checkTrue('the guidance explains the icon rule', strpos($scGuidance, 'fit.replace is "icon"') !== false);

/* ------------------------------------------------ the shipped list */
$scShipped = json_decode((string) file_get_contents(__DIR__ . '/../lib/slot-corrections.json'), true);
$scProblems = [];
foreach ($scShipped['contracts'] as $scId => $scEntry) {
    $scProfile = __DIR__ . '/../lib/contracts/' . $scId . '/content-map.json';
    if (hash_file('sha256', $scProfile) !== $scEntry['contentMapSha256']) $scProblems[] = $scId . ': another content map';
    $scKnown = array_column(json_decode((string) file_get_contents($scProfile), true)['slots'], null, 'key');
    foreach ($scEntry['limits'] as $scKey => $scLimit) {
        if (($scKnown[$scKey]['type'] ?? '') !== 'text') $scProblems[] = $scKey . ' is no text slot';
        elseif ($scLimit > $scKnown[$scKey]['maxCharacters']) $scProblems[] = $scKey . ' goes over its map';
    }
    foreach ($scEntry['icons'] as $scKey) if (($scKnown[$scKey]['fit']['replace'] ?? '') !== 'keep' || ($scKnown[$scKey]['fit']['transparent'] ?? false) !== true) $scProblems[] = $scKey . ' is no kept transparent picture';
    foreach ($scEntry['alts'] as $scKey => $scLimit) if (($scKnown[$scKey]['sample'] ?? 'x') !== '') $scProblems[] = $scKey . ' is no empty slot';
    unset($scKnown);
}
check('the shipped list matches its content maps', $scProblems, []);

$scBoxDir = __DIR__ . '/../lib/contracts/ja-boxing/j6/1.0.10';
$scBoxStore = new TestContractStore();
$scBox = new QuickstartContract(new ContractTestWriter(new FakeApplyLog(), $scBoxStore), $scBoxStore, $scRoot, $scBoxDir);
$scBoxMap = json_decode((string) file_get_contents($scBoxDir . '/content-map.json'), true);
$scBoxEntry = $scShipped['contracts']['ja-boxing/j6/1.0.10'];
check('JA Boxing j6 1.0.10 is covered by the rule: limits, icons, alts', [count($scBoxEntry['limits']), count($scBoxEntry['icons']), count($scBoxEntry['alts'])], [185, 48, 6]);
check('each of the eight words the door refused takes the kit number', array_intersect_key($scBoxEntry['limits'], array_flip(['menuItem-187.0', 'menuItem-191.0', 'article-14.17', 'contact-1.1', 'article-2.16', 'article-16.13', 'article-15.11', 'article-14.13'])),
    ['article-14.13' => 46, 'article-14.17' => 26, 'article-15.11' => 46, 'article-16.13' => 46, 'article-2.16' => 46, 'contact-1.1' => 69, 'menuItem-187.0' => 24, 'menuItem-191.0' => 21]);
$scBoxHash = (new ReflectionMethod(QuickstartContract::class, 'digest'));
if (PHP_VERSION_ID < 80100) $scBoxHash->setAccessible(true);
check('the Boxing contract hash is the one a bound site stores: the map as released', $scBox->contractHash(), $scBoxHash->invoke($scBox, [json_decode((string) file_get_contents($scBoxDir . '/manifest.json'), true), $scBoxMap, json_decode((string) file_get_contents($scBoxDir . '/presentation-lock.json'), true)]));
$scBoxSlotsFor = fn(string $entity) => $scSlotsFor->invoke($scBox, $entity);
$scBoxWrong = [];
foreach ($scBoxMap['slots'] as $scSlotIn) {
    $scIs = array_column($scBoxSlotsFor($scSlotIn['entity']), null, 'key')[$scSlotIn['key']];
    $scListed = isset($scBoxEntry['limits'][$scSlotIn['key']]) || isset($scBoxEntry['alts'][$scSlotIn['key']]) || in_array($scSlotIn['key'], $scBoxEntry['icons'], true);
    if (!$scListed && $scIs !== $scSlotIn) $scBoxWrong[] = $scSlotIn['key'];
}
check('a Boxing slot not listed is the slot the map holds, byte for byte', $scBoxWrong, []);
$scInfo = $scEngine->handle(['token' => $WTOKEN, 'action' => 'info'])['info']['slot_corrections'] ?? null;
check('info says what this engine corrects, per contract', [$scInfo['version'] ?? null, $scInfo['contracts']['ja-boxing/j6/1.0.10'] ?? null], [1, ['limits' => 185, 'icons' => 48, 'alts' => 6]]);
unset($scShipped, $scBoxMap, $scBox);
