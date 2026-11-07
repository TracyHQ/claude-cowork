<?php
/**
 * Field and featured-image slots on a post (TracyHQ/tracy#452).
 *
 * The capture of a WordPress quickstart with `--posts` makes an entity of every published post,
 * with its title (`target.field: post_title`), its excerpt (`target.field: post_excerpt`), its
 * featured picture (`target.meta: _thumbnail_id`) and its named blocks as slots. A post's title
 * and excerpt live in the row, not in post_content, and its featured picture is post meta, so a
 * plugin that only read blocks could not bind such a profile at all.
 *
 * Only those three targets: any other field or meta key a profile names is refused by name,
 * so a content map can never reach an arbitrary column or meta key of a row.
 *
 * Loaded by run.php after image-slots.php. Uses `check()` / `checkTrue()`, `$WTOKEN`,
 * `contractRoot()` from contracts.php and FakeImageLibrary from image-slots.php.
 */
declare(strict_types=1);

echo "\nPost field slots\n";

$POST_FIXTURES = __DIR__ . '/fixtures/post-contracts';
$POST_HOME = (string) file_get_contents(__DIR__ . '/fixtures/image-contracts/home.html');
$POST_BODY = (string) file_get_contents($POST_FIXTURES . '/spring-tips.html');

/** @return array{engine:Engine,images:FakeImageLibrary} a site with one page and one post, the post's featured picture set */
function postSite(string $fixtures, string $home, string $body): array
{
    WP_Fake::reset();
    WP_Fake::$stylesheet = 'test-theme';
    WP_Fake::$options = ['stylesheet' => 'test-theme', 'template' => 'test-theme', 'home' => 'http://test.local'];
    WP_Fake::$posts[10] = ['ID' => 10, 'post_type' => 'page', 'post_name' => 'home', 'post_status' => 'publish', 'post_parent' => 0, 'post_title' => 'Home', 'post_content' => $home];
    WP_Fake::$posts[20] = ['ID' => 20, 'post_type' => 'post', 'post_name' => 'spring-tips', 'post_status' => 'publish', 'post_parent' => 0,
        'post_title' => 'Spring garden tips', 'post_excerpt' => 'What to do before the first warm week.', 'post_content' => $body];
    WP_Fake::$meta['20:_thumbnail_id'] = '92';
    WP_Fake::$meta['92:_wp_attached_file'] = '2026/09/spring.jpg';
    $site = json_decode((string) file_get_contents(__DIR__ . '/fixtures/contracts/site.json'), true);
    $images = new FakeImageLibrary();
    $images->images['wp-content/uploads/2026/09/spring.jpg'] = ['id' => 92, 'width' => 1200, 'height' => 800];
    $writer = new Claude_Cowork_Site_Writer();
    $contract = new QuickstartContract($writer, new Claude_Cowork_Contract_Store(), contractRoot($site), $fixtures, '', null, $images);
    $engine = new Engine($GLOBALS['WTOKEN'], [], null, null, null, null, $writer, new FakeMediaWriter(), new FakeApplyLog(), null, $contract);
    return ['engine' => $engine, 'images' => $images];
}

$ps = postSite($POST_FIXTURES, $POST_HOME, $POST_BODY);
$PE = $ps['engine'];
$pdoor = static function (string $operation, array $params = []) use ($PE, $WTOKEN): array {
    return $PE->handle(['token' => $WTOKEN, 'action' => 'content.contract', 'params' => ['operation' => $operation] + $params]);
};

check('a profile with post field slots binds', $pdoor('bind', ['contract' => 'post-design/wp7/1.0.0'])['ok'], true);
$pinsp = $pdoor('inspect');
check('the post is not drift', $pinsp['problems'], []);
check('the post is an entity of its own', array_column($pinsp['entities'], 'id', 'key'), ['page-home' => 10, 'post-spring-tips' => 20]);
check('the title slot reads the post title', $pinsp['slots']['post-spring-tips.title'], 'Spring garden tips');
check('the excerpt slot reads the excerpt', $pinsp['slots']['post-spring-tips.excerpt'], 'What to do before the first warm week.');
check('the featured picture reads as its uploads path', $pinsp['slots']['post-spring-tips.featured-image'], 'wp-content/uploads/2026/09/spring.jpg');
check('and is listed among the image slots', $pinsp['imageSlots'], ['post-spring-tips.featured-image' => 'wp-content/uploads/2026/09/spring.jpg']);
check('a block of the post still reads as a block', $pinsp['slots']['post-spring-tips.post.intro'], 'Three small changes make a garden ready for the first warm week.');
check('and the page beside it as before', $pinsp['slots']['home.hero.eyebrow'], 'Established 2004');

