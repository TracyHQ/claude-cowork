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

// ── one language's words: `<locale>::site.name` moves that language's translation alone ─────
// The seeder writes the source pass (every language gets the source words, above) and then each
// chosen edition's own words; the option row is what `/` shows, each `/<lang>/` its own entry.
// Measured 25/09/2026 on dev (`wpbghcqkm`): before this, `/` carried the Vietnamese tagline and
// `/vi/` the archive's "Northgate", because the language-object bug above made every translation
// write a no-op while Polylang had re-keyed the vi demo entry under the new name.

$e = contractSite($SITE, $FIXTURES, true);
$EE = $e['engine'];
WP_Fake::$strings = ['en' => ['Test Co' => 'Test Co', 'A test' => 'A test'], 'de' => ['Test Co' => 'Test Co GmbH', 'A test' => 'Ein Test']];
$tdoor($EE, 'bind', ['contract' => 'test-design/wp7/1.0.0']);
$re0 = $tdoor($EE, 'inspect')['revision'];
$one = $tdoor($EE, 'apply', ['expected_revision' => $re0, 'apply_id' => 'contract-e1', 'request_id' => 'e1', 'changes' => ['site.name' => 'Acme Corp', 'de::site.name' => 'Acme GmbH']]);
check('the source write and the German edition land in one apply', [$one['ok'], array_map(static fn($w) => $w['kind'] . ':' . $w['key'] . (isset($w['locale']) ? '@' . $w['locale'] : ''), $one['written'] ?? [])], [true, ['option:blogname', 'optionTranslation:blogname@de']]);
check('the option row holds the source words', WP_Fake::$options['blogname'], 'Acme Corp');
check('English serves the source words under every key it may look up', WP_Fake::$strings['en'], ['Test Co' => 'Acme Corp', 'A test' => 'A test', 'Acme Corp' => 'Acme Corp']);
check('German serves ITS words under the same keys, nothing else touched', WP_Fake::$strings['de'], ['Test Co' => 'Acme GmbH', 'A test' => 'Ein Test', 'Acme Corp' => 'Acme GmbH', 'Acme GmbH' => 'Acme GmbH']);
$entries = $e['log']->entries('contract-e1');
check('the edition entry names its language and moved no row', [$entries[1]['kind'], $entries[1]['locale'], $entries[1]['before']], ['optionTranslation', 'de', null]);
check('and remembers what German said before, absent keys as null', $entries[1]['translations'], ['de' => ['Acme Corp' => 'Acme Corp', 'Test Co' => 'Acme Corp', 'Acme GmbH' => null]]);
check('inspect stays clean', $tdoor($EE, 'inspect')['problems'], []);
$undo = $EE->handle(['token' => $WTOKEN, 'action' => 'apply.revert', 'params' => ['apply_id' => 'contract-e1']]);
check('apply.revert takes both back', [$undo['ok'], $undo['reverted']], [true, 3]);
check('the option as it was', WP_Fake::$options['blogname'], 'Test Co');
check('German as the archive shipped it', WP_Fake::$strings['de'], ['Test Co' => 'Test Co GmbH', 'A test' => 'Ein Test']);
check('English as the archive shipped it', WP_Fake::$strings['en'], ['Test Co' => 'Test Co', 'A test' => 'A test']);

// The German words alone, on a site whose option already carries the customer's: keyed by the
// row's value too, so the front end finds it whichever key Polylang looks up.
$re1 = $tdoor($EE, 'inspect')['revision'];
$tdoor($EE, 'apply', ['expected_revision' => $re1, 'apply_id' => 'contract-e2', 'request_id' => 'e2', 'changes' => ['site.name' => 'Acme Corp']]);
$re2 = $tdoor($EE, 'inspect')['revision'];
$two = $tdoor($EE, 'apply', ['expected_revision' => $re2, 'apply_id' => 'contract-e3', 'request_id' => 'e3', 'changes' => ['de::site.name' => 'Acme GmbH']]);
check('an edition write alone is an apply of its own', [$two['ok'], count($two['written'] ?? [])], [true, 1]);
check('keyed by the row value, the demo value and itself', WP_Fake::$strings['de'], ['Test Co' => 'Acme GmbH', 'A test' => 'Ein Test', 'Acme Corp' => 'Acme GmbH', 'Acme GmbH' => 'Acme GmbH']);
check('English untouched by it', WP_Fake::$strings['en']['Test Co'], 'Acme Corp');

// What has no edition is refused by name, nothing written.
$re3 = $tdoor($EE, 'inspect')['revision'];
$noField = $tdoor($EE, 'apply', ['expected_revision' => $re3, 'apply_id' => 'contract-e4', 'request_id' => 'e4', 'changes' => ['de::identity.email' => 'x@acme.test']]);
check('a field of an untranslated option has no edition', [$noField['ok'], $noField['error']], [false, 'contract_failed']);
checkTrue('and says so by name', strpos((string) $noField['message'], 'An option has no edition: de::identity.email') !== false);
$noLang = $tdoor($EE, 'apply', ['expected_revision' => $re3, 'apply_id' => 'contract-e5', 'request_id' => 'e5', 'changes' => ['fr::site.name' => 'Acme SARL']]);
check('a language the archive does not ship is refused', $noLang['ok'], false);
checkTrue('by name', strpos((string) $noLang['message'], 'No fr edition of option-blogname') !== false);
check('nothing moved', WP_Fake::$strings['de']['Test Co'], 'Acme GmbH');
