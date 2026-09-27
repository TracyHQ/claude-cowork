<?php
/**
 * ProvenanceStamps — tells a Tracy preview which record rendered each block on the page.
 *
 * WHAT. When a page is requested for Tracy's element picker, every rendered block gets one
 * attribute on its first element:
 *
 *     data-tracy-src="<owner> block:<blockName>"
 *
 * where <owner> is the record that produced the block:
 *
 *     post:<id>:<slug>                 a post or page (the page being viewed, the post of a query-loop
 *                                      item, the post a post-title/excerpt/date/image block shows)
 *     part:<slug>                      a template part (header, footer, …)
 *     ref:<id>:<slug>                  any block pointing at a stored post through a numeric `ref`
 *                                      (a `wp_navigation` menu, a synced pattern)
 *     site:identity                    site title, tagline, logo
 *     term:<taxonomy>:<id>:<slug>      the term an archive is about (archive title, term description)
 *     template:<slug>                  the block template, when the page is about no record
 *                                      (search, date archive, 404)
 *
 * A block with no owner still says what it is: `data-tracy-src="block:core/paragraph"`. The page
 * also carries one `<template data-tracy-owner="<owner>">` in the footer: the owner of anything the
 * theme printed outside every block. The picker reads the stamps of an element and its ancestors,
 * innermost first; the resolver in Tracy intersects them with its value index and never guesses.
 * The format is shared with the page runtime and the resolver: changing a kind here is a
 * contract change on both.
 *
 * WHEN. Only when the request carries `X-Tracy-Preview: pick` AND this site has a configured token.
 * Tracy's site proxy sets that header after it has verified a preview ticket, and strips any
 * `x-tracy-*` header a browser sent. This plugin has no signal of its own that a request came
 * through the proxy (a site reached directly can be sent the header by anyone), so the token is
 * the only extra condition: a site that switched this plugin off (empty token) never stamps. What a
 * forged header can obtain is the ids and slugs of records the same request already renders — no
 * content it could not read without the header.
 *
 * Without the header, register() returns before adding a single hook: an ordinary visitor gets the
 * byte-identical page (tests/provenance-stamps.php proves no hook is added; README has the curl check).
 *
 * CACHES. A stamped page looks, to a page cache, like any anonymous page view — cached, it would be
 * served to the public. So a stamping request is kept out of every cache it can reach: WordPress's
 * own `nocache_headers()`, `DONOTCACHEPAGE` (WP Super Cache, W3 Total Cache, WP Rocket, and most
 * others), `LSCACHE_NO_CACHE` plus LiteSpeed's own no-cache call and header, and
 * `Cache-Control: private, no-store` with `Vary: Cookie`. A cache that answers before PHP runs
 * (advanced-cache.php, a server cache) can still serve its ordinary copy to a stamping request: the
 * picker then sees no stamps, which is a missing label, never a leak.
 *
 * HOW. Two core filters every block passes through: `render_block_data` on the way in and
 * `render_block` on the way out. Blocks that render other records inside themselves push an owner
 * on the way in and pop it on the way out, so everything rendered in between is theirs. Two cases
 * the throwaway spike (TCH `tasks/evidence/provenance-spike`, measured 97% on 27/09/2026) got wrong
 * are fixed here:
 *
 *  1. A query loop's items. `core/post-template` renders each item as a fresh `core/null` block
 *     built without its ancestors' context, so no block inside an item sees `queryId` (read in
 *     WordPress 7.1.2 `blocks/post-template.php`). The spike used `queryId` to recognise a loop
 *     and, inside a template part, stamped a related-post teaser with the post being viewed. Here
 *     `core/post-template` itself pushes a loop marker, and a block under it is owned by the
 *     `postId` the loop put in its context — right at any nesting depth, because each nested
 *     post-template adds its own context filter after the outer one.
 *  2. A listing's heading. Top-level blocks on a page about a record (a single post, the posts
 *     page, a term archive) are owned by that record; `core/query-title` and `core/term-description`
 *     are owned by it wherever they sit; and text the theme printed outside every block is covered
 *     by the page-level `<template data-tracy-owner>`. Filtering `get_the_archive_title` instead was
 *     rejected: themes print its value inside attributes (`esc_attr(get_the_archive_title())`), where
 *     markup would show up as text.
 *
 * Loaded on every request by the plugin file, so it loads nothing else and touches no WordPress
 * function until a stamping request needs one.
 */

/**
 * What the stamps need to know about the site. The WordPress implementation is below; the tests
 * pass their own, so the owner logic is tested without a WordPress.
 */
