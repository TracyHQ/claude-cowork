<?php
/**
 * MysqliRowSource — the RowSource that actually talks to MySQL.
 *
 * Deliberately not covered by the unit tests here, which would need a real server: the behaviour
 * that matters is tested through FakeRowSource and DbDumper instead. This file is the wire to the
 * driver and is kept as thin as it can be, so that there is little in it left to be wrong.
 */
require_once __DIR__ . '/RowSource.php';

final class MysqliRowSource implements RowSource
{
    private mysqli $db;
    /** @var array<string,int> Row counts already asked for, so a table with a hundred chunks
     *  costs one COUNT(*) rather than a hundred. */
    private array $countCache = [];

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    public function tables(): array
    {
        $out = [];
        $res = $this->db->query('SHOW TABLES');
        while ($row = $res->fetch_row()) {
            $out[] = $row[0];
        }
        return $out;
    }

    public function createStatement(string $table): string
    {
        $res = $this->db->query('SHOW CREATE TABLE `' . $this->db->real_escape_string($table) . '`');
        $row = $res->fetch_assoc();
        return $row['Create Table'] ?? '';
    }

    public function rowCount(string $table): int
    {
        if (!isset($this->countCache[$table])) {
            $res = $this->db->query('SELECT COUNT(*) c FROM `' . $this->db->real_escape_string($table) . '`');
            $this->countCache[$table] = (int) ($res->fetch_assoc()['c'] ?? 0);
        }
        return $this->countCache[$table];
    }

    public function tableStats(): array
    {
        // SHOW TABLE STATUS reports what the storage engine already tracks, so this costs one
        // query no matter how many tables there are. `Rows` is an estimate on InnoDB and that is
        // fine here: it sizes the work, it does not decide when the work is done.
        $out = [];
        $res = $this->db->query('SHOW TABLE STATUS');
        while ($row = $res->fetch_assoc()) {
            $out[(string) $row['Name']] = [
                'rows'  => (int) ($row['Rows'] ?? 0),
                'bytes' => (int) ($row['Data_length'] ?? 0) + (int) ($row['Index_length'] ?? 0),
            ];
        }
        return $out;
    }

    public function readRows(string $table, int $offset, int $limit): array
    {
        $t = $this->db->real_escape_string($table);
        $res = $this->db->query("SELECT * FROM `{$t}` LIMIT {$limit} OFFSET {$offset}");
        $out = [];
        while ($row = $res->fetch_row()) {
            // mysqli hands back every column as string|null, which is exactly the shape a
            // dump needs: no type guessing, and NULL stays distinguishable from an empty string.
            $out[] = $row;
        }
        return $out;
    }
}
