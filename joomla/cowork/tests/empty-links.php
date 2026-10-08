<?php
// Loaded by run.php after contracts.php (reuses TestContractStore, ContractTestWriter, FakeApplyLog, $WTOKEN).
// A link a page no longer answers is taken off, not sent elsewhere (TCH #1013, 08/10/2026): `content.contract
// apply` empties a link slot named in `empty_links`, and only one whose ACM layout was reviewed to render no
// dead link without it (lib/link-layouts.json, keyed by the layout file's sha256).

$elRoot = sys_get_temp_dir() . '/cowork-empty-links-' . bin2hex(random_bytes(6));
$elDir = $elRoot . '/lib/contracts/test-links/j6/1.0.0';
foreach (['/templates/tpl/acm/btn/tmpl', '/templates/tpl/acm/rows/tmpl', '/lib/contracts/test-links/j6/1.0.0'] as $sub) mkdir($elRoot . $sub, 0777, true);
// The layouts' bytes are what was reviewed: a button drawn only with a link, a list whose row is text without one,
// and a second button style that prints its link whatever it holds.
$elButton = "<?php if (\$text === '' || \$link === '') return; ?><a href=\"<?php echo \$link; ?>\"><?php echo \$text; ?></a>\n";
$elAlways = "<a class=\"btn\" href=\"<?php echo \$helper->get('link'); ?>\"><?php echo \$helper->get('text'); ?></a>\n";
$elRows = "<?php foreach (\$rows as [\$t, \$l]) : ?><?php if (\$l !== '') : ?><a href=\"<?php echo \$l; ?>\"><?php echo \$t; ?></a><?php else : ?><span><?php echo \$t; ?></span><?php endif; ?><?php endforeach; ?>\n";
file_put_contents($elRoot . '/templates/tpl/acm/btn/tmpl/style-1.php', $elButton);
file_put_contents($elRoot . '/templates/tpl/acm/btn/tmpl/style-2.php', $elAlways);
file_put_contents($elRoot . '/templates/tpl/acm/rows/tmpl/style-1.php', $elRows);
file_put_contents($elRoot . '/lib/link-layouts.json', json_encode(['layouts' => [
    hash('sha256', $elButton) => ['path' => 'templates/tpl/acm/btn/tmpl/style-1.php', 'fields' => ['btn[link]' => 'hidden']],
    hash('sha256', $elRows) => ['path' => 'templates/tpl/acm/rows/tmpl/style-1.php', 'fields' => ['rows[row-link]' => 'plain']],
]]));

$elAcm = fn(string $title, array $config, string $ordering) => ['title' => $title, 'module' => 'mod_ja_acm', 'position' => 'header', 'published' => '1',
    'publish_up' => null, 'publish_down' => null, 'ordering' => $ordering, 'access' => '1', 'showtitle' => '0', 'language' => '*', 'client_id' => '0',
    'content' => '', 'params' => json_encode(['jatools-config' => json_encode($config), 'moduleclass_sfx' => ''])];
$elCta = $elAcm('Quote CTA', [':type' => 'tpl:btn', 'btn' => ['btn[text]' => ['Request a quote'], 'btn[link]' => ['index.php?Itemid=604'], 'jatools-layout-btn' => 'style-1']], '1');
$elOther = $elAcm('Other CTA', [':type' => 'tpl:btn', 'btn' => ['btn[text]' => ['See plans'], 'btn[link]' => ['index.php?Itemid=605'], 'jatools-layout-btn' => 'style-2']], '2');
$elList = $elAcm('Rows', [':type' => 'tpl:rows', 'rows' => ['rows[row-title]' => ['Careers', 'News'], 'rows[row-link]' => ['index.php?Itemid=606', 'index.php?Itemid=607'], 'jatools-layout-rows' => 'style-1']], '3');
$elHero = ['title' => 'Hero', 'module' => 'mod_custom', 'position' => 'masthead', 'published' => '1', 'publish_up' => null, 'publish_down' => null,
    'ordering' => '4', 'access' => '1', 'showtitle' => '0', 'language' => '*', 'client_id' => '0', 'content' => '<h1>Demo</h1><a href="/start">Start</a>', 'params' => '{"style":"0"}'];
