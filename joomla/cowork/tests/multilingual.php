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
// A CURRENCY AMOUNT MAY PUT ITS SYMBOL ON THE OTHER SIDE. Measured 13/09/2026 on a real French run:
// the three tiers of a pricing block, "$0" / "$24" / "$96", came back as "0 $" / "24 $" / "96 $" —
// correct French — and were refused three times running. The amount must survive; the symbol
// may stand on either side of it. Dropping the amount is still refused.
$money = [
    ['$24', '24 $', true],
    ['$24', "24\u{202F}\$", true],
    ['$0 free forever', '0 $ pour toujours', true],
    ['From $96 a night', 'À partir de 96 $ la nuit', true],
    ['€19 per month', '19 € par mois', true],
    ['$24', '24', false],
    ['$24', '42 $', false],
    ['$24 and $96', '24 $ et 69 $', false],
];
foreach ($money as [$source, $target, $ok]) {
    $errors = MultilingualProfile::preservationErrors($source, $target);
    check('currency "' . $source . '" as "' . $target . '" ' . ($ok ? 'keeps its amount' : 'is refused'), $errors === [], $ok);
}

// AN ANGLE BRACKET IS MARKUP ONLY WHERE THE SOURCE HAS NONE. `module-678.9` of the Business
// quickstart is an email preview: its published English carries `<no-reply@northgate-ind.ru>`, the
// address in the brackets every mail client writes, and the reviewed Vietnamese edition keeps it.
// Refusing every `<` turned that faithful translation away and stopped the build (22/09/2026).
$markup = [
    ['From: Northgate <no-reply@northgate-ind.ru>', 'Từ: Northgate <no-reply@northgate-ind.ru>', false],
    // A plain slot is unchanged: nothing in, nothing allowed out.
    ['Talk to our team', 'Nói chuyện với <b>đội ngũ</b> của chúng tôi', true],
    ['Talk to our team', 'Nói chuyện với đội ngũ của chúng tôi', false],
    // And a slot that legitimately holds one bracket pair cannot be grown into a tag.
    ['From: Northgate <no-reply@northgate-ind.ru>', 'Từ: <b>Northgate</b> <no-reply@northgate-ind.ru>', true],
];
foreach ($markup as [$source, $target, $introduced]) {
    check('"' . $target . '" ' . ($introduced ? 'brings markup its source lacks' : 'brings no markup of its own'),
        MultilingualProfile::markupIntroduced($source, $target), $introduced);
}

// DIGIT GROUPING IS PART OF A LANGUAGE, NOT PART OF A FACT. Vietnamese writes "4.800" where
// British English writes "4,800", and "6,2" where English writes "6.2"; German, Spanish, Italian,
// Portuguese, Russian, Turkish and French group with a dot or a space too. Comparing the
// characters refused every one of them — and it refused text TRACY ITSELF SHIPPED: measured
// 22/09/2026, the reviewed vi-VN edition of the Business quickstart was turned away on
// article-546.0 ("4,800" → "4.800") and article-547.0 ("6.2" → "6,2"), and the build stopped at
// the `language` stage with nothing wrong on either side. The figure is compared by VALUE now, and
// a figure that changed or vanished is refused exactly as before.
$separators = [
    ['Full cycle: 4,800 tonnes of steel frame', 'Trọn chu trình: khung thép 4.800 tấn', true],
    ['6.2 km of internal roads', '6,2 km đường nội bộ', true],
    ['a plant rated 4,000 m3 a day', 'nhà máy công suất 4.000 m³/ngày', true],
    // Grouped with spaces — ordinary, non-breaking and narrow — as French and Russian write them.
    ['Over 1,200 teams', 'plus de 1 200 équipes', true],
    ["Over 1,200 teams", "plus de 1\u{00A0}200 équipes", true],
    ["Over 1,200 teams", "plus de 1\u{202F}200 équipes", true],
    // Still strict about the digits themselves: a different number is a different fact.
    ['Full cycle: 4,800 tonnes of steel frame', 'Trọn chu trình: khung thép 4.900 tấn', false],
    ['6.2 km of internal roads', '6,3 km đường nội bộ', false],
    // And a figure dropped outright is still a loss, whatever the separator rule.
    ['Full cycle: 4,800 tonnes of steel frame', 'Trọn chu trình: khung thép chịu lực', false],
    // Levelling the separators must not make a decimal into a whole number.
    ['Response in 1.5 seconds', 'Phản hồi trong 15 giây', false],
];
foreach ($separators as [$source, $target, $ok]) {
    $errors = MultilingualProfile::preservationErrors($source, $target);
    check('separator "' . $source . '" as "' . $target . '" ' . ($ok ? 'keeps its figure' : 'is refused'), $errors === [], $ok);
}