$papply = static function (array $changes, string $id) use ($pdoor): array {
    return $pdoor('apply', ['expected_revision' => $pdoor('inspect')['revision'], 'apply_id' => 'contract-' . $id, 'request_id' => $id, 'changes' => $changes]);
};

// ── title and excerpt ───────────────────────────────────────────────────────────────────────

$words = $papply(['post-spring-tips.title' => 'Autumn & winter tips', 'post-spring-tips.excerpt' => 'Cold weeks, kept simple.'], 'p1');
check('a new title and excerpt land', $words['ok'], true);
check('in one write of the post row', $words['written'], [['kind' => 'post', 'id' => 20, 'key' => null]]);
check('the title is stored as given', WP_Fake::$posts[20]['post_title'], 'Autumn & winter tips');
check('so is the excerpt', WP_Fake::$posts[20]['post_excerpt'], 'Cold weeks, kept simple.');
check('the body is untouched', WP_Fake::$posts[20]['post_content'], $POST_BODY);
$afterWords = $pdoor('inspect');
check('a changed title is not drift', $afterWords['problems'], []);
check('the slot answers the new title', $afterWords['slots']['post-spring-tips.title'], 'Autumn & winter tips');
checkTrue('and the revision moved with it', $afterWords['revision'] !== $pinsp['revision']);

check('markup in a title is refused', $papply(['post-spring-tips.title' => '<b>Bold</b>'], 'p2')['errors'][0]['code'] ?? null, 'SLOT_NOT_CONTENT');
check('so is a title past its limit', $papply(['post-spring-tips.title' => str_repeat('a', 81)], 'p3')['errors'][0]['code'] ?? null, 'SLOT_TOO_LONG');

check('the field apply reverts', $PE->handle(['token' => $WTOKEN, 'action' => 'apply.revert', 'params' => ['apply_id' => 'contract-p1']])['ok'], true);
check('to the title and excerpt it shipped with', [WP_Fake::$posts[20]['post_title'], WP_Fake::$posts[20]['post_excerpt']],
    ['Spring garden tips', 'What to do before the first warm week.']);

// ── the featured picture ────────────────────────────────────────────────────────────────────

$picture = 'wp-content/uploads/tracy-content/' . hash('sha256', 'autumn') . '.jpg';
check('a picture not in the media library is refused', $papply(['post-spring-tips.featured-image' => $picture], 'p4')['errors'][0]['code'] ?? null, 'SLOT_IMAGE_INVALID');
$ps['images']->images[$picture] = ['id' => 131, 'width' => 800, 'height' => 1000];
check('a picture of another shape is refused', $papply(['post-spring-tips.featured-image' => $picture], 'p5')['errors'][0]['message'] ?? null,
    'Image aspect ratio does not match its slot: post-spring-tips.featured-image');
$ps['images']->images[$picture] = ['id' => 131, 'width' => 1500, 'height' => 1000];
WP_Fake::$meta['131:_wp_attached_file'] = 'tracy-content/' . hash('sha256', 'autumn') . '.jpg';
$featured = $papply(['post-spring-tips.featured-image' => $picture, 'post-spring-tips.post.intro' => 'Two changes are enough.'], 'p6');
check('a picture in the library, in the slot\'s shape, lands', $featured['ok'], true);
check('as a meta write beside the row write', $featured['written'],
    [['kind' => 'post', 'id' => 20, 'key' => null], ['kind' => 'postmeta', 'id' => 20, 'key' => '_thumbnail_id']]);
check('the post names the new attachment', (int) WP_Fake::$meta['20:_thumbnail_id'], 131);
checkTrue('and the block beside it moved in the same apply', strpos((string) WP_Fake::$posts[20]['post_content'], 'Two changes are enough.') !== false);
$afterPicture = $pdoor('inspect');
check('a changed featured picture is not drift', $afterPicture['problems'], []);
check('the slot answers the new picture', $afterPicture['slots']['post-spring-tips.featured-image'], $picture);

