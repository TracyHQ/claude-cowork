<?php
// Loaded by run.php after contract-ops-locks.php (the gate's site builder and its doubles) and
// content-writer-creates.php (its Joomla\CMS\Factory stand-in). multilingual.retire and
// multilingual.restore write in bulk since 05/10/2026: a chunk's undo entries in one recordMany(),
// then one setVisibilityMany() per kind, where the pass used to write one undo row and one or two
// UPDATEs per row (~7.6 ms a row, 6,684 rows on Tracy Business). What is held here:
//
//   1. on the Business archive, the rows hidden and the undo entries are exactly what the per-row
//      pass wrote: MultilingualApply::retireWrites, in its order, one `visibility` entry per row;
//   2. written in bulk: one recordMany() per call, one setVisibilityMany() per kind per call, and its
//      rows read in bulk too (ContractRows, as an inspect reads them), never through list();
//   3. a row open in the editor refuses the pass before anything is recorded or written;
//   4. a row the site refuses mid-pass takes the whole call back, its undo entries included;
//   5. restore shows every row again, in bulk, and a row recorded twice is left with its OLDEST
//      `before` — what replaying the log newest-first one row at a time leaves;
//   6. the real JoomlaSiteWriter and JoomlaApplyLog statements over SQLite: the menu scope, the
//      `#__ucm_content` copy, batches of 500, all or nothing, and the undo rows in seq order.

namespace Joomla\CMS {
    if (!class_exists(Factory::class)) {
        /** The same stand-in content-writer-creates.php declares, should this file ever load first. */
        final class Factory
        {
            public static function getDate(): object
            {
                return new class { public function toSql(): string { return '2026-09-29 10:00:00'; } };
            }
            public static function getApplication(): object
            {
                return new class { public function getIdentity(): object { return (object) ['id' => 42]; } };
            }
        }
    }
}

namespace Joomla\Database {
    if (!interface_exists(DatabaseInterface::class)) {
        interface DatabaseInterface {}
    }
    if (!interface_exists(QueryInterface::class)) {
        interface QueryInterface {}
    }
    if (!class_exists(ParameterType::class)) {
        final class ParameterType
        {
            public const BOOLEAN = 'boolean';
            public const INTEGER = 'int';
            public const STRING = 'string';
        }
    }
}

namespace {
    if (!defined('_JEXEC')) {
        define('_JEXEC', 1);
    }
    require_once __DIR__ . '/../com_claudecowork/site/src/Controller/JoomlaSiteWriter.php';
    require_once __DIR__ . '/../com_claudecowork/site/src/Controller/JoomlaApplyLog.php';

    use Tracy\Component\ClaudeCowork\Site\Controller\JoomlaApplyLog;
    use Tracy\Component\ClaudeCowork\Site\Controller\JoomlaSiteWriter;

    echo "\nmultilingual.retire and .restore, in bulk\n";

