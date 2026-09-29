<?php
// Loaded by run.php. A derived contract: an imported site bound to the map its own rows make
// (DerivedMap), written through the same Apply door as a quickstart, and taken back the same way.
require_once __DIR__ . '/../lib/ContentProjection.php';
echo "\nDerived contract\n";

$dcRoot = sys_get_temp_dir() . '/cowork-derived-' . bin2hex(random_bytes(6));
mkdir($dcRoot);
$dcFieldValue = FieldValueKey::encode(3, 12);
$dcSeed = [
    'article' => [12 => ['id' => '12', 'title' => 'Hello Northwind', 'alias' => 'hello-northwind', 'introtext' => '<p>Welcome aboard</p>', 'fulltext' => '',
        'state' => '1', 'access' => '1', 'catid' => '5', 'language' => '*', 'attribs' => '{}', 'images' => '{}', 'urls' => '{}',
        'publish_up' => null, 'publish_down' => null, 'created' => null, 'modified' => null, 'created_by' => '0', 'created_by_alias' => '']],
    'module' => [7 => ['id' => '7', 'title' => 'Footer', 'module' => 'mod_custom', 'content' => '<p>Call us today</p>',
        'params' => '{"tagline":"Ships in 24h","style":"dark"}', 'published' => '1', 'access' => '1', 'position' => 'footer', 'language' => '*',
        'showtitle' => '0', 'ordering' => '1', 'publish_up' => null, 'publish_down' => null, 'client_id' => '0']],
    'category' => [5 => ['id' => '5', 'title' => 'News', 'alias' => 'news', 'description' => '<p>Latest from Northwind</p>', 'params' => '{}',
        'extension' => 'com_content', 'published' => '1', 'access' => '1', 'parent_id' => '1', 'language' => '*']],
    'fieldValue' => [$dcFieldValue => ['field_id' => '3', 'item_id' => '12', 'value' => 'Oak and steel']],
    'templateStyle' => [7 => ['id' => '7', 'title' => 'Northwind - Default', 'template' => 'northwind', 'home' => '1', 'client_id' => '0',
        'params' => '{"footerText":"Made in Oslo"}']],
];
// What JoomlaDerivedRows reads, from the double's rows: the same columns per kind.
$dcRowsOf = static function (array $store): array {
    $out = [];
    foreach ($store['article'] ?? [] as $id => $r) $out[] = ['kind' => 'article', 'id' => $id, 'identity' => ['id' => $id], 'core' => ['title' => $r['title']],
        'html' => ['introtext' => $r['introtext'], 'fulltext' => $r['fulltext']], 'nested' => ['attribs' => $r['attribs'], 'images' => $r['images'], 'urls' => $r['urls']]];
    foreach ($store['module'] ?? [] as $id => $r) $out[] = ['kind' => 'module', 'id' => $id, 'identity' => ['id' => $id], 'core' => ['title' => $r['title']],
        'html' => $r['module'] === 'mod_custom' ? ['content' => $r['content']] : [], 'nested' => ['params' => $r['params']]];
    foreach ($store['category'] ?? [] as $id => $r) $out[] = ['kind' => 'category', 'id' => $id, 'identity' => ['id' => $id], 'core' => ['title' => $r['title']],
        'html' => ['description' => $r['description']], 'nested' => ['params' => $r['params']]];
    foreach ($store['fieldValue'] ?? [] as $id => $r) $out[] = ['kind' => 'fieldValue', 'id' => $id, 'identity' => ['fieldId' => (int) $r['field_id'], 'itemId' => (int) $r['item_id']],
        'core' => [], 'html' => [], 'nested' => ['value' => $r['value']]];
    foreach ($store['menuItem'] ?? [] as $id => $r) $out[] = ['kind' => 'menuItem', 'id' => $id, 'identity' => ['id' => $id], 'core' => ['title' => $r['title']], 'html' => [], 'nested' => ['params' => $r['params']]];
    foreach ($store['templateStyle'] ?? [] as $id => $r) $out[] = ['kind' => 'templateStyle', 'id' => $id, 'identity' => ['id' => $id], 'core' => [], 'html' => [], 'nested' => ['params' => $r['params']]];
    return $out;
};
$dcPages = ['<html><body><h1>Hello Northwind</h1><p>Welcome aboard</p><p>Call us today</p><div>Ships in 24h</div><p>Oak and steel</p><p>Made in Oslo</p><p>Words from a theme file</p></body></html>'];
$dcFresh = static function () use ($dcSeed): array {
    $store = new TestContractStore(); $log = new FakeApplyLog();
    $writer = new ContractTestWriter($log, $store); $writer->store = $dcSeed;
    return [$store, $log, $writer];
};
// The way EngineFactory builds the contract of a derived site: lazily, from the rows, kept leaves per the binding.
$dcContractOf = static function (ContractTestWriter $writer, TestContractStore $store, ?int &$builds = null) use ($dcRowsOf, $dcRoot): QuickstartContract {
    $builds = 0;
    return QuickstartContract::derived($writer, $store, $dcRoot, static function () use ($writer, $store, $dcRowsOf, &$builds): array {
        $builds++;
        $binding = $store->load();
        return DerivedMap::build($dcRowsOf($writer->store), null, substr((string) $binding['contract'], strlen('derived/')), (int) $binding['algorithm'], $binding['keep']);
    });
};
$dcApple = static fn(ContractTestWriter $writer, TestContractStore $store): QuickstartContract =>
    new QuickstartContract($writer, $store, $dcRoot, __DIR__ . '/../lib/contracts/tracy-apple/j6/1.1.0');
