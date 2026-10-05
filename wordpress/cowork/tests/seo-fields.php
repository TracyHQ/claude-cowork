<?php
/**
 * A page's search title and meta description on a site with NO SEO plugin.
 *
 * Measured 05/10/2026 on a Tracy Business wp7 site (no Yoast, Rank Math or AIOSEO): a post write's
 * `seo:{title}` wrote nothing, silently, so the agent changed the page title instead — which is also
 * the breadcrumb inside <main> and, on some pages, the H1. The customer had asked to change only what
 * Google shows. These cases pin the rule that replaced it: with no SEO plugin, the plugin keeps the
 * values in its own post meta and prints them itself; with one, its keys; and a value nothing on this
 * site would print is refused by name, never dropped.
 *
 * Loaded by run.php after string-overrides.php (whose add_filter stub this file reuses).
 */
declare(strict_types=1);

echo "\nSearch title and description without an SEO plugin\n";

// One callback per hook, as WP_Fake::$filters keeps them; the same shape string-overrides.php defines.
if (!function_exists('add_filter')) {
    function add_filter(string $tag, $callback, int $priority = 10, int $args = 1): bool
    {
        WP_Fake::$filters[$tag] = $callback;
        return true;
    }
}
// Actions keep every priority: wp_head takes two callbacks of this file.
if (!function_exists('add_action')) {
    function add_action(string $tag, $callback, int $priority = 10, int $args = 1): bool
    {
        $GLOBALS['wpActions'][$tag][$priority] = $callback;
        return true;
    }
}

$seoRefusal = static function (callable $write): ?string {
    try {
        $write();
    } catch (RuntimeException $e) {
        return $e->getMessage();
    }
    return null;
};

// ------------------------------------------------------------------ the keys and who runs --

check('seo: the title key is the plugin\'s own, protected meta', SeoFields::TITLE_KEY, '_claude_cowork_seo_title');
check('seo: the description key is the plugin\'s own, protected meta', SeoFields::DESCRIPTION_KEY, '_claude_cowork_seo_description');

WP_Fake::reset();
check('seo: a site with no SEO plugin runs none', SeoFields::running(), null);
WP_Fake::$options['active_plugins'] = ['contact-form-7/wp-contact-form-7.php', 'polylang/polylang.php'];
check('seo: other plugins are not SEO plugins', SeoFields::running(), null);
WP_Fake::$options['active_plugins'] = ['wordpress-seo/wp-seo.php'];
check('seo: Yoast is found by its folder', (SeoFields::running() ?? [])['id'] ?? null, 'yoast');
WP_Fake::$options['active_plugins'] = ['seo-by-rank-math/rank-math.php'];
check('seo: Rank Math is found by its folder', (SeoFields::running() ?? [])['id'] ?? null, 'rank-math');
WP_Fake::$options['active_plugins'] = ['all-in-one-seo-pack/all_in_one_seo_pack.php'];
check('seo: All in One SEO is found by its folder', (SeoFields::running() ?? [])['id'] ?? null, 'aioseo');
WP_Fake::$options['active_plugins'] = ['wp-seopress/seopress.php'];
check('seo: SEOPress is found too, though its fields are not written here', (SeoFields::running() ?? [])['id'] ?? null, 'seopress');

// ------------------------------------------------------------------ the write, beside a post write --

$seoWriter = new Claude_Cowork_Site_Writer();

WP_Fake::reset();
WP_Fake::$posts[42] = ['ID' => 42, 'post_title' => 'About', 'post_content' => '<p>Hi</p>'];
$seoWriter->write('post', 42, ['post_excerpt' => 'Who we are', 'seo' => ['title' => 'About Northgate | Engineering', 'description' => 'Who builds Northgate.']]);
check('seo: with no SEO plugin, the title lands in the plugin\'s own meta', WP_Fake::$meta['42:_claude_cowork_seo_title'] ?? null, 'About Northgate | Engineering');
check('seo: and the description in its own meta', WP_Fake::$meta['42:_claude_cowork_seo_description'] ?? null, 'Who builds Northgate.');
check('seo: and the page title is left as it was', WP_Fake::$posts[42]['post_title'], 'About');
check('seo: it reads back as the post\'s seo', SeoFields::read(42), ['title' => 'About Northgate | Engineering', 'description' => 'Who builds Northgate.']);

