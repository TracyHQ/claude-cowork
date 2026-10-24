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
    'another schema' => fn ($m) => ['schemaVersion' => 'tracy-quickstart-showcase-nav/joomla/v3'] + $m,
    'a style operation under v1' => fn ($m) => ['schemaVersion' => 'tracy-quickstart-showcase-nav/joomla/v1'] + $m,
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
$styles = [];
foreach ($snavMap['operations'] as $op) foreach ($snavContent['entities'] as $entity) if ($entity['key'] === $op['key']) {
    if ($op['op'] === 'menuItem.param') $hidden[$op['label']] = (int) $entity['sourceId'];
    else $styles[$op['key']] = (int) $entity['sourceId'];
}
$kept = ['Home' => 101, 'Our Blog' => 108, 'Contact Us' => 117, 'Offers' => 141, 'About Us' => 142, 'Reservation' => 144];
check('the diner map hides the J! Pages drawer and the Home colour variants', array_keys($hidden),
    ['J! Pages', 'Login', 'Registration', 'User Profile', 'Edit User Profile', 'Other Pages', 'Smart Search', 'Fetured Articles', 'Error Page', 'Offline Page', 'Typography', 'Home - Red', 'Home - Pink', 'Home - Green', 'Home - Blue']);
foreach ($kept as $title => $id) check("the diner's $title is the entry the test means", $w->store['menuItem'][$id]['title'], $title);
$original = array_map(fn ($row) => $row['params'] ?? null, $w->store['menuItem']);

$status = $call(['operation' => 'showcaseNav.status']);
check('status says what an apply would hide, before anything is written', [$status['ok'], $status['status'], $status['hides'], $status['remaining']], [true, 'none', ['menuItem' => 15, 'templateStyle' => 5], 20]);
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
check('the retried apply completes', [$done['ok'], $done['status'], $done['moved'], $done['remaining'], $done['skipped']], [true, 'completed', 20, 0, []]);
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
snavClean('the rows that answer wrote are this job\'s: the site still inspects clean', $contract);
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
check('revert brings the showcase back', [$back['ok'], $back['status'], $back['moved'], $back['remaining']], [true, 'reverted', 19, 0]);
check('and names the entry the customer changed', array_keys($back['conflicts']), ['menuItem-' . $pink]);
check("the customer's own value is left as they wrote it", $w->store['menuItem'][$pink]['params'], $customer);
foreach ($hidden as $title => $id) if ($id !== $pink) check("$title has exactly its params back", $w->store['menuItem'][$id]['params'], $original[$id]);
$pinkStatus = $call(['operation' => 'showcaseNav.status']);
check('and leaves no showcase navigation applied, only the conflict on record', [$store->binding['showcaseNav']['status'], array_keys($store->binding['showcaseNav']['receipts']), $contract->inspect()['showcaseNav'], $pinkStatus['status'], array_keys($pinkStatus['conflicts'])],
    ['reverted', ['menuItem-' . $pink], null, 'reverted', ['menuItem-' . $pink]]);
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
$after = $call(['operation' => 'showcaseNav.status']);
check('status after a finished revert: reverted, nothing remaining, nothing in conflict', [$after['status'], $after['remaining'], $after['conflicts'], $after['owners'], $after['applyId']],
    ['reverted', 0, [], 20, 'snav-a']);
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
    ['completed', 18, ['menuItem-' . $hidden['Login'] => 'already out of the menu', 'menuItem-' . $hidden['Registration'] => 'menu_show is "maybe", not shown']]);
$skipDrift = ['menuItem-' . $hidden['Login'], 'menuItem-' . $hidden['Registration']];
sort($skipDrift);
// 🔒 A row found already where the map leaves it was not this job's write: the baseline does not move for it.
check('a row the customer already hid is still drift after the apply', snavDrifted($contract), $skipDrift);
check('the skipped rows were not written', [JsonObjectKey::get($w->store['menuItem'][$hidden['Login']]['params'], 'menu_show')['literal'], JsonObjectKey::get($w->store['menuItem'][$hidden['Registration']]['params'], 'menu_show')['literal']], ['0', '"maybe"']);
$skipBack = $call(['operation' => 'showcaseNav.revert', 'apply_id' => 'snav-undo4', 'request_id' => 'u4']);
check('their revert leaves them as they were', [$skipBack['status'], $skipBack['moved'], JsonObjectKey::get($w->store['menuItem'][$hidden['Login']]['params'], 'menu_show')['literal']], ['reverted', 18, '0']);
check('and still drift after it', snavDrifted($contract), $skipDrift);

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
check('a listed module is unpublished with the menu entries', [$mApplied['status'], $mApplied['moved'], $mw->store['module'][$mid]['published']], ['completed', 21, '0']);
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
check('a raced apply goes on with the other rows', [$rApplied['ok'], $rApplied['status'] ?? null, $rApplied['moved'] ?? null], [true, 'completed', 19]);
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
check('the apply still runs', [$dApplied['ok'], $dApplied['moved'] ?? $dApplied], [true, 20]);
check('and the drift it did not make is still reported after it', snavDrifted($dContract), $dWant);
$dBack = $dCall(['operation' => 'showcaseNav.revert', 'apply_id' => 'snav-dru', 'request_id' => 'd2']);
check('the revert still runs', [$dBack['ok'], $dBack['moved'] ?? $dBack], [true, 20]);
check('and the drift is still reported after it', snavDrifted($dContract), $dWant);
$dw->store['menuItem'][141]['params'] = $dOffers;
check('and a row put back is no longer reported', snavDrifted($dContract), ['menuItem-' . $dGreen]);

// ------------------------------------------------------------------ an empty params column

