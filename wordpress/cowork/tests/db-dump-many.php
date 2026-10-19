<?php
/**
 * db.dump_many: several tables per request, for a host whose firewall counts requests.
 * Required from run.php, whose check() and checkTrue() it uses.
 */

$DMTOKEN = bin2hex(random_bytes(16));

/** A keyed table of `$count` rows, each `$width` bytes of payload; ids zero-padded so string order is key order. */
function dmKeyed(string $name, int $count, int $width = 8): array
{
    $rows = [];
    for ($i = 1; $i <= $count; $i++) {
        $rows[] = [sprintf('%05d', $i), str_repeat(chr(97 + $i % 26), $width)];
    }
    return ['create' => "CREATE TABLE `{$name}` (`id` int, `v` text)", 'rows' => $rows, 'columns' => ['id', 'v'], 'key' => ['id']];
}

/** Every page of every table, one db.dump call each: what db.dump_many has to add up to. */
function dmViaDbDump(Engine $engine, string $token, array $tables, int $limit): array
{
    $sql = '';
    $calls = 0;
    foreach ($tables as $table) {
        $cursor = '';
        do {
            $r = $engine->handle(['token' => $token, 'action' => 'db.dump', 'params' => ['table' => $table, 'cursor' => $cursor, 'limit' => $limit]]);
            $calls++;
            $sql .= base64_decode($r['sql_b64']);
            $cursor = $r['next_cursor'];
        } while (!$r['done']);
    }
    return [$sql, $calls];
}

/** db.dump_many from the start until done: the SQL, the number of calls, and every answer. */
function dmViaMany(Engine $engine, string $token, array $tables, array $params = []): array
{
    $sql = '';
    $answers = [];
    $cursor = null;
    do {
        $r = $engine->handle(['token' => $token, 'action' => 'db.dump_many', 'params' => ['tables' => $tables, 'cursor' => $cursor] + $params]);
        $answers[] = $r;
        if (!$r['ok'] || count($answers) > 1000) {
            break;
        }
        $sql .= base64_decode($r['sql_b64']);
        $cursor = $r['next_cursor'];
    } while (!$r['done']);
    return [$sql, count($answers), $answers];
}

// ---- many small tables, one request ------------------------------------------------------------

$dmSmall = [];
for ($i = 1; $i <= 200; $i++) {
    $name = sprintf('jos_small_%03d', $i);
    $dmSmall[$name] = dmKeyed($name, $i % 4);
}
$dmSmallEngine = new Engine($DMTOKEN, [], new DbDumper(new FakeRowSource($dmSmall)));
$dmSmallNames = array_keys($dmSmall);
[$dmSmallSingle, $dmSmallSingleCalls] = dmViaDbDump($dmSmallEngine, $DMTOKEN, $dmSmallNames, 1000);
$dmOne = $dmSmallEngine->handle(['token' => $DMTOKEN, 'action' => 'db.dump_many', 'params' => ['tables' => $dmSmallNames]]);
check('db.dump_many: 200 small tables answer in one request', [$dmOne['ok'], $dmOne['done'], $dmOne['next_cursor'], $dmOne['stopped'], $dmOne['tables_done']], [true, true, null, 'done', 200]);
check('db.dump_many: the same 200 tables cost db.dump 200 requests', $dmSmallSingleCalls, 200);
check('db.dump_many: one request is byte for byte the 200 db.dump pages', base64_decode($dmOne['sql_b64']), $dmSmallSingle);
check('db.dump_many: rows and bytes count what it sent', [$dmOne['rows'], $dmOne['bytes']], [array_sum(array_map(function ($t) { return count($t['rows']); }, $dmSmall)), strlen($dmSmallSingle)]);
check('db.dump_many: progress names each table it read, in order', array_column($dmOne['progress'], 'table'), $dmSmallNames);
check('db.dump_many: progress carries each table\'s rows and total', $dmOne['progress'][2], ['index' => 2, 'table' => 'jos_small_003', 'rows' => 3, 'total' => 3, 'done' => true]);

// ---- empty tables ------------------------------------------------------------------------------

