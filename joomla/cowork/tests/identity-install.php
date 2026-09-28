<?php
// Loaded by run.php. The identity table is kept by six AFTER INSERT/DELETE triggers on #__content,
// #__menu and #__modules. Joomla's own installer deletes the component's admin menu item under
// `LOCK TABLES #__menu WRITE` (Nested::delete) when the component is UPDATED, and a trigger that
// then writes a table outside that lock makes MariaDB refuse the whole install ("Can't update table
// … already used by statement which invoked this trigger"; measured 26/09/2026 on every 0.18-RC
// site, while 0.17.4 → RC, which has no triggers yet, went through). So the install script drops
// the triggers before Joomla touches #__menu and `install()` puts them back afterwards; what these
// checks pin is the SQL each step emits, against a database fake that records it.

function startsWith(string $s, string $p): bool { return strncmp($s, $p, strlen($p)) === 0; }

final class RecordingDb
{
    /** @var string[] */
    public array $queries = [];
    private string $pending = '';
    /** @var array<string, mixed> substring of a query → what loadResult answers for it */
    private array $answers;

    public function __construct(array $answers = []) { $this->answers = $answers; }
    public function getPrefix(): string { return 'ng_'; }
    public function quote($v): string { return "'" . str_replace("'", "''", (string) $v) . "'"; }
    public function quoteName($v): string { return '`' . $v . '`'; }
    public function setQuery($q): self { $this->pending = (string) $q; return $this; }
    public function execute(): bool { $this->queries[] = $this->pending; return true; }
    public function loadResult()
    {
        $this->queries[] = $this->pending;
        foreach ($this->answers as $needle => $answer) if (str_contains($this->pending, $needle)) return $answer;
        return 0;
    }
}

check('the six identity triggers are named from the table prefix, insert and delete per kind',
    ContentIdentity::triggerNames('ng_'),
    ['ng_cc_content_article_insert', 'ng_cc_content_article_delete', 'ng_cc_content_page_insert', 'ng_cc_content_page_delete',
        'ng_cc_content_shared_insert', 'ng_cc_content_shared_delete']);

$dropDb = new RecordingDb();
ContentIdentity::dropTriggers($dropDb);
check('dropping the triggers is one DROP TRIGGER IF EXISTS per trigger and nothing else',
    $dropDb->queries,
    array_map(fn($n) => 'DROP TRIGGER IF EXISTS `' . $n . '`', ContentIdentity::triggerNames('ng_')));

$freshDb = new RecordingDb();
ContentIdentity::install($freshDb);
$created = array_values(array_filter($freshDb->queries, fn($q) => startsWith($q, 'CREATE TRIGGER')));
check('install creates a trigger only for the tables Joomla writes without LOCK TABLES (content, modules)', count($created), 4);
check('no trigger is ever created on #__menu', count(array_filter($created, fn($q) => str_contains($q, 'ON #__menu'))), 0);
check('the reader requires exactly those four',
    ContentIdentity::requiredTriggerNames('ng_'),
    ['ng_cc_content_article_insert', 'ng_cc_content_article_delete', 'ng_cc_content_shared_insert', 'ng_cc_content_shared_delete']);
check('the delete trigger removes exactly that row of the identity table',
    $created[1], "CREATE TRIGGER `ng_cc_content_article_delete` AFTER DELETE ON #__content FOR EACH ROW DELETE FROM #__claudecowork_content_identity WHERE kind='article' AND native_id=OLD.id");
$sweeps = array_values(array_filter($freshDb->queries, fn($q) => startsWith($q, 'DELETE ci FROM')));
check('after backfilling, install sweeps the identities whose native row is gone (one per kind)', count($sweeps), 3);
check('the sweep joins the identity table to its native table and keeps only orphans',
    $sweeps[1], "DELETE ci FROM #__claudecowork_content_identity ci LEFT JOIN #__menu t ON t.id=ci.native_id WHERE ci.kind='page' AND t.id IS NULL");
$order = array_values(array_filter($freshDb->queries, fn($q) => startsWith($q, 'CREATE TRIGGER') || startsWith($q, 'INSERT IGNORE INTO #__claudecowork_content_identity') || startsWith($q, 'DELETE ci FROM')));
check('the order is: every trigger, then per kind backfill then sweep — so nothing inserted meanwhile is missed',
    array_map(fn($q) => substr($q, 0, 6), $order), ['CREATE', 'CREATE', 'CREATE', 'CREATE', 'INSERT', 'DELETE', 'INSERT', 'DELETE', 'INSERT', 'DELETE']);

$pageDb = new RecordingDb();
ContentIdentity::reconcile($pageDb, 'page');
check('reconciling page identities is the backfill and the sweep for #__menu, nothing else',
    $pageDb->queries,
    ["INSERT IGNORE INTO #__claudecowork_content_identity(kind,native_id,uid) SELECT 'page',id,REPLACE(UUID(),'-','') FROM #__menu",
        "DELETE ci FROM #__claudecowork_content_identity ci LEFT JOIN #__menu t ON t.id=ci.native_id WHERE ci.kind='page' AND t.id IS NULL"]);

$keptDb = new RecordingDb(['information_schema.TRIGGERS' => 1]);
ContentIdentity::install($keptDb);
check('install leaves a trigger that already exists alone',
    count(array_filter($keptDb->queries, fn($q) => startsWith($q, 'CREATE TRIGGER'))), 0);
