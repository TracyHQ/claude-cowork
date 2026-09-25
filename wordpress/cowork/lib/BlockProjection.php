<?php
/**
 * BlockProjection — what a saved block tree says, read without rendering it.
 *
 * Content API v1 (`/content.json`) describes a page as occurrences of blocks. WordPress stores a
 * page as one string of block markup; `parse_blocks()` turns it into a tree, and this class turns
 * that tree into the parts the reader publishes. It never renders: no render callback, no
 * shortcode, no `the_content` filter runs, so a plugin's directive in a post cannot execute on a
 * read. What a dynamic block would print is therefore NOT here — the reader says so.
 *
 * Identity comes from what the markup names, never from position. A Tracy section names its
 * words with `metadata.name` (`hero.eyebrow`, `hero.item.1.title`), the same names the content
 * contract's slots use; a top-level block holding such names is a SECTION keyed by the first
 * segment they share. A block that names nothing has no identity that survives a reorder, so it
 * is counted as unkeyed and left to `bodyHtml` rather than given an index as an id.
 *
 * Plain PHP, no WordPress: the input is the array `parse_blocks()` returns.
 */
final class BlockProjection
{
    /** Blocks whose whole meaning is a pointer to content stored elsewhere. */
    private const SHARED = ['core/block', 'core/template-part', 'core/navigation'];

    /**
     * @param array<int,array<string,mixed>> $blocks what parse_blocks() returned
     * @return array{entries:array<int,array<string,mixed>>,unkeyed:int,bodyHtml:string}
     *   entries in document order: `section` {key, names[], fields[], items[], images[]} or
     *   `shared` {key, ref{type, id?, slug?, theme?}}
     */
    public static function project(array $blocks): array
    {
        $entries = [];
        $unkeyed = 0;
        foreach ($blocks as $block) {
            if (!is_array($block) || !is_string($block['blockName'] ?? null)) {
                continue; // freeform whitespace between blocks
            }
            $shared = self::sharedRef($block);
            if ($shared !== null) {
                $entries[] = $shared;
                continue;
            }
            $names = self::names($block);
            if ($names === []) {
                if (self::hasContent($block)) {
                    $unkeyed++;
                }
                foreach (self::nestedShared($block) as $ref) {
                    $entries[] = $ref;
                }
                continue;
            }
            $entries[] = self::section($block, $names);
            foreach (self::nestedShared($block) as $ref) {
                $entries[] = $ref;
            }
        }
        return ['entries' => $entries, 'unkeyed' => $unkeyed, 'bodyHtml' => self::staticHtml($blocks)];
    }

    /**
     * The links of a navigation post, in order. `key` is local (it holds the target's native id)
     * and must be hashed by the caller before it leaves the site.
     *
     * @return array<int,array{key:string,label:?string,url:?string,targetId:?int,targetType:?string}>
     */
    public static function navigationItems(array $blocks): array
    {
        $out = [];
        $seen = [];
        $walk = static function (array $list) use (&$walk, &$out, &$seen): void {
            foreach ($list as $block) {
                if (!is_array($block)) {
                    continue;
                }
                $name = $block['blockName'] ?? null;
                if ($name === 'core/navigation-link' || $name === 'core/navigation-submenu') {
                    $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
                    $id = isset($attrs['id']) && is_numeric($attrs['id']) ? (int) $attrs['id'] : null;
                    $url = isset($attrs['url']) && is_string($attrs['url']) && $attrs['url'] !== '' ? $attrs['url'] : null;
                    $type = isset($attrs['type']) && is_string($attrs['type']) ? $attrs['type'] : null;
                    // Two links to the same target are told apart by how many came before them,
                    // which a reorder of OTHER links does not change.
                    $base = $id !== null ? 'post:' . $id : 'url:' . (string) $url . '|' . (string) ($attrs['label'] ?? '');
                    $seen[$base] = ($seen[$base] ?? 0) + 1;
                    $out[] = [
                        'key' => $base . '#' . $seen[$base],
                        'label' => isset($attrs['label']) && is_string($attrs['label']) ? $attrs['label'] : null,
                        'url' => $url,
                        'targetId' => $id,
                        'targetType' => $type,
                    ];
                }
                if (!empty($block['innerBlocks']) && is_array($block['innerBlocks'])) {
                    $walk($block['innerBlocks']);
                }
            }
        };
        $walk($blocks);
        return $out;
    }