// A menu item saved with no params at all holds "", only whitespace or `[]` (Joomla reads each as {}):
// `from: null` hides it with the one key the admin form would write, and the revert hands the column
// back exactly as it was.
$bId = $hidden['Typography'];
foreach (['empty' => '', 'whitespace-only' => " \n", '[]' => '[]'] as $bName => $bText) {
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
    check("the $bName params column is hidden from null", [$bApplied['ok'], $bApplied['moved'] ?? $bApplied, $bw->store['menuItem'][$bId]['params']], [true, 20, '{"menu_show":"0"}']);
    snavClean("the site with the $bName row hidden inspects clean", $bContract);
    $bBack = $bCall(['operation' => 'showcaseNav.revert', 'apply_id' => 'snav-bu', 'request_id' => 'b2']);
    check("the revert hands the $bName column back as it was", [$bBack['ok'], $bBack['moved'] ?? $bBack, $bw->store['menuItem'][$bId]['params']], [true, 20, $bText]);
    snavClean("the site with the $bName row back inspects clean", $bContract);
}

// 🔒 `[]` EXACTLY, as tracy-business 1.2.6 ships its kit screens (404, Style guide): the apply retried
// after a lost answer gets the committed one, a row put back to `[]` by hand is restored with nothing
// written, and any other list is still not a params object.
[, , $bw, $bContract, $bCall] = snavSite($snavSource, function ($dir) use ($bId) {
    snavRelock($dir, function ($lock) use ($bId) { $lock['entities']['menuItem-' . $bId]['params'] = '[]'; return $lock; });
    snavRewrite($dir, function ($m) use ($bId) {
        foreach ($m['operations'] as &$op) if ($op['key'] === 'menuItem-' . $bId) $op['from'] = null;
        return $m;
    });
});
$bApplied = $bCall(['operation' => 'showcaseNav.apply', 'apply_id' => 'snav-bl', 'request_id' => 'bl1']);
$bAgain = $bCall(['operation' => 'showcaseNav.apply', 'apply_id' => 'snav-bl', 'request_id' => 'bl1']);
check('a [] row: the apply retried gets the committed answer', [$bApplied['skipped'], $bAgain['status'], $bAgain['alreadyApplied'] ?? null, $bw->store['menuItem'][$bId]['params']],
    [[], 'completed', true, '{"menu_show":"0"}']);
$bw->store['menuItem'][$bId]['params'] = '[]';
$bBack = $bCall(['operation' => 'showcaseNav.revert', 'apply_id' => 'snav-blu', 'request_id' => 'bl2']);
check('a [] row put back by hand is restored with nothing written', [$bBack['status'], $bBack['conflicts'], $bw->store['menuItem'][$bId]['params']], ['reverted', [], '[]']);
snavClean('and the site inspects clean', $bContract);
foreach (['[ ]', '[1]'] as $bText) {
    [, , $bw, , $bCall] = snavSite($snavSource, function ($dir) use ($bId, $bText) {
        snavRelock($dir, function ($lock) use ($bId, $bText) { $lock['entities']['menuItem-' . $bId]['params'] = $bText; return $lock; });
        snavRewrite($dir, function ($m) use ($bId) {
            foreach ($m['operations'] as &$op) if ($op['key'] === 'menuItem-' . $bId) $op['from'] = null;
            return $m;
        });
    });
    $bApplied = $bCall(['operation' => 'showcaseNav.apply', 'apply_id' => 'snav-bo', 'request_id' => 'bo1']);
    check("a $bText row is still not a params object", [$bApplied['skipped']['menuItem-' . $bId] ?? null, $bw->store['menuItem'][$bId]['params']], ['its params are not a JSON object', $bText]);
}

// ------------------------------------------------------------------ the T3 mega-menu sub of an emptied item

// 🔒 V2: every child of Home leaves the menu, so each T3 style drops `mm_config.mainmenu.item-101.sub`
// — the whole column a byte-exact compare-and-set, the edit made by the engine and proven by the map's
// hash, run only after the children's hides, and put back byte for byte by the revert.
$tFinal = $tSteps = [];
foreach ($snavMap['operations'] as $op) if ($op['op'] === 'templateStyle.megaSubRemove') { $tFinal[$op['key']] = $op['after']; $tSteps[$op['key']][] = $op['item']; }
check('the diner map removes one sub from each of its five T3 styles', [$tSteps, array_values($styles)],
    [array_fill_keys(['templateStyle-9', 'templateStyle-10', 'templateStyle-11', 'templateStyle-12', 'templateStyle-13'], [101]), [9, 10, 11, 12, 13]]);
