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

    /** RENAME TABLE, for the trash-not-drop cleanup (ADR 0083). */
    public function renameTable(string $from, string $to): void
    {
        $this->src->renameTable($from, $to);
    }

    /** DROP TABLE — only ever called on `_tracy_trash_*` names (ADR 0083: purge). */
    public function dropTable(string $table): void
    {
        $this->src->dropTable($table);
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
     * @param ?string $cursor
     * @return array{sql:string, next_cursor:?string, done:bool, rows:int, total:int}
     */
    public function dumpChunkFrom(string $table, ?string $cursor, int $limit): array
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
