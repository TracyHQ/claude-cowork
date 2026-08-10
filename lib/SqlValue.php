<?php
/**
 * SqlValue — escape gia tri thanh string literal MySQL theo dung quy uoc mysqldump.
 *
 * Phai KHOP tung byte voi bo unescape ben Python (local-mirror-proto/sql_dump_rewrite.py)
 * de dump nay -> rewrite domain -> re-import chay tron ven. Cung bang escape:
 *   0x00->\0  0x0A->\n  0x0D->\r  0x1A->\Z  '->\'  \->\\  "->\"  con lai giu nguyen byte.
 * NULL phat ra bare NULL (khong nhay). So van boc nhay nhu chuoi — MySQL nhan '123' vao
 * cot INT binh thuong, va boc nhay an toan hon cho cot nhi phan; mysqldump de so tran,
 * nhung tuong duong ngu nghia khi import.
 *
 * KHONG phu thuoc Joomla — test duoc offline.
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

    /** bytes tho -> phan giua hai dau nhay (chua boc nhay). */
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

    /** mot gia tri o -> literal SQL. null => NULL; con lai => 'escaped'. */
    public static function literal(?string $raw): string
    {
        if ($raw === null) {
            return 'NULL';
        }
        return "'" . self::escape($raw) . "'";
    }

    /**
     * Mot dong -> "(v1,v2,...)".
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
     * Mot lo dong -> mot cau INSERT extended (nhieu tuple, gon byte hon tung dong).
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
