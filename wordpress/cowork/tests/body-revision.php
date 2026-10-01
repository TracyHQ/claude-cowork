<?php
/**
 * A body written over what was read: `body_revision` on `content.get` and `content.update`, and the
 * `expected_body_revision` condition a write may carry.
 *
 * Two agents (or an agent and a person in the Site Editor) can read the same page, each change it,
 * and each write the whole body back: the second write silently erases the first. A caller that
 * names the body it read (`expected_body_revision`, the `body_revision` content.get answered) is
 * refused instead (`revision_stale`, with the revision the site holds now), and nothing is written.
 *
 * The expected revisions below are sha256 values computed outside PHP (`printf '%s' … | shasum -a 256`),
 * so a test cannot agree with the code by computing the value the same way.
 *
 * Runs the REAL site writer over the WordPress stubs. Loaded by run.php (uses `$WTOKEN`).
 */
declare(strict_types=1);

echo "\nBody revisions: content.get answers one, content.update can be held to it\n";

/** sha256 of the bodies these tests write, computed outside PHP. */
const BR_HELLO = 'd0a26d23e9d8e0538fd47e7bc502d26cf6c320e8daaec7c8521d4769530f5900';          // <p>Hello</p>
const BR_HELLO_AGAIN = '7458f3c96e945ca0b5adc81e4897242e24193fd050165be1c27236fb6ab135cc';    // <p>Hello again</p>
const BR_SITE_TITLE = 'a4593dae6586758550b077d641134c89f33eb0adebc456dcfef9d45a2b954146';     // <!-- wp:site-title /-->
const BR_SITE_LOGO = 'c270e4d0f3d509e86472eec11af6862611a18704d580b82c8872ea579902bb2a';      // <!-- wp:site-logo /-->
const BR_HOME = 'cd64cb8ca2420b12bd38a63e9ea71821f14a3e01ad212651c260c46ee9fa09a1';           // <!-- wp:paragraph --><p>Home</p><!-- /wp:paragraph -->
const BR_WELCOME = 'eeb1d773ff00d939678e028d2c660cd2d2834b5d74e60ddf9de3d2f2d15650ac';        // <!-- wp:paragraph --><p>Welcome</p><!-- /wp:paragraph -->

WP_Fake::reset();
$brWriter = new Claude_Cowork_Site_Writer();
$brLog = new FakeApplyLog();
$brEngine = new Engine($WTOKEN, [], null, null, null, null, $brWriter, null, $brLog);
$br = static fn (string $action, array $params) => $brEngine->handle(['token' => $WTOKEN, 'action' => $action, 'params' => $params]);

// ── content.get: the revision of the body a body write would replace ───────────────────────────

WP_Fake::$posts[70] = ['ID' => 70, 'post_type' => 'page', 'post_title' => 'About', 'post_status' => 'publish', 'post_content' => '<p>Hello</p>'];
$brGot = $br('content.get', ['id' => 70]);
check('content.get on a post answers the sha256 of its post_content', [$brGot['ok'] ?? null, $brGot['body_revision'] ?? null], [true, BR_HELLO]);

$brTheme = sys_get_temp_dir() . '/cowork-body-revision-' . bin2hex(random_bytes(4));
mkdir($brTheme . '/templates', 0777, true);
mkdir($brTheme . '/parts', 0777, true);
file_put_contents($brTheme . '/parts/header.html', '<!-- wp:site-title /-->');
file_put_contents($brTheme . '/templates/front-page.html', '<!-- wp:paragraph --><p>Home</p><!-- /wp:paragraph -->');
WP_Fake::$themeDir = $brTheme;

$brPart = $br('content.get', ['kind' => 'templatePart', 'key' => 'header']);
check('a part served from the theme file answers the revision of the file\'s bytes', [$brPart['stored'] ?? null, $brPart['body_revision'] ?? null], [false, BR_SITE_TITLE]);
$brTpl = $br('content.get', ['kind' => 'template', 'key' => 'front-page']);
check('a template served from the theme file too', [$brTpl['stored'] ?? null, $brTpl['body_revision'] ?? null], [false, BR_HOME]);

