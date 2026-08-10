<?php
/**
 * MysqliRowSource — RowSource that thi bang mysqli, dung cho plugin adapter.
 * KHONG cover boi unit test o day (can MySQL that) — hanh vi cot loi da test qua
 * FakeRowSource + DbDumper. Day chi la day noi driver, giu that mong.
 */
require_once __DIR__ . '/RowSource.php';

final class MysqliRowSource implements RowSource
{
    private mysqli $db;
    /** @var array<string,int>|null cache rowCount de khoi COUNT(*) lap lai moi chunk */
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

    public function readRows(string $table, int $offset, int $limit): array
    {
        $t = $this->db->real_escape_string($table);
        $res = $this->db->query("SELECT * FROM `{$t}` LIMIT {$limit} OFFSET {$offset}");
        $out = [];
        while ($row = $res->fetch_row()) {
            // mysqli tra moi cot dang string|null theo dung nhu mysqldump can
            $out[] = $row;
        }
        return $out;
    }
}
