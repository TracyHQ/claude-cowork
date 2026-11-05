<?php
// Loaded by run.php after contract-site-settings.php (reuses TestContractStore, ContractTestWriter, FakeApplyLog).
// Two places a T4 site keeps words and pictures visitors read that are not rows of the content tables:
// - a T4 LAYOUT or NAVIGATION profile FILE, `templates/<t>/etc/{layout,navigation}/<profile>.json`: JA Vega's and JA Kinetic's
//   mega menu column titles are `mega_settings` of the navigation profile (a JSON string inside the file's JSON), a
//   section's background picture is `background_image` of a layout profile. Capture declares each file it binds as a
//   `templateConfig` entity, identity {template, group, profile}, whose one column `value` is the file's whole text; the
//   contract writes the profile's `local/` copy, never the template's file, and its undo is the files' previous bytes.
// - a custom field's stored value (`#__fields_values`, no id of its own): JA Vega's author skill bars. Capture declares it as
//   a `fieldValue` entity, identity {field, context}, matched to its item through `identityReferences` (`item_id`).

$tcDir = sys_get_temp_dir() . '/cowork-template-config-' . bin2hex(random_bytes(6));
foreach (['/assets', '/templates/ja_vega/etc/navigation', '/templates/ja_vega/etc/layout', '/images/joomlart/bg'] as $folder) mkdir($tcDir . $folder, 0777, true);
file_put_contents($tcDir . '/assets/site.css', 'body { color: #111 }');
foreach (['hero.jpg', 'new-hero.jpg'] as $tcPicture) file_put_contents($tcDir . '/images/joomlart/bg/' . $tcPicture, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
$tcMega = json_encode(['mainmenu' => ['120' => ['settings' => [['contents' => [['type' => 'module'], ['type' => 'items', 'title' => 'Our Portfolio']]]]]]]);
$tcNavigation = json_encode(['navigation_type' => 'megamenu', 'mega_settings' => $tcMega], JSON_UNESCAPED_SLASHES);
$tcHeroSample = 'images/joomlart/bg/hero.jpg#joomlaImage://local-images/joomlart/bg/hero.jpg?width=1920&height=800';
$tcLayout = json_encode(['name' => 'home', 'sections' => [['name' => 'hero', 'background_image' => $tcHeroSample, 'extra_class' => 't4-hero']]], JSON_UNESCAPED_SLASHES);
$tcNavFile = $tcDir . '/templates/ja_vega/etc/navigation/default.json';
$tcLayoutFile = $tcDir . '/templates/ja_vega/etc/layout/home.json';
$tcLocalNav = $tcDir . '/templates/ja_vega/local/etc/navigation/default.json';
$tcLocalLayout = $tcDir . '/templates/ja_vega/local/etc/layout/home.json';
file_put_contents($tcNavFile, $tcNavigation);
file_put_contents($tcLayoutFile, $tcLayout);
$tcNav = \TemplateConfigRows::id('ja_vega', 'navigation', 'default');
$tcHome = \TemplateConfigRows::id('ja_vega', 'layout', 'home');
check('template profiles: an id is a positive whole number, the same every time', [$tcNav > 0, $tcNav === \TemplateConfigRows::id('ja_vega', 'navigation', 'default'), $tcNav !== $tcHome], [true, true, true]);
$tcSkill = FieldValueKey::encode(7, 160);
$tcSlots = [
    ['key' => "templateConfig-{$tcNav}.0", 'entity' => "templateConfig-{$tcNav}", 'column' => 'value', 'nestedJson' => 'mega_settings',
        'jsonPath' => ['mainmenu', '120', 'settings', 0, 'contents', 1, 'title'], 'type' => 'text', 'sample' => 'Our Portfolio', 'maxCharacters' => 40, 'customize' => 'rewrite'],
    ['key' => "templateConfig-{$tcHome}.0", 'entity' => "templateConfig-{$tcHome}", 'column' => 'value', 'jsonPath' => ['sections', 0, 'background_image'],
        'type' => 'image', 'sample' => $tcHeroSample, 'maxCharacters' => 255, 'customize' => 'keep'],
    ['key' => "fieldValue-{$tcSkill}.0", 'entity' => "fieldValue-{$tcSkill}", 'column' => 'value', 'type' => 'text', 'sample' => 'Design 90%', 'maxCharacters' => 40, 'customize' => 'rewrite'],
    ['key' => 'module-160.0', 'entity' => 'module-160', 'column' => 'title', 'type' => 'text', 'sample' => 'Our Team', 'maxCharacters' => 40, 'customize' => 'keep'],
];
$tcModule = ['title' => 'Our Team', 'module' => 'mod_custom', 'position' => 'section-2', 'published' => '1', 'access' => '1', 'language' => '*', 'client_id' => '0', 'content' => '', 'showtitle' => '1', 'params' => '{}'];
$tcStore = new TestContractStore();
$tcFiles = [
    'manifest' => ['id' => 'template-config/v1'],
    'content-map' => ['entities' => [
        ['key' => 'module-160', 'kind' => 'module', 'sourceId' => 160, 'identity' => ['title' => 'Our Team', 'module' => 'mod_custom']],
        ['key' => "templateConfig-{$tcNav}", 'kind' => 'templateConfig', 'sourceId' => $tcNav, 'identity' => ['template' => 'ja_vega', 'group' => 'navigation', 'profile' => 'default']],
        ['key' => "templateConfig-{$tcHome}", 'kind' => 'templateConfig', 'sourceId' => $tcHome, 'identity' => ['template' => 'ja_vega', 'group' => 'layout', 'profile' => 'home']],
        // The item a field value belongs to is any bound entity (an author, an article); a module stands in for it here.
        ['key' => "fieldValue-{$tcSkill}", 'kind' => 'fieldValue', 'sourceId' => $tcSkill, 'identity' => ['field' => 'skill-1', 'context' => 'com_users.user'], 'identityReferences' => ['item_id' => 'module-160']],
    ], 'slots' => $tcSlots, 'pages' => []],
    'presentation-lock' => ['entities' => [
        'module-160' => $tcModule,
        "templateConfig-{$tcNav}" => ['template' => 'ja_vega', 'group' => 'navigation', 'profile' => 'default', 'value' => $tcNavigation],
        "templateConfig-{$tcHome}" => ['template' => 'ja_vega', 'group' => 'layout', 'profile' => 'home', 'value' => $tcLayout],
        "fieldValue-{$tcSkill}" => ['value' => 'Design 90%'],
    ], 'assignments' => [['moduleid' => 160, 'menuid' => 0]], 'fileRoots' => ['assets', 'templates/ja_vega'],
        'files' => ['assets/site.css' => hash_file('sha256', $tcDir . '/assets/site.css'), 'templates/ja_vega/etc/navigation/default.json' => hash_file('sha256', $tcNavFile),
            'templates/ja_vega/etc/layout/home.json' => hash_file('sha256', $tcLayoutFile)],
        'inventoryCounts' => ['module' => 1], 'access' => $tcStore->acl],
];
foreach ($tcFiles as $name => $body) file_put_contents($tcDir . '/' . $name . '.json', json_encode($body));
$tcLog = new FakeApplyLog();
$tcWriter = new ContractTestWriter($tcLog, $tcStore);
$tcWriter->store['module'][160] = ['id' => '160'] + $tcModule;
$tcWriter->store['moduleAssignment'][160] = ['menuids' => '[0]'];
$tcWriter->store['fieldValue'][$tcSkill] = ['field' => 'skill-1', 'context' => 'com_users.user', 'item_id' => 160, 'value' => 'Design 90%'];
$tcContract = new QuickstartContract($tcWriter, $tcStore, $tcDir, $tcDir);
$tcEngine = (new Engine($WTOKEN, [], null, null, null, null, $tcWriter, null, $tcLog, null, null, null, $tcContract))
    ->templateSiteSettings(new TemplateSiteFiles($tcDir, static fn(string $template): array => []));
$tcCall = fn(array $params) => $tcEngine->handle(['token' => $WTOKEN, 'action' => 'content.contract', 'params' => $params]);
$tcCurrent = static function () use ($tcContract): array { $c = array_column($tcContract->inspect()['slots'], 'current', 'key'); ksort($c); return $c; };
$tcSamples = array_column($tcSlots, 'sample', 'key'); ksort($tcSamples);

check('template profiles: the contract binds', $tcCall(['operation' => 'bind'])['bound'] ?? null, true);
$tcState = $tcContract->inspect();
check('template profiles: a mega menu title, a section picture and a field value read as the site holds them', $tcCurrent(), $tcSamples);
check('template profiles: a freshly bound site has no drift, and profiles and field values are not counted as inventory', $tcContract->driftWarnings(), []);

$tcApplied = $tcCall(['operation' => 'apply', 'apply_id' => 'contract-config-1', 'request_id' => 'tc-1', 'expected_revision' => $tcState['revision'],
    'changes' => ["templateConfig-{$tcNav}.0" => 'Our Work', "templateConfig-{$tcHome}.0" => 'images/joomlart/bg/new-hero.jpg', "fieldValue-{$tcSkill}.0" => 'Strategy 80%']]);
check('template profiles: an apply through a mega menu title, a section picture and a field value commits', $tcApplied['ok'] ?? null, true);
check('template profiles: the template\'s own files are untouched', [file_get_contents($tcNavFile), file_get_contents($tcLayoutFile)], [$tcNavigation, $tcLayout]);
$tcLocal = json_decode((string) @file_get_contents($tcLocalNav), true);
check('template profiles: the new title is in the navigation profile\'s local copy, every other key as it was',
    [$tcLocal['navigation_type'] ?? null, json_decode($tcLocal['mega_settings'] ?? 'null', true)['mainmenu']['120']['settings'][0]['contents'][1]['title'] ?? null], ['megamenu', 'Our Work']);
$tcLocal = json_decode((string) @file_get_contents($tcLocalLayout), true);
check('template profiles: the new picture is in the layout profile\'s local copy, the section otherwise as it was',
    [$tcLocal['sections'][0]['background_image'] ?? null, $tcLocal['sections'][0]['extra_class'] ?? null], ['images/joomlart/bg/new-hero.jpg', 't4-hero']);
check('template profiles: the site reads the new words and picture back', $tcCurrent(),
    array_replace($tcSamples, ["templateConfig-{$tcNav}.0" => 'Our Work', "templateConfig-{$tcHome}.0" => 'images/joomlart/bg/new-hero.jpg', "fieldValue-{$tcSkill}.0" => 'Strategy 80%']));
check('template profiles: writing a profile\'s local copy is not drift', $tcContract->driftWarnings(), []);

$tcReverted = $tcEngine->handle(['token' => $WTOKEN, 'action' => 'apply.revert', 'params' => ['apply_id' => 'contract-config-1']]);
check('template profiles: the apply is taken back', $tcReverted['ok'] ?? null, true);
check('template profiles: the local copies it created are gone again, and so are their folders', [file_exists($tcLocalNav), file_exists($tcLocalLayout), is_dir($tcDir . '/templates/ja_vega/local')], [false, false, false]);
check('template profiles: after the revert the site reads the demo words and picture', $tcCurrent(), $tcSamples);

// A layout or navigation file in `local/` the contract does not name is still the design's: unexpected.
mkdir($tcDir . '/templates/ja_vega/local/etc/layout', 0777, true);
file_put_contents($tcDir . '/templates/ja_vega/local/etc/layout/blog.json', '{}');
$tcFresh = new QuickstartContract($tcWriter, $tcStore, $tcDir, $tcDir); $tcFresh->inspect();
check('template profiles: a local profile the contract does not name is drift', $tcFresh->driftWarnings(), ['Unexpected presentation file: templates/ja_vega/local/etc/layout/blog.json']);
unlink($tcDir . '/templates/ja_vega/local/etc/layout/blog.json');
rmdir($tcDir . '/templates/ja_vega/local/etc/layout'); rmdir($tcDir . '/templates/ja_vega/local/etc'); rmdir($tcDir . '/templates/ja_vega/local');

// A batch that fails after the files were written takes them back too: they are outside the database transaction.
$tcState = $tcContract->inspect();
$tcWriter->failOn = ['module', 160];
$tcFailed = $tcCall(['operation' => 'apply', 'apply_id' => 'contract-config-2', 'request_id' => 'tc-2', 'expected_revision' => $tcState['revision'],
    'changes' => ["templateConfig-{$tcNav}.0" => 'Our Work', 'module-160.0' => 'The Team']]);
$tcWriter->failOn = null;
check('template profiles: a failed apply leaves no local copy', [$tcFailed['ok'] ?? null, file_exists($tcLocalNav)], [false, false]);

// Outside the contract no door writes a template profile: a plain batch refuses the kind.
$tcBatch = $tcEngine->handle(['token' => $WTOKEN, 'action' => 'content.batch', 'params' => ['apply_id' => 'batch-config-1', 'request_id' => 'tc-3',
    'operations' => [['kind' => 'templateConfig', 'id' => $tcNav, 'fields' => ['value' => '{}']]]]]);
check('template profiles: a plain content batch may not write a template profile', [$tcBatch['ok'] ?? null, file_exists($tcLocalNav)], [false, false]);
