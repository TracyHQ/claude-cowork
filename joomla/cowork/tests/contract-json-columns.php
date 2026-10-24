<?php
// Loaded by run.php after contract-cost.php (reuses TestContractStore, ContractTestWriter, FakeApplyLog).
// A quickstart's demo words do not all live in an HTML body or an ACM's nested `jatools-config`: an
// article's intro picture and its alt are keys of its `images` JSON, a T4 template's extra fields
// (class schedules, exercise lists) are keys of its `attribs`, a module's template fields (`top-desc`,
// `title-btn`) are keys of its own `params`, and a contact's name is a plain column of a kind the
// contract never held before. TCH capture (`--full-coverage`) declares them as slots: a `jsonPath`
// with no `nestedJson` reads the column's own JSON, and `xpath` beside `jsonPath` is one text or
// attribute of an HTML string stored there. Every such slot must read back and write through this door,
// or declaring it is useless.

$jcDir = sys_get_temp_dir() . '/cowork-json-columns-' . bin2hex(random_bytes(6));
mkdir($jcDir); mkdir($jcDir . '/assets');
file_put_contents($jcDir . '/assets/site.css', 'body { color: #111 }');
$jcImages = json_encode(['image_intro' => 'images/intro.jpg#joomlaImage://local-images/intro.jpg?width=10&height=10', 'image_intro_alt' => 'A boxer', 'float_intro' => '']);
$jcAttribs = json_encode(['show_title' => '', 'classes-info' => ['classes-info0' => ['project_label' => 'MONDAY', 'project_info' => '6:00AM']], 'ft-title' => 'WHAT TO BRING']);
$jcArticle = ['title' => 'Advanced boxing', 'alias' => 'advanced-boxing', 'state' => '1', 'catid' => '9', 'language' => '*', 'access' => '1',
    'introtext' => '<p>Intro</p>', 'fulltext' => '', 'images' => $jcImages, 'attribs' => $jcAttribs];
$jcModule = ['title' => 'Our Classes', 'module' => 'mod_articles_category', 'position' => 'section-2', 'published' => '1', 'access' => '1',
    'language' => '*', 'client_id' => '0', 'content' => '', 'showtitle' => '0',
    'params' => json_encode(['count' => 4, 'main-section' => 'Our', 'top-desc' => '<p>Join over <b>100k</b> people</p>', 'bg-color' => 'dark'])];
$jcContact = ['name' => 'GET IN TOUCH', 'alias' => 'contact', 'published' => '1', 'language' => '*', 'misc' => '<p>Ask us</p>', 'email_to' => 'sale@getfit.com'];
$jcSlots = [
    ['key' => 'article-2.0', 'entity' => 'article-2', 'column' => 'images', 'type' => 'image', 'sample' => json_decode($jcImages, true)['image_intro'], 'maxCharacters' => 255, 'jsonPath' => ['image_intro'], 'customize' => 'keep'],
    ['key' => 'article-2.1', 'entity' => 'article-2', 'column' => 'images', 'type' => 'text', 'sample' => 'A boxer', 'maxCharacters' => 40, 'jsonPath' => ['image_intro_alt'], 'customize' => 'keep'],
    ['key' => 'article-2.2', 'entity' => 'article-2', 'column' => 'attribs', 'type' => 'text', 'sample' => '6:00AM', 'maxCharacters' => 40, 'jsonPath' => ['classes-info', 'classes-info0', 'project_info'], 'customize' => 'keep'],
    ['key' => 'article-2.3', 'entity' => 'article-2', 'column' => 'attribs', 'type' => 'text', 'sample' => 'WHAT TO BRING', 'maxCharacters' => 40, 'jsonPath' => ['ft-title'], 'customize' => 'keep'],
    ['key' => 'module-160.0', 'entity' => 'module-160', 'column' => 'params', 'type' => 'text', 'sample' => 'Our', 'maxCharacters' => 40, 'jsonPath' => ['main-section'], 'customize' => 'keep'],
    ['key' => 'module-160.1', 'entity' => 'module-160', 'column' => 'params', 'type' => 'text', 'sample' => 'Join over ', 'maxCharacters' => 40, 'jsonPath' => ['top-desc'], 'xpath' => '/html/body/div/p/text()[1]', 'customize' => 'keep'],
    ['key' => 'module-160.2', 'entity' => 'module-160', 'column' => 'params', 'type' => 'text', 'sample' => '100k', 'maxCharacters' => 40, 'jsonPath' => ['top-desc'], 'xpath' => '/html/body/div/p/b/text()', 'customize' => 'keep'],
    ['key' => 'module-160.3', 'entity' => 'module-160', 'column' => 'title', 'type' => 'text', 'sample' => 'Our Classes', 'maxCharacters' => 40, 'customize' => 'keep'],
    ['key' => 'contact-1.0', 'entity' => 'contact-1', 'column' => 'name', 'type' => 'text', 'sample' => 'GET IN TOUCH', 'maxCharacters' => 40, 'customize' => 'keep'],
    ['key' => 'contact-1.1', 'entity' => 'contact-1', 'column' => 'misc', 'type' => 'text', 'sample' => 'Ask us', 'maxCharacters' => 40, 'xpath' => '/html/body/div/p/text()', 'customize' => 'keep'],
];
$jcStore = new TestContractStore();
$jcFiles = [
    'manifest' => ['id' => 'json-columns/v1'],
    'content-map' => ['entities' => [
        ['key' => 'article-2', 'kind' => 'article', 'sourceId' => 2, 'identity' => ['alias' => 'advanced-boxing', 'language' => '*']],
        ['key' => 'module-160', 'kind' => 'module', 'sourceId' => 160, 'identity' => ['title' => 'Our Classes', 'module' => 'mod_articles_category']],
        ['key' => 'contact-1', 'kind' => 'contact', 'sourceId' => 1, 'identity' => ['alias' => 'contact', 'language' => '*']],
    ], 'slots' => $jcSlots, 'pages' => []],
    'presentation-lock' => ['entities' => ['article-2' => $jcArticle, 'module-160' => $jcModule, 'contact-1' => $jcContact],
        'assignments' => [['moduleid' => 160, 'menuid' => 0]],
        'fileRoots' => ['assets'], 'files' => ['assets/site.css' => hash_file('sha256', $jcDir . '/assets/site.css')],
        'inventoryCounts' => ['article' => 1, 'module' => 1, 'contact' => 1], 'access' => $jcStore->acl],
];
foreach ($jcFiles as $name => $body) file_put_contents($jcDir . '/' . $name . '.json', json_encode($body));
$jcLog = new FakeApplyLog();
$jcWriter = new ContractTestWriter($jcLog, $jcStore);
$jcWriter->store['article'][2] = ['id' => '2'] + $jcArticle;
$jcWriter->store['module'][160] = ['id' => '160'] + $jcModule;
$jcWriter->store['contact'][1] = ['id' => '1'] + $jcContact;
$jcWriter->store['moduleAssignment'][160] = ['menuids' => '[0]'];
$jcContract = new QuickstartContract($jcWriter, $jcStore, $jcDir, $jcDir);
$jcEngine = new Engine($WTOKEN, [], null, null, null, null, $jcWriter, null, $jcLog, null, null, null, $jcContract);
$jcCall = fn(array $params) => $jcEngine->handle(['token' => $WTOKEN, 'action' => 'content.contract', 'params' => $params]);

