<?php
/**
 * Stands in for `Claude_Cowork_Packages` so the engine's own decisions can be tested without
 * WordPress: which action maps to what, what is refused before the site is touched, and what the
 * reply carries. The real class is a thin wrapper over `Plugin_Upgrader` / `Theme_Upgrader` and
 * `switch_theme` — testing those would be testing WordPress.
 */
final class FakePackages
{
    public static string $active = 'tracy';
    /** @var array<string,bool> stylesheet → exists */
    public static array $themes = ['tracy' => true, 'twentytwentytwo' => false];

    /** Plugin files currently switched on, so the fake can answer was_active. */
    public static array $activePlugins = [];

    public function list_plugins(): array
    {
        return [['file' => 'akismet/akismet.php', 'name' => 'Akismet', 'version' => '5.0', 'active' => true]];
    }

    public function list_themes(): array
    {
        return [['stylesheet' => 'twentytwentytwo', 'name' => 'Twenty Twenty-Two', 'version' => '1.9', 'active' => false]];
    }

    public function install_plugin(string $url): array
    {
        $shape = self::checkUrl($url);
        return $shape ?: ['ok' => true, 'file' => 'hello/hello.php', 'name' => 'Hello', 'version' => '1.0'];
    }

    public function install_theme(string $url): array
    {
        $shape = self::checkUrl($url);
        if ($shape) {
            return $shape;
        }
        self::$themes['twentytwentytwo'] = true;
        return ['ok' => true, 'stylesheet' => 'twentytwentytwo', 'name' => 'Twenty Twenty-Two', 'version' => '1.9'];
    }

    public function activate_plugin_file(string $file): array
    {
        if ($file !== 'akismet/akismet.php') {
            return ['ok' => false, 'error' => "no such plugin: {$file}"];
        }
        $wasActive = in_array($file, self::$activePlugins, true);
        if (!$wasActive) {
            self::$activePlugins[] = $file;
        }
        return ['ok' => true, 'was_active' => $wasActive];
    }

    public function activate_theme(string $stylesheet): array
    {
        if (empty(self::$themes[$stylesheet])) {
            return ['ok' => false, 'error' => "no such theme: {$stylesheet}"];
        }
        $previous = self::$active;
        self::$active = $stylesheet;
        return ['ok' => true, 'previous' => $previous];
    }

    /** The same shape rule the real class applies, so the engine's refusals are exercised. */
    private static function checkUrl(string $url): ?array
    {
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return ['ok' => false, 'error' => 'not a URL'];
        }
        if (strtolower((string) $parts['scheme']) !== 'https') {
            return ['ok' => false, 'error' => 'https required'];
        }
        if (substr(strtolower((string) ($parts['path'] ?? '')), -4) !== '.zip') {
            return ['ok' => false, 'error' => 'package URL must end in .zip'];
        }
        return null;
    }
}
