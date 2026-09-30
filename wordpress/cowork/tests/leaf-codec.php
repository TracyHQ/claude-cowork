<?php
// Loaded by run.php. LeafCodec finds visitor-readable leaves in nested stored values and rewrites one.
require_once __DIR__ . '/../lib/LeafCodec.php';
echo "\nLeaf codec\n";

$lcPlain = 'Welcome to Northwind';
check('codec: plain text is one leaf', LeafCodec::leaves($lcPlain), [['path' => 'text:', 'type' => 'text', 'text' => 'Welcome to Northwind']]);
check('codec: plain set', LeafCodec::set($lcPlain, 'text:', 'Hello'), 'Hello');

$lcHtml = '<h2 class="x">Our &amp; story</h2><p>Since <a href="/about">our history</a></p><script>var a="no";</script><img src="/a.jpg" alt="Team photo">';
$lcLeaves = LeafCodec::leaves($lcHtml);
check('codec: html text leaves decode entities, skip script', array_column($lcLeaves, 'text'), ['Our & story', 'Since', '/about', 'our history', '/a.jpg', 'Team photo']);
check('codec: html types', array_column($lcLeaves, 'type'), ['text', 'text', 'url', 'text', 'image', 'text']);
$lcPath = $lcLeaves[0]['path'];
check('codec: html set escapes and keeps markup', LeafCodec::set($lcHtml, $lcPath, 'A <new> story'),
    '<h2 class="x">A &lt;new&gt; story</h2><p>Since <a href="/about">our history</a></p><script>var a="no";</script><img src="/a.jpg" alt="Team photo">');

$lcSc = '[et_pb_section][et_pb_text admin_label="Intro" title="Big title"]<p>Hello world</p>[/et_pb_text][/et_pb_section]';
$lcScLeaves = LeafCodec::leaves($lcSc);
check('codec: shortcode attr and nested html content', array_column($lcScLeaves, 'text'), ['Intro', 'Big title', 'Hello world']);
$lcHello = $lcScLeaves[2]['path'];
check('codec: shortcode nested set', LeafCodec::set($lcSc, $lcHello, 'Hi there'),
    '[et_pb_section][et_pb_text admin_label="Intro" title="Big title"]<p>Hi there</p>[/et_pb_text][/et_pb_section]');

$lcJson = '[{"id":"a1","elType":"widget","settings":{"title":"Fast delivery","link":{"url":"https:\/\/x.test\/go"},"_css_classes":"hero-title"}}]';
$lcJsonLeaves = LeafCodec::leaves($lcJson);
check('codec: json leaves skip ids and css keys', array_column($lcJsonLeaves, 'text'), ['Fast delivery', 'https://x.test/go']);
$lcSet = LeafCodec::set($lcJson, $lcJsonLeaves[0]['path'], 'Giao hàng nhanh');
check('codec: json set keeps escaped slashes and writes unicode as the source did', $lcSet,
    '[{"id":"a1","elType":"widget","settings":{"title":"Giao h\u00e0ng nhanh","link":{"url":"https:\/\/x.test\/go"},"_css_classes":"hero-title"}}]');

$lcSer = serialize(['header_text' => '<strong>Call us</strong> today', 'color' => '#ff0000', 'count' => 3]);
$lcSerLeaves = LeafCodec::leaves($lcSer);
check('codec: serialize with nested html', array_column($lcSerLeaves, 'text'), ['Call us', 'today']);
$lcSerNew = LeafCodec::set($lcSer, $lcSerLeaves[0]['path'], 'Gọi ngay');
check('codec: serialize recomputes byte lengths', unserialize($lcSerNew)['header_text'], '<strong>Gọi ngay</strong> today');

$lcObj = 'O:8:"stdClass":1:{s:1:"a";s:5:"hello";}';
check('codec: serialized objects are opaque', LeafCodec::leaves($lcObj), []);
$lcThrown = null;
try { LeafCodec::set($lcJson, 'json:/0/missing', 'x'); } catch (LeafCodecError $e) { $lcThrown = $e->getMessage(); }
check('codec: a path that does not exist is refused', $lcThrown !== null, true);
check('codec: get reads a leaf', LeafCodec::get($lcSc, $lcHello), 'Hello world');
check('codec: slugs, colours and numbers are not text', LeafCodec::leaves('hero-title_2'), []);

