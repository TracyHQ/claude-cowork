<?php
/**
 * Text slots that keep their inline markup (TracyHQ/tracy#459).
 *
 * A demo block often marks words inside one element: a dish name and its price span, an accent span in a heading,
 * a line break, a link inside a sentence. A plain-text slot cannot carry that (its value is escaped as words), so
 * such a block had no door at all. A slot with `"markup": "inline"` reads the element's inner HTML in the content
 * map's notation (`((span class="price"))$12((/span))`, `((br/))`) and writes it back as HTML built from a fixed
 * allowlist of inline tags and attributes, with classes held to the ones the slot's demo (or its `markupClasses`)
 * already uses, and passes the result through `wp_kses` with the same allowlist.
 *
 * Loaded by run.php after post-field-slots.php. Uses `check()` / `checkTrue()`, `$WTOKEN`, `contractRoot()` from
 * contracts.php and FakeImageLibrary from image-slots.php. `markedSite()` is used by theme-picture-slots.php too.
 */
declare(strict_types=1);

echo "\nInline markup slots\n";

$MARKED_FIXTURES = __DIR__ . '/fixtures/markup-contracts';
$MARKED_MENU = (string) file_get_contents($MARKED_FIXTURES . '/menu.html');
$MARKED_POST = (string) file_get_contents($MARKED_FIXTURES . '/spring-notes.html');

// WordPress's own sanitiser is not part of the fake runtime: this stand-in records what it was asked and changes
// nothing, so the test sees the allowlist the plugin hands it. No other code of the plugin calls wp_kses.
$GLOBALS['kses_calls'] = [];
if (!function_exists('wp_kses')) {
    function wp_kses($content, $allowed, $protocols = [])
    {
        $GLOBALS['kses_calls'][] = ['content' => $content, 'allowed' => $allowed, 'protocols' => $protocols];
        return $content;
    }
}

/** @return array{engine:Engine,images:FakeImageLibrary} a page with marked-up blocks, a post with a theme picture meta, a theme picture option */
function markedSite(string $fixtures, string $menu, string $post): array
{
    WP_Fake::reset();
    WP_Fake::$stylesheet = 'test-theme';
    WP_Fake::$options = ['stylesheet' => 'test-theme', 'template' => 'test-theme', 'home' => 'http://test.local',
        'test_theme_blog_banner_image' => '93', 'test_theme_contact_phone' => '(555) 010-2030'];
    WP_Fake::$posts[10] = ['ID' => 10, 'post_type' => 'page', 'post_name' => 'menu', 'post_status' => 'publish', 'post_parent' => 0, 'post_title' => 'Menu', 'post_content' => $menu];
    WP_Fake::$posts[20] = ['ID' => 20, 'post_type' => 'post', 'post_name' => 'spring-notes', 'post_status' => 'publish', 'post_parent' => 0,
        'post_title' => 'Spring notes', 'post_excerpt' => '', 'post_content' => $post];
    WP_Fake::$meta['20:tt_intro_image'] = '92';
    WP_Fake::$meta['92:_wp_attached_file'] = '2026/09/plates.jpg';
    WP_Fake::$meta['93:_wp_attached_file'] = '2026/09/banner.jpg';
    $site = json_decode((string) file_get_contents(__DIR__ . '/fixtures/contracts/site.json'), true);
    $images = new FakeImageLibrary();
    $images->images['wp-content/uploads/2026/09/plates.jpg'] = ['id' => 92, 'width' => 1200, 'height' => 800];
    $images->images['wp-content/uploads/2026/09/banner.jpg'] = ['id' => 93, 'width' => 1920, 'height' => 600];
    $writer = new Claude_Cowork_Site_Writer();
    $contract = new QuickstartContract($writer, new Claude_Cowork_Contract_Store(), contractRoot($site), $fixtures, '', null, $images);
    $engine = new Engine($GLOBALS['WTOKEN'], [], null, null, null, null, $writer, new FakeMediaWriter(), new FakeApplyLog(), null, $contract);
    return ['engine' => $engine, 'images' => $images];
}

/**
 * The fixture profile with its files changed by `$edit(manifest, map)`, as a site: inspect problems, bind, and the
 * inspect answer itself (for a profile the plugin refuses whole).
 */
