<?php

/**
 * @package     Claude Cowork for Joomla
 * @copyright   (C) 2026 Tracy
 * @license     GPL-2.0-or-later
 */

/**
 * Render stamps: marks in the page saying which CMS record printed which part of it.
 *
 * Tracy's page picker lets a person click an element of their own site and ask for a change. To
 * change the right record, it has to know what rendered that element — "module 443", "article 734"
 * — and only the CMS knows that, at the moment it renders. So while rendering, for a request that
 * asked for it, the system plugin prints:
 *
 *   data-tracy-src="module:<id> block:<module type>"          on a module's first element
 *   <template data-tracy-owner="article:<id>[:<alias>]">      inside every article render
 *   <template data-tracy-owner="category:<id>[:<alias>]">     inside a category's description
 *   data-tracy-src="article:<id>[:<alias>] block:<module type>"  on each item of an article-list module
 *   data-tracy-src="category:<id>[:<alias>]" / "tag:<id>[:<alias>]"  on each link to a category or a
 *                                              tag page, in a module's output and in the component's
 *
 * The format is a contract shared with the page runtime and the resolver in Tracy
 * (`<kind>:<id>[:<slug>] block:<name>`); change it there first. Menu items need nothing: Joomla
 * and T4 already print the id on every `<li>` (`item-599`, `data-id="599"`).
 *
 * Plain PHP with no Joomla dependency, like Door, so every rule here is pinned by tests/run.php.
 * The plugin only reads the request and the database and hands the results in.
 */
final class RenderStamps
{
    /** The trusted request header, set by Tracy's site proxy after it verified a preview ticket. */
    public const HEADER = 'X-Tracy-Preview';
    public const SERVER_KEY = 'HTTP_X_TRACY_PREVIEW';
    public const HEADER_VALUE = 'pick';

    /**
     * Modules that print nothing of their own but a list of articles, so each link to an article
     * inside them names the item it sits in. Measured on the spike (tasks/evidence/provenance-spike,
     * pass 3): these render articles WITHOUT firing any content event, so without this the whole
     * list was stamped "module N" and 27% of the page could not be resolved.
     *
     * `mod_menu` is deliberately absent: a menu item linking to an article prints the MENU ITEM's
     * title, and it already carries its own id on the `<li>`.
     */
    private const LIST_MODULES = [
        'mod_articles' => 1,          // Joomla 5.2+, the merged articles module
        'mod_articles_news' => 1,
        'mod_articles_latest' => 1,
        'mod_articles_category' => 1,
        'mod_articles_popular' => 1,
        'mod_related_items' => 1,
    ];

    /**
     * Modules that MAY be an article list. JA ACM is one module type with dozens of layouts: some
     * list articles (Selected projects), most print their own copy from module params and may hold
     * one call-to-action linking to an article page. Stamping that button "article N" would name
     * the wrong record for words that live in the module. So an ACM is treated as a list only when
     * it links to at least this many distinct articles — a list, not a button.
     */
    private const MAYBE_LIST_MODULES = ['mod_ja_acm' => 2];

    /** A module output is scanned for at most this many distinct links; a bigger one is not a list. */
    public const MAX_LINKS = 200;

    private const VOID = [
        'area' => 1, 'base' => 1, 'br' => 1, 'col' => 1, 'embed' => 1, 'hr' => 1, 'img' => 1,
        'input' => 1, 'link' => 1, 'meta' => 1, 'param' => 1, 'source' => 1, 'track' => 1, 'wbr' => 1,
    ];

    /**
     * Should this request be stamped?
     *
     * Two conditions, both required. The header, which Tracy's site proxy adds only after checking a
     * preview ticket against the viewer's session and seat, and which it strips when a browser sends
     * it. And a configured cowork token: a site whose token is empty has been disconnected from
     * Tracy on purpose, and nothing Tracy-shaped should happen on it any more.
     *
     * What this does NOT prove: a site reachable without the proxy (an imported site, or a direct
     * hit on the origin) cannot tell the proxy's header from anyone else's. What such a caller gets
     * is the ids of records already public on the page it asked for, on a response that is never
     * cached — no content, nothing behind a login. A signed ticket for those sites is the next step
     * (Tracy plan "S", HMAC of the cowork token), not this one.
     */
    public static function wanted($headerValue, string $token): bool
    {
        return is_string($headerValue)
            && strtolower(trim($headerValue)) === self::HEADER_VALUE
            && trim($token) !== '';
    }

