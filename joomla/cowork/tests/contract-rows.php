<?php
// Loaded by run.php after multilingual-contracts.php (it borrows gateContractCopy / gateSite).
// A contract call reads the site in bulk and gives the same answer the paged walk gave.

/** The list-and-read walk every writer without BulkSiteReader gets, whatever mode the suite runs in. */
final class PagedOnlyWriter extends FakeSiteWriterBase {}
/** Bulk reads whatever mode the suite runs in, counting what it is asked. */
final class BulkOnlyWriter extends FakeSiteWriterBase implements BulkSiteReader {
    public array $reads = ['read' => 0, 'list' => 0, 'readAll' => 0, 'readMany' => 0];
    public function read(string $kind, int $id): ?array { $this->reads['read']++; return parent::read($kind, $id); }
    public function list(string $kind, int $offset, int $limit): array { $this->reads['list']++; return parent::list($kind, $offset, $limit); }
    public function readAll(string $kind, int $limit): array {
        $this->reads['readAll']++;
        $ids = array_keys($this->store[$kind] ?? []); sort($ids);
        $out = [];
        foreach (array_slice($ids, 0, $limit) as $id) if (($row = parent::read($kind, (int) $id)) !== null) $out[(int) $id] = $row;
        return $out;
    }
    public function readMany(string $kind, array $ids): array {
        $this->reads['readMany']++;
        $out = [];
        foreach ($ids as $id) if (($row = parent::read($kind, (int) $id)) !== null) $out[(int) $id] = $row;
        return $out;
    }
}

$rowsStore = [];
foreach ([3, 1, 2, 250] as $id) $rowsStore['module'][$id] = ['id' => (string) $id, 'title' => 'm' . $id, 'note' => ''];
$rowsStore['moduleAssignment'][1] = ['menuids' => '[0]'];
$paged = new PagedOnlyWriter(); $paged->store = $rowsStore;
$bulk = new BulkOnlyWriter(); $bulk->store = $rowsStore;
$pr = new ContractRows($paged); $br = new ContractRows($bulk);
check('bulk: a whole kind reads as the paged walk read it, in id order', $br->all('module'), $pr->all('module'));
check('bulk: a whole kind is one call, not a list plus a read per row', $bulk->reads, ['read' => 0, 'list' => 0, 'readAll' => 1, 'readMany' => 0]);
check('bulk: a row of a kind read whole comes from memory', [$br->row('module', 250), $bulk->reads['read']], [$paged->read('module', 250), 0]);
check('bulk: an id outside the kind answers null, as read() does', [$br->row('module', 9), $br->row('module', 0)], [null, null]);
check('bulk: summaries name each row\'s id, as a list row does', array_column($br->summaries('module'), 'id'), ['1', '2', '3', '250']);
$br->prefetch('moduleAssignment', [1, 2]);
check('bulk: prefetched ids come from memory, a missing one as null', [$br->row('moduleAssignment', 1), $br->row('moduleAssignment', 2), $bulk->reads['readMany'], $bulk->reads['read']], [['menuids' => '[0]'], null, 1, 0]);
$br->prefetch('moduleAssignment', [1, 2]);
check('bulk: ids already held are not fetched again', $bulk->reads['readMany'], 1);
check('bulk: an id never prefetched still goes to read()', [$br->row('moduleAssignment', 7), $bulk->reads['read']], [null, 1]);
$bulk->store['module'][2]['title'] = 'changed';
check('bulk: rows are held for one call only — a new call sees a write', (new ContractRows($bulk))->row('module', 2)['title'] ?? null, 'changed');
check('paged: without BulkSiteReader, prefetch asks nothing and row() is read()', (function () use ($paged, $pr) { $pr->prefetch('module', [1]); return $pr->row('module', 1); })(), $paged->read('module', 1));

