<?php
declare(strict_types=1);

/**
 * Where a Media Library picture is held outside post bodies: which post meta row or option has it.
 *
 * A theme keeps the pictures it shows wherever its author chose — an attachment id in a post meta
 * (`jd_intro_image` = 24), an option (`<theme>_blog_banner_image` = 24), a URL of one of the sizes,
 * or a customizer/builder record that is JSON or PHP-serialized with the id or URL inside. No table
 * of theme keys can keep up with that, so this reads the VALUE, the same way for every theme:
 *
 *  - `id`         the whole value is the attachment id, as a number or a numeric string, under a
 *                 picture-like key (the meta key or option name);
 *  - `url`        a plain string that holds the URL of the original or of one registered size
 *                 (matched on the uploads path, so http/https, another host or a relative URL count);
 *  - `json`       a JSON object/array with such a URL inside, or the id under a picture-like key;
 *  - `serialized` the same, PHP-serialized.
 *
 * A bare number is evidence only through its name, because a counter or a width can equal an id by
 * chance. "Picture-like" is a word rule, not a key list: the key, split into words (`jd_intro_image`,
 * `backgroundImage`), holds a picture word (`image`, `logo`, `banner`, `bg`, …) and does not end in
 * a measure or another record's id (`image_width`, `logo_size`, `banner_count`, `hero_post_id`).
 * `_thumbnail_id`, `site_logo` and `site_icon` are named. A list entry or an `id` takes the key it
 * sits under (`{"logos":[23,24]}`, `{"image":{"id":24}}`, `{"image":{"ids":[24]}}`). A list word
 * (`gallery`, `slides`, `slider`, `carousel`) names its array's entries, not a number set on it
 * directly (`{"gallery":[ 24 ]}` yes, `{"gallery":24}` and `gallery_count` no). A URL needs no name:
 * it is the evidence.
 *
 * Plain PHP with no WordPress in it: the writer runs the bounded SQL and hands each value here. The
 * SQL only narrows (sqlIdPattern/likeNeedles keep every value this class could accept, whatever its
 * whitespace); the decision is made here. Serialized values are decoded with
 * `allowed_classes => false`, so no object of the site's is built.
 */
final class AttachmentReferences
{
    /**
     * Primary-key span of one table examined by one call: the scan walks the table by its key in
     * SCAN_CHUNK ranges, so no statement reads more than one range whatever matches, and stops at
     * this ceiling with `truncated` and where it stopped.
     */
    public const MAX_SCAN = 100000;
    public const SCAN_CHUNK = 10000;
    /** References one table reports; the rest is `truncated`. */
    public const MAX_HITS = 100;
    /** How deep a decoded value is walked. */
    private const MAX_DEPTH = 32;

    /** WordPress's own bookkeeping, never a picture a theme shows. `_thumbnail_id` is not here. */
    public const SKIP_META_KEYS = [
        '_wp_attached_file', '_wp_attachment_metadata', '_edit_lock', '_edit_last', '_wp_old_slug',
        '_wp_old_date', '_wp_desired_post_slug', '_wp_page_template', '_encloseme', '_pingme',
    ];
    /** Prefixes of the same: attachment records, trash bookkeeping, oEmbed caches and this plugin's own keys. */
    public const SKIP_META_PREFIXES = ['_wp_attachment_', '_wp_trash_meta_', '_oembed_', '_claude_cowork_', '_tracy_'];
    /**
     * Options that are bookkeeping or a number that is not a picture: scheduling, rewrite cache,
     * plugin lists, schema versions and the core numeric settings (sizes, counts, days) whose value
     * could equal an id by chance. `site_logo`, `site_icon` and `theme_mods_*` are not here.
     */
    public const SKIP_OPTIONS = [
        'cron', 'rewrite_rules', 'active_plugins', 'recently_edited', 'uninstall_plugins', 'recovery_keys',
        'db_version', 'initial_db_version', 'db_upgraded', 'posts_per_page', 'posts_per_rss', 'comments_per_page',
        'thumbnail_size_w', 'thumbnail_size_h', 'medium_size_w', 'medium_size_h', 'medium_large_size_w',
        'medium_large_size_h', 'large_size_w', 'large_size_h', 'start_of_week', 'gmt_offset',
        'thread_comments_depth', 'close_comments_days_old', 'default_category', 'default_link_category',
        'default_post_format', 'comment_max_links', 'default_email_category', 'blog_public', 'users_can_register',
        'auto_update_core_dev', 'fresh_site', 'finished_splitting_shared_terms', 'site_icon_url_cache',
        '_tracy_content_contract',
    ];
    public const SKIP_OPTION_PREFIXES = ['_transient_', '_site_transient_', 'claude_cowork_'];