$tSub = fn (string $params) => array_key_exists('sub', json_decode(json_decode($params, true)['mm_config'], true)['mainmenu']['item-101'] ?? []);
$tHome = fn (ContractTestWriter $w) => array_map(fn ($id) => $tSub((string) $w->store['templateStyle'][$id]['params']), $styles);
$tHash = fn (ContractTestWriter $w) => array_map(fn ($id) => hash('sha256', (string) $w->store['templateStyle'][$id]['params']), $styles);
$tParams = fn (ContractTestWriter $w) => array_map(fn ($id) => (string) $w->store['templateStyle'][$id]['params'], $styles);
$tProfile = function (callable $edit) use ($snavMap, $snavLoads) { $m = $snavMap; foreach ($m['operations'] as $n => $op) if ($op['op'] === 'templateStyle.megaSubRemove') { $m['operations'][$n] = $edit($op); break; } return $snavLoads($m); };
foreach ([
    'another style id' => fn ($op) => ['style' => ['id' => 10, 'template' => 'ja_diner']] + $op,
    'another template' => fn ($op) => ['style' => ['id' => 9, 'template' => 'ja_resort']] + $op,
    'another menutype' => fn ($op) => ['menutype' => 'joomla-pages'] + $op,
    'an item outside the contract' => fn ($op) => ['item' => 99999, 'path' => 'mm_config.mainmenu.item-99999.sub'] + $op,
    'an item the map does not empty' => fn ($op) => ['item' => 108, 'path' => 'mm_config.mainmenu.item-108.sub'] + $op,
    'another path' => fn ($op) => ['path' => 'mm_config.mainmenu.item-101.class'] + $op,
    'a before the lock does not hold' => fn ($op) => ['before' => str_repeat('a', 64)] + $op,
    'an after the edit does not reach' => fn ($op) => ['after' => str_repeat('a', 64)] + $op,
    'an extra field' => fn ($op) => $op + ['from' => '1'],
    'no reason' => fn ($op) => ['reason' => ''] + $op,
] as $what => $edit) check("a megaSubRemove with $what is refused", $tProfile($edit), 'refused');
$tTwice = $snavMap;
foreach ($snavMap['operations'] as $op) if ($op['key'] === 'templateStyle-9') $tTwice['operations'][] = ['before' => $op['after']] + $op;
check('a sub removed twice from one style is refused', $snavLoads($tTwice), 'refused');
$tNoChildren = $snavMap;
$tNoChildren['operations'] = array_values(array_filter($snavMap['operations'], fn ($op) => !in_array($op['key'], ['menuItem-145', 'menuItem-146', 'menuItem-147', 'menuItem-148'], true)));
check('a sub removed from an item whose children the map keeps is refused', $snavLoads($tNoChildren), 'refused');
// Every child of the item goes, not one: a map that leaves Home - Red in the menu may not drop the sub,
// unless the lock already has that child out of the menu.
$tOneKept = $snavMap;
$tOneKept['operations'] = array_values(array_filter($snavMap['operations'], fn ($op) => $op['key'] !== 'menuItem-145'));
check('a sub removed from an item one of whose shown children the map keeps is refused', $snavLoads($tOneKept), 'refused');
$tLoadsWith = function (callable $lockEdit) use ($tOneKept, $snavSource, $snavContent, $snavLock) {
    $lock = $snavLock;
    $lock['entities']['menuItem-145'] = $lockEdit($lock['entities']['menuItem-145']);
    try { new ShowcaseNavProfile($tOneKept, $snavContent, $lock, $snavSource, json_encode($tOneKept), 'ja-diner/j6/1.0.4'); return 'loaded'; }
    catch (RuntimeException $e) { return 'refused'; }
};
check('a child the lock has unpublished needs no hide', $tLoadsWith(fn ($row) => ['published' => '0'] + $row), 'loaded');
check('nor one the lock has out of the menu', $tLoadsWith(fn ($row) => ['params' => JsonObjectKey::set((string) $row['params'], 'menu_show', '"0"')] + $row), 'loaded');
check('one the lock shows with an unreadable menu_show still keeps the sub', $tLoadsWith(fn ($row) => ['params' => JsonObjectKey::set((string) $row['params'], 'menu_show', '"maybe"')] + $row), 'refused');

// The diner, applied and reverted.
[, $tStore, $tw, $tContract, $tCall] = snavSite($snavSource);
$tOriginal = $tParams($tw);
check('every diner style keeps a sub for Home before the apply', array_values($tHome($tw)), [true, true, true, true, true]);
$tWrites = [];
$tw->beforeCas = function (ContractTestWriter $writer, string $kind, int $id) use (&$tWrites) { $tWrites[] = $kind; };
$tApplied = $tCall(['operation' => 'showcaseNav.apply', 'apply_id' => 'snav-t', 'request_id' => 't1']);
$tw->beforeCas = null;
check('the apply removes the sub from every style', [$tApplied['status'], $tApplied['moved'], $tApplied['skipped'], array_values($tHome($tw))], ['completed', 20, [], [false, false, false, false, false]]);
check('each style holds exactly the bytes the map names', $tHash($tw), $tFinal);
check('the styles are written after every hide of their item', array_slice($tWrites, -5), array_fill(0, 5, 'templateStyle'));
foreach ($styles as $key => $id) {
    $was = json_decode($tOriginal[$key], true);
    $now = json_decode((string) $tw->store['templateStyle'][$id]['params'], true);
    $wasMm = json_decode($was['mm_config'], true);
    unset($wasMm['mainmenu']['item-101']['sub']);
    check("$key changes no other param", array_diff_key($now, ['mm_config' => 1]), array_diff_key($was, ['mm_config' => 1]));
    check("$key changes no other mega-menu setting", json_decode($now['mm_config'], true), $wasMm);
}
$tReceipt = $tStore->binding['showcaseNav']['receipts']['templateStyle-9'];
check('the style receipt holds the column found and the hash written', [$tReceipt['outcome'], $tReceipt['kind'], $tReceipt['before'], $tReceipt['after'], $tReceipt['items']],
    ['written', 'templateStyle', $tOriginal['templateStyle-9'], $tFinal['templateStyle-9'], ['menuItem-101']]);
