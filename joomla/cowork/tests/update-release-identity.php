<?php
// The package may be a dev RC while both public updater doors continue to announce one stable release.
function updateReleaseIdentityErrors(string $package, string $xml, string $json): array
{
    $errors = [];
    $stable = static fn(string $version): bool => preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D', $version) === 1;
    if (!$stable($xml) || !$stable($json)) $errors[] = 'feed-not-stable';
    if ($xml !== $json) $errors[] = 'feeds-disagree';
    if (strpos($package, '-') === false) {
        if ($xml !== $package || $json !== $package) $errors[] = 'stable-package-mismatch';
    } else {
        if (preg_match('/^[0-9]+\.[0-9]+\.[0-9]+-rc\.[0-9]+$/D', $package) !== 1) $errors[] = 'candidate-identity-invalid';
        if (!version_compare($xml, $package, '<') || !version_compare($json, $package, '<')) $errors[] = 'candidate-not-newer';
    }
    return $errors;
}
check('stable release identities require exact package and both updater versions', updateReleaseIdentityErrors('1.2.3', '1.2.3', '1.2.3'), []);
check('RC identity preserves one older stable announcement', updateReleaseIdentityErrors('1.2.4-rc.1', '1.2.3', '1.2.3'), []);
checkTrue('RC identity rejects mismatched stable updater doors', in_array('feeds-disagree', updateReleaseIdentityErrors('1.2.4-rc.1', '1.2.3', '1.2.2'), true));
checkTrue('stable package still rejects an older announcement', in_array('stable-package-mismatch', updateReleaseIdentityErrors('1.2.4', '1.2.3', '1.2.3'), true));
checkTrue('RC identity rejects announcing the candidate to stable updaters', in_array('feed-not-stable', updateReleaseIdentityErrors('1.2.4-rc.1', '1.2.4-rc.1', '1.2.4-rc.1'), true));
checkTrue('RC identity rejects a future stable announcement', in_array('candidate-not-newer', updateReleaseIdentityErrors('1.2.4-rc.1', '1.2.4', '1.2.4'), true));
checkTrue('only an explicit numbered RC may differ from stable updater doors', in_array('candidate-identity-invalid', updateReleaseIdentityErrors('1.2.4-preview', '1.2.3', '1.2.3'), true));
