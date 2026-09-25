<?php
/**
 * `NavigationLinks::fillUrl`: a navigation-link block with an id and no url gets the permalink;
 * a block with a url, another block, or an id the site cannot resolve is left alone.
 */
declare(strict_types=1);
require_once __DIR__ . '/../lib/NavigationLinks.php';

if (!function_exists('get_permalink')) {
    function get_permalink($id) { return $id === 1946 ? 'https://site.test/vi/' : ($id === 1951 ? 'https://site.test/vi/gioi-thieu-vi/' : false); }
}

$empty = ['blockName' => 'core/navigation-link', 'attrs' => ['label' => 'Home', 'type' => 'page', 'id' => 1946, 'url' => '', 'kind' => 'post-type']];
check('an empty url is filled from the page id', NavigationLinks::fillUrl($empty)['attrs']['url'], 'https://site.test/vi/');
$sub = ['blockName' => 'core/navigation-submenu', 'attrs' => ['id' => 1951, 'url' => '', 'kind' => 'post-type']];
check('a submenu block too', NavigationLinks::fillUrl($sub)['attrs']['url'], 'https://site.test/vi/gioi-thieu-vi/');
$kept = ['blockName' => 'core/navigation-link', 'attrs' => ['id' => 1946, 'url' => 'https://site.test/custom/']];
check('a url the block carries is kept', NavigationLinks::fillUrl($kept)['attrs']['url'], 'https://site.test/custom/');
$unknown = ['blockName' => 'core/navigation-link', 'attrs' => ['id' => 999999, 'url' => '']];
check('an id the site cannot resolve stays empty', NavigationLinks::fillUrl($unknown)['attrs']['url'], '');
$other = ['blockName' => 'core/paragraph', 'attrs' => ['url' => '']];
check('other blocks are untouched', NavigationLinks::fillUrl($other), $other);
check('a non-array is returned as is', NavigationLinks::fillUrl('x'), 'x');
