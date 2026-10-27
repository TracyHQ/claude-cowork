<?php
/**
 * DbDumper — a database dump written in plain PHP, one resumable chunk at a time.
 *
 * Plain PHP rather than shelling out to mysqldump because shared hosts routinely disable exec()
 * and shell_exec(), and those are exactly the hosts this has to work on. There is no fallback to
 * fall back to.
 *
 * Every call does one bounded piece of work: read at most `limit` rows of ONE table, return the
 * SQL and where to carry on from. The caller keeps the cursor and calls again; this side holds no
 * loop and no state, which is what lets a table of any size finish on a host that stops PHP after
 * thirty seconds. The first chunk of each table carries DROP and CREATE, so the dump can rebuild
 * the schema as well as fill it.
 *
 * `dumpChunkFrom()` is the one to use. Its cursor names the last ROW emitted, so a site still
 * writing to the table it is being dumped from cannot shift what has already been sent. The older
 * `dumpChunk()` addresses a POSITION instead, and a position moves: an insert behind the cursor
 * re-emits a row (the import then dies on `ERROR 1062`, measured on a live site 2026-08-12:
 * a session table, 2636 rows and 2635 distinct) and a delete behind it skips one silently, which is
 * worse. It is kept only so a desk built against the old wire keeps working while it updates.
 */

require_once __DIR__ . '/SqlValue.php';
require_once __DIR__ . '/RowSource.php';

final class DbDumper
{
    private RowSource $src;

    public function __construct(RowSource $src)
    {
        $this->src = $src;
    }

    /** Table names, so the caller discovers the schema rather than being told it. @return string[] */
    public function tables(): array
    {
        return $this->src->tables();
    }

    /**
     * Row counts and sizes per table, for sizing a dump before running it.
     * @return array<string,array{rows:int,bytes:int}>
     */
    public function tableStats(): array
    {
        return $this->src->tableStats();
    }

    /** How many tables one CHECKSUM TABLE statement names. */
    public const CHECKSUM_BATCH = 50;

    /**
     * CHECKSUM TABLE over every table named, a batch of {@see CHECKSUM_BATCH} per statement, so a
     * site of five hundred tables costs ten statements rather than one each or one too long to send.
     *
     * @param string[] $tables
     * @return array<string,?string> keyed by table, in the order given
     */
    public function checksums(array $tables): array
    {
        $out = [];
        foreach (array_chunk(array_values($tables), self::CHECKSUM_BATCH) as $batch) {
            $answer = $this->src->checksums($batch);
            foreach ($batch as $table) {
                $value = $answer[$table] ?? null;
                $out[$table] = $value === null ? null : (string) $value;
            }
        }
        return $out;
    }

    /** {@see RowSource::rangeChecksums}, for one table. @param string[] $skip @param int[] $starts */
    public function rangeChecksums(string $table, array $skip, string $key, array $starts): ?array
    {
        return $this->src->rangeChecksums($table, $skip, $key, $starts);
    }

    /**
     * The rows of a table keyed by ONE integer column, from the first key above `$after` (null: from
     * the start), at most `$limit`, as INSERT statements: one range of rows a caller found moved
     * ({@see rangeChecksums}), read again alone. `last` is the key of the last row answered (null
     * when none), `rows` how many.
     *
     * @return array{sql:string, rows:int, last:?string}
     */
    public function rowsAfter(string $table, string $key, ?int $after, int $limit): array
    {
        if ($this->src->keyColumns($table) !== [$key]) {
            throw new InvalidArgumentException("`{$key}` is not the one key column of `{$table}`");
        }
        $batch = $this->src->readRowsAfter($table, $after === null ? null : [(string) $after], $limit);
        $rows = $batch['rows'];
        $last = $batch['after'] === null ? null : (string) $batch['after'][0];
        return ['sql' => SqlValue::insert($table, $rows), 'rows' => count($rows), 'last' => $last];
    }

