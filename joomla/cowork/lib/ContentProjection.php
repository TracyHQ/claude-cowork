<?php
require_once __DIR__ . '/ContentReader.php';
require_once __DIR__ . '/JoomlaLocks.php';
require_once __DIR__ . '/SiteWriter.php';
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
    /**
     * Tables the projection reads, each with the order it is read in. The last four feed what an
     * article prints beside its own columns (custom field values, tags); both readers load them, so
     * both hash the same projection. A caller that leaves them out gets articles without those
     * fields, never an error — {@see build()} reads each with `?? []`.
     */
    public const TABLES = ['menu' => 'id', 'modules' => 'id', 'modules_menu' => 'moduleid,menuid', 'categories' => 'id',
        'viewlevels' => 'id', 'associations' => 'context,id', 'claudecowork_content_identity' => 'kind,native_id',
        'fields' => 'id', 'fields_values' => 'field_id,item_id', 'tags' => 'id', 'contentitem_tag_map' => 'type_alias,content_item_id,tag_id'];

    /**
     * Custom field types whose stored value is the words a visitor reads: text as typed, an editor's
     * HTML, a media field's picture. A list, radio or checkbox value is an option KEY (its label
     * lives in the field's params, per language), a subform is a JSON repeater, a calendar is a date
     * the layout formats — none of them is the printed text, so none is offered as if it were.
     */
    private const FIELD_TYPES = ['text' => 'text', 'textarea' => 'text', 'editor' => 'html', 'media' => 'image'];

    /**
     * Menu item params that are settings, whatever their value looks like: metadata a visitor
     * never sees on the page, and switches, classes, icons, links and layout choices.
     */
    private const SETTING_PARAM = '/^(menu-meta_|robots$)|(^|[-_])(css|class|icon|image|link|url|href|target|layout|order|style|rel)($|[-_])/i';

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

    /**
     * The Joomla rows each content is made of, as `native: [{kind, id}]` in the kinds content.get,
     * content.update and content.delete take (`menuItem`, `article`, `module`). Without it the agent
     * held a page's opaque id and ran an inspect or a content.list only to learn which row to write.
     * Applied AFTER the revisions, like {@see locks()}: a row id never changes what a content says,
     * and a revision that moved on upgrade would read as a change to every page.
     *
     * @param array<string,list<array{0:string,1:int}>> $rows {@see build()} `rows`
     */
    public static function natives(array $contents, array $rows): array
    {
        foreach ($contents as $id => $content) {
            $native = [];
            foreach ($rows[$id] ?? [] as [$kind, $nativeId]) $native[$kind . ':' . $nativeId] = ['kind' => $kind, 'id' => (int) $nativeId];
            $contents[$id]['native'] = array_values($native);
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
     * One field this projection adds beside the contract's slots: no slot key (it is not a contract
     * slot, so `content.contract` cannot write it) and a semantic key naming what it is. No
     * `writable`: in the Content API that means "writable through content.contract apply", which
     * Tracy's relay decides from the slot key. Which of these fields `content.update` can change on
     * the record's native row, and which are read-only, is stated per group in {@see extraBlocks()}
     * and in the README.
     */
    private static function extraField(string $key, string $type, string $value, string $semantic): array
    {
        return ['key' => $key, 'type' => $type, 'value' => $value, 'slotKey' => null, 'semanticKey' => $semantic];
    }

    /** A stored image value as a visitor's browser asks for it: Joomla's `#joomlaImage://…` suffix off. */
    private static function mediaValue(string $value): string
    {
        return trim(explode('#', $value, 2)[0]);
    }

    private static function mediaSrc(string $value, string $base): string
    {
        return preg_match('~^https?://~', $value) ? $value : $base . '/' . ltrim($value, '/');
    }

    /** Whether a row is published to the public audience ($levels) now: state, access, window. */
    private static function isPublic(array $row, array $levels, string $stateColumn): bool
    {
        return (int)($row[$stateColumn] ?? 0) === 1 && in_array((int)($row['access'] ?? 0), $levels, true);
    }

    /**
     * Everything {@see extraBlocks()} and {@see taxonomyContents()} look up, indexed once.
     *
     * @return array{levels:list<int>,fields:array<int,array>,values:array<int,array<int,string>>,
     *   tags:array<int,array>,tagsOf:array<int,list<int>>,articleTags:array<int,list<string>>,
     *   users:array<int,string>,megamenu:list<array>,categories:array<int,array>,
     *   articleCategory:array<int,int>,pageCategories:array<int,true>}
     */
    private static function extrasContext(array $data, array $levels): array
    {
        $fields = [];
        foreach ($data['fields'] ?? [] as $field)
            if (($field['context'] ?? '') === 'com_content.article' && isset(self::FIELD_TYPES[$field['type'] ?? '']) && self::isPublic($field, $levels, 'state'))
                $fields[(int)$field['id']] = $field;
        $values = [];
        foreach ($data['fields_values'] ?? [] as $value)
            if (isset($fields[(int)$value['field_id']])) $values[(int)$value['item_id']][(int)$value['field_id']] = (string)$value['value'];
        $tags = [];
        foreach ($data['tags'] ?? [] as $tag) if ((int)$tag['id'] > 1 && self::isPublic($tag, $levels, 'published')) $tags[(int)$tag['id']] = $tag;
        $tagsOf = []; $articleTags = [];
        foreach ($data['contentitem_tag_map'] ?? [] as $map)
            if (($map['type_alias'] ?? '') === 'com_content.article' && isset($tags[(int)$map['tag_id']])) $tagsOf[(int)$map['content_item_id']][] = (int)$map['tag_id'];
        foreach ($tagsOf as $item => $ids) {
            $names = array_map(fn($tid) => (string)$tags[$tid]['title'], $ids);
            sort($names); $articleTags[$item] = array_values(array_unique($names));
        }
        $users = [];
        foreach ($data['users'] ?? [] as $user) $users[(int)$user['id']] = (string)$user['name'];
        return ['levels' => $levels, 'fields' => $fields, 'values' => $values, 'tags' => $tags, 'tagsOf' => $tagsOf, 'articleTags' => $articleTags,
            'users' => $users, 'megamenu' => $data['megamenu'] ?? [], 'categories' => array_column($data['categories'], null, 'id'),
            'articleCategory' => [], 'pageCategories' => []];
    }

    /**
     * What a Joomla row prints that no contract slot holds — each group one block, keyed by the
     * row's NATIVE id (`article-548.images`), because a render stamp names the native id
     * (`article:548`) and Tracy's resolver ties a stamp to the block whose key starts with it.
     *
     * - article: `article-<id>.images` — the intro and full-text pictures (`image_intro`,
     *   `image_fulltext`, with `_caption`; alt text travels on the picture in `images[]`, not as a
     *   field, since it usually repeats the title); `article-<id>.fields` — published custom field
     *   values (`field.<name>`, read-only: Joomla keeps them outside the article row);
     *   `article-<id>.author` — the author's display name (`created_by_alias`, else the user's name;
     *   read-only).
     * - page: `menuItem-<id>.params` — menu item params written as words (`params.<name>`: a page
     *   heading, a call-to-action label a template reads from the menu item) — and
     *   `menuItem-<id>.megamenu` — what a T4 mega menu prints for the item (read-only: T4 keeps it
     *   in the template's navigation file, not in the database).
     *
     * @return list<array{key:string,role:string,fields:list<array>,images:list<array{0:string,1:?string}>}>
     */
    private static function extraBlocks(string $kind, int $id, array $row, array $x): array
    {
        $out = [];
        if ($kind === 'article') {
            $images = json_decode((string)($row['images'] ?? ''), true);
            $fields = []; $pictures = [];
            foreach (['image_intro', 'image_fulltext'] as $slot) {
                $value = self::mediaValue((string)(is_array($images) ? ($images[$slot] ?? '') : ''));
                if ($value === '') continue;
                $alt = is_array($images) && is_string($images[$slot . '_alt'] ?? null) && $images[$slot . '_alt'] !== '' ? $images[$slot . '_alt'] : null;
                $fields[] = self::extraField("article-$id.$slot", 'image', $value, $slot);
                $caption = is_array($images) ? (string)($images[$slot . '_caption'] ?? '') : '';
                if ($caption !== '') $fields[] = self::extraField("article-$id.{$slot}_caption", 'text', $caption, $slot . '_caption');
                if (!in_array($value, array_column($pictures, 0), true)) $pictures[] = [$value, $alt];
            }
            if ($fields) $out[] = ['key' => "article-$id.images", 'role' => 'images', 'fields' => $fields, 'images' => $pictures];
            $fields = []; $pictures = [];
            foreach ($x['values'][$id] ?? [] as $fieldId => $value) {
                $field = $x['fields'][$fieldId];
                $language = (string)($field['language'] ?? '*');
                if ($language !== '*' && $language !== (string)($row['language'] ?? '*')) continue;
                $type = self::FIELD_TYPES[$field['type']];
                if ($type === 'image') {
                    // Joomla 4+ stores a media field as JSON ({imagefile, alt_text}); older values are a bare path.
                    $media = json_decode($value, true);
                    $value = self::mediaValue(is_array($media) ? (string)($media['imagefile'] ?? '') : $value);
                    if ($value !== '') $pictures[] = [$value, is_array($media) && ($media['alt_text'] ?? '') !== '' ? (string)$media['alt_text'] : null];
                }
                if (trim($value) === '') continue;
                $fields[] = self::extraField("article-$id.field." . $field['name'], $type, $value, (string)$field['name']);
            }
            if ($fields) $out[] = ['key' => "article-$id.fields", 'role' => 'custom fields', 'fields' => $fields, 'images' => $pictures];
            $author = trim((string)($row['created_by_alias'] ?? ''));
            if ($author === '') $author = $x['users'][(int)($row['created_by'] ?? 0)] ?? '';
            if ($author !== '') $out[] = ['key' => "article-$id.author", 'role' => 'author', 'fields' => [self::extraField("article-$id.author", 'text', $author, 'author')], 'images' => []];
        }
        if ($kind === 'menuItem') {
            $params = json_decode((string)($row['params'] ?? ''), true);
            $fields = [];
            foreach (is_array($params) ? $params : [] as $name => $value)
                if (is_string($name) && self::isProse($value) && !preg_match(self::SETTING_PARAM, $name))
                    $fields[] = self::extraField("menuItem-$id.params.$name", 'text', $value, $name);
            if ($fields) $out[] = ['key' => "menuItem-$id.params", 'role' => 'menu item params', 'fields' => $fields, 'images' => []];
            $fields = self::megamenuFields($id, (string)($row['menutype'] ?? ''), $x['megamenu']);
            if ($fields) $out[] = ['key' => "menuItem-$id.megamenu", 'role' => 'mega menu', 'fields' => $fields, 'images' => []];
        }
        return $out;
    }

    /**
     * Whether a param value reads as words a page prints, not a setting: a string with a letter in
     * it, containing a space or starting with a capital ("Need advice on …", "All", "Next →"), and
     * shaped like neither markup, JSON, an address nor a path. Settings are lower-case tokens
     * (`blog`, `rdate`, `published`). A HEURISTIC, named so: Joomla keeps a template's own menu
     * params without any form saying which are text (the Tracy Business ones are seeded, not
     * declared), so the shape of the value is all there is to go on.
     */
    private static function isProse($value): bool
    {
        if (!is_string($value) || $value === '' || strlen($value) > 2000 || !preg_match('/\p{L}/u', $value)) return false;
        if (preg_match('~^([{\[<#/]|https?:|mailto:|tel:|index\.php)~i', trim($value))) return false;
        return preg_match('/\s/u', trim($value)) === 1 || preg_match('/^\p{Lu}/u', $value) === 1;
    }

    /**
     * What a T4 mega menu prints for one menu item: its caption (`megamenu[<template>:<profile>].caption`,
     * the line under the item's title) and the titles of its mega columns
     * (`megamenu[<template>:<profile>].column.<row>.<col>` — "Marketing Kit", "Recent projects").
     * T4 keeps these per navigation profile, keyed by menu type and item id, in the template's
     * `etc/navigation/<profile>.json` (a `local/` copy wins); the Joomla reader hands in every
     * profile a site template style uses, so a key names the template and profile it came from.
     * Column keys are positional, like the contract's own slot keys: T4 gives a column no id.
     *
     * @param list<array{template:string,profile:string,settings:array}> $profiles
     */
    private static function megamenuFields(int $id, string $menutype, array $profiles): array
    {
        $fields = [];
        foreach ($profiles as $profile) {
            $item = $profile['settings'][$menutype][(string)$id] ?? $profile['settings'][$menutype][$id] ?? null;
            if (!is_array($item)) continue;
            $prefix = "menuItem-$id.megamenu[" . $profile['template'] . ':' . $profile['profile'] . ']';
            if (is_string($item['caption'] ?? null) && trim($item['caption']) !== '') $fields[] = self::extraField("$prefix.caption", 'text', $item['caption'], 'caption');
            foreach (is_array($item['settings'] ?? null) ? array_values($item['settings']) : [] as $r => $settingsRow)
                foreach (is_array($settingsRow['contents'] ?? null) ? array_values($settingsRow['contents']) : [] as $c => $column)
                    if (is_array($column) && is_string($column['title'] ?? null) && trim($column['title']) !== '')
                        $fields[] = self::extraField("$prefix.column.$r.$c", 'text', $column['title'], 'megaColumn');
        }
        return $fields;
    }

    /**
     * Records of their own for the categories and tags the readable articles live in: every
     * category an article or a page (a category view) points at, with its ancestors, and every
     * tag on an article — public and published, the same audience as the articles. Not every
     * category on the site: a 41-language quickstart ships hundreds, most in languages nobody reads.
     *
     * Each is a `shared` record, like a module: its name is printed across pages (breadcrumbs,
     * filters, an article's meta line) rather than being a page of its own. Its id is fixed by
     * the native id (`category:<id>` / `tag:<id>`, the ids Joomla never reuses), its title is the
     * record's title, and its description, picture and nothing else are the fields of one block
     * keyed `category-<id>` / `tag-<id>` — the block a render stamp `category:<id>` resolves to.
     * All three are written through `content.update` on the native row.
     *
     * @param array<int,string> $articles native article id => content id (the readable articles)
     * @return array<string,array{0:array,1:array{0:string,1:int}}> content id => [content, native row]
     */
    private static function taxonomyContents(array $x, array $articles, Closure $opaque, string $base): array
    {
        $wanted = $x['pageCategories'];
        foreach (array_keys($articles) as $articleId) if (isset($x['articleCategory'][$articleId])) $wanted[$x['articleCategory'][$articleId]] = true;
        $categories = [];
        foreach (array_keys($wanted) as $catId) {
            $chain = []; $cat = $x['categories'][$catId] ?? null; $public = true; $seen = [];
            while ($cat && (int)$cat['id'] > 1 && !isset($seen[$cat['id']])) {
                $seen[$cat['id']] = true;
                if (($cat['extension'] ?? '') !== 'com_content' || !self::isPublic($cat, $x['levels'], 'published')) $public = false;
                $chain[] = $cat;
                $cat = $x['categories'][$cat['parent_id']] ?? null;
            }
            // A category under an unpublished or non-public parent is not shown to the audience.
            if ($public) foreach ($chain as $one) $categories[(int)$one['id']] = $one;
        }
        $tags = [];
        foreach (array_keys($articles) as $articleId) foreach ($x['tagsOf'][$articleId] ?? [] as $tagId) $tags[$tagId] = $x['tags'][$tagId];
        ksort($categories); ksort($tags);
        $out = [];
        foreach (['category' => $categories, 'tag' => $tags] as $kind => $rows) foreach ($rows as $nativeId => $row) {
            $id = $opaque('content', $kind . ':' . $nativeId);
            $params = json_decode((string)($row['params'] ?? ''), true);
            $image = self::mediaValue(is_array($params) ? (string)($params['image'] ?? ($params['image_intro'] ?? '')) : '');
            $fields = [self::extraField("$kind-$nativeId.description", 'html', (string)($row['description'] ?? ''), 'description')];
            if ($image !== '') $fields[] = self::extraField("$kind-$nativeId.image", 'image', $image, 'image');
            $locale = ($row['language'] ?? '*') === '*' || $row['language'] === 'sr-YU' ? null : $row['language'];
            $blockId = $opaque('block', $kind . ':' . $nativeId);
            $content = ['id' => $id, 'type' => 'shared', 'title' => $row['title'] ?? null, 'slug' => $row['alias'] ?? null, 'url' => null, 'locale' => $locale,
                'translationGroupId' => null, 'summary' => null, 'publishedAt' => null,
                'createdAt' => self::date($row['created_time'] ?? null), 'updatedAt' => self::date($row['modified_time'] ?? null),
                'revision' => 'pending', 'detailState' => 'complete', 'links' => ['self' => $base . '/content.json?id=' . $id],
                'publication' => ['status' => 'published', 'valueSource' => 'current', 'scheduledAt' => null],
                'bodyHtml' => null, 'tags' => [], 'fields' => [],
                'blocks' => [['id' => $blockId, 'key' => "$kind-$nativeId", 'role' => $kind, 'position' => 0, 'sharedContentId' => null, 'visibility' => 'unknown', 'fields' => $fields, 'items' => []]],
                'images' => [], 'relations' => []];
            if ($image !== '') {
                $alt = is_array($params) && is_string($params['image_alt'] ?? null) && $params['image_alt'] !== '' ? $params['image_alt'] : null;
                $content['images'][] = ['id' => $opaque('media', $image), 'src' => self::mediaSrc($image, $base), 'alt' => $alt, 'width' => null, 'height' => null,
                    'usages' => [['contentId' => $id, 'blockId' => $blockId, 'itemId' => null]]];
            }
            if ($kind === 'category' && isset($categories[(int)$row['parent_id']]))
                $content['relations'][] = ['type' => 'parent', 'contentId' => $opaque('content', 'category:' . (int)$row['parent_id'])];
            $out[$id] = [$content, [$kind, (int)$nativeId]];
        }
        return $out;
    }

    /**
     * @param array<string,list<array>> $data rows of every table in {@see TABLES}, plus two things
     *   the Joomla reader gathers beside them ({@see JoomlaContentReader::supplement}): `users`
     *   (`id`, `name` of the articles' authors) and `megamenu` (the T4 navigation profiles in use,
     *   see {@see megamenuFields()}). Every one of the extra inputs may be absent.
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
        $extras = self::extrasContext($data, $levels);
        // With a home per language, the language filter sends every visitor to one of them: the
        // "All languages" home is never the page anyone reads. Left in the map it sat beside the
        // real homes as a three-block "Home" and the agent opened it first (25/09, capijl1644).
        $languageHomes = count(array_filter($data['menu'], fn($m) => (int)($m['home'] ?? 0) === 1 && ($m['language'] ?? '*') !== '*' && (int)($m['published'] ?? 0) === 1 && (int)($m['client_id'] ?? 0) === 0));
        // A derived contract (an imported site's own rows) also maps site template styles, which read
        // as shared contents under an identity of their own: a style and a module may share a number.
        $derived = ($mapping['manifest']['mode'] ?? '') === 'derived';
        $kinds = ['article' => 'article', 'menuItem' => 'page', 'module' => 'shared'] + ($derived ? ['templateStyle' => 'shared'] : []);
        foreach ($mapping['keys'] as $key => $meta) {
            $kind = $kinds[$meta['kind']] ?? null;
            if ($kind === null || !empty($meta['switcher'])) continue;
            $identity = $meta['kind'] === 'templateStyle' ? 'templateStyle' : $kind;
            $row = $mapping['rows'][$key]; $nativeId = (int)$mapping['ids'][$key];
            // A site template style has no audience of its own: it shows wherever the site does.
            $allowed = $identity === 'templateStyle' || self::visible($row, $levels, $now, $kind);
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
            if ($kind === 'article') $extras['articleCategory'][$nativeId] = (int)($row['catid'] ?? 0);
            if ($kind === 'page' && preg_match('~[?&]option=com_content&view=category(?:&[^&]*)*?&id=(\d+)~', (string)($row['link'] ?? ''), $m)) $extras['pageCategories'][(int)$m[1]] = true;
            $uid = $identities[$identity][$nativeId] ?? null;
            if (!$uid) throw new ContentReadError('CONTENT_ADAPTER_UNSUPPORTED', 501, 'Content identity registry is incomplete');
            $id = $opaque('content', $uid); $keys[$key] = $id; $native[$identity][$nativeId] = $id; $contractKey[$id] ??= $key;
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
            $semantic = function (array $slot) use ($derived): ?string {
                $path = $slot['jsonPath'] ?? null;
                if (is_array($path) && isset($path[1]) && is_string($path[1]) && preg_match('/\[([^\]]+)\]$/', $path[1], $m))
                    return $m[1] . ((int)($path[2] ?? 0) > 0 ? '.' . (int)$path[2] : '');
                return isset($slot['column']) && is_string($slot['column']) && ($slot['column'] !== 'params' || $derived) ? $slot['column'] : null;
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
            // What the row prints beside its contract slots (see extraBlocks). After the slots, so a
            // slot keeps its position; keyed by the NATIVE id, the id a render stamp names.
            foreach (self::extraBlocks($meta['kind'], $nativeId, $row, $extras) as $extra) {
                $blockId = $opaque('block', $uid . ':' . $extra['key']);
                $content['blocks'][] = ['id' => $blockId, 'key' => $extra['key'], 'role' => $extra['role'], 'position' => count($content['blocks']),
                    'sharedContentId' => null, 'visibility' => 'unknown', 'fields' => $extra['fields'], 'items' => []];
                foreach ($extra['images'] as [$value, $alt]) {
                    $mid = $opaque('media', $value); $usage = ['contentId' => $id, 'blockId' => $blockId, 'itemId' => null];
                    if (isset($content['images'][$mid])) { $content['images'][$mid]['usages'][] = $usage; $content['images'][$mid]['alt'] ??= $alt; }
                    else $content['images'][$mid] = ['id' => $mid, 'src' => self::mediaSrc($value, $base), 'alt' => $alt, 'width' => null, 'height' => null, 'usages' => [$usage]];
                }
            }
            if ($meta['kind'] === 'article') $content['tags'] = $extras['articleTags'][$nativeId] ?? [];
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
            $fields = [];
            // A shared module is read with its title as the record's own; inlined, the record is gone,
            // so the title it prints ("Categories", "Latest articles" over a sidebar list) goes with
            // its fields — or no record would hold it at all (27/09, capijl1644 /en/news).
            [, $moduleId] = $rowsOf[$source][0];
            $moduleRow = $moduleRows[$moduleId] ?? null;
            if ($moduleRow && (int)($moduleRow['showtitle'] ?? 0) === 1 && trim((string)($moduleRow['title'] ?? '')) !== '')
                $fields[] = self::extraField('module-' . $moduleId . '.title', 'text', (string)$moduleRow['title'], 'module-title');
            foreach ($module['blocks'] as $block) foreach ($block['fields'] as $field) $fields[] = $field;
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
        // Categories and tags the readable articles live in (see taxonomyContents): their own records,
        // so a name printed on a page ("Disclosure" in a breadcrumb, a filter chip) has one to answer.
        foreach (self::taxonomyContents($extras, $native['article'] ?? [], $opaque, $base) as $tid => [$taxonomy, $row]) {
            $contents[$tid] = $taxonomy; $rowsOf[$tid] = [$row];
            if ($taxonomy['locale'] !== null) $locales[$taxonomy['locale']] = true;
        }
        foreach ($native['article'] ?? [] as $articleId => $cid) {
            $category = $opaque('content', 'category:' . (int)($extras['articleCategory'][$articleId] ?? 0));
            if (isset($contents[$category], $contents[$cid])) $contents[$cid]['relations'][] = ['type' => 'parent', 'contentId' => $category];
        }
        // A derived map's category and custom-field-value slots belong to contents already made: a
        // category's to its category record, a field value's to the article it is stored for. One
        // block per slot, like every other slot; a content that is not read leaves its slots unread.
        if ($derived) foreach ($mapping['keys'] as $key => $meta) {
            if ($meta['kind'] === 'category') $owner = $opaque('content', 'category:' . (int)$mapping['ids'][$key]);
            elseif ($meta['kind'] === 'fieldValue') $owner = $native['article'][FieldValueKey::decode((int)$mapping['ids'][$key])[1]] ?? null;
            else continue;
            if ($owner === null || !isset($contents[$owner])) continue;
            $row = $mapping['rows'][$key];
            foreach ($mapping['slots'][$key] as $slot) {
                $field = ['key' => $slot['key'], 'type' => $slot['type'], 'value' => $slotValue($row, $slot), 'slotKey' => $slot['key'], 'semanticKey' => $slot['column']];
                $contents[$owner]['blocks'][] = ['id' => $opaque('block', $owner . ':' . $slot['key']), 'key' => $slot['key'], 'role' => null, 'position' => count($contents[$owner]['blocks']),
                    'sharedContentId' => null, 'visibility' => 'unknown', 'fields' => [$field], 'items' => []];
            }
            $keys[$key] = $owner;
            if ($meta['kind'] === 'fieldValue') $rowsOf[$owner][] = ['fieldValue', (int)$mapping['ids'][$key]];
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
