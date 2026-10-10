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
// An AcyMailing form's words live in its params, not in a language file (JA Essence 1.0.4, module 115): "Join 70,000
// subscribers!" and its "Sign up" button stayed English on g58-ess-j-full after the headings were given.
$sltNews = ['title' => 'Newsletter', 'module' => 'mod_acym', 'position' => 'sidebar', 'published' => '1', 'access' => '1', 'language' => '*', 'client_id' => '0',
    'showtitle' => '1', 'content' => '', 'params' => '{"mode":"vertical","subtext":"Sign up","introtext":"Join 70,000 subscribers!","posttext":"","unsubtext":"<b>Bye</b>","moduleclass_sfx":" acymailing-module"}'];
$sltWriter->store['module'][110] = ['id' => '110'] + $sltHero;
$sltWriter->store['module'][114] = ['id' => '114'] + $sltTags;
$sltWriter->store['module'][115] = ['id' => '115'] + $sltNews;
foreach ([110, 114, 115] as $id) $sltWriter->store['moduleAssignment'][$id] = ['menuids' => '[0]'];
$sltWriter->store['languageDefaults'] = ['site' => 'en-GB', 'administrator' => 'en-GB'];
$sltSlots = [];
foreach (ContentSlots::htmlSlots($sltHero['content']) as $n => $s) $sltSlots[] = ['key' => 'hero.' . $n, 'entity' => 'hero', 'column' => 'content', 'maxCharacters' => 80, 'sample' => 'Demo title'] + $s;
foreach ([
    'manifest' => ['id' => 'slt/j6/1.0.0'],
    'content-map' => ['entities' => [
        ['key' => 'hero', 'kind' => 'module', 'sourceId' => 10, 'identity' => ['title' => 'Home hero']],
        ['key' => 'tags', 'kind' => 'module', 'sourceId' => 14, 'identity' => ['title' => 'Tags cloud']],
        ['key' => 'news', 'kind' => 'module', 'sourceId' => 15, 'identity' => ['title' => 'Newsletter']],
    ], 'slots' => $sltSlots, 'pages' => []],
    'presentation-lock' => ['entities' => ['hero' => $sltHero, 'tags' => $sltTags, 'news' => $sltNews],
        'assignments' => [['moduleid' => 10, 'menuid' => 0], ['moduleid' => 14, 'menuid' => 0], ['moduleid' => 15, 'menuid' => 0]],
        'fileRoots' => [], 'files' => [], 'inventoryCounts' => ['module' => 3], 'access' => $sltStore->acl],
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
check('a site-language plan names the headings shown and the module words a visitor reads, and only those', $plan['titles'] ?? null, [
    ['key' => 'news.title', 'source' => 'Newsletter', 'maxCharacters' => 100],
    ['key' => 'tags.title', 'source' => 'Tags cloud', 'maxCharacters' => 100],
    ['key' => 'news.params.introtext', 'source' => 'Join 70,000 subscribers!', 'maxCharacters' => 400],
    ['key' => 'news.params.subtext', 'source' => 'Sign up', 'maxCharacters' => 400],
]);
$set = fn(array $extra = []) => $sltCall($extra + ['operation' => 'siteLanguage.set', 'locale' => 'vi-VN', 'apply_id' => 'slang-t', 'request_id' => 't1']);
check('a heading the plan does not name is refused', $set(['titles' => ['hero.title' => 'Anh hùng']])['error'] ?? null, 'bad_params');
check('a heading with markup is refused', $set(['titles' => ['tags.title' => '<b>Thẻ</b>']])['error'] ?? null, 'bad_params');
check('a module word the plan does not name is refused', $set(['titles' => ['news.params.moduleclass_sfx' => 'x']])['error'] ?? null, 'bad_params');
check('a module word with markup is refused', $set(['titles' => ['news.params.introtext' => '<i>Tham gia</i>']])['error'] ?? null, 'bad_params');
check('nothing was written by a refusal', [$sltWriter->store['languageDefaults']['site'], $sltWriter->store['module'][114]['title'], $sltWriter->store['module'][115]['params']],
    ['en-GB', 'Tags cloud', $sltNews['params']]);
$done = $set(['titles' => ['tags.title' => 'Đám mây thẻ', 'news.title' => 'Bản tin', 'news.params.introtext' => 'Nhận tin mới mỗi tuần!', 'news.params.subtext' => 'Đăng ký']]);
$newsParams = json_decode((string) $sltWriter->store['module'][115]['params'], true);
check('the language, the headings and the module words are set together', [$done['ok'] ?? null, $done['titles'] ?? null, $sltWriter->store['languageDefaults']['site'],
    $sltWriter->store['module'][114]['title'], $newsParams['introtext'] ?? null, $newsParams['subtext'] ?? null],
    [true, 4, 'vi-VN', 'Đám mây thẻ', 'Nhận tin mới mỗi tuần!', 'Đăng ký']);
check('the other params of the module are untouched', [$newsParams['mode'] ?? null, $newsParams['moduleclass_sfx'] ?? null, $newsParams['unsubtext'] ?? null], ['vertical', ' acymailing-module', '<b>Bye</b>']);
check('the old heading and words are on record', [$sltStore->binding['siteLanguage']['titles'] ?? null, $sltStore->binding['siteLanguage']['texts'] ?? null],
    [['tags' => 'Tags cloud', 'news' => 'Newsletter'], ['news' => ['introtext' => 'Join 70,000 subscribers!', 'subtext' => 'Sign up']]]);
check('and the site still inspects clean with it', $sltContract->inspect()['contract'], 'slt/j6/1.0.0');
$back = $sltCall(['operation' => 'siteLanguage.revert', 'apply_id' => 'slang-tu', 'request_id' => 'tu1']);
$newsParams = json_decode((string) $sltWriter->store['module'][115]['params'], true);
check('revert gives the heading and the words back with the language', [$back['ok'] ?? null, $sltWriter->store['languageDefaults']['site'],
    $sltWriter->store['module'][114]['title'], $newsParams['introtext'] ?? null, $newsParams['subtext'] ?? null], [true, 'en-GB', 'Tags cloud', 'Join 70,000 subscribers!', 'Sign up']);
check('the reverted site inspects clean', $sltContract->inspect()['contract'], 'slt/j6/1.0.0');
