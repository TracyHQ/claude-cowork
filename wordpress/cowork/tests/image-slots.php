<?php
/**
 * Image slots on a sealed site: a `core/image` block the contract lets a picture land in.
 *
 * A sealed WordPress site used to take words and links only, so an agent asked for a new hero
 * photo could only say no (measured 26/09/2026 on a Tracy Business site: "I cannot draw or change
 * pictures on this site"). An image slot names the block; its value is a file already in the
 * site's media library, and the write sets the three places a picture lives in block markup — the
 * `src`, the block's `id` and the `wp-image-N` class — so the editor still knows the attachment.
 *
 * The skeleton does NOT mask an image slot: it puts the demo picture back (`sample` +
 * `sampleId`). An untouched page then hashes to exactly the bytes it always did, so adding image
 * slots to a released profile leaves its presentation lock the same and every site already
 * sealed to it keeps working.
 *
 * Loaded by run.php. Uses `check()` / `checkTrue()`, `$WTOKEN`, `contractRoot()` from contracts.php.
 */
declare(strict_types=1);

echo "\nImage slots\n";

/** A media library that knows exactly the pictures a test gives it. */
final class FakeImageLibrary implements ImageLibrary
{
    /** @var array<string,array{id:int,width:int,height:int}> */
    public array $images = [];

    public function find(string $path): ?array
    {
        return $this->images[$path] ?? null;
    }
}

$IMAGE_FIXTURES = __DIR__ . '/fixtures/image-contracts';
$IMAGE_HOME = (string) file_get_contents($IMAGE_FIXTURES . '/home.html');

// ── the block value, both ways ──────────────────────────────────────────────────────────────

check('an image slot reads its picture as a webroot path', QuickstartContract::getBlockValue($IMAGE_HOME, 'hero.img', 'src'), 'wp-content/uploads/2026/09/hero.png');

$swapped = QuickstartContract::setBlockValue($IMAGE_HOME, 'hero.img', 'src', 'wp-content/uploads/tracy-content/' . str_repeat('a', 64) . '.jpg', 120);
checkTrue('the src takes the new file', strpos($swapped, 'src="/wp-content/uploads/tracy-content/' . str_repeat('a', 64) . '.jpg"') !== false);
checkTrue('the block names the new attachment', strpos($swapped, '"name":"hero.img"},"id":120} -->') !== false);
checkTrue('and so does the image class', strpos($swapped, 'class="wp-image-120"') !== false);
check('nothing else moved', str_replace(
    ['src="/wp-content/uploads/tracy-content/' . str_repeat('a', 64) . '.jpg"', '"id":120}', 'wp-image-120'],
    ['src="/wp-content/uploads/2026/09/hero.png"', '"id":91}', 'wp-image-91'],
    $swapped
), $IMAGE_HOME);
check('and it reads back', QuickstartContract::getBlockValue($swapped, 'hero.img', 'src'), 'wp-content/uploads/tracy-content/' . str_repeat('a', 64) . '.jpg');
check('putting the demo picture back is the page it shipped as',
    QuickstartContract::setBlockValue($swapped, 'hero.img', 'src', 'wp-content/uploads/2026/09/hero.png', 91), $IMAGE_HOME);

$noId = null;
try {
    QuickstartContract::setBlockValue($IMAGE_HOME, 'hero.img', 'src', 'wp-content/uploads/x.png');
} catch (RuntimeException $e) {
    $noId = $e->getMessage();
}
check('a picture without its attachment is refused', $noId, 'block hero.img needs the attachment id of its picture');
$notImage = null;
try {
    QuickstartContract::setBlockValue($IMAGE_HOME, 'hero.eyebrow', 'src', 'wp-content/uploads/x.png', 5);
} catch (RuntimeException $e) {
    $notImage = $e->getMessage();
}
check('a block with no picture is refused by name', $notImage, 'block hero.eyebrow has no picture to write');

// An absolute src on the site's own origin reads as the same path.
check('an absolute src reads as its path', QuickstartContract::getBlockValue(
    str_replace('src="/wp-content', 'src="http://test.local/wp-content', $IMAGE_HOME), 'hero.img', 'src'), 'wp-content/uploads/2026/09/hero.png');

// ── a sealed site with one image slot ───────────────────────────────────────────────────────

