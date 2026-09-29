<?php

/**
 * @package     Claude Cowork for Joomla
 * @copyright   (C) 2026 Tracy
 * @license     GPL-2.0-or-later
 */

namespace Tracy\Component\ClaudeCowork\Administrator\Service;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Http\HttpFactory;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;

/**
 * What a DERIVED contract is made of on Joomla: the public rows of an imported site, in the shape
 * `DerivedMap::build()` takes, and the rendered pages that calibrate which nested leaves a visitor
 * reads. Plain SQL through the site's own connection; nothing here writes.
 *
 * How a derived entity is resolved (read from QuickstartContract::resolveRows, 29/09/2026): a BOUND
 * row is found by the id its binding records, and only an unbound one by matching its `identity`
 * fields across the whole kind. A derived map is rebuilt from these rows on every request, so its
 * entities always resolve by their own id (`sourceId`); `identity` is recorded for the reader of the
 * map, never matched. `{id}` for every kind with an id column; `{fieldId, itemId}` for a custom
 * field value, whose row id is the pair packed by \FieldValueKey.
 *
 * Only what the public sees: published rows, the site (client_id 0) menus and modules, com_content
 * categories, published article fields, and the site template styles that are a home or that a
 * published menu item names. Extension tables are not read (v1: they are not written either).
 */
final class JoomlaDerivedRows
{
    /**
     * Custom field types whose one stored value is the words or picture a visitor sees — the same
     * list as ContentProjection::FIELD_TYPES. A list, checkbox or radio value is an option key, and a
     * multiple-value field keeps one row per choice, which no single slot can stand for.
     */
    private const FIELD_TYPES = ['text', 'textarea', 'editor', 'media'];
    /** One budget for every page a derive fetches: it runs under the serialized write lock (Door::TIME_LIMIT is 300 s). */
    private const PAGES_SECONDS = 60;
    /** The render check after a derived apply: it runs under the same lock, and a write should not wait a minute on it. */
    public const CHECK_SECONDS = 15;

    /**
     * @param list<string> $unresolved gains one line per value too large to scan
     * @return list<array{kind:string,id:int,identity:array,core:array<string,string>,html:array<string,string>,nested:array<string,string>}>
     */
    public static function rows(DatabaseInterface $db, array &$unresolved = []): array
    {
        $out = [];
        $q = static fn(string $sql): array => $db->setQuery($sql)->loadAssocList() ?: [];
        $n = static fn(string $column): string => $db->quoteName($column);
        // Published and inside its window now, as the site serves it (Joomla stores both dates in UTC).
        $now = $db->quote(Factory::getDate()->toSql());
        foreach ($q('SELECT id, title, introtext, ' . $n('fulltext') . ', attribs, images, urls FROM #__content WHERE state = 1'
            . ' AND (publish_up IS NULL OR publish_up <= ' . $now . ') AND (publish_down IS NULL OR publish_down > ' . $now . ') ORDER BY id') as $r)
            $out[] = self::row('article', (int) $r['id'], ['id' => (int) $r['id']], $r, ['title'], ['introtext', 'fulltext'], ['attribs', 'images', 'urls'], $unresolved);
        // `level > 0` leaves out the tree's root; `main` is the administrator's own menu type.
        foreach ($q('SELECT id, title, params FROM #__menu WHERE published = 1 AND client_id = 0 AND level > 0 AND menutype <> ' . $db->quote('main') . ' ORDER BY id') as $r)
            $out[] = self::row('menuItem', (int) $r['id'], ['id' => (int) $r['id']], $r, ['title'], [], ['params'], $unresolved);
        foreach ($q('SELECT id, title, ' . $n('module') . ', content, params FROM #__modules WHERE published = 1 AND client_id = 0 ORDER BY id') as $r)
            $out[] = self::row('module', (int) $r['id'], ['id' => (int) $r['id']], $r, ['title'], $r['module'] === 'mod_custom' ? ['content'] : [], ['params'], $unresolved);
        foreach ($q('SELECT id, title, description, params FROM #__categories WHERE published = 1 AND extension = ' . $db->quote('com_content') . ' ORDER BY id') as $r)
            $out[] = self::row('category', (int) $r['id'], ['id' => (int) $r['id']], $r, ['title'], ['description'], ['params'], $unresolved);
        // item_id is a string column; only an article's (a positive integer) can be named by the packed key.
        // #__fields_values has no key: a pair stored in more than one row is a multiple-value field, left out.
        foreach ($q('SELECT v.field_id, v.item_id, MIN(v.value) AS value FROM #__fields_values v JOIN #__fields f ON f.id = v.field_id WHERE f.context = '
            . $db->quote('com_content.article') . ' AND f.state = 1 AND f.type IN (' . implode(',', array_map([$db, 'quote'], self::FIELD_TYPES)) . ')'
            . ' GROUP BY v.field_id, v.item_id HAVING COUNT(*) = 1 ORDER BY v.field_id, v.item_id') as $r) {
            if (!ctype_digit((string) $r['item_id']) || (int) $r['item_id'] < 1 || (int) $r['item_id'] >= \FieldValueKey::SPAN) continue;
            $id = \FieldValueKey::encode((int) $r['field_id'], (int) $r['item_id']);
            $out[] = self::row('fieldValue', $id, ['fieldId' => (int) $r['field_id'], 'itemId' => (int) $r['item_id']], $r, [], [], ['value'], $unresolved);
        }
        // `home` is '0', '1' or a language tag (a home per language); either kind of home is in use.
        foreach ($q('SELECT id, params FROM #__template_styles WHERE client_id = 0 AND (home <> ' . $db->quote('0')
            . ' OR id IN (SELECT template_style_id FROM #__menu WHERE client_id = 0 AND published = 1 AND template_style_id > 0)) ORDER BY id') as $r)
            $out[] = self::row('templateStyle', (int) $r['id'], ['id' => (int) $r['id']], $r, [], [], ['params'], $unresolved);
        return $out;
    }