interface ProvenanceSite
{
    /** The slug (`post_name`) of a post, or '' when there is none. */
    public function slug(int $postId): string;

    /** The global post (`get_the_ID()`), 0 when there is none. */
    public function currentPostId(): int;

    /** The record this page is about, as an owner (`post:…` / `term:…`), or null. */
    public function pageOwner(): ?string;

    /** The slug of the block template being rendered, or null (classic theme, unknown). */
    public function templateSlug(): ?string;

    /** False for renders that are not the page itself: REST, feeds, embeds. */
    public function isPageRender(): bool;
}

final class ProvenanceStamps
{
    /** The request header Tracy's site proxy sets after verifying a preview ticket. */
    public const HEADER = 'HTTP_X_TRACY_PREVIEW';
    public const HEADER_VALUE = 'pick';
    /** The token option, spelled here so the engine is not loaded on a page view. */
    public const TOKEN_OPTION = 'claude_cowork_token';

    /** Blocks whose output is the site's identity rather than any post. */
    private const IDENTITY_BLOCKS = ['core/site-title', 'core/site-tagline', 'core/site-logo'];

    /** Blocks that print a field of the post in their context — that post owns them. */
    private const POST_FIELD_BLOCKS = [
        'core/post-title', 'core/post-excerpt', 'core/post-date', 'core/post-featured-image',
        'core/post-author', 'core/post-author-name', 'core/post-author-biography', 'core/post-terms',
        'core/read-more',
    ];

    /** Blocks that print the record the page is about (an archive's term, the posts page). */
    private const PAGE_RECORD_BLOCKS = ['core/query-title', 'core/term-description'];

    /** Not blocks a reader sees: freeform HTML between blocks, and a query-loop item's shell. */
    private const NEVER_STAMPED = ['', 'core/null'];

    /** Leading elements that are not the block's own box: stamping one would label nothing. */
    private const NOT_A_BOX = ['script', 'style', 'link', 'meta', 'template', 'noscript'];

    /** Owner marker for `core/post-template`: resolved per item, from the block's context. */
    private const LOOP = "\0loop";

    /** @var ProvenanceSite */
    private $site;

    /** @var array<int, array{name: string, owner: ?string}> blocks that own what renders inside them */
    private $stack = [];

    /** @var array<int, string> slug lookups, one query per post per request */
    private $slugs = [];

    public function __construct(ProvenanceSite $site)
    {
        $this->site = $site;
    }

    /**
     * Whether this request asked for stamps and this site may give them.
     *
     * @param array<string, mixed> $server `$_SERVER`
     */
    public static function requested(array $server, string $token): bool
    {
        $asked = strtolower(trim((string) ($server[self::HEADER] ?? ''))) === self::HEADER_VALUE;
        // Same floor as Token::isConfigured: a token too short to accept calls is no token at all.
        return $asked && strlen(trim($token)) >= 16;
    }

    /** Headers of a stamping response, in the order they are sent. */
    public static function headers(): array
    {
        return [
            // After nocache_headers(), which older WordPress sends without `no-store`/`private`.
            ['Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0', true],
            ['Vary: Cookie', false],
            ['Vary: X-Tracy-Preview', false],
            ['X-LiteSpeed-Cache-Control: no-cache', true],
            ['Referrer-Policy: no-referrer', true],
        ];
    }

    /**
     * Hook in, only for a stamping request. Safe wherever the plugin file is included: without
     * WordPress (a lint, a fresh process) there is nothing to hook and nothing happens.
     */
    public static function register(): void
    {
        if (!function_exists('add_filter') || !function_exists('get_option')) {
            return;
        }
        if (!self::requested($_SERVER, (string) get_option(self::TOKEN_OPTION, ''))) {
            return;
        }
        // The picker works on the public page. The admin, admin-ajax (this plugin's own endpoint
        // included) and WP-CLI are never stamped, whatever header arrives with them.
        if ((function_exists('is_admin') && is_admin())
            || (function_exists('wp_doing_ajax') && wp_doing_ajax())
            || (defined('WP_CLI') && WP_CLI)) {
            return;
        }

        self::keepOutOfPageCaches();
        add_action('send_headers', [self::class, 'sendHeaders'], 999);

        $stamps = new self(new WordPressProvenanceSite());
        add_filter('render_block_data', [$stamps, 'enter'], 10, 1);
        // Late, so filters that rewrite a block's markup (NavigationLinks at 10) see it unstamped.
        add_filter('render_block', [$stamps, 'leave'], 999, 3);
        add_action('wp_footer', [$stamps, 'printPageOwner'], 999);
    }

