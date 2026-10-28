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
    /**
     * The elements Tracy's Joomla capture takes as one piece of text with `--whole-units`
     * (capture-contract.py, UNIT_TAGS): a heading, a sentence, a list item, a caption, a cell, a button.
     */
    private const UNIT_TAGS = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'li', 'dt', 'dd', 'figcaption', 'summary', 'th', 'td', 'button', 'label'];
    /** Python's `\s` on text, which the capture's sample collapses: ASCII whitespace, \x1c-\x1f, NEL, NBSP and the Unicode spaces. */
    private const CAPTURE_SPACE = '/[\x{9}-\x{D}\x{1C}-\x{20}\x{85}\x{A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]+/u';

    /**
     * What a slot's node holds, as the content map's sample spells it.
     *
     * A text node or attribute is its value, exactly as before. An element is a whole unit: capture
     * cut it into the text slots htmlSlots() lists, then merged those into one slot on the element,
     * its sample the pieces joined with one space, every whitespace run collapsed to one space,
     * trimmed. The DOM's textContent is not that: `THE <br> WORKOUT` is `THE  WORKOUT` there and
     * `THE WORKOUT` in the map, so an untouched slot read as changed by the owner.
     */
    public static function slotText(DOMNode $node): string
    {
        if (!($node instanceof DOMElement)) return (string) $node->nodeValue;
        $pieces = array_map(static fn(DOMText $text): string => $text->data, self::unitPieces(new DOMXPath($node->ownerDocument), $node));
        $joined = implode(' ', $pieces);
        return trim(preg_replace(self::CAPTURE_SPACE, ' ', $joined) ?? $joined, ' ');
    }

    /**
     * The text nodes capture merged into the unit `$unit`, in document order: the ones htmlSlots()
     * lists (not blank, not inside script/style/svg/code, no Joomla directive) whose nearest unit
     * element is `$unit` itself. Text inside a nested unit (a `<p>` in an `<li>`) is that unit's own.
     * The root (`/html/body/div`) is a unit only when it holds no unit element, as capture decides.
     *
     * @return list<DOMText>
     */
    private static function unitPieces(DOMXPath $xp, DOMElement $unit): array
    {
        $out = [];
        foreach ($xp->query('.//text()[normalize-space(.) != ""]', $unit) as $text) {
            if ($xp->query('ancestor::script | ancestor::style | ancestor::svg | ancestor::code', $text)->length) continue;
            if (IdentityTokens::hasDirective($text->nodeValue)) continue;
            if (self::nearestUnitBelow($text, $unit)) continue;
            $out[] = $text;
        }
        return $out;
    }

    /** Whether a unit element sits between `$node` and `$unit` (both excluded). */
    private static function nearestUnitBelow(DOMNode $node, DOMElement $unit): bool
    {
        for ($up = $node->parentNode; $up !== null && !$up->isSameNode($unit); $up = $up->parentNode)
            if ($up instanceof DOMElement && in_array(strtolower($up->nodeName), self::UNIT_TAGS, true)) return true;
        return false;
    }

    /**
     * Write a whole-unit slot: the unit's visible text becomes `$value`, one text node, and the element
     * keeps its name and attributes.
     *
     * Inline markup inside the unit's text goes with that text. A whole-unit slot is the whole heading
     * or sentence (TracyHQ/tracy#37, C1: "a title is never split into words that the build cannot
     * rewrite as a whole"), and new words cannot be mapped back onto the demo's `<span>`, `<strong>`
     * or `<br>`: the new text replaces the first piece, every other piece is removed, every `<br>` of
     * the unit is removed, and an element left empty by that is removed with it (so `NO1 <span>boxing</span>`
     * leaves no empty span). The leading space of the first piece and the trailing space of the last
     * are kept, so an icon or a word beside the unit keeps its gap.
     *
     * What is NOT the unit's text stays, because removing it would break another slot or the page:
     * an element that never held a piece (an icon `<i>`, an `<img>`, an `<svg>`), a nested unit and its
     * text, script/style/code, a Joomla directive, and an element carrying href/src/alt/title, which
     * htmlSlots() addresses as a slot of its own. So `Call <a href="tel:...">us</a> today` becomes
     * `New text<a href="tel:..."></a>`: the link's url slot still resolves, its label is gone with
     * the rest of the old sentence.
     */
    private static function writeUnit(DOMXPath $xp, DOMElement $unit, string $value): void
    {
        $doc = $unit->ownerDocument; $pieces = self::unitPieces($xp, $unit);
        if ($pieces === []) { $unit->appendChild($doc->createTextNode($value)); return; }
        $first = $pieces[0]; $last = $pieces[count($pieces) - 1];
        preg_match('/^\s*/u', $first->data, $lead); preg_match('/\s*$/u', $last->data, $trail);
        // The new text goes directly in the unit, where the child holding the first piece starts.
        $anchor = $first; while (!$anchor->parentNode->isSameNode($unit)) $anchor = $anchor->parentNode;
        $unit->insertBefore($doc->createTextNode($lead[0] . $value . $trail[0]), $anchor);
        $held = [];
        foreach ($pieces as $piece) {
            for ($up = $piece->parentNode; !$up->isSameNode($unit); $up = $up->parentNode) $held[] = $up;
            $piece->parentNode->removeChild($piece);
        }
        foreach ($xp->query('.//br', $unit) as $br) if (!self::nearestUnitBelow($br, $unit)) $br->parentNode->removeChild($br);
        // Deepest first, so an emptied <em> inside an emptied <strong> lets the <strong> go too.
        usort($held, static fn(DOMNode $a, DOMNode $b): int => substr_count($b->getNodePath(), '/') <=> substr_count($a->getNodePath(), '/'));
        foreach ($held as $element) {
            if ($element->parentNode === null) continue;
            foreach (['href', 'src', 'alt', 'title'] as $slotAttr) if ($element->hasAttribute($slotAttr)) continue 2;
            if (trim($element->textContent) === '' && !$xp->query('.//*', $element)->length) $element->parentNode->removeChild($element);
        }
    }

    public static function htmlPatch(string $html, array $changes): string
    {
        $doc = self::html($html); $xp = new DOMXPath($doc);
        foreach ($changes as $change) {
            $nodes = $xp->query($change['xpath']);
            if ($nodes === false || $nodes->length !== 1) throw new RuntimeException('HTML slot is missing or ambiguous');
            $node = $nodes->item(0);
            if ($node instanceof DOMElement) { self::writeUnit($xp, $node, (string) $change['value']); continue; }
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
