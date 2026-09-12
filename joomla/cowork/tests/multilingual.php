<?php
// Loaded by run.php: the rules a language version is built on, and the refusals that bound it.
// The end-to-end phase run is exercised against a real Joomla in QA; what is tested here is every
// decision that has one right answer and no server to ask.

/* ---------------------------------------------------------------- what a translation may lose */

$keep = [
    ['Plans from $29 per month', '方案每月 $29 起', true],
    ['Plans from $29 per month', '方案每月起', false],
    ['Save 30% today', '今天节省 30%', true],
    ['Save 30% today', '今天节省', false],
    ['Write to hello@tracy.ai', '写信至 hello@tracy.ai', true],
    ['Write to hello@tracy.ai', '写信给我们', false],
    ['See https://tracy.ai/docs', '见 https://tracy.ai/docs', true],
    ['See https://tracy.ai/docs', '见文档', false],
    ['Over 1,200 teams', '超过 1,200 个团队', true],
    ['Over 1,200 teams', '超过一千个团队', false],
    ['Welcome, %s', '欢迎，%s', true],
    ['Welcome, %s', '欢迎', false],
    ['Response in 1.5 seconds', '1.5 秒内响应', true],
    ['Response in 1.5 seconds', '一秒半内响应', false],
];
foreach ($keep as [$source, $target, $ok]) {
    $errors = MultilingualProfile::preservationErrors($source, $target);
    check('translation of "' . $source . '" ' . ($ok ? 'keeps its facts' : 'is refused'), $errors === [], $ok);
}
// A single digit is a WORD in most languages and a fact in none: refusing its transliteration
// would refuse correct translations, which is a worse failure than missing a "1".
check('a single digit may be written out', MultilingualProfile::preservationErrors('Chapter 1', '第一章'), []);
check('a figure the source repeats must be kept as often', count(MultilingualProfile::preservationErrors('$10 and $10', 'only $10')), 1);

/* ---------------------------------------------------------------- the profile and its pinning */

$mlDir = sys_get_temp_dir() . '/cowork-ml-' . bin2hex(random_bytes(6));
mkdir($mlDir, 0777, true);
$mlMap = [
    'entities' => [
        ['key' => 'article-1', 'kind' => 'article', 'sourceId' => 1, 'identity' => ['alias' => 'home']],
        ['key' => 'module-2', 'kind' => 'module', 'sourceId' => 2, 'identity' => ['title' => 'Hero']],
        ['key' => 'module-3', 'kind' => 'module', 'sourceId' => 3, 'identity' => ['title' => 'Latest']],
        ['key' => 'menuItem-4', 'kind' => 'menuItem', 'sourceId' => 4, 'identity' => ['path' => 'home']],
        ['key' => 'category-5', 'kind' => 'category', 'sourceId' => 5, 'identity' => ['path' => 'blog']],
    ],
    'slots' => [
        ['key' => 'article-1.0', 'entity' => 'article-1', 'column' => 'title', 'type' => 'text', 'sample' => 'Home', 'maxCharacters' => 40],
        ['key' => 'module-2.0', 'entity' => 'module-2', 'column' => 'content', 'type' => 'text', 'sample' => 'Hi', 'maxCharacters' => 40, 'xpath' => '/html/body/div/p/text()'],
    ],
    'pages' => [],
];
$mlLock = [
    'entities' => [
        'article-1' => ['title' => 'Home', 'alias' => 'home', 'catid' => '5', 'language' => '*'],
        'module-2' => ['title' => 'Hero', 'showtitle' => '0', 'note' => '', 'language' => '*', 'position' => 'top'],
        'module-3' => ['title' => 'Latest', 'showtitle' => '1', 'note' => '', 'language' => '*', 'position' => 'side'],
        'menuItem-4' => ['title' => 'Home', 'alias' => 'home', 'link' => 'index.php?option=com_content&view=article&id=1', 'parent_id' => '1', 'img' => '', 'language' => '*'],
        'category-5' => ['title' => 'Blog', 'language' => '*'],
    ],
    'assignments' => [], 'fileRoots' => [], 'files' => [], 'inventoryCounts' => [], 'access' => [],
];
foreach (['content-map' => $mlMap, 'presentation-lock' => $mlLock, 'manifest' => ['id' => 'test/ml']] as $name => $body)
    file_put_contents($mlDir . '/' . $name . '.json', json_encode($body));
