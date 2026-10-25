<?php
declare(strict_types=1);

// Which post meta row or option holds a Media Library picture (AttachmentReferences), read from the
// value for every theme alike, and how media.delete reports it beside `usedIn` (`usedInStorage`).

require_once __DIR__ . '/../lib/AttachmentReferences.php';

$arMeta = ['file' => '2026/10/blog.jpg', 'original_image' => 'blog-orig.jpg', 'sizes' => [
    'thumbnail' => ['file' => 'blog-150x150.jpg'],
    'medium' => ['file' => 'blog-300x200.jpg'],
]];
$ar = AttachmentReferences::forAttachment(24, 'https://shop.example/wp-content/uploads', '2026/10/blog.jpg', $arMeta);

// The shapes a theme keeps a picture in. A bare number is evidence only through its key's name.
check('an id as a number under a picture key is the id shape', $ar->match(24, 'jd_intro_image'), ['shape' => 'id']);
check('an id as a numeric string under a theme option is the id shape', $ar->match('24', 'wp_ja_diner_blog_banner_image'), ['shape' => 'id']);
check('_thumbnail_id is named', $ar->match('24', '_thumbnail_id'), ['shape' => 'id']);
check('site_logo is named', $ar->match('24', 'site_logo'), ['shape' => 'id']);
check('a counter that equals the id is not a picture', $ar->match('24', 'custom_counter'), null);
check('an image width that equals the id is not a picture', $ar->match('24', 'image_width'), null);
check('another record\'s id under a picture word is not a picture', $ar->match('24', 'hero_post_id'), null);
check('image_id is the picture\'s id', $ar->match('24', 'image_id'), ['shape' => 'id']);
check('a camelCase picture key counts', $ar->match(' 24 ', 'blogBannerImage'), ['shape' => 'id']);
check('another number is not this picture', $ar->match('245', 'jd_intro_image'), null);
check('the original URL is the url shape, size full, whatever the key', $ar->match('https://shop.example/wp-content/uploads/2026/10/blog.jpg', 'custom_counter'), ['shape' => 'url', 'size' => 'full']);
check('a registered size URL names its size', $ar->match('http://cdn.example/wp-content/uploads/2026/10/blog-300x200.jpg', 'x'), ['shape' => 'url', 'size' => 'medium']);
check('a relative URL with a query string counts', $ar->match('/wp-content/uploads/2026/10/blog-150x150.jpg?v=2', 'x'), ['shape' => 'url', 'size' => 'thumbnail']);
check('the pre-scaling original is size original', $ar->match('https://shop.example/wp-content/uploads/2026/10/blog-orig.jpg', 'x'), ['shape' => 'url', 'size' => 'original']);
check('a longer file name is not this picture', $ar->match('https://shop.example/wp-content/uploads/2026/10/blog.jpg.webp', 'x'), null);
check('a picture of another month is not this picture', $ar->match('https://shop.example/wp-content/uploads/2026/09/blog.jpg', 'x'), null);
check('JSON with an escaped-slash size URL is the json shape', $ar->match('{"hero":{"url":"https:\/\/shop.example\/wp-content\/uploads\/2026\/10\/blog-300x200.jpg"}}', 'builder'), ['shape' => 'json', 'size' => 'medium']);
check('JSON with the id under a picture key is the json shape', $ar->match('{"image":{"id":24,"alt":""}}', 'builder'), ['shape' => 'json']);
check('JSON with the id spaced over lines is still read', $ar->match("{\"image\":\n 24}", 'builder'), ['shape' => 'json']);
check('JSON logo list entries count, spaced', $ar->match('{"logos":[ 24 ]}', 'builder'), ['shape' => 'json']);
check('JSON with 24 under a width is not a picture', $ar->match('{"image":{"width":24}}', 'builder'), null);
check('JSON slide entries by id count', $ar->match('{"slides":[{"id":24}]}', 'builder'), ['shape' => 'json']);
// List words name their array's entries: gallery, galleries, slides, slider, carousel (and images, photos, media_ids… by picture word).
foreach (['{"gallery":[ 24 ]}', '{"galleries":[[23,24]]}', '{"slider":[23, 24]}', '{"homeCarousel":[24]}', '{"gallery":{"ids":[23,24]}}',
    '{"images":[24]}', '{"imgs":[24]}', '{"photos":[24]}', '{"pictures":[24]}', '{"media_ids":[24]}', '{"attachments":[24]}'] as $arList) {
    check('a list of pictures: ' . $arList, $ar->match($arList, 'builder'), ['shape' => 'json']);
}
check('a gallery list stored as its own row', $ar->match(serialize([23, 24]), 'product_gallery'), ['shape' => 'serialized']);
check('a gallery number not in a list is not a picture', $ar->match('{"gallery":24}', 'builder'), null);
check('a gallery count is not a picture', $ar->match('{"gallery_count":[24]}', 'builder'), null);
check('a slider width list is not a picture', $ar->match('{"slider_width":[24]}', 'builder'), null);
check('a list under no picture word is not a picture', $ar->match('{"related":[24]}', 'builder'), null);
check('serialized theme mods with the id under a picture key', $ar->match(serialize(['blog_banner_image' => 24, 'posts_count' => 3]), 'theme_mods_ja-diner'), ['shape' => 'serialized']);
check('serialized with the id as a string under a logo key', $ar->match(serialize(['custom_logo' => '24']), 'theme_mods_x'), ['shape' => 'serialized']);
check('serialized with a size URL names its size', $ar->match(serialize(['slides' => [['src' => 'https://shop.example/wp-content/uploads/2026/10/blog-150x150.jpg']]]), 'x'), ['shape' => 'serialized', 'size' => 'thumbnail']);
check('serialized image_width that equals the id is not a picture', $ar->match(serialize(['image_width' => 24]), 'theme_mods_x'), null);
check('a serialized bare number takes the row key: counter no', $ar->match('i:24;', 'custom_counter'), null);
check('a serialized bare number takes the row key: picture yes', $ar->match('i:24;', 'jd_intro_image'), ['shape' => 'serialized']);
$arObject = 'O:8:"stdClass":1:{s:13:"background_id";i:24;}';
check('a serialized object is read without building it', $ar->match($arObject, 'x'), ['shape' => 'serialized']);
check('no uploads URL: only the id is matched', AttachmentReferences::forAttachment(24, '', '2026/10/blog.jpg', $arMeta)->match('https://shop.example/wp-content/uploads/2026/10/blog.jpg', 'x'), null);
checkTrue('the SQL needles are the size file names, which survive JSON slash escaping', array_diff(['blog.jpg', 'blog-orig.jpg', 'blog-150x150.jpg', 'blog-300x200.jpg'], $ar->likeNeedles()) === []);
// The SQL narrows, the PHP decides: every value match() takes passes the SQL test (here as MySQL REGEXP/LIKE read it).
$arSqlKeeps = static function (string $value) use ($ar): bool {
    if (preg_match('/' . $ar->sqlIdPattern() . '/', $value)) return true;
    foreach ($ar->likeNeedles() as $needle) if (str_contains($value, $needle)) return true;
    return false;
};
foreach ([[24, 'jd_intro_image'], ["{\"image\":\n 24}", 'b'], ['{"logos":[ 24 ]}', 'b'], ['{"image" : "24"}', 'b'], ["a:1:{s:4:\"logo\";i:24;}", 'b'],
    ['{"u":"https:\/\/shop.example\/wp-content\/uploads\/2026\/10\/blog-300x200.jpg"}', 'b'], ['  24  ', 'site_icon'], ['{"gallery":[ 24 ]}', 'b'], ["{\"slides\":[\n\t{\"id\":\t24}]}", 'b']] as [$arValue, $arKey]) {
    checkTrue('the SQL keeps ' . json_encode($arValue), $ar->match($arValue, $arKey) !== null && $arSqlKeeps((string) $arValue));
}
// JSON writes a non-ASCII file name as \u escapes: the SQL looks for that form too.
$arCafe = AttachmentReferences::forAttachment(31, 'https://shop.example/wp-content/uploads', '2026/10/café.jpg', ['file' => '2026/10/café.jpg', 'sizes' => ['medium' => ['file' => 'café-300x200.jpg']]]);
$arCafeJson = json_encode(['card' => ['src' => 'https://shop.example/wp-content/uploads/2026/10/café-300x200.jpg']]);
check('a JSON-escaped non-ASCII URL is read', $arCafe->match($arCafeJson, 'builder'), ['shape' => 'json', 'size' => 'medium']);
checkTrue('the SQL needles carry the JSON-escaped file name', in_array('caf\\u00e9-300x200.jpg', $arCafe->likeNeedles(), true) && in_array('café-300x200.jpg', $arCafe->likeNeedles(), true));
checkTrue('the SQL keeps the JSON-escaped value', (static function (string $value) use ($arCafe): bool {
    foreach ($arCafe->likeNeedles() as $needle) if (str_contains($value, $needle)) return true;
    return false;
})($arCafeJson) && str_contains($arCafeJson, '\\u00e9'));
checkTrue('the SQL needles of an ASCII name are not doubled', count($ar->likeNeedles()) === count(array_unique(array_map('basename', ['blog.jpg', 'blog-orig.jpg', 'blog-150x150.jpg', 'blog-300x200.jpg']))));
checkTrue('the SQL drops a longer number', !$arSqlKeeps('{"image":245}') && !$arSqlKeeps('1240'));

