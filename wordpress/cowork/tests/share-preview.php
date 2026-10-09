<?php
/**
 * A shared link shows the customer's logo, name and words (TracyHQ/tch#1013, D5). WordPress and the Tracy themes print
 * no Open Graph tags, so a scraper picked any picture off the page. While `tracy_share_image` names an attachment
 * (Tracy writes the uploaded logo's), the head carries og:image and the texts the site already holds.
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/SeoFields.php';
require_once __DIR__ . '/../lib/SharePreview.php';

echo "\nShare preview\n";

$fields = ['og:type' => 'website', 'og:site_name' => 'Tamarind & Co', 'og:title' => 'Tamarind & Co – Tiệm bánh', 'og:description' => 'Bánh mì "Việt"',
    'og:url' => 'https://x.test/', 'og:image' => 'https://x.test/wp-content/uploads/tracy-brand/logo.png', 'og:image:width' => '400', 'og:image:height' => '120'];
check('the tags: every field, escaped, then the card',
    SharePreview::tags($fields),
    '<meta property="og:type" content="website" />' . "\n"
    . '<meta property="og:site_name" content="Tamarind &amp; Co" />' . "\n"
    . '<meta property="og:title" content="Tamarind &amp; Co – Tiệm bánh" />' . "\n"
    . '<meta property="og:description" content="Bánh mì &quot;Việt&quot;" />' . "\n"
    . '<meta property="og:url" content="https://x.test/" />' . "\n"
    . '<meta property="og:image" content="https://x.test/wp-content/uploads/tracy-brand/logo.png" />' . "\n"
    . '<meta property="og:image:width" content="400" />' . "\n"
    . '<meta property="og:image:height" content="120" />' . "\n"
    . '<meta name="twitter:card" content="summary" />' . "\n");
check('no picture, no tags', SharePreview::tags(array_merge($fields, ['og:image' => ''])), '');
checkTrue('an empty description is left out', strpos(SharePreview::tags(array_merge($fields, ['og:description' => ''])), 'og:description') === false);
check('a head that already shares a picture (a theme of its own) is left as it is',
    SharePreview::withTags('<meta property="og:image" content="/demo.jpg" />', '<meta property="og:image" content="/logo.png" />'),
    '<meta property="og:image" content="/demo.jpg" />');
check('a head without one gets the tags', SharePreview::withTags('<title>x</title>', '<t/>'), '<title>x</title><t/>');

if (!function_exists('wp_get_attachment_image_src')) {
    function wp_get_attachment_image_src($id, $size = 'thumbnail')
    {
        return (int) $id === 77 ? ['http://test.local/wp-content/uploads/tracy-brand/logo.png', 400, 120, false] : false;
    }
}
if (!function_exists('wp_get_document_title')) {
    function wp_get_document_title(): string
    {
        return 'Tamarind &amp; Co &#8211; Ti&#7879;m b&aacute;nh';
    }
}
if (!function_exists('is_front_page')) {
    function is_front_page(): bool
    {
        return false;
    }
}
$render = static function (): string {
    ob_start();
    SharePreview::openHead();
    echo "<title>x</title>\n";
    SharePreview::closeHead();
    return (string) ob_get_clean();
};
WP_Fake::reset();
WP_Fake::$options = ['blogname' => 'Tamarind & Co', 'blogdescription' => 'Tiệm bánh gia đình'];
check('no option: the head is byte-identical', $render(), "<title>x</title>\n");
WP_Fake::$options[SharePreview::OPTION] = 77;
$head = $render();
checkTrue('the logo is the share picture', strpos($head, '<meta property="og:image" content="http://test.local/wp-content/uploads/tracy-brand/logo.png" />') !== false);
checkTrue('with its size', strpos($head, '<meta property="og:image:width" content="400" />') !== false);
checkTrue('the title is the page title as a reader sees it', strpos($head, '<meta property="og:title" content="Tamarind &amp; Co – Tiệm bánh" />') !== false);
checkTrue('the description falls back to the tagline', strpos($head, '<meta property="og:description" content="Tiệm bánh gia đình" />') !== false);
WP_Fake::$options[SharePreview::OPTION] = 78;
check('an id that is no image: nothing printed', $render(), "<title>x</title>\n");
WP_Fake::$options[SharePreview::OPTION] = 77;
WP_Fake::$options['active_plugins'] = ['wordpress-seo/wp-seo.php'];
check('an SEO plugin runs: it prints its own, nothing here', $render(), "<title>x</title>\n");
WP_Fake::reset();