    /**
     * The rendered home page and the site's published menu pages, at most `$limit`, fetched over
     * loopback with the site's own Host and `X-Tracy-Preview: pick` (RenderStamps: the page carries
     * its stamps and skips the page cache). A page that fails, times out or redirects (a 3xx is not
     * followed) is left out; none at all is `[]`, and the derive then records `calibrated: false`.
     * All pages share one deadline, {@see PAGES_SECONDS}: past it, what loaded is what calibrates.
     *
     * Each page is asked over plain http to 127.0.0.1 first, then (an https site that fails or
     * redirects there) over https with its own name resolved to 127.0.0.1 — curl only, never through
     * a proxy; see LoopbackRoute.
     *
     * @return list<string>
     */
    public static function pages(DatabaseInterface $db, int $limit = 40): array
    {
        $paths = [''];
        $ids = $db->setQuery('SELECT id FROM #__menu WHERE published = 1 AND client_id = 0 AND level > 0 AND type = ' . $db->quote('component')
            . ' AND menutype <> ' . $db->quote('main') . ' ORDER BY home DESC, lft', 0, max(0, $limit - 1))->loadColumn() ?: [];
        foreach ($ids as $id) $paths[] = 'index.php?Itemid=' . (int) $id;
        return array_values(self::fetch(array_slice($paths, 0, $limit)));
    }

