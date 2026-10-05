<?php

/**
 * @package     Claude Cowork for Joomla
 * @copyright   (C) 2026 Tracy
 * @license     GPL-2.0-or-later
 */

namespace Tracy\Component\ClaudeCowork\Site\Controller;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

/**
 * The Joomla half of {@see \ApplyLog} — where the before-state of every edit is kept so an Apply
 * can be undone to exactly what was there.
 *
 * One row per step, in `#__claudecowork_apply_log`, ordered by a per-Apply sequence. The step is
 * stored as `base64(serialize($entry))`, not JSON: a media edit's before-state is the file's raw
 * bytes, and JSON cannot carry a byte that is not valid UTF-8, where PHP's own serialization
 * carries any string exactly. base64 keeps the result safe for a text column. On the way back the
 * blob is unserialized with classes forbidden, so a corrupted row can never instantiate anything.
 *
 * The table is created by the install script (`script.php`), idempotently, so it exists after a
 * fresh install and after an upgrade from a version that had no write side.
 */
final class JoomlaApplyLog implements \ApplyLog
{
    private const TABLE = '#__claudecowork_apply_log';

    private DatabaseInterface $db;

    public function __construct(DatabaseInterface $db)
    {
        $this->db = $db;
    }

    public function record(string $applyId, array $entry): void
    {
        $object = new \stdClass();
        $object->apply_id = $applyId;
        $object->seq      = $this->nextSeq($applyId);
        $object->entry    = base64_encode(serialize($entry));
        $object->created  = Factory::getDate()->toSql();

        $this->db->insertObject(self::TABLE, $object);
    }

    /**
     * Rows one multi-row INSERT carries, and the SQL it may grow to. 256 KB is far under the default
     * max_allowed_packet of every database Joomla 4 and later runs on (4 MB on MySQL 5.6, 16 MB on
     * MariaDB, 64 MB on MySQL 8). A retire's visibility step is ~200 bytes, so its 3,000 rows go in
     * six statements. One entry over the limit (a media before-state) goes alone, exactly as record()
     * would send it.
     */
    private const INSERT_ROWS = 500;
    private const INSERT_BYTES = 262144;

    /**
     * The rows record() would write, one per entry and in the order given: the sequence is read once
     * and counted on, instead of a MAX(seq) read and an INSERT per step.
     */
    public function recordMany(string $applyId, array $entries): void
    {
        if ($entries === []) {
            return;
        }
        $seq = $this->nextSeq($applyId);
        $apply = $this->db->quote($applyId);
        $created = $this->db->quote(Factory::getDate()->toSql());
        $rows = [];
        $bytes = 0;
        foreach (array_values($entries) as $entry) {
            $row = $apply . ',' . $seq++ . ',' . $this->db->quote(base64_encode(serialize($entry))) . ',' . $created;
            if ($rows !== [] && (\count($rows) >= self::INSERT_ROWS || $bytes + \strlen($row) > self::INSERT_BYTES)) {
                $this->insertRows($rows);
                $rows = [];
                $bytes = 0;
            }
            $rows[] = $row;
            $bytes += \strlen($row) + 3;
        }
        $this->insertRows($rows);
    }

    /** @param string[] $rows each the quoted values of one row, in the columns' order */
    private function insertRows(array $rows): void
    {
        $query = $this->db->getQuery(true)
            ->insert($this->db->quoteName(self::TABLE))
            ->columns($this->db->quoteName(['apply_id', 'seq', 'entry', 'created']));
        foreach ($rows as $row) {
            $query->values($row);
        }
        $this->db->setQuery($query)->execute();
    }

    public function entries(string $applyId): array
    {
        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName('entry'))
            ->from($this->db->quoteName(self::TABLE))
            ->where($this->db->quoteName('apply_id') . ' = :apply')
            ->order($this->db->quoteName('seq') . ' ASC')
            ->bind(':apply', $applyId, ParameterType::STRING);

        $rows = $this->db->setQuery($query)->loadColumn() ?: [];

        $out = [];
        foreach ($rows as $blob) {
            $decoded = base64_decode((string) $blob, true);
            if ($decoded === false) {
                continue;
            }
            $entry = unserialize($decoded, ['allowed_classes' => false]);
            if (\is_array($entry)) {
                $out[] = $entry;
            }
        }
        return $out;
    }

    public function clear(string $applyId): void
    {
        $query = $this->db->getQuery(true)
            ->delete($this->db->quoteName(self::TABLE))
            ->where($this->db->quoteName('apply_id') . ' = :apply')
            ->bind(':apply', $applyId, ParameterType::STRING);
        $this->db->setQuery($query)->execute();
    }

    /** The next sequence number for an Apply, so steps replay in the order they happened. */
    private function nextSeq(string $applyId): int
    {
        $query = $this->db->getQuery(true)
            ->select('COALESCE(MAX(' . $this->db->quoteName('seq') . '), 0) + 1')
            ->from($this->db->quoteName(self::TABLE))
            ->where($this->db->quoteName('apply_id') . ' = :apply')
            ->bind(':apply', $applyId, ParameterType::STRING);
        return (int) $this->db->setQuery($query)->loadResult();
    }
}