$lcBlock = '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button {"text":"Buy now","url":"/shop"} /--></div><!-- /wp:buttons -->';
$lcBlockLeaves = LeafCodec::leaves($lcBlock);
check('codec: block comment attributes are leaves', array_column($lcBlockLeaves, 'text'), ['Buy now', '/shop']);
check('codec: block comment set keeps the comment', LeafCodec::set($lcBlock, $lcBlockLeaves[0]['path'], 'Mua ngay'),
    '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button {"text":"Mua ngay","url":"/shop"} /--></div><!-- /wp:buttons -->');
check('codec: numbers alone are not text', LeafCodec::leaves('2004'), []);

// JSON writes splice one string token; nothing outside it is re-encoded.
$lcSpaced = "{ \"a\" : \"x\\/y\",\n  \"n\": 1.50, \"title\" : \"Old\", \"z\":\"caf\u{e9}\" }";
check('codec: json splice keeps spacing, numbers and other escapes byte-identical',
    LeafCodec::set($lcSpaced, 'json:/title|text:', 'New'),
    "{ \"a\" : \"x\\/y\",\n  \"n\": 1.50, \"title\" : \"New\", \"z\":\"caf\u{e9}\" }");
$lcNested = '{"layout":"carousel","slides":"[{\"title\":\"Autumn Sale\",\"link\":\"https:\\\\/\\\\/example.com\\\\/sale\"}]","note":"1/2"}';
$lcNestedLeaves = LeafCodec::leaves($lcNested);
check('codec: nested json-in-string leaves', array_column($lcNestedLeaves, 'text'), ['Autumn Sale', 'https://example.com/sale']);
$lcNestedSet = LeafCodec::set($lcNested, $lcNestedLeaves[0]['path'], 'Spring Sale');
check('codec: nested json-in-string set touches only the addressed token at each layer', $lcNestedSet,
    '{"layout":"carousel","slides":"[{\"title\":\"Spring Sale\",\"link\":\"https:\\\\/\\\\/example.com\\\\/sale\"}]","note":"1/2"}');
check('codec: nested json-in-string restores exactly', LeafCodec::set($lcNestedSet, $lcNestedLeaves[0]['path'], 'Autumn Sale'), $lcNested);
check('codec: nested url set keeps the inner \/ style', LeafCodec::set($lcNested, $lcNestedLeaves[1]['path'], 'https://example.com/spring'),
    '{"layout":"carousel","slides":"[{\"title\":\"Autumn Sale\",\"link\":\"https:\\\\/\\\\/example.com\\\\/spring\"}]","note":"1/2"}');
$lcMixed = '{"a":"https:\/\/x.test\/","b":"https://y.test/"}';
check('codec: \/ style is taken per token (escaped token)', LeafCodec::set($lcMixed, 'json:/a|text:', 'https://x.test/new'),
    '{"a":"https:\/\/x.test\/new","b":"https://y.test/"}');
check('codec: \/ style is taken per token (plain token)', LeafCodec::set($lcMixed, 'json:/b|text:', 'https://y.test/new'),
    '{"a":"https:\/\/x.test\/","b":"https://y.test/new"}');
check('codec: raw UTF-8 source keeps unicode unescaped', LeafCodec::set('{"t":"Tiêu đề","u":"Old"}', 'json:/u|text:', 'Mới'),
    '{"t":"Tiêu đề","u":"Mới"}');
check('codec: json array index and duplicate key (last wins, like json_decode)',
    LeafCodec::set('{"k":["zero","one"],"k":["two","three"]}', 'json:/k/1|text:', 'drei'), '{"k":["zero","one"],"k":["two","drei"]}');

// WPBakery link mini-format.
$lcVcl = '[vc_btn title="Go" link="url:https%3A%2F%2Fceramics.example.com%2Fstudio|title:See%20the%20Studio|target:_blank"][/vc_btn]';
$lcVclLeaves = LeafCodec::leaves($lcVcl);
check('codec: vcl url and title are leaves', array_map(static fn($l) => [$l['type'], $l['text']], $lcVclLeaves),
    [['text', 'Go'], ['url', 'https://ceramics.example.com/studio'], ['text', 'See the Studio']]);
