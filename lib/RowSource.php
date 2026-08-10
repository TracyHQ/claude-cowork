<?php
/**
 * RowSource — nguon doc DB truu tuong hoa, de test khong can MySQL.
 *
 * Nguyen ly "plugin ngu, Tracy khon": phan sinh SQL (DbDumper) khong biet gi ve
 * driver. Ban that noi mysqli/PDO; test dua rows gia. Doc theo LO co gioi han +
 * con tro resume (offset) de moi HTTP call chi lam mot mieng ≤20s.
 */
interface RowSource
{
    /** Danh sach ten bang theo thu tu on dinh. @return string[] */
    public function tables(): array;

    /** Cau CREATE TABLE cua mot bang (SHOW CREATE TABLE). */
    public function createStatement(string $table): string;

    /** Tong so dong cua mot bang (de tinh done + progress). */
    public function rowCount(string $table): int;

    /**
     * Doc mot lo dong: LIMIT $limit OFFSET $offset, moi dong la mang gia tri
     * ?string (null = SQL NULL) theo thu tu cot.
     * @return array<int,array<int,?string>>
     */
    public function readRows(string $table, int $offset, int $limit): array;
}
