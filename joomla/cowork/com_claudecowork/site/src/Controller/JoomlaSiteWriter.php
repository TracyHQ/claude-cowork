<?php

/**
 * @package     Claude Cowork for Joomla
 * @copyright   (C) 2026 Tracy
 * @license     GPL-2.0-or-later
 */

namespace Tracy\Component\ClaudeCowork\Site\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\Cache\Exception\CacheExceptionInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Router\Route;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

/**
 * The Joomla half of {@see \SiteWriter} — how an Apply's content edits actually land, and how
 * they read back so they can be undone.
 *
 * Writes go straight through the database driver rather than Joomla's Table classes, on purpose:
 * the three things an Apply touches (an article's fields, a module's content, a template style's
 * params) are row edits whose before-and-after is the row itself, and a raw read/write is exactly
 * what the engine's undo log needs to capture and restore. The driver replaces `#__` with the
 * site's real prefix and quotes every value, so no caller-supplied string reaches SQL unescaped.
 *
 * Two guards make this safe to run on a customer's live tables:
 *  - Only the columns whitelisted per kind are ever written. A field the caller is not meant to
 *    set (an author, an ACL asset, a checked-out lock) is dropped, not written.
 *  - `read` returns the whole row, but `write` only applies the whitelist — so restoring a
 *    before-state on revert touches exactly the columns a forward write could have changed.
 *
 * Known bound, to settle when this is exercised on a real site: inserting a brand-new article
 * (id 0) writes `#__content` without the `#__assets` row Joomla's model would create, so a
 * from-scratch article would have no ACL. Reskin edits existing rows, which is the path this is
 * built and tested for first; a create path grows an assets row here when a Proposal needs it.
 */
final class JoomlaSiteWriter implements \SiteWriter
{
    /**
     * Each kind: the table it lives in and the only columns an Apply may set on it.
     *
     * @var array<string,array{table:string,columns:string[]}>
     */
    private const MAP = [
        'article' => [
            'table'   => '#__content',
            'columns' => ['title', 'alias', 'introtext', 'fulltext', 'state', 'catid', 'images',
                'urls', 'attribs', 'metadata', 'metakey', 'metadesc', 'language', 'featured', 'ordering',
                // Who may read it, and the window it is visible in. Both are decisions an editor
                // makes about an article, and both were readable in the row already.
                'access', 'publish_up', 'publish_down'],
        ],
        'module' => [
            'table'   => '#__modules',
            'columns' => ['title', 'note', 'content', 'position', 'module', 'access', 'showtitle',
                'params', 'published', 'language', 'ordering'],
        ],
        'templateStyle' => [
            'table'   => '#__template_styles',
            'columns' => ['title', 'params', 'home'],
        ],
    ];

    private DatabaseInterface $db;

    public function __construct(DatabaseInterface $db)
    {
        $this->db = $db;
    }

    public function read(string $kind, int $id): ?array
    {
        $table = $this->tableFor($kind);
        if ($id <= 0) {
            return null;
        }
        $query = $this->db->getQuery(true)
            ->select('*')
            ->from($this->db->quoteName($table))
            ->where($this->db->quoteName('id') . ' = :id')
            ->bind(':id', $id, \Joomla\Database\ParameterType::INTEGER);
        $row = $this->db->setQuery($query)->loadAssoc();
        return $row === null ? null : $row;
    }

    public function write(string $kind, int $id, array $fields): int
    {
        // Tags are not a column of #__content, so they travel beside the row rather than in it.
        // Written only when the caller mentioned them: a file silent about tags must not clear
        // an article's tags.
        $tags = array_key_exists('tags', $fields) && is_array($fields['tags']) ? $fields['tags'] : null;
        unset($fields['tags']);
        [$table, $allowed] = [$this->tableFor($kind), self::MAP[$kind]['columns']];

        $object = new \stdClass();
        foreach ($fields as $column => $value) {
            if (\in_array($column, $allowed, true)) {
                $object->{$column} = $value;
            }
        }
        if (get_object_vars($object) === []) {
            throw new \RuntimeException("no writable column for kind {$kind}");
        }

        if ($id <= 0) {
            // A brand-new article stamps its own timestamps. The whitelist drops authorship
            // fields on purpose, and #__content's `created` and `modified` are NOT NULL with no
            // database default, so an insert that sets neither is refused by the database.
            if ($kind === 'article') {
                $now = Factory::getDate()->toSql();
                $object->created  = $now;
                $object->modified = $now;
            }
            $this->db->insertObject($table, $object, 'id');
            $newId = (int) $object->id;
            if ($tags !== null) {
                $this->setTags($newId, $tags);
            }
            return $newId;
        }

        $object->id = $id;
        $this->db->updateObject($table, $object, 'id');
        if ($tags !== null) {
            $this->setTags($id, $tags);
        }
        return $id;
    }

