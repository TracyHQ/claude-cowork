<?php
/**
 * LanguageOverrides — the disk side of `template.languageOverrides`: which front-end language strings of a site
 * still read in English for a language, and the site's language override file for that language.
 *
 * A JoomlArt template ships its own words in en-GB only (`TPL_SHARE_ARTICLE="Share article:"`), and a language pack
 * covers Joomla's own words but not a template's, and not every key a newer Joomla added: a site whose default
 * language is vi-VN still printed "Share article:", "Tags in:" and "Author's latest articles" (TracyHQ/tch#1013,
 * measured 10/10/2026 on JA Essence j6). Joomla reads `language/overrides/<tag>.override.ini` after every other
 * language file, so a translated string written there wins for every template and extension, and nothing the
 * template or the pack ships is edited.
 *
 * What is offered for a language (`plan`): every key of the template's own front-end language files, and every key
 * of the site's other front-end language files, whose `<tag>` value is
 *   - missing (the pack has no such key or file: Joomla shows the en-GB words),
 *   - the en-GB words themselves (`untranslated`), or
 *   - implausibly long for its en-GB words (`suspect`: vi-VN 4.2.2.1 ships COM_CONTENT_ARTICLE_INFO, "Details",
 *     as "Banner được lưu thành công.", the translation of another string).
 * Values holding markup are never offered (a translator cannot be trusted to keep it), nor `.sys.ini` strings
 * (administrator only). The file it writes is that one file; its previous bytes are what undoes it.
 */
final class LanguageOverrides
{
    public const TEMPLATE = '/^[A-Za-z0-9_-]{1,60}$/D';
    public const LOCALE = '/^[a-z]{2,3}-[A-Z]{2,4}$/D';
    public const KEY = '/^[A-Z0-9_][A-Z0-9_.\-]{0,119}$/D';
    /** The longest value written. */
    public const MAX_VALUE = 2000;
    /** The most strings one plan offers: the template's first. */
    public const MAX_STRINGS = 1500;
    private const SOURCE = 'en-GB';

    private string $root;

    /** @param string $root the site root (JPATH_ROOT) */
    public function __construct(string $root)
    {
        $this->root = rtrim($root, '/');
    }

    /** Whether a site template of that folder name is installed. */
    public function hasTemplate(string $template): bool
    {
        return preg_match(self::TEMPLATE, $template) === 1 && is_file($this->root . '/templates/' . $template . '/templateDetails.xml');
    }

    /** The override file of a language, site-relative. */
    public static function path(string $locale): string
    {
        return 'language/overrides/' . $locale . '.override.ini';
    }

    /**
     * The strings that read in en-GB on the front end in `$locale`, and the overrides the site holds for it now.
     *
     * @return array{strings:list<array{key:string,source:string,reason:string,file:string}>,overrides:array<string,string>,truncated:bool}
     */
    public function plan(string $template, string $locale): array
    {
        $current = self::parse((string) $this->bytes(self::path($locale)));
        $offered = [];
        $truncated = false;
        foreach ($this->sourceFiles($template) as $file => $counterparts) {
            $english = self::parse((string) $this->bytes($file));
            $own = [];
            foreach ($counterparts as $candidate) {
                $bytes = $this->bytes(str_replace('%LOCALE%', $locale, $candidate));
                if ($bytes !== null) { $own = self::parse($bytes); break; }
            }
            foreach ($english as $key => $source) {
                if (isset($offered[$key]) || !preg_match(self::KEY, $key)) continue;
                if (!self::worded($source)) continue;
                $reason = !array_key_exists($key, $own) ? 'missing'
                    : (trim($own[$key]) === trim($source) ? 'untranslated' : (self::suspect($source, $own[$key]) ? 'suspect' : null));
                if ($reason === null) continue;
                if (count($offered) >= self::MAX_STRINGS) { $truncated = true; break 2; }
                $offered[$key] = ['key' => $key, 'source' => $source, 'reason' => $reason, 'file' => $file];
            }
        }
        return ['strings' => array_values($offered), 'overrides' => $current, 'truncated' => $truncated];
    }

    /**
     * The file a set would write: `$values` set in the language's override file, every other line kept. Null when
     * the bytes would not change.
     *
     * @param array<string,string> $values clean already
     * @return array{path:string,before:?string,after:string}|null
     */
    public function change(string $locale, array $values): ?array
    {
        $path = self::path($locale);
        $before = $this->bytes($path);
        $after = self::render($before ?? '', $values);
        return $after === $before ? null : ['path' => $path, 'before' => $before, 'after' => $after];
    }

