<?php
/**
 * Remove ONE T3 mega-menu `sub`, or one column of it, from a template style's params, leaving every
 * other byte as it was.
 *
 * T3's renderer (`plugins/system/t3/includes/menu/megamenu.php`) opens a dropdown for an item whenever
 * `mm_config.<menutype>.item-<id>.sub` exists, children or not: once a showcase map has hidden every
 * child of the item, that dropdown opens empty. Dropping the key closes it.
 *
 * A COLUMN whose items all leave breaks the panel even when other children stay: `mega()` starts the
 * first column at the first SHOWN child but ends it at the next column's head, which `nav()` has already
 * passed, so the first column lists every remaining column again. Dropping that column's entries closes
 * the gap and the duplicate both.
 *
 * 🔒 BYTE-EXACT OR NOTHING. `mm_config` is a JSON string inside the params JSON. The new column is the
 * old one with only the bytes of the `mm_config` value replaced (JsonObjectKey), and that value is
 * re-encoded the way the release stored it: an encoding is used only when re-encoding the parsed text
 * gives back the very bytes the column holds, for the inner JSON and for the string literal around it.
 * The encoders are the ones Tracy's generator (`scripts/lib/showcase-nav/t3-megamenu.mjs`) tries, written
 * the same way, so the engine reaches the bytes the map's `after` hash names — and the caller proves it
 * does by that hash before writing anything.
 */
require_once __DIR__ . '/JsonObjectKey.php';

final class T3MegaSub
{
    /** PHP `json_encode` with or without unescaped slashes / unicode (U+2028/U+2029 still escaped), and `JSON.stringify`. */
    private const ENCODINGS = [
        'php' => ['slashes' => true, 'unicode' => true, 'lineTerminators' => true],
        'php-unescaped-slashes' => ['slashes' => false, 'unicode' => true, 'lineTerminators' => true],
        'php-unescaped-unicode' => ['slashes' => true, 'unicode' => false, 'lineTerminators' => true],
        'php-unescaped-slashes-unicode' => ['slashes' => false, 'unicode' => false, 'lineTerminators' => true],
        'json-stringify' => ['slashes' => false, 'unicode' => false, 'lineTerminators' => false],
    ];
    private const NAMED = ['"' => '\\"', '\\' => '\\\\', "\x08" => '\\b', "\f" => '\\f', "\n" => '\\n', "\r" => '\\r', "\t" => '\\t'];

    /**
     * `$raw` with `mm_config.<menutype>.item-<item>.sub` removed.
     *
     * @return array{raw:string}|array{absent:true}|array{problem:string}
     */
    public static function remove(string $raw, string $menutype, int $item): array
    {
        return self::edit($raw, $menutype, $item, function (stdClass $setting): bool { unset($setting->sub); return true; });
    }

    /**
     * `$raw` with every `{"item": <column>}` column of that sub removed, in every row; a row this empties
     * goes too, a row that was already empty stays. A module column (placesModule) is never one.
     *
     * @return array{raw:string}|array{absent:true}|array{problem:string}
     */
    public static function removeColumn(string $raw, string $menutype, int $item, int $column): array
    {
        return self::edit($raw, $menutype, $item, function (stdClass $setting) use ($column): bool {
            $rows = $setting->sub->rows ?? null;
            if (!is_array($rows)) return false;
            $found = false;
            foreach ($rows as $row) if (is_array($row)) foreach ($row as $col) if (self::heads($col, $column)) $found = true;
            if (!$found) return false;
            $next = [];
            foreach ($rows as $row) {
                if (!is_array($row)) { $next[] = $row; continue; }
                $kept = array_values(array_filter($row, fn ($col) => !self::heads($col, $column)));
                if ($kept || !$row) $next[] = $kept;
            }
            $setting->sub->rows = $next;
            return true;
        });
    }

    /**
     * Whether a sub column is the column of items headed by `$item`. Only an integer or a string names an
     * item here; anything else (a float, a boolean) matches nothing, so such a sub is never edited.
     */
    public static function heads($col, int $item): bool
    {
        if (!$col instanceof stdClass || self::placesModule($col) || !property_exists($col, 'item')) return false;
        return (is_int($col->item) || is_string($col->item)) && (string) $col->item === (string) $item;
    }

