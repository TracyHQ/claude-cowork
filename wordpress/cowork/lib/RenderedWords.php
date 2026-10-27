<?php
/**
 * RenderedWords — words a theme prints at render time that no stored record holds (a pattern a
 * `the_content` filter injects, a literal in a template), replaced in the finished HTML of one page.
 *
 * A rule names one place on one page: a text node or an `<a href>` found by inspection, its exact
 * old words, the new ones and how many times the old words are there. Rules are kept per page and
 * locale (one option per scope, RenderedWordsHooks reads it) and applied to the response the theme
 * produced, never to anything stored:
 *
 *   - text matches inside ONE decoded text node; an `<a href>` matches its whole decoded value. No
 *     other attribute, and nothing inside `head`, `form`, `script`, `style`, `textarea`, a control,
 *     an `svg` or a comment, is ever read as a candidate or written;
 *   - every rule of the scope is checked against the unmodified page first. A count that changed, an
 *     anchor that is gone, two rules that overlap or markup this scanner cannot follow leave the page
 *     exactly as the theme printed it: a rule set applies whole or not at all;
 *   - only the matched bytes are spliced; the rest of the document is never reserialized.
 *
 * Plain PHP, no WordPress: the hooks and the door wire it to the site.
 */
declare(strict_types=1);

interface RenderedWordsSite
{
    /**
     * A fresh render of a page as an anonymous visitor's, with no rule applied and no page cache in
     * the way, and the scope the front end resolved it to.
     *
     * @return array{code:int,body:string,post:int,locale:string,theme:string} code 0 when nothing answered
     */
    public function probe(string $path): array;

    /** The page as an anonymous visitor gets it now, page caches included. @return array{code:int,body:string} */
    public function visit(string $path): array;

    /** The active theme's fingerprint, as the front end computes it. */
    public function theme(): string;

    /** Bytes every scope option of this site holds together. */
    public function storedBytes(): int;

    /** Drop the page caches that may serve the page (the visit after it is what proves they did). */
    public function purge(string $optionKey): void;

    /** The latest drift the front end saw on a scope, or null. @return array<string,mixed>|null */
    public function drift(int $post, string $locale): ?array;
}

final class RenderedWords
{
    public const SCHEMA = 1;
    /** One option per scope: `<prefix><post id>_<locale>`. Not autoloaded (the writer passes `false`). */
    public const OPTION_PREFIX = 'claude_cowork_rendered_words_';
    /** The request ids this site answered, kept after a revert so a late retry cannot write again. Not in the apply log. */
    public const RECEIPTS_OPTION = 'claude_cowork_rendered_words_receipts';
    /** Probe request header (signed) and the scope the front end answers it with. */
    public const PROBE_HEADER = 'X-Tracy-Rendered-Words-Probe';
    public const SCOPE_HEADER = 'X-Tracy-Rendered-Words-Scope';

    public const MAX_HTML = 2097152;
    public const MAX_TEXT = 4096;
    public const MAX_URL = 2048;
    public const MAX_RULES_REQUEST = 16;
    public const MAX_RULES_SCOPE = 64;
    public const MAX_SCOPE_BYTES = 131072;
    public const MAX_SITE_BYTES = 4194304;
    public const MAX_MATCHES = 64;
    /** Requests a site keeps a receipt of. Never evicted (a forgotten request_id could apply twice): past it, new applies are refused. */
    public const MAX_RECEIPTS = 1000;
    /** How long an inspection's proof and a probe header are good for, in seconds. */
    public const PROOF_SECONDS = 900;
    public const PROBE_SECONDS = 120;

