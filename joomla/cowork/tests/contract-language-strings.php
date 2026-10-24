<?php
// Loaded by run.php after contract-authors.php (reuses TestContractStore, ContractTestWriter, FakeApplyLog).
// A template keeps some of the words a visitor reads in its LANGUAGE FILE, not in a row: JA Boxing's
// "Try a class" button is `Text::_('TPL_TRY_CLASS')`, its contact form says "LEAVE US A MESSAGE", and the
// learning page's heading is `TPL_LEARNING_LEAD_TITLE="The 6 <span>punches</span>"`. TCH capture
// (`--full-coverage`) declares each such constant the template's front-end code names as a `languageString`
// entity, identity {extension, key, language}, whose one column `value` is the string as a visitor reads it:
// the override (`language/overrides/<tag>.override.ini`) when it sets the key, else the language file
// Joomla loads (the site's `language/<tag>/` copy before the template's own), else the key itself (Joomla
// prints a missing key as is). The door writes the override file, never a language file; its undo is the
// file's previous bytes, carried by the apply log; a string with markup is one slot per text in it.

$lsDir = sys_get_temp_dir() . '/cowork-language-strings-' . bin2hex(random_bytes(6));
foreach (['/assets', '/language/en-GB', '/templates/ja_box/language/en-GB'] as $folder) mkdir($lsDir . $folder, 0777, true);
file_put_contents($lsDir . '/assets/site.css', 'body { color: #111 }');
// The template's own copy and the site's copy disagree on one key: Joomla loads the site's file only.
$lsTemplateIni = "; JA Box\nTPL_TRY_CLASS = \"Try a class (template copy)\"\nTPL_LEAD = \"The 6 <span>punches</span>\"\nTPL_UNUSED = \"Not on a page\"\n";
$lsSiteIni = "; JA Box\nTPL_TRY_CLASS = \"Try a class\"\nTPL_LEAD = \"The 6 <span>punches</span>\"\nTPL_SEND = \"Submit\"\nTPL_UNUSED = \"Not on a page\"\n";
$lsSysIni = "TPL_JA_BOX_POSITION_BREADCRUMBS=\"Breadcrumbs\"\n";
file_put_contents($lsDir . '/templates/ja_box/language/en-GB/en-GB.tpl_ja_box.ini', $lsTemplateIni);
file_put_contents($lsDir . '/language/en-GB/en-GB.tpl_ja_box.ini', $lsSiteIni);
file_put_contents($lsDir . '/language/en-GB/en-GB.tpl_ja_box.sys.ini', $lsSysIni);
$lsTry = \LanguageStrings::id('tpl_ja_box', 'en-GB', 'TPL_TRY_CLASS');
$lsLead = \LanguageStrings::id('tpl_ja_box', 'en-GB', 'TPL_LEAD');
$lsFollow = \LanguageStrings::id('tpl_ja_box', 'en-GB', 'TPL_FOLLOW_US');
$lsCrumbs = \LanguageStrings::id('tpl_ja_box.sys', 'en-GB', 'TPL_JA_BOX_POSITION_BREADCRUMBS');
check('language strings: an id is a positive whole number, the same every time', [$lsTry > 0, $lsTry === \LanguageStrings::id('tpl_ja_box', 'en-GB', 'TPL_TRY_CLASS'), $lsTry !== $lsLead], [true, true, true]);
$lsSlots = [
    ['key' => "languageString-{$lsTry}.0", 'entity' => "languageString-{$lsTry}", 'column' => 'value', 'type' => 'text', 'sample' => 'Try a class', 'maxCharacters' => 42, 'customize' => 'keep'],
    ['key' => "languageString-{$lsLead}.0", 'entity' => "languageString-{$lsLead}", 'column' => 'value', 'type' => 'text', 'sample' => 'The 6 ', 'maxCharacters' => 40, 'xpath' => '/html/body/div/text()', 'customize' => 'keep'],
    ['key' => "languageString-{$lsLead}.1", 'entity' => "languageString-{$lsLead}", 'column' => 'value', 'type' => 'text', 'sample' => 'punches', 'maxCharacters' => 40, 'xpath' => '/html/body/div/span/text()', 'customize' => 'keep'],
    ['key' => "languageString-{$lsFollow}.0", 'entity' => "languageString-{$lsFollow}", 'column' => 'value', 'type' => 'text', 'sample' => 'TPL_FOLLOW_US', 'maxCharacters' => 46, 'customize' => 'keep'],
    ['key' => "languageString-{$lsCrumbs}.0", 'entity' => "languageString-{$lsCrumbs}", 'column' => 'value', 'type' => 'text', 'sample' => 'Breadcrumbs', 'maxCharacters' => 42, 'customize' => 'keep'],
    ['key' => 'module-160.0', 'entity' => 'module-160', 'column' => 'title', 'type' => 'text', 'sample' => 'Our Classes', 'maxCharacters' => 40, 'customize' => 'keep'],
];
$lsEntity = static fn(int $id, string $extension, string $key) => ['key' => "languageString-{$id}", 'kind' => 'languageString', 'sourceId' => $id,
    'identity' => ['extension' => $extension, 'key' => $key, 'language' => 'en-GB']];
