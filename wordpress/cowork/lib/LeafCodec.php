<?php
/**
 * LeafCodec — the visitor-readable leaves inside one stored value, and a way to rewrite one of them.
 *
 * A stored value may be plain text, HTML, shortcodes, JSON or PHP serialize, nested in any order (JSON in a
 * serialized option, HTML in a JSON setting, shortcodes around HTML). A leaf is addressed by a path of
 * `<codec>:<address>` steps joined by `|`. A write re-encodes every layer in its own format and is refused
 * (LeafCodecError) rather than store a value that does not read back.
 *
 * JSON is never re-encoded as a whole: a write re-encodes only the one string token it changes and splices
 * it into the raw text, so every other byte (spacing, key order, escape style, nested JSON held in other
 * strings) stays exactly as it was. The token's escape style is copied from the token itself first, then
 * from the rest of the document, then from the writer that produced it (see jsonEncode()). A JSON Pointer
 * step escapes `~` `/` `|` in a key as `~0` `~1` `~2`.
 *
 * `vcl` is WPBakery's link mini-format (`url:<pct>|title:<pct>|target:_blank`, always starting with url:
 * and never holding raw whitespace): the url and title parts are leaves (addressed `vcl:url`,
 * `vcl:title`), percent-decoded to read and rawurlencoded to write. A key that repeats is a leaf only at
 * its first occurrence, which is also the one a write changes.
 *
 * set() is value-exact, not byte-exact: get(set(v)) === v, but a layer that re-encodes its value can pick a
 * different spelling than the one it replaced (rawurlencode writes `(` `)` `!` as %28 %29 %21, HTML text
 * loses `&nbsp;` for the raw character), so setting the old value back does not always restore the old
 * bytes. apply.revert restores the stored before-image of the whole column, not set(old).
 *
 * A credential is no leaf (SecretLeaf, 02/10/2026): a JSON or serialized key whose NAME says it holds one
 * (`smtp`, `api_key`, `password`...) takes its whole subtree with it, and a text whose SHAPE gives it away (a
 * Google or Stripe key, a JWT, a webhook address...) is skipped wherever it sits. Listing only; get() and set()
 * follow the address they are given.
 *
 * Plain PHP, no CMS. Identical in the Joomla and WordPress engines.
 */
declare(strict_types=1);

require_once __DIR__ . '/SecretLeaf.php';

final class LeafCodecError extends RuntimeException {}

final class LeafCodec
{
    public const MAX_BYTES = 2000000;
    private const DEPTH = 6;
    private const URL_ATTRS = ['href', 'src', 'poster', 'data-src'];
    private const TEXT_ATTRS = ['alt', 'title', 'placeholder', 'aria-label', 'content', 'value'];
    private const RAW_TAGS = ['script', 'style', 'textarea', 'noscript', 'template'];
    private const TAG = '/<!--.*?-->|<(\/?)([a-zA-Z][\w:-]*)((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>/s';
    private const SHORTCODE = '/\[(\/)?([a-zA-Z][\w-]*)((?:[^\[\]"\']|"[^"]*"|\'[^\']*\')*)\]/';
    private const ATTR = '/([a-zA-Z_:][\w:.-]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>\]]+))/';
    private const VCL = '/^url:[^|\s]*(?:\|(?:(?:title|target|rel):[^|\s]*)?)*$/';
    private const VCL_LEAVES = ['url', 'title'];

    /** @return list<array{path:string,type:string,text:string}> */
    public static function leaves(string $raw): array
    {
        $out = [];
        self::walk($raw, [], 0, $out);
        return $out;
    }

    public static function get(string $raw, string $path): string
    {
        return self::read($raw, self::steps($path), 0);
    }

