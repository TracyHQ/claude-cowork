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

    /** Locales this fake WordPress already has on disk, and the ones api.wordpress.org offers. */
    public static array $haveLocales = ['en_GB'];
    public static array $offeredLocales = ['vi', 'pt_BR', 'en_GB', 'fr_FR'];
    /** Set to a sentence to make the download fail the way a hardened host does. */
    public static ?string $languageRefusal = null;

    public function install_language(string $locale): array
    {
        if (in_array($locale, self::$haveLocales, true)) {
            return ['ok' => true, 'locale' => $locale, 'already' => true];
        }
        if (self::$offeredLocales === []) {
            return ['ok' => false, 'error' => 'the list of translations could not be read from api.wordpress.org'];
        }
        if (!in_array($locale, self::$offeredLocales, true)) {
            return ['ok' => false, 'error' => "WordPress offers no translation for {$locale}"];
        }
        if (self::$languageRefusal !== null) {
            return ['ok' => false, 'error' => self::$languageRefusal];
        }
        self::$haveLocales[] = $locale;
        return ['ok' => true, 'locale' => $locale, 'already' => false];
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

    /** The palette a site renders with — theme values, with any override already merged in. */
    public static $palette = [
        ['slug' => 'primary', 'color' => '#111827', 'name' => 'Primary'],
        ['slug' => 'contrast', 'color' => '#ffffff', 'name' => 'Contrast'],
    ];

    public function palette(): array
    {
        return self::$palette;
    }

    public function set_palette($pairs): array
    {
        if (!is_array($pairs) || $pairs === []) {
            return ['ok' => false, 'error' => 'no colours were named'];
        }
        $was = [];
        $changed = [];
        foreach ($pairs as $slug => $color) {
            $found = false;
            foreach (self::$palette as $index => $entry) {
                if ($entry['slug'] === (string) $slug) {
                    $was[$slug] = $entry['color'];
                    self::$palette[$index]['color'] = $color;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $was[$slug] = '';
                self::$palette[] = ['slug' => (string) $slug, 'color' => $color, 'name' => (string) $slug];
            }
            $changed[$slug] = $color;
        }
        return ['ok' => true, 'changed' => $changed, 'was' => $was, 'post' => 7];
    }

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