$dcDerive = static fn(string $requestId, string $label = 'northwind-import') =>
    ['token' => $WTOKEN, 'action' => 'content.contract', 'params' => ['operation' => 'derive', 'label' => $label, 'requestId' => $requestId]];

// 1. The hash names the algorithm, never the rows: every apply changes the rows.
[$dcStore, $dcLog, $dcWriter] = $dcFresh();
$dcBuilt = DerivedMap::build($dcRowsOf($dcWriter->store), null, 'northwind-import');
$dcChanged = $dcWriter->store; $dcChanged['article'][12]['title'] = 'Goodbye Northwind';
$dcA = QuickstartContract::derived($dcWriter, $dcStore, $dcRoot, fn() => $dcBuilt);
$dcB = QuickstartContract::derived($dcWriter, $dcStore, $dcRoot, fn() => DerivedMap::build($dcRowsOf($dcChanged), null, 'northwind-import'));
check('derived: the contract hash does not depend on the rows', $dcA->contractHash(), $dcB->contractHash());
checkTrue('derived: and differs from a quickstart profile\'s', $dcA->contractHash() !== $dcApple($dcWriter, $dcStore)->contractHash());
$dcLazyBuilds = 0;
$dcLazy = QuickstartContract::derived($dcWriter, $dcStore, $dcRoot, function () use (&$dcLazyBuilds, $dcBuilt) { $dcLazyBuilds++; return $dcBuilt; });
$dcLazyEngine = new Engine($WTOKEN, ['php' => PHP_VERSION], null, null, null, null, $dcWriter, null, $dcLog, null, null, null, $dcLazy);
check('derived: an action that reads no content does not scan the rows', [$dcLazyEngine->handle(['token' => $WTOKEN, 'action' => 'info'])['ok'], $dcLazyBuilds], [true, 0]);

// 2 + 4b. Derive on an unbound import that the factory handed the default Apple profile (EngineFactory's fallback).
[$dcStore, $dcLog, $dcWriter] = $dcFresh();
$dcEngine = (new Engine($WTOKEN, [], null, null, null, null, $dcWriter, null, $dcLog, null, null, null, $dcApple($dcWriter, $dcStore)))
    ->derivedSource($dcStore, $dcRoot, fn() => ['rows' => $dcRowsOf($dcWriter->store), 'pages' => $dcPages, 'unresolved' => ['module 9.params is 2000001 bytes, not scanned']]);
$dcAnswer = $dcEngine->handle($dcDerive('derive-1'));
check('derived: derive answers ok on an unbound site the default profile was wired for', ($dcAnswer['ok'] ?? false) ? true : $dcAnswer, true);
check('derived: the binding is derived and names its label and request',
    [$dcStore->binding['mode'] ?? null, $dcStore->binding['contract'] ?? null, $dcStore->binding['requestId'] ?? null, $dcStore->binding['calibrated'] ?? null],
    ['derived', 'derived/northwind-import', 'derive-1', true]);
check('derived: the answer counts entities, slots and classes', [$dcAnswer['contract'] ?? null, $dcAnswer['entities'] ?? null, $dcAnswer['calibrated'] ?? null, $dcAnswer['replayed'] ?? null],
    ['derived/northwind-import', 5, true, false]);
check('derived: db slots, the nested leaves the pages show, and one visible block no leaf holds', $dcAnswer['byClass'] ?? null, ['db' => 6, 'nested' => 3, 'unmatched' => 1]);
check('derived: slots adds up', $dcAnswer['slots'] ?? null, 9);
check('derived: what was not scanned is said', $dcAnswer['unresolved'] ?? null, ['module 9.params is 2000001 bytes, not scanned']);
check('derived: the kept nested leaves are stored for later reads', count($dcStore->binding['keep'] ?? []), 3);

// 3. The same request again: the binding it made, untouched.
$dcBound = $dcStore->binding;
$dcReplay = $dcEngine->handle($dcDerive('derive-1'));
check('derived: a replayed derive answers ok, replayed', [$dcReplay['ok'] ?? null, $dcReplay['replayed'] ?? null, $dcReplay['contract'] ?? null], [true, true, 'derived/northwind-import']);
check('derived: and leaves the binding as it was', $dcStore->binding, $dcBound);
$dcBad = $dcEngine->handle(['token' => $WTOKEN, 'action' => 'content.contract', 'params' => ['operation' => 'derive', 'label' => 'No', 'requestId' => 'derive-2']]);
check('derived: a malformed label is refused', [$dcBad['ok'], $dcBad['error']], [false, 'bad_params']);
$dcBad = $dcEngine->handle(['token' => $WTOKEN, 'action' => 'content.contract', 'params' => ['operation' => 'derive', 'label' => 'northwind-import']]);
check('derived: a missing requestId is refused', [$dcBad['ok'], $dcBad['error']], [false, 'bad_params']);

