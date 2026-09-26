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
check('install creates every trigger the database lacks', count($created), 6);
check('the delete trigger removes exactly that row of the identity table',
    $created[1], "CREATE TRIGGER `ng_cc_content_article_delete` AFTER DELETE ON #__content FOR EACH ROW DELETE FROM #__claudecowork_content_identity WHERE kind='article' AND native_id=OLD.id");
$sweeps = array_values(array_filter($freshDb->queries, fn($q) => startsWith($q, 'DELETE ci FROM')));
check('after backfilling, install sweeps the identities whose native row is gone (one per kind)', count($sweeps), 3);
check('the sweep joins the identity table to its native table and keeps only orphans',
    $sweeps[1], "DELETE ci FROM #__claudecowork_content_identity ci LEFT JOIN #__menu t ON t.id=ci.native_id WHERE ci.kind='page' AND t.id IS NULL");
$order = array_values(array_filter($freshDb->queries, fn($q) => startsWith($q, 'CREATE TRIGGER') || startsWith($q, 'INSERT IGNORE INTO #__claudecowork_content_identity') || startsWith($q, 'DELETE ci FROM')));
check('per kind the order is: triggers, then backfill, then sweep — so nothing inserted meanwhile is missed',
    array_map(fn($q) => substr($q, 0, 6), array_slice($order, 0, 4)), ['CREATE', 'CREATE', 'INSERT', 'DELETE']);

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