// An SEO plugin's keys were written only when that post already held one: a Yoast site whose page had
// never been given a Yoast title wrote nothing. The plugin that runs decides, not what the post holds.
WP_Fake::reset();
WP_Fake::$options['active_plugins'] = ['wordpress-seo/wp-seo.php'];
WP_Fake::$posts[43] = ['ID' => 43, 'post_title' => 'Services', 'post_content' => ''];
$seoWriter->write('post', 43, ['post_excerpt' => 'x', 'seo' => ['title' => 'Our services', 'description' => 'What we do.']]);
check('seo: on a Yoast site, Yoast\'s keys, even on a post that held none', [WP_Fake::$meta['43:_yoast_wpseo_title'] ?? null, WP_Fake::$meta['43:_yoast_wpseo_metadesc'] ?? null], ['Our services', 'What we do.']);
check('seo: and not the plugin\'s own, which a site with Yoast never prints', isset(WP_Fake::$meta['43:_claude_cowork_seo_title']), false);
check('seo: Yoast\'s value reads back', SeoFields::read(43), ['title' => 'Our services', 'description' => 'What we do.']);

WP_Fake::reset();
WP_Fake::$options['active_plugins'] = ['seo-by-rank-math/rank-math.php'];
WP_Fake::$posts[44] = ['ID' => 44, 'post_title' => 'News', 'post_content' => ''];
$seoWriter->write('post', 44, ['post_excerpt' => 'x', 'seo' => ['title' => 'Latest news']]);
check('seo: on a Rank Math site, Rank Math\'s title key', WP_Fake::$meta['44:rank_math_title'] ?? null, 'Latest news');

// All in One SEO 4 keeps its fields in its own table (wp_aioseo_posts); `_aioseo_title` is a copy it
// writes for other plugins and never reads. Writing it changed nothing a visitor sees.
WP_Fake::reset();
WP_Fake::$options['active_plugins'] = ['all-in-one-seo-pack/all_in_one_seo_pack.php'];
WP_Fake::$posts[45] = ['ID' => 45, 'post_title' => 'Contact', 'post_content' => ''];
$aioseo = $seoRefusal(static fn () => $seoWriter->write('post', 45, ['post_title' => 'Contact us', 'seo' => ['title' => 'Reach us']]));
checkTrue('seo: on an All in One SEO site the write is refused, not dropped', $aioseo !== null);
checkTrue('seo: and the refusal names the plugin', str_contains((string) $aioseo, 'All in One SEO'));
check('seo: and nothing of the write landed, the post title included', [WP_Fake::$posts[45]['post_title'], isset(WP_Fake::$meta['45:_aioseo_title'])], ['Contact', false]);

WP_Fake::reset();
WP_Fake::$options['active_plugins'] = ['wp-seopress/seopress.php'];
WP_Fake::$posts[46] = ['ID' => 46, 'post_title' => 'Team', 'post_content' => ''];
$seopress = $seoRefusal(static fn () => $seoWriter->write('post', 46, ['post_excerpt' => 'x', 'seo' => ['title' => 'Our team']]));
checkTrue('seo: on a site running another SEO plugin the write is refused by its name', str_contains((string) $seopress, 'SEOPress'));
check('seo: and the plugin\'s own meta is not written behind it', isset(WP_Fake::$meta['46:_claude_cowork_seo_title']), false);

// ------------------------------------------------------------------ shapes it refuses --

WP_Fake::reset();
WP_Fake::$posts[47] = ['ID' => 47, 'post_title' => 'Home', 'post_content' => ''];
$unknownField = $seoRefusal(static fn () => $seoWriter->write('post', 47, ['post_excerpt' => 'x', 'seo' => ['title' => 'Home', 'keywords' => 'a, b']]));
checkTrue('seo: a field it has no place for is refused', $unknownField !== null);
checkTrue('seo: and the refusal names it and what is taken', str_contains((string) $unknownField, 'keywords') && str_contains((string) $unknownField, 'description'));
check('seo: and nothing landed', [WP_Fake::$posts[47]['post_excerpt'] ?? null, isset(WP_Fake::$meta['47:_claude_cowork_seo_title'])], [null, false]);
$notAnObject = $seoRefusal(static fn () => $seoWriter->write('post', 47, ['post_excerpt' => 'x', 'seo' => 'Home']));
checkTrue('seo: seo that is not an object is refused, not ignored', $notAnObject !== null);
$notText = $seoRefusal(static fn () => $seoWriter->write('post', 47, ['post_excerpt' => 'x', 'seo' => ['title' => ['Home']]]));
checkTrue('seo: a title that is not text is refused', $notText !== null);