$dmEmptyEngine = new Engine($DMTOKEN, [], new DbDumper(new FakeRowSource([
    'jos_e1' => ['create' => 'CREATE TABLE `jos_e1` (`id` int)', 'rows' => [], 'columns' => ['id'], 'key' => ['id']],
    'jos_e2' => ['create' => 'CREATE TABLE `jos_e2` (`id` int)', 'rows' => []],
    'jos_e3' => ['create' => 'CREATE TABLE `jos_e3` (`id` int)', 'rows' => [], 'columns' => ['id'], 'key' => ['id']],
])));
$dmEmpty = $dmEmptyEngine->handle(['token' => $DMTOKEN, 'action' => 'db.dump_many', 'params' => ['tables' => ['jos_e1', 'jos_e2', 'jos_e3']]]);
$dmEmptySql = base64_decode($dmEmpty['sql_b64']);
check('db.dump_many: empty tables finish, no rows', [$dmEmpty['done'], $dmEmpty['rows'], $dmEmpty['tables_done']], [true, 0, 3]);
check('db.dump_many: every empty table still gets DROP and CREATE', [substr_count($dmEmptySql, 'DROP TABLE IF EXISTS'), substr_count($dmEmptySql, 'CREATE TABLE'), substr_count($dmEmptySql, 'INSERT')], [3, 3, 0]);
check('db.dump_many: empty tables are the db.dump pages too', $dmEmptySql, dmViaDbDump($dmEmptyEngine, $DMTOKEN, ['jos_e1', 'jos_e2', 'jos_e3'], 1000)[0]);

// ---- a table spanning calls, stopped by bytes -------------------------------------------------

$dmBig = ['jos_a' => dmKeyed('jos_a', 2), 'jos_big' => dmKeyed('jos_big', 50, 400), 'jos_z' => dmKeyed('jos_z', 3)];
$dmBigEngine = new Engine($DMTOKEN, [], new DbDumper(new FakeRowSource($dmBig)));
$dmB1 = $dmBigEngine->handle(['token' => $DMTOKEN, 'action' => 'db.dump_many', 'params' => ['tables' => ['jos_a', 'jos_big', 'jos_z'], 'limit' => 5, 'max_bytes' => 2000]]);
check('db.dump_many: stops at the byte budget, inside the big table', [$dmB1['ok'], $dmB1['done'], $dmB1['stopped'], $dmB1['next_cursor']['table'], $dmB1['tables_done']], [true, false, 'bytes', 1, 1]);
checkTrue('db.dump_many: the byte stop carries the table\'s row cursor', is_string($dmB1['next_cursor']['row']));
checkTrue('db.dump_many: the byte stop came at the first page past the budget', $dmB1['bytes'] >= 2000 && $dmB1['rows'] === 2 + 5);
$dmB2 = $dmBigEngine->handle(['token' => $DMTOKEN, 'action' => 'db.dump_many', 'params' => ['tables' => ['jos_a', 'jos_big', 'jos_z'], 'limit' => 5, 'max_bytes' => 2000, 'cursor' => $dmB1['next_cursor']]]);
checkTrue('db.dump_many: the resumed call carries on without a second DROP', !str_contains(base64_decode($dmB2['sql_b64']), 'DROP TABLE'));
check('db.dump_many: the resumed call reads the next page of the same table', [$dmB2['progress'][0]['table'], $dmB2['progress'][0]['rows']], ['jos_big', 5]);
[$dmBigMany, $dmBigCalls, $dmBigAnswers] = dmViaMany($dmBigEngine, $DMTOKEN, ['jos_a', 'jos_big', 'jos_z'], ['limit' => 5, 'max_bytes' => 2000]);
check('db.dump_many: a table spanning calls adds up to the db.dump pages', $dmBigMany, dmViaDbDump($dmBigEngine, $DMTOKEN, ['jos_a', 'jos_big', 'jos_z'], 5)[0]);
check('db.dump_many: every row arrives once', array_sum(array_column($dmBigAnswers, 'rows')), 55);
check('db.dump_many: the table spanning calls is done on the last call only', array_column($dmBigAnswers, 'done'), array_merge(array_fill(0, $dmBigCalls - 1, false), [true]));

