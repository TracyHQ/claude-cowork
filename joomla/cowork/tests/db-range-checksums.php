<?php
/**
 * db.range_checksums and db.dump_after: a caller compares each range of a table's rows with the same
 * ranges of an earlier read and reads again only the ranges that moved.
 * Required from run.php, whose check() and checkTrue() it uses.
 */

$RCTOKEN = bin2hex(random_bytes(16));

/** A `content`-like table keyed by an integer `id`, with a hit counter the checksums leave out. */
function rcTable(int $count): array
{
    $rows = [];
    for ($i = 1; $i <= $count; $i++) {
        $rows[] = [(string) $i, "title {$i}", '0'];
    }
    return ['create' => 'CREATE TABLE `jos_content` (`id` int, `title` text, `hits` int)', 'rows' => $rows,
            'columns' => ['id', 'title', 'hits'], 'key' => ['id']];
}

$rcSource = new FakeRowSource(['jos_content' => rcTable(12)]);
$rcEngine = new Engine($RCTOKEN, [], new DbDumper($rcSource));
$rcAsk = function (array $params) use ($rcEngine, $RCTOKEN) {
    return $rcEngine->handle(['token' => $RCTOKEN, 'action' => 'db.range_checksums', 'params' => $params + ['table' => 'jos_content', 'key' => 'id', 'skip_columns' => ['hits']]]);
};

$rcInfo = $rcEngine->handle(['token' => $RCTOKEN, 'action' => 'info']);
check('info names db.range_checksums, its version and limits', $rcInfo['info']['db_range_checksums']['version'] ?? null, 1);

$rcBefore = $rcAsk(['starts' => [5, 9]]);
check('three ranges for two starts: below 5, 5 to 8, 9 and up', [$rcBefore['ok'], count($rcBefore['ranges'])], [true, 3]);
check('each range counts its rows', array_map(fn($r) => (int) explode(':', $r)[0], $rcBefore['ranges']), [4, 4, 4]);

// An edited title in row 6 moves its range alone; a hit counter moving moves nothing.
$rcSource->deleteRow('jos_content', 5);
$rcSource->insertRow('jos_content', ['6', 'title 6, edited', '0'], 5);
$rcSource->deleteRow('jos_content', 0);
$rcSource->insertRow('jos_content', ['1', 'title 1', '41'], 0);
$rcAfter = $rcAsk(['starts' => [5, 9]]);
check('an edited row moves only its own range', [$rcAfter['ranges'][0] === $rcBefore['ranges'][0], $rcAfter['ranges'][1] === $rcBefore['ranges'][1], $rcAfter['ranges'][2] === $rcBefore['ranges'][2]], [true, false, true]);

// A row added past the last start, and one deleted in the first range: those two ranges move.
$rcSource->insertRow('jos_content', ['13', 'title 13', '0'], 12);
$rcSource->deleteRow('jos_content', 1);
$rcLater = $rcAsk(['starts' => [5, 9]]);
check('a deleted row and an added one move their ranges and their counts', array_map(fn($r) => (int) explode(':', $r)[0], $rcLater['ranges']), [3, 4, 5]);
check('no start: the whole table is one range, as its substance checksum', $rcAsk(['starts' => []])['ranges'], [$rcSource->substanceChecksum('jos_content', ['hits'])]);

check('starts must ascend', $rcAsk(['starts' => [9, 5]])['error'] ?? null, 'bad_params');
check('starts must be integers', $rcAsk(['starts' => ['5']])['error'] ?? null, 'bad_params');
check('a table db.tables does not list is refused', $rcAsk(['starts' => [], 'table' => 'jos_nope'])['error'] ?? null, 'bad_params');
check('a key that is no column of the table is refused', $rcAsk(['starts' => [], 'key' => 'nope'])['error'] ?? null, 'dump_failed');

// db.dump_after: the rows of one range again, from the first key above `after`.
$rcRange = $rcEngine->handle(['token' => $RCTOKEN, 'action' => 'db.dump_after', 'params' => ['table' => 'jos_content', 'key' => 'id', 'after' => 4, 'limit' => 4]]);
check('dump_after: the rows above after, in key order, at most limit', [$rcRange['ok'], $rcRange['rows'], $rcRange['last']], [true, 4, '8']);
$rcSql = base64_decode($rcRange['sql_b64']);
checkTrue('dump_after: the edited row is in the range read again', strpos($rcSql, "'title 6, edited'") !== false);
checkTrue('dump_after: nothing below the range', strpos($rcSql, "'title 3'") === false && strpos($rcSql, "'title 9'") === false);
$rcFirst = $rcEngine->handle(['token' => $RCTOKEN, 'action' => 'db.dump_after', 'params' => ['table' => 'jos_content', 'key' => 'id', 'after' => null, 'limit' => 2]]);
check('dump_after from the start (after null)', [$rcFirst['rows'], $rcFirst['last']], [2, '3']);
$rcWrongKey = $rcEngine->handle(['token' => $RCTOKEN, 'action' => 'db.dump_after', 'params' => ['table' => 'jos_content', 'key' => 'title', 'after' => null, 'limit' => 2]]);
check('dump_after only by the table\'s one key column', $rcWrongKey['error'] ?? null, 'bad_params');