$elJson = fn(string $key, string $entity, array $path, string $type, string $sample) => ['key' => $key, 'entity' => $entity, 'column' => 'params', 'type' => $type,
    'sample' => $sample, 'maxCharacters' => 2048, 'requiresEvidence' => false, 'jsonPath' => $path, 'nestedJson' => 'jatools-config'];
$elSlots = [
    $elJson('cta.0', 'cta', ['btn', 'btn[text]', 0], 'text', 'Request a quote'),
    $elJson('cta.1', 'cta', ['btn', 'btn[link]', 0], 'url', 'index.php?Itemid=604'),
    $elJson('other.0', 'other', ['btn', 'btn[text]', 0], 'text', 'See plans'),
    $elJson('other.1', 'other', ['btn', 'btn[link]', 0], 'url', 'index.php?Itemid=605'),
    $elJson('rows.0', 'rows', ['rows', 'rows[row-title]', 0], 'text', 'Careers'),
    $elJson('rows.1', 'rows', ['rows', 'rows[row-link]', 0], 'url', 'index.php?Itemid=606'),
    $elJson('rows.2', 'rows', ['rows', 'rows[row-link]', 1], 'url', 'index.php?Itemid=607'),
];
foreach (ContentSlots::htmlSlots($elHero['content']) as $n => $s) $elSlots[] = ['key' => 'hero.' . $n, 'entity' => 'hero', 'column' => 'content', 'maxCharacters' => 80] + $s;
$elStore = new TestContractStore();
$elEntities = [];
foreach (['cta' => 10, 'other' => 11, 'rows' => 12, 'hero' => 13] as $key => $id) $elEntities[] = ['key' => $key, 'kind' => 'module', 'sourceId' => $id, 'identity' => ['title' => ['cta' => 'Quote CTA', 'other' => 'Other CTA', 'rows' => 'Rows', 'hero' => 'Hero'][$key]]];
$elFiles = [
    'manifest' => ['id' => 'test-links/j6/1.0.0'],
    'content-map' => ['entities' => $elEntities, 'slots' => $elSlots, 'pages' => []],
    'presentation-lock' => ['entities' => ['cta' => $elCta, 'other' => $elOther, 'rows' => $elList, 'hero' => $elHero],
        'assignments' => [['moduleid' => 10, 'menuid' => 0], ['moduleid' => 11, 'menuid' => 0], ['moduleid' => 12, 'menuid' => 0], ['moduleid' => 13, 'menuid' => 0]],
        'fileRoots' => [], 'files' => [], 'inventoryCounts' => ['module' => 4], 'access' => $elStore->acl],
];
foreach ($elFiles as $name => $body) file_put_contents($elDir . '/' . $name . '.json', json_encode($body));

$elLog = new FakeApplyLog();
$elWriter = new ContractTestWriter($elLog, $elStore);
foreach (['cta' => [110, $elCta], 'other' => [111, $elOther], 'rows' => [112, $elList], 'hero' => [113, $elHero]] as [$id, $row]) {
    $elWriter->store['module'][$id] = ['id' => (string) $id] + $row;
    $elWriter->store['moduleAssignment'][$id] = ['menuids' => '[0]'];
}
$elContract = new QuickstartContract($elWriter, $elStore, $elRoot, $elDir);
$elContract->bind($elContract->inspect()['snapshot']);
$elEngine = new Engine($WTOKEN, [], null, null, null, null, $elWriter, null, $elLog, null, null, null, $elContract);
$elDoor = fn(array $params) => $elEngine->handle(['token' => $WTOKEN, 'action' => 'content.contract', 'params' => $params]);
$elInspect = fn() => $elDoor(['operation' => 'inspect']);
$elApply = fn(string $id, array $params) => $elDoor(['operation' => 'apply', 'apply_id' => 'contract-' . $id, 'request_id' => $id,
    'expected_revision' => $elContract->inspect()['revision']] + $params);