snavClean('the site with the subs removed inspects clean', $tContract);
// Another param of a style moved since: reported, as before the apply.
$tBlue = $styles['templateStyle-12'];
$tw->store['templateStyle'][$tBlue]['params'] = JsonObjectKey::set((string) $tw->store['templateStyle'][$tBlue]['params'], 'devmode', '"1"');
check('another change to a style is still drift after the apply', snavDrifted($tContract), ['templateStyle-12']);
$tw->store['templateStyle'][$tBlue]['params'] = JsonObjectKey::set((string) $tw->store['templateStyle'][$tBlue]['params'], 'devmode', '"0"');
snavClean('and is clean once it is back', $tContract);
$tLost = $tCall(['operation' => 'showcaseNav.apply', 'apply_id' => 'snav-t', 'request_id' => 't1']);
check('the same apply after a lost answer writes nothing more', [$tLost['status'], $tHash($tw)], ['completed', $tFinal]);
// The customer edits one style after the apply, and puts the sub back by hand in another.
$tRed = $styles['templateStyle-10'];
$tPink = $styles['templateStyle-11'];
$tw->store['templateStyle'][$tRed]['params'] = JsonObjectKey::set((string) $tw->store['templateStyle'][$tRed]['params'], 'devmode', '"1"');
$tRedSaved = $tw->store['templateStyle'][$tRed]['params'];
$tw->store['templateStyle'][$tPink]['params'] = $tOriginal['templateStyle-11'];
$tWrites = [];
$tw->beforeCas = function (ContractTestWriter $writer, string $kind, int $id) use (&$tWrites) { if ($kind === 'templateStyle') $tWrites[] = $id; };
$tBack = $tCall(['operation' => 'showcaseNav.revert', 'apply_id' => 'snav-tu', 'request_id' => 't2']);
$tw->beforeCas = null;
check('the revert puts the subs back and leaves the edited style', [$tBack['status'], $tBack['moved'], $tBack['conflicts']],
    ['reverted', 19, ['templateStyle-10' => 'its params were changed since the mega-menu sub was removed']]);
check('it writes only the styles that held what the apply wrote', $tWrites, [9, 12, 13]);
check('every other style has exactly its bytes back', array_diff_key($tParams($tw), ['templateStyle-10' => 1]), array_diff_key($tOriginal, ['templateStyle-10' => 1]));
check("the customer's style is left as they saved it", $tw->store['templateStyle'][$tRed]['params'], $tRedSaved);
check('and is still drift: the baseline holds what the apply wrote', snavDrifted($tContract), ['templateStyle-10']);
// Put back by hand as the apply left it: a later revert takes it back by the same compare-and-set.
$tw->store['templateStyle'][$tRed]['params'] = JsonObjectKey::set($tRedSaved, 'devmode', '"0"');
$tAgain = $tCall(['operation' => 'showcaseNav.revert', 'apply_id' => 'snav-tu2', 'request_id' => 't3']);
check('a later revert recovers the style', [$tAgain['status'], $tAgain['moved'], $tAgain['conflicts'], $tParams($tw)], ['reverted', 1, [], $tOriginal]);
snavClean('the reverted styles inspect clean', $tContract);

// A style the customer edited before the apply is a conflict; the others go on.
[, $eStore, $ew, $eContract, $eCall] = snavSite($snavSource);
check('a bound site for the edited style', $eCall(['operation' => 'bind'])['ok'], true);
$ew->store['templateStyle'][$tRed]['params'] = JsonObjectKey::set((string) $ew->store['templateStyle'][$tRed]['params'], 'devmode', '"1"');
$eSaved = $ew->store['templateStyle'][$tRed]['params'];
$eApplied = $eCall(['operation' => 'showcaseNav.apply', 'apply_id' => 'snav-e', 'request_id' => 'e1']);
check('an edited style is skipped as a conflict', [$eApplied['status'], $eApplied['moved'], $eApplied['skipped']], ['completed', 19, ['templateStyle-10' => 'its params differ from the release']]);
check('and not written', [$ew->store['templateStyle'][$tRed]['params'], array_values(array_diff_key($tHome($ew), ['templateStyle-10' => 1]))], [$eSaved, [false, false, false, false]]);
check('it is still drift after the apply', snavDrifted($eContract), ['templateStyle-10']);
check('and after the revert', [$eCall(['operation' => 'showcaseNav.revert', 'apply_id' => 'snav-eu', 'request_id' => 'e2'])['moved'], snavDrifted($eContract)], [19, ['templateStyle-10']]);

// A style already as the map leaves it: nothing written, and — not this job's write — the baseline does not move.
[, , $aw, $aContract, $aCall] = snavSite($snavSource);
check('a bound site for the style already edited', $aCall(['operation' => 'bind'])['ok'], true);
$aAfter = T3MegaSub::remove((string) $aw->store['templateStyle'][$tRed]['params'], 'mainmenu', 101)['raw'];
$aw->store['templateStyle'][$tRed]['params'] = $aAfter;
$aApplied = $aCall(['operation' => 'showcaseNav.apply', 'apply_id' => 'snav-al', 'request_id' => 'al1']);
check('a style already without the sub is left', [$aApplied['moved'], $aApplied['skipped'], $aw->store['templateStyle'][$tRed]['params']], [19, ['templateStyle-10' => 'the mega-menu sub is already gone'], $aAfter]);
check('and stays drift', snavDrifted($aContract), ['templateStyle-10']);

// A child of Home still in the menu: its item keeps the dropdown, in every style.
[, $kStore2, $cw2, $cContract2, $cCall2] = snavSite($snavSource);
$cRed = $hidden['Home - Red'];
$cw2->store['menuItem'][$cRed]['params'] = JsonObjectKey::set((string) $cw2->store['menuItem'][$cRed]['params'], 'menu_show', '"maybe"');
$cBefore = $tParams($cw2);
$cApplied = $cCall2(['operation' => 'showcaseNav.apply', 'apply_id' => 'snav-ch', 'request_id' => 'ch1']);
$cWhy = 'kept the mega-menu sub of menuItem-101: its child menuItem-' . $cRed . ' stays in the menu (menu_show is "maybe", not shown)';
check('a child left in the menu keeps its item\'s sub, and says why', [$cApplied['status'], $cApplied['moved'], $cApplied['skipped']],
    ['completed', 14, ['menuItem-' . $cRed => 'menu_show is "maybe", not shown'] + array_fill_keys(array_keys($styles), $cWhy)]);
