<?php
require_once __DIR__ . '/TemplateSiteSettings.php';
require_once __DIR__ . '/TemplateSiteFiles.php';

/**
 * SiteSettingRows — a T4 template's logos as content contract rows (kind `siteSetting`).
 *
 * A T4 template keeps its logos in a FILE, `etc/site/<profile>.json` (read from `templates/<t>/local/` first), not in a
 * database row: JA Boxing's light logo `item-2.png` is `site_logo` of its `default` profile and is printed on every page
 * (TCH coverage audit of JA Boxing 1.0.3, 24/10/2026). A contract declares each picture key a profile sets as an entity of
 * identity {template, profile, key} whose one column, `value`, is what the profile holds now (the raw string, a media field's
 * `#joomlaImage:` suffix included).
 *
 * A row's id is a stable whole number made from its identity ({@see id()}), so a binding names it like any row. Only the keys
 * a contract names are rows: a settings file is not inventory, and is never counted.
 *
 * Writing is the door's own (`template.siteSettings`): the value goes into the profile's `local/` copy, never the template's
 * file ({@see TemplateSiteFiles::profileChange()}), and the files' previous bytes are recorded under the apply_id.
 */
final class SiteSettingRows
{
    public const KIND = 'siteSetting';
    /** The keys of {@see TemplateSiteSettings::T4_KEYS} that hold a picture. */
    public const KEYS = ['site_logo', 'site_logo_small', 'site_logo_dark', 'site_logo_dark_small', 'site_logo_2'];

    private TemplateSiteFiles $files;

    public function __construct(TemplateSiteFiles $files)
    {
        $this->files = $files;
    }

    /** The row id of one setting: 28 bits of the sha1 of its identity, never 0. Capture computes the same. */
    public static function id(string $template, string $profile, string $key): int
    {
        return hexdec(substr(sha1($template . '|' . $profile . '|' . $key), 0, 7)) + 1;
    }

    /**
     * The rows of the settings a contract names, by id. A profile no file holds, or a key it does not set, has no row (the
     * contract's entity is then missing).
     *
     * @param list<array{template:string,profile:string,key:string}> $wanted
     * @return array<int,array{id:int,template:string,profile:string,key:string,value:string}>
     */
    public function rows(array $wanted): array
    {
        $rows = [];
        $profiles = [];
        foreach ($wanted as $identity) {
            $template = (string) ($identity['template'] ?? '');
            $profile = (string) ($identity['profile'] ?? '');
            $key = (string) ($identity['key'] ?? '');
            if (!preg_match(TemplateSiteSettings::TEMPLATE, $template) || !preg_match(TemplateSiteSettings::PROFILE, $profile) || !in_array($key, self::KEYS, true))
                throw new RuntimeException('Not a site setting: ' . substr($template . ' ' . $profile . ' ' . $key, 0, 160));
            $settings = $profiles[$template . '|' . $profile] ??= $this->files->profileSettings($template, $profile);
            if ($settings === null || !isset($settings[$key])) continue;
            $id = self::id($template, $profile, $key);
            if (isset($rows[$id])) throw new RuntimeException('Two site settings share the row id ' . $id);
            $rows[$id] = ['id' => $id, 'template' => $template, 'profile' => $profile, 'key' => $key, 'value' => $settings[$key]];
        }
        ksort($rows);
        return $rows;
    }

    /**
     * The profile file change that makes `$row` read `$value`: an empty value or a path to a picture on the site, as
     * `template.siteSettings` accepts. Null when the profile already says so.
     *
     * @return array{path:string,before:?string,after:string}|null
     */
    public function change(array $row, string $value): ?array
    {
        $cleaned = TemplateSiteSettings::clean((string) $row['key'], $value, $this->files->root());
        if (isset($cleaned['error'])) throw new RuntimeException($cleaned['error']);
        return $this->files->profileChange((string) $row['template'], (string) $row['profile'], (string) $row['key'], $cleaned['value']);
    }
}
