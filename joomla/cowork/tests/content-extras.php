<?php
// Loaded by run.php. What content.read carries beside the contract slots (unreleased): the words and
// pictures a Joomla page prints from other places — an article's pictures, custom fields and
// author, a menu item's params and T4 mega menu, an inlined module's title, and the categories and
// tags the articles live in — each keyed by the NATIVE id a render stamp names.
require_once __DIR__ . '/../lib/ContentProjection.php';

$exMenu = fn(int $id, string $title, array $extra = []) => $extra + ['id' => (string)$id, 'title' => $title, 'alias' => strtolower($title), 'path' => strtolower($title),
    'menutype' => 'main-en', 'parent_id' => '1', 'published' => '1', 'access' => '1', 'language' => 'en-GB', 'home' => '0', 'client_id' => '0',
    'link' => 'index.php?option=com_content&view=article&id=1', 'params' => '{}', 'publish_up' => null, 'publish_down' => null];
$exArticle = fn(int $id, int $catid, array $extra = []) => $extra + ['id' => (string)$id, 'title' => "Article $id", 'alias' => "article-$id", 'catid' => (string)$catid,
    'state' => '1', 'access' => '1', 'language' => 'en-GB', 'introtext' => '<p>Intro</p>', 'fulltext' => '', 'images' => '{}',
    'created_by' => '42', 'created_by_alias' => '', 'publish_up' => null, 'publish_down' => null, 'created' => null, 'modified' => null];
$exModule = fn(int $id, string $title, string $showtitle) => ['id' => (string)$id, 'title' => $title, 'module' => 'mod_articles_categories', 'position' => 'sidebar',
    'published' => '1', 'access' => '1', 'language' => 'en-GB', 'client_id' => '0', 'showtitle' => $showtitle, 'ordering' => (string)$id, 'content' => '', 'params' => '{}',
    'publish_up' => null, 'publish_down' => null];
$exCategory = fn(int $id, int $parent, string $title, array $extra = []) => $extra + ['id' => (string)$id, 'parent_id' => (string)$parent, 'title' => $title,
    'alias' => strtolower($title), 'extension' => 'com_content', 'published' => '1', 'access' => '1', 'language' => 'en-GB', 'description' => '', 'params' => '{}',
    'created_time' => '2026-01-01 00:00:00', 'modified_time' => '2026-01-02 00:00:00'];