/** @return array{engine:Engine,images:FakeImageLibrary} */
function imageSite(string $home, string $fixtures): array
{
    WP_Fake::reset();
    WP_Fake::$stylesheet = 'test-theme';
    WP_Fake::$options = ['stylesheet' => 'test-theme', 'template' => 'test-theme', 'home' => 'http://test.local'];
    WP_Fake::$posts[10] = ['ID' => 10, 'post_type' => 'page', 'post_name' => 'home', 'post_status' => 'publish', 'post_parent' => 0, 'post_title' => 'Home', 'post_content' => $home];
    $site = json_decode((string) file_get_contents(__DIR__ . '/fixtures/contracts/site.json'), true);
    $images = new FakeImageLibrary();
    $images->images['wp-content/uploads/2026/09/hero.png'] = ['id' => 91, 'width' => 800, 'height' => 1000];
    $writer = new Claude_Cowork_Site_Writer();
    $contract = new QuickstartContract($writer, new Claude_Cowork_Contract_Store(), contractRoot($site), $fixtures, '', null, $images);
    $engine = new Engine($GLOBALS['WTOKEN'], [], null, null, null, null, $writer, new FakeMediaWriter(), new FakeApplyLog(), null, $contract);
    return ['engine' => $engine, 'images' => $images];
}

$img = imageSite($IMAGE_HOME, $IMAGE_FIXTURES);
$IE = $img['engine'];
$idoor = static function (string $operation, array $params = []) use ($IE, $WTOKEN): array {
    return $IE->handle(['token' => $WTOKEN, 'action' => 'content.contract', 'params' => ['operation' => $operation] + $params]);
};
check('a profile with an image slot binds', $idoor('bind', ['contract' => 'image-design/wp7/1.0.0'])['ok'], true);
$iinsp = $idoor('inspect');
check('the demo picture is not drift', $iinsp['problems'], []);
check('the image slot answers its picture', $iinsp['slots']['home.hero.img'], 'wp-content/uploads/2026/09/hero.png');
check('and the inspect names which slots take pictures, with the demo each replaces',
    $iinsp['imageSlots'], ['home.hero.img' => 'wp-content/uploads/2026/09/hero.png']);

$drawn = 'wp-content/uploads/tracy-content/' . hash('sha256', 'drawn') . '.jpg';
$apply = static function (string $value, string $id) use ($idoor): array {
    return $idoor('apply', ['expected_revision' => $idoor('inspect')['revision'], 'apply_id' => 'contract-' . $id, 'request_id' => $id,
        'changes' => ['home.hero.img' => $value]]);
};

$refusal = static function (array $answer): array {
    $e = $answer['errors'][0] ?? [];
    return [$answer['ok'], $e['code'] ?? null, $e['field']['slotKey'] ?? null, $e['severity'] ?? null];
};
check('a picture not in the media library is refused as SLOT_IMAGE_INVALID', $refusal($apply($drawn, 'i1')),
    [false, 'SLOT_IMAGE_INVALID', 'home.hero.img', 'recoverable']);
check('with the reason', $apply($drawn, 'i1')['errors'][0]['message'], 'Image must already be in the site media library: home.hero.img');
check('a file outside uploads is refused', $refusal($apply('wp-content/themes/test-theme/logo.png', 'i2'))[1], 'SLOT_IMAGE_INVALID');
check('so is a path that climbs out', $refusal($apply('wp-content/uploads/../../wp-config.png', 'i3'))[1], 'SLOT_IMAGE_INVALID');
check('so is an address instead of a path', $refusal($apply('https://example.test/a.jpg', 'i4'))[1], 'SLOT_IMAGE_INVALID');
check('so is an empty picture', $refusal($apply('', 'i5'))[1], 'SLOT_IMAGE_INVALID');

$img['images']->images[$drawn] = ['id' => 130, 'width' => 1600, 'height' => 900];
check('a picture of another shape is refused', $apply($drawn, 'i6')['errors'][0]['message'], 'Image aspect ratio does not match its slot: home.hero.img');

$img['images']->images[$drawn] = ['id' => 130, 'width' => 1200, 'height' => 1500];
$landed = $apply($drawn, 'i7');
check('a picture in the library, in the slot\'s shape, lands', $landed['ok'], true);
$page = (string) WP_Fake::$posts[10]['post_content'];
checkTrue('the page shows it', strpos($page, 'src="/' . $drawn . '"') !== false);
checkTrue('as its own attachment', strpos($page, '"id":130} -->') !== false && strpos($page, 'class="wp-image-130"') !== false);
$after = $idoor('inspect');
check('a changed picture is not drift either', $after['problems'], []);
check('the slot answers the new picture', $after['slots']['home.hero.img'], $drawn);
check('the text slot beside it is untouched', $after['slots']['home.hero.eyebrow'], 'Established 2004');

