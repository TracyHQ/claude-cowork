<?php
require_once __DIR__ . '/../lib/RowSource.php';

/**
 * A stand-in source for tests: rows held in memory, no MySQL anywhere.
 *
 * A table may declare `columns` and `key` to be paged by key; without them it behaves like a
 * table with no unique NOT NULL index, which is the fallback path and also worth testing.
 *
 * Values compare as STRINGS here, where MySQL would compare by column type. Nothing in these
 * tests turns on the difference (`'2' < '10'` in one and not the other), and a fake that
 * reimplemented MySQL's collation rules would be a second thing to be wrong.
 */
final class FakeRowSource implements RowSource
{
    /** @var array<string,array{create:string,rows:array<int,array<int,?string>>,columns?:string[],key?:string[]}> */
    private array $tables;

    /** @param array<string,array{create:string,rows:array<int,array<int,?string>>,columns?:string[],key?:string[]}> $tables */
    public function __construct(array $tables)
    {
        $this->tables = $tables;
    }

    /**
     * Write to the table mid-dump, which is the whole thing these tests exist to reproduce.
     * `$at` puts the row somewhere other than the end, the way a random primary key does.
     *
     * @param array<int,?string> $row
     */
    public function insertRow(string $table, array $row, int $at = 0): void
    {
        array_splice($this->tables[$table]['rows'], $at, 0, [$row]);
    }

    public function deleteRow(string $table, int $at): void
    {
        array_splice($this->tables[$table]['rows'], $at, 1);
    }

    public function tables(): array
    {
        return array_keys($this->tables);
    }

    public function createStatement(string $table): string
    {
        return $this->tables[$table]['create'] ?? '';
    }

    public function insertColumns(string $table): ?array
    {
        return $this->tables[$table]['insertColumns'] ?? null;
    }

    public function rowCount(string $table): int
    {
        return count($this->tables[$table]['rows'] ?? []);
    }

    public function tableStats(): array
    {
        $out = [];
        foreach ($this->tables as $name => $t) {
            // A rough size, since nothing here has a storage engine to ask. The point of the
            // fake is the shape of the answer, not its accuracy.
            $out[$name] = ['rows' => count($t['rows'] ?? []), 'bytes' => count($t['rows'] ?? []) * 64];
        }
        return $out;
    }

    /** @var array<int,string[]> Every list checksums() was asked for, so a test can see the batches. */
    public array $checksumCalls = [];

    /**
     * A checksum of the table's rows as the fake holds them, so changing a row changes it. Not
     * MySQL's number — only its shape: a decimal string, or null for a table that is not here, as
     * CHECKSUM TABLE answers NULL for one. A table may pin `checksum` (null included) to stand for
     * an engine that cannot checksum.
     */
    public function checksums(array $tables): array
    {
        $this->checksumCalls[] = $tables;
        $out = [];
        foreach ($tables as $table) {
            if (!isset($this->tables[$table])) {
                $out[$table] = null;
            } elseif (array_key_exists('checksum', $this->tables[$table])) {
                $out[$table] = $this->tables[$table]['checksum'];
            } else {
                $out[$table] = (string) hexdec(substr(sha1(serialize($this->tables[$table]['rows'] ?? [])), 0, 12));
            }
        }
        return $out;
    }

    public function rangeChecksums(string $table, array $skip, string $key, array $starts): ?array
    {
        if (!isset($this->tables[$table])) {
            return null;
        }
        $columns = $this->tables[$table]['columns'] ?? [];
        $at = array_search($key, $columns, true);
        if ($at === false) {
            throw new RuntimeException("`{$key}` is not a column of `{$table}`");
        }
        $drop = array_map('strtolower', $skip);
        $counts = array_fill(0, count($starts) + 1, 0);
        $xors = array_fill(0, count($starts) + 1, 0);
        foreach ($this->tables[$table]['rows'] ?? [] as $row) {
            $values = array_values($row);
            $k = (int) $values[$at];
            // INTERVAL(): the number of starts at or below the key.
            $range = 0;
            foreach ($starts as $start) {
                if ($k >= (int) $start) {
                    $range++;
                }
            }
            $kept = [];
            foreach ($values as $i => $value) {
                $name = $columns[$i] ?? "c{$i}";
                if (!in_array(strtolower($name), $drop, true)) {
                    $kept[] = $value === null ? 'N' : bin2hex((string) $value);
                }
            }
            $counts[$range]++;
            if ($kept !== []) {
                $xors[$range] ^= hexdec(substr(md5(implode("\x1f", $kept)), 0, 12));
            }
        }
        $out = [];
        foreach ($counts as $i => $n) {
            $out[] = $n . ':' . ($n === 0 ? 0 : $xors[$i]);
        }
        return $out;
    }

