<?php
/**
 * RowSource — the database behind an interface, so the dumper can be tested without MySQL.
 *
 * Follows the engine's guiding split: the part that writes SQL (DbDumper) knows nothing about a
 * driver. The real implementation talks to mysqli; a test hands it rows from an array. Reads are
 * batched, so one HTTP call only ever does one bounded piece of work and finishes well inside a
 * shared host's execution limit.
 *
 * Batching is by KEY, not by offset. A dump of a live site reads a table the site is still
 * writing to, and OFFSET addresses a position in a result set rather than a row: an insert
 * between chunk N and N+1 pushes every later row back one place, so a row already emitted is
 * emitted again, and a delete pushes the other way and a row is never emitted at all. Measured
 * on a real site 2026-08-12: a session table gave 2636 rows of which 2635 were distinct, and the
 * import died on `ERROR 1062` after the whole 312 MB export had already run.
 *
 * `readRows()` is kept because it is the interface a caller with no key can still use, and
 * because a fake in a test has no concurrency to defend against. Nothing that dumps a live
 * site should reach for it.
 */
interface RowSource
{
    /** Table names, in a stable order. @return string[] */
    public function tables(): array;

    /** One table's CREATE TABLE statement (SHOW CREATE TABLE). */
    public function createStatement(string $table): string;

    /** How many rows a table holds, which is what tells the caller when it is done. */
    public function rowCount(string $table): int;

    /**
     * Row counts and sizes for every table, in ONE query.
     *
     * Separate from rowCount() because the two answer different questions. rowCount() is exact
     * and is what decides when a dump is finished. This is an estimate the server already keeps,
     * and it exists so a caller can size the work before starting it — asking rowCount() per
     * table would mean a COUNT(*) across every table on the site just to draw a progress bar.
     *
     * @return array<string,array{rows:int,bytes:int}>
     */
    public function tableStats(): array;

    /**
     * One batch of rows: LIMIT $limit OFFSET $offset. Each row is an array of column values in
     * column order, where null means SQL NULL.
     *
     * Positional, not associative, deliberately: a dump needs the values in the order the
     * CREATE declares them, and mysqli hands every column back as string|null, which is exactly
     * the shape SQL wants with no type guessing.
     *
     * Unsafe against a table being written to while it is read. See {@see readRowsAfter}.
     *
     * @return array<int,array<int,?string>>
     */
    public function readRows(string $table, int $offset, int $limit): array;

    /**
     * The columns that address a row uniquely, in index order, for keyset paging.
     *
     * The PRIMARY KEY when there is one. Failing that, any UNIQUE index whose every column is
     * NOT NULL — unique is what makes the cursor address one row, and NOT NULL is what makes
     * `>` answer at all, since a comparison against NULL is neither true nor false and the row
     * would simply drop out of every batch.
     *
     * Empty when the table has neither. That is not an error: a caller falls back to offset
     * paging and accepts the risk, because there is no stable cursor to be had.
     *
     * @return string[]
     */
    public function keyColumns(string $table): array;

    /**
     * One batch of rows strictly after $after, ordered by {@see keyColumns}.
     *
     * Immune to the site writing during the dump, and that is the whole point: the cursor names
     * a ROW, so a row inserted behind it is simply never seen by this dump, and one inserted
     * ahead of it is seen exactly once. Neither can shift what has already been emitted.
     *
     * `after` is the key values of the last row of the previous batch, or null to start. It is
     * carried back as `after` in the return so the caller never has to know which columns the
     * key is made of.
     *
     * @param ?array<int,?string> $after
     * @return array{rows:array<int,array<int,?string>>, after:?array<int,?string>}
     */
    public function readRowsAfter(string $table, ?array $after, int $limit): array;

    /**
     * RENAME TABLE, for the trash-not-drop cleanup (ADR 0083). A metadata operation: instant,
     * no data copied, fully reversible by renaming back.
     */
    public function renameTable(string $from, string $to): void;

    /**
     * DROP TABLE — the trash's second step (ADR 0083: purge). The ONE destructive operation in
     * this interface, and the engine only ever points it at `_tracy_trash_*` names; everything
     * else goes through {@see renameTable} first, which is what makes a wrong call recoverable.
     */
    public function dropTable(string $table): void;
}
