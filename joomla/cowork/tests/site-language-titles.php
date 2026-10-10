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
    'showtitle' => '0', 'content' => '<h1>Demo title</h1>', 'params' => '{"style":"0","sub-heading":"<span>New</span> arrivals","module-intro":"Fresh from the oven"}'];
$sltTags = ['title' => 'Tags cloud', 'module' => 'mod_tags_popular', 'position' => 'sidebar', 'published' => '1', 'access' => '1', 'language' => '*', 'client_id' => '0',
    'showtitle' => '1', 'content' => '', 'params' => '{"mod-desc":"Articles of the day","main-heading":"{loadposition x}","title-btn":"","module_tag":"div"}'];
// T4 and T3 give any module a description, sub-heading or button title in its params ("Articles of the day" above
// JA Essence's module 129, a mod_articles_category); a value with markup is left alone.
// An AcyMailing form's words live in its params, not in a language file (JA Essence 1.0.4, module 115): "Join 70,000
// subscribers!" and its "Sign up" button stayed English on g58-ess-j-full after the headings were given.
$sltNews = ['title' => 'Newsletter', 'module' => 'mod_acym', 'position' => 'sidebar', 'published' => '1', 'access' => '1', 'language' => '*', 'client_id' => '0',
    'showtitle' => '1', 'content' => '', 'params' => '{"mode":"vertical","subtext":"Sign up","introtext":"Join 70,000 subscribers!","posttext":"","unsubtext":"<b>Bye</b>","moduleclass_sfx":" acymailing-module"}'];
// A list module prints its dates with a format of its own, in English order: "Jun 09, 2023" on a vi-VN site (JA
// Essence 1.0.4, 10 modules; 32 mod_articles_category in the published contracts carry "M d, Y").
$sltList = ['title' => 'Latest', 'module' => 'mod_articles_category', 'position' => 'sidebar', 'published' => '1', 'access' => '1', 'language' => '*', 'client_id' => '0',
    'showtitle' => '0', 'content' => '', 'params' => '{"show_date_format":"M d, Y","date_format":"Y-m-d","month_year_format":"F Y"}'];
$sltWriter->store['module'][116] = ['id' => '116'] + $sltList;
$sltWriter->store['module'][110] = ['id' => '110'] + $sltHero;
$sltWriter->store['module'][114] = ['id' => '114'] + $sltTags;
$sltWriter->store['module'][115] = ['id' => '115'] + $sltNews;
foreach ([110, 114, 115, 116] as $id) $sltWriter->store['moduleAssignment'][$id] = ['menuids' => '[0]'];
$sltWriter->store['languageDefaults'] = ['site' => 'en-GB', 'administrator' => 'en-GB'];
$sltSlots = [];
foreach (ContentSlots::htmlSlots($sltHero['content']) as $n => $s) $sltSlots[] = ['key' => 'hero.' . $n, 'entity' => 'hero', 'column' => 'content', 'maxCharacters' => 80, 'sample' => 'Demo title'] + $s;
foreach ([
    'manifest' => ['id' => 'slt/j6/1.0.0'],
    'content-map' => ['entities' => [
        ['key' => 'hero', 'kind' => 'module', 'sourceId' => 10, 'identity' => ['title' => 'Home hero']],
        ['key' => 'tags', 'kind' => 'module', 'sourceId' => 14, 'identity' => ['title' => 'Tags cloud']],
        ['key' => 'news', 'kind' => 'module', 'sourceId' => 15, 'identity' => ['title' => 'Newsletter']],
        ['key' => 'list', 'kind' => 'module', 'sourceId' => 16, 'identity' => ['title' => 'Latest']],
    ], 'slots' => $sltSlots, 'pages' => []],
    'presentation-lock' => ['entities' => ['hero' => $sltHero, 'tags' => $sltTags, 'news' => $sltNews, 'list' => $sltList],
        'assignments' => [['moduleid' => 10, 'menuid' => 0], ['moduleid' => 14, 'menuid' => 0], ['moduleid' => 15, 'menuid' => 0], ['moduleid' => 16, 'menuid' => 0]],
        'fileRoots' => [], 'files' => [], 'inventoryCounts' => ['module' => 4], 'access' => $sltStore->acl],
] as $name => $body) file_put_contents($sltDir . '/' . $name . '.json', json_encode($body));
file_put_contents($sltRoot . '/lib/language-packs.json', json_encode([
    'schemaVersion' => 'tracy-joomla-language-packs/v1',
    'packs' => ['vi-VN' => ['tag' => 'vi-VN', 'name' => 'Vietnamese', 'version' => '4.2.2.1', 'url' => 'https://downloads.joomla.org/vi.zip',
        'sha256' => str_repeat('c', 64), 'bytes' => 754892, 'platformMajors' => [4, 6]]],
]));
// The vi-VN pack's own date format, as the pack ships it (4.2.2.1, language/vi-VN/joomla.ini).
mkdir($sltRoot . '/site/language/vi-VN', 0777, true);
file_put_contents($sltRoot . '/site/language/vi-VN/joomla.ini', "DATE_FORMAT_LC1=\"l, d F Y\"\nDATE_FORMAT_LC3=\"d F Y\"\nJUNE=\"Tháng sáu\"\n");
$sltContract = new QuickstartContract($sltWriter, $sltStore, $sltDir, $sltDir);
$sltEngine = (new Engine($WTOKEN, ['joomla' => '6.1.2'], null, null, null, new LanguageTestExtensions(), $sltWriter, null, $sltLog, null, null, null, $sltContract))
    ->languageOverrides(new LanguageOverrides($sltRoot . '/site'));