    /** Page-cache opt-outs, defined as early as possible: some caches read them at shutdown. */
    public static function keepOutOfPageCaches(): void
    {
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        if (!defined('LSCACHE_NO_CACHE')) {
            define('LSCACHE_NO_CACHE', true);
        }
    }

    public static function sendHeaders(): void
    {
        if (function_exists('nocache_headers')) {
            nocache_headers();
        }
        if (!headers_sent()) {
            foreach (self::headers() as [$line, $replace]) {
                header($line, $replace);
            }
        }
        if (function_exists('do_action')) {
            // LiteSpeed Cache's documented API; a no-op on a site without it.
            do_action('litespeed_control_set_nocache', 'Tracy preview stamps');
        }
    }

    /**
     * `render_block_data`: a block that owns what renders inside it pushes its owner.
     *
     * @param mixed $block
     * @return mixed the block, unchanged
     */
    public function enter($block)
    {
        if (is_array($block) && $this->ownsInside($block)) {
            $this->stack[] = ['name' => (string) $block['blockName'], 'owner' => $this->ownerOf($block)];
        }
        return $block;
    }

    /**
     * `render_block`: pop what enter() pushed, then stamp the block's first element.
     *
     * @param mixed $html
     * @param mixed $block
     * @param mixed $instance the WP_Block being rendered (its `context` is read)
     * @return mixed
     */
    public function leave($html, $block, $instance = null)
    {
        if (!is_array($block) || !is_string($html)) {
            return $html;
        }
        $name = (string) ($block['blockName'] ?? '');

        $own = null;
        $top = end($this->stack);
        // Popped by name, not blindly: a block rendered without passing render_block_data (a query
        // loop's `core/null` item shell) must not take its parent's entry with it.
        if ($top !== false && $top['name'] === $name && $this->ownsInside($block)) {
            $own = array_pop($this->stack);
        }

        if (in_array($name, self::NEVER_STAMPED, true) || trim($html) === '' || !$this->site->isPageRender()) {
            return $html;
        }

        $context = is_object($instance) && isset($instance->context) && is_array($instance->context)
            ? $instance->context : [];
        $owner = $this->resolve($name, $own, $context);

        return self::stamp($html, trim(($owner ?? '') . ' block:' . $name));
    }

    /** `wp_footer`: the owner of anything the theme printed outside every block. */
    public function printPageOwner(): void
    {
        if (!$this->site->isPageRender()) {
            return;
        }
        $owner = $this->pageFallback();
        if ($owner !== null) {
            echo '<template data-tracy-owner="' . self::attr($owner) . '"></template>';
        }
    }

    /**
     * Put `data-tracy-src` on the first element of a rendered block. Leading whitespace and HTML
     * comments are skipped; a block whose markup starts with text or a non-box element, or whose
     * first element is already stamped (a wrapperless block — a synced pattern, a pattern — whose
     * first element is its first inner block, stamped more precisely already), is left as it is.
     */
    public static function stamp(string $html, string $stamp): string
    {
        if (!preg_match('/^(?:\s|<!--.*?-->)*<([a-zA-Z][a-zA-Z0-9-]*)(?=[\s>\/])/s', $html, $m, PREG_OFFSET_CAPTURE)) {
            return $html;
        }
        $tag = $m[1][0];
        $at = $m[1][1] + strlen($tag);
        if (in_array(strtolower($tag), self::NOT_A_BOX, true)) {
            return $html;
        }
        $end = strpos($html, '>', $at);
        if ($end !== false && strpos(substr($html, $at, $end - $at), 'data-tracy-src=') !== false) {
            return $html;
        }
        return substr($html, 0, $at) . ' data-tracy-src="' . self::attr($stamp) . '"' . substr($html, $at);
    }

    /** Blocks that push an owner for everything rendered inside them. */
    private function ownsInside(array $block): bool
    {
        $name = (string) ($block['blockName'] ?? '');
        $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
        return $name === 'core/template-part'
            || $name === 'core/post-template'
            || $name === 'core/post-content'
            || (isset($attrs['ref']) && is_numeric($attrs['ref']) && (int) $attrs['ref'] > 0);
    }

