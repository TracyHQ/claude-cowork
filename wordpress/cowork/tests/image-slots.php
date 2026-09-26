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