check('no style is written', $tParams($cw2), $cBefore);
check('the skipped styles have nothing to revert', [$cCall2(['operation' => 'showcaseNav.revert', 'apply_id' => 'snav-chu', 'request_id' => 'ch2'])['moved'], $tParams($cw2)], [14, $cBefore]);

// 🔒 THE LIVE MENU HAS THE LAST WORD: a page the customer added under Home after the install is a child
// the map never saw. Shown, it keeps the dropdown in every style; out of the menu, the sub goes.
$tAdded = function (string $params, string $published = '1', array $more = []) use ($snavSource, $cRed) {
    [, , $w, $c, $call] = snavSite($snavSource);
    $w->store['menuItem'][9001] = ['id' => 9001, 'title' => 'Our own home', 'alias' => 'our-own-home', 'path' => 'home/our-own-home',
        'published' => $published, 'parent_id' => 101, 'params' => $params] + $more + $w->store['menuItem'][$cRed];
    $c->bind($c->inspect()['snapshot']);
    return [$w, $call];
};
[$lw, $lCall] = $tAdded('{"menu_show":"1"}');
$lBefore = $tParams($lw);
$lApplied = $lCall(['operation' => 'showcaseNav.apply', 'apply_id' => 'snav-lv', 'request_id' => 'lv1']);
check('a shown child the customer added under Home keeps its sub in every style, and says why', [$lApplied['status'], $lApplied['moved'], $lApplied['skipped']],
    ['completed', 15, array_fill_keys(array_keys($styles), 'kept the mega-menu sub of menuItem-101: its child menu item 9001 is still shown on this site')]);
check('and no style is written', $tParams($lw), $lBefore);
// A child another connection committed after this batch's snapshot: only the locking read, in the batch's
// own transaction, sees it — and seeing it keeps the sub.
[, , $lw, , $lCall] = snavSite($snavSource);
$lBefore = $tParams($lw);
$lw->lateChildren = fn (int $parent) => $parent === 101 ? [['id' => 9002, 'parent_id' => 101, 'menutype' => 'mainmenu', 'language' => '*', 'published' => '1', 'params' => '{}']] : [];
$lApplied = $lCall(['operation' => 'showcaseNav.apply', 'apply_id' => 'snav-lt', 'request_id' => 'lt1']);
check('a shown child committed after the snapshot is seen by the locking read, and keeps the sub', [$lApplied['moved'], $tParams($lw), array_unique($lw->lockedParents), array_unique($lw->lockedOpen)],
    [15, $lBefore, [101], [true]]);
// With innodb_snapshot_isolation ON, MariaDB refuses that locking read (error 1020): the batch rolls back
// whole, and the same request run again completes.
[, , $lw, , $lCall] = snavSite($snavSource);
$lBefore = $tParams($lw);
$lMenu = array_map(fn ($row) => $row['params'] ?? null, $lw->store['menuItem']);
$lw->lateChildren = function () use ($lw) { $lw->lateChildren = null; throw new RuntimeException('Record has changed since last read in table'); };
$lFailed = $lCall(['operation' => 'showcaseNav.apply', 'apply_id' => 'snav-lr', 'request_id' => 'lr1']);
check('a refused locking read rolls the batch back: no menu item, no style written', [$lFailed['ok'], $tParams($lw), array_map(fn ($row) => $row['params'] ?? null, $lw->store['menuItem'])], [false, $lBefore, $lMenu]);
$lApplied = $lCall(['operation' => 'showcaseNav.apply', 'apply_id' => 'snav-lr', 'request_id' => 'lr1']);
check('and the same request run again completes', [$lApplied['status'], $lApplied['moved'], array_values($tHash($lw))], ['completed', 20, array_values($tFinal)]);
foreach (['another language' => ['language' => 'fr-FR'], 'a row naming another menu' => ['menutype' => 'joomla-pages']] as $what => $more) {
    [$lw, $lCall] = $tAdded('{}', '1', $more);
    $lBefore = $tParams($lw);
    $lApplied = $lCall(['operation' => 'showcaseNav.apply', 'apply_id' => 'snav-lo', 'request_id' => 'lo1']);
    check("a shown child in $what keeps the sub too", [$lApplied['moved'], $tParams($lw)], [15, $lBefore]);
}
foreach (['{"menu_show":"0"}' => ['1', 'out of the menu'], '{}' => ['0', 'unpublished']] as $params => [$published, $what]) {
    [$lw, $lCall] = $tAdded($params, $published);
    $lApplied = $lCall(['operation' => 'showcaseNav.apply', 'apply_id' => 'snav-lh', 'request_id' => 'lh1']);
    check("a child the customer added $what lets every sub go", [$lApplied['status'], $lApplied['moved'], $lApplied['skipped'], array_values($tHash($lw))],
        ['completed', 20, [], array_values($tFinal)]);
}

// Listed first in the map, the styles still wait for the hides of their item's children.
[, , $ow, , $oCall] = snavSite($snavSource, fn ($dir) => snavRewrite($dir, function ($m) {
    usort($m['operations'], fn ($a, $b) => ($b['op'] === 'templateStyle.megaSubRemove') <=> ($a['op'] === 'templateStyle.megaSubRemove'));
    return $m;
}));
$oWrites = [];
$ow->beforeCas = function (ContractTestWriter $writer, string $kind, int $id) use (&$oWrites) { $oWrites[] = $kind; };
check('styles listed first are still applied', $oCall(['operation' => 'showcaseNav.apply', 'apply_id' => 'snav-o', 'request_id' => 'o1'])['moved'], 20);
check('after every hide', array_slice($oWrites, -5), array_fill(0, 5, 'templateStyle'));