    /** `<kind>:<id>[:<slug>]`, or null when the id is not a record. */
    public static function owner(string $kind, $id, $slug = null): ?string
    {
        $id = (int) $id;
        if ($id <= 0 || !preg_match('/^[a-zA-Z]+$/', $kind)) {
            return null;
        }
        $owner = $kind . ':' . $id;
        // A slug that could not be read back unambiguously (a space splits the value, a colon adds a
        // part) is left off rather than mangled: the id alone still names the record.
        if (is_string($slug) && $slug !== '' && !preg_match('/[\s:"<>]/u', $slug)) {
            $owner .= ':' . $slug;
        }
        return $owner;
    }

    /** The `data-tracy-src` value: `<owner> block:<name>`. */
    public static function src(string $owner, string $block): string
    {
        $block = preg_replace('/[^a-zA-Z0-9_.-]/', '', $block);
        return $block === '' ? $owner : $owner . ' block:' . $block;
    }

    /** The marker printed inside an article's or a category's own markup. */
    public static function marker(string $owner): string
    {
        return '<template data-tracy-owner="' . htmlspecialchars($owner, ENT_QUOTES, 'UTF-8') . '"></template>';
    }

    /**
     * Put `data-tracy-src` on the first element of a fragment.
     *
     * Leading whitespace and comments are skipped (module chrome and template overrides often open
     * with one). A fragment that opens with text, or whose first element already carries a stamp,
     * is returned unchanged — two `data-tracy-src` on one element is invalid HTML, and the inner
     * stamp is the more precise one.
     */
    public static function stampFirst(string $html, string $value): string
    {
        if (!preg_match('/\A(?:\s+|<!--.*?-->)*<([a-zA-Z][a-zA-Z0-9:-]*)/s', $html, $m, PREG_OFFSET_CAPTURE)) {
            return $html;
        }
        $at = $m[1][1] + strlen($m[1][0]);
        $close = self::tagEnd($html, $at);
        if ($close === null || self::hasStamp(substr($html, $at, $close - $at))) {
            return $html;
        }
        return self::insertAttribute($html, $at, $value);
    }

