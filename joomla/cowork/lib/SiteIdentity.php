<?php
/**
 * SiteIdentity — the Global Configuration values that name a Joomla site to its visitors, and
 * nothing else of `configuration.php`.
 *
 * - `sitename`: the site name (Global Configuration › Site Name) — a page's `<title>` when the page
 *   has none of its own, added to every `<title>` when the site says so (`sitename_pagetitles`), and
 *   named in the mail Joomla writes. The mail's sender name is another key (`fromname`), not this one.
 * - `MetaDesc`: the site meta description, which every page without a description of its own falls
 *   back to (a menu item whose `menu-meta_description` is empty prints this one).
 * - `sitename_pagetitles`: whether every page's `<title>` carries the site name — 0 no, 1 before the
 *   page title ("Site - Page"), 2 after it ("Page - Site"). An integer, as Joomla's own form stores
 *   it (`filter="integer"`). A template keeps 0, so a site Tracy named still showed the bare page
 *   title in every browser tab and search result (TCH #1013, D1).
 * - `offline`, `offline_message`, `display_offline_message`: Global Configuration › Site Offline,
 *   the offline message, and which message the offline page shows (0 none, 1 the custom one, 2
 *   Joomla's default text). Tracy turns the site offline while a brief build writes it and keeps it
 *   offline when the build fails; while it is offline, a front-end request carrying the site's
 *   cowork token still sees the site as a visitor would ({@see SiteOffline}), so Tracy can read what
 *   it wrote. Integers as Joomla's form stores them; the message is plain text, stored as the
 *   escaped HTML Joomla's offline page prints raw.
 *
 * Beside them, the store keeps one key of its own, {@see HomeTitle::MARK}: the switch value Tracy
 * last set, written and undone with the switch, so the system plugin prints the home tab as the site
 * name alone only on a site where Tracy turned the name on ({@see HomeTitle}). No caller of the door
 * reads or writes it.
 *
 * Both used to be reachable only from the administrator. A site made from a template keeps the
 * template's own words there ("JA Vega - Modern Joomla Template…"), so after Tracy wrote every page
 * in the owner's words the home page still advertised the template (TCH ledger L24, 05/10/2026).
 *
 * 🔒 SIX KEYS, BY NAME, BOTH WAYS. `configuration.php` also holds the database password, the site
 * secret, mail credentials and server paths. Nothing here reads, returns or writes any key outside
 * `FIELDS`: a read answers exactly those, and a write copies every other key through untouched.
 *
 * No Joomla dependency, like the rest of `lib/`: the component hands {@see ConfigurationFile} what
 * Joomla holds (`JConfig`) and how Joomla formats it (`Registry`), so the tests can do the same with
 * stand-ins.
 */

/** Where the engine reads and writes the site identity. */
require_once __DIR__ . '/HomeTitle.php';

interface SiteIdentityStore
{
    /**
     * @return array{sitename:string,MetaDesc:string,sitename_pagetitles:int,offline:int,offline_message:string,display_offline_message:int,tracy_sitename_pagetitles:?int} the values as
     *   the site holds them now, and Tracy's mark ({@see HomeTitle::MARK}), null when absent
     */
    public function read(): array;

    /** Whether a write would land now: the file can be written, or made writable the way Joomla does. */
    public function writable(): bool;

    /**
     * Write some of the values; every other key of the configuration stays exactly as it was.
     *
     * @param array<string,string|int|null> $values a subset of {@see SiteIdentity::STORED}, each of its type; the mark
     *   may be null, which takes the key out of the file
     * @throws SiteIdentityUnwritable when the file cannot be written; nothing was changed
     */
    public function write(array $values): void;
}

/** The configuration file cannot be written by this process; the site is as it was. */
final class SiteIdentityUnwritable extends RuntimeException
{
}

/** What the door accepts as a site name, a site description or where page titles carry the name. */
final class SiteIdentity
{
    /** Every key this door can read or write. Nothing outside this list ever leaves or enters. */
    public const FIELDS = ['sitename', 'MetaDesc', 'sitename_pagetitles', 'offline', 'offline_message', 'display_offline_message'];

    /** The keys of the offline lock, a subset of {@see FIELDS}; `info` advertises them as `site_offline`. */
    public const OFFLINE_FIELDS = ['offline', 'offline_message', 'display_offline_message'];

    /** What the store reads and writes: the door's keys and Tracy's mark ({@see HomeTitle::MARK}), never a caller's. */
    public const STORED = ['sitename', 'MetaDesc', 'sitename_pagetitles', 'offline', 'offline_message', 'display_offline_message', HomeTitle::MARK];