// A page over the budget on its own is sent whole: a call always moves.
$dmHuge = $dmBigEngine->handle(['token' => $DMTOKEN, 'action' => 'db.dump_many', 'params' => ['tables' => ['jos_big', 'jos_z'], 'limit' => 50, 'max_bytes' => 1]]);
check('db.dump_many: a budget under one page (clamped to 1 KiB) ends inside it', [$dmHuge['rows'] > 0 && $dmHuge['rows'] < 50, $dmHuge['bytes'] >= 1024, $dmHuge['stopped']], [true, true, 'bytes']);
$dmCapped = $dmBigEngine->handle(['token' => $DMTOKEN, 'action' => 'db.dump_many', 'params' => ['tables' => ['jos_big'], 'limit' => 999999, 'max_bytes' => PHP_INT_MAX]]);
check('db.dump_many: oversized limit and budget are clamped, not refused', [$dmCapped['ok'], $dmCapped['done'], $dmCapped['rows']], [true, true, 50]);

// ---- stopped by time --------------------------------------------------------------------------

// One-row tables: the clock is read between tables only, and no page is cut, so the SQL stays db.dump's byte for byte.
$dmOnes = [];
for ($i = 1; $i <= 30; $i++) {
    $dmOnes[sprintf('jos_one_%02d', $i)] = dmKeyed(sprintf('jos_one_%02d', $i), 1);
}
$dmOnesNames = array_keys($dmOnes);
$dmTicks = 0.0;
$dmTimeEngine = (new Engine($DMTOKEN, [], new DbDumper(new FakeRowSource($dmOnes))))
    ->dumpClock(function () use (&$dmTicks): float { $t = $dmTicks; $dmTicks += 3.0; return $t; });
$dmT1 = $dmTimeEngine->handle(['token' => $DMTOKEN, 'action' => 'db.dump_many', 'params' => ['tables' => $dmOnesNames, 'max_seconds' => 5]]);
check('db.dump_many: stops on the clock between tables (3 s a reading, 5 s budget: two tables)', [$dmT1['ok'], $dmT1['done'], $dmT1['stopped'], $dmT1['tables_done'], $dmT1['next_cursor']], [true, false, 'time', 2, ['table' => 2, 'row' => null]]);
$dmTicks = 0.0;
$dmT2 = $dmTimeEngine->handle(['token' => $DMTOKEN, 'action' => 'db.dump_many', 'params' => ['tables' => $dmOnesNames, 'max_seconds' => 999]]);
check('db.dump_many: the time budget is clamped to 20 s', [$dmT2['stopped'], $dmT2['tables_done']], ['time', 7]);
$dmTicks = 0.0;
[$dmTimeMany] = dmViaMany($dmTimeEngine, $DMTOKEN, $dmOnesNames, ['max_seconds' => 5]);
check('db.dump_many: calls stopped on time between tables add up to the db.dump pages', $dmTimeMany, dmViaDbDump($dmTimeEngine, $DMTOKEN, $dmOnesNames, 1000)[0]);

// ---- mixed tables, tiny budget: the concatenation is db.dump's --------------------------------

/** Consecutive INSERTs into one table as one: a page cut short is two INSERTs where db.dump has one. */
function dmJoinInserts(string $sql): string
{
    do {
        $sql = preg_replace('/^(INSERT INTO `([^`]+)` VALUES [^\n]*);\nINSERT INTO `\2` VALUES /m', '$1,', $sql, -1, $n);
    } while ($n > 0);
    return $sql;
}

$dmMixed = [
    'jos_k' => dmKeyed('jos_k', 13, 40),
    'jos_exact' => dmKeyed('jos_exact', 8, 40),
    'jos_none' => ['create' => 'CREATE TABLE `jos_none` (`id` int)', 'rows' => []],
    'jos_keyless' => ['create' => 'CREATE TABLE `jos_keyless` (`a` text)', 'rows' => [['x'], ['y'], ['z'], ["o'k"], [null]]],
    'jos_tail' => dmKeyed('jos_tail', 1),
];
$dmMixedEngine = new Engine($DMTOKEN, [], new DbDumper(new FakeRowSource($dmMixed)));
$dmMixedNames = array_keys($dmMixed);
[$dmMixedSingle, $dmMixedSingleCalls] = dmViaDbDump($dmMixedEngine, $DMTOKEN, $dmMixedNames, 4);
foreach ([1024, 300000] as $dmBudget) {
    [$dmMixedMany, $dmMixedCalls] = dmViaMany($dmMixedEngine, $DMTOKEN, $dmMixedNames, ['limit' => 4, 'max_bytes' => $dmBudget]);
    check("db.dump_many: keyed, exact-multiple, empty and keyless tables carry db.dump's rows in db.dump's order (max_bytes {$dmBudget})", dmJoinInserts($dmMixedMany), dmJoinInserts($dmMixedSingle));
    if ($dmBudget === 300000) {
        check('db.dump_many: with no page cut, byte for byte db.dump', $dmMixedMany, $dmMixedSingle);
    }
    checkTrue("db.dump_many: fewer requests than db.dump (max_bytes {$dmBudget}: {$dmMixedCalls} against {$dmMixedSingleCalls})", $dmMixedCalls < $dmMixedSingleCalls);
}