function markedVariant(callable $edit): array
{
    $from = __DIR__ . '/fixtures/markup-contracts/marked-design/wp7/1.0.0';
    $dir = sys_get_temp_dir() . '/cc-marked-profile-' . bin2hex(random_bytes(4));
    register_shutdown_function('contractRootRemove', $dir);
    mkdir($dir . '/marked-design/wp7/1.0.0', 0777, true);
    copy($from . '/presentation-lock.json', $dir . '/marked-design/wp7/1.0.0/presentation-lock.json');
    $manifest = json_decode((string) file_get_contents($from . '/manifest.json'), true);
    $map = json_decode((string) file_get_contents($from . '/content-map.json'), true);
    [$manifest, $map] = $edit($manifest, $map);
    file_put_contents($dir . '/marked-design/wp7/1.0.0/manifest.json', json_encode($manifest));
    file_put_contents($dir . '/marked-design/wp7/1.0.0/content-map.json', json_encode($map));
    $site = markedSite($dir, $GLOBALS['MARKED_MENU'], $GLOBALS['MARKED_POST']);
    $engine = $site['engine'];
    $inspect = $engine->handle(['token' => $GLOBALS['WTOKEN'], 'action' => 'content.contract', 'params' => ['operation' => 'inspect', 'contract' => 'marked-design/wp7/1.0.0']]);
    return [
        'inspect' => $inspect['problems'] ?? null,
        'answer' => $inspect,
        'engine' => $engine,
        'bind' => $engine->handle(['token' => $GLOBALS['WTOKEN'], 'action' => 'content.contract', 'params' => ['operation' => 'bind', 'contract' => 'marked-design/wp7/1.0.0']])['ok'] ?? null,
    ];
}

$ms = markedSite($MARKED_FIXTURES, $MARKED_MENU, $MARKED_POST);
$ME = $ms['engine'];
$mdoor = static function (string $operation, array $params = []) use ($ME, $WTOKEN): array {
    return $ME->handle(['token' => $WTOKEN, 'action' => 'content.contract', 'params' => ['operation' => $operation] + $params]);
};
$mapply = static function (array $changes, string $id) use ($mdoor): array {
    return $mdoor('apply', ['expected_revision' => $mdoor('inspect')['revision'], 'apply_id' => 'contract-' . $id, 'request_id' => $id, 'changes' => $changes]);
};
$mcode = static function (array $changes, string $id) use ($mapply): ?string {
    return $mapply($changes, $id)['errors'][0]['code'] ?? null;
};
$mrevert = static function (string $id) use ($ME, $WTOKEN): bool {
    return (bool) ($ME->handle(['token' => $WTOKEN, 'action' => 'apply.revert', 'params' => ['apply_id' => 'contract-' . $id]])['ok'] ?? false);
};

$mbind = $mdoor('bind', ['contract' => 'marked-design/wp7/1.0.0']);
check('a profile with inline markup slots binds', $mbind['ok'], true);
$minsp = $mdoor('inspect');
check('the page is not drift', $minsp['problems'], []);
check('a price span reads in the notation', $minsp['slots']['menu.dish.one'], 'Lemon Rice Bowl ((span class="price"))$12((/span))');
check('an accent span and a line break too', $minsp['slots']['menu.menu.title'], 'Our ((span class="text-accent"))Menu((/span))((br/))this season');
check('a link inside a sentence too', $minsp['slots']['menu.menu.cta'], 'Book a table ((a class="btn-link" href="/reservation/"))today((/a)).');
check('a plain text slot reads as before', $minsp['slots']['menu.menu.note'], 'Open daily from noon.');
check('inspect says what each marked-up slot may carry', $minsp['markupSlots']['menu.dish.one'] ?? null,
    ['tags' => ['span', 'strong', 'em', 'b', 'i', 'br', 'a', 'sup', 'sub'], 'classes' => ['price']]);
check('the per-slot allowlist adds to the demo classes', $minsp['markupSlots']['menu.menu.title']['classes'] ?? null, ['text-accent', 'text-muted']);
checkTrue('a plain text slot is not listed there', !isset($minsp['markupSlots']['menu.menu.note']));

// ── a write keeps its markup ─────────────────────────────────────────────────────────────────

$GLOBALS['kses_calls'] = [];
$dish = $mapply(['menu.dish.one' => 'Fish & Chips ((span class="price"))$14((/span))'], 'm1');
check('a new dish and price land', $dish['ok'] ?? null, true);
checkTrue('as markup in the block, the words escaped', strpos((string) WP_Fake::$posts[10]['post_content'],
    '<p class="dish">Fish &amp; Chips <span class="price">$14</span></p>') !== false);
