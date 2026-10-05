<?php
require_once __DIR__ . '/IdentityTokens.php';
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
            if (IdentityTokens::hasDirective($node->nodeValue)) continue;
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
            $value = $change['value'];
            // An attribute's value set from PHP is read as markup: `&` starts an entity reference, and
            // a bare one (`?a=1&b=2`, "Sales & Marketing") emptied the attribute on libxml 2.9 and was
            // dropped on 2.13. So every `&` that does not begin a complete reference is written as
            // `&amp;`, and reads back as the `&` that was written. A complete reference (`&amp;`,
            // `&#38;`, `&copy;`) is decoded as before, so a value that already spells `&amp;` is not
            // escaped twice; a name with no `;` (`&copy=2`, `&lang=en`) is text.
            if ($node instanceof DOMAttr) $value = preg_replace('/&(?!(?:[A-Za-z][A-Za-z0-9]*|#[0-9]+|#x[0-9A-Fa-f]+);)/', '&amp;', (string) $value);
            $node->nodeValue = $value;
        }
        $seen = $html; foreach ($changes as $change) $seen .= "\n" . $change['value'];
        $restore = self::shieldTokens($xp, $seen);
        $root = $xp->query('//*[@id="contract-root"]')->item(0); $out = '';
        foreach ($root->childNodes as $child) $out .= $doc->saveHTML($child);
        return $restore === [] ? $out : strtr($out, $restore);
    }
    /**
     * libxml 2.9 (the Joomla site image, php:8.3-cli) percent-encodes `{` and `}` in every href, src
     * and action it saves, so re-saving a row turned `href="tel:{contact.tel}"` into
     * `href="tel:%7Bcontact.tel%7D"` — on links the write never touched — and plg_system_tracyidentity
     * no longer filled them; libxml 2.13 leaves braces alone. So before saving, every identity token
     * an attribute holds RAW is swapped for a letters-and-digits stand-in that no libxml escapes, and
     * the returned map puts the raw token back into the saved text. Only the closed list
     * IdentityTokens::NAMES, and only where the attribute holds the token raw: a `%7B…%7D` the author
     * wrote encoded is not a raw token in the DOM, so it is saved encoded, as before.
     *
     * @param string $seen everything the row and the write hold, so a stand-in cannot already occur there
     * @return array<string,string> stand-in => raw token; empty when no attribute holds a token
     */
    private static function shieldTokens(DOMXPath $xp, string $seen): array
    {
        do $stem = 'tracyidtok' . bin2hex(random_bytes(8)); while (strpos($seen, $stem) !== false);
        $shield = [];
        foreach (IdentityTokens::NAMES as $i => $name) $shield['{' . $name . '}'] = $stem . $i . 'x';
        $shielded = false;
        foreach ($xp->query('//*[@id="contract-root"]//@*') as $attr) {
            if (strpos($attr->value, '{') === false) continue;
            // The attribute's text children are rewritten, not its value: a text node's content is
            // taken literally, while an attribute value set from PHP reads `&` as an entity reference.
            foreach ($attr->childNodes as $text) {
                if (!($text instanceof DOMText)) continue;
                $next = strtr($text->data, $shield);
                if ($next === $text->data) continue;
                $text->data = $next; $shielded = true;
            }
        }
        return $shielded ? array_flip($shield) : [];
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
