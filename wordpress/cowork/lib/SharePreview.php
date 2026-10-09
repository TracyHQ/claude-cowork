<?php
/**
 * SharePreview — a link shared on Facebook, Zalo or X shows the customer's logo, name and words.
 *
 * WordPress prints no Open Graph tags of its own, and no Tracy theme does either (checked 09/10/2026: wp-tracy-business,
 * tracy-base, the wp-ja-* themes). A shared link then shows whatever picture the scraper picks off the page — on a
 * fresh quickstart site, a demo photograph — beside the page title (TracyHQ/tch#1013, D5: the preview picture is the
 * customer's logo when one was uploaded).
 *
 * Option `tracy_share_image` (an attachment id, written by Tracy through `content.update {kind: "option"}` and taken
 * back by its apply id) names that picture. While it names an image, every front-end page gets `og:image` (with its
 * size), `og:title` (the page's `<title>`, which carries the customer's name), `og:description` (the page's own search
 * description, else the site's tagline), `og:site_name`, `og:type`, `og:url` and `twitter:card` at the end of
 * `wp_head`, unless an SEO plugin runs (it prints its own) or the head already holds an `og:image` (a theme that prints
 * its own). No option, no tags: the page is byte-identical to before.
 */
final class SharePreview
{
    public const OPTION = 'tracy_share_image';

    private static int $level = 0;
    /** @var array<string,string>|null the tags this page will print, decided when wp_head opens */
    private static ?array $pending = null;

    /** Hook in; without WordPress nothing happens. */
    public static function register(): void
    {
        if (!function_exists('add_action')) {
            return;
        }
        add_action('wp_head', [self::class, 'openHead'], PHP_INT_MIN);
        add_action('wp_head', [self::class, 'closeHead'], PHP_INT_MAX);
    }

    /**
     * The Open Graph tags for one page, as markup. Pure. Empty values are left out; no image, no tags at all.
     * @param array<string,string> $fields og:image, og:image:width, og:image:height, og:title, og:description,
     *   og:site_name, og:type, og:url
     */
    public static function tags(array $fields): string
    {
        if (trim((string) ($fields['og:image'] ?? '')) === '') {
            return '';
        }
        $out = '';
        foreach (['og:type', 'og:site_name', 'og:title', 'og:description', 'og:url', 'og:image', 'og:image:width', 'og:image:height'] as $property) {
            $value = trim((string) ($fields[$property] ?? ''));
            if ($value !== '') {
                $out .= '<meta property="' . $property . '" content="' . self::attr($value) . '" />' . "\n";
            }
        }
        return $out . '<meta name="twitter:card" content="summary" />' . "\n";
    }

    /** The head with the tags added, unless it already carries a share picture of its own. Pure. */
    public static function withTags(string $head, string $tags): string
    {
        if ($tags === '' || preg_match('/<meta\s[^>]*\b(property|name)\s*=\s*(["\']?)(og:image|twitter:image)\2[\s\/>]/i', $head) === 1) {
            return $head;
        }
        return $head . $tags;
    }

    /** First on wp_head: when this page has a share picture, hold the head to see what the theme prints. */
    public static function openHead(): void
    {
        self::$pending = self::fields();
        if (self::$pending === null) {
            return;
        }
        ob_start();
        self::$level = ob_get_level();
    }

    /** Last on wp_head: the head as printed, plus the tags. A buffer left open inside wp_head gets them printed into it. */
    public static function closeHead(): void
    {
        $fields = self::$pending;
        self::$pending = null;
        if ($fields === null) {
            return;
        }
        $tags = self::tags($fields);
        if (ob_get_level() === self::$level) {
            echo self::withTags((string) ob_get_clean(), $tags);
            return;
        }
        echo $tags;
    }

    /** @return array<string,string>|null what this page shares; null with no picture, an SEO plugin, or no WordPress */
    private static function fields(): ?array
    {
        if (!function_exists('get_option') || !function_exists('wp_get_attachment_image_src')) {
            return null;
        }
        $id = (int) get_option(self::OPTION, 0);
        if ($id <= 0 || (class_exists('SeoFields') && SeoFields::running() !== null)) {
            return null;
        }
        $image = wp_get_attachment_image_src($id, 'full');
        if (!is_array($image) || !is_string($image[0] ?? null) || $image[0] === '') {
            return null;
        }
        $post = function_exists('get_queried_object') ? get_queried_object() : null;
        $singular = $post instanceof WP_Post && (int) $post->ID > 0;
        $front = function_exists('is_front_page') && is_front_page();
        $description = $singular && function_exists('get_post_meta') ? trim((string) get_post_meta((int) $post->ID, '_claude_cowork_seo_description', true)) : '';
        if ($description === '') {
            $description = (string) get_option('blogdescription', '');
        }
        $url = '';
        if ($front && function_exists('home_url')) {
            $url = (string) home_url('/');
        } elseif ($singular && function_exists('get_permalink')) {
            $url = (string) get_permalink($post);
        }
        return [
            'og:type' => $singular && !$front ? 'article' : 'website',
            'og:site_name' => self::plain((string) get_option('blogname', '')),
            'og:title' => function_exists('wp_get_document_title') ? self::plain((string) wp_get_document_title()) : '',
            'og:description' => self::plain($description),
            'og:url' => $url,
            'og:image' => $image[0],
            'og:image:width' => (int) ($image[1] ?? 0) > 0 ? (string) (int) $image[1] : '',
            'og:image:height' => (int) ($image[2] ?? 0) > 0 ? (string) (int) $image[2] : '',
        ];
    }

    /** Text as a reader sees it: entities decoded (the document title arrives escaped), tags dropped, one line. */
    private static function plain(string $text): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private static function attr(string $text): string
    {
        return function_exists('esc_attr') ? (string) esc_attr($text) : htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}
