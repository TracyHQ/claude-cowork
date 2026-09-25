<?php
/**
 * The WordPress side of `/content.json`: what this site holds, projected to `tracy-content/v1`
 * for one read scope, from ONE consistent read of the database.
 *
 * ## One read, one state
 *
 * Every query runs inside `START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY` on WordPress's
 * own connection and the transaction ends before the answer is printed. The snapshot revision is
 * the SHA-256 of every source row the projection reads for this scope (posts with their content
 * hashed by the database, their meta and terms, the options, the theme files that hold template
 * parts, the bound contract). A cursor carries it; the next page recomputes it and a difference is
 * a 409. Two pages are therefore never stitched from two states of the site, whoever changed it —
 * an agent, an admin, cron publishing a scheduled post, a language retired.
 *
 * ## Read only
 *
 * Nothing here writes: no option, no meta, no binding, no render. Content is read from rows, not
 * through `the_content`, so no shortcode, block render callback or plugin directive runs on a
 * read. Permalinks are the one WordPress API used for values, because the address of a page is
 * the permalink machinery's answer (Polylang prefixes it) and not a column.
 *
 * ## Identity
 *
 * A content id is an HMAC, under this site's content key and its home address, of a random uid
 * the site gives each row once (`_tracy_content_uid`). It survives a new title, slug, parent,
 * order or language; a deleted row takes its uid with it, so a row created again is new. The uid
 * is minted when WordPress inserts a row (hook below) and by the explicit `content.identity`
 * action for rows older than this plugin — never by a read. A site that never ran that action
 * answers 501: without identities there is nothing stable to hand out.
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/ContentReader.php';
require_once __DIR__ . '/BlockProjection.php';
require_once __DIR__ . '/QuickstartContract.php';
require_once __DIR__ . '/ContentIdentity.php';

final class Claude_Cowork_Content_Source implements ContentSource
{
    public const UID_META = ContentIdentity::UID_META;
    public const SITE_OPTION = ContentIdentity::SITE_OPTION;
    /** Post types that are content (page/post) or shared content, plus attachments for images. */
    public const CONTENT_TYPES = ['page' => 'page', 'post' => 'article'];
    public const SHARED_TYPES = ['wp_template_part', 'wp_block', 'wp_navigation'];
    public const IDENTIFIED_TYPES = ContentIdentity::TYPES;
    /** What each scope may read. `published` is what an anonymous visitor could open. */
    public const STATUSES = [
        'published' => ['publish'],
        'editorial' => ['publish', 'future', 'draft', 'pending', 'private'],
    ];
    private const META_KEYS = [self::UID_META, '_thumbnail_id', '_wp_page_template', '_wp_attachment_metadata', '_wp_attachment_image_alt', '_wp_attached_file', 'tracy_page_heading'];
    private const OPTIONS = ['home', 'siteurl', 'blogname', 'blogdescription', 'page_on_front', 'page_for_posts', 'show_on_front',
        'stylesheet', 'template', 'polylang', 'WPLANG', 'permalink_structure', 'active_plugins',
        QuickstartContract::STORE_OPTION, 'tracy_site_identity', self::SITE_OPTION];

    /** @var string */
    private $scope;
    /** @var string */
    private $contractsDir;
    /** @var string */
    private $version;

    private $loaded = false;
    private $key = '';
    private $siteRow = [];
    private $revision = '';
    /** @var array<int,array<string,mixed>> post rows in scope (and attachments), by native id */
    private $posts = [];
    /** @var array<int,array<string,string>> meta by post id */
    private $meta = [];
    /** @var array<int,array<string,array<int,array<string,mixed>>>> terms by post id and taxonomy */
    private $terms = [];
    /** @var array<string,array<string,mixed>> Polylang languages by slug, in their order */
    private $languages = [];
    private $options = [];
    /** @var array<string,array{theme:string,slug:string,path:string,hash:string}> theme part files by slug */
    private $themeParts = [];
    /** @var array<int,string> content id by native id (contents in scope only) */
    private $ids = [];
    /** @var array<string,array<string,mixed>> summaries by content id, listing order */
    private $summaries = [];
    /** @var array<string,array{kind:string,native:mixed}> what each content id is */
    private $index = [];
    private $binding = null;
    private $profile = null;
    private $missingIdentity = 0;
    private $duplicateIdentity = 0;
    /** Counted while one content is read in full, and reported with it. */
    private $unkeyedBlocks = 0;
    private $ambiguousSections = 0;

    public function __construct(string $scope, string $contractsDir, string $version)
    {
        if (!isset(self::STATUSES[$scope])) {
            throw new InvalidArgumentException('Unknown read scope');
        }
        $this->scope = $scope;
        $this->contractsDir = rtrim($contractsDir, '/');
        $this->version = $version;
    }

    // ---- ContentSource ----------------------------------------------------------------------

    public function site(): array
    {
        $this->load();
        return $this->siteRow;
    }

    public function provenance(): ?array
    {
        $this->load();
        if ($this->profile === null) {
            return null;
        }
        $quickstart = $this->profile['manifest']['quickstart'] ?? [];
        return [
            'quickstartTag' => (string) ($quickstart['release'] ?? ''),
            'quickstartVersion' => (string) ($quickstart['version'] ?? ''),
            'contractId' => (string) $this->profile['id'],
            'contractHash' => isset($this->binding['contractHash']) && $this->binding['contractHash'] !== '' ? (string) $this->binding['contractHash'] : null,
        ];
    }

    public function revision(): string
    {
        $this->load();
        return $this->revision;
    }

    public function summaries(): array
    {
        $this->load();
        return array_values($this->summaries);
    }

    public function unresolved(): array
    {
        $this->load();
        $out = [
            ['code' => 'WP_UNSUPPORTED_CONTENT', 'message' => 'Pages, posts, template parts, synced patterns, navigation menus and the site identity are listed. Attachments appear only as images; forms, comments, users, custom post types and taxonomy archives are not listed.'],
            ['code' => 'WP_STATIC_MARKUP', 'message' => 'Values are read from saved block markup. Dynamic blocks, shortcodes and theme render filters are not executed, so rendered pages can differ and block visibility is unknown. Blocks without a stable name are only in bodyHtml.'],
            ['code' => 'WP_TEMPLATE_REFERENCES', 'message' => 'Template part and navigation occurrences come from stored templates; a theme may substitute them when it renders (for example a menu per language).'],
        ];
        if ($this->languages !== []) {
            $out[] = ['code' => 'WP_STRING_TRANSLATIONS', 'message' => 'Polylang string translations (such as the site name per language) are not listed.'];
        }
        if ($this->unkeyedBlocks > 0) {
            $out[] = ['code' => 'WP_UNKEYED_BLOCKS', 'message' => $this->unkeyedBlocks . ' top-level blocks of this content carry no block name and are only in bodyHtml.'];
        }
        if ($this->ambiguousSections > 0) {
            $out[] = ['code' => 'WP_AMBIGUOUS_SECTIONS', 'message' => $this->ambiguousSections . ' sections of this content hold the same block names as another section and are only in bodyHtml.'];
        }
        if ($this->missingIdentity > 0) {
            $out[] = ['code' => 'WP_IDENTITY_MISSING', 'message' => $this->missingIdentity . ' readable rows have no content identity yet and are not listed; run content.identity.'];
        }
        if ($this->duplicateIdentity > 0) {
            $out[] = ['code' => 'WP_IDENTITY_DUPLICATE', 'message' => $this->duplicateIdentity . ' readable rows share a copied content identity and are not listed; run content.identity.'];
        }
        return $out;
    }

    public function detail(string $id): ?array
    {
        $this->load();
        if (!isset($this->index[$id])) {
            return null;
        }
        $entry = $this->index[$id];
        $content = $this->summaries[$id];
        unset($content['detailState']);
        $content += ['bodyHtml' => null, 'tags' => [], 'fields' => [], 'blocks' => [], 'images' => [], 'relations' => []];
        $content['detailState'] = 'complete';
        if ($entry['kind'] === 'site') {
            $content['fields'] = $this->siteFields();
            return $content;
        }
        if ($entry['kind'] === 'theme-part') {
            $part = $this->themeParts[$entry['native']];
            $this->fillFromMarkup($content, (string) file_get_contents($part['path']), null);
            return $content;
        }
        $native = (int) $entry['native'];
        $row = $this->posts[$native];
        $markup = $this->postContent($native);
        if ($row['post_type'] === 'wp_navigation') {
            $content['bodyHtml'] = BlockProjection::staticHtml(parse_blocks($markup)) ?: null;
            $content['blocks'] = [$this->navigationBlock($id, $markup)];
            return $content;
        }
        $this->fillFromMarkup($content, $markup, $row);
        if (isset(self::CONTENT_TYPES[$row['post_type']])) {
            $content['tags'] = $this->tagNames($native);
            $content['relations'] = $this->relations($native);
            $heading = $this->meta[$native]['tracy_page_heading'] ?? null;
            if (is_string($heading) && $heading !== '') {
                $content['fields'][] = ['key' => 'meta.tracy_page_heading', 'type' => 'text', 'value' => $heading, 'slotKey' => null, 'semanticKey' => null];
            }
            $thumb = (int) ($this->meta[$native]['_thumbnail_id'] ?? 0);
            if ($thumb > 0) {
                $this->addImage($content, $thumb, null, null, null, null);
            }
            $content['blocks'] = $this->withTemplate($content['blocks'], $row, $id);
        }
        return $content;
    }

    // ---- loading ------------------------------------------------------------------------------

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }
        global $wpdb;
        if (!is_object($wpdb) || !($wpdb->dbh instanceof mysqli)) {
            throw new ContentReadError('CONTENT_ADAPTER_UNSUPPORTED', 501, 'This site does not use a MySQL connection the reader supports.');
        }
        $wpdb->query('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
        try {
            $this->read();
        } finally {
            $wpdb->query('COMMIT');
        }
        $this->loaded = true;
    }

    private function query(string $sql): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows) || $wpdb->last_error !== '') {
            throw new RuntimeException('A content query failed');
        }
        return $rows;
    }

    private static function inList(array $values): string
    {
        global $wpdb;
        return implode(',', array_map(static fn($v) => $wpdb->prepare('%s', $v), $values));
    }

    private function read(): void
    {
        global $wpdb;
        foreach ($this->query('SELECT option_name, option_value FROM ' . $wpdb->options . ' WHERE option_name IN (' . self::inList(self::OPTIONS) . ')') as $row) {
            $this->options[$row['option_name']] = $row['option_value'];
        }
        ksort($this->options);
        $siteKey = (string) ($this->options[self::SITE_OPTION] ?? '');
        if (!preg_match('/^[a-f0-9]{32,64}$/D', $siteKey)) {
            throw new ContentReadError('CONTENT_ADAPTER_UNSUPPORTED', 501, 'Content identity is not set up on this site yet.');
        }
        $home = rtrim((string) ($this->options['home'] ?? ''), '/');
        // The key binds every id to this site AND its address: a copy imported elsewhere hands out new ids.
        $this->key = hash_hmac('sha256', $home, $siteKey);
        $this->binding = self::decodeJson($this->options[QuickstartContract::STORE_OPTION] ?? null);
        $this->profile = $this->loadProfile();

        $polylang = in_array('polylang/polylang.php', (array) self::unserialize($this->options['active_plugins'] ?? ''), true)
            || in_array('polylang-pro/polylang.php', (array) self::unserialize($this->options['active_plugins'] ?? ''), true);
        $statuses = self::STATUSES[$this->scope];

        $types = array_merge(array_keys(self::CONTENT_TYPES), self::SHARED_TYPES);
        $posts = $this->query('SELECT ID, post_type, post_status, post_password, post_name, post_title, post_excerpt, post_parent, menu_order,'
            . ' post_date_gmt, post_modified_gmt, MD5(post_content) AS content_md5 FROM ' . $wpdb->posts
            . ' WHERE (post_type IN (' . self::inList($types) . ') AND post_status IN (' . self::inList($statuses) . '))'
            // Attachments give images their metadata; saved templates decide where header and footer go.
            . " OR post_type IN ('attachment','wp_template') ORDER BY ID");
        foreach ($posts as $row) {
            if ($this->scope === 'published' && in_array($row['post_type'], ['page', 'post'], true) && $row['post_password'] !== '') {
                continue; // a password-protected post is not published content for this scope
            }
            $this->posts[(int) $row['ID']] = $row;
        }
        $ids = array_keys($this->posts);
        if ($ids !== []) {
            foreach (array_chunk($ids, 1000) as $chunk) {
                $in = implode(',', array_map('intval', $chunk));
                foreach ($this->query('SELECT post_id, meta_key, meta_value FROM ' . $wpdb->postmeta . ' WHERE post_id IN (' . $in . ') AND meta_key IN ('
                    . self::inList(self::META_KEYS) . ') ORDER BY meta_id') as $row) {
                    // The first row wins: a second copy of a key (a duplicated uid) never replaces it.
                    if (!isset($this->meta[(int) $row['post_id']][$row['meta_key']])) {
                        $this->meta[(int) $row['post_id']][$row['meta_key']] = $row['meta_value'];
                    }
                }
                foreach ($this->query('SELECT tr.object_id, tt.taxonomy, tt.term_taxonomy_id, t.name, t.slug, tt.description FROM ' . $wpdb->term_relationships . ' tr'
                    . ' JOIN ' . $wpdb->term_taxonomy . ' tt ON tt.term_taxonomy_id = tr.term_taxonomy_id JOIN ' . $wpdb->terms . ' t ON t.term_id = tt.term_id'
                    . ' WHERE tr.object_id IN (' . $in . ") AND tt.taxonomy IN ('language','post_translations','post_tag','category','wp_theme','wp_template_part_area')"
                    . ' ORDER BY tr.object_id, tt.taxonomy, t.name') as $row) {
                    $this->terms[(int) $row['object_id']][$row['taxonomy']][] = $row;
                }
            }
        }
        if ($polylang) {
            foreach ($this->query('SELECT t.slug, t.name, t.term_group, tt.description FROM ' . $wpdb->term_taxonomy . ' tt JOIN ' . $wpdb->terms
                . " t ON t.term_id = tt.term_id WHERE tt.taxonomy = 'language' ORDER BY t.term_group, t.term_id") as $row) {
                $this->languages[$row['slug']] = $row;
            }
        }
        $this->themeParts = $this->readThemeParts();

        $this->buildSite();
        $this->buildContents();
        $this->revision = $this->fingerprint();
    }

    /** The bound contract's profile, when this plugin carries it. */
    private function loadProfile(): ?array
    {
        $id = is_array($this->binding) ? (string) ($this->binding['contract'] ?? '') : '';
        if ($id === '' || !preg_match(QuickstartContract::ID_SHAPE, $id)) {
            return null;
        }
        $dir = $this->contractsDir . '/' . $id;
        $out = ['id' => $id, 'hash' => ''];
        foreach (['manifest', 'content-map', 'editions'] as $name) {
            $file = $dir . '/' . $name . '.json';
            $raw = is_file($file) ? (string) file_get_contents($file) : null;
            $out[$name] = $raw === null ? null : json_decode($raw, true);
            $out['hash'] .= $raw === null ? '-' : hash('sha256', $raw);
        }
        return is_array($out['manifest']) && is_array($out['content-map']) ? $out : null;
    }

    /** @return array<string,array{theme:string,slug:string,path:string,hash:string}> */
    private function readThemeParts(): array
    {
        $out = [];
        $themes = array_unique(array_filter([(string) ($this->options['stylesheet'] ?? ''), (string) ($this->options['template'] ?? '')]));
        foreach ($themes as $theme) {
            $dir = get_theme_root($theme) . '/' . $theme . '/parts';
            foreach (is_dir($dir) ? (array) glob($dir . '/*.html') : [] as $file) {
                $slug = basename((string) $file, '.html');
                if (!isset($out[$slug]) && preg_match('/^[a-z0-9_-]+$/D', $slug)) {
                    $out[$slug] = ['theme' => $theme, 'slug' => $slug, 'path' => (string) $file, 'hash' => (string) hash_file('sha256', (string) $file)];
                }
            }
        }
        ksort($out);
        return $out;
    }

    // ---- building -----------------------------------------------------------------------------

    private function opaque(string $prefix, string $what): string
    {
        return $prefix . substr(hash_hmac('sha256', $what, $this->key), 0, 26);
    }

    private function buildSite(): void
    {
        $home = rtrim((string) ($this->options['home'] ?? ''), '/');
        $retired = QuickstartContract::retiredLanguages(is_array($this->binding) ? $this->binding : null);
        $locales = [];
        foreach ($this->languages as $slug => $language) {
            if ($this->scope === 'published' && in_array($slug, $retired, true)) {
                continue; // a retired edition is not live: its switcher entry is hidden, its pages are drafts
            }
            $locales[$slug] = $this->languageTag((string) $slug);
        }
        $default = null;
        if ($this->languages !== []) {
            $settings = self::unserialize($this->options['polylang'] ?? '');
            $slug = is_array($settings) ? (string) ($settings['default_lang'] ?? '') : '';
            $default = $locales[$slug] ?? null;
        } else {
            $default = self::bcp47((string) (($this->options['WPLANG'] ?? '') ?: 'en_US'));
            $locales['_site'] = $default;
        }
        $this->siteRow = [
            'id' => $this->opaque('s', 'site'),
            'name' => isset($this->options['blogname']) ? (string) $this->options['blogname'] : null,
            'url' => $home,
            'defaultLocale' => $default,
            'locales' => array_values(array_unique(array_values($locales))),
        ];
    }

    private function languageTag(string $slug): string
    {
        $description = self::unserialize($this->languages[$slug]['description'] ?? '');
        $locale = is_array($description) && isset($description['locale']) ? (string) $description['locale'] : $slug;
        return self::bcp47($locale);
    }

    /** A WordPress locale (`en_US`, `pt_BR`, `de_DE_formal`, `bel`) as a canonical BCP 47 tag. */
    public static function bcp47(string $locale): string
    {
        $parts = preg_split('/[_-]/', $locale) ?: [$locale];
        $language = strtolower((string) array_shift($parts));
        // ISO 639-2/3 codes WordPress uses for languages that also have a two-letter code.
        $language = ['bel' => 'be', 'fao' => 'fo', 'gle' => 'ga', 'kin' => 'rw', 'oci' => 'oc', 'tuk' => 'tk', 'uig' => 'ug', 'ido' => 'io'][$language] ?? $language;
        $tag = [$language];
        $private = [];
        foreach ($parts as $part) {
            if (preg_match('/^[A-Za-z]{4}$/D', $part)) {
                $tag[] = ucfirst(strtolower($part));
            } elseif (preg_match('/^([A-Za-z]{2}|[0-9]{3})$/D', $part)) {
                $tag[] = strtoupper($part);
            } elseif ($part !== '') {
                $private[] = strtolower($part);
            }
        }
        return implode('-', $tag) . ($private === [] ? '' : '-x-' . implode('-', $private));
    }

    private function buildContents(): void
    {
        // The site identity first: one shared content for the options every page prints.
        $siteId = $this->opaque('c', 'option:site');
        $this->index[$siteId] = ['kind' => 'site', 'native' => null];
        $this->summaries[$siteId] = $this->summary($siteId, 'shared', (string) ($this->options['blogname'] ?? ''), null, null, null, null,
            hash('sha256', json_encode([$this->options['blogname'] ?? null, $this->options['blogdescription'] ?? null, $this->options['tracy_site_identity'] ?? null, $this->bindingSlotsHash()])),
            ['status' => 'published', 'valueSource' => 'current', 'scheduledAt' => null], null);

        // Rows: uid → content id, duplicates (a copied meta row) refused.
        $byUid = [];
        foreach ($this->posts as $native => $row) {
            $uid = (string) ($this->meta[$native][self::UID_META] ?? '');
            if ($row['post_type'] === 'wp_template') {
                continue;
            }
            $counted = $row['post_type'] !== 'attachment';
            if (!preg_match('/^[a-f0-9]{32}$/D', $uid)) {
                if ($counted) {
                    $this->missingIdentity++;
                }
                continue;
            }
            if (isset($byUid[$uid])) {
                if ($counted) {
                    $this->duplicateIdentity++;
                }
                continue;
            }
            $byUid[$uid] = $native;
            $this->ids[$native] = $this->opaque($row['post_type'] === 'attachment' ? 'm' : 'c', 'uid:' . $uid);
        }

        // Theme-file template parts that no database row overrides.
        $overridden = [];
        foreach ($this->posts as $native => $row) {
            if ($row['post_type'] === 'wp_template_part' && isset($this->ids[$native])) {
                $overridden[$row['post_name']] = true;
            }
        }
        $rank = ['page' => 0, 'post' => 1, 'wp_template_part' => 2, 'wp_block' => 3, 'wp_navigation' => 4];
        $rows = array_filter($this->posts, fn($row, $native) => isset($this->ids[$native]) && isset($rank[$row['post_type']]), ARRAY_FILTER_USE_BOTH);
        uksort($rows, static fn($a, $b) => [$rank[$rows[$a]['post_type']], $a] <=> [$rank[$rows[$b]['post_type']], $b]);
        $contentRows = array_filter($rows, static fn($row) => isset(self::CONTENT_TYPES[$row['post_type']]));
        // Prime WordPress's caches in two queries so a permalink per row does not cost one each.
        if (function_exists('_prime_post_caches') && $contentRows !== []) {
            _prime_post_caches(array_keys($contentRows), true, false);
        }
        foreach ($rows as $native => $row) {
            $id = $this->ids[$native];
            $this->index[$id] = ['kind' => 'post', 'native' => $native];
            $this->summaries[$id] = $this->postSummary($id, (int) $native, $row);
            if ($row['post_type'] === 'wp_template_part') {
                // placed after pages/posts by rank; theme files follow the database parts
            }
        }
        foreach ($this->themeParts as $slug => $part) {
            if (isset($overridden[$slug])) {
                continue;
            }
            $id = $this->opaque('c', 'theme-part:' . $part['theme'] . '//' . $slug);
            $this->index[$id] = ['kind' => 'theme-part', 'native' => $slug];
            $this->summaries[$id] = $this->summary($id, 'shared', $slug, $slug, null, null, null, $part['hash'],
                ['status' => 'published', 'valueSource' => 'current', 'scheduledAt' => null], null);
        }
    }

    private function postSummary(string $id, int $native, array $row): array
    {
        $type = self::CONTENT_TYPES[$row['post_type']] ?? 'shared';
        $status = (string) $row['post_status'];
        $publication = [
            'publish' => ['status' => 'published', 'valueSource' => 'published', 'scheduledAt' => null],
            'future' => ['status' => 'scheduled', 'valueSource' => 'current', 'scheduledAt' => self::time($row['post_date_gmt'])],
            'draft' => ['status' => 'draft', 'valueSource' => 'draft', 'scheduledAt' => null],
            'pending' => ['status' => 'draft', 'valueSource' => 'draft', 'scheduledAt' => null],
            'private' => ['status' => 'private', 'valueSource' => 'current', 'scheduledAt' => null],
        ][$status] ?? ['status' => 'unknown', 'valueSource' => 'unknown', 'scheduledAt' => null];
        $locale = null;
        $group = null;
        if ($type !== 'shared') {
            $language = $this->terms[$native]['language'][0]['slug'] ?? null;
            if ($this->languages !== []) {
                $locale = $language !== null && isset($this->languages[$language]) ? $this->languageTag((string) $language) : null;
            } else {
                $locale = $this->siteRow['defaultLocale'];
            }
            if ($locale !== null && !in_array($locale, $this->siteRow['locales'], true)) {
                $locale = null; // a retired edition's page seen in the editorial scope keeps no live locale
            }
            $translation = $this->terms[$native]['post_translations'][0] ?? null;
            if ($translation !== null && $locale !== null) {
                $group = $this->opaque('g', 'translations:' . $translation['term_taxonomy_id']);
            }
        }
        $url = null;
        if ($status === 'publish' && $type !== 'shared' && function_exists('get_permalink')) {
            $permalink = get_permalink($native);
            $url = is_string($permalink) && $permalink !== '' ? $permalink : null;
        }
        $revision = hash('sha256', json_encode([$row, $this->meta[$native] ?? [], $this->terms[$native] ?? [], $url, $locale, $this->bindingSlotsHash()]));
        $summary = $this->summary($id, $type, (string) $row['post_title'], $row['post_name'] === '' ? null : (string) $row['post_name'], $url, $locale, $group, $revision, $publication, $row);
        $summary['summary'] = trim((string) $row['post_excerpt']) === '' ? null : (string) $row['post_excerpt'];
        return $summary;
    }

    private function summary(string $id, string $type, ?string $title, ?string $slug, ?string $url, ?string $locale, ?string $group, string $revision, array $publication, ?array $row): array
    {
        return [
            'id' => $id,
            'type' => $type,
            'title' => $title === '' ? null : $title,
            'slug' => $slug,
            'url' => $url,
            'locale' => $locale,
            'translationGroupId' => $group,
            'summary' => null,
            'publishedAt' => $row !== null && $row['post_status'] === 'publish' ? self::time($row['post_date_gmt']) : null,
            'createdAt' => null,
            'updatedAt' => $row !== null ? self::time($row['post_modified_gmt']) : null,
            'revision' => substr($revision, 0, 40),
            'publication' => $publication,
            'detailState' => 'summary',
            'links' => ['self' => $this->link($id)],
        ];
    }

    private function link(string $id): string
    {
        $path = (string) parse_url((string) ($this->options['home'] ?? ''), PHP_URL_PATH);
        return rtrim($path, '/') . '/content.json?id=' . rawurlencode($id);
    }

    private static function time($value): ?string
    {
        $value = (string) $value;
        if ($value === '' || strpos($value, '0000-00-00') === 0) {
            return null;
        }
        $time = strtotime($value . ' UTC');
        return $time === false ? null : gmdate('Y-m-d\TH:i:s\Z', $time);
    }

    /** Everything the projection read, as one hash. Scope is part of it: two scopes never share a cursor. */
    private function fingerprint(): string
    {
        $themeFiles = [];
        foreach ($this->themeTemplates() as $slug => $file) {
            $themeFiles['template:' . $slug] = hash_file('sha256', $file);
        }
        foreach ($this->themeParts as $slug => $part) {
            $themeFiles['part:' . $slug] = $part['hash'];
        }
        return hash('sha256', json_encode([
            'v' => 1, 'plugin' => $this->version, 'scope' => $this->scope,
            'options' => $this->options, 'posts' => $this->posts, 'meta' => $this->meta, 'terms' => $this->terms,
            'languages' => $this->languages, 'theme' => $themeFiles, 'profile' => $this->profile['hash'] ?? null,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    // ---- detail -----------------------------------------------------------------------------

    private function postContent(int $native): string
    {
        global $wpdb;
        $wpdb->query('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
        try {
            $row = $this->query($wpdb->prepare('SELECT post_content, MD5(post_content) AS content_md5 FROM ' . $wpdb->posts . ' WHERE ID = %d', $native));
        } finally {
            $wpdb->query('COMMIT');
        }
        // The body must be the one the fingerprint saw; a write between the two reads is a conflict, not a mix.
        if (!isset($row[0]) || $row[0]['content_md5'] !== $this->posts[$native]['content_md5']) {
            throw new ContentReadError('CONTENT_SNAPSHOT_EXPIRED', 409, 'The content changed while it was read; read it again.');
        }
        return (string) $row[0]['post_content'];
    }

    /**
     * Blocks, fields, images and body of one stored markup. `$row` is the owning post (null for
     * a theme-file part): its binding decides which fields carry a contract slot key.
     */
    private function fillFromMarkup(array &$content, string $markup, ?array $row): void
    {
        $projected = BlockProjection::project(parse_blocks($markup));
        $this->unkeyedBlocks += $projected['unkeyed'];
        $content['bodyHtml'] = $projected['bodyHtml'] === '' ? null : $projected['bodyHtml'];
        $slots = $row === null ? [] : $this->slotsFor((int) $row['ID'], $row);
        // Two sections may share a first segment (the vendor named both `text.*`). They are then
        // told apart by the SET of names each holds — which a reorder does not change — and only
        // sections holding the very same set stay ambiguous and are left to bodyHtml.
        $sectionKeys = [];
        foreach ($projected['entries'] as $entry) {
            if ($entry['kind'] === 'section') {
                $sectionKeys[$entry['key']] = ($sectionKeys[$entry['key']] ?? 0) + 1;
            }
        }
        $keyOf = static function (array $entry) use ($sectionKeys): string {
            if ($sectionKeys[$entry['key']] < 2) {
                return $entry['key'];
            }
            $names = array_values(array_unique($entry['names']));
            sort($names);
            return $entry['key'] . '#' . substr(hash('sha256', implode(',', $names)), 0, 8);
        };
        $finalKeys = [];
        foreach ($projected['entries'] as $entry) {
            if ($entry['kind'] === 'section') {
                $finalKeys[$keyOf($entry)] = ($finalKeys[$keyOf($entry)] ?? 0) + 1;
            }
        }
        // A slot names a block; the contract writes the FIRST block of that name in the markup, so
        // only that occurrence carries the slot key.
        $slotted = [];
        $owner = $content['id'];
        $blocks = [];
        $sharedSeen = [];
        foreach ($projected['entries'] as $entry) {
            if ($entry['kind'] === 'shared') {
                $target = $this->sharedTarget($entry['ref']);
                if ($target === null) {
                    continue; // not readable in this scope, or not a content this site lists
                }
                $key = $entry['key'] ?? 'shared.' . $target;
                $sharedSeen[$key] = ($sharedSeen[$key] ?? 0) + 1;
                if ($sharedSeen[$key] > 1) {
                    $key .= '#' . $sharedSeen[$key];
                }
                $occurrence = $this->occurrence($owner, $key, $target);
                $occurrence['position'] = count($blocks);
                $blocks[] = $occurrence;
                continue;
            }
            $sectionKey = $keyOf($entry);
            if ($finalKeys[$sectionKey] > 1) {
                // Sections holding the same names: neither has an identity a reorder keeps. Their
                // words stay in bodyHtml, and a slot the contract resolves to the first of them is
                // not handed to a later block of the same name.
                $this->ambiguousSections++;
                foreach ($entry['fields'] as $field) {
                    $this->field($field, $slots, $slotted);
                }
                foreach ($entry['items'] as $item) {
                    foreach ($item['fields'] as $field) {
                        $this->field($field, $slots, $slotted);
                    }
                }
                continue;
            }
            $blockId = $this->opaque('b', $owner . '|section:' . $sectionKey);
            $block = ['id' => $blockId, 'key' => $sectionKey, 'role' => null, 'position' => count($blocks),
                'sharedContentId' => null, 'visibility' => 'unknown', 'fields' => [], 'items' => []];
            foreach ($entry['fields'] as $field) {
                $block['fields'][] = $this->field($field, $slots, $slotted);
            }
            foreach ($entry['items'] as $position => $item) {
                $fields = [];
                foreach ($item['fields'] as $field) {
                    $fields[] = $this->field($field, $slots, $slotted);
                }
                $block['items'][] = ['id' => $this->opaque('i', $owner . '|item:' . $item['key']), 'position' => $position, 'fields' => $fields, 'contentId' => null];
            }
            foreach ($entry['images'] as $image) {
                $itemId = $image['itemKey'] === null ? null : $this->opaque('i', $owner . '|item:' . $image['itemKey']);
                $this->addImage($content, $image['attachment'], $image['src'], $image['alt'], $blockId, $itemId);
            }
            $blocks[] = $block;
        }
        $content['blocks'] = $blocks;
    }

    private function occurrence(string $owner, string $key, string $target): array
    {
        return ['id' => $this->opaque('b', $owner . '|shared:' . $key), 'key' => $key, 'role' => null, 'position' => 0,
            'sharedContentId' => $target, 'visibility' => 'unknown', 'fields' => [], 'items' => []];
    }

    /** The content id a shared pointer resolves to in this scope, or null. */
    private function sharedTarget(array $ref): ?string
    {
        if ($ref['type'] === 'template-part') {
            $theme = $ref['theme'] ?? null;
            foreach ($this->posts as $native => $row) {
                if ($row['post_type'] === 'wp_template_part' && $row['post_name'] === $ref['slug'] && isset($this->ids[$native])
                    && ($theme === null || ($this->terms[$native]['wp_theme'][0]['slug'] ?? null) === $theme)) {
                    return isset($this->summaries[$this->ids[$native]]) ? $this->ids[$native] : null;
                }
            }
            $part = $this->themeParts[$ref['slug']] ?? null;
            if ($part === null || ($theme !== null && $theme !== $part['theme'])) {
                return null;
            }
            $id = $this->opaque('c', 'theme-part:' . $part['theme'] . '//' . $part['slug']);
            return isset($this->summaries[$id]) ? $id : null;
        }
        $native = (int) $ref['id'];
        $want = $ref['type'] === 'wp_block' ? 'wp_block' : 'wp_navigation';
        if (!isset($this->posts[$native], $this->ids[$native]) || $this->posts[$native]['post_type'] !== $want) {
            return null;
        }
        return isset($this->summaries[$this->ids[$native]]) ? $this->ids[$native] : null;
    }

    /** Header and footer around a page's own blocks, in the order its template places them. */
    private function withTemplate(array $body, array $row, string $owner): array
    {
        $template = $this->templateFor($row);
        $before = [];
        $after = [];
        $seenContent = false;
        if ($template !== null) {
            $walk = function (array $blocks) use (&$walk, &$before, &$after, &$seenContent): void {
                foreach ($blocks as $block) {
                    $name = $block['blockName'] ?? null;
                    if ($name === 'core/post-content') {
                        $seenContent = true;
                        continue;
                    }
                    if ($name === 'core/template-part' && is_string($block['attrs']['slug'] ?? null)) {
                        $ref = ['type' => 'template-part', 'slug' => $block['attrs']['slug'], 'theme' => $block['attrs']['theme'] ?? null];
                        $target = $this->sharedTarget($ref);
                        if ($target !== null) {
                            if ($seenContent) {
                                $after[] = ['template.' . $ref['slug'], $target];
                            } else {
                                $before[] = ['template.' . $ref['slug'], $target];
                            }
                        }
                        continue;
                    }
                    if (!empty($block['innerBlocks'])) {
                        $walk($block['innerBlocks']);
                    }
                }
            };
            $walk(parse_blocks($template));
        }
        $out = [];
        foreach ($before as [$key, $target]) {
            $out[] = $this->occurrence($owner, $key, $target);
        }
        foreach ($body as $block) {
            $out[] = $block;
        }
        foreach ($after as [$key, $target]) {
            $out[] = $this->occurrence($owner, $key, $target);
        }
        $keys = [];
        foreach ($out as $position => $block) {
            if (isset($keys[$block['key']])) {
                unset($out[$position]); // a template naming a part the body also holds: keep the first
                continue;
            }
            $keys[$block['key']] = true;
        }
        $out = array_values($out);
        foreach ($out as $position => &$block) {
            $block['position'] = $position;
        }
        return $out;
    }

    /** @return array<string,string> theme template files by slug (child theme first) */
    private function themeTemplates(): array
    {
        $out = [];
        foreach (array_unique(array_filter([(string) ($this->options['stylesheet'] ?? ''), (string) ($this->options['template'] ?? '')])) as $theme) {
            $dir = get_theme_root($theme) . '/' . $theme . '/templates';
            foreach (is_dir($dir) ? (array) glob($dir . '/*.html') : [] as $file) {
                $slug = basename((string) $file, '.html');
                $out[$slug] = $out[$slug] ?? (string) $file;
            }
        }
        ksort($out);
        return $out;
    }

    /**
     * The block template a page or post is shown with: its assigned template, else the template
     * hierarchy WordPress walks for it. A template saved in the database is read through
     * WordPress's own lookup; otherwise the theme file.
     */
    private function templateFor(array $row): ?string
    {
        $native = (int) $row['ID'];
        $slug = (string) $row['post_name'];
        $assigned = (string) ($this->meta[$native]['_wp_page_template'] ?? '');
        $candidates = [];
        if ($assigned !== '' && $assigned !== 'default') {
            $candidates[] = $assigned;
        }
        if ($row['post_type'] === 'page') {
            if ((string) ($this->options['show_on_front'] ?? '') === 'page' && (int) ($this->options['page_on_front'] ?? 0) === $native) {
                $candidates[] = 'front-page';
            }
            array_push($candidates, 'page-' . $slug, 'page-' . $native, 'page', 'singular', 'index');
        } else {
            array_push($candidates, 'single-post-' . $slug, 'single-post', 'single', 'singular', 'index');
        }
        $files = $this->themeTemplates();
        $theme = (string) ($this->options['stylesheet'] ?? '');
        foreach ($candidates as $candidate) {
            if (function_exists('get_block_template')) {
                $template = get_block_template($theme . '//' . $candidate, 'wp_template');
                if ($template !== null && isset($template->content) && $template->content !== '') {
                    return (string) $template->content;
                }
            }
            if (isset($files[$candidate])) {
                return (string) file_get_contents($files[$candidate]);
            }
        }
        return null;
    }

    private function navigationBlock(string $owner, string $markup): array
    {
        $items = [];
        foreach (BlockProjection::navigationItems(parse_blocks($markup)) as $position => $link) {
            $target = null;
            if ($link['targetId'] !== null && isset($this->ids[$link['targetId']], $this->summaries[$this->ids[$link['targetId']]])) {
                $target = $this->ids[$link['targetId']];
            }
            // The local key names the target's native id; only its hash leaves the site.
            $items[] = ['id' => $this->opaque('i', $owner . '|link:' . $link['key']), 'position' => $position, 'contentId' => $target, 'fields' => [
                ['key' => 'label', 'type' => 'text', 'value' => $link['label'], 'slotKey' => null, 'semanticKey' => null],
                ['key' => 'url', 'type' => 'url', 'value' => $link['url'], 'slotKey' => null, 'semanticKey' => null],
            ]];
        }
        return ['id' => $this->opaque('b', $owner . '|links'), 'key' => 'links', 'role' => null, 'position' => 0,
            'sharedContentId' => null, 'visibility' => 'unknown', 'fields' => [], 'items' => $items];
    }

    /** @param array<string,bool> $slotted slot targets already given to an earlier block of the same name */
    private function field(array $field, array $slots, array &$slotted): array
    {
        [$name, $attr] = substr($field['key'], -4) === ':url' && strlen($field['key']) > 4
            ? [substr($field['key'], 0, -4), 'url'] : [$field['key'], 'content'];
        $target = $name . '|' . $attr;
        $slot = isset($slotted[$target]) ? null : ($slots[$target] ?? null);
        $slotted[$target] = true;
        return ['key' => $field['key'], 'type' => $field['type'], 'value' => $field['value'], 'slotKey' => $slot, 'semanticKey' => null];
    }

    /**
     * Which contract slot each named block of one post is, when the site is bound: the source
     * edition by the binding's ids (and the identity the entity names), another edition by the
     * editions profile, written `<language>::<slot>` exactly as `content.contract apply` takes it.
     *
     * @return array<string,string> `<block name>|<attr>` → slot key
     */
    private function slotsFor(int $native, array $row): array
    {
        if ($this->profile === null || !is_array($this->binding)) {
            return [];
        }
        $entities = [];
        foreach ((array) ($this->profile['content-map']['entities'] ?? []) as $entity) {
            $entities[(string) ($entity['key'] ?? '')] = $entity;
        }
        $language = $this->terms[$native]['language'][0]['slug'] ?? null;
        $match = [];
        foreach ((array) ($this->binding['ids'] ?? []) as $key => $boundId) {
            $identity = $entities[$key]['identity'] ?? [];
            $kind = (string) ($entities[$key]['kind'] ?? '');
            $type = $identity['postType'] ?? (['page' => 'page', 'post' => 'post', 'templatePart' => 'wp_template_part'][$kind] ?? null);
            if ((int) $boundId === $native && ($identity['slug'] ?? null) === $row['post_name'] && $type === $row['post_type']
                && ($this->languages === [] || !isset($identity['language']) || $identity['language'] === $language)) {
                $match[$key] = '';
            }
        }
        foreach ((array) ($this->profile['editions']['locales'] ?? []) as $slug => $edition) {
            if ($slug === ($this->profile['editions']['source']['language'] ?? QuickstartContract::SOURCE_LANGUAGE) || $slug !== $language) {
                continue;
            }
            foreach ((array) ($edition['ids'] ?? []) as $key => $copy) {
                if ((int) $copy === $native) {
                    $match[$key] = $slug . '::';
                }
            }
        }
        $out = [];
        foreach ((array) ($this->profile['content-map']['slots'] ?? []) as $slot) {
            $entity = (string) ($slot['entity'] ?? '');
            if (!isset($match[$entity], $slot['target']['block'])) {
                continue;
            }
            $missing = $match[$entity] === '' ? [] : (array) ($this->profile['editions']['locales'][rtrim($match[$entity], ':')]['missing'] ?? []);
            if (in_array($entity . ':' . $slot['target']['block'], $missing, true)) {
                continue;
            }
            $attr = (string) ($slot['target']['attr'] ?? 'content');
            $out[$slot['target']['block'] . '|' . ($attr === 'text' ? 'content' : $attr)] = $match[$entity] . $slot['key'];
        }
        return $out;
    }

    private function bindingSlotsHash(): string
    {
        return hash('sha256', json_encode([$this->binding['contract'] ?? null, $this->binding['ids'] ?? null, $this->profile['hash'] ?? null]));
    }

    /** The site identity: name, tagline and, when the theme keeps one, the identity option's fields. */
    private function siteFields(): array
    {
        $slots = [];
        if ($this->profile !== null) {
            foreach ((array) ($this->profile['content-map']['slots'] ?? []) as $slot) {
                $option = $slot['target']['option'] ?? null;
                if (is_string($option)) {
                    $slots[$option . (isset($slot['target']['field']) ? '.' . $slot['target']['field'] : '')] = (string) $slot['key'];
                }
            }
        }
        $fields = [];
        foreach (['blogname', 'blogdescription'] as $option) {
            $fields[] = ['key' => $option, 'type' => 'text', 'value' => isset($this->options[$option]) ? (string) $this->options[$option] : null,
                'slotKey' => $slots[$option] ?? null, 'semanticKey' => null];
        }
        $identity = self::unserialize($this->options['tracy_site_identity'] ?? '');
        if (is_array($identity)) {
            $flat = [];
            foreach ($identity as $key => $value) {
                if (is_array($value)) {
                    foreach ($value as $sub => $leaf) {
                        $flat[$key . '.' . $sub] = $leaf;
                    }
                } else {
                    $flat[(string) $key] = $value;
                }
            }
            ksort($flat);
            foreach ($flat as $key => $value) {
                if (is_scalar($value) || $value === null) {
                    $fields[] = ['key' => 'tracy_site_identity.' . $key, 'type' => 'text', 'value' => $value === null ? null : (string) $value,
                        'slotKey' => $slots['tracy_site_identity.' . $key] ?? null, 'semanticKey' => null];
                }
            }
        }
        return $fields;
    }

    /** One image usage, merged into the content's media list by media id. */
    private function addImage(array &$content, ?int $attachment, ?string $src, ?string $alt, ?string $blockId, ?string $itemId): void
    {
        $meta = $attachment !== null ? ($this->meta[$attachment] ?? []) : [];
        $isAttachment = $attachment !== null && isset($this->posts[$attachment]) && $this->posts[$attachment]['post_type'] === 'attachment';
        if ($src === null && $isAttachment && function_exists('wp_get_attachment_url')) {
            $url = wp_get_attachment_url($attachment);
            $src = is_string($url) ? $url : null;
        }
        if ($alt === null && $isAttachment) {
            $alt = isset($meta['_wp_attachment_image_alt']) ? (string) $meta['_wp_attachment_image_alt'] : null;
        }
        $id = $isAttachment && isset($this->ids[$attachment]) ? $this->ids[$attachment] : ($src !== null ? $this->opaque('m', 'src:' . $src) : null);
        if ($id === null) {
            return;
        }
        [$width, $height] = $isAttachment ? self::dimensions($meta, $src) : [null, null];
        $usage = ['contentId' => $content['id'], 'blockId' => $blockId, 'itemId' => $itemId];
        foreach ($content['images'] as &$image) {
            if ($image['id'] === $id) {
                if (!in_array($usage, $image['usages'], true)) {
                    $image['usages'][] = $usage;
                }
                return;
            }
        }
        unset($image);
        $content['images'][] = ['id' => $id, 'src' => $src, 'alt' => $alt, 'width' => $width, 'height' => $height, 'usages' => [$usage]];
    }

    /** Width and height of the file a src names: the original or one of its sizes; unknown is null. */
    private static function dimensions(array $meta, ?string $src): array
    {
        $data = self::unserialize($meta['_wp_attachment_metadata'] ?? '');
        if (!is_array($data) || $src === null) {
            return [null, null];
        }
        $file = basename((string) parse_url($src, PHP_URL_PATH));
        $pick = static fn($w, $h) => [is_int($w) && $w > 0 ? $w : null, is_int($h) && $h > 0 ? $h : null];
        if ($file !== '' && basename((string) ($data['file'] ?? '')) === $file) {
            return $pick($data['width'] ?? null, $data['height'] ?? null);
        }
        foreach ((array) ($data['sizes'] ?? []) as $size) {
            if (is_array($size) && ($size['file'] ?? null) === $file) {
                return $pick($size['width'] ?? null, $size['height'] ?? null);
            }
        }
        return [null, null];
    }

    /** @return string[] */
    private function tagNames(int $native): array
    {
        return array_values(array_map(static fn($t) => (string) $t['name'], $this->terms[$native]['post_tag'] ?? []));
    }

    /** Translations Polylang groups with this row, and its parent page — each only when readable. */
    private function relations(int $native): array
    {
        $out = [];
        $translation = $this->terms[$native]['post_translations'][0] ?? null;
        $ownLocale = $this->summaries[$this->ids[$native]]['locale'];
        if ($translation !== null && $ownLocale !== null) {
            $members = self::unserialize($translation['description']);
            foreach (is_array($members) ? $members : [] as $other) {
                $other = (int) $other;
                if ($other === $native || !isset($this->ids[$other], $this->summaries[$this->ids[$other]])) {
                    continue;
                }
                $theirs = $this->summaries[$this->ids[$other]];
                if ($theirs['translationGroupId'] !== null && $theirs['locale'] !== null && $theirs['locale'] !== $ownLocale) {
                    $out[] = ['type' => 'translation', 'contentId' => $this->ids[$other]];
                }
            }
        }
        $parent = (int) $this->posts[$native]['post_parent'];
        if ($parent > 0 && isset($this->ids[$parent], $this->summaries[$this->ids[$parent]])) {
            $out[] = ['type' => 'parent', 'contentId' => $this->ids[$parent]];
        }
        return $out;
    }

    private static function unserialize($value)
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        // Stored WordPress data, never caller input; objects are refused all the same.
        $out = @unserialize($value, ['allowed_classes' => false]);
        return $out === false && $value !== 'b:0;' ? null : $out;
    }

    private static function decodeJson($value): ?array
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        $out = json_decode($value, true);
        return is_array($out) ? $out : null;
    }

    // ---- identity ---------------------------------------------------------------------------

    /**
     * Give every identified row a content uid, and the site its content key: the explicit,
     * opt-in write behind the `content.identity` action. Idempotent; a uid a copy duplicated is
     * replaced on the newer row, the older keeps its own.
     *
     * @return array{site:bool,minted:int,repaired:int,total:int}
     */
    public static function ensureIdentity(): array
    {
        global $wpdb;
        $site = false;
        if (!preg_match('/^[a-f0-9]{32,64}$/D', (string) get_option(self::SITE_OPTION, ''))) {
            add_option(self::SITE_OPTION, bin2hex(random_bytes(16)), '', 'no');
            $site = true;
        }
        $rows = $wpdb->get_results('SELECT p.ID, m.meta_id, m.meta_value FROM ' . $wpdb->posts . ' p LEFT JOIN ' . $wpdb->postmeta
            . ' m ON m.post_id = p.ID AND m.meta_key = ' . $wpdb->prepare('%s', self::UID_META)
            . ' WHERE p.post_type IN (' . self::inList(self::IDENTIFIED_TYPES) . ") AND p.post_status NOT IN ('auto-draft','inherit') OR p.post_type = 'attachment'"
            . ' ORDER BY p.ID, m.meta_id', ARRAY_A);
        $minted = 0;
        $repaired = 0;
        $seen = [];
        $ids = [];
        foreach ((array) $rows as $row) {
            $id = (int) $row['ID'];
            $value = (string) ($row['meta_value'] ?? '');
            if (isset($ids[$id])) {
                // A second uid row on one post: keep the first.
                $wpdb->delete($wpdb->postmeta, ['meta_id' => (int) $row['meta_id']], ['%d']);
                $repaired++;
                continue;
            }
            $ids[$id] = true;
            if (preg_match('/^[a-f0-9]{32}$/D', $value) && !isset($seen[$value])) {
                $seen[$value] = true;
                continue;
            }
            $uid = bin2hex(random_bytes(16));
            if ($row['meta_id'] !== null) {
                $wpdb->update($wpdb->postmeta, ['meta_value' => $uid], ['meta_id' => (int) $row['meta_id']], ['%s'], ['%d']);
                $repaired++;
            } else {
                $wpdb->insert($wpdb->postmeta, ['post_id' => $id, 'meta_key' => self::UID_META, 'meta_value' => $uid], ['%d', '%s', '%s']);
                $minted++;
            }
            $seen[$uid] = true;
            wp_cache_delete($id, 'post_meta');
        }
        return ['site' => $site, 'minted' => $minted, 'repaired' => $repaired, 'total' => count($ids)];
    }
}
