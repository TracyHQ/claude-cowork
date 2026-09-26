<?php
/**
 * `lockedBy`: who has a post open in the WordPress editor, listed by `content.read` and refused by
 * every write that would land under them — the contract's `apply` and the open native writes.
 *
 * The rule is core's `wp_check_post_lock()`: `_edit_lock` = "<time>:<user>", live strictly before
 * time + `wp_check_post_lock_window` (150 s), held by a user that exists. The reader side runs the
 * REAL reader over `WP_Fake_ContentDb`; the write side runs the REAL site writer over the WordPress
 * stubs. Loaded by run.php after content-revisions.php (uses `$revSite`, `$rdoor`, `$listed`,
 * `$idOf`, `WP_Fake_ContentDb`).
 */
declare(strict_types=1);

echo "\nEditor locks (lockedBy)\n";

// ── the rule, pure ──────────────────────────────────────────────────────────────────────────

check('a fresh lock is held by its user, until time + window', EditLock::holder('1000:3', '', 1100, 150), ['user' => 3, 'since' => 1000, 'until' => 1150]);
check('one second before the window closes it is still live', EditLock::holder('1000:3', '', 1149, 150)['user'] ?? null, 3);
check('at time + window it is over (core: time > now - window)', EditLock::holder('1000:3', '', 1150, 150), null);
check('a narrower window ends it sooner', EditLock::holder('1000:3', '', 1100, 60), null);
check('no lock is no lock', [EditLock::holder('', '', 1100, 150), EditLock::holder(null, '', 1100, 150)], [null, null]);
check('a malformed stamp is no lock', [EditLock::holder('abc:3', '', 1100, 150), EditLock::holder('0:3', '', 1100, 150), EditLock::holder('1000:x', '', 1100, 150)], [null, null, null]);
check('a stamp without a user falls back to _edit_last, as core does', EditLock::holder('1000', '4', 1100, 150)['user'] ?? null, 4);
check('and without either it is no lock', EditLock::holder('1000', '', 1100, 150), null);
check('lockedBy shape', EditLock::lockedBy(['user' => 3, 'since' => 1000, 'until' => 1150], 'Ada Editor'),
    ['kind' => 'admin-user', 'name' => 'Ada Editor', 'since' => '1970-01-01T00:16:40Z', 'until' => '1970-01-01T00:19:10Z']);
check('an empty display name is null, not invented', EditLock::lockedBy(['user' => 3, 'since' => 1000, 'until' => 1150], '')['name'], null);
check('the message says who to ask and what to ask', EditLock::message('Home', ['name' => 'Ada Editor']),
    '"Home" is open in the WordPress editor by Ada Editor: ask them to save and close it, then try again.');
check('the window is core\'s default', EditLock::window(), 150);
WP_Fake::$filters[EditLock::FILTER] = static fn($w) => 300;
check('and follows wp_check_post_lock_window', EditLock::window(), 300);
WP_Fake::$filters = [];

// ── content.read lists it; revisions do not move ────────────────────────────────────────────

$previousLockDb = $GLOBALS['wpdb'];
$GLOBALS['wpdb'] = new WP_Fake_ContentDb();
$lockAt = static function (int $post, int $age, int $user = 3): void {
    WP_Fake::$meta[$post . ':' . EditLock::META] = (time() - $age) . ':' . $user;
};
$lockedRows = static function () use ($FIXTURES): array {
    $source = new Claude_Cowork_Content_Source('editorial', $FIXTURES, 'test');
    $out = [];
    foreach ($source->summaries() as $summary) {
        $out[$summary['id']] = array_key_exists('lockedBy', $summary) ? $summary['lockedBy'] : 'missing';
    }
    $source->release();
    return $out;
};
$snapshot = static function () use ($FIXTURES): string {
    $source = new Claude_Cowork_Content_Source('editorial', $FIXTURES, 'test');
    $revision = $source->revision();
    $source->release();
    return $revision;
};

$L = $revSite();
WP_Fake::$users[3] = ['display_name' => 'Ada Editor'];
$home = $idOf('post', 10);
$header = $idOf('templatePart', 70, 'header');
$unlockedRevs = $listed();
$unlockedSnapshot = $snapshot();
check('with no lock every content lists lockedBy null', array_unique(array_values($lockedRows())), [null]);