// 4. A site bound to a quickstart keeps its binding.
[$dcQsStore, $dcQsLog, $dcQsWriter] = $dcFresh();
$dcQsStore->binding = ['contractHash' => 'quickstart-hash', 'ids' => ['hero' => 110], 'presentation' => [], 'assignments' => [], 'counts' => [], 'access' => []];
$dcQsEngine = (new Engine($WTOKEN, [], null, null, null, null, $dcQsWriter, null, $dcQsLog, null, null, null, $dcApple($dcQsWriter, $dcQsStore)))
    ->derivedSource($dcQsStore, $dcRoot, fn() => ['rows' => $dcRowsOf($dcQsWriter->store), 'pages' => $dcPages, 'unresolved' => []]);
$dcRefused = $dcQsEngine->handle($dcDerive('derive-qs'));
check('derived: a quickstart-bound site refuses derive', [$dcRefused['ok'], $dcRefused['error'], $dcRefused['message']],
    [false, 'conflict', 'This site is bound to a quickstart contract; derive refuses to replace it']);
check('derived: and its binding stands', $dcQsStore->binding['contractHash'], 'quickstart-hash');

// A site a quickstart Build is making: its own bind comes later and must find the site unbound.
[$dcBuildStore, $dcBuildLog, $dcBuildWriter] = $dcFresh();
$dcBuildEngine = (new Engine($WTOKEN, [], null, null, null, null, $dcBuildWriter, null, $dcBuildLog))
    ->underConstruction('tracy-base/j6/1.0.0')
    ->derivedSource($dcBuildStore, $dcRoot, fn() => ['rows' => $dcRowsOf($dcBuildWriter->store), 'pages' => $dcPages, 'unresolved' => []]);
$dcRefused = $dcBuildEngine->handle($dcDerive('derive-build'));
check('derived: a site under construction refuses derive', [$dcRefused['ok'], $dcRefused['error'], $dcRefused['message']],
    [false, 'conflict', 'This site is being built from a quickstart; derive refuses']);
check('derived: and stays unbound', $dcBuildStore->binding, null);

// 4c. No page could be fetched (loopback blocked): bound all the same, uncalibrated, every nested leaf kept.
[$dcBlindStore, $dcBlindLog, $dcBlindWriter] = $dcFresh();
$dcBlindEngine = (new Engine($WTOKEN, [], null, null, null, null, $dcBlindWriter, null, $dcBlindLog))
    ->derivedSource($dcBlindStore, $dcRoot, fn() => ['rows' => $dcRowsOf($dcBlindWriter->store), 'pages' => [], 'unresolved' => []]);
$dcBlind = $dcBlindEngine->handle($dcDerive('derive-blind'));
check('derived: without pages derive still binds, uncalibrated', [$dcBlind['ok'] ?? null, $dcBlind['calibrated'] ?? null, $dcBlindStore->binding['calibrated'] ?? null],
    [true, false, false]);
check('derived: an uncalibrated binding keeps null (every nested leaf)', array_key_exists('keep', $dcBlindStore->binding) ? $dcBlindStore->binding['keep'] : 'absent', null);
$dcBlindRead = $dcContractOf($dcBlindWriter, $dcBlindStore)->readMapping();
check('derived: a later read keeps every nested leaf', array_sum(array_map('count', $dcBlindRead['slots'])),
    count(DerivedMap::build($dcRowsOf($dcSeed), null, 'northwind-import')['map']['slots']));

// 5. The read mapping of a derived site.
$dcContract = $dcContractOf($dcWriter, $dcStore, $dcBuilds);
$dcMapping = $dcContract->readMapping();
checkTrue('derived: the mapping names article-12 and module-7', isset($dcMapping['keys']['article-12'], $dcMapping['keys']['module-7']));
check('derived: every mapped entity resolved to its row', $dcMapping['ids']['module-7'], 7);
checkTrue('derived: slots carry their leaf path', array_key_exists('leaf', $dcMapping['slots']['module-7'][0]));
checkTrue('derived: slots never carry a sample', !isset($dcMapping['slots']['module-7'][0]['sample']));
check('derived: the map was built once for the read', $dcBuilds, 1);
// 7. The envelope content.read serves (JoomlaContentReader::read reads these three).
check('derived: the envelope names the derived contract',
    [$dcMapping['manifest']['quickstart']['release'], $dcMapping['manifest']['quickstart']['version'], $dcMapping['manifest']['id'], $dcMapping['contractHash']],
    ['derived', (string) DerivedMap::ALGORITHM, 'derived/northwind-import', $dcStore->binding['contractHash']]);

// 6. Apply one nested leaf, then take it back to the byte.
$dcApplyEngine = new Engine($WTOKEN, [], null, null, null, null, $dcWriter, null, $dcLog, null, null, null, $dcContractOf($dcWriter, $dcStore));
$dcInspect = $dcApplyEngine->handle(['token' => $WTOKEN, 'action' => 'content.contract', 'params' => ['operation' => 'inspect']]);
check('derived: inspect answers ok', $dcInspect['ok'] ?? $dcInspect, true);
$dcTagline = current(array_filter($dcInspect['slots'] ?? [], fn($s) => $s['current'] === 'Ships in 24h'));
check('derived: the tagline leaf is a slot of module-7', [$dcTagline['entity'] ?? null, $dcTagline['column'] ?? null], ['module-7', 'params']);
$dcParamsBefore = $dcWriter->store['module'][7]['params'];
$dcApplied = $dcApplyEngine->handle(['token' => $WTOKEN, 'action' => 'content.contract', 'params' => ['operation' => 'apply', 'apply_id' => 'contract-dc-1',
    'request_id' => 'dc-1', 'expected_revision' => $dcInspect['revision'], 'changes' => [$dcTagline['key'] => 'Ships in 48h']]]);
