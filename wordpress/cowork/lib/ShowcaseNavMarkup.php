<?php
/**
 * The three edits a showcase-navigation map makes to block markup, BY BYTE RANGE: take one entry out
 * of a `wp_navigation` post, repoint the `ref` of one navigation block of a template part, drop one
 * block of a template part. A port of Tracy's `scripts/lib/showcase-nav/wp-navigation-ast.mjs`, the
 * generator whose output the map's `before`/`after` sha256 fingerprint.
 *
 * 🔒 BYTES ARE NOT RE-SERIALISED. Not `parse_blocks()` + `serialize_blocks()`: those normalise the
 * attribute JSON and the whitespace between blocks, so the result would never be the bytes the map
 * promises. An edit cuts the span of one block (and the line break after it) or rewrites the attribute
 * JSON of one comment; every other byte is the one it read. Whatever this returns is still checked by
 * the caller against the map's `after` sha256 before anything is written, so a reading that differs
 * from the generator's on some exotic markup refuses the write; it never writes something else.
 *
 * Offsets are bytes (the generator's are UTF-16 units over the same text, so a span is the same span).
 */
final class ShowcaseNavMarkup
{
    /** JavaScript's `\s`, which the generator's patterns use: ASCII whitespace and the Unicode spaces. */
    private const S = '[\t\n\x{0B}\f\r \x{A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]';
    /** The block comment grammar of WordPress's block parser, attributes included (the generator's COMMENT). */
    private const COMMENT = '~<!--' . self::S . '+(/)?wp:([a-z][a-z0-9_-]*(?:/[a-z][a-z0-9_-]*)?)' . self::S . '+(\{[\s\S]*?\}' . self::S . '+)?(/)?-->~u';
    private const ITEMS = ['core/navigation-link', 'core/navigation-submenu'];