// seo alone is not a post write: the row's undo cannot reach a meta. The refusal names the call that
// writes exactly that value and is undone on its own (kind postmeta), with this site's key.
$alone = $seoRefusal(static fn () => $seoWriter->write('post', 47, ['seo' => ['title' => 'Home | Northgate']]));
checkTrue('seo: seo alone is refused', $alone !== null);
checkTrue('seo: and the refusal names the postmeta call and the key', str_contains((string) $alone, '"postmeta"') && str_contains((string) $alone, '_claude_cowork_seo_title'));
WP_Fake::$options['active_plugins'] = ['wordpress-seo/wp-seo.php'];
$aloneYoast = $seoRefusal(static fn () => $seoWriter->write('post', 47, ['seo' => ['description' => 'x']]));
checkTrue('seo: on a Yoast site it names Yoast\'s key', str_contains((string) $aloneYoast, '_yoast_wpseo_metadesc'));

// ------------------------------------------------------------------ the postmeta road --

WP_Fake::reset();
WP_Fake::$posts[48] = ['ID' => 48, 'post_title' => 'About', 'post_content' => ''];
$seoWriter->write('postmeta', 48, ['value' => 'About us | Northgate'], SeoFields::TITLE_KEY);
check('seo: the own title is writable as a postmeta, so its undo is a meta\'s', $seoWriter->read('postmeta', 48, SeoFields::TITLE_KEY), ['value' => 'About us | Northgate']);
WP_Fake::$options['active_plugins'] = ['wordpress-seo/wp-seo.php'];
$hidden = $seoRefusal(static fn () => $seoWriter->write('postmeta', 48, ['value' => 'x'], SeoFields::DESCRIPTION_KEY));
checkTrue('seo: on a Yoast site the own key is refused: nothing would print it', $hidden !== null);
checkTrue('seo: and the refusal names Yoast\'s key instead', str_contains((string) $hidden, '_yoast_wpseo_metadesc'));
$seoWriter->write('postmeta', 48, ['value' => 'Yoast desc'], '_yoast_wpseo_metadesc');
check('seo: Yoast\'s own key stays writable as before', WP_Fake::$meta['48:_yoast_wpseo_metadesc'], 'Yoast desc');

// ------------------------------------------------------------------ what a visitor's page prints --

WP_Fake::reset();
$page = new WP_Post();
$page->ID = 50;
WP_Fake::$meta['50:_claude_cowork_seo_title'] = 'About <Northgate> & co';
WP_Fake::$queried = $page;
check('title: the page\'s own search title is the whole <title>, escaped', SeoFields::documentTitle(''), 'About &lt;Northgate&gt; &amp; co');
check('title: it wins over what a theme put first', SeoFields::documentTitle('Theme title'), 'About &lt;Northgate&gt; &amp; co');
WP_Fake::$meta['50:_claude_cowork_seo_title'] = "  \n";
check('title: a blank one leaves the title WordPress builds', SeoFields::documentTitle(''), '');
unset(WP_Fake::$meta['50:_claude_cowork_seo_title']);
check('title: a page with none leaves it too', SeoFields::documentTitle('Kept'), 'Kept');
WP_Fake::$meta['50:_claude_cowork_seo_title'] = 'Own';
WP_Fake::$queried = null;
check('title: a view that is no post (an archive, a search) is left', SeoFields::documentTitle(''), '');
WP_Fake::$queried = $page;
WP_Fake::$options['active_plugins'] = ['wordpress-seo/wp-seo.php'];
check('title: with an SEO plugin running, the plugin prints nothing of its own', SeoFields::documentTitle('Yoast title'), 'Yoast title');

