<?php
/**
 * Run inside a Joomla container, from the site root: compares a release manifest with the files on
 * disk and prints one JSON object.
 *
 *   php verify-install.php <path of tracy-release.json, relative to the site root> [extra paths…]
 *
 * - `mismatched`: manifest entries whose file is missing or whose bytes hash differently;
 * - `unlisted`: files under the manifest's `roots` that the manifest does not name (left over from an
 *   earlier release, or put there by someone else);
 * - `present`: for each extra path given, whether it exists.
 */
declare(strict_types=1);

$manifestPath = $argv[1] ?? '';
$manifest = json_decode((string) @file_get_contents($manifestPath), true);
if (!is_array($manifest) || !is_array($manifest['files'] ?? null)) {
    fwrite(STDERR, "no manifest at {$manifestPath}\n");
    exit(1);
}

$mismatched = [];
foreach ($manifest['files'] as $path => $sha) {
    if (!is_file($path)) {
        $mismatched[$path] = 'missing';
    } elseif (!hash_equals($sha, hash_file('sha256', $path))) {
        $mismatched[$path] = 'hash';
    }
}

$unlisted = [];
foreach ($manifest['roots'] ?? [] as $root) {
    if (!is_dir($root)) continue;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        $path = str_replace('\\', '/', $file->getPathname());
        if ($path === $manifest['path'] || isset($manifest['files'][$path])) continue;
        $unlisted[] = $path;
    }
}
sort($unlisted);

$present = [];
foreach (array_slice($argv, 2) as $extra) {
    $present[$extra] = file_exists($extra);
}

echo json_encode([
    'tag' => $manifest['tag'] ?? null,
    'listed' => count($manifest['files']),
    'mismatched' => $mismatched,
    'unlisted' => $unlisted,
    'present' => $present,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