    /**
     * Stamp each item of an article-list module with the article it shows.
     *
     * ## Why over the rendered HTML
     *
     * These modules build their lists in their own helpers and print them with their own layouts;
     * Joomla fires no content event per item (not onContentPrepare, not onContentAfterTitle, not
     * onContentBeforeDisplay), and there is no core hook in Joomla 5 or 6 between "the helper
     * fetched the items" and "the layout printed them". Template overrides per module and per
     * template would each need writing and would miss every third-party layout. What every layout
     * does print is a link to each article — so the link is the one generic signal.
     *
     * ## Why a tag scanner and not DOMDocument
     *
     * DOMDocument would find the elements, but to change them it re-serialises the whole fragment:
     * libxml's HTML 4 parser reorders what HTML5 allows (a block inside `<a>`, `<svg>`, `<template>`),
     * rewrites entities and quoting, and a picker that shows a subtly different page misleads the
     * person using it. The scanner below only learns where each start tag sits and which element
     * encloses which, then inserts one attribute into the original bytes. It is tolerant the way
     * browsers are: void elements, stray end tags, unclosed elements, comments and raw-text
     * elements (script, style, textarea, title) are all handled without failing.
     *
     * ## Which element gets the stamp
     *
     * Each element collects the set of articles linked inside it. An item is the LARGEST element
     * whose set is exactly one article — the `<li>` holding the image link, title link and date,
     * not the bare `<a>` — mirroring how the picker reads markers (nearest ancestor holding exactly
     * one). Top-level elements of the module are never chosen: the first of them carries the
     * module's own stamp. An element that already carries a stamp is left alone.
     *
     * @param callable $resolve fn(string[] $hrefs): array<string, array{0:int,1:?string}> — the
     *                          article id (and alias) each href is a link to; hrefs it cannot tie to
     *                          exactly one article are simply absent. Hrefs arrive entity-decoded.
     */
    public static function stampListItems(string $html, string $moduleType, callable $resolve): string
    {
        $minArticles = isset(self::LIST_MODULES[$moduleType]) ? 1 : (self::MAYBE_LIST_MODULES[$moduleType] ?? 0);
        if ($minArticles === 0 || stripos($html, '<a') === false) {
            return $html;
        }

        $nodes = self::scan($html);
        $hrefs = [];
        foreach ($nodes as $node) {
            if ($node['href'] !== null) {
                $hrefs[$node['href']] = true;
            }
        }
        if ($hrefs === [] || count($hrefs) > self::MAX_LINKS) {
            return $html;
        }
        $articles = $resolve(array_keys($hrefs));

        // Articles per element, collected upward: a node's set is its own link's article plus its
        // children's. Nodes are in document order, so a child always comes after its parent;
        // walking backwards folds every child into its parent before the parent is read.
        $sets = [];
        $all = [];
        for ($i = count($nodes) - 1; $i >= 0; $i--) {
            $href = $nodes[$i]['href'];
            if ($href !== null && isset($articles[$href])) {
                $id = (int) $articles[$href][0];
                $sets[$i][$id] = $articles[$href];
                $all[$id] = true;
            }
            $parent = $nodes[$i]['parent'];
            if ($parent !== null && isset($sets[$i])) {
                $sets[$parent] = ($sets[$parent] ?? []) + $sets[$i];
            }
        }
        if (count($all) < $minArticles) {
            return $html;
        }

        $inserts = [];
        foreach ($nodes as $i => $node) {
            if (!isset($sets[$i]) || count($sets[$i]) !== 1 || $node['depth'] === 0 || $node['stamped']) {
                continue;
            }
            $parent = $node['parent'];
            // The largest such element: its parent either sees several articles or is top-level.
            if ($parent !== null && $nodes[$parent]['depth'] > 0 && count($sets[$parent]) === 1) {
                continue;
            }
            $hit = reset($sets[$i]);
            $owner = self::owner('article', $hit[0], $hit[1] ?? null);
            if ($owner !== null) {
                $inserts[$node['at']] = self::src($owner, $moduleType);
            }
        }

        krsort($inserts);
        foreach ($inserts as $at => $value) {
            $html = self::insertAttribute($html, $at, $value);
        }
        return $html;
    }

    /**
     * What an href says about the article it may point to, before any database is asked.
     *
     * - `nonSef`: the id of a raw `index.php?option=com_content&view=article&id=N` link — Joomla's
     *   own address for an article, needing no router to read.
     * - `path`: any other internal link in one spelling (path and query, `normaliseLink`), for
     *   comparing against the route Joomla builds for a candidate article. The query counts: an
     *   article no menu item reaches is routed as `/component/content/article/<alias>?catid=N`, so
     *   two articles sharing an alias differ only there.
     * - `id` / `alias`: the candidates the last path segment names — `12-my-alias` when the site
     *   keeps ids in its URLs, `my-alias` when it does not (the Joomla 4+ default). A suffix
     *   (`.html`) is dropped.
     *
     * Null for anything that is not a link into this site: another host, `mailto:`, a bare `#`.
     *
     * @return array{nonSef:?int, path:?string, id:?int, alias:?string}|null
     */
    public static function hrefHints(string $href, string $siteHost): ?array
    {
        $href = trim($href);
        if ($href === '' || $href[0] === '#') {
            return null;
        }
        $parts = parse_url($href);
        if ($parts === false) {
            return null;
        }
        if (isset($parts['scheme']) && !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return null;
        }
        if (isset($parts['host']) && strcasecmp($parts['host'], $siteHost) !== 0) {
            return null;
        }
        $path = isset($parts['path']) ? rawurldecode($parts['path']) : '';
        parse_str($parts['query'] ?? '', $query);

        if (($query['option'] ?? null) === 'com_content' && ($query['view'] ?? null) === 'article'
            && isset($query['id']) && is_string($query['id']) && preg_match('/^(\d+)/', $query['id'], $m)) {
            return ['nonSef' => (int) $m[1], 'path' => null, 'id' => (int) $m[1], 'alias' => null];
        }
        if ($path === '' || $path === '/' || isset($query['option'])) {
            return null;
        }

        $segments = array_values(array_filter(explode('/', $path), 'strlen'));
        $last = (string) end($segments);
        $last = preg_replace('/\.[a-z0-9]{1,5}$/i', '', $last);
        $id = null;
        $alias = $last;
        if (preg_match('/^(\d+)-(.+)$/', $last, $m)) {
            $id = (int) $m[1];
            $alias = $m[2];
        }
        return ['nonSef' => null, 'path' => self::normaliseLink($href), 'id' => $id, 'alias' => $alias === '' ? null : $alias];
    }