check('derived: the leaf apply answers ok', $dcApplied['ok'] ?? $dcApplied, true);
$dcParams = json_decode($dcWriter->store['module'][7]['params'], true);
check('derived: params still decode, with the new words at the leaf and the rest untouched', $dcParams, ['tagline' => 'Ships in 48h', 'style' => 'dark']);
check('derived: the leaf reads back through the codec', LeafCodec::get($dcWriter->store['module'][7]['params'], $dcTagline['leaf']), 'Ships in 48h');
check('derived: the binding did not move', $dcStore->binding, $dcBound);
$dcAgain = new Engine($WTOKEN, [], null, null, null, null, $dcWriter, null, $dcLog, null, null, null, $dcContractOf($dcWriter, $dcStore));
$dcNext = $dcAgain->handle(['token' => $WTOKEN, 'action' => 'content.contract', 'params' => ['operation' => 'inspect']]);
checkTrue('derived: the next request finds the same slot key with the new words',
    in_array([$dcTagline['key'], 'Ships in 48h'], array_map(fn($s) => [$s['key'], $s['current']], $dcNext['slots'] ?? []), true));
$dcReverted = $dcAgain->handle(['token' => $WTOKEN, 'action' => 'apply.revert', 'params' => ['apply_id' => 'contract-dc-1']]);
check('derived: apply.revert answers ok', $dcReverted['ok'] ?? $dcReverted, true);
check('derived: params are back to the byte', $dcWriter->store['module'][7]['params'], $dcParamsBefore);
$dcHtml = current(array_filter($dcNext['slots'] ?? [], fn($s) => $s['current'] === 'Welcome aboard'));
$dcNext = $dcAgain->handle(['token' => $WTOKEN, 'action' => 'content.contract', 'params' => ['operation' => 'inspect']]);
$dcHtmlApply = $dcAgain->handle(['token' => $WTOKEN, 'action' => 'content.contract', 'params' => ['operation' => 'apply', 'apply_id' => 'contract-dc-2',
    'request_id' => 'dc-2', 'expected_revision' => $dcNext['revision'], 'changes' => [$dcHtml['key'] => 'Welcome & enjoy']]]);
check('derived: an HTML leaf is rewritten in place, escaped', [$dcHtmlApply['ok'] ?? $dcHtmlApply, $dcWriter->store['article'][12]['introtext']], [true, '<p>Welcome &amp; enjoy</p>']);
$dcWriter->store['article'][12]['introtext'] = '<p>Welcome aboard</p>';

// Projection: a category, a custom field value and a template style on a derived site — no 501,
// and the template style is read under an identity of its own, never a module's with the same id.
$dcTables = static fn(array $identities) => ['menu' => [], 'modules' => array_values($dcWriter->store['module']), 'modules_menu' => [],
    'categories' => array_values($dcWriter->store['category']), 'viewlevels' => [['id' => '1', 'rules' => '[1]']], 'associations' => [],
    'claudecowork_content_identity' => $identities,
    'fields' => [['id' => '3', 'name' => 'material', 'type' => 'text', 'context' => 'com_content.article', 'state' => '1', 'access' => '1', 'language' => '*']],
    'fields_values' => [['field_id' => '3', 'item_id' => '12', 'value' => 'Oak and steel']], 'tags' => [], 'contentitem_tag_map' => []];
$dcIdentities = [['kind' => 'article', 'native_id' => '12', 'uid' => str_repeat('a', 32)], ['kind' => 'shared', 'native_id' => '7', 'uid' => str_repeat('b', 32)],
    ['kind' => 'templateStyle', 'native_id' => '7', 'uid' => str_repeat('c', 32)]];