    /**
     * Write the planned file, creating `language/overrides/` when it is missing. Answers what undoes it.
     *
     * @param array{path:string,before:?string,after:string} $change
     * @return list<array{path:string,before:?string,made:list<string>}>
     */
    public function write(array $change): array
    {
        $path = $change['path'];
        $this->assertDoorFile($path);
        $made = [];
        if (!is_dir($this->root . '/language/overrides')) {
            if (!@mkdir($this->root . '/language/overrides', 0755, true)) throw new RuntimeException('Could not create language/overrides: the web server cannot write the language folder');
            $made[] = 'language/overrides';
        }
        if (@file_put_contents($this->root . '/' . $path, $change['after'], LOCK_EX) !== strlen($change['after'])) {
            $this->restore([['path' => $path, 'before' => $change['before'], 'made' => $made]]);
            throw new RuntimeException('Could not write ' . $path . ': the web server cannot write the language folder');
        }
        return [['path' => $path, 'before' => $change['before'], 'made' => $made]];
    }

    /**
     * Undo what {@see write()} recorded: the previous bytes back, or the file deleted (and the folder made for it,
     * when empty). An undo row is data: a path that is not an override file is refused.
     *
     * @param list<array<string,mixed>> $files
     */
    public function restore(array $files): void
    {
        foreach (array_reverse($files) as $entry) {
            $path = is_array($entry) && is_string($entry['path'] ?? null) ? $entry['path'] : '';
            $this->assertDoorFile($path);
            $before = $entry['before'] ?? null;
            if ($before !== null && !is_string($before)) throw new RuntimeException('Unreadable undo step for ' . $path);
            $file = $this->root . '/' . $path;
            if ($before === null) {
                if (is_file($file) && !@unlink($file)) throw new RuntimeException('Could not delete ' . $path);
                foreach (is_array($entry['made'] ?? null) ? $entry['made'] : [] as $folder)
                    if ($folder === 'language/overrides' && is_dir($this->root . '/' . $folder) && !is_link($this->root . '/' . $folder)) @rmdir($this->root . '/' . $folder);
                continue;
            }
            if (!is_dir(dirname($file)) && !@mkdir(dirname($file), 0755, true)) throw new RuntimeException('Could not create the folder of ' . $path);
            if (@file_put_contents($file, $before, LOCK_EX) !== strlen($before)) throw new RuntimeException('Could not write ' . $path);
        }
    }

    /**
     * Why a translated value cannot be written for `$source`, or null: one line of plain text within MAX_VALUE,
     * carrying the same placeholders (`%s`, `%1$s`, `%d`, `%date%`) as the en-GB words.
     */
    public static function refusal(string $source, $value): ?string
    {
        if (!is_string($value) || trim($value) === '') return 'not a non-empty string';
        if (mb_strlen($value) > self::MAX_VALUE) return 'longer than ' . self::MAX_VALUE . ' characters';
        if (preg_match('/[\r\n\x00]/', $value)) return 'more than one line';
        if (preg_match('/[<>]/', $value)) return 'markup is not written';
        if (self::placeholders($value) !== self::placeholders($source)) return 'its placeholders are not the en-GB ones';
        return null;
    }

    /** The placeholders of a language string, sorted: `%s`, `%1$s`, `%d`, `%date%`, `%sitename%`. */
    public static function placeholders(string $text): array
    {
        preg_match_all('/%(?:\d+\$)?[sdfu]|%[a-z_]+%/i', $text, $m);
        $out = $m[0];
        sort($out);
        return $out;
    }

    /**
     * A language file's strings as Joomla reads them (LanguageHelper::parseIniFile: raw scanner, `_QQ_` and `\"` as
     * quotes); an unreadable file has none.
     *
     * @return array<string,string>
     */
    public static function parse(string $bytes): array
    {
        if (trim($bytes) === '') return [];
        $strings = @parse_ini_string(str_replace('_QQ_', '"\""', $bytes), false, INI_SCANNER_RAW);
        if (!is_array($strings)) return [];
        $out = [];
        foreach ($strings as $key => $value) if (is_string($key) && is_scalar($value)) $out[strtoupper($key)] = str_replace('\"', '"', (string) $value);
        return $out;
    }