$kses = $GLOBALS['kses_calls'][0] ?? ['allowed' => [], 'protocols' => []];
check('the markup went through wp_kses', $kses['content'] ?? null, 'Fish &amp; Chips <span class="price">$14</span>');
check('with the inline allowlist only', $kses['allowed'], [
    'span' => ['class' => true], 'strong' => [], 'em' => [], 'b' => [], 'i' => [], 'br' => [],
    'a' => ['href' => true, 'class' => true, 'target' => true, 'rel' => true], 'sup' => [], 'sub' => [],
]);
check('and the web protocols only', $kses['protocols'], ['http', 'https', 'mailto', 'tel']);
$afterDish = $mdoor('inspect');
check('a written dish is not drift', $afterDish['problems'], []);
check('the slot answers the new dish', $afterDish['slots']['menu.dish.one'], 'Fish & Chips ((span class="price"))$14((/span))');
check('the revert restores the exact previous HTML', [$mrevert('m1'), WP_Fake::$posts[10]['post_content']], [true, $MARKED_MENU]);

$title = $mapply(['menu.menu.title' => 'Fresh ((span class="text-muted"))plates((/span))((br/))((strong))every((/strong)) week',
    'menu.menu.cta' => 'Call ((a href="tel:+15550100" class="btn-link"))us((/a)) or ((a href="https://example.com/book" target="_blank" rel="noopener noreferrer"))book((/a)).'], 'm2');
check('a class from the per-slot allowlist, a break, a bold word and two links land', $title['ok'] ?? null, true);
checkTrue('the heading holds them as markup', strpos((string) WP_Fake::$posts[10]['post_content'],
    '<h2 class="wp-block-heading">Fresh <span class="text-muted">plates</span><br><strong>every</strong> week</h2>') !== false);
checkTrue('the links keep their attributes in the order written', strpos((string) WP_Fake::$posts[10]['post_content'],
    '<p>Call <a href="tel:+15550100" class="btn-link">us</a> or <a href="https://example.com/book" target="_blank" rel="noopener noreferrer">book</a>.</p>') !== false);
check('both read back as written', [$mdoor('inspect')['slots']['menu.menu.title'], $mdoor('inspect')['slots']['menu.menu.cta']],
    ['Fresh ((span class="text-muted"))plates((/span))((br/))((strong))every((/strong)) week',
        'Call ((a href="tel:+15550100" class="btn-link"))us((/a)) or ((a href="https://example.com/book" target="_blank" rel="noopener noreferrer"))book((/a)).']);
check('and revert to the demo bytes', [$mrevert('m2'), WP_Fake::$posts[10]['post_content']], [true, $MARKED_MENU]);
check('plain words with no markup at all land too', [$mapply(['menu.dish.one' => 'Soup of the day'], 'm3')['ok'] ?? null,
    $mdoor('inspect')['slots']['menu.dish.one']], [true, 'Soup of the day']);
$mrevert('m3');
check('the demo value written back changes nothing', $mapply(['menu.dish.one' => 'Lemon Rice Bowl ((span class="price"))$12((/span))',
    'menu.menu.note' => 'Open daily from noon.'], 'm4')['unchanged'] ?? null, true);

// ── what a marked-up slot refuses ────────────────────────────────────────────────────────────

