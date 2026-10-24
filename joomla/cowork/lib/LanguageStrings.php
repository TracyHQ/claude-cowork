<?php
require_once __DIR__ . '/LanguageOverrides.php';

/**
 * LanguageStrings — a template's language strings as content contract rows (kind `languageString`).
 *
 * A template keeps some of the words a visitor reads in its language file, not in a database row: JA Boxing's
 * "Try a class" button is `Text::_('TPL_TRY_CLASS')`, its contact form heading "LEAVE US A MESSAGE" is
 * TPL_CONTACT_EMAIL_FORM, the learning page's heading is `TPL_LEARNING_LEAD_TITLE="The 6 <span>punches</span>"`
 * (TCH coverage audit of JA Boxing 1.0.2, 24/10/2026). A contract declares each one as an entity of identity
 * {extension, key, language} (`tpl_ja_boxing`, `TPL_TRY_CLASS`, `en-GB`; `tpl_ja_boxing.sys` for the template's
 * `.sys.ini`) whose one column is `value`, the string a visitor reads now:
 *   1. the site's override (`language/overrides/<tag>.override.ini`), when it sets the key: Joomla loads it last;
 *   2. else the extension's language file Joomla loads: for a template the SITE's copy
 *      (`language/<tag>/<tag>.tpl_x.ini`) when there is one, and only then the template's own
 *      (`templates/x/language/<tag>/...`): SiteApplication loads `tpl_x` from JPATH_BASE first and stops there;
 *   3. else the key itself: Joomla prints a key it has no string for (JA Boxing's TPL_FOLLOW_US).
 * In each folder the plain name (`tpl_x.ini`) is read before the language-prefixed one (`<tag>.tpl_x.ini`).
 *
 * A row's id is a stable whole number made from its identity ({@see id()}), so a binding names it like any row.
 * Only the keys a contract names are rows: a language file is not inventory, and is never counted.
 *
 * Writing is the override file's, never a language file's ({@see change()}): the template and the site's language
 * files stay what the release shipped, and a revert puts the override file's previous bytes back.
 */
final class LanguageStrings
{
    public const KIND = 'languageString';
    /** A template's language file stem: `tpl_<template>`, or its `.sys` file. */
    private const EXTENSION = '/^tpl_([A-Za-z0-9_-]{1,60})(\.sys)?$/D';

    private string $root;

    public function __construct(string $root)
    {
        $this->root = rtrim($root, '/');
    }

    /** The row id of one string: 28 bits of the sha1 of its identity, never 0. Capture computes the same. */
    public static function id(string $extension, string $language, string $key): int
    {
        return hexdec(substr(sha1($extension . '|' . $language . '|' . strtoupper($key)), 0, 7)) + 1;
    }

    /**
     * The rows of the strings a contract names, by id.
     *
     * @param list<array{extension:string,language:string,key:string}> $wanted
     * @return array<int,array{id:int,extension:string,key:string,language:string,value:string}>
     */
    public function rows(array $wanted): array
    {
        $files = [];
        $overrides = [];
        $rows = [];
        foreach ($wanted as $identity) {
            $extension = (string) ($identity['extension'] ?? '');
            $language = (string) ($identity['language'] ?? '');
            $key = strtoupper((string) ($identity['key'] ?? ''));
            if (!preg_match(self::EXTENSION, $extension) || !preg_match(LanguageOverrides::LOCALE, $language) || !preg_match(LanguageOverrides::KEY, $key))
                throw new RuntimeException('Not a template language string: ' . substr($extension . ' ' . $key . ' ' . $language, 0, 160));
            $strings = $files[$extension . '|' . $language] ??= $this->strings($extension, $language);
            $override = $overrides[$language] ??= LanguageOverrides::parse((string) $this->bytes(LanguageOverrides::path($language)));
            $id = self::id($extension, $language, $key);
            if (isset($rows[$id]) && ($rows[$id]['extension'] !== $extension || $rows[$id]['key'] !== $key || $rows[$id]['language'] !== $language))
                throw new RuntimeException('Two language strings share the row id ' . $id);
            $rows[$id] = ['id' => $id, 'extension' => $extension, 'key' => $key, 'language' => $language,
                'value' => $override[$key] ?? $strings[$key] ?? $key];
        }
        ksort($rows);
        return $rows;
    }

    /**
     * The override file change that makes `$row` read `$value`: the key's line set in its language's file, every
     * other line kept ({@see LanguageOverrides::render()}). Null when the file already says so.
     *
     * @return array{path:string,before:?string,after:string}|null
     */
    public function change(array $row, string $value): ?array
    {
        if (preg_match('/[\r\n\x00]/', $value)) throw new RuntimeException('A language string is one line: ' . $row['key']);
        return (new LanguageOverrides($this->root))->change((string) $row['language'], [(string) $row['key'] => $value]);
    }

    /** The strings of the file Joomla loads for an extension in a language, or none. */
    private function strings(string $extension, string $language): array
    {
        preg_match(self::EXTENSION, $extension, $m);
        foreach (['language/' . $language, 'templates/' . $m[1] . '/language/' . $language] as $folder)
            foreach ([$extension . '.ini', $language . '.' . $extension . '.ini'] as $name) {
                $bytes = $this->bytes($folder . '/' . $name);
                if ($bytes !== null) return LanguageOverrides::parse($bytes);
            }
        return [];
    }

    private function bytes(string $relative): ?string
    {
        $file = $this->root . '/' . $relative;
        if (!is_file($file) || is_link($file)) return null;
        $bytes = @file_get_contents($file);
        return $bytes === false ? null : $bytes;
    }
}