// ---- refusals ---------------------------------------------------------------------------------

$dmAsk = function (Engine $engine, array $params, ?string $token = null) use ($DMTOKEN): array {
    return $engine->handle(['token' => $token ?? $DMTOKEN, 'action' => 'db.dump_many', 'params' => $params]);
};
check('db.dump_many: a wrong token is refused', $dmAsk($dmSmallEngine, ['tables' => ['jos_small_001']], 'nope')['error'], 'unauthorized');
check('db.dump_many: no dumper wired is unavailable', $dmAsk(new Engine($DMTOKEN), ['tables' => ['x']])['error'], 'unavailable');
check('db.dump_many: no tables is bad_params', $dmAsk($dmSmallEngine, [])['error'], 'bad_params');
check('db.dump_many: an empty list is bad_params', $dmAsk($dmSmallEngine, ['tables' => []])['error'], 'bad_params');
check('db.dump_many: tables as a string is bad_params', $dmAsk($dmSmallEngine, ['tables' => 'jos_small_001'])['error'], 'bad_params');
check('db.dump_many: a non-string table is bad_params', $dmAsk($dmSmallEngine, ['tables' => ['jos_small_001', 7]])['error'], 'bad_params');
check('db.dump_many: an empty table name is bad_params', $dmAsk($dmSmallEngine, ['tables' => ['']])['error'], 'bad_params');
check('db.dump_many: more than 5000 tables is bad_params', $dmAsk($dmSmallEngine, ['tables' => array_fill(0, 5001, 'jos_small_001')])['error'], 'bad_params');
check('db.dump_many: a cursor that is not an object is bad_params', $dmAsk($dmSmallEngine, ['tables' => ['jos_small_001'], 'cursor' => 'abc'])['error'], 'bad_params');
check('db.dump_many: a cursor past the list is bad_params', $dmAsk($dmSmallEngine, ['tables' => ['jos_small_001'], 'cursor' => ['table' => 2, 'row' => null]])['error'], 'bad_params');
check('db.dump_many: a negative cursor is bad_params', $dmAsk($dmSmallEngine, ['tables' => ['jos_small_001'], 'cursor' => ['table' => -1]])['error'], 'bad_params');
check('db.dump_many: a non-string row cursor is bad_params', $dmAsk($dmSmallEngine, ['tables' => ['jos_small_001'], 'cursor' => ['table' => 0, 'row' => 5]])['error'], 'bad_params');
$dmAtEnd = $dmAsk($dmSmallEngine, ['tables' => ['jos_small_001'], 'cursor' => ['table' => 1, 'row' => null]]);
check('db.dump_many: a cursor at the end of the list is done, with nothing', [$dmAtEnd['ok'], $dmAtEnd['done'], $dmAtEnd['sql_b64'], $dmAtEnd['next_cursor']], [true, true, '', null]);
$dmBadRow = $dmAsk($dmBigEngine, ['tables' => ['jos_big'], 'cursor' => ['table' => 0, 'row' => 'not-base64!!']]);
check('db.dump_many: a bad row cursor is dump_failed, naming where', [$dmBadRow['ok'], $dmBadRow['error'], $dmBadRow['table'], $dmBadRow['cursor']], [false, 'dump_failed', 'jos_big', ['table' => 0, 'row' => 'not-base64!!']]);
check('db.dump refuses the same row cursor the same way', $dmBigEngine->handle(['token' => $DMTOKEN, 'action' => 'db.dump', 'params' => ['table' => 'jos_big', 'cursor' => 'not-base64!!']])['error'], 'dump_failed');