// WordPress bookkeeping is not a picture a theme shows; the featured image and the site logo are.
foreach (['_wp_attached_file', '_wp_attachment_metadata', '_edit_lock', '_edit_last', '_claude_cowork_seo_title', '_tracy_content_uid'] as $arKey) {
    checkTrue("post meta {$arKey} is skipped", AttachmentReferences::skipMetaKey($arKey));
}
checkTrue('_thumbnail_id is kept', !AttachmentReferences::skipMetaKey('_thumbnail_id'));
checkTrue('a theme meta key is kept', !AttachmentReferences::skipMetaKey('jd_intro_image'));
foreach (['_transient_feed_x', '_site_transient_update_core', 'cron', 'rewrite_rules', 'claude_cowork_token', 'thumbnail_size_w'] as $arKey) {
    checkTrue("option {$arKey} is skipped", AttachmentReferences::skipOption($arKey));
}
checkTrue('site_logo is kept', !AttachmentReferences::skipOption('site_logo'));
checkTrue('theme mods are kept', !AttachmentReferences::skipOption('theme_mods_ja-diner'));

// The real media writer: each table walked by primary-key ranges, prepared, read-only. The fake
// applies the ranges and the REGEXP/LIKE narrowing as MySQL would, so the PHP decision is tested behind it.
final class ArFakeDb
{
    public string $postmeta = 'wp_postmeta';
    public string $options = 'wp_options';
    public array $metaRows = [];
    public array $optionRows = [];
    public array $prepared = [];
    public array $queries = [];
    public string $last_error = '';
    /** @var string|null the table whose range queries fail */
    public ?string $failOn = null;
    private array $args = [];
    public function esc_like(string $t): string { return addcslashes($t, '_%\\'); }
    public function prepare(string $q, ...$args): string
    {
        if (count($args) === 1 && is_array($args[0])) $args = $args[0];
        $this->prepared[] = [$q, $args];
        $this->args = $args;
        return $q;
    }
    private function table(string $q): array
    {
        return strpos($q, 'wp_postmeta') !== false ? [$this->metaRows, 'meta_id', 'meta_value', 'postmeta'] : [$this->optionRows, 'option_id', 'option_value', 'options'];
    }
    public function get_var(string $q)
    {
        $this->queries[] = $q;
        $this->last_error = '';
        [$rows, $idCol] = $this->table($q);
        return $rows === [] ? null : (string) max(array_column($rows, $idCol));
    }
    public function get_results(string $q, $output = 'OBJECT')
    {
        $this->queries[] = $q;
        $this->last_error = '';
        [$rows, $idCol, $valueCol, $name] = $this->table($q);
        if ($this->failOn === $name) { $this->last_error = "Table '{$name}' is marked as crashed"; return null; }
        [$from, $to] = $this->args;
        $regexp = '';
        $likes = [];
        foreach ($this->args as $arg) {
            if (is_string($arg) && str_starts_with($arg, '(^|')) $regexp = $arg;
            elseif (is_string($arg) && str_starts_with($arg, '%') && str_ends_with($arg, '%')) $likes[] = stripcslashes(substr($arg, 1, -1));
        }
        return array_values(array_filter($rows, static function (array $row) use ($from, $to, $idCol, $valueCol, $regexp, $likes): bool {
            $id = (int) $row[$idCol];
            if ($id <= $from || $id > $to) return false;
            $value = (string) $row[$valueCol];
            if ($regexp !== '' && preg_match('/' . $regexp . '/', $value)) return true;
            foreach ($likes as $like) if (str_contains($value, $like)) return true;
            return false;
        }));
    }
}
$arWasDb = $GLOBALS['wpdb'];
$arDb = new ArFakeDb();
$GLOBALS['wpdb'] = $arDb;
try {
    WP_Fake::$meta['24:_wp_attached_file'] = '2026/10/blog.jpg';
    WP_Fake::$meta['24:_wp_attachment_metadata'] = $arMeta;
    $arDb->metaRows = [
        ['meta_id' => 1, 'post_id' => 7, 'meta_key' => 'jd_intro_image', 'meta_value' => '24'],
        ['meta_id' => 2, 'post_id' => 8, 'meta_key' => '_thumbnail_id', 'meta_value' => '24'],
        ['meta_id' => 3, 'post_id' => 9, 'meta_key' => '_edit_last', 'meta_value' => '24'],
        ['meta_id' => 4, 'post_id' => 24, 'meta_key' => 'jd_intro_image', 'meta_value' => '24'],
        ['meta_id' => 5, 'post_id' => 10, 'meta_key' => '_claude_cowork_seo_title', 'meta_value' => '24'],
        ['meta_id' => 6, 'post_id' => 11, 'meta_key' => 'hero', 'meta_value' => "{\"image\":\n {\"id\": \"24\"}}"],
        ['meta_id' => 7, 'post_id' => 12, 'meta_key' => 'stock', 'meta_value' => '{"count":24}'],
        ['meta_id' => 8, 'post_id' => 13, 'meta_key' => 'custom_counter', 'meta_value' => '24'],
        // Past the first range: found by the next one. (The fake site has no uploads URL, so URL rows are covered above.)
        ['meta_id' => 15000, 'post_id' => 14, 'meta_key' => 'card_bg', 'meta_value' => '[24]'],
    ];
    $arDb->optionRows = [
        ['option_id' => 1, 'option_name' => 'wp_ja_diner_blog_banner_image', 'option_value' => '24'],
        ['option_id' => 2, 'option_name' => 'theme_mods_ja-diner', 'option_value' => serialize(['image_width' => 24, 'custom_logo' => 24])],
        ['option_id' => 3, 'option_name' => '_transient_x', 'option_value' => '24'],
        ['option_id' => 4, 'option_name' => 'cron', 'option_value' => serialize([24 => ['hook' => 'x']])],
        ['option_id' => 5, 'option_name' => 'ja_items_count', 'option_value' => '24'],
    ];
    $arFound = (new Claude_Cowork_Media_Writer())->attachmentStorageUsage(24);
    check('post meta holding the picture; internals, the attachment\'s own rows and counters skipped', $arFound['postmeta'], [
        ['post_id' => 7, 'key' => 'jd_intro_image', 'shape' => 'id'],
        ['post_id' => 8, 'key' => '_thumbnail_id', 'shape' => 'id'],
        ['post_id' => 11, 'key' => 'hero', 'shape' => 'json'],
        ['post_id' => 14, 'key' => 'card_bg', 'shape' => 'json'],
    ]);
    check('options holding the picture; transients, cron and counters skipped', $arFound['options'], [
        ['option' => 'wp_ja_diner_blog_banner_image', 'shape' => 'id'],
        ['option' => 'theme_mods_ja-diner', 'shape' => 'serialized'],
    ]);
    check('a walk that reached each last id is complete', [$arFound['truncated'], $arFound['scanned']['postmeta'], $arFound['scanned']['options']],
        [false, ['after' => 0, 'through' => 15000, 'lastId' => 15000, 'complete' => true], ['after' => 0, 'through' => 5, 'lastId' => 5, 'complete' => true]]);
    checkTrue('no errors key when every query answered', !isset($arFound['errors']));
    $arMetaQueries = array_values(array_filter($arDb->prepared, static fn(array $q): bool => str_contains($q[0], 'FROM wp_postmeta')));
    check('the post meta walk is two primary-key ranges', array_map(static fn(array $q): array => array_slice($q[1], 0, 2), $arMetaQueries), [[0, 10000], [10000, 15000]]);
    [$arMetaSql, $arMetaArgs] = $arMetaQueries[0];
    checkTrue('each statement is bounded by an id range, has no LIMIT, and skips the internals in SQL', str_contains($arMetaSql, 'meta_id > %d AND meta_id <= %d') && !str_contains($arMetaSql, 'LIMIT')
        && str_contains($arMetaSql, 'meta_key NOT IN (') && in_array('_wp_attachment_metadata', $arMetaArgs, true) && in_array('\_claude\_cowork\_%', $arMetaArgs, true));
    checkTrue('every value goes through a placeholder', !str_contains($arMetaSql, '24') && in_array('(^|[^0-9])24([^0-9]|$)', $arMetaArgs, true) && in_array(24, $arMetaArgs, true));
    $arOptQueries = array_values(array_filter($arDb->prepared, static fn(array $q): bool => str_contains($q[0], 'FROM wp_options')));
    [$arOptSql, $arOptArgs] = $arOptQueries[0];
    checkTrue('the option walk is bounded by option_id and skips transients in SQL', str_contains($arOptSql, 'option_id > %d AND option_id <= %d') && str_contains($arOptSql, 'option_value REGEXP %s')
        && !str_contains($arOptSql, 'meta_value') && !str_contains($arOptSql, 'post_id') && in_array('\_transient\_%', $arOptArgs, true) && in_array('cron', $arOptArgs, true));
    checkTrue('the search only reads', array_filter($arDb->queries, static fn(string $q): bool => !str_starts_with(ltrim($q), 'SELECT')) === []);

    // No match anywhere still examines no more than MAX_SCAN ids, says where it stopped, and resumes there.
    $arFar = AttachmentReferences::MAX_SCAN + 5;
    $arDb->prepared = [];
    $arDb->metaRows = [['meta_id' => 3, 'post_id' => 1, 'meta_key' => 'note', 'meta_value' => 'hello'], ['meta_id' => $arFar, 'post_id' => 15, 'meta_key' => 'jd_intro_image', 'meta_value' => '24']];
    $arDb->optionRows = [];
    $arFirst = (new Claude_Cowork_Media_Writer())->attachmentStorageUsage(24);
    check('a walk stops at the ceiling, truncated, saying where', [$arFirst['postmeta'], $arFirst['truncated'], $arFirst['scanned']['postmeta']],
        [[], true, ['after' => 0, 'through' => AttachmentReferences::MAX_SCAN, 'lastId' => $arFar, 'complete' => false]]);
    $arRanges = array_map(static fn(array $q): array => array_slice($q[1], 0, 2), array_values(array_filter($arDb->prepared, static fn(array $q): bool => str_contains($q[0], 'FROM wp_postmeta'))));
    checkTrue('no statement spans more than one chunk', array_filter($arRanges, static fn(array $r): bool => $r[1] - $r[0] > AttachmentReferences::SCAN_CHUNK) === []
        && count($arRanges) === intdiv(AttachmentReferences::MAX_SCAN, AttachmentReferences::SCAN_CHUNK));
    check('an empty table is complete at once', $arFirst['scanned']['options'], ['after' => 0, 'through' => 0, 'lastId' => 0, 'complete' => true]);
    $arNext = (new Claude_Cowork_Media_Writer())->attachmentStorageUsage(24, ['postmeta' => $arFirst['scanned']['postmeta']['through']]);
    check('resuming after where it stopped finds the rest', [$arNext['postmeta'], $arNext['truncated'], $arNext['scanned']['postmeta']['complete']],
        [[['post_id' => 15, 'key' => 'jd_intro_image', 'shape' => 'id']], false, true]);

    // More references than the cap: MAX_HITS reported, resumable at the first one left out.
    $arDb->metaRows = [];
    for ($i = 1; $i <= AttachmentReferences::MAX_HITS + 1; $i++) $arDb->metaRows[] = ['meta_id' => $i, 'post_id' => 1000 + $i, 'meta_key' => 'card_image', 'meta_value' => '24'];
    $arCapped = (new Claude_Cowork_Media_Writer())->attachmentStorageUsage(24);
    check('a capped search reports at most MAX_HITS rows', count($arCapped['postmeta']), AttachmentReferences::MAX_HITS);
    check('a capped search says truncated and resumes at the first row left out', [$arCapped['truncated'], $arCapped['scanned']['postmeta']['through'], $arCapped['scanned']['postmeta']['complete']],
        [true, AttachmentReferences::MAX_HITS, false]);

    // A query that fails is an error for its table, never an empty "held nowhere".
    $arDb->metaRows = [['meta_id' => 1, 'post_id' => 7, 'meta_key' => 'jd_intro_image', 'meta_value' => '24']];
    $arDb->optionRows = [['option_id' => 1, 'option_name' => 'site_logo', 'option_value' => '24']];
    $arDb->failOn = 'postmeta';
    $arBroken = (new Claude_Cowork_Media_Writer())->attachmentStorageUsage(24);
    check('a failed post meta query is named in errors', $arBroken['errors'] ?? null, ['postmeta' => "Table 'postmeta' is marked as crashed"]);
    check('and the answer is truncated, the post meta walk not complete', [$arBroken['truncated'], $arBroken['scanned']['postmeta']['complete']], [true, false]);
    check('the other table still answers', $arBroken['options'], [['option' => 'site_logo', 'shape' => 'id']]);
    $arDb->failOn = null;

    // The post-body search behind usedIn: a failed query throws, so media.usage says read_failed and
    // media.delete deletes nothing, instead of an empty usedIn reading as "used nowhere".
    $GLOBALS['wpdb'] = new class {
        public string $posts = 'wp_posts';
        public string $postmeta = 'wp_postmeta';
        public string $last_error = '';
        public function esc_like(string $t): string { return $t; }
        public function prepare(string $q, ...$args): string { return $q; }
        public function get_results(string $q, $output = 'OBJECT') { $this->last_error = 'Lost connection to MySQL server'; return null; }
    };
    $arUsageError = '';
    try { (new Claude_Cowork_Media_Writer())->attachmentUsage(24, []); } catch (RuntimeException $e) { $arUsageError = $e->getMessage(); }
    checkTrue('a failed post-body search throws, naming the error', str_contains($arUsageError, 'Lost connection to MySQL server'));
} finally {
    $GLOBALS['wpdb'] = $arWasDb;
    unset(WP_Fake::$meta['24:_wp_attached_file'], WP_Fake::$meta['24:_wp_attachment_metadata']);
}

