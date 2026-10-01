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
$got = $br('content.get', ['id' => 70]);
check('content.get on a post answers the sha256 of its post_content', [$got['ok'] ?? null, $got['body_revision'] ?? null], [true, BR_HELLO]);

$brTheme = sys_get_temp_dir() . '/cowork-body-revision-' . bin2hex(random_bytes(4));
mkdir($brTheme . '/templates', 0777, true);
mkdir($brTheme . '/parts', 0777, true);
file_put_contents($brTheme . '/parts/header.html', '<!-- wp:site-title /-->');
file_put_contents($brTheme . '/templates/front-page.html', '<!-- wp:paragraph --><p>Home</p><!-- /wp:paragraph -->');
WP_Fake::$themeDir = $brTheme;

$part = $br('content.get', ['kind' => 'templatePart', 'key' => 'header']);
check('a part served from the theme file answers the revision of the file\'s bytes', [$part['stored'] ?? null, $part['body_revision'] ?? null], [false, BR_SITE_TITLE]);
$tpl = $br('content.get', ['kind' => 'template', 'key' => 'front-page']);
check('a template served from the theme file too', [$tpl['stored'] ?? null, $tpl['body_revision'] ?? null], [false, BR_HOME]);

WP_Fake::$options['blogname'] = 'Tracy';
check('an option has no body, so no body_revision', array_key_exists('body_revision', $br('content.get', ['kind' => 'option', 'key' => 'blogname'])), false);
WP_Fake::$patterns['tracy/hero'] = ['name' => 'tracy/hero', 'title' => 'Hero', 'content' => '<p>Hello</p>'];
check('nor does a pattern, which is never written', array_key_exists('body_revision', $br('content.get', ['kind' => 'pattern', 'key' => 'tracy/hero'])), false);

// ── content.update: a write answers the revision of the body it left ─────────────────────────────

$up = $br('content.update', ['apply_id' => 'br-1', 'kind' => 'post', 'id' => 70, 'fields' => ['post_content' => '<p>Hello again</p>']]);
check('a post write answers the revision of the body it left', [$up['ok'] ?? null, $up['body_revision'] ?? null], [true, BR_HELLO_AGAIN]);
check('the one content.get answers next', $br('content.get', ['id' => 70])['body_revision'] ?? null, BR_HELLO_AGAIN);
$titled = $br('content.update', ['apply_id' => 'br-2', 'kind' => 'post', 'id' => 70, 'fields' => ['post_title' => 'About us']]);
check('a write that left the body alone answers the revision it already had', $titled['body_revision'] ?? null, BR_HELLO_AGAIN);
$partUp = $br('content.update', ['apply_id' => 'br-3', 'kind' => 'templatePart', 'key' => 'header', 'fields' => ['content' => '<!-- wp:site-logo /-->']]);
check('a part written over its theme file answers the revision of the override it created', [$partUp['created'] ?? null, $partUp['body_revision'] ?? null], [true, BR_SITE_LOGO]);
check('an option write answers no body_revision', array_key_exists('body_revision', $br('content.update', ['apply_id' => 'br-4', 'kind' => 'option', 'key' => 'blogname', 'fields' => ['value' => 'Tracy Cowork']])), false);
$br('apply.revert', ['apply_id' => 'br-4']);
$br('apply.revert', ['apply_id' => 'br-3']);
$br('apply.revert', ['apply_id' => 'br-2']);
$br('apply.revert', ['apply_id' => 'br-1']);
check('(the writes above are taken back before the next tests)', [WP_Fake::$posts[70]['post_content'] ?? null, $brWriter->read('templatePart', 0, 'header'), WP_Fake::$options['blogname'] ?? null], ['<p>Hello</p>', null, 'Tracy']);

// ── expected_body_revision: the write lands only over the body the caller read ─────────────────────

