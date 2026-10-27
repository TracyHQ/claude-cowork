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

    public function checksums(array $tables): array
    {
        $out = [];
        if ($tables === []) {
            return $out;
        }
        $names = array_map(fn($t) => '`' . str_replace('`', '``', (string) $t) . '`', $tables);
        $res = $this->db->query('CHECKSUM TABLE ' . implode(', ', $names));
        if (!$res) {
            throw new RuntimeException($this->db->error ?: 'checksum failed');
        }
        // One row per table named, in the order named, with `Table` as `<database>.<table>`. Matched
        // by position and checked by that suffix, so a database name with a dot in it changes nothing.
        $i = 0;
        while ($row = $res->fetch_assoc()) {
            $table = $tables[$i] ?? null;
            $i++;
            if ($table === null) {
                continue;
            }
            $named = (string) ($row['Table'] ?? '');
            if ($named !== $table && substr($named, -strlen('.' . $table)) !== '.' . $table) {
                continue;
            }
            $sum = $row['Checksum'] ?? null;
            $out[$table] = $sum === null ? null : (string) $sum;
        }
        foreach ($tables as $table) {
            if (!array_key_exists($table, $out)) {
                $out[$table] = null;
            }
        }
        return $out;
    }

    public function substanceChecksum(string $table, array $skip): ?string
    {
        $quoted = '`' . str_replace('`', '``', $table) . '`';
        $kept = $this->keptColumns($table, $skip);
        if ($kept === null) {
            return null;
        }
        $sql = $kept === []
            ? "SELECT COUNT(*), 0 FROM {$quoted}"
            : 'SELECT COUNT(*), ' . $this->rowXor($kept) . ' FROM ' . $quoted;
        $res = $this->db->query($sql);
        if (!$res) {
            throw new RuntimeException($this->db->error ?: 'substance checksum failed');
        }
        $row = $res->fetch_row();
        return (string) ($row[0] ?? '0') . ':' . (string) ($row[1] ?? '0');
    }

    public function rangeChecksums(string $table, array $skip, string $key, array $starts): ?array
    {
        $quoted = '`' . str_replace('`', '``', $table) . '`';
        $all = $this->keptColumns($table, []);
        if ($all === null) {
            return null;
        }
        if (!in_array($key, $all, true)) {
            throw new RuntimeException("`{$key}` is not a column of `{$table}`");
        }
        $kept = $this->keptColumns($table, $skip) ?? [];
        // INTERVAL(k, s1, s2, …) is 0 below s1, i from s_i up to s_{i+1}: the range of every row in
        // one pass, by a binary search over the starts (they must ascend, which the caller checks).
        $range = $starts === []
            ? '0'
            : 'INTERVAL(`' . str_replace('`', '``', $key) . '`, ' . implode(', ', array_map('intval', $starts)) . ')';
        $sum = $kept === [] ? '0' : $this->rowXor($kept);
        $res = $this->db->query("SELECT {$range} AS r, COUNT(*), {$sum} FROM {$quoted} GROUP BY r");
        if (!$res) {
            throw new RuntimeException($this->db->error ?: 'range checksums failed');
        }
        $out = array_fill(0, count($starts) + 1, '0:0');
        while ($row = $res->fetch_row()) {
            $at = (int) $row[0];
            if ($at >= 0 && $at < count($out)) {
                $out[$at] = (string) ($row[1] ?? '0') . ':' . (string) ($row[2] ?? '0');
            }
        }
        return $out;
    }

    /** The table's columns in ordinal order, those in `$skip` left out (any case); null when it cannot be read. */
    private function keptColumns(string $table, array $skip): ?array
    {
        $quoted = '`' . str_replace('`', '``', $table) . '`';
        $drop = array_map('strtolower', $skip);
        // SHOW COLUMNS answers in ordinal order, which is what keeps the concatenation stable.
        try {
            $res = $this->db->query('SHOW COLUMNS FROM ' . $quoted);
        } catch (Throwable $e) {
            return null;
        }
        if (!$res) {
            return null;
        }
        $kept = [];
        while ($row = $res->fetch_assoc()) {
            $name = (string) ($row['Field'] ?? '');
            if ($name !== '' && !in_array(strtolower($name), $drop, true)) {
                $kept[] = $name;
            }
        }
        return $kept;
    }

    /**
     * The XOR over rows of the first 64 bits of an MD5 of the columns named, as unsigned decimal.
     * CAST AS BINARY before HEX: HEX() of a DECIMAL or DOUBLE rounds it to an integer (1.40 and 1.60
     * hex as 1 and 2), so a price edited by cents would not move the value. The binary cast is the
     * exact text of a number and the raw bytes of a string or BLOB, with no character set or
     * collation in between. 'N' for NULL cannot be a hex string, and 0x1f cannot be inside one, so
     * neither NULL against '' nor a shifted value collides.
     */
    private function rowXor(array $kept): string
    {
        $parts = array_map(function ($c) {
            return "IFNULL(HEX(CAST(`" . str_replace('`', '``', $c) . "` AS BINARY)), 'N')";
        }, $kept);
        return 'BIT_XOR(CAST(CONV(SUBSTRING(MD5(CONCAT_WS(0x1f, ' . implode(', ', $parts) . ')), 1, 16), 16, 10) AS UNSIGNED))';
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

    public function renameTable(string $from, string $to): void
    {
        $f = $this->db->real_escape_string($from);
        $t = $this->db->real_escape_string($to);
        if (!$this->db->query("RENAME TABLE `{$f}` TO `{$t}`")) {
            throw new RuntimeException($this->db->error ?: 'rename failed');
        }
    }

    public function dropTable(string $table): void
    {
        $t = $this->db->real_escape_string($table);
        if (!$this->db->query("DROP TABLE `{$t}`")) {
            throw new RuntimeException($this->db->error ?: 'drop failed');
        }
    }
}