check('codec: vcl detect', LeafCodec::detect('url:%2Fshop|target:_blank'), 'vcl');
$lcVclUrl = LeafCodec::set($lcVcl, $lcVclLeaves[1]['path'], 'https://ceramics.example.com/visit us');
check('codec: vcl url set percent-encodes that part only', $lcVclUrl,
    '[vc_btn title="Go" link="url:https%3A%2F%2Fceramics.example.com%2Fvisit%20us|title:See%20the%20Studio|target:_blank"][/vc_btn]');
check('codec: vcl url round trip restores the raw', LeafCodec::set($lcVclUrl, $lcVclLeaves[1]['path'], 'https://ceramics.example.com/studio'), $lcVcl);
check('codec: vcl title set', LeafCodec::set($lcVcl, $lcVclLeaves[2]['path'], 'Xem xưởng | 2'),
    '[vc_btn title="Go" link="url:https%3A%2F%2Fceramics.example.com%2Fstudio|title:Xem%20x%C6%B0%E1%BB%9Fng%20%7C%202|target:_blank"][/vc_btn]');

// Review round 1.
// vcl needs url: first and no raw whitespace, so "title: ..." prose stays plain text.
check('codec: prose that starts with title: is text, not vcl', LeafCodec::detect('title: Hello there'), 'text');
check('codec: prose title: in json is one text leaf', LeafCodec::leaves('{"t":"title: Hello there"}'),
    [['path' => 'json:/t|text:', 'type' => 'text', 'text' => 'title: Hello there']]);
check('codec: prose title: in json writes plain text', LeafCodec::set('{"t":"title: Hello there"}', 'json:/t|text:', 'Bye now'), '{"t":"Bye now"}');
check('codec: target: with spaces is text', LeafCodec::detect('target: x y'), 'text');
check('codec: vcl with empty trailing parts', LeafCodec::detect('url:%23|||'), 'vcl');
check('codec: vcl with trailing pipe', LeafCodec::detect('url:http%3A%2F%2Fa.test|title:A|target:%20_blank|'), 'vcl');
check('codec: vcl empty parts are skipped', array_column(LeafCodec::leaves('url:http%3A%2F%2Fa.test||title:Go%20on|'), 'text'), ['http://a.test', 'Go on']);
// Duplicate vcl keys: only the first occurrence is a leaf and is the one written.
$lcVclDup = 'url:%2Fa|title:One|title:Two';
check('codec: vcl duplicate key exposes only the first', array_column(LeafCodec::leaves($lcVclDup), 'text'), ['/a', 'One']);
check('codec: vcl duplicate key writes the first', LeafCodec::set($lcVclDup, 'vcl:title|text:', 'Uno'), 'url:%2Fa|title:Uno|title:Two');

// A | inside a JSON key is escaped as ~2 in the pointer.
$lcPipeKey = '{"a|b":{"c/d~e":"Hello there"}}';
check('codec: json key with | / ~ gets an escaped pointer', array_column(LeafCodec::leaves($lcPipeKey), 'path'), ['json:/a~2b/c~1d~0e|text:']);
check('codec: json key with | writes', LeafCodec::set($lcPipeKey, 'json:/a~2b/c~1d~0e|text:', 'Bye'), '{"a|b":{"c/d~e":"Bye"}}');

// The target token's own escapes win over the rest of the document.
check('codec: token \" wins over \u0022 elsewhere', LeafCodec::set('{"a":"x \u0022y\u0022","b":"say \"hi\""}', 'json:/b|text:', 'say "yo"'),
    '{"a":"x \u0022y\u0022","b":"say \"yo\""}');
check('codec: token raw < wins over \u003c elsewhere', LeafCodec::set('{"a":"\u003cb\u003e bold","b":"x <i> y"}', 'json:/b|text:', 'x <em> y'),
    '{"a":"\u003cb\u003e bold","b":"x <em> y"}');
check('codec: token \u003c style is kept', LeafCodec::set('{"b":"x \u003ci\u003e y"}', 'json:/b|text:', 'x <em> y'), '{"b":"x \u003cem\u003e y"}');

// No slash evidence: escape like json_encode, except in a block comment, where WordPress does not.
check('codec: no slash evidence escapes slashes', LeafCodec::set('{"t":"Hello"}', 'json:/t|text:', 'a/b'), '{"t":"a\/b"}');
check('codec: no slash evidence in a block comment keeps slashes plain',
    LeafCodec::set('<!-- wp:button {"text":"Buy"} /-->', 'html:c0|json:/text|text:', 'a/b'), '<!-- wp:button {"text":"a/b"} /-->');

