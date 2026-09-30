<?php
/**
 * A derived contract at the size of a real import (tracy.ai, measured 30/09/2026: 2,482 published
 * posts, 2,430 of them one custom type; 121,925 postmeta rows holding 1.3 MB in all). There a
 * derive under the default 128 MB died in WordPressDerivedRows::row(), and every content.read built
 * the map again at 156 MB. Here: the same shape over the fake, its peak memory, and the map kept
 * between reads until the site changes.
 *
 * Loaded by run.php after derived-contract.php (uses DwDb, $dwEngineOf, $dwDerive, $FIXTURES).
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/DerivedCache.php';

echo "\nDerived contract at scale (WordPress)\n";

/** Peak bytes `$run` allocates above what was in use before it. */
$dsPeak = static function (callable $run, &$result = null): int {
    gc_collect_cycles();
    // memory_reset_peak_usage() is PHP 8.2+; CI runs 8.1, where the retained growth stands in for the peak.
    $reset = function_exists('memory_reset_peak_usage');
    if ($reset) memory_reset_peak_usage();
    $base = memory_get_usage();
    $result = $run();
    return ($reset ? memory_get_peak_usage() : memory_get_usage()) - $base;
};
$dsSeed = static function (): void {
    WP_Fake::reset();
    WP_Fake::$stylesheet = 'catalog';
    WP_Fake::$options = ['home' => 'http://catalog.test', 'siteurl' => 'http://catalog.test', 'blogname' => 'Catalog', 'blogdescription' => 'Things made by hand',
        'stylesheet' => 'catalog', 'template' => 'catalog', 'active_plugins' => ['woocommerce/woocommerce.php'],
        'theme_mods_catalog' => ['footer_text' => 'Made in Oslo', 'header_color' => '#000000'], Claude_Cowork_Content_Source::SITE_OPTION => str_repeat('cd', 16)];
    for ($i = 0; $i < 200; $i++) {
        // Plugin settings: most are counters and switches, a few are words.
        WP_Fake::$options['plugin_setting_' . $i] = $i % 20 === 0 ? 'Free shipping over ' . $i . ' euros' : (string) ($i * 7);
    }
    // 2,430 catalog items and 50 pages; each item 49 meta rows, as WooCommerce and a builder leave them:
    // bookkeeping, prices, stock, ids, and three that hold words.
    for ($id = 100; $id < 2580; $id++) {
        $page = $id >= 2530;
        WP_Fake::$posts[$id] = ['ID' => $id, 'post_type' => $page ? 'page' : 'catalog_item', 'post_name' => 'item-' . $id, 'post_status' => 'publish', 'post_parent' => 0,
            'post_title' => ($page ? 'Page ' : 'Oak chair ') . $id, 'post_excerpt' => '', 'post_modified_gmt' => '2026-09-01 10:00:00',
            'post_content' => '<!-- wp:paragraph --><p>Hand finished piece number ' . $id . ', made to order.</p><!-- /wp:paragraph -->'];
        foreach (['_edit_lock' => '1727600000:1', '_edit_last' => '1', '_thumbnail_id' => (string) ($id + 5000), '_wp_page_template' => 'default',
            '_wp_old_slug' => 'old-item-' . $id, '_wp_old_date' => '2026-01-01', Claude_Cowork_Content_Source::UID_META => md5((string) $id)] as $key => $value) {
            WP_Fake::$meta[$id . ':' . $key] = $value;
        }
        foreach (['_price', '_regular_price', '_sale_price', '_stock', 'total_sales', '_weight', '_length', '_width', '_height', '_tax_class_id',
            '_download_limit', '_download_expiry', '_wc_average_rating', '_wc_review_count', '_low_stock_amount', '_menu_order_meta'] as $n => $key) {
            WP_Fake::$meta[$id . ':' . $key] = (string) (($id * 13 + $n) % 997) . ($n % 3 === 0 ? '.50' : '');
        }
        foreach (['_manage_stock' => 'no', '_backorders' => 'no', '_sold_individually' => 'no', '_virtual' => 'no', '_downloadable' => 'no',
            '_tax_status' => 'taxable', '_stock_status' => 'instock', '_visibility' => 'visible', '_featured' => 'no'] as $key => $value) {
            WP_Fake::$meta[$id . ':' . $key] = $value;
        }
        for ($n = 0; $n < 12; $n++) {
            WP_Fake::$meta[$id . ':_gallery_ref_' . $n] = substr(md5($id . ':' . $n), 0, 16);
        }
        WP_Fake::$meta[$id . ':_product_version'] = '9.3.1';
        WP_Fake::$meta[$id . ':_sku'] = 'OAK-' . $id;
        WP_Fake::$meta[$id . ':_subtitle'] = 'Solid oak, oiled by hand, piece ' . $id;
        WP_Fake::$meta[$id . ':_care_note'] = 'Wipe with a dry cloth';
        WP_Fake::$meta[$id . ':_product_attributes'] = serialize(['material' => ['name' => 'Material', 'value' => 'Oak', 'is_visible' => 1]]);
    }
    WP_Fake::$termRows[5] = ['term_id' => 5, 'name' => 'Chairs', 'slug' => 'chairs', 'description' => 'Everything to sit on', 'parent' => 0, 'taxonomy' => 'category'];
    $GLOBALS['wpdb'] = new DwDb();
};
$dsRowsOf = static fn(): WordPressDerivedRows => new WordPressDerivedRows($GLOBALS['wpdb'], ['post', 'page', 'catalog_item', 'attachment'], ['category'],
    'http://catalog.test/', static fn(int $id): ?string => 'http://catalog.test/item-' . $id . '/', static fn(): array => ['code' => 0, 'body' => '']);
