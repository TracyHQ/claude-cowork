<?php
require_once __DIR__ . '/IdentityTokens.php';
require_once __DIR__ . '/SvgScalar.php';
/** Scalar slots only: JSON leaves or text/URL/media nodes in existing HTML. */
final class ContentSlots
{
    public static function html(string $html): DOMDocument
    {
        $html = self::preservePreComparisons($html);
        $doc = new DOMDocument('1.0', 'UTF-8');
        $old = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><html><body><div id="contract-root">' . $html . '</div></body></html>', LIBXML_NONET);
        libxml_clear_errors(); libxml_use_internal_errors($old);
        return $doc;
    }

    /** libxml's HTML4 parser drops non-tag comparisons inside otherwise literal CODE. */
    private static function preservePreComparisons(string $html): string
    {
        $tags = LeafCodec::htmlTags($html); $blocked = []; $edits = []; $end = 0;
        $raw = ['script', 'style', 'textarea', 'noscript', 'template', 'title', 'xmp', 'iframe', 'noembed', 'noframes', 'plaintext'];
        for ($i = 0; $i < count($tags); $i++) {
            $tag = $tags[$i]; $name = strtolower($tag[2][0] ?? ''); $close = ($tag[1][0] ?? '') === '/';
            $gap = substr($html, $end, $tag[0][1] - $end);
            $end = $tag[0][1] + strlen($tag[0][0]);
            if ($blocked && in_array(end($blocked), $raw, true)) {
                if ($close && $name === end($blocked) && $name !== 'plaintext') array_pop($blocked);
                continue;
            }
            // shortcut: ambiguous lexical gaps keep the old parse; broaden only with a proved source case.
            if (strpos($gap, '<') !== false) return $html;
            if ($name === '') continue; // The tokenizer consumes comments including their apparent tags.
            if (!$blocked && !$close && $name === 'pre' && isset($tags[$i + 3])) {
                [$code, $codeEnd, $preEnd] = array_slice($tags, $i + 1, 3);
                $bodyStart = $code[0][1] + strlen($code[0][0]);
                $body = substr($html, $bodyStart, $codeEnd[0][1] - $bodyStart);
                if ($code[0][1] === $end && ($code[1][0] ?? '') === '' && strtolower($code[2][0] ?? '') === 'code'
                    && !preg_match('~/\s*>$~', $tag[0][0]) && !preg_match('~/\s*>$~', $code[0][0])
                    && preg_match('~^</code\s*>$~iD', $codeEnd[0][0]) && preg_match('~^</pre\s*>$~iD', $preEnd[0][0])
                    && $preEnd[0][1] === $codeEnd[0][1] + strlen($codeEnd[0][0])
                    && !preg_match('/<(?![=\x09-\x0D ])/D', $body)) {
                    if (!IdentityTokens::hasDirective(html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8')) && strpos($body, '<') !== false)
                        $edits[] = [$bodyStart, strlen($body), str_replace('<', '&lt;', $body)];
                    $i += 3; $end = $preEnd[0][1] + strlen($preEnd[0][0]);
                    continue;
                }
            }
            if (!$close && (in_array($name, $raw, true) || in_array($name, ['pre', 'code', 'svg'], true))) $blocked[] = $name;
            elseif ($close && $blocked && $name === end($blocked)) array_pop($blocked);
        }
        if (strpos(substr($html, $end), '<') !== false) return $html;
        foreach (array_reverse($edits) as [$offset, $length, $value]) $html = substr_replace($html, $value, $offset, $length);
        return $html;
    }
    /** A map address is only a candidate; every read/write also checks the current DOM. */
    public static function literalPreSlot(array $slot): bool
    {
        return ($slot['type'] ?? null) === 'text' && !isset($slot['leaf']) && is_string($slot['xpath'] ?? null)
            && preg_match('~^/html/body/div(?:/[a-z][a-z0-9-]*(?:\[[1-9][0-9]*\])?)*/pre(?:\[[1-9][0-9]*\])?/code(?:\[[1-9][0-9]*\])?/text\(\)(?:\[1\])?$~D', $slot['xpath']) === 1;
    }

    /** Only one literal DOMText in one CODE child of PRE; no highlight or sibling nodes. */
    public static function literalPreNode(DOMNode $node): bool
    {
        $code = $node->parentNode; $pre = $code ? $code->parentNode : null;
        if ($node->nodeType !== XML_TEXT_NODE || !($code instanceof DOMElement) || $code->tagName !== 'code'
            || !($pre instanceof DOMElement) || $pre->tagName !== 'pre'
            || $code->childNodes->length !== 1 || $pre->childNodes->length !== 1) return false;
        return !(new DOMXPath($node->ownerDocument))->query('ancestor::script | ancestor::style | ancestor::svg | ancestor::pre | ancestor::code', $pre)->length;
    }

    /** Reject stale or forged code addresses even when the requested value contains no markup. */
    public static function checkCodeNode(DOMNode $node, array $slot): void
    {
        $element = $node instanceof DOMAttr ? $node->ownerElement : $node;
        $inside = (new DOMXPath($node->ownerDocument))->query('ancestor-or-self::code', $element)->length;
        if ((self::literalPreSlot($slot) || $inside) && (!self::literalPreSlot($slot) || !self::literalPreNode($node)))
            throw new RuntimeException('HTML code slot is not one literal PRE/CODE text leaf');
    }

    public static function htmlSlots(string $html): array
    {
        $doc = self::html($html); $xpath = new DOMXPath($doc); $out = [];
        $inert = self::inertPreSources($html, $xpath, $html);
        $svgPaths = SvgScalar::paths($html);
        foreach ($xpath->query('//*[@id="contract-root"]//text()[normalize-space(.) != ""] | //*[@id="contract-root"]//@href | //*[@id="contract-root"]//@src | //*[@id="contract-root"]//@alt | //*[@id="contract-root"]//@title | //*[@id="contract-root"]//svg/@aria-label') as $node) {
            $element = $node instanceof DOMAttr ? $node->ownerElement : $node->parentNode;
            if ($xpath->query('ancestor-or-self::svg', $element)->length) {
                if (!SvgScalar::eligible($node) || !in_array($element->getNodePath(), $svgPaths, true)) continue;
            } elseif ($xpath->query('ancestor-or-self::script | ancestor-or-self::style', $element)->length) continue;
            if ($xpath->query('ancestor-or-self::code', $element)->length && !self::literalPreNode($node)) continue;
            // Joomla content-plugin directives select modules/fields and are executable structure.
            if (IdentityTokens::hasDirective($node->nodeValue)
                && !self::inertBraceSource($inert[$node->getNodePath()] ?? [])) continue;
            $out[] = ['xpath' => $node->getNodePath(), 'type' => $node->nodeName === 'src' ? 'image' : ($node->nodeName === 'href' ? 'url' : 'text'), 'sample' => $node->nodeValue];
        }
        return $out;
    }
    /**
     * The elements Tracy's Joomla capture takes as one piece of text with `--whole-units`
     * (capture-contract.py, UNIT_TAGS): a heading, a sentence, a list item, a caption, a cell, a button.
     */
    private const UNIT_TAGS = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'li', 'dt', 'dd', 'figcaption', 'summary', 'th', 'td', 'button', 'label'];
    /** The comment an emptied unit holds in place of its words, see writeUnit(). */
    private const EMPTIED_UNIT = 'emptied-slot';
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
     *
     * Emptying a unit leaves a comment where its words started. The words were what told the next write where to go: without
     * them, the gaps before and after run together into one blank text and the elements around them say nothing of the place,
     * so the masked row no longer matches the masked baseline, and words written back land at the end of the unit. The comment
     * draws nothing, is no text node, and takes no index among the elements, so no other slot's address moves.
     */
    private static function writeUnit(DOMXPath $xp, DOMElement $unit, string $value): void
    {
        // The same editable words need no remapping around this unit's excluded CODE.
        if ($value !== '' && $value === self::slotText($unit))
            foreach ($xp->query('.//code', $unit) as $code)
                if (!self::nearestUnitBelow($code, $unit)) return;
        $doc = $unit->ownerDocument; $pieces = self::unitPieces($xp, $unit);
        $mark = $xp->query('./comment()[. = "' . self::EMPTIED_UNIT . '"]', $unit)->item(0);
        if ($pieces === []) {
            if ($mark === null) $unit->appendChild($doc->createTextNode($value));
            elseif ($value !== '') $unit->replaceChild($doc->createTextNode($value), $mark);
            return;
        }
        $first = $pieces[0]; $last = $pieces[count($pieces) - 1];
        preg_match('/^\s*/u', $first->data, $lead); preg_match('/\s*$/u', $last->data, $trail);
        // The new text goes directly in the unit, where the child holding the first piece starts.
        $anchor = $first; while (!$anchor->parentNode->isSameNode($unit)) $anchor = $anchor->parentNode;
        if ($value === '') {
            $unit->insertBefore($doc->createTextNode($lead[0]), $anchor);
            $unit->insertBefore($doc->createComment(self::EMPTIED_UNIT), $anchor);
            $unit->insertBefore($doc->createTextNode($trail[0]), $anchor);
        } else $unit->insertBefore($doc->createTextNode($lead[0] . $value . $trail[0]), $anchor);
        if ($mark !== null) $unit->removeChild($mark);
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
        return SvgScalar::patch($html, $changes, static fn(string $raw, array $rest): string => self::domPatch($raw, $rest));
    }

    /** Map original numeric brace source spans to their unique literal DOM receivers. */
    private static function inertPreSources(string $html, DOMXPath $xp, string $seen): array
    {
        if (!preg_match('/&#(?:0*12[35]|x0*7[bd]);/i', $html)) return [];
        $tags = LeafCodec::htmlTags($html); $fragments = [];
        do $stem = 'inertpre' . bin2hex(random_bytes(12)); while (strpos($seen, $stem) !== false);
        foreach ($tags as $i => $pre) {
            if (($pre[1][0] ?? '') !== '' || strtolower($pre[2][0] ?? '') !== 'pre' || !isset($tags[$i + 3])) continue;
            [$code, $codeEnd, $preEnd] = array_slice($tags, $i + 1, 3);
            $start = $code[0][1] + strlen($code[0][0]);
            $body = substr($html, $start, $codeEnd[0][1] - $start);
            if (($code[1][0] ?? '') !== '' || strtolower($code[2][0] ?? '') !== 'code'
                || $code[0][1] !== $pre[0][1] + strlen($pre[0][0])
                || preg_match('~/\s*>$~', $pre[0][0]) || preg_match('~/\s*>$~', $code[0][0])
                || !preg_match('~^</code\s*>$~iD', $codeEnd[0][0]) || !preg_match('~^</pre\s*>$~iD', $preEnd[0][0])
                || $preEnd[0][1] !== $codeEnd[0][1] + strlen($codeEnd[0][0])
                || strpos($body, '<') !== false || !preg_match('/&#(?:0*12[35]|x0*7[bd]);/i', $body)) continue;
            $fragments[] = ['start' => $start, 'raw' => $body, 'token' => $stem . 'x' . count($fragments)];
        }
        if ($fragments === []) return [];
        $probe = $html;
        foreach (array_reverse($fragments) as $fragment)
            $probe = substr_replace($probe, $fragment['token'], $fragment['start'], strlen($fragment['raw']));
        $probeXp = new DOMXPath(self::html($probe)); $out = [];
        foreach ($fragments as $fragment) {
            $nodes = $probeXp->query('//code/text()[.="' . $fragment['token'] . '"]');
            if ($nodes->length === 0) continue; // A lexical lookalike inside a comment or raw-text element.
            if ($nodes->length !== 1) throw new RuntimeException('Inert PRE source receiver is ambiguous');
            $node = $nodes->item(0);
            if (!self::literalPreNode($node) || $probeXp->query('ancestor::textarea | ancestor::noscript | ancestor::template | ancestor::title | ancestor::xmp | ancestor::iframe | ancestor::noembed | ancestor::noframes | ancestor::plaintext', $node)->length) continue;
            $path = $node->getNodePath(); $original = $xp->query($path);
            if ($original->length !== 1 || !self::literalPreNode($original->item(0)) || isset($out[$path]))
                throw new RuntimeException('Inert PRE source has no unique literal receiver');
            $fragment['node'] = $original->item(0); $fragment['value'] = $fragment['node']->data;
            $out[$path] = $fragment;
        }
        return $out;
    }

    /** All braces must come from numeric references, with the same decoded bytes as the real receiver. */
    private static function inertBraceSource(array $fragment): bool
    {
        if (!isset($fragment['raw'], $fragment['value'])) return false;
        $rest = preg_replace('/&#(?:0*12[35]|x0*7[bd]);/i', '', $fragment['raw']);
        $rest = html_entity_decode($rest, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return strpbrk($rest, '{}') === false
            && html_entity_decode($fragment['raw'], ENT_QUOTES | ENT_HTML5, 'UTF-8') === $fragment['value'];
    }

    /** Resolve a candidate against the actual current column, including nested JSON-HTML. No map flag grants it. */
    public static function inertLiteralBraceSlot(array $row, array $slot): bool
    {
        if (!self::literalPreSlot($slot)) return false;
        try {
            $html = $row[$slot['column']] ?? null;
            if (isset($slot['jsonPath'])) {
                $html = json_decode($html, true, 512, JSON_THROW_ON_ERROR);
                if (isset($slot['nestedJson'])) $html = json_decode($html[$slot['nestedJson']], true, 512, JSON_THROW_ON_ERROR);
                foreach ($slot['jsonPath'] as $key) $html = is_array($html) ? ($html[$key] ?? null) : null;
            }
            if (!is_string($html)) return false;
            $xp = new DOMXPath(self::html($html)); $nodes = $xp->query($slot['xpath']);
            if (!$nodes || $nodes->length !== 1 || !self::literalPreNode($nodes->item(0))) return false;
            $sources = self::inertPreSources($html, $xp, $html);
            return self::inertBraceSource($sources[$nodes->item(0)->getNodePath()] ?? []);
        } catch (Throwable $error) { return false; }
    }

    private static function domPatch(string $html, array $changes): string
    {
        $doc = self::html($html); $xp = new DOMXPath($doc);
        $seen = $html; foreach ($changes as $change) $seen .= "\n" . $change['value'];
        $inert = self::inertPreSources($html, $xp, $seen);
        foreach ($changes as $change) {
            $nodes = $xp->query($change['xpath']);
            if ($nodes === false || $nodes->length !== 1) throw new RuntimeException('HTML slot is missing or ambiguous');
            $node = $nodes->item(0);
            self::checkCodeNode($node, ['type' => 'text', 'xpath' => $change['xpath']]);
            if ($node instanceof DOMElement) { self::writeUnit($xp, $node, (string) $change['value']); continue; }
            if (!($node instanceof DOMText) && !($node instanceof DOMAttr)) throw new RuntimeException('HTML slot is not a scalar');
            $value = $change['value'];
            if (self::literalPreNode($node) && IdentityTokens::hasDirective((string) $value)
                && !self::inertBraceSource($inert[$node->getNodePath()] ?? []))
                throw new RuntimeException('Literal brace content needs an inert source receiver');
            // An attribute's value set from PHP is read as markup: `&` starts an entity reference, and
            // a bare one (`?a=1&b=2`, "Sales & Marketing") emptied the attribute on libxml 2.9 and was
            // dropped on 2.13. So every `&` that does not begin a complete reference is written as
            // `&amp;`, and reads back as the `&` that was written. A complete reference (`&amp;`,
            // `&#38;`, `&copy;`) is decoded as before, so a value that already spells `&amp;` is not
            // escaped twice; a name with no `;` (`&copy=2`, `&lang=en`) is text.
            if ($node instanceof DOMAttr) $value = preg_replace('/&(?!(?:[A-Za-z][A-Za-z0-9]*|#[0-9]+|#x[0-9A-Fa-f]+);)/', '&amp;', (string) $value);
            // HTML parsers normalize CR LF and lone CR to LF (libxml 2.14+ does, older ones kept CR), so a
            // literal PRE value is written with LF breaks and reads back the same on every libxml.
            if (self::literalPreNode($node)) $node->data = self::lineBreaks((string) $value);
            else $node->nodeValue = $value;
        }
        $restore = [];
        foreach ($inert as $fragment) {
            $node = $fragment['node'];
            if (!self::literalPreNode($node) || !$xp->query('ancestor::*[@id="contract-root"]', $node)->length) continue;
            $raw = $fragment['raw'];
            if ($node->data !== $fragment['value']) {
                if (!self::inertBraceSource($fragment)) continue;
                $raw = strtr(htmlspecialchars($node->data, ENT_NOQUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8'), ['{' => '&#123;', '}' => '&#125;']);
            }
            $node->data = $fragment['token']; $restore[$fragment['token']] = $raw;
        }
        $saved = self::saved($doc, $xp, $seen);
        foreach ($restore as $token => $raw)
            if (substr_count($saved, $token) !== 1) throw new RuntimeException('HTML write lost an inert PRE source receiver');
        if ($restore !== []) $saved = strtr($saved, $restore);
        $readback = null;
        foreach ($changes as $change) if (self::literalPreSlot(['type' => 'text', 'xpath' => $change['xpath']])) {
            $readback = $readback ?? new DOMXPath(self::html($saved));
            $nodes = $readback->query($change['xpath']);
            if (!$nodes || $nodes->length !== 1 || !self::literalPreNode($nodes->item(0))
                || self::slotText($nodes->item(0)) !== self::lineBreaks((string) $change['value']))
                throw new RuntimeException('Literal PRE text did not read back exactly');
        }
        return $saved;
    }
    private static function lineBreaks(string $text): string
    {
        return str_replace(["\r\n", "\r"], "\n", $text);
    }
    private static function saved(DOMDocument $doc, DOMXPath $xp, string $seen): string
    {
        $restore = self::shieldTokens($xp, $seen);
        $root = $xp->query('//*[@id="contract-root"]')->item(0); $out = '';
        foreach ($root->childNodes as $child) $out .= $doc->saveHTML($child);
        return $restore === [] ? $out : strtr($out, $restore);
    }

    /** The comment a removed element leaves: two text nodes around it stay two, so no text address moves. It draws nothing. */
    private const REMOVED_NODE = 'removed-slot';
    private const ROOT = '/html/body/div';

    /**
     * Takes one node out of an HTML field, by the address {@see removalOps} found. A field left with nothing but blanks and
     * comments is the empty string: a template's `if ($field)` draws no box around it.
     */
    public static function removeNode(string $html, array $op): string
    {
        if ($op['xpath'] === self::ROOT) return '';
        $doc = self::html($html); $xp = new DOMXPath($doc);
        $nodes = $xp->query((string) $op['xpath']);
        if ($nodes === false || $nodes->length !== 1) throw new RuntimeException('HTML node to remove is missing or ambiguous');
        $node = $nodes->item(0);
        if ($node instanceof DOMAttr) $node->ownerElement->removeAttributeNode($node);
        elseif ($node instanceof DOMElement) $node->parentNode->replaceChild($doc->createComment(self::REMOVED_NODE), $node);
        else $node->parentNode->removeChild($node);
        $root = $xp->query('//*[@id="contract-root"]')->item(0);
        foreach ($root->childNodes as $child)
            if (!($child instanceof DOMComment) && !($child instanceof DOMText && trim($child->data) === '')) return self::saved($doc, $xp, $html);
        return '';
    }

    /**
     * Which nodes of one HTML field go when `$going` slots are hidden: their smallest own element or text, and each parent
     * those leave with nothing in it.
     *
     * Read from the field, not from any one template. A slot's element goes when every slot in it may go. Its parent goes
     * when nothing is left in it; one left with only decoration (an icon, no slot) goes only when a sibling element of the
     * same name and class proves it a repeated row, and otherwise the slot is refused by name: its box would stay. An element
     * holding a slot in `$held` (one the writer keeps or fills) never goes: its slot gives up only its own text or alt.
     *
     * @param list<array> $slots every slot of this field as it now stands
     * @param array<string,true> $going @param array<string,true> $held
     * @return array{ops:list<array{op:string,xpath:string}>,refused:array<string,string>} ops in reverse document order, each
     *   address true when it is taken, because everything after it has gone first
     */
    public static function removalOps(string $html, array $slots, array $going, array $held): array
    {
        $doc = self::html($html); $xp = new DOMXPath($doc);
        $root = $xp->query('//*[@id="contract-root"]')->item(0);
        $nodes = []; $refused = [];
        foreach ($slots as $slot) {
            $found = $xp->query((string) $slot['xpath']);
            if ($found === false || $found->length !== 1) {
                if (isset($going[$slot['key']])) $refused[$slot['key']] = 'its address is missing or ambiguous on this site: ' . $slot['key'];
                continue;
            }
            $nodes[$slot['key']] = $found->item(0);
        }
        $holder = static fn(DOMNode $node): DOMElement => $node instanceof DOMAttr ? $node->ownerElement : ($node instanceof DOMElement ? $node : $node->parentNode);
        $inside = static function (DOMNode $node, DOMElement $element): bool {
            for ($up = $node instanceof DOMAttr ? $node->ownerElement : $node; $up !== null; $up = $up->parentNode) if ($up->isSameNode($element)) return true;
            return false;
        };
        $holdsKept = static function (DOMElement $element) use (&$nodes, $held, $inside): bool {
            foreach ($nodes as $key => $node) if (isset($held[$key]) && $inside($node, $element)) return true;
            return false;
        };
        $holdsSlot = static function (DOMNode $node) use (&$nodes, $inside): bool {
            if (!($node instanceof DOMElement)) return false;
            foreach ($nodes as $slotNode) if ($inside($slotNode, $node)) return true;
            return false;
        };
        $taken = []; $texts = []; $attrs = [];
        foreach ($nodes as $key => $node) {
            if (!isset($going[$key])) continue;
            $element = $holder($node);
            if ($element->isSameNode($root) && !($node instanceof DOMElement)) {
                if ($node instanceof DOMAttr) $refused[$key] = 'an attribute of the field itself cannot be removed: ' . $key;
                else $texts[] = $node;
                continue;
            }
            if (!$holdsKept($element)) { $taken[] = $element; continue; }
            if ($node instanceof DOMText) { $texts[] = $node; continue; }
            if ($node instanceof DOMAttr && in_array($node->nodeName, ['alt', 'title'], true)) { $attrs[] = $node; continue; }
            $refused[$key] = 'its element holds a slot that stays, so it cannot be removed: ' . $key;
        }
        $isGone = static function (DOMNode $node, array $list): bool {
            foreach ($list as $item) if ($item->isSameNode($node)) return true;
            return false;
        };
        // What is left in `$element` once these go: nothing, decoration only, or something that stays.
        $left = static function (DOMElement $element) use (&$taken, &$texts, $isGone, $holdsSlot): string {
            $state = 'nothing';
            foreach ($element->childNodes as $child) {
                if ($child instanceof DOMComment || $isGone($child, $taken) || $isGone($child, $texts)) continue;
                if ($child instanceof DOMText) { if (trim($child->data) !== '') return 'stays'; continue; }
                if ($child instanceof DOMElement && strtolower($child->nodeName) === 'br') continue;
                if ($holdsSlot($child)) return 'stays';
                $state = 'decoration';
            }
            return $state;
        };
        $repeats = static function (DOMElement $element): bool {
            foreach ($element->parentNode->childNodes as $other)
                if ($other instanceof DOMElement && !$other->isSameNode($element) && $other->nodeName === $element->nodeName && $other->getAttribute('class') === $element->getAttribute('class')) return true;
            return false;
        };
        for ($climbed = true; $climbed;) {
            $climbed = false;
            foreach ($taken as $element) {
                if ($element->isSameNode($root)) continue;
                $parent = $element->parentNode;
                if ($isGone($parent, $taken) || !($parent instanceof DOMElement)) continue;
                if ($parent->isSameNode($root)) {
                    if ($left($root) === 'nothing') { $taken[] = $root; $climbed = true; break 2; }
                    continue;
                }
                $state = $left($parent);
                if ($state === 'stays' || $holdsKept($parent)) continue;
                if ($state === 'nothing' || $repeats($parent)) { $taken[] = $parent; $climbed = true; continue; }
                foreach ($nodes as $key => $node) if (isset($going[$key]) && $inside($node, $parent))
                    $refused[$key] = 'its parent keeps markup of its own and has no repeated sibling to prove it a row, so the box would stay: ' . $key;
            }
        }
        if ($taken !== [] && $left($root) === 'nothing') $taken[] = $root;
        if ($isGone($root, $taken)) return ['ops' => [['op' => 'node', 'xpath' => self::ROOT]], 'refused' => $refused];
        $inTaken = static function (DOMNode $node) use (&$taken, $inside, $holder): bool {
            foreach ($taken as $element) if (!$node->isSameNode($element) && $inside($node, $element)) return true;
            return false;
        };
        $out = [];
        foreach (array_merge($taken, $texts, $attrs) as $node) {
            if ($inTaken($node)) continue;
            $path = $node->getNodePath();
            $out[$path] = ['op' => 'node', 'xpath' => $path];
        }
        // Later nodes first, so each address still holds when its turn comes.
        $order = [];
        foreach ($xp->query('.//node() | .//@*', $root) as $i => $node) $order[$node->getNodePath()] = $i;
        uksort($out, static fn(string $a, string $b): int => ($order[$b] ?? 0) <=> ($order[$a] ?? 0));
        return ['ops' => array_values($out), 'refused' => $refused];
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