// Unescaped unicode also leaves U+2028/U+2029 unescaped.
check('codec: unescaped unicode keeps line terminators raw', LeafCodec::set('{"t":"café"}', 'json:/t|text:', "a\u{2028}b"), "{\"t\":\"a\u{2028}b\"}");

// Block comment JSON escapes -- < > the way serialize_block_attributes does, so the comment stays closed.
$lcBlockArrow = LeafCodec::set('<!-- wp:button {"text":"Buy"} /-->', 'html:c0|json:/text|text:', 'a --> b <c>');
check('codec: block comment escapes -- < >', $lcBlockArrow, '<!-- wp:button {"text":"a \u002d\u002d\u003e b \u003cc\u003e"} /-->');
check('codec: block comment escaped value reads back', LeafCodec::get($lcBlockArrow, 'html:c0|json:/text|text:'), 'a --> b <c>');
// One column with thousands of leaves (a T4 template style's params on an imported site, measured
// 30/09/2026: 2 s per content.read): reading every leaf must not parse the whole column once per leaf.
$lcBig = [];
for ($lcI = 0; $lcI < 3000; $lcI++) $lcBig['block' . $lcI] = ['title' => 'Heading number ' . $lcI, 'body' => '<p>Paragraph ' . $lcI . ' <a href="/page-' . $lcI . '">more</a></p>', 'color' => '#fff'];
$lcBigRaw = json_encode(['sections' => $lcBig]);
$lcBigLeaves = LeafCodec::leaves($lcBigRaw);
$lcStarted = microtime(true);
$lcBigRead = [];
foreach ($lcBigLeaves as $lcLeaf) $lcBigRead[] = LeafCodec::get($lcBigRaw, $lcLeaf['path']);
$lcBigMs = (microtime(true) - $lcStarted) * 1000;
check('leaf: every leaf of a 3000-block column reads back', [count($lcBigLeaves), $lcBigRead[0], $lcBigRead[count($lcBigRead) - 1]], [12000, 'Heading number 0', 'more']);
// Linear, not quadratic: the old read path took 7.3 s here; slow CI runners take under 1 s.
checkTrue('leaf: and reading them all takes well under the old 7 s (' . round($lcBigMs) . ' ms)', $lcBigMs < 3000);
$lcBigSet = LeafCodec::set($lcBigRaw, $lcBigLeaves[4]['path'], 'Heading one');
check('leaf: a write after those reads still splices the one leaf', [LeafCodec::get($lcBigSet, $lcBigLeaves[4]['path']), LeafCodec::get($lcBigSet, $lcBigLeaves[0]['path']), strlen($lcBigSet) - strlen($lcBigRaw)],
    ['Heading one', 'Heading number 0', strlen('Heading one') - strlen('Heading number 1')]);
// What the kept parses hold is bounded, not only the bytes of the values parsed: a 40,000-object JSON
// is under 1 MB but one entry per container grew the process by 50 MB (review, 30/09/2026).
$lcMany = [];
for ($lcI = 0; $lcI < 40000; $lcI++) $lcMany[] = ['t' => 'Word number ' . $lcI];
$lcManyRaw = json_encode(['items' => $lcMany]);
unset($lcMany);
$lcManyLeaves = LeafCodec::leaves($lcManyRaw);
gc_collect_cycles();
// memory_reset_peak_usage() is PHP 8.2+; CI runs 8.1, where the retained growth stands in for the peak.
$lcManyReset = function_exists('memory_reset_peak_usage');
if ($lcManyReset) memory_reset_peak_usage();
$lcManyBase = memory_get_usage();
$lcManyOk = 0;
foreach ($lcManyLeaves as $lcLeaf) if (LeafCodec::get($lcManyRaw, $lcLeaf['path']) === $lcLeaf['text']) $lcManyOk++;
$lcManyPeak = ($lcManyReset ? memory_get_peak_usage() : memory_get_usage()) - $lcManyBase;
check('leaf: every leaf of a 40,000-object column reads back', $lcManyOk, 40000);
checkTrue('leaf: and the parses kept for it stay within their budget (' . round($lcManyPeak / 1048576, 1) . ' MB)', $lcManyPeak < 40 * 1048576);
unset($lcManyRaw, $lcManyLeaves);
