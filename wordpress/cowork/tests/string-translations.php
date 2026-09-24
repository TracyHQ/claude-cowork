<?php
/**
 * Polylang's string translations of `blogname` and `blogdescription`: a contract `apply` of
 * `site.name` writes the option AND the same words as every language's translation of it, the
 * undo entry keeps what each language said before, and `apply.revert` puts both back. Without
 * Polylang the option alone moves.
 *
 * Loaded by run.php after site-language.php. Uses `check()` / `checkTrue()`, `$WTOKEN`, and
 * `contractSite()` from contracts.php.
 */
declare(strict_types=1);

echo "\nString translations of blogname\n";

$FIXTURES = __DIR__ . '/fixtures/contracts';
$SITE = json_decode((string) file_get_contents($FIXTURES . '/site.json'), true);
$tdoor = static function (Engine $engine, string $operation, array $params = []) use ($WTOKEN): array {
    return $engine->handle(['token' => $WTOKEN, 'action' => 'content.contract', 'params' => ['operation' => $operation] + $params]);
};

// ── with Polylang: the archive's per-language entries would keep serving the demo name ──────

$t = contractSite($SITE, $FIXTURES, true);
$T = $t['engine'];
// As the archive is captured: each language translates the demo name by itself (English keeps
// it verbatim, German has a translated title), and the tagline in German only.
WP_Fake::$strings = [
    'en' => ['Test Co' => 'Test Co', 'A test' => 'A test'],
    'de' => ['Test Co' => 'Test Co GmbH', 'A test' => 'Ein Test'],
];
$tdoor($T, 'bind', ['contract' => 'test-design/wp7/1.0.0']);
$revision = $tdoor($T, 'inspect')['revision'];
$applied = $tdoor($T, 'apply', ['expected_revision' => $revision, 'apply_id' => 'contract-t1', 'request_id' => 't1', 'changes' => ['site.name' => 'Acme Corp']]);
check('the apply lands', [$applied['ok'], array_column($applied['written'], 'key')], [true, ['blogname']]);
check('the option holds the customer\'s name', WP_Fake::$options['blogname'], 'Acme Corp');
check('and so does every language\'s translation of it, keyed by the old value and the new one', WP_Fake::$strings, [
    'en' => ['Test Co' => 'Acme Corp', 'A test' => 'A test', 'Acme Corp' => 'Acme Corp'],
    'de' => ['Test Co' => 'Acme Corp', 'A test' => 'Ein Test', 'Acme Corp' => 'Acme Corp'],
]);
$entry = $t['log']->entries('contract-t1')[0];
check('the undo entry is the option write', [$entry['op'], $entry['kind'], $entry['key'], $entry['before']], ['content', 'option', 'blogname', ['value' => 'Test Co']]);
check('with what each language said before, absent entries as null', $entry['translations'], [
    'en' => ['Test Co' => 'Test Co', 'Acme Corp' => null],
    'de' => ['Test Co' => 'Test Co GmbH', 'Acme Corp' => null],
]);
check('the receipt carries no translations', isset($t['log']->entries('contract-t1')[1]['translations']), false);
check('inspect stays clean', $tdoor($T, 'inspect')['problems'], []);

$back = $T->handle(['token' => $WTOKEN, 'action' => 'apply.revert', 'params' => ['apply_id' => 'contract-t1']]);
check('apply.revert takes the apply back', [$back['ok'], $back['reverted']], [true, 2]);
check('the option as it was', WP_Fake::$options['blogname'], 'Test Co');
check('and every language\'s translations as they were, the new key gone', WP_Fake::$strings, [
    'en' => ['Test Co' => 'Test Co', 'A test' => 'A test'],
    'de' => ['Test Co' => 'Test Co GmbH', 'A test' => 'Ein Test'],
]);
check('at the revision before', $tdoor($T, 'inspect')['revision'], $revision);

// ── the demo value from the profile is keyed too, when the option already moved past it ─────

$d = contractSite($SITE, $FIXTURES, true);
$D = $d['engine'];
WP_Fake::$strings = ['en' => ['Test Co' => 'Test Co'], 'de' => ['Test Co' => 'Test Co GmbH']];
$tdoor($D, 'bind', ['contract' => 'test-design/wp7/1.0.0']);
$r0 = $tdoor($D, 'inspect')['revision'];
$tdoor($D, 'apply', ['expected_revision' => $r0, 'apply_id' => 'contract-d1', 'request_id' => 'd1', 'changes' => ['site.name' => 'First']]);
$r1 = $tdoor($D, 'inspect')['revision'];
$tdoor($D, 'apply', ['expected_revision' => $r1, 'apply_id' => 'contract-d2', 'request_id' => 'd2', 'changes' => ['site.name' => 'Second']]);
check('a second apply keys the sample, the value before and the new value', WP_Fake::$strings['de'], ['Test Co' => 'Second', 'First' => 'Second', 'Second' => 'Second']);
$D->handle(['token' => $WTOKEN, 'action' => 'apply.revert', 'params' => ['apply_id' => 'contract-d2']]);
check('reverting the second leaves the first\'s translations', WP_Fake::$strings['de'], ['Test Co' => 'First', 'First' => 'First']);
$D->handle(['token' => $WTOKEN, 'action' => 'apply.revert', 'params' => ['apply_id' => 'contract-d1']]);
check('and reverting the first leaves the archive\'s', WP_Fake::$strings['de'], ['Test Co' => 'Test Co GmbH']);

// ── the other option, and one the door does not translate ──────────────────────────────────

$o = contractSite($SITE, $FIXTURES, true);
$O = $o['engine'];
WP_Fake::$strings = ['en' => [], 'de' => []];
$tdoor($O, 'bind', ['contract' => 'test-design/wp7/1.0.0']);
$tdoor($O, 'apply', ['expected_revision' => $tdoor($O, 'inspect')['revision'], 'apply_id' => 'contract-o1', 'request_id' => 'o1', 'changes' => ['identity.email' => 'x@acme.test']]);
check('an option Polylang does not serve through strings writes no translation', WP_Fake::$strings, ['en' => [], 'de' => []]);
check('and its undo entry carries none', isset($o['log']->entries('contract-o1')[0]['translations']), false);

// ── without Polylang: the option alone ─────────────────────────────────────────────────────

$n = contractSite($SITE, $FIXTURES, false);
$N = $n['engine'];
$tdoor($N, 'bind', ['contract' => 'test-design/wp7/1.0.0']);
$tdoor($N, 'apply', ['expected_revision' => $tdoor($N, 'inspect')['revision'], 'apply_id' => 'contract-n1', 'request_id' => 'n1', 'changes' => ['site.name' => 'Acme Corp']]);
check('without Polylang the option moves', WP_Fake::$options['blogname'], 'Acme Corp');
check('and nothing else', WP_Fake::$strings, []);
check('the undo entry carries no translations', isset($n['log']->entries('contract-n1')[0]['translations']), false);
$N->handle(['token' => $WTOKEN, 'action' => 'apply.revert', 'params' => ['apply_id' => 'contract-n1']]);
check('and reverts as before', WP_Fake::$options['blogname'], 'Test Co');

// Leave the fake as the next file expects it.
WP_Fake::reset();