    /**
     * rewrite() without the write: the same steps, the same refusals, but no layer is re-encoded or
     * spliced back, so reading one leaf costs the parse of each layer (kept by memo()), not a copy
     * of the whole column per step.
     *
     * @param list<array{0:string,1:string}> $steps
     */
    private static function read(string $raw, array $steps, int $index): string
    {
        if ($index === count($steps)) return $raw;
        [$codec, $address] = $steps[$index];
        if ($codec === 'text') return $raw;
        $have = self::detect($raw);
        if ($have !== $codec) throw new LeafCodecError("Expected {$codec} at step {$index}, found {$have}");
        if ($codec === 'ser') {
            $node = unserialize($raw, ['allowed_classes' => false]);
            foreach (self::pointerKeys($address) as $key) {
                if (is_array($node) && array_key_exists($key, $node)) $node = $node[$key];
                elseif (is_object($node) && property_exists($node, $key)) $node = $node->{$key};
                else throw new LeafCodecError("No key {$key}");
            }
            if (!is_string($node)) throw new LeafCodecError('The leaf is not a string');
            return self::read($node, $steps, $index + 1);
        }
        if ($codec === 'json') {
            [$start, $length] = self::jsonLocate($raw, $address);
            return self::read((string) json_decode(substr($raw, $start, $length)), $steps, $index + 1);
        }
        if ($codec === 'vcl') {
            foreach (self::vclParts($raw) as $part) if ($part['key'] === $address) return self::read(rawurldecode($part['value']), $steps, $index + 1);
            throw new LeafCodecError("No vcl part {$address}");
        }
        foreach (self::segments($raw, $codec) as $segment) if ($segment['address'] === $address) return self::read($segment['value'], $steps, $index + 1);
        throw new LeafCodecError("No {$codec} segment {$address}");
    }

    public static function set(string $raw, string $path, string $value): string
    {
        $out = self::rewrite($raw, self::steps($path), 0, static fn(string $leaf): string => $value);
        if (self::get($out, $path) !== $value) throw new LeafCodecError('The rewritten value does not read back');
        return $out;
    }

    public static function detect(string $raw): string
    {
        return self::memo('detect', $raw, static fn(): string => self::detectOnce($raw));
    }

    private static function detectOnce(string $raw): string
    {
        if (strlen($raw) > self::MAX_BYTES) return 'opaque';
        $trim = ltrim($raw);
        if (preg_match('/^(a|s|i|d|b|N):/', $raw)) {
            $value = @unserialize($raw, ['allowed_classes' => false]);
            if ($value !== false || $raw === 'b:0;') return self::hasObject($value) ? 'opaque' : 'ser';
        }
        if (preg_match('/^O:\d+:"/', $raw)) return 'opaque';
        if ($trim !== '' && ($trim[0] === '{' || $trim[0] === '[')) {
            json_decode($raw);
            if (json_last_error() === JSON_ERROR_NONE) return 'json';
        }
        if (preg_match('/\[\/[a-zA-Z][\w-]*\]|\[[a-zA-Z][\w-]*\s+[\w-]+\s*=/', $raw)) return 'sc';
        if (preg_match('/<[a-zA-Z!\/]/', $raw)) return 'html';
        if (preg_match(self::VCL, $raw)) return 'vcl';
        return 'text';
    }

    /** @param list<array{0:string,1:string}> $prefix */
    private static function walk(string $raw, array $prefix, int $depth, array &$out): void
    {
        $codec = $depth > self::DEPTH ? 'text' : self::detect($raw);
        switch ($codec) {
            case 'opaque':
                return;
            case 'ser':
                self::walkTree(unserialize($raw, ['allowed_classes' => false]), '', 'ser', $prefix, $depth, $out);
                return;
            case 'json':
                self::walkTree(json_decode($raw, true), '', 'json', $prefix, $depth, $out);
                return;
            case 'sc':
            case 'html':
                foreach (self::segments($raw, $codec) as $segment) {
                    $step = [$codec, $segment['address']];
                    if ($segment['attr'] !== null && !self::usefulAttr($segment['attr'], $segment['value'])) continue;
                    self::walk($segment['value'], array_merge($prefix, [$step]), $depth + 1, $out);
                }
                return;
            case 'vcl':
                $seen = [];
                foreach (self::vclParts($raw) as $part) {
                    if (!in_array($part['key'], self::VCL_LEAVES, true) || isset($seen[$part['key']])) continue;
                    $seen[$part['key']] = true;
                    self::walk(rawurldecode($part['value']), array_merge($prefix, [['vcl', $part['key']]]), $depth + 1, $out);
                }
                return;
            default:
                $text = trim($raw);
                $type = self::typeOf($text);
                if ($type === null || SecretLeaf::value($text)) return;
                $out[] = ['path' => self::path(array_merge($prefix, [['text', '']])), 'type' => $type, 'text' => $text];
        }
    }