check('the picture apply reverts', $PE->handle(['token' => $WTOKEN, 'action' => 'apply.revert', 'params' => ['apply_id' => 'contract-p6']])['ok'], true);
check('to the featured picture it shipped with', (string) WP_Fake::$meta['20:_thumbnail_id'], '92');
check('and the body it shipped with', WP_Fake::$posts[20]['post_content'], $POST_BODY);

// A post that had no featured picture: the apply creates the meta, and its revert removes it.
unset(WP_Fake::$meta['20:_thumbnail_id']);
check('a post without a featured picture reads empty', $pdoor('inspect')['slots']['post-spring-tips.featured-image'], '');
check('a picture lands on it', $papply(['post-spring-tips.featured-image' => $picture], 'p7')['ok'], true);
check('the revert takes it off again', [$PE->handle(['token' => $WTOKEN, 'action' => 'apply.revert', 'params' => ['apply_id' => 'contract-p7']])['ok'],
    array_key_exists('20:_thumbnail_id', WP_Fake::$meta)], [true, false]);

// ── only the three targets ──────────────────────────────────────────────────────────────────

/** The inspect problems of the fixture profile with one slot's target replaced. */
$withTarget = static function (int $slot, array $target, ?string $type = null) use ($POST_FIXTURES, $POST_HOME, $POST_BODY, $WTOKEN): array {
    $dir = sys_get_temp_dir() . '/cc-post-profile-' . bin2hex(random_bytes(4));
    register_shutdown_function('contractRootRemove', $dir);
    mkdir($dir . '/post-design/wp7/1.0.0', 0777, true);
    foreach (['manifest.json', 'presentation-lock.json'] as $file) {
        copy($POST_FIXTURES . '/post-design/wp7/1.0.0/' . $file, $dir . '/post-design/wp7/1.0.0/' . $file);
    }
    $map = json_decode((string) file_get_contents($POST_FIXTURES . '/post-design/wp7/1.0.0/content-map.json'), true);
    $map['slots'][$slot]['target'] = $target;
    if ($type !== null) {
        $map['slots'][$slot]['type'] = $type;
    }
    file_put_contents($dir . '/post-design/wp7/1.0.0/content-map.json', json_encode($map));
    $site = postSite($dir, $POST_HOME, $POST_BODY);
    $engine = $site['engine'];
    return [
        'inspect' => $engine->handle(['token' => $WTOKEN, 'action' => 'content.contract', 'params' => ['operation' => 'inspect', 'contract' => 'post-design/wp7/1.0.0']])['problems'] ?? null,
        'bind' => $engine->handle(['token' => $WTOKEN, 'action' => 'content.contract', 'params' => ['operation' => 'bind', 'contract' => 'post-design/wp7/1.0.0']])['ok'] ?? null,
    ];
};
check('any other post field is refused by name, and the profile does not bind', $withTarget(1, ['field' => 'post_password']),
    ['inspect' => ['Slot post-spring-tips.title: unsupported post field post_password'], 'bind' => false]);
check('so is post_content named as a field', $withTarget(1, ['field' => 'post_content'])['inspect'],
    ['Slot post-spring-tips.title: unsupported post field post_content']);
check('any other meta key is refused by name', $withTarget(4, ['meta' => '_wp_page_template']),
    ['inspect' => ['Slot post-spring-tips.featured-image: unsupported post meta _wp_page_template'], 'bind' => false]);
check('a field slot that is not text is refused', $withTarget(1, ['field' => 'post_title'], 'image')['inspect'],
    ['Slot post-spring-tips.title: a post field takes a text slot']);
check('the featured picture of a page is refused', $withTarget(0, ['meta' => '_thumbnail_id'], 'image')['inspect'],
    ['Slot home.hero.eyebrow: the featured picture is an image slot of a post']);
check('a slot naming a block and a field is refused', $withTarget(1, ['block' => 'post.intro', 'field' => 'post_title'])['inspect'],
    ['Slot post-spring-tips.title: a slot names one target: a block, a field or a meta key']);