    /** Words that name a picture, matched against whole words of a key (a plural `s` taken off). */
    private const PICTURE_WORDS = ['image', 'img', 'logo', 'banner', 'photo', 'picture', 'thumbnail', 'thumb', 'background', 'bg',
        'icon', 'cover', 'media', 'attachment', 'avatar', 'hero', 'heroe'];
    /** A last word that makes the key a measure or a setting of the picture, not the picture. */
    private const NOT_PICTURE_LAST = ['width', 'height', 'w', 'h', 'size', 'count', 'order', 'position', 'pos', 'alt', 'ratio',
        'opacity', 'x', 'y', 'top', 'left', 'right', 'bottom', 'padding', 'margin', 'radius', 'duration', 'delay', 'speed',
        'interval', 'limit', 'number', 'num', 'max', 'min', 'column', 'col', 'row', 'quality', 'zoom', 'blur', 'overlay',
        'scale', 'offset', 'index', 'length', 'time', 'per', 'page', 'total', 'level', 'version'];
    /** Words that name a list of pictures: a number counts under them only as an entry of an array. */
    private const LIST_WORDS = ['gallery', 'gallerie', 'slide', 'slider', 'carousel'];
    /** Keys WordPress itself keeps an attachment id under. */
    private const PICTURE_KEYS = ['_thumbnail_id', 'site_logo', 'site_icon', 'custom_logo'];

    private string $id;
    /** @var array<string,string|null> uploads path of a file => its size name (null for the original) */
    private array $paths;

    /** @param array<string,string|null> $paths uploads path ("/wp-content/uploads/2024/01/a.jpg") => size name */
    public function __construct(int $id, array $paths)
    {
        $this->id = (string) $id;
        $this->paths = $paths;
    }

    /**
     * From what WordPress stores about the attachment.
     *
     * @param string $uploadsUrl  the uploads base URL (`wp_upload_dir()['baseurl']`); empty means no URL is matched
     * @param string $attached    `_wp_attached_file`, relative to uploads
     * @param mixed  $metadata    `_wp_attachment_metadata`
     * @param mixed  $backupSizes `_wp_attachment_backup_sizes`
     */
    public static function forAttachment(int $id, string $uploadsUrl, string $attached, $metadata, $backupSizes = []): self
    {
        $base = rtrim((string) parse_url($uploadsUrl, PHP_URL_PATH), '/');
        $paths = [];
        if ($uploadsUrl !== '' && $attached !== '') {
            $dir = dirname($attached);
            $dir = ($dir === '.' || $dir === '') ? '' : $dir . '/';
            $paths[$base . '/' . ltrim($attached, '/')] = 'full';
            $metadata = is_array($metadata) ? $metadata : [];
            if (!empty($metadata['original_image']) && is_string($metadata['original_image'])) {
                $paths[$base . '/' . $dir . basename($metadata['original_image'])] = 'original';
            }
            foreach ((array) ($metadata['sizes'] ?? []) as $name => $size) {
                if (is_array($size) && !empty($size['file']) && is_string($size['file'])) {
                    $paths += [$base . '/' . $dir . basename($size['file']) => (string) $name];
                }
            }
            foreach (is_array($backupSizes) ? $backupSizes : [] as $name => $size) {
                if (is_array($size) && !empty($size['file']) && is_string($size['file'])) {
                    $paths += [$base . '/' . $dir . basename($size['file']) => (string) $name];
                }
            }
        }
        return new self($id, $paths);
    }

    /**
     * For `value REGEXP %s`: the id's digits with no digit on either side. Every value match() takes
     * on the id holds them so, however it is spaced (`{"image":\n 24}`, `[ 24 ]`, `i:24;`, `"24"`).
     */
    public function sqlIdPattern(): string
    {
        return '(^|[^0-9])' . $this->id . '([^0-9]|$)';
    }

    /**
     * For `value LIKE %…%` (escape with `esc_like` first): the file name of each size, as written and
     * as JSON writes it (`caf\u00e9.jpg`). Every value match() takes on a URL holds one of them, with
     * its slashes plain or JSON-escaped (a file name has none); the path is checked here.
     *
     * @return string[]
     */
    public function likeNeedles(): array
    {
        $out = [];
        foreach (array_keys($this->paths) as $path) {
            $name = basename($path);
            $out[] = $name;
            $json = json_encode($name);
            if (is_string($json) && substr($json, 1, -1) !== $name) {
                $out[] = substr($json, 1, -1);
            }
        }
        return array_values(array_unique($out));
    }

    public static function skipMetaKey(string $key): bool
    {
        return in_array($key, self::SKIP_META_KEYS, true) || self::prefixed($key, self::SKIP_META_PREFIXES);
    }

    public static function skipOption(string $name): bool
    {
        return in_array($name, self::SKIP_OPTIONS, true) || self::prefixed($name, self::SKIP_OPTION_PREFIXES);
    }