// A crash on a style rolls the batch back; the same request resumes it. A save between the read and the write is a conflict.
[, $xStore, $xw, $xContract, $xCall] = snavSite($snavSource);
$xw->failOn = ['templateStyle', $tPink];
check('a style that fails fails the batch', $xCall(['operation' => 'showcaseNav.apply', 'apply_id' => 'snav-x', 'request_id' => 'x1'])['ok'], false);
check('and nothing is written', [$tParams($xw), $xStore->binding['showcaseNav']['receipts']], [$tOriginal, []]);
$xw->failOn = null;
$xRaced = JsonObjectKey::set((string) $xw->store['templateStyle'][$tPink]['params'], 'devmode', '"0"');
$xw->beforeCas = function (ContractTestWriter $writer, string $kind, int $id) use ($tPink, $xRaced) { if ($kind === 'templateStyle' && $id === $tPink) $writer->store['templateStyle'][$id]['params'] = $xRaced . ' '; };
$xDone = $xCall(['operation' => 'showcaseNav.apply', 'apply_id' => 'snav-x', 'request_id' => 'x1']);
$xw->beforeCas = null;
check('the retried apply completes, the raced style a conflict', [$xDone['status'], $xDone['moved'], $xDone['skipped'], $xw->store['templateStyle'][$tPink]['params']],
    ['completed', 19, ['templateStyle-11' => 'it changed while it was being written'], $xRaced . ' ']);
// ------------------------------------------------------------------ a T3 mega-menu column whose items all leave

// T3MegaSub::removeColumn on its own: every entry of the column goes, a row it empties goes with it, a
// row that was already empty stays, a module column naming the same id is not that column.
$cCol = fn (array $rows) => json_encode(['mm_config' => json_encode(['mainmenu' => ['item-7' => ['sub' => ['rows' => $rows]]]])]);
$cRows = fn (array $edit) => isset($edit['raw']) ? json_decode(json_decode($edit['raw'], true)['mm_config'], true)['mainmenu']['item-7']['sub']['rows'] : $edit;
check('removeColumn drops the column in every row, and a row it empties',
    $cRows(T3MegaSub::removeColumn($cCol([[['item' => 8, 'width' => 6], ['item' => '9']], [['item' => 8]], [], [['position' => 8, 'item' => 8]]]), 'mainmenu', 7, 8)),
    [[['item' => '9']], [], [['position' => 8, 'item' => 8]]]);
check('a column the sub does not hold is absent', T3MegaSub::removeColumn($cCol([[['item' => 9]]]), 'mainmenu', 7, 8), ['absent' => true]);
check('nor is a boolean or a float an item: nothing is edited', [isset(T3MegaSub::removeColumn($cCol([[['item' => true]]]), 'mainmenu', 7, 1)['raw']), isset(T3MegaSub::removeColumn(str_replace(':8}', ':8.0}', $cCol([[['item' => 8]]])), 'mainmenu', 7, 8)['raw'])], [false, false]);
$cSlashes = '{"a":"x\/y","mm_config":"{\"mainmenu\":{\"item-7\":{\"sub\":{\"rows\":[[{\"item\":8,\"class\":\"a\\\\\/b\"},{\"item\":9}]]}}}}","z":1}';
check('the column is re-encoded the way it was stored, every other byte kept', T3MegaSub::removeColumn($cSlashes, 'mainmenu', 7, 8)['raw'] ?? null,
    '{"a":"x\/y","mm_config":"{\"mainmenu\":{\"item-7\":{\"sub\":{\"rows\":[[{\"item\":9}]]}}}}","z":1}');

// 🔒 ja-space 1.0.7: the map hides "J! Pages" (109), the head of the first column of "Pages" (108),
// while "Other Pages" and "Joomla Content" stay. T3 would start the first column at Other Pages and run
// it to the end, listing both columns twice: each style drops the 109 column and nothing else.
$spSource = __DIR__ . '/../lib/contracts/ja-space/j6/1.0.7';
$spMap = json_decode(file_get_contents($spSource . '/showcase-nav-map.json'), true, 512, JSON_THROW_ON_ERROR);
$spContent = json_decode(file_get_contents($spSource . '/content-map.json'), true, 512, JSON_THROW_ON_ERROR);
$spLock = json_decode(file_get_contents($spSource . '/presentation-lock.json'), true, 512, JSON_THROW_ON_ERROR);
$spLoads = function (array $profile, ?array $lock = null) use ($spSource, $spContent, $spLock) {
    try { new ShowcaseNavProfile($profile, $spContent, $lock ?? $spLock, $spSource, json_encode($profile), 'ja-space/j6/1.0.7'); return 'loaded'; }
    catch (RuntimeException $e) { return 'refused'; }
};
$spOps = array_values(array_filter($spMap['operations'], fn ($op) => $op['op'] === 'templateStyle.megaColumnRemove'));
check('the space map removes column 109 of Pages from both T3 styles', [$spLoads($spMap), array_map(fn ($op) => [$op['key'], $op['item'], $op['column']], $spOps)],
    ['loaded', [['templateStyle-9', 108, 109], ['templateStyle-10', 108, 109]]]);