$sltCall = fn(array $params) => $sltEngine->handle(['token' => $WTOKEN, 'action' => 'content.contract', 'params' => $params]);
$sltWriter->transaction(function () use ($sltContract) { $sltContract->bind($sltContract->inspect()['snapshot']); return []; });

$plan = $sltCall(['operation' => 'siteLanguage.plan', 'locale' => 'vi-VN']);
check('a site-language plan names the headings shown and the module words a visitor reads, and only those', $plan['titles'] ?? null, [
    ['key' => 'news.title', 'source' => 'Newsletter', 'maxCharacters' => 100],
    ['key' => 'tags.title', 'source' => 'Tags cloud', 'maxCharacters' => 100],
    ['key' => 'hero.params.module-intro', 'source' => 'Fresh from the oven', 'maxCharacters' => 400],
    ['key' => 'news.params.introtext', 'source' => 'Join 70,000 subscribers!', 'maxCharacters' => 400],
    ['key' => 'news.params.subtext', 'source' => 'Sign up', 'maxCharacters' => 400],
    ['key' => 'tags.params.mod-desc', 'source' => 'Articles of the day', 'maxCharacters' => 400],
]);
check('a template chrome value with markup or a load tag is not offered', array_values(array_filter(array_column($plan['titles'] ?? [], 'key'), fn($k) => in_array($k, ['hero.params.sub-heading', 'tags.params.main-heading'], true))), []);
check('the plan names the date formats it will set in the language\'s own order', $plan['dates'] ?? null, [
    ['key' => 'list.params.show_date_format', 'source' => 'M d, Y', 'format' => 'd F Y'],
]);
check('a format with the month word before the day is English order; a day-first or numeric one is not', array_map([QuickstartContract::class, 'monthBeforeDay'],
    ['M d, Y', 'F j, Y', 'D, M j Y', 'd M, Y', 'Y-m-d H:i:s', 'F Y', '\\M d Y', 'M d']), [true, true, true, false, false, false, false, false]);