$mlBase = hash('sha256', implode('', array_map(
    fn ($n) => $n . ':' . hash_file('sha256', $mlDir . '/' . $n) . "\n",
    ['manifest.json', 'content-map.json', 'presentation-lock.json']
)));
$mlProfile = [
    'schemaVersion' => 'tracy-quickstart-multilingual/v1', 'extensionVersion' => '1.0.0',
    'contract' => 'test/ml', 'baseHash' => $mlBase, 'sourceLanguage' => 'en-GB',
    'derive' => [
        'article' => ['create' => 'row', 'carry' => ['catid'], 'translate' => ['title'], 'alias' => 'source-sef', 'association' => 'articleAssociation'],
        'module' => ['create' => 'row', 'carry' => ['position'], 'translate' => ['content'], 'note' => 'tracy-ml:{sourceKey}:{locale}', 'assignments' => 'map'],
        'menuItem' => ['create' => 'nested', 'carry' => [], 'translate' => ['title'], 'alias' => 'source', 'parent' => 'map', 'link' => 'remap-ids', 'home' => 'mirror', 'association' => 'menuAssociation'],
    ],
    'sourceDelta' => ['language' => ['from' => '*', 'to' => 'en-GB', 'entities' => ['article-1', 'module-2', 'module-3', 'menuItem-4']]],
    'policies' => [
        'article-1' => ['kind' => 'article', 'policy' => 'translate'],
        'module-2' => ['kind' => 'module', 'policy' => 'translate'],
        'module-3' => ['kind' => 'module', 'policy' => 'translate'],
        'menuItem-4' => ['kind' => 'menuItem', 'policy' => 'translate'],
        'category-5' => ['kind' => 'category', 'policy' => 'shared', 'reason' => 'no category content slot'],
    ],
    'switcher' => ['module' => 'mod_languages', 'position' => 'top', 'anchorEntity' => 'module-2', 'language' => '*',
        'showtitle' => '0', 'access' => '1', 'published' => '1', 'assignment' => 'all', 'note' => 'tracy-ml:switcher', 'params' => ['inline' => 1]],
    'languageFilter' => ['element' => 'languagefilter', 'folder' => 'system', 'enabled' => 1, 'params' => ['item_associations' => 1]],
    'unsupported' => [], 'aliasPolicy' => 'shared-with-source',
];
$mlRaw = json_encode($mlProfile);
$profile = new MultilingualProfile($mlProfile, $mlMap, $mlLock, $mlDir, $mlRaw);

check('a profile knows which entities get a copy', $profile->translatedKeys(), ['article-1', 'module-2', 'module-3', 'menuItem-4']);
check('a shared entity says why', $profile->coverage()['shared'][0]['reason'], 'no category content slot');

// The pinning is the whole point of a separately versioned extension: it may not be applied to a
// contract it was not generated from.
$wrong = $mlProfile; $wrong['baseHash'] = str_repeat('0', 64);
contractRejects('a profile pinned to another contract is refused', fn () => new MultilingualProfile($wrong, $mlMap, $mlLock, $mlDir, json_encode($wrong)));
$short = $mlProfile; unset($short['policies']['category-5']);
contractRejects('an entity with no policy is refused, not silently skipped', fn () => new MultilingualProfile($short, $mlMap, $mlLock, $mlDir, json_encode($short)));
$old = $mlProfile; $old['schemaVersion'] = 'tracy-quickstart-multilingual/v0';
contractRejects('an unknown profile schema is refused', fn () => new MultilingualProfile($old, $mlMap, $mlLock, $mlDir, json_encode($old)));

/* ---------------------------------------------------------------- the two alias rules */

// Two rules because Joomla has two rules: `#__menu` is unique on (parent, alias, LANGUAGE) so a
// translated page keeps the source's path under a second prefix; `Table\Content::store()` checks
// (alias, catid) and ignores language entirely, so an article copy must differ.
check('a menu copy keeps its source path', $profile->derivedAlias('menuItem', 'style-guide', 'zh-CN'), 'style-guide');
check('an article copy cannot', $profile->derivedAlias('article', 'style-guide', 'zh-CN'), 'style-guide-zh');
check('the URL segment comes from the tag', MultilingualProfile::sefOf('zh-CN'), 'zh');

/* ---------------------------------------------------------------- what a copied link points at */

$idMap = ['article' => [1 => 101], 'menuItem' => [4 => 104]];
check('an article reference follows its copy', $profile->remapLink('index.php?option=com_content&view=article&id=1', $idMap), 'index.php?option=com_content&view=article&id=101');
check('an Itemid follows its copy', $profile->remapLink('index.php?Itemid=4', $idMap), 'index.php?Itemid=104');
check('an id with no copy is left alone', $profile->remapLink('index.php?view=article&id=99', $idMap), 'index.php?view=article&id=99');
// A raw path needs no rewriting BECAUSE a menu copy keeps its alias; rewriting it would break it.
check('a raw path is never rewritten', $profile->remapLink('/resources/style-guide', $idMap), '/resources/style-guide');
// A supplier's catalogue page at `?id=1` is not this site's article 1.
check('an external link is never rewritten', $profile->remapLink('https://example.com/?id=1', $idMap), 'https://example.com/?id=1');
check('a protocol-relative link is never rewritten', $profile->remapLink('//cdn.example.com/?id=1', $idMap), '//cdn.example.com/?id=1');
check('a mail address is never rewritten', $profile->remapLink('mailto:sales@example.com?id=1', $idMap), 'mailto:sales@example.com?id=1');