// A table that fails after others were read: the answer keeps what it has, the next call reports it.
$dmBroken = new Engine($DMTOKEN, [], new DbDumper(new FakeRowSource([
    'jos_ok' => dmKeyed('jos_ok', 2),
    'jos_broken' => ['create' => 'CREATE TABLE `jos_broken` (`id` int)', 'rows' => [['1']], 'columns' => ['other'], 'key' => ['id']],
    'jos_after' => dmKeyed('jos_after', 1),
])));
$dmF1 = $dmAsk($dmBroken, ['tables' => ['jos_ok', 'jos_broken', 'jos_after']]);
check('db.dump_many: a failing table ends the answer early, keeping what was read', [$dmF1['ok'], $dmF1['done'], $dmF1['stopped'], $dmF1['rows'], $dmF1['next_cursor']], [true, false, 'error', 2, ['table' => 1, 'row' => null]]);
$dmF2 = $dmAsk($dmBroken, ['tables' => ['jos_ok', 'jos_broken', 'jos_after'], 'cursor' => $dmF1['next_cursor']]);
check('db.dump_many: the next call reports the failing table', [$dmF2['ok'], $dmF2['error'], $dmF2['table']], [false, 'dump_failed', 'jos_broken']);

// ---- advertised -------------------------------------------------------------------------------

$dmInfo = (new Engine($DMTOKEN, ['php' => PHP_VERSION]))->handle(['token' => $DMTOKEN, 'action' => 'info']);
check('info advertises db.dump_many and its ceilings', $dmInfo['info']['db_dump_many'] ?? null, ['max_tables' => 5000, 'max_bytes' => 8388608, 'max_seconds' => 20]);
check('info still carries what the host said', $dmInfo['info']['php'], PHP_VERSION);

// ---- budgets inside a page: stop after the row that crosses, resume at the next ---------------

/** The ids an answer's INSERTs carry, in order: what a split page must still add up to. */
function dmIds(string $sql): array
{
    preg_match_all("/\\('(\\d{5})',/", $sql, $m);
    return $m[1];
}

/** Every segment of one table, each beside db.dump at the same cursor with limit = rows taken. */
function dmSegments(Engine $engine, string $token, string $table, array $params): array
{
    $out = [];
    $cursor = null;
    do {
        $r = $engine->handle(['token' => $token, 'action' => 'db.dump_many', 'params' => ['tables' => [$table], 'cursor' => $cursor] + $params]);
        if (!$r['ok'] || count($out) > 1000) {
            break;
        }
        $single = $engine->handle(['token' => $token, 'action' => 'db.dump', 'params' => ['table' => $table, 'cursor' => $cursor['row'] ?? '', 'limit' => max(1, $r['rows'])]]);
        $out[] = ['many' => $r, 'single' => base64_decode($single['sql_b64'])];
        $cursor = $r['next_cursor'];
    } while (!$r['done']);
    return $out;
}

$dmMid = [
    'jos_med' => dmKeyed('jos_med', 40, 100),
    'jos_medless' => ['create' => 'CREATE TABLE `jos_medless` (`id` int, `v` text)', 'rows' => dmKeyed('jos_medless', 40, 100)['rows']],
    'jos_fat' => ['create' => 'CREATE TABLE `jos_fat` (`id` int, `v` text)', 'rows' => [['00001', str_repeat('f', 5000)], ['00002', 'b'], ['00003', 'c']], 'columns' => ['id', 'v'], 'key' => ['id']],
];
$dmMidEngine = new Engine($DMTOKEN, [], new DbDumper(new FakeRowSource($dmMid)));
$dmAllIds = array_column($dmMid['jos_med']['rows'], 0);
foreach (['jos_med' => 'keyed', 'jos_medless' => 'keyless'] as $dmTable => $dmKind) {
    $dmM1 = $dmMidEngine->handle(['token' => $DMTOKEN, 'action' => 'db.dump_many', 'params' => ['tables' => [$dmTable], 'limit' => 40, 'max_bytes' => 1024]]);
    check("db.dump_many ({$dmKind}): a budget crossed mid-page stops mid-page", [$dmM1['ok'], $dmM1['done'], $dmM1['stopped'], $dmM1['rows'] > 0 && $dmM1['rows'] < 40], [true, false, 'bytes', true]);
    checkTrue("db.dump_many ({$dmKind}): it stops after the row that crosses the budget, not later ({$dmM1['bytes']} bytes)", $dmM1['bytes'] >= 1024 && $dmM1['bytes'] < 1024 + 120);
    checkTrue("db.dump_many ({$dmKind}): the mid-page stop is standalone SQL", substr(base64_decode($dmM1['sql_b64']), -2) === ";\n");
    $dmSegs = dmSegments($dmMidEngine, $DMTOKEN, $dmTable, ['limit' => 40, 'max_bytes' => 1024]);
    $dmJoined = implode('', array_map(function ($s) { return base64_decode($s['many']['sql_b64']); }, $dmSegs));
    check("db.dump_many ({$dmKind}): resumed mid-page, every row once, in order", dmIds($dmJoined), $dmAllIds);
    check("db.dump_many ({$dmKind}): each segment is db.dump at its cursor with limit = rows taken",
        array_map(function ($s) { return base64_decode($s['many']['sql_b64']); }, $dmSegs), array_column($dmSegs, 'single'));
    checkTrue("db.dump_many ({$dmKind}): every segment but the last stayed within a row of the budget",
        max(array_map(function ($s) { return $s['many']['bytes']; }, array_slice($dmSegs, 0, -1))) < 1024 + 120);
}