$exRows = [
    'page-10' => $exMenu(10, 'News', ['link' => 'index.php?option=com_content&view=category&layout=blog&id=30',
        'params' => json_encode(['layout_type' => 'blog', 'orderby_sec' => 'rdate', 'page_heading' => 'News & engineering notes', 'cta_btn' => 'Send a request',
            'all_label' => 'All', 'count_label' => 'articles', 'menu-meta_description' => 'Our news', 'menu_image_css' => 'Big Icon', 'num_leading_articles' => 1,
            'feed_link' => 'https://x.test/feed', 'template_json' => '{"a": "B c"}'])]),
    'page-11' => $exMenu(11, 'Newsletter'),
    // A page opening a category under a private one: that category is no record.
    'page-12' => $exMenu(12, 'Resources', ['link' => 'index.php?option=com_content&view=category&id=32']),
    'article-100' => $exArticle(100, 31, ['images' => json_encode(['image_intro' => 'images/news-1.png#joomlaImage://local-images/news-1.png?width=800&height=450',
        'image_intro_alt' => 'A crane', 'image_intro_caption' => 'On site, June', 'image_fulltext' => 'images/news-1.png', 'image_fulltext_alt' => '', 'image_fulltext_caption' => '']),
        'created_by_alias' => 'Press office']),
    'article-101' => $exArticle(101, 30),
    'module-200' => $exModule(200, 'Categories', '1'),
    'module-201' => $exModule(201, '[Admin label] Sidebar list', '0'),
];
$exMapping = ['keys' => [], 'rows' => [], 'ids' => [], 'slots' => [], 'contractHash' => 'contract-x', 'manifest' => []];
foreach ($exRows as $key => $row) {
    $kind = ['page' => 'menuItem', 'article' => 'article', 'module' => 'module'][explode('-', $key)[0]];
    $contractKey = $kind . '-' . $row['id'];
    $exMapping['keys'][$contractKey] = ['kind' => $kind, 'lockKey' => $contractKey];
    $exMapping['rows'][$contractKey] = $row; $exMapping['ids'][$contractKey] = (int)$row['id'];
    $exMapping['slots'][$contractKey] = $kind === 'menuItem' ? [['key' => "$contractKey.0", 'entity' => $contractKey, 'column' => 'title', 'type' => 'text']] : [];
}
$exData = function (array $over = []) use ($exRows, $exCategory): array {
    return $over + [
        'menu' => [$exRows['page-10'], $exRows['page-11'], $exRows['page-12']],
        'modules' => [$exRows['module-200'], $exRows['module-201']],
        // Both modules sit on the News page only: each is inlined into it.
        'modules_menu' => [['moduleid' => '200', 'menuid' => '10'], ['moduleid' => '201', 'menuid' => '10']],
        'categories' => [$exCategory(1, 0, 'ROOT', ['extension' => 'system']), $exCategory(30, 1, 'News'), $exCategory(31, 30, 'Disclosure', ['description' => '<p>Filings</p>']),
            $exCategory(32, 33, 'Hidden child'), $exCategory(33, 1, 'Private', ['access' => '3']), $exCategory(34, 1, 'Unused'),
            $exCategory(35, 1, 'Pictured', ['params' => json_encode(['image' => 'images/cat.png', 'image_alt' => 'Cat picture'])])],
        'viewlevels' => [['id' => '1', 'rules' => '[1]']], 'associations' => [],
        'claudecowork_content_identity' => [['kind' => 'page', 'native_id' => '10', 'uid' => 'u10'], ['kind' => 'page', 'native_id' => '11', 'uid' => 'u11'],
            ['kind' => 'page', 'native_id' => '12', 'uid' => 'u12'], ['kind' => 'article', 'native_id' => '100', 'uid' => 'u100'], ['kind' => 'article', 'native_id' => '101', 'uid' => 'u101'],
            ['kind' => 'shared', 'native_id' => '200', 'uid' => 'u200'], ['kind' => 'shared', 'native_id' => '201', 'uid' => 'u201']],
        'fields' => [
            ['id' => '1', 'name' => 'author-line', 'type' => 'text', 'context' => 'com_content.article', 'state' => '1', 'access' => '1', 'language' => '*'],
            ['id' => '2', 'name' => 'lede', 'type' => 'editor', 'context' => 'com_content.article', 'state' => '1', 'access' => '1', 'language' => '*'],
            ['id' => '3', 'name' => 'kind', 'type' => 'list', 'context' => 'com_content.article', 'state' => '1', 'access' => '1', 'language' => '*'],
            ['id' => '4', 'name' => 'draft-note', 'type' => 'text', 'context' => 'com_content.article', 'state' => '0', 'access' => '1', 'language' => '*'],
            ['id' => '5', 'name' => 'ru-only', 'type' => 'text', 'context' => 'com_content.article', 'state' => '1', 'access' => '1', 'language' => 'ru-RU'],
            ['id' => '6', 'name' => 'photo', 'type' => 'media', 'context' => 'com_content.article', 'state' => '1', 'access' => '1', 'language' => '*'],
            ['id' => '7', 'name' => 'contact-email', 'type' => 'text', 'context' => 'com_contact.contact', 'state' => '1', 'access' => '1', 'language' => '*']],
        'fields_values' => [['field_id' => '1', 'item_id' => '100', 'value' => 'Communications'], ['field_id' => '2', 'item_id' => '100', 'value' => '<p>The lede</p>'],
            ['field_id' => '3', 'item_id' => '100', 'value' => 'opt-2'], ['field_id' => '4', 'item_id' => '100', 'value' => 'not yet'],
            ['field_id' => '5', 'item_id' => '100', 'value' => 'только'], ['field_id' => '6', 'item_id' => '100', 'value' => '{"imagefile":"images/portrait.jpg#joomlaImage://x","alt_text":"Portrait"}'],
            ['field_id' => '7', 'item_id' => '100', 'value' => 'a@b.test'], ['field_id' => '1', 'item_id' => '101', 'value' => '  ']],
        'tags' => [['id' => '1', 'title' => 'ROOT', 'alias' => 'root', 'published' => '1', 'access' => '1', 'language' => '*', 'description' => '', 'params' => '{}', 'parent_id' => '0'],
            ['id' => '7', 'title' => 'factory', 'alias' => 'factory', 'published' => '1', 'access' => '1', 'language' => '*', 'description' => '<p>Plants</p>', 'params' => '{}', 'parent_id' => '1'],
            ['id' => '8', 'title' => 'archived', 'alias' => 'archived', 'published' => '2', 'access' => '1', 'language' => '*', 'description' => '', 'params' => '{}', 'parent_id' => '1']],
        'contentitem_tag_map' => [['type_alias' => 'com_content.article', 'content_item_id' => '100', 'tag_id' => '7'], ['type_alias' => 'com_content.article', 'content_item_id' => '100', 'tag_id' => '8'],
            ['type_alias' => 'com_contact.contact', 'content_item_id' => '101', 'tag_id' => '7']],
        'users' => [['id' => '42', 'name' => 'Site Administrator']],
        'megamenu' => [['template' => 'tpl_x', 'profile' => 'main', 'settings' => ['main-en' => [
            '12' => ['megabuild' => 1, 'settings' => [['contents' => [['name' => 'Kit', 'title' => 'Marketing Kit', 'type' => 'items'], ['title' => 'Recent projects', 'type' => 'module'], ['title' => ' ', 'type' => 'module']]]]],
            '11' => ['caption' => 'Digest email template']], 'main-ru' => ['11' => ['caption' => 'Не этот']]]]],
    ];
};
$exId = ContentProjection::opaque('site-secret');
$exBuild = fn(array $data) => ContentProjection::build($data, $exMapping, 'site-secret', 'https://fixture.invalid/', 1000000, fn($row, $slot) => (string)$row[$slot['column']]);
$ex = $exBuild($exData());
$exBlocks = function (string $contentId) use (&$ex): array {
    $out = [];
    foreach ($ex['contents'][$contentId]['blocks'] as $block) $out[$block['key']] = array_column($block['fields'], 'value', 'key');
    return $out;
};
$exNews = $exId('content', 'u10'); $exLetter = $exId('content', 'u11'); $exResources = $exId('content', 'u12');
$exPost = $exId('content', 'u100'); $exPlain = $exId('content', 'u101');