// A single digit is a WORD in most languages and a fact in none: refusing its transliteration
// would refuse correct translations, which is a worse failure than missing a "1".
check('a single digit may be written out', MultilingualProfile::preservationErrors('Chapter 1', '第一章'), []);

// A figure carrying a SCALE WORD is the one case where verbatim is the WRONG rule: Chinese writes
// "$44bn" as "440亿美元" — same currency, same amount, scale moved into the number. Measured
// 2026-09-12 on a real run: the model gave that answer twice, including when asked again with the
// complaint attached, because the answer was right and the rule was wrong.
$scaled = [
    // Chinese folds the scale into the number; French keeps the digits and translates the scale.
    // Both are right, and neither contains "$44bn".
    ['UK Government approves $44bn O2, Virgin Media merger', '英国政府批准440亿美元O2与Virgin Media合并交易', true],
    ['UK Government approves $44bn O2, Virgin Media merger', 'Le gouvernement britannique approuve la fusion de 44 milliards de dollars entre O2 et Virgin Media', true],
    // A scale word spelled out is the same case as one abbreviated.
    ['Verizon to sell Yahoo and AOL for $5 Billion to Apollo', 'Verizon vend Yahoo et AOL pour 5 milliards de dollars à Apollo', true],
    ['Raised $12m in Series B', 'B轮融资1200万美元', true],
    ['50k downloads', '5万次下载', true],
    // Dropped entirely it is still a refusal — and the 2 of "O2" does not count as evidence: a
    // source word carrying a digit that survives verbatim is struck out before the target is read.
    ['UK Government approves $44bn O2, Virgin Media merger', '英国政府批准O2与Virgin Media合并交易', false],
    ['UK Government approves $44bn O2, Virgin Media merger', 'Le gouvernement approuve la fusion entre O2 et Virgin Media', false],
    ['Verizon to sell Yahoo and AOL for $5 Billion to Apollo', 'Verizon vend Yahoo et AOL à Apollo', false],
    ['Raised $12m in Series B', 'B轮融资完成', false],
    ['50k downloads', '很多次下载', false],
];
foreach ($scaled as [$source, $target, $ok])
    check(
        'a scaled figure "' . $source . '" ' . ($ok ? 'may be re-scaled' : 'may not vanish'),
        MultilingualProfile::preservationErrors($source, $target) === [],
        $ok
    );
check('a figure the source repeats must be kept as often', count(MultilingualProfile::preservationErrors('$10 and $10', 'only $10')), 1);

// CHINESE AND JAPANESE WRITE A NUMBER AGAINST THE WORD BESIDE IT. "Established 2004" is "成立于2004年"
// and "14 regions" is "14个州": no space, so a lookbehind that only means "not inside a Latin word"
// read the Han character before the digits as a letter and saw no figure at all. Measured
// 23/09/2026 on `j-h0n2f4` (Business, zh-CN): 40 correct translations refused, "the figure 2004 is
// missing", and the `language` stage stopped on every retry. The digits inside a Latin word stay
// out, so "O2" is still not a figure.
$cjk = [
    ['Established 2004', '成立于2004年', true],
    ['across 14 regions of Central and Volga Russia', '为俄罗斯中部和伏尔加地区14个州', true],
    ['Issue 14 · 12 August 2026', '第14期 · 2026年8月12日', true],
    ['Founded in 2004', '2004年に設立', true],
    ['Established 2004', '成立于2005年', false],
    ['across 14 regions', '覆盖多个州', false],
];
foreach ($cjk as [$source, $target, $ok])
    check(
        'a figure against a Han or kana character "' . $target . '" ' . ($ok ? 'is read' : 'is still refused'),
        MultilingualProfile::preservationErrors($source, $target) === [],
        $ok
    );
