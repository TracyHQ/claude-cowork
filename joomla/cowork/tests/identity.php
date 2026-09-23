<?php
// Loaded by run.php: a Tracy quickstart keeps its name, contact and social links in ONE module, and
// every other place says `{site.name}`, `{contact.email}`… — tokens plg_system_tracyidentity (shipped
// inside the quickstart archive) replaces as the page is sent. The contract has to let exactly those
// tokens through, and let the identity module's own slots be emptied or filled.

check('an identity token is text a customer can edit, not a Joomla directive',
    array_column(ContentSlots::htmlSlots('<h2>Why {site.name}</h2>'), 'sample'), ['Why {site.name}']);
check('a directive next to an identity token is still never editable',
    ContentSlots::htmlSlots('<p>{site.name} {loadposition hero}</p>'), []);
check('an unknown token is still a directive', ContentSlots::htmlSlots('<p>{site.evil}</p>'), []);

$idDir = sys_get_temp_dir() . '/cowork-identity-' . bin2hex(random_bytes(6));
mkdir($idDir);
$idHero = ['title' => 'Why us', 'module' => 'mod_custom', 'position' => 'masthead', 'published' => '1', 'access' => '1',
    'language' => '*', 'client_id' => '0', 'content' => '<h2>Why {site.name}</h2>', 'params' => '{"style":"0"}'];
$idConfig = [':type' => 'tracy_business:site-identity', 'site-identity' => ['site-name' => 'Northgate Industrial', 'phone' => '', 'tiktok' => '']];
$idModule = ['title' => '[Tracy] Site identity', 'module' => 'mod_ja_acm', 'position' => '', 'published' => '1', 'access' => '1',
    'language' => '*', 'client_id' => '0', 'content' => '', 'params' => json_encode(['jatools-config' => json_encode($idConfig)])];
$idWriter = new FakeSiteWriter();
$idWriter->store['module'][110] = ['id' => '110'] + $idHero;
$idWriter->store['module'][111] = ['id' => '111'] + $idModule;
$idWriter->store['moduleAssignment'][110] = ['menuids' => '[0]'];
$idWriter->store['moduleAssignment'][111] = ['menuids' => '[]'];
$idSlots = [];
foreach (ContentSlots::htmlSlots($idHero['content']) as $n => $s) $idSlots[] = ['key' => 'hero.' . $n, 'entity' => 'hero', 'column' => 'content', 'maxCharacters' => 80] + $s;
foreach (['site-name' => ['text', 'Northgate Industrial'], 'phone' => ['text', ''], 'tiktok' => ['url', '']] as $field => [$type, $sample])
    $idSlots[] = ['key' => 'identity.' . $field, 'entity' => 'identity', 'column' => 'params', 'type' => $type, 'sample' => $sample,
        'maxCharacters' => 255, 'jsonPath' => ['site-identity', $field], 'nestedJson' => 'jatools-config', 'siteIdentity' => true];
$idFiles = [
    'manifest' => ['id' => 'identity/v1'],
    'content-map' => ['entities' => [
        ['key' => 'hero', 'kind' => 'module', 'sourceId' => 10, 'identity' => ['title' => 'Why us']],
        ['key' => 'identity', 'kind' => 'module', 'sourceId' => 11, 'identity' => ['title' => '[Tracy] Site identity']],
    ], 'slots' => $idSlots, 'pages' => []],
    'presentation-lock' => ['entities' => ['hero' => $idHero, 'identity' => $idModule], 'assignments' => [['moduleid' => 10, 'menuid' => 0]],
        'fileRoots' => [], 'files' => [], 'inventoryCounts' => ['module' => 2], 'access' => (new TestContractStore())->acl],
];
foreach ($idFiles as $name => $body) file_put_contents($idDir . '/' . $name . '.json', json_encode($body));
$idContract = new QuickstartContract($idWriter, new TestContractStore(), $idDir, $idDir);
$idState = $idContract->inspect();
$idContract->bind($idState['snapshot']);
$idPlan = fn(array $changes) => $idContract->plan(['expected_revision' => $idState['revision'], 'changes' => $changes]);

check('the customer may keep a token in copy they rewrite',
    str_contains(json_encode($idPlan(['hero.0' => 'Why choose {site.name} today'])['operations']), 'Why choose {site.name} today'), true);
contractRejects('an unknown token is refused like any directive', fn() => $idPlan(['hero.0' => 'Why {site.evil}']));
contractRejects('a directive beside an identity token is still refused', fn() => $idPlan(['hero.0' => '{site.name} {loadposition x}']));
check('an identity fact the customer never gave can be emptied',
    count($idPlan(['identity.site-name' => 'Sarah', 'identity.phone' => '', 'identity.tiktok' => 'https://tiktok.com/@sarah'])['operations']), 1);
contractRejects('an ordinary slot still cannot be emptied', fn() => $idPlan(['hero.0' => '']));
contractRejects('an identity URL is still held to the CTA rules', fn() => $idPlan(['identity.tiktok' => 'javascript:alert(1)']));

$idOps = $idPlan(['identity.site-name' => 'Sarah', 'identity.phone' => '', 'hero.0' => 'Why {site.name} now'])['operations'];
// The real SiteWriter updates the named fields; the fake replaces a row, so merge like the real one.
foreach ($idOps as $op) $idWriter->store[$op['kind']][$op['id']] = $op['fields'] + $idWriter->store[$op['kind']][$op['id']];
$idAfter = $idContract->inspect();
check('after the identity is written the contract still holds, with the new values read back',
    [$idAfter['slotValues']['identity.site-name'] ?? null, $idAfter['slotValues']['hero.0'] ?? null], ['Sarah', 'Why {site.name} now']);

check('a phone link built from the identity is an editable link, not a directive',
    array_column(ContentSlots::htmlSlots('<a href="tel:{contact.tel}">{contact.phone}</a>'), 'sample'), ['tel:{contact.tel}', '{contact.phone}']);
