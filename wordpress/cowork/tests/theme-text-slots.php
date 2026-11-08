<?php
/**
 * Texts a theme prints from its own option (TracyHQ/tracy#459).
 *
 * A theme can print a business's phone, address or website from an option of its own (`<theme>_contact_phone`). The
 * profile's manifest declares such options by name (`themeTexts.options`); a text slot may name one (`target.option`),
 * is read as the option's value and written as the words. A profile that declares `themeTexts` reads and writes no
 * other text option than the site name and tagline: nothing undeclared, and nothing outside the theme's own prefix.
 *
 * Loaded by run.php after theme-picture-slots.php, whose `markedVariant()` it uses.
 */
declare(strict_types=1);

echo "\nTheme text slots\n";

$withPhone = static function (array $manifest, array $map, bool $declare = true): array {
    if ($declare) {
        $manifest['themeTexts'] = ['options' => ['test_theme_contact_phone']];
    }
    $map['entities'][] = ['key' => 'option-contact-phone', 'kind' => 'option', 'identity' => ['name' => 'test_theme_contact_phone']];
    $map['slots'][] = ['key' => 'theme.contact-phone', 'entity' => 'option-contact-phone', 'target' => ['option' => 'test_theme_contact_phone'],
        'type' => 'text', 'sample' => '(555) 010-2030', 'maxCharacters' => 80, 'label' => 'Theme text'];
    return [$manifest, $map];
};
$phoneSite = static function (callable $edit): array {
    WP_Fake::reset();
    return markedVariant($edit);
};

$declared = markedVariant(static fn(array $m, array $p): array => $withPhone($m, $p));
check('a declared theme text option binds with no problem', [$declared['inspect'], $declared['bind']], [[], true]);
$undeclared = markedVariant(static function (array $m, array $p) use ($withPhone): array {
    [$m, $p] = $withPhone($m, $p);
    $m['themeTexts'] = ['options' => []];
    return [$m, $p];
});
check('an option the manifest does not declare is refused by name', $undeclared['inspect'],
    ['Slot theme.contact-phone: undeclared theme text option test_theme_contact_phone']);
$legacy = markedVariant(static fn(array $m, array $p): array => $withPhone($m, $p, false));
check('a profile that declares no theme texts keeps the old rule', $legacy['inspect'], []);

$outside = markedVariant(static function (array $m, array $p) use ($withPhone): array {
    [$m, $p] = $withPhone($m, $p);
    $m['themeTexts'] = ['options' => ['siteurl']];
    return [$m, $p];
});
check('an option outside the theme\'s own names refuses the profile', ($outside['answer']['ok'] ?? null) === false ? (string) $outside['answer']['message'] : null,
    'marked-design/wp7/1.0.0: theme text option siteurl is not one of the theme test-theme (its name starts test_theme_)');
$notList = markedVariant(static function (array $m, array $p) use ($withPhone): array {
    [$m, $p] = $withPhone($m, $p);
    $m['themeTexts'] = ['options' => 'test_theme_contact_phone'];
    return [$m, $p];
});
check('a declaration that is not a list of names refuses the profile', ($notList['answer']['ok'] ?? null) === false ? (string) $notList['answer']['message'] : null,
    'marked-design/wp7/1.0.0: themeTexts declares options as a list of names');

// ── a declared option reads and writes as words ──────────────────────────────────────────────

$tt = $declared['engine'];
$ttdoor = static function (string $operation, array $params = []) use ($tt, $WTOKEN): array {
    return $tt->handle(['token' => $WTOKEN, 'action' => 'content.contract', 'params' => ['operation' => $operation, 'contract' => 'marked-design/wp7/1.0.0'] + $params]);
};
$ttdoor('bind');
$ttinsp = $ttdoor('inspect');
check('the option reads as its value', $ttinsp['slots']['theme.contact-phone'] ?? null, '(555) 010-2030');
$ttwrite = $ttdoor('apply', ['expected_revision' => $ttinsp['revision'], 'apply_id' => 'contract-tt1', 'request_id' => 'tt1', 'changes' => ['theme.contact-phone' => '+1 555 010 9999']]);
check('a new phone lands as an option write', [$ttwrite['ok'] ?? null, $ttwrite['written'] ?? null],
    [true, [['kind' => 'option', 'id' => 0, 'key' => 'test_theme_contact_phone']]]);
