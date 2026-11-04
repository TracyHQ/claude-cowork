<?php
// Loaded by run.php after contracts.php (reuses TestContractStore, ContractTestWriter, FakeApplyLog, $WTOKEN).
// Hiding for real (TracyHQ/tracy#362): three abilities, each acting only when a write asks for it.
//   content.contract apply `empty_slots`      empties a slot the receiver's review lists for this contract
//   content.contract `blocks.unpublish`       unpublishes modules of the contract, baseline moved with them
//   template.languageOverrides `setSource`    en-GB overrides for the constants a write names
// Every business word, constant and slot here is made up.

$hoRoot = sys_get_temp_dir() . '/cowork-hide-ops-' . bin2hex(random_bytes(6));
$hoDir = $hoRoot . '/lib/contracts/test-hide/j6/1.0.0';
mkdir($hoDir, 0777, true);

$hoAcm = fn(string $title, array $config, string $ordering) => ['title' => $title, 'module' => 'mod_ja_acm', 'position' => 'header', 'published' => '1',
    'publish_up' => null, 'publish_down' => null, 'ordering' => $ordering, 'access' => '1', 'showtitle' => '0', 'language' => '*', 'client_id' => '0',
    'content' => '', 'params' => json_encode(['jatools-config' => json_encode($config), 'moduleclass_sfx' => ''])];
$hoCards = $hoAcm('Made-up cards', [':type' => 'tpl:cards', 'cards' => [
    'cards[title]' => ['First made-up card', 'Second made-up card'],
    'cards[link]' => ['index.php?Itemid=701', 'index.php?Itemid=702'],
    'cards[picture]' => ['images/made-up/a.png', 'images/made-up/b.png'],
    'jatools-layout-cards' => 'style-1']], '1');
$hoBand = $hoAcm('Made-up band', [':type' => 'tpl:band', 'band' => ['band[title]' => ['A made-up band'], 'band[link]' => [''], 'jatools-layout-band' => 'style-1']], '2');
$hoHtml = ['title' => 'Made-up prose', 'module' => 'mod_custom', 'position' => 'masthead', 'published' => '1', 'publish_up' => null, 'publish_down' => null,
    'ordering' => '3', 'access' => '1', 'showtitle' => '0', 'language' => '*', 'client_id' => '0',
    'content' => '<div><p>A whole made-up sentence</p><span>A loose made-up word</span> and more <a href="/made-up">made-up</a></div>', 'params' => '{"style":"0"}'];
$hoJson = fn(string $key, string $entity, array $path, string $type, string $sample) => ['key' => $key, 'entity' => $entity, 'column' => 'params', 'type' => $type,
    'sample' => $sample, 'maxCharacters' => 2048, 'requiresEvidence' => false, 'jsonPath' => $path, 'nestedJson' => 'jatools-config'];