$elLink = fn(int $id, string $field = 'btn[link]', int $i = 0, string $acm = 'btn') =>
    json_decode(json_decode($elWriter->store['module'][$id]['params'], true)['jatools-config'], true)[$acm][$field][$i];
$elMarks = function () use ($elInspect): array {
    $out = [];
    foreach ($elInspect()['slots'] as $slot) if (isset($slot['emptyLink'])) $out[$slot['key']] = $slot['emptyLink'];
    return $out;
};

/* ------------------------------------------------ which links inspect says may be emptied */
check('inspect names the links whose reviewed layout renders no dead link without them, and how',
    $elMarks(), ['cta.1' => 'hidden', 'rows.1' => 'plain', 'rows.2' => 'plain']);
file_put_contents($elRoot . '/templates/tpl/acm/btn/tmpl/style-1.php', $elButton . "<!-- edited -->\n");
check('a layout whose bytes changed is no longer the one reviewed', $elMarks(), ['rows.1' => 'plain', 'rows.2' => 'plain']);
file_put_contents($elRoot . '/templates/tpl/acm/btn/tmpl/style-1.php', $elButton);

/* ------------------------------------------------ refusals: nothing is written */
$elCodes = fn(array $answer) => [$answer['ok'], array_column($answer['errors'] ?? [], 'code')];
check('a link slot emptied through changes is still refused as before',
    $elCodes($elApply('plain-empty', ['changes' => ['cta.1' => '']])), [false, ['SLOT_EMPTY_STATE']]);
check('a link whose layout was not reviewed cannot be emptied, nor a text slot, nor an HTML link',
    $elCodes($elApply('not-reviewed', ['empty_links' => ['other.1', 'cta.0', 'hero.1']])), [false, ['SLOT_LINK_NOT_EMPTIABLE', 'SLOT_LINK_NOT_EMPTIABLE', 'SLOT_LINK_NOT_EMPTIABLE']]);
check('an unknown slot is refused by name', $elCodes($elApply('unknown', ['empty_links' => ['nope.1']])), [false, ['SLOT_UNKNOWN']]);
check('a slot both changed and emptied is refused', $elCodes($elApply('both', ['changes' => ['cta.1' => 'index.php?Itemid=9'], 'empty_links' => ['cta.1']])), [false, ['CHANGES_INVALID']]);
check('empty_links must be a list of slot keys', $elCodes($elApply('shape', ['empty_links' => 'cta.1'])), [false, ['CHANGES_INVALID']]);
check('refusals wrote nothing', [$elLink(110), $elLink(111)], ['index.php?Itemid=604', 'index.php?Itemid=605']);

/* ------------------------------------------------ the apply, its check and its revert */
$elRevBefore = $elContract->inspect()['revision'];
$elOk = $elApply('off', ['empty_links' => ['cta.1', 'rows.1'], 'changes' => ['cta.0' => 'Get a quote']]);
check('emptied links and a changed word go in one apply', [$elOk['ok'], $elLink(110), $elLink(112, 'rows[row-link]', 0, 'rows'), $elLink(112, 'rows[row-link]', 1, 'rows')],
    [true, '', '', 'index.php?Itemid=607']);
check('the text beside an emptied link is kept', json_decode(json_decode($elWriter->store['module'][110]['params'], true)['jatools-config'], true)['btn']['btn[text]'][0], 'Get a quote');
$elAfter = $elInspect();
check('the contract still holds: no drift, the emptied slot reads empty and is still marked',
    [$elAfter['ok'], $elContract->driftWarnings(), array_column($elAfter['slots'], 'current', 'key')['cta.1'], $elMarks()['cta.1'] ?? null], [true, [], '', 'hidden']);
check('a lost reply replays the same receipt', $elDoor(['operation' => 'apply', 'apply_id' => 'contract-off', 'request_id' => 'off',
    'empty_links' => ['cta.1', 'rows.1'], 'changes' => ['cta.0' => 'Get a quote']]), $elOk);
