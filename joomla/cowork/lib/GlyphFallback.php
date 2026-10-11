<?php
/**
 * GlyphFallback — the letters of the site's language that the template's fonts cannot draw, drawn from a font that can.
 *
 * Measured 10/10/2026 on a JA Essence site written in Vietnamese (TracyHQ/tch#1013): the heading font Jost (Google Fonts,
 * cyrillic, latin and latin-ext only) has no Vietnamese glyphs, so "đề" was drawn as "đê" with a loose grave accent. Tracy
 * finds the families a built site uses that lack its language's letters and the faces that have them, and writes them
 * through `template.siteSettings` as `other_glyphFaces` (the template's own settings file, like the share image):
 * `{"faces":[{"family":"Jost","weight":"400","style":"normal","src":"https://fonts.gstatic.com/…woff2","subset":"vietnamese"}]}`.
 * The system plugin adds them to the page's head as `@font-face` rules of the SAME family name, limited by `unicode-range`
 * to the subset's own letters: after the template's faces, they are the ones the browser takes for those letters only.
 *
 * The value is data: a face is printed only when its family is a plain name, its weight and style are CSS values, its
 * source a WOFF2 on fonts.gstatic.com and its subset one this file knows. No library dependency, like the rest of `lib/`.
 * The WordPress plugin carries the same rules (wordpress/cowork/lib/GlyphFallback.php).
 */
final class GlyphFallback
{
    /** The letters each subset adds to the Latin ones, as `unicode-range`. */
    public const RANGES = [
        'vietnamese' => 'U+0102-0103, U+0110-0111, U+0128-0129, U+0168-0169, U+01A0-01A1, U+01AF-01B0, U+1EA0-1EF9',
    ];

    /** The most faces printed: a few families in a few weights. */
    public const MAX_FACES = 24;



    /**
     * The `@font-face` rules an option value asks for, every face that is not plainly one left out. Pure.
     *
     * @param mixed $value the option: its JSON text, or the decoded array
     */
    public static function css($value): string
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }
        if (!is_array($value) || !is_array($value['faces'] ?? null)) {
            return '';
        }
        $rules = [];
        foreach (array_slice($value['faces'], 0, self::MAX_FACES) as $face) {
            $rule = self::rule($face);
            if ($rule !== null) {
                $rules[] = $rule;
            }
        }
        return implode("\n", $rules);
    }

    /** One face as an `@font-face`, or null when any of its fields is not what a face may hold. */
    private static function rule($face): ?string
    {
        if (!is_array($face)) {
            return null;
        }
        $family = $face['family'] ?? null;
        $weight = (string) ($face['weight'] ?? '400');
        $style = (string) ($face['style'] ?? 'normal');
        $src = $face['src'] ?? null;
        $subset = $face['subset'] ?? null;
        if (!is_string($family) || preg_match('/^[\p{L}\p{N}][\p{L}\p{N} _-]{0,62}$/u', $family) !== 1) {
            return null;
        }
        if (preg_match('/^[1-9]00(?: [1-9]00)?$/', $weight) !== 1 || !in_array($style, ['normal', 'italic'], true)) {
            return null;
        }
        if (!is_string($src) || preg_match('~^https://fonts\.gstatic\.com/[A-Za-z0-9_./-]{1,300}\.woff2$~', $src) !== 1 || strpos($src, '..') !== false) {
            return null;
        }
        if (!is_string($subset) || !isset(self::RANGES[$subset])) {
            return null;
        }
        return '@font-face{font-family:"' . $family . '";font-style:' . $style . ';font-weight:' . $weight
            . ';font-display:swap;src:url("' . $src . '") format("woff2");unicode-range:' . self::RANGES[$subset] . '}';
    }
}