$hoSlots = [
    $hoJson('cards.0', 'cards', ['cards', 'cards[title]', 0], 'text', 'First made-up card'),
    $hoJson('cards.1', 'cards', ['cards', 'cards[link]', 0], 'url', 'index.php?Itemid=701'),
    $hoJson('cards.2', 'cards', ['cards', 'cards[picture]', 0], 'image', 'images/made-up/a.png'),
    $hoJson('cards.3', 'cards', ['cards', 'cards[title]', 1], 'text', 'Second made-up card'),
    $hoJson('cards.4', 'cards', ['cards', 'cards[link]', 1], 'url', 'index.php?Itemid=702'),
    $hoJson('cards.5', 'cards', ['cards', 'cards[picture]', 1], 'image', 'images/made-up/b.png'),
    $hoJson('band.0', 'band', ['band', 'band[title]', 0], 'text', 'A made-up band'),
    $hoJson('band.1', 'band', ['band', 'band[link]', 0], 'url', ''),
    ['key' => 'prose.unit', 'entity' => 'prose', 'column' => 'content', 'type' => 'text', 'sample' => 'A whole made-up sentence', 'maxCharacters' => 80, 'requiresEvidence' => false, 'xpath' => '/html/body/div/div/p'],
    ['key' => 'prose.node', 'entity' => 'prose', 'column' => 'content', 'type' => 'text', 'sample' => 'A loose made-up word', 'maxCharacters' => 80, 'requiresEvidence' => false, 'xpath' => '/html/body/div/div/span/text()'],
    ['key' => 'prose.href', 'entity' => 'prose', 'column' => 'content', 'type' => 'url', 'sample' => '/made-up', 'maxCharacters' => 80, 'requiresEvidence' => false, 'xpath' => '/html/body/div/div/a/@href'],
];
$hoStore = new TestContractStore();
$hoEntities = [];
foreach (['cards' => 10, 'band' => 11, 'prose' => 12] as $key => $id) $hoEntities[] = ['key' => $key, 'kind' => 'module', 'sourceId' => $id, 'identity' => ['title' => ['cards' => 'Made-up cards', 'band' => 'Made-up band', 'prose' => 'Made-up prose'][$key]]];
$hoFiles = [
    'manifest' => ['id' => 'test-hide/j6/1.0.0'],
    'content-map' => ['entities' => $hoEntities, 'slots' => $hoSlots, 'pages' => []],
    'presentation-lock' => ['entities' => ['cards' => $hoCards, 'band' => $hoBand, 'prose' => $hoHtml],
        'assignments' => [['moduleid' => 10, 'menuid' => 0], ['moduleid' => 11, 'menuid' => 0], ['moduleid' => 12, 'menuid' => 0]],
        'fileRoots' => [], 'files' => [], 'inventoryCounts' => ['module' => 3], 'access' => $hoStore->acl],
];
foreach ($hoFiles as $name => $body) file_put_contents($hoDir . '/' . $name . '.json', json_encode($body));
$hoMapHash = hash_file('sha256', $hoDir . '/content-map.json');
$hoReview = fn(?string $hash = null, string $id = 'test-hide/j6/1.0.0') => json_encode(['contracts' => [$id => ['contentMapSha256' => $hash ?? $hoMapHash,
    'measured' => ['cards.2', 'prose.node'], 'unmeasured' => ['cards.0', 'cards.3', 'band.0', 'prose.unit'], 'withRow' => ['cards.1' => ['cards.0', 'cards.2'], 'cards.5' => ['cards.3', 'cards.4']]]]]);
file_put_contents($hoRoot . '/lib/hideable-slots.json', $hoReview());

$hoLog = new FakeApplyLog();
$hoWriter = new ContractTestWriter($hoLog, $hoStore);
foreach (['cards' => [110, $hoCards], 'band' => [111, $hoBand], 'prose' => [112, $hoHtml]] as [$id, $row]) {
    $hoWriter->store['module'][$id] = ['id' => (string) $id] + $row;
    $hoWriter->store['moduleAssignment'][$id] = ['menuids' => '[0]'];
}
$hoContract = new QuickstartContract($hoWriter, $hoStore, $hoRoot, $hoDir);
$hoContract->bind($hoContract->inspect()['snapshot']);
$hoEngine = new Engine($WTOKEN, [], null, null, null, null, $hoWriter, null, $hoLog, null, null, null, $hoContract);
$hoDoor = fn(array $params) => $hoEngine->handle(['token' => $WTOKEN, 'action' => 'content.contract', 'params' => $params]);
$hoApply = fn(string $id, array $params) => $hoDoor(['operation' => 'apply', 'apply_id' => 'contract-' . $id, 'request_id' => $id, 'expected_revision' => $hoContract->inspect()['revision']] + $params);
$hoCodes = fn(array $answer) => [$answer['ok'], array_column($answer['errors'] ?? [], 'code')];
$hoRead = fn(string $field, int $i) => json_decode(json_decode($hoWriter->store['module'][110]['params'], true)['jatools-config'], true)['cards'][$field][$i];
$hoTiers = function () use ($hoDoor): array {
    $out = [];
    foreach ($hoDoor(['operation' => 'inspect'])['slots'] as $slot) if (isset($slot['canHide'])) $out[$slot['key']] = $slot['canHide'];
    ksort($out);
    return $out;
};

/* ------------------------------------------------ what inspect says may be emptied */
check('info says this engine has these abilities', $hoEngine->handle(['token' => $WTOKEN, 'action' => 'info'])['info']['hide_ops'] ?? null, ['version' => 1]);
check('inspect names each slot the review lists, and how it was judged', $hoTiers(), [
    'band.0' => 'unmeasured', 'cards.0' => 'unmeasured', 'cards.1' => 'withRow', 'cards.2' => 'measured', 'cards.3' => 'unmeasured', 'cards.5' => 'withRow', 'prose.unit' => 'unmeasured']);

