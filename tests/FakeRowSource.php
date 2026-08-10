<?php
require_once __DIR__ . '/../lib/RowSource.php';

/** Nguon gia cho test: du lieu trong bo nho, khong can MySQL. */
final class FakeRowSource implements RowSource
{
    /** @var array<string,array{create:string,rows:array<int,array<int,?string>>}> */
    private array $tables;

    /** @param array<string,array{create:string,rows:array<int,array<int,?string>>}> $tables */
    public function __construct(array $tables)
    {
        $this->tables = $tables;
    }

    public function tables(): array
    {
        return array_keys($this->tables);
    }

    public function createStatement(string $table): string
    {
        return $this->tables[$table]['create'] ?? '';
    }

    public function rowCount(string $table): int
    {
        return count($this->tables[$table]['rows'] ?? []);
    }

    public function readRows(string $table, int $offset, int $limit): array
    {
        $rows = $this->tables[$table]['rows'] ?? [];
        return array_slice($rows, $offset, $limit);
    }
}
