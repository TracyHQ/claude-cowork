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

    public function core_manifest(): array
    {
        return [
            'platform'        => 'wordpress',
            'platformVersion' => '6.6',
            'extensions'      => [
                ['type' => 'theme', 'element' => 'twentytwentytwo', 'core' => true, 'enabled' => false, 'version' => '1.9'],
                // The case the name-prefix heuristic gets wrong: a child theme wearing the name.
                ['type' => 'theme', 'element' => 'twentyfive-child', 'core' => false, 'enabled' => true, 'version' => '1.0'],
            ],
        ];
    }

    /** What `self_update` should answer, per case. Set by the test before it calls. */
    public static array $selfUpdate = ['ok' => true, 'updated' => true, 'before' => '0.6.1', 'after' => '0.6.2'];

    public function self_update(): array
    {
        return self::$selfUpdate;
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

    /** The variations this fake theme ships, and the one it is wearing. */
    public static array $styles = ['airbnb', 'apple'];
    public static ?string $wornStyle = null;

    public function wear_style(string $style): array
    {
        if (!preg_match('/^[a-z0-9-]+$/', $style)) {
            return ['ok' => false, 'error' => 'style must be a-z, 0-9 and dashes'];
        }
        if (!in_array($style, self::$styles, true)) {
            return ['ok' => false, 'error' => "the theme has no styles/{$style}.json"];
        }
        self::$wornStyle = $style;
        return ['ok' => true, 'style' => $style, 'post' => 7];
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
