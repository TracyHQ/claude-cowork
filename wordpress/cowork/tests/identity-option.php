<?php
/**
 * The site identity option a released archive does not carry.
 *
 * Tracy Business wp7 1.2.0 and 1.3.0 map the site identity (name, contact, social…) to fields of ONE
 * option, `tracy_site_identity`, which the theme reads with "a missing field renders empty" — and
 * the released database has no such row: the first contract apply creates it. Measured 30/09/2026
 * on a new 1.3.0 site: bind refused "Missing option: option-tracy_site_identity", so provision
 * stopped and no Build ran. An option entity whose every slot is a `siteIdentity` field is that
 * option: absent, it is the all-empty identity; a field it does not hold reads empty.
 *
 * Loaded by run.php after contracts.php (reuses `contractSite()`, `$call`, `$door`, `$SITE`).
 */
declare(strict_types=1);

echo "\nSite identity option absent from the archive\n";

/** A copy of the test-design profile whose identity option is a site identity (every slot `siteIdentity`, by field). */
function identityProfile(string $fixtures): string
{
    $dir = sys_get_temp_dir() . '/cc-identity-' . bin2hex(random_bytes(4));
    $profile = $dir . '/identity-design/wp7/1.0.0';
    mkdir($profile, 0777, true);
    $manifest = json_decode((string) file_get_contents($fixtures . '/test-design/wp7/1.0.0/manifest.json'), true);
    $manifest['id'] = 'identity-design/wp7/1.0.0';
    file_put_contents($profile . '/manifest.json', json_encode($manifest));
    copy($fixtures . '/test-design/wp7/1.0.0/presentation-lock.json', $profile . '/presentation-lock.json');
    $map = json_decode((string) file_get_contents($fixtures . '/test-design/wp7/1.0.0/content-map.json'), true);
    foreach ($map['slots'] as $i => $slot) {
        if ($slot['entity'] === 'option-identity') {
            $map['slots'][$i]['siteIdentity'] = true;
        }
    }
    $map['slots'][] = ['key' => 'identity.phone', 'entity' => 'option-identity', 'target' => ['option' => 'tracy_identity', 'field' => 'phone'],
        'type' => 'text', 'sample' => '+1 555', 'maxCharacters' => 40, 'siteIdentity' => true, 'label' => 'Phone'];
    file_put_contents($profile . '/content-map.json', json_encode($map));
    return $dir;
}

$ID_FIXTURES = identityProfile($FIXTURES);
$idContract = 'identity-design/wp7/1.0.0';

// ── the archive as released: no identity row ───────────────────────────────────────────────

$s = contractSite($SITE, $ID_FIXTURES);
unset(WP_Fake::$options['tracy_identity']);
$E = $s['engine'];
$plan = $door($E, 'inspect', ['contract' => $idContract]);
check('an absent site identity option is not a problem', $plan['problems'] ?? null, []);
check('it is still one of the entities', array_column($plan['entities'], 'id', 'key'),
    ['option-blogname' => null, 'option-identity' => null, 'page-home' => 10, 'part-header' => 70]);
check('every field of it reads empty', [$plan['slots']['identity.email'] ?? null, $plan['slots']['identity.phone'] ?? null], ['', '']);
$revision0 = $plan['revision'];
$bound = $door($E, 'bind', ['contract' => $idContract]);
check('the site binds', $bound['ok'] ?? $bound, true);
check('at the revision the inspect saw', $bound['revision'], $revision0);
check('and nothing was written to the identity row by binding', array_key_exists('tracy_identity', WP_Fake::$options), false);

// ── the first write creates it ─────────────────────────────────────────────────────────────

$applied = $door($E, 'apply', ['expected_revision' => $revision0, 'apply_id' => 'contract-id1', 'request_id' => 'rid1',
    'changes' => ['identity.email' => 'hello@acme.test']]);
check('an apply of one identity field lands', $applied['ok'] ?? $applied, true);
check('creating the option with that field alone', WP_Fake::$options['tracy_identity'] ?? null, ['email' => 'hello@acme.test']);
$after = $door($E, 'inspect');
check('inspect is clean after it', $after['problems'], []);
check('the written field reads back', $after['slots']['identity.email'], 'hello@acme.test');
check('a field the option does not hold reads empty', $after['slots']['identity.phone'], '');
check('at the revision the apply reported', $after['revision'], $applied['revision']);
$reverted = $call($E, 'apply.revert', ['apply_id' => 'contract-id1']);
check('the apply reverts', $reverted['ok'] ?? $reverted, true);
check('taking the option away again', array_key_exists('tracy_identity', WP_Fake::$options), false);
check('back at the revision the site was bound at', $door($E, 'inspect')['revision'], $revision0);

// ── only a site identity may be absent ─────────────────────────────────────────────────────

$t = contractSite($SITE, $ID_FIXTURES);
unset(WP_Fake::$options['blogname']);
$refused = $door($t['engine'], 'bind', ['contract' => $idContract]);
check('any other absent option still refuses the bind', $refused['error'] ?? 'ok', 'contract_failed');
checkTrue('naming it', in_array('Missing option: option-blogname', $refused['problems'] ?? [], true));

$t = contractSite($SITE, $FIXTURES);
unset(WP_Fake::$options['tracy_identity']);
$refused = $door($t['engine'], 'bind', ['contract' => 'test-design/wp7/1.0.0']);
check('an option of ordinary slots is not a site identity: absent, it refuses', $refused['error'] ?? 'ok', 'contract_failed');

$t = contractSite($SITE, $ID_FIXTURES);
WP_Fake::$options['tracy_identity'] = 'not an array';
$refused = $door($t['engine'], 'inspect', ['contract' => $idContract]);
checkTrue('a site identity that is not an array is still a problem', in_array('Option slot is not text: identity.email', $refused['problems'] ?? [], true));

// ── the shipped profiles that need it ──────────────────────────────────────────────────────

foreach (['1.2.0', '1.3.0', '1.3.1'] as $version) {
    $map = json_decode((string) file_get_contents(__DIR__ . '/../lib/contracts/tracy-business/wp7/' . $version . '/content-map.json'), true);
    $identitySlots = array_values(array_filter($map['slots'], static fn(array $slot): bool => $slot['entity'] === 'option-tracy_site_identity'));
    checkTrue("tracy-business/wp7/{$version}: every slot of tracy_site_identity is a site identity field",
        $identitySlots !== [] && count(array_filter($identitySlots, static fn(array $slot): bool =>
            ($slot['siteIdentity'] ?? false) === true && isset($slot['target']['field']))) === count($identitySlots));
}
