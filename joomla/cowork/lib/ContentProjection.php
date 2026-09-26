<?php
require_once __DIR__ . '/ContentReader.php';
require_once __DIR__ . '/JoomlaLocks.php';
/**
 * The mapped-content projection: CMS rows in, `content.read` contents out. No Joomla globals, no
 * database and no writes, so the contract door can compute the SAME revisions the reader served.
 *
 * 🔒 ONE PROJECTION, TWO READERS. `content.read` serves `contents[].revision` and `content.contract`
 * apply checks `expected_content_revisions` against it. Two copies of this code is how an agent's
 * freshly read revision starts being refused as stale (or a stale one accepted) with nothing red.
 */
final class ContentProjection
{
    /** Tables the projection reads, each with the order it is read in. */
    public const TABLES = ['menu' => 'id', 'modules' => 'id', 'modules_menu' => 'moduleid,menuid', 'categories' => 'id',
        'viewlevels' => 'id', 'associations' => 'context,id', 'claudecowork_content_identity' => 'kind,native_id'];

    /** Opaque, site-scoped ids: `<domain>_<32 hex>`. */
    public static function opaque(string $siteId): Closure
    {
        return fn(string $domain, string $key) => $domain . '_' . substr(hash_hmac('sha256', $domain . ':' . $key, $siteId), 0, 32);
    }

    /**
     * One content's revision: its projection (without the revision field itself) and the contract
     * it was projected under. Nothing outside the content enters it, so an edit to one content
     * leaves every other content's revision alone.
     */
    public static function revision(array $content, string $contractHash): string
    {
        unset($content['revision']);
        return hash('sha256', ContentReader::encode([$content, $contractHash]));
    }