$headOf = static function (string $printed): string {
    ob_start();
    SeoFields::openHead();
    echo $printed;
    SeoFields::closeHead();
    return (string) ob_get_clean();
};
WP_Fake::reset();
WP_Fake::$queried = $page;
WP_Fake::$meta['50:_claude_cowork_seo_description'] = 'Who builds "Northgate" & why';
check('description: printed in wp_head when nothing printed one',
    $headOf("<title>About</title>\n"),
    "<title>About</title>\n" . '<meta name="description" content="Who builds &quot;Northgate&quot; &amp; why" />' . "\n");
check('description: not when the theme or another plugin already printed one',
    $headOf("<meta name='description' content='theirs'>\n"), "<meta name='description' content='theirs'>\n");
check('description: an og:description is not a meta description',
    $headOf('<meta property="og:description" content="og" />'),
    '<meta property="og:description" content="og" />' . '<meta name="description" content="Who builds &quot;Northgate&quot; &amp; why" />' . "\n");
check('description: either attribute order is seen', SeoFields::withDescription('<meta content="x" NAME="Description" />', 'y'), '<meta content="x" NAME="Description" />');
WP_Fake::$options['active_plugins'] = ['seo-by-rank-math/rank-math.php'];
check('description: with an SEO plugin running, none of its own', $headOf('<title>x</title>'), '<title>x</title>');
WP_Fake::$options['active_plugins'] = [];
unset(WP_Fake::$meta['50:_claude_cowork_seo_description']);
check('description: a page with none prints none', $headOf('<title>x</title>'), '<title>x</title>');
$seoLevel = ob_get_level();
SeoFields::openHead();
$seoOpened = ob_get_level() - $seoLevel;
SeoFields::closeHead();
check('description: and opens no buffer', $seoOpened, 0);
// A buffer someone opened inside wp_head and left open: ours is not theirs to close, so the tag is
// printed where the page is, rather than their output taken.
WP_Fake::$meta['50:_claude_cowork_seo_description'] = 'Ours';
SeoFields::openHead();
echo '<title>x</title>';
ob_start();
echo '<!-- left open -->';
SeoFields::closeHead();
$seoInner = (string) ob_get_clean();
$seoOuter = (string) ob_get_clean();
check('description: a buffer left open inside wp_head is not taken', [$seoInner, $seoOuter],
    ['<!-- left open --><meta name="description" content="Ours" />' . "\n", '<title>x</title>']);

// ------------------------------------------------------------------ review 05/10: empty and unchanged --

// An empty seo names nothing to write: refused, so no write and no undo entry stand for it.
WP_Fake::reset();
WP_Fake::$posts[60] = ['ID' => 60, 'post_title' => 'About', 'post_content' => ''];
$emptySeo = $seoRefusal(static fn () => $seoWriter->write('post', 60, ['post_excerpt' => 'x', 'seo' => []]));
checkTrue('seo: an empty seo is refused, not written as nothing', $emptySeo !== null && str_contains((string) $emptySeo, 'names no field'));
check('seo: and the row is untouched', WP_Fake::$posts[60]['post_excerpt'] ?? null, null);
// A value the page already holds changes nothing: no key to write, so nothing to record.
WP_Fake::$meta['60:_claude_cowork_seo_title'] = 'About | Northgate';
check('seo: the keys a write changes leave out a value already held', $seoWriter->seoTargets(60, ['title' => 'About | Northgate', 'description' => 'New']), ['_claude_cowork_seo_description']);
check('seo: and nothing when every value is already held', $seoWriter->seoTargets(60, ['title' => 'About | Northgate']), []);

// ------------------------------------------------------------------ review 05/10: undo of a seo write --

// The post row's undo cannot reach a meta, so the engine records each key the seo write changes as its own
// postmeta step (the value before, or absent), and apply.revert puts each back exactly.
WP_Fake::reset();
WP_Fake::$posts[61] = ['ID' => 61, 'post_title' => 'About', 'post_content' => '<p>Hi</p>', 'post_excerpt' => 'Old excerpt'];
WP_Fake::$meta['61:_claude_cowork_seo_title'] = 'Old title | Northgate';
$undoLog = new FakeApplyLog();
$undoEngine = new Engine($WTOKEN, [], null, null, null, null, new Claude_Cowork_Site_Writer(), null, $undoLog);
$undoCall = static fn (string $action, array $params): array => $undoEngine->handle(['token' => $WTOKEN, 'action' => $action, 'params' => $params]);
$undoOk = $undoCall('content.update', ['apply_id' => 'seo-undo-1', 'kind' => 'post', 'id' => 61,
    'fields' => ['post_excerpt' => 'New excerpt', 'seo' => ['title' => 'New title | Northgate', 'description' => 'Who we are.']]]);
