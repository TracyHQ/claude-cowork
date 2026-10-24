<?php
/**
 * Which navigation entries of a published WordPress quickstart are TEMPLATE SHOWCASE (home-page
 * variants, the kit's sample pages), and the reviewed edits that take them out of a bound site's
 * navigation: `showcase-nav-map.json`, next to the contract it was generated from.
 *
 * Pinned like a demo trim: `baseHash` is the base contract's bytes, so the map can never be applied to
 * a contract it was not generated from. An edit names its owner (`navigation-<id>`: a `wp_navigation`
 * post; `templatePart-<id>`: a template part, found by its slug in the active theme) and carries the
 * sha256 of the owner's WHOLE content before and after it, chained per owner in map order: the first
 * `before` of an owner is what the release shipped, the last `after` is what the site ends with.
 *
 * NEVER A POST STATUS. Every showcase page stays published and reachable; only the entry that links
 * it leaves a navigation. The operations are the three of `OPERATIONS`, and no other.
 */
final class ShowcaseNavProfile
{
    public const SCHEMA = 'tracy-quickstart-showcase-nav/wordpress/v1';
    public const OPERATIONS = ['navigation.removeLink', 'templatePart.setNavigationRef', 'templatePart.removeBlock'];
    private const FIELDS = ['op', 'key', 'before', 'after', 'label', 'path', 'contains', 'rule', 'reason',
        'navigation', 'link', 'templatePart', 'from', 'to', 'block'];
    private const SHA = '/^[0-9a-f]{64}$/D';

    private array $profile;
    private string $hash;
    /** @var array<string,array{key:string,kind:string,id:int,slug:string,postType:string,before:string,after:string,operations:list<array>}> */
    private array $owners = [];

