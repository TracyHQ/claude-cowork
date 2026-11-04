<?php
// Loaded by run.php after contracts.php (reuses TestContractStore, ContractTestWriter, FakeApplyLog, $WTOKEN).
// A page the write step creates (TracyHQ/tracy#375): a category, an article and a menu item made through content.batch, each
// carrying the mark in its `note`. They are Tracy's own, like the design-system module, so they are not rows the site gained:
// the contract reads no drift, its baseline stays byte for byte, and every other added row still counts. All words are made up.

$pcRoot = sys_get_temp_dir() . '/cowork-page-create-' . bin2hex(random_bytes(6));
$pcDir = $pcRoot . '/lib/contracts/test-pages/j6/1.0.0';
mkdir($pcDir, 0777, true);

$pcModule = ['title' => 'Made-up links', 'module' => 'mod_menu', 'position' => 'footer', 'published' => '1', 'publish_up' => null, 'publish_down' => null,
    'ordering' => '1', 'access' => '1', 'showtitle' => '1', 'language' => '*', 'client_id' => '0', 'content' => '', 'params' => '{"menutype":"made-up-links"}'];
$pcArticle = ['title' => 'A made-up article', 'alias' => 'a-made-up-article', 'introtext' => '<p>Made-up words</p>', 'state' => '1', 'catid' => '30', 'language' => '*', 'access' => '1', 'note' => ''];
$pcCategory = ['title' => 'Made-up things', 'alias' => 'made-up-things', 'path' => 'made-up-things', 'extension' => 'com_content', 'published' => '1', 'note' => '', 'language' => '*', 'access' => '1'];
$pcItem = ['menutype' => 'made-up-links', 'title' => 'A made-up price', 'alias' => 'made-up-price', 'path' => 'made-up-price', 'note' => '', 'link' => 'index.php?Itemid=70', 'type' => 'url',
    'published' => '1', 'parent_id' => '1', 'language' => '*', 'client_id' => '0', 'params' => '{"menu_show":1}'];

$pcStore = new TestContractStore();
$pcFiles = [
    'manifest' => ['id' => 'test-pages/j6/1.0.0'],
    'content-map' => ['entities' => [
        ['key' => 'links', 'kind' => 'module', 'sourceId' => 10, 'identity' => ['title' => 'Made-up links']],
        ['key' => 'story', 'kind' => 'article', 'sourceId' => 20, 'identity' => ['alias' => 'a-made-up-article']],
        ['key' => 'things', 'kind' => 'category', 'sourceId' => 30, 'identity' => ['path' => 'made-up-things']],
        ['key' => 'price', 'kind' => 'menuItem', 'sourceId' => 40, 'identity' => ['path' => 'made-up-price']],
    ], 'slots' => [
        ['key' => 'price.0', 'entity' => 'price', 'column' => 'title', 'type' => 'text', 'sample' => 'A made-up price', 'maxCharacters' => 40, 'requiresEvidence' => false],
    ], 'pages' => []],
    'presentation-lock' => ['entities' => ['links' => $pcModule, 'story' => $pcArticle, 'things' => $pcCategory, 'price' => $pcItem],
        'assignments' => [['moduleid' => 10, 'menuid' => 0]], 'fileRoots' => [], 'files' => [],
        'inventoryCounts' => ['module' => 1, 'article' => 1, 'category' => 1, 'menuItem' => 1], 'access' => $pcStore->acl],
];
foreach ($pcFiles as $name => $body) file_put_contents($pcDir . '/' . $name . '.json', json_encode($body));
$pcContractBytes = fn() => array_map(fn($name) => hash_file('sha256', $pcDir . '/' . $name . '.json'), array_keys($pcFiles));

$pcLog = new FakeApplyLog();
$pcWriter = new ContractTestWriter($pcLog, $pcStore);
$pcWriter->store['module'][110] = ['id' => '110'] + $pcModule;
$pcWriter->store['moduleAssignment'][110] = ['menuids' => '[0]'];
$pcWriter->store['article'][120] = ['id' => '120'] + ['catid' => '130'] + $pcArticle;
$pcWriter->store['category'][130] = ['id' => '130'] + $pcCategory;
$pcWriter->store['menuItem'][140] = ['id' => '140'] + $pcItem;
$pcContract = new QuickstartContract($pcWriter, $pcStore, $pcRoot, $pcDir);
$pcContract->bind($pcContract->inspect()['snapshot']);
$pcEngine = new Engine($WTOKEN, [], null, null, null, null, $pcWriter, null, $pcLog, null, null, null, $pcContract);
$pcCall = fn(string $action, array $params) => $pcEngine->handle(['token' => $WTOKEN, 'action' => $action, 'params' => $params]);
$pcInspect = fn() => $pcCall('content.contract', ['operation' => 'inspect']);
$pcWarnings = fn() => array_column($pcInspect()['warnings'] ?? [], 'message');
$pcBatch = fn(string $request, array $step) => $pcCall('content.batch', ['apply_id' => 'page-made-up', 'request_id' => $request, 'operations' => [['key' => 'row'] + $step]]);