check('the apply reverts like any other', $IE->handle(['token' => $WTOKEN, 'action' => 'apply.revert', 'params' => ['apply_id' => 'contract-i7']])['ok'], true);
check('to the page it shipped as', WP_Fake::$posts[10]['post_content'], $IMAGE_HOME);

// ── a picture is the same picture in every language ─────────────────────────────────────────
//
// Measured 27/09/2026 on the r1w1734 stand site (Tracy Business, 40 Polylang editions): a drawn
// hero sent to `home.hero.img` landed on the English page only, while /vi/ kept the demo picture
// and the agent told the customer every language had changed. Joomla copies a source picture into
// every language in the same apply (claude-cowork joomla 0.16.16); WordPress now does the same.

$multi = sys_get_temp_dir() . '/cc-image-editions-' . bin2hex(random_bytes(4));
mkdir($multi . '/image-design/wp7/1.0.0', 0777, true);
foreach (['manifest.json', 'presentation-lock.json', 'content-map.json'] as $file) {
    copy($IMAGE_FIXTURES . '/image-design/wp7/1.0.0/' . $file, $multi . '/image-design/wp7/1.0.0/' . $file);
}
file_put_contents($multi . '/image-design/wp7/1.0.0/editions.json', json_encode([
    'schemaVersion' => QuickstartContract::EDITIONS_SCHEMA,
    'contract' => 'image-design/wp7/1.0.0',
    'baseHash' => DemoTrimProfile::baseHash($multi . '/image-design/wp7/1.0.0'),
    'source' => ['language' => 'en', 'locale' => 'en_US', 'tag' => 'en-us'],
    'locales' => [
        'en' => ['locale' => 'en_US', 'tag' => 'en-us', 'ids' => ['page-home' => 10], 'missing' => []],
        'vi' => ['locale' => 'vi', 'tag' => 'vi', 'ids' => ['page-home' => 60], 'missing' => []],
        // An edition that shipped without the hero picture: nothing to write there, nothing to refuse.
        'fr' => ['locale' => 'fr_FR', 'tag' => 'fr-fr', 'ids' => ['page-home' => 61], 'missing' => ['page-home:hero.img']],
    ],
]));
$VI_HOME = str_replace('Established 2004', 'Founded 2004 (vi)', $IMAGE_HOME);
$FR_HOME = (string) preg_replace('/<!-- wp:image .*?<!-- \/wp:image -->\s*/s', '', str_replace('Established 2004', 'Fondée en 2004', $IMAGE_HOME));
checkTrue('the French fixture really has no picture', strpos($FR_HOME, 'hero.img') === false);

$m = imageSite($IMAGE_HOME, $multi);
WP_Fake::$polylang = true;
WP_Fake::$languages = [
    'en' => ['term_id' => 5, 'name' => 'English', 'slug' => 'en', 'locale' => 'en_US', 'is_rtl' => 0, 'term_group' => 0, 'flag_code' => 'us'],
    'vi' => ['term_id' => 6, 'name' => 'Vietnamese', 'slug' => 'vi', 'locale' => 'vi', 'is_rtl' => 0, 'term_group' => 1, 'flag_code' => 'vn'],
    'fr' => ['term_id' => 7, 'name' => 'French', 'slug' => 'fr', 'locale' => 'fr_FR', 'is_rtl' => 0, 'term_group' => 2, 'flag_code' => 'fr'],
];
WP_Fake::$posts[60] = ['ID' => 60, 'post_type' => 'page', 'post_name' => 'home', 'post_status' => 'publish', 'post_parent' => 0, 'post_title' => 'Home (vi)', 'post_content' => $VI_HOME];
WP_Fake::$posts[61] = ['ID' => 61, 'post_type' => 'page', 'post_name' => 'home', 'post_status' => 'publish', 'post_parent' => 0, 'post_title' => 'Accueil', 'post_content' => $FR_HOME];
WP_Fake::$postLanguage = [10 => 'en', 60 => 'vi', 61 => 'fr'];
$ME = $m['engine'];
$mdoor = static function (string $operation, array $params = []) use ($ME, $WTOKEN): array {
    return $ME->handle(['token' => $WTOKEN, 'action' => 'content.contract', 'params' => ['operation' => $operation] + $params]);
};
check('a profile with image slots and editions binds', $mdoor('bind', ['contract' => 'image-design/wp7/1.0.0'])['ok'], true);
$m['images']->images[$drawn] = ['id' => 130, 'width' => 1200, 'height' => 1500];