/* ------------------------------------------------ refusals: nothing is written */
$hoRevision = $hoContract->inspect()['revision'];
check('an occupied slot emptied through changes is still refused as before, listed or not',
    $hoCodes($hoApply('plain', ['changes' => ['cards.0' => '', 'cards.2' => '']])), [false, ['SLOT_EMPTY_STATE', 'SLOT_EMPTY_STATE']]);
check('a slot the review does not list cannot be emptied', $hoCodes($hoApply('unlisted', ['empty_slots' => ['cards.4']])), [false, ['SLOT_NOT_HIDEABLE']]);
check('an unknown slot is refused by name', $hoCodes($hoApply('unknown', ['empty_slots' => ['nope.1']])), [false, ['SLOT_UNKNOWN']]);
check('a slot both changed and emptied is refused', $hoCodes($hoApply('both', ['changes' => ['cards.0' => 'x'], 'empty_slots' => ['cards.0']])), [false, ['CHANGES_INVALID']]);
check('a slot in both empty lists is refused', $hoCodes($hoApply('lists', ['empty_links' => ['cards.1'], 'empty_slots' => ['cards.1']])), [false, ['CHANGES_INVALID']]);
check('empty_slots must be a list of slot keys', $hoCodes($hoApply('shape', ['empty_slots' => 'cards.0'])), [false, ['CHANGES_INVALID']]);
check('a slot that goes only with its row is refused alone', $hoCodes($hoApply('alone', ['empty_slots' => ['cards.1']])), [false, ['SLOT_NOT_HIDEABLE']]);
check('a refused slot refuses the whole apply, with every problem named',
    $hoCodes($hoApply('mixed', ['empty_slots' => ['cards.0', 'cards.4', 'prose.node']])), [false, ['SLOT_NOT_HIDEABLE', 'SLOT_NOT_HIDEABLE']]);
check('refusals wrote nothing', [$hoContract->inspect()['revision'], $hoRead('cards[title]', 0)], [$hoRevision, 'First made-up card']);

/* ------------------------------------------------ a text node's address is its index among its siblings */
file_put_contents($hoRoot . '/lib/hideable-slots.json', json_encode(['contracts' => ['test-hide/j6/1.0.0' => ['contentMapSha256' => $hoMapHash,
    'measured' => ['prose.node', 'prose.href'], 'unmeasured' => [], 'withRow' => []]]]));
$hoForced = new QuickstartContract($hoWriter, $hoStore, $hoRoot, $hoDir);
$hoForcedEngine = new Engine($WTOKEN, [], null, null, null, null, $hoWriter, null, $hoLog, null, null, null, $hoForced);
check('a text node or an attribute is refused even when a review lists it: emptied, its address would move or dangle',
    $hoCodes($hoForcedEngine->handle(['token' => $WTOKEN, 'action' => 'content.contract', 'params' => ['operation' => 'apply', 'apply_id' => 'contract-forced', 'request_id' => 'forced',
        'expected_revision' => $hoForced->inspect()['revision'], 'empty_slots' => ['prose.node', 'prose.href']]])), [false, ['SLOT_NOT_HIDEABLE', 'SLOT_NOT_HIDEABLE']]);
file_put_contents($hoRoot . '/lib/hideable-slots.json', $hoReview());

/* ------------------------------------------------ the apply, its check and its revert */
$hoBefore = $hoContract->inspect()['revision'];
$hoOk = $hoApply('off', ['empty_slots' => ['cards.0', 'cards.1', 'cards.2', 'prose.unit'], 'changes' => ['cards.3' => 'A changed made-up card']]);
check('emptied slots and a changed word go in one apply', [$hoOk['ok'], $hoRead('cards[title]', 0), $hoRead('cards[link]', 0), $hoRead('cards[picture]', 0), $hoRead('cards[title]', 1)],
    [true, '', '', '', 'A changed made-up card']);
