<?php
/**
 * Slot fit (TCH ADR 0039): what a slot of a newer content map says about the SHAPE of what goes in it,
 * measured at capture from the rendered demo. A picture says its kind (photo, icon, cut-out...), whether
 * a build may replace it (`replace`: photo, logo or keep), whether it needs a transparent ground, the
 * aspect of its box and the narrowest source that stays sharp. A text says how many characters and
 * lines its box holds, and that a longer text is rewritten, never cut (`overflow: "rewrite"`).
 *
 * 🔒 A CONTRACT WITHOUT FIT ANSWERS EXACTLY AS BEFORE. Every rule here reads only a slot that carries a
 * `fit` object; QuickstartContract keeps its own checks for every other slot, unchanged. A field that
 * is missing or malformed inside a fit is skipped, never guessed.
 *
 * 🔒 THE DEMO'S OWN VALUE IS ALWAYS TAKEN BACK. Putting a slot's demo picture or words back restores
 * what the layout was measured with, so none of these rules refuses it.
 */
final class SlotFit
{
    /** What the slot list tells the agent; returned only when a slot carries a fit. */
    public const GUIDANCE = 'Slots of this contract carry a fit: the shape the template was measured with. Read it before you write a slot. '
        . 'A picture whose fit.replace is "photo" takes a photograph: crop it to fit.aspect (width / height, within fit.tolerance) keeping the subject in frame (toward fit.focus when given), at least fit.minWidth pixels wide. '
        . 'A picture whose fit.replace is "logo" takes only the business\'s own logo. '
        . 'A picture whose fit.replace is "keep" (an icon, avatar, signature, cut-out, decoration or map) stays as the template has it: never put a photo in its place. '
        . 'Change one only when the customer asks for that very picture (for example "replace the signature with mine"), and send replace_kept with the slot key mapped to the customer\'s words that ask for it; a general request such as "use my photos" is not such a request. '
        . 'A picture whose fit.transparent is true needs a cut-out: a PNG or WebP with a transparent background. Ask the customer for one, or produce one by removing the background; an opaque picture is refused. '
        . 'A text has fit.maxCharacters and fit.lines: when the customer\'s words are longer, rewrite them shorter so they keep their meaning; never cut them or add an ellipsis. A text over its fit is refused. '
        . 'Write in normal case: fit.casing says how the template already shows the words (upper means the template capitalises them).';

    /** Relative aspect tolerance when a fit names none; the same 2% the shape check always used. */
    private const TOLERANCE = 0.02;
    /** Pictures larger than this are not decoded to read their corners: the header decides (memory on a shared host). */
    private const DECODE_PIXELS = 4000000;
    /** A corner is transparent from this GD alpha (0 opaque .. 127 clear): about 247 of 255 opaque. */
    private const CLEAR_ALPHA = 4;

    /** The slot's fit when it carries one as an object, else null. */
    public static function of(array $slot): ?array
    {
        $fit = $slot['fit'] ?? null;
        return is_array($fit) && $fit !== [] && array_keys($fit) !== range(0, count($fit) - 1) ? $fit : null;
    }

    /**
     * The fit as the slot list carries it: what the agent decides with, without the measured boxes and
     * the demo file's size (they say nothing a write can act on beyond aspect and minWidth).
     */
    public static function compact(array $fit): array
    {
        $keep = ['measured', 'kind', 'replace', 'transparent', 'aspect', 'tolerance', 'minWidth', 'crop', 'focus',
            'lines', 'maxChars', 'maxCharacters', 'casing', 'overflow'];
        $out = [];
        foreach ($keep as $field) if (array_key_exists($field, $fit) && $fit[$field] !== null) $out[$field] = $fit[$field];
        return $out;
    }

    /** Whether `$value` puts the slot's own demo value back. */
    public static function restores(array $slot, string $value): bool
    {
        $path = static fn(string $v): string => ltrim(explode('#', $v, 2)[0], '/');
        $sample = (string)($slot['sample'] ?? '');
        return $value === $sample || ($slot['type'] === 'image' && $path($value) === $path($sample));
    }

