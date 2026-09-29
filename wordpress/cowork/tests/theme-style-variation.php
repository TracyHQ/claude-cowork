<?php
/**
 * theme.style with a variation Tracy made at build time (the customer's brand): the document is
 * checked, written under uploads/tracy/, registered in uploads/tracy/inspirations.json, and then
 * worn; a style the theme ships needs no variation; a variation of the wrong shape is refused before
 * anything is written. The static helpers run against the REAL packages class over a handful of
 * WordPress functions faked here; the engine road runs over FakePackages.
 *
 * Loaded by run.php. Uses `check()` / `checkTrue()` and the engine built over FakePackages in run.php.
 */
declare(strict_types=1);

echo "\nTheme style with a variation (brand on WordPress)\n";

$tsvRoot = sys_get_temp_dir() . '/cc-tsv-' . bin2hex(random_bytes(4));
mkdir($tsvRoot . '/theme/styles', 0777, true);
mkdir($tsvRoot . '/uploads', 0777, true);
file_put_contents($tsvRoot . '/theme/styles/apple.json', '{"version":3,"settings":{}}');

if (!function_exists('wp_upload_dir')) {
    function wp_upload_dir(): array
    {
        global $tsvRoot;
        return ['basedir' => $tsvRoot . '/uploads'];
    }
}
if (!function_exists('wp_mkdir_p')) {
    function wp_mkdir_p(string $dir): bool
    {
        return is_dir($dir) || mkdir($dir, 0777, true);
    }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, int $flags = 0): string
    {
        return (string) json_encode($data, $flags);
    }
}
if (!function_exists('wp_json_file_decode')) {
    function wp_json_file_decode(string $file, array $o = [])
    {
        return json_decode((string) file_get_contents($file), true);
    }
}
if (!function_exists('get_theme_file_path')) {
    function get_theme_file_path(string $f): string
    {
        global $tsvRoot;
        return $tsvRoot . '/theme/' . $f;
    }
}
require_once __DIR__ . '/../lib/class-claude-cowork-packages.php';

check('a theme.json v3 object is a variation', Claude_Cowork_Packages::variation_problem(['version' => 3, 'settings' => ['color' => []]]), null);
check('a string is not', Claude_Cowork_Packages::variation_problem('apple'), 'variation must be a theme.json object');
check('version 2 is not', Claude_Cowork_Packages::variation_problem(['version' => 2, 'settings' => []]), 'variation.version must be 3');
check('no settings is not', Claude_Cowork_Packages::variation_problem(['version' => 3]), 'variation.settings must be an object');

check('a shipped style resolves in the theme', Claude_Cowork_Packages::variation_file('apple'), $tsvRoot . '/theme/styles/apple.json');
check('an unknown style resolves nowhere', Claude_Cowork_Packages::variation_file('brand-acme-example'), null);

$staged = Claude_Cowork_Packages::stage_variation(
    'brand-acme-example',
    ['version' => 3, 'title' => 'Acme Tools', 'settings' => ['color' => ['palette' => [['slug' => 'accent', 'color' => '#b7410e']]]]],
    ['name' => 'Acme Tools', 'category' => 'Your brand', 'nav' => 'top-left', 'hero' => 'split', 'dark' => false]
);
check('a staged variation lands under uploads/tracy/styles', $staged, ['ok' => true, 'file' => $tsvRoot . '/uploads/tracy/styles/brand-acme-example.json']);
check('and now resolves — after the theme, which does not have it', Claude_Cowork_Packages::variation_file('brand-acme-example'), $tsvRoot . '/uploads/tracy/styles/brand-acme-example.json');
$written = json_decode((string) file_get_contents($tsvRoot . '/uploads/tracy/styles/brand-acme-example.json'), true);
check('the document is written whole', $written['settings']['color']['palette'][0]['color'], '#b7410e');
$index = json_decode((string) file_get_contents($tsvRoot . '/uploads/tracy/inspirations.json'), true);
check('and registered for the theme to list', $index['systems']['brand-acme-example'], ['name' => 'Acme Tools', 'category' => 'Your brand', 'nav' => 'top-left', 'hero' => 'split', 'dark' => false]);
Claude_Cowork_Packages::stage_variation('brand-other-example', ['version' => 3, 'settings' => []], []);
$index = json_decode((string) file_get_contents($tsvRoot . '/uploads/tracy/inspirations.json'), true);
check('a second brand joins the index rather than replacing it', array_keys($index['systems']), ['brand-acme-example', 'brand-other-example']);
check('a row with no meta takes the title and the defaults', $index['systems']['brand-other-example'], ['name' => 'brand-other-example', 'category' => 'Your brand', 'nav' => 'top-left', 'hero' => 'split', 'dark' => false]);

// The engine road, over FakePackages: the variation and the meta reach wear_style whole.
FakePackages::$wornVariation = null;
$worn = $pkgEngine->handle(['token' => 'a-token-at-least-16', 'action' => 'theme.style', 'params' => [
    'theme' => 'tracy', 'style' => 'brand-acme-example',
    'variation' => ['version' => 3, 'title' => 'Acme Tools', 'settings' => []],
    'meta' => ['name' => 'Acme Tools', 'category' => 'Your brand'],
]]);
check('theme.style with a variation is worn', [$worn['ok'] ?? null, $worn['style'] ?? null], [true, 'brand-acme-example']);
check('the variation arrived whole', FakePackages::$wornVariation['title'] ?? null, 'Acme Tools');
check('and so did the meta', FakePackages::$wornMeta['category'] ?? null, 'Your brand');
$bad = $pkgEngine->handle(['token' => 'a-token-at-least-16', 'action' => 'theme.style', 'params' => ['theme' => 'tracy', 'style' => 'x', 'variation' => 'apple']]);
check('a variation of the wrong shape is bad_params, before the site is touched', $bad['error'] ?? null, 'bad_params');
$plain = $pkgEngine->handle(['token' => 'a-token-at-least-16', 'action' => 'theme.style', 'params' => ['theme' => 'tracy', 'style' => 'airbnb']]);
check('a shipped style still needs no variation', $plain['ok'] ?? null, true);