check('the row after the emptied one keeps its index and its words', [$hoRead('cards[link]', 1), $hoRead('cards[picture]', 1)], ['index.php?Itemid=702', 'images/made-up/b.png']);
checkTrue('the emptied whole sentence is gone from the HTML', strpos($hoWriter->store['module'][112]['content'], 'A whole made-up sentence') === false);
$hoAfter = $hoDoor(['operation' => 'inspect']);
$hoNow = array_column($hoAfter['slots'], 'current', 'key');
check('the contract still holds: no drift, emptied slots read empty', [$hoAfter['ok'], $hoContract->driftWarnings(), $hoNow['cards.0'], $hoNow['cards.2'], $hoNow['prose.unit']], [true, [], '', '', '']);
check('a lost reply replays the same receipt', $hoDoor(['operation' => 'apply', 'apply_id' => 'contract-off', 'request_id' => 'off',
    'empty_slots' => ['cards.0', 'cards.1', 'cards.2', 'prose.unit'], 'changes' => ['cards.3' => 'A changed made-up card']]), $hoOk);
$hoReused = $hoDoor(['operation' => 'apply', 'apply_id' => 'contract-off', 'request_id' => 'off', 'empty_slots' => ['cards.0'], 'changes' => ['cards.3' => 'A changed made-up card']]);
check('the same request id with other slots to empty is refused', [$hoReused['ok'], $hoReused['message']], [false, 'request_id reused with different content']);
check('a slot the review does not list, already empty, is no refusal and no write', $hoApply('empty-already', ['empty_slots' => ['band.1']])['unchanged'] ?? null, true);
check('emptying a slot that is already empty changes nothing', $hoApply('again', ['empty_slots' => ['cards.0']])['unchanged'] ?? null, true);
check('a slot that goes with its row is taken when its row is already gone', $hoCodes($hoApply('later', ['empty_slots' => ['cards.5', 'cards.3']])), [true, []]);
check('and that apply can be taken back', $hoEngine->handle(['token' => $WTOKEN, 'action' => 'apply.revert', 'params' => ['apply_id' => 'contract-later']])['ok'], true);
$hoRevert = $hoEngine->handle(['token' => $WTOKEN, 'action' => 'apply.revert', 'params' => ['apply_id' => 'contract-off']]);
check('apply.revert puts every emptied slot and the word back', [$hoRevert['ok'], $hoRead('cards[title]', 0), $hoRead('cards[link]', 0), $hoRead('cards[picture]', 0), $hoRead('cards[title]', 1)],
    [true, 'First made-up card', 'index.php?Itemid=701', 'images/made-up/a.png', 'Second made-up card']);
check('and the site is where it began', [$hoContract->inspect()['revision'], $hoContract->driftWarnings()], [$hoBefore, []]);

/* ------------------------------------------------ an emptied slot takes words again */
$hoApply('off-2', ['empty_slots' => ['cards.0']]);
$hoBack = $hoApply('on-again', ['changes' => ['cards.0' => 'Words again']]);
check('words written into an emptied slot show again', [$hoBack['ok'], $hoRead('cards[title]', 0)], [true, 'Words again']);
$hoEngine->handle(['token' => $WTOKEN, 'action' => 'apply.revert', 'params' => ['apply_id' => 'contract-on-again']]);
$hoEngine->handle(['token' => $WTOKEN, 'action' => 'apply.revert', 'params' => ['apply_id' => 'contract-off-2']]);

/* ------------------------------------------------ a receiver with no review for this contract empties nothing */
$hoFresh = fn() => new QuickstartContract($hoWriter, $hoStore, $hoRoot, $hoDir);
$hoListed = fn(QuickstartContract $contract) => array_values(array_filter(array_column($contract->inspect()['slots'], 'canHide')));
file_put_contents($hoRoot . '/lib/hideable-slots.json', $hoReview(str_repeat('0', 64)));
check('a review of other content map bytes is no review of these', $hoListed($hoFresh()), []);
file_put_contents($hoRoot . '/lib/hideable-slots.json', $hoReview(null, 'test-other/j6/1.0.0'));
check('a review of another contract is no review of this one', $hoListed($hoFresh()), []);
file_put_contents($hoRoot . '/lib/hideable-slots.json', '{not json');
check('an unreadable review closes only itself', [$hoListed($hoFresh()), $hoFresh()->inspect()['contract']], [[], 'test-hide/j6/1.0.0']);
unlink($hoRoot . '/lib/hideable-slots.json');
$hoBare = $hoFresh();
$hoBareEngine = new Engine($WTOKEN, [], null, null, null, null, $hoWriter, null, $hoLog, null, null, null, $hoBare);
check('without a review no slot is emptiable, and inspect carries no new field',
    [$hoCodes($hoBareEngine->handle(['token' => $WTOKEN, 'action' => 'content.contract', 'params' => ['operation' => 'apply', 'apply_id' => 'contract-bare', 'request_id' => 'bare',
        'expected_revision' => $hoBare->inspect()['revision'], 'empty_slots' => ['cards.0']]])), $hoListed($hoBare)], [[false, ['SLOT_NOT_HIDEABLE']], []]);