/* ---------------------------------------------------------------- articles */
check('article: its pictures are fields keyed by the native id, Joomla\'s #joomlaImage suffix off, the caption beside',
    $exBlocks($exPost)['article-100.images'] ?? null,
    ['article-100.image_intro' => 'images/news-1.png', 'article-100.image_intro_caption' => 'On site, June', 'article-100.image_fulltext' => 'images/news-1.png']);
check('article: one picture used twice is one image, its alt on the image (not a field: alt text usually repeats the title)',
    array_map(fn($i) => [$i['src'], $i['alt'], count($i['usages'])], $ex['contents'][$exPost]['images']),
    [['https://fixture.invalid/images/news-1.png', 'A crane', 1], ['https://fixture.invalid/images/portrait.jpg', 'Portrait', 1]]);
check('article: published public custom field values of the words kind, in the article\'s language; a list key, a draft field, another language and another context are not',
    $exBlocks($exPost)['article-100.fields'] ?? null,
    ['article-100.field.author-line' => 'Communications', 'article-100.field.lede' => '<p>The lede</p>', 'article-100.field.photo' => 'images/portrait.jpg']);
check('article: an editor field is html, a media field an image', array_column($ex['contents'][$exPost]['blocks'][array_search('article-100.fields', array_column($ex['contents'][$exPost]['blocks'], 'key'))]['fields'], 'type'),
    ['text', 'html', 'image']);
check('article: the author is the alias when one is set', $exBlocks($exPost)['article-100.author'] ?? null, ['article-100.author' => 'Press office']);
check('article: else the user\'s display name; a blank field value is no field', [$exBlocks($exPlain)['article-101.author'] ?? null, isset($exBlocks($exPlain)['article-101.fields'])],
    [['article-101.author' => 'Site Administrator'], false]);
check('article: its published tags by name', $ex['contents'][$exPost]['tags'], ['factory']);
check('article: its category is its parent record', [$ex['contents'][$exPost]['relations'], $ex['contents'][$exPlain]['relations']],
    [[['type' => 'parent', 'contentId' => $exId('content', 'category:31')]], [['type' => 'parent', 'contentId' => $exId('content', 'category:30')]]]);
check('article: an added field is no contract slot and says what it is', array_intersect_key($ex['contents'][$exPost]['blocks'][count($ex['contents'][$exPost]['blocks']) - 1]['fields'][0], ['slotKey' => 1, 'semanticKey' => 1]),
    ['slotKey' => null, 'semanticKey' => 'author']);

/* ---------------------------------------------------------------- pages */
check('page: menu item params written as words are fields; settings, metadata, numbers, links and JSON are not',
    $exBlocks($exNews)['menuItem-10.params'] ?? null,
    ['menuItem-10.params.page_heading' => 'News & engineering notes', 'menuItem-10.params.cta_btn' => 'Send a request', 'menuItem-10.params.all_label' => 'All']);
