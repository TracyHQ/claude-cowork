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
    /** @var array<string,string[]> Key columns per table, for the same reason: the key does not
     *  change between two chunks of one dump, and SHOW KEYS is not free. */
    private array $keyCache = [];

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
        // No ORDER BY and no cursor: the caller has asked for a position in a result set, and a
        // result set with no order does not have stable positions. Kept for callers that have no
        // key to page by; readRowsAfter() is what a live site is dumped with.
        $res = $this->db->query("SELECT * FROM `{$t}` LIMIT {$limit} OFFSET {$offset}");
        $out = [];
        while ($row = $res->fetch_row()) {
            // mysqli hands back every column as string|null, which is exactly the shape a
            // dump needs: no type guessing, and NULL stays distinguishable from an empty string.
            $out[] = $row;
        }
        return $out;
    }

    public function keyColumns(string $table): array
    {
        if (isset($this->keyCache[$table])) {
            return $this->keyCache[$table];
        }
        $t = $this->db->real_escape_string($table);
        // One query answers both halves. SHOW KEYS reports Non_unique, Null and Seq_in_index,
        // which is everything the choice needs; information_schema would answer the same at the
        // cost of a join and of permissions some shared hosts do not grant.
        $res = @$this->db->query("SHOW KEYS FROM `{$t}`");
        if (!$res) {
            return $this->keyCache[$table] = [];
        }
        $byName = [];
        while ($row = $res->fetch_assoc()) {
            $name = (string) ($row['Key_name'] ?? '');
            if ($name === '' || (int) ($row['Non_unique'] ?? 1) !== 0) {
                continue;
            }
            // `Null` is 'YES' for a nullable column. One nullable column disqualifies the whole
            // index: the cursor compares with `>`, and a comparison against NULL is neither true
            // nor false, so that row would fall out of every batch and never be dumped.
            if (strtoupper((string) ($row['Null'] ?? '')) === 'YES') {
                $byName[$name] = null;
                continue;
            }
            if (array_key_exists($name, $byName) && $byName[$name] === null) {
                continue;
            }
            $byName[$name][(int) ($row['Seq_in_index'] ?? 0)] = (string) ($row['Column_name'] ?? '');
        }
        $chosen = [];
        if (!empty($byName['PRIMARY'])) {
            $chosen = $byName['PRIMARY'];
        } else {
            foreach ($byName as $name => $cols) {
                if ($name !== 'PRIMARY' && !empty($cols)) {
                    // First unique index wins. SHOW KEYS returns them in a stable order, and any
                    // of them addresses a row uniquely, which is the only property that matters.
                    $chosen = $cols;
                    break;
                }
            }
        }
        ksort($chosen);
        return $this->keyCache[$table] = array_values($chosen);
    }

    public function readRowsAfter(string $table, ?array $after, int $limit): array
    {
        $key = $this->keyColumns($table);
        if ($key === []) {
            throw new RuntimeException("table `{$table}` has no unique NOT NULL key to page by");
        }
        $t = $this->db->real_escape_string($table);
        $order = implode(', ', array_map(fn($c) => '`' . str_replace('`', '``', $c) . '`', $key));

        $where = '';
        if ($after !== null) {
            if (count($after) !== count($key)) {
                throw new RuntimeException("cursor for `{$table}` has the wrong number of columns");
            }
            // Row constructor comparison, so a composite key needs no unrolling into the
            // `(a > x) OR (a = x AND b > y)` chain that is so easy to get subtly wrong. MySQL has
            // supported it since 4.1 and it uses the index.
            $values = implode(', ', array_map(fn($v) => "'" . $this->db->real_escape_string((string) $v) . "'", $after));
            $where = " WHERE ({$order}) > ({$values})";
        }

        $res = $this->db->query("SELECT * FROM `{$t}`{$where} ORDER BY {$order} LIMIT {$limit}");
        if (!$res) {
            throw new RuntimeException($this->db->error ?: 'read failed');
        }
        // Which POSITIONS hold the key, resolved once per batch from the result's own metadata.
        // Resolving it from the result rather than from a second DESCRIBE keeps the two in step:
        // whatever `SELECT *` returned is what is being indexed into.
        $positions = [];
        foreach ($res->fetch_fields() as $index => $field) {
            $positions[$field->name] = $index;
        }
        $keyPositions = [];
        foreach ($key as $column) {
            if (!array_key_exists($column, $positions)) {
                throw new RuntimeException("key column `{$column}` is missing from `{$table}`");
            }
            $keyPositions[] = $positions[$column];
        }

        $rows = [];
        $last = null;
        while ($row = $res->fetch_row()) {
            $rows[] = $row;
            $last = $row;
        }
        $next = null;
        if ($last !== null) {
            $next = [];
            foreach ($keyPositions as $position) {
                $next[] = $last[$position];
            }
        }
        return ['rows' => $rows, 'after' => $next];
    }
}
