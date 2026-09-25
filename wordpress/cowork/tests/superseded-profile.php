<?php
/**
 * A released profile whose write rules were corrected in place (0.13.6/0.13.7 changed a slot's
 * limit and input) must not lock the sites already sealed to it. `superseded.json` names the
 * predecessor base hashes a profile accepts, and only when the seal itself — the presentation
 * lock — is the same bytes. A site bound to a predecessor inspects clean, can write, and its
 * binding moves to the current hash on that write. Anything else still refuses.
 *
 * Loaded by run.php after contracts.php (uses contractSite(), $SITE, $call, $door).
 */
declare(strict_types=1);

echo "\nSuperseded profile\n";

/** A copy of the test-design fixture tree, so a profile can be rewritten per case. */
function supersededTree(string $fixtures): string
{
    $dir = sys_get_temp_dir() . '/cc-superseded-' . bin2hex(random_bytes(4));
    foreach (['test-design/wp7/1.0.0', 'bad-trim/wp7/1.0.0'] as $id) {
        mkdir($dir . '/' . $id, 0777, true);
        foreach (glob($fixtures . '/' . $id . '/*.json') as $file) {
            copy($file, $dir . '/' . $id . '/' . basename($file));
        }
    }
    return $dir;
}

/**
 * Change one slot's limit in the profile at $dir/test-design/wp7/1.0.0 and re-pin the two
 * extensions to the new base, as a regenerated profile would be. Returns [old base hash, old trim hash].
 */
