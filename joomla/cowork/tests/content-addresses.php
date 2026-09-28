<?php
// Loaded by run.php. content.read's addresses: the link a visitor sees, written by Joomla's own router.
require_once __DIR__ . '/../lib/ContentProjection.php';

$adContents = [
    'c-home' => ['id' => 'c-home', 'type' => 'page', 'url' => 'https://site.example/index.php?Itemid=101', 'revision' => 'r1'],
    'c-post' => ['id' => 'c-post', 'type' => 'article', 'url' => 'https://site.example/index.php?option=com_content&view=article&id=7', 'revision' => 'r2'],
    'c-part' => ['id' => 'c-part', 'type' => 'shared', 'url' => null, 'revision' => 'r3'],
    'c-lost' => ['id' => 'c-lost', 'type' => 'page', 'url' => 'https://site.example/index.php?Itemid=999', 'revision' => 'r4'],
];
$adAsked = [];
$adRoute = function (string $kind, int $id) use (&$adAsked): ?string {
    $adAsked[] = [$kind, $id];
    if ($id === 999) throw new RuntimeException('no such menu item');
    return ['page' => ['101' => '/sub/ru/about-us']][$kind][(string) $id] ?? ($kind === 'article' ? '/sub/blog/opening-day' : null);
};
$adOut = ContentProjection::addresses($adContents, 'https://site.example/sub/', $adRoute);
check('addresses: a page takes the path its menu item routes to, on the site origin', $adOut['c-home']['url'], 'https://site.example/sub/ru/about-us');
check('addresses: an article takes its routed path', $adOut['c-post']['url'], 'https://site.example/sub/blog/opening-day');
check('addresses: a content with no address keeps none', $adOut['c-part']['url'], null);
check('addresses: a route that fails keeps the address the reader had', $adOut['c-lost']['url'], 'https://site.example/index.php?Itemid=999');
check('addresses: revisions are untouched (the door hashed the other address)', array_column($adOut, 'revision'), ['r1', 'r2', 'r3', 'r4']);
check('addresses: the router is asked by kind and native id', $adAsked, [['page', 101], ['article', 7], ['page', 999]]);

// The paths Joomla's router gives inside the door's own request, where the language filter's
// rules are not attached: no language prefix, and an alias menu item routes to `?Itemid=`.
require_once __DIR__ . '/../lib/JoomlaAddress.php';
$jaRaw = function (string $query): string {
    return [
        'index.php?Itemid=629' => '/terms',
        'index.php?Itemid=635' => '/projects',
        'index.php?Itemid=623' => '/?Itemid=623',
        'index.php?Itemid=640' => '/services/maintenance',
        'index.php?Itemid=101' => '/',
        'index.php?option=com_content&view=article&id=7&catid=9' => '/blog/opening-day',
    ][$query] ?? '/?unknown';
};
$jaMenu = [
    ['id' => 629, 'type' => 'component', 'language' => 'en-GB', 'home' => 0, 'params' => '{}'],
    ['id' => 635, 'type' => 'component', 'language' => 'ru-RU', 'home' => 0, 'params' => '{}'],
    ['id' => 623, 'type' => 'alias', 'language' => 'en-GB', 'home' => 0, 'params' => '{"aliasoptions":"640"}'],
    ['id' => 640, 'type' => 'component', 'language' => 'en-GB', 'home' => 0, 'params' => '{}'],
    ['id' => 101, 'type' => 'component', 'language' => '*', 'home' => 1, 'params' => '{}'],
];
$jaArticles = [['id' => 7, 'catid' => 9, 'language' => 'ru-RU']];
$jaLanguages = [['lang_code' => 'en-GB', 'sef' => 'en', 'published' => 1], ['lang_code' => 'ru-RU', 'sef' => 'ru', 'published' => 1]];
$jaFilter = ['enabled' => true, 'removeDefaultPrefix' => false, 'defaultLanguage' => 'en-GB'];
$ja = new JoomlaAddress($jaMenu, $jaArticles, $jaLanguages, $jaFilter, '/', $jaRaw);
check('address: a page carries its language prefix', $ja->path('page', 629), '/en/terms');
check('address: another language, its own prefix', $ja->path('page', 635), '/ru/projects');
check('address: an alias menu item is not a page of its own (its target has the address)', $ja->path('page', 623), false);
check('address: an all-languages home is the default language root', $ja->path('page', 101), '/en/');
check('address: an article takes its own language and category', $ja->path('article', 7), '/ru/blog/opening-day');
check('address: an unknown row has none', $ja->path('page', 1), null);
$jaPlain = new JoomlaAddress($jaMenu, $jaArticles, $jaLanguages, ['enabled' => false, 'removeDefaultPrefix' => false, 'defaultLanguage' => 'en-GB'], '/', $jaRaw);
check('address: no language filter, no prefix', $jaPlain->path('page', 629), '/terms');
$jaShort = new JoomlaAddress($jaMenu, $jaArticles, $jaLanguages, ['enabled' => true, 'removeDefaultPrefix' => true, 'defaultLanguage' => 'en-GB'], '/', $jaRaw);
check('address: the default language without its prefix when the filter removes it', [$jaShort->path('page', 629), $jaShort->path('page', 635)], ['/terms', '/ru/projects']);
$jaSub = new JoomlaAddress($jaMenu, $jaArticles, $jaLanguages, $jaFilter, '/sub', fn(string $q) => '/sub' . $jaRaw($q));
check('address: a site in a folder keeps its folder before the prefix', $jaSub->path('page', 629), '/sub/en/terms');
$jaTwice = new JoomlaAddress($jaMenu, $jaArticles, $jaLanguages, $jaFilter, '/', fn(string $q) => '/en' . $jaRaw($q));
check('address: a router that already prefixed is not prefixed twice', $jaTwice->path('page', 629), '/en/terms');

// A heading or separator is a menu label, not a page; a url item is its own link when it stays on the site.
$jaMenu2 = array_merge($jaMenu, [
    ['id' => 605, 'type' => 'heading', 'language' => 'en-GB', 'home' => 0, 'params' => '{}', 'link' => ''],
    ['id' => 606, 'type' => 'separator', 'language' => 'en-GB', 'home' => 0, 'params' => '{}', 'link' => ''],
    ['id' => 617, 'type' => 'url', 'language' => 'en-GB', 'home' => 0, 'params' => '{}', 'link' => '/en/this-page-does-not-exist'],
    ['id' => 618, 'type' => 'url', 'language' => 'en-GB', 'home' => 0, 'params' => '{}', 'link' => 'https://elsewhere.example/'],
]);
$ja2 = new JoomlaAddress($jaMenu2, $jaArticles, $jaLanguages, $jaFilter, '/', $jaRaw);
check('address: a heading or a separator has no page', [$ja2->path('page', 605), $ja2->path('page', 606)], [false, false]);
check('address: a url item on this site is its own link; one elsewhere is not a page here', [$ja2->path('page', 617), $ja2->path('page', 618)], ['/en/this-page-does-not-exist', false]);
$adNone = ContentProjection::addresses(['c-h' => ['id' => 'c-h', 'type' => 'page', 'url' => 'https://site.example/index.php?Itemid=605', 'revision' => 'r']], 'https://site.example/', fn() => false);
check('addresses: a content with no page loses its address', $adNone['c-h']['url'], null);
