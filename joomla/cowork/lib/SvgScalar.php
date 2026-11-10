<?php
require_once __DIR__ . '/LeafCodec.php';

/** Existing SVG root aria-label only; source geometry is never DOM-serialized. */
final class SvgScalar
{
    public static function eligible(DOMNode $node): bool
    {
        if (!($node instanceof DOMAttr) || $node->nodeName !== 'aria-label' || $node->ownerElement->tagName !== 'svg') return false;
        return !(new DOMXPath($node->ownerDocument))->query('ancestor::svg | ancestor::script | ancestor::style | ancestor::code', $node->ownerElement)->length;
    }

    /** Reuse LeafCodec's quote-aware tokenizer; balance/protect SVG without demanding XML eligibility. */
    private static function fragments(string $html): array
    {
        $fragments = []; $depth = 0; $start = null; $rawUntil = null;
        foreach (LeafCodec::htmlTags($html) as $tag) {
            [$whole, $at] = $tag[0];
            if (str_starts_with($whole, '<!--')) continue;
            $closing = ($tag[1][0] ?? '') === '/'; $name = strtolower($tag[2][0]);
            if ($rawUntil !== null) {
                if ($closing && $name === $rawUntil) $rawUntil = null;
                continue;
            }
            if (!$closing && in_array($name, ['script', 'style', 'textarea', 'noscript', 'template'], true)) $rawUntil = $name;
            if ($name !== 'svg') continue;
            if (!$closing) {
                if ($depth++ === 0) $start = $at;
                if (!preg_match('~/\s*>$~', $whole)) continue;
            }
            if ($depth === 0) throw new RuntimeException('SVG closing tag has no opening tag');
            if (--$depth !== 0) continue;
            $fragments[] = ['start' => $start, 'raw' => substr($html, $start, $at + strlen($whole) - $start)];
        }
        if ($depth !== 0) throw new RuntimeException('SVG fragment is unclosed');
        if ($fragments === []) return [];
        do $stem = 'svgscalar' . bin2hex(random_bytes(12)); while (str_contains($html, $stem));
        $tokens = [];
        foreach ($fragments as $i => &$fragment) {
            $token = $stem . 'x' . $i; $tokens[$token] = $i;
            $fragment['placeholder'] = '<svg data-tracy-svg-scalar="' . $token . '"></svg>';
        }
        unset($fragment);
        // Batch all sentinels into one probe, rather than parsing the full field once per icon.
        $probe = self::replace($html, $fragments, true);
        $xp = new DOMXPath(ContentSlots::html($probe)); $out = []; $seen = [];
        foreach ($xp->query('//svg[@data-tracy-svg-scalar]') as $node) {
            $token = $node->getAttribute('data-tracy-svg-scalar');
            if (!isset($tokens[$token])) continue;
            $path = $node->getNodePath();
            if (isset($out[$path]) || isset($seen[$token])) throw new RuntimeException('SVG raw receiver is ambiguous');
            $out[$path] = $fragments[$tokens[$token]]; $seen[$token] = true;
        }
        if (count($seen) !== count($fragments)) throw new RuntimeException('SVG raw fragment has no unique DOM receiver');
        uasort($out, static fn(array $a, array $b): int => $a['start'] <=> $b['start']);
        return $out;
    }

    /** One linear byte splice for both the batch probe and the actual writer. */
    private static function replace(string $html, array $fragments, bool $shield): string
    {
        $parts = []; $cursor = 0;
        foreach ($fragments as $fragment) {
            $parts[] = substr($html, $cursor, $fragment['start'] - $cursor);
            $parts[] = $shield ? $fragment['placeholder'] : ($fragment['next'] ?? $fragment['raw']);
            $cursor = $fragment['start'] + strlen($fragment['raw']);
        }
        $parts[] = substr($html, $cursor);
        return implode('', $parts);
    }

    /** XML validation is required only for a new editable scalar, never for raw shielding. */
    private static function editable(string $raw): bool
    {
        if (LeafCodec::htmlAttributeSpan($raw, 'a0.aria-label') === null || stripos($raw, '<!DOCTYPE') !== false) return false;
        $xml = new DOMDocument(); $old = libxml_use_internal_errors(true); libxml_clear_errors();
        $valid = $xml->loadXML($raw, LIBXML_NONET);
        foreach (libxml_get_errors() as $error) if ($error->level >= LIBXML_ERR_ERROR) $valid = false;
        libxml_clear_errors(); libxml_use_internal_errors($old);
        return $valid && $xml->documentElement->nodeName === 'svg';
    }

    /** An unbounded fragment refuses capture explicitly; balanced ineligible labels remain unexposed. */
    public static function paths(string $html): array
    {
        $paths = [];
        foreach (self::fragments($html) as $path => $fragment)
            if (self::editable($fragment['raw'])) $paths[] = $path;
        return $paths;
    }

    private static function attribute(string $raw, string $value): string
    {
        $span = LeafCodec::htmlAttributeSpan($raw, 'a0.aria-label');
        if ($span === null) throw new RuntimeException('SVG aria-label is missing or ambiguous');
        if ($span['value'] === $value) return $raw;
        $encoded = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return substr_replace($raw, $encoded, $span['start'], $span['length']);
    }

    public static function patch(string $html, array $changes, callable $ordinary): string
    {
        $fragments = self::fragments($html);
        if ($fragments === []) return $ordinary($html, $changes);
        $xp = new DOMXPath(ContentSlots::html($html)); $rest = []; $svgChanges = [];
        foreach ($changes as $change) {
            $nodes = $xp->query($change['xpath']);
            if (!$nodes || $nodes->length !== 1) throw new RuntimeException('HTML slot is missing or ambiguous');
            $node = $nodes->item(0); $element = $node instanceof DOMAttr ? $node->ownerElement : $node;
            if (!$xp->query('ancestor-or-self::svg', $element)->length) { $rest[] = $change; continue; }
            if (!self::eligible($node)) throw new RuntimeException('Only an existing SVG root aria-label is writable');
            $path = $node->ownerElement->getNodePath();
            if (!isset($fragments[$path]) || !self::editable($fragments[$path]['raw'])) throw new RuntimeException('SVG scalar eligibility cannot be proven');
            $fragments[$path]['next'] = self::attribute($fragments[$path]['next'] ?? $fragments[$path]['raw'], (string) $change['value']);
            $svgChanges[$change['xpath']] = (string) $change['value'];
        }
        $out = self::replace($html, $fragments, $rest !== []);
        if ($rest !== []) {
            $out = $ordinary($out, $rest);
            preg_match_all('~<svg data-tracy-svg-scalar="[^"]+"></svg>~', $out, $matches);
            $counts = array_count_values($matches[0]); $restore = [];
            foreach ($fragments as $fragment) {
                if (($counts[$fragment['placeholder']] ?? 0) !== 1) throw new RuntimeException('Ordinary HTML write lost an SVG receiver');
                $restore[$fragment['placeholder']] = $fragment['next'] ?? $fragment['raw'];
            }
            $out = strtr($out, $restore);
        }
        $readback = new DOMXPath(ContentSlots::html($out));
        foreach ($svgChanges as $path => $value) {
            $nodes = $readback->query($path);
            if (!$nodes || $nodes->length !== 1 || !self::eligible($nodes->item(0)) || $nodes->item(0)->nodeValue !== $value)
                throw new RuntimeException('SVG scalar did not read back exactly');
        }
        return $out;
    }
}
