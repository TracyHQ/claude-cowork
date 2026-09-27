<?php
/*
 * `native`: content.read names each content's own WordPress record in the kind and address
 * content.get / content.update / content.delete take, so an agent writes without a lookup first.
 * Loaded after content-revisions.php (reuses $revSite, $idOf, $FIXTURES).
 */
echo "\nNative ids (native)\n";
$previousNativeDb = $GLOBALS['wpdb'];
$GLOBALS['wpdb'] = new WP_Fake_ContentDb();
$revSite();
$nativeOf = static function () use ($FIXTURES): array {
    $source = new Claude_Cowork_Content_Source('editorial', $FIXTURES, 'test');
    $out = [];
    foreach ($source->summaries() as $summary) {
        $out[$summary['id']] = array_key_exists('native', $summary) ? $summary['native'] : 'missing';
    }
    $source->release();
    return $out;
};
$natives = $nativeOf();
check('a page names its post row', $natives[$idOf('post', 10)] ?? null, [['kind' => 'post', 'id' => 10]]);
check('a template part is addressed by its slug, as the writer takes it', $natives[$idOf('templatePart', 70, 'header')] ?? null, [['kind' => 'templatePart', 'key' => 'header']]);
check('every content carries native (a list, empty when no single record is its own)', in_array('missing', $natives, true), false);
$GLOBALS['wpdb'] = $previousNativeDb;