$dsPages = ['<html><body><h1>Catalog</h1><p>Things made by hand</p><footer>Made in Oslo</footer><p>Solid oak, oiled by hand, piece 100</p><p>Free shipping over 20 euros</p></body></html>'];

$dsSeed();
check('scale: the fake site is the size of the import', [count(WP_Fake::$posts), count(WP_Fake::$meta)], [2480, 121520]);

// Read in batches, each let go before the next: what a derive and a map build hold at once.
$dsRowsPeak = $dsPeak(static function () use ($dsRowsOf): array {
    $kinds = [];
    $batches = $dsRowsOf()->batches();
    foreach ($batches as $batch) {
        foreach ($batch as $row) {
            $kinds[$row['kind']] = ($kinds[$row['kind']] ?? 0) + 1;
        }
    }
    return [$kinds, $batches->getReturn()];
}, $dsRead);
[$dsKinds, $dsUnresolved] = $dsRead;
echo '       rows: ' . array_sum($dsKinds) . ' rows in batches, peak ' . round($dsRowsPeak / 1048576, 1) . ' MB; ' . $GLOBALS['wpdb']->metaRows . " meta rows left the database\n";
check('scale: every published post is read, and only the meta that can hold words', [$dsKinds['post'] ?? 0, $dsKinds['postmeta'] ?? 0, $dsUnresolved], [2480, 2480 * 7, []]);
check('scale: the rest of the meta never leaves the database', $GLOBALS['wpdb']->metaRows, 2480 * 7);
checkTrue('scale: reading the rows in batches stays under 8 MB (' . round($dsRowsPeak / 1048576, 1) . ' MB)', $dsRowsPeak < 8 * 1048576);

$dsWriter = new Claude_Cowork_Site_Writer();
$dsEngine = (new Engine($GLOBALS['WTOKEN'], [], null, null, null, null, $dsWriter, new FakeMediaWriter(), new FakeApplyLog(), null,
    new QuickstartContract($dsWriter, new Claude_Cowork_Contract_Store(), $dwRoot, $FIXTURES, '')))
    ->derivedSource(static function () use ($dsRowsOf, $dsPages): array {
        $unresolved = [];
        return ['rows' => $dsRowsOf()->rows($unresolved), 'pages' => $dsPages, 'unresolved' => $unresolved];
    });
$dsStarted = microtime(true);
$dsDerivePeak = $dsPeak(static fn(): array => $dsEngine->handle($dwDerive('derive-scale', 'catalog-import')), $dsAnswer);
echo '       derive: ' . ($dsAnswer['entities'] ?? '?') . ' entities, ' . ($dsAnswer['slots'] ?? '?') . ' slots, peak ' . round($dsDerivePeak / 1048576, 1) . ' MB, '
    . round(microtime(true) - $dsStarted, 1) . " s\n";
check('scale: derive succeeds', ($dsAnswer['ok'] ?? false) ? true : $dsAnswer, true);
checkTrue('scale: derive stays under 48 MB (' . round($dsDerivePeak / 1048576, 1) . ' MB)', $dsDerivePeak < 48 * 1048576);
unset($dsEngine, $dsAnswer);