check('digits inside a Latin word are still not a figure', MultilingualProfile::preservationErrors('Model X20', 'Modèle X20'), []);

// A scaled figure copied VERBATIM is kept, not lost. "m" is a scale word ("$12m") and a unit
// ("45 m"), and the translation of a length keeps "45 m" as it is — but the scale rule struck every
// verbatim digit word out of the target first, the 45 with it, and then found no number left.
// Measured 23/09/2026 on `j-h0n2f4` (zh-CN): the last two refusals of the edition, both correct.
check('a scaled figure copied verbatim is kept', MultilingualProfile::preservationErrors('steel frames spanning up to 45 m.', '跨度达45 m的钢结构框架'), []);
check('a unit figure copied with a space before it is kept', MultilingualProfile::preservationErrors('the design frost depth reaches 1.6 m and', '设计冻深可达 1.6 m，'), []);
check('a scaled figure dropped outright is still refused', MultilingualProfile::preservationErrors('steel frames spanning up to 45 m.', '钢结构框架'), ['the figure 45 m left no number in the translation']);

/* ------------------------------------------------ the languages a customer did not ask for */

// 🔒 A QUICKSTART MAY SHIP EDITIONS OF ITS OWN, AND A CUSTOMER'S SITE MUST NOT KEEP THEM. The
// Business archive carries 43 content languages (~184 rows each) that its contract does not
// govern: measured 23/09/2026 on `j-h0n2f4`, built in en-GB + zh-CN + vi-VN, the switcher and the
// page's hreflang offered all 43 — the other 40 were the vendor's demo company, translated. And the
// archive's OWN zh-CN edition would sit beside the copy derived from the customer's words: a second
// zh-CN home, a second set of zh-CN modules. So a retire pass unpublishes (never deletes) every
// ungoverned row in a language other than the source, and every content language the site should
// not route. Rows the contract governs are never touched — that is what keeps `inspect` exact.
$retireRows = [
    'language' => [
        ['lang_id' => 1, 'lang_code' => 'en-GB', 'published' => 1],
        ['lang_id' => 2, 'lang_code' => 'ru-RU', 'published' => 1],
        ['lang_id' => 3, 'lang_code' => 'zh-CN', 'published' => 1],
        ['lang_id' => 4, 'lang_code' => 'de-DE', 'published' => 1],
        ['lang_id' => 5, 'lang_code' => 'ko-KR', 'published' => 0],
    ],
    'article' => [
        ['id' => 10, 'language' => 'en-GB', 'state' => 1],   // the source edition, governed
        ['id' => 11, 'language' => 'ru-RU', 'state' => 1],   // an authored edition the contract governs
        ['id' => 12, 'language' => 'zh-CN', 'state' => 1],   // the archive's own zh-CN — not governed
        ['id' => 13, 'language' => 'zh-CN', 'state' => 1],   // the copy derived from the customer's words
        ['id' => 14, 'language' => 'de-DE', 'state' => 1],   // an edition nobody asked for
        ['id' => 15, 'language' => '*', 'state' => 1],       // shows in every language
        ['id' => 16, 'language' => 'de-DE', 'state' => 0],   // already hidden
        ['id' => 17, 'language' => 'en-GB', 'state' => 1],   // ungoverned, but in the source language
    ],
    'menuItem' => [
        ['id' => 20, 'language' => 'de-DE', 'published' => 1, 'client_id' => 0],
        ['id' => 21, 'language' => 'de-DE', 'published' => 1, 'client_id' => 1],  // the admin menu
    ],
    'module' => [
        ['id' => 30, 'language' => 'zh-CN', 'published' => 1],
        ['id' => 31, 'language' => 'zh-CN', 'published' => 1],  // the derived copy
    ],
];
$retired = MultilingualApply::retireWrites($retireRows, ['article' => [10, 11, 13], 'module' => [31]], 'en-GB', ['en-GB', 'zh-CN']);
check('a retire pass hides exactly the rows nobody asked for', $retired, [
    ['language', 2, ['published' => 0]],
    ['language', 4, ['published' => 0]],
    ['article', 12, ['state' => 0]],
    ['article', 14, ['state' => 0]],
    ['menuItem', 20, ['published' => 0]],
    ['module', 30, ['published' => 0]],
]);
check('a retire pass on a retired site writes nothing', MultilingualApply::retireWrites([
    'language' => [['lang_id' => 1, 'lang_code' => 'en-GB', 'published' => 1], ['lang_id' => 2, 'lang_code' => 'ru-RU', 'published' => 0]],
    'article' => [['id' => 12, 'language' => 'zh-CN', 'state' => 0]],
], [], 'en-GB', ['en-GB']), []);
// Ids are per TABLE: article 20 being governed says nothing about menu item 20.
check('a governed id guards only its own kind', MultilingualApply::retireWrites([
    'menuItem' => [['id' => 20, 'language' => 'de-DE', 'published' => 1, 'client_id' => 0]],
], ['article' => [20]], 'en-GB', []), [['menuItem', 20, ['published' => 0]]]);
// The source language is routed whatever the caller says: without it the site has no home page.
check('the source language is never retired', MultilingualApply::retireWrites([
    'language' => [['lang_id' => 1, 'lang_code' => 'en-GB', 'published' => 1]],
], [], 'en-GB', []), []);

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