    /**
     * A kept picture (fit.replace "keep") changes only when the request names it: `replace_kept` maps the
     * slot key to the customer's words asking for that picture. Throws through `$refuse`.
     */
    public static function checkKept(array $fit, string $key, array $params, callable $refuse): void
    {
        if (($fit['replace'] ?? null) !== 'keep') return;
        $asked = $params['replace_kept'][$key] ?? null;
        if (is_string($asked) && trim($asked) !== '' && strlen($asked) <= 2000) return;
        $kind = is_string($fit['kind'] ?? null) ? $fit['kind'] : 'picture';
        throw $refuse('SLOT_PICTURE_KEPT', 'This picture is a ' . $kind . ' the template keeps: leave it, or send replace_kept with the customer\'s words that ask for this very picture: ' . $key, ['kind' => $kind]);
    }

    /**
     * Shape, size and ground of a new picture against its fit. `$size` is getimagesize() of the new file,
     * `$before` of the demo file (false when unreadable), `$file` the new file on disk.
     * Returns false when the fit names no aspect, so the caller keeps its own shape check.
     */
    public static function checkPicture(array $fit, string $key, array $size, $before, string $file, callable $refuse): bool
    {
        $actual = $size[0] / $size[1];
        $reference = self::referenceAspect($fit);
        $tolerance = self::number($fit['tolerance'] ?? null);
        $tolerance = $tolerance !== null && $tolerance <= 0.2 ? $tolerance : self::TOLERANCE;
        if ($reference !== null && abs($actual / $reference - 1) > $tolerance)
            throw $refuse('SLOT_IMAGE_INVALID', 'Image aspect ratio does not match its slot: crop it to ' . round($reference, 4) . ' (width / height) keeping the subject: ' . $key,
                ['expected' => round($reference, 4), 'actual' => round($actual, 4), 'tolerance' => $tolerance]);
        $minWidth = $fit['minWidth'] ?? null;
        if (is_int($minWidth) && $minWidth > 0 && $size[0] < $minWidth)
            throw $refuse('SLOT_IMAGE_TOO_SMALL', 'Image is too small for its slot: it must be at least ' . $minWidth . ' pixels wide to stay sharp, this one is ' . $size[0] . '; use a larger original: ' . $key,
                ['limit' => $minWidth, 'actual' => (int)$size[0]]);
        if (($fit['transparent'] ?? null) === true && self::transparent($file, $size) === false)
            throw $refuse('SLOT_IMAGE_OPAQUE', 'This slot shows a cut-out on a transparent ground and this image is opaque: ask the customer for a cut-out, or produce one by removing the background (PNG or WebP with transparency): ' . $key);
        return $reference !== null;
    }

    /** Words over their box are refused with a request to rewrite them shorter; nothing is ever cut. */
    public static function checkText(array $fit, string $key, string $value, callable $refuse): void
    {
        if (($fit['measured'] ?? null) !== true) return;
        $limit = $fit['maxCharacters'] ?? null;
        if (!is_int($limit) && is_array($fit['maxChars'] ?? null)) {
            $all = array_filter($fit['maxChars'], 'is_int');
            $limit = $all ? min($all) : null;
        }
        if (is_int($limit) && $limit > 0 && mb_strlen($value) > $limit)
            throw $refuse('SLOT_TOO_LONG', 'Content too long for its box: rewrite it shorter, at most ' . $limit . ' characters, keeping its meaning (never cut it or add an ellipsis): ' . $key,
                ['limit' => $limit, 'actual' => mb_strlen($value)]);
        $lines = is_array($fit['lines'] ?? null) ? array_filter($fit['lines'], fn($n) => is_int($n) && $n > 0) : [];
        $count = substr_count(str_replace("\r\n", "\n", $value), "\n") + 1;
        if ($lines && $count > min($lines))
            throw $refuse('SLOT_TOO_LONG', 'Content has more lines than its box holds: rewrite it in at most ' . min($lines) . ' line(s): ' . $key,
                ['limit' => min($lines), 'actual' => $count, 'unit' => 'lines']);
    }

