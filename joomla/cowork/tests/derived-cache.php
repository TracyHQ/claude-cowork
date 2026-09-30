<?php
// Loaded by run.php. DerivedCache keeps a derived map between requests, keyed by what the site's
// source tables hold (a fingerprint) and what the binding asks for. Identical in both engines.
require_once __DIR__ . '/../lib/LeafCodec.php';
require_once __DIR__ . '/../lib/DerivedMap.php';
require_once __DIR__ . '/../lib/DerivedCache.php';
echo "\nDerived cache\n";
$dkRows = [['kind' => 'article', 'id' => 12, 'identity' => ['id' => 12], 'core' => ['title' => 'Hello Northwind'], 'html' => [], 'nested' => []],
    ['kind' => 'module', 'id' => 7, 'identity' => ['id' => 7], 'core' => ['title' => '17'], 'html' => [], 'nested' => []]];
$dkPrint = 'site-a';
$dkStored = null;
$dkSaves = 0;
$dkCache = static function () use (&$dkPrint, &$dkStored, &$dkSaves): DerivedCache {
    return new DerivedCache(static fn(): string => $dkPrint, static fn(): ?string => $dkStored,
        static function (?string $value) use (&$dkStored, &$dkSaves): void { $dkStored = $value; $dkSaves++; }, '1.0.0');
};
$dkMade = 0;
$dkMake = static function () use ($dkRows, &$dkMade): array {
    $dkMade++;
    return ['built' => DerivedMap::build($dkRows, null, 'northwind-import'), 'rows' => $dkRows];
};
$dkBasis = ['contract' => 'derived/northwind-import', 'algorithm' => 1, 'keep' => null, 'calibrated' => true];
$dkFirst = $dkCache()->get($dkBasis, $dkMake);
check('cache: a miss builds once and stores', [$dkMade, $dkSaves, is_string($dkStored)], [1, 1, true]);
check('cache: the rows kept are the ones that carry a slot, by entity key', array_keys($dkFirst['rows']), ['article-12']);
$dkAgain = $dkCache();
$dkSecond = $dkAgain->get($dkBasis, $dkMake);
check('cache: another request with the same fingerprint reads it back, no build', [$dkMade, $dkAgain->builds, $dkSecond], [1, 0, $dkFirst]);
$dkAgain->get($dkBasis, $dkMake);
check('cache: the same object answers a second ask from memory', [$dkMade, $dkSaves], [1, 1]);
$dkPrint = 'site-b';
$dkCache()->get($dkBasis, $dkMake);
check('cache: a changed fingerprint rebuilds', [$dkMade, $dkSaves], [2, 2]);
$dkCache()->get(['keep' => []] + $dkBasis, $dkMake);
check('cache: so does another binding (keep, calibration, algorithm, label)', $dkMade, 3);
$dkStored = 'garbage';
$dkCache()->get(['keep' => []] + $dkBasis, $dkMake);
check('cache: an entry that does not decode is a miss, never an error', $dkMade, 4);
$dkCleared = $dkCache();
$dkCleared->clear();
check('cache: clear drops the stored entry', $dkStored, null);
$dkNoRows = (new DerivedCache(static fn(): string => 'x', static fn(): ?string => null, static function (?string $v) use (&$dkStored): void { $dkStored = $v; }))
    ->get($dkBasis, static fn(): array => ['built' => DerivedMap::build($dkRows, null, 'northwind-import')]);
check('cache: a maker that returns no rows stores none', $dkNoRows['rows'], null);
check('cache: a byte count in php.ini units', array_map([DerivedCache::class, 'bytes'], ['128M', '1G', '262144K', '-1', '512', '']), [134217728, 1073741824, 268435456, -1, 512, -1]);
check('cache: the memory limit is raised to a floor, never lowered, never from unlimited',
    [DerivedCache::raisedLimit('128M', 268435456), DerivedCache::raisedLimit('512M', 268435456), DerivedCache::raisedLimit('-1', 268435456)], ['268435456', null, null]);
$dkMore = [['kind' => 'category', 'id' => 5, 'identity' => ['id' => 5], 'core' => ['title' => 'News'], 'html' => ['description' => '<p>Latest from Northwind</p>'], 'nested' => []]];
$dkMapped = [];
$dkBatched = DerivedMap::buildBatches((static function () use ($dkRows, $dkMore) { yield $dkRows; yield []; yield $dkMore; })(), null, 'northwind-import', 1, null,
    static fn(array $batch): array => $batch, $dkMapped);
check('batches: the same map as one build over every row', $dkBatched, DerivedMap::build(array_merge($dkRows, $dkMore), null, 'northwind-import', 1));
check('batches: and the rows that carry a slot, by entity key', array_keys($dkMapped), ['article-12', 'category-5']);
$dkHeld = null;
$dkHeldCache = new DerivedCache(static fn(): string => 'x', static fn(): ?string => null, static function (?string $v) use (&$dkHeld): void { $dkHeld = $v; });
$dkHeldCache->hold();
$dkHeldCache->get($dkBasis, $dkMake);
$dkWhileHeld = $dkHeld;
$dkHeldCache->flush();
check('cache: held (inside a read-only transaction) nothing is written until flush()', [$dkWhileHeld, is_string($dkHeld)], [null, true]);
$dkBroken = null;
$dkThrows = (new DerivedCache(static function (): string { throw new RuntimeException('Illegal mix of collations'); }, static fn(): ?string => null,
    static function (?string $v) use (&$dkBroken): void { $dkBroken = $v; }))->get($dkBasis, $dkMake);
check('cache: a fingerprint that fails builds the map, keeps nothing, and fails nothing', [count($dkThrows['built']['map']['slots']), $dkBroken], [1, null]);
$dkMoving = 0;
$dkRaced = null;
(new DerivedCache(static function () use (&$dkMoving): string { return 'state-' . $dkMoving++; }, static fn(): ?string => null,
    static function (?string $v) use (&$dkRaced): void { $dkRaced = $v; }))->get($dkBasis, $dkMake);
check('cache: tables that moved while the map was built are not stored', $dkRaced, null);
$dkCounted = 0;
$dkOnce = new DerivedCache(static function () use (&$dkCounted): string { $dkCounted++; return 'same'; }, static fn(): ?string => null, static function (?string $v): void {});
$dkOnce->get($dkBasis, $dkMake);
$dkOnce->get(['keep' => []] + $dkBasis, $dkMake);
check('cache: one object takes the fingerprint once (plus once to check a build it stores)', $dkCounted, 3);