    /**
     * Replace an article's tags with the ones named, by title.
     *
     * Titles rather than ids for the same reason WordPress uses slugs: a person editing a file
     * writes what they read on the site. A title that matches no existing tag is DROPPED, not
     * created — inventing taxonomy from a typo is not something a content edit should do.
     *
     * @param string[] $titles
     */
    private function setTags(int $articleId, array $titles): void
    {
        $wanted = [];
        foreach ($titles as $title) {
            $title = trim((string) $title);
            if ($title === '') {
                continue;
            }
            $query = $this->db->getQuery(true)
                ->select($this->db->quoteName('id'))
                ->from($this->db->quoteName('#__tags'))
                ->where($this->db->quoteName('title') . ' = :title')
                ->bind(':title', $title, ParameterType::STRING);
            $found = $this->db->setQuery($query)->loadResult();
            if ($found !== null) {
                $wanted[(int) $found] = (int) $found;
            }
        }

        $delete = $this->db->getQuery(true)
            ->delete($this->db->quoteName('#__contentitem_tag_map'))
            ->where($this->db->quoteName('type_alias') . ' = ' . $this->db->quote('com_content.article'))
            ->where($this->db->quoteName('content_item_id') . ' = :id')
            ->bind(':id', $articleId, ParameterType::INTEGER);
        $this->db->setQuery($delete)->execute();

        foreach ($wanted as $tagId) {
            $row = new \stdClass();
            $row->type_alias = 'com_content.article';
            $row->core_content_id = 0;
            $row->content_item_id = $articleId;
            $row->tag_id = $tagId;
            $row->tag_date = Factory::getDate()->toSql();
            $row->type_id = 1;
            try {
                $this->db->insertObject('#__contentitem_tag_map', $row);
            } catch (\Throwable $e) {
                // One tag that will not attach is not a reason to fail an article's whole save.
            }
        }
    }

    /**
     * The summary columns list() returns per kind — identity and bookkeeping, never body text,
     * so a page's size is bounded by the row count alone. Articles carry their category's title
     * and path (one LEFT JOIN) because the mirror files them under a category folder and a
     * second lookup per row would be N queries on a shared host.
     *
     * @var array<string,string[]>
     */
    private const LIST_COLUMNS = [
        // Everything the mirror's file shows about an article. `images` and `metadata` are JSON
        // columns Joomla stores whole; the caller unpacks them rather than the SQL doing it, so
        // an article with a malformed value still lists instead of failing the page.
        'article'       => ['id', 'title', 'alias', 'catid', 'state', 'language', 'created', 'modified',
            'created_by', 'created_by_alias', 'featured', 'images', 'metadesc', 'metakey',
            'access', 'publish_up', 'publish_down'],
        'module'        => ['id', 'title', 'position', 'module', 'published', 'language'],
        'templateStyle' => ['id', 'title', 'template', 'home'],
    ];

