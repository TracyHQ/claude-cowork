<?php

/**
 * @package     Claude Cowork for Joomla
 * @copyright   (C) 2026 Tracy
 * @license     GPL-2.0-or-later
 */

namespace Tracy\Plugin\System\ClaudeCoworkApi\Extension;

use Joomla\CMS\Factory;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;
use Joomla\Component\Content\Site\Helper\RouteHelper;
use Joomla\Database\DatabaseInterface;

\defined('_JEXEC') or die;

/**
 * Which article each link in a module's output points to — the resolver `RenderStamps::stampListItems`
 * is handed.
 *
 * ## Why not ask the router to parse the link
 *
 * Parsing is what the router does for the request itself, and it is not side-effect free: rules
 * attached by other plugins (the language filter, redirect and SEF extensions) may set the active
 * language, the active menu item, or redirect outright. Doing that in the middle of rendering a
 * module is how a stamp would take a page down. BUILDING a route has none of that — it is what
 * every module already does for each of its items.
 *
 * ## So: candidates from the database, confirmed by building their route
 *
 * The last path segment names the candidates (`12-alias` or `alias`); each candidate's own route is
 * built exactly the way the article modules build it (`RouteHelper::getArticleRoute` + `Route::_`),
 * and a link is tied to an article only when exactly one candidate's route is that link. Two
 * articles with the same alias in different categories are therefore told apart by their paths,
 * and a link the router would have built differently (a layout that routes its own way) is simply
 * left unstamped — never guessed. A raw `index.php?option=com_content&view=article&id=N` link names
 * its article directly and only has to exist.
 */
final class ArticleLinks
{
    /** Never more candidate rows than this per module, whatever the aliases match. */
    private const MAX_ROWS = 500;

    /**
     * @param string[] $hrefs
     * @return array<string, array{0:int,1:?string}>
     */
    public static function resolve(array $hrefs): array
    {
        $host = Uri::getInstance()->getHost();
        $hints = [];
        $ids = [];
        $aliases = [];
        foreach ($hrefs as $href) {
            $hint = \RenderStamps::hrefHints($href, $host);
            if ($hint === null) {
                continue;
            }
            $hints[$href] = $hint;
            if ($hint['id'] !== null) {
                $ids[$hint['id']] = true;
            }
            if ($hint['alias'] !== null) {
                $aliases[$hint['alias']] = true;
            }
        }
        if ($hints === []) {
            return [];
        }

        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $where = [];
        if ($ids !== []) {
            $where[] = $db->quoteName('id') . ' IN (' . implode(',', array_map('intval', array_keys($ids))) . ')';
        }
        if ($aliases !== []) {
            $where[] = $db->quoteName('alias') . ' IN (' . implode(',', array_map([$db, 'quote'], array_keys($aliases))) . ')';
        }
        $query = $db->getQuery(true)
            ->select($db->quoteName(['id', 'alias', 'catid', 'language']))
            ->from($db->quoteName('#__content'))
            ->where('(' . implode(' OR ', $where) . ')')
            ->setLimit(self::MAX_ROWS);
        $db->setQuery($query);
        $rows = $db->loadObjectList() ?: [];

        $byId = [];
        $byAlias = [];
        foreach ($rows as $row) {
            $byId[(int) $row->id] = $row;
            $byAlias[(string) $row->alias][] = $row;
        }

        $routes = [];
        $route = static function ($row) use (&$routes): string {
            $id = (int) $row->id;
            if (!isset($routes[$id])) {
                $routes[$id] = \RenderStamps::normaliseLink(
                    Route::_(RouteHelper::getArticleRoute($id . ':' . $row->alias, (int) $row->catid, (string) $row->language), false)
                );
            }
            return $routes[$id];
        };

        $found = [];
        foreach ($hints as $href => $hint) {
            if ($hint['nonSef'] !== null) {
                if (isset($byId[$hint['nonSef']])) {
                    $found[$href] = [$hint['nonSef'], (string) $byId[$hint['nonSef']]->alias];
                }
                continue;
            }
            if ($hint['id'] !== null) {
                $candidates = isset($byId[$hint['id']]) ? [$byId[$hint['id']]] : [];
            } else {
                $candidates = $byAlias[$hint['alias']] ?? [];
            }
            $confirmed = [];
            foreach ($candidates as $row) {
                if ($route($row) === $hint['path']) {
                    $confirmed[] = $row;
                }
            }
            if (\count($confirmed) === 1) {
                $found[$href] = [(int) $confirmed[0]->id, (string) $confirmed[0]->alias];
            }
        }
        return $found;
    }
}