    // ========================================================= 1-5. the engine, on the Business archive
    // The archive as a site is ~80 MB of arrays, and the suite has used most of its memory by here: the
    // limit is raised while this runs, and everything it built is local to the function below, so it is
    // all given back when the function returns.
    $rbLimit = ini_get('memory_limit');
    if ($rbLimit !== '-1') checkTrue('bulk: (the memory limit is raised for the Business archive)', ini_set('memory_limit', '1G') !== false);
    (function () use ($WTOKEN): void {
        $rbDir = gateContractCopy(__DIR__ . '/../lib/contracts/tracy-business/j6/1.2.0');
        $rbStore = new GateContractStore();
        $rbLog = new FakeApplyLog();
        $rbSite = gateSite($rbDir, $rbStore, $rbLog);
        $rbContract = new QuickstartContract($rbSite, $rbStore, $rbDir, $rbDir);
        $rbHeld = [];
        $rbEngine = (new Engine($WTOKEN, ['joomla' => '6.1.1'], null, null, null, new FakeExtensions(), $rbSite, null, $rbLog, null, null, null, $rbContract))
            ->locks(function (array $targets) use (&$rbHeld): array {
                $out = [];
                foreach ($targets as [$kind, $id]) if (isset($rbHeld[$kind . ':' . $id])) $out[$kind . ':' . $id] = $rbHeld[$kind . ':' . $id];
                return $out;
            });
        $rbCall = fn (array $params) => $rbEngine->handle(['token' => $WTOKEN, 'action' => 'content.contract', 'params' => $params]);
        $rbState = fn () => [$rbSite->store, $rbLog->log, $rbStore->binding, $rbStore->job];
        // One sequence for the writer's and the log's calls, to see which came first.
        $rbTrace = [];
        $rbSite->trace = &$rbTrace;
        $rbLog->trace = &$rbTrace;
        /** Whether a call recorded its undo entries once, first, and only then wrote rows (in bulk). */
        $rbUndoFirst = function (array $trace): bool {
            $ops = array_column($trace, 0);
            return ($ops[0] ?? null) === 'recordMany' && count(array_keys($ops, 'recordMany', true)) === 1 && in_array('setVisibilityMany', $ops, true);
        };
        /** setVisibilityMany() calls as a set: by kind and value, each call's ids sorted (an IN list has no order). */
        $rbAsSet = function (array $calls): array {
            foreach ($calls as $i => $call) sort($calls[$i][1]);
            usort($calls, fn (array $a, array $b): int => [$a[0], $a[3]] <=> [$b[0], $b[3]]);
            return $calls;
        };
        check('bulk: the Business archive binds', $rbCall(['operation' => 'bind'])['ok'] ?? null, true);

        // What the per-row pass wrote, from the same rows and the same rule: the rows retireWrites names,
        // in its order, each recorded as a `visibility` undo before it was hidden.
        $rbKeep = ['en-GB'];
        $rbRows = [];
        foreach (['language', 'article', 'menuItem', 'module'] as $rbKind) $rbRows[$rbKind] = $rbSite->list($rbKind, 0, 100000);
        $rbSpared = array_values(array_intersect($rbContract->profile()->editionLocales(), $rbKeep));
        $rbRouted = array_values(array_intersect($rbContract->derivedLanguages(), $rbKeep));
        $rbWrites = MultilingualApply::retireWrites($rbRows, $rbContract->governedIds(), $rbContract->profile()->sourceLanguage(),
            array_values(array_unique(array_merge($rbRouted, $rbSpared))), $rbSpared);
        $rbEntries = array_map(fn (array $w): array => ['op' => 'visibility', 'kind' => $w[0], 'id' => $w[1], 'column' => (string) array_key_first($w[2]), 'before' => 1], $rbWrites);
        $rbChunks = array_chunk($rbWrites, 3000);
        checkTrue('bulk: (the archive has more to hide than one call takes, so chunking is exercised)', count($rbChunks) >= 2);

        // 3. One row open in the editor: refused before the first undo entry, the first bulk call, anything.
        $rbOpen = null;
        foreach ($rbChunks[0] as [$kind, $id]) if ($kind === 'article') { $rbOpen = $id; break; }
        $rbHeld = ['article:' . $rbOpen => ['kind' => 'admin-user', 'name' => 'Jane Admin', 'since' => '2026-10-05T10:00:00Z', 'until' => null]];
        $rbBefore = $rbState();
        $rbTrace = [];
        $rbR = $rbCall(['operation' => 'multilingual.retire', 'keep' => $rbKeep, 'apply_id' => 'mlang-bulk']);
        check('bulk: a row open in the editor refuses the pass', [$rbR['ok'] ?? null, $rbR['errors'][0]['code'] ?? null, $rbR['errors'][0]['record'] ?? null],
            [false, 'SLOT_LOCKED_BY_USER', ['kind' => 'article', 'id' => $rbOpen]]);
        check('bulk: and nothing is recorded or written, not even in bulk', [$rbState(), $rbTrace], [$rbBefore, []]);
        $rbHeld = [];

        // 4. A row the site refuses in the middle of a call: the undo entries were written first (one
        // recordMany), the rows before it were hidden, and the transaction takes all of it back.
        $rbLastBulk = null;
        foreach ($rbChunks[0] as $w) if ($w[0] !== 'language') $rbLastBulk = $w;
        $rbSite->failOn = [$rbLastBulk[0], $rbLastBulk[1]];
        $rbBefore = $rbState();
        $rbLog->many = []; $rbTrace = [];
        $rbR = $rbCall(['operation' => 'multilingual.retire', 'keep' => $rbKeep, 'apply_id' => 'mlang-bulk']);
        $rbSite->failOn = null;
        check('bulk: a row the site refuses fails the call', [$rbR['ok'] ?? null, str_contains((string) ($rbR['message'] ?? ''), 'The site refused')], [false, true]);
        check('bulk: (its undo entries were written, all at once, before any row moved)', [$rbLog->many, $rbUndoFirst($rbTrace)], [[count($rbChunks[0])], true]);
        check('bulk: and the call is taken back whole, undo entries and hidden rows', $rbState(), $rbBefore);

        // 1-2. The pass, call after call until it completes.
        $rbBeforeStore = $rbSite->store;
        $rbAnswers = []; $rbBulkPerCall = []; $rbManyPerCall = []; $rbUndoFirstPerCall = [];
        $rbBulkReads = $rbSite instanceof BulkSiteReader;
        if ($rbBulkReads) $rbSite->reads = ['read' => 0, 'list' => 0, 'readAll' => 0, 'readMany' => 0];
        for ($i = 0, $rbR = ['status' => 'running']; $i < 20 && ($rbR['status'] ?? '') === 'running'; $i++) {
            $rbSite->bulkVisibility = []; $rbLog->many = []; $rbTrace = [];
            $rbR = $rbCall(['operation' => 'multilingual.retire', 'keep' => $rbKeep, 'apply_id' => 'mlang-bulk']);
            $rbAnswers[] = [$rbR['ok'] ?? null, $rbR['status'] ?? $rbR['message'] ?? null];
            $rbUndoFirstPerCall[] = $rbUndoFirst($rbTrace);
            $rbBulkPerCall[] = $rbSite->bulkVisibility;
            $rbManyPerCall[] = $rbLog->many;
        }
        // list() asks Joomla's router, author, tags and access level of every article; the pass needs none of it.
        if ($rbBulkReads) check('bulk: each call reads its rows whole, one readAll() per kind, and never through list()',
            [$rbSite->reads['list'], $rbSite->reads['readAll']], [0, 4 * count($rbChunks)]);
        check('bulk: the pass takes one call per chunk of 3,000 and completes',
            $rbAnswers, array_merge(array_fill(0, count($rbChunks) - 1, [true, 'running']), [[true, 'completed']]));
        check('bulk: the undo entries are the per-row pass\'s, entry for entry and in its order', $rbLog->log['mlang-bulk'] ?? null, $rbEntries);
        check('bulk: one recordMany() per call, carrying that call\'s chunk', $rbManyPerCall, array_map(fn (array $chunk): array => [count($chunk)], $rbChunks));
        check('bulk: in every call the chunk\'s undo entries are recorded before any row is written', $rbUndoFirstPerCall, array_fill(0, count($rbChunks), true));
        $rbWant = [];
        foreach ($rbChunks as $chunk) {
            $calls = [];
            foreach ($chunk as [$kind, $id, $fields]) if ($kind !== 'language') {
                $calls[$kind] ??= [$kind, [], (string) array_key_first($fields), '0'];
                $calls[$kind][1][] = $id;
            }
            $rbWant[] = $rbAsSet(array_values($calls));
        }
        check('bulk: one setVisibilityMany() per kind per call, holding that kind\'s rows of the chunk; languages one at a time',
            array_map($rbAsSet, $rbBulkPerCall), $rbWant);
        $rbHidden = $rbBeforeStore;
        foreach ($rbWrites as [$kind, $id, $fields]) {
            $column = (string) array_key_first($fields);
            // A language goes through write(), which keeps the integer it is given; setVisibility() a string.
            $rbHidden[$kind][$id][$column] = $kind === 'language' ? 0 : '0';
        }
        check('bulk: exactly the rows the per-row pass hid are hidden, and nothing else moved', $rbSite->store, $rbHidden);
        check('bulk: a second pass finds nothing left to hide', [$rbCall(['operation' => 'multilingual.retire', 'keep' => $rbKeep, 'apply_id' => 'mlang-bulk-again'])['hidden'] ?? null, isset($rbLog->log['mlang-bulk-again'])], [[], false]);

        // 5. Restore, in bulk, to what the rows were.
        $rbSite->bulkVisibility = []; $rbLog->many = [];
        $rbR = $rbCall(['operation' => 'multilingual.restore', 'apply_id' => 'mlang-bulk']);
        check('bulk: restore answers every row it showed', [$rbR['ok'] ?? null, $rbR['restored'] ?? null], [true, count($rbWrites)]);
        $rbShown = $rbBeforeStore;
        foreach ($rbWrites as [$kind, $id, $fields]) $rbShown[$kind][$id][(string) array_key_first($fields)] = $kind === 'language' ? 1 : '1';
        check('bulk: every hidden row is shown again, and nothing else moved', $rbSite->store, $rbShown);
        check('bulk: the pass is forgotten once restored', isset($rbLog->log['mlang-bulk']), false);
        $rbKinds = array_values(array_unique(array_filter(array_column($rbWrites, 0), fn ($k) => $k !== 'language')));
        $rbRestoreKinds = array_column($rbSite->bulkVisibility, 0);
        sort($rbRestoreKinds); sort($rbKinds);
        check('bulk: restore writes one setVisibilityMany() per kind', $rbRestoreKinds, $rbKinds);

        // A log that names one row twice: replayed newest first, the oldest `before` is what stands.
        [$rbA, $rbB] = array_slice(array_keys(array_filter($rbSite->store['article'], fn ($row) => ($row['language'] ?? '') === 'de-DE')), 0, 2);
        $rbSite->store['article'][$rbA]['state'] = '1';
        $rbSite->store['article'][$rbB]['state'] = '0';
        $rbSite->store['language'][2]['published'] = 0;
        foreach ([['article', $rbA, 'state', 0], ['article', $rbB, 'state', 1], ['language', 2, 'published', 1],
                  ['article', $rbA, 'state', 1], ['article', $rbB, 'state', 0], ['language', 2, 'published', 0]] as [$kind, $id, $column, $before])
            $rbLog->record('mlang-bulk-twice', ['op' => 'visibility', 'kind' => $kind, 'id' => $id, 'column' => $column, 'before' => $before]);
        $rbSite->bulkVisibility = [];
        $rbR = $rbCall(['operation' => 'multilingual.restore', 'apply_id' => 'mlang-bulk-twice']);
        check('bulk: a row recorded twice is left with its oldest before, as the newest-first replay leaves it',
            [$rbR['ok'] ?? null, $rbSite->store['article'][$rbA]['state'], $rbSite->store['article'][$rbB]['state'], $rbSite->store['language'][2]['published']],
            [true, '0', '1', 1]);
        check('bulk: and each such row is written once', $rbAsSet($rbSite->bulkVisibility), $rbAsSet([['article', [$rbB], 'state', '1'], ['article', [$rbA], 'state', '0']]));

        // A row the restore names that is gone: refused whole, as setVisibility() refused it, the log kept.
        $rbLog->record('mlang-bulk-gone', ['op' => 'visibility', 'kind' => 'article', 'id' => $rbA, 'column' => 'state', 'before' => 1]);
        $rbLog->record('mlang-bulk-gone', ['op' => 'visibility', 'kind' => 'article', 'id' => 987654321, 'column' => 'state', 'before' => 1]);
        $rbBefore = $rbState();
        $rbR = $rbCall(['operation' => 'multilingual.restore', 'apply_id' => 'mlang-bulk-gone']);
        check('bulk: a restore naming a row that is gone is refused, nothing shown and the log kept',
            [$rbR['ok'] ?? null, str_contains((string) ($rbR['message'] ?? ''), 'target does not exist in this scope'), $rbState()], [false, true, $rbBefore]);

    })();
    gc_collect_cycles();
    gc_mem_caches();
    // Given back, or said: a suite left at 1G would hide the next file's appetite.
    checkTrue('bulk: (and set back to ' . $rbLimit . ' once the archive is gone)', ini_set('memory_limit', $rbLimit) !== false);