    /**
     * An override file with `$values` set: a line that sets one of these keys is replaced, the others are added at
     * the end, every other line (another override, a comment) is kept byte for byte.
     *
     * @param array<string,string> $values
     */
    public static function render(string $before, array $values): string
    {
        $lines = $before === '' ? [] : preg_split('/\r\n|\n|\r/', rtrim($before, "\r\n"));
        $left = $values;
        foreach ($lines as $i => $line) {
            if (!preg_match('/^\s*([A-Za-z0-9_.\-]+)\s*=/', $line, $m)) continue;
            $key = strtoupper($m[1]);
            if (!array_key_exists($key, $left)) continue;
            $lines[$i] = self::line($key, $left[$key]);
            unset($left[$key]);
        }
        foreach ($left as $key => $value) $lines[] = self::line($key, $value);
        return $lines === [] ? '' : implode("\n", $lines) . "\n";
    }

    private static function line(string $key, string $value): string
    {
        return $key . '="' . str_replace('"', '\"', str_replace('\"', '"', $value)) . '"';
    }

    /** Words a visitor reads: a letter, and no markup a translator could break. */
    private static function worded(string $source): bool
    {
        return preg_match('/\p{L}/u', $source) === 1 && preg_match('/[<>]/', $source) !== 1;
    }

    /**
     * A translation far longer than its words could ever need: the translation of another string, pasted in.
     * Measured on vi-VN 4.2.2.1: "Details" (7) as "Banner được lưu thành công." (27); a true translation of a short
     * label stays under two and a half times its length plus a word.
     */
    private static function suspect(string $source, string $translated): bool
    {
        return mb_strlen(trim($translated)) > 2.5 * mb_strlen(trim($source)) + 6;
    }

    /**
     * The en-GB front-end language files of the site, the template's first, each with where its `$locale` copy would
     * be (J4 names, `com_content.ini`, and the older prefixed ones, `en-GB.com_content.ini`).
     *
     * @return array<string,list<string>> site-relative source file => its locale counterparts, `%LOCALE%` for the tag
     */
    private function sourceFiles(string $template): array
    {
        $own = [];
        $other = [];
        $folders = ['templates/' . $template . '/language/' . self::SOURCE => 'templates/' . $template . '/language/', 'language/' . self::SOURCE => 'language/'];
        foreach ($folders as $folder => $base) {
            $dir = $this->root . '/' . $folder;
            if (!is_dir($dir) || is_link($dir)) continue;
            $names = @scandir($dir) ?: [];
            sort($names);
            foreach ($names as $name) {
                if (!preg_match('/^(?:en-GB\.)?([a-z0-9_.\-]+)\.ini$/i', $name, $m) || preg_match('/\.sys\.ini$/i', $name)) continue;
                $extension = $m[1];
                if (preg_match('/^tpl_(.+)$/i', $extension, $t) && strcasecmp($t[1], $template) !== 0) continue;
                $file = $folder . '/' . $name;
                if (is_link($this->root . '/' . $file) || !is_file($this->root . '/' . $file)) continue;
                $counterparts = [];
                foreach (['language/', $base] as $where)
                    foreach (['{L}/' . $extension . '.ini', '{L}/{L}.' . $extension . '.ini'] as $form)
                        $counterparts[] = $where . str_replace('{L}', '%LOCALE%', $form);
                $counterparts = array_values(array_unique($counterparts));
                if (strcasecmp($extension, 'tpl_' . $template) === 0) $own[$file] = $counterparts;
                else $other[$file] = $counterparts;
            }
        }
        return $own + $other;
    }

    /** A site-relative file's bytes, or null when it is not a plain file. */
    private function bytes(string $relative): ?string
    {
        $file = $this->root . '/' . $relative;
        if (!is_file($file) || is_link($file)) return null;
        $bytes = @file_get_contents($file);
        return $bytes === false ? null : $bytes;
    }

    /** Refuse any path that is not an override file, or that reaches one through a link. */
    private function assertDoorFile(string $path): void
    {
        if (!preg_match('~^language/overrides/[a-z]{2,3}-[A-Z]{2,4}\.override\.ini$~D', $path)) throw new RuntimeException('Not a language override file: ' . substr($path, 0, 120));
        foreach (['language', 'language/overrides', $path] as $part)
            if (is_link($this->root . '/' . $part)) throw new RuntimeException('Refusing to write through a link: ' . $path);
    }
}