    /**
     * Whether a stored value holds this attachment, and how.
     *
     * @return array{shape:string,size?:string}|null
     */
    public function match($value, string $key): ?array
    {
        if (is_int($value)) {
            return (string) $value === $this->id && $this->pictureKey([$key]) ? ['shape' => 'id'] : null;
        }
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);
        if ($trimmed === $this->id) {
            return $this->pictureKey([$key]) ? ['shape' => 'id'] : null;
        }
        if (self::looksSerialized($trimmed)) {
            $decoded = @unserialize($trimmed, ['allowed_classes' => false]);
            if ($decoded !== false || $trimmed === 'b:0;') {
                $hit = $this->walk($decoded, [$key], 0);
                return $hit === null ? null : ['shape' => 'serialized'] + $hit;
            }
        }
        if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
            $decoded = json_decode($trimmed, true, self::MAX_DEPTH + 1);
            if (is_array($decoded)) {
                $hit = $this->walk($decoded, [$key], 0);
                return $hit === null ? null : ['shape' => 'json'] + $hit;
            }
        }
        $size = $this->urlSize($value);
        return $size === null ? null : ['shape' => 'url', 'size' => $size];
    }

    /** @return array{size?:string}|null */
    private function walk($node, array $keys, int $depth): ?array
    {
        if ($depth > self::MAX_DEPTH) {
            return null;
        }
        if (is_object($node)) {
            $node = (array) $node; // __PHP_Incomplete_Class: its properties, never its code
        }
        if (is_array($node)) {
            foreach ($node as $key => $child) {
                if ($key === '__PHP_Incomplete_Class_Name') {
                    continue;
                }
                $hit = $this->walk($child, array_merge($keys, [$key]), $depth + 1);
                if ($hit !== null) {
                    return $hit;
                }
            }
            return null;
        }
        if (is_string($node)) {
            $size = $this->urlSize($node);
            if ($size !== null) {
                return ['size' => $size];
            }
        }
        if ((is_int($node) || is_string($node)) && trim((string) $node) === $this->id && $this->pictureKey($keys)) {
            return [];
        }
        return null;
    }

    /**
     * Whether a number at this key path is named as a picture (see the class comment). The path
     * starts with the row's meta key or option name; a list index or a bare `id` defers to the key
     * above it.
     */
    private function pictureKey(array $keys): bool
    {
        $listed = false;
        for ($i = count($keys) - 1; $i >= 0; $i--) {
            $key = $keys[$i];
            if (is_int($key)) {
                $listed = true;
                continue;
            }
            if (in_array(strtolower((string) $key), ['id', 'ids'], true)) {
                continue;
            }
            return self::pictureName((string) $key) || ($listed && self::listName((string) $key));
        }
        return false;
    }

    /** One key, by its words: `jd_intro_image` and `blogBannerImage` yes, `image_width` and `custom_counter` no. */
    public static function pictureName(string $key): bool
    {
        if (in_array($key, self::PICTURE_KEYS, true)) {
            return true;
        }
        $words = self::words($key);
        if ($words === []) {
            return false;
        }
        $last = $words[count($words) - 1];
        if (in_array($last, self::NOT_PICTURE_LAST, true)) {
            return false;
        }
        if ($last === 'id') {
            // `image_id` is the picture's id; `hero_post_id` is another record's.
            return count($words) > 1 && in_array($words[count($words) - 2], self::PICTURE_WORDS, true);
        }
        return array_intersect($words, self::PICTURE_WORDS) !== [];
    }

    /** A key naming a list of pictures (`gallery`, `slides`, `homeCarousel`), not a measure of one (`gallery_count`). */
    private static function listName(string $key): bool
    {
        $words = self::words($key);
        return $words !== [] && !in_array($words[count($words) - 1], self::NOT_PICTURE_LAST, true)
            && array_intersect($words, self::LIST_WORDS) !== [];
    }

    /** @return string[] the key's words, lowercased, a plural `s` taken off */
    private static function words(string $key): array
    {
        $spaced = preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $key);
        $words = preg_split('/[^A-Za-z0-9]+/', strtolower((string) $spaced), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_map(static fn(string $w): string => strlen($w) > 3 && substr($w, -1) === 's' ? substr($w, 0, -1) : $w, $words);
    }

    /** The size whose uploads path this string holds, longest path first so `a-300x200.jpg` is not read as `a.jpg`. */
    private function urlSize(string $value): ?string
    {
        if ($this->paths === [] || strpos($value, '/') === false) {
            return null;
        }
        $paths = array_keys($this->paths);
        usort($paths, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
        $unescaped = strpos($value, '\\/') === false ? $value : str_replace('\\/', '/', $value);
        foreach ($paths as $path) {
            $at = strpos($unescaped, $path);
            while ($at !== false) {
                $next = substr($unescaped, $at + strlen($path), 1);
                // The file name ends here: not `a.jpg` inside `a.jpgx` or `a.jpg.webp`.
                if ($next === '' || !preg_match('/[A-Za-z0-9._-]/', $next)) {
                    return (string) $this->paths[$path];
                }
                $at = strpos($unescaped, $path, $at + 1);
            }
        }
        return null;
    }

    private static function looksSerialized(string $value): bool
    {
        return (bool) preg_match('/^(a|O|s|i|d|b):[0-9]/', $value) || $value === 'N;';
    }

    private static function prefixed(string $key, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (strpos($key, $prefix) === 0) {
                return true;
            }
        }
        return false;
    }
}
