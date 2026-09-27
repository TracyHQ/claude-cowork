<?php
/**
 * `ProvenanceStamps`: who is stamped as the owner of each block, when stamping happens at all, and
 * what a stamping response tells caches. The owner logic runs against a fake site; the gating runs
 * in fresh processes against fake WordPress hooks, because what it must prove is that an ordinary
 * request adds no hook — the only way the page stays byte-identical.
 */
declare(strict_types=1);
require_once __DIR__ . '/../lib/ProvenanceStamps.php';

final class FakeProvenanceSite implements ProvenanceSite
{
    /** @var array<int, string> */
    public $slugs = [];
    /** @var int */
    public $current = 0;
    /** @var string|null */
    public $page = null;
    /** @var string|null */
    public $template = null;
    /** @var bool */
    public $pageRender = true;

    public function slug(int $postId): string { return $this->slugs[$postId] ?? ''; }
    public function currentPostId(): int { return $this->current; }
    public function pageOwner(): ?string { return $this->page; }
    public function templateSlug(): ?string { return $this->template; }
    public function isPageRender(): bool { return $this->pageRender; }
}

/** The part of WP_Block the stamps read. */
final class FakeBlockInstance
{
    /** @var array<string, mixed> */
    public $context;
    public function __construct(array $context = []) { $this->context = $context; }
}

/**
 * Render a tree of blocks the way WordPress does: render_block_data on the way in (unless the node
 * says `noEnter`, as a query loop's `core/null` item shell does), children first, render_block on
 * the way out. A node: [name, attrs, html-before-children, children, html-after, context, noEnter].
 */
function provRender(ProvenanceStamps $s, array $node): string
{
    [$name, $attrs, $open, $children, $close] = $node;
    $context = $node[5] ?? [];
    $block = ['blockName' => $name, 'attrs' => $attrs];
    if (empty($node[6])) {
        $block = $s->enter($block);
    }
    $inner = '';
    foreach ($children as $child) {
        $inner .= provRender($s, $child);
    }
    return $s->leave($open . $inner . $close, $block, new FakeBlockInstance($context));
}

function provStamps(string $html): array
{
    preg_match_all('/<([a-z0-9-]+)[^>]*?data-tracy-src="([^"]*)"/', $html, $m, PREG_SET_ORDER);
    $out = [];
    foreach ($m as $hit) {
        $out[] = $hit[1] . ' ' . $hit[2];
    }
    return $out;
}

// ---- Stamping one element ----------------------------------------------------------------------

check('stamp goes on the first element', ProvenanceStamps::stamp('<p class="x">Hi</p>', 'block:core/paragraph'), '<p data-tracy-src="block:core/paragraph" class="x">Hi</p>');
check('leading whitespace and comments are skipped', ProvenanceStamps::stamp("\n<!-- x --> <div>a</div>", 'block:core/group'), "\n<!-- x --> <div data-tracy-src=\"block:core/group\">a</div>");
check('an element already stamped keeps its own stamp', ProvenanceStamps::stamp('<p data-tracy-src="post:1:a block:core/paragraph">a</p>', 'ref:9:x block:core/block'), '<p data-tracy-src="post:1:a block:core/paragraph">a</p>');
check('markup starting with text is left alone', ProvenanceStamps::stamp('plain text <b>x</b>', 'block:core/shortcode'), 'plain text <b>x</b>');
check('a leading <style> is not a box to label', ProvenanceStamps::stamp('<style>.a{}</style><div>x</div>', 'block:core/group'), '<style>.a{}</style><div>x</div>');
check('a self-closing first element is stamped', ProvenanceStamps::stamp('<img src="a.jpg"/>', 'block:core/image'), '<img data-tracy-src="block:core/image" src="a.jpg"/>');

// ---- Owners ------------------------------------------------------------------------------------

$site = new FakeProvenanceSite();
$site->slugs = [10 => 'four-common-mistakes', 21 => '500-000-work-hours', 22 => 'zavolzhye-tender', 30 => 'main-menu', 40 => 'news', 50 => 'cta-banner'];
$site->current = 10;
$site->page = 'post:10:four-common-mistakes';
$site->template = 'single';