    /**
     * The substance checksum ({@see RowSource::substanceChecksum}) of every table named, one query
     * each, with the same columns left out of all of them so the values stay comparable.
     *
     * @param string[] $tables
     * @param string[] $skip
     * @return array<string,?string> keyed by table, in the order given
     */
    public function substanceChecksums(array $tables, array $skip): array
    {
        $out = [];
        foreach (array_values($tables) as $table) {
            $out[$table] = $this->src->substanceChecksum($table, $skip);
        }
        return $out;
    }

    /** RENAME TABLE, for the trash-not-drop cleanup (ADR 0083). */
    public function renameTable(string $from, string $to): void
    {
        $this->src->renameTable($from, $to);
    }

    /** CREATE TABLE LIKE + INSERT SELECT, for the snapshot a rollback renames back into place. */
    public function copyTable(string $from, string $to): void
    {
        $this->src->copyTable($from, $to);
    }

    /** DROP TABLE — only ever called on `_tracy_trash_*` names (ADR 0083: purge). */
    public function dropTable(string $table): void
    {
        $this->src->dropTable($table);
    }

    /**
     * @param string $table  which table
     * @param int    $offset the row to start at
     * @param int    $limit  the most rows this chunk may read
     * @return array{sql:string, next_offset:int, done:bool, rows:int, total:int}
     */
    public function dumpChunk(string $table, int $offset, int $limit): array
    {
        if ($limit < 1) {
            throw new InvalidArgumentException('limit must be >= 1');
        }
        $total = $this->src->rowCount($table);
        $sql = '';

        if ($offset === 0) {
            $sql .= "-- table `{$table}`\n";
            $sql .= 'DROP TABLE IF EXISTS `' . str_replace('`', '``', $table) . "`;\n";
            $create = rtrim($this->src->createStatement($table));
            if ($create !== '') {
                $sql .= $create . ";\n";
            }
        }

        $rows = $this->src->readRows($table, $offset, $limit);
        $sql .= SqlValue::insert($table, $rows);

        $read = count($rows);
        $next = $offset + $read;

        return [
            'sql'         => $sql,
            'next_offset' => $next,
            'done'        => $this->finished($read, $limit),
            'rows'        => $read,
            'total'       => $total,
        ];
    }

    /**
     * The same work, addressed by the last row emitted instead of by a row count.
     *
     * `$cursor` is opaque and is this class's own: null starts a table, and whatever comes back
     * as `next_cursor` is handed straight back on the following call. Opaque because the two
     * paging strategies below carry different things inside it, and a caller that could read it
     * would end up depending on which one it got.
     *
     * `$more`, when given, cuts the batch short: it is asked after each row but the last with the
     * bytes the answer would hold if it ended there, and returning false ends it after that row.
     * The answer is then exactly what this method returns for the same cursor with `limit` set to
     * the rows taken — the same SQL, the same next cursor — so a cut page resumes at the very next
     * row. At least one row is always taken. Without `$more` nothing here changes.
     *
     * A cut on a keyed table reads the rows it keeps a second time, with the shorter limit: the key
     * of the row it stopped at is the source's to report, and asking again keeps it so. It costs one
     * extra read of at most one batch per cut, and the batch already read is let go first.
     *
     * @param ?string $cursor
     * @param ?callable $more `fn(int $bytes): bool`
     * @return array{sql:string, next_cursor:?string, done:bool, rows:int, total:int}
     */
    public function dumpChunkFrom(string $table, ?string $cursor, int $limit, ?callable $more = null): array
    {
        if ($limit < 1) {
            throw new InvalidArgumentException('limit must be >= 1');
        }
        $state = $this->decodeCursor($cursor);
        $total = $this->src->rowCount($table);

        $sql = '';
        if ($state === null) {
            $sql .= "-- table `{$table}`\n";
            $sql .= 'DROP TABLE IF EXISTS `' . str_replace('`', '``', $table) . "`;\n";
            $create = rtrim($this->src->createStatement($table));
            if ($create !== '') {
                $sql .= $create . ";\n";
            }
        }

        // A table with no unique NOT NULL column has no cursor to be had, and refusing to dump it
        // would lose data that is merely at risk. Fall back to offset, and say so in the SQL: a
        // dump that had to guess should be readable as such by whoever debugs the import.
        $key = $this->src->keyColumns($table);
        if ($key === []) {
            if ($state === null) {
                $sql .= "-- no unique NOT NULL key: paged by offset, rows may shift if the site writes here\n";
            }
            $offset = is_array($state) && isset($state['o']) ? (int) $state['o'] : 0;
            $rows = $this->src->readRows($table, $offset, $limit);
            $read = count($rows);
            $taken = $more === null ? $read : $this->rowsToTake($table, $sql, $rows, $more);
            if ($taken < $read) {
                // The rows a read of `$taken` would have returned from here: the same slice.
                $rows = array_slice($rows, 0, $taken);
                $read = $taken;
                $limit = $taken;
            }
            $sql .= SqlValue::insert($table, $rows);
            return [
                'sql'         => $sql,
                'next_cursor' => $this->encodeCursor(['o' => $offset + $read]),
                'done'        => $this->finished($read, $limit),
                'rows'        => $read,
                'total'       => $total,
            ];
        }

        $after = is_array($state) && isset($state['k']) && is_array($state['k']) ? $state['k'] : null;
        $batch = $this->src->readRowsAfter($table, $after, $limit);
        if ($more !== null) {
            $taken = $this->rowsToTake($table, $sql, $batch['rows'], $more);
            if ($taken < count($batch['rows'])) {
                $batch = null;
                $limit = $taken;
                $batch = $this->src->readRowsAfter($table, $after, $limit);
            }
        }
        $rows = $batch['rows'];
        $read = count($rows);
        $sql .= SqlValue::insert($table, $rows);

        return [
            'sql' => $sql,
            // Carry the previous cursor when a batch came back empty: the answer is `done`
            // anyway, and inventing a null cursor there would read as "start this table again".
            'next_cursor' => $batch['after'] === null ? $cursor : $this->encodeCursor(['k' => $batch['after']]),
            'done'        => $this->finished($read, $limit),
            'rows'        => $read,
            'total'       => $total,
        ];
    }

