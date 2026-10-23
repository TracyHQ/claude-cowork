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
/** The lock at `$dir`, edited, and every profile pinned to the edited copy. */
function snavRelock(string $dir, callable $edit): void {
    $file = $dir . '/presentation-lock.json';
    file_put_contents($file, json_encode($edit(json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR))));
    $base = hash('sha256', implode('', array_map(fn ($n) => $n . ':' . hash_file('sha256', $dir . '/' . $n) . "\n", ['manifest.json', 'content-map.json', 'presentation-lock.json'])));
    foreach (glob($dir . '/*-map.json') ?: [] as $profile)
        if (basename($profile) !== 'content-map.json') file_put_contents($profile, json_encode(['baseHash' => $base] + json_decode(file_get_contents($profile), true, 512, JSON_THROW_ON_ERROR)));
}
function snavClean(string $label, QuickstartContract $contract): void {
    try { $contract->inspect(); check($label, $contract->driftWarnings(), []); }
    catch (Throwable $e) { check($label, 'refused: ' . $e->getMessage(), 'clean'); }
}
/** The rows inspect reports drifted, by key. */
function snavDrifted(QuickstartContract $contract): array {
    try { $contract->inspect(); } catch (Throwable $e) { return ['refused: ' . $e->getMessage()]; }
    $keys = [];
    foreach ($contract->driftWarnings() as $warning) if (preg_match('/drift: (\S+) /', $warning, $m)) $keys[] = $m[1];
    sort($keys);
    return array_values(array_unique($keys));
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
$pinkStatus = $call(['operation' => 'showcaseNav.status']);
check('and leaves no showcase navigation applied, only the conflict on record', [$store->binding['showcaseNav']['status'], array_keys($store->binding['showcaseNav']['receipts']), $contract->inspect()['showcaseNav'], $pinkStatus['status'], array_keys($pinkStatus['conflicts'])],
    ['reverted', ['menuItem-' . $pink], null, 'none', ['menuItem-' . $pink]]);
// The baseline moved only for the rows the revert wrote: the row it left is still held to what the
// apply wrote, and the customer's own change to it is reported, even put back as the lock had it.
check("the customer's change is not adopted as the baseline", snavDrifted($contract), ['menuItem-' . $pink]);
$w->store['menuItem'][$pink]['params'] = $original[$pink];
check('nor is putting it back by hand', snavDrifted($contract), ['menuItem-' . $pink]);
$retried = $call(['operation' => 'showcaseNav.revert', 'apply_id' => 'snav-undo', 'request_id' => 'u1']);
check('the same revert after a lost answer gets the committed answer', [$retried['ok'], $retried['status'], $retried['remaining'], $retried['alreadyReverted'], array_keys($retried['conflicts'])], [true, 'reverted', 0, true, ['menuItem-' . $pink]]);
check('and writes nothing', $w->store['menuItem'][$pink]['params'], $original[$pink]);
check('the reverted apply request cannot apply again', $call(['operation' => 'showcaseNav.apply', 'apply_id' => 'snav-a', 'request_id' => 'r1'])['error'], 'conflict');
// A new revert request looks at the conflict again: put back by hand, it is restored with nothing written.
$again = $call(['operation' => 'showcaseNav.revert', 'apply_id' => 'snav-undo2', 'request_id' => 'u2']);
check('a later revert takes the row put back by hand', [$again['ok'], $again['status'], $again['moved'], $again['conflicts'], $w->store['menuItem'][$pink]['params']], [true, 'reverted', 1, [], $original[$pink]]);
snavClean('the reverted site inspects clean', $contract);
check('with nothing left to bring back, a revert says so', $call(['operation' => 'showcaseNav.revert', 'apply_id' => 'snav-undo3', 'request_id' => 'u3'])['error'], 'contract_failed');
check('both reverts still answer their retries', [
    array_keys($call(['operation' => 'showcaseNav.revert', 'apply_id' => 'snav-undo', 'request_id' => 'u1'])['conflicts']),
    $call(['operation' => 'showcaseNav.revert', 'apply_id' => 'snav-undo2', 'request_id' => 'u2'])['conflicts'],
], [['menuItem-' . $pink], []]);

// A row someone already hid, or moved elsewhere, is not written; its receipt says why.
$w->store['menuItem'][$hidden['Login']]['params'] = JsonObjectKey::set($original[$hidden['Login']], 'menu_show', '0');
$w->store['menuItem'][$hidden['Registration']]['params'] = JsonObjectKey::set($original[$hidden['Registration']], 'menu_show', '"maybe"');
$skip = $call(['operation' => 'showcaseNav.apply', 'apply_id' => 'snav-d', 'request_id' => 'r4']);
check('rows not where the map starts are skipped, by name', [$skip['status'], $skip['moved'], $skip['skipped']],
    ['completed', 13, ['menuItem-' . $hidden['Login'] => 'already out of the menu', 'menuItem-' . $hidden['Registration'] => 'menu_show is "maybe", not shown']]);
check('the skipped rows were not written', [JsonObjectKey::get($w->store['menuItem'][$hidden['Login']]['params'], 'menu_show')['literal'], JsonObjectKey::get($w->store['menuItem'][$hidden['Registration']]['params'], 'menu_show')['literal']], ['0', '"maybe"']);
$skipBack = $call(['operation' => 'showcaseNav.revert', 'apply_id' => 'snav-undo4', 'request_id' => 'u4']);
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

// ------------------------------------------------------------------ a revert's conflicts, looked at again

// 🔒 A ROW A REVERT LEFT IS RECOVERED, NEVER OVERWRITTEN. A later revert request looks at those rows
// only: one already holding, byte for byte, what the apply found is restored with nothing written; one
// holding what the apply wrote is put back by the same compare-and-set; anything else stays the customer's.
[, $cStore, $cw, $cContract, $cCall] = snavSite($snavSource);
$cOriginal = array_map(fn ($row) => $row['params'] ?? null, $cw->store['menuItem']);
check('the conflicts site applies', $cCall(['operation' => 'showcaseNav.apply', 'apply_id' => 'snav-c', 'request_id' => 'c1'])['status'], 'completed');
[$cFound, $cHidden, $cOther] = [$hidden['Home - Red'], $hidden['Home - Green'], $hidden['Home - Blue']];
foreach ([$cFound => '"1"', $cHidden => '"maybe"', $cOther => '"1"'] as $id => $literal)
    $cw->store['menuItem'][$id]['params'] = JsonObjectKey::set((string) $cw->store['menuItem'][$id]['params'], 'menu_show', $literal);
$cFirst = $cCall(['operation' => 'showcaseNav.revert', 'apply_id' => 'snav-cu', 'request_id' => 'c2']);
$cWant = ['menuItem-' . $cFound, 'menuItem-' . $cHidden, 'menuItem-' . $cOther];
sort($cWant);
$cKeys = fn (array $answer) => (function ($k) { sort($k); return $k; })(array_keys($answer['conflicts'] ?? []));
check('the first revert leaves three rows', [$cFirst['status'], $cKeys($cFirst)], ['reverted', $cWant]);
check('the lock says what each row started from', JsonObjectKey::get($snavLock['entities']['menuItem-' . $cFound]['params'], 'menu_show')['literal'], '1');
// One is put back by hand exactly, one is hidden again as the apply left it, one stays the customer's;
// a kept page drifts too, and is no row of the revert.
$cw->store['menuItem'][$cFound]['params'] = $cOriginal[$cFound];
$cw->store['menuItem'][$cHidden]['params'] = JsonObjectKey::set((string) $cw->store['menuItem'][$cHidden]['params'], 'menu_show', '0');
$cOtherSaved = $cw->store['menuItem'][$cOther]['params'];
$cOffers = (string) $cw->store['menuItem'][141]['params'];
$cw->store['menuItem'][141]['params'] = JsonObjectKey::set($cOffers, array_key_first(array_diff_key(json_decode($cOffers, true), ['menu_show' => 1])), '"drifted"');
$cWrites = [];
$cw->beforeCas = function (ContractTestWriter $writer, string $kind, int $id) use (&$cWrites) { $cWrites[] = $kind . '-' . $id; };
$cSecond = $cCall(['operation' => 'showcaseNav.revert', 'apply_id' => 'snav-cu2', 'request_id' => 'c3']);
$cw->beforeCas = null;
check('a later revert takes two rows back and leaves the third', [$cSecond['ok'], $cSecond['status'], $cSecond['moved'], array_keys($cSecond['conflicts'])], [true, 'reverted', 2, ['menuItem-' . $cOther]]);
check('it writes only the row that held what the apply wrote', $cWrites, ['menuItem-' . $cHidden]);
check('the row put back by hand keeps its bytes', $cw->store['menuItem'][$cFound]['params'], $cOriginal[$cFound]);
check('the row the apply left gets exactly its params back', $cw->store['menuItem'][$cHidden]['params'], $cOriginal[$cHidden]);
check("the customer's row is untouched", $cw->store['menuItem'][$cOther]['params'], $cOtherSaved);
check('the baseline followed the two rows and nothing else', snavDrifted($cContract), ['menuItem-141', 'menuItem-' . $cOther]);
check('the retried later revert gets its own answer', [$cCall(['operation' => 'showcaseNav.revert', 'apply_id' => 'snav-cu2', 'request_id' => 'c3'])['alreadyReverted'] ?? null, $cKeys($cCall(['operation' => 'showcaseNav.revert', 'apply_id' => 'snav-cu2', 'request_id' => 'c3']))], [true, ['menuItem-' . $cOther]]);
check('the retried first revert still gets its own', $cKeys($cCall(['operation' => 'showcaseNav.revert', 'apply_id' => 'snav-cu', 'request_id' => 'c2'])), $cWant);
$cw->store['menuItem'][$cOther]['params'] = $cOriginal[$cOther];
$cw->store['menuItem'][141]['params'] = $cOffers;
$cLast = $cCall(['operation' => 'showcaseNav.revert', 'apply_id' => 'snav-cu3', 'request_id' => 'c4']);
check('once every row is back the revert ends with no conflict', [$cLast['status'], $cLast['moved'], $cLast['conflicts'], $cStore->binding['showcaseNav']['receipts']], ['reverted', 1, [], []]);
check('every row has exactly its params back', array_map(fn ($row) => $row['params'] ?? null, $cw->store['menuItem']), $cOriginal);
snavClean('the site inspects clean', $cContract);
check('and a revert has nothing more to bring back', $cCall(['operation' => 'showcaseNav.revert', 'apply_id' => 'snav-cu4', 'request_id' => 'c5'])['error'], 'contract_failed');

// ------------------------------------------------------------------ a save between the read and the write

// 🔒 EVERY WRITE IS A COMPARE-AND-SET, module or menu item. A save that lands after the row was read
// (here: the same value in other bytes, so the site itself is not drift) is left as it was saved and
// named a conflict; the rest of the batch goes on.
[, $rStore, $rw, $rContract, $rCall] = snavSite($snavSource, fn ($dir) => snavRewrite($dir, function ($m) use ($snavModule) {
    $m['operations'][] = ['op' => 'module.published', 'key' => $snavModule['key'], 'identity' => $snavModule['identity'], 'field' => 'published', 'from' => '1', 'to' => '0', 'rule' => 'test', 'reason' => 'a showcase module'];
    return $m;
}));
$rTypo = $hidden['Typography'];
$rRaced = JsonObjectKey::set((string) $rw->store['menuItem'][$rTypo]['params'], 'menu_show', '"1"');
$rw->beforeCas = function (ContractTestWriter $writer, string $kind, int $id) use ($rTypo, $mid, $rRaced) {
    if ($kind === 'menuItem' && $id === $rTypo) $writer->store['menuItem'][$id]['params'] = $rRaced;
    if ($kind === 'module' && $id === $mid) $writer->store['module'][$id]['published'] = '01';
};
$rApplied = $rCall(['operation' => 'showcaseNav.apply', 'apply_id' => 'snav-r', 'request_id' => 'race1']);
$rReceipts = $rStore->binding['showcaseNav']['receipts'] ?? [];
check('a raced apply goes on with the other rows', [$rApplied['ok'], $rApplied['status'] ?? null, $rApplied['moved'] ?? null], [true, 'completed', 14]);
check('and names both raced rows a conflict', [$rReceipts['menuItem-' . $rTypo]['outcome'] ?? null, $rReceipts[$snavModule['key']]['outcome'] ?? null], ['conflict', 'conflict']);
check('neither raced row is overwritten', [$rw->store['menuItem'][$rTypo]['params'], $rw->store['module'][$mid]['published']], [$rRaced, '01']);
$rw->beforeCas = null;
snavClean('the raced site inspects clean', $rContract);
// The revert's writes are compare-and-sets too: a row saved after its check is not restored over.
$rRed = $hidden['Home - Red'];
$rRedSaved = JsonObjectKey::set((string) $rw->store['menuItem'][$rRed]['params'], 'menu_show', '"0"');
$rw->beforeCas = function (ContractTestWriter $writer, string $kind, int $id) use ($rRed, $rRedSaved) {
    if ($kind === 'menuItem' && $id === $rRed) $writer->store['menuItem'][$id]['params'] = $rRedSaved;
};
$rBack = $rCall(['operation' => 'showcaseNav.revert', 'apply_id' => 'snav-ru', 'request_id' => 'race2']);
check('a raced revert names the row and leaves it as saved', [$rBack['status'] ?? $rBack, array_keys($rBack['conflicts'] ?? []), $rw->store['menuItem'][$rRed]['params']], ['reverted', ['menuItem-' . $rRed], $rRedSaved]);
$rw->beforeCas = null;

// ------------------------------------------------------------------ drift the apply did not make stays drift

// 🔒 THE BASELINE MOVES ONLY WHERE A ROW WAS WRITTEN. A kept page and a listed entry that already
// differ from the lock before the apply are still reported after it, and after its revert.
[, , $dw, $dContract, $dCall] = snavSite($snavSource);
check('a bound site', $dCall(['operation' => 'bind'])['ok'], true);
$dOffers = (string) $dw->store['menuItem'][141]['params'];
$dKey = array_key_first(array_diff_key(json_decode($dOffers, true), ['menu_show' => 1]));
$dw->store['menuItem'][141]['params'] = JsonObjectKey::set($dOffers, $dKey, '"drifted"');
$dGreen = $hidden['Home - Green'];
$dw->store['menuItem'][$dGreen]['params'] = JsonObjectKey::set((string) $dw->store['menuItem'][$dGreen]['params'], $dKey, '"drifted"');
$dWant = ['menuItem-141', 'menuItem-' . $dGreen];
sort($dWant);
check('the drift is reported before the apply', snavDrifted($dContract), $dWant);
$dApplied = $dCall(['operation' => 'showcaseNav.apply', 'apply_id' => 'snav-dr', 'request_id' => 'd1']);
check('the apply still runs', [$dApplied['ok'], $dApplied['moved'] ?? $dApplied], [true, 15]);
check('and the drift it did not make is still reported after it', snavDrifted($dContract), $dWant);
$dBack = $dCall(['operation' => 'showcaseNav.revert', 'apply_id' => 'snav-dru', 'request_id' => 'd2']);
check('the revert still runs', [$dBack['ok'], $dBack['moved'] ?? $dBack], [true, 15]);
check('and the drift is still reported after it', snavDrifted($dContract), $dWant);
$dw->store['menuItem'][141]['params'] = $dOffers;
check('and a row put back is no longer reported', snavDrifted($dContract), ['menuItem-' . $dGreen]);

// ------------------------------------------------------------------ an empty params column

// A menu item saved with no params at all holds "" or only whitespace (Joomla reads either as {}):
// `from: null` hides it with the one key the admin form would write, and the revert hands the column
// back exactly as it was.
$bId = $hidden['Typography'];
foreach (['empty' => '', 'whitespace-only' => " \n"] as $bName => $bText) {
    [, , $bw, $bContract, $bCall] = snavSite($snavSource, function ($dir) use ($bId, $bText) {
        snavRelock($dir, function ($lock) use ($bId, $bText) { $lock['entities']['menuItem-' . $bId]['params'] = $bText; return $lock; });
        snavRewrite($dir, function ($m) use ($bId) {
            foreach ($m['operations'] as &$op) if ($op['key'] === 'menuItem-' . $bId) $op['from'] = null;
            return $m;
        });
    });
    check("the $bName column is what the site holds", $bw->store['menuItem'][$bId]['params'], $bText);
    snavClean("and the site inspects clean with the $bName column", $bContract);
    $bApplied = $bCall(['operation' => 'showcaseNav.apply', 'apply_id' => 'snav-b', 'request_id' => 'b1']);
    check("the $bName params column is hidden from null", [$bApplied['ok'], $bApplied['moved'] ?? $bApplied, $bw->store['menuItem'][$bId]['params']], [true, 15, '{"menu_show":"0"}']);
    snavClean("the site with the $bName row hidden inspects clean", $bContract);
    $bBack = $bCall(['operation' => 'showcaseNav.revert', 'apply_id' => 'snav-bu', 'request_id' => 'b2']);
    check("the revert hands the $bName column back as it was", [$bBack['ok'], $bBack['moved'] ?? $bBack, $bw->store['menuItem'][$bId]['params']], [true, 15, $bText]);
    snavClean("the site with the $bName row back inspects clean", $bContract);
}

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
