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
    /** The most strings one page of a plan offers. */
    public const MAX_STRINGS = 1500;
    /** A date or time format: configuration a translator must not touch (the language step sets dates itself). */
    private const FORMAT_KEY = '/^DATE_FORMAT_/';
    /** A month or day name: every date the front end prints reads one, and no code names it as a literal. */
    private const DATE_WORD = '/^(?:(?:JANUARY|FEBRUARY|MARCH|APRIL|MAY|JUNE|JULY|AUGUST|SEPTEMBER|OCTOBER|NOVEMBER|DECEMBER)(?:_SHORT)?|MONDAY|TUESDAY|WEDNESDAY|THURSDAY|FRIDAY|SATURDAY|SUNDAY|MON|TUE|WED|THU|FRI|SAT|SUN)$/';
    private const RANKS = ['template' => 0, 'used' => 1, 'core' => 2, 'other' => 3];
    /** Joomla's own front-end language files besides joomla.ini, lib_joomla.ini and every mod_*.ini. */
    private const CORE_FILES = ['joomla', 'lib_joomla', 'com_ajax', 'com_banners', 'com_config', 'com_contact', 'com_content', 'com_fields',
        'com_finder', 'com_mailto', 'com_media', 'com_newsfeeds', 'com_privacy', 'com_search', 'com_tags', 'com_users', 'com_wrapper'];
    /** The front-end code read for the keys it names: at most this many PHP files, each at most MAX_CODE_BYTES. */
    private const MAX_CODE_FILES = 20000;
    private const MAX_CODE_BYTES = 1048576;
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
     * The strings that read in en-GB on the front end in `$locale`, one page of them, the ones a visitor reads first,
     * and the overrides the site holds for it now.
     *
     * 🔒 RANKED BY USE, NEVER CUT SILENTLY (TCH #1013, measured 10/10/2026 on g59-ess-j-full): walked in file order,
     * AcyMailing's 2,861 mostly administrator strings came before joomla.ini and every mod_*.ini, and a cap of 1500
     * cut inside them, so "All Rights Reserved", "Remember me", "Advanced Search", "Jun" and "Details" were never
     * offered. The order is now (`rank`):
     *   1. `template`: the template's own front-end file;
     *   2. `used`: a key the site's front-end code names (the template, layouts, site components, modules, plugins, and
     *      the shared partials of a component whose module is installed) or a month or day name (dates print them),
     *      Joomla's own files before third-party ones;
     *   3. `core`: the rest of Joomla's front-end files and of every module's file;
     *   4. `other`: the rest of third-party files.
     * A page is `limit` strings (at most MAX_STRINGS) from `offset`; `total` counts them all, `truncated` says more
     * follow and `nextOffset` is where (null on the last page).
     *
     * @return array{strings:list<array{key:string,source:string,reason:string,file:string,rank:string}>,overrides:array<string,string>,truncated:bool,total:int,offset:int,nextOffset:?int}
     */
    public function plan(string $template, string $locale, int $offset = 0, int $limit = self::MAX_STRINGS): array
    {
        $all = $this->offered($template, $locale);
        $limit = max(1, min(self::MAX_STRINGS, $limit));
        $offset = max(0, $offset);
        $page = array_slice($all, $offset, $limit);
        $next = $offset + count($page) < count($all) ? $offset + count($page) : null;
        return [
            'strings' => $page, 'overrides' => self::parse((string) $this->bytes(self::path($locale))),
            'truncated' => $next !== null, 'total' => count($all), 'offset' => $offset, 'nextOffset' => $next,
        ];
    }

    /**
     * Every string the site shows in en-GB in `$locale`, ranked (see {@see plan()}): what a set may write.
     *
     * @return list<array{key:string,source:string,reason:string,file:string,rank:string}>
     */
    public function offered(string $template, string $locale): array
    {
        $used = $this->usedKeys($template);
        $rows = [];
        $order = 0;
        foreach ($this->sourceFiles($template) as $file => $counterparts) {
            $english = self::parse((string) $this->bytes($file));
            $own = [];
            foreach ($counterparts as $candidate) {
                $bytes = $this->bytes(str_replace('%LOCALE%', $locale, $candidate));
                if ($bytes !== null) { $own = self::parse($bytes); break; }
            }
            $class = self::fileClass($file, $template);
            foreach ($english as $key => $source) {
                if (isset($rows[$key]) || !preg_match(self::KEY, $key) || preg_match(self::FORMAT_KEY, $key)) continue;
                if (!self::worded($source)) continue;
                $reason = !array_key_exists($key, $own) ? 'missing'
                    : (trim($own[$key]) === trim($source) ? 'untranslated' : (self::suspect($source, $own[$key]) ? 'suspect' : null));
                if ($reason === null) continue;
                $rank = $class === 'template' ? 'template' : (isset($used[$key]) || preg_match(self::DATE_WORD, $key) ? 'used' : $class);
                $rows[$key] = ['row' => ['key' => $key, 'source' => $source, 'reason' => $reason, 'file' => $file, 'rank' => $rank],
                    'sort' => [self::RANKS[$rank], $class === 'other' ? 1 : 0, $order++]];
            }
        }
        uasort($rows, static fn(array $a, array $b): int => $a['sort'] <=> $b['sort']);
        return array_values(array_map(static fn(array $r): array => $r['row'], $rows));
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

    /** A language file's class: the template's own, Joomla's (or a module's), or a third party's. */
    private static function fileClass(string $file, string $template): string
    {
        $extension = strtolower((string) preg_replace('/^(?:[a-z]{2,3}-[A-Z]{2,4}\.)?(.+)\.ini$/i', '$1', basename($file)));
        if ($extension === strtolower('tpl_' . $template)) return 'template';
        return in_array($extension, self::CORE_FILES, true) || strpos($extension, 'mod_') === 0 ? 'core' : 'other';
    }

    /**
     * The language keys the site's front-end code names as literals (`Text::_('JDETAILS')`): the template, the shared
     * layouts, the site components, the modules, the plugins (the T4 and T3 base themes live there), and the shared
     * partials of a component whose module is installed (AcyMailing's form renders `com_acym/partial/forms/*` from
     * mod_acym). Administrator views are not read: they name every key a component has.
     *
     * @return array<string,true>
     */
    private function usedKeys(string $template): array
    {
        $folders = ['templates/' . $template, 'layouts', 'components', 'modules', 'plugins'];
        foreach (@scandir($this->root . '/modules') ?: [] as $module)
            if (preg_match('/^mod_([a-z0-9_]+)$/i', $module, $m))
                foreach (['partial', 'Partial', 'layouts'] as $shared) $folders[] = 'administrator/components/com_' . $m[1] . '/' . $shared;
        $keys = [];
        $files = 0;
        foreach ($folders as $folder) {
            $dir = $this->root . '/' . $folder;
            if (!is_dir($dir) || is_link($dir)) continue;
            $walk = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
                static fn(SplFileInfo $f): bool => !$f->isLink() && !($f->isDir() && in_array($f->getFilename(), ['vendor', 'node_modules', 'tests'], true))
            ));
            foreach ($walk as $file) {
                if (!$file->isFile() || strtolower($file->getExtension()) !== 'php' || $file->getSize() > self::MAX_CODE_BYTES) continue;
                if (++$files > self::MAX_CODE_FILES) return $keys;
                $bytes = @file_get_contents($file->getPathname());
                if ($bytes === false || !preg_match_all('/[\'"]([A-Z][A-Z0-9_]{2,119})[\'"]/', $bytes, $m)) continue;
                foreach ($m[1] as $key) $keys[$key] = true;
            }
        }
        return $keys;
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