    /**
     * The item columns of the sub a style keeps for `<menutype>.item-<item>`, in the order T3 reads them
     * (row by row, left to right), as the `item` each names; null when the style keeps no such sub.
     *
     * @return ?list<scalar>
     */
    public static function columns(string $raw, string $menutype, int $item): ?array
    {
        return self::sub($raw, $menutype, $item)['heads'] ?? null;
    }

    /**
     * What the sub a style keeps for `<menutype>.item-<item>` holds, as this class reads it: `problem`, the
     * shape it cannot read (null when it can); `heads`, as columns() gives them; `modules`, the module
     * columns that can put something in the panel (moduleId above 0). Null when the style keeps no such sub.
     *
     * @return ?array{problem:?string,heads:list<scalar>,modules:int}
     */
    public static function sub(string $raw, string $menutype, int $item): ?array
    {
        $outer = json_decode($raw);
        $config = $outer instanceof stdClass && is_string($outer->mm_config ?? null) ? json_decode($outer->mm_config) : null;
        $setting = $config instanceof stdClass && ($config->{$menutype} ?? null) instanceof stdClass ? ($config->{$menutype}->{'item-' . $item} ?? null) : null;
        if (!$setting instanceof stdClass || !property_exists($setting, 'sub')) return null;
        $sub = $setting->sub;
        $out = ['problem' => null, 'heads' => [], 'modules' => 0];
        if (!$sub instanceof stdClass) return ['problem' => 'the sub is not an object'] + $out;
        if (!property_exists($sub, 'rows')) return $out;
        if (!is_array($sub->rows)) return ['problem' => 'its rows are not a list'] + $out;
        foreach ($sub->rows as $row) {
            if (!is_array($row)) $out['problem'] ??= 'a row is not a list';
            else foreach ($row as $col) {
                if (!$col instanceof stdClass) $out['problem'] ??= 'a column is not an object';
                elseif (self::placesModule($col)) {
                    $id = self::moduleId($col->position);
                    if ($id === null) $out['problem'] ??= 'a column names a module that is not a scalar';
                    elseif ($id > 0) $out['modules']++;
                } elseif (($col->item ?? null) === null) $out['problem'] ??= 'a column names neither an item nor a module';
                elseif (is_scalar($col->item)) $out['heads'][] = $col->item;
            }
        }
        return $out;
    }

    /**
     * T3's own test for a module column (`mega()`, `isset($col['position'])`): the key is there and not null.
     * Any other column, `"position": null` included, is a column of items headed by its `item`.
     */
    public static function placesModule(stdClass $col): bool
    {
        return isset($col->position);
    }

    /**
     * The module id `module()` loads for a scalar `position`, by PHP `intval()` as T3 reads it ("" and
     * "abc" give 0, " 112" and "112abc" 112); null for a list or an object, a shape this does not read.
     */
    public static function moduleId($position): ?int
    {
        return is_scalar($position) ? intval($position) : null;
    }

    /**
     * `$raw` with one edit made to the parsed `mm_config.<menutype>.item-<item>` setting, which holds a
     * `sub`; `$change` returns false when it has nothing to change.
     */
    private static function edit(string $raw, string $menutype, int $item, callable $change): array
    {
        $outer = json_decode($raw);
        if (!$outer instanceof stdClass) return ['problem' => 'the params column is not a JSON object'];
        if (!property_exists($outer, 'mm_config')) return ['absent' => true];
        $inner = $outer->mm_config;
        // An empty or null mm_config is T3's "no mega settings": no sub to remove.
        if ($inner === null || $inner === '') return ['absent' => true];
        if (!is_string($inner)) return ['problem' => 'mm_config is not a JSON string'];
        $config = json_decode($inner);
        if ($config === null && trim($inner) !== 'null') return ['problem' => 'mm_config does not parse'];
        $name = 'item-' . $item;
        $setting = $config instanceof stdClass && ($config->{$menutype} ?? null) instanceof stdClass ? ($config->{$menutype}->{$name} ?? null) : null;
        if (!$setting instanceof stdClass || !property_exists($setting, 'sub')) return ['absent' => true];
        try { $literal = JsonObjectKey::get($raw, 'mm_config')['literal']; }
        catch (Throwable $error) { return ['problem' => 'the place of mm_config in params is ambiguous']; }
        $innerEnc = self::roundTrip($inner, $config);
        $outerEnc = null;
        foreach (self::ENCODINGS as $enc) if (self::encodeString($inner, $enc) === $literal) { $outerEnc = $enc; break; }
        if ($innerEnc === null || $outerEnc === null)
            return ['problem' => 'mm_config does not re-encode to the bytes the column holds (' . ($innerEnc === null ? 'inner JSON' : 'string literal') . ')'];
        if (!$change($setting)) return ['absent' => true];
        $nextInner = self::encode($config, $innerEnc);
        try { $next = JsonObjectKey::set($raw, 'mm_config', self::encodeString($nextInner, $outerEnc)); }
        catch (Throwable $error) { return ['problem' => 'the edited params column does not read back as expected']; }
        // Proof of the edit: the new column reads as the old one with only mm_config changed, as encoded.
        $want = clone $outer;
        $want->mm_config = $nextInner;
        $reread = json_decode($next);
        if (!$reread instanceof stdClass || json_encode($reread) !== json_encode($want) || self::encode(json_decode($nextInner), $innerEnc) !== $nextInner)
            return ['problem' => 'the edited params column does not read back as expected'];
        return ['raw' => $next];
    }