check('json columns: the profile binds', $jcCall(['operation' => 'bind'])['bound'] ?? null, true);
$jcState = $jcContract->inspect();
check('json columns: every new slot reads its value back, from the column JSON, an HTML string in it, a plain column and a new kind',
    array_column($jcState['slots'], 'current', 'key'), array_column($jcSlots, 'sample', 'key'));
check('json columns: a freshly bound site has no drift', $jcContract->driftWarnings(), []);
check('json columns: the slot keeps what it says about Customize', $jcState['slots'][0]['customize'] ?? null, 'keep');

$jcApplied = $jcCall(['operation' => 'apply', 'apply_id' => 'contract-json-columns-1', 'request_id' => 'jc-1', 'expected_revision' => $jcState['revision'],
    'changes' => ['article-2.1' => 'A coach', 'article-2.2' => '7:30AM', 'article-2.3' => 'BRING WATER', 'module-160.0' => 'All',
        'module-160.2' => '200k', 'module-160.3' => 'All classes', 'contact-1.0' => 'Write to us', 'contact-1.1' => 'Ask the desk']]);
check('json columns: an apply through the new slots commits', $jcApplied['ok'] ?? null, true);
$jcImagesAfter = json_decode($jcWriter->store['article'][2]['images'], true);
check('json columns: the alt changed in the images JSON, the picture and the other keys did not',
    $jcImagesAfter, ['image_intro' => json_decode($jcImages, true)['image_intro'], 'image_intro_alt' => 'A coach', 'float_intro' => '']);
check('json columns: an extra field deep in attribs changed, its sibling did not',
    json_decode($jcWriter->store['article'][2]['attribs'], true)['classes-info']['classes-info0'], ['project_label' => 'MONDAY', 'project_info' => '7:30AM']);
$jcParams = json_decode($jcWriter->store['module'][160]['params'], true);
check('json columns: a template field of the module changed, its options did not', [$jcParams['main-section'], $jcParams['count'], $jcParams['bg-color']], ['All', 4, 'dark']);
checkTrue('json columns: one text in the HTML of a params field changed, its markup stayed',
    strpos($jcParams['top-desc'], '<b>200k</b>') !== false && strpos($jcParams['top-desc'], 'Join over ') !== false);
check('json columns: a module title and a contact are written as plain columns',
    [$jcWriter->store['module'][160]['title'], $jcWriter->store['contact'][1]['name']], ['All classes', 'Write to us']);
checkTrue('json columns: a contact HTML column takes its text', strpos($jcWriter->store['contact'][1]['misc'], 'Ask the desk') !== false);
$jcAfter = $jcContract->inspect();
check('json columns: after the apply the site reads the new values', array_column($jcAfter['slots'], 'current', 'key')['module-160.1'], 'Join over ');
check('json columns: writing the words is not drift', $jcContract->driftWarnings(), []);

// What is NOT a slot in those columns is still the design: changing it is reported.
$jcWriter->store['module'][160]['params'] = json_encode(['bg-color' => 'light'] + $jcParams);
contractDrifts('json columns: a module option beside the new slots is still the design', $jcContract, fn() => $jcContract->inspect());
$jcWriter->store['module'][160]['params'] = json_encode($jcParams);
$jcWriter->store['article'][2]['attribs'] = json_encode(['show_title' => '1'] + json_decode($jcWriter->store['article'][2]['attribs'], true));
contractDrifts('json columns: an article attribute beside the new slots is still the design', $jcContract, fn() => $jcContract->inspect());