WP_Fake::$options['blogname'] = 'Tracy';
check('an option has no body, so no body_revision', array_key_exists('body_revision', $br('content.get', ['kind' => 'option', 'key' => 'blogname'])), false);
WP_Fake::$patterns['tracy/hero'] = ['name' => 'tracy/hero', 'title' => 'Hero', 'content' => '<p>Hello</p>'];
check('nor does a pattern, which is never written', array_key_exists('body_revision', $br('content.get', ['kind' => 'pattern', 'key' => 'tracy/hero'])), false);
WP_Fake::$meta['70:tracy_heading'] = '<p>Hello</p>';
WP_Fake::$termRows[9] = ['term_id' => 9, 'taxonomy' => 'category', 'name' => 'News', 'slug' => 'news', 'description' => '<p>Hello</p>', 'parent' => 0];
WP_Fake::$posts[71] = ['ID' => 71, 'post_type' => 'nav_menu_item', 'post_title' => 'About', 'menu_order' => 1, 'post_content' => '<p>Hello</p>'];
foreach (['postmeta' => ['id' => 70, 'key' => 'tracy_heading'], 'term' => ['id' => 9, 'key' => 'category'], 'menuItem' => ['id' => 71]] as $brKind => $brAddress) {
    $brRead = $br('content.get', ['kind' => $brKind] + $brAddress);
    check("nor does a {$brKind}", [$brRead['ok'] ?? null, array_key_exists('body_revision', $brRead)], [true, false]);
}

// ── content.update: a write answers the revision of the body it left ─────────────────────────────

$brUp = $br('content.update', ['apply_id' => 'br-1', 'kind' => 'post', 'id' => 70, 'fields' => ['post_content' => '<p>Hello again</p>']]);
check('a post write answers the revision of the body it left', [$brUp['ok'] ?? null, $brUp['body_revision'] ?? null], [true, BR_HELLO_AGAIN]);
check('the one content.get answers next', $br('content.get', ['id' => 70])['body_revision'] ?? null, BR_HELLO_AGAIN);
$brTitled = $br('content.update', ['apply_id' => 'br-2', 'kind' => 'post', 'id' => 70, 'fields' => ['post_title' => 'About us']]);
check('a write that left the body alone answers the revision it already had', $brTitled['body_revision'] ?? null, BR_HELLO_AGAIN);
$brPartUp = $br('content.update', ['apply_id' => 'br-3', 'kind' => 'templatePart', 'key' => 'header', 'fields' => ['content' => '<!-- wp:site-logo /-->']]);
check('a part written over its theme file answers the revision of the override it created', [$brPartUp['created'] ?? null, $brPartUp['body_revision'] ?? null], [true, BR_SITE_LOGO]);
check('an option write answers no body_revision', array_key_exists('body_revision', $br('content.update', ['apply_id' => 'br-4', 'kind' => 'option', 'key' => 'blogname', 'fields' => ['value' => 'Tracy Cowork']])), false);
$br('apply.revert', ['apply_id' => 'br-4']);
$br('apply.revert', ['apply_id' => 'br-3']);
$br('apply.revert', ['apply_id' => 'br-2']);
$br('apply.revert', ['apply_id' => 'br-1']);
check('(the writes above are taken back before the next tests)', [WP_Fake::$posts[70]['post_content'] ?? null, $brWriter->read('templatePart', 0, 'header'), WP_Fake::$options['blogname'] ?? null], ['<p>Hello</p>', null, 'Tracy']);

// ── expected_body_revision: the write lands only over the body the caller read ─────────────────────

WP_Fake::$posts[70]['post_content'] = '<p>Hello again</p>'; // someone else changed the page after the caller read BR_HELLO
$brStale = $br('content.update', ['apply_id' => 'br-stale', 'kind' => 'post', 'id' => 70, 'expected_body_revision' => BR_HELLO,
    'fields' => ['post_content' => '<p>Mine</p>']]);
check('a write over a body that changed since it was read is refused as revision_stale',
    [$brStale['ok'] ?? null, $brStale['error'] ?? null, $brStale['body_revision'] ?? null], [false, 'revision_stale', BR_HELLO_AGAIN]);
