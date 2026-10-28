<?php
// Loaded by run.php: Tracy's Joomla capture with `--whole-units` (capture-contract.py, whole_units())
// addresses a heading, sentence or list item cut into several text nodes by the xpath of its element
// (`/html/body/div/h2`), and a fragment with no such element by the root (`/html/body/div`). Its sample
// is the cut pieces joined with one space, every whitespace run collapsed to one space, trimmed. Such a
// slot must read back as exactly that sample, and must be writable. All content below is fictional.

$wuRead = function (string $html, string $xpath): string {
    $nodes = (new DOMXPath(ContentSlots::html($html)))->query($xpath);
    return ContentSlots::slotText($nodes->item(0));
};
$wuWrite = fn(string $html, string $xpath, string $value): string => ContentSlots::htmlPatch($html, [['xpath' => $xpath, 'value' => $value]]);

// ---- Read: the capture's sample, not the DOM's textContent.
check('whole unit: a heading cut by a span reads as the capture sample',
    $wuRead('<h2>NO1 <span>boxing</span> club since 2008</h2>', '/html/body/div/h2'), 'NO1 boxing club since 2008');
check('whole unit: a <br> between two words reads as one space, not two',
    $wuRead('THE <br> WORKOUT', '/html/body/div'), 'THE WORKOUT');
check('whole unit: pieces with no space between them are joined with one, as capture joins them',
    $wuRead('THE <br>WORKOUT <b>YARD</b>', '/html/body/div'), 'THE WORKOUT YARD');
check('whole unit: line breaks, tabs and no-break spaces collapse to one space, ends trimmed',
    $wuRead("<p>\n  Call\t<strong>Wren\u{00A0}\u{00A0}Example</strong>\n today </p>", '/html/body/div/p'), 'Call Wren Example today');
check('whole unit: a nested heading or paragraph is its own unit and is left out of the outer one',
    $wuRead('<li>Plans <em>below</em><p>Not this</p></li>', '/html/body/div/li'), 'Plans below');
check('whole unit: script text and a Joomla directive are not part of the visible unit',
    $wuRead('<p>Visit <b>us</b><script>var x = 1;</script> {loadposition side}</p>', '/html/body/div/p'), 'Visit us');
check('a text-node slot still reads its node exactly, spaces included',
    $wuRead('<h3>  Single  text heading </h3>', '/html/body/div/h3/text()'), '  Single  text heading ');
check('an attribute slot still reads its value exactly',
    $wuRead('<p><a href="tel:+000-0100">us</a></p>', '/html/body/div/p/a/@href'), 'tel:+000-0100');

// ---- Write: the element and its attributes stay; the visible text becomes the new text.
check('whole unit: a heading cut by a span becomes one text node in the same element',
    $wuWrite('<h2 class="hero-title">NO1 <span class="accent">boxing</span> club since 2008</h2>', '/html/body/div/h2', 'Fictional new heading'),
    '<h2 class="hero-title">Fictional new heading</h2>');
check('whole unit: the root fragment loses its <br> and keeps no stray markup',
    $wuWrite('THE <br> WORKOUT', '/html/body/div', 'Fictional new heading'), 'Fictional new heading');
check('whole unit: strong and em around a piece go with the piece',
    $wuWrite('<p>Call <strong>Wren <em>Example</em></strong> today</p><p>Next</p>', '/html/body/div/p[1]', 'Fictional sentence'),
    '<p>Fictional sentence</p><p>Next</p>');
check('whole unit: a list item is written alone, its siblings untouched',
    $wuWrite('<ul><li><a href="#">Classes</a></li><li>Plain <em>item</em></li></ul>', '/html/body/div/ul/li[2]', 'Fictional item'),
    '<ul><li><a href="#">Classes</a></li><li>Fictional item</li></ul>');
check('whole unit: the new text is literal text, never markup',
    $wuWrite('<h2>Old <span>title</span></h2>', '/html/body/div/h2', 'Fish & <chips>'), '<h2>Fish &amp; &lt;chips&gt;</h2>');
check('whole unit: what was written reads back as the value',
    $wuRead($wuWrite('<h2>NO1 <span>boxing</span> club</h2>', '/html/body/div/h2', 'Fictional new heading'), '/html/body/div/h2'), 'Fictional new heading');

// What is not part of the unit's text is kept: another slot's link, an icon, a nested unit, a directive.
$wuLink = $wuWrite('<p>Call <a href="tel:+000-0100">us</a> today</p>', '/html/body/div/p', 'Fictional sentence');
check('whole unit: a link inside the unit keeps its href, so its url slot still resolves',
    [$wuLink, $wuRead($wuLink, '/html/body/div/p/a/@href')], ['<p>Fictional sentence<a href="tel:+000-0100"></a></p>', 'tel:+000-0100']);
check('whole unit: an icon that held no text is kept where it was',
    $wuWrite('<h3><i class="icon-star"></i> Top <b>rated</b></h3>', '/html/body/div/h3', 'Fictional heading'),
    '<h3><i class="icon-star"></i> Fictional heading</h3>');
check('whole unit: a nested paragraph is another unit and is not overwritten',
    $wuWrite('<li>Plans <em>below</em><p>Keep me</p></li>', '/html/body/div/li', 'Fictional item'),
    '<li>Fictional item<p>Keep me</p></li>');