    /**
     * The address a visitor sees, instead of the `index.php?Itemid=` form the projection is hashed
     * with. A customer pastes `/ru/about-us`; the agent has to find that page by it (Tracy
     * `content.read {view:"pages", url}`). Applied AFTER the revisions are computed and never inside
     * `build`: the contract door hashes the same projection without a router, so a revision must not
     * depend on the address. `$route(kind, nativeId)` answers the routed path (with the site's base
     * path, as Joomla's own relative route has it), false for a row that is no page of this site (its
     * address becomes null), or null; a failure keeps the address the reader had.
     */
    public static function addresses(array $contents, string $base, callable $route): array
    {
        $parts = parse_url($base) ?: [];
        $origin = isset($parts['scheme'], $parts['host'])
            ? $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '')
            : rtrim($base, '/');
        foreach ($contents as $id => $content) {
            $url = $content['url'] ?? null;
            if (!is_string($url)) continue;
            if (preg_match('~[?&]Itemid=(\d+)$~', $url, $m)) $asked = ['page', (int) $m[1]];
            elseif (preg_match('~option=com_content&view=article&id=(\d+)$~', $url, $m)) $asked = ['article', (int) $m[1]];
            else continue;
            try {
                $path = $route($asked[0], $asked[1]);
            } catch (\Throwable $e) {
                $path = null;
            }
            if ($path === false) $contents[$id]['url'] = null;
            elseif (is_string($path) && $path !== '' && $path[0] === '/') $contents[$id]['url'] = $origin . $path;
        }
        return $contents;
    }

    /**
     * Who holds each content open in the Joomla editor (`lockedBy`, see JoomlaLocks), or null.
     * Applied AFTER the revisions, like `addresses`: a check-out is who is looking, not what the
     * content says, and the contract door hashes the same projection without asking — so opening
     * a record in the editor must never move its revision and turn an agent's fresh read stale.
     *
     * A content is locked by the first live lock among the rows it is read from, its own row first:
     * a page's menu item, then the section modules inlined into it (their slots are written through
     * that page, and an apply to them is refused while the module is open).
     *
     * @param array<string,list<array{0:string,1:int}>> $rows {@see build()} `rows`
     * @param callable(list<array{0:string,1:int}>):array<string,array> $lockOf every row at once => "kind:id" => lockedBy
     */
    public static function locks(array $contents, array $rows, callable $lockOf): array
    {
        $targets = [];
        foreach ($contents as $id => $content) foreach ($rows[$id] ?? [] as $row) $targets[JoomlaLocks::key($row[0], $row[1])] = $row;
        $held = $targets ? $lockOf(array_values($targets)) : [];
        foreach ($contents as $id => $content) {
            $contents[$id]['lockedBy'] = null;
            foreach ($rows[$id] ?? [] as $row)
                if (isset($held[JoomlaLocks::key($row[0], $row[1])])) { $contents[$id]['lockedBy'] = $held[JoomlaLocks::key($row[0], $row[1])]; break; }
        }
        return $contents;
    }

    public static function date($value): ?string
    {
        return !$value || substr($value, 0, 4) === '0000' ? null : gmdate('Y-m-d\TH:i:s\Z', strtotime($value . ' UTC'));
    }

    private static function visible(array $row, array $levels, int $now, string $kind): bool
    {
        if (!in_array((int)($row['access'] ?? 0), $levels, true) || (int)($row[$kind === 'article' ? 'state' : 'published'] ?? 0) !== 1) return false;
        foreach (['publish_up' => true, 'publish_down' => false] as $key => $up) {
            $at = self::date($row[$key] ?? null);
            if ($at && ($up ? strtotime($at) > $now : strtotime($at) <= $now)) return false;
        }
        return true;
    }

    /**
     * @param array<string,list<array>> $data rows of every table in {@see TABLES}
     * @param array $mapping QuickstartContract::readMapping()
     * @param callable(array,array):string $slotValue QuickstartContract::slotValue
     * @return array{contents:array<string,array>,revisions:array<string,string>,owners:array<string,string>,locales:list<string>,rows:array<string,list<array{0:string,1:int}>>}
     *   `contents` carry `revision: 'pending'` (the snapshot revision is hashed over that shape);
     *   `owners` maps each projected contract entity key to the content its slots are read in;
     *   `rows` names, per content, the native rows (writer kind, id) it is read from, its own first.
     */
    public static function build(array $data, array $mapping, string $siteId, string $base, int $now, callable $slotValue): array
    {
        $base = rtrim($base, '/');
        $levels = [];
        foreach ($data['viewlevels'] as $row) if (in_array(1, json_decode($row['rules'], true) ?? [], true)) $levels[] = (int)$row['id'];
        $identities = [];
        foreach ($data['claudecowork_content_identity'] as $row) $identities[$row['kind']][(int)$row['native_id']] = $row['uid'];
        $opaque = self::opaque($siteId);
        $contents = []; $keys = []; $locales = []; $native = []; $contractKey = []; $rowsOf = [];
        $categories = array_column($data['categories'], null, 'id');
        $menus = array_column($data['menu'], null, 'id');
        // With a home per language, the language filter sends every visitor to one of them: the
        // "All languages" home is never the page anyone reads. Left in the map it sat beside the
        // real homes as a three-block "Home" and the agent opened it first (25/09, capijl1644).
        $languageHomes = count(array_filter($data['menu'], fn($m) => (int)($m['home'] ?? 0) === 1 && ($m['language'] ?? '*') !== '*' && (int)($m['published'] ?? 0) === 1 && (int)($m['client_id'] ?? 0) === 0));
        foreach ($mapping['keys'] as $key => $meta) {
            $kind = ['article' => 'article', 'menuItem' => 'page', 'module' => 'shared'][$meta['kind']] ?? null;
            if ($kind === null || !empty($meta['switcher'])) continue;
            $row = $mapping['rows'][$key]; $nativeId = (int)$mapping['ids'][$key];
            $allowed = self::visible($row, $levels, $now, $kind);
            if ($kind === 'article') {
                $cat = $categories[$row['catid']] ?? null;
                $seen = [];
                while ($cat && (int)$cat['id'] > 1) {
                    if (isset($seen[$cat['id']])) throw new RuntimeException('Category cycle');
                    $seen[$cat['id']] = true;
                    if ((int)$cat['published'] !== 1 || !in_array((int)$cat['access'], $levels, true)) $allowed = false;
                    $cat = $categories[$cat['parent_id']] ?? null;
                }
            }
            if ($kind === 'page') {
                $parent = $menus[$row['parent_id']] ?? null; $seen = [];
                while ($parent && (int)$parent['id'] > 1) {
                    if (isset($seen[$parent['id']])) throw new RuntimeException('Menu cycle');
                    $seen[$parent['id']] = true;
                    if (!self::visible($parent, $levels, $now, 'page')) $allowed = false;
                    $parent = $menus[$parent['parent_id']] ?? null;
                }
            }
            if ($kind === 'page' && $languageHomes > 0 && (int)($row['home'] ?? 0) === 1 && ($row['language'] ?? '*') === '*') $allowed = false;
            if (!$allowed) continue;
            $uid = $identities[$kind][$nativeId] ?? null;
            if (!$uid) throw new ContentReadError('CONTENT_ADAPTER_UNSUPPORTED', 501, 'Content identity registry is incomplete');
            $id = $opaque('content', $uid); $keys[$key] = $id; $native[$kind][$nativeId] = $id; $contractKey[$id] ??= $key;
            if (isset($contents[$id])) continue;
            $rowsOf[$id] = [[$meta['kind'], $nativeId]];
            $locale = ($row['language'] ?? '*') === '*' ? null : $row['language'];
            // Joomla's legacy sr-YU is not a canonical language tag. Do not silently relabel it.
            if ($locale === 'sr-YU') $locale = null;
            if ($locale !== null) $locales[$locale] = true;
            $url = $kind === 'page' ? $base . '/index.php?Itemid=' . $nativeId : ($kind === 'article' ? $base . '/index.php?option=com_content&view=article&id=' . $nativeId : null);
            $content = ['id' => $id, 'type' => $kind, 'title' => $row['title'] ?? null, 'slug' => $row['alias'] ?? null, 'url' => $url, 'locale' => $locale, 'translationGroupId' => null, 'summary' => null,
                'publishedAt' => self::date($row['publish_up'] ?? null), 'createdAt' => self::date($row['created'] ?? null), 'updatedAt' => self::date($row['modified'] ?? null),
                'revision' => 'pending', 'detailState' => 'complete', 'links' => ['self' => $base . '/content.json?id=' . $id],
                'publication' => ['status' => 'published', 'valueSource' => 'current', 'scheduledAt' => null],
                'bodyHtml' => $kind === 'article' ? ($row['introtext'] ?? '') . ($row['fulltext'] ?? '') : ($kind === 'shared' && ($row['module'] ?? '') === 'mod_custom' ? ($row['content'] ?? '') : null),
                'tags' => [], 'fields' => [], 'blocks' => [], 'images' => [], 'relations' => []];
            $fields = [];
            // 🔒 A FIELD SAYS WHAT IT IS. A slot key is positional (`module-441.9`); its name lives in the
            // contract's jsonPath (`tb-hero[image-alt]`). Without it the agent could not tell the
            // picture's alt from any other text and ran the contract inspect only to read labels — on
            // the dev host 60–80 s a call (25/09/2026, local agent chat on r1j1734, 3 of 3 runs).
            $semantic = function (array $slot): ?string {
                $path = $slot['jsonPath'] ?? null;
                if (is_array($path) && isset($path[1]) && is_string($path[1]) && preg_match('/\[([^\]]+)\]$/', $path[1], $m))
                    return $m[1] . ((int)($path[2] ?? 0) > 0 ? '.' . (int)$path[2] : '');
                return isset($slot['column']) && is_string($slot['column']) && $slot['column'] !== 'params' ? $slot['column'] : null;
            };
            $alts = [];
            foreach ($mapping['slots'][$key] as $slot) {
                $name = $semantic($slot);
                if ($name !== null && preg_match('/^(.*)-alt(\.\d+)?$/', $name, $m)) $alts[$m[1] . ($m[2] ?? '')] = $slotValue($row, $slot);
            }
            foreach ($mapping['slots'][$key] as $slot) {
                $value = $slotValue($row, $slot);
                $fields[] = ['key' => $slot['key'], 'type' => $slot['type'], 'value' => $value, 'slotKey' => $slot['key'], 'semanticKey' => $semantic($slot)];
                if ($slot['type'] === 'image' && $value !== '') {
                    $src = preg_match('~^https?://~', $value) ? $value : $base . '/' . ltrim($value, '/');
                    $mid = $opaque('media', $value);
                    // The slot is its own block below (same opaque key), so the picture says which
                    // block it belongs to. Alt stays null: a contract slot carries no alt of its own.
                    $block = $opaque('block', $uid . ':' . $slot['key']);
                    $alt = $alts[$semantic($slot) ?? ''] ?? null;
                    if (isset($content['images'][$mid])) $content['images'][$mid]['usages'][] = ['contentId' => $id, 'blockId' => $block, 'itemId' => null];
                    else $content['images'][$mid] = ['id' => $mid, 'src' => $src, 'alt' => is_string($alt) ? $alt : null, 'width' => null, 'height' => null, 'usages' => [['contentId' => $id, 'blockId' => $block, 'itemId' => null]]];
                }
            }
            // Physical slots are fields, never manufactured repeater item identities. Bounded
            // chunks contain stable slot keys; their IDs are based on keys, not array position.
            foreach ($fields as $field) $content['blocks'][] = ['id' => $opaque('block', $uid . ':' . $field['key']), 'key' => $field['key'], 'role' => null, 'position' => count($content['blocks']),
                'sharedContentId' => null, 'visibility' => 'unknown', 'fields' => [$field], 'items' => []];
            $content['images'] = array_values($content['images']);
            $contents[$id] = $content;
        }
        // Relations only from CMS foreign keys/associations. Assignment is a candidate occurrence,
        // never evidence that a layout actually rendered a module.
        $moduleRows = array_column($data['modules'], null, 'id');
        $assignments = $data['modules_menu'];
        usort($assignments, fn($a, $b) => [(int)$moduleRows[$a['moduleid']]['ordering'], (int)$a['moduleid'], (int)$a['menuid']] <=> [(int)$moduleRows[$b['moduleid']]['ordering'], (int)$b['moduleid'], (int)$b['menuid']]);
        foreach ($assignments as $assignment) {
            $source = $native['shared'][(int)$assignment['moduleid']] ?? null;
            if (!$source) continue;
            foreach ($native['page'] ?? [] as $menuId => $owner) {
                $menu = (int)$assignment['menuid'];
                if ($menu !== 0 && $menu !== $menuId) continue;
                if ($contents[$source]['locale'] !== null && $contents[$owner]['locale'] !== $contents[$source]['locale']) continue;
                $blockId = $opaque('occurrence', $owner . ':' . $source);
                if (in_array($blockId, array_column($contents[$owner]['blocks'], 'id'), true)) continue;
                $contents[$owner]['blocks'][] = ['id' => $blockId, 'key' => $contractKey[$source] ?? $blockId, 'role' => $contents[$source]['title'], 'position' => count($contents[$owner]['blocks']),
                    'sharedContentId' => $source, 'visibility' => 'unknown', 'fields' => [], 'items' => []];
            }
        }
        // 🔒 A MODULE ONE PAGE PLACES IS THAT PAGE'S SECTION, NOT A SHARED PART. A Tracy Business
        // home is a list of modules (hero, services, clients…); as references the agent had to open
        // each one to see a word or a picture — 10 to 13 calls where WordPress takes 5 (25/09, local
        // agent chat on capijl1644). Such a module is projected inline: the page block carries its
        // fields (slotKey included) and its pictures point at that block. Modules placed on several
        // pages — header, footer, topbar — stay shared references, read once.
        $placements = [];
        foreach ($contents as $ownerId => $owner) foreach ($owner['blocks'] as $block)
            if ($block['sharedContentId'] !== null) $placements[$block['sharedContentId']][$ownerId] = true;
        foreach ($placements as $source => $owners) {
            if (count($owners) !== 1 || ($contents[$source]['type'] ?? null) !== 'shared') continue;
            $ownerId = array_key_first($owners); $module = $contents[$source];
            $fields = []; foreach ($module['blocks'] as $block) foreach ($block['fields'] as $field) $fields[] = $field;
            $images = array_column($contents[$ownerId]['images'], null, 'id');
            foreach ($contents[$ownerId]['blocks'] as &$block) {
                if ($block['sharedContentId'] !== $source) continue;
                $block['sharedContentId'] = null; $block['fields'] = $fields;
                foreach ($module['images'] as $image) {
                    $usage = ['contentId' => $ownerId, 'blockId' => $block['id'], 'itemId' => null];
                    if (isset($images[$image['id']])) $images[$image['id']]['usages'][] = $usage;
                    else { $image['usages'] = [$usage]; $images[$image['id']] = $image; }
                }
            }
            unset($block);
            $contents[$ownerId]['images'] = array_values($images);
            unset($contents[$source]);
            $rowsOf[$ownerId] = array_merge($rowsOf[$ownerId], $rowsOf[$source]); unset($rowsOf[$source]);
            // The inlined module's slots are now read, and so revised, in the page that carries them.
            foreach ($keys as $key => $id) if ($id === $source) $keys[$key] = $ownerId;
        }
        $groups = [];
        foreach ($data['associations'] as $association) {
            $kind = ['com_content.item' => 'article', 'com_menus.item' => 'page'][$association['context']] ?? null;
            $id = $kind === null ? null : ($native[$kind][(int)$association['id']] ?? null);
            if ($id && $contents[$id]['locale'] !== null) $groups[$association['context'] . ':' . $association['key']][] = $id;
        }
        foreach ($groups as $group => $members) {
            if (count($members) < 2 || count(array_unique(array_map(fn($id) => $contents[$id]['locale'], $members))) !== count($members)) continue;
            sort($members);
            foreach ($members as $id) {
                $contents[$id]['translationGroupId'] = $opaque('translation', implode(':', $members));
                foreach ($members as $other) if ($id !== $other) $contents[$id]['relations'][] = ['type' => 'translation', 'contentId' => $other];
            }
        }
        ksort($contents);
        $revisions = [];
        foreach ($contents as $id => $content) $revisions[$id] = self::revision($content, $mapping['contractHash']);
        $localeList = array_keys($locales); sort($localeList);
        return ['contents' => $contents, 'revisions' => $revisions, 'owners' => $keys, 'locales' => $localeList, 'rows' => $rowsOf];
    }
}