file_put_contents($hoRoot . '/lib/hideable-slots.json', $hoReview());

/* ------------------------------------------------ blocks.unpublish */
$hoBlocks = fn(string $id, array $params) => $hoDoor(['operation' => 'blocks.unpublish', 'apply_id' => 'contract-' . $id, 'request_id' => $id, 'expected_revision' => $hoContract->inspect()['revision']] + $params);
$hoPublished = fn(int $id) => $hoWriter->store['module'][$id]['published'];
$hoStart = $hoContract->inspect()['revision'];
check('a block name is needed', $hoCodes($hoBlocks('none', ['blocks' => []])), [false, ['CHANGES_INVALID']]);
check('a name that is no module of this contract is refused by name, and nothing is written',
    [$hoCodes($hoBlocks('stranger', ['blocks' => ['band', 'module-999', 'cards.0']])), $hoPublished(111)], [[false, ['BLOCK_UNKNOWN', 'BLOCK_UNKNOWN']], '1']);
check('the revision it is based on is required', $hoCodes($hoDoor(['operation' => 'blocks.unpublish', 'apply_id' => 'contract-norev', 'request_id' => 'norev', 'blocks' => ['band']])), [false, ['REVISION_REQUIRED']]);
check('a stale revision is refused', $hoCodes($hoDoor(['operation' => 'blocks.unpublish', 'apply_id' => 'contract-stale', 'request_id' => 'stale', 'blocks' => ['band'], 'expected_revision' => str_repeat('a', 64)])), [false, ['REVISION_STALE']]);
check('an apply_id must carry the contract prefix', $hoDoor(['operation' => 'blocks.unpublish', 'apply_id' => 'other', 'request_id' => 'x', 'blocks' => ['band']])['ok'], false);
$hoWriter->store['module'][112]['published'] = '-2';
check('a block neither shown nor hidden (trashed) is refused', $hoCodes($hoBlocks('trashed', ['blocks' => ['prose']])), [false, ['BLOCK_NOT_PUBLISHED']]);
$hoWriter->store['module'][112]['published'] = '1';
$hoOff = $hoBlocks('hide', ['blocks' => ['band']]);
check('a listed block is unpublished and nothing else moves', [$hoOff['ok'], $hoOff['unpublished'], $hoOff['already'], $hoPublished(111), $hoPublished(110), $hoPublished(112)], [true, ['band'], [], '0', '1', '1']);
check('the baseline moved with it: no drift warning, now or on the next read', [$hoOff['warnings'] ?? [], $hoDoor(['operation' => 'inspect'])['warnings'] ?? [], $hoContract->driftWarnings()], [[], [], []]);
check('the answer names the revision it left, so the next write needs no inspect', $hoOff['afterRevision'], $hoContract->inspect()['revision']);
check('a lost reply replays the same receipt', $hoDoor(['operation' => 'blocks.unpublish', 'apply_id' => 'contract-hide', 'request_id' => 'hide', 'blocks' => ['band']]), $hoOff);
check('the same request id with other blocks is refused', $hoDoor(['operation' => 'blocks.unpublish', 'apply_id' => 'contract-hide', 'request_id' => 'hide', 'blocks' => ['cards']])['message'], 'request_id reused with different content');
$hoAgain = $hoBlocks('hide-again', ['blocks' => ['band']]);
check('a block already unpublished is named, and nothing is written', [$hoAgain['ok'], $hoAgain['unpublished'], $hoAgain['already'], $hoAgain['unchanged'] ?? null], [true, [], ['band'], true]);
check('a content apply after it keeps the block hidden, with no drift', [$hoApply('after-block', ['changes' => ['cards.0' => 'After a block'], 'empty_slots' => ['cards.3']])['ok'], $hoPublished(111), $hoContract->driftWarnings()], [true, '0', []]);
$hoLate = $hoEngine->handle(['token' => $WTOKEN, 'action' => 'apply.revert', 'params' => ['apply_id' => 'contract-hide']]);
check('it does not come back over later content', [$hoLate['ok'], array_column($hoLate['errors'] ?? [], 'code'), $hoPublished(111)], [false, ['CONFLICT'], '0']);
check('the later content goes back first', $hoEngine->handle(['token' => $WTOKEN, 'action' => 'apply.revert', 'params' => ['apply_id' => 'contract-after-block']]), ['ok' => true, 'reverted' => 3]);
$hoCame = $hoEngine->handle(['token' => $WTOKEN, 'action' => 'apply.revert', 'params' => ['apply_id' => 'contract-hide']]);
check('apply.revert brings the block back', [$hoCame['ok'], $hoCame['message'] ?? null, $hoPublished(111)], [true, null, '1']);
check('and the site is where it began', [$hoContract->inspect()['revision'], $hoContract->driftWarnings()], [$hoStart, []]);
$hoWriter->beforeCas = function ($writer, $kind, $id) { $writer->store['module'][$id]['published'] = '0'; };
$hoRaced = $hoBlocks('raced', ['blocks' => ['band', 'prose']]);
$hoWriter->beforeCas = null;
check('a row that moved under the write refuses the whole call', [$hoRaced['ok'], array_column($hoRaced['errors'] ?? [], 'code')], [false, ['CONFLICT']]);
foreach ([111, 112] as $hoId) $hoWriter->store['module'][$hoId]['published'] = '1';
check('the ordinary door still unpublishes a module the way it always has', $hoEngine->handle(['token' => $WTOKEN, 'action' => 'content.update', 'params' => ['kind' => 'module', 'id' => 111, 'apply_id' => 'plain-door', 'fields' => ['published' => '0']]])['ok'], true);
$hoEngine->handle(['token' => $WTOKEN, 'action' => 'apply.revert', 'params' => ['apply_id' => 'plain-door']]);