    /**
     * The HTML a block tree saved, with every block comment dropped and nothing executed: the
     * static half of the page. A dynamic block contributes nothing, because its HTML only exists
     * once a render callback runs.
     */
    public static function staticHtml(array $blocks): string
    {
        $html = '';
        foreach ($blocks as $block) {
            if (is_array($block)) {
                $html .= self::blockHtml($block);
            }
        }
        return trim($html);
    }

    private static function blockHtml(array $block): string
    {
        $inner = is_array($block['innerBlocks'] ?? null) ? $block['innerBlocks'] : [];
        $content = is_array($block['innerContent'] ?? null) ? $block['innerContent'] : [(string) ($block['innerHTML'] ?? '')];
        $html = '';
        $next = 0;
        foreach ($content as $chunk) {
            if (is_string($chunk)) {
                $html .= $chunk;
            } elseif (isset($inner[$next]) && is_array($inner[$next])) {
                $html .= self::blockHtml($inner[$next]);
                $next++;
            }
        }
        return $html;
    }

    /** @return array<string,mixed>|null a shared-content pointer, or null when the block is not one */
    private static function sharedRef(array $block): ?array
    {
        $type = (string) $block['blockName'];
        if (!in_array($type, self::SHARED, true)) {
            return null;
        }
        $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
        $name = self::nameOf($block);
        if ($type === 'core/template-part') {
            $slug = isset($attrs['slug']) && is_string($attrs['slug']) ? $attrs['slug'] : '';
            if ($slug === '') {
                return null;
            }
            $theme = isset($attrs['theme']) && is_string($attrs['theme']) ? $attrs['theme'] : null;
            return ['kind' => 'shared', 'key' => $name ?? 'part.' . $slug, 'ref' => ['type' => 'template-part', 'slug' => $slug, 'theme' => $theme]];
        }
        if (!isset($attrs['ref']) || !is_numeric($attrs['ref'])) {
            // A navigation with no ref lists its links inline (or the page list); it is not shared.
            return null;
        }
        return [
            'kind' => 'shared',
            'key' => $name,
            'ref' => ['type' => $type === 'core/block' ? 'wp_block' : 'navigation', 'id' => (int) $attrs['ref']],
        ];
    }

    /** @return array<int,array<string,mixed>> shared pointers nested anywhere under a block */
    private static function nestedShared(array $block): array
    {
        $out = [];
        foreach ((array) ($block['innerBlocks'] ?? []) as $child) {
            if (!is_array($child) || !is_string($child['blockName'] ?? null)) {
                continue;
            }
            $ref = self::sharedRef($child);
            if ($ref !== null) {
                $out[] = $ref;
                continue;
            }
            foreach (self::nestedShared($child) as $nested) {
                $out[] = $nested;
            }
        }
        return $out;
    }

    private static function nameOf(array $block): ?string
    {
        $name = $block['attrs']['metadata']['name'] ?? null;
        return is_string($name) && $name !== '' ? $name : null;
    }

    /** @return string[] every metadata.name in a subtree, shared pointers excluded */
    private static function names(array $block): array
    {
        $out = [];
        $name = self::nameOf($block);
        if ($name !== null && self::sharedRef($block) === null) {
            $out[] = $name;
        }
        foreach ((array) ($block['innerBlocks'] ?? []) as $child) {
            if (is_array($child) && is_string($child['blockName'] ?? null) && self::sharedRef($child) === null) {
                foreach (self::names($child) as $n) {
                    $out[] = $n;
                }
            }
        }
        return $out;
    }

    private static function hasContent(array $block): bool
    {
        return trim(strip_tags(self::blockHtml($block))) !== '' || strpos(self::blockHtml($block), '<img') !== false;
    }

    /** @param string[] $names */
    private static function section(array $block, array $names): array
    {
        $segments = [];
        foreach ($names as $name) {
            $segments[explode('.', $name, 2)[0]] = true;
        }
        $segments = array_keys($segments);
        sort($segments);
        $section = ['kind' => 'section', 'key' => implode('+', $segments), 'names' => $names, 'fields' => [], 'items' => [], 'images' => []];
        self::collect($block, $section, null);
        return $section;
    }