    /** The integer key, and the values Joomla's form offers for it: 0 no, 1 before, 2 after. */
    public const PAGE_TITLES = 'sitename_pagetitles';
    public const PAGE_TITLE_VALUES = [0, 1, 2];

    /**
     * Every integer key: the values Joomla's form offers for it, what any other value (or none) reads
     * as — Joomla's own default — and the refusal for anything else.
     */
    public const INTEGERS = [
        'sitename_pagetitles' => [[0, 1, 2], 0, 'sitename_pagetitles must be 0 (no site name in page titles), 1 (before) or 2 (after)'],
        'offline' => [[0, 1], 0, 'offline must be 0 (site online) or 1 (site offline)'],
        'display_offline_message' => [[0, 1, 2], 1, 'display_offline_message must be 0 (no message), 1 (the custom offline_message) or 2 (Joomla\'s default message)'],
    ];

    /** The offline message: plain text that keeps its lines, stored as escaped HTML. */
    public const OFFLINE_MESSAGE = 'offline_message';

    /**
     * Characters after cleaning. `MetaDesc` is Joomla's own limit (the Global Configuration form's
     * `maxlength="300"`); Joomla sets none on `sitename`, which every `<title>` carries, so 200; none on
     * the offline message either, a paragraph or two, so 1000.
     */
    public const MAX_CHARACTERS = ['sitename' => 200, 'MetaDesc' => 300, 'offline_message' => 1000];

    /** Bytes before cleaning: far above any real value, so a huge body is refused before it is parsed. */
    public const MAX_BYTES = 4096;

