<?php
// Loaded by run.php. DerivedMap turns a site's own rows into a content map a derived contract reads.
require_once __DIR__ . '/../lib/LeafCodec.php';
require_once __DIR__ . '/../lib/VisibleText.php';
require_once __DIR__ . '/../lib/DerivedMap.php';
echo "\nDerived map\n";
$dmRows = [
    ['kind' => 'article', 'id' => 12, 'identity' => ['id' => 12], 'core' => ['title' => 'Hello Northwind'], 'html' => ['introtext' => '<p>Welcome aboard</p>'], 'nested' => []],
    ['kind' => 'module', 'id' => 7, 'identity' => ['id' => 7], 'core' => ['title' => 'Footer'], 'html' => [],
     'nested' => ['params' => '{"tagline":"Ships in 24h","style":"dark","hidden_note":"Draft only"}']],
];
$dmSeen = VisibleText::fromPages(['<p>Welcome aboard</p><div>Ships in 24h</div>']);
$dm = DerivedMap::build($dmRows, $dmSeen, 'northwind-import-example-e1b80210', 1);
check('derived: manifest id and mode', [$dm['manifest']['id'], $dm['manifest']['mode']], ['derived/northwind-import-example-e1b80210', 'derived']);
check('derived: quickstart envelope stays readable', $dm['manifest']['quickstart'], ['release' => 'derived', 'version' => '1']);
check('derived: entities', array_column($dm['map']['entities'], 'key'), ['article-12', 'module-7']);
$dmTexts = array_map(static fn($s) => $s['sample'], $dm['map']['slots']);
check('derived: core and html always kept, nested only when shown', $dmTexts, ['Hello Northwind', 'Welcome aboard', 'Footer', 'Ships in 24h']);
check('derived: slot types are content types only', array_values(array_unique(array_column($dm['map']['slots'], 'type'))), ['text']);
$dmKeys = array_column(DerivedMap::build($dmRows, $dmSeen, 'northwind-import-example-e1b80210', 1)['map']['slots'], 'key');
check('derived: slot keys are stable between two builds', $dmKeys, array_column($dm['map']['slots'], 'key'));
$dmBlind = DerivedMap::build($dmRows, null, 'northwind-import-example-e1b80210', 1);
check('derived: without calibration every nested leaf is kept', count($dmBlind['map']['slots']), 5);
check('derived: the hash basis is the algorithm, not the rows', DerivedMap::hashBasis(1), ['mode' => 'derived', 'algorithm' => 1]);
$dmKeep = DerivedMap::keepOf($dm);
check('derived: keep lists the nested slots a calibrated derive found', count($dmKeep), 1);
$dmRead = DerivedMap::build($dmRows, null, 'northwind-import-example-e1b80210', 1, $dmKeep);
check('derived: a later read keeps only the kept nested leaves', array_map(static fn($s) => $s['sample'], $dmRead['map']['slots']), $dmTexts);
check('derived: an uncalibrated derive keeps null, and a read then keeps every nested leaf', [DerivedMap::keepOf($dmBlind), count(DerivedMap::build($dmRows, null, 'northwind-import-example-e1b80210', 1, DerivedMap::keepOf($dmBlind))['map']['slots'])], [null, 5]);
check('derived: an empty keep keeps no nested leaf', count(DerivedMap::build($dmRows, null, 'northwind-import-example-e1b80210', 1, [])['map']['slots']), 3);