// content.read: the map kept between requests, keyed by the fingerprint of the tables.
$dsReads = 0;
$dsSource = static function () use ($dsRowsOf, &$dsReads): Claude_Cowork_Content_Source {
    return new Claude_Cowork_Content_Source('editorial', $GLOBALS['FIXTURES'], 'test', static function () use ($dsRowsOf, &$dsReads): array {
        $dsReads++;
        return $dsRowsOf()->rows();
    }, $dsRowsOf()->cache('test'));
};
$dsReadPeak = $dsPeak(static function () use ($dsSource): string {
    $source = $dsSource();
    $revision = $source->revision();
    $source->release();
    return $revision;
}, $dsRevision);
echo '       first read: peak ' . round($dsReadPeak / 1048576, 1) . " MB\n";
check('scale: the first read builds the map', $dsReads, 1);
$dsWarmPeak = $dsPeak(static function () use ($dsSource): string {
    $source = $dsSource();
    $revision = $source->revision();
    $source->release();
    return $revision;
}, $dsWarm);
echo '       second read: peak ' . round($dsWarmPeak / 1048576, 1) . " MB\n";
check('scale: a second read with the tables unchanged does not build it again, and reads the same', [$dsReads, $dsWarm], [1, $dsRevision]);
checkTrue('scale: a read stays under 32 MB (' . round(max($dsReadPeak, $dsWarmPeak) / 1048576, 1) . ' MB)', max($dsReadPeak, $dsWarmPeak) < 32 * 1048576);
$dsRevisionNow = static function () use ($dsSource): string {
    $source = $dsSource();
    $revision = $source->revision();
    $source->release();
    return $revision;
};
echo '       kept: ' . round(strlen((string) get_option(WordPressDerivedRows::CACHE_OPTION)) / 1024) . " KB in one option\n";
WP_Fake::$posts[2000]['post_title'] = 'Walnut chair 2000';
check('scale: one post changed rebuilds the map, and the read moves', [$dsRevisionNow() !== $dsRevision, $dsReads], [true, 2]);
WP_Fake::$meta['2001:_subtitle'] = 'Solid walnut';
$dsRevisionNow();
WP_Fake::$options['plugin_setting_20'] = 'Free shipping over 30 euros';
$dsRevisionNow();
WP_Fake::$termRows[5]['description'] = 'Everything to sit on, in oak';
$dsRevisionNow();
check('scale: so does one meta, one option, one term', $dsReads, 5);
WP_Fake::$meta['2001:_price'] = '99.50';
WP_Fake::$meta['2001:_stock'] = '2';
$dsRevisionNow();
check('scale: a price or a stock count (numbers no slot can hold) leaves it', $dsReads, 5);

// As the plugin wires it: the contract reads the rows in batches and keeps the map; a derived apply drops it.
$dwSeed();
$dsWriter = new Claude_Cowork_Site_Writer();
$dwEngineOf($dsWriter, new FakeApplyLog(), $dwContractOf($dsWriter), $dwPages)->handle($dwDerive('derive-kept'));
$dsKept = (new QuickstartContract($dsWriter, new Claude_Cowork_Contract_Store(), $dwRoot, $FIXTURES, ''))
    ->withDerivedRows(static fn(): Generator => $dwRowsOf()->batches(), $dwRowsOf()->cache('test'));
$dsKeptEngine = $dwEngineOf($dsWriter, new FakeApplyLog(), $dsKept, $dwPages);
$dsInspect = $dwDoor($dsKeptEngine, 'inspect');
check('kept: an inspect over batches finds the same slots', array_column($dsInspect['slotDetails'] ?? [], 'current'),
    array_column($dwDoor($dwEngineOf($dsWriter, new FakeApplyLog(), $dwContractOf($dsWriter), $dwPages), 'inspect')['slotDetails'] ?? [], 'current'));
checkTrue('kept: and stores the map it built', is_string(get_option(WordPressDerivedRows::CACHE_OPTION)));
$dsApplied = $dwApply($dsKeptEngine, ['Welcome aboard' => 'Welcome to Northwind'], 'dw-kept');
check('kept: a derived apply drops the kept map', [$dsApplied['ok'] ?? $dsApplied, get_option(WordPressDerivedRows::CACHE_OPTION, null)], [true, null]);
checkTrue('kept: one kept map per request, shared by the contract and every reader', WordPressDerivedRows::siteCache('test') === WordPressDerivedRows::siteCache('test'));