    /**
     * The substance checksum in its real shape, "<rows>:<xor>", over the columns `$skip` leaves:
     * not MySQL's number, but it moves exactly when a kept column does. A table without `columns`
     * names its columns c0, c1, … by position.
     */
    public function substanceChecksum(string $table, array $skip): ?string
    {
        if (!isset($this->tables[$table])) {
            return null;
        }
        $rows = $this->tables[$table]['rows'] ?? [];
        $drop = array_map('strtolower', $skip);
        $xor = 0;
        foreach ($rows as $row) {
            $kept = [];
            foreach (array_values($row) as $i => $value) {
                $name = $this->tables[$table]['columns'][$i] ?? "c{$i}";
                if (!in_array(strtolower($name), $drop, true)) {
                    $kept[] = $value === null ? 'N' : bin2hex($value);
                }
            }
            if ($kept !== []) {
                $xor ^= hexdec(substr(md5(implode("\x1f", $kept)), 0, 12));
            }
        }
        return count($rows) . ':' . $xor;
    }

    public function readRows(string $table, int $offset, int $limit): array
    {
        $rows = $this->tables[$table]['rows'] ?? [];
        return array_slice($rows, $offset, $limit);
    }

    public function keyColumns(string $table): array
    {
        return $this->tables[$table]['key'] ?? [];
    }

    public function readRowsAfter(string $table, ?array $after, int $limit): array
    {
        $key = $this->keyColumns($table);
        if ($key === []) {
            throw new RuntimeException("table `{$table}` has no unique NOT NULL key to page by");
        }
        $positions = [];
        foreach ($key as $column) {
            $index = array_search($column, $this->tables[$table]['columns'] ?? [], true);
            if ($index === false) {
                throw new RuntimeException("key column `{$column}` is missing from `{$table}`");
            }
            $positions[] = (int) $index;
        }

        $rows = $this->tables[$table]['rows'] ?? [];
        // ORDER BY the key, which is what the real source asks the server for. The fake holds
        // rows in insertion order, so without this the cursor would be comparing against an
        // order nothing produced.
        usort($rows, fn($a, $b) => self::compare($a, $b, $positions));

        $out = [];
        foreach ($rows as $row) {
            if ($after !== null && self::compareKeys(self::keyOf($row, $positions), $after) <= 0) {
                continue;
            }
            $out[] = $row;
            if (count($out) >= $limit) {
                break;
            }
        }
        $last = $out === [] ? null : self::keyOf($out[count($out) - 1], $positions);
        return ['rows' => $out, 'after' => $last];
    }

    /**
     * @param array<int,?string> $row
     * @param int[] $positions
     * @return array<int,?string>
     */
    private static function keyOf(array $row, array $positions): array
    {
        $out = [];
        foreach ($positions as $position) {
            $out[] = $row[$position];
        }
        return $out;
    }

    /** @param int[] $positions */
    private static function compare(array $a, array $b, array $positions): int
    {
        return self::compareKeys(self::keyOf($a, $positions), self::keyOf($b, $positions));
    }

    /**
     * @param array<int,?string> $a
     * @param array<int,?string> $b
     */
    private static function compareKeys(array $a, array $b): int
    {
        foreach ($a as $i => $value) {
            $other = $b[$i] ?? null;
            // As MySQL orders an integer key: by value (10 after 9), not by its text.
            $cmp = is_numeric($value) && is_numeric($other) && ctype_digit(ltrim((string) $value, '-')) && ctype_digit(ltrim((string) $other, '-'))
                ? ((int) $value <=> (int) $other)
                : strcmp((string) $value, (string) $other);
            if ($cmp !== 0) {
                return $cmp;
            }
        }
        return 0;
    }

    public function renameTable(string $from, string $to): void
    {
        if (!isset($this->tables[$from])) {
            throw new RuntimeException("table {$from} does not exist");
        }
        if (isset($this->tables[$to])) {
            throw new RuntimeException("table {$to} already exists");
        }
        $this->tables[$to] = $this->tables[$from];
        unset($this->tables[$from]);
    }

    public function dropTable(string $table): void
    {
        if (!isset($this->tables[$table])) {
            throw new RuntimeException("table {$table} does not exist");
        }
        unset($this->tables[$table]);
    }
}