// The real site writer names the options its option door refuses.
$arSite = new Claude_Cowork_Site_Writer();
checkTrue('the site writer refuses siteurl', $arSite->optionRefused('siteurl'));
checkTrue('the site writer takes a theme option', !$arSite->optionRefused('wp_ja_diner_blog_banner_image'));

// media.delete answers it beside usedIn, an option its write door would refuse marked locked.
$arSiteW = new FakeSiteWriter();
$arSiteW->refusedOptions = ['siteurl'];
$arMedia = new FakeMediaWriter();
$arMedia->store['wp-content/uploads/2026/10/blog.jpg'] = 'JPEG';
$arMedia->attachments[24] = 'wp-content/uploads/2026/10/blog.jpg';
$arMedia->storageUsage[24] = ['postmeta' => [['post_id' => 7, 'key' => 'jd_intro_image', 'shape' => 'id']], 'options' => [
    ['option' => 'wp_ja_diner_blog_banner_image', 'shape' => 'id'],
    ['option' => 'siteurl', 'shape' => 'url', 'size' => 'full'],
    ['option' => 'claude_cowork_rendered_words_7_en', 'shape' => 'json'],
], 'truncated' => false];
$arEngine = new Engine($GLOBALS['WTOKEN'], [], null, null, null, null, $arSiteW, $arMedia, new FakeApplyLog());
$arDry = $arEngine->handle(['token' => $GLOBALS['WTOKEN'], 'action' => 'media.delete', 'params' => ['apply_id' => 'AR1', 'id' => 24, 'dry_run' => true]]);
check('media.delete dry_run still answers usedIn', $arDry['usedIn'] ?? null, []);
check('media.delete dry_run answers the post meta rows', $arDry['usedInStorage']['postmeta'] ?? null, [['post_id' => 7, 'key' => 'jd_intro_image', 'shape' => 'id']]);
check('media.delete dry_run marks only refused options locked', $arDry['usedInStorage']['options'] ?? null, [
    ['option' => 'wp_ja_diner_blog_banner_image', 'shape' => 'id'],
    ['option' => 'siteurl', 'shape' => 'url', 'size' => 'full', 'locked' => true],
    ['option' => 'claude_cowork_rendered_words_7_en', 'shape' => 'json', 'locked' => true],
]);
check('media.delete dry_run says truncated', $arDry['usedInStorage']['truncated'] ?? null, false);
checkTrue('media.delete dry_run names the storage scope', is_string($arDry['usedInStorageScope'] ?? null));
checkTrue('media.delete dry_run changes nothing', isset($arMedia->attachments[24]));

