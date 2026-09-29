<?php
/**
 * VisibleText — what a visitor reads on rendered pages, and whether a stored leaf is part of it.
 *
 * A derived contract keeps a nested leaf (a builder setting, a theme option) only when its words or its
 * address show on a page of the site: the rendered page is the truth, whatever wrote the value.
 * Plain PHP, no CMS. Identical in the Joomla and WordPress engines.
 */
declare(strict_types=1);

final class VisibleText
{
    /** Block elements a page's readable text is split by, for VisibleText::unmatched(). */
    private const BLOCK_TAGS = 'p|h[1-6]|li|td|div|a|button';

    /** @param list<string> $pages @return array{text:string,paths:array<string,true>} */
    public static function fromPages(array $pages): array
    {
        $text = [];
        $paths = [];
        foreach ($pages as $html) {
            $body = preg_replace('~<(script|style|noscript|template|head)\b.*?</\1>~is', ' ', $html) ?? '';
            preg_match_all('~\s(?:href|src|data-src|srcset|poster)\s*=\s*["\']([^"\']+)["\']~i', $body, $m);
            foreach ($m[1] as $url) foreach (preg_split('/\s*,\s*/', $url) as $one) {
                $path = self::pathOf(preg_replace('/\s+\d+[wx]$/', '', trim($one)) ?? '');
                if ($path !== '') $paths[$path] = true;
            }
            preg_match_all('~\s(?:alt|title|placeholder|aria-label)\s*=\s*["\']([^"\']+)["\']~i', $body, $a);
            $text[] = implode(' ', $a[1]);
            $text[] = strip_tags(str_replace('<', ' <', $body));
        }
        return ['text' => self::normal(html_entity_decode(implode(' ', $text), ENT_QUOTES | ENT_HTML5, 'UTF-8')), 'paths' => $paths];
    }

    /** @param array{text:string,paths:array<string,true>} $seen @param array{type:string,text:string} $leaf */
    public static function shows(array $seen, array $leaf): bool
    {
        if ($leaf['type'] === 'url' || $leaf['type'] === 'image') {
            $path = self::pathOf($leaf['text']);
            return $path !== '' && isset($seen['paths'][$path]);
        }
        $want = self::normal(strip_tags($leaf['text']));
        return $want !== '' && strpos($seen['text'], $want) !== false;
    }

    public static function normal(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }

    /**
     * How many visible text blocks on the pages (split by block elements: p, h1-h6, li, td, div, a, button)
     * have normalised text that is not contained in any of the given leaf texts. Blocks shorter than 3
     * characters are ignored (too short to carry a leaf's identity, e.g. a lone icon glyph).
     *
     * @param list<string> $pages
     * @param list<string> $leafTexts
     */
    public static function unmatched(array $pages, array $leafTexts): int
    {
        $known = [];
        foreach ($leafTexts as $leafText) {
            $normal = self::normal(strip_tags((string) $leafText));
            if ($normal !== '') $known[] = $normal;
        }
        $count = 0;
        foreach ($pages as $html) {
            $body = preg_replace('~<(script|style|noscript|template|head)\b.*?</\1>~is', ' ', $html) ?? '';
            // A block that wraps other block tags (a div around two p's) is not itself a leaf: only the run of
            // text between one block-tag boundary (open or close) and the next is one block, so a wrapping
            // element's concatenated text is never checked and its children are never double counted.
            preg_match_all('~<(/?)(?:' . self::BLOCK_TAGS . ')\b[^>]*>~is', $body, $tags, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
            $total = count($tags);
            for ($i = 0; $i < $total; $i++) {
                if ($tags[$i][1][0] === '/') continue;
                [$whole, $at] = $tags[$i][0];
                $start = $at + strlen($whole);
                $end = $i + 1 < $total ? $tags[$i + 1][0][1] : strlen($body);
                $inner = substr($body, $start, $end - $start);
                $blockText = self::normal(html_entity_decode(strip_tags($inner), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                if (mb_strlen($blockText) < 3) continue;
                $matched = false;
                foreach ($known as $leafText) {
                    if (strpos($leafText, $blockText) !== false) { $matched = true; break; }
                }
                if (!$matched) $count++;
            }
        }
        return $count;
    }

    /** A URL reduced to its path and query, so the copy's host and the customer's host compare equal. */
    private static function pathOf(string $url): string
    {
        if (preg_match('~^(mailto|tel):~i', $url)) return strtolower($url);
        $parts = parse_url($url);
        if ($parts === false) return '';
        $path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
        return rtrim($path, '/') ?: '/';
    }
}
