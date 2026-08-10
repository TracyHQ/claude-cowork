<?php
/**
 * DbDumper — a database dump written in plain PHP, one resumable chunk at a time.
 *
 * Plain PHP rather than shelling out to mysqldump because shared hosts routinely disable exec()
 * and shell_exec(), and those are exactly the hosts this has to work on. There is no fallback to
 * fall back to.
 *
 * Every call to dumpChunk() does one bounded piece of work: read at most `limit` rows of ONE
 * table from `offset`, return the SQL and where to carry on from. The caller keeps the cursor and
 * calls again; this side holds no loop and no state, which is what lets a table of any size
 * finish on a host that stops PHP after thirty seconds. The first chunk of each table (offset 0)
 * carries DROP and CREATE, so the dump can rebuild the schema as well as fill it.
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
        // Done when the cursor has passed the last row, or when a batch comes back empty —
        // which covers both a table that has run out and one that was empty to begin with.
        $done = ($read === 0) || ($next >= $total);

        return [
            'sql'         => $sql,
            'next_offset' => $next,
            'done'        => $done,
            'rows'        => $read,
            'total'       => $total,
        ];
    }
}