    /** The owner an owning block pushes (called on the way in). */
    private function ownerOf(array $block): ?string
    {
        $name = (string) $block['blockName'];
        $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
        if ($name === 'core/template-part') {
            $slug = trim((string) ($attrs['slug'] ?? ''));
            return 'part:' . ($slug === '' ? 'part' : $slug);
        }
        if ($name === 'core/post-template') {
            return self::LOOP;
        }
        if ($name === 'core/post-content') {
            // On the way in there is no block context yet; the global post is the one this content
            // belongs to — the viewed post, or the loop item (post-template calls the_post()).
            return $this->post($this->site->currentPostId());
        }
        return 'ref:' . (int) $attrs['ref'] . ':' . $this->slugOf((int) $attrs['ref']);
    }

    /**
     * Who owns a block being stamped.
     *
     * @param array{name: string, owner: ?string}|null $own the entry this block pushed, if any
     * @param array<string, mixed> $context the block's context
     */
    private function resolve(string $name, ?array $own, array $context): ?string
    {
        if (in_array($name, self::IDENTITY_BLOCKS, true)) {
            return 'site:identity';
        }
        // A part, a ref or a post's content owns its own box. A query loop's list does not: the
        // `<ul>` belongs to whatever holds the loop.
        if ($own !== null && $own['owner'] !== self::LOOP && $own['owner'] !== null) {
            return $own['owner'];
        }
        $postId = (int) ($context['postId'] ?? 0);
        if ($postId > 0 && in_array($name, self::POST_FIELD_BLOCKS, true)) {
            return $this->post($postId);
        }
        if (in_array($name, self::PAGE_RECORD_BLOCKS, true)) {
            $page = $this->site->pageOwner();
            if ($page !== null) {
                return $page;
            }
        }
        for ($i = count($this->stack) - 1; $i >= 0; $i--) {
            $owner = $this->stack[$i]['owner'];
            if ($owner === self::LOOP) {
                // Inside an item, post-template has put the item's post into every block's context.
                return $this->post($postId > 0 ? $postId : $this->site->currentPostId());
            }
            if ($owner !== null) {
                return $owner;
            }
        }
        return $this->pageFallback();
    }

    /** Outside every owner: the record the page is about, else the block template. */
    private function pageFallback(): ?string
    {
        $page = $this->site->pageOwner();
        if ($page !== null) {
            return $page;
        }
        $template = $this->site->templateSlug();
        return $template === null || $template === '' ? null : 'template:' . $template;
    }

    private function post(int $id): ?string
    {
        return $id > 0 ? 'post:' . $id . ':' . $this->slugOf($id) : null;
    }

    private function slugOf(int $id): string
    {
        if (!isset($this->slugs[$id])) {
            $this->slugs[$id] = $this->site->slug($id);
        }
        return $this->slugs[$id];
    }

    private static function attr(string $value): string
    {
        return function_exists('esc_attr') ? esc_attr($value) : htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}

/** The site, read through WordPress. Called only on a stamping request. */
final class WordPressProvenanceSite implements ProvenanceSite
{
    /** @var string|null|false false until read */
    private $page = false;

    public function slug(int $postId): string
    {
        return function_exists('get_post_field') ? (string) get_post_field('post_name', $postId) : '';
    }

    public function currentPostId(): int
    {
        return function_exists('get_the_ID') ? (int) get_the_ID() : 0;
    }

    public function pageOwner(): ?string
    {
        if ($this->page === false) {
            $this->page = $this->readPageOwner();
        }
        return $this->page;
    }

    private function readPageOwner(): ?string
    {
        if (is_singular()) {
            $post = get_queried_object();
            return $post instanceof WP_Post ? 'post:' . $post->ID . ':' . $post->post_name : null;
        }
        // The posts page (`page_for_posts`): a listing, but a page record holds its title and text.
        if (is_home()) {
            $id = (int) get_option('page_for_posts', 0);
            return $id > 0 ? 'post:' . $id . ':' . $this->slug($id) : null;
        }
        if (is_category() || is_tag() || is_tax()) {
            $term = get_queried_object();
            return $term instanceof WP_Term
                ? 'term:' . $term->taxonomy . ':' . $term->term_id . ':' . $term->slug
                : null;
        }
        return null;
    }

    public function templateSlug(): ?string
    {
        // `theme//slug`, set by locate_block_template() since WordPress 6.3; absent on a classic theme.
        $id = isset($GLOBALS['_wp_current_template_id']) ? (string) $GLOBALS['_wp_current_template_id'] : '';
        if ($id === '') {
            return null;
        }
        $at = strpos($id, '//');
        return $at === false ? $id : substr($id, $at + 2);
    }

    public function isPageRender(): bool
    {
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return false;
        }
        return !(function_exists('is_feed') && is_feed()) && !(function_exists('is_embed') && is_embed());
    }
}