    /**
     * Pages of this site by path relative to its root ('' is the home page), under the rules of
     * {@see pages()}: loopback, no redirect followed, one deadline for all. Also what the render
     * check after a derived apply asks (Engine::afterDerivedApply).
     *
     * @param list<string> $paths
     * @return array<string,string> the pages that loaded, by path
     */
    public static function fetch(array $paths, int $seconds = self::PAGES_SECONDS): array
    {
        require_once JPATH_ADMINISTRATOR . '/components/com_claudecowork/lib/LoopbackRoute.php';
        $deadline = microtime(true) + $seconds;
        $routes = [];
        // Curl only, proxy off, on every route (LoopbackRoute): no curl, no route, nothing calibrated.
        foreach (\LoopbackRoute::routes(Uri::root(), \function_exists('curl_init') && \defined('CURLOPT_RESOLVE'), \LoopbackRoute::tls()) as $route) {
            $options = ['follow_location' => false, 'transport.curl' => \LoopbackRoute::curlOptions($route)];
            try { $routes[] = [$route, HttpFactory::getHttp($options, ['curl'])]; } catch (\Throwable $e) {}
        }
        // Once a route answers, the next pages start there: a site that redirects http does so for every page.
        $first = 0;
        $pages = [];
        foreach ($paths as $page) {
            for ($i = $first; $i < count($routes); $i++) {
                $left = (int) floor($deadline - microtime(true));
                if ($left < 1) return $pages;
                [$route, $http] = $routes[$i];
                try {
                    $response = $http->get($route['url'] . $page, ['Host' => $route['host'], 'X-Tracy-Preview' => 'pick'], min(10, $left));
                    $code = (int) $response->code; $body = (string) $response->body;
                } catch (\Throwable $e) {
                    $code = 0; $body = '';
                }
                $outcome = \LoopbackRoute::outcome($code);
                if ($outcome === 'next') continue;
                $first = $i;
                if ($outcome === 'page' && $body !== '') $pages[(string) $page] = $body;
                break;
            }
        }
        return $pages;
    }

    /**
     * A page a module shows on, from `#__modules_menu`: its first published site menu item ('index.php?Itemid=<id>');
     * the home page when it shows on every page (menuid 0), or on every page but some (negative ids) that
     * do not include a home; null when it shows nowhere known — the render check then says nothing.
     */
    public static function modulePage(DatabaseInterface $db, int $moduleId): ?string
    {
        $menus = array_map('intval', $db->setQuery('SELECT menuid FROM #__modules_menu WHERE moduleid = ' . $moduleId)->loadColumn() ?: []);
        if (!$menus) return null;
        if (in_array(0, $menus, true)) return '';
        $shown = array_filter($menus, static fn(int $m) => $m > 0);
        if ($shown) {
            $id = $db->setQuery('SELECT id FROM #__menu WHERE published = 1 AND client_id = 0 AND id IN (' . implode(',', $shown) . ') ORDER BY lft', 0, 1)->loadResult();
            return $id ? 'index.php?Itemid=' . (int) $id : null;
        }
        $homes = array_map('intval', $db->setQuery('SELECT id FROM #__menu WHERE home = 1 AND client_id = 0 AND published = 1')->loadColumn() ?: []);
        return array_intersect($homes, array_map(static fn(int $m) => -$m, $menus)) ? null : '';
    }

    /**
     * Empty T4's optimize cache (`media/t4/optimize`), which keeps serving combined CSS/JS — and a T4
     * page cache keyed on them — after a write; the writer's own purge covers Joomla's cache groups.
     * The same guards as the Tracy skill's clear-cache.sh: only that folder, only when it is a real
     * directory, never through a symlinked path. Best-effort: a file that will not go is left.
     */
    public static function clearOptimize(string $root): void
    {
        $cache = rtrim($root, '/') . '/media/t4/optimize';
        if (is_link($cache) || !is_dir($cache)) return;
        $real = realpath($cache);
        if ($real === false || $real !== (realpath($root) ?: '') . '/media/t4/optimize') return;
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($real, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            if ($item->isDir() && !$item->isLink()) @rmdir($item->getPathname());
            else @unlink($item->getPathname());
        }
    }

    /** One row in DerivedMap's shape; a value past LeafCodec's ceiling is named, not scanned. */
    private static function row(string $kind, int $id, array $identity, array $r, array $core, array $html, array $nested, array &$unresolved): array
    {
        $out = ['kind' => $kind, 'id' => $id, 'identity' => $identity, 'core' => [], 'html' => [], 'nested' => []];
        foreach (['core' => $core, 'html' => $html, 'nested' => $nested] as $class => $columns) foreach ($columns as $column) {
            $value = (string) ($r[$column] ?? '');
            if (strlen($value) > \LeafCodec::MAX_BYTES) { $unresolved[] = $kind . ' ' . $id . '.' . $column . ' is ' . strlen($value) . ' bytes, not scanned'; continue; }
            $out[$class][$column] = $value;
        }
        return $out;
    }
}