$dcProjection = null;
try { $dcProjection = ContentProjection::build($dcTables($dcIdentities), $dcContract->readMapping(), 'site-secret', 'https://fixture.invalid/', 1000000, [$dcContract, 'slotValue']); }
catch (Throwable $e) { check('derived: content.read projects a derived site', $e->getMessage(), 'no error'); }
$dcOpaque = ContentProjection::opaque('site-secret');
if ($dcProjection !== null) {
    $dcContents = $dcProjection['contents'];
    check('derived: the template style is its own shared content', $dcContents[$dcOpaque('content', str_repeat('c', 32))]['type'] ?? null, 'shared');
    checkTrue('derived: and not the module\'s', $dcOpaque('content', str_repeat('c', 32)) !== $dcOpaque('content', str_repeat('b', 32)) && isset($dcContents[$dcOpaque('content', str_repeat('b', 32))]));
    $dcArticleFields = array_merge(...array_map(fn($b) => $b['fields'], $dcContents[$dcOpaque('content', str_repeat('a', 32))]['blocks']));
    $dcFieldSlot = current(array_filter($dcArticleFields, fn($f) => $f['slotKey'] !== null && str_starts_with($f['slotKey'], 'fieldValue-')));
    check('derived: a custom field value is a slot field of its article, named by its column', [$dcFieldSlot['value'] ?? null, $dcFieldSlot['semanticKey'] ?? null], ['Oak and steel', 'value']);
    check('derived: and is owned by that article', $dcProjection['owners']['fieldValue-' . $dcFieldValue] ?? null, $dcOpaque('content', str_repeat('a', 32)));
    $dcCategory = $dcContents[$dcOpaque('content', 'category:5')] ?? null;
    $dcCategoryFields = $dcCategory ? array_merge(...array_map(fn($b) => $b['fields'], $dcCategory['blocks'])) : [];
    checkTrue('derived: a category\'s slots are fields of its category content', in_array('News', array_column(array_filter($dcCategoryFields, fn($f) => $f['slotKey'] !== null), 'value'), true));
}
try {
    ContentProjection::build($dcTables(array_slice($dcIdentities, 0, 2)), $dcContract->readMapping(), 'site-secret', 'https://fixture.invalid/', 1000000, [$dcContract, 'slotValue']);
    check('derived: a template style without an identity of its own refuses', 'projected', 'refused');
} catch (ContentReadError $e) { check('derived: a template style without an identity of its own refuses', $e->status, 501); }

// A quickstart bind on a site a derive bound: refused like the reverse, the derived binding stands.
$dcQsBind = (new Engine($WTOKEN, [], null, null, null, null, $dcWriter, null, $dcLog, null, null, null, $dcApple($dcWriter, $dcStore)))
    ->handle(['token' => $WTOKEN, 'action' => 'content.contract', 'params' => ['operation' => 'bind']]);
check('derived: a quickstart bind on a derived site is refused', [$dcQsBind['ok'], $dcQsBind['error'] ?? null], [false, 'conflict']);
check('derived: and the derived binding stands', $dcStore->binding, $dcBound);

// #__fields_values has no key of its own: a multiple-value field (checkbox, list) keeps several rows
// for one (field_id, item_id), and none of them is "the" value — read as none, written never.
$dcFvWriter = new FakeSiteWriter();
$dcFvWriter->fieldValues = [['field_id' => '3', 'item_id' => '12', 'value' => 'Oak'], ['field_id' => '4', 'item_id' => '12', 'value' => 'red'], ['field_id' => '4', 'item_id' => '12', 'value' => 'blue']];
$dcFvLog = new FakeApplyLog();
$dcFvEngine = new Engine($WTOKEN, [], null, null, null, null, $dcFvWriter, null, $dcFvLog);
check('field value: one row reads as that row', $dcFvWriter->read('fieldValue', FieldValueKey::encode(3, 12))['value'] ?? null, 'Oak');
check('field value: a multiple-value pair reads as none, never an arbitrary one of its rows', $dcFvWriter->read('fieldValue', FieldValueKey::encode(4, 12)), null);
$dcFvTable = $dcFvWriter->fieldValues;
$dcFvRefused = $dcFvEngine->handle(['token' => $WTOKEN, 'action' => 'content.update', 'params' => ['apply_id' => 'fv-1', 'kind' => 'fieldValue', 'id' => FieldValueKey::encode(4, 12), 'fields' => ['value' => 'green']]]);
check('field value: a multiple-value pair is refused', [$dcFvRefused['ok'], str_contains((string) ($dcFvRefused['message'] ?? ''), 'multiple-value')], [false, true]);
check('field value: and nothing is written', $dcFvWriter->fieldValues, $dcFvTable);
$dcFvDone = $dcFvEngine->handle(['token' => $WTOKEN, 'action' => 'content.update', 'params' => ['apply_id' => 'fv-2', 'kind' => 'fieldValue', 'id' => FieldValueKey::encode(3, 12), 'fields' => ['value' => 'Walnut']]]);
check('field value: a single value is written in place', [$dcFvDone['ok'], $dcFvWriter->fieldValues[0]['value']], [true, 'Walnut']);
$dcFvEngine->handle(['token' => $WTOKEN, 'action' => 'apply.revert', 'params' => ['apply_id' => 'fv-2']]);
check('field value: and reverted', $dcFvWriter->fieldValues, $dcFvTable);

// Pictures and links on a derived site: whatever form the import stored, written back in that form.
$dcPng = static function (string $file, int $w, int $h): void {
    $ihdr = pack('NN', $w, $h) . "\x08\x02\x00\x00\x00";
    file_put_contents($file, "\x89PNG\r\n\x1a\n" . pack('N', 13) . 'IHDR' . $ihdr . pack('N', crc32('IHDR' . $ihdr)));
};
@mkdir($dcRoot . '/images');
foreach (['a' => [4, 2], 'b' => [4, 2], 'c' => [4, 2], 'd' => [8, 2]] as $dcName => [$dcW, $dcH]) $dcPng($dcRoot . '/images/' . $dcName . '.png', $dcW, $dcH);
file_put_contents($dcRoot . '/images/e.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');
[$dcImStore, $dcImLog, $dcImWriter] = $dcFresh();
$dcImWriter->store['module'][8] = ['id' => '8', 'title' => 'Hero', 'module' => 'mod_custom', 'content' => '', 'published' => '1', 'access' => '1', 'position' => 'hero',
    'language' => '*', 'showtitle' => '0', 'ordering' => '1', 'publish_up' => null, 'publish_down' => null, 'client_id' => '0',
    'params' => json_encode(['photo' => '/images/a.png', 'banner' => '/images/c.png#joomlaImage://local-images/c.png?width=4&height=2', 'cta' => '/about-us', 'more' => 'https://northwind.example/tour?x=1'], JSON_UNESCAPED_SLASHES)];