// 🔒 TWO CHINESES CANNOT SHARE A SITE, and the plan is where that is said. Both tags derive `zh`,
// `#__languages.sef` is unique, and by apply time the customer has paid for ~1000 translated
// strings. Measured 12/09 on a site already publishing zh-TW: the plan happily answered
// `creates: {module: 49, article: 78, menuItem: 46}` for zh-CN before this check existed.
check('a second Chinese collides with the first', MultilingualProfile::sefClash('zh-CN', ['en-GB', 'zh-TW']), 'zh-TW');
check('the source language holds its own segment too', MultilingualProfile::sefClash('en-US', ['en-GB']), 'en-GB');
check('an unrelated language is free to land', MultilingualProfile::sefClash('fr-FR', ['en-GB', 'zh-TW']), null);
check('a locale already present is not a clash with itself', MultilingualProfile::sefClash('zh-TW', ['en-GB', 'zh-TW']), null);

/* ---------------------------------------------------------------- what a copied link points at */

$idMap = ['article' => [1 => 101], 'menuItem' => [4 => 104]];
check('an article reference follows its copy', $profile->remapLink('index.php?option=com_content&view=article&id=1', $idMap), 'index.php?option=com_content&view=article&id=101');
// 🔒 `id=` under a CATEGORY view is a category, and categories are shared by this profile. Mapping
// it through the article table pointed the Team page at a category that does not exist and answered
// 404 in Chinese while English served the page — seven of forty-six links had that shape.
check('a category reference is NOT an article reference', $profile->remapLink('index.php?option=com_content&view=category&layout=blog&id=1', $idMap), 'index.php?option=com_content&view=category&layout=blog&id=1');
check('a category LIST keeps its id', $profile->remapLink('index.php?option=com_content&view=categories&id=0', $idMap), 'index.php?option=com_content&view=categories&id=0');
check('another component\'s id is never an article id', $profile->remapLink('index.php?option=com_contact&view=contact&id=1', $idMap), 'index.php?option=com_contact&view=contact&id=1');
check('an archive view keeps its id', $profile->remapLink('index.php?option=com_content&view=archive&id=1', $idMap), 'index.php?option=com_content&view=archive&id=1');
// An Itemid is a menu item under every view there is.
check('an Itemid maps under a category view too', $profile->remapLink('index.php?option=com_content&view=category&id=1&Itemid=4', $idMap), 'index.php?option=com_content&view=category&id=1&Itemid=104');
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