check('undo: the write lands', [$undoOk['ok'] ?? null, WP_Fake::$meta['61:_claude_cowork_seo_title'] ?? null, WP_Fake::$meta['61:_claude_cowork_seo_description'] ?? null],
    [true, 'New title | Northgate', 'Who we are.']);
$undoSteps = array_map(static fn (array $e): string => $e['kind'] . ':' . $e['key'] . ':' . ($e['before'] === null ? 'absent' : 'held'), $undoLog->entries('seo-undo-1'));
check('undo: one step for the row and one per meta key it changed, each with its value before', $undoSteps,
    ['post::held', 'postmeta:_claude_cowork_seo_title:held', 'postmeta:_claude_cowork_seo_description:absent']);
check('undo: the row step is a span of the row columns alone, not a whole-row step', $undoLog->entries('seo-undo-1')[0]['undo'] ?? null, 'span');
$undoBack = $undoCall('apply.revert', ['apply_id' => 'seo-undo-1']);
check('undo: apply.revert puts back the title, removes the description and restores the row',
    [$undoBack['ok'] ?? null, WP_Fake::$meta['61:_claude_cowork_seo_title'] ?? null, array_key_exists('61:_claude_cowork_seo_description', WP_Fake::$meta), WP_Fake::$posts[61]['post_excerpt']],
    [true, 'Old title | Northgate', false, 'Old excerpt']);
// A seo value already held records no step at all.
$undoSame = $undoCall('content.update', ['apply_id' => 'seo-undo-2', 'kind' => 'post', 'id' => 61,
    'fields' => ['post_excerpt' => 'Again', 'seo' => ['title' => 'Old title | Northgate']]]);
check('undo: an unchanged seo value records no meta step', [$undoSame['ok'] ?? null, count($undoLog->entries('seo-undo-2'))], [true, 1]);
// A refused seo leaves no step and no row change behind.
WP_Fake::$options['active_plugins'] = ['wp-seopress/seopress.php'];
$undoRefused = $undoCall('content.update', ['apply_id' => 'seo-undo-3', 'kind' => 'post', 'id' => 61, 'fields' => ['post_excerpt' => 'Refused', 'seo' => ['title' => 'x']]]);
check('undo: a refused seo writes nothing and records nothing', [$undoRefused['ok'] ?? null, $undoLog->entries('seo-undo-3'), WP_Fake::$posts[61]['post_excerpt']], [false, [], 'Again']);

// ------------------------------------------------------------------ review 05/10: the own keys take text --

WP_Fake::reset();
WP_Fake::$posts[62] = ['ID' => 62, 'post_title' => 'About', 'post_content' => ''];
$notString = $seoRefusal(static fn () => $seoWriter->write('postmeta', 62, ['value' => ['About']], SeoFields::TITLE_KEY));
checkTrue('own key: a value that is not text is refused, by name', $notString !== null && str_contains((string) $notString, 'must be text'));
$objectValue = $seoRefusal(static fn () => $seoWriter->write('postmeta', 62, ['value' => (object) ['a' => 1]], SeoFields::DESCRIPTION_KEY));
checkTrue('own key: an object is refused too', $objectValue !== null);
check('own key: nothing was stored', array_key_exists('62:' . SeoFields::TITLE_KEY, WP_Fake::$meta), false);
$seoWriter->write('postmeta', 62, ['value' => "  About <b>us</b>\n\tnow  "], SeoFields::TITLE_KEY);
check('own key: the title is stored as plain one-line text', WP_Fake::$meta['62:' . SeoFields::TITLE_KEY], 'About us now');
$seoWriter->write('postmeta', 62, ['value' => "Who <script>x</script>we\nare."], SeoFields::DESCRIPTION_KEY);
check('own key: and so is the description, a script with its words gone', WP_Fake::$meta['62:' . SeoFields::DESCRIPTION_KEY], 'Who we are.');
$seoWriter->write('post', 62, ['post_excerpt' => 'x', 'seo' => ['title' => " Contact <i>us</i> "]]);
check('own key: a seo write stores the same plain text', WP_Fake::$meta['62:' . SeoFields::TITLE_KEY], 'Contact us');