check('the reader is switched off while triggers are missing and back on at the end',
    [reset($keptDb->queries) !== false && in_array('UPDATE #__claudecowork_content_reader SET enabled=0 WHERE id=1', $keptDb->queries, true), end($keptDb->queries)],
    [true, 'UPDATE #__claudecowork_content_reader SET enabled=1 WHERE id=1']);

check('whether the identity table exists is asked of information_schema, not guessed',
    ContentIdentity::installed(new RecordingDb(['information_schema.TABLES' => 1])), true);
check('a site whose reader was never enabled has no identity table',
    ContentIdentity::installed(new RecordingDb()), false);

// A READ NEVER WRITES WHILE IT HOLDS ITS SNAPSHOT. `reconcile` inside the reader's REPEATABLE READ
// transaction made every content.read an INSERT IGNORE … SELECT (shared locks on every identity
// row, duplicates included) followed by a DELETE (exclusive locks): two overlapping reads locked
// each other, and MariaDB killed one — 17 of 24 parallel reads failed with "Deadlock found when
// trying to get lock" on capijl1644 (27/09/2026, SHOW ENGINE INNODB STATUS names that DELETE).
// `level` looks first with one plain (non-locking) read and writes only when a menu item appeared
// or went since the last read — outside any snapshot, retried when MariaDB picks it as a victim.
$levelDb = new RecordingDb();
check('page identities already level: one plain read and no write', [ContentIdentity::level($levelDb, 'page'), count($levelDb->queries), startsWith($levelDb->queries[0], 'SELECT ')], [false, 1, true]);
check('the look counts menu items without an identity and identities without a menu item',
    $levelDb->queries[0],
    "SELECT (SELECT COUNT(*) FROM #__menu t LEFT JOIN #__claudecowork_content_identity ci ON ci.kind='page' AND ci.native_id=t.id WHERE ci.native_id IS NULL)+(SELECT COUNT(*) FROM #__claudecowork_content_identity ci LEFT JOIN #__menu t ON t.id=ci.native_id WHERE ci.kind='page' AND t.id IS NULL)");
$driftDb = new RecordingDb(['LEFT JOIN #__menu t' => 2]);
check('page identities out of step: the look, then the backfill and the sweep', [ContentIdentity::level($driftDb, 'page'), array_map(fn($q) => substr($q, 0, 6), $driftDb->queries)], [true, ['SELECT', 'INSERT', 'DELETE']]);

final class DeadlockingDb
{
    public array $queries = [];
    private string $pending = '';
    public function __construct(public int $failures, public int $code = 1213) {}
    public function getPrefix(): string { return 'ng_'; }
    public function quote($v): string { return "'" . $v . "'"; }
    public function setQuery($q): self { $this->pending = (string) $q; return $this; }
    public function loadResult() { $this->queries[] = $this->pending; return 1; }
    public function execute(): bool
    {
        $this->queries[] = $this->pending;
        if (str_starts_with($this->pending, 'DELETE') && $this->failures-- > 0) throw new RuntimeException('Deadlock found when trying to get lock', $this->code);
        return true;
    }
}
$victim = new DeadlockingDb(1);
check('a reconcile chosen as a deadlock victim is run again', [ContentIdentity::level($victim, 'page'), count(array_filter($victim->queries, fn($q) => str_starts_with($q, 'DELETE')))], [true, 2]);
$stubborn = new DeadlockingDb(9);
check('but not forever', (function () use ($stubborn) { try { ContentIdentity::level($stubborn, 'page'); return null; } catch (RuntimeException $e) { return $e->getCode(); } })(), 1213);
$inside = new DeadlockingDb(1);
check('inside a caller\'s transaction (tries = 1) the deadlock goes up: InnoDB already rolled that transaction back',
    (function () use ($inside) { try { ContentIdentity::level($inside, 'page', 1); return null; } catch (RuntimeException $e) { return $e->getCode(); } })(), 1213);
$other = new DeadlockingDb(1, 1146);
check('any other failure is not retried', (function () use ($other) { try { ContentIdentity::level($other, 'page'); return null; } catch (RuntimeException $e) { return count(array_filter($other->queries, fn($q) => str_starts_with($q, 'DELETE'))); } })(), 1);

// What the reader checks INSIDE its snapshot instead of writing: every native row it read has an
// identity row it read. A menu item created between `level` and the snapshot fails this, and the
// reader takes a fresh snapshot rather than answer a page with no id.
check('every native row has an identity: level', ContentIdentity::covers([['id' => '1'], ['id' => '7']], [['kind' => 'page', 'native_id' => '7'], ['kind' => 'page', 'native_id' => '1'], ['kind' => 'article', 'native_id' => '9']], 'page'), true);
check('a native row without its identity: not level', ContentIdentity::covers([['id' => '1'], ['id' => '8']], [['kind' => 'page', 'native_id' => '1'], ['kind' => 'article', 'native_id' => '8']], 'page'), false);
check('an identity whose row went is harmless to a read', ContentIdentity::covers([['id' => '1']], [['kind' => 'page', 'native_id' => '1'], ['kind' => 'page', 'native_id' => '5']], 'page'), true);