/* ------------------------------------------------ template.languageOverrides setSource */
$hoPut = static function (string $relative, string $bytes) use ($hoRoot): void {
    $path = $hoRoot . '/' . $relative;
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0777, true);
    file_put_contents($path, $bytes);
};
$hoPut('templates/madeup/templateDetails.xml', "<?xml version=\"1.0\"?>\n<extension type=\"template\" client=\"site\"><name>madeup</name></extension>\n");
$hoPut('templates/madeup/language/en-GB/en-GB.tpl_madeup.ini', "TPL_MADEUP_NOTICE=\"A made-up notice\"\nTPL_MADEUP_KEPT=\"A made-up sentence kept\"\nTPL_MADEUP_BY=\"By %s\"\n");
$hoPut('language/en-GB/mod_madeup.ini', "MOD_MADEUP_LINE=\"Another made-up line\"\n");
$hoPut('administrator/language/en-GB/plg_system_madeup.ini', "PLG_MADEUP_WORD=\"A made-up plugin word\"\n");
$hoLoLog = new FakeApplyLog();
$hoLo = (new Engine($WTOKEN, [], null, null, null, null, null, null, $hoLoLog))->languageOverrides(new LanguageOverrides($hoRoot));
$hoCall = fn(array $params) => $hoLo->handle(['token' => $WTOKEN, 'action' => 'template.languageOverrides', 'params' => $params]);
$hoIni = $hoRoot . '/language/overrides/en-GB.override.ini';
$hoSet = fn(string $id, array $strings) => $hoCall(['operation' => 'setSource', 'apply_id' => $id, 'template' => 'madeup', 'strings' => $strings]);