check('the option holds the words', WP_Fake::$options['test_theme_contact_phone'], '+1 555 010 9999');
check('it reads back, and is not drift', [$ttdoor('inspect')['slots']['theme.contact-phone'] ?? null, $ttdoor('inspect')['problems']], ['+1 555 010 9999', []]);
check('the revert puts the demo phone back', [(bool) ($tt->handle(['token' => $WTOKEN, 'action' => 'apply.revert', 'params' => ['apply_id' => 'contract-tt1']])['ok'] ?? false), WP_Fake::$options['test_theme_contact_phone']],
    [true, '(555) 010-2030']);

// ── plain text, an option not stored yet, and the option the slot's entity names ────────────

// (The plugin's own plain-text rules are checked here: the test host defines no sanitize_text_field.)
$withHours = static function (array $manifest, array $map) use ($withPhone): array {
    [$manifest, $map] = $withPhone($manifest, $map);
    $manifest['themeTexts']['options'][] = 'test_theme_hours';
    $map['entities'][] = ['key' => 'option-hours', 'kind' => 'option', 'identity' => ['name' => 'test_theme_hours']];
    $map['slots'][] = ['key' => 'theme.hours', 'entity' => 'option-hours', 'target' => ['option' => 'test_theme_hours'],
        'type' => 'text', 'multiline' => true, 'sample' => "Mon-Fri 9-5\nSat 10-2", 'maxCharacters' => 120, 'label' => 'Opening hours'];
    return [$manifest, $map];
};
$hours = markedVariant($withHours);
check('a declared option the site has not stored yet is no problem and binds', [$hours['inspect'], $hours['bind']], [[], true]);
$hx = $hours['engine'];
$hdoor = static function (string $operation, array $params = []) use ($hx, $WTOKEN): array {
    return $hx->handle(['token' => $WTOKEN, 'action' => 'content.contract', 'params' => ['operation' => $operation, 'contract' => 'marked-design/wp7/1.0.0'] + $params]);
};
$hinsp = $hdoor('inspect');
check('it reads empty', $hinsp['slots']['theme.hours'], '');
$hwrite = $hdoor('apply', ['expected_revision' => $hinsp['revision'], 'apply_id' => 'contract-hh1', 'request_id' => 'hh1',
    'changes' => ['theme.hours' => "Mon-Fri 9-5  \n Sat 10-2", 'theme.contact-phone' => "  +1 555\n0199 "]]);
check('both land', $hwrite['ok'] ?? $hwrite, true);
check('a multiline slot keeps its lines, a one-line slot is one line', [WP_Fake::$options['test_theme_hours'], WP_Fake::$options['test_theme_contact_phone']],
    ["Mon-Fri 9-5\nSat 10-2", '+1 555 0199']);
check('the revert restores the phone and takes away the option that did not exist',
    [(bool) ($hx->handle(['token' => $WTOKEN, 'action' => 'apply.revert', 'params' => ['apply_id' => 'contract-hh1']])['ok'] ?? false),
        WP_Fake::$options['test_theme_contact_phone'], array_key_exists('test_theme_hours', WP_Fake::$options)],
    [true, '(555) 010-2030', false]);
check('markup is not text', $hdoor('apply', ['expected_revision' => $hdoor('inspect')['revision'], 'apply_id' => 'contract-hh2', 'request_id' => 'hh2',
    'changes' => ['theme.hours' => '<b>9-5</b>']])['errors'][0]['code'] ?? null, 'SLOT_NOT_CONTENT');
check('an option no slot names is never written', array_key_exists('test_theme_hours', WP_Fake::$options) || WP_Fake::$options['home'] !== 'http://test.local', false);

$mismatch = markedVariant(static function (array $m, array $p) use ($withPhone): array {
    [$m, $p] = $withPhone($m, $p);
    $m['themeTexts']['options'][] = 'test_theme_other';
    $p['entities'][count($p['entities']) - 1]['identity']['name'] = 'home';
    return [$m, $p];
});
check('a declared name on a slot whose entity is another option is refused, so a declaration never opens a core option', [$mismatch['inspect'], $mismatch['bind']],
    [['Slot theme.contact-phone: the slot names test_theme_contact_phone, not the option of its entity home'], false]);