    // ============================================ 6. the real writer and log, over SQLite
    /** Joomla's query builder as far as setVisibilityMany() and JoomlaApplyLog use it. */
    final class RbQuery implements \Joomla\Database\QueryInterface
    {
        private string $verb = 'SELECT';
        private string $table = '';
        private array $select = [];
        private array $where = [];
        private array $set = [];
        private array $columns = [];
        private array $values = [];
        private array $order = [];
        public array $bounded = [];

        public function select($columns): self { $this->select = array_merge($this->select, (array) $columns); return $this; }
        public function from($table): self { $this->table = $table; return $this; }
        public function update($table): self { $this->verb = 'UPDATE'; $this->table = $table; return $this; }
        public function insert($table): self { $this->verb = 'INSERT'; $this->table = $table; return $this; }
        public function set($condition): self { $this->set[] = $condition; return $this; }
        public function columns($columns): self { $this->columns = (array) $columns; return $this; }
        public function values($values): self { $this->values[] = $values; return $this; }
        public function where($conditions): self { $this->where[] = $conditions; return $this; }
        public function order($columns): self { $this->order[] = $columns; return $this; }
        public function bind($key, &$value, $type = 'string'): self { $this->bounded[$key] = $value; return $this; }

        public function __toString(): string
        {
            $where = $this->where ? ' WHERE ' . implode(' AND ', $this->where) : '';
            if ($this->verb === 'UPDATE') return 'UPDATE ' . $this->table . ' SET ' . implode(', ', $this->set) . $where;
            if ($this->verb === 'INSERT') return 'INSERT INTO ' . $this->table . ' (' . implode(',', $this->columns) . ') VALUES (' . implode('),(', $this->values) . ')';
            return 'SELECT ' . implode(',', $this->select) . ' FROM ' . $this->table . $where . ($this->order ? ' ORDER BY ' . implode(', ', $this->order) : '');
        }
    }

