<?php
/** Scalar slots only: JSON leaves or text/URL/media nodes in existing HTML. */
final class ContentSlots
{
    public static function html(string $html): DOMDocument
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $old = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><html><body><div id="contract-root">' . $html . '</div></body></html>', LIBXML_NONET);
        libxml_clear_errors(); libxml_use_internal_errors($old);
        return $doc;
    }
    public static function htmlSlots(string $html): array
    {
        $doc = self::html($html); $xpath = new DOMXPath($doc); $out = [];
        foreach ($xpath->query('//*[@id="contract-root"]//text()[normalize-space(.) != ""] | //*[@id="contract-root"]//@href | //*[@id="contract-root"]//@src | //*[@id="contract-root"]//@alt | //*[@id="contract-root"]//@title') as $node) {
            $element = $node instanceof DOMAttr ? $node->ownerElement : $node->parentNode;
            if ($xpath->query('ancestor-or-self::script | ancestor-or-self::style | ancestor-or-self::svg | ancestor-or-self::code', $element)->length) continue;
            // Joomla content-plugin directives select modules/fields and are executable structure.
            if (preg_match('/\{\/?[a-z][^{}]*\}/i', $node->nodeValue)) continue;
            $out[] = ['xpath' => $node->getNodePath(), 'type' => $node->nodeName === 'src' ? 'image' : ($node->nodeName === 'href' ? 'url' : 'text'), 'sample' => $node->nodeValue];
        }
        return $out;
    }
    public static function htmlPatch(string $html, array $changes): string
    {
        $doc = self::html($html); $xp = new DOMXPath($doc);
        foreach ($changes as $change) {
            $nodes = $xp->query($change['xpath']);
            if ($nodes === false || $nodes->length !== 1) throw new RuntimeException('HTML slot is missing or ambiguous');
            $node = $nodes->item(0);
            if (!($node instanceof DOMText) && !($node instanceof DOMAttr)) throw new RuntimeException('HTML slot is not a scalar');
            $node->nodeValue = $change['value'];
        }
        $root = $xp->query('//*[@id="contract-root"]')->item(0); $out = '';
        foreach ($root->childNodes as $child) $out .= $doc->saveHTML($child);
        return $out;
    }
    public static function jsonPatch(array $data, array $path, string $value): array
    {
        $node =& $data;
        foreach ($path as $key) {
            if (!is_array($node) || !array_key_exists($key, $node)) throw new RuntimeException('JSON slot is missing');
            $node =& $node[$key];
        }
        if (!is_string($node)) throw new RuntimeException('JSON slot is not text');
        $node = $value; return $data;
    }
}
