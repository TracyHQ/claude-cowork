<?php
// Loaded by run.php after contract-language-strings.php (reuses TestContractStore, ContractTestWriter, FakeApplyLog).
// A T4 template keeps its LOGOS in a FILE: `templates/<t>/etc/site/<profile>.json` (JA Boxing's light logo `item-2.png`
// is `site_logo` of its `default` profile, on every page). TCH capture (`--full-coverage`) declares each picture key a
// profile sets as a `siteSetting` entity, identity {template, profile, key}, whose one column `value` is what the profile
// holds now. The door writes the profile's `local/` copy, never the template's file; its undo is the files' previous
// bytes, carried by the apply log (the same op as `template.siteSettings`).

$ssDir = sys_get_temp_dir() . '/cowork-site-settings-' . bin2hex(random_bytes(6));
foreach (['/assets', '/templates/ja_box/etc/site', '/images/logo'] as $folder) mkdir($ssDir . $folder, 0777, true);
file_put_contents($ssDir . '/assets/site.css', 'body { color: #111 }');
foreach (['item-1.png', 'item-2.png', 'new-light.png'] as $ssPicture) file_put_contents($ssDir . '/images/logo/' . $ssPicture, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
$ssProfile = '{"site_name":"JA Box","site_logo":"images\/logo\/item-2.png#joomlaImage:\/\/local-images\/logo\/item-2.png?width=175&height=56","site_logo_small":"","site_logo_dark":"images\/logo\/item-1.png","other_faviconFile":""}';
$ssTemplateFile = $ssDir . '/templates/ja_box/etc/site/default.json';
$ssLocalFile = $ssDir . '/templates/ja_box/local/etc/site/default.json';
file_put_contents($ssTemplateFile, $ssProfile);
$ssLogo = \SiteSettingRows::id('ja_box', 'default', 'site_logo');
$ssDark = \SiteSettingRows::id('ja_box', 'default', 'site_logo_dark');
$ssLightSample = 'images/logo/item-2.png#joomlaImage://local-images/logo/item-2.png?width=175&height=56';
check('site settings: an id is a positive whole number, the same every time', [$ssLogo > 0, $ssLogo === \SiteSettingRows::id('ja_box', 'default', 'site_logo'), $ssLogo !== $ssDark], [true, true, true]);
$ssSlots = [
    ['key' => "siteSetting-{$ssLogo}.0", 'entity' => "siteSetting-{$ssLogo}", 'column' => 'value', 'type' => 'image', 'sample' => $ssLightSample, 'maxCharacters' => 255, 'customize' => 'keep'],
    ['key' => "siteSetting-{$ssDark}.0", 'entity' => "siteSetting-{$ssDark}", 'column' => 'value', 'type' => 'image', 'sample' => 'images/logo/item-1.png', 'maxCharacters' => 255, 'customize' => 'keep'],
    ['key' => 'module-160.0', 'entity' => 'module-160', 'column' => 'title', 'type' => 'text', 'sample' => 'Our Classes', 'maxCharacters' => 40, 'customize' => 'keep'],
];
$ssEntity = static fn(int $id, string $key) => ['key' => "siteSetting-{$id}", 'kind' => 'siteSetting', 'sourceId' => $id,
    'identity' => ['template' => 'ja_box', 'profile' => 'default', 'key' => $key]];
$ssLock = static fn(string $key, string $value) => ['template' => 'ja_box', 'profile' => 'default', 'key' => $key, 'value' => $value];
$ssModule = ['title' => 'Our Classes', 'module' => 'mod_custom', 'position' => 'section-2', 'published' => '1', 'access' => '1', 'language' => '*', 'client_id' => '0', 'content' => '', 'showtitle' => '1', 'params' => '{}'];
$ssStore = new TestContractStore();
$ssFiles = [
    'manifest' => ['id' => 'site-settings/v1'],
    'content-map' => ['entities' => [
        $ssEntity($ssLogo, 'site_logo'), $ssEntity($ssDark, 'site_logo_dark'),
        ['key' => 'module-160', 'kind' => 'module', 'sourceId' => 160, 'identity' => ['title' => 'Our Classes', 'module' => 'mod_custom']],
    ], 'slots' => $ssSlots, 'pages' => []],
    'presentation-lock' => ['entities' => [
        "siteSetting-{$ssLogo}" => $ssLock('site_logo', $ssLightSample),
        "siteSetting-{$ssDark}" => $ssLock('site_logo_dark', 'images/logo/item-1.png'),
        'module-160' => $ssModule,
    ], 'assignments' => [['moduleid' => 160, 'menuid' => 0]], 'fileRoots' => ['assets'], 'files' => ['assets/site.css' => hash_file('sha256', $ssDir . '/assets/site.css')],
        'inventoryCounts' => ['module' => 1], 'access' => $ssStore->acl],
];
foreach ($ssFiles as $name => $body) file_put_contents($ssDir . '/' . $name . '.json', json_encode($body));
$ssLog = new FakeApplyLog();
$ssWriter = new ContractTestWriter($ssLog, $ssStore);
$ssWriter->store['module'][160] = ['id' => '160'] + $ssModule;
$ssWriter->store['moduleAssignment'][160] = ['menuids' => '[0]'];
$ssContract = new QuickstartContract($ssWriter, $ssStore, $ssDir, $ssDir);
$ssEngine = (new Engine($WTOKEN, [], null, null, null, null, $ssWriter, null, $ssLog, null, null, null, $ssContract))
    ->templateSiteSettings(new TemplateSiteFiles($ssDir, static fn(string $template): array => []));
$ssCall = fn(array $params) => $ssEngine->handle(['token' => $WTOKEN, 'action' => 'content.contract', 'params' => $params]);
$ssCurrent = static function () use ($ssContract): array { return array_column($ssContract->inspect()['slots'], 'current', 'key'); };

check('site settings: the profile binds', $ssCall(['operation' => 'bind'])['bound'] ?? null, true);
$ssState = $ssContract->inspect();
check('site settings: each logo reads as the profile holds it (the raw media value included)', array_column($ssState['slots'], 'current', 'key'), array_column($ssSlots, 'sample', 'key'));
check('site settings: a freshly bound site has no drift, and its settings are not counted as inventory', $ssContract->driftWarnings(), []);

$ssApplied = $ssCall(['operation' => 'apply', 'apply_id' => 'contract-logo-1', 'request_id' => 'ss-1', 'expected_revision' => $ssState['revision'],
    'changes' => ["siteSetting-{$ssLogo}.0" => 'images/logo/new-light.png']]);
check('site settings: an apply through a logo commits', $ssApplied['ok'] ?? null, true);
check('site settings: the template\'s own profile file is untouched', file_get_contents($ssTemplateFile), $ssProfile);
$ssLocal = json_decode((string) @file_get_contents($ssLocalFile), true);
check('site settings: the new logo is in the profile\'s local copy, every other key as it was',
    [$ssLocal['site_logo'] ?? null, $ssLocal['site_logo_dark'] ?? null, $ssLocal['site_name'] ?? null], ['images/logo/new-light.png', 'images/logo/item-1.png', 'JA Box']);
check('site settings: the site reads the new logo back, the other one untouched', [$ssCurrent()["siteSetting-{$ssLogo}.0"], $ssCurrent()["siteSetting-{$ssDark}.0"]], ['images/logo/new-light.png', 'images/logo/item-1.png']);
check('site settings: writing a logo is not drift', $ssContract->driftWarnings(), []);

$ssReverted = $ssEngine->handle(['token' => $WTOKEN, 'action' => 'apply.revert', 'params' => ['apply_id' => 'contract-logo-1']]);
check('site settings: the apply is taken back', $ssReverted['ok'] ?? null, true);
check('site settings: the local copy it created is gone again, and so are its folders', [file_exists($ssLocalFile), is_dir($ssDir . '/templates/ja_box/local')], [false, false]);
check('site settings: after the revert the site reads the demo logos', $ssCurrent(), array_column($ssSlots, 'sample', 'key'));

// A picture that is not on the site is refused, nothing written.
$ssState = $ssContract->inspect();
$ssRefused = $ssCall(['operation' => 'apply', 'apply_id' => 'contract-logo-2', 'request_id' => 'ss-2', 'expected_revision' => $ssState['revision'],
    'changes' => ["siteSetting-{$ssLogo}.0" => 'images/logo/missing.png']]);
check('site settings: a picture that is not on the site is refused and nothing is written', [$ssRefused['ok'] ?? null, file_exists($ssLocalFile)], [false, false]);

// A batch that fails after the file was written takes the file back too: it is outside the database transaction.
$ssState = $ssContract->inspect();
$ssWriter->failOn = ['module', 160];
$ssFailed = $ssCall(['operation' => 'apply', 'apply_id' => 'contract-logo-3', 'request_id' => 'ss-3', 'expected_revision' => $ssState['revision'],
    'changes' => ["siteSetting-{$ssLogo}.0" => 'images/logo/new-light.png', 'module-160.0' => 'All classes']]);
$ssWriter->failOn = null;
check('site settings: a failed apply leaves no local copy', [$ssFailed['ok'] ?? null, file_exists($ssLocalFile)], [false, false]);

// Outside the contract the profile is template.siteSettings' to write: a plain batch refuses the kind.
$ssBatch = $ssEngine->handle(['token' => $WTOKEN, 'action' => 'content.batch', 'params' => ['apply_id' => 'batch-logo-1', 'request_id' => 'ss-4',
    'operations' => [['kind' => 'siteSetting', 'id' => $ssLogo, 'fields' => ['value' => 'images/logo/new-light.png']]]]]);
check('site settings: a plain content batch may not write a site setting', [$ssBatch['ok'] ?? null, file_exists($ssLocalFile)], [false, false]);