checkTrue('with a sentence that says what to do next', is_string($brStale['message'] ?? null)
    && str_contains($brStale['message'], 'content.get') && str_ends_with($brStale['message'], '.'));
check('nothing is written', WP_Fake::$posts[70]['post_content'], '<p>Hello again</p>');
check('nothing is logged', $brLog->entries('br-stale'), []);

$brFresh = $br('content.update', ['apply_id' => 'br-5', 'kind' => 'post', 'id' => 70, 'expected_body_revision' => BR_HELLO_AGAIN,
    'fields' => ['post_content' => '<p>Hello</p>']]);
check('a write over the body the caller read lands, and answers the revision it left',
    [$brFresh['ok'] ?? null, WP_Fake::$posts[70]['post_content'], $brFresh['body_revision'] ?? null], [true, '<p>Hello</p>', BR_HELLO]);
check('the revision content.get answered is the one a write is held to: read, then write',
    $br('content.update', ['apply_id' => 'br-6', 'kind' => 'post', 'id' => 70, 'expected_body_revision' => $br('content.get', ['id' => 70])['body_revision'],
        'fields' => ['post_content' => '<p>Hello again</p>']])['ok'] ?? null, true);
$brTitleOnly = $br('content.update', ['apply_id' => 'br-title', 'kind' => 'post', 'id' => 70, 'expected_body_revision' => BR_HELLO, 'fields' => ['post_title' => 'Renamed']]);
check('a write that changes only the title is still held to the body it was read with',
    [$brTitleOnly['error'] ?? null, WP_Fake::$posts[70]['post_title'], $brLog->entries('br-title')], ['revision_stale', 'About', []]);
$brTitleOnly = $br('content.update', ['apply_id' => 'br-title2', 'kind' => 'post', 'id' => 70, 'expected_body_revision' => BR_HELLO_AGAIN, 'fields' => ['post_title' => 'About Tracy']]);
check('and lands when the body is still the one read', [$brTitleOnly['ok'] ?? null, WP_Fake::$posts[70]['post_title'], $brTitleOnly['body_revision'] ?? null], [true, 'About Tracy', BR_HELLO_AGAIN]);
$br('apply.revert', ['apply_id' => 'br-title2']);
check('a revision in capitals is not the one content.get answers', $br('content.update', ['apply_id' => 'br-x', 'kind' => 'post', 'id' => 70,
    'expected_body_revision' => strtoupper(BR_HELLO_AGAIN), 'fields' => ['post_content' => 'x']])['error'] ?? null, 'revision_stale');

foreach (['an empty string' => '', 'a number' => 7, 'null' => null, 'a list' => [BR_HELLO_AGAIN], 'false' => false] as $brWhat => $brValue) {
    $brR = $br('content.update', ['apply_id' => 'br-bad', 'kind' => 'post', 'id' => 70, 'expected_body_revision' => $brValue, 'fields' => ['post_content' => 'x']]);
    check("expected_body_revision as {$brWhat} is refused, never read as no condition", [$brR['error'] ?? null, str_contains((string) ($brR['message'] ?? ''), 'expected_body_revision')], ['bad_params', true]);
}
check('(and none of them wrote)', [WP_Fake::$posts[70]['post_content'], $brLog->entries('br-bad'), $brLog->entries('br-x')], ['<p>Hello again</p>', [], []]);

WP_Fake::$options['blogname'] = 'Tracy';
$brOpt = $br('content.update', ['apply_id' => 'br-opt', 'kind' => 'option', 'key' => 'blogname', 'expected_body_revision' => BR_HELLO, 'fields' => ['value' => 'x']]);
check('a kind with no body refuses the condition rather than ignore it, naming the kinds that take it',
    [$brOpt['error'] ?? null, str_contains((string) ($brOpt['message'] ?? ''), 'templatePart'), WP_Fake::$options['blogname']], ['bad_params', true, 'Tracy']);