check('raw HTML is refused: markup comes in the notation', $mcode(['menu.dish.one' => 'Soup <span class="price">$5</span>'], 'r1'), 'SLOT_NOT_CONTENT');
$refusal = static function (string $value) use ($mapply): ?string {
    return $mapply(['menu.dish.one' => $value], 'r' . bin2hex(random_bytes(3)))['errors'][0]['message'] ?? null;
};
check('a block tag is refused by name', $refusal('((div))Soup((/div))'), 'Markup tag div is not allowed: menu.dish.one');
check('so is a paragraph', $refusal('((p))Soup((/p))'), 'Markup tag p is not allowed: menu.dish.one');
check('so is a script', $refusal('((script))alert(1)((/script))'), 'Markup tag script is not allowed: menu.dish.one');
check('so is an image', $refusal('Soup ((img src="x.jpg"/))'), 'Markup tag img is not allowed: menu.dish.one');
check('a style attribute is refused', $refusal('Soup ((span style="color:red"))$5((/span))'), 'Markup attribute style is not allowed on span: menu.dish.one');
check('an event attribute is refused', $refusal('Soup ((span onclick="go"))$5((/span))'), 'Markup attribute onclick is not allowed on span: menu.dish.one');
check('a class on a bold word is refused', $refusal('((strong class="price"))Soup((/strong))'), 'Markup attribute class is not allowed on strong: menu.dish.one');
check('a class the demo does not use is refused', $refusal('Soup ((span class="huge"))$5((/span))'), 'Markup class huge is not in this slot\'s demo: menu.dish.one');
check('another slot\'s allowlist does not carry over', $refusal('Soup ((span class="text-muted"))$5((/span))'), 'Markup class text-muted is not in this slot\'s demo: menu.dish.one');
check('a script link is refused', $refusal('((a href="javascript:void"))Soup((/a))'), 'Markup link is not allowed: menu.dish.one');
check('a link to another target is refused', $refusal('((a href="/" target="_top"))Soup((/a))'), 'Markup target _top is not allowed: menu.dish.one');
check('an unknown rel is refused', $refusal('((a href="/" rel="me"))Soup((/a))'), 'Markup rel me is not allowed: menu.dish.one');
check('a link inside a link is refused', $refusal('((a href="/"))Soup ((a href="/b"))here((/a))((/a))'), 'Markup link inside a link: menu.dish.one');
check('an unclosed tag is refused', $refusal('Soup ((span class="price"))$5'), 'Markup is not balanced: menu.dish.one');
check('a stray closing tag is refused', $refusal('Soup $5((/span))'), 'Markup is not balanced: menu.dish.one');
check('tags closed out of order are refused', $refusal('((strong))((em))Soup((/strong))((/em))'), 'Markup is not balanced: menu.dish.one');
check('an attribute the notation cannot read is refused', $refusal('Soup ((span class=price))$5((/span))'), 'Markup is not readable: menu.dish.one');
check('a half-written tag is refused', $refusal('Soup ((span $5'), 'Markup is not readable: menu.dish.one');
check('the limit counts the words, not the markup', $mapply(['menu.dish.one' => 'Lemon Rice Bowl, large ((span class="price"))$18.50((/span))'], 'r20')['ok'] ?? null, true);
$mrevert('r20');
check('and still holds them', $mcode(['menu.dish.one' => str_repeat('a', 41)], 'r21'), 'SLOT_TOO_LONG');
check('nothing was written by any refusal', WP_Fake::$posts[10]['post_content'], $MARKED_MENU);

// ── plain text slots are unchanged ───────────────────────────────────────────────────────────

check('markup in a plain text slot is still refused', $mcode(['menu.menu.note' => 'Open <b>daily</b>'], 'p1'), 'SLOT_NOT_CONTENT');
check('a plain text slot still escapes its words', [$mapply(['menu.menu.note' => 'Open 12 & 7'], 'p2')['ok'] ?? null,
    strpos((string) WP_Fake::$posts[10]['post_content'], '<p>Open 12 &amp; 7</p>') !== false], [true, true]);
$mrevert('p2');

// ── a markup declaration the plugin cannot honour ────────────────────────────────────────────

check('an unknown markup kind is refused by name, and the profile does not bind', markedVariant(static function (array $manifest, array $map): array {
    $map['slots'][1]['markup'] = 'html';
    return [$manifest, $map];
})['inspect'] ?? null, ['Slot menu.dish.one: unsupported markup html']);
check('markup on a slot that is not text is refused', markedVariant(static function (array $manifest, array $map): array {
    $map['slots'][5]['markup'] = 'inline';
    return [$manifest, $map];
})['inspect'] ?? null, ['Slot post-spring-notes.intro-image: inline markup is for a text slot of a block']);
check('markup on a post field is refused', markedVariant(static function (array $manifest, array $map): array {
    $map['slots'][4]['target'] = ['field' => 'post_title'];
    $map['slots'][4]['markup'] = 'inline';
    return [$manifest, $map];
})['inspect'] ?? null, ['Slot post-spring-notes.post.intro: inline markup is for a text slot of a block']);
check('a per-slot class that is not a class name is refused', markedVariant(static function (array $manifest, array $map): array {
    $map['slots'][1]['markupClasses'] = ['price" onclick="x'];
    return [$manifest, $map];
})['inspect'] ?? null, ['Slot menu.dish.one: markupClasses holds a value that is not a class name']);
