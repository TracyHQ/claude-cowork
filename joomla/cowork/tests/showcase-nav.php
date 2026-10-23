<?php
// Loaded by run.php, after multilingual-contracts.php (gateContractCopy, GateContractStore, gateSite).
//
// 🔒 A TEMPLATE'S SHOWCASE LEAVES THE MENUS, AND COMES BACK EXACTLY AS IT WAS. The shipped
// showcase-nav-map.json of ja-diner 1.0.4 runs on a site built from that contract's own lock: the
// "J! Pages" drawer and the Home colour variants lose "Display in Menu", the customer's real pages
// keep it, every write is a compare-and-set whose receipt is the revert, and inspect holds the site to
// the result after every step.

// Run in its own scope: nothing it builds outlives it, and no later file finds its variables.
(static function () use ($WTOKEN): void {

// ------------------------------------------------------------------ JsonObjectKey

$jk = '{"a":1, "menu_show":1,"url":"http:\/\/x\/y","u":"é"}';
check('a key is read with its literal', JsonObjectKey::get($jk, 'menu_show'), ['exists' => true, 'literal' => '1', 'value' => 1]);
check('an absent key reads as absent', JsonObjectKey::get($jk, 'nope'), ['exists' => false, 'literal' => null, 'value' => null]);
check('setting a key moves only its value, escapes and spacing kept', JsonObjectKey::set($jk, 'menu_show', '0'), '{"a":1, "menu_show":0,"url":"http:\/\/x\/y","u":"é"}');
check('a new key is added last', JsonObjectKey::set('{"a":"1"}', 'menu_show', '"0"'), '{"a":"1","menu_show":"0"}');
check('a new key in an empty object', JsonObjectKey::set('{}', 'menu_show', '"0"'), '{"menu_show":"0"}');
check('removing a key takes the member and one comma', JsonObjectKey::set(JsonObjectKey::set($jk, 'menu_show', null), 'menu_show', null), '{"a":1, "url":"http:\/\/x\/y","u":"é"}');
check('removing the last key', JsonObjectKey::set('{"a":"1","menu_show":"0"}', 'menu_show', null), '{"a":"1"}');
check('removing the only key', JsonObjectKey::set('{"menu_show":"0"}', 'menu_show', null), '{}');
check('nested values are skipped whole', JsonObjectKey::set('{"x":{"menu_show":"1","y":[1,"}"]},"menu_show":"1"}', 'menu_show', '"0"'), '{"x":{"menu_show":"1","y":[1,"}"]},"menu_show":"0"}');
foreach (['["menu_show"]' => 'a list', 'not json' => 'not JSON', '{"menu_show":1,"menu_show":2}' => 'a key named twice'] as $bad => $what) {
    try { JsonObjectKey::set($bad, 'menu_show', '"0"'); check("JsonObjectKey refuses $what", 'accepted', 'refused'); }
    catch (Throwable $e) { check("JsonObjectKey refuses $what", 'refused', 'refused'); }
}

// ------------------------------------------------------------------ the profile

$snavSource = __DIR__ . '/../lib/contracts/ja-diner/j6/1.0.4';
$snavMap = json_decode(file_get_contents($snavSource . '/showcase-nav-map.json'), true, 512, JSON_THROW_ON_ERROR);
$snavContent = json_decode(file_get_contents($snavSource . '/content-map.json'), true, 512, JSON_THROW_ON_ERROR);
$snavLock = json_decode(file_get_contents($snavSource . '/presentation-lock.json'), true, 512, JSON_THROW_ON_ERROR);
$snavLoads = function (array $profile) use ($snavSource, $snavContent, $snavLock) {
    try { new ShowcaseNavProfile($profile, $snavContent, $snavLock, $snavSource, json_encode($profile), 'ja-diner/j6/1.0.4'); return 'loaded'; }
    catch (RuntimeException $e) { return 'refused'; }
};
check('the shipped diner map loads', $snavLoads($snavMap), 'loaded');
$snavBad = [
    'another schema' => fn ($m) => ['schemaVersion' => 'tracy-quickstart-showcase-nav/joomla/v2'] + $m,
    'another contract' => fn ($m) => ['contract' => 'ja-diner/j6/1.0.3'] + $m,
    'another base' => fn ($m) => ['baseHash' => str_repeat('0', 64)] + $m,
    'an unknown status' => fn ($m) => ['status' => 'draft'] + $m,
    'a not-covered map with operations' => fn ($m) => ['status' => 'not-covered'] + $m,
    'another param' => function ($m) { $m['operations'][0]['path'] = 'menu_text'; return $m; },
    'a field on a menu item' => function ($m) { $m['operations'][0]['field'] = 'published'; return $m; },
    'a "from" the lock does not hold' => function ($m) { $m['operations'][0]['from'] = null; return $m; },
    'a "to" that shows' => function ($m) { $m['operations'][0]['to'] = '1'; return $m; },
    'another identity' => function ($m) { $m['operations'][0]['identity'] = ['title' => 'Somebody else']; return $m; },
    'an entity outside the contract' => function ($m) { $m['operations'][0]['key'] = 'menuItem-99999'; return $m; },
    'an operation it does not apply' => function ($m) { $m['operations'][0]['op'] = 'menuItem.published'; return $m; },
    'an extra field' => function ($m) { $m['operations'][0]['params'] = '{}'; return $m; },
    'a row listed twice' => function ($m) { $m['operations'][] = $m['operations'][0]; return $m; },
    'a row with no reason' => function ($m) { $m['operations'][0]['reason'] = ''; return $m; },
];
foreach ($snavBad as $what => $edit) check("a showcase-navigation map with $what is refused", $snavLoads($edit($snavMap)), 'refused');
check('a not-covered map without operations loads', $snavLoads(['status' => 'not-covered', 'operations' => []] + $snavMap), 'loaded');

// ------------------------------------------------------------------ a site

/** A site built from the contract at `$source`, the copy edited by `$edit` before anything reads it. */
function snavSite(string $source, ?callable $edit = null): array {
    global $WTOKEN;
    $dir = gateContractCopy($source);
    if ($edit) $edit($dir);
    $store = new GateContractStore();
    $log = new FakeApplyLog();
    $writer = gateSite($dir, $store, $log);
    $contract = new QuickstartContract($writer, $store, $dir, $dir);
    $engine = new Engine($WTOKEN, ['joomla' => '6.1.1'], null, null, null, new FakeExtensions(), $writer, null, $log, null, null, null, $contract);
    $call = fn (array $params) => $engine->handle(['token' => $WTOKEN, 'action' => 'content.contract', 'params' => $params]);
    return [$dir, $store, $writer, $contract, $call, $engine];
}
/** The profile at `$dir/showcase-nav-map.json`, edited, and pinned to the copy again. */
function snavRewrite(string $dir, callable $edit): void {
    $file = $dir . '/showcase-nav-map.json';
    file_put_contents($file, json_encode($edit(json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR))));
}
function snavClean(string $label, QuickstartContract $contract): void {
    try { $contract->inspect(); check($label, $contract->driftWarnings(), []); }
    catch (Throwable $e) { check($label, 'refused: ' . $e->getMessage(), 'clean'); }
}
$snavShow = fn (ContractTestWriter $w, int $id) => JsonObjectKey::get((string) $w->store['menuItem'][$id]['params'], 'menu_show');

// An old site: a contract published before the map. Nothing is offered and inspect is what it was.
[, $oldStore, , $oldContract, $oldCall] = snavSite($snavSource, fn ($dir) => unlink($dir . '/showcase-nav-map.json'));
check('a contract without the map offers no showcase navigation', $oldCall(['operation' => 'showcaseNav.status'])['error'], 'unsupported');
check('nor an apply', $oldCall(['operation' => 'showcaseNav.apply', 'apply_id' => 'snav-a', 'request_id' => 'r1'])['error'], 'unsupported');
check('the old site binds as before', $oldCall(['operation' => 'bind'])['ok'], true);
check('and inspects with no showcase navigation on record', [$oldContract->inspect()['showcaseNav'], isset($oldStore->binding['showcaseNav'])], [null, false]);
[, , , , $ncCall] = snavSite($snavSource, fn ($dir) => snavRewrite($dir, fn ($m) => ['status' => 'not-covered', 'operations' => [], 'reason' => 'no showcase'] + $m));
check('a not-covered map is unsupported, and says why', $ncCall(['operation' => 'showcaseNav.status'])['message'], 'The showcase-navigation map does not cover this design (no showcase); nothing can be hidden by it');
[, , , $badContract, $badCall] = snavSite($snavSource, fn ($dir) => snavRewrite($dir, fn ($m) => ['baseHash' => str_repeat('0', 64)] + $m));
check('a refused map is unsupported, not a broken contract', [$badCall(['operation' => 'showcaseNav.status'])['error'], $badContract->inspect()['contract']], ['unsupported', 'ja-diner/j6/1.0.4']);

// ------------------------------------------------------------------ the diner, end to end

[$dir, $store, $w, $contract, $call, $engine] = snavSite($snavSource);
$hidden = [];
foreach ($snavMap['operations'] as $op) foreach ($snavContent['entities'] as $entity) if ($entity['key'] === $op['key']) $hidden[$op['label']] = (int) $entity['sourceId'];
$kept = ['Home' => 101, 'Our Blog' => 108, 'Contact Us' => 117, 'Offers' => 141, 'About Us' => 142, 'Reservation' => 144];
check('the diner map hides the J! Pages drawer and the Home colour variants', array_keys($hidden),
    ['J! Pages', 'Login', 'Registration', 'User Profile', 'Edit User Profile', 'Other Pages', 'Smart Search', 'Fetured Articles', 'Error Page', 'Offline Page', 'Typography', 'Home - Red', 'Home - Pink', 'Home - Green', 'Home - Blue']);
foreach ($kept as $title => $id) check("the diner's $title is the entry the test means", $w->store['menuItem'][$id]['title'], $title);
$original = array_map(fn ($row) => $row['params'] ?? null, $w->store['menuItem']);

$status = $call(['operation' => 'showcaseNav.status']);
check('status says what an apply would hide, before anything is written', [$status['ok'], $status['status'], $status['hides'], $status['remaining']], [true, 'none', ['menuItem' => 15], 15]);
check('an apply without the snav- prefix is refused by name', $call(['operation' => 'showcaseNav.apply', 'apply_id' => 'dtrim-a', 'request_id' => 'r1'])['message'], 'apply_id must start with "snav-"');
check('an apply without a request_id is refused', $call(['operation' => 'showcaseNav.apply', 'apply_id' => 'snav-a'])['error'], 'bad_params');
check('an unknown operation is refused', $call(['operation' => 'showcaseNav.hideAll'])['error'], 'bad_params');

$store->binding = ['multilingual' => ['languages' => ['fr-FR' => ['ids' => []]]]];
check('a site with a second language is refused, both ways', [
    $call(['operation' => 'showcaseNav.apply', 'apply_id' => 'snav-a', 'request_id' => 'r1'])['message'],
    $call(['operation' => 'showcaseNav.revert', 'apply_id' => 'snav-u', 'request_id' => 'u1'])['error'],
], ['This site already has a second language; taking its showcase entries out of the menus would leave the translated menus showing them. Nothing has been written.', 'contract_failed']);
$store->binding = null;
check('and nothing was written', array_map(fn ($row) => $row['params'] ?? null, $w->store['menuItem']), $original);

// A crash in the first batch rolls back the rows, but not the record that an apply began.
$w->failOn = ['menuItem', $hidden['Typography']];
$crashed = $call(['operation' => 'showcaseNav.apply', 'apply_id' => 'snav-a', 'request_id' => 'r1']);
check('a batch that fails reports it', $crashed['ok'], false);
check('the failed batch wrote no row', array_map(fn ($row) => $row['params'] ?? null, $w->store['menuItem']), $original);
check('an unbound site is bound first, and the begun apply is on record', [$store->binding['showcaseNav']['status'] ?? null, $store->binding['showcaseNav']['receipts'] ?? null], ['applying', []]);
$w->failOn = null;
snavClean('mid-apply, the site still inspects clean', $contract);
check('a different request cannot take over an apply in flight', $call(['operation' => 'showcaseNav.apply', 'apply_id' => 'snav-b', 'request_id' => 'r2'])['error'], 'conflict');

$done = $call(['operation' => 'showcaseNav.apply', 'apply_id' => 'snav-a', 'request_id' => 'r1']);
check('the retried apply completes', [$done['ok'], $done['status'], $done['moved'], $done['remaining'], $done['skipped']], [true, 'completed', 15, 0, []]);
foreach ($hidden as $title => $id) {
    $before = json_decode($original[$id], true);
    $after = json_decode((string) $w->store['menuItem'][$id]['params'], true);
    check("$title is out of the menu", (string) $after['menu_show'], '0');
    unset($before['menu_show'], $after['menu_show']);
    check("$title keeps every other param", $after, $before);
    check("$title keeps its published state and its link", [$w->store['menuItem'][$id]['published'], $w->store['menuItem'][$id]['link']], ['1', $snavLock['entities']['menuItem-' . $id]['link']]);
}
foreach ($kept as $title => $id) check("$title is untouched", $w->store['menuItem'][$id]['params'], $original[$id]);
check('only the listed rows changed', count(array_diff_assoc(array_map(fn ($row) => $row['params'] ?? null, $w->store['menuItem']), $original)), 15);
$receipt = $store->binding['showcaseNav']['receipts']['menuItem-' . $hidden['Typography']];
check('the receipt holds what was found and what was written', [$receipt['outcome'], $receipt['existed'], $receipt['before'], $receipt['after']], ['written', true, '1', '0']);
snavClean('the applied site inspects clean', $contract);
check('inspect says the showcase is out of the menus', $contract->inspect()['showcaseNav'], 'complete');
check('status says so too', array_intersect_key($call(['operation' => 'showcaseNav.status']), ['status' => 1, 'remaining' => 1, 'profileChanged' => 1]), ['status' => 'complete', 'remaining' => 0, 'profileChanged' => false]);
$again = $call(['operation' => 'showcaseNav.apply', 'apply_id' => 'snav-c', 'request_id' => 'r3']);
check('asking again is a no-op, not a second apply', [$again['status'], $again['alreadyApplied'], $again['applyId']], ['completed', true, 'snav-a']);
$lost = $call(['operation' => 'showcaseNav.apply', 'apply_id' => 'snav-a', 'request_id' => 'r1']);
check('the same request after a lost answer gets the same answer', [$lost['ok'], $lost['status']], [true, 'completed']);
check('the generic revert does not take it apart', $engine->handle(['token' => $WTOKEN, 'action' => 'apply.revert', 'params' => ['apply_id' => 'snav-a']])['error'], 'bad_params');

// Drift: an entry put back in the menu is reported; so is any other param of a hidden row.
$red = $hidden['Home - Red'];
$w->store['menuItem'][$red]['params'] = JsonObjectKey::set($w->store['menuItem'][$red]['params'], 'menu_show', '1');
contractDrifts('a hidden entry that reappears is reported as drift', $contract, fn () => $contract->inspect());
$w->store['menuItem'][$red]['params'] = JsonObjectKey::set($w->store['menuItem'][$red]['params'], 'menu_show', '0');
snavClean('and is clean once hidden again', $contract);
$otherKey = array_key_first(array_diff_key(json_decode($original[$red], true), ['menu_show' => 1]));
$w->store['menuItem'][$red]['params'] = JsonObjectKey::set($w->store['menuItem'][$red]['params'], $otherKey, '"drifted"');
contractDrifts('another param of a hidden entry is still held to the lock', $contract, fn () => $contract->inspect());
$w->store['menuItem'][$red]['params'] = JsonObjectKey::set($w->store['menuItem'][$red]['params'], $otherKey, JsonObjectKey::get($original[$red], $otherKey)['literal']);
snavClean('and is clean once it is back', $contract);

// The customer puts one entry back in the menu themselves: the revert leaves it, and says so.
$pink = $hidden['Home - Pink'];
$w->store['menuItem'][$pink]['params'] = JsonObjectKey::set($w->store['menuItem'][$pink]['params'], 'menu_show', '"1"');
$customer = $w->store['menuItem'][$pink]['params'];
$back = $call(['operation' => 'showcaseNav.revert', 'apply_id' => 'snav-undo', 'request_id' => 'u1']);
check('revert brings the showcase back', [$back['ok'], $back['status'], $back['moved'], $back['remaining']], [true, 'reverted', 14, 0]);
check('and names the entry the customer changed', array_keys($back['conflicts']), ['menuItem-' . $pink]);
check("the customer's own value is left as they wrote it", $w->store['menuItem'][$pink]['params'], $customer);
foreach ($hidden as $title => $id) if ($id !== $pink) check("$title has exactly its params back", $w->store['menuItem'][$id]['params'], $original[$id]);
check('and leaves no showcase navigation on record', [isset($store->binding['showcaseNav']), $contract->inspect()['showcaseNav']], [false, null]);
$w->store['menuItem'][$pink]['params'] = $original[$pink];
snavClean('the reverted site inspects clean', $contract);
check('revert with nothing applied says so', $call(['operation' => 'showcaseNav.revert', 'apply_id' => 'snav-undo2', 'request_id' => 'u2'])['error'], 'contract_failed');

// A row someone already hid, or moved elsewhere, is not written; its receipt says why.
$w->store['menuItem'][$hidden['Login']]['params'] = JsonObjectKey::set($original[$hidden['Login']], 'menu_show', '0');
$w->store['menuItem'][$hidden['Registration']]['params'] = JsonObjectKey::set($original[$hidden['Registration']], 'menu_show', '"maybe"');
$skip = $call(['operation' => 'showcaseNav.apply', 'apply_id' => 'snav-d', 'request_id' => 'r4']);
check('rows not where the map starts are skipped, by name', [$skip['status'], $skip['moved'], $skip['skipped']],
    ['completed', 13, ['menuItem-' . $hidden['Login'] => 'already out of the menu', 'menuItem-' . $hidden['Registration'] => 'menu_show is "maybe", not shown']]);
check('the skipped rows were not written', [JsonObjectKey::get($w->store['menuItem'][$hidden['Login']]['params'], 'menu_show')['literal'], JsonObjectKey::get($w->store['menuItem'][$hidden['Registration']]['params'], 'menu_show')['literal']], ['0', '"maybe"']);
$skipBack = $call(['operation' => 'showcaseNav.revert', 'apply_id' => 'snav-undo3', 'request_id' => 'u3']);
check('their revert leaves them as they were', [$skipBack['status'], $skipBack['moved'], JsonObjectKey::get($w->store['menuItem'][$hidden['Login']]['params'], 'menu_show')['literal']], ['reverted', 13, '0']);

// ------------------------------------------------------------------ a module, through the visibility column

$snavModule = null;
foreach ($snavContent['entities'] as $entity)
    if ($entity['kind'] === 'module' && (string) ($snavLock['entities'][$entity['key']]['published'] ?? '') === '1') { $snavModule = $entity; break; }
[, $mStore, $mw, $mContract, $mCall] = snavSite($snavSource, fn ($dir) => snavRewrite($dir, function ($m) use ($snavModule) {
    $m['operations'][] = ['op' => 'module.published', 'key' => $snavModule['key'], 'identity' => $snavModule['identity'], 'field' => 'published', 'from' => '1', 'to' => '0', 'rule' => 'test', 'reason' => 'a showcase module'];
    return $m;
}));
$mid = (int) $snavModule['sourceId'];
$mApplied = $mCall(['operation' => 'showcaseNav.apply', 'apply_id' => 'snav-m', 'request_id' => 'm1']);
check('a listed module is unpublished with the menu entries', [$mApplied['status'], $mApplied['moved'], $mw->store['module'][$mid]['published']], ['completed', 16, '0']);
snavClean('the site with the module hidden inspects clean', $mContract);
$mBack = $mCall(['operation' => 'showcaseNav.revert', 'apply_id' => 'snav-mu', 'request_id' => 'm2']);
check('and is published again by the revert', [$mBack['status'], $mw->store['module'][$mid]['published']], ['reverted', '1']);
snavClean('the reverted module site inspects clean', $mContract);

// ------------------------------------------------------------------ an absent key, beside a demo trim

// Kinetic's showcase items carry no menu_show at all (`from: null`): the apply adds the key, the
// revert takes it out again, byte for byte. Its demo trim is a separate record and stays one.
[, $kStore, $kw, $kContract, $kCall] = snavSite(__DIR__ . '/../lib/contracts/ja-kinetic/j6/1.0.10');
$kMap = json_decode(file_get_contents(__DIR__ . '/../lib/contracts/ja-kinetic/j6/1.0.10/showcase-nav-map.json'), true, 512, JSON_THROW_ON_ERROR);
$kIds = [];
foreach (json_decode(file_get_contents(__DIR__ . '/../lib/contracts/ja-kinetic/j6/1.0.10/content-map.json'), true)['entities'] as $entity)
    foreach ($kMap['operations'] as $op) if ($op['key'] === $entity['key']) $kIds[] = (int) $entity['sourceId'];
$kOriginal = array_map(fn ($row) => $row['params'] ?? null, $kw->store['menuItem']);
check('kinetic lists entries with no menu_show', array_map(fn ($id) => $snavShow($kw, $id)['exists'], $kIds), [false, false]);
check('kinetic applies', $kCall(['operation' => 'showcaseNav.apply', 'apply_id' => 'snav-k', 'request_id' => 'k1'])['status'], 'completed');
check('the absent key is added, as the admin form writes it', array_map(fn ($id) => $snavShow($kw, $id)['literal'], $kIds), ['"0"', '"0"']);
check('its receipt says the key was not there', array_column(array_values($kStore->binding['showcaseNav']['receipts']), 'existed'), [false, false]);
snavClean('kinetic inspects clean with the keys added', $kContract);
$kTrim = $kCall(['operation' => 'demoTrim.apply', 'apply_id' => 'dtrim-k', 'request_id' => 'k2']);
check('a demo trim still runs beside it', [$kTrim['ok'], $kTrim['status'] ?? null, $kStore->binding['showcaseNav']['status']], [true, 'completed', 'complete']);
$kBack = $kCall(['operation' => 'showcaseNav.revert', 'apply_id' => 'snav-ku', 'request_id' => 'k3']);
check('the showcase revert leaves the trim alone', [$kBack['status'], $kStore->binding['demoTrim']['status'] ?? null], ['reverted', 'complete']);
check('the added keys are gone again, byte for byte', array_map(fn ($id) => $kw->store['menuItem'][$id]['params'], $kIds), array_map(fn ($id) => $kOriginal[$id], $kIds));
check('the demo trim still reverts', $kCall(['operation' => 'demoTrim.revert', 'apply_id' => 'dtrim-ku', 'request_id' => 'k4'])['status'], 'reverted');
snavClean('kinetic inspects clean after both reverts', $kContract);
})();