    /**
     * {@see hrefHints()} for a link to another kind of page: a category (`com_content` /
     * `category`) or a tag (`com_tags` / `tag`). The raw form names its id the way that view takes
     * it (`&id=N`, or `&id[0]=N` for a tag).
     *
     * @return array{nonSef:?int, path:?string, id:?int, alias:?string}|null
     */
    public static function viewHints(string $href, string $siteHost, string $option, string $view): ?array
    {
        $href = trim($href);
        $parts = $href === '' || $href[0] === '#' ? false : parse_url($href);
        if ($parts === false) {
            return null;
        }
        parse_str($parts['query'] ?? '', $query);
        if (($query['option'] ?? null) === $option && ($query['view'] ?? null) === $view) {
            if (isset($parts['host']) && strcasecmp($parts['host'], $siteHost) !== 0) {
                return null;
            }
            $id = $query['id'] ?? null;
            if (is_array($id)) {
                $id = count($id) === 1 ? reset($id) : null;
            }
            return is_string($id) && preg_match('/^(\d+)/', $id, $m)
                ? ['nonSef' => (int) $m[1], 'path' => null, 'id' => (int) $m[1], 'alias' => null]
                : null;
        }
        $hint = self::hrefHints($href, $siteHost);
        return $hint === null || $hint['nonSef'] !== null ? null : $hint;
    }

    /**
     * Stamp each link to a record page — a category, a tag — with that record, on the `<a>` itself.
     *
     * Not the largest element, as for article lists: a category or tag link is a label ("Disclosure"
     * in a breadcrumb, a filter chip, the category line of a news card) sitting inside an item that
     * belongs to something else (the article the card shows). The `<a>` is the one element whose
     * words are the record's. A link already stamped keeps its stamp.
     *
     * @param callable $resolve fn(string[] $hrefs): array<string, string> — the owner
     *                          (`category:12:alias`) each href links to; others absent.
     */
    public static function stampLinks(string $html, callable $resolve, string $block = ''): string
    {
        if (stripos($html, '<a') === false) {
            return $html;
        }
        $nodes = self::scan($html);
        $hrefs = [];
        foreach ($nodes as $node) {
            if ($node['href'] !== null) {
                $hrefs[$node['href']] = true;
            }
        }
        if ($hrefs === [] || count($hrefs) > self::MAX_LINKS) {
            return $html;
        }
        $owners = $resolve(array_keys($hrefs));
        $inserts = [];
        foreach ($nodes as $node) {
            if ($node['href'] !== null && !$node['stamped'] && is_string($owners[$node['href']] ?? null)) {
                $inserts[$node['at']] = self::src($owners[$node['href']], $block);
            }
        }
        krsort($inserts);
        foreach ($inserts as $at => $value) {
            $html = self::insertAttribute($html, $at, $value);
        }
        return $html;
    }