    /**
     * One value as it will be stored, or why it is refused.
     *
     * Cleaned the way Joomla's own form cleans both fields (`filter="string"`: entities decoded, then
     * tags removed), and made one line: a line break or a tab becomes a space, any other control
     * character is dropped, runs of spaces become one, and the ends are trimmed. A `sitename` that is
     * empty after that is refused, as Joomla's form refuses it (`required="true"`); an empty
     * `MetaDesc` is allowed and means "no site description".
     *
     * `sitename_pagetitles` is 0, 1 or 2, `offline` 0 or 1 and `display_offline_message` 0, 1 or 2,
     * each as an integer or the same digit as a string, and is stored as the integer.
     *
     * `offline_message` is cleaned the same way but keeps its lines (a `<br>` is a line break too), and
     * is stored as Joomla's offline page prints it, raw: each line HTML-escaped, joined by `<br>`.
     * Cleaning what a read answered gives the same value back. An empty message is allowed.
     *
     * @param mixed $value
     * @return array{value:string|int}|array{error:string}
     */
    public static function clean(string $field, $value): array
    {
        if (!in_array($field, self::FIELDS, true)) return ['error' => $field . ' is not a site identity field'];
        if (isset(self::INTEGERS[$field])) {
            [$allowed, , $refusal] = self::INTEGERS[$field];
            if (is_string($value) && preg_match('/^[0-9]$/D', $value)) $value = (int) $value;
            if (!is_int($value) || !in_array($value, $allowed, true)) return ['error' => $refusal];
            return ['value' => $value];
        }
        if (!is_string($value)) return ['error' => $field . ' must be a string'];
        if (strlen($value) > self::MAX_BYTES) return ['error' => $field . ' is longer than ' . self::MAX_BYTES . ' bytes'];
        if (preg_match('//u', $value) !== 1) return ['error' => $field . ' is not valid UTF-8'];
        if ($field === self::OFFLINE_MESSAGE) return self::cleanMessage($value);
        $text = strip_tags(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $text = (string) preg_replace('/[\r\n\t\x{0085}\x{2028}\x{2029}]+/u', ' ', $text);
        $text = (string) preg_replace('/[\x{0000}-\x{001F}\x{007F}-\x{009F}]/u', '', $text);
        $text = trim((string) preg_replace('/ {2,}/', ' ', $text));
        $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : (int) preg_match_all('/./su', $text);
        if ($length > self::MAX_CHARACTERS[$field])
            return ['error' => $field . ' is longer than ' . self::MAX_CHARACTERS[$field] . ' characters (' . $length . ' after cleaning)'];
        if ($field === 'sitename' && $text === '') return ['error' => 'sitename cannot be empty: Joomla requires a site name'];
        return ['value' => $text];
    }

    /**
     * The offline message as stored: tags removed (a `<br>` first becomes a line break), each line
     * cleaned like the one-line fields, empty lines at either end dropped, then each line escaped and
     * the lines joined by `<br>`: the HTML Joomla's offline page prints as it is.
     *
     * @return array{value:string}|array{error:string}
     */
    private static function cleanMessage(string $value): array
    {
        $text = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = strip_tags((string) preg_replace('#<br\s*/?>#i', "\n", $text));
        $text = (string) preg_replace('/\r\n?|[\x{0085}\x{2028}\x{2029}]/u', "\n", $text);
        $text = (string) preg_replace('/\t+/', ' ', $text);
        $text = (string) preg_replace('/[\x{0000}-\x{0009}\x{000B}-\x{001F}\x{007F}-\x{009F}]/u', '', $text);
        $lines = array_map(static fn(string $line): string => trim((string) preg_replace('/ {2,}/', ' ', $line)), explode("\n", $text));
        while ($lines !== [] && $lines[0] === '') array_shift($lines);
        while ($lines !== [] && end($lines) === '') array_pop($lines);
        $plain = implode("\n", $lines);
        $length = function_exists('mb_strlen') ? mb_strlen($plain, 'UTF-8') : (int) preg_match_all('/./su', $plain);
        $max = self::MAX_CHARACTERS[self::OFFLINE_MESSAGE];
        if ($length > $max) return ['error' => 'offline_message is longer than ' . $max . ' characters (' . $length . ' after cleaning)'];
        return ['value' => implode('<br>', array_map(static fn(string $line): string => htmlspecialchars($line, ENT_QUOTES, 'UTF-8'), $lines))];
    }

    /**
     * Whether a value is of the type the store writes for this key: an integer for the integer keys,
     * an integer or null (absent) for the mark, a string for the texts.
     *
     * @param mixed $value
     */
    public static function storable(string $field, $value): bool
    {
        if (!in_array($field, self::STORED, true)) return false;
        if ($field === HomeTitle::MARK) return is_int($value) || $value === null;
        if (isset(self::INTEGERS[$field])) return is_int($value);
        return is_string($value);
    }

    /**
     * One stored value in this door's type, whatever the configuration or an undo row holds: the
     * flag as an integer (a value Joomla would not offer reads as 0, Joomla's own default), the two
     * texts as strings.
     *
     * @param scalar|null $value
     * @return string|int
     */
    public static function typed(string $field, $value)
    {
        // The mark: absent stays absent (null), anything else is the switch value it names, or absent.
        if ($field === HomeTitle::MARK)
            return is_numeric($value) && in_array((int) $value, self::PAGE_TITLE_VALUES, true) ? (int) $value : null;
        if (!isset(self::INTEGERS[$field])) return is_scalar($value) ? (string) $value : '';
        [$allowed, $default] = self::INTEGERS[$field];
        // Joomla's installer writes `offline` as a boolean; its form saves an integer.
        if (is_bool($value)) $value = (int) $value;
        $number = is_numeric($value) ? (int) $value : $default;
        return in_array($number, $allowed, true) ? $number : $default;
    }
}

/**
 * `configuration.php`, read through what Joomla loaded and written the way Joomla's own Global
 * Configuration save writes it (`ApplicationModel::writeConfigFile`, Joomla 4–6):
 *
 * 1. The whole configuration as Joomla holds it — `ArrayHelper::fromObject(new JConfig())`, the
 *    `$current` callable — with the changed keys merged over it, so every other key keeps its value
 *    and its place.
 * 2. Formatted by Joomla's `Registry` as a `JConfig` class without a closing tag — the `$format`
 *    callable — and checked to parse as PHP before a byte reaches the disk.
 * 3. Written in place. When the file is not writable but this process owns it, its owner-write bit
 *    is set for the write, as Joomla does (Joomla chmods 0644, then 0444); unlike Joomla, the file's
 *    own mode is put back afterwards, so a write here never loosens or tightens a site's permissions.
 *    A file this process can neither write nor chmod is refused, and nothing is written.
 * 4. PHP's opcode cache for the file is dropped, so the next request reads the new values.
 *
 * A write that does not complete puts the file's original bytes back: a half-written
 * configuration.php is a site that answers nothing.
 */
final class ConfigurationFile implements SiteIdentityStore
{
    private string $path;
    /** @var callable(): array<string,mixed> */
    private $current;
    /** @var callable(array<string,mixed>): string */
    private $format;
    /** @var null|callable(string): bool */
    private $owns;
    /** @var array<string,mixed>|null The configuration as this request last knew it (what Joomla loaded, then what was written). */
    private ?array $all = null;

    /**
     * @param string $path the site's configuration.php
     * @param callable(): array<string,mixed> $current the whole configuration Joomla loaded at boot
     * @param callable(array<string,mixed>): string $format the file text Joomla's Registry makes of it
     * @param null|callable(string): bool $owns whether this process owns the file; null asks the OS
     */
    public function __construct(string $path, callable $current, callable $format, ?callable $owns = null)
    {
        $this->path = $path;
        $this->current = $current;
        $this->format = $format;
        $this->owns = $owns;
    }