$set = fn(array $extra = []) => $sltCall($extra + ['operation' => 'siteLanguage.set', 'locale' => 'vi-VN', 'apply_id' => 'slang-t', 'request_id' => 't1']);
check('a heading the plan does not name is refused', $set(['titles' => ['hero.title' => 'Anh hùng']])['error'] ?? null, 'bad_params');
check('a heading with markup is refused', $set(['titles' => ['tags.title' => '<b>Thẻ</b>']])['error'] ?? null, 'bad_params');
check('a module word the plan does not name is refused', $set(['titles' => ['news.params.moduleclass_sfx' => 'x']])['error'] ?? null, 'bad_params');
check('a module word with markup is refused', $set(['titles' => ['news.params.introtext' => '<i>Tham gia</i>']])['error'] ?? null, 'bad_params');
check('nothing was written by a refusal', [$sltWriter->store['languageDefaults']['site'], $sltWriter->store['module'][114]['title'], $sltWriter->store['module'][115]['params']],
    ['en-GB', 'Tags cloud', $sltNews['params']]);
$done = $set(['titles' => ['tags.title' => 'Đám mây thẻ', 'news.title' => 'Bản tin', 'news.params.introtext' => 'Nhận tin mới mỗi tuần!', 'news.params.subtext' => 'Đăng ký', 'tags.params.mod-desc' => 'Bài viết trong ngày']]);
$listParams = json_decode((string) $sltWriter->store['module'][116]['params'], true);
check('the module date reads in the language\'s own order; a numeric format and the month-year heading stay',
    [$done['dates'] ?? null, $listParams['show_date_format'] ?? null, $listParams['date_format'] ?? null, $listParams['month_year_format'] ?? null], [1, 'd F Y', 'Y-m-d', 'F Y']);
$tagsParams = json_decode((string) $sltWriter->store['module'][114]['params'], true);
check('a template chrome text is written with the language, its other params kept', [$tagsParams['mod-desc'] ?? null, $tagsParams['main-heading'] ?? null, $tagsParams['module_tag'] ?? null],
    ['Bài viết trong ngày', '{loadposition x}', 'div']);
$newsParams = json_decode((string) $sltWriter->store['module'][115]['params'], true);
check('the language, the headings and the module words are set together', [$done['ok'] ?? null, $done['titles'] ?? null, $sltWriter->store['languageDefaults']['site'],
    $sltWriter->store['module'][114]['title'], $newsParams['introtext'] ?? null, $newsParams['subtext'] ?? null],
    [true, 5, 'vi-VN', 'Đám mây thẻ', 'Nhận tin mới mỗi tuần!', 'Đăng ký']);
check('the other params of the module are untouched', [$newsParams['mode'] ?? null, $newsParams['moduleclass_sfx'] ?? null, $newsParams['unsubtext'] ?? null], ['vertical', ' acymailing-module', '<b>Bye</b>']);
check('the old heading and words are on record', [$sltStore->binding['siteLanguage']['titles'] ?? null, $sltStore->binding['siteLanguage']['texts'] ?? null],
    [['tags' => 'Tags cloud', 'news' => 'Newsletter'], ['news' => ['introtext' => 'Join 70,000 subscribers!', 'subtext' => 'Sign up'], 'tags' => ['mod-desc' => 'Articles of the day'], 'list' => ['show_date_format' => 'M d, Y']]]);
check('and the site still inspects clean with it', $sltContract->inspect()['contract'], 'slt/j6/1.0.0');
$back = $sltCall(['operation' => 'siteLanguage.revert', 'apply_id' => 'slang-tu', 'request_id' => 'tu1']);
$newsParams = json_decode((string) $sltWriter->store['module'][115]['params'], true);
check('revert gives the heading and the words back with the language', [$back['ok'] ?? null, $sltWriter->store['languageDefaults']['site'],
    $sltWriter->store['module'][114]['title'], $newsParams['introtext'] ?? null, $newsParams['subtext'] ?? null], [true, 'en-GB', 'Tags cloud', 'Join 70,000 subscribers!', 'Sign up']);
check('revert gives the module its own date format back', json_decode((string) $sltWriter->store['module'][116]['params'], true)['show_date_format'] ?? null, 'M d, Y');
check('revert gives the template chrome text back', json_decode((string) $sltWriter->store['module'][114]['params'], true)['mod-desc'] ?? null, 'Articles of the day');
check('the reverted site inspects clean', $sltContract->inspect()['contract'], 'slt/j6/1.0.0');
