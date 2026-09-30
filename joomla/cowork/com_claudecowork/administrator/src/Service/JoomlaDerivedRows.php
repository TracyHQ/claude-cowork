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
 * fields across the whole kind. A derived map is made from these rows (and kept between requests
 * only while they are unchanged: fingerprint(), DerivedCache), so its entities always resolve by
 * their own id (`sourceId`); `identity` is recorded for the reader of the map, never matched. `{id}` for every kind with an id column; `{fieldId, itemId}` for a custom
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

    /** Articles read per query: each batch is rows before the next is fetched. */
    private const BATCH = 200;
    /** Values up to this many bytes are tested in SQL for holding no word at all (wordValue); longer ones are always read. */
    private const SHORT = 64;
    /**
     * The table that keeps the built map (row 1) and the reader's media hashes (row 2) between requests
     * (script.php creates it). Its own table, never a row of #__claudecowork_content_contract: the
     * content reader snapshots that one whole.
     */
    private const CACHE_TABLE = '#__claudecowork_derived_cache';
    /** @var array<string,\DerivedCache> this request's kept maps (siteCache) */
    private static array $siteCaches = [];

    /**
     * Every row at once: batches() gathered. For a test or a small site; a derive and a map build
     * take the batches, so a large site is never held whole.
     *
     * @param list<string> $unresolved gains one line per value too large to scan
     * @return list<array{kind:string,id:int,identity:array,core:array<string,string>,html:array<string,string>,nested:array<string,string>}>
     */
    public static function rows(DatabaseInterface $db, array &$unresolved = []): array
    {
        $out = [];
        $batches = self::batches($db);
        foreach ($batches as $batch) foreach ($batch as $row) $out[] = $row;
        array_push($unresolved, ...$batches->getReturn());
        return $out;
    }

    /**
     * The rows in DerivedMap's shape, one batch at a time, in the order rows() lists them: articles
     * BATCH at a time by id (the one table that grows with the site), then menu items, modules,
     * categories, custom field values and template styles, each database result let go before the
     * next query. A PHP row costs about 1.2 KB before its values: a site of thousands of articles
     * held whole, then built into a map, went past a 128 MB limit on WordPress (30/09/2026).
     *
     * @return \Generator<int,list<array>,mixed,list<string>> returns the `unresolved` lines (values too large to scan)
     */
    public static function batches(DatabaseInterface $db): \Generator
    {
        self::raiseMemory();
        $unresolved = [];
        $q = static fn(string $sql): array => $db->setQuery($sql)->loadAssocList() ?: [];
        $n = static fn(string $column): string => $db->quoteName($column);
        for ($last = 0; ;) {
            $batch = $q('SELECT id, title, introtext, ' . $n('fulltext') . ', attribs, images, urls FROM #__content WHERE ' . self::articleScope($db)
                . ' AND id > ' . $last . ' ORDER BY id LIMIT ' . self::BATCH);
            if (!$batch) break;
            $out = [];
            foreach ($batch as $r) {
                $last = (int) $r['id'];
                $out[] = self::row('article', (int) $r['id'], ['id' => (int) $r['id']], $r, ['title'], ['introtext', 'fulltext'], ['attribs', 'images', 'urls'], $unresolved);
            }
            unset($batch);
            yield $out;
        }
        $out = [];
        // `level > 0` leaves out the tree's root; `main` is the administrator's own menu type.
        foreach ($q('SELECT id, title, params FROM #__menu WHERE ' . self::menuScope($db) . ' ORDER BY id') as $r)
            $out[] = self::row('menuItem', (int) $r['id'], ['id' => (int) $r['id']], $r, ['title'], [], ['params'], $unresolved);
        foreach ($q('SELECT id, title, ' . $n('module') . ', content, params FROM #__modules WHERE published = 1 AND client_id = 0 ORDER BY id') as $r)
            $out[] = self::row('module', (int) $r['id'], ['id' => (int) $r['id']], $r, ['title'], $r['module'] === 'mod_custom' ? ['content'] : [], ['params'], $unresolved);
        foreach ($q('SELECT id, title, description, params FROM #__categories WHERE published = 1 AND extension = ' . $db->quote('com_content') . ' ORDER BY id') as $r)
            $out[] = self::row('category', (int) $r['id'], ['id' => (int) $r['id']], $r, ['title'], ['description'], ['params'], $unresolved);
        yield $out;
        // item_id is a string column; only an article's (a positive integer) can be named by the packed key.
        // #__fields_values has no key: a pair stored in more than one row is a multiple-value field, left out.
        // A value that cannot hold a word (a number, an id) stays in the database.
        $out = [];
        foreach ($q('SELECT v.field_id, v.item_id, MIN(v.value) AS value FROM #__fields_values v JOIN #__fields f ON f.id = v.field_id WHERE ' . self::fieldScope($db)
            . ' GROUP BY v.field_id, v.item_id HAVING COUNT(*) = 1 AND ' . self::wordValue('MIN(v.value)') . ' ORDER BY v.field_id, v.item_id') as $r) {
            if (!ctype_digit((string) $r['item_id']) || (int) $r['item_id'] < 1 || (int) $r['item_id'] >= \FieldValueKey::SPAN) continue;
            $id = \FieldValueKey::encode((int) $r['field_id'], (int) $r['item_id']);
            $out[] = self::row('fieldValue', $id, ['fieldId' => (int) $r['field_id'], 'itemId' => (int) $r['item_id']], $r, [], [], ['value'], $unresolved);
        }
        // `home` is '0', '1' or a language tag (a home per language); either kind of home is in use.
        foreach ($q('SELECT id, params FROM #__template_styles WHERE ' . self::styleScope($db) . ' ORDER BY id') as $r)
            $out[] = self::row('templateStyle', (int) $r['id'], ['id' => (int) $r['id']], $r, [], [], ['params'], $unresolved);
        yield $out;
        return $unresolved;
    }

    /**
     * What the rows are read from, in one query: per table a count and a checksum of every column
     * rows() reads, over the same scope. Any write to them changes it; DerivedCache keys a built map
     * by it. An article whose publish window opens or closes enters or leaves the scope, so it
     * changes too. The cache row itself is outside every scope.
     */
    public static function fingerprint(DatabaseInterface $db): string
    {
        $n = static fn(string $column): string => $db->quoteName($column);
        $sum = static fn(string $columns): string => "CONCAT_WS(':', COUNT(*), COALESCE(BIT_XOR(CRC32(CONCAT_WS('|', " . $columns . '))), 0))';
        $row = $db->setQuery('SELECT'
            . ' (SELECT ' . $sum('id, title, introtext, ' . $n('fulltext') . ', attribs, images, urls, modified') . ' FROM #__content WHERE ' . self::articleScope($db) . ') AS articles,'
            . ' (SELECT ' . $sum('id, title, params') . ' FROM #__menu WHERE ' . self::menuScope($db) . ') AS menu,'
            . ' (SELECT ' . $sum('id, title, ' . $n('module') . ', content, params') . ' FROM #__modules WHERE published = 1 AND client_id = 0) AS modules,'
            . ' (SELECT ' . $sum('id, title, description, params') . ' FROM #__categories WHERE published = 1 AND extension = ' . $db->quote('com_content') . ') AS categories,'
            . ' (SELECT ' . $sum('v.field_id, v.item_id, v.value') . ' FROM #__fields_values v JOIN #__fields f ON f.id = v.field_id WHERE ' . self::fieldScope($db) . ') AS fieldValues,'
            . ' (SELECT ' . $sum('id, home, params') . ' FROM #__template_styles WHERE ' . self::styleScope($db) . ') AS styles')->loadAssoc();
        return hash('sha256', (string) json_encode($row));
    }

    /** The derived map kept between requests in CACHE_TABLE, keyed by fingerprint(). */
    public static function cache(DatabaseInterface $db, string $version): \DerivedCache
    {
        self::loadCache();
        return new \DerivedCache(static fn(): string => self::fingerprint($db), static function () use ($db): ?string {
            self::raiseMemory(); // unpacking a kept map of thousands of slots is tens of MB too
            $stored = $db->setQuery('SELECT entry FROM ' . self::CACHE_TABLE . ' WHERE id = 1')->loadResult();
            return is_string($stored) ? $stored : null;
        }, static function (?string $value) use ($db): void {
            // REPLACE: one statement, the same in MySQL and in the tests' SQLite.
            $db->setQuery($value === null ? 'DELETE FROM ' . self::CACHE_TABLE . ' WHERE id = 1'
                : 'REPLACE INTO ' . self::CACHE_TABLE . ' (id, entry) VALUES (1, ' . $db->quote($value) . ')')->execute();
        }, $version);
    }

    /** The site's kept map, one object per request and connection: the contract and the content reader share it. */
    public static function siteCache(DatabaseInterface $db, string $version): \DerivedCache
    {
        return self::$siteCaches[spl_object_id($db) . ':' . $version] ??= self::cache($db, $version);
    }

    /**
     * Around a read's snapshot transaction: a map built inside it is stored only after it ends, so a
     * read never writes (no lock inside a read that promises none, no deadlock between two misses).
     */
    public static function holdSiteCaches(): void
    {
        foreach (self::$siteCaches as $cache) $cache->hold();
    }

    public static function flushSiteCaches(): void
    {
        foreach (self::$siteCaches as $cache) $cache->flush();
    }

    /**
     * The map a derived binding reads (no pages: the derive's `keep` chose the nested leaves), built
     * batch by batch and kept between requests while fingerprint() stands. What EngineFactory hands
     * QuickstartContract::derived() to build lazily.
     *
     * @return array{manifest:array,map:array}
     */
    public static function built(DatabaseInterface $db, array $binding, \DerivedCache $cache): array
    {
        $label = substr((string) $binding['contract'], strlen('derived/'));
        $algorithm = (int) ($binding['algorithm'] ?? \DerivedMap::ALGORITHM);
        $keep = is_array($binding['keep'] ?? null) ? array_values(array_map('strval', $binding['keep'])) : null;
        $basis = ['contract' => (string) $binding['contract'], 'algorithm' => $algorithm, 'keep' => $keep === null ? null : hash('sha256', implode("\n", $keep))];
        return $cache->get($basis, static fn(): array => ['built' => \DerivedMap::buildBatches(self::batches($db), null, $label, $algorithm, $keep)])['built'];
    }

    /**
     * The media hashes the content reader keeps between reads (JoomlaContentReader::hashes), row 2 of
     * CACHE_TABLE: path => [signature, sha256]. Empty when the table is not there yet.
     *
     * @return array<string,array{0:string,1:string}>
     */
    public static function keptHashes(DatabaseInterface $db): array
    {
        try {
            $raw = $db->setQuery('SELECT entry FROM ' . self::CACHE_TABLE . ' WHERE id = 2')->loadResult();
        } catch (\Throwable $e) {
            return [];
        }
        $kept = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($kept) ? $kept : [];
    }

    /** Keep the reader's media hashes (after its snapshot: never a write inside a read). A store that fails is only slower. */
    public static function keepHashes(DatabaseInterface $db, array $hashes): void
    {
        try {
            $db->setQuery('REPLACE INTO ' . self::CACHE_TABLE . ' (id, entry) VALUES (2, ' . $db->quote((string) json_encode($hashes, JSON_UNESCAPED_SLASHES)) . ')')->execute();
        } catch (\Throwable $e) {
        }
    }

    /** Drop the kept map: after a derived apply, the next read builds from the rows as they are now. */
    public static function forget(DatabaseInterface $db): void
    {
        $db->setQuery('DELETE FROM ' . self::CACHE_TABLE . ' WHERE id = 1')->execute();
    }

    /** At least 256 MB for a derive or a map build, as wp-admin gives its heavy screens; never lowers a limit, never touches -1. */
    public static function raiseMemory(): void
    {
        self::loadCache();
        \DerivedCache::raiseMemory();
    }

    private static function loadCache(): void
    {
        if (!class_exists('DerivedCache', false)) require_once __DIR__ . '/../../lib/DerivedCache.php';
    }

    /** Published and inside its window now, as the site serves it (Joomla stores both dates in UTC). */
    private static function articleScope(DatabaseInterface $db): string
    {
        $now = $db->quote(Factory::getDate()->toSql());
        return 'state = 1 AND (publish_up IS NULL OR publish_up <= ' . $now . ') AND (publish_down IS NULL OR publish_down > ' . $now . ')';
    }

    private static function menuScope(DatabaseInterface $db): string
    {
        return 'published = 1 AND client_id = 0 AND level > 0 AND menutype <> ' . $db->quote('main');
    }

    private static function fieldScope(DatabaseInterface $db): string
    {
        return 'f.context = ' . $db->quote('com_content.article') . ' AND f.state = 1 AND f.type IN (' . implode(',', array_map([$db, 'quote'], self::FIELD_TYPES)) . ')';
    }

    private static function styleScope(DatabaseInterface $db): string
    {
        return 'client_id = 0 AND (home <> ' . $db->quote('0') . ' OR id IN (SELECT template_style_id FROM #__menu WHERE client_id = 0 AND published = 1 AND template_style_id > 0))';
    }

    /**
     * SQL: a value that may hold a word or a link — the short values LeafCodec::typeOf finds nothing
     * in are left out (empty, only digits, spaces and `.,:;+-`, a bare hex id of 8 or more, one
     * character that is not `/`). A text field's `yes` is a word a visitor reads, so it stays.
     * ASCII classes only, so MySQL 5.7's byte-wise REGEXP and 8.0's ICU one agree.
     */
    private static function wordValue(string $value): string
    {
        return '(LENGTH(' . $value . ') > ' . self::SHORT . ' OR NOT (' . $value . " REGEXP '^[-0-9[:space:].,:;+]*$' OR " . $value
            . " REGEXP '^[0-9a-fA-F]{8,}$' OR (CHAR_LENGTH(" . $value . ') = 1 AND ' . $value . " <> '/')))";
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