// media.usage: the same answer, read-only, with no apply_id and no file read.
$arMedia->usage[24] = [['type' => 'featuredImage', 'id' => 7, 'postType' => 'post', 'title' => 'Card']];
$arLog = new FakeApplyLog();
$arEngine = new Engine($GLOBALS['WTOKEN'], [], null, null, null, null, $arSiteW, $arMedia, $arLog);
$arUse = static fn(array $params): array => $arEngine->handle(['token' => $GLOBALS['WTOKEN'], 'action' => 'media.usage', 'params' => $params]);
$arUsage = $arUse(['id' => 24]);
check('media.usage needs no apply_id', $arUsage['ok'] ?? null, true);
check('media.usage answers usedIn and its scope', [$arUsage['usedIn'] ?? null, $arUsage['usedInScope'] ?? null], [$arMedia->usage[24], $arDry['usedInScope']]);
check('media.usage answers usedInStorage as media.delete does', [$arUsage['usedInStorage'] ?? null, $arUsage['usedInStorageScope'] ?? null], [$arDry['usedInStorage'], $arDry['usedInStorageScope']]);
check('media.usage hands the usage search paths, no bytes', $arMedia->usageFiles[24], [['path' => 'wp-content/uploads/2026/10/blog.jpg']]);
check('media.usage records nothing', $arLog->log, []);
check('media.usage of an unknown id is not_found', $arUse(['id' => 999])['error'] ?? null, 'not_found');
check('media.usage without an id is bad_params', $arUse([])['error'] ?? null, 'bad_params');
check('media.usage of a URL no attachment owns is not_found', $arUse(['url' => 'https://elsewhere.example/a.jpg'])['error'] ?? null, 'not_found');
check('info advertises media.usage and its bounds', $arEngine->handle(['token' => $GLOBALS['WTOKEN'], 'action' => 'info'])['info']['media_usage'] ?? null,
    ['storage' => true, 'max_ids_scanned' => AttachmentReferences::MAX_SCAN, 'max_rows_reported' => AttachmentReferences::MAX_HITS]);