    /**
     * How many of `$rows` to keep: the first row for which `$more` answers false, or all of them.
     * The bytes it is told are exactly strlen() of `$sql` plus the INSERT of the rows so far.
     *
     * @param array<int,array<int,?string>> $rows
     */
    private function rowsToTake(string $table, string $sql, array $rows, callable $more): int
    {
        $count = count($rows);
        if ($count < 2) {
            return $count;
        }
        // 'INSERT INTO `t` VALUES ' + the tuples joined by ',' + ";\n", as SqlValue::insert writes it.
        $bytes = strlen($sql) + strlen('INSERT INTO `' . str_replace('`', '``', $table) . '` VALUES ') + 2;
        for ($i = 0; $i < $count - 1; $i++) {
            $bytes += strlen(SqlValue::rowTuple($rows[$i])) + ($i > 0 ? 1 : 0);
            if (!$more($bytes)) {
                return $i + 1;
            }
        }
        return $count;
    }

    /**
     * A short batch is the end, and it is the ONLY thing that is.
     *
     * This used to compare a running count against COUNT(*). That reads a number that moves: on a
     * table the site is deleting from, the total drops below the count already read and the dump
     * stops early, leaving rows out of a backup that reports success. A short batch cannot lie in
     * that direction. The price is one extra round trip when a table's size is an exact multiple
     * of the batch — a few hundred bytes, against a silently truncated table.
     */
    private function finished(int $read, int $limit): bool
    {
        return $read < $limit;
    }

    /** @param array<string,mixed> $state */
    private function encodeCursor(array $state): string
    {
        return base64_encode((string) json_encode($state));
    }

    /**
     * @return ?array<string,mixed> null when there is no cursor, i.e. this table starts here.
     */
    private function decodeCursor(?string $cursor): ?array
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }
        $decoded = base64_decode($cursor, true);
        if ($decoded === false) {
            throw new InvalidArgumentException('cursor is not valid base64');
        }
        $state = json_decode($decoded, true);
        if (!is_array($state)) {
            throw new InvalidArgumentException('cursor is not a valid cursor');
        }
        return $state;
    }
}
