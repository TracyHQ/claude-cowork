<?php
/**
 * Builder fixtures — loaded by run.php. Proves LeafCodec (lib/LeafCodec.php, identical in both engines)
 * reads and rewrites the actual RAW column values popular WordPress page builders and the classic
 * customizer/widgets leave behind, not just the small hand-written strings in tests/leaf-codec.php.
 *
 * Each fixtures/builders/<name>.txt is a raw stored value; <name>.expect.json lists the visible
 * texts/urls a visitor would see, which leaves() must find (a subset — extra structural leaves such
 * as an internal id or a "type" discriminator are not asserted against). Every fixture here is one a
 * site owner can edit through the CMS, so each is also round-tripped: set() one leaf, then confirm only
 * that leaf moved (leaves() elsewhere byte-identical, get() reads the new value, and setting the old
 * value back restores the raw bytes exactly).
 */
require_once __DIR__ . '/../lib/LeafCodec.php';
echo "\nBuilder fixtures\n";

$bfDir = __DIR__ . '/fixtures/builders';

function bfRead(string $name): string
{
    global $bfDir;
    return file_get_contents("{$bfDir}/{$name}.txt");
}

function bfExpect(string $name): array
{
    global $bfDir;
    return json_decode(file_get_contents("{$bfDir}/{$name}.expect.json"), true);
}

/** @param list<array{path:string,type:string,text:string}> $leaves */
function bfPathOf(array $leaves, string $text): ?string
{
    foreach ($leaves as $leaf) {
        if ($leaf['text'] === $text) return $leaf['path'];
    }
    return null;
}

/**
 * leaves() must find every visible text/url in the fixture's .expect.json (a subset of what it finds).
 * For a writable fixture: set() the first expected leaf we can locate, then require that leaf's new
 * value to read back, every other leaf to be byte-identical, and setting the old value back to restore
 * the raw bytes exactly (the strongest available proof that nothing outside that one leaf moved).
 */
function bfCheckFixture(string $name, bool $writable, string $newValueSuffix = ' — updated'): void
{
    $raw = bfRead($name);
    $expect = bfExpect($name);
    $leaves = LeafCodec::leaves($raw);
    $texts = array_column($leaves, 'text');
    $missing = array_values(array_diff($expect, $texts));
    checkTrue("builder: {$name} leaves() finds every visible text in the fixture", $missing === []);

    if (!$writable) return;

    $target = null;
    foreach ($expect as $text) {
        $path = bfPathOf($leaves, $text);
        if ($path !== null) { $target = [$text, $path]; break; }
    }
    checkTrue("builder: {$name} has a located leaf to round trip", $target !== null);
    if ($target === null) return;
    [$oldText, $path] = $target;
    $newText = $oldText . $newValueSuffix;

    $updated = LeafCodec::set($raw, $path, $newText);
    check("builder: {$name} get() reads the new value", LeafCodec::get($updated, $path), $newText);

    $before = [];
    foreach ($leaves as $l) if ($l['path'] !== $path) $before[$l['path']] = $l['text'];
    $after = [];
    foreach (LeafCodec::leaves($updated) as $l) if ($l['path'] !== $path) $after[$l['path']] = $l['text'];
    check("builder: {$name} every other leaf is byte-identical after the write", $after, $before);

    $restored = LeafCodec::set($updated, $path, $oldText);
    check("builder: {$name} setting the old value back restores the raw bytes exactly", $restored, $raw);
}

bfCheckFixture('block-theme', true);
bfCheckFixture('elementor', true, ' — café');
bfCheckFixture('divi', true, ' — updated');
bfCheckFixture('wpbakery', true);
bfCheckFixture('theme-mods', true);
bfCheckFixture('widget-text', true);
bfCheckFixture('widget-block', true);
bfCheckFixture('acf-option', true);
bfCheckFixture('acf-repeater', true);
bfCheckFixture('woocommerce-content', true);
bfCheckFixture('woocommerce-attributes', true);

// WPBakery's vc_btn (and vc_button2, vc_cta, ...) keeps its href in a percent-encoded mini format,
// link="url:https%3A%2F%2Fceramics.example.com%2Fstudio|title:See%20the%20Studio|target:_blank".
// LeafCodec's `vcl` codec splits it: the url is a url-typed leaf and can be repointed with set().
$bfWpbRaw = bfRead('wpbakery');
$bfWpbLeaves = LeafCodec::leaves($bfWpbRaw);
$bfWpbLinkUrl = 'https://ceramics.example.com/studio';
$bfWpbLinkPath = bfPathOf($bfWpbLeaves, $bfWpbLinkUrl);
checkTrue('builder: wpbakery vc_btn link is decomposed into a url-typed leaf', $bfWpbLinkPath !== null
    && in_array(['type' => 'url', 'text' => $bfWpbLinkUrl], array_map(static fn($l) => ['type' => $l['type'], 'text' => $l['text']], $bfWpbLeaves), true));
if ($bfWpbLinkPath !== null) {
    $bfWpbMoved = LeafCodec::set($bfWpbRaw, $bfWpbLinkPath, 'https://ceramics.example.com/visit');
    checkTrue('builder: wpbakery vc_btn link set() rewrites only the url part',
        strpos($bfWpbMoved, 'link="url:https%3A%2F%2Fceramics.example.com%2Fvisit|title:See%20the%20Studio|target:_blank"') !== false);
    check('builder: wpbakery vc_btn link restores the raw bytes exactly', LeafCodec::set($bfWpbMoved, $bfWpbLinkPath, $bfWpbLinkUrl), $bfWpbRaw);
}