$elReused = $elDoor(['operation' => 'apply', 'apply_id' => 'contract-off', 'request_id' => 'off', 'empty_links' => ['cta.1'], 'changes' => ['cta.0' => 'Get a quote']]);
check('the same request id with other links to empty is refused', [$elReused['ok'], $elReused['message']], [false, 'request_id reused with different content']);
check('emptying a link that is already empty changes nothing', $elApply('again', ['empty_links' => ['cta.1']])['unchanged'] ?? null, true);
$elRevert = $elEngine->handle(['token' => $WTOKEN, 'action' => 'apply.revert', 'params' => ['apply_id' => 'contract-off']]);
check('apply.revert puts the links and the word back', [$elRevert['ok'], $elLink(110), $elLink(112, 'rows[row-link]', 0, 'rows'),
    json_decode(json_decode($elWriter->store['module'][110]['params'], true)['jatools-config'], true)['btn']['btn[text]'][0]], [true, 'index.php?Itemid=604', 'index.php?Itemid=606', 'Request a quote']);
check('and the site is where it began', [$elContract->inspect()['revision'], $elContract->driftWarnings()], [$elRevBefore, []]);

/* ------------------------------------------------ an emptied link is a link again when one is written */
$elApply('off-2', ['empty_links' => ['cta.1']]);
$elBack = $elApply('on-again', ['changes' => ['cta.1' => 'index.php?Itemid=608']]);
check('a link written into an emptied slot shows the button again', [$elBack['ok'], $elLink(110)], [true, 'index.php?Itemid=608']);

/* ------------------------------------------------ a site whose receiver carries no review */
unlink($elRoot . '/lib/link-layouts.json');
$elBare = new QuickstartContract($elWriter, $elStore, $elRoot, $elDir);
check('without the reviewed list no link is emptiable', array_values(array_filter(array_column($elBare->inspect()['slots'], 'emptyLink'))), []);

/* ------------------------------------------------ the shipped review is tied to bytes a published quickstart ships */
$elShipped = json_decode((string) file_get_contents(__DIR__ . '/../lib/link-layouts.json'), true);
$elPaths = array_flip(array_column($elShipped['layouts'], 'path'));
$elLocked = []; $elFields = [];
foreach (glob(__DIR__ . '/../lib/contracts/*/*/*', GLOB_ONLYDIR) as $elProfile) {
    // Only the paths the review names are kept: every profile together is far more than the runner's memory.
    foreach (json_decode((string) file_get_contents($elProfile . '/presentation-lock.json'), true)['files'] ?? [] as $path => $hash)
        if (isset($elPaths[$path])) $elLocked[$hash][$path] = true;
    foreach (json_decode((string) file_get_contents($elProfile . '/content-map.json'), true)['slots'] as $slot)
        if (($slot['type'] ?? '') === 'url' && isset($slot['jsonPath'][1])) $elFields[$slot['jsonPath'][1]] = true;
    gc_collect_cycles();
}
$elStray = [];
foreach ($elShipped['layouts'] as $hash => $layout) {
    if (!isset($elLocked[$hash][$layout['path']])) $elStray[] = $layout['path'] . ' is not locked with these bytes in any shipped contract';
    foreach ($layout['fields'] as $field => $renders) {
        if (!isset($elFields[$field])) $elStray[] = $field . ' is no link slot of any shipped contract';
        if (!in_array($renders, ['hidden', 'plain'], true)) $elStray[] = $field . ' renders ' . json_encode($renders);
    }
}
check('every reviewed layout is one a shipped contract locks, by path and sha256, and every field is a link slot', $elStray, []);
$elByPath = array_combine(array_column($elShipped['layouts'], 'path'), array_keys($elShipped['layouts']));
check('the review covers the Tracy Business header button', $elShipped['layouts'][$elByPath['templates/tracy_business/acm/tb-button/tmpl/style-1.php'] ?? '']['fields'] ?? null, ['tb-button[link]' => 'hidden']);
unset($elShipped, $elByPath, $elPaths, $elLocked, $elFields, $elStray);
