<?php
// Loaded by run.php, after site-language.php (it reuses LanguageTestExtensions): a one-edition site given ONE language
// (`siteLanguage.set`) gives the module headings the contract has no slot for in it too. Measured 10/10/2026 on dev
// g58-ess-j-full (JA Essence j6 1.0.4, Vietnamese): "Newsletter", "Tags cloud", "Follow me", "More reading" and
// "Features post" stayed English over Vietnamese words (TracyHQ/tch#1013, LANG-2).

$sltRoot = sys_get_temp_dir() . '/cowork-site-language-titles-' . bin2hex(random_bytes(6));
$sltDir = $sltRoot . '/lib/contracts/slt/j6/1.0.0';
mkdir($sltDir, 0777, true);
$sltStore = new TestContractStore();
$sltLog = new FakeApplyLog();
$sltWriter = new ContractTestWriter($sltLog, $sltStore);
$sltHero = ['title' => 'Home hero', 'module' => 'mod_custom', 'position' => 'masthead', 'published' => '1', 'access' => '1', 'language' => '*', 'client_id' => '0',
    'showtitle' => '0', 'content' => '<h1>Demo title</h1>', 'params' => '{"style":"0"}'];
$sltTags = ['title' => 'Tags cloud', 'module' => 'mod_tags_popular', 'position' => 'sidebar', 'published' => '1', 'access' => '1', 'language' => '*', 'client_id' => '0',
    'showtitle' => '1', 'content' => '', 'params' => '{}'];
$sltWriter->store['module'][110] = ['id' => '110'] + $sltHero;
$sltWriter->store['module'][114] = ['id' => '114'] + $sltTags;
foreach ([110, 114] as $id) $sltWriter->store['moduleAssignment'][$id] = ['menuids' => '[0]'];
$sltWriter->store['languageDefaults'] = ['site' => 'en-GB', 'administrator' => 'en-GB'];
$sltSlots = [];
foreach (ContentSlots::htmlSlots($sltHero['content']) as $n => $s) $sltSlots[] = ['key' => 'hero.' . $n, 'entity' => 'hero', 'column' => 'content', 'maxCharacters' => 80, 'sample' => 'Demo title'] + $s;
foreach ([
    'manifest' => ['id' => 'slt/j6/1.0.0'],
    'content-map' => ['entities' => [
        ['key' => 'hero', 'kind' => 'module', 'sourceId' => 10, 'identity' => ['title' => 'Home hero']],
        ['key' => 'tags', 'kind' => 'module', 'sourceId' => 14, 'identity' => ['title' => 'Tags cloud']],
    ], 'slots' => $sltSlots, 'pages' => []],
    'presentation-lock' => ['entities' => ['hero' => $sltHero, 'tags' => $sltTags], 'assignments' => [['moduleid' => 10, 'menuid' => 0], ['moduleid' => 14, 'menuid' => 0]],
        'fileRoots' => [], 'files' => [], 'inventoryCounts' => ['module' => 2], 'access' => $sltStore->acl],
] as $name => $body) file_put_contents($sltDir . '/' . $name . '.json', json_encode($body));
file_put_contents($sltRoot . '/lib/language-packs.json', json_encode([
    'schemaVersion' => 'tracy-joomla-language-packs/v1',
    'packs' => ['vi-VN' => ['tag' => 'vi-VN', 'name' => 'Vietnamese', 'version' => '4.2.2.1', 'url' => 'https://downloads.joomla.org/vi.zip',
        'sha256' => str_repeat('c', 64), 'bytes' => 754892, 'platformMajors' => [4, 6]]],
]));
$sltContract = new QuickstartContract($sltWriter, $sltStore, $sltDir, $sltDir);
$sltEngine = new Engine($WTOKEN, ['joomla' => '6.1.2'], null, null, null, new LanguageTestExtensions(), $sltWriter, null, $sltLog, null, null, null, $sltContract);
$sltCall = fn(array $params) => $sltEngine->handle(['token' => $WTOKEN, 'action' => 'content.contract', 'params' => $params]);
$sltWriter->transaction(function () use ($sltContract) { $sltContract->bind($sltContract->inspect()['snapshot']); return []; });

$plan = $sltCall(['operation' => 'siteLanguage.plan', 'locale' => 'vi-VN']);
check('a site-language plan names the headings shown, and only those', $plan['titles'] ?? null, [['key' => 'tags.title', 'source' => 'Tags cloud', 'maxCharacters' => 100]]);
$set = fn(array $extra = []) => $sltCall($extra + ['operation' => 'siteLanguage.set', 'locale' => 'vi-VN', 'apply_id' => 'slang-t', 'request_id' => 't1']);
check('a heading the plan does not name is refused', $set(['titles' => ['hero.title' => 'Anh hùng']])['error'] ?? null, 'bad_params');
check('a heading with markup is refused', $set(['titles' => ['tags.title' => '<b>Thẻ</b>']])['error'] ?? null, 'bad_params');
check('nothing was written by a refusal', [$sltWriter->store['languageDefaults']['site'], $sltWriter->store['module'][114]['title']], ['en-GB', 'Tags cloud']);
$done = $set(['titles' => ['tags.title' => 'Đám mây thẻ']]);
check('the language and the heading are set together', [$done['ok'] ?? null, $done['titles'] ?? null, $sltWriter->store['languageDefaults']['site'], $sltWriter->store['module'][114]['title']], [true, 1, 'vi-VN', 'Đám mây thẻ']);
check('the old heading is on record', $sltStore->binding['siteLanguage']['titles'] ?? null, ['tags' => 'Tags cloud']);
check('and the site still inspects clean with it', $sltContract->inspect()['contract'], 'slt/j6/1.0.0');
$back = $sltCall(['operation' => 'siteLanguage.revert', 'apply_id' => 'slang-tu', 'request_id' => 'tu1']);
check('revert gives the heading back with the language', [$back['ok'] ?? null, $sltWriter->store['languageDefaults']['site'], $sltWriter->store['module'][114]['title']], [true, 'en-GB', 'Tags cloud']);
check('the reverted site inspects clean', $sltContract->inspect()['contract'], 'slt/j6/1.0.0');