check('media.usage without after starts each walk at the beginning', $arMedia->storageAfter[24], []);
$arUse(['id' => 24, 'after' => ['postmeta' => '100000', 'options' => -3, 'posts' => 9]]);
check('media.usage hands after on, per table, as a non-negative id', $arMedia->storageAfter[24], ['postmeta' => 100000, 'options' => 0]);
// Where the walk stopped and which table failed reach the caller; a failed table is no "held nowhere".
$arScanned = ['postmeta' => ['after' => 0, 'through' => 0, 'lastId' => 900, 'complete' => false], 'options' => ['after' => 0, 'through' => 40, 'lastId' => 40, 'complete' => true]];
$arMedia->storageUsage[24] = ['postmeta' => [], 'options' => [], 'truncated' => true, 'scanned' => $arScanned, 'errors' => ['postmeta' => 'Table is marked as crashed']];
$arBrokenUse = $arUse(['id' => 24])['usedInStorage'] ?? [];
check('media.usage passes the walk and the failed table through', [$arBrokenUse['truncated'] ?? null, $arBrokenUse['scanned'] ?? null, $arBrokenUse['errors'] ?? null],
    [true, $arScanned, ['postmeta' => 'Table is marked as crashed']]);
check('media.delete dry_run passes them too', array_intersect_key($arEngine->handle(['token' => $GLOBALS['WTOKEN'], 'action' => 'media.delete', 'params' => ['apply_id' => 'AR2', 'id' => 24, 'dry_run' => true]])['usedInStorage'] ?? [], ['errors' => 1, 'scanned' => 1]),
    ['scanned' => $arScanned, 'errors' => ['postmeta' => 'Table is marked as crashed']]);