// A part or template the site never stored is held to the theme file content.get serves for it.
$brPartStale = $br('content.update', ['apply_id' => 'br-p1', 'kind' => 'templatePart', 'key' => 'header', 'expected_body_revision' => BR_SITE_LOGO,
    'fields' => ['content' => '<!-- wp:site-logo /-->']]);
check('a part never stored is compared with its theme file: another revision is stale, answering the file\'s',
    [$brPartStale['error'] ?? null, $brPartStale['body_revision'] ?? null, $brWriter->read('templatePart', 0, 'header'), $brLog->entries('br-p1')],
    ['revision_stale', BR_SITE_TITLE, null, []]);
$brPartUp = $br('content.update', ['apply_id' => 'br-p2', 'kind' => 'templatePart', 'key' => 'header', 'expected_body_revision' => BR_SITE_TITLE,
    'fields' => ['content' => '<!-- wp:site-logo /-->']]);
check('and the file\'s own revision lets the first override land', [$brPartUp['ok'] ?? null, $brPartUp['created'] ?? null, $brPartUp['body_revision'] ?? null], [true, true, BR_SITE_LOGO]);
check('content.get on a stored part answers the revision of its row\'s content', [$br('content.get', ['kind' => 'templatePart', 'key' => 'header'])['stored'] ?? true,
    $br('content.get', ['kind' => 'templatePart', 'key' => 'header'])['body_revision'] ?? null], [true, BR_SITE_LOGO]);
check('from then on the stored row is what a write is held to', $br('content.update', ['apply_id' => 'br-p3', 'kind' => 'templatePart', 'key' => 'header',
    'expected_body_revision' => BR_SITE_TITLE, 'fields' => ['content' => 'x']])['body_revision'] ?? null, BR_SITE_LOGO);

$br('content.update', ['apply_id' => 'br-t1', 'kind' => 'template', 'key' => 'front-page', 'fields' => ['content' => '<!-- wp:paragraph --><p>Welcome</p><!-- /wp:paragraph -->']]);
$brTplStale = $br('content.update', ['apply_id' => 'br-t2', 'kind' => 'template', 'key' => 'front-page', 'expected_body_revision' => BR_HOME, 'fields' => ['content' => 'x']]);
check('content.get on a stored template answers its row\'s revision, not the theme file\'s', $br('content.get', ['kind' => 'template', 'key' => 'front-page'])['body_revision'] ?? null, BR_WELCOME);
check('a stored template is held to its row, not to the theme file under it', [$brTplStale['error'] ?? null, $brTplStale['body_revision'] ?? null], ['revision_stale', BR_WELCOME]);
check('and its own revision lands', $br('content.update', ['apply_id' => 'br-t3', 'kind' => 'template', 'key' => 'front-page', 'expected_body_revision' => BR_WELCOME,
    'fields' => ['content' => '<!-- wp:paragraph --><p>Home</p><!-- /wp:paragraph -->']])['body_revision'] ?? null, BR_HOME);

// Nothing there to compare with (a create, or a record deleted since it was read): the key names the
// revision of a record that exists, so it is refused as a parameter, and nothing is written.
$brPostsBefore = WP_Fake::$posts;
$brGone = $br('content.update', ['apply_id' => 'br-g1', 'kind' => 'post', 'id' => 4040, 'expected_body_revision' => BR_HELLO, 'fields' => ['post_content' => 'x']]);
check('a post that is not there is refused: the key names the revision of an existing record',
    [$brGone['error'] ?? null, str_contains((string) ($brGone['message'] ?? ''), 'existing'), str_contains((string) ($brGone['message'] ?? ''), 'post 4040')],
    ['bad_params', true, true]);
$brCreate = $br('content.update', ['apply_id' => 'br-g2', 'kind' => 'post', 'id' => 0, 'expected_body_revision' => BR_HELLO, 'fields' => ['post_title' => 'New']]);
check('so is a create, and nothing is created', [$brCreate['error'] ?? null, WP_Fake::$posts === $brPostsBefore], ['bad_params', true]);
$brNoPart = $br('content.update', ['apply_id' => 'br-g3', 'kind' => 'templatePart', 'key' => 'sidebar', 'expected_body_revision' => BR_HELLO, 'fields' => ['content' => 'x']]);
check('and a part neither stored nor in the theme', [$brNoPart['error'] ?? null, $brWriter->read('templatePart', 0, 'sidebar')], ['bad_params', null]);
check('(none of them logged a step)', [$brLog->entries('br-g1'), $brLog->entries('br-g2'), $brLog->entries('br-g3')], [[], [], []]);