$lsLock = static fn(string $extension, string $key, string $value) => ['extension' => $extension, 'key' => $key, 'language' => 'en-GB', 'value' => $value];
$lsModule = ['title' => 'Our Classes', 'module' => 'mod_custom', 'position' => 'section-2', 'published' => '1', 'access' => '1', 'language' => '*', 'client_id' => '0', 'content' => '', 'showtitle' => '1', 'params' => '{}'];
$lsStore = new TestContractStore();
$lsFiles = [
    'manifest' => ['id' => 'language-strings/v1'],
    'content-map' => ['entities' => [
        $lsEntity($lsTry, 'tpl_ja_box', 'TPL_TRY_CLASS'), $lsEntity($lsLead, 'tpl_ja_box', 'TPL_LEAD'), $lsEntity($lsFollow, 'tpl_ja_box', 'TPL_FOLLOW_US'),
        $lsEntity($lsCrumbs, 'tpl_ja_box.sys', 'TPL_JA_BOX_POSITION_BREADCRUMBS'),
        ['key' => 'module-160', 'kind' => 'module', 'sourceId' => 160, 'identity' => ['title' => 'Our Classes', 'module' => 'mod_custom']],
    ], 'slots' => $lsSlots, 'pages' => []],
    'presentation-lock' => ['entities' => [
        "languageString-{$lsTry}" => $lsLock('tpl_ja_box', 'TPL_TRY_CLASS', 'Try a class'),
        "languageString-{$lsLead}" => $lsLock('tpl_ja_box', 'TPL_LEAD', 'The 6 <span>punches</span>'),
        "languageString-{$lsFollow}" => $lsLock('tpl_ja_box', 'TPL_FOLLOW_US', 'TPL_FOLLOW_US'),
        "languageString-{$lsCrumbs}" => $lsLock('tpl_ja_box.sys', 'TPL_JA_BOX_POSITION_BREADCRUMBS', 'Breadcrumbs'),
        'module-160' => $lsModule,
    ], 'assignments' => [['moduleid' => 160, 'menuid' => 0]], 'fileRoots' => ['assets'], 'files' => ['assets/site.css' => hash_file('sha256', $lsDir . '/assets/site.css')],
        'inventoryCounts' => ['module' => 1], 'access' => $lsStore->acl],
];
foreach ($lsFiles as $name => $body) file_put_contents($lsDir . '/' . $name . '.json', json_encode($body));
$lsLog = new FakeApplyLog();
$lsWriter = new ContractTestWriter($lsLog, $lsStore);
$lsWriter->store['module'][160] = ['id' => '160'] + $lsModule;
$lsWriter->store['moduleAssignment'][160] = ['menuids' => '[0]'];
$lsContract = new QuickstartContract($lsWriter, $lsStore, $lsDir, $lsDir);
$lsEngine = (new Engine($WTOKEN, [], null, null, null, null, $lsWriter, null, $lsLog, null, null, null, $lsContract))->languageOverrides(new LanguageOverrides($lsDir));
$lsCall = fn(array $params) => $lsEngine->handle(['token' => $WTOKEN, 'action' => 'content.contract', 'params' => $params]);
$lsOverride = $lsDir . '/language/overrides/en-GB.override.ini';
$lsCurrent = static function () use ($lsContract): array { return array_column($lsContract->inspect()['slots'], 'current', 'key'); };

check('language strings: the profile binds', $lsCall(['operation' => 'bind'])['bound'] ?? null, true);
$lsState = $lsContract->inspect();
check('language strings: each string reads as a visitor reads it (the site copy before the template copy, a missing key as itself, a sys string)',
    array_column($lsState['slots'], 'current', 'key'), array_column($lsSlots, 'sample', 'key'));
check('language strings: a freshly bound site has no drift, and its strings are not counted as inventory', $lsContract->driftWarnings(), []);

$lsApplied = $lsCall(['operation' => 'apply', 'apply_id' => 'contract-strings-1', 'request_id' => 'ls-1', 'expected_revision' => $lsState['revision'],
    'changes' => ["languageString-{$lsTry}.0" => 'Book a "free" class', "languageString-{$lsLead}.1" => 'jabs', "languageString-{$lsFollow}.0" => 'Follow us']]);
check('language strings: an apply through the strings commits', $lsApplied['ok'] ?? null, true);
check('language strings: the words land in the override file, markup kept, quotes escaped as Joomla reads them',
    \LanguageOverrides::parse((string) @file_get_contents($lsOverride)),
    ['TPL_TRY_CLASS' => 'Book a "free" class', 'TPL_LEAD' => 'The 6 <span>jabs</span>', 'TPL_FOLLOW_US' => 'Follow us']);
check('language strings: no language file of the site or the template changed',
    [file_get_contents($lsDir . '/language/en-GB/en-GB.tpl_ja_box.ini'), file_get_contents($lsDir . '/templates/ja_box/language/en-GB/en-GB.tpl_ja_box.ini')], [$lsSiteIni, $lsTemplateIni]);