// The real media writer's paths for media.usage: what WordPress records, under uploads, without reading a file.
WP_Fake::$posts[24] = ['ID' => 24, 'post_type' => 'attachment', 'post_mime_type' => 'image/jpeg', 'post_title' => 'Blog'];
WP_Fake::$posts[25] = ['ID' => 25, 'post_type' => 'attachment', 'post_mime_type' => 'application/pdf', 'post_title' => 'Menu'];
WP_Fake::$meta['24:_wp_attached_file'] = '2026/10/blog.jpg';
WP_Fake::$meta['24:_wp_attachment_metadata'] = $arMeta;
try {
    if (!function_exists('get_attached_file')) {
        function get_attached_file(int $id) { return rtrim(wp_upload_dir()['basedir'], '/') . '/' . get_post_meta($id, '_wp_attached_file', true); }
    }
    if (!function_exists('wp_get_attachment_metadata')) {
        function wp_get_attachment_metadata(int $id, bool $unfiltered = false) { return get_post_meta($id, '_wp_attachment_metadata', true); }
    }
    if (!function_exists('trailingslashit')) {
        function trailingslashit(string $s): string { return rtrim($s, '/\\') . '/'; }
    }
    $arReal = new Claude_Cowork_Media_Writer();
    check('the real writer lists the paths an image owns, files not needed', $arReal->attachmentPaths(24), [
        ['path' => 'wp-content/uploads/2026/10/blog.jpg'], ['path' => 'wp-content/uploads/2026/10/blog-orig.jpg'],
        ['path' => 'wp-content/uploads/2026/10/blog-150x150.jpg'], ['path' => 'wp-content/uploads/2026/10/blog-300x200.jpg'],
    ]);
    check('the real writer answers null for an attachment that is not an image', $arReal->attachmentPaths(25), null);
    check('the real writer answers null for no attachment', $arReal->attachmentPaths(26), null);
} finally {
    unset(WP_Fake::$posts[24], WP_Fake::$posts[25], WP_Fake::$meta['24:_wp_attached_file'], WP_Fake::$meta['24:_wp_attachment_metadata']);
}
