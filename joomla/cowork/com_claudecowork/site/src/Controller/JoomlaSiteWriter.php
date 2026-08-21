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
use Joomla\Database\DatabaseInterface;

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
                'urls', 'attribs', 'metadata', 'metakey', 'metadesc', 'language', 'featured', 'ordering'],
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
            $this->db->insertObject($table, $object, 'id');
            return (int) $object->id;
        }

        $object->id = $id;
        $this->db->updateObject($table, $object, 'id');
        return $id;
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
        'article'       => ['id', 'title', 'alias', 'catid', 'state', 'language', 'created', 'modified'],
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
        }

        $rows = $this->db->setQuery($query, $offset, $limit)->loadAssocList();
        return $rows ?? [];
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

    private function tableFor(string $kind): string
    {
        if (!isset(self::MAP[$kind])) {
            throw new \RuntimeException("unknown kind: {$kind}");
        }
        return self::MAP[$kind]['table'];
    }
}