$everywhere = $mdoor('apply', ['expected_revision' => $mdoor('inspect')['revision'], 'apply_id' => 'contract-m1', 'request_id' => 'm1',
    'changes' => ['home.hero.img' => $drawn]]);
check('a source picture lands', $everywhere['ok'], true);
check('on the source page and on every edition that shows it', array_column($everywhere['written'], 'id'), [10, 60]);
checkTrue('the Vietnamese page shows it', strpos((string) WP_Fake::$posts[60]['post_content'], 'src="/' . $drawn . '"') !== false);
checkTrue('as the same attachment', strpos((string) WP_Fake::$posts[60]['post_content'], 'class="wp-image-130"') !== false);
checkTrue('and keeps its own words', strpos((string) WP_Fake::$posts[60]['post_content'], 'Founded 2004 (vi)') !== false);
check('the edition without a picture is untouched', WP_Fake::$posts[61]['post_content'], $FR_HOME);
check('the apply reverts every page it wrote', $ME->handle(['token' => $WTOKEN, 'action' => 'apply.revert', 'params' => ['apply_id' => 'contract-m1']])['ok'], true);
check('the source as it shipped', WP_Fake::$posts[10]['post_content'], $IMAGE_HOME);
check('and the Vietnamese page as it shipped', WP_Fake::$posts[60]['post_content'], $VI_HOME);

// A picture sent for one edition by name is that edition's, and the source's does not overwrite it.
$own = 'wp-content/uploads/tracy-content/' . hash('sha256', 'own') . '.jpg';
$m['images']->images[$own] = ['id' => 131, 'width' => 800, 'height' => 1000];
$named = $mdoor('apply', ['expected_revision' => $mdoor('inspect')['revision'], 'apply_id' => 'contract-m2', 'request_id' => 'm2',
    'changes' => ['home.hero.img' => $drawn, 'vi::home.hero.img' => $own]]);
check('a source picture and a named edition picture land together', $named['ok'], true);
checkTrue('the named one wins on its edition', strpos((string) WP_Fake::$posts[60]['post_content'], 'src="/' . $own . '"') !== false);
checkTrue('the source keeps the source picture', strpos((string) WP_Fake::$posts[10]['post_content'], 'src="/' . $drawn . '"') !== false);

// Words are translated, so a source sentence stays on the source.
$words = $mdoor('apply', ['expected_revision' => $mdoor('inspect')['revision'], 'apply_id' => 'contract-m3', 'request_id' => 'm3',
    'changes' => ['home.hero.eyebrow' => 'Since 2003']]);
check('a source sentence writes the source page only', array_column($words['written'], 'id'), [10]);

// Held by content revisions, the caller read the source page only: its revision holds the copies too.
$previousDb = $GLOBALS['wpdb'];
$GLOBALS['wpdb'] = new WP_Fake_ContentDb();
$r = imageSite($IMAGE_HOME, $multi);
WP_Fake::$polylang = true;
WP_Fake::$languages = [
    'en' => ['term_id' => 5, 'name' => 'English', 'slug' => 'en', 'locale' => 'en_US', 'is_rtl' => 0, 'term_group' => 0, 'flag_code' => 'us'],
    'vi' => ['term_id' => 6, 'name' => 'Vietnamese', 'slug' => 'vi', 'locale' => 'vi', 'is_rtl' => 0, 'term_group' => 1, 'flag_code' => 'vn'],
    'fr' => ['term_id' => 7, 'name' => 'French', 'slug' => 'fr', 'locale' => 'fr_FR', 'is_rtl' => 0, 'term_group' => 2, 'flag_code' => 'fr'],
];
WP_Fake::$posts[60] = ['ID' => 60, 'post_type' => 'page', 'post_name' => 'home', 'post_status' => 'publish', 'post_parent' => 0, 'post_title' => 'Home (vi)', 'post_content' => $VI_HOME];
WP_Fake::$posts[61] = ['ID' => 61, 'post_type' => 'page', 'post_name' => 'home', 'post_status' => 'publish', 'post_parent' => 0, 'post_title' => 'Accueil', 'post_content' => $FR_HOME];
WP_Fake::$postLanguage = [10 => 'en', 60 => 'vi', 61 => 'fr'];
WP_Fake::$options[Claude_Cowork_Content_Source::SITE_OPTION] = str_repeat('ab', 16);
foreach ([10, 60, 61] as $id) {
    WP_Fake::$meta[$id . ':' . Claude_Cowork_Content_Source::UID_META] = md5('uid-' . $id);
}
$r['images']->images[$drawn] = ['id' => 130, 'width' => 1200, 'height' => 1500];
$writer = new Claude_Cowork_Site_Writer();
$RE = new Engine($WTOKEN, [], null, null, null, null, $writer, new FakeMediaWriter(), new FakeApplyLog(), null,
    new QuickstartContract($writer, new Claude_Cowork_Contract_Store(), contractRoot(json_decode((string) file_get_contents(__DIR__ . '/fixtures/contracts/site.json'), true)),
        $multi, '', new Claude_Cowork_Content_Revisions($multi, 'test'), $r['images']));