    /** A driver over SQLite that runs what it is handed, `#__` read as `j_`, and keeps every statement. */
    final class RbDb implements \Joomla\Database\DatabaseInterface
    {
        public PDO $pdo;
        /** @var string[] */
        public array $ran = [];
        private $pending = '';

        public function __construct()
        {
            $this->pdo = new PDO('sqlite::memory:');
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }
        public function getQuery($new = false): RbQuery { return new RbQuery(); }
        public function quoteName($name, $as = null)
        {
            if (is_array($name)) return array_map(fn ($n) => $this->quoteName($n), $name);
            return implode('.', array_map(fn (string $part): string => '`' . $part . '`', explode('.', $name)));
        }
        public function quote($text): string { return $this->pdo->quote((string) $text); }
        public function setQuery($query, $offset = 0, $limit = 0): self { $this->pending = $query; return $this; }
        public function loadColumn(): array { return array_map(fn (array $row) => reset($row), $this->run()->fetchAll(PDO::FETCH_ASSOC)); }
        public function loadResult()
        {
            $row = $this->run()->fetch(PDO::FETCH_NUM);
            return $row === false ? null : $row[0];
        }
        public function execute(): bool { $this->run(); return true; }
        public function insertObject(string $table, object $object, $key = null): bool
        {
            $columns = array_keys(get_object_vars($object));
            $this->setQuery('INSERT INTO ' . $this->quoteName($table) . ' (' . implode(',', $this->quoteName($columns)) . ') VALUES ('
                . implode(',', array_map(fn ($v) => $this->quote($v), array_values(get_object_vars($object)))) . ')')->execute();
            return true;
        }
        /** Statements run since the last call, by their first word. */
        public function verbs(): array
        {
            $out = array_map(fn (string $sql): string => strtok($sql, ' '), $this->ran);
            $this->ran = [];
            return $out;
        }
        private function run(): PDOStatement
        {
            $sql = str_replace('#__', 'j_', (string) $this->pending);
            $this->ran[] = $sql;
            $statement = $this->pdo->prepare($sql);
            if ($this->pending instanceof RbQuery) foreach ($this->pending->bounded as $key => $value) $statement->bindValue($key, $value);
            $statement->execute();
            return $statement;
        }
    }

