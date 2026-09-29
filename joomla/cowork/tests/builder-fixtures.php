<?php
/**
 * Builder fixtures — loaded by run.php. Proves LeafCodec (lib/LeafCodec.php, identical in both engines)
 * reads and rewrites the actual RAW column values Joomla's popular site builders leave behind, not just
 * the small hand-written strings in tests/leaf-codec.php.
 *
 * Each fixtures/builders/<name>.txt is a raw stored value; <name>.expect.json lists the visible
 * texts/urls a visitor would see, which leaves() must find (a subset — extra structural leaves such as
 * an internal id or a "type" discriminator are not asserted against). Three of the four fixtures here
 * are writable in v1 (JA ACM module params, Cassiopeia template style params, YOOtheme builder JSON) and
 * are round-tripped: set() one leaf, then confirm only that leaf moved (leaves() elsewhere
 * byte-identical, get() reads the new value, and setting the old value back restores the raw bytes
 * exactly). SP Page Builder's #__sppagebuilder.content is read-only in v1 (see the plan's "Deliberate deviations" —
 * an arbitrary extension table is not opened to writes), so it is only checked with leaves().
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

// JA ACM (T4) module params: top-level JSON where "slides" is itself a JSON-encoded string — the
// nested-config-inside-JSON shape ACM and several JA modules use for carousel/list items.
// LeafCodec splices only the edited string token at each JSON layer, so the outer layer's unescaped
// slashes and the inner layer's \/ escapes both survive a write byte for byte.
bfCheckFixture('ja-acm-module-params', true);
// Cassiopeia template style params: flat JSON, siteTitle/siteDescription/metaDescription are rendered
// into <title>/<meta>, colorScheme and similar keys are not visible text.
bfCheckFixture('cassiopeia-style-params', true, ' — mise à jour');
// YOOtheme Pro: #__template_styles.params is JSON whose "builder" key is itself a JSON-encoded string
// holding the section/row/column/element tree — two JSON layers deep, same shape as JA ACM's "slides".
bfCheckFixture('yootheme-builder-params', true);
// SP Page Builder: #__sppagebuilder.content, rows -> columns -> addons -> settings. Read-only in v1
// (an arbitrary extension table is not opened to content.contract writes — see the plan), so leaves()
// only, no set()/get() round trip.
bfCheckFixture('sppagebuilder-content', false);
