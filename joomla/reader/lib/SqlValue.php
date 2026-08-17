<?php
/**
 * SqlValue — turns a value into a MySQL string literal, byte for byte as mysqldump would.
 *
 * The escaping table has to match whatever unescapes it later, or a dump that is rewritten and
 * re-imported comes back subtly wrong. It is:
 *   0x00 -> \0   0x0A -> \n   0x0D -> \r   0x1A -> \Z   ' -> \'   \ -> \\   " -> \"
 * and every other byte is passed through untouched.
 *
 * NULL is emitted bare, without quotes, so it stays distinct from the string "NULL". Numbers are
 * quoted like everything else: MySQL accepts '123' into an INT column, and quoting is the safer
 * choice for binary columns. mysqldump leaves numbers unquoted; on import the two are equivalent.
 *
 * No Joomla dependency, so it can be tested on its own.
 */

final class SqlValue
{
    /** @var array<int,string> */
    private const ESC = [
        0x00 => '\\0',
        0x0A => '\\n',
        0x0D => '\\r',
        0x1A => '\\Z',
        0x27 => "\\'",   // '
        0x5C => '\\\\',  // backslash
        0x22 => '\\"',   // "
    ];

    /** Raw bytes to what belongs between the quotes. Does not add the quotes itself. */
    public static function escape(string $raw): string
    {
        $out = '';
        $n = strlen($raw);
        for ($i = 0; $i < $n; $i++) {
            $c = ord($raw[$i]);
            $out .= self::ESC[$c] ?? $raw[$i];
        }
        return $out;
    }

    /** One cell to a SQL literal: null becomes NULL, anything else becomes 'escaped'. */
    public static function literal(?string $raw): string
    {
        if ($raw === null) {
            return 'NULL';
        }
        return "'" . self::escape($raw) . "'";
    }

    /**
     * One row to "(v1,v2,...)".
     * @param array<int,?string> $row
     */
    public static function rowTuple(array $row): string
    {
        $parts = [];
        foreach ($row as $cell) {
            $parts[] = self::literal($cell);
        }
        return '(' . implode(',', $parts) . ')';
    }

    /**
     * A batch of rows to one extended INSERT. Many tuples in one statement rather than one
     * statement each: fewer bytes to move, and far fewer statements for MySQL to parse on import.
     * @param array<int,array<int,?string>> $rows
     */
    public static function insert(string $table, array $rows): string
    {
        if (count($rows) === 0) {
            return '';
        }
        $tuples = [];
        foreach ($rows as $row) {
            $tuples[] = self::rowTuple($row);
        }
        return 'INSERT INTO `' . str_replace('`', '``', $table) . '` VALUES '
            . implode(',', $tuples) . ";\n";
    }
}
