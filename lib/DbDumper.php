<?php
/**
 * DbDumper — dump DB PHP-thuan theo CHUNK, resume duoc.
 *
 * Vi sao PHP-thuan (khong exec mysqldump): shared hosting hay tat exec/shell. Day la
 * duong di duy nhat song duoc o do; co exec thi Tracy uu tien duong exec o cap
 * orchestration, dumper nay la fallback bat buoc.
 *
 * Moi lan goi dumpChunk() lam mot mieng BOUNDED: doc <=limit dong cua MOT bang tu
 * offset, tra ra SQL + con tro tiep theo. Tracy giu con tro va goi lai — plugin khong
 * giu vong lap. Chunk dau cua moi bang (offset 0) kem DROP + CREATE de dump restore duoc.
 *
 * Output di qua SqlValue nen khop byte voi bo rewrite domain ben Python.
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

    /** Danh sach ten bang — de orchestrator tu kham pha schema. @return string[] */
    public function tables(): array
    {
        return $this->src->tables();
    }

    /**
     * @param string $table ten bang
     * @param int    $offset dong bat dau
     * @param int    $limit  so dong toi da chunk nay
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
        // done khi da qua het total, HOAC lo doc ve rong (bang het dong / bang rong)
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
