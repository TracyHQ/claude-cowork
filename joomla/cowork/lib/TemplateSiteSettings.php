<?php
/**
 * TemplateSiteSettings — the rules of `template.siteSettings`: which keys of a template's site
 * settings it writes, what a value may be, how one JSON file is edited without touching any other
 * byte, which files are the door's own, and the favicon a template without T4 is given.
 *
 * A T4 template keeps its logo, its name and slogan and its favicon in a FILE, not in the database:
 * `etc/site/<profile>.json`, read local-first (`templates/<t>/local/` → `templates/<t>/` → the T4
 * base theme), each template style naming its profile (`typelist-site`). A file in `local/` replaces
 * the template's file whole, keys are not merged, so the door copies the profile and changes only
 * the keys it was given (TCH #1013, T18; research "logo-name-joomla" §0.3, §1.1).
 *
 * A T3 template (and any other non-T4 one) has no favicon setting at all: Joomla prints
 * `templates/<t>/favicon.ico`. The door keeps that one setting in a file of the same folder
 * ({@see FAVICON_FILE}) and the system plugin prints it ({@see faviconLink}).
 *
 * No Joomla dependency, like the rest of `lib/`. The disk side is {@see TemplateSiteFiles}.
 */
final class TemplateSiteSettings
{
    /** Every key a T4 profile write may carry. Nothing outside this list is ever written. */
    public const T4_KEYS = ['site_logo', 'site_logo_small', 'site_logo_dark', 'site_logo_dark_small', 'site_logo_2', 'site_name', 'site_slogan', 'other_faviconFile'];

    /** The one key a template without T4 has: its favicon. Its logo is a style param (`templateStyle`). */
    public const OTHER_KEYS = ['other_faviconFile'];

    /** Keys holding words, not a picture. */
    private const TEXT_KEYS = ['site_name', 'site_slogan'];

    /** A non-T4 template's favicon setting, beside the T4 profiles in `templates/<t>/local/etc/site/`. */
    public const FAVICON_FILE = 'tracy-favicon.json';

    /** Characters a name or slogan may hold after cleaning, and bytes any value may hold before. */
    public const MAX_CHARACTERS = 200;
    public const MAX_BYTES = 4096;

