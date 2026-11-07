<?php
/**
 * The inline markup of one text slot, both ways (TracyHQ/tracy#459).
 *
 * A slot with `"markup": "inline"` holds the inner HTML of one element (a heading, a paragraph, a list item, a
 * button's link) in the content map's notation: `((tag attrs))words((/tag))` and `((br/))`, the notation the Joomla
 * capture writes (TCH `unit-markup.php`) and the template kit reads. A value in the notation carries no `<` or `>`,
 * so every rule that refuses markup in a plain value still holds for it; this class is the only place it becomes HTML.
 *
 * What it may become is fixed here and nowhere else: the inline tags of ALLOWED with only their listed attributes,
 * a class only when the slot allows that class, a link only to an address the caller allows. Never a block tag, a
 * script, a style, an event attribute or a style attribute.
 */
declare(strict_types=1);

final class InlineMarkup
{
    /** The tags a marked-up slot may carry and the attributes each may have, in `wp_kses` shape. */
    public const ALLOWED = [
        'span' => ['class' => true], 'strong' => [], 'em' => [], 'b' => [], 'i' => [], 'br' => [],
        'a' => ['href' => true, 'class' => true, 'target' => true, 'rel' => true], 'sup' => [], 'sub' => [],
    ];
    /** The protocols `wp_kses` keeps in a link: the web, mail and phone. Paths and anchors are always kept. */
    public const PROTOCOLS = ['http', 'https', 'mailto', 'tel'];
    private const TARGETS = ['_blank', '_self'];
    private const RELS = ['noopener', 'noreferrer', 'nofollow', 'external', 'ugc', 'sponsored'];
    private const CLASS_NAME = '/^[A-Za-z_][A-Za-z0-9_-]{0,63}$/D';
    private const TOKEN = '/\(\((\/?)([a-z][a-z0-9]*)((?:\s[^()]*)?\/?)\)\)/i';

