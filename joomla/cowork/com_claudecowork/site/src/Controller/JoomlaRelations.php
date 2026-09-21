<?php
namespace Tracy\Component\ClaudeCowork\Site\Controller;
\defined('_JEXEC') or die;
use Joomla\Database\DatabaseInterface;

/** Native relations with bounded inputs and a complete before-state for Apply undo. */
final class JoomlaRelations
{
    private DatabaseInterface $db;
    public function __construct(DatabaseInterface $db) { $this->db = $db; }
    private function context(string $kind): string { return $kind === 'articleAssociation' ? 'com_content.item' : 'com_menus.item'; }
    private function target(string $kind, int $id): void {
        $table = $kind === 'moduleAssignment' ? '#__modules' : ($kind === 'articleAssociation' ? '#__content' : '#__menu');
        if ($id < 1 || !$this->db->setQuery('SELECT id FROM ' . $table . ' WHERE id=' . $id . ($table === '#__menu' ? ' AND client_id=0' : ''))->loadResult()) throw new \RuntimeException('relation target missing');
    }
    public function read(string $kind, int $id): ?array {
        $this->target($kind, $id);
        if ($kind === 'moduleAssignment') return ['menuids' => json_encode(array_map('intval', $this->db->setQuery('SELECT menuid FROM #__modules_menu WHERE moduleid=' . $id . ' ORDER BY menuid')->loadColumn()))];
        $context = $this->db->quote($this->context($kind));
        $key = $this->db->setQuery('SELECT `key` FROM #__associations WHERE id=' . $id . ' AND context=' . $context)->loadResult();
        $members = $key ? $this->db->setQuery('SELECT id, context, `key` FROM #__associations WHERE context=' . $context . ' AND `key`=' . $this->db->quote($key))->loadAssocList() : [];
        return ['members' => json_encode($members)];
    }
    public function write(string $kind, int $id, array $fields): int {
        $this->target($kind, $id);
        if ($kind === 'moduleAssignment') {
            $ids = json_decode((string) ($fields['menuids'] ?? ''), true);
            if (!is_array($ids) || count($ids) > 1000) throw new \RuntimeException('menuids must be a JSON array');
            foreach ($ids as $menu) if (!is_int($menu)) throw new \RuntimeException('menu ids must be integers');
            $this->db->setQuery('DELETE FROM #__modules_menu WHERE moduleid=' . $id)->execute();
            foreach (array_unique($ids) as $menu) { $row = (object) ['moduleid' => $id, 'menuid' => $menu]; $this->db->insertObject('#__modules_menu', $row); }
            return $id;
        }
        $context = $this->context($kind);
        $old = json_decode($this->read($kind, $id)['members'], true);
        if (isset($fields['members'])) {
            $members = json_decode((string) $fields['members'], true);
            if (!is_array($members)) throw new \RuntimeException('invalid association snapshot');
        } else {
            $ids = json_decode((string) ($fields['ids'] ?? ''), true);
            if (!is_array($ids) || count($ids) < 2 || count($ids) > 50 || !in_array($id, $ids, true)) throw new \RuntimeException('association needs 2–50 ids including its target');
            foreach ($ids as $member) { if (!is_int($member)) throw new \RuntimeException('association ids must be integers'); $this->target($kind, $member); }
            $table = $kind === 'articleAssociation' ? '#__content' : '#__menu';
            $languages = $this->db->setQuery('SELECT language FROM ' . $table . ' WHERE id IN (' . implode(',', $ids) . ')')->loadColumn();
            if (in_array('*', $languages, true) || count(array_unique($languages)) !== count($ids)) throw new \RuntimeException('association requires one item per specific language');
            sort($ids); $key = md5(json_encode($ids));
            $members = array_map(static fn ($item) => ['id' => $item, 'context' => $context, 'key' => $key], $ids);
            // Do not merge a customer's existing group by accident; include its full membership.
            foreach ($ids as $member) {
                $existing = json_decode($this->read($kind, $member)['members'], true);
                foreach ($existing as $entry) if (!in_array((int) $entry['id'], $ids, true)) throw new \RuntimeException('association already belongs to another group');
            }
        }
        $affected = [$id];
        foreach (array_merge($old, $members) as $entry) {
            if (!isset($entry['id'], $entry['key']) || ($entry['context'] ?? '') !== $context || !preg_match('/^[a-f0-9]{32}$/D', $entry['key'])) throw new \RuntimeException('invalid association member');
            $affected[] = (int) $entry['id'];
        }
        $this->db->setQuery('DELETE FROM #__associations WHERE context=' . $this->db->quote($context) . ' AND id IN (' . implode(',', array_unique($affected)) . ')')->execute();
        foreach ($members as $entry) { $row = (object) ['id' => (int) $entry['id'], 'context' => $context, 'key' => $entry['key']]; $this->db->insertObject('#__associations', $row); }
        return $id;
    }
}