$dmFat1 = $dmMidEngine->handle(['token' => $DMTOKEN, 'action' => 'db.dump_many', 'params' => ['tables' => ['jos_fat'], 'limit' => 10, 'max_bytes' => 1024]]);
check('db.dump_many: a row larger than the budget is returned alone, whole', [$dmFat1['rows'], $dmFat1['done'], $dmFat1['stopped'], dmIds(base64_decode($dmFat1['sql_b64']))], [1, false, 'bytes', ['00001']]);
checkTrue('db.dump_many: the oversize row is in the answer in full', str_contains(base64_decode($dmFat1['sql_b64']), str_repeat('f', 5000)));
$dmFat2 = $dmMidEngine->handle(['token' => $DMTOKEN, 'action' => 'db.dump_many', 'params' => ['tables' => ['jos_fat'], 'limit' => 10, 'max_bytes' => 1024, 'cursor' => $dmFat1['next_cursor']]]);
check('db.dump_many: after the oversize row the call moves on to the rest', [$dmFat2['done'], dmIds(base64_decode($dmFat2['sql_b64']))], [true, ['00002', '00003']]);
$dmFatMid = new Engine($DMTOKEN, [], new DbDumper(new FakeRowSource(['jos_fat' => ['rows' => [['00000', 'a'], $dmMid['jos_fat']['rows'][0], ['00002', 'b']]] + $dmMid['jos_fat']])));
[$dmFatSql, $dmFatCalls, $dmFatAnswers] = dmViaMany($dmFatMid, $DMTOKEN, ['jos_fat'], ['limit' => 10, 'max_bytes' => 1024]);
check('db.dump_many: an oversize row after a small one: the small row\'s call takes it too, then the rest', [array_column($dmFatAnswers, 'rows'), dmIds($dmFatSql)], [[2, 1], ['00000', '00001', '00002']]);

$dmTicks = 0.0;
$dmMidTime = (new Engine($DMTOKEN, [], new DbDumper(new FakeRowSource($dmMid))))
    ->dumpClock(function () use (&$dmTicks): float { $t = $dmTicks; $dmTicks += 3.0; return $t; });
$dmMT1 = $dmMidTime->handle(['token' => $DMTOKEN, 'action' => 'db.dump_many', 'params' => ['tables' => ['jos_med'], 'limit' => 40, 'max_seconds' => 5]]);
check('db.dump_many: the time budget is checked between rows (3 s a row, 5 s: two rows of a 40-row page)', [$dmMT1['ok'], $dmMT1['done'], $dmMT1['stopped'], $dmMT1['rows']], [true, false, 'time', 2]);
$dmTicks = 0.0;
$dmTimeSegs = dmSegments($dmMidTime, $DMTOKEN, 'jos_med', ['limit' => 40, 'max_seconds' => 5]);
check('db.dump_many: stopped on time mid-page, every row once, in order',
    dmIds(implode('', array_map(function ($s) { return base64_decode($s['many']['sql_b64']); }, $dmTimeSegs))), $dmAllIds);