check('en-GB is still refused by read', $hoCall(['template' => 'madeup', 'locale' => 'en-GB'])['error'] ?? null, 'bad_params');
check('en-GB is still refused by set', $hoCall(['operation' => 'set', 'apply_id' => 'plain-set', 'template' => 'madeup', 'locale' => 'en-GB', 'strings' => ['TPL_MADEUP_NOTICE' => '']])['error'] ?? null, 'bad_params');
check('setSource names no other language', $hoCall(['operation' => 'setSource', 'apply_id' => 'other-lang', 'template' => 'madeup', 'locale' => 'vi-VN', 'strings' => ['TPL_MADEUP_NOTICE' => '']])['error'] ?? null, 'bad_params');
check('setSource needs a template, an apply id and named strings', [
    $hoCall(['operation' => 'setSource', 'apply_id' => 'a1', 'strings' => ['TPL_MADEUP_NOTICE' => '']])['error'] ?? null,
    $hoCall(['operation' => 'setSource', 'template' => 'madeup', 'strings' => ['TPL_MADEUP_NOTICE' => '']])['error'] ?? null,
    $hoSet('a2', [])['error'] ?? null,
    $hoSet('a3', ['TPL_MADEUP_NOTICE'])['error'] ?? null], ['bad_params', 'bad_params', 'bad_params', 'bad_params']);
checkTrue('no refusal wrote the file', !is_file($hoIni));

$hoHidden = $hoSet('hide-words', ['tpl_madeup_notice' => '', 'MOD_MADEUP_LINE' => '', 'PLG_MADEUP_WORD' => '', 'TPL_NOT_ON_THIS_SITE' => '', 'TPL_MADEUP_BY' => 'Written by', 'TPL_MADEUP_KEPT' => "two\nlines"]);
check('a named constant of the site is emptied, every other name is refused by name', [$hoHidden['ok'], $hoHidden['written'], array_column($hoHidden['refused'], 'key')],
    [true, 3, ['TPL_NOT_ON_THIS_SITE', 'TPL_MADEUP_BY', 'TPL_MADEUP_KEPT']]);
$hoStrings = LanguageOverrides::parse((string) file_get_contents($hoIni));
check('the override holds exactly what was named, and an empty value reads as empty', $hoStrings, ['TPL_MADEUP_NOTICE' => '', 'MOD_MADEUP_LINE' => '', 'PLG_MADEUP_WORD' => '']);
check('the template file itself is not touched', (string) file_get_contents($hoRoot . '/templates/madeup/language/en-GB/en-GB.tpl_madeup.ini'), "TPL_MADEUP_NOTICE=\"A made-up notice\"\nTPL_MADEUP_KEPT=\"A made-up sentence kept\"\nTPL_MADEUP_BY=\"By %s\"\n");
check('setting the same again writes nothing', $hoSet('hide-same', ['TPL_MADEUP_NOTICE' => ''])['unchanged'] ?? null, true);
$hoWords = $hoSet('reword', ['TPL_MADEUP_BY' => 'Written by %s']);
check('a value in words carries the source placeholders', [$hoWords['written'], LanguageOverrides::parse((string) file_get_contents($hoIni))['TPL_MADEUP_BY']], [1, 'Written by %s']);
$hoLo->handle(['token' => $WTOKEN, 'action' => 'apply.revert', 'params' => ['apply_id' => 'reword']]);
check('apply.revert takes back that write alone', array_key_exists('TPL_MADEUP_BY', LanguageOverrides::parse((string) file_get_contents($hoIni))), false);
check('apply.revert takes the first write back: the file is gone', [$hoLo->handle(['token' => $WTOKEN, 'action' => 'apply.revert', 'params' => ['apply_id' => 'hide-words']])['ok'], is_file($hoIni)], [true, false]);

