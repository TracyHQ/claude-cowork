<?php
/**
 * The letters of the site's language its theme's fonts cannot draw (TracyHQ/tch#1013). Measured 10/10/2026 on dev
 * g60-ess-wp-full (JA Essence WordPress 1.0.13, Vietnamese): the heading font Jost has no Vietnamese glyphs, so "đề" was
 * drawn as "đê" with a loose accent, and the theme's Noto Serif is cut to Latin. `tracy_glyph_fallback` names faces that
 * draw those letters; each is printed as an @font-face of the theme's own family name, limited to the subset's letters.
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/GlyphFallback.php';

echo "\nGlyph fallback\n";

$gfFace = ['family' => 'Jost', 'weight' => '400', 'style' => 'normal', 'subset' => 'vietnamese',
    'src' => 'https://fonts.gstatic.com/s/bevietnampro/v12/QdVPSTAyLFyeg_IDWvOJmVES_HRUBX8YYbAiah8.woff2'];
check('g60: a face is printed under the theme\'s own family, limited to the Vietnamese letters',
    GlyphFallback::css(json_encode(['faces' => [$gfFace]])),
    '@font-face{font-family:"Jost";font-style:normal;font-weight:400;font-display:swap;'
    . 'src:url("https://fonts.gstatic.com/s/bevietnampro/v12/QdVPSTAyLFyeg_IDWvOJmVES_HRUBX8YYbAiah8.woff2") format("woff2");'
    . 'unicode-range:U+0102-0103, U+0110-0111, U+0128-0129, U+0168-0169, U+01A0-01A1, U+01AF-01B0, U+1EA0-1EF9}');
check('a family name with spaces is kept; the decoded option reads the same',
    substr_count(GlyphFallback::css(['faces' => [array_merge($gfFace, ['family' => 'Noto Serif', 'weight' => '700'])]]), 'font-family:"Noto Serif";font-style:normal;font-weight:700'), 1);
check('no option, a broken one, or no faces print nothing', [GlyphFallback::css(''), GlyphFallback::css('{oops'), GlyphFallback::css(['faces' => []])], ['', '', '']);
check('a face that is not plainly one is left out: a family that closes the rule, a source off Google\'s font host, a subset nobody knows, a weight that is not one', [
    GlyphFallback::css(['faces' => [array_merge($gfFace, ['family' => 'Jost"}body{display:none'])]]),
    GlyphFallback::css(['faces' => [array_merge($gfFace, ['src' => 'https://evil.example/f.woff2'])]]),
    GlyphFallback::css(['faces' => [array_merge($gfFace, ['src' => 'https://fonts.gstatic.com/../x.woff2'])]]),
    GlyphFallback::css(['faces' => [array_merge($gfFace, ['subset' => 'klingon'])]]),
    GlyphFallback::css(['faces' => [array_merge($gfFace, ['weight' => 'bold;}'])]]),
], ['', '', '', '', '']);
check('a variable face keeps its weight range', substr_count(GlyphFallback::css(['faces' => [array_merge($gfFace, ['weight' => '100 900'])]]), 'font-weight:100 900;'), 1);
