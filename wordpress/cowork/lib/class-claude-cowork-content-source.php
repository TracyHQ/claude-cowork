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
 * A content id is an HMAC, under this site's content seed, of a random uid the site gives each
 * row once (`_tracy_content_uid`). It survives a new title, slug, parent, order, language and a
 * new domain; a deleted row takes its uid with it, so a row created again is new; a fork rotates
 * the seed (`newSite`) and with it every id and every cursor. A database copied by hand keeps the
 * seed — two sites with the same ids — until whoever made the copy declares the fork. The uid
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
    private $open = false;
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

    /**
     * The content id and revision of each row a contract write lands on, exactly as this reader
     * lists them in `contents[]`: a post or a database template part by its native id, a theme
     * file part by its slug, and every option (and a language's string translation of one) by the
     * site identity content that shows it. Null for a row this scope lists no content for.
     *
     * @param array<int|string,array{kind:string,id:int,key:string}> $targets
     * @return array<int|string,?array{id:string,revision:string}>
     */
    public function revisionsOf(array $targets): array
    {
        $this->load();
        $out = [];
        foreach ($targets as $index => $target) {
            $id = $this->contentIdOf((string) ($target['kind'] ?? ''), (int) ($target['id'] ?? 0), (string) ($target['key'] ?? ''));
            $out[$index] = $id !== null && isset($this->summaries[$id]) ? ['id' => $id, 'revision' => (string) $this->summaries[$id]['revision']] : null;
        }
        return $out;
    }

    private function contentIdOf(string $kind, int $native, string $key): ?string
    {
        if ($kind === 'option' || $kind === 'optionTranslation') {
            return $this->siteContentId();
        }
        if ($kind !== 'post' && $kind !== 'templatePart') {
            return null;
        }
        if ($native > 0 && isset($this->ids[$native])) {
            return $this->ids[$native];
        }
        if ($kind === 'templatePart' && isset($this->themeParts[$key])) {
            return $this->themePartId($this->themeParts[$key]);
        }
        return null;
    }

    public function detail(string $id, bool $withBody = true): ?array
    {
        $this->load();
        self::checkpoint('detail-before-body');
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
        if (!$withBody) {
            $content['bodyHtml'] = null;
        }
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

    /**
     * Open ONE consistent read and keep it open until `release()`: the fingerprint, the rows, a
     * detail's body, its template and its attachments are all read from the same state of the
     * database, so a write landing at any point of the request is either wholly in it or wholly
     * out of it — and the next page's cursor sees the new fingerprint and answers 409.
     */
    private function load(): void
    {
        if ($this->loaded) {
            return;
        }
        global $wpdb;
        if (!is_object($wpdb) || !($wpdb->dbh instanceof mysqli)) {
            throw new ContentReadError('CONTENT_ADAPTER_UNSUPPORTED', 501, 'This site does not use a MySQL connection the reader supports.');
        }
        // A persistent object cache answers get_post()/get_post_meta() from outside the snapshot;
        // until that is measured, such a site is not served rather than served a mix.
        if (function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache()) {
            throw new ContentReadError('CONTENT_ADAPTER_UNSUPPORTED', 501, 'This site uses a persistent object cache, which the reader does not support yet.');
        }
        $wpdb->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        if ($wpdb->query('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY') === false) {
            throw new RuntimeException('Could not open a consistent read');
        }
        $this->open = true;
        try {
            self::checkpoint('snapshot-open');
            $this->read();
            self::checkpoint('snapshot-loaded');
        } catch (Throwable $e) {
            $this->release();
            throw $e;
        }
        $this->loaded = true;
    }

    public function release(): void
    {
        if ($this->open) {
            global $wpdb;
            $wpdb->query('COMMIT');
            $this->open = false;
        }
    }

    public function __destruct()
    {
        $this->release();
    }

    /**
     * A named point inside the consistent read. Nothing listens in production; the acceptance
     * harness hooks it to hold a request there while it writes, so an interleaving is placed on
     * purpose instead of hoped for.
     */
    private static function checkpoint(string $point): void
    {
        if (function_exists('do_action')) {
            do_action('claude_cowork_content_checkpoint', $point);
        }
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
        // The key is the site's seed alone: a new domain, HTTPS, a subdirectory or another server
        // keep every id. Only `content.identity {newSite: true}` (a fork) changes it.
        $this->key = hash_hmac('sha256', 'tracy-content-id/v1', $siteKey);
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

    /** The one shared content for the options every page prints. */
    private function siteContentId(): string
    {
        return $this->opaque('c', 'option:site');
    }

    /** A template part served from the theme's file, not overridden by a database row. */
    private function themePartId(array $part): string
    {
        return $this->opaque('c', 'theme-part:' . $part['theme'] . '//' . $part['slug']);
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
        $siteId = $this->siteContentId();
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
            $id = $this->themePartId($part);
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
        // Same transaction as the fingerprint (see load()); the hash check stays as a second line.
        $row = $this->query($wpdb->prepare('SELECT post_content, MD5(post_content) AS content_md5 FROM ' . $wpdb->posts . ' WHERE ID = %d', $native));
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
            $id = $this->themePartId($part);
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
            // A projected block has one field under its bare name: its text, or for a
            // `core/image` its picture — so a `text` slot and an image (`src`) slot both land there.
            $out[$slot['target']['block'] . '|' . ($attr === 'text' || $attr === 'src' ? 'content' : $attr)] = $match[$entity] . $slot['key'];
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

    // ---- identity ----------------------------------------------------------------------------

    public const MARKER_OPTION = 'claude_cowork_content_identity';

    /** The site's content seed, or null before opt-in. Read by the plugin to key cursors. */
    public static function seed(): ?string
    {
        $seed = trim((string) get_option(self::SITE_OPTION, ''));
        return preg_match('/^[a-f0-9]{32,64}$/D', $seed) ? $seed : null;
    }

    /**
     * Give the site its content seed and every identified row a uid: the explicit, opt-in write
     * behind `content.identity`. Idempotent; a uid a copy duplicated is replaced on the newer row.
     *
     * `$newSite` is a FORK: a new seed, so a new `site.id`, new content/block/item/media ids and no
     * valid cursor from before. Row uids stay. It is called by provisioning or a fork operation
     * that already checked its own authority, with a `$requestId` it keeps across its retries: the
     * same request id never rotates twice. A restore of the same site is not a fork and keeps the
     * seed. One run at a time (MySQL named lock); a marker records a run that did not finish.
     *
     * @return array{site:bool,rotated:bool,replayed:bool,minted:int,repaired:int,total:int}
     */
    public static function ensureIdentity(bool $newSite = false, ?string $requestId = null): array
    {
        global $wpdb;
        if ($newSite && ($requestId === null || !preg_match('/^[A-Za-z0-9_-]{8,64}$/D', $requestId))) {
            throw new InvalidArgumentException('A fork needs a request id');
        }
        $lock = 'claude_cowork_content_identity_' . substr(md5((string) $wpdb->prefix), 0, 8);
        if ((string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 10)', $lock)) !== '1') {
            throw new RuntimeException('Another content identity run holds the lock');
        }
        try {
            $marker = self::decodeJson(get_option(self::MARKER_OPTION, '')) ?? [];
            $site = false;
            $rotated = false;
            $replayed = false;
            if (self::seed() === null) {
                self::putOption(self::SITE_OPTION, bin2hex(random_bytes(16)));
                $site = true;
            }
            if ($newSite) {
                if (($marker['fork']['requestId'] ?? null) === $requestId) {
                    $replayed = true;
                } else {
                    self::putOption(self::SITE_OPTION, bin2hex(random_bytes(16)));
                    $marker['fork'] = ['requestId' => $requestId, 'at' => gmdate('c')];
                    $rotated = true;
                }
            }
            $marker['v'] = 1;
            $marker['state'] = 'running';
            self::putOption(self::MARKER_OPTION, (string) json_encode($marker));
            // A run that dies from here on leaves `state: running`; the next call with the same
            // request id does not rotate again and finishes the backfill.
            self::checkpoint('identity-backfill');
            $counts = self::backfill();
            $marker['state'] = 'complete';
            $marker['at'] = gmdate('c');
            self::putOption(self::MARKER_OPTION, (string) json_encode($marker));
            return ['site' => $site, 'rotated' => $rotated, 'replayed' => $replayed] + $counts;
        } finally {
            $wpdb->query($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
        }
    }

    private static function putOption(string $name, string $value): void
    {
        global $wpdb;
        // Written through the table, not update_option(): no filter may veto or transform the seed,
        // and a failure is an error here rather than a silent `false`.
        $done = $wpdb->query($wpdb->prepare('INSERT INTO ' . $wpdb->options . " (option_name, option_value, autoload) VALUES (%s, %s, 'off')"
            . ' ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)', $name, $value));
        if ($done === false) {
            throw new RuntimeException('Could not write ' . $name);
        }
        wp_cache_delete($name, 'options');
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('notoptions', 'options');
    }

    /** @return array{minted:int,repaired:int,total:int} */
    private static function backfill(): array
    {
        global $wpdb;
        $rows = $wpdb->get_results('SELECT p.ID, m.meta_id, m.meta_value FROM ' . $wpdb->posts . ' p LEFT JOIN ' . $wpdb->postmeta
            . ' m ON m.post_id = p.ID AND m.meta_key = ' . $wpdb->prepare('%s', self::UID_META)
            . ' WHERE p.post_type IN (' . self::inList(self::IDENTIFIED_TYPES) . ") AND p.post_status NOT IN ('auto-draft','inherit') OR p.post_type = 'attachment'"
            . ' ORDER BY p.ID, m.meta_id', ARRAY_A);
        if (!is_array($rows) || $wpdb->last_error !== '') {
            throw new RuntimeException('Could not read the rows to identify');
        }
        $minted = 0;
        $repaired = 0;
        $seen = [];
        $ids = [];
        foreach ($rows as $row) {
            $id = (int) $row['ID'];
            $value = (string) ($row['meta_value'] ?? '');
            if (isset($ids[$id])) {
                // A second uid row on one post: keep the first.
                $done = $wpdb->delete($wpdb->postmeta, ['meta_id' => (int) $row['meta_id']], ['%d']);
                $repaired++;
            } else {
                $ids[$id] = true;
                if (preg_match('/^[a-f0-9]{32}$/D', $value) && !isset($seen[$value])) {
                    $seen[$value] = true;
                    continue;
                }
                $uid = bin2hex(random_bytes(16));
                if ($row['meta_id'] !== null) {
                    $done = $wpdb->update($wpdb->postmeta, ['meta_value' => $uid], ['meta_id' => (int) $row['meta_id']], ['%s'], ['%d']);
                    $repaired++;
                } else {
                    $done = $wpdb->insert($wpdb->postmeta, ['post_id' => $id, 'meta_key' => self::UID_META, 'meta_value' => $uid], ['%d', '%s', '%s']);
                    $minted++;
                }
                $seen[$uid] = true;
            }
            if ($done === false) {
                throw new RuntimeException('Could not write a content uid');
            }
            wp_cache_delete($id, 'post_meta');
        }
        return ['minted' => $minted, 'repaired' => $repaired, 'total' => count($ids)];
    }
}

/**
 * `content.contract apply`'s view of `content.read` revisions: a fresh reader per question, in
 * the editorial scope (a governed page may be a draft), its consistent read released before the
 * answer returns so no snapshot is held open across the writes that follow.
 */
final class Claude_Cowork_Content_Revisions implements ContentRevisions
{
    /** @var string */
    private $contractsDir;
    /** @var string */
    private $version;

    public function __construct(string $contractsDir, string $version)
    {
        $this->contractsDir = $contractsDir;
        $this->version = $version;
    }

    public function of(array $targets): array
    {
        $source = new Claude_Cowork_Content_Source('editorial', $this->contractsDir, $this->version);
        try {
            return $source->revisionsOf($targets);
        } finally {
            $source->release();
        }
    }
}
