<?php
// Loaded by run.php after contract-site-settings.php (reuses TestContractStore, ContractTestWriter, FakeApplyLog).
// A T3 template keeps its LOGOS in the params of every template style (`logoimage`, `logoimage_sm`,
// `logofooterimage`), not in a module or a settings file. TCH capture (`--template-style-logos`) declares each
// picture a style sets as an image slot of that style's `templateStyle` entity: column `params`, jsonPath [key],
// role `business.logo`. The brand step writes the picture with the switches around it (`logotype`, `sitename`,
// `enable_logoimage_sm`, `logofootertype`: tracy-web brand-joomla-t3.js), through `content.update` kind
// `templateStyle`. Every style carries its own slots, and none of those writes may read as presentation drift,
// while every other param of the style stays the design.

$tlDir = sys_get_temp_dir() . '/cowork-template-style-logos-' . bin2hex(random_bytes(6));
foreach (['/assets', '/images/brand'] as $folder) mkdir($tlDir . $folder, 0777, true);
file_put_contents($tlDir . '/assets/site.css', 'body { color: #111 }');
foreach (['logo-main.png', 'logo-small.png', 'logo-foot.png', 'customer.png', 'customer-dark.png'] as $tlPicture)
    file_put_contents($tlDir . '/images/brand/' . $tlPicture, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
// Two styles of one invented T3 template, each with its own logos; a third declares none.
$tlParams = [
    9 => ['logotype' => 'image', 'sitename' => '', 'logoimage' => 'images/brand/logo-main.png', 'enable_logoimage_sm' => '1',
        'logoimage_sm' => 'images/brand/logo-small.png', 'logofootertype' => 'image', 'logofooterimage' => 'images/brand/logo-foot.png',
        'logoimage_dark' => '', 'devmode' => '0', 'mm_type' => 'mainmenu'],
    10 => ['logotype' => 'image', 'sitename' => '', 'logoimage' => 'images/brand/logo-main.png', 'devmode' => '0', 'mm_type' => 'mainmenu'],
    11 => ['logotype' => 'text', 'sitename' => 'Demo', 'devmode' => '0'],
];
$tlStyle = static fn(int $id, string $title) => ['template' => 'ja_t3demo', 'client_id' => '0', 'home' => $id === 9 ? '1' : '0', 'title' => $title,
    'params' => json_encode($tlParams[$id]), 'inheritable' => '0', 'parent' => ''];
$tlTitles = [9 => 'ja_t3demo - Default', 10 => 'ja_t3demo - Home', 11 => 'ja_t3demo - Blank'];
$tlLogo = static fn(int $id, int $n, string $param) => ['key' => "templateStyle-{$id}.{$n}", 'entity' => "templateStyle-{$id}", 'column' => 'params',
    'type' => 'image', 'sample' => $tlParams[$id][$param], 'maxCharacters' => 255, 'requiresEvidence' => false, 'jsonPath' => [$param],
    'label' => "{$param} (template style {$tlTitles[$id]})", 'customize' => 'keep', 'role' => 'business.logo',
    'group' => ['id' => 'site-logo.business', 'item' => 0, 'kind' => 'business']];
$tlSlots = [$tlLogo(9, 0, 'logoimage'), $tlLogo(9, 1, 'logoimage_sm'), $tlLogo(9, 2, 'logofooterimage'), $tlLogo(10, 0, 'logoimage')];
$tlStore = new TestContractStore();
$tlEntities = []; $tlLock = [];
foreach ($tlTitles as $tlId => $tlTitle) {
    $tlEntities[] = ['key' => "templateStyle-{$tlId}", 'kind' => 'templateStyle', 'sourceId' => $tlId,
        'identity' => ['title' => $tlTitle, 'template' => 'ja_t3demo', 'client_id' => '0']];
    $tlLock["templateStyle-{$tlId}"] = $tlStyle($tlId, $tlTitle);
}
$tlFiles = [
    'manifest' => ['id' => 'template-style-logos/v1'],
    'content-map' => ['entities' => $tlEntities, 'slots' => $tlSlots, 'pages' => []],
    'presentation-lock' => ['entities' => $tlLock, 'assignments' => [], 'fileRoots' => ['assets'],
        'files' => ['assets/site.css' => hash_file('sha256', $tlDir . '/assets/site.css')],
        'inventoryCounts' => ['templateStyle' => 3], 'access' => $tlStore->acl],
];
foreach ($tlFiles as $name => $body) file_put_contents($tlDir . '/' . $name . '.json', json_encode($body));
$tlLog = new FakeApplyLog();
$tlWriter = new ContractTestWriter($tlLog, $tlStore);
foreach ($tlTitles as $tlId => $tlTitle) $tlWriter->store['templateStyle'][$tlId] = ['id' => (string) $tlId] + $tlStyle($tlId, $tlTitle);
$tlContract = new QuickstartContract($tlWriter, $tlStore, $tlDir, $tlDir);
$tlEngine = new Engine($WTOKEN, [], null, null, null, null, $tlWriter, null, $tlLog, null, null, null, $tlContract);
$tlCall = fn(array $params) => $tlEngine->handle(['token' => $WTOKEN, 'action' => 'content.contract', 'params' => $params]);
$tlCurrent = static function () use ($tlContract): array { return array_column($tlContract->inspect()['slots'], 'current', 'key'); };
$tlParamsOf = static function (int $id) use ($tlWriter): array { return json_decode((string) $tlWriter->store['templateStyle'][$id]['params'], true); };
$tlSetParams = static function (int $id, array $params) use ($tlWriter): void { $tlWriter->store['templateStyle'][$id]['params'] = json_encode($params); };

check('style logos: the profile binds', $tlCall(['operation' => 'bind'])['bound'] ?? null, true);
$tlState = $tlContract->inspect();
check('style logos: every logo of every style reads back from its own params', array_column($tlState['slots'], 'current', 'key'), array_column($tlSlots, 'sample', 'key'));
check('style logos: a freshly bound site has no drift', $tlContract->driftWarnings(), []);

// A contract apply writes the picture into the style's params, as content.
$tlApplied = $tlCall(['operation' => 'apply', 'apply_id' => 'contract-style-logo-1', 'request_id' => 'tl-1', 'expected_revision' => $tlState['revision'],
    'changes' => ['templateStyle-9.0' => 'images/brand/customer.png', 'templateStyle-10.0' => 'images/brand/customer.png']]);
check('style logos: an apply through a style logo commits', $tlApplied['ok'] ?? null, true);
check('style logos: the new logo is in each style\'s params, every other param as it was',
    [$tlParamsOf(9), $tlParamsOf(10)],
    [array_replace($tlParams[9], ['logoimage' => 'images/brand/customer.png']), array_replace($tlParams[10], ['logoimage' => 'images/brand/customer.png'])]);
check('style logos: the site reads the new logos back, the other slots untouched', $tlCurrent(),
    ['templateStyle-9.0' => 'images/brand/customer.png', 'templateStyle-9.1' => 'images/brand/logo-small.png',
        'templateStyle-9.2' => 'images/brand/logo-foot.png', 'templateStyle-10.0' => 'images/brand/customer.png']);
check('style logos: writing a style logo is not drift', $tlContract->driftWarnings(), []);
$tlReverted = $tlEngine->handle(['token' => $WTOKEN, 'action' => 'apply.revert', 'params' => ['apply_id' => 'contract-style-logo-1']]);
check('style logos: the apply is taken back', [$tlReverted['ok'] ?? null, $tlParamsOf(9), $tlParamsOf(10)], [true, $tlParams[9], $tlParams[10]]);

// The brand step writes the whole params through content.update, the switches flipped with the pictures.
$tlBrand = ['logotype' => 'image', 'logoimage' => 'images/brand/customer.png', 'sitename' => '', 'enable_logoimage_sm' => '0',
    'logoimage_dark' => 'images/brand/customer-dark.png', 'logofootertype' => 'text'] + $tlParams[9];
$tlUpdated = $tlEngine->handle(['token' => $WTOKEN, 'action' => 'content.update', 'params' => ['apply_id' => 'brand-style-logo-1', 'request_id' => 'tl-2',
    'kind' => 'templateStyle', 'id' => 9, 'fields' => ['params' => json_encode($tlBrand)]]]);
check('style logos: the brand step\'s write commits', $tlUpdated['ok'] ?? null, true);
$tlTextParams = ['logotype' => 'text', 'sitename' => 'Customer Co', 'logoimage' => 'images/brand/logo-main.png'] + $tlParams[10];
$tlSetParams(10, $tlTextParams);
$tlContract->inspect();
check('style logos: the logo switches the brand step flips are not drift (image or name as text, small logo off, footer name, dark logo)',
    $tlContract->driftWarnings(), []);
// A switch the style never had, added by the brand step, is the customer's too.
$tlSetParams(10, ['enable_logoimage_sm' => '0', 'logofootertype' => 'text'] + $tlTextParams);
$tlContract->inspect();
check('style logos: a logo switch the style did not carry is not drift either', $tlContract->driftWarnings(), []);

// Every other param of a style is still the design.
$tlSetParams(9, ['devmode' => '1'] + $tlBrand);
contractDrifts('style logos: a style param beside the logo is still the design', $tlContract, fn() => $tlContract->inspect());
$tlSetParams(9, $tlBrand + ['themermode' => '1']);
contractDrifts('style logos: a style param added beside the logo is still the design', $tlContract, fn() => $tlContract->inspect());
$tlSetParams(9, $tlBrand);
$tlWriter->store['templateStyle'][9]['home'] = '0';
contractDrifts('style logos: a style column beside its params is still the design', $tlContract, fn() => $tlContract->inspect());
$tlWriter->store['templateStyle'][9]['home'] = '1';
// A style whose contract declares no logo slot keeps its switches pinned.
$tlSetParams(11, ['logotype' => 'image'] + $tlParams[11]);
contractDrifts('style logos: a style with no declared logo still holds its logo switches', $tlContract, fn() => $tlContract->inspect());
$tlSetParams(11, ['logoimage' => 'images/brand/customer.png'] + $tlParams[11]);
contractDrifts('style logos: a style with no declared logo still holds its logo pictures', $tlContract, fn() => $tlContract->inspect());
$tlSetParams(11, $tlParams[11]);
$tlContract->inspect();
check('style logos: back as the release, the site is clean again', $tlContract->driftWarnings(), []);

// A mega-menu sub taken out of a style (showcase navigation) records the style's whole params as the baseline:
// held as an inspect reads them, so the customer's logo and switches in that column do not read as drift after it.
$tlStore->save($tlContract->bindingWithShowcaseNav(null, [['key' => 'templateStyle-9', 'kind' => 'templateStyle', 'raw' => json_encode($tlBrand)]]));
$tlSetParams(9, ['logoimage' => 'images/brand/logo-main.png', 'logotype' => 'text', 'sitename' => 'Customer Co'] + $tlBrand);
$tlContract->inspect();
check('style logos: after a showcase-navigation write, the logo set of that style is still the customer\'s', $tlContract->driftWarnings(), []);
$tlSetParams(9, ['mm_type' => 'other'] + $tlBrand);
contractDrifts('style logos: after a showcase-navigation write, a style param beside the logo is still the design', $tlContract, fn() => $tlContract->inspect());