    private static function walkTree($value, string $pointer, string $codec, array $prefix, int $depth, array &$out): void
    {
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                if (is_string($key) && (self::technicalKey($key) || SecretLeaf::name($key))) continue;
                self::walkTree($child, $pointer . '/' . strtr((string) $key, ['~' => '~0', '/' => '~1', '|' => '~2']), $codec, $prefix, $depth, $out);
            }
            return;
        }
        if (is_string($value)) self::walk($value, array_merge($prefix, [[$codec, $pointer]]), $depth + 1, $out);
    }

    /** @param list<array{0:string,1:string}> $steps */
    private static function rewrite(string $raw, array $steps, int $index, callable $leaf): string
    {
        if ($index === count($steps)) return $leaf($raw);
        [$codec, $address] = $steps[$index];
        if ($codec === 'text') return $leaf($raw);
        $have = self::detectOnce($raw);
        if ($have !== $codec) throw new LeafCodecError("Expected {$codec} at step {$index}, found {$have}");
        if ($codec === 'ser') {
            $tree = unserialize($raw, ['allowed_classes' => false]);
            $tree = self::replaceAt($tree, $address, fn(string $child) => self::rewrite($child, $steps, $index + 1, $leaf));
            return serialize($tree);
        }
        if ($codec === 'json') {
            [$start, $length] = self::jsonLocate($raw, $address, false);
            $token = substr($raw, $start, $length);
            $new = self::rewrite((string) json_decode($token), $steps, $index + 1, $leaf);
            // A Gutenberg block comment's attributes (`html:c<n>`) are written by serialize_block_attributes().
            $block = $index > 0 && $steps[$index - 1][0] === 'html' && preg_match('/^c\d+$/', $steps[$index - 1][1]) === 1;
            return substr($raw, 0, $start) . self::jsonEncode($new, $token, $raw, $block) . substr($raw, $start + $length);
        }
        if ($codec === 'vcl') {
            foreach (self::vclParts($raw) as $part) {
                if ($part['key'] !== $address) continue;
                $new = rawurlencode(self::rewrite(rawurldecode($part['value']), $steps, $index + 1, $leaf));
                return substr($raw, 0, $part['start']) . $new . substr($raw, $part['start'] + strlen($part['value']));
            }
            throw new LeafCodecError("No vcl part {$address}");
        }
        foreach (self::segmentsOnce($raw, $codec) as $segment) {
            if ($segment['address'] !== $address) continue;
            $new = self::rewrite($segment['value'], $steps, $index + 1, $leaf);
            if ($segment['quote'] === 'raw') $encoded = $new;
            elseif ($segment['attr'] !== null && $segment['quote'] === '') $encoded = htmlspecialchars($new, ENT_QUOTES);
            elseif ($segment['attr'] !== null) $encoded = str_replace($segment['quote'], $segment['quote'] === '"' ? '&quot;' : '&#039;', $new);
            elseif ($codec === 'html') $encoded = htmlspecialchars($new, ENT_NOQUOTES | ENT_HTML5, 'UTF-8', false);
            else $encoded = $new;
            return substr($raw, 0, $segment['start']) . $encoded . substr($raw, $segment['start'] + $segment['length']);
        }
        throw new LeafCodecError("No {$codec} segment {$address}");
    }

    /**
     * Text gaps and attribute values of an HTML or shortcode string, with byte offsets into it.
     * HTML text is entity-decoded (and re-escaped on write); shortcode text is kept raw.
     *
     * @return list<array{address:string,value:string,start:int,length:int,attr:?string,quote:string}>
     */
    private static function segments(string $raw, string $codec): array
    {
        return self::memo('segments:' . $codec, $raw, static fn(): array => self::segmentsOnce($raw, $codec));
    }

    private static function segmentsOnce(string $raw, string $codec): array
    {
        $pattern = $codec === 'html' ? self::TAG : self::SHORTCODE;
        preg_match_all($pattern, $raw, $tags, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        $out = [];
        $gap = 0;
        $comment = 0;
        $tagIndex = 0;
        $cursor = 0;
        $rawUntil = null;
        foreach ($tags as $tag) {
            [$whole, $at] = $tag[0];
            if ($rawUntil === null && $at > $cursor) {
                $text = substr($raw, $cursor, $at - $cursor);
                if (trim($text) !== '') {
                    $lead = strlen($text) - strlen(ltrim($text));
                    $value = trim($text);
                    $out[] = ['address' => 'g' . $gap++, 'value' => $codec === 'html' ? html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8') : $value,
                        'start' => $cursor + $lead, 'length' => strlen($value), 'attr' => null, 'quote' => ''];
                }
            }
            $cursor = $at + strlen($whole);
            if (strncmp($whole, '<!--', 4) === 0) {
                // A block comment carries the block's attributes as JSON (`<!-- wp:button {"text":"Buy"} /-->`).
                if ($rawUntil === null && preg_match('~^<!--\s+wp:[\w/-]+\s+(\{.*\})\s+/?-->$~s', $whole, $json, PREG_OFFSET_CAPTURE)) {
                    $out[] = ['address' => 'c' . $comment, 'value' => $json[1][0], 'start' => $at + $json[1][1],
                        'length' => strlen($json[1][0]), 'attr' => 'block', 'quote' => 'raw'];
                }
                $comment++;
                continue;
            }
            $closing = ($tag[1][0] ?? '') === '/';
            $name = strtolower($tag[2][0] ?? '');
            if ($rawUntil !== null) {
                if ($closing && $name === $rawUntil) $rawUntil = null;
                continue;
            }
            $n = $tagIndex++;
            if (!$closing && $codec === 'html' && in_array($name, self::RAW_TAGS, true)) $rawUntil = $name;
            if ($closing || !isset($tag[3])) continue;
            [$attrs, $attrsAt] = $tag[3];
            preg_match_all(self::ATTR, $attrs, $pairs, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
            foreach ($pairs as $pair) {
                $attr = strtolower($pair[1][0]);
                foreach ([2 => '"', 3 => "'", 4 => ''] as $group => $quote) {
                    if (!isset($pair[$group]) || $pair[$group][1] < 0) continue;
                    [$value, $valueAt] = $pair[$group];
                    $out[] = ['address' => 'a' . $n . '.' . $attr, 'value' => $codec === 'html' ? html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8') : $value,
                        'start' => $attrsAt + $valueAt, 'length' => strlen($value), 'attr' => $attr, 'quote' => $quote];
                    break;
                }
            }
        }
        if ($rawUntil === null && $cursor < strlen($raw) && trim(substr($raw, $cursor)) !== '') {
            $text = substr($raw, $cursor);
            $lead = strlen($text) - strlen(ltrim($text));
            $value = trim($text);
            $out[] = ['address' => 'g' . $gap, 'value' => $codec === 'html' ? html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8') : $value,
                'start' => $cursor + $lead, 'length' => strlen($value), 'attr' => null, 'quote' => ''];
        }
        return $out;
    }

    private static function replaceAt($tree, string $pointer, callable $child)
    {
        $keys = self::pointerKeys($pointer);
        $walk = static function ($node, array $keys) use (&$walk, $child) {
            if ($keys === []) {
                if (!is_string($node)) throw new LeafCodecError('The leaf is not a string');
                return $child($node);
            }
            $key = array_shift($keys);
            if (is_array($node) && array_key_exists($key, $node)) { $node[$key] = $walk($node[$key], $keys); return $node; }
            if (is_object($node) && property_exists($node, $key)) { $node->{$key} = $walk($node->{$key}, $keys); return $node; }
            throw new LeafCodecError("No key {$key}");
        };
        return $walk($tree, $keys);
    }

    /** @return list<string> */
    private static function pointerKeys(string $pointer): array
    {
        if ($pointer === '') return [];
        if ($pointer[0] !== '/') throw new LeafCodecError("Bad JSON pointer {$pointer}");
        return array_map(static fn($k) => strtr($k, ['~2' => '|', '~1' => '/', '~0' => '~']), array_slice(explode('/', $pointer), 1));
    }

    /**
     * Byte span [start, length] of the string token (quotes included) that a JSON Pointer names in $raw.
     * Walks the raw text itself instead of decoding it, so a write can splice one token and leave every
     * other byte alone. On a duplicate object key the last one wins, as json_decode() does.
     * The scanner does not validate: every caller reaches it through detect(), which already json_decode'd
     * this exact text, so it only has to find its way, not judge.
     *
     * @return array{0:int,1:int}
     */
    private static function jsonLocate(string $raw, string $pointer, bool $kept = true): array
    {
        $at = self::jsonSpace($raw, 0);
        foreach (self::pointerKeys($pointer) as $key) {
            // The members by name, the last of a repeated name winning, as JSON decoders read it.
            $names = static function () use ($raw, $at): array {
                $names = [];
                foreach (self::jsonMembers($raw, $at) as [$name, $valueAt]) $names[(string) $name] = $valueAt;
                return $names;
            };
            $members = $kept ? self::memo('names:' . $at, $raw, $names) : $names();
            if (!isset($members[$key])) throw new LeafCodecError("No key {$key}");
            $at = $members[$key];
        }
        if (($raw[$at] ?? '') !== '"') throw new LeafCodecError('The leaf is not a string');
        return [$at, self::jsonSkip($raw, $at) - $at];
    }

    /**
     * The members of the object or array that starts at $at: [key or index, offset of its value].
     *
     * @return list<array{0:string|int,1:int}>
     */
    private static function jsonMembers(string $raw, int $at): array
    {
        $open = $raw[$at] ?? '';
        if ($open !== '{' && $open !== '[') return [];
        $close = $open === '{' ? '}' : ']';
        $out = [];
        $at = self::jsonSpace($raw, $at + 1);
        if (($raw[$at] ?? '') === $close) return [];
        while (true) {
            $name = count($out);
            if ($open === '{') {
                $keyEnd = self::jsonSkip($raw, $at);
                $name = (string) json_decode(substr($raw, $at, $keyEnd - $at));
                $at = self::jsonSpace($raw, self::jsonSpace($raw, $keyEnd) + 1); // past the ':'
            }
            $out[] = [$name, $at];
            $at = self::jsonSpace($raw, self::jsonSkip($raw, $at));
            if (($raw[$at] ?? '') !== ',') return $out;
            $at = self::jsonSpace($raw, $at + 1);
        }
    }

    /** What memo() may hold: the memory its kept parses take, measured as they are made. */
    private const MEMO_BYTES = 33554432;
    /** @var array<string,array<string,mixed>> what memo() keeps: the value parsed => kind => its parse */
    private static array $memo = [];
    private static int $memoBytes = 0;

    /**
     * A pure parse of one value (its codec, its segments, the names of one JSON container's members),
     * kept while the same value is asked again by a READ. Reading the leaves of one column one by one
     * parsed the whole column once per leaf: a T4 template style with thousands of slots took 2 s of
     * every content.read on an imported site (30/09/2026). Keyed by the value itself; short values
     * are cheaper to parse than to keep. Counted by the memory each kept parse takes (a JSON of
     * 40,000 small objects is under 1 MB of text and tens of MB of parses): past MEMO_BYTES it starts
     * over. rewrite() never comes here, so a write depends on nothing kept.
     */
    private static function memo(string $kind, string $raw, callable $parse)
    {
        if (strlen($raw) < 512) return $parse();
        if (isset(self::$memo[$raw]) && array_key_exists($kind, self::$memo[$raw])) return self::$memo[$raw][$kind];
        if (self::$memoBytes > self::MEMO_BYTES) {
            self::$memo = [];
            self::$memoBytes = 0;
        }
        $before = memory_get_usage();
        $parsed = $parse();
        self::$memo[$raw][$kind] = $parsed;
        self::$memoBytes += max(0, memory_get_usage() - $before) + 64;
        return $parsed;
    }

    private static function jsonSpace(string $raw, int $at): int
    {
        return $at + strspn($raw, " \t\r\n", $at);
    }

    /** Offset just past the JSON value that starts at $at. */
    private static function jsonSkip(string $raw, int $at): int
    {
        $length = strlen($raw);
        $char = $raw[$at] ?? '';
        if ($char === '"') {
            for ($i = $at + 1; $i < $length; $i++) {
                if ($raw[$i] === '\\') { $i++; continue; }
                if ($raw[$i] === '"') return $i + 1;
            }
            throw new LeafCodecError('Malformed JSON');
        }
        if ($char === '{' || $char === '[') {
            $depth = 0;
            for ($i = $at; $i < $length; $i++) {
                $c = $raw[$i];
                if ($c === '"') { $i = self::jsonSkip($raw, $i) - 1; continue; }
                if ($c === '{' || $c === '[') $depth++;
                elseif (($c === '}' || $c === ']') && --$depth === 0) return $i + 1;
            }
            throw new LeafCodecError('Malformed JSON');
        }
        $end = $at + strcspn($raw, ",]} \t\r\n", $at);
        if ($end === $at) throw new LeafCodecError('Malformed JSON');
        return $end;
    }

    private const JSON_HEX = ['<' => '003c', '>' => '003e', '&' => '0026', "'" => '0027', '"' => '0022'];

    /**
     * Encode one string as a JSON token in the style of the token it replaces.
     *
     * Every choice is answered by the old token's own characters first, then by the rest of the document
     * (read escape-aware, so a nested JSON string's `\\/`, an escaped backslash then a plain slash, never
     * counts as `\/`), then by the writer that most likely produced it:
     *  - slashes: `\/` or `/`; no evidence escapes them (json_encode, wp_json_encode, Joomla's Registry do),
     *    except in a block comment, which WordPress writes unescaped;
     *  - non-ASCII: `\uXXXX` or raw UTF-8 (raw also leaves U+2028/U+2029 raw); no evidence escapes it
     *    unless in a block comment, so an ASCII-only store stays ASCII-only;
     *  - `<` `>` `&` `'` `"`: raw (`\"` for the quote) or `\u00XX` in the spelling seen; no evidence keeps
     *    them raw, except in a block comment, where `<` `>` `&` `"` are `\u00XX` as serialize_block_attributes
     *    writes them. A block comment also always gets `--` as `\u002d\u002d`, so the comment cannot close early.
     */
    private static function jsonEncode(string $value, string $token, string $raw, bool $block): string
    {
        $own = self::jsonStyle($token);
        $all = self::jsonStyle($raw);
        $slash = $own['slash'] ?? $all['slash'] ?? !$block;
        $unicode = $own['unicode'] ?? $all['unicode'] ?? !$block;
        $flags = ($slash ? 0 : JSON_UNESCAPED_SLASHES) | ($unicode ? 0 : JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS);
        $encoded = json_encode($value, $flags);
        if ($encoded === false) throw new LeafCodecError('JSON did not encode');
        $inner = substr($encoded, 1, -1);
        foreach (self::JSON_HEX as $char => $hex) {
            $form = $own['chars'][$char] ?? $all['chars'][$char] ?? ($block && $char !== "'" ? '\u' . $hex : '');
            if ($form === '') continue;
            // Inside json_encode output every `"` is escaped, so each one is preceded by its own backslash.
            $inner = $char === '"' ? str_replace('\"', $form, $inner) : str_replace($char, $form, $inner);
        }
        if ($block) $inner = str_replace('--', '\u002d\u002d', $inner);
        return '"' . $inner . '"';
    }

    /**
     * Escape evidence inside the string tokens of a JSON text (or of one token); the first seen wins.
     * slash: true on `\/`, false on a plain `/`; unicode: true on `\u` of a non-ASCII code point, false on a
     * raw non-ASCII byte; chars: per character of JSON_HEX, '' when written raw (`\"` for the quote) or the
     * exact `\u00XX` spelling. null or absent means no evidence.
     *
     * @return array{slash:?bool,unicode:?bool,chars:array<string,string>}
     */
    private static function jsonStyle(string $json): array
    {
        $out = ['slash' => null, 'unicode' => null, 'chars' => []];
        $hexChars = array_flip(self::JSON_HEX);
        $length = strlen($json);
        $inString = false;
        for ($i = 0; $i < $length; $i++) {
            $c = $json[$i];
            if (!$inString) { if ($c === '"') $inString = true; continue; }
            if ($c === '"') { $inString = false; continue; }
            if ($c === '/') { $out['slash'] ??= false; continue; }
            if (ord($c) >= 0x80) { $out['unicode'] ??= false; continue; }
            if (isset(self::JSON_HEX[$c])) { $out['chars'][$c] ??= ''; continue; }
            if ($c !== '\\') continue;
            $next = $json[++$i] ?? '';
            if ($next === '/') $out['slash'] ??= true;
            elseif ($next === '"') $out['chars']['"'] ??= '';
            elseif ($next === 'u') {
                $hex = substr($json, $i + 1, 4);
                if (hexdec($hex) >= 0x80) $out['unicode'] ??= true;
                if (isset($hexChars[strtolower($hex)])) $out['chars'][$hexChars[strtolower($hex)]] ??= '\u' . $hex;
                $i += 4;
            }
        }
        return $out;
    }

    /**
     * The key:value parts of a WPBakery link value, with byte offsets of each value.
     *
     * @return list<array{key:string,value:string,start:int}>
     */
    private static function vclParts(string $raw): array
    {
        $out = [];
        $at = 0;
        foreach (explode('|', $raw) as $part) {
            $colon = strpos($part, ':');
            if ($colon === false) { $at += strlen($part) + 1; continue; }
            $out[] = ['key' => substr($part, 0, $colon), 'value' => substr($part, $colon + 1), 'start' => $at + $colon + 1];
            $at += strlen($part) + 1;
        }
        return $out;
    }

    private static function usefulAttr(string $attr, string $value): bool
    {
        return $attr === 'block' || in_array($attr, self::URL_ATTRS, true) || in_array($attr, self::TEXT_ATTRS, true)
            || (self::typeOf(trim($value)) !== null && !in_array($attr, ['class', 'id', 'style', 'type', 'name', 'rel', 'target', 'width', 'height'], true));
    }

    private static function technicalKey(string $key): bool
    {
        return (bool) preg_match('/^(_|id$|elType$|widgetType$|css|class|style|color|colour|align|size|width|height|margin|padding|font|animation|icon$|layout|position)/i', $key);
    }

    public static function typeOf(string $text): ?string
    {
        if ($text === '' || strlen($text) > 20000) return null;
        if (preg_match('~^(https?:)?//\S+$|^/\S*$|^(mailto|tel):\S+$~i', $text)) {
            return preg_match('~\.(jpe?g|png|gif|webp|avif|svg)(\?.*)?$~i', $text) ? 'image' : 'url';
        }
        if (!preg_match('/\p{L}/u', $text)) return null;
        if (preg_match('/^[a-z0-9]+([_-][a-z0-9]+)+$/', $text)) return null;
        if (preg_match('/^(#[0-9a-f]{3,8}|(rgba?|hsla?|var|calc)\(.*\))$/i', $text)) return null;
        if (preg_match('/^[0-9a-f]{8,}$/i', $text)) return null;
        return mb_strlen($text) >= 2 ? 'text' : null;
    }

    private static function hasObject($value): bool
    {
        if (is_object($value)) return true;
        if (is_array($value)) foreach ($value as $child) if (self::hasObject($child)) return true;
        return false;
    }

    /** @param list<array{0:string,1:string}> $steps */
    private static function path(array $steps): string
    {
        return implode('|', array_map(static fn($s) => $s[0] . ':' . $s[1], $steps));
    }

    /** @return list<array{0:string,1:string}> */
    private static function steps(string $path): array
    {
        $out = [];
        foreach (explode('|', $path) as $step) {
            $colon = strpos($step, ':');
            if ($colon === false) throw new LeafCodecError("Bad step {$step}");
            $out[] = [substr($step, 0, $colon), substr($step, $colon + 1)];
        }
        return $out;
    }
}