check('whole unit: a Joomla directive inside the unit survives the write',
    $wuWrite('<p>Visit <b>us</b> {loadposition side}</p>', '/html/body/div/p', 'Fictional sentence'),
    '<p>Fictional sentence {loadposition side}</p>');

// ---- Text-node and attribute slots behave exactly as before.
check('a text-node slot is still written in place, its siblings untouched',
    $wuWrite('<ul><li><a href="#">Classes</a></li><li>Plain <em>item</em></li></ul>', '/html/body/div/ul/li[1]/a/text()', 'Fictional label'),
    '<ul><li><a href="#">Fictional label</a></li><li>Plain <em>item</em></li></ul>');
check('a single-text heading is still written through its text node',
    $wuWrite('<h3>Single text heading</h3>', '/html/body/div/h3/text()', 'Fictional heading'), '<h3>Fictional heading</h3>');
check('an attribute slot is still written with a bare & escaped',
    $wuWrite('<a href="/old">Go</a>', '/html/body/div/a/@href', '/new?a=1&b=2'), '<a href="/new?a=1&amp;b=2">Go</a>');
try { $wuWrite('<p>One</p><p>Two</p>', '/html/body/div/p', 'x'); $wuAmbiguous = 'written'; }
catch (RuntimeException $e) { $wuAmbiguous = $e->getMessage(); }
check('an xpath that matches two elements is still refused as ambiguous', $wuAmbiguous, 'HTML slot is missing or ambiguous');

// ---- Through the contract: inspect reads the capture sample (no false change), apply writes the unit.
$wuDir = sys_get_temp_dir() . '/cowork-whole-units-' . bin2hex(random_bytes(6));
mkdir($wuDir);
$wuHero = ['title' => 'Hero', 'module' => 'mod_custom', 'position' => 'masthead', 'published' => '1', 'access' => '1',
    'language' => '*', 'client_id' => '0', 'params' => '{"style":"0"}',
    'content' => "<h2>NO1 <span>boxing</span> club since 2008</h2><p>Call <a href=\"tel:+000-0100\">us</a> today</p><h3>THE <br> WORKOUT</h3>"];
$wuSlot = fn(string $key, string $type, string $sample, string $xpath) =>
    ['key' => $key, 'entity' => 'hero', 'column' => 'content', 'maxCharacters' => 120, 'type' => $type, 'sample' => $sample, 'xpath' => $xpath];
// The slots exactly as capture writes them with --whole-units.
$wuSlots = [
    $wuSlot('hero.heading', 'text', 'NO1 boxing club since 2008', '/html/body/div/h2'),
    $wuSlot('hero.lead', 'text', 'Call us today', '/html/body/div/p'),
    $wuSlot('hero.phone', 'url', 'tel:+000-0100', '/html/body/div/p/a/@href'),
    $wuSlot('hero.kicker', 'text', 'THE WORKOUT', '/html/body/div/h3'),
];
$wuWriter = new FakeSiteWriter();
$wuWriter->store['module'][120] = ['id' => '120'] + $wuHero;
$wuWriter->store['moduleAssignment'][120] = ['menuids' => '[0]'];
$wuFiles = [
    'manifest' => ['id' => 'whole-units/v1'],
    'content-map' => ['entities' => [['key' => 'hero', 'kind' => 'module', 'sourceId' => 20, 'identity' => ['title' => 'Hero']]],
        'slots' => $wuSlots, 'pages' => []],
    'presentation-lock' => ['entities' => ['hero' => $wuHero], 'assignments' => [['moduleid' => 20, 'menuid' => 0]],
        'fileRoots' => [], 'files' => [], 'inventoryCounts' => ['module' => 1], 'access' => (new TestContractStore())->acl],
];
foreach ($wuFiles as $name => $body) file_put_contents($wuDir . '/' . $name . '.json', json_encode($body));
$wuContract = new QuickstartContract($wuWriter, new TestContractStore(), $wuDir, $wuDir);
$wuState = $wuContract->inspect();
check('contract: an untouched site reads every whole-unit slot as its capture sample',
    $wuState['slotValues'], ['hero.heading' => 'NO1 boxing club since 2008', 'hero.lead' => 'Call us today',
        'hero.phone' => 'tel:+000-0100', 'hero.kicker' => 'THE WORKOUT']);
$wuContract->bind($wuState['snapshot']);
$wuOps = $wuContract->plan(['expected_revision' => $wuState['revision'],
    'changes' => ['hero.heading' => 'Fictional boxing club', 'hero.kicker' => 'Train with us', 'hero.phone' => 'tel:+000-0199']])['operations'];
foreach ($wuOps as $op) $wuWriter->store[$op['kind']][$op['id']] = $op['fields'] + $wuWriter->store[$op['kind']][$op['id']];
check('contract: the written module keeps its elements and drops the inline markup of the units it rewrote',
    $wuWriter->store['module'][120]['content'],
    '<h2>Fictional boxing club</h2><p>Call <a href="tel:+000-0199">us</a> today</p><h3>Train with us</h3>');
$wuAfter = $wuContract->inspect();
check('contract: the next inspect reads the new values back, the untouched sentence unchanged',
    $wuAfter['slotValues'], ['hero.heading' => 'Fictional boxing club', 'hero.lead' => 'Call us today',
        'hero.phone' => 'tel:+000-0199', 'hero.kicker' => 'Train with us']);
array_map('unlink', glob($wuDir . '/*.json')); rmdir($wuDir);