/* ---------------------------------------------------------------- how a copy is checked */

$maskedSource = ['title' => 'Hero', 'showtitle' => '0', 'note' => '', 'language' => '*', 'position' => 'top'];
$copy = $profile->derivedPresentation('module', 'module-2', $maskedSource, 'zh-CN', [], $mlLock['entities']['module-2']);
check('a hidden module title stays readable in the admin', $copy['title'], 'Hero [zh-CN]');
check('a copy carries its provenance', $copy['note'], 'tracy-ml:module-2:zh-CN');
check('a copy is in its own language', $copy['language'], 'zh-CN');
check('a copy keeps its position', $copy['position'], 'top');
$shown = $profile->derivedPresentation('module', 'module-3', ['title' => 'Latest', 'showtitle' => '1', 'note' => '', 'language' => '*', 'position' => 'side'], 'zh-CN', [], $mlLock['entities']['module-3']);
// A shown title is words on a page, so it is CONTENT on the copy and compares as a slot — the one
// field whose masking differs between a source and its copy.
check('a shown module title is content on the copy', $shown['title'], MultilingualProfile::CONTENT_SLOT);
check('a shown module gets a title slot', count($profile->derivedSlots('zh-CN', 'module-3', $mlLock['entities']['module-3'])), 1);
check('a hidden one does not', count($profile->derivedSlots('zh-CN', 'module-2', $mlLock['entities']['module-2'])), 1);
$slots = $profile->derivedSlots('zh-CN', 'article-1', $mlLock['entities']['article-1']);
check('a copy\'s slots are keyed to the copy', $slots[0]['key'], 'zh-CN::article-1.0');
check('and remember where they came from', $slots[0]['sourceKey'], 'article-1.0');
// Evidence proves a factual CLAIM against the brief. A translation restates a claim already
// approved in the source, so requiring brief-verbatim text would refuse every correct translation.
check('a translation is never asked for brief evidence', $slots[0]['requiresEvidence'], false);

// Joomla writes a single space where a menu item has no image (Table\Menu::check), while the
// published archive carries an empty string. Both mean "none".
$menuCopy = $profile->derivedPresentation('menuItem', 'menuItem-4', $mlLock['entities']['menuItem-4'], 'zh-CN', $idMap, $mlLock['entities']['menuItem-4'], ['img' => ' ']);
check('the two spellings of "no image" are one fact', $menuCopy['img'], ' ');
check('a copied menu link follows the copies', $menuCopy['link'], 'index.php?option=com_content&view=article&id=101');

/* ---------------------------------------------------------------- which packages may be installed */

$catalogData = ['schemaVersion' => 'tracy-joomla-language-packs/v1', 'version' => '1.0.0', 'packs' => [
    'zh-CN' => ['tag' => 'zh-CN', 'name' => 'Chinese (Simplified)', 'version' => '6.0.1.4',
        'url' => 'https://downloads.joomla.org/x?format=zip', 'sha256' => str_repeat('a', 64), 'bytes' => 619023, 'platformMajors' => [6]],
]];
$packs = new LanguagePackCatalog($catalogData, 'en-GB');
check('the source language needs no package', $packs->pack('en-GB', 6), null);
check('a verified locale resolves to its pinned archive', $packs->pack('zh-CN', 6)['bytes'], 619023);
check('the offered locales lead with the source', $packs->locales(6), ['en-GB', 'zh-CN']);
contractRejects('an unverified locale is refused by name', fn () => $packs->pack('de-DE', 6));
contractRejects('a pack that does not cover this Joomla is refused', fn () => $packs->pack('zh-CN', 5));
foreach ([
    'a non-https url' => ['url', 'http://downloads.joomla.org/x'],
    'a url off Joomla\'s own host' => ['url', 'https://example.invalid/x.zip'],
    'a short hash' => ['sha256', 'abc'],
    'no byte count' => ['bytes', null],
    'a tag its key disagrees with' => ['tag', 'zh-TW'],
    'no platform majors' => ['platformMajors', []],
] as $why => [$field, $value]) {
    $broken = $catalogData;
    $broken['packs']['zh-CN'][$field] = $value;
    contractRejects('a catalog with ' . $why . ' is refused', fn () => new LanguagePackCatalog($broken, 'en-GB'));
}

array_map('unlink', glob($mlDir . '/*.json'));
rmdir($mlDir);
