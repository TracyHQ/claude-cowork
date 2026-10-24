<?php
/**
 * Whether a site page gets the keyboard for T3's off-canvas drawer
 * (plg_system_claudecoworkapi/media/js/t3-offcanvas-a11y.js, printed by plg_system_claudecoworkapi).
 *
 * The script is written against one T3 off-canvas.js — the bytes every T3 quickstart contract locks
 * (T3 3.3.2, `base-bs3`). It clicks T3's own × and reads the classes T3 sets, so it is printed only
 * where those are the bytes on disk: a T3 template whose style turns the drawer on, its T3 base's
 * off-canvas.js hashing to one listed here. A T3 that is different (updated upstream, edited by hand)
 * gets nothing, and the drawer stays exactly as T3 draws it.
 */
final class T3OffCanvas
{
    /** sha256 of `plugins/system/t3/<base>/js/off-canvas.js` the script was checked against, per T3 base. */
    public const KNOWN = [
        'base-bs3' => ['a8f6adbd753c616afce00bc4a7e4f7e8cf94cc6a1e9fc273ad2b1d6da6c399f4'],
    ];

    /** Hashes already taken in this request, by file, size and modification time. */
    private static array $hashes = [];

    /**
     * @param mixed $offCanvas the style's `addon_offcanvas_enable`
     * @param array<string, list<string>> $known base => hashes (tests name their own fixture's)
     */
    public static function applies(string $root, string $template, $offCanvas, array $known = self::KNOWN): bool
    {
        if (!(int) $offCanvas) return false;
        $manifest = TemplateSiteSettings::manifestOf($root, $template);
        if ($manifest === null || !preg_match('~<t3\b~', $manifest) || preg_match('~<t4\b~', $manifest)) return false;
        $base = self::baseOf($manifest);
        if (!isset($known[$base])) return false;
        $file = rtrim($root, '/') . '/plugins/system/t3/' . $base . '/js/off-canvas.js';
        clearstatcache(true, $file);
        if (!is_file($file) || is_link($file) || filesize($file) > 65536) return false;
        $key = $file . ':' . filesize($file) . ':' . filemtime($file);
        if (!isset(self::$hashes[$key])) self::$hashes[$key] = (string) hash_file('sha256', $file);
        return in_array(self::$hashes[$key], $known[$base], true);
    }

    /** The T3 base a template is built on: its manifest's `<t3><base>`, or T3's own default. */
    public static function baseOf(string $manifest): string
    {
        return preg_match('~<t3\b[^>]*>.*?<base>\s*([A-Za-z0-9_-]+)\s*</base>~s', $manifest, $m) ? $m[1] : 'base';
    }
}