$spEdit = function (callable $edit) use ($spMap) { $m = $spMap; foreach ($m['operations'] as $n => $op) if ($op['op'] === 'templateStyle.megaColumnRemove') { $m['operations'][$n] = $edit($op); break; } return $m; };
foreach ([
    'a column the sub does not hold' => fn ($op) => ['column' => 114, 'path' => 'mm_config.mainmenu.item-108.sub.rows[item=114]'] + $op,
    'a head that is no child of the item' => fn ($op) => ['column' => 101, 'path' => 'mm_config.mainmenu.item-108.sub.rows[item=101]'] + $op,
    'no column' => function ($op) { unset($op['column']); return $op; },
    'a string column' => fn ($op) => ['column' => '109'] + $op,
    'the sub path' => fn ($op) => ['path' => 'mm_config.mainmenu.item-108.sub'] + $op,
    'an after the edit does not reach' => fn ($op) => ['after' => str_repeat('a', 64)] + $op,
    'the sub op name' => fn ($op) => ['op' => 'templateStyle.megaSubRemove'] + $op,
] as $what => $edit) check("a megaColumnRemove with $what is refused", $spLoads($spEdit($edit)), 'refused');
// The head stays in the menu (the map no longer hides it): the column is not empty, the profile is refused.
$spKept = $spMap;
$spKept['operations'] = array_values(array_filter($spMap['operations'], fn ($op) => $op['key'] !== 'menuItem-109'));
check('a column whose head the map keeps shown is refused', $spLoads($spKept), 'refused');
$spOff = $spLock;
$spOff['entities']['menuItem-109']['published'] = '0';
check('unless the lock already has it out of the menu', $spLoads($spKept, $spOff), 'loaded');
// A shown child that heads no column: the lock holds no menu order, so it might sit in the column.
$spStray = $spLock;
foreach (['templateStyle-9', 'templateStyle-10'] as $k) {
    $outer = json_decode($spStray['entities'][$k]['params'], true);
    $mm = json_decode($outer['mm_config'], true);
    $mm['mainmenu']['item-108']['sub']['rows'][0] = array_values(array_filter($mm['mainmenu']['item-108']['sub']['rows'][0], fn ($c) => $c['item'] !== 121));
    $outer['mm_config'] = json_encode($mm);
    $spStray['entities'][$k]['params'] = json_encode($outer);
}
$spStrayMap = $spMap;
foreach ($spStrayMap['operations'] as &$op) if ($op['op'] === 'templateStyle.megaColumnRemove') {
    $op['before'] = hash('sha256', $spStray['entities'][$op['key']]['params']);
    $op['after'] = hash('sha256', T3MegaSub::removeColumn($spStray['entities'][$op['key']]['params'], 'mainmenu', 108, 109)['raw']);
}
unset($op);
check('a shown child that heads no column of the sub refuses the column', $spLoads($spStrayMap, $spStray), 'refused');

$spSub = fn (string $params) => json_decode(json_decode($params, true)['mm_config'], true)['mainmenu']['item-108']['sub'];
[, $spStore, $spw, $spContract, $spCall] = snavSite($spSource);
$spStyles = [];
foreach ($spContent['entities'] as $e) if ($e['kind'] === 'templateStyle' && in_array($e['key'], ['templateStyle-9', 'templateStyle-10'], true)) $spStyles[$e['key']] = (int) $e['sourceId'];
$spParams = fn (ContractTestWriter $w) => array_map(fn ($id) => (string) $w->store['templateStyle'][$id]['params'], $spStyles);
$spOriginal = $spParams($spw);
$spAfter = array_column($spOps, 'after', 'key');
$spApplied = $spCall(['operation' => 'showcaseNav.apply', 'apply_id' => 'snav-sp', 'request_id' => 'sp1']);
check('the apply removes the column from both styles', [$spApplied['status'], $spApplied['skipped'], array_map(fn ($p) => array_column($spSub($p)['rows'][0], 'item'), $spParams($spw))],
    ['completed', [], ['templateStyle-9' => [114, 121], 'templateStyle-10' => [114, 121]]]);
check('each style holds exactly the bytes the map names', array_map(fn ($p) => hash('sha256', $p), $spParams($spw)), $spAfter);
foreach ($spOriginal as $k => $was) {
    $wasMm = json_decode(json_decode($was, true)['mm_config'], true);
    $wasMm['mainmenu']['item-108']['sub']['rows'][0] = array_values(array_filter($wasMm['mainmenu']['item-108']['sub']['rows'][0], fn ($c) => $c['item'] !== 109));
    check("$k changes nothing else", [array_diff_key(json_decode($spParams($spw)[$k], true), ['mm_config' => 1]), json_decode(json_decode($spParams($spw)[$k], true)['mm_config'], true)],
        [array_diff_key(json_decode($was, true), ['mm_config' => 1]), $wasMm]);
}
check('its receipt names the column path', $spStore->binding['showcaseNav']['receipts']['templateStyle-9']['paths'], ['mm_config.mainmenu.item-108.sub.rows[item=109]']);
snavClean('the site with the column removed inspects clean', $spContract);
$spLost = $spCall(['operation' => 'showcaseNav.apply', 'apply_id' => 'snav-sp', 'request_id' => 'sp1']);
check('the same apply after a lost answer writes nothing more', [$spLost['status'], $spLost['alreadyApplied'] ?? null, array_map(fn ($p) => hash('sha256', $p), $spParams($spw))], ['completed', true, $spAfter]);
$spBack = $spCall(['operation' => 'showcaseNav.revert', 'apply_id' => 'snav-spu', 'request_id' => 'sp2']);
check('the revert puts every byte of both styles back', [$spBack['status'], $spBack['conflicts'], $spParams($spw)], ['reverted', [], $spOriginal]);
snavClean('the reverted styles inspect clean', $spContract);