// A single post: header part with the site title and a navigation menu; the post's content; and
// a "related posts" query loop inside a template part — the case the spike stamped with post 10.
$item = static function (int $id): array {
    return ['core/null', [], '', [
        ['core/post-featured-image', [], '<figure><img src="' . $id . '.jpg"></figure>', [], '', ['postId' => $id]],
        ['core/group', [], '<div class="teaser">', [
            ['core/post-title', [], '<h3><a>Title ' . $id . '</a></h3>', [], '', ['postId' => $id]],
            ['core/paragraph', [], '<p>teaser copy</p>', [], '', ['postId' => $id]],
        ], '</div>', ['postId' => $id]],
    ], '', ['postId' => $id], true];
};
$page = [
    ['core/template-part', ['slug' => 'header'], '<header class="wp-block-template-part">', [
        ['core/site-title', [], '<p class="wp-block-site-title">Northgate</p>', [], '', ['postId' => 10]],
        ['core/navigation', ['ref' => 30], '<nav>', [
            ['core/navigation-link', ['label' => 'About'], '<li><a href="/about/">About</a></li>', [], '', []],
        ], '</nav>', []],
    ], '</header>', ['postId' => 10]],
    ['core/post-title', [], '<h1>Four common mistakes</h1>', [], '', ['postId' => 10]],
    ['core/post-content', [], '<div class="entry-content">', [
        ['core/paragraph', [], '<p>Body text</p>', [], '', ['postId' => 10]],
        // A synced pattern prints no wrapper: its first element is its first inner block's.
        ['core/block', ['ref' => 50], '', [
            ['core/heading', [], '<h2>Ready to build?</h2>', [], '', ['postId' => 10]],
        ], '', ['postId' => 10]],
    ], '</div>', ['postId' => 10]],
    ['core/template-part', ['slug' => 'related'], '<section class="wp-block-template-part">', [
        ['core/query', ['queryId' => 3], '<div class="wp-block-query">', [
            ['core/post-template', [], '<ul class="wp-block-post-template">', [$item(21), $item(22)], '</ul>', ['postId' => 10, 'queryId' => 3]],
        ], '</div>', ['postId' => 10, 'queryId' => 3]],
    ], '</section>', ['postId' => 10]],
];
$s = new ProvenanceStamps($site);
$html = '';
foreach ($page as $node) {
    $html .= provRender($s, $node);
}
check('single post: every block names its owner', provStamps($html), [
    'header part:header block:core/template-part',
    'p site:identity block:core/site-title',
    'nav ref:30:main-menu block:core/navigation',
    'li ref:30:main-menu block:core/navigation-link',
    'h1 post:10:four-common-mistakes block:core/post-title',
    'div post:10:four-common-mistakes block:core/post-content',
    'p post:10:four-common-mistakes block:core/paragraph',
    'h2 ref:50:cta-banner block:core/heading',
    'section part:related block:core/template-part',
    'div part:related block:core/query',
    'ul part:related block:core/post-template',
    'figure post:21:500-000-work-hours block:core/post-featured-image',
    'div post:21:500-000-work-hours block:core/group',
    'h3 post:21:500-000-work-hours block:core/post-title',
    'p post:21:500-000-work-hours block:core/paragraph',
    'figure post:22:zavolzhye-tender block:core/post-featured-image',
    'div post:22:zavolzhye-tender block:core/group',
    'h3 post:22:zavolzhye-tender block:core/post-title',
    'p post:22:zavolzhye-tender block:core/paragraph',
]);
checkTrue('the item shells (core/null) do not unbalance the owner stack', strpos($html, 'core/null') === false);