// The ceiling the paged walk enforced — 20,000 rows or more is refused — holds in both.
foreach (['paged' => new PagedOnlyWriter(), 'bulk' => new BulkOnlyWriter()] as $mode => $big) {
    for ($id = 1; $id <= 19999; $id++) $big->store['article'][$id] = ['id' => (string) $id];
    check("$mode: 19,999 rows are an inventory", count((new ContractRows($big))->all('article')), 19999);
    $big->store['article'][20000] = ['id' => '20000'];
    try { (new ContractRows($big))->all('article'); $refused = 'read'; } catch (RuntimeException $e) { $refused = $e->getMessage(); }
    check("$mode: 20,000 rows exceed the inventory limit", $refused, 'Inventory limit exceeded');
}

// The real profile, both ways: the same inspect, the same mapping, the same apply — and the bulk
// one asks for each kind once.
$rowsSource = __DIR__ . '/../lib/contracts/tracy-business/j6/1.2.0';
$rowsDir = gateContractCopy($rowsSource);
$rowsStoreB = new GateContractStore(); $rowsLog = new FakeApplyLog();
$rowsSite = gateSite($rowsDir, $rowsStoreB, $rowsLog);
$bw = new BulkOnlyWriter(); $bw->store = $rowsSite->store;
$pw = new PagedOnlyWriter(); $pw->store = $rowsSite->store;
$bc = new QuickstartContract($bw, $rowsStoreB, $rowsDir, $rowsDir);
$pc = new QuickstartContract($pw, $rowsStoreB, $rowsDir, $rowsDir);
$unbound = [$bc->inspect(), $pc->inspect()];
check('tracy-business 1.2.0: an unbound inspect answers the same in bulk as paged', $unbound[0], $unbound[1]);
$bc->bind($unbound[0]['snapshot']);
$bw->reads = ['read' => 0, 'list' => 0, 'readAll' => 0, 'readMany' => 0];
$bound = [$bc->inspect(), $pc->inspect()];
check('tracy-business 1.2.0: a bound inspect answers the same in bulk as paged', $bound[0], $bound[1]);
check('tracy-business 1.2.0: a bound inspect reads each kind once and every assignment at once',
    $bw->reads, ['read' => 0, 'list' => 0, 'readAll' => count($unbound[0]['snapshot']['counts']), 'readMany' => 1]);
check('tracy-business 1.2.0: the mapping answers the same in bulk as paged', $bc->readMapping(), $pc->readMapping());
$bw->reads = ['read' => 0, 'list' => 0, 'readAll' => 0, 'readMany' => 0];
$bc->readMapping();
checkTrue('tracy-business 1.2.0: the mapping reads bound rows per kind, not per row', $bw->reads['read'] === 0 && $bw->reads['list'] === 0 && $bw->reads['readMany'] <= 5);
$textSlot = null;
foreach ($bound[0]['slots'] as $slot) if ($slot['type'] === 'text' && empty($slot['requiresEvidence']) && trim($slot['current']) !== '' && mb_strlen($slot['current']) + 2 <= $slot['maxCharacters']) { $textSlot = $slot; break; }
$planArgs = ['expected_revision' => $bound[0]['revision'], 'changes' => [$textSlot['key'] => $textSlot['current'] . ' !']];
check('tracy-business 1.2.0: an apply plans the same operations in bulk as paged', $bc->plan($planArgs), $pc->plan($planArgs));
try { $bc->plan(['expected_revision' => str_repeat('0', 64)] + $planArgs); $stale = 'planned'; } catch (RuntimeException $e) { $stale = $e->getMessage(); }
check('tracy-business 1.2.0: a stale revision is still refused in bulk', $stale, 'Content changed; inspect again');
foreach ($bc->plan($planArgs)['operations'] as $op) $bw->store[$op['kind']][$op['id']] = array_merge($bw->store[$op['kind']][$op['id']], $op['fields']);
$after = $bc->inspect();
check('tracy-business 1.2.0: the next inspect in the same request sees the write', [$after['revision'] !== $bound[0]['revision'], $after['slotValues'][$textSlot['key']]], [true, $textSlot['current'] . ' !']);