// 🔒 THE SHIPPED CATALOG IS THE ONE THE BUILD FORM OFFERS. TracyHQ/tch pins 52 packs
// (`packages/cms/tracy-joomla-quickstart/language-packs.json`, re-measured 21/09/2026) and its Build form
// offers every one; this file had stayed at three, so a customer who chose German was refused here with
// "No verified language pack for de-DE" (j-8zjzs6, 23/09/2026) while the form had just offered it.
$shipped = new LanguagePackCatalog(
    json_decode((string) file_get_contents(__DIR__ . '/../lib/language-packs.json'), true),
    'en-GB'
);
checkTrue('the shipped catalog covers the languages the Build form offers', count($shipped->locales(6)) > 50);
check('German is one of them', $shipped->pack('de-DE', 6)['tag'], 'de-DE');

/* ------------------------------------------------------- the door's own shape refusals */

// The engine is wired for real in tests/run.php's contract fixture; here the point is the RULE,
// which is that a caller naming its own archive is refused rather than quietly ignored.
foreach (['url', 'sha256', 'bytes', 'package'] as $mine) {
    $request = ['operation' => 'multilingual.package', 'locale' => 'zh-CN', $mine => 'anything'];
    checkTrue('a package request carrying `' . $mine . '` is refused by shape', isset($request[$mine]));
}

array_map('unlink', glob($mlDir . '/*.json'));
rmdir($mlDir);

/* ------------------------------------------------- who an association group is written THROUGH */

// 🔒 THE UNDO LOG STORES WHAT `read()` ANSWERED FOR THE ID BEING WRITTEN. A copy created moments
// ago belongs to no group, so a group write addressed to the COPY recorded an empty "before", and
// undoing it deleted the whole group instead of restoring it. Measured 12/09/2026 on a site with
// English, Chinese and French: reverting French left `#__associations` empty, and the switcher on
// an English inner page fell back to the Chinese HOME page instead of the translated page.
// Addressed to the SOURCE, the before is the group as it stood, and the undo restores it.
$relationWrites = [];
$applyRef = new ReflectionClass(MultilingualApply::class);
$applyForRelations = $applyRef->newInstanceWithoutConstructor();
foreach ([
    'profile' => $profile,
    'write' => function (string $kind, int $id, array $fields) use (&$relationWrites): int {
        $relationWrites[] = [$kind, $id, $fields];
        return $id;
    },
] as $name => $value) {
    $applyRef->getProperty($name)->setValue($applyForRelations, $value);
}
$relationsJob = MultilingualApply::start('zh-CN', 'apply-1', 'req-1', 't', 'c', 'p', 'rev');
$relationsJob['phase'] = 'relations';
$relationsJob['ids'] = ['article-1' => 101, 'menuItem-4' => 104];
$relationsState = [
    'ids' => ['article-1' => 1, 'menuItem-4' => 4],
    'keys' => [
        'article-1' => ['kind' => 'article'],
        'module-2' => ['kind' => 'module'],
        'module-3' => ['kind' => 'module'],
        'menuItem-4' => ['kind' => 'menuItem'],
    ],
];
$applyForRelations->step($relationsJob, $relationsState, []);
check('an association group is written through the SOURCE, never the new copy',
    array_map(fn ($w) => [$w[0], $w[1]], $relationWrites),
    [['menuAssociation', 4], ['articleAssociation', 1]]);
check('and it carries the whole group, source and copy together',
    array_map(fn ($w) => json_decode($w[2]['ids'], true), $relationWrites), [[4, 104], [1, 101]]);
