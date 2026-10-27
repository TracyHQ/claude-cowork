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
 *
 * Two more kinds are offered (TracyHQ/tch#1013, measured 10/10/2026 on dev g60-ess-j-full, vi-VN):
 *   - a plugin's strings that a visitor reads ("Page Navigation" over every article's Previous/Next, the registration
 *     form's "Postal / ZIP Code"): Joomla keeps them in `administrator/language/<tag>/plg_*.ini` and reads them there on
 *     the site too. Only the keys a plugin's front-end layout (`tmpl/`) or a visitor's form (`forms/` of a user, content
 *     or captcha plugin, the privacy consent) names are offered; the rest of those files are its settings.
 *   - a date format a front-end layout passes through Text::_ as a literal (`Text::_('F j, Y')`, JA Essence and 26 other
 *     JoomlArt and Gavick templates) with the month before the day: Text::_ looks it up upper-cased ("F J, Y"), finds no
 *     key, and prints the English order ("Tháng sáu 9, 2023" beside the pack's "09 Tháng sáu 2023"). It is offered with
 *     `reason: date-format` and the language's own format as `value` (DATE_FORMAT_LC3, its month width and year kept as
 *     the literal has them), and a set writes that format or nothing: a date format is not words to translate.
 *
 * And one more (TracyHQ/tch#1013, dev g60-ess-j-full 11/10/2026): words a front-end layout prints as a literal, with no key at
 * all (Joomla 6.1's mod_finder `aria-label="search"`, JA Essence's `<span class="sr-only">Watch the video</span>`), offered
 * under `PageLiterals::keyOf()` with `reason: literal`; the system plugin's output filter prints the override's words in
 * their place (lib/PageLiterals.php). Read from the template, the shared layouts and every `tmpl/` folder of a site
 * component, module or plugin, never from an `admin` folder.
 */
require_once __DIR__ . '/PageLiterals.php';

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
    /** Where Joomla keeps a plugin's language files, the site's included. */
    private const PLUGIN_LANGUAGE = 'administrator/language/';
    /** The plugin groups whose forms a visitor fills in (registration, profile, contact, captcha); others are settings. */
    private const VISITOR_FORM_GROUPS = ['user', 'content', 'captcha'];
    /** A PHP date format with nothing but format letters and separators. */
    private const DATE_LITERAL = '~^[dDjlNSwzWFmMntLoYyaABgGhHisuv][dDjlNSwzWFmMntLoYyaABgGhHisuv\s,./:-]{0,38}$~D';
    /** A front-end call that passes a literal through Text::_ (or JText::_), the literal captured. */
    private const TEXT_LITERAL = '~\bJ?Text::_\(\s*([\'"])([^\'"\r\n]{1,40})\1\s*[,)]~';

    /** @var array<string,array{keys:array<string,true>,formats:array<string,string>,literals:array<string,string>}> the front-end code's keys, date literals and page literals, by template, read once */
    private array $front = [];
    /** @var array<string,true>|null the words of the site's en-GB strings, lower-cased, read once ({@see vocabulary()}) */
    private ?array $vocabulary = null;

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
            'scope' => $locale === self::SOURCE ? 'site-wide locale/key; literals use exact source text with case and punctuation preserved' : 'site-wide locale',
            'ambiguous' => $locale === self::SOURCE ? $this->englishLiterals($template)['ambiguous'] : [],
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
        if ($locale === self::SOURCE) return $this->englishLiterals($template)['strings'];
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
                $reason = $this->reasonOf($source, $own, $key, $locale);
                if ($reason === null) continue;
                $rank = $class === 'template' ? 'template' : (isset($used[$key]) || preg_match(self::DATE_WORD, $key) ? 'used' : $class);
                $rows[$key] = ['row' => ['key' => $key, 'source' => $source, 'reason' => $reason, 'file' => $file, 'rank' => $rank],
                    'sort' => [self::RANKS[$rank], $class === 'other' ? 1 : 0, $order++]];
            }
        }
        // A plugin's strings a visitor reads, from where Joomla keeps them (administrator/language), ranked as used.
        $visitor = $this->pluginKeys();
        foreach ($this->pluginFiles() as $file => $counterparts) {
            $english = self::parse((string) $this->bytes($file));
            $own = [];
            foreach ($counterparts as $candidate) {
                $bytes = $this->bytes(str_replace('%LOCALE%', $locale, $candidate));
                if ($bytes !== null) { $own = self::parse($bytes); break; }
            }
            foreach ($english as $key => $source) {
                if (isset($rows[$key]) || !isset($visitor[$key]) || !preg_match(self::KEY, $key) || !self::worded($source)) continue;
                $reason = $this->reasonOf($source, $own, $key, $locale);
                if ($reason === null) continue;
                $rows[$key] = ['row' => ['key' => $key, 'source' => $source, 'reason' => $reason, 'file' => $file, 'rank' => 'used'],
                    'sort' => [self::RANKS['used'], 0, $order++]];
            }
        }
        // The date formats the layouts pass through Text::_ in English order, each with the language's own.
        $format = $this->dateFormat($locale);
        if ($format !== null)
            foreach ($this->front($template)['formats'] as $literal => $file) {
                $key = strtoupper($literal);
                $value = self::languageOrder($literal, $format);
                if (isset($rows[$key]) || $value === null) continue;
                $rows[$key] = ['row' => ['key' => $key, 'source' => $literal, 'reason' => 'date-format', 'file' => $file, 'rank' => 'used', 'value' => $value],
                    'sort' => [self::RANKS['used'], 0, $order++]];
            }
        // The words a layout prints as a literal, each under a key of its own (lib/PageLiterals.php).
        foreach ($this->front($template)['literals'] as $literal => $file) {
            $key = PageLiterals::keyOf($literal);
            if ($key === null || isset($rows[$key])) continue;
            $rows[$key] = ['row' => ['key' => $key, 'source' => $literal, 'reason' => 'literal', 'file' => $file, 'rank' => 'used'],
                'sort' => [self::RANKS['used'], 0, $order++]];
        }
        uasort($rows, static fn(array $a, array $b): int => $a['sort'] <=> $b['sort']);
        return array_values(array_map(static fn(array $r): array => $r['row'], $rows));
    }

    /** English editing is limited to template-owned words and literals; conflicting sources in the read inventory are refused. */
    private function englishLiterals(string $template): array
    {
        $groups = [];
        foreach ($this->front($template)['literals'] as $literal => $file) {
            $key = PageLiterals::keyOf($literal);
            if ($key === null) continue;
            $source = PageLiterals::sourceText($literal);
            $groups[$key][$source] = ['file' => $file, 'source' => $source];
        }
        $rows = [];
        $ambiguous = [];
        foreach ($groups as $key => $sources) {
            $owned = array_filter($sources, static fn(array $row): bool => strpos($row['file'], 'templates/' . $template . '/') === 0);
            if ($owned === []) continue;
            if (count($sources) !== 1) {
                $ambiguous[] = ['key' => $key, 'sources' => array_keys($sources)];
                continue;
            }
            $row = reset($owned);
            $rows[] = ['key' => $key, 'source' => $row['source'], 'reason' => 'literal', 'file' => $row['file'], 'rank' => 'template'];
        }
        $language = [];
        $foreign = [];
        foreach ($this->sourceFiles($template) + $this->pluginFiles() as $file => $counterparts) {
            foreach (self::parse((string) $this->bytes($file)) as $key => $source) {
                if (!preg_match(self::KEY, $key) || preg_match(self::FORMAT_KEY, $key) || preg_match(self::DATE_WORD, $key) || strpos($key, 'TRACY_') === 0) continue;
                if (!self::englishTemplateFile($file, $template)) $foreign[$key] = true;
                if (!isset($language[$key][$source])) $language[$key][$source] = ['file' => $file, 'source' => $source];
            }
        }
        foreach ($language as $key => $sources) {
            if (isset($foreign[$key])) continue;
            $owned = array_filter($sources, static fn(array $row): bool => self::englishTemplateFile($row['file'], $template));
            if ($owned === []) continue;
            if (count($sources) !== 1) { $ambiguous[] = ['key' => $key, 'sources' => array_keys($sources)]; continue; }
            $row = reset($owned);
            $plain = self::breakText($row['source']);
            if ($plain === null || !self::worded($plain)) continue;
            $rows[] = ['key' => $key, 'source' => $row['source'], 'reason' => 'template-words', 'file' => $row['file'], 'rank' => 'template'];
        }
        return ['strings' => $rows, 'ambiguous' => $ambiguous];
    }

    /** Joomla may install the selected template's own language file in the global language folder. */
    private static function englishTemplateFile(string $file, string $template): bool
    {
        return strpos($file, 'templates/' . $template . '/language/en-GB/') === 0
            || $file === 'language/en-GB/tpl_' . $template . '.ini'
            || $file === 'language/en-GB/en-GB.tpl_' . $template . '.ini';
    }

    /** Plain text with simple line breaks only; attributes, other tags and encoded markup are refused. */
    private static function breakText(string $value): ?string
    {
        $text = (string) preg_replace('~<br[ ]*/?>~i', '', $value);
        return preg_match('/[<>]/', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')) ? null : $text;
    }

    /** English template keys may preserve existing simple line breaks, never add markup or drop a break. */
    public static function englishRefusal(string $source, $value): ?string
    {
        if (!is_string($value)) return self::refusal($source, $value);
        if (mb_strlen($value) > self::MAX_VALUE) return 'longer than ' . self::MAX_VALUE . ' characters';
        if (preg_match('/[\r\n\x00]/', $value)) return 'more than one line';
        $from = self::breakText($source);
        $to = self::breakText($value);
        if ($from === null || $to === null) return 'only the existing simple line breaks may be kept';
        preg_match_all('~<br[ ]*/?>~i', $source, $a);
        preg_match_all('~<br[ ]*/?>~i', $value, $b);
        if (count($a[0]) !== count($b[0])) return 'keep the same number of line breaks';
        return self::refusal($from, $to);
    }

    /** New guarded language receipts are preflighted newest first, including multiple writes in one Apply. */
    public function revertBlocked(array $entries): array
    {
        $virtual = [];
        $blocked = [];
        foreach (array_reverse($entries) as $entry) {
            if (($entry['op'] ?? null) !== 'languageOverrides' || !isset($entry['expected'])) continue;
            foreach (array_reverse($entry['files'] ?? []) as $file) {
                $path = $file['path'] ?? '';
                $this->assertDoorFile($path);
                if (!array_key_exists($path, $virtual)) $virtual[$path] = $this->bytes($path);
                $expected = $entry['expected'][$path] ?? null;
                if (!is_string($expected) || !is_string($virtual[$path]) || !hash_equals($expected, hash('sha256', $virtual[$path]))) $blocked[] = $path;
                $virtual[$path] = $file['before'] ?? null;
            }
        }
        return array_values(array_unique($blocked));
    }

    /**
     * Whether a literal is a date format that puts the month's name before the day (`F j, Y`, `M d, Y`): the English order a
     * language's own format replaces. A format with the day first, or with no month name or no day, is left as it is.
     */
    public static function englishOrder(string $literal): bool
    {
        if (!preg_match(self::DATE_LITERAL, $literal)) return false;
        $month = strcspn($literal, 'FM');
        $day = strcspn($literal, 'dj');
        return $month < strlen($literal) && $day < strlen($literal) && $month < $day;
    }

    /**
     * The language's own format (`$format`, its DATE_FORMAT_LC3) in the shape of `$literal`: a short month name when the
     * literal has one (`M`), no year when it has none, its day of the week in front when it starts with one. Null when that
     * is the literal itself.
     */
    public static function languageOrder(string $literal, string $format): ?string
    {
        $out = $format;
        if (strpos($literal, 'F') === false && strpos($literal, 'M') !== false) $out = str_replace('F', 'M', $out);
        if (strcspn($literal, 'Yy') === strlen($literal)) $out = trim((string) preg_replace('~[\s,./-]*[Yy][\s,./-]*~', ' ', $out));
        if (preg_match('/^[lD]/', $literal) && strcspn($out, 'lD') === strlen($out)) $out = $literal[0] . ', ' . $out;
        return trim($out) === '' || $out === $literal ? null : $out;
    }

    /**
     * A language's own date format, the one its pack prints an article's date with (DATE_FORMAT_LC3: "d F Y" in
     * vi-VN 4.2.2.1): the site's override for it first, then the pack (`joomla.ini`, or `<tag>.ini` before Joomla 4).
     * Null when neither has one that names a day and a month.
     */
    public function dateFormat(string $locale): ?string
    {
        if (!preg_match(self::LOCALE, $locale)) return null;
        foreach ([self::path($locale), 'language/' . $locale . '/joomla.ini', 'language/' . $locale . '/' . $locale . '.ini'] as $file) {
            $format = self::parse((string) $this->bytes($file))['DATE_FORMAT_LC3'] ?? null;
            if (is_string($format) && trim($format) !== '' && strcspn($format, 'dj') < strlen($format) && strcspn($format, 'mnMF') < strlen($format)) return trim($format);
        }
        return null;
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
            // A key is what stands before `=`: a language key, or the literal of a date format (`F J, Y`).
            if (!preg_match('/^\s*([^;=\s"\[][^="]*?)\s*=/', $line, $m)) continue;
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

    /**
     * Why a string is offered for `$locale`, or null when its value there is the language's own: `missing`, `untranslated`
     * (the en-GB words, or English words reworded), `suspect` (another string's translation).
     *
     * 🔒 A PACK'S ENGLISH IS UNTRANSLATED, HOWEVER IT IS WORDED (TracyHQ/tch#1013, dev g61-ess-j-full 11/10/2026): the vi-VN 4.2.2
     * pack holds PLG_USER_PROFILE_SLIDER_LABEL as "Show Profile" (en-GB: "User Profile") and the postal code label as
     * "Postal / ZIP Code" (en-GB: "Postal/ZIP Code"), so a byte comparison took both for translations and the registration form
     * stayed English. A value of plain ASCII whose every word is an English word of the site's en-GB files is English. Never
     * for an English locale (en-US, en-AU): its own words are English.
     *
     * @param array<string,string> $own the locale's strings of the same file
     */
    private function reasonOf(string $source, array $own, string $key, string $locale): ?string
    {
        if (!array_key_exists($key, $own)) return 'missing';
        $value = trim($own[$key]);
        if ($value === trim($source)) return 'untranslated';
        if (self::suspect($source, $value)) return 'suspect';
        return strpos($locale, 'en-') !== 0 && $this->english($value) ? 'untranslated' : null;
    }

    /** Whether words are English: plain ASCII, and every word of two letters or more one the site's en-GB strings use. */
    private function english(string $value): bool
    {
        if (preg_match('/^[\x20-\x7E]+$/D', $value) !== 1 || !preg_match_all('/[A-Za-z]{2,}/', $value, $m)) return false;
        $vocabulary = $this->vocabulary();
        foreach ($m[0] as $word) if (!isset($vocabulary[strtolower($word)])) return false;
        return true;
    }

    /**
     * Every word of two letters or more in the site's en-GB front-end and plugin language files, lower-cased. Read once.
     *
     * @return array<string,true>
     */
    private function vocabulary(): array
    {
        if ($this->vocabulary !== null) return $this->vocabulary;
        $words = [];
        $folders = ['language/' . self::SOURCE, self::PLUGIN_LANGUAGE . self::SOURCE];
        foreach (@scandir($this->root . '/templates') ?: [] as $template)
            if ($template !== '.' && $template !== '..') $folders[] = 'templates/' . $template . '/language/' . self::SOURCE;
        foreach ($folders as $folder) {
            $dir = $this->root . '/' . $folder;
            if (!is_dir($dir) || is_link($dir)) continue;
            foreach (@scandir($dir) ?: [] as $name) {
                if (!preg_match('/\.ini$/i', $name)) continue;
                foreach (self::parse((string) $this->bytes($folder . '/' . $name)) as $text)
                    if (preg_match_all('/[A-Za-z]{2,}/', $text, $m)) foreach ($m[0] as $word) $words[strtolower($word)] = true;
            }
        }
        return $this->vocabulary = $words;
    }

    /**
     * The words a T4 mega menu prints raw (TracyHQ/tch#1013, dev g61-ess-j-full 11/10/2026: "User", "J!Pages", "J! Content" over
     * every page): each column title and each item caption of the template's navigation profiles
     * (`templates/<template>/local/etc/navigation/*.json`, then `etc/navigation/*.json`, their `mega_settings`, a JSON object or
     * a JSON string of one). T4's `mega_sub.php` echoes them with no key, so they are page literals: offered under
     * `PageLiterals::keyOf()` and printed in the override's words by the output filter. Each with the first file that holds it.
     *
     * @return array<string,string> literal => site-relative file
     */
    private function megamenuLiterals(string $template): array
    {
        $out = [];
        foreach (['templates/' . $template . '/local/etc/navigation', 'templates/' . $template . '/etc/navigation'] as $folder) {
            $dir = $this->root . '/' . $folder;
            if (!is_dir($dir) || is_link($dir)) continue;
            $names = @scandir($dir) ?: [];
            sort($names);
            foreach ($names as $name) {
                if (!preg_match('/^[A-Za-z0-9_. -]+\.json$/D', $name)) continue;
                $body = json_decode((string) $this->bytes($folder . '/' . $name), true);
                $settings = is_array($body) ? ($body['mega_settings'] ?? null) : null;
                if (is_string($settings)) $settings = json_decode($settings, true);
                if (!is_array($settings)) continue;
                foreach ($settings as $items) {
                    if (!is_array($items)) continue;
                    foreach ($items as $item) {
                        if (!is_array($item)) continue;
                        $words = [$item['caption'] ?? null];
                        foreach (is_array($item['settings'] ?? null) ? $item['settings'] : [] as $row)
                            foreach (is_array($row['contents'] ?? null) ? $row['contents'] : [] as $column)
                                if (is_array($column)) $words[] = $column['title'] ?? null;
                        foreach ($words as $word) {
                            $word = is_string($word) ? trim((string) preg_replace('/\s+/', ' ', $word)) : '';
                            if ($word !== '' && PageLiterals::keyOf($word) !== null && !isset($out[$word])) $out[$word] = $folder . '/' . $name;
                        }
                    }
                }
            }
        }
        return $out;
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
        return $this->front($template)['keys'];
    }

    /**
     * The site's en-GB plugin language files where Joomla keeps them (`administrator/language/en-GB/plg_*.ini`, the
     * `.sys.ini` ones left out), each with where its `$locale` copy would be.
     *
     * @return array<string,list<string>>
     */
    private function pluginFiles(): array
    {
        $out = [];
        $dir = $this->root . '/' . self::PLUGIN_LANGUAGE . self::SOURCE;
        if (!is_dir($dir) || is_link($dir)) return $out;
        $names = @scandir($dir) ?: [];
        sort($names);
        foreach ($names as $name) {
            if (!preg_match('/^(?:en-GB\.)?(plg_[a-z0-9_.\-]+)\.ini$/i', $name, $m) || preg_match('/\.sys\.ini$/i', $name)) continue;
            $file = self::PLUGIN_LANGUAGE . self::SOURCE . '/' . $name;
            if (is_link($this->root . '/' . $file) || !is_file($this->root . '/' . $file)) continue;
            $out[$file] = [self::PLUGIN_LANGUAGE . '%LOCALE%/' . $m[1] . '.ini', self::PLUGIN_LANGUAGE . '%LOCALE%/%LOCALE%.' . $m[1] . '.ini'];
        }
        return $out;
    }

    /**
     * The keys a visitor reads from a plugin: named in a plugin's front-end layouts (`plugins/<group>/<name>/tmpl/`) or in the
     * forms a visitor fills in (`forms/` of a user, content or captcha plugin, and of the privacy consent). A plugin's
     * manifest and its other forms are its settings, an administrator's words.
     *
     * @return array<string,true>
     */
    private function pluginKeys(): array
    {
        $keys = [];
        foreach (@scandir($this->root . '/plugins') ?: [] as $group) {
            if ($group === '.' || $group === '..' || !is_dir($this->root . '/plugins/' . $group)) continue;
            foreach (@scandir($this->root . '/plugins/' . $group) ?: [] as $plugin) {
                if ($plugin === '.' || $plugin === '..') continue;
                $base = 'plugins/' . $group . '/' . $plugin;
                $folders = [$base . '/tmpl' => 'php'];
                if (in_array($group, self::VISITOR_FORM_GROUPS, true) || $base === 'plugins/system/privacyconsent') $folders[$base . '/forms'] = 'xml';
                foreach ($folders as $folder => $extension)
                    foreach ($this->codeFiles($folder, $extension) as $bytes)
                        if (preg_match_all('/[\'"]([A-Z][A-Z0-9_]{2,119})[\'"]/', $bytes, $m))
                            foreach ($m[1] as $key) $keys[$key] = true;
            }
        }
        return $keys;
    }

    /**
     * The bytes of the `$extension` files under a site folder, links, vendor trees and tests left out, at most MAX_CODE_FILES
     * files of at most MAX_CODE_BYTES each.
     *
     * @return iterable<string>
     */
    private function codeFiles(string $folder, string $extension): iterable
    {
        $dir = $this->root . '/' . $folder;
        if (!is_dir($dir) || is_link($dir)) return;
        $walk = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            static fn(SplFileInfo $f): bool => !$f->isLink() && !($f->isDir() && in_array($f->getFilename(), ['vendor', 'node_modules', 'tests'], true))
        ));
        $files = 0;
        foreach ($walk as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== $extension || $file->getSize() > self::MAX_CODE_BYTES) continue;
            if (++$files > self::MAX_CODE_FILES) return;
            $bytes = @file_get_contents($file->getPathname());
            if ($bytes !== false) yield $bytes;
        }
    }

    /**
     * The front-end code's language keys ({@see usedKeys()}) and the date formats it passes through Text::_ in English order
     * ({@see englishOrder()}), each format with the first file that names it. Read once.
     *
     * @return array{keys:array<string,true>,formats:array<string,string>,literals:array<string,string>}
     */
    private function front(string $template): array
    {
        if (isset($this->front[$template])) return $this->front[$template];
        $folders = ['templates/' . $template, 'layouts', 'components', 'modules', 'plugins'];
        foreach (@scandir($this->root . '/modules') ?: [] as $module)
            if (preg_match('/^mod_([a-z0-9_]+)$/i', $module, $m))
                foreach (['partial', 'Partial', 'layouts'] as $shared) $folders[] = 'administrator/components/com_' . $m[1] . '/' . $shared;
        $keys = [];
        $formats = [];
        $literals = [];
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
                if (++$files > self::MAX_CODE_FILES) return $this->front[$template] = ['keys' => $keys, 'formats' => $formats, 'literals' => $literals];
                $bytes = @file_get_contents($file->getPathname());
                if ($bytes === false) continue;
                $relative = substr($file->getPathname(), strlen($this->root) + 1);
                // A visitor's layout: the template's own, the shared layouts, a `tmpl/` folder of the site; never an `admin` one.
                if ((strpos($relative, 'templates/') === 0 || strpos($relative, 'layouts/') === 0 || strpos($relative, '/tmpl/') !== false)
                    && preg_match('~(^|/)admin(istrator)?/~', $relative) !== 1)
                    foreach (PageLiterals::inCode($bytes) as $literal)
                        if (!isset($literals[$literal])) $literals[$literal] = $relative;
                if (preg_match_all(self::TEXT_LITERAL, $bytes, $t))
                    foreach ($t[2] as $literal)
                        if (!isset($formats[$literal]) && self::englishOrder($literal))
                            $formats[$literal] = substr($file->getPathname(), strlen($this->root) + 1);
                if (!preg_match_all('/[\'"]([A-Z][A-Z0-9_]{2,119})[\'"]/', $bytes, $m)) continue;
                foreach ($m[1] as $key) $keys[$key] = true;
            }
        }
        foreach ($this->megamenuLiterals($template) as $literal => $file) if (!isset($literals[$literal])) $literals[$literal] = $file;
        return $this->front[$template] = ['keys' => $keys, 'formats' => $formats, 'literals' => $literals];
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