    /**
     * Every block, in document order: name, attrs, rawAttrs, start, end (the whole block, opening
     * comment to closing comment, or the void comment), parent and children as indexes.
     *
     * @return list<array{name:string,attrs:array,rawAttrs:?string,start:int,end:int,parent:?int,children:list<int>}>
     */
    public static function parse(string $markup): array
    {
        if (preg_match_all(self::COMMENT, $markup, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === false) {
            throw new RuntimeException('the block markup is not UTF-8 the block parser can read');
        }
        $blocks = [];
        $open = [];
        foreach ($matches as $m) {
            $whole = $m[0][0];
            $at = (int) $m[0][1];
            $group = static fn(int $n): ?string => isset($m[$n]) && $m[$n][1] >= 0 && $m[$n][0] !== '' ? $m[$n][0] : null;
            $name = self::fullName((string) $group(2));
            if ($group(1) !== null) {
                $top = $open === [] ? null : end($open);
                if ($top === null || $blocks[$top]['name'] !== $name) {
                    throw new RuntimeException('block markup closes ' . $name . ' while ' . ($top === null ? 'nothing' : $blocks[$top]['name']) . ' is open');
                }
                array_pop($open);
                $blocks[$top]['end'] = $at + strlen($whole);
                continue;
            }
            $raw = $group(3);
            $attrs = [];
            if ($raw !== null) {
                $attrs = json_decode($raw, true);
                if (!is_array($attrs)) {
                    throw new RuntimeException('block ' . $name . ' has attributes that do not parse');
                }
            }
            $parent = $open === [] ? null : end($open);
            $index = count($blocks);
            $blocks[] = ['name' => $name, 'attrs' => $attrs, 'rawAttrs' => $raw, 'start' => $at, 'end' => $at + strlen($whole), 'parent' => $parent, 'children' => []];
            if ($parent !== null) {
                $blocks[$parent]['children'][] = $index;
            }
            if ($group(4) === null) {
                $open[] = $index;
            }
        }
        if ($open !== []) {
            throw new RuntimeException('block markup leaves ' . implode(', ', array_map(static fn(int $i): string => $blocks[$i]['name'], $open)) . ' open');
        }
        return $blocks;
    }

    /**
     * The navigation entries of a container (a `wp_navigation` post, or the children of one block),
     * each with the block it is and its path: the labels from the top entry down.
     *
     * @return list<array{block:int,label:string,pageId:?int,path:list<string>}>
     */
    public static function items(array $blocks, ?int $within = null): array
    {
        $items = [];
        $visit = static function (int $index, array $path) use (&$visit, &$items, $blocks): void {
            $block = $blocks[$index];
            if (!in_array($block['name'], self::ITEMS, true)) {
                foreach ($block['children'] as $child) {
                    $visit($child, $path);
                }
                return;
            }
            $label = self::trim(self::text($block['attrs']['label'] ?? ''));
            $path[] = $label;
            $items[] = ['block' => $index, 'label' => $label, 'pageId' => self::pageId($block['attrs']), 'path' => $path];
            foreach ($block['children'] as $child) {
                $visit($child, $path);
            }
        };
        $roots = $within === null
            ? array_keys(array_filter($blocks, static fn(array $b): bool => $b['parent'] === null))
            : $blocks[$within]['children'];
        foreach ($roots as $root) {
            $visit($root, []);
        }
        return $items;
    }

    /** The markup of a `wp_navigation` post without one entry (a submenu goes with what it holds). */
    public static function removeLink(string $markup, array $path, bool $byPage, ?int $pageId): string
    {
        $blocks = self::parse($markup);
        return self::cut($markup, $blocks[self::locate(self::items($blocks), $path, $byPage, $pageId)]);
    }

    /** The markup of a template part without one entry of its `$navigation`-th inline navigation (no `ref`). */
    public static function removeInlineLink(string $markup, int $navigation, array $path, bool $byPage, ?int $pageId): string
    {
        $blocks = self::parse($markup);
        $inline = array_values(array_keys(array_filter($blocks, static fn(array $b): bool => $b['name'] === 'core/navigation' && !array_key_exists('ref', $b['attrs']))));
        if (!isset($inline[$navigation])) {
            throw new RuntimeException('inline navigation ' . $navigation . ' is not in the template part');
        }
        return self::cut($markup, $blocks[self::locate(self::items($blocks, $inline[$navigation]), $path, $byPage, $pageId)]);
    }

    /** The markup with the one navigation block that points at `$from` pointing at `$to`; nothing else moves. */
    public static function setNavigationRef(string $markup, int $from, int $to): string
    {
        $found = array_values(array_filter(self::parse($markup), static fn(array $b): bool => $b['name'] === 'core/navigation'
            && array_key_exists('ref', $b['attrs']) && self::number($b['attrs']['ref']) === (float) $from));
        if (count($found) !== 1) {
            throw new RuntimeException('navigation ref ' . $from . ' is found ' . count($found) . ' times');
        }
        $block = $found[0];
        $head = substr($markup, $block['start'], $block['end'] - $block['start']);
        $raw = self::trim((string) $block['rawAttrs']);
        $next = preg_replace('~("ref"' . self::S . '*:' . self::S . '*)' . $from . '(?=' . self::S . '*[,}])~u', '${1}' . $to, $raw, 1);
        if (!is_string($next) || $next === $raw) {
            throw new RuntimeException('navigation ref ' . $from . ' is not written as a number');
        }
        $at = strpos($head, $raw);
        $head = substr($head, 0, (int) $at) . $next . substr($head, (int) $at + strlen($raw));
        return substr($markup, 0, $block['start']) . $head . substr($markup, $block['end']);
    }

    /**
     * The markup without the one block `$want` matches: `name`, and each of `ref`, `anchor`,
     * `className` (one of its classes) and `contains` (a string inside its markup) when given.
     */
    public static function removeBlock(string $markup, array $want): string
    {
        $found = [];
        foreach (self::parse($markup) as $block) {
            if (self::matches($markup, $block, $want)) {
                $found[] = $block;
            }
        }
        if (count($found) !== 1) {
            throw new RuntimeException('block ' . json_encode($want, JSON_UNESCAPED_SLASHES) . ' is found ' . count($found) . ' times');
        }
        return self::cut($markup, $found[0]);
    }

    private static function matches(string $markup, array $block, array $want): bool
    {
        if ($block['name'] !== self::fullName((string) ($want['name'] ?? ''))) {
            return false;
        }
        if (array_key_exists('ref', $want) && self::number($block['attrs']['ref'] ?? null) !== self::number($want['ref'])) {
            return false;
        }
        if (array_key_exists('anchor', $want) && ($block['attrs']['anchor'] ?? null) !== $want['anchor']) {
            return false;
        }
        if (array_key_exists('className', $want)) {
            $classes = preg_split('~' . self::S . '+~u', self::text($block['attrs']['className'] ?? ''));
            if (!in_array($want['className'], is_array($classes) ? $classes : [], true)) {
                return false;
            }
        }
        if (array_key_exists('contains', $want) && strpos(substr($markup, $block['start'], $block['end'] - $block['start']), (string) $want['contains']) === false) {
            return false;
        }
        return true;
    }

    /** The one entry at `$path` (labels, top down) — linking `$pageId` when `$byPage` — or a refusal. */
    private static function locate(array $items, array $path, bool $byPage, ?int $pageId): int
    {
        $found = array_values(array_filter($items, static fn(array $i): bool => $i['path'] === $path && (!$byPage || $i['pageId'] === $pageId)));
        if (count($found) !== 1) {
            throw new RuntimeException('entry ' . implode(' › ', $path) . ' (page ' . ($pageId ?? 'null') . ') is found ' . count($found) . ' times');
        }
        return $found[0]['block'];
    }

    /** The markup without the span of one block, and the line break that followed it. */
    private static function cut(string $markup, array $block): string
    {
        return substr($markup, 0, $block['start']) . (string) preg_replace('~^\r?\n~', '', substr($markup, $block['end']));
    }

    private static function fullName(string $name): string
    {
        return strpos($name, '/') !== false ? $name : 'core/' . $name;
    }

    /** `String(x)` for what a label or a class name holds. */
    private static function text($value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        return is_scalar($value) ? (string) $value : '';
    }

    /** JavaScript's `trim()`. */
    private static function trim(string $text): string
    {
        return (string) preg_replace('~^' . self::S . '+|' . self::S . '+$~uD', '', $text);
    }

    /** `Number(x)` for what a `ref` holds; NaN (null here) for anything that is not a number. */
    private static function number($value): ?float
    {
        if ($value === null || $value === false) {
            return 0.0;
        }
        if ($value === true) {
            return 1.0;
        }
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_string($value)) {
            $value = self::trim($value);
            return $value === '' ? 0.0 : (is_numeric($value) ? (float) $value : null);
        }
        return null;
    }

    /** The generator's `pageIdOf`: an integer `id` attribute, else null. */
    private static function pageId(array $attrs): ?int
    {
        if (!array_key_exists('id', $attrs) || $attrs['id'] === '') {
            return null;
        }
        $number = self::number($attrs['id']);
        return $number !== null && floor($number) === $number && is_finite($number) ? (int) $number : null;
    }
}