// A query loop inside a loop item: the inner post-template puts its own post into the context.
$s = new ProvenanceStamps($site);
$nested = ['core/post-template', [], '<ul>', [
    ['core/null', [], '', [
        ['core/post-title', [], '<h3>21</h3>', [], '', ['postId' => 21]],
        ['core/post-template', [], '<ol>', [
            ['core/null', [], '', [
                ['core/paragraph', [], '<p>inner</p>', [], '', ['postId' => 22]],
            ], '', ['postId' => 22], true],
        ], '</ol>', ['postId' => 21]],
        ['core/paragraph', [], '<p>after inner loop</p>', [], '', ['postId' => 21]],
    ], '', ['postId' => 21], true],
], '</ul>', ['postId' => 10]];
check('nested loops: each block belongs to the item of its own loop', provStamps(provRender($s, $nested)), [
    'ul post:10:four-common-mistakes block:core/post-template',
    'h3 post:21:500-000-work-hours block:core/post-title',
    'ol post:21:500-000-work-hours block:core/post-template',
    'p post:22:zavolzhye-tender block:core/paragraph',
    'p post:21:500-000-work-hours block:core/paragraph',
]);

// The posts page (`/news/`): the heading and intro sit in the template, outside any owner; the
// global post is the FIRST post of the listing, which must not be taken for the owner.
$listing = new FakeProvenanceSite();
$listing->slugs = $site->slugs;
$listing->current = 21;
$listing->page = 'post:40:news';
$listing->template = 'home';
$s = new ProvenanceStamps($listing);
$html = provRender($s, ['core/group', [], '<div class="tracy-listing__head">', [
    ['core/heading', [], '<h1>News &amp; engineering notes</h1>', [], '', ['postId' => 21]],
    ['core/paragraph', [], '<p>Project updates, corporate disclosures</p>', [], '', ['postId' => 21]],
], '</div>', ['postId' => 21]]);
check('posts page: the listing head belongs to the posts page, not the first post', provStamps($html), [
    'div post:40:news block:core/group',
    'h1 post:40:news block:core/heading',
    'p post:40:news block:core/paragraph',
]);
ob_start();
$s->printPageOwner();
check('posts page: text printed outside every block is covered by the page owner', ob_get_clean(), '<template data-tracy-owner="post:40:news"></template>');

// A category archive: the query title and term description name the term, even inside a part.
$archive = new FakeProvenanceSite();
$archive->page = 'term:category:7:projects';
$archive->template = 'archive';
$s = new ProvenanceStamps($archive);
$html = provRender($s, ['core/template-part', ['slug' => 'archive-head'], '<div>', [
    ['core/query-title', ['type' => 'archive'], '<h1 class="wp-block-query-title">Projects</h1>', [], '', []],
    ['core/term-description', [], '<div class="wp-block-term-description"><p>All projects</p></div>', [], '', []],
], '</div>', []]);
check('term archive: title and description name the term', provStamps($html), [
    'div part:archive-head block:core/template-part',
    'h1 term:category:7:projects block:core/query-title',
    'div term:category:7:projects block:core/term-description',
]);

// A page about no record (search, 404): the template owns what nothing else does.
$search = new FakeProvenanceSite();
$search->template = 'search';
$s = new ProvenanceStamps($search);
check('no record: the block template is the owner', provStamps(provRender($s, ['core/heading', [], '<h1>Search</h1>', [], '', []])), ['h1 template:search block:core/heading']);
$bare = new FakeProvenanceSite();
$s = new ProvenanceStamps($bare);
check('nothing known: the block still says what it is', provStamps(provRender($s, ['core/heading', [], '<h1>x</h1>', [], '', []])), ['h1 block:core/heading']);
ob_start();
$s->printPageOwner();
check('nothing known: no page owner element', ob_get_clean(), '');

$feed = new FakeProvenanceSite();
$feed->pageRender = false;
$s = new ProvenanceStamps($feed);
check('a feed / REST render is never stamped', provRender($s, ['core/paragraph', [], '<p>x</p>', [], '', []]), '<p>x</p>');