check('info says this engine takes created pages', $pcEngine->handle(['token' => $WTOKEN, 'action' => 'info'])['info']['page_create'] ?? null, ['version' => 1]);
check('pages: the quickstart as bound reads no drift', $pcWarnings(), []);

$pcBinding = json_encode($pcStore->binding);
$pcRevision = $pcInspect()['revision'];
$pcBytes = $pcContractBytes();

$pcCategoryAnswer = $pcBatch('category-1', ['kind' => 'category', 'id' => 0, 'fields' => ['title' => 'Site pages', 'note' => 'tracy-page:category', 'published' => 1]]);
$pcArticleAnswer = $pcBatch('article-1', ['kind' => 'article', 'id' => 0, 'fields' => ['title' => 'A made-up policy', 'alias' => 'made-up-policy', 'introtext' => '<p>Made-up policy words</p>', 'state' => 1,
    'catid' => $pcCategoryAnswer['ids']['row'], 'note' => 'tracy-page:legal:made-up']]);
$pcItemId = fn() => $pcBatch('item-1', ['kind' => 'menuItem', 'id' => 0, 'fields' => ['title' => 'A made-up policy', 'alias' => 'made-up-policy', 'menutype' => 'made-up-links', 'type' => 'component',
    'link' => 'index.php?option=com_content&view=article&id=' . $pcArticleAnswer['ids']['row'], 'note' => 'tracy-page:legal:made-up', 'params' => '{"menu_show":0}']]);
$pcItemAnswer = $pcItemId();
check('pages: content.batch creates the three rows of a page on a bound site', [$pcCategoryAnswer['ok'], $pcArticleAnswer['ok'], $pcItemAnswer['ok']], [true, true, true]);
check('pages: the article holds the copy\'s words', $pcWriter->store['article'][$pcArticleAnswer['ids']['row']]['introtext'], '<p>Made-up policy words</p>');

check('pages: a created page reads no drift', $pcWarnings(), []);
check('pages: nor does it move the revision a contract call must name', $pcInspect()['revision'], $pcRevision);
check('pages: the bound baseline stays byte for byte', json_encode($pcStore->binding), $pcBinding);
check('pages: and so do the contract files', $pcContractBytes(), $pcBytes);

$pcRows = count($pcWriter->store['menuItem']);
check('pages: the same request again answers the same row', $pcItemId()['ids']['row'], $pcItemAnswer['ids']['row']);
check('pages: and makes no second one', count($pcWriter->store['menuItem']), $pcRows);

$pcApply = $pcCall('content.contract', ['operation' => 'apply', 'apply_id' => 'contract-pages', 'request_id' => 'pages', 'expected_revision' => $pcRevision, 'changes' => ['price.0' => 'A made-up label']]);
check('pages: a contract apply beside it goes through with no warning', [$pcApply['ok'], $pcApply['warnings'] ?? [], $pcWriter->store['menuItem'][140]['title']], [true, [], 'A made-up label']);
check('pages: the apply reads the created rows as no gain in its baseline', $pcStore->binding['counts'], ['module' => 1, 'article' => 1, 'category' => 1, 'menuItem' => 1]);

// Only the mark, only at the start of the note, only on these kinds, and any other added row still counts.
$pcWriter->store['article'][990] = ['id' => '990', 'note' => ''] + $pcArticle;
check('pages: an article someone added beside it still counts', $pcWarnings(), ['Quickstart inventory changed']);
$pcWriter->store['article'][990]['note'] = 'about tracy-page:x';
check('pages: the mark inside a note is no mark', $pcWarnings(), ['Quickstart inventory changed']);
unset($pcWriter->store['article'][990]);
$pcWriter->store['module'][991] = ['id' => '991', 'note' => 'tracy-page:x'] + $pcModule;
check('pages: the mark on a module is no mark', $pcWarnings(), ['Quickstart inventory changed']);
unset($pcWriter->store['module'][991]);
// A bound row is the contract's whatever its note says, so it is not taken off the count.
$pcWriter->store['category'][130]['note'] = 'tracy-page:x';
check('pages: the mark does not take a bound row off the count', in_array('Quickstart inventory changed', $pcWarnings(), true), false);
$pcWriter->store['category'][130]['note'] = '';

$pcRevert = $pcCall('apply.revert', ['apply_id' => 'page-made-up']);
check('pages: a created page has the ordinary way back', [$pcRevert['ok'], isset($pcWriter->store['menuItem'][$pcItemAnswer['ids']['row']]) && (string) ($pcWriter->store['menuItem'][$pcItemAnswer['ids']['row']]['published'] ?? '1') !== '-2'], [true, false]);
check('pages: and the site still reads no drift', $pcWarnings(), []);