    /**
     * The aspect a new picture is held to: the box's for a picture cut to it (crop "cover"), the demo
     * file's for one fitted whole or never cut (a logo in a wider box would otherwise refuse its own demo).
     */
    private static function referenceAspect(array $fit): ?float
    {
        if (($fit['measured'] ?? null) !== true) return null;
        $crop = $fit['crop'] ?? 'cover';
        if ($crop === 'contain' || $crop === 'none') {
            $w = self::number($fit['source']['width'] ?? null); $h = self::number($fit['source']['height'] ?? null);
            return $w !== null && $h !== null ? $w / $h : null;
        }
        return self::number($fit['aspect'] ?? null);
    }

    private static function number($n): ?float
    {
        return (is_int($n) || is_float($n)) && $n > 0 ? (float)$n : null;
    }

    /**
     * Whether a picture has a transparent ground, read the way capture reads the demo: a transparent corner.
     * false: it has no alpha at all (JPEG, a PNG or WebP without one) or every corner is opaque. null: it
     * may have one but could not be decoded here (no GD, too large), which is not refused.
     */
    public static function transparent(string $file, array $size): ?bool
    {
        $type = $size[2] ?? 0;
        if ($type === IMAGETYPE_JPEG) return false;
        $head = (string)@file_get_contents($file, false, null, 0, 65536);
        $mayHaveAlpha = $type === IMAGETYPE_PNG ? self::pngAlpha($head) : ($type === IMAGETYPE_WEBP ? self::webpAlpha($head) : null);
        if ($mayHaveAlpha !== true) return $mayHaveAlpha;
        $open = $type === IMAGETYPE_PNG ? 'imagecreatefrompng' : 'imagecreatefromwebp';
        if (!function_exists($open) || !function_exists('imagecolorat') || $size[0] * $size[1] > self::DECODE_PIXELS) return null;
        $image = @$open($file);
        if (!$image) return null;
        $w = imagesx($image) - 1; $h = imagesy($image) - 1;
        foreach ([[0, 0], [$w, 0], [0, $h], [$w, $h]] as [$x, $y])
            if (((imagecolorat($image, $x, $y) >> 24) & 0x7f) >= self::CLEAR_ALPHA) return true;
        return false;
    }

    /** A PNG carries alpha when its colour type has it (4, 6) or a tRNS chunk precedes the image data. */
    private static function pngAlpha(string $head): ?bool
    {
        if (strlen($head) < 33 || substr($head, 0, 8) !== "\x89PNG\r\n\x1a\n") return null;
        if (in_array(ord($head[25]), [4, 6], true)) return true;
        for ($at = 8; $at + 8 <= strlen($head);) {
            $length = unpack('N', substr($head, $at, 4))[1]; $chunk = substr($head, $at + 4, 4);
            if ($chunk === 'tRNS') return true;
            if ($chunk === 'IDAT' || $chunk === 'IEND') return false;
            $at += 12 + $length;
        }
        return null;
    }

    /** A WebP carries alpha by its extended header's flag (VP8X) or its lossless header's bit (VP8L); simple lossy has none. */
    private static function webpAlpha(string $head): ?bool
    {
        if (strlen($head) < 30 || substr($head, 0, 4) !== 'RIFF' || substr($head, 8, 4) !== 'WEBP') return null;
        $chunk = substr($head, 12, 4);
        if ($chunk === 'VP8X') return (ord($head[20]) & 0x10) !== 0;
        if ($chunk === 'VP8L') return ((ord($head[24]) >> 4) & 1) === 1;
        if ($chunk === 'VP8 ') return false;
        return null;
    }
}