WP_Fake::$posts[70]['post_content'] = '<p>Hello again</p>'; // someone else changed the page after the caller read BR_HELLO
WP_Fake::$cleaned = [];
$stale = $br('content.update', ['apply_id' => 'br-stale', 'kind' => 'post', 'id' => 70, 'expected_body_revision' => BR_HELLO,
    'fields' => ['post_content' => '<p>Mine</p>']]);
check('a write over a body that changed since it was read is refused as revision_stale',
    [$stale['ok'] ?? null, $stale['error'] ?? null, $stale['body_revision'] ?? null], [false, 'revision_stale', BR_HELLO_AGAIN]);
checkTrue('with a sentence that says what to do next', is_string($stale['message'] ?? null)
    && str_contains($stale['message'], 'content.get') && str_ends_with($stale['message'], '.'));
check('nothing is written', WP_Fake::$posts[70]['post_content'], '<p>Hello again</p>');
check('nothing is logged', $brLog->entries('br-stale'), []);

$fresh = $br('content.update', ['apply_id' => 'br-5', 'kind' => 'post', 'id' => 70, 'expected_body_revision' => BR_HELLO_AGAIN,
    'fields' => ['post_content' => '<p>Hello</p>']]);
check('a write over the body the caller read lands, and answers the revision it left',
    [$fresh['ok'] ?? null, WP_Fake::$posts[70]['post_content'], $fresh['body_revision'] ?? null], [true, '<p>Hello</p>', BR_HELLO]);
check('the revision content.get answered is the one a write is held to: read, then write',
    $br('content.update', ['apply_id' => 'br-6', 'kind' => 'post', 'id' => 70, 'expected_body_revision' => $br('content.get', ['id' => 70])['body_revision'],
        'fields' => ['post_content' => '<p>Hello again</p>']])['ok'] ?? null, true);
check('a revision in capitals is not the one content.get answers', $br('content.update', ['apply_id' => 'br-x', 'kind' => 'post', 'id' => 70,
    'expected_body_revision' => strtoupper(BR_HELLO_AGAIN), 'fields' => ['post_content' => 'x']])['error'] ?? null, 'revision_stale');

foreach (['an empty string' => '', 'a number' => 7, 'null' => null, 'a list' => [BR_HELLO_AGAIN], 'false' => false] as $what => $value) {
    $r = $br('content.update', ['apply_id' => 'br-bad', 'kind' => 'post', 'id' => 70, 'expected_body_revision' => $value, 'fields' => ['post_content' => 'x']]);
    check("expected_body_revision as {$what} is refused, never read as no condition", [$r['error'] ?? null, str_contains((string) ($r['message'] ?? ''), 'expected_body_revision')], ['bad_params', true]);
}
check('(and none of them wrote)', [WP_Fake::$posts[70]['post_content'], $brLog->entries('br-bad'), $brLog->entries('br-x')], ['<p>Hello again</p>', [], []]);

WP_Fake::$options['blogname'] = 'Tracy';
$opt = $br('content.update', ['apply_id' => 'br-opt', 'kind' => 'option', 'key' => 'blogname', 'expected_body_revision' => BR_HELLO, 'fields' => ['value' => 'x']]);
check('a kind with no body refuses the condition rather than ignore it, naming the kinds that take it',
    [$opt['error'] ?? null, str_contains((string) ($opt['message'] ?? ''), 'templatePart'), WP_Fake::$options['blogname']], ['bad_params', true, 'Tracy']);

// A part or template the site never stored is held to the theme file content.get serves for it.
$partStale = $br('content.update', ['apply_id' => 'br-p1', 'kind' => 'templatePart', 'key' => 'header', 'expected_body_revision' => BR_SITE_LOGO,
    'fields' => ['content' => '<!-- wp:site-logo /-->']]);
check('a part never stored is compared with its theme file: another revision is stale, answering the file\'s',
    [$partStale['error'] ?? null, $partStale['body_revision'] ?? null, $brWriter->read('templatePart', 0, 'header'), $brLog->entries('br-p1')],
    ['revision_stale', BR_SITE_TITLE, null, []]);
