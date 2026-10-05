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

// Re-saving a row must keep the identity tokens of its links raw. libxml 2.9 (the Joomla site image)
// percent-encodes `{` `}` in every href/src/action it saves, so a write to the top-bar module's text
// turned its links into `tel:%7Bcontact.tel%7D` and `mailto:%7Bcontact.email%7D`, which the identity
// plugin never fills (measured on a Business j6 vi-VN copy made by Apply, 05/10/2026). libxml 2.13
// does not encode braces, so on it these cases passed before the fix too.
$idTopBar = '<ul class="tb-contacts"><li><a href="tel:{contact.tel}">Hotline: {contact.phone}</a></li>'
    . '<li><a href="mailto:{contact.email}">{contact.email}</a></li><li>{contact.address}</li></ul>';
$idTopSlots = [];
foreach (ContentSlots::htmlSlots($idTopBar) as $n => $s) $idTopSlots[] = ['key' => 'topbar.' . $n, 'entity' => 'topbar', 'column' => 'content'] + $s;
check('a write beside an identity link keeps the link raw, byte for byte',
    $idContract->patch(['content' => $idTopBar], $idTopSlots, ['topbar.1' => 'Đường dây nóng: {contact.phone}'])['content'],
    str_replace('Hotline: {contact.phone}', 'Đường dây nóng: {contact.phone}', $idTopBar));
check('a link written with an identity token keeps the token raw',
    ContentSlots::htmlPatch('<p><a href="mailto:info@example.com">Mail</a></p>', [['xpath' => '/html/body/div/p/a/@href', 'value' => 'mailto:{contact.email}']]),
    '<p><a href="mailto:{contact.email}">Mail</a></p>');
check('a token the author wrote percent-encoded stays encoded',
    ContentSlots::htmlPatch('<a href="https://x.test/?q=%7Bcontact.tel%7D">Find</a><p>Old</p>', [['xpath' => '/html/body/div/p/text()', 'value' => 'New']]),
    '<a href="https://x.test/?q=%7Bcontact.tel%7D">Find</a><p>New</p>');
// Everything but a raw identity token is saved as this libxml saves it, which is what htmlPatch did before.
$idLibxmlSave = function (string $html, string $xpath, string $value): string {
    $doc = ContentSlots::html($html); $xp = new DOMXPath($doc);
    $xp->query($xpath)->item(0)->nodeValue = $value; $out = '';
    foreach ($xp->query('//*[@id="contract-root"]')->item(0)->childNodes as $child) $out .= $doc->saveHTML($child);
    return $out;
};
$idPlainRow = '<p class="lead">Hi {site.name}</p><img src="/img/a b/{size}.png" alt="{site.name}">'
    . '<a href="https://x.test/?q=%7Bcontact.tel%7D&amp;r=1" title="{site.name} &amp; co">Find</a>';
check('a row with no raw token in a link is saved exactly as libxml saves it',
    ContentSlots::htmlPatch($idPlainRow, [['xpath' => '/html/body/div/a/text()', 'value' => 'Search']]),
    $idLibxmlSave($idPlainRow, '/html/body/div/a/text()', 'Search'));
$idMixedRow = '<img src="/img/{size}/logo.png" alt="Logo"><a href="tel:{contact.tel}">Call</a><p>Old</p>';
check('beside a raw token, braces that are not an identity token are saved as libxml saves them',
    ContentSlots::htmlPatch($idMixedRow, [['xpath' => '/html/body/div/p/text()', 'value' => 'New']]),
    str_replace('tel:%7Bcontact.tel%7D', 'tel:{contact.tel}', $idLibxmlSave($idMixedRow, '/html/body/div/p/text()', 'New')));

check('a translation that drops an identity token is refused',
    MultilingualProfile::preservationErrors('Why {site.name}', 'Warum Northgate') !== [], true);
check('a translation that keeps every identity token passes',
    MultilingualProfile::preservationErrors('Why {site.name}', 'Warum {site.name}'), []);