// The checks that were there first come first: a post open in the editor is refused as locked, even
// when the caller's revision is stale too (the lock is what the caller has to wait out).
$brLkWriter = new FakeSiteWriter();
$brLkWriter->store['post']['33'] = ['post_title' => 'Contact', 'post_content' => '<p>Hello again</p>'];
$brLkWriter->locks[33] = ['kind' => 'admin-user', 'name' => 'Ada Editor', 'since' => '2026-10-01T09:00:00Z', 'until' => '2026-10-01T09:02:30Z'];
$brLkEngine = new Engine($WTOKEN, [], null, null, null, null, $brLkWriter, null, new FakeApplyLog());
$brLk = $brLkEngine->handle(['token' => $WTOKEN, 'action' => 'content.update',
    'params' => ['apply_id' => 'lk-1', 'kind' => 'post', 'id' => 33, 'expected_body_revision' => BR_HELLO, 'fields' => ['post_content' => 'x']]]);
check('a post both open in the editor and stale answers the lock', [$brLk['error'] ?? null, $brLk['code'] ?? null, $brLkWriter->store['post']['33']['post_content']],
    ['locked', 'SLOT_LOCKED_BY_USER', '<p>Hello again</p>']);

// ── the check runs under the writer's lock, between the read and the write ───────────────────────

/**
 * A site writer that says what was asked of it, in order, and whether the writer's lock was held:
 * the in-memory writer underneath, plus the `serialize` the real one takes its advisory lock in.
 */
final class BodyRevisionSpyWriter implements SiteWriter
{
    public FakeSiteWriter $inner;
    /** @var string[] */
    public array $events = [];
    private bool $locked = false;

    public function __construct()
    {
        $this->inner = new FakeSiteWriter();
    }

    public function serialize(callable $work)
    {
        $this->events[] = 'lock';
        $this->locked = true;
        try {
            return $work();
        } finally {
            $this->locked = false;
            $this->events[] = 'unlock';
        }
    }

    public function read(string $kind, int $id, string $key = ''): ?array
    {
        $this->events[] = 'read' . ($this->locked ? '' : ' (unlocked)');
        return $this->inner->read($kind, $id, $key);
    }

    public function write(string $kind, int $id, array $fields, string $key = ''): int
    {
        $this->events[] = 'write' . ($this->locked ? '' : ' (unlocked)');
        return $this->inner->write($kind, $id, $fields, $key);
    }

    public function editLock(int $postId): ?array
    {
        return null;
    }

    public function delete(string $kind, int $id, string $key = ''): void
    {
        $this->inner->delete($kind, $id, $key);
    }

    public function canTrash(string $kind): bool
    {
        return $this->inner->canTrash($kind);
    }

    public function trash(string $kind, int $id): void
    {
        $this->inner->trash($kind, $id);
    }

    public function purgeCache(): void
    {
        $this->events[] = 'purge';
    }
}

$brSpy = new BodyRevisionSpyWriter();
$brSpy->inner->store['post']['12'] = ['post_title' => 'Pricing', 'post_content' => '<p>Hello</p>'];
$brSpyLog = new FakeApplyLog();
$brStamp = new class {
    /** @var string[] */
    public array $touched = [];

    public function touch(string $reason): void
    {
        $this->touched[] = $reason;
    }
};
$brSpyEngine = new Engine($WTOKEN, [], null, null, null, null, $brSpy, null, $brSpyLog, $brStamp);
$brSpyUpdate = static fn (array $params) => $brSpyEngine->handle(['token' => $WTOKEN, 'action' => 'content.update', 'params' => $params]);