    /**
     * One spelling of a link — decoded path without its trailing slash, then the query sorted by
     * key — so a route Joomla builds and an href a layout printed compare equal exactly when they
     * are the same address. Scheme, host and fragment are dropped: the caller already knows the
     * link is on this site.
     */
    public static function normaliseLink(string $link): string
    {
        $parts = parse_url($link);
        if ($parts === false) {
            return '';
        }
        $normal = '/' . trim(rawurldecode($parts['path'] ?? ''), '/');
        parse_str($parts['query'] ?? '', $query);
        if ($query !== []) {
            ksort($query);
            $normal .= '?' . http_build_query($query);
        }
        return $normal;
    }

    // -------------------------------------------------------------------------------- scanner --

    /**
     * Every element of a fragment, in document order: where its start tag's name ends (the insert
     * point), its parent, its depth, its link (for `<a href>`), and whether it is already stamped.
     *
     * @return array<int, array{at:int, parent:?int, depth:int, href:?string, stamped:bool}>
     */
    private static function scan(string $html): array
    {
        $nodes = [];
        $stack = [];   // [name, node index]
        $len = strlen($html);
        $pos = 0;
        while ($pos < $len) {
            $lt = strpos($html, '<', $pos);
            if ($lt === false) {
                break;
            }
            if (substr_compare($html, '<!--', $lt, 4) === 0) {
                $end = strpos($html, '-->', $lt + 4);
                $pos = $end === false ? $len : $end + 3;
                continue;
            }
            if (!preg_match('/\G<(\/?)([a-zA-Z][a-zA-Z0-9:-]*)/', $html, $m, 0, $lt)) {
                $pos = $lt + 1;
                continue;
            }
            $nameEnd = $lt + strlen($m[0]);
            $close = self::tagEnd($html, $nameEnd);
            if ($close === null) {
                break;
            }
            $name = strtolower($m[2]);
            $pos = $close + 1;

            if ($m[1] === '/') {
                // Pop to the matching element; an end tag nothing opened is ignored, as browsers do.
                for ($s = count($stack) - 1; $s >= 0; $s--) {
                    if ($stack[$s][0] === $name) {
                        array_splice($stack, $s);
                        break;
                    }
                }
                continue;
            }

            $attrs = substr($html, $nameEnd, $close - $nameEnd);
            $parent = $stack === [] ? null : $stack[count($stack) - 1][1];
            $href = null;
            if ($name === 'a' && preg_match('/(?:^|\s)href\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $attrs, $h)) {
                $href = html_entity_decode($h[1] !== '' ? $h[1] : ($h[2] ?? '') . ($h[3] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if ($href === '') {
                    $href = null;
                }
            }
            $nodes[] = ['at' => $nameEnd, 'parent' => $parent, 'depth' => count($stack), 'href' => $href, 'stamped' => self::hasStamp($attrs)];
            $index = count($nodes) - 1;

            if (isset(self::VOID[$name]) || substr(rtrim($attrs), -1) === '/') {
                continue;
            }
            if (in_array($name, ['script', 'style', 'textarea', 'title'], true)) {
                // Raw text: nothing inside is markup, however much it looks like it.
                $end = stripos($html, '</' . $name, $pos);
                $pos = $end === false ? $len : $end;
                continue;
            }
            $stack[] = [$name, $index];
        }
        return $nodes;
    }

    /** The offset of the `>` closing a start tag whose attributes begin at $from, quotes respected. */
    private static function tagEnd(string $html, int $from): ?int
    {
        $len = strlen($html);
        $quote = null;
        for ($i = $from; $i < $len; $i++) {
            $c = $html[$i];
            if ($quote !== null) {
                if ($c === $quote) {
                    $quote = null;
                }
            } elseif ($c === '"' || $c === "'") {
                $quote = $c;
            } elseif ($c === '>') {
                return $i;
            }
        }
        return null;
    }

    private static function hasStamp(string $attrs): bool
    {
        return (bool) preg_match('/(?:^|\s)data-tracy-src\s*=/i', $attrs);
    }

    private static function insertAttribute(string $html, int $at, string $value): string
    {
        return substr($html, 0, $at) . ' data-tracy-src="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '"' . substr($html, $at);
    }
}