$partUp = $br('content.update', ['apply_id' => 'br-p2', 'kind' => 'templatePart', 'key' => 'header', 'expected_body_revision' => BR_SITE_TITLE,
    'fields' => ['content' => '<!-- wp:site-logo /-->']]);
check('and the file\'s own revision lets the first override land', [$partUp['ok'] ?? null, $partUp['created'] ?? null, $partUp['body_revision'] ?? null], [true, true, BR_SITE_LOGO]);
check('from then on the stored row is what a write is held to', $br('content.update', ['apply_id' => 'br-p3', 'kind' => 'templatePart', 'key' => 'header',
    'expected_body_revision' => BR_SITE_TITLE, 'fields' => ['content' => 'x']])['body_revision'] ?? null, BR_SITE_LOGO);

$br('content.update', ['apply_id' => 'br-t1', 'kind' => 'template', 'key' => 'front-page', 'fields' => ['content' => '<!-- wp:paragraph --><p>Welcome</p><!-- /wp:paragraph -->']]);
$tplStale = $br('content.update', ['apply_id' => 'br-t2', 'kind' => 'template', 'key' => 'front-page', 'expected_body_revision' => BR_HOME, 'fields' => ['content' => 'x']]);
check('a stored template is held to its row, not to the theme file under it', [$tplStale['error'] ?? null, $tplStale['body_revision'] ?? null], ['revision_stale', BR_WELCOME]);
check('and its own revision lands', $br('content.update', ['apply_id' => 'br-t3', 'kind' => 'template', 'key' => 'front-page', 'expected_body_revision' => BR_WELCOME,
    'fields' => ['content' => '<!-- wp:paragraph --><p>Home</p><!-- /wp:paragraph -->']])['body_revision'] ?? null, BR_HOME);

// Nothing there: deleted since it was read, or never there. No revision matches it, and none is invented.
$WP_FAKE_POSTS_BEFORE = WP_Fake::$posts;
$gone = $br('content.update', ['apply_id' => 'br-g1', 'kind' => 'post', 'id' => 4040, 'expected_body_revision' => BR_HELLO, 'fields' => ['post_content' => 'x']]);
check('a post that is not there matches no revision: stale, body_revision null',
    [$gone['error'] ?? null, array_key_exists('body_revision', $gone) ? $gone['body_revision'] : 'absent', str_contains((string) ($gone['message'] ?? ''), 'post 4040')],
    ['revision_stale', null, true]);
$create = $br('content.update', ['apply_id' => 'br-g2', 'kind' => 'post', 'id' => 0, 'expected_body_revision' => BR_HELLO, 'fields' => ['post_title' => 'New']]);
check('nor does a create, and nothing is created', [$create['error'] ?? null, WP_Fake::$posts === $WP_FAKE_POSTS_BEFORE], ['revision_stale', true]);
$noPart = $br('content.update', ['apply_id' => 'br-g3', 'kind' => 'templatePart', 'key' => 'sidebar', 'expected_body_revision' => BR_HELLO, 'fields' => ['content' => 'x']]);
check('nor a part neither stored nor in the theme', [$noPart['error'] ?? null, array_key_exists('body_revision', $noPart) ? $noPart['body_revision'] : 'absent', $brWriter->read('templatePart', 0, 'sidebar')],
    ['revision_stale', null, null]);
check('(none of them logged a step)', [$brLog->entries('br-g1'), $brLog->entries('br-g2'), $brLog->entries('br-g3')], [[], [], []]);

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

$spy = new BodyRevisionSpyWriter();
$spy->inner->store['post']['12'] = ['post_title' => 'Pricing', 'post_content' => '<p>Hello</p>'];
$spyLog = new FakeApplyLog();
$spyEngine = new Engine($WTOKEN, [], null, null, null, null, $spy, null, $spyLog);
$spyUpdate = static fn (array $params) => $spyEngine->handle(['token' => $WTOKEN, 'action' => 'content.update', 'params' => $params]);