(new Engine($WTOKEN, [], null, null, null, null, $dcImWriter, null, $dcImLog))
    ->derivedSource($dcImStore, $dcRoot, fn() => ['rows' => $dcRowsOf($dcImWriter->store), 'pages' => [], 'unresolved' => []])->handle($dcDerive('derive-images'));
$dcImEngine = new Engine($WTOKEN, [], null, null, null, null, $dcImWriter, null, $dcImLog, null, null, null, $dcContractOf($dcImWriter, $dcImStore));
$dcImSlot = static function (string $current) use ($dcImEngine, $WTOKEN): array {
    $state = $dcImEngine->handle(['token' => $WTOKEN, 'action' => 'content.contract', 'params' => ['operation' => 'inspect']]);
    foreach ($state['slots'] ?? [] as $slot) if ($slot['current'] === $current) return [$slot, $state['revision']];
    return [['key' => 'missing ' . $current, 'type' => null], $state['revision'] ?? ''];
};
$dcImApply = static function (string $current, string $value, string $id) use ($dcImEngine, $dcImSlot, $WTOKEN): array {
    [$slot, $revision] = $dcImSlot($current);
    return $dcImEngine->handle(['token' => $WTOKEN, 'action' => 'content.contract', 'params' => ['operation' => 'apply', 'apply_id' => 'contract-' . $id,
        'request_id' => $id, 'expected_revision' => $revision, 'changes' => [$slot['key'] => $value]]]);
};
$dcImParams = static fn() => json_decode($dcImWriter->store['module'][8]['params'], true);
check('derived image: the stored forms are image and url slots', [$dcImSlot('/images/a.png')[0]['type'], $dcImSlot('/images/c.png#joomlaImage://local-images/c.png?width=4&height=2')[0]['type'],
    $dcImSlot('/about-us')[0]['type'], $dcImSlot('https://northwind.example/tour?x=1')[0]['type']], ['image', 'image', 'url', 'url']);
$dcIm = $dcImApply('/images/a.png', 'images/d.png', 'im-1');
check('derived image: a picture of another shape is accepted, and a leading slash stays', [($dcIm['ok'] ?? false) ?: $dcIm, $dcImParams()['photo']], [true, '/images/d.png']);
$dcIm = $dcImApply('/images/d.png', '/images/e.svg#joomlaImage://local-images/e.svg?width=1&height=1', 'im-2');
check('derived image: a fragment sent with the value is not trusted', [$dcIm['ok'] ?? $dcIm, $dcImParams()['photo']], [true, '/images/e.svg']);
$dcIm = $dcImApply('/images/c.png#joomlaImage://local-images/c.png?width=4&height=2', 'images/d.png', 'im-3');
check('derived image: a Joomla media value keeps its form, with the new file\'s size',
    [$dcIm['ok'] ?? $dcIm, $dcImParams()['banner']], [true, '/images/d.png#joomlaImage://local-images/d.png?width=8&height=2']);
$dcIm = $dcImApply('/images/d.png#joomlaImage://local-images/d.png?width=8&height=2', 'images/e.svg', 'im-4');
check('derived image: without a known size the fragment is dropped', [$dcIm['ok'] ?? $dcIm, $dcImParams()['banner']], [true, '/images/e.svg']);
foreach (['images/missing.png', 'images/../configuration.php', 'https://elsewhere.example/a.png', 'tmp/a.png'] as $dcBadImage) {
    $dcIm = $dcImApply('/images/e.svg', $dcBadImage, 'im-bad-' . md5($dcBadImage));
    check('derived image: refused ' . $dcBadImage, [$dcIm['ok'], $dcIm['errors'][0]['code'] ?? null], [false, 'SLOT_IMAGE_INVALID']);
}
$dcIm = $dcImApply('/about-us', 'index.php?option=com_content&view=article&id=12', 'url-1');
check('derived url: an index.php link is accepted', [$dcIm['ok'] ?? $dcIm, $dcImParams()['cta']], [true, 'index.php?option=com_content&view=article&id=12']);
$dcIm = $dcImApply('https://northwind.example/tour?x=1', 'https://northwind.example/tour?x=2&y=3', 'url-2');
check('derived url: a full address with a query is accepted', [$dcIm['ok'] ?? $dcIm, $dcImParams()['more']], [true, 'https://northwind.example/tour?x=2&y=3']);
$dcIm = $dcImApply('index.php?option=com_content&view=article&id=12', '/about us', 'url-3');
check('derived url: a path with a space is refused', [$dcIm['ok'], $dcIm['errors'][0]['code'] ?? null], [false, 'SLOT_LINK_UNSUPPORTED']);
foreach (['//elsewhere.example/', 'javascript:alert(1)', '/a\\b'] as $dcBadUrl) {
    $dcIm = $dcImApply('index.php?option=com_content&view=article&id=12', $dcBadUrl, 'url-bad-' . md5($dcBadUrl));
    check('derived url: refused ' . $dcBadUrl, [$dcIm['ok'], $dcIm['errors'][0]['code'] ?? null], [false, 'SLOT_LINK_UNSUPPORTED']);
}