    private const LOCALE = '/^[a-z]{2,3}(?:_[A-Z]{2})?(?:_[a-z0-9]+)?$/D';
    private const ANCHOR = '/^[a-f0-9]{16}$/D';
    /** Subtrees never read nor written: page metadata, forms and their controls, scripts, embedded documents, foreign markup. */
    private const EXCLUDED = ['head', 'title', 'form', 'script', 'style', 'textarea', 'select', 'option', 'optgroup', 'datalist',
        'button', 'template', 'noscript', 'iframe', 'object', 'svg', 'math'];
    /** Elements whose content is not markup: skipped to their own end tag. */
    private const RAW_TEXT = ['script', 'style', 'textarea', 'title', 'xmp', 'iframe', 'noembed', 'noframes', 'noscript'];
    private const VOID = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr', 'keygen'];
    private const RULE_KEYS = ['rule_id', 'kind', 'name', 'anchor', 'old', 'new', 'expected_matches', 'replaces_rule'];

    // ---- scope and storage ------------------------------------------------------------------

    public static function optionKey(int $post, string $locale): string
    {
        return self::OPTION_PREFIX . $post . '_' . $locale;
    }

    /** Whether an option is this feature's own record: it changes only through `content.renderedWords`. */
    public static function isOption(string $key): bool
    {
        return strpos($key, self::OPTION_PREFIX) === 0;
    }

    public static function validLocale($locale): bool
    {
        return is_string($locale) && preg_match(self::LOCALE, $locale) === 1;
    }

    /**
     * One scope's set from its option value, `[]` for none or anything that is not the shape: a set
     * that cannot be read applies nothing.
     *
     * @return array{schema:int,post:int,locale:string,revision:int,theme:string,rules:list<array<string,mixed>>}|array{}
     */
    public static function decodeSet($raw): array
    {
        $set = is_string($raw) && $raw !== '' ? json_decode($raw, true) : (is_array($raw) ? $raw : null);
        if (!is_array($set) || ($set['schema'] ?? null) !== self::SCHEMA || !is_int($set['post'] ?? null) || !self::validLocale($set['locale'] ?? null)
            || !is_int($set['revision'] ?? null) || !is_string($set['theme'] ?? null) || !is_array($set['rules'] ?? null)) {
            return [];
        }
        foreach ($set['rules'] as $rule) {
            if (!is_array($rule) || self::ruleRefusal(array_intersect_key($rule, array_flip(self::RULE_KEYS))) !== null) {
                return [];
            }
        }
        $set['rules'] = array_values($set['rules']);
        return $set;
    }