$lockAt(10, 10);
$rows = $lockedRows();
check('a live lock on the home page is listed on it', is_array($rows[$home] ?? null) ? array_keys($rows[$home]) : $rows[$home] ?? null, ['kind', 'name', 'since', 'until']);
check('as the admin user holding it, by display name', [$rows[$home]['kind'], $rows[$home]['name']], ['admin-user', 'Ada Editor']);
$since = strtotime($rows[$home]['since']);
check('since/until are ISO 8601 UTC, until = since + 150', [preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/D', $rows[$home]['until']), strtotime($rows[$home]['until']) - $since], [1, 150]);
check('only the locked content carries it', count(array_filter($rows)), 1);
check('a lock does not move any revision', $listed(), $unlockedRevs);
check('nor the snapshot revision a cursor holds', $snapshot(), $unlockedSnapshot);
$lockAt(10, 3);
check('a heartbeat does not move them either', [$listed(), $snapshot()], [$unlockedRevs, $unlockedSnapshot]);
// Through the reader's envelope (a listing: a detail needs parse_blocks, which the stubs lack; a
// detail starts from the same summary row, so it carries the same value).
$listing = (new ContentReader(new Claude_Cowork_Content_Source('editorial', $FIXTURES, 'test'), str_repeat('k', 32), 'site', 'editorial', time()))->read([]);
$byId = array_column($listing['contents'], 'lockedBy', 'id');
check('content.read hands it out as it is', $byId[$home]['name'] ?? null, 'Ada Editor');

$lockAt(10, 150);
check('a lock 150 s old is expired', $lockedRows()[$home], null);
WP_Fake::$filters[EditLock::FILTER] = static fn($w) => 300;
check('unless the site widened the window', $lockedRows()[$home]['name'] ?? null, 'Ada Editor');
WP_Fake::$filters = [];
$lockAt(10, 10, 99);
check('a lock held by a user that no longer exists is no lock (core)', $lockedRows()[$home], null);
$lockAt(70, 10);
check('a database template part is locked like a post', $lockedRows()[$header]['name'] ?? null, 'Ada Editor');
unset(WP_Fake::$meta['70:' . EditLock::META]);

// ── content.contract apply refuses it, atomically ───────────────────────────────────────────

$L = $revSite();
WP_Fake::$users[3] = ['display_name' => 'Ada Editor'];
$home = $idOf('post', 10);
$pageBefore = WP_Fake::$posts[10]['post_content'];
$nameBefore = WP_Fake::$options['blogname'];
$lockAt(10, 10);
$refused = $rdoor($L['E'], 'apply', ['expected_revision' => $L['bound']['revision'], 'apply_id' => 'contract-l1', 'request_id' => 'l1',
    'changes' => ['home.hero.eyebrow' => 'Since 1999', 'site.name' => 'Acme']]);
check('an apply onto a post open in the editor is refused', [$refused['ok'], $refused['error']], [false, 'contract_failed']);
check('as SLOT_LOCKED_BY_USER, recoverable, naming the slot and the content', array_map(static fn($e) => [$e['code'], $e['severity'], $e['field']], $refused['errors']),
    [['SLOT_LOCKED_BY_USER', 'recoverable', ['slotKey' => 'home.hero.eyebrow', 'contentId' => $home]]]);
check('with lockedBy in the content.read shape', [$refused['errors'][0]['lockedBy']['kind'] ?? null, $refused['errors'][0]['lockedBy']['name'] ?? null], ['admin-user', 'Ada Editor']);
check('and a message a person can act on', $refused['errors'][0]['message'],
    '"Home" is open in the WordPress editor by Ada Editor: ask them to save and close it, then try again.');
check('nothing was written: not the page, not the option beside it', [WP_Fake::$posts[10]['post_content'], WP_Fake::$options['blogname']], [$pageBefore, $nameBefore]);
check('no log left behind', $L['log']->entries('contract-l1'), []);

$revs = $listed();
$held = $rdoor($L['E'], 'apply', ['expected_content_revisions' => [$home => $revs[$home]], 'apply_id' => 'contract-l2', 'request_id' => 'l2',
    'changes' => ['home.hero.eyebrow' => 'Since 1999']]);
check('held by content revisions, a locked page is refused as locked, not as stale', array_column($held['errors'], 'code'), ['SLOT_LOCKED_BY_USER']);
check('other slots of an unlocked row are not blamed', $rdoor($L['E'], 'apply', ['expected_revision' => $L['bound']['revision'], 'apply_id' => 'contract-l3', 'request_id' => 'l3',
    'changes' => ['site.name' => 'Acme']])['ok'], true);

$lockAt(10, 200);
$L2 = $rdoor($L['E'], 'apply', ['expected_revision' => $rdoor($L['E'], 'inspect')['revision'], 'apply_id' => 'contract-l4', 'request_id' => 'l4',
    'changes' => ['home.hero.eyebrow' => 'Since 1999']]);
check('once the lock expires the same apply lands', $L2['ok'], true);
check('and wrote the page', QuickstartContract::getBlockValue(WP_Fake::$posts[10]['post_content'], 'hero.eyebrow', 'content'), 'Since 1999');

// ── the open native writes refuse it too ────────────────────────────────────────────────────

$L = $revSite();
WP_Fake::$users[3] = ['display_name' => 'Ada Editor'];
$wcall = static function (Engine $engine, string $action, array $params): array {
    return $engine->handle(['token' => $GLOBALS['WTOKEN'], 'action' => $action, 'params' => $params]);
};
$landed = $wcall($L['E'], 'content.update', ['apply_id' => 'open-l0', 'kind' => 'post', 'id' => 10, 'fields' => ['post_title' => 'Welcome']]);
check('an unlocked post takes content.update as before', [$landed['ok'], WP_Fake::$posts[10]['post_title']], [true, 'Welcome']);
$lockAt(10, 10);
$open = $wcall($L['E'], 'content.update', ['apply_id' => 'open-l1', 'kind' => 'post', 'id' => 10, 'fields' => ['post_title' => 'Hello']]);
check('content.update on a locked post keeps its error shape', [$open['ok'], $open['error']], [false, 'locked']);
check('with the code and lockedBy', [$open['code'] ?? null, $open['lockedBy']['name'] ?? null], ['SLOT_LOCKED_BY_USER', 'Ada Editor']);
check('and the same sentence', $open['message'], '"Welcome" is open in the WordPress editor by Ada Editor: ask them to save and close it, then try again.');
check('nothing written, nothing logged', [WP_Fake::$posts[10]['post_title'], $L['log']->entries('open-l1')], ['Welcome', []]);
check('a meta of the locked post is refused too', $wcall($L['E'], 'content.update', ['apply_id' => 'open-l2', 'kind' => 'postmeta', 'id' => 10, 'key' => 'tracy_page_heading', 'fields' => ['value' => 'x']])['code'] ?? null, 'SLOT_LOCKED_BY_USER');
check('content.delete of the locked post is refused', [$wcall($L['E'], 'content.delete', ['apply_id' => 'open-l3', 'kind' => 'post', 'id' => 10])['code'] ?? null, WP_Fake::$posts[10]['post_status']], ['SLOT_LOCKED_BY_USER', 'publish']);
check('apply.revert onto the locked post is refused, whole', [$wcall($L['E'], 'apply.revert', ['apply_id' => 'open-l0'])['code'] ?? null, WP_Fake::$posts[10]['post_title']], ['SLOT_LOCKED_BY_USER', 'Welcome']);
check('another post still takes content.update', $wcall($L['E'], 'content.update', ['apply_id' => 'open-l4', 'kind' => 'post', 'id' => 26, 'fields' => ['post_title' => 'Other']])['ok'], true);
$lockAt(70, 10);
check('a database template part open in the Site Editor is refused', $wcall($L['E'], 'content.update', ['apply_id' => 'open-l5', 'kind' => 'templatePart', 'key' => 'header', 'fields' => ['content' => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->']])['code'] ?? null, 'SLOT_LOCKED_BY_USER');
$lockAt(10, 150);
check('an expired lock lets content.update through', $wcall($L['E'], 'content.update', ['apply_id' => 'open-l6', 'kind' => 'post', 'id' => 10, 'fields' => ['post_title' => 'Hello']])['ok'], true);

$GLOBALS['wpdb'] = $previousLockDb;