    /** Media types a picture may have, by extension; a favicon takes the same less nothing. */
    private const TYPES = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'gif' => 'image/gif',
        'svg' => 'image/svg+xml', 'avif' => 'image/avif', 'ico' => 'image/vnd.microsoft.icon'];

    /** A template folder name, as Joomla installs one. */
    public const TEMPLATE = '~^[A-Za-z0-9_-]{1,100}$~D';

    /** A profile name: T4 lets an administrator type one, spaces included, but never a path. */
    public const PROFILE = '~^[A-Za-z0-9_-][A-Za-z0-9 _.-]{0,99}$~D';

    /**
     * Whether a site-relative path is a file this door writes: a JSON file directly in a template's
     * `local/etc/site/`. The contract's file check skips exactly these, so a site whose logo was
     * changed through the door does not read as a design that drifted. Nothing else under `local/`.
     */
    public static function isDoorFile(string $relative): bool
    {
        if (!preg_match('~^templates/([A-Za-z0-9_-]+)/local/etc/site/([^/]+)\.json$~D', $relative, $m)) return false;
        return (bool) preg_match(self::TEMPLATE, $m[1]) && (bool) preg_match(self::PROFILE, $m[2]);
    }

    /**
     * One value as it will be written, or why it is refused.
     *
     * - A name or a slogan: a string, tags removed and made one line as Joomla's own forms clean
     *   text, at most {@see MAX_CHARACTERS}; empty is allowed (T4 then shows the global site name).
     * - A picture (every other key): empty clears it; otherwise a path relative to the site root under
     *   `images/`, `media/` or a template's `images/`, with a picture's extension, naming a file that
     *   is on the site now. A caller uploads first (`media.upload`), under a new name each time, so a
     *   cached copy of the old picture is never what a visitor sees.
     *
     * @param mixed $value
     * @return array{value:string}|array{error:string}
     */
    public static function clean(string $key, $value, string $root): array
    {
        if (!in_array($key, self::T4_KEYS, true)) return ['error' => $key . ' is not a site setting this door writes'];
        if (!is_string($value)) return ['error' => $key . ' must be a string'];
        if (strlen($value) > self::MAX_BYTES) return ['error' => $key . ' is longer than ' . self::MAX_BYTES . ' bytes'];
        if (preg_match('//u', $value) !== 1) return ['error' => $key . ' is not valid UTF-8'];
        if (in_array($key, self::TEXT_KEYS, true)) {
            $text = strip_tags(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $text = (string) preg_replace('/[\r\n\t\x{0085}\x{2028}\x{2029}]+/u', ' ', $text);
            $text = (string) preg_replace('/[\x{0000}-\x{001F}\x{007F}-\x{009F}]/u', '', $text);
            $text = trim((string) preg_replace('/ {2,}/', ' ', $text));
            $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : (int) preg_match_all('/./su', $text);
            if ($length > self::MAX_CHARACTERS) return ['error' => $key . ' is longer than ' . self::MAX_CHARACTERS . ' characters'];
            return ['value' => $text];
        }
        $path = ltrim(trim($value), '/');
        if ($path === '') return ['value' => ''];
        if (self::picturePath($path) === null)
            return ['error' => $key . ' must be a picture under images/ (png, jpg, webp, gif, svg, avif or ico), named by a path relative to the site root'];
        $file = rtrim($root, '/') . '/' . $path;
        if (!is_file($file) || is_link($file)) return ['error' => $key . ': ' . $path . ' is not a file on this site; upload it first (media.upload)'];
        return ['value' => $path];
    }

    /** The extension of a usable picture path, or null: a known folder, plain segments, no `..`. */
    private static function picturePath(string $path): ?string
    {
        if (!preg_match('~^(?:images|media|templates/[A-Za-z0-9_-]+/images)/(?:[A-Za-z0-9_.-]+/)*[A-Za-z0-9_.-]+\.([A-Za-z0-9]+)$~D', $path, $m)) return null;
        if (preg_match('~(^|/)\.{1,2}(/|$)~', $path)) return null;
        $extension = strtolower($m[1]);
        return isset(self::TYPES[$extension]) ? $extension : null;
    }

    /**
     * The whitelisted keys a profile holds, as strings and in the file's order (a missing key is not invented).
     *
     * @param list<string> $keys
     * @return array<string,string>|null null when the text is not a JSON object
     */
    public static function settingsOf(string $json, array $keys): ?array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded) || ltrim($json)[0] !== '{') return null;
        $out = [];
        // In the file's own order: a reader comparing two profiles sees them as the files list them.
        foreach ($decoded as $key => $value) if (in_array($key, $keys, true) && is_scalar($value)) $out[$key] = is_bool($value) ? ($value ? '1' : '') : (string) $value;
        return $out;
    }

    /**
     * The JSON object with these top-level keys set, and every other byte as it was: a value in
     * place is replaced where it stands, a missing key is added at the end. Written as T4's own
     * `json_encode` writes it, unless the file shows it was written otherwise (unescaped slashes or
     * raw UTF-8). Null when the text is not one JSON object, or the edit would not read back as
     * exactly the object with those values — nothing half-edited is ever returned.
     *
     * @param array<string,string> $values
     */
    public static function withValues(string $json, array $values): ?string
    {
        $decoded = json_decode($json, true);
        $open = strspn($json, " \t\r\n");
        if (!is_array($decoded) || ($json[$open] ?? '') !== '{') return null;
        // A slash can only stand inside a string in JSON: one written bare and none escaped says how.
        $flags = (strpos($json, '/') !== false && strpos($json, '\\/') === false ? JSON_UNESCAPED_SLASHES : 0)
            | (preg_match('/[\x80-\xff]/', $json) ? JSON_UNESCAPED_UNICODE : 0);
        $spans = self::topLevelSpans($json, $open);
        if ($spans === null) return null;
        $edits = [];
        $missing = [];
        foreach ($values as $key => $value) {
            $found = false;
            foreach ($spans['values'] as [$name, $start, $end]) if ($name === $key) { $edits[] = [$start, $end, json_encode($value, $flags)]; $found = true; }
            if (!$found) $missing[] = json_encode((string) $key, $flags) . ':' . json_encode($value, $flags);
        }
        if ($missing !== []) {
            $edits[] = $spans['values'] === []
                ? [$open + 1, $spans['close'], implode(',', $missing)]
                : [$spans['last'], $spans['last'], ',' . implode(',', $missing)];
        }
        usort($edits, static fn(array $a, array $b): int => $b[0] <=> $a[0]);
        $out = $json;
        foreach ($edits as [$start, $end, $text]) $out = substr($out, 0, $start) . $text . substr($out, $end);
        return json_decode($out, true) === array_merge($decoded, $values) ? $out : null;
    }

    /**
     * Where each top-level value of the object opening at `$open` stands: `[key, start, end]`, the
     * end of the last one, and the position of the closing brace. Null on text that does not scan.
     *
     * @return array{values:list<array{0:string,1:int,2:int}>,last:int,close:int}|null
     */
    private static function topLevelSpans(string $json, int $open): ?array
    {
        $length = strlen($json);
        $i = $open + 1;
        $values = [];
        $last = $i;
        while ($i < $length) {
            $i += strspn($json, " \t\r\n,", $i);
            if (($json[$i] ?? '') === '}') return ['values' => $values, 'last' => $last, 'close' => $i];
            if (($json[$i] ?? '') !== '"') return null;
            $keyEnd = self::stringEnd($json, $i);
            if ($keyEnd === null) return null;
            $key = json_decode(substr($json, $i, $keyEnd - $i));
            $i = $keyEnd + strspn($json, " \t\r\n", $keyEnd);
            if (($json[$i] ?? '') !== ':' || !is_string($key)) return null;
            $i += 1 + strspn($json, " \t\r\n", $i + 1);
            $start = $i;
            $depth = 0;
            while ($i < $length) {
                $c = $json[$i];
                if ($c === '"') { $i = self::stringEnd($json, $i); if ($i === null) return null; continue; }
                if ($c === '{' || $c === '[') $depth++;
                elseif ($c === '}' || $c === ']') { if ($depth === 0) break; $depth--; }
                elseif ($c === ',' && $depth === 0) break;
                $i++;
            }
            $end = $i;
            while ($end > $start && strpos(" \t\r\n", $json[$end - 1]) !== false) $end--;
            $values[] = [$key, $start, $end];
            $last = $end;
        }
        return null;
    }

    /** The position just after the JSON string opening at `$i`, or null when it never closes. */
    private static function stringEnd(string $json, int $i): ?int
    {
        $length = strlen($json);
        for ($j = $i + 1; $j < $length; $j++) {
            if ($json[$j] === '\\') { $j++; continue; }
            if ($json[$j] === '"') return $j + 1;
        }
        return null;
    }

    /**
     * The favicon a non-T4 template was given through the door: the link to print on its pages, or
     * null to leave Joomla's own. Read from {@see FAVICON_FILE}, and only a picture that is on the
     * site now — the file is data, and whatever else it might name is never printed.
     *
     * @return array{href:string,type:string}|null
     */
    public static function faviconLink(string $root, string $rootUri, string $template): ?array
    {
        if (!preg_match(self::TEMPLATE, $template)) return null;
        $file = rtrim($root, '/') . '/templates/' . $template . '/local/etc/site/' . self::FAVICON_FILE;
        if (!is_file($file) || is_link($file) || filesize($file) > self::MAX_BYTES) return null;
        $settings = self::settingsOf((string) file_get_contents($file), self::OTHER_KEYS);
        $path = ltrim((string) ($settings['other_faviconFile'] ?? ''), '/');
        $extension = $path === '' ? null : self::picturePath($path);
        if ($extension === null || !is_file(rtrim($root, '/') . '/' . $path)) return null;
        return ['href' => rtrim($rootUri, '/') . '/' . $path, 'type' => self::TYPES[$extension]];
    }

    /**
     * A document's head links without its favicons: every `rel` whose words include `icon`
     * (`icon`, `shortcut icon`, `alternate icon`), and nothing else — `apple-touch-icon` and
     * `mask-icon` are other words, and a feed or a canonical link is untouched.
     *
     * @param array<string,array<string,mixed>> $links HtmlDocument's `_links`, keyed by URL
     * @return array<string,array<string,mixed>>
     */
    public static function withoutFavicons(array $links): array
    {
        return array_filter($links, static function ($link): bool {
            if (!is_array($link) || ($link['relType'] ?? 'rel') !== 'rel') return true;
            $words = preg_split('/\s+/', strtolower(trim((string) ($link['relation'] ?? ''))));
            return !in_array('icon', $words ?: [], true);
        });
    }

    /**
     * A printed page whose `<head>` keeps one favicon: the customer's (`$keepHref`, as printed). Every
     * other `<link>` whose `rel` words include `icon` goes, with the white space after it.
     *
     * Why the printed page and not only {@see withoutFavicons}: Joomla's MetasRenderer adds
     * `templates/<t>/favicon.ico` AFTER `onBeforeCompileHead` whenever no head link has the type
     * `image/vnd.microsoft.icon`, so a PNG favicon is always followed by the template's icon and a
     * browser may show that one. A template printing its own icon tag is covered the same way. Only
     * the head is read: a page without `</head>` (JSON, a fragment) is returned as it is.
     */
    public static function withoutOtherFaviconTags(string $html, string $keepHref): string
    {
        $end = stripos($html, '</head>');
        if ($end === false) return $html;
        $head = preg_replace_callback('~<link\b[^>]*>\s*~i', static function (array $m) use ($keepHref): string {
            $rel = self::attributeOf($m[0], 'rel');
            $words = $rel === null ? [] : (preg_split('/\s+/', strtolower(trim($rel))) ?: []);
            if (!in_array('icon', $words, true) || self::attributeOf($m[0], 'href') === $keepHref) return $m[0];
            return '';
        }, substr($html, 0, $end));
        return $head === null ? $html : $head . substr($html, $end);
    }

    /** One attribute of a tag, entities decoded, or null; `data-rel` is not `rel`. */
    private static function attributeOf(string $tag, string $name): ?string
    {
        $pattern = '~(?<![\w-])' . preg_quote($name, '~') . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))~i';
        if (!preg_match($pattern, $tag, $m)) return null;
        $value = ($m[1] ?? '') !== '' ? $m[1] : (($m[2] ?? '') !== '' ? $m[2] : ($m[3] ?? ''));
        return html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