    checkTrue('sql: pdo_sqlite is here to run the statements', extension_loaded('pdo_sqlite'));
    if (extension_loaded('pdo_sqlite')) {
        $rbDb = new RbDb();
        $rbDb->pdo->exec('CREATE TABLE j_content (id INTEGER PRIMARY KEY, state INTEGER)');
        $rbDb->pdo->exec('CREATE TABLE j_menu (id INTEGER PRIMARY KEY, published INTEGER, client_id INTEGER)');
        $rbDb->pdo->exec('CREATE TABLE j_modules (id INTEGER PRIMARY KEY, published INTEGER)');
        $rbDb->pdo->exec('CREATE TABLE j_ucm_content (core_content_id INTEGER PRIMARY KEY, core_type_alias TEXT, core_content_item_id INTEGER, core_state INTEGER)');
        $rbDb->pdo->exec('CREATE TABLE j_claudecowork_apply_log (id INTEGER PRIMARY KEY AUTOINCREMENT, apply_id TEXT, seq INTEGER, entry TEXT, created TEXT)');
        for ($i = 1; $i <= 1200; $i++) {
            $rbDb->pdo->exec("INSERT INTO j_content VALUES ($i, 1)");
            // Item 1100 is an administrator menu item: outside the scope read() applies to a menuItem.
            $rbDb->pdo->exec("INSERT INTO j_menu VALUES ($i, 1, " . ($i === 1100 ? 1 : 0) . ')');
            $rbDb->pdo->exec("INSERT INTO j_modules VALUES ($i, 1)");
        }
        // Tag-index copies: of articles 5 and 700, and of a contact whose item id is also 5.
        $rbDb->pdo->exec("INSERT INTO j_ucm_content VALUES (1, 'com_content.article', 5, 1), (2, 'com_content.article', 700, 1), (3, 'com_contact.contact', 5, 1)");
        $rbVisible = fn (string $table, string $column) => array_map('intval', $rbDb->pdo->query("SELECT $column FROM $table ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN));
        $rbHiddenIds = fn (string $table, string $column) => array_map('intval', $rbDb->pdo->query("SELECT id FROM $table WHERE $column = 0 ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
        $rbUcm = fn () => array_map('intval', $rbDb->pdo->query('SELECT core_state FROM j_ucm_content ORDER BY core_content_id')->fetchAll(PDO::FETCH_COLUMN));
        $rbW = new JoomlaSiteWriter($rbDb);

        // Every id found under the scope before any row moves: an admin menu item in the LAST batch
        // refuses the whole call, and the two batches before it are not written either.
        $rbDb->verbs();
        $rbThrown = null;
        try { $rbW->setVisibilityMany('menuItem', range(1, 1150), 'published', '0'); } catch (RuntimeException $e) { $rbThrown = $e->getMessage(); }
        check('sql: a menu item outside the site menu refuses the call, in setVisibility()\'s words', $rbThrown, 'target does not exist in this scope');
        check('sql: and no row is written, not even in the batches before it', [$rbHiddenIds('j_menu', 'published'), $rbDb->verbs()], [[], ['SELECT', 'SELECT', 'SELECT']]);
        $rbThrown = null;
        try { $rbW->setVisibilityMany('module', [3, 0], 'published', '0'); } catch (RuntimeException $e) { $rbThrown = $e->getMessage(); }
        check('sql: an id of 0 is refused before any statement', [$rbThrown, $rbDb->verbs()], ['target does not exist in this scope', []]);
        $rbThrown = null;
        try { $rbW->setVisibilityMany('module', [3, 4999], 'published', '0'); } catch (RuntimeException $e) { $rbThrown = $e->getMessage(); }
        check('sql: a row that is not there refuses the call', [$rbThrown, $rbHiddenIds('j_modules', 'published')], ['target does not exist in this scope', []]);
        $rbThrown = null;
        try { $rbW->setVisibilityMany('module', [3], 'state', '0'); } catch (RuntimeException $e) { $rbThrown = $e->getMessage(); }
        check('sql: only a kind\'s own visibility column is written', $rbThrown, 'state is not the visibility column of module');

        // In scope: batches of 500 ids, a repeated id written once, nothing else touched.
        $rbDb->verbs();
        $rbIds = array_merge(range(1, 1099), range(1101, 1150), [7, 7]);
        $rbW->setVisibilityMany('menuItem', $rbIds, 'published', '0');
        check('sql: every named site menu item is hidden, and no other row', $rbHiddenIds('j_menu', 'published'), array_merge(range(1, 1099), range(1101, 1150)));
        check('sql: 1,149 ids in three batches: three reads, then three writes', $rbDb->verbs(), ['SELECT', 'SELECT', 'SELECT', 'UPDATE', 'UPDATE', 'UPDATE']);
        $rbW->setVisibilityMany('menuItem', range(1, 1099), 'published', '1');
        check('sql: and shown again through the same column', $rbHiddenIds('j_menu', 'published'), range(1101, 1150));

        // An article's state lives twice: the tag index's copy moves with it, and only an article's.
        $rbW->setVisibilityMany('article', range(1, 600), 'state', '0');
        check('sql: articles hidden', $rbHiddenIds('j_content', 'state'), range(1, 600));
        check('sql: the tag index copy of a hidden article follows it, an article outside the call and a contact do not', $rbUcm(), [0, 1, 1]);
        $rbW->setVisibility('article', 700, 'state', '0');
        check('sql: setVisibility() is the same write for one row', [$rbHiddenIds('j_content', 'state'), $rbUcm()], [array_merge(range(1, 600), [700]), [0, 0, 1]]);
        $rbW->setVisibilityMany('article', array_merge(range(1, 600), [700]), 'state', '1');
        check('sql: and an article shown again shows in the tag index too', [$rbHiddenIds('j_content', 'state'), $rbUcm()], [[], [1, 1, 1]]);
        $rbDb->verbs();
        $rbW->setVisibilityMany('module', [], 'published', '0');
        check('sql: no ids, no statement', $rbDb->verbs(), []);

        // The undo log: the rows record() writes, in seq order, in a few INSERTs.
        $rbL = new JoomlaApplyLog($rbDb);
        $rbL->record('mlang-sql', ['op' => 'visibility', 'kind' => 'language', 'id' => 2, 'column' => 'published', 'before' => 1]);
        $rbMany = [];
        for ($i = 1; $i <= 1200; $i++) $rbMany[] = ['op' => 'visibility', 'kind' => 'module', 'id' => $i, 'column' => 'published', 'before' => 1];
        $rbDb->verbs();
        $rbL->recordMany('mlang-sql', $rbMany);
        check('sql: 1,200 undo entries are one sequence read and three INSERTs', $rbDb->verbs(), ['SELECT', 'INSERT', 'INSERT', 'INSERT']);
        check('sql: entries() reads them back after the one record() wrote, unchanged and in order', $rbL->entries('mlang-sql'),
            array_merge([['op' => 'visibility', 'kind' => 'language', 'id' => 2, 'column' => 'published', 'before' => 1]], $rbMany));
        check('sql: their seq follows on from it, one apart', array_map('intval', $rbDb->pdo->query("SELECT seq FROM j_claudecowork_apply_log WHERE apply_id = 'mlang-sql' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN)), range(1, 1201));
        check('sql: each row stored as record() stores it: base64 of the serialized entry',
            $rbDb->pdo->query("SELECT entry FROM j_claudecowork_apply_log WHERE apply_id = 'mlang-sql' AND seq = 2")->fetchColumn(), base64_encode(serialize($rbMany[0])));
        check('sql: and stamped with the same clock', $rbDb->pdo->query("SELECT DISTINCT created FROM j_claudecowork_apply_log WHERE apply_id = 'mlang-sql'")->fetchAll(PDO::FETCH_COLUMN), ['2026-09-29 10:00:00']);
        // Large entries go in statements of at most 256 KB; one larger than that goes alone.
        $rbBig = [];
        foreach ([90000, 90000, 300000, 10] as $i => $bytes) $rbBig[] = ['op' => 'media', 'path' => 'images/' . $i . '.bin', 'before' => str_repeat(chr(65 + $i), $bytes)];
        $rbDb->verbs();
        $rbL->recordMany('apply-big', $rbBig);
        check('sql: large entries split by size, a 300 KB one on its own', $rbDb->verbs(), ['SELECT', 'INSERT', 'INSERT', 'INSERT']);
        check('sql: and read back byte for byte', $rbL->entries('apply-big'), $rbBig);
        $rbDb->verbs();
        $rbL->recordMany('apply-none', []);
        check('sql: no entries, no statement', $rbDb->verbs(), []);
    }
}