$r = $spyUpdate(['apply_id' => 'spy-1', 'kind' => 'post', 'id' => 12, 'expected_body_revision' => BR_HELLO_AGAIN, 'fields' => ['post_content' => 'x']]);
check('a stale write reads under the lock and stops there: no write, no purge',
    [$r['error'] ?? null, $spy->events], ['revision_stale', ['lock', 'read', 'unlock']]);
$spy->events = [];
$r = $spyUpdate(['apply_id' => 'spy-2', 'kind' => 'post', 'id' => 12, 'expected_body_revision' => BR_HELLO, 'fields' => ['post_content' => '<p>Hello again</p>']]);
check('a fresh one reads, checks and writes in one hold of the lock, and its answer reuses the read the undo takes',
    [$r['body_revision'] ?? null, $spy->events], [BR_HELLO_AGAIN, ['lock', 'read', 'write', 'read', 'purge', 'unlock']]);

// ── without the key nothing changes; with it, the receipt is the same receipt ─────────────────────

$spy->inner->store['post']['12']['post_content'] = '<p>Changed by someone else</p>';
$plain = $spyUpdate(['apply_id' => 'spy-3', 'kind' => 'post', 'id' => 12, 'fields' => ['post_content' => '<p>Hello</p>']]);
check('a write without expected_body_revision is not held to anything: it lands over whatever is there',
    [$plain['ok'] ?? null, $spy->inner->store['post']['12']['post_content']], [true, '<p>Hello</p>']);
check('and answers what 0.16.0 answered, plus body_revision', array_keys($plain), ['ok', 'kind', 'id', 'key', 'created', 'body_revision']);
$held = $spyUpdate(['apply_id' => 'spy-4', 'kind' => 'post', 'id' => 12, 'expected_body_revision' => BR_HELLO, 'fields' => ['post_content' => '<p>Hello again</p>']]);
check('a held write answers the same shape', array_keys($held), array_keys($plain));
$plainEntry = $spyLog->entries('spy-3')[0] ?? [];
$heldEntry = $spyLog->entries('spy-4')[0] ?? [];
check('and records the same kind of undo step: the condition is not part of the receipt',
    [array_keys($heldEntry), $heldEntry['undo'] ?? null, array_key_exists('expected_body_revision', $heldEntry)], [array_keys($plainEntry), 'span', false]);

// apply.revert takes a held write back exactly as it takes back any other.
$rv = $spyEngine->handle(['token' => $WTOKEN, 'action' => 'apply.revert', 'params' => ['apply_id' => 'spy-4']]);
check('apply.revert takes back a held write', [$rv['ok'] ?? null, $rv['reverted'] ?? null, $spy->inner->store['post']['12']['post_content']], [true, 1, '<p>Hello</p>']);
check('and the body is back at the revision the held write was compared with', $spyEngine->handle(['token' => $WTOKEN, 'action' => 'content.get', 'params' => ['id' => 12]])['body_revision'] ?? null, BR_HELLO);
WP_Fake::$posts[70]['post_content'] = '<p>Hello</p>';
$br('content.update', ['apply_id' => 'br-r1', 'kind' => 'post', 'id' => 70, 'expected_body_revision' => BR_HELLO, 'fields' => ['post_content' => '<p>Hello again</p>']]);
$br('content.update', ['apply_id' => 'br-r2', 'kind' => 'post', 'id' => 70, 'fields' => ['post_title' => 'About Tracy']]);
$rv = $br('apply.revert', ['apply_id' => 'br-r1']);
check('on the real writer too, and a later receipt on the same post stays',
    [$rv['ok'] ?? null, WP_Fake::$posts[70]['post_content'], WP_Fake::$posts[70]['post_title']], [true, '<p>Hello</p>', 'About Tracy']);

foreach (['templates/front-page.html', 'parts/header.html'] as $f) {
    unlink($brTheme . '/' . $f);
}
rmdir($brTheme . '/templates');
rmdir($brTheme . '/parts');
rmdir($brTheme);
WP_Fake::reset();