// 🔒 THE LIVE COLUMN, IN MENU ORDER. A child the customer added under Pages sits in a column by its lft:
// between J! Pages and Other Pages it is in the 109 column, and shown it keeps that column; after Other
// Pages it is in a column that stays, and the 109 column goes.
$spAdded = function (int $lft, string $show) use ($spSource) {
    [, , $w, $c, $call] = snavSite($spSource);
    foreach ([109 => 10, 114 => 20, 121 => 30] as $id => $at) $w->store['menuItem'][$id]['lft'] = $at;
    $w->store['menuItem'][9101] = ['id' => 9101, 'title' => 'Our page', 'alias' => 'our-page', 'path' => 'pages/our-page', 'lft' => $lft,
        'params' => '{"menu_show":"' . $show . '"}'] + $w->store['menuItem'][114];
    $c->bind($c->inspect()['snapshot']);
    return [$w, $call];
};
[$saw, $saCall] = $spAdded(15, '1');
$saBefore = $spParams($saw);
$saApplied = $saCall(['operation' => 'showcaseNav.apply', 'apply_id' => 'snav-sa', 'request_id' => 'sa1']);
check('a shown child in the column keeps it in every style, and says why', [$saApplied['status'], $saApplied['skipped'], $spParams($saw)],
    ['completed', array_fill_keys(['templateStyle-9', 'templateStyle-10'], 'kept the mega-menu column 109 of menuItem-108: menu item 9101 in that column is still shown on this site'), $saBefore]);
[$saw, $saCall] = $spAdded(15, '0');
check('out of the menu, it lets the column go', [$saCall(['operation' => 'showcaseNav.apply', 'apply_id' => 'snav-sa', 'request_id' => 'sa1'])['skipped'], array_map(fn ($p) => hash('sha256', $p), $spParams($saw))], [[], $spAfter]);
[$saw, $saCall] = $spAdded(25, '1');
check('a shown child in a column that stays lets it go too', [$saCall(['operation' => 'showcaseNav.apply', 'apply_id' => 'snav-sa', 'request_id' => 'sa1'])['skipped'], array_map(fn ($p) => hash('sha256', $p), $spParams($saw))], [[], $spAfter]);
[$saw, $saCall] = $spAdded(5, '1');
check('before the head of the first column it is in that column', $saCall(['operation' => 'showcaseNav.apply', 'apply_id' => 'snav-sa', 'request_id' => 'sa1'])['skipped']['templateStyle-9'] ?? null,
    'kept the mega-menu column 109 of menuItem-108: menu item 9101 in that column is still shown on this site');
// The head's own hide a conflict: the column keeps it, and says so.
[, , $shw, , $shCall] = snavSite($spSource);
$shw->store['menuItem'][109]['params'] = JsonObjectKey::set((string) $shw->store['menuItem'][109]['params'], 'menu_show', '"maybe"');
check('a head left in the menu keeps its column', $shCall(['operation' => 'showcaseNav.apply', 'apply_id' => 'snav-sh', 'request_id' => 'sh1'])['skipped']['templateStyle-9'] ?? null,
    'kept the mega-menu column 109 of menuItem-108: its head menuItem-109 stays in the menu (menu_show is "maybe", not shown)');

// ja-smallbiz 1.0.4: one style chains a whole sub (Home, 101, every child gone) and a column (Pages, 103,
// its 104 column). The sub is a megaSubRemove — no column path — and the column starts from its bytes.
$sbSource = __DIR__ . '/../lib/contracts/ja-smallbiz/j6/1.0.4';
$sbMap = json_decode(file_get_contents($sbSource . '/showcase-nav-map.json'), true, 512, JSON_THROW_ON_ERROR);
$sbChain = [];
foreach ($sbMap['operations'] as $op) if ($op['key'] === 'templateStyle-10') $sbChain[] = [$op['op'], $op['path']];
check('smallbiz chains a sub and a column in one style', $sbChain,
    [['templateStyle.megaSubRemove', 'mm_config.mainmenu.item-101.sub'], ['templateStyle.megaColumnRemove', 'mm_config.mainmenu.item-103.sub.rows[item=104]']]);
[, $sbStore, $sbw, $sbContract, $sbCall] = snavSite($sbSource);
$sbIds = [];
foreach (json_decode(file_get_contents($sbSource . '/content-map.json'), true)['entities'] as $e) if ($e['kind'] === 'templateStyle') $sbIds[$e['key']] = (int) $e['sourceId'];
$sbFinal = [];
foreach ($sbMap['operations'] as $op) if (str_starts_with($op['op'], 'templateStyle.')) $sbFinal[$op['key']] = $op['after'];
$sbOriginal = array_map(fn ($k) => (string) $sbw->store['templateStyle'][$sbIds[$k]]['params'], array_combine(array_keys($sbFinal), array_keys($sbFinal)));
$sbApplied = $sbCall(['operation' => 'showcaseNav.apply', 'apply_id' => 'snav-sb', 'request_id' => 'sb1']);
$sbNow = fn () => array_map(fn ($k) => (string) $sbw->store['templateStyle'][$sbIds[$k]]['params'], array_combine(array_keys($sbFinal), array_keys($sbFinal)));
check('smallbiz applies both steps of every style', [$sbApplied['status'], $sbApplied['skipped'], array_map(fn ($p) => hash('sha256', $p), $sbNow())], ['completed', [], $sbFinal]);
check('Home has no sub left, Pages keeps its sub without the 104 column', array_map(function ($p) {
    $mm = json_decode(json_decode($p, true)['mm_config'], true)['mainmenu'];
    return [array_key_exists('sub', $mm['item-101']), in_array(104, array_column(array_merge(...$mm['item-103']['sub']['rows']), 'item'), false), isset($mm['item-103']['sub'])];
}, array_values($sbNow())), array_fill(0, count($sbFinal), [false, false, true]));
snavClean('smallbiz inspects clean after the apply', $sbContract);
check('and reverts every byte', [$sbCall(['operation' => 'showcaseNav.revert', 'apply_id' => 'snav-sbu', 'request_id' => 'sb2'])['conflicts'], $sbNow()], [[], $sbOriginal]);

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