    /** The inner HTML of an element in the notation: tags as `((tag attrs))`, words entity-decoded and kept as they are. */
    public static function notationOf(string $html): string
    {
        $out = '';
        $at = 0;
        preg_match_all('/<(\/?)([a-zA-Z][a-zA-Z0-9]*)\b((?:[^>"\']|"[^"]*"|\'[^\']*\')*?)(\/?)>/', $html, $tags, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($tags as $tag) {
            $out .= html_entity_decode(substr($html, $at, $tag[0][1] - $at), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $at = $tag[0][1] + strlen($tag[0][0]);
            $name = strtolower($tag[2][0]);
            if ($tag[1][0] === '/') {
                $out .= '((/' . $name . '))';
                continue;
            }
            $attrs = '';
            preg_match_all('/([a-zA-Z_:][a-zA-Z0-9_:.-]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/', $tag[3][0], $pairs, PREG_SET_ORDER);
            foreach ($pairs as $pair) {
                $value = html_entity_decode($pair[2] !== '' ? $pair[2] : ($pair[3] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $attrs .= ' ' . strtolower($pair[1]) . '="' . str_replace('"', '&quot;', $value) . '"';
            }
            $out .= $name === 'br' || $tag[4][0] === '/' ? '((' . $name . $attrs . '/))' : '((' . $name . $attrs . '))';
        }
        return $out . html_entity_decode(substr($html, $at), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** The words a visitor reads of a value in the notation: what a slot's character limit counts. */
    public static function wordsOf(string $value): string
    {
        return (string) preg_replace(self::TOKEN, '', $value);
    }

    /** Every class a value in the notation uses, in order of first use. @return string[] */
    public static function classesOf(string $value): array
    {
        $out = [];
        preg_match_all('/\sclass="([^"]*)"/', $value, $m);
        foreach ($m[1] as $list) {
            foreach (preg_split('/\s+/', trim(html_entity_decode($list, ENT_QUOTES | ENT_HTML5, 'UTF-8'))) ?: [] as $class) {
                if ($class !== '' && !in_array($class, $out, true)) {
                    $out[] = $class;
                }
            }
        }
        return $out;
    }

    /** Whether a per-slot class allowlist holds class names only. */
    public static function classNames($classes): bool
    {
        if (!is_array($classes) || array_values($classes) !== $classes) {
            return false;
        }
        foreach ($classes as $class) {
            if (!is_string($class) || !preg_match(self::CLASS_NAME, $class)) {
                return false;
            }
        }
        return true;
    }

    /**
     * The HTML of a value in the notation, or the reason it is refused (a RuntimeException whose message names it).
     * Words are escaped as the seeder escapes a block's text; each tag is rebuilt from what it was allowed to keep.
     *
     * @param string[] $classes the classes this slot may use
     * @param callable(string):bool $linkAllowed whether a link's address may be written
     * @param bool $links false inside an element that is itself a link (a button), where a link cannot nest
     */
    public static function htmlOf(string $value, array $classes, callable $linkAllowed, bool $links = true): string
    {
        $html = '';
        $open = [];
        $at = 0;
        preg_match_all(self::TOKEN, $value, $tokens, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($tokens as $token) {
            $html .= self::words(substr($value, $at, $token[0][1] - $at));
            $at = $token[0][1] + strlen($token[0][0]);
            $name = strtolower($token[2][0]);
            if (!isset(self::ALLOWED[$name])) {
                throw new RuntimeException('Markup tag ' . $name . ' is not allowed');
            }
            if ($token[1][0] === '/') {
                if ($name === 'br' || array_pop($open) !== $name) {
                    throw new RuntimeException('Markup is not balanced');
                }
                $html .= '</' . $name . '>';
                continue;
            }
            $attrs = trim($token[3][0]);
            $void = substr($attrs, -1) === '/';
            $attrs = rtrim(rtrim($attrs, '/'));
            if ($void && $name !== 'br') {
                throw new RuntimeException('Markup is not readable');
            }
            if ($name === 'a' && (!$links || in_array('a', $open, true))) {
                throw new RuntimeException('Markup link inside a link');
            }
            $html .= '<' . $name . self::attributes($name, $attrs, $classes, $linkAllowed) . '>';
            if ($name !== 'br') {
                $open[] = $name;
            }
        }
        $html .= self::words(substr($value, $at));
        if ($open !== []) {
            throw new RuntimeException('Markup is not balanced');
        }
        return $html;
    }

    /** Words between tags, escaped; a half-written tag left among them is refused. */
    private static function words(string $text): string
    {
        if (strpos($text, '((') !== false || strpos($text, '))') !== false) {
            throw new RuntimeException('Markup is not readable');
        }
        return QuickstartContract::escapeHtml($text);
    }

    /** The attributes of one tag as written, each checked; anything the notation cannot read is refused. */
    private static function attributes(string $tag, string $attrs, array $classes, callable $linkAllowed): string
    {
        $rest = (string) preg_replace_callback('/\s*([a-zA-Z][a-zA-Z0-9_:.-]*)="([^"]*)"/', static function (): string {
            return '';
        }, $attrs);
        if (trim($rest) !== '') {
            throw new RuntimeException('Markup is not readable');
        }
        preg_match_all('/([a-zA-Z][a-zA-Z0-9_:.-]*)="([^"]*)"/', $attrs, $pairs, PREG_SET_ORDER);
        $out = '';
        $seen = [];
        foreach ($pairs as [, $name, $raw]) {
            $name = strtolower($name);
            if (!isset(self::ALLOWED[$tag][$name]) || isset($seen[$name])) {
                throw new RuntimeException('Markup attribute ' . $name . ' is not allowed on ' . $tag);
            }
            $seen[$name] = true;
            $value = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($name === 'class') {
                foreach (preg_split('/\s+/', trim($value)) ?: [] as $class) {
                    if ($class !== '' && !in_array($class, $classes, true)) {
                        throw new RuntimeException('Markup class ' . $class . ' is not in this slot\'s demo');
                    }
                }
            } elseif ($name === 'href' && ($value === '' || !$linkAllowed($value))) {
                throw new RuntimeException('Markup link is not allowed');
            } elseif ($name === 'target' && !in_array($value, self::TARGETS, true)) {
                throw new RuntimeException('Markup target ' . $value . ' is not allowed');
            } elseif ($name === 'rel') {
                foreach (preg_split('/\s+/', trim($value)) ?: [] as $rel) {
                    if (!in_array($rel, self::RELS, true)) {
                        throw new RuntimeException('Markup rel ' . $rel . ' is not allowed');
                    }
                }
            }
            $out .= ' ' . $name . '="' . QuickstartContract::escapeHtml($value) . '"';
        }
        return $out;
    }

    /** The HTML through WordPress's own sanitiser with the same allowlist, where WordPress is loaded. */
    public static function kses(string $html): string
    {
        return function_exists('wp_kses') ? (string) wp_kses($html, self::ALLOWED, self::PROTOCOLS) : $html;
    }
}