$s = new ProvenanceStamps($site);
check('an attribute value is escaped', ProvenanceStamps::stamp('<p>x</p>', 'post:1:a"b block:core/paragraph'), '<p data-tracy-src="post:1:a&quot;b block:core/paragraph">x</p>');

// ---- When stamping happens -------------------------------------------------------------------

$TOKEN16 = str_repeat('a', 24);
checkTrue('header + token: stamps', ProvenanceStamps::requested(['HTTP_X_TRACY_PREVIEW' => 'pick'], $TOKEN16));
checkTrue('header value is case- and space-insensitive', ProvenanceStamps::requested(['HTTP_X_TRACY_PREVIEW' => ' Pick '], $TOKEN16));
check('no header: no stamps', ProvenanceStamps::requested([], $TOKEN16), false);
check('another header value: no stamps', ProvenanceStamps::requested(['HTTP_X_TRACY_PREVIEW' => 'refresh'], $TOKEN16), false);
check('header but the plugin is switched off (empty token): no stamps', ProvenanceStamps::requested(['HTTP_X_TRACY_PREVIEW' => 'pick'], ''), false);
check('header but a token too short to accept calls: no stamps', ProvenanceStamps::requested(['HTTP_X_TRACY_PREVIEW' => 'pick'], 'short'), false);

/** Run register() in a fresh process against fake hooks; report what it did. */
function provRegister(string $header, string $token, string $extra = ''): string
{
    $code = 'define("ABSPATH", "/tmp/"); $GLOBALS["hooks"] = [];'
        . 'function add_filter($h, $f, $p = 10, $a = 1) { $GLOBALS["hooks"][] = $h; return true; }'
        . 'function add_action($h, $f, $p = 10, $a = 1) { $GLOBALS["hooks"][] = $h; return true; }'
        . 'function get_option($k, $d = false) { return $k === "claude_cowork_token" ? ' . var_export($token, true) . ' : $d; }'
        . $extra
        . ($header === '' ? '' : '$_SERVER["HTTP_X_TRACY_PREVIEW"] = ' . var_export($header, true) . ';')
        . 'require ' . var_export(__DIR__ . '/../lib/ProvenanceStamps.php', true) . ';'
        . 'ProvenanceStamps::register();'
        . 'echo implode(",", $GLOBALS["hooks"]), "|", defined("DONOTCACHEPAGE") ? "nocache" : "cache", "|", defined("LSCACHE_NO_CACHE") ? "ls-nocache" : "ls-cache";';
    return trim((string) shell_exec(PHP_BINARY . ' -d display_errors=1 -r ' . escapeshellarg($code) . ' 2>&1'));
}

check('ordinary request: register() adds no hook and touches no cache setting (the page is byte-identical)', provRegister('', $TOKEN16), '|cache|ls-cache');
check('stamping request: hooks in and opts out of page caches', provRegister('pick', $TOKEN16), 'send_headers,render_block_data,render_block,wp_footer|nocache|ls-nocache');
check('stamping header on a switched-off site: nothing', provRegister('pick', ''), '|cache|ls-cache');
check('stamping header on an admin request: nothing', provRegister('pick', $TOKEN16, 'function is_admin() { return true; }'), '|cache|ls-cache');
check('stamping header on admin-ajax: nothing', provRegister('pick', $TOKEN16, 'function wp_doing_ajax() { return true; }'), '|cache|ls-cache');
$code = 'require ' . var_export(__DIR__ . '/../lib/ProvenanceStamps.php', true) . '; ProvenanceStamps::register(); echo "ok";';
check('loads and registers with no WordPress at all', trim((string) shell_exec(PHP_BINARY . ' -d display_errors=1 -r ' . escapeshellarg($code) . ' 2>&1')), 'ok');

check('a stamping response is private, uncacheable and varies on the cookie', ProvenanceStamps::headers(), [
    ['Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0', true],
    ['Vary: Cookie', false],
    ['Vary: X-Tracy-Preview', false],
    ['X-LiteSpeed-Cache-Control: no-cache', true],
    ['Referrer-Policy: no-referrer', true],
]);