/* ------------------------------------------------ the shipped review */
$hoShipped = json_decode((string) file_get_contents(__DIR__ . '/../lib/hideable-slots.json'), true);
$hoProblems = [];
foreach ($hoShipped['contracts'] as $hoId => $hoEntry) {
    $hoProfile = __DIR__ . '/../lib/contracts/' . $hoId;
    if (hash_file('sha256', $hoProfile . '/content-map.json') !== $hoEntry['contentMapSha256']) $hoProblems[] = $hoId . ': another content map';
    $hoKnown = array_column(json_decode((string) file_get_contents($hoProfile . '/content-map.json'), true)['slots'], null, 'key');
    foreach (array_merge($hoEntry['measured'], $hoEntry['unmeasured'], array_keys($hoEntry['withRow'])) as $hoKey) {
        if (!isset($hoKnown[$hoKey])) { $hoProblems[] = $hoKey . ' is no slot'; continue; }
        if (preg_match('~/(text\(\)(\[\d+\])?|@[a-z]+)$~', (string) ($hoKnown[$hoKey]['xpath'] ?? ''))) $hoProblems[] = $hoKey . ' has an address emptying would move';
    }
    foreach ($hoEntry['withRow'] as $hoKey => $hoRow) if ($hoRow === [] || array_diff($hoRow, array_keys($hoKnown))) $hoProblems[] = $hoKey . ' names a row that is not there';
    unset($hoKnown);
}
check('the shipped review matches its content maps and lists no address emptying would move', $hoProblems, []);
checkTrue('the review covers JA Boxing j6 1.0.10', isset($hoShipped['contracts']['ja-boxing/j6/1.0.10']));
unset($hoShipped, $hoEntry, $hoProblems);

/* ------------------------------------------------ the shipped review on its own archive's shape */
// Every listed slot of JA Boxing j6 1.0.10 emptied in the rows the contract's own lock holds: each one is a shape a real
// write will meet. Every one must read back empty, and every slot left in the row must still read what it held.
$hoBoxDir = __DIR__ . '/../lib/contracts/ja-boxing/j6/1.0.10';
$hoBoxLock = json_decode((string) file_get_contents($hoBoxDir . '/presentation-lock.json'), true);
$hoBoxMap = json_decode((string) file_get_contents($hoBoxDir . '/content-map.json'), true);
$hoBoxEntry = json_decode((string) file_get_contents(__DIR__ . '/../lib/hideable-slots.json'), true)['contracts']['ja-boxing/j6/1.0.10'];
$hoBoxListed = array_fill_keys(array_merge($hoBoxEntry['measured'], $hoBoxEntry['unmeasured'], array_keys($hoBoxEntry['withRow'])), '');
$hoBoxStore = new TestContractStore();
$hoBox = new QuickstartContract(new ContractTestWriter(new FakeApplyLog(), $hoBoxStore), $hoBoxStore, $hoRoot, $hoBoxDir);
$hoBoxBy = [];
foreach ($hoBoxMap['slots'] as $hoSlot) $hoBoxBy[$hoSlot['entity']][] = $hoSlot;
$hoBoxWrong = [];
$hoBoxEmptied = 0;
foreach ($hoBoxBy as $hoEntity => $hoEntitySlots) {
    if (!array_intersect_key(array_flip(array_column($hoEntitySlots, 'key')), $hoBoxListed) || strpos($hoEntity, 'languageString-') === 0) continue;
    $hoRow = $hoBoxLock['entities'][$hoEntity];
    $hoBefore = [];
    foreach ($hoEntitySlots as $hoSlot) $hoBefore[$hoSlot['key']] = $hoBox->slotValue($hoRow, $hoSlot);
    $hoPatched = $hoBox->patch($hoRow, $hoEntitySlots, array_intersect_key($hoBoxListed, $hoBefore));
    foreach ($hoEntitySlots as $hoSlot) {
        $hoWant = isset($hoBoxListed[$hoSlot['key']]) ? '' : $hoBefore[$hoSlot['key']];
        try { $hoGot = $hoBox->slotValue($hoPatched, $hoSlot); } catch (Throwable $hoError) { $hoGot = 'unreadable: ' . $hoError->getMessage(); }
        if (trim($hoGot) !== trim($hoWant)) $hoBoxWrong[] = $hoSlot['key'] . ' reads ' . json_encode(substr($hoGot, 0, 40));
        elseif ($hoWant === '') $hoBoxEmptied++;
    }
}
check('every listed slot of the shipped review empties, and no neighbour moves', array_slice($hoBoxWrong, 0, 5), []);
checkTrue('and that covered the review', $hoBoxEmptied > 700);
unset($hoBoxLock, $hoBoxMap, $hoBoxBy, $hoBoxEntry, $hoBoxListed, $hoBox, $hoPatched, $hoRow);
gc_collect_cycles();