    public function read(): array
    {
        $all = $this->all();
        $out = [];
        foreach (SiteIdentity::STORED as $field)
            $out[$field] = SiteIdentity::typed($field, isset($all[$field]) && is_scalar($all[$field]) ? $all[$field] : null);
        return $out;
    }

    public function writable(): bool
    {
        clearstatcache(true, $this->path);
        return is_file($this->path) && (is_writable($this->path) || $this->owned());
    }

    public function write(array $values): void
    {
        foreach ($values as $field => $value)
            if (!SiteIdentity::storable((string) $field, $value))
                throw new InvalidArgumentException('Only the site identity keys, each of its type, are written here');
        // array_merge keeps every existing key in its place and only replaces the values named; a mark of
        // null leaves the file without one, as it was before Tracy first set the switch.
        $next = array_merge($this->all(), $values);
        if (array_key_exists(HomeTitle::MARK, $values) && $values[HomeTitle::MARK] === null) unset($next[HomeTitle::MARK]);
        $text = ($this->format)($next);
        self::assertConfiguration($text);
        $this->put($text);
        $this->all = $next;
    }

    /**
     * What Joomla loaded at boot, the first time; after a write, what this request wrote — `JConfig`
     * was declared once, at the start of the request, and does not see the file change under it.
     *
     * @return array<string,mixed>
     */
    private function all(): array
    {
        if ($this->all === null) {
            $all = ($this->current)();
            if (!is_array($all)) throw new RuntimeException('The site configuration could not be read');
            $this->all = $all;
        }
        return $this->all;
    }

    /**
     * Refuse to write text that is not a JConfig class PHP can parse. The formatter is Joomla's, so
     * this guards against a broken formatter or a stand-in, never against a value: every value is a
     * string the Registry quotes and escapes. The message never quotes the text — it holds secrets.
     */
    private static function assertConfiguration($text): void
    {
        if (!is_string($text) || strncmp($text, '<?php', 5) !== 0 || !preg_match('/\bclass\s+JConfig\b/', $text))
            throw new RuntimeException('The configuration could not be formatted as a JConfig class; nothing was written');
        // The tokenizer is optional in PHP; a host without it is not refused for that.
        if (!function_exists('token_get_all')) return;
        try {
            token_get_all($text, TOKEN_PARSE);
        } catch (ParseError $error) {
            throw new RuntimeException('The formatted configuration does not parse as PHP; nothing was written');
        }
    }

    private function put(string $text): void
    {
        $path = $this->path;
        clearstatcache(true, $path);
        if (!is_file($path)) throw new SiteIdentityUnwritable('configuration.php was not found; nothing was written');
        $mode = @fileperms($path);
        $original = @file_get_contents($path);
        if ($mode === false || $original === false) throw new SiteIdentityUnwritable('configuration.php could not be read; nothing was written');
        $mode &= 07777;
        $loosened = false;
        if (!is_writable($path)) {
            // Joomla's own save does this: a file this process owns is made writable for the write.
            if (!$this->owned() || !@chmod($path, $mode | 0200))
                throw new SiteIdentityUnwritable('configuration.php is not writable by the web server, which does not own it; nothing was written. Make it writable, or set the site name and description in Global Configuration.');
            $loosened = true;
            clearstatcache(true, $path);
            if (!is_writable($path)) {
                @chmod($path, $mode);
                throw new SiteIdentityUnwritable('configuration.php could not be made writable; nothing was written');
            }
        }
        try {
            $written = @file_put_contents($path, $text);
            if ($written !== strlen($text)) {
                if (@file_put_contents($path, $original) === strlen($original))
                    throw new SiteIdentityUnwritable('configuration.php could not be written; its previous contents were put back');
                // Not a refusal: the site did change, and somebody has to know at once.
                throw new RuntimeException('configuration.php could not be written, and its previous contents could not be put back: restore it from a backup');
            }
        } finally {
            // The mode it had, whatever happened: never left more open (or more closed) than found.
            if ($loosened) @chmod($path, $mode);
            clearstatcache(true, $path);
            if (function_exists('opcache_invalidate')) @opcache_invalidate($path, true);
        }
    }

    /** Whether this process owns the file — the test Joomla's `Path::isOwner` makes, without Joomla. */
    private function owned(): bool
    {
        if ($this->owns !== null) return (bool) ($this->owns)($this->path);
        $owner = @fileowner($this->path);
        if ($owner === false) return false;
        if (function_exists('posix_geteuid')) return $owner === posix_geteuid();
        // Joomla's own way where posix is missing: a file this process creates is owned by it.
        $probe = @tempnam(sys_get_temp_dir(), 'cowork');
        if ($probe === false) return false;
        $mine = @fileowner($probe);
        @unlink($probe);
        return $mine !== false && $mine === $owner;
    }
}