$lsAfter = $lsCurrent();
check('language strings: the site reads the new words back, the untouched text of a marked-up string too',
    [$lsAfter["languageString-{$lsTry}.0"], $lsAfter["languageString-{$lsLead}.0"], $lsAfter["languageString-{$lsLead}.1"], $lsAfter["languageString-{$lsFollow}.0"]],
    ['Book a "free" class', 'The 6 ', 'jabs', 'Follow us']);
check('language strings: writing the words is not drift', $lsContract->driftWarnings(), []);
$lsList = $lsEngine->handle(['token' => $WTOKEN, 'action' => 'apply.list', 'params' => ['apply_id' => 'contract-strings-1']]);
checkTrue('language strings: the apply records the override file it wrote, as a file it created',
    in_array(['op' => 'languageOverrides', 'created' => true, 'files' => ['language/overrides/en-GB.override.ini']], $lsList['steps'] ?? [], true));

$lsReverted = $lsEngine->handle(['token' => $WTOKEN, 'action' => 'apply.revert', 'params' => ['apply_id' => 'contract-strings-1']]);
check('language strings: the apply is taken back', $lsReverted['ok'] ?? null, true);
check('language strings: the override file it created is gone again', file_exists($lsOverride), false);
check('language strings: after the revert the site reads the demo words', $lsCurrent(), array_column($lsSlots, 'sample', 'key'));
check('language strings: a reverted site has no drift', $lsContract->driftWarnings(), []);

// An override file the site already holds: its other lines stay, and a revert gives back its exact bytes.
$lsBefore = "; site overrides\nCOM_CONTENT_READ_MORE=\"Keep reading\"\nTPL_SEND=\"Send it\"\n";
mkdir(dirname($lsOverride));
file_put_contents($lsOverride, $lsBefore);
$lsState = $lsContract->inspect();
$lsApplied = $lsCall(['operation' => 'apply', 'apply_id' => 'contract-strings-2', 'request_id' => 'ls-2', 'expected_revision' => $lsState['revision'],
    'changes' => ["languageString-{$lsCrumbs}.0" => 'You are here']]);
check('language strings: an apply beside other overrides commits', $lsApplied['ok'] ?? null, true);
check('language strings: the other overrides are kept line for line',
    \LanguageOverrides::parse((string) file_get_contents($lsOverride)), ['COM_CONTENT_READ_MORE' => 'Keep reading', 'TPL_SEND' => 'Send it', 'TPL_JA_BOX_POSITION_BREADCRUMBS' => 'You are here']);
$lsEngine->handle(['token' => $WTOKEN, 'action' => 'apply.revert', 'params' => ['apply_id' => 'contract-strings-2']]);
check('language strings: the revert puts the override file back byte for byte', file_get_contents($lsOverride), $lsBefore);

// An override the site sets for a declared key is what a visitor reads.
file_put_contents($lsOverride, $lsBefore . "TPL_TRY_CLASS=\"Try one free\"\n");
check('language strings: an override the site holds wins over the language file', $lsCurrent()["languageString-{$lsTry}.0"], 'Try one free');
file_put_contents($lsOverride, $lsBefore);

// A line break would end the ini line: refused, nothing written.
$lsState = $lsContract->inspect();
$lsRefused = $lsCall(['operation' => 'apply', 'apply_id' => 'contract-strings-3', 'request_id' => 'ls-3', 'expected_revision' => $lsState['revision'],
    'changes' => ["languageString-{$lsTry}.0" => "Try\na class"]]);
check('language strings: a value with a line break is refused and nothing is written', [$lsRefused['ok'] ?? null, file_get_contents($lsOverride)], [false, $lsBefore]);

// A batch that fails after the file was written takes the file back too: it is outside the database transaction.
$lsState = $lsContract->inspect();
$lsWriter->failOn = ['module', 160];
$lsFailed = $lsCall(['operation' => 'apply', 'apply_id' => 'contract-strings-4', 'request_id' => 'ls-4', 'expected_revision' => $lsState['revision'],
    'changes' => ["languageString-{$lsTry}.0" => 'Book a class', 'module-160.0' => 'All classes']]);
$lsWriter->failOn = null;
check('language strings: a failed apply leaves the override file as it was', [$lsFailed['ok'] ?? null, file_get_contents($lsOverride)], [false, $lsBefore]);
check('language strings: and the site reads the demo words', $lsCurrent()["languageString-{$lsTry}.0"], 'Try a class');

// Outside the contract the override file is template.languageOverrides' to write: a plain batch refuses the kind.
$lsBatch = $lsEngine->handle(['token' => $WTOKEN, 'action' => 'content.batch', 'params' => ['apply_id' => 'batch-strings-1', 'request_id' => 'ls-5',
    'operations' => [['kind' => 'languageString', 'id' => $lsTry, 'fields' => ['value' => 'Someone']]]]]);
check('language strings: a plain content batch may not write a language string', [$lsBatch['ok'] ?? null, file_get_contents($lsOverride)], [false, $lsBefore]);