    public function list(string $kind, int $offset, int $limit): array
    {
        $table = $this->tableFor($kind);
        $columns = self::LIST_COLUMNS[$kind];

        $query = $this->db->getQuery(true)
            ->select(array_map(fn (string $c): string => 'a.' . $this->db->quoteName($c), $columns))
            ->from($this->db->quoteName($table, 'a'))
            ->order('a.' . $this->db->quoteName('id') . ' ASC');

        if ($kind === 'article') {
            $query->select([
                $this->db->quoteName('c.title', 'category_title'),
                $this->db->quoteName('c.path', 'category_path'),
            ])->join('LEFT', $this->db->quoteName('#__categories', 'c'), 'c.id = a.catid');
            // The mirror's own checksum — sha256 over introtext, one NUL byte, fulltext — computed
            // by the database so the bodies never leave it. This is what makes a summary page a
            // truthful delta inventory: the caller compares these against the checksums its files
            // already carry and fetches bodies only where they disagree. Byte-compatible with the
            // desk's articleChecksum because the columns are utf8mb4 and sha256 hashes bytes.
            // `fulltext` is a reserved word — unquoted it is a syntax error, measured on MariaDB.
            $fulltext = 'a.' . $this->db->quoteName('fulltext');
            $query->select(
                "SHA2(CONCAT(COALESCE(a.introtext, ''), CHAR(0), COALESCE({$fulltext}, '')), 256) AS "
                . $this->db->quoteName('checksum')
            );
        }

        $rows = $this->db->setQuery($query, $offset, $limit)->loadAssocList() ?? [];

        // Where each article actually lives on the web. Asked of Joomla's own router rather than
        // assembled from the alias: the answer depends on SEF settings, on which menu item claims
        // the category (the Itemid), and on whatever routing plugin the site runs. A caller that
        // spells the URL itself is right on a default install and wrong on a real one.
        if ($kind === 'article') {
            foreach ($rows as $index => $row) {
                $rows[$index]['url'] = $this->articleUrl((int) $row['id'], (int) $row['catid'], (string) ($row['language'] ?? '*'));
                $rows[$index]['has_menu_item'] = $this->categoryHasMenuItem((int) $row['catid']);
                $rows[$index]['author'] = $this->describeAuthor(
                    (int) ($row['created_by'] ?? 0),
                    (string) ($row['created_by_alias'] ?? '')
                );
                // The two pictures an article carries: the one on a list page and the one on the
                // article itself. Unpacked here because `images` is a JSON blob, and a file that
                // shows a blob is a file nobody edits.
                $rows[$index]['intro_image'] = $this->imageFrom($row['images'] ?? '', 'image_intro');
                $rows[$index]['full_image'] = $this->imageFrom($row['images'] ?? '', 'image_fulltext');
                $rows[$index]['tags'] = $this->tagsFor((int) $row['id']);
                $rows[$index]['access_name'] = $this->accessName((int) ($row['access'] ?? 0));
            }
        }
        return $rows;
    }

    public function delete(string $kind, int $id): void
    {
        $table = $this->tableFor($kind);
        if ($id <= 0) {
            return;
        }
        $query = $this->db->getQuery(true)
            ->delete($this->db->quoteName($table))
            ->where($this->db->quoteName('id') . ' = :id')
            ->bind(':id', $id, \Joomla\Database\ParameterType::INTEGER);
        $this->db->setQuery($query)->execute();
    }

    /**
     * Drop the caches that would otherwise keep serving the version just replaced. Best-effort by
     * the interface's contract: a cache that will not clear must not fail a write that landed.
     */
    public function purgeCache(): void
    {
        foreach (['com_content', 'com_modules', 'com_templates', '_system', 'page'] as $group) {
            try {
                Factory::getCache($group, '')->clean();
            } catch (CacheExceptionInterface $e) {
                // A cache group that will not clean is not worth failing a completed write.
            } catch (\Throwable $e) {
                // Same intent for anything else the cache layer throws on an older generation.
            }
        }
    }