// The production state: EngineFactory hands a derived-bound site its derived contract; bind still refuses.
$dcDerivedBind = (new Engine($WTOKEN, [], null, null, null, null, $dcWriter, null, $dcLog, null, null, null, $dcContractOf($dcWriter, $dcStore)))
    ->handle(['token' => $WTOKEN, 'action' => 'content.contract', 'params' => ['operation' => 'bind']]);
check('derived: bind on a derived site holding its derived contract is refused', [$dcDerivedBind['ok'], $dcDerivedBind['error'] ?? null], [false, 'conflict']);
check('derived: and that binding stands too', $dcStore->binding, $dcBound);

// After a derived apply: the caches that could still serve the old words are dropped (after the
// commit), and the pages that own the written slots are fetched again to see the new words.
[$dcRvStore, $dcRvLog, $dcRvWriter] = $dcFresh();
$dcRvFetched = []; $dcRvPurged = 0; $dcRvPages = []; $dcRvThrow = false; $dcRvInTransaction = null;
$dcRvFetch = function (array $paths) use (&$dcRvFetched, &$dcRvPages, &$dcRvThrow): array {
    $dcRvFetched[] = $paths;
    if ($dcRvThrow) throw new RuntimeException('loopback refused');
    return array_intersect_key($dcRvPages, array_flip($paths));
};
$dcRvPurge = function () use (&$dcRvPurged, $dcRvStore, $dcRvWriter, &$dcRvInTransaction) { $dcRvPurged++; $dcRvInTransaction = $dcRvWriter->open; };
$dcRvWriter->store['menuItem'][30] = ['id' => '30', 'title' => 'Tours', 'params' => '{}', 'published' => '1', 'access' => '1', 'language' => '*', 'client_id' => '0'];
$dcRvSource = fn() => ['rows' => $dcRowsOf($dcRvWriter->store), 'pages' => $dcPages, 'unresolved' => []];
(new Engine($WTOKEN, [], null, null, null, null, $dcRvWriter, null, $dcRvLog))->derivedSource($dcRvStore, $dcRoot, $dcRvSource)->handle($dcDerive('derive-render'));
$dcRvEngine = (new Engine($WTOKEN, [], null, null, null, null, $dcRvWriter, null, $dcRvLog, null, null, null, $dcContractOf($dcRvWriter, $dcRvStore)))
    ->derivedSource($dcRvStore, $dcRoot, $dcRvSource, $dcRvFetch, $dcRvPurge, function (int $id) use (&$dcRvModulePages): ?string { return $dcRvModulePages[$id] ?? null; });
// Where a module shows, as #__modules_menu says (the Joomla source answers it): module 7 on the home page.
$dcRvModulePages = [7 => ''];
$dcRvApply = function (array $changes, string $id) use ($dcRvEngine, $WTOKEN): array {
    $state = $dcRvEngine->handle(['token' => $WTOKEN, 'action' => 'content.contract', 'params' => ['operation' => 'inspect']]);
    $keys = [];
    foreach ($state['slots'] as $slot) if (isset($changes[$slot['current']])) $keys[$slot['key']] = $changes[$slot['current']];
    return $dcRvEngine->handle(['token' => $WTOKEN, 'action' => 'content.contract', 'params' => ['operation' => 'apply', 'apply_id' => 'contract-' . $id,
        'request_id' => $id, 'expected_revision' => $state['revision'], 'changes' => $keys]]);
};
$dcRvWriterPurges = $dcRvWriter->purges;
$dcRvPages = ['index.php?option=com_content&view=article&id=12' => '<h1>Hello Northwind</h1>'];
$dcRv = $dcRvApply(['Hello Northwind' => 'Hello Oslo'], 'rv-1');
check('render: a derived apply the page does not show still answers ok', $dcRv['ok'] ?? $dcRv, true);
$dcRvTitle = current(array_filter($dcRvEngine->handle(['token' => $WTOKEN, 'action' => 'content.contract', 'params' => ['operation' => 'inspect']])['slots'], fn($s) => $s['current'] === 'Hello Oslo'));
check('render: and warns which slot on which page', $dcRv['warnings'] ?? null,
    [['code' => 'WRITTEN_NOT_VISIBLE', 'message' => 'Written, but index.php?option=com_content&view=article&id=12 does not show it', 'severity' => 'warning',
        'slotKey' => $dcRvTitle['key'], 'url' => 'index.php?option=com_content&view=article&id=12']]);
