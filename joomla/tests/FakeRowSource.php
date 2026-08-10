<?php
require_once __DIR__ . '/../lib/RowSource.php';

/** A stand-in source for tests: rows held in memory, no MySQL anywhere. */
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

    public function tableStats(): array
    {
        $out = [];
        foreach ($this->tables as $name => $t) {
            // A rough size, since nothing here has a storage engine to ask. The point of the
            // fake is the shape of the answer, not its accuracy.
            $out[$name] = ['rows' => count($t['rows'] ?? []), 'bytes' => count($t['rows'] ?? []) * 64];
        }
        return $out;
    }

    public function readRows(string $table, int $offset, int $limit): array
    {
        $rows = $this->tables[$table]['rows'] ?? [];
        return array_slice($rows, $offset, $limit);
    }
}