    /**
     * The public address of one article, through Joomla's router.
     *
     * Never throws: a URL is something a caller displays, and a site whose router is unhappy
     * (a routing plugin mid-upgrade, a language filter with no match) must still be able to
     * export its articles. Null means "could not be worked out", which a caller can say.
     */
    private function articleUrl(int $id, int $catid, string $language): ?string
    {
        try {
            if (!class_exists(\Joomla\Component\Content\Site\Helper\RouteHelper::class)) {
                return null;
            }
            $route = \Joomla\Component\Content\Site\Helper\RouteHelper::getArticleRoute($id, $catid, $language);
            return Route::_($route, false, Route::TLS_IGNORE, true);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Whether a menu item points at this article's category.
     *
     * Worth reporting rather than hiding: an article in a category no menu claims still HAS a URL
     * — Joomla borrows the default menu's Itemid — but that URL changes the day the default menu
     * changes, and its breadcrumbs lead somewhere else. Somebody publishing content deserves to
     * know which of the two they are looking at.
     */
    private function categoryHasMenuItem(int $catid): bool
    {
        if ($catid <= 0) {
            return false;
        }
        try {
            $query = $this->db->getQuery(true)
                ->select('COUNT(*)')
                ->from($this->db->quoteName('#__menu'))
                ->where($this->db->quoteName('published') . ' = 1')
                ->where($this->db->quoteName('link') . ' LIKE :needle')
                ->bind(':needle', $needle, ParameterType::STRING);
            // Both shapes a category menu item can take: the category blog/list views.
            foreach (['%view=category%id=' . $catid, '%view=category%id=' . $catid . '&%'] as $pattern) {
                $needle = $pattern;
                if ((int) $this->db->setQuery($query)->loadResult() > 0) {
                    return true;
                }
            }
            return false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Who wrote it, as a person.
     *
     * `created_by_alias` wins when the article carries one: it is what the site prints under the
     * title, and an article deliberately bylined to somebody else should not read as the account
     * that typed it.
     *
     * @return array<string,mixed>
     */
    private function describeAuthor(int $userId, string $alias): array
    {
        $name = '';
        if ($userId > 0) {
            try {
                $user = \Joomla\CMS\Factory::getContainer()
                    ->get(\Joomla\CMS\User\UserFactoryInterface::class)
                    ->loadUserById($userId);
                $name = $user->name ?? '';
            } catch (\Throwable $e) {
                $name = '';
            }
        }
        return ['id' => $userId, 'name' => $alias !== '' ? $alias : $name, 'account' => $name];
    }

    /**
     * One image out of Joomla's `images` JSON column, with its alt text and caption.
     *
     * Null when the article has none. Never throws: a column somebody edited by hand into
     * invalid JSON is a reason to show no picture, not a reason to fail the export.
     *
     * @return array<string,string>|null
     */
    private function imageFrom(string $json, string $key): ?array
    {
        if ($json === '') {
            return null;
        }
        $data = json_decode($json, true);
        if (!is_array($data) || empty($data[$key])) {
            return null;
        }
        return [
            'src' => (string) $data[$key],
            'alt' => (string) ($data[$key . '_alt'] ?? ''),
            'caption' => (string) ($data[$key . '_caption'] ?? ''),
        ];
    }

    /**
     * An article's tags, by the name a person typed rather than the id the database keeps.
     *
     * Tags are not a column: they live in `#__contentitem_tag_map`, one row per pairing, which is
     * why they were missing from the mirror while `catid` was there from the start.
     *
     * @return string[]
     */
    private function tagsFor(int $articleId): array
    {
        try {
            $query = $this->db->getQuery(true)
                ->select($this->db->quoteName('t.title'))
                ->from($this->db->quoteName('#__contentitem_tag_map', 'm'))
                ->join('INNER', $this->db->quoteName('#__tags', 't'), 't.id = m.tag_id')
                ->where($this->db->quoteName('m.type_alias') . ' = ' . $this->db->quote('com_content.article'))
                ->where($this->db->quoteName('m.content_item_id') . ' = :id')
                ->bind(':id', $articleId, ParameterType::INTEGER)
                ->order($this->db->quoteName('t.title') . ' ASC');
            return array_values(array_map('strval', $this->db->setQuery($query)->loadColumn() ?: []));
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * The name of an access level, because `access: 3` means nothing to the person reading a file
     * — `Special` does. The number stays beside it: that is what an Apply writes back.
     */
    private function accessName(int $access): string
    {
        if ($access <= 0) {
            return '';
        }
        try {
            $query = $this->db->getQuery(true)
                ->select($this->db->quoteName('title'))
                ->from($this->db->quoteName('#__viewlevels'))
                ->where($this->db->quoteName('id') . ' = :id')
                ->bind(':id', $access, ParameterType::INTEGER);
            return (string) ($this->db->setQuery($query)->loadResult() ?? '');
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function tableFor(string $kind): string
    {
        if (!isset(self::MAP[$kind])) {
            throw new \RuntimeException("unknown kind: {$kind}");
        }
        return self::MAP[$kind]['table'];
    }
}