    /**
     * @param array $lock the contract's presentation lock: a template part the map edits must be one
     *                    it pins whole, at the bytes the map starts from
     * @param string[] $governedParts template-part slugs the content map governs (slot by slot): never edited here
     */
    public function __construct(array $profile, string $directory, string $rawProfile, string $contractId, array $lock, array $governedParts)
    {
        $this->profile = $profile;
        $this->hash = hash('sha256', $rawProfile);
        if (($profile['schemaVersion'] ?? '') !== self::SCHEMA) {
            throw new RuntimeException('Unsupported showcase-navigation map');
        }
        if (($profile['contract'] ?? null) !== $contractId) {
            throw new RuntimeException('The showcase-navigation map names another contract');
        }
        if (!hash_equals(DemoTrimProfile::baseHash($directory), (string) ($profile['baseHash'] ?? ''))) {
            throw new RuntimeException('The showcase-navigation map does not belong to this contract');
        }
        $status = $profile['status'] ?? null;
        if ($status !== 'checked' && $status !== 'not-covered') {
            throw new RuntimeException('Showcase-navigation map status must be checked or not-covered');
        }
        if (!isset($profile['operations']) || !is_array($profile['operations']) || !self::isList($profile['operations'])) {
            throw new RuntimeException('Incomplete showcase-navigation map: operations');
        }
        if ($status === 'not-covered' && $profile['operations'] !== []) {
            throw new RuntimeException('A not-covered showcase-navigation map carries operations');
        }
        $pins = isset($lock['templateParts']) && is_array($lock['templateParts']) ? $lock['templateParts'] : [];
        foreach ($profile['operations'] as $row) {
            if (!is_array($row) || array_diff(array_keys($row), self::FIELDS) !== []) {
                throw new RuntimeException('Showcase-navigation operation carries a field it may not');
            }
            $key = (string) ($row['key'] ?? '');
            $op = (string) ($row['op'] ?? '');
            if (!in_array($op, self::OPERATIONS, true)) {
                throw new RuntimeException('Showcase-navigation operation is not one this receiver applies: ' . $op);
            }
            if (!preg_match('/^(navigation|templatePart)-([1-9][0-9]*)$/D', $key, $m) || strpos($op, $m[1] . '.') !== 0) {
                throw new RuntimeException('Showcase-navigation operation names the wrong owner: ' . $key);
            }
            $kind = $m[1];
            $owner = is_array($row[$kind] ?? null) ? $row[$kind] : [];
            $id = (int) $m[2];
            if (($owner['id'] ?? null) !== $id || !is_string($owner['slug'] ?? null) || $owner['slug'] === '') {
                throw new RuntimeException('Showcase-navigation operation does not name its owner as its key does: ' . $key);
            }
            foreach (['before', 'after'] as $end) {
                if (!is_string($row[$end] ?? null) || !preg_match(self::SHA, $row[$end])) {
                    throw new RuntimeException('Showcase-navigation operation ' . $key . ' has no ' . $end . ' sha256');
                }
            }
            if (!is_string($row['rule'] ?? null) || trim($row['rule']) === '' || !is_string($row['reason'] ?? null) || trim($row['reason']) === '') {
                throw new RuntimeException('Showcase-navigation operation has no rule or reason: ' . $key);
            }
            self::checkShape($op, $row, $key);
            if (!isset($this->owners[$key])) {
                if ($kind === 'templatePart') {
                    $slug = $owner['slug'];
                    if (in_array($slug, $governedParts, true)) {
                        throw new RuntimeException('Showcase-navigation operation edits a template part the content map governs: ' . $slug);
                    }
                    if (!is_array($pins[$slug] ?? null) || ($pins[$slug]['sha256'] ?? null) !== $row['before']) {
                        throw new RuntimeException('Showcase-navigation operation does not start from the locked bytes of template part ' . $slug);
                    }
                    foreach ($this->owners as $other) {
                        if ($other['kind'] === 'templatePart' && $other['slug'] === $slug) {
                            throw new RuntimeException('Showcase-navigation map names template part ' . $slug . ' twice');
                        }
                    }
                }
                $this->owners[$key] = [
                    'key' => $key, 'kind' => $kind, 'id' => $id, 'slug' => $owner['slug'],
                    'postType' => $kind === 'navigation' ? 'wp_navigation' : 'wp_template_part',
                    'before' => $row['before'], 'after' => $row['after'], 'operations' => [],
                ];
            } elseif ($this->owners[$key]['slug'] !== $owner['slug']) {
                throw new RuntimeException('Showcase-navigation operations name ' . $key . ' by two slugs');
            } elseif ($this->owners[$key]['after'] !== $row['before']) {
                // Each edit starts from the bytes the one before it left: the chain is the whole proof.
                throw new RuntimeException('Showcase-navigation operations of ' . $key . ' do not chain');
            }
            $this->owners[$key]['after'] = $row['after'];
            $this->owners[$key]['operations'][] = $row;
        }
        $finals = $profile['finals'] ?? [];
        if (!is_array($finals) || !self::isList($finals) || count($finals) !== count($this->owners)) {
            throw new RuntimeException('The showcase-navigation map finals do not list each owner once');
        }
        foreach ($finals as $final) {
            $owner = is_array($final) ? ($this->owners[(string) ($final['key'] ?? '')] ?? null) : null;
            if ($owner === null || ($final['before'] ?? null) !== $owner['before'] || ($final['after'] ?? null) !== $owner['after']) {
                throw new RuntimeException('The showcase-navigation map finals do not match its operations');
            }
        }
    }