check('render: an article is checked on its own page', end($dcRvFetched), ['index.php?option=com_content&view=article&id=12']);
check('render: the caches were purged once, after the commit', [$dcRvPurged, $dcRvInTransaction, $dcRvWriter->purges > $dcRvWriterPurges], [1, false, true]);
$dcRvPages = ['' => '<footer><div>Ships in 48h</div></footer>'];
$dcRv = $dcRvApply(['Ships in 24h' => 'Ships in 48h'], 'rv-2');
check('render: words the home page shows raise no warning', [$dcRv['ok'] ?? $dcRv, $dcRv['warnings'] ?? null, end($dcRvFetched)], [true, null, ['']]);
$dcRvThrow = true;
$dcRv = $dcRvApply(['Ships in 48h' => 'Ships in 72h'], 'rv-3');
check('render: a page that cannot be fetched raises no warning', [$dcRv['ok'] ?? $dcRv, $dcRv['warnings'] ?? null], [true, null]);
$dcRvThrow = false; $dcRvPages = [];
$dcRv = $dcRvEngine->handle(['token' => $WTOKEN, 'action' => 'apply.revert', 'params' => ['apply_id' => 'contract-rv-3']]);
check('render: a warned apply is taken back by its apply_id', [$dcRv['ok'] ?? $dcRv, json_decode($dcRvWriter->store['module'][7]['params'], true)['tagline']], [true, 'Ships in 48h']);
$dcRvCount = count($dcRvFetched);
$dcRvPages = ['index.php?option=com_content&view=category&id=5' => '<h1>News</h1>'];
$dcRv = $dcRvApply(['Latest from Northwind' => 'Latest news'], 'rv-4');
check('render: a category is checked on its own page', [end($dcRvFetched), $dcRv['warnings'][0]['url'] ?? null],
    [['index.php?option=com_content&view=category&id=5'], 'index.php?option=com_content&view=category&id=5']);
$dcRvModulePages = [7 => 'index.php?Itemid=101'];
$dcRv = $dcRvApply(['Call us today' => 'Call us now'], 'rv-5');
check('render: a module is checked on a page it is assigned to', end($dcRvFetched), ['index.php?Itemid=101']);
$dcRvModulePages = [];
$dcRvCount = count($dcRvFetched);
$dcRv = $dcRvApply(['Call us now' => 'Call us soon'], 'rv-6');
check('render: a module shown on no known page is not checked, and not warned about', [count($dcRvFetched) - $dcRvCount, $dcRv['ok'] ?? $dcRv, $dcRv['warnings'] ?? null], [0, true, null]);
$dcRvModulePages = [7 => 'index.php?Itemid=101'];
$dcRvCount = count($dcRvFetched);
$dcRv = $dcRvApply(['Hello Oslo' => 'Hello Bergen', 'Welcome aboard' => 'Welcome home', 'Call us soon' => 'Call us later', 'Latest news' => 'Latest words', 'Made in Oslo' => 'Made in Bergen'], 'rv-7');
check('render: one fetch per apply, at most three pages, each owner page once', [count($dcRvFetched) - $dcRvCount, count(end($dcRvFetched)), count(array_unique(end($dcRvFetched)))], [1, 3, 3]);

$dcRvPages = ['index.php?Itemid=30' => '<nav>Home</nav>'];
$dcRv = $dcRvApply(['Tours' => 'Guided tours'], 'rv-8');
check('render: a menu item is checked on its own page', [end($dcRvFetched), $dcRv['warnings'][0]['url'] ?? null], [['index.php?Itemid=30'], 'index.php?Itemid=30']);
// An uncalibrated derive kept every nested leaf, shown or not (a meta description, a setting): only
// the words a row holds as its own text are checked, or the check is mostly noise.
$dcRvStore->binding['calibrated'] = false;
$dcRvCount = count($dcRvFetched);
$dcRv = $dcRvApply(['Ships in 48h' => 'Ships in 96h'], 'rv-9');
check('render: uncalibrated, a nested leaf is not checked', [count($dcRvFetched) - $dcRvCount, $dcRv['ok'] ?? $dcRv, $dcRv['warnings'] ?? null], [0, true, null]);
$dcRv = $dcRvApply(['Guided tours' => 'Tours by boat'], 'rv-10');
check('render: uncalibrated, a row\'s own text still is', [end($dcRvFetched), $dcRv['warnings'][0]['code'] ?? null], [['index.php?Itemid=30'], 'WRITTEN_NOT_VISIBLE']);
$dcRvStore->binding['calibrated'] = true;

// Params that name a quickstart contract, with no binding yet: a provision whose bind is still to come
// (or failed). Derive refuses, or that bind would find the site taken by a derive and be refused forever.
[$dcQcStore, $dcQcLog, $dcQcWriter] = $dcFresh();
$dcQcEngine = (new Engine($WTOKEN, [], null, null, null, null, $dcQcWriter, null, $dcQcLog, null, null, null, $dcApple($dcQcWriter, $dcQcStore)))
    ->derivedSource($dcQcStore, $dcRoot, fn() => ['rows' => $dcRowsOf($dcQcWriter->store), 'pages' => [], 'unresolved' => []], null, null, null, true);
$dcQc = $dcQcEngine->handle($dcDerive('derive-qc'));
check('derived: a site configured for a quickstart and not bound yet refuses derive', [$dcQc['ok'], $dcQc['error'] ?? null, $dcQc['message'] ?? null],
    [false, 'conflict', 'This site is being built from a quickstart; derive refuses']);
check('derived: and stays unbound for its quickstart bind', $dcQcStore->binding, null);
