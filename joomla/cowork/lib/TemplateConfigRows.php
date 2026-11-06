<?php
require_once __DIR__ . '/TemplateSiteSettings.php';
require_once __DIR__ . '/TemplateSiteFiles.php';

/**
 * TemplateConfigRows — a T4 template's layout and navigation profiles as content contract rows (kind `templateConfig`).
 *
 * T4 keeps a page's layout and its mega menu in FILES, `etc/layout/<profile>.json` and `etc/navigation/<profile>.json`
 * (read from `templates/<t>/local/` first), not in database rows. Visitors read words and pictures there: the column titles
 * of JA Vega's and JA Kinetic's mega menus ("Our Portfolio", "Company") are `mega_settings` of the navigation profile, a
 * section's background picture is `background_image` of a layout profile. A contract declares each profile file it binds as
 * an entity of identity {template, group, profile} whose one column, `value`, is the file's whole text; its slots point
 * into that text by `jsonPath` (and `nestedJson` for `mega_settings`, itself a JSON string).
 *
 * A row's id is a stable whole number made from its identity ({@see id()}), so a binding names it like any row. Only the
 * profiles a contract names are rows: a profile file is not inventory, and is never counted.
 *
 * Writing puts the new text into the profile's `local/` copy, never the template's file, and records the files' previous
 * bytes under the apply_id like the `siteSetting` rows do (op `siteSettings`).
 */
final class TemplateConfigRows
{
    public const KIND = 'templateConfig';
    public const GROUPS = ['layout', 'navigation'];

    private TemplateSiteFiles $files;

    public function __construct(TemplateSiteFiles $files)
    {
        $this->files = $files;
    }

    /** The row id of one profile file: 28 bits of the sha1 of its identity, never 0. Capture computes the same. */
    public static function id(string $template, string $group, string $profile): int
    {
        return hexdec(substr(sha1($template . '|' . $group . '|' . $profile), 0, 7)) + 1;
    }

    /**
     * The rows of the profiles a contract names, by id. A profile no file holds, or whose file is not a JSON object, has no
     * row (the contract's entity is then missing).
     *
     * @param list<array{template:string,group:string,profile:string}> $wanted
     * @return array<int,array{id:int,template:string,group:string,profile:string,value:string}>
     */
    public function rows(array $wanted): array
    {
        $rows = [];
        foreach ($wanted as $identity) {
            [$template, $group, $profile] = self::identity($identity);
            $file = $this->files->configProfile($template, $group, $profile);
            if ($file === null || !self::isObject($file['bytes'])) continue;
            $id = self::id($template, $group, $profile);
            if (isset($rows[$id])) throw new RuntimeException('Two template profiles share the row id ' . $id);
            $rows[$id] = ['id' => $id, 'template' => $template, 'group' => $group, 'profile' => $profile, 'value' => $file['bytes']];
        }
        ksort($rows);
        return $rows;
    }

    /**
     * The file change that makes `$row` read `$value`, a JSON object (T4 would read anything else as an empty profile).
     * Null when T4 already reads that text.
     *
     * @return array{path:string,before:?string,after:string}|null
     */
    public function change(array $row, string $value): ?array
    {
        [$template, $group, $profile] = self::identity($row);
        if (!self::isObject($value)) throw new RuntimeException('A template profile must be a JSON object: ' . $group . ' ' . $profile);
        return $this->files->configChange($template, $group, $profile, $value);
    }

    /** @return array{0:string,1:string,2:string} */
    private static function identity(array $identity): array
    {
        $template = (string) ($identity['template'] ?? '');
        $group = (string) ($identity['group'] ?? '');
        $profile = (string) ($identity['profile'] ?? '');
        if (!preg_match(TemplateSiteSettings::TEMPLATE, $template) || !in_array($group, self::GROUPS, true) || !preg_match(TemplateSiteSettings::PROFILE, $profile))
            throw new RuntimeException('Not a template profile: ' . substr($template . ' ' . $group . ' ' . $profile, 0, 160));
        return [$template, $group, $profile];
    }

    private static function isObject(string $text): bool
    {
        $decoded = json_decode($text);
        return $decoded instanceof stdClass;
    }
}
