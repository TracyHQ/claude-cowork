<?php

/**
 * @package     Claude Cowork for Joomla
 * @copyright   (C) 2026 Tracy
 * @license     GPL-2.0-or-later
 */

namespace Tracy\Plugin\System\ClaudeCoworkApi\Extension;

use Joomla\CMS\Factory;
use Joomla\CMS\Router\Route;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * Which category or tag each link points to — the resolver `RenderStamps::stampLinks` is handed.
 *
 * Same method as ArticleLinks, for the same reasons (never parse a route mid-render): candidates
 * from the database by the id or alias the link's last segment names, confirmed only when exactly
 * one candidate's own route — built the way Joomla's category and tag views build it — is that
 * link.
 *
 * ## A category a menu item shows is left alone
 *
 * When a menu item opens a category directly (`/en/news` is menu item 603 showing category 259),
 * a link to that address is the MENU ITEM's link, and what Joomla prints beside it — in a
 * breadcrumb, in a menu — is the menu item's title, not the category's. Stamping it `category:259`
 * would send an edit of "News" to the category. Such categories (and tags) are skipped; the
 * category pages below them, which no menu item names, are the ones stamped.
 */
final class TaxonomyLinks
{
    /** Never more candidate rows than this per kind and fragment, whatever the aliases match. */
    private const MAX_ROWS = 500;

    /**
     * @param string[] $hrefs
     * @return array<string, string> href => `category:<id>:<alias>` / `tag:<id>:<alias>`
     */
    public static function resolve(array $hrefs): array
    {
        $host = \Joomla\CMS\Uri\Uri::getInstance()->getHost();
        $found = [];
        foreach ([['category', 'com_content', 'category'], ['tag', 'com_tags', 'tag']] as [$kind, $option, $view]) {
            $hints = [];
            foreach ($hrefs as $href) {
                if (isset($found[$href])) {
                    continue;
                }
                $hint = \RenderStamps::viewHints($href, $host, $option, $view);
                if ($hint !== null) {
                    $hints[$href] = $hint;
                }
            }
            if ($hints !== []) {
                $found += self::resolveKind($kind, $option, $view, $hints);
            }
        }
        return $found;
    }

    /** @param array<string, array{nonSef:?int, path:?string, id:?int, alias:?string}> $hints */
    private static function resolveKind(string $kind, string $option, string $view, array $hints): array
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $ids = [];
        $aliases = [];
        foreach ($hints as $hint) {
            if ($hint['id'] !== null) {
                $ids[$hint['id']] = true;
            }
            if ($hint['alias'] !== null) {
                $aliases[$hint['alias']] = true;
            }
        }
        $where = [];
        if ($ids !== []) {
            $where[] = $db->quoteName('id') . ' IN (' . implode(',', array_map('intval', array_keys($ids))) . ')';
        }
        if ($aliases !== []) {
            $where[] = $db->quoteName('alias') . ' IN (' . implode(',', array_map([$db, 'quote'], array_keys($aliases))) . ')';
        }
        if ($where === []) {
            return [];
        }
        $query = $db->getQuery(true)
            ->select($db->quoteName(['id', 'alias', 'language']))
            ->from($db->quoteName($kind === 'category' ? '#__categories' : '#__tags'))
            ->where('(' . implode(' OR ', $where) . ')')
            ->where($db->quoteName('published') . ' = 1')
            ->where($db->quoteName('id') . ' > 1')
            ->setLimit(self::MAX_ROWS);
        if ($kind === 'category') {
            $query->where($db->quoteName('extension') . ' = ' . $db->quote('com_content'));
        }
        $rows = $db->setQuery($query)->loadObjectList() ?: [];
        $shown = self::shownByMenuItem($db, $option, $view);

        $byId = [];
        $byAlias = [];
        foreach ($rows as $row) {
            if (isset($shown[(int) $row->id])) {
                continue;
            }
            $byId[(int) $row->id] = $row;
            $byAlias[(string) $row->alias][] = $row;
        }
        $routes = [];
        $route = static function ($row) use (&$routes, $kind): string {
            $id = (int) $row->id;
            if (!isset($routes[$id])) {
                $raw = $kind === 'category'
                    ? \Joomla\Component\Content\Site\Helper\RouteHelper::getCategoryRoute($id, (string) $row->language)
                    : \Joomla\Component\Tags\Site\Helper\RouteHelper::getComponentTagRoute($id . ':' . $row->alias, (string) $row->language);
                $routes[$id] = \RenderStamps::normaliseLink(Route::_($raw, false));
            }
            return $routes[$id];
        };

        $found = [];
        foreach ($hints as $href => $hint) {
            if ($hint['nonSef'] !== null) {
                if (isset($byId[$hint['nonSef']])) {
                    $found[$href] = \RenderStamps::owner($kind, $hint['nonSef'], (string) $byId[$hint['nonSef']]->alias);
                }
                continue;
            }
            $candidates = $hint['id'] !== null ? (isset($byId[$hint['id']]) ? [$byId[$hint['id']]] : []) : ($byAlias[$hint['alias']] ?? []);
            $confirmed = array_values(array_filter($candidates, static fn($row) => $route($row) === $hint['path']));
            if (\count($confirmed) === 1) {
                $found[$href] = \RenderStamps::owner($kind, (int) $confirmed[0]->id, (string) $confirmed[0]->alias);
            }
        }
        return array_filter($found, 'is_string');
    }

    /** Ids of the categories (or tags) a published site menu item opens directly. */
    private static function shownByMenuItem(DatabaseInterface $db, string $option, string $view): array
    {
        $query = $db->getQuery(true)
            ->select($db->quoteName('link'))
            ->from($db->quoteName('#__menu'))
            ->where($db->quoteName('client_id') . ' = 0')
            ->where($db->quoteName('published') . ' = 1')
            ->where($db->quoteName('link') . ' LIKE ' . $db->quote('index.php?option=' . $option . '&view=' . $view . '%'));
        $shown = [];
        foreach ($db->setQuery($query)->loadColumn() ?: [] as $link) {
            parse_str((string) parse_url((string) $link, PHP_URL_QUERY), $params);
            if (($params['view'] ?? null) !== $view) {
                continue;
            }
            foreach ((array) ($params['id'] ?? []) as $id) {
                if (is_string($id) && preg_match('/^(\d+)/', $id, $m)) {
                    $shown[(int) $m[1]] = true;
                }
            }
        }
        return $shown;
    }
}