$rdoorI = static function (string $operation, array $params = []) use ($RE, $WTOKEN): array {
    return $RE->handle(['token' => $WTOKEN, 'action' => 'content.contract', 'params' => ['operation' => $operation] + $params]);
};
check('bound again, for content revisions', $rdoorI('bind', ['contract' => 'image-design/wp7/1.0.0'])['ok'], true);
$source = new Claude_Cowork_Content_Source('editorial', $multi, 'test');
$home = $source->revisionsOf([['kind' => 'post', 'id' => 10, 'key' => '']])[0];
$source->release();
$held = $rdoorI('apply', ['expected_content_revisions' => [$home['id'] => $home['revision']], 'apply_id' => 'contract-m4', 'request_id' => 'm4',
    'changes' => ['home.hero.img' => $drawn]]);
check('held by the source page\'s revision alone, the picture reaches the edition', [$held['ok'], array_column($held['written'] ?? [], 'id')], [true, [10, 60]]);
$GLOBALS['wpdb'] = $previousDb;

// A profile whose image slot forgot its demo attachment cannot hold the page's structure.
$broken = sys_get_temp_dir() . '/cc-image-profile-' . bin2hex(random_bytes(4));
mkdir($broken . '/image-design/wp7/1.0.0', 0777, true);
foreach (['manifest.json', 'presentation-lock.json'] as $file) {
    copy($IMAGE_FIXTURES . '/image-design/wp7/1.0.0/' . $file, $broken . '/image-design/wp7/1.0.0/' . $file);
}
$map = json_decode((string) file_get_contents($IMAGE_FIXTURES . '/image-design/wp7/1.0.0/content-map.json'), true);
unset($map['slots'][1]['sampleId']);
file_put_contents($broken . '/image-design/wp7/1.0.0/content-map.json', json_encode($map));
$bad = imageSite($IMAGE_HOME, $broken);
$badInspect = $bad['engine']->handle(['token' => $WTOKEN, 'action' => 'content.contract', 'params' => ['operation' => 'inspect', 'contract' => 'image-design/wp7/1.0.0']]);
check('an image slot without its demo attachment is named', $badInspect['problems'], ['Slot home.hero.img: an image slot needs sample and sampleId']);

// The shipped Tracy Business profiles gained image slots IN PLACE: the presentation lock is the same
// bytes, so a site sealed before them (base 115c3f…, the r1w1734 stand site on 26/09/2026) is still
// accepted and moves to the new bytes on its next validated write.
foreach (['1.1.0' => '115c3fbd719a5d2c92e1117f617564b87fb846c1f7ddb235f27a68def619f520'] as $version => $before) {
    $superseded = json_decode((string) file_get_contents(__DIR__ . '/../lib/contracts/tracy-business/wp7/' . $version . '/superseded.json'), true);
    checkTrue("tracy-business/wp7/{$version}: a site sealed before its image slots is still accepted",
        in_array($before, array_column($superseded['accepts'], 'baseHash'), true));
    check("tracy-business/wp7/{$version}: under the same presentation lock", $superseded['presentationLock'],
        hash('sha256', (string) file_get_contents(__DIR__ . '/../lib/contracts/tracy-business/wp7/' . $version . '/presentation-lock.json')));
}