    private static function checkShape(string $op, array $row, string $key): void
    {
        $text = static fn($v): bool => is_string($v) && $v !== '';
        $path = static fn($v): bool => is_array($v) && $v !== [] && self::isList($v) && array_filter($v, 'is_string') === $v;
        $pageId = static fn(array $a): bool => !array_key_exists('pageId', $a) || $a['pageId'] === null || (is_int($a['pageId']) && $a['pageId'] > 0);
        $ok = false;
        if ($op === 'navigation.removeLink') {
            $ok = $path($row['path'] ?? null) && is_array($row['link'] ?? null) && $pageId($row['link']);
        } elseif ($op === 'templatePart.setNavigationRef') {
            $ok = is_int($row['from'] ?? null) && $row['from'] > 0 && is_int($row['to'] ?? null) && $row['to'] > 0 && $row['from'] !== $row['to'];
        } else {
            $block = $row['block'] ?? null;
            if (is_array($block) && array_key_exists('path', $block)) {
                // An entry of an inline navigation is found by its path (and page): the builder names the
                // entry's block (`core/navigation-link`, `core/navigation-submenu`) only to say what it is,
                // and its own replay selects by path alone — so does this one, and the sha256 chain proves the bytes.
                $ok = $path($block['path']) && is_int($block['navigationBlock'] ?? null) && $block['navigationBlock'] >= 0 && $pageId($block)
                    && (!array_key_exists('name', $block) || $text($block['name']))
                    && array_diff(array_keys($block), ['name', 'navigationBlock', 'path', 'pageId', 'label']) === [];
            } elseif (is_array($block)) {
                // A block removed by name alone would be any block of that name: one more selector is required.
                $ok = $text($block['name'] ?? null) && array_diff(array_keys($block), ['name', 'ref', 'anchor', 'className', 'contains']) === []
                    && count($block) > 1 && (!isset($block['ref']) || is_int($block['ref']))
                    && (!isset($block['anchor']) || $text($block['anchor'])) && (!isset($block['className']) || $text($block['className']))
                    && (!isset($block['contains']) || $text($block['contains']));
            }
        }
        if (!$ok) {
            throw new RuntimeException('Showcase-navigation operation ' . $op . ' is malformed: ' . $key);
        }
    }

    /**
     * One owner's content after its edits, from the bytes it shipped with. Null when the edits do not
     * land on these bytes, or land somewhere else than the map says (an `after` sha256 that differs).
     */
    public static function edit(array $owner, string $content): ?string
    {
        foreach ($owner['operations'] as $op) {
            if (!hash_equals($op['before'], hash('sha256', $content))) {
                return null;
            }
            try {
                if ($op['op'] === 'navigation.removeLink') {
                    $content = ShowcaseNavMarkup::removeLink($content, $op['path'], array_key_exists('pageId', $op['link']), $op['link']['pageId'] ?? null);
                } elseif ($op['op'] === 'templatePart.setNavigationRef') {
                    $content = ShowcaseNavMarkup::setNavigationRef($content, $op['from'], $op['to']);
                } elseif (array_key_exists('path', $op['block'])) {
                    $block = $op['block'];
                    $content = ShowcaseNavMarkup::removeInlineLink($content, $block['navigationBlock'], $block['path'], array_key_exists('pageId', $block), $block['pageId'] ?? null);
                } else {
                    $content = ShowcaseNavMarkup::removeBlock($content, $op['block']);
                }
            } catch (RuntimeException $e) {
                return null;
            }
            if (!hash_equals($op['after'], hash('sha256', $content))) {
                return null;
            }
        }
        return $content;
    }

    private static function isList($value): bool
    {
        return is_array($value) && array_keys($value) === range(0, count($value) - 1) || $value === [];
    }

    public function hash(): string
    {
        return $this->hash;
    }

    public function version(): string
    {
        return (string) ($this->profile['profileVersion'] ?? '');
    }

    public function covered(): bool
    {
        return $this->profile['status'] === 'checked';
    }

    public function reason(): string
    {
        return is_string($this->profile['reason'] ?? null) ? $this->profile['reason'] : '';
    }

    /** @return array<string,array{key:string,kind:string,id:int,slug:string,postType:string,before:string,after:string,operations:list<array>}> in map order */
    public function owners(): array
    {
        return $this->owners;
    }

    /** @return array<string,int> edits per operation, for a status that says what an apply would do */
    public function counts(): array
    {
        $out = [];
        foreach ($this->owners as $owner) {
            foreach ($owner['operations'] as $op) {
                $out[$op['op']] = ($out[$op['op']] ?? 0) + 1;
            }
        }
        ksort($out);
        return $out;
    }
}