    /** The first encoding that writes `$parsed` back as exactly `$text`, or null. */
    private static function roundTrip(string $text, $parsed): ?array
    {
        foreach (self::ENCODINGS as $enc) {
            $out = self::encode($parsed, $enc);
            if ($out !== null && $out === $text) return $enc;
        }
        return null;
    }

    /** Compact JSON as `$enc` writes it, or null for a value the generator would not write the same way. */
    private static function encode($value, array $enc): ?string
    {
        if ($value === null) return 'null';
        if (is_bool($value)) return $value ? 'true' : 'false';
        if (is_int($value)) return (string) $value;
        // JavaScript prints an integral float without a fraction (`4.0` reads back as `4`), any other the shortest way.
        if (is_float($value)) {
            if (!is_finite($value)) return 'null';
            if ($value == floor($value) && abs($value) < 1e15) return sprintf('%.0f', $value);
            return null;
        }
        if (is_string($value)) return self::encodeString($value, $enc);
        $parts = [];
        if (is_array($value)) {
            foreach ($value as $v) { $part = self::encode($v, $enc); if ($part === null) return null; $parts[] = $part; }
            return '[' . implode(',', $parts) . ']';
        }
        foreach (get_object_vars($value) as $k => $v) {
            $part = self::encode($v, $enc);
            if ($part === null) return null;
            $parts[] = self::encodeString((string) $k, $enc) . ':' . $part;
        }
        return '{' . implode(',', $parts) . '}';
    }

    /** A JSON string literal as `$enc` writes it; escapes count UTF-16 units, as JavaScript does. */
    private static function encodeString(string $text, array $enc): string
    {
        $out = '"';
        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
            $code = self::ord($ch);
            if (isset(self::NAMED[$ch])) $out .= self::NAMED[$ch];
            elseif ($code < 0x20) $out .= sprintf('\\u%04x', $code);
            elseif ($ch === '/' && $enc['slashes']) $out .= '\\/';
            elseif ($code > 0x7f && $enc['unicode']) $out .= self::utf16($code);
            elseif (($code === 0x2028 || $code === 0x2029) && $enc['lineTerminators']) $out .= sprintf('\\u%04x', $code);
            else $out .= $ch;
        }
        return $out . '"';
    }

    /** The code point of one UTF-8 character, without mbstring. */
    private static function ord(string $ch): int
    {
        $b = array_values(unpack('C*', $ch));
        if (count($b) === 1) return $b[0];
        if (count($b) === 2) return (($b[0] & 0x1f) << 6) | ($b[1] & 0x3f);
        if (count($b) === 3) return (($b[0] & 0x0f) << 12) | (($b[1] & 0x3f) << 6) | ($b[2] & 0x3f);
        return (($b[0] & 0x07) << 18) | (($b[1] & 0x3f) << 12) | (($b[2] & 0x3f) << 6) | ($b[3] & 0x3f);
    }

    private static function utf16(int $code): string
    {
        if ($code < 0x10000) return sprintf('\\u%04x', $code);
        $code -= 0x10000;
        return sprintf('\\u%04x\\u%04x', 0xd800 + ($code >> 10), 0xdc00 + ($code & 0x3ff));
    }
}