check('page: the T4 mega menu caption, named by template and profile', $exBlocks($exLetter)['menuItem-11.megamenu'] ?? null,
    ['menuItem-11.megamenu[tpl_x:main].caption' => 'Digest email template']);
check('page: mega column titles by position; a blank title is none', $exBlocks($exResources)['menuItem-12.megamenu'] ?? null,
    ['menuItem-12.megamenu[tpl_x:main].column.0.0' => 'Marketing Kit', 'menuItem-12.megamenu[tpl_x:main].column.0.1' => 'Recent projects']);
check('page: a page with neither gets no empty blocks', array_keys($exBlocks($exResources)), ['menuItem-12.0', 'menuItem-12.megamenu']);

/* ---------------------------------------------------------------- inlined modules */
check('module: an inlined module that shows its title carries it into the page; one that hides it does not',
    [$exBlocks($exNews)['module-200'] ?? null, $exBlocks($exNews)['module-201'] ?? null], [['module-200.title' => 'Categories'], []]);

/* ---------------------------------------------------------------- categories and tags */
$exTax = array_values(array_filter($ex['contents'], fn($c) => in_array($c['id'], [$exId('content', 'category:30'), $exId('content', 'category:31'), $exId('content', 'tag:7')], true)));
check('taxonomy: the categories readable content lives in (a page\'s, an article\'s, their ancestors) and its tags; not an unused category, a child of a private one, an unpublished tag',
    array_values(array_map(fn($c) => $c['title'], array_filter($ex['contents'], fn($c) => $c['type'] === 'shared' && !in_array($c['id'], [$exId('content', 'u200'), $exId('content', 'u201')], true)))),
    (function () use ($ex, $exId) { $want = []; foreach (['category:30' => 'News', 'category:31' => 'Disclosure', 'tag:7' => 'factory'] as $k => $t) $want[$exId('content', $k)] = $t; ksort($want); return array_values($want); })());
$exCat = $ex['contents'][$exId('content', 'category:31')];
check('taxonomy: a category is a shared record whose one block is keyed like its render stamp', [$exCat['type'], $exCat['slug'], $exCat['locale'], array_column($exCat['blocks'], 'key')],
    ['shared', 'disclosure', 'en-GB', ['category-31']]);
check('taxonomy: its description is its field; its parent category its parent record', [$exBlocks($exCat['id'])['category-31'], $exCat['relations']],
    [['category-31.description' => '<p>Filings</p>'], [['type' => 'parent', 'contentId' => $exId('content', 'category:30')]]]);
check('taxonomy: a tag the same way', [$ex['contents'][$exId('content', 'tag:7')]['type'], $exBlocks($exId('content', 'tag:7'))], ['shared', ['tag-7' => ['tag-7.description' => '<p>Plants</p>']]]);
check('taxonomy: each names its native row, for content.update', [$ex['rows'][$exCat['id']], $ex['rows'][$exId('content', 'tag:7')]], [[['category', 31]], [['tag', 7]]]);
check('taxonomy: every one has its own revision', isset($ex['revisions'][$exCat['id']], $ex['revisions'][$exId('content', 'tag:7')]), true);

/* ---------------------------------------------------------------- revisions */
$exMoved = function (array $data) use ($exBuild, $ex): array {
    $after = $exBuild($data)['revisions'];
    return array_keys(array_filter($after, fn($rev, $id) => ($ex['revisions'][$id] ?? null) !== $rev, ARRAY_FILTER_USE_BOTH));
};
$exMega = $exData(); $exMega['megamenu'][0]['settings']['main-en']['11']['caption'] = 'Weekly digest';
check('revision: a new mega menu caption moves that page, alone', $exMoved($exMega), [$exLetter]);
$exField = $exData(); $exField['fields_values'][0]['value'] = 'Press desk';
check('revision: a new custom field value moves that article, alone', $exMoved($exField), [$exPost]);
$exCatData = $exData(); $exCatData['categories'][2]['description'] = '<p>Regulatory filings</p>';
check('revision: a new category description moves that category, alone', $exMoved($exCatData), [$exCat['id']]);
$exBare = $exData(); foreach (['fields', 'fields_values', 'tags', 'contentitem_tag_map', 'users', 'megamenu'] as $table) unset($exBare[$table]);
checkTrue('a caller without the extra inputs still gets a projection (articles without those fields)', isset($exBuild($exBare)['contents'][$exPost]));