    public static function encodeSet(array $set): string
    {
        return (string) json_encode($set, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** A rule's stable id: what it matches, never what it writes, so a second write replaces the first. */
    public static function ruleId(array $rule): string
    {
        return substr(hash('sha256', implode("\0", [(string) ($rule['kind'] ?? ''), (string) ($rule['name'] ?? ''),
            (string) ($rule['anchor'] ?? ''), (string) ($rule['old'] ?? '')])), 0, 16);
    }

    /**
     * The set's rules with a request's rules folded in, by rule id: a new destination replaces the
     * old one (the source stays what the theme prints, so no chain forms); `new` equal to `old` drops the rule.
     *
     * @param list<array<string,mixed>> $rules
     * @param list<array<string,mixed>> $incoming validated rules
     * @return list<array<string,mixed>>
     */
    public static function merge(array $rules, array $incoming, string $applyId): array
    {
        $byId = [];
        foreach ($rules as $rule) {
            $byId[(string) $rule['id']] = $rule;
        }
        foreach ($incoming as $rule) {
            $id = self::ruleId($rule);
            if ($rule['new'] === $rule['old']) {
                unset($byId[$id]);
                continue;
            }
            $byId[$id] = ['id' => $id, 'kind' => $rule['kind']] + ($rule['kind'] === 'attribute' ? ['name' => 'href'] : [])
                + ['anchor' => $rule['anchor'], 'old' => $rule['old'], 'new' => $rule['new'],
                    'expected_matches' => (int) $rule['expected_matches'], 'apply_id' => $applyId];
        }
        return array_values($byId);
    }

    // ---- validation ---------------------------------------------------------------------------

    /** Why one rule of a request cannot be stored, or null when it can. */
    public static function ruleRefusal($rule): ?string
    {
        if (!is_array($rule)) {
            return 'a rule is an object';
        }
        $extra = array_diff(array_keys($rule), self::RULE_KEYS, ['id', 'apply_id']);
        if ($extra !== []) {
            return 'unknown rule field: ' . implode(', ', array_map('strval', $extra));
        }
        $kind = $rule['kind'] ?? null;
        if ($kind !== 'text' && $kind !== 'attribute') {
            return 'kind is text or attribute';
        }
        if ($kind === 'attribute' && ($rule['name'] ?? null) !== 'href') {
            return 'an attribute rule names href: no other attribute is ever written';
        }
        if ($kind === 'text' && array_key_exists('name', $rule)) {
            return 'a text rule has no name';
        }
        if (!is_string($rule['anchor'] ?? null) || preg_match(self::ANCHOR, $rule['anchor']) !== 1) {
            return 'anchor is one an inspection returned';
        }
        $limit = $kind === 'text' ? self::MAX_TEXT : self::MAX_URL;
        foreach (['old', 'new'] as $field) {
            $value = $rule[$field] ?? null;
            if (!is_string($value) || $value === '' || strlen($value) > $limit || preg_match('//u', $value) !== 1) {
                return "{$field} is non-empty UTF-8 of at most {$limit} bytes";
            }
            if ($kind === 'text' && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
                return "{$field} holds a control character";
            }
        }
        if ($kind === 'text' && trim($rule['old']) === '') {
            return 'old words are not only spaces';
        }
        if ($kind === 'attribute' && $rule['new'] !== $rule['old']) {
            $why = self::urlRefusal($rule['new']);
            if ($why !== null) {
                return $why;
            }
        }
        $count = $rule['expected_matches'] ?? null;
        if (!is_int($count) || $count < 1 || $count > self::MAX_MATCHES) {
            return 'expected_matches is a count from 1 to ' . self::MAX_MATCHES;
        }
        if (isset($rule['rule_id']) && $rule['rule_id'] !== self::ruleId($rule)) {
            return 'rule_id does not match the rule';
        }
        if (array_key_exists('replaces_rule', $rule) && (!is_string($rule['replaces_rule']) || preg_match(self::ANCHOR, $rule['replaces_rule']) !== 1)) {
            return 'replaces_rule is a rule id from a whole-output inspection';
        }
        return null;
    }

    /**
     * Inspect visible words while retaining the one raw rule that produced each whole output.
     * Mixed raw/output matches at one anchor, substrings and outputs assembled by several rules
     * are deliberately refused: their visible count cannot name one existing rule unambiguously.
     * @return list<array<string,mixed>>|null
     */
    public static function visibleCandidates(string $html, array $rules, array $targets): ?array
    {
        $rendered = self::render($html, $rules);
        if ($rules !== [] && !$rendered['applied']) return null;
        $visible = self::candidates($rendered['html'], $targets);
        $raw = self::candidates($html, $targets);
        if ($visible === null || $raw === null) return null;
        foreach ($visible as $i => &$row) {
            foreach ($row['candidates'] as &$candidate) {
                $outputs = array_values(array_filter($rules, static fn($rule): bool =>
                    $rule['kind'] === $row['kind'] && ($rule['name'] ?? null) === ($row['name'] ?? null)
                    && $rule['anchor'] === $candidate['anchor'] && strpos($rule['new'], $row['old']) !== false));
                $rawCount = 0;
                foreach ($raw[$i]['candidates'] as $original) {
                    if ($original['anchor'] === $candidate['anchor']) $rawCount += $original['count'];
                }
                if ($outputs === []) {
                    if ($rawCount !== $candidate['count']) return null;
                    continue;
                }
                if (count($outputs) !== 1 || $rawCount !== 0 || $outputs[0]['new'] !== $row['old']
                    || $outputs[0]['expected_matches'] !== $candidate['count']) return null;
                $candidate['replaces_rule'] = $outputs[0]['id'];
            }
            unset($candidate);
        }
        unset($row);
        return $visible;
    }

    /** Resolve a visible edit to its original raw source only after verifying its current provenance. */
    public static function compose(string $html, array $existing, array $incoming): ?array
    {
        $out = [];
        $ids = [];
        foreach ($incoming as $rule) {
            if (isset($rule['replaces_rule'])) {
                $target = ['kind' => $rule['kind'], 'old' => $rule['old']] + ($rule['kind'] === 'attribute' ? ['name' => 'href'] : []);
                $rows = self::visibleCandidates($html, $existing, [$target]);
                $matched = false;
                foreach ($rows[0]['candidates'] ?? [] as $candidate) {
                    if ($candidate['anchor'] === $rule['anchor'] && $candidate['count'] === $rule['expected_matches']
                        && ($candidate['replaces_rule'] ?? null) === $rule['replaces_rule']) $matched = true;
                }
                if (!$matched) return null;
                $source = null;
                foreach ($existing as $old) {
                    if ($old['id'] === $rule['replaces_rule']) $source = $old;
                }
                if ($source === null) return null;
                $rule['old'] = $source['old'];
                unset($rule['replaces_rule'], $rule['rule_id']);
            }
            $id = self::ruleId($rule);
            if (isset($ids[$id]) || self::ruleRefusal($rule) !== null) return null;
            $ids[$id] = true;
            $out[] = $rule;
        }
        return $out;
    }

    /**
     * Why an address may not become an `href`, or null. The value is the decoded one the caller means;
     * it is escaped on the way out, so an entity cannot smuggle a scheme in. Allowed: http(s) with a
     * host and no credentials, tel:, mailto:, a site path (`/…`, not `//…`) and a fragment.
     */
    public static function urlRefusal(string $url): ?string
    {
        if ($url === '' || strlen($url) > self::MAX_URL || preg_match('//u', $url) !== 1) {
            return 'an address is non-empty UTF-8 of at most ' . self::MAX_URL . ' bytes';
        }
        if (preg_match('/[\x00-\x20\x7F\\\\]/', $url) === 1) {
            return 'an address holds no space, control character or backslash';
        }
        if ($url[0] === '#') {
            return null;
        }
        if ($url[0] === '/') {
            return ($url[1] ?? '') === '/' ? 'a protocol-relative address is not allowed' : null;
        }
        if (preg_match('/^([a-zA-Z][a-zA-Z0-9+.-]*):(.*)$/sD', $url, $m) !== 1) {
            return 'an address is http(s):, tel:, mailto:, a site path starting with / or a #fragment';
        }
        $scheme = strtolower($m[1]);
        $rest = $m[2];
        if ($scheme === 'http' || $scheme === 'https') {
            if (preg_match('~^//([^/?#]+)~', $rest, $authority) !== 1 || strpos($authority[1], '@') !== false
                || preg_match('/^(?:\[[0-9a-fA-F:.]+\]|[^\[\]:]+)(?::[0-9]{1,5})?$/D', $authority[1]) !== 1) {
                return 'an http(s) address names a host and no credentials';
            }
            return null;
        }
        if ($scheme === 'tel') {
            return preg_match('/^\+?[0-9()+.\-]{1,32}$/D', $rest) === 1 ? null : 'a tel: address holds digits, + ( ) . - only';
        }
        if ($scheme === 'mailto') {
            return preg_match('/^[^<>"\'@?]+@[^<>"\'@?]+(?:\?[^<>"\']*)?$/D', $rest) === 1 ? null : 'a mailto: address is one mailbox';
        }
        return 'scheme not allowed: ' . $scheme;
    }

    // ---- proof ------------------------------------------------------------------------------

    /** The signing key of probes and proofs, derived from the site token: nothing else holds it. */
    public static function key(string $token): string
    {
        return hash_hmac('sha256', 'claude-cowork/rendered-words/v1', $token);
    }

    public static function sign(string $key, array $payload): string
    {
        $body = rtrim(strtr(base64_encode((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), '+/', '-_'), '=');
        return $body . '.' . hash_hmac('sha256', 'proof.' . $body, $key);
    }

    /** The payload of a proof this site signed and that has not expired, or null. */
    public static function verify(string $key, $proof, int $now): ?array
    {
        if (!is_string($proof) || strlen($proof) > 4096 || preg_match('/^([A-Za-z0-9_-]+)\.([a-f0-9]{64})$/D', $proof, $m) !== 1
            || !hash_equals(hash_hmac('sha256', 'proof.' . $m[1], $key), $m[2])) {
            return null;
        }
        $payload = json_decode((string) base64_decode(strtr($m[1], '-_', '+/'), true), true);
        return is_array($payload) && is_int($payload['exp'] ?? null) && $payload['exp'] >= $now ? $payload : null;
    }

    /** The probe header's value: `<expiry>.<mac>`. */
    public static function probeValue(string $key, int $now): string
    {
        $exp = $now + self::PROBE_SECONDS;
        return $exp . '.' . hash_hmac('sha256', 'probe.' . $exp, $key);
    }

    public static function probeValid(string $key, $value, int $now): bool
    {
        if (!is_string($value) || preg_match('/^([0-9]{1,12})\.([a-f0-9]{64})$/D', trim($value), $m) !== 1) {
            return false;
        }
        $exp = (int) $m[1];
        return $exp >= $now && $exp <= $now + self::PROBE_SECONDS && hash_equals(hash_hmac('sha256', 'probe.' . $exp, $key), $m[2]);
    }

    // ---- the page ---------------------------------------------------------------------------

    /**
     * The places on a page words may be replaced: eligible text nodes and `<a href>` values, with byte
     * offsets and the element path above them. Null when the page is too large or this scanner cannot
     * follow its markup (an unclosed comment, tag or raw-text element; an excluded element closed by
     * another element's end tag).
     *
     * @return array{texts:list<array{0:int,1:int,2:string}>,hrefs:list<array{0:int,1:int,2:string,3:string}>}|null
     *         a text is [start, end, path]; an href is [value start, value end (quotes included), raw value, path]
     */
    public static function scan(string $html): ?array
    {
        $len = strlen($html);
        if ($len > self::MAX_HTML) {
            return null;
        }
        $stack = [];
        $excluded = 0;
        $texts = [];
        $hrefs = [];
        $textStart = 0;
        $pos = 0;
        $flush = static function (int $from, int $to) use (&$texts, &$stack, &$excluded, $html): void {
            if ($to > $from && $excluded === 0 && strspn($html, " \t\n\r\f", $from, $to - $from) < $to - $from) {
                $texts[] = [$from, $to, implode('/', $stack)];
            }
        };
        while ($pos < $len) {
            $lt = strpos($html, '<', $pos);
            if ($lt === false) {
                break;
            }
            $next = $html[$lt + 1] ?? '';
            if (substr_compare($html, '<!--', $lt, 4) === 0) {
                $flush($textStart, $lt);
                if (preg_match('/\G-?/', $html, $m, 0, $lt + 4) === 1 && ($html[$lt + 4 + strlen($m[0])] ?? '') === '>') {
                    $end = $lt + 4 + strlen($m[0]);   // `<!-->` and `<!--->`: an empty comment
                } else {
                    $end = strpos($html, '-->', $lt + 4);
                    if ($end === false) {
                        return null;
                    }
                    $end += 2;
                }
                $pos = $textStart = $end + 1;
                continue;
            }
            if ($next === '!' || $next === '?') {
                $flush($textStart, $lt);
                $cdata = substr_compare($html, '<![CDATA[', $lt, 9) === 0;
                $end = $cdata ? strpos($html, ']]>', $lt + 9) : strpos($html, '>', $lt + 2);
                if ($end === false) {
                    return null;
                }
                $pos = $textStart = $end + ($cdata ? 3 : 1);
                continue;
            }
            if ($next === '/') {
                $flush($textStart, $lt);
                $gt = strpos($html, '>', $lt + 2);
                if ($gt === false) {
                    return null;
                }
                if (preg_match('/\G<\/([A-Za-z][^\s\/>]*)/', $html, $m, 0, $lt) === 1) {
                    $name = strtolower($m[1]);
                    $at = array_search($name, array_reverse($stack, true), true);
                    if ($at !== false) {
                        for ($j = count($stack) - 1; $j > $at; $j--) {
                            if (in_array($stack[$j], self::EXCLUDED, true)) {
                                return null;
                            }
                        }
                        $stack = array_slice($stack, 0, $at);
                        $excluded = count(array_intersect($stack, self::EXCLUDED));
                    }
                }
                $pos = $textStart = $gt + 1;
                continue;
            }
            if (!ctype_alpha($next)) {
                $pos = $lt + 1;   // a lone `<` is text
                continue;
            }
            $flush($textStart, $lt);
            $tag = self::tag($html, $lt);
            if ($tag === null) {
                return null;
            }
            [$name, $attrs, $end, $selfClosing] = $tag;
            if ($name === 'a' && $excluded === 0) {
                $own = array_values(array_filter($attrs, static fn(array $a): bool => $a[0] === 'href'));
                // Two hrefs: a browser takes the first, a filter might take the last. Neither is touched.
                if (count($own) === 1 && $own[0][1] !== null) {
                    $hrefs[] = [$own[0][1], $own[0][2], $own[0][3], implode('/', array_merge($stack, ['a']))];
                }
            }
            if ($name === 'plaintext') {
                return null;
            }
            if (in_array($name, self::RAW_TEXT, true)) {
                if (preg_match('~</' . preg_quote($name, '~') . '(?=[\s/>])~i', $html, $m, PREG_OFFSET_CAPTURE, $end) !== 1) {
                    return null;
                }
                $gt = strpos($html, '>', (int) $m[0][1]);
                if ($gt === false) {
                    return null;
                }
                $pos = $textStart = $gt + 1;
                continue;
            }
            $foreign = in_array('svg', $stack, true) || in_array('math', $stack, true) || $name === 'svg' || $name === 'math';
            if (!in_array($name, self::VOID, true) && !($selfClosing && $foreign)) {
                $stack[] = $name;
                if (in_array($name, self::EXCLUDED, true)) {
                    $excluded++;
                }
            }
            $pos = $textStart = $end;
        }
        $flush($textStart, $len);
        return ['texts' => $texts, 'hrefs' => $hrefs];
    }

    /**
     * One start tag at `$lt`: [lower-case name, attributes, offset after `>`, self-closing], each
     * attribute [lower-case name, value start (quote included) or null, value end, raw value]. Null
     * when the tag does not end or a quoted value does not close.
     */
    private static function tag(string $html, int $lt): ?array
    {
        if (preg_match('/\G<([^\s\/>]+)/', $html, $m, 0, $lt) !== 1) {
            return null;
        }
        $name = strtolower($m[1]);
        $i = $lt + strlen($m[0]);
        $len = strlen($html);
        $attrs = [];
        while (true) {
            $i += strspn($html, " \t\n\r\f", $i);
            if ($i >= $len) {
                return null;
            }
            $c = $html[$i];
            if ($c === '>') {
                return [$name, $attrs, $i + 1, false];
            }
            if ($c === '/') {
                if (($html[$i + 1] ?? '') === '>') {
                    return [$name, $attrs, $i + 2, true];
                }
                $i++;
                continue;
            }
            preg_match('/\G=?[^\s\/>=]*/', $html, $an, 0, $i);
            $attr = strtolower($an[0]);
            $i += strlen($an[0]);
            $j = $i + strspn($html, " \t\n\r\f", $i);
            if (($html[$j] ?? '') !== '=') {
                $attrs[] = [$attr, null, null, ''];
                continue;
            }
            $j++;
            $j += strspn($html, " \t\n\r\f", $j);
            if ($j >= $len) {
                return null;
            }
            $q = $html[$j];
            if ($q === '"' || $q === "'") {
                $close = strpos($html, $q, $j + 1);
                if ($close === false) {
                    return null;
                }
                $attrs[] = [$attr, $j, $close + 1, substr($html, $j + 1, $close - $j - 1)];
                $i = $close + 1;
                continue;
            }
            $run = strcspn($html, " \t\n\r\f>", $j);
            $attrs[] = [$attr, $j, $j + $run, substr($html, $j, $run)];
            $i = $j + $run;
        }
    }

    /** What an element path is called to a caller: opaque, stable while the markup above the words is. */
    public static function anchor(string $kind, string $path): string
    {
        return substr(hash('sha256', 'rw1|' . $kind . '|' . $path), 0, 16);
    }

    private static function decode(string $raw): string
    {
        return html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Every place `$old` sits in one text node, as raw byte ranges of the node. Matched in the decoded
     * text; a match that starts or ends inside an entity's decoding cannot be spliced and is null.
     *
     * @return list<array{0:int,1:int}>|null
     */
    private static function textMatches(string $raw, string $old): ?array
    {
        if (strpos($raw, '&') === false) {
            $out = [];
            for ($at = strpos($raw, $old); $at !== false; $at = strpos($raw, $old, $at + strlen($old))) {
                $out[] = [$at, $at + strlen($old)];
            }
            return $out;
        }
        // Segments [decoded start, decoded end, raw start, raw end, literal].
        $segments = [];
        $decoded = '';
        $last = 0;
        preg_match_all('/&(?:#[0-9]+;?|#[xX][0-9a-fA-F]+;?|[A-Za-z][A-Za-z0-9]*;?)/', $raw, $all, PREG_OFFSET_CAPTURE);
        foreach ($all[0] as [$entity, $at]) {
            $value = self::decode($entity);
            if ($value === $entity) {
                continue;   // not an entity: literal, part of the run around it
            }
            if ($at > $last) {
                $segments[] = [strlen($decoded), strlen($decoded) + $at - $last, $last, $at, true];
                $decoded .= substr($raw, $last, $at - $last);
            }
            $segments[] = [strlen($decoded), strlen($decoded) + strlen($value), $at, $at + strlen($entity), false];
            $decoded .= $value;
            $last = $at + strlen($entity);
        }
        if ($last < strlen($raw)) {
            $segments[] = [strlen($decoded), strlen($decoded) + strlen($raw) - $last, $last, strlen($raw), true];
            $decoded .= substr($raw, $last);
        }
        $toRaw = static function (int $d, bool $end) use ($segments): ?int {
            foreach ($segments as [$ds, $de, $rs, $re, $literal]) {
                if ($d === $ds && !$end) {
                    return $rs;
                }
                if ($d === $de && $end) {
                    return $re;
                }
                if ($d > $ds && $d < $de) {
                    return $literal ? $rs + ($d - $ds) : null;
                }
            }
            return null;
        };
        $out = [];
        for ($at = strpos($decoded, $old); $at !== false; $at = strpos($decoded, $old, $at + strlen($old))) {
            $start = $toRaw($at, false);
            $end = $toRaw($at + strlen($old), true);
            if ($start === null || $end === null) {
                return null;
            }
            $out[] = [$start, $end];
        }
        return $out;
    }

    /**
     * What an inspection reports for requested targets: per target, the anchors its old words sit
     * under and how often, with a short excerpt for a person to tell them apart.
     *
     * @param list<array{kind:string,name?:string,old:string}> $targets
     * @return list<array<string,mixed>>|null null when the page cannot be scanned
     */
    public static function candidates(string $html, array $targets): ?array
    {
        $page = self::scan($html);
        if ($page === null) {
            return null;
        }
        $out = [];
        foreach ($targets as $target) {
            $groups = [];
            $old = (string) $target['old'];
            if ($target['kind'] === 'text') {
                foreach ($page['texts'] as [$start, $end, $path]) {
                    $raw = substr($html, $start, $end - $start);
                    $found = self::textMatches($raw, $old);
                    if ($found === null || $found === []) {
                        continue;
                    }
                    $anchor = self::anchor('text', $path);
                    $groups[$anchor] = $groups[$anchor] ?? ['anchor' => $anchor, 'count' => 0, 'where' => self::where($path),
                        'excerpt' => self::excerpt(self::decode($raw))];
                    $groups[$anchor]['count'] += count($found);
                }
            } else {
                foreach ($page['hrefs'] as [, , $raw, $path]) {
                    if (self::decode($raw) !== $old) {
                        continue;
                    }
                    $anchor = self::anchor('attribute', $path);
                    $groups[$anchor] = $groups[$anchor] ?? ['anchor' => $anchor, 'count' => 0, 'where' => self::where($path)];
                    $groups[$anchor]['count']++;
                }
            }
            $out[] = ['kind' => $target['kind']] + ($target['kind'] === 'attribute' ? ['name' => 'href'] : [])
                + ['old' => $old, 'candidates' => array_slice(array_values($groups), 0, 16)];
        }
        return $out;
    }

    private static function where(string $path): string
    {
        return implode('/', array_slice(explode('/', $path), -3));
    }

    private static function excerpt(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        return preg_match('/^.{0,160}/su', $text, $m) === 1 ? $m[0] : '';
    }

    /**
     * The page with a scope's rules applied, or exactly as it was with the reasons why not. Every
     * rule is matched on the unmodified page before one byte moves.
     *
     * @param list<array<string,mixed>> $rules stored rules
     * @return array{html:string,applied:bool,problems:list<string>}
     */
    public static function render(string $html, array $rules): array
    {
        $unchanged = static fn(array $problems): array => ['html' => $html, 'applied' => false, 'problems' => $problems];
        if ($rules === []) {
            return ['html' => $html, 'applied' => false, 'problems' => []];
        }
        if (strlen($html) > self::MAX_HTML) {
            return $unchanged(['the page is larger than ' . self::MAX_HTML . ' bytes']);
        }
        $page = self::scan($html);
        if ($page === null) {
            return $unchanged(['the page markup could not be followed']);
        }
        $anchors = [];
        $anchorOf = static function (string $kind, string $path) use (&$anchors): string {
            return $anchors[$kind . '|' . $path] = $anchors[$kind . '|' . $path] ?? self::anchor($kind, $path);
        };
        $edits = [];
        $problems = [];
        foreach ($rules as $rule) {
            $found = [];
            if ($rule['kind'] === 'text') {
                foreach ($page['texts'] as [$start, $end, $path]) {
                    if ($anchorOf('text', $path) !== $rule['anchor']) {
                        continue;
                    }
                    $matches = self::textMatches(substr($html, $start, $end - $start), (string) $rule['old']);
                    if ($matches === null) {
                        $problems[] = 'rule ' . self::ruleId($rule) . ': its words sit inside an entity';
                        continue 2;
                    }
                    foreach ($matches as [$from, $to]) {
                        $found[] = [$start + $from, $start + $to, htmlspecialchars((string) $rule['new'], ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8')];
                    }
                }
            } else {
                foreach ($page['hrefs'] as [$start, $end, $raw, $path]) {
                    if ($anchorOf('attribute', $path) === $rule['anchor'] && self::decode($raw) === $rule['old']) {
                        $found[] = [$start, $end, '"' . htmlspecialchars((string) $rule['new'], ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8') . '"'];
                    }
                }
            }
            if (count($found) !== (int) $rule['expected_matches']) {
                $problems[] = 'rule ' . self::ruleId($rule) . ': expected ' . (int) $rule['expected_matches'] . ' match(es), found ' . count($found);
                continue;
            }
            array_push($edits, ...$found);
        }
        if ($problems !== []) {
            return $unchanged($problems);
        }
        usort($edits, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
        for ($i = 1; $i < count($edits); $i++) {
            if ($edits[$i][0] < $edits[$i - 1][1]) {
                return $unchanged(['two rules match overlapping words']);
            }
        }
        $out = $html;
        foreach (array_reverse($edits) as [$start, $end, $replacement]) {
            $out = substr_replace($out, $replacement, $start, $end - $start);
        }
        return ['html' => $out, 'applied' => true, 'problems' => []];
    }
}