function supersedeProfile(string $tree): array
{
    $dir = $tree . '/test-design/wp7/1.0.0';
    $oldBase = DemoTrimProfile::baseHash($dir);
    $oldTrim = hash('sha256', (string) file_get_contents($dir . '/demo-trim-map.json'));
    $map = json_decode((string) file_get_contents($dir . '/content-map.json'), true);
    $map['slots'][0]['maxCharacters'] = (int) ($map['slots'][0]['maxCharacters'] ?? 100) - 1;
    file_put_contents($dir . '/content-map.json', json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    $newBase = DemoTrimProfile::baseHash($dir);
    foreach (['demo-trim-map.json', 'editions.json'] as $name) {
        $ext = json_decode((string) file_get_contents($dir . '/' . $name), true);
        $ext['baseHash'] = $newBase;
        if (isset($ext['baseFiles']['content-map.json'])) {
            $ext['baseFiles']['content-map.json'] = hash_file('sha256', $dir . '/content-map.json');
        }
        file_put_contents($dir . '/' . $name, json_encode($ext, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    }
    return [$oldBase, $oldTrim];
}

function writeSuperseded(string $tree, array $accepts, ?string $baseHash = null): void
{
    $dir = $tree . '/test-design/wp7/1.0.0';
    file_put_contents($dir . '/superseded.json', json_encode([
        'schemaVersion' => QuickstartContract::SUPERSEDED_SCHEMA,
        'contract' => 'test-design/wp7/1.0.0',
        'baseHash' => $baseHash ?? DemoTrimProfile::baseHash($dir),
        'accepts' => $accepts,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

/** A site bound (and demo-trimmed) under the tree as it is now; returns the site and its binding. */
$boundSite = static function (string $tree) use ($SITE, $door): array {
    $t = contractSite($SITE, $tree);
    $bind = $door($t['engine'], 'bind', ['contract' => 'test-design/wp7/1.0.0']);
    $trim = $door($t['engine'], 'demoTrim.apply', ['apply_id' => 'dtrim-s1', 'request_id' => 'r-s1']);
    return [$t, $bind, $trim];
};
$sameContract = static function (string $tree, array $t): QuickstartContract {
    return new QuickstartContract($t['writer'], new Claude_Cowork_Contract_Store(), $t['root'], $tree, '');
};
$engineFor = static function (array $t, QuickstartContract $contract): Engine {
    return new Engine($GLOBALS['WTOKEN'], [], null, null, null, null, $t['writer'], $t['media'], $t['log'], null, $contract);
};

// 1. Without a declaration, a regenerated profile locks the site — the 0.13.6/0.13.7 regression.
$tree = supersededTree($FIXTURES);
[$t, $bind, $trim] = $boundSite($tree);
check('fixture: bound and trimmed under the original profile', [$bind['ok'], $trim['ok'] ?? null], [true, true]);
[$oldBase, $oldTrim] = supersedeProfile($tree);
$e = $engineFor($t, $sameContract($tree, $t));
$plain = $door($e, 'inspect');
check('an undeclared change of profile still refuses', [$plain['ok'], $plain['problems'] ?? null],
    [false, ['Installed content contract changed', 'The installed demo-trim profile changed']]);

// 2. Declared, with the same presentation lock: clean, writable, and the binding moves on write.
$lock = hash_file('sha256', $tree . '/test-design/wp7/1.0.0/presentation-lock.json');
writeSuperseded($tree, [['baseHash' => $oldBase, 'demoTrimHash' => $oldTrim, 'presentationLock' => $lock, 'reason' => 'slot limit corrected']]);
$e = $engineFor($t, $sameContract($tree, $t));
$state = $door($e, 'inspect');
check('a declared predecessor inspects clean', [$state['ok'], $state['problems']], [true, []]);
check('and says which base it was bound to', $state['contractLineage'], ['from' => $oldBase, 'to' => DemoTrimProfile::baseHash($tree . '/test-design/wp7/1.0.0')]);
$slot = array_key_first($state['slots']);
$apply = $door($e, 'apply', ['expected_revision' => $state['revision'], 'apply_id' => 'contract-s1', 'request_id' => 'r-s2', 'changes' => [$slot => 'New words']]);
check('a site bound to a predecessor can write', $apply['ok'], true);
$binding = json_decode((string) WP_Fake::$options['_tracy_content_contract'], true);
check('and that write moves its binding to the current profile', [$binding['contractHash'], $binding['demoTrim']['profileHash']],
    [DemoTrimProfile::baseHash($tree . '/test-design/wp7/1.0.0'), hash('sha256', (string) file_get_contents($tree . '/test-design/wp7/1.0.0/demo-trim-map.json'))]);
check('after which no lineage is reported', $door($e, 'inspect')['contractLineage'], null);
$revert = $call($e, 'apply.revert', ['apply_id' => 'contract-s1']);
check('the existing revert still works across the move', $revert['ok'], true);

// 3. A declaration whose presentation lock is not the lock here is not accepted: the profile refuses.
$tree = supersededTree($FIXTURES);
[$t] = $boundSite($tree);
[$oldBase, $oldTrim] = supersedeProfile($tree);
writeSuperseded($tree, [['baseHash' => $oldBase, 'demoTrimHash' => $oldTrim, 'presentationLock' => str_repeat('0', 64), 'reason' => 'x']]);
check('a predecessor declared under another presentation lock refuses the profile',
    $door($engineFor($t, $sameContract($tree, $t)), 'inspect')['error'], 'contract_unavailable');

// 4. A declaration that belongs to another base refuses the profile.
writeSuperseded($tree, [['baseHash' => $oldBase, 'demoTrimHash' => $oldTrim, 'presentationLock' => $lock, 'reason' => 'x']], str_repeat('a', 64));
check('a declaration pinned to another base refuses the profile',
    $door($engineFor($t, $sameContract($tree, $t)), 'inspect')['error'], 'contract_unavailable');

// 5. A hash nobody declared is still a changed contract.
writeSuperseded($tree, [['baseHash' => str_repeat('b', 64), 'presentationLock' => hash_file('sha256', $tree . '/test-design/wp7/1.0.0/presentation-lock.json'), 'reason' => 'x']]);
$other = $door($engineFor($t, $sameContract($tree, $t)), 'inspect');
check('a binding hash the declaration does not name still refuses', [$other['ok'], in_array('Installed content contract changed', $other['problems'] ?? [], true)], [false, true]);

// 6. The shipped profiles carry their real declarations, and they load.
foreach (['tracy-business/wp7/1.1.0', 'tracy-business/wp7/1.2.0'] as $shipped) {
    $real = new QuickstartContract(new FakeSiteWriter(), new Claude_Cowork_Contract_Store(), sys_get_temp_dir(), __DIR__ . '/../lib/contracts');
    try {
        $real->preview($shipped);
        check('the shipped ' . $shipped . ' loads with its superseded list', true, true);
    } catch (Throwable $e) {
        check('the shipped ' . $shipped . ' loads with its superseded list', $e->getMessage(), 'loaded');
    }
}
