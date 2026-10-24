<?php
/**
 * Rendered words, the matcher (lib/RenderedWords.php): what a rule may name on a finished page, and
 * how a page's rule set is applied — exact, escaped, whole or not at all, nothing else touched.
 *
 * Loaded by run.php and by rendered-words-run.php (uses check()).
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/RenderedWords.php';

echo "\nRendered words: matcher\n";

$rwPage = (string) file_get_contents(__DIR__ . '/fixtures/rendered-words/contact.html');
$rwPhone = '(084) 555-0110';
$rwSite = 'http://joomlart.com';

$rwFound = RenderedWords::candidates($rwPage, [
    ['kind' => 'text', 'old' => $rwPhone],
    ['kind' => 'attribute', 'name' => 'href', 'old' => $rwSite],
    ['kind' => 'text', 'old' => '1600 Amphitheatre Parkway, Mountain View 5692'],
    ['kind' => 'text', 'old' => 'Send Email'],
]);
$rwCounts = static fn(array $target): array => array_map(static fn(array $c): array => [$c['where'], $c['count']], $target['candidates']);
check('candidates: the phone in the address card and in the footer, nowhere in head, comment, title attribute, form, textarea or svg',
    $rwCounts($rwFound[0]), [['dl/dd/span', 1], ['body/footer/p', 1]]);
check('candidates: five page links in two places, none in head, script, form or svg', $rwCounts($rwFound[1]), [['ul/li/a', 4], ['dd/span/a', 1]]);
check('candidates: the address, with an excerpt', [$rwCounts($rwFound[2]), $rwFound[2]['candidates'][0]['excerpt']],
    [[['dl/dd/span', 1]], '1600 Amphitheatre Parkway, Mountain View 5692']);
check('candidates: a button label inside a form is never a candidate', $rwFound[3]['candidates'], []);

$rwAnchor = static fn(array $target, int $i): string => $target['candidates'][$i]['anchor'];
$rwRules = [
    ['kind' => 'text', 'anchor' => $rwAnchor($rwFound[0], 0), 'old' => $rwPhone, 'new' => '+84 28 3822 0000', 'expected_matches' => 1],
    ['kind' => 'attribute', 'name' => 'href', 'anchor' => $rwAnchor($rwFound[1], 0), 'old' => $rwSite, 'new' => 'https://example.vn/social?a=1&b="2"', 'expected_matches' => 4],
    ['kind' => 'text', 'anchor' => $rwAnchor($rwFound[2], 0), 'old' => '1600 Amphitheatre Parkway, Mountain View 5692', 'new' => '12 Lê Lợi <Quận 1> & Co', 'expected_matches' => 1],
];
foreach ($rwRules as $i => $rule) {
    check("rule {$i} is valid", RenderedWords::ruleRefusal($rule), null);
}
$rwDone = RenderedWords::render($rwPage, $rwRules);
check('render: applied, no problems', [$rwDone['applied'], $rwDone['problems']], [true, []]);
check('render: the phone in the card is replaced, the footer one (another anchor) stays',
    [substr_count($rwDone['html'], '>+84 28 3822 0000</span>'), substr_count($rwDone['html'], 'Call (084) 555-0110 today')], [1, 1]);
check('render: the new text is escaped as text', substr_count($rwDone['html'], '>12 Lê Lợi &lt;Quận 1&gt; &amp; Co</span>'), 1);
check('render: the four social hrefs carry the new address, attribute-escaped',
    substr_count($rwDone['html'], 'href="https://example.vn/social?a=1&amp;b=&quot;2&quot;"'), 4);
check('render: the website link (another anchor), its text, the canonical link, the script, the form and svg keep joomlart',
    substr_count($rwDone['html'], 'joomlart.com'), substr_count($rwPage, 'joomlart.com') - 4);
// Everything outside the spliced ranges is the theme's bytes: undo the three edits and the page is the original.
$rwBack = str_replace(['>+84 28 3822 0000</span>', '>12 Lê Lợi &lt;Quận 1&gt; &amp; Co</span>', 'href="https://example.vn/social?a=1&amp;b=&quot;2&quot;"'],
    ['>(084) 555-0110</span>', '>1600 Amphitheatre Parkway, Mountain View 5692</span>', 'href="http://joomlart.com"'], $rwDone['html']);
check('render: every other byte is the original (form, nonce, attributes, comments, head)', $rwBack === $rwPage, true);
check('render: the form subtree is byte-identical', strpos($rwDone['html'], substr($rwPage, (int) strpos($rwPage, '<form'), (int) strpos($rwPage, '</form>') - (int) strpos($rwPage, '<form'))) !== false, true);
check('render: no rules is the page itself', RenderedWords::render($rwPage, []), ['html' => $rwPage, 'applied' => false, 'problems' => []]);

// Whole or nothing: one rule that no longer matches leaves every rule unapplied.
$rwDrift = $rwRules;
$rwDrift[1]['expected_matches'] = 5;
$rwSkipped = RenderedWords::render($rwPage, $rwDrift);
check('drift: a changed count skips the whole set, the page untouched', [$rwSkipped['applied'], $rwSkipped['html'] === $rwPage, count($rwSkipped['problems'])], [false, true, 1]);
$rwGone = $rwRules;
$rwGone[0]['anchor'] = RenderedWords::anchor('text', 'html/body/div/div/aside/span');
check('drift: an anchor the page no longer has skips the set', [RenderedWords::render($rwPage, $rwGone)['applied'], RenderedWords::render($rwPage, $rwGone)['html'] === $rwPage], [false, true]);
$rwOverlap = [$rwRules[0], ['kind' => 'text', 'anchor' => $rwRules[0]['anchor'], 'old' => '555', 'new' => '777', 'expected_matches' => 1]];
check('drift: two rules on overlapping words skip the set', [RenderedWords::render($rwPage, $rwOverlap)['applied'], RenderedWords::render($rwPage, $rwOverlap)['problems']],
    [false, ['two rules match overlapping words']]);
$rwMarkupCut = substr($rwPage, 0, (int) strpos($rwPage, '<!-- (084)') + 10);
check('malformed: an unclosed comment leaves the page as it was', [RenderedWords::render($rwMarkupCut, $rwRules)['applied'], RenderedWords::render($rwMarkupCut, $rwRules)['html'] === $rwMarkupCut], [false, true]);
$rwUnclosed = str_replace('</script>', '', $rwPage);
check('malformed: a script that never ends leaves the page as it was', RenderedWords::render($rwUnclosed, $rwRules)['applied'], false);
$rwInterleaved = str_replace('</form>', '</div></form>', $rwPage);
check('malformed: a form closed by another element\'s end tag leaves the page as it was', RenderedWords::scan($rwInterleaved), null);
$rwBig = str_replace('</body>', str_repeat('<p>x</p>', 300000) . '</body>', $rwPage);
check('too large: past 2 MiB the page is left as it was', [RenderedWords::render($rwBig, $rwRules)['applied'], RenderedWords::render($rwBig, $rwRules)['html'] === $rwBig], [false, true]);

// Entities: matched in the decoded text, spliced in the raw bytes.
$rwHours = RenderedWords::candidates($rwPage, [['kind' => 'text', 'old' => "Mon & Tue: 9\u{a0}am – 5 pm"]]);
check('entities: decoded words are found across &amp; &nbsp; &ndash;', $rwCounts($rwHours[0]), [['div/div/p', 1]]);
$rwHourRule = ['kind' => 'text', 'anchor' => $rwHours[0]['candidates'][0]['anchor'], 'old' => 'Mon & Tue', 'new' => 'T2 & T3', 'expected_matches' => 1];
check('entities: the raw entity is replaced, the rest of the node kept', strpos(RenderedWords::render($rwPage, [$rwHourRule])['html'], '<p class="hours">T2 &amp; T3: 9&nbsp;am &ndash; 5 pm</p>') !== false, true);
check('cross-node words are not one match', RenderedWords::candidates('<p>Mountain <b>View</b></p>', [['kind' => 'text', 'old' => 'Mountain View']])[0]['candidates'], []);
check('an href whose decoded value matches', RenderedWords::candidates('<p><a href="http://a.test/?x=1&amp;y=2">a</a> <a href=\'http://a.test/?x=1&y=2\'>b</a> <a href=http://a.test/?x=1&amp;y=2>c</a></p>',
    [['kind' => 'attribute', 'name' => 'href', 'old' => 'http://a.test/?x=1&y=2']])[0]['candidates'][0]['count'], 3);
check('an a with two hrefs is never touched', RenderedWords::candidates('<p><a href="http://a.test" href="http://b.test">a</a></p>',
    [['kind' => 'attribute', 'name' => 'href', 'old' => 'http://a.test']])[0]['candidates'], []);
check('comments: <!--> and <!---> end where a browser ends them', RenderedWords::candidates('<p>a<!-->b<!--->c</p>', [['kind' => 'text', 'old' => 'c']])[0]['candidates'][0]['count'], 1);

// What a rule may say.
$rwBase = ['kind' => 'text', 'anchor' => str_repeat('a', 16), 'old' => 'x', 'new' => 'y', 'expected_matches' => 1];
$rwHref = ['kind' => 'attribute', 'name' => 'href', 'anchor' => str_repeat('a', 16), 'old' => 'http://joomlart.com', 'expected_matches' => 1];
check('rule: another attribute is refused', RenderedWords::ruleRefusal(['name' => 'src', 'new' => 'https://x.test'] + $rwHref) !== null, true);
check('rule: a caller selector or unknown field is refused', RenderedWords::ruleRefusal($rwBase + ['selector' => '#x']) !== null, true);
check('rule: a made-up anchor is refused', RenderedWords::ruleRefusal(['anchor' => 'div.contact'] + $rwBase) !== null, true);
check('rule: a control character in the words is refused', RenderedWords::ruleRefusal(['new' => "a\x07b"] + $rwBase) !== null, true);
check('rule: words past 4 KiB are refused', RenderedWords::ruleRefusal(['new' => str_repeat('a', 4097)] + $rwBase) !== null, true);
check('rule: an address past 2 KiB is refused', RenderedWords::ruleRefusal(['new' => 'https://x.test/' . str_repeat('a', 2048)] + $rwHref) !== null, true);
check('rule: a count of 0 or 65 is refused', [RenderedWords::ruleRefusal(['expected_matches' => 0] + $rwBase) !== null, RenderedWords::ruleRefusal(['expected_matches' => 65] + $rwBase) !== null], [true, true]);
check('rule: invalid UTF-8 is refused', RenderedWords::ruleRefusal(['new' => "\xc3\x28"] + $rwBase) !== null, true);
check('rule: a rule_id that is not the rule\'s is refused', RenderedWords::ruleRefusal(['rule_id' => 'ffffffffffffffff'] + $rwBase) !== null, true);
foreach (['https://example.vn/a?b=c#d', 'http://example.vn', 'tel:+84283822000', 'tel:(084)555-0110', 'mailto:hi@example.vn', '/lien-he/', '#map', 'HTTPS://EXAMPLE.VN', 'https://[::1]:8080/x'] as $ok) {
    check("url allowed: {$ok}", RenderedWords::urlRefusal($ok), null);
}
foreach (['javascript:alert(1)', 'JaVaScRiPt:alert(1)', 'data:text/html,x', 'vbscript:x', '//evil.test/x', 'https://user:pw@example.vn/', 'https://a@b',
    "java\tscript:alert(1)", ' https://example.vn', "https://example.vn/\n", 'https:\\\\evil.test', 'relative/page', 'https:', 'https:///x', 'tel:1;phone-context=x', 'mailto:a@b?x=<s>', 'file:///etc/passwd', 'http://exa mple.vn'] as $bad) {
    check('url refused: ' . addcslashes($bad, "\t\n"), RenderedWords::urlRefusal($bad) !== null, true);
}
check('url: entity text is literal, escaped on the way out, never decoded into a scheme',
    [RenderedWords::urlRefusal('&#106;avascript:alert(1)') !== null, RenderedWords::urlRefusal('/x?q=&#106;') === null], [true, true]);
$rwEsc = RenderedWords::render('<p><a href="http://joomlart.com">a</a></p>', [['new' => '/x?q="><script>', 'anchor' => RenderedWords::anchor('attribute', 'p/a')] + $rwHref]);
check('url: quotes and brackets in an allowed address are escaped in the attribute', $rwEsc['html'], '<p><a href="/x?q=&quot;&gt;&lt;script&gt;">a</a></p>');

// Proof and probe: signed with the site token, expiring.
$rwKey = RenderedWords::key('write-token-at-least-16chars');
$rwProof = RenderedWords::sign($rwKey, ['post' => 7, 'exp' => 2000]);
check('proof: verifies while fresh', RenderedWords::verify($rwKey, $rwProof, 1999), ['post' => 7, 'exp' => 2000]);
check('proof: expired is refused', RenderedWords::verify($rwKey, $rwProof, 2001), null);
check('proof: another site\'s key is refused', RenderedWords::verify(RenderedWords::key('another-token-at-least-16'), $rwProof, 1999), null);
$rwForged = RenderedWords::sign($rwKey, ['post' => 8, 'exp' => 2000]);
check('proof: a payload swapped under another mac is refused', RenderedWords::verify($rwKey, strtok($rwForged, '.') . '.' . substr($rwProof, strpos($rwProof, '.') + 1), 1999), null);
$rwProbe = RenderedWords::probeValue($rwKey, 1000);
check('probe: valid now, not after it expires, not with another key', [RenderedWords::probeValid($rwKey, $rwProbe, 1000), RenderedWords::probeValid($rwKey, $rwProbe, 1000 + RenderedWords::PROBE_SECONDS + 1),
    RenderedWords::probeValid(RenderedWords::key('another-token-at-least-16'), $rwProbe, 1000), RenderedWords::probeValid($rwKey, 'pick', 1000)], [true, false, false, false]);

// Merge: a second write replaces a destination, keeps the source; new === old drops the rule.
$rwMerged = RenderedWords::merge([], [$rwRules[0]], 'a1');
$rwMerged = RenderedWords::merge($rwMerged, [['new' => '+84 1'] + $rwRules[0]], 'a2');
check('merge: same place and words is one rule, the latest destination, the theme\'s own source', [count($rwMerged), $rwMerged[0]['old'], $rwMerged[0]['new'], $rwMerged[0]['apply_id']], [1, $rwPhone, '+84 1', 'a2']);
check('merge: new equal to old drops the rule', RenderedWords::merge($rwMerged, [['new' => $rwPhone] + $rwRules[0]], 'a3'), []);
check('option: one per page and locale, recognised by its prefix', [RenderedWords::optionKey(12, 'vi'), RenderedWords::isOption('claude_cowork_rendered_words_12_vi'), RenderedWords::isOption('blogname')],
    ['claude_cowork_rendered_words_12_vi', true, false]);
check('option: a set that is not the shape reads as none', [RenderedWords::decodeSet('{"schema":1}'), RenderedWords::decodeSet('nonsense'), RenderedWords::decodeSet('')], [[], [], []]);