    /**
     * Walk one section. A named block with named descendants is an ITEM (a card, a stat); a named
     * block without is a FIELD of the nearest item, else of the section.
     */
    private static function collect(array $block, array &$section, ?int $item): void
    {
        if (self::sharedRef($block) !== null) {
            return;
        }
        $name = self::nameOf($block);
        $children = [];
        foreach ((array) ($block['innerBlocks'] ?? []) as $child) {
            if (is_array($child) && is_string($child['blockName'] ?? null)) {
                $children[] = $child;
            }
        }
        $namedBelow = false;
        foreach ($children as $child) {
            if (self::names($child) !== []) {
                $namedBelow = true;
                break;
            }
        }
        if ($name !== null && $namedBelow) {
            $section['items'][] = ['key' => $name, 'fields' => []];
            $item = count($section['items']) - 1;
        } elseif ($name !== null) {
            foreach (self::fieldsOf($block, $name) as $field) {
                if ($item === null) {
                    $section['fields'][] = $field;
                } else {
                    $section['items'][$item]['fields'][] = $field;
                }
            }
            $image = self::imageOf($block);
            if ($image !== null) {
                $image['itemKey'] = $item === null ? null : $section['items'][$item]['key'];
                $image['fieldKey'] = $name;
                $section['images'][] = $image;
            }
            return;
        }
        foreach ($children as $child) {
            self::collect($child, $section, $item);
        }
    }

    /** @return array<int,array{key:string,type:string,value:?string}> */
    private static function fieldsOf(array $block, string $name): array
    {
        $type = (string) $block['blockName'];
        $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
        $inner = (string) ($block['innerHTML'] ?? '');
        if ($type === 'core/image') {
            $image = self::imageOf($block);
            return [['key' => $name, 'type' => 'image', 'value' => $image['src'] ?? null]];
        }
        if ($type === 'core/button') {
            $text = preg_match('/<a\b[^>]*>([\s\S]*?)<\/a>/', $inner, $m) ? $m[1] : null;
            $url = isset($attrs['url']) && is_string($attrs['url']) ? $attrs['url']
                : (preg_match('/<a\b[^>]*\bhref="([^"]*)"/', $inner, $h) ? html_entity_decode($h[1], ENT_QUOTES | ENT_HTML5, 'UTF-8') : null);
            return [self::textField($name, $text), ['key' => $name . ':url', 'type' => 'url', 'value' => $url]];
        }
        if (preg_match('/^\s*<([a-z0-9]+)\b[^>]*>([\s\S]*?)<\/\1>\s*$/D', $inner, $m) && empty($block['innerBlocks'])) {
            return [self::textField($name, $m[2])];
        }
        $html = trim(self::blockHtml($block));
        return [['key' => $name, 'type' => 'html', 'value' => $html === '' ? null : $html]];
    }

    /** Text when the element holds only text; its inner HTML, untouched, when it holds markup. */
    private static function textField(string $key, ?string $inner): array
    {
        if ($inner === null) {
            return ['key' => $key, 'type' => 'text', 'value' => null];
        }
        if (preg_match('/<[a-zA-Z!\/]/', $inner)) {
            return ['key' => $key, 'type' => 'html', 'value' => $inner];
        }
        return ['key' => $key, 'type' => 'text', 'value' => html_entity_decode($inner, ENT_QUOTES | ENT_HTML5, 'UTF-8')];
    }

    /** @return array{attachment:?int,src:?string,alt:?string}|null */
    private static function imageOf(array $block): ?array
    {
        if (($block['blockName'] ?? '') !== 'core/image') {
            return null;
        }
        $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
        $inner = (string) ($block['innerHTML'] ?? '');
        $src = preg_match('/<img\b[^>]*\bsrc="([^"]*)"/', $inner, $m) ? html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8') : null;
        $alt = preg_match('/<img\b[^>]*\balt="([^"]*)"/', $inner, $a) ? html_entity_decode($a[1], ENT_QUOTES | ENT_HTML5, 'UTF-8') : null;
        return [
            'attachment' => isset($attrs['id']) && is_numeric($attrs['id']) ? (int) $attrs['id'] : null,
            'src' => $src === '' ? null : $src,
            'alt' => $alt,
        ];
    }
}