$brR = $brSpyUpdate(['apply_id' => 'spy-1', 'kind' => 'post', 'id' => 12, 'expected_body_revision' => BR_HELLO_AGAIN, 'fields' => ['post_content' => 'x']]);
check('a stale write reads under the lock and stops there: no write, no purge, no change stamp, no log',
    [$brR['error'] ?? null, $brSpy->events, $brStamp->touched, $brSpyLog->entries('spy-1')], ['revision_stale', ['lock', 'read', 'unlock'], [], []]);
$brSpy->events = [];
$brR = $brSpyUpdate(['apply_id' => 'spy-2', 'kind' => 'post', 'id' => 12, 'expected_body_revision' => BR_HELLO, 'fields' => ['post_content' => '<p>Hello again</p>']]);
check('a fresh one reads, checks and writes in one hold of the lock, and its answer reuses the read the undo takes',
    [$brR['body_revision'] ?? null, $brSpy->events, $brStamp->touched], [BR_HELLO_AGAIN, ['lock', 'read', 'write', 'read', 'purge', 'unlock'], ['content']]);

// ── without the key nothing changes; with it, the receipt is the same receipt ─────────────────────

$brSpy->inner->store['post']['12']['post_content'] = '<p>Changed by someone else</p>';
$brPlain = $brSpyUpdate(['apply_id' => 'spy-3', 'kind' => 'post', 'id' => 12, 'fields' => ['post_content' => '<p>Hello</p>']]);
check('a write without expected_body_revision is not held to anything: it lands over whatever is there',
    [$brPlain['ok'] ?? null, $brSpy->inner->store['post']['12']['post_content']], [true, '<p>Hello</p>']);
check('and answers what 0.16.0 answered, plus body_revision', array_keys($brPlain), ['ok', 'kind', 'id', 'key', 'created', 'body_revision']);
$brHeld = $brSpyUpdate(['apply_id' => 'spy-4', 'kind' => 'post', 'id' => 12, 'expected_body_revision' => BR_HELLO, 'fields' => ['post_content' => '<p>Hello again</p>']]);
check('a held write answers the same shape', array_keys($brHeld), array_keys($brPlain));
$brPlainEntry = $brSpyLog->entries('spy-3')[0] ?? [];
$brHeldEntry = $brSpyLog->entries('spy-4')[0] ?? [];
check('and records the same kind of undo step: the condition is not part of the receipt',
    [array_keys($brHeldEntry), $brHeldEntry['undo'] ?? null, array_key_exists('expected_body_revision', $brHeldEntry)], [array_keys($brPlainEntry), 'span', false]);

// apply.revert takes a held write back exactly as it takes back any other.
$brRv = $brSpyEngine->handle(['token' => $WTOKEN, 'action' => 'apply.revert', 'params' => ['apply_id' => 'spy-4']]);
check('apply.revert takes back a held write', [$brRv['ok'] ?? null, $brRv['reverted'] ?? null, $brSpy->inner->store['post']['12']['post_content']], [true, 1, '<p>Hello</p>']);
check('and the body is back at the revision the held write was compared with', $brSpyEngine->handle(['token' => $WTOKEN, 'action' => 'content.get', 'params' => ['id' => 12]])['body_revision'] ?? null, BR_HELLO);
WP_Fake::$posts[70]['post_content'] = '<p>Hello</p>';
$br('content.update', ['apply_id' => 'br-r1', 'kind' => 'post', 'id' => 70, 'expected_body_revision' => BR_HELLO, 'fields' => ['post_content' => '<p>Hello again</p>']]);
$br('content.update', ['apply_id' => 'br-r2', 'kind' => 'post', 'id' => 70, 'fields' => ['post_title' => 'About Tracy']]);
$brRv = $br('apply.revert', ['apply_id' => 'br-r1']);
check('on the real writer too, and a later receipt on the same post stays',
    [$brRv['ok'] ?? null, WP_Fake::$posts[70]['post_content'], WP_Fake::$posts[70]['post_title']], [true, '<p>Hello</p>', 'About Tracy']);

foreach (['templates/front-page.html', 'parts/header.html'] as $f) {
    unlink($brTheme . '/' . $f);
}
rmdir($brTheme . '/templates');
rmdir($brTheme . '/parts');
rmdir($brTheme);
WP_Fake::reset();
