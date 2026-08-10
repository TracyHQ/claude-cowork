<?php
/**
 * RowSource — the database behind an interface, so the dumper can be tested without MySQL.
 *
 * Follows the engine's guiding split: the part that writes SQL (DbDumper) knows nothing about a
 * driver. The real implementation talks to mysqli; a test hands it rows from an array. Reads are
 * batched with an offset cursor, so one HTTP call only ever does one bounded piece of work and
 * finishes well inside a shared host's execution limit.
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
     * @return array<int,array<int,?string>>
     */
    public function readRows(string $table, int $offset, int $limit): array;
}