// ------------------------------------------------------------------ review 05/10: page 2 and on --

WP_Fake::reset();
$paged = new WP_Post();
$paged->ID = 63;
WP_Fake::$queried = $paged;
WP_Fake::$meta['63:' . SeoFields::TITLE_KEY] = 'News | Northgate';
WP_Fake::$meta['63:' . SeoFields::DESCRIPTION_KEY] = 'Our news.';
WP_Fake::$paged = true;
check('paged: page 2 of a listing keeps the title WordPress builds', SeoFields::documentTitle('News – Page 2 – Northgate'), 'News – Page 2 – Northgate');
check('paged: and prints no description of its own', $headOf('<title>x</title>'), '<title>x</title>');
WP_Fake::$paged = false;
check('paged: page 1 takes them', SeoFields::documentTitle(''), 'News | Northgate');

// ------------------------------------------------------------------ review 05/10: more SEO plugins --

WP_Fake::reset();
WP_Fake::$options['active_plugins'] = ['smartcrawl-seo/wpmu-dev-seo.php'];
check('more: SmartCrawl is found', (SeoFields::running() ?? [])['id'] ?? null, 'smartcrawl');
WP_Fake::$options['active_plugins'] = ['surerank/surerank.php'];
check('more: SureRank is found', (SeoFields::running() ?? [])['id'] ?? null, 'surerank');
WP_Fake::$options['active_plugins'] = ['jetpack/jetpack.php'];
WP_Fake::$options['jetpack_active_modules'] = ['stats', 'sitemaps'];
check('more: Jetpack without its SEO module prints no title or description, so it is none', SeoFields::running(), null);
WP_Fake::$options['jetpack_active_modules'] = ['stats', 'seo-tools'];
WP_Fake::$posts[62] = ['ID' => 62, 'post_title' => 'About', 'post_content' => ''];
check('more: Jetpack with its SEO module is found', (SeoFields::running() ?? [])['id'] ?? null, 'jetpack-seo');
$jetpack = $seoRefusal(static fn () => $seoWriter->write('postmeta', 62, ['value' => 'x'], SeoFields::TITLE_KEY));
checkTrue('more: with one running, the own keys are refused by its name', str_contains((string) $jetpack, 'Jetpack'));
WP_Fake::$queried = $paged;
check('more: and the page prints nothing of its own', SeoFields::documentTitle('Jetpack title'), 'Jetpack title');

// Polylang copies a page's custom fields to a new translation; our two keys hold one language's words.
check('polylang: a new translation does not inherit the source language\'s search title and description',
    SeoFields::notCopied(['_thumbnail_id', SeoFields::TITLE_KEY, 'footnotes', SeoFields::DESCRIPTION_KEY]), ['_thumbnail_id', 'footnotes']);
check('polylang: anything that is not a list is passed on', SeoFields::notCopied('x'), 'x');

// ------------------------------------------------------------------ wired into the plugin --

WP_Fake::reset();
$GLOBALS['wpActions'] = [];
SeoFields::register();
check('register: the title filter', WP_Fake::$filters['pre_get_document_title'] ?? null, [SeoFields::class, 'documentTitle']);
check('register: Polylang leaves the two keys out of what it copies', WP_Fake::$filters['pll_copy_post_metas'] ?? null, [SeoFields::class, 'notCopied']);
check('register: the head opens first and closes last', [$GLOBALS['wpActions']['wp_head'][PHP_INT_MIN] ?? null, $GLOBALS['wpActions']['wp_head'][PHP_INT_MAX] ?? null],
    [[SeoFields::class, 'openHead'], [SeoFields::class, 'closeHead']]);
$seoMain = (string) file_get_contents(__DIR__ . '/../claude-cowork/claude-cowork.php');
checkTrue('register: the plugin file loads and registers it on every request', str_contains($seoMain, "require_once __DIR__ . '/lib/SeoFields.php';") && str_contains($seoMain, 'SeoFields::register();'));
