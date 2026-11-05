<?php
/**
 * ExtensionParams: the named params of a few named extensions that `extension.params` may read and set.
 *
 * `content.update` `extensionParams` replaces an extension's whole `params` column, so a caller has to
 * read the column first and send every key back, the extension's secrets included (JA Google Map keeps
 * its Google API key there). This door goes the other way: it knows, per extension, the few keys a
 * customer's content lives in, takes only those, checks each value, and merges them over the column
 * the site holds. Every other key stays byte for byte, and no other key is ever answered.
 *
 * An extension that is not listed here is refused, and so is a key that is not listed under it. Adding
 * one is a reviewed change to ALLOWED and to its cleaner below, never a parameter of the call.
 *
 * No Joomla dependency: the engine reads and writes the row through the SiteWriter (`extensionParams`),
 * so the write lands in the undo log like any `content.update` and `apply.revert` takes it back.
 */
final class ExtensionParams
{
    /**
     * Which keys of which extension, by "type/folder/element" (folder empty for anything but a plugin).
     *
     * `plugin/system/jagooglemap` (System - JA Google Map): `locations` is the map the plugin draws for a
     * `{jamap}` shortcode that names no `locations` of its own, and the place its admin form shows;
     * `zoom` is that map's zoom (the form's list: 0 to 20).
     *
     * @var array<string,list<string>>
     */
    public const ALLOWED = [
        'plugin/system/jagooglemap' => ['locations', 'zoom'],
    ];

    /** At most this many places on one map; the plugin's own form adds them one by one. */
    public const MAX_LOCATIONS = 20;
    /** At most this many characters in a place's address or in its info line. */
    public const MAX_TEXT = 300;

    /** The key of an extension in ALLOWED. */
    public static function key(string $type, ?string $folder, string $element): string
    {
        return $type . '/' . ($folder ?? '') . '/' . $element;
    }

    /** The keys this door may write for one extension, or null when the extension is not one of ALLOWED. */
    public static function keysOf(string $type, ?string $folder, string $element): ?array
    {
        return self::ALLOWED[self::key($type, $folder, $element)] ?? null;
    }

    /** What `info` says this door takes: the version and every extension with its keys. */
    public static function ability(): array
    {
        return ['version' => 1, 'extensions' => self::ALLOWED];
    }

    /**
     * One value checked and put in the form the extension stores it in.
     *
     * @return array{value: mixed}|array{error: string}
     */
    public static function clean(string $extensionKey, string $name, $value): array
    {
        if ($extensionKey === 'plugin/system/jagooglemap') {
            if ($name === 'locations') return self::mapLocations($value);
            if ($name === 'zoom') return self::mapZoom($value);
        }
        return ['error' => "{$name} cannot be written through extension.params"];
    }

    /**
     * The params column with the cleaned values set over it, every other key as it was.
     *
     * @param array<string,mixed> $clean
     * @return array{json: string}|array{error: string}
     */
    public static function merge(?string $params, array $clean): array
    {
        $current = trim((string) $params) === '' ? [] : json_decode((string) $params, true);
        if (!is_array($current) || ($current !== [] && array_keys($current) === range(0, count($current) - 1))) {
            return ['error' => 'the params this extension holds are not a JSON object; nothing was written'];
        }
        foreach ($clean as $name => $value) $current[$name] = $value;
        $json = json_encode($current, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json === false ? ['error' => 'the params could not be encoded; nothing was written'] : ['json' => $json];
    }

    /**
     * The allowed keys of a params column, as the site holds them: what `read` answers, and nothing else.
     *
     * @param list<string> $keys
     * @return array<string,mixed>
     */
    public static function shown(?string $params, array $keys): array
    {
        $current = json_decode((string) $params, true);
        $out = [];
        foreach ($keys as $key) $out[$key] = is_array($current) && array_key_exists($key, $current) ? $current[$key] : null;
        return $out;
    }

    /**
     * A list of places, as the plugin's form saves them: one list per field, the same length, every
     * value a string. Each place: `location` (one line, the address as typed in Google Maps), and
     * `latitude` and `longitude` as decimal degrees, both or neither (neither: the map looks the
     * address up itself), and an optional plain `info` line.
     *
     * @return array{value: array{location: list<string>, latitude: list<string>, longitude: list<string>, info: list<string>}}|array{error: string}
     */
    private static function mapLocations($value): array
    {
        if (!is_array($value) || $value === [] || array_keys($value) !== range(0, count($value) - 1))
            return ['error' => 'locations must be a list of 1 to ' . self::MAX_LOCATIONS . ' places'];
        if (count($value) > self::MAX_LOCATIONS)
            return ['error' => 'locations holds at most ' . self::MAX_LOCATIONS . ' places'];
        $out = ['location' => [], 'latitude' => [], 'longitude' => [], 'info' => []];
        foreach ($value as $i => $place) {
            if (!is_array($place)) return ['error' => "locations[{$i}] must be an object"];
            $unknown = array_diff(array_keys($place), ['location', 'latitude', 'longitude', 'info']);
            if ($unknown !== []) return ['error' => "locations[{$i}] takes location, latitude, longitude and info only, not " . substr((string) reset($unknown), 0, 40)];
            $location = self::line($place['location'] ?? null);
            if ($location === null || $location === '') return ['error' => "locations[{$i}].location must be one line of plain text, 1 to " . self::MAX_TEXT . ' characters'];
            $info = array_key_exists('info', $place) ? self::line($place['info']) : '';
            if ($info === null) return ['error' => "locations[{$i}].info must be one line of plain text, at most " . self::MAX_TEXT . ' characters'];
            $lat = self::degrees($place['latitude'] ?? null, 90);
            $lng = self::degrees($place['longitude'] ?? null, 180);
            if ($lat === false || $lng === false || ($lat === '') !== ($lng === ''))
                return ['error' => "locations[{$i}]: latitude (-90 to 90) and longitude (-180 to 180) must be decimal degrees, both given or both empty"];
            $out['location'][] = $location;
            $out['latitude'][] = $lat;
            $out['longitude'][] = $lng;
            $out['info'][] = $info;
        }
        return ['value' => $out];
    }

    /** @return array{value: string}|array{error: string} the zoom as the plugin's form stores it */
    private static function mapZoom($value): array
    {
        if (is_string($value) && preg_match('/^\d{1,2}$/D', $value)) $value = (int) $value;
        if (!is_int($value) || $value < 0 || $value > 20) return ['error' => 'zoom must be a whole number from 0 to 20'];
        return ['value' => (string) $value];
    }

    /** One line of plain text, trimmed; null when it is not text, holds markup or a line break, or is too long. */
    private static function line($value): ?string
    {
        if (!is_string($value)) return null;
        $value = trim($value);
        if (preg_match('/[<>\x00-\x1F\x7F]/', $value) || !preg_match('//u', $value)) return null;
        $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        return $length > self::MAX_TEXT ? null : $value;
    }

    /**
     * Decimal degrees as a plain string ('' for none), or false when the value is not a number in range.
     *
     * @return string|false
     */
    private static function degrees($value, int $limit)
    {
        if ($value === null || $value === '') return '';
        if (is_string($value) && preg_match('/^-?\d{1,3}(\.\d{1,12})?$/D', trim($value))) $value = (float) trim($value);
        if (is_int($value)) $value = (float) $value;
        if (!is_float($value) || !is_finite($value) || abs($value) > $limit) return false;
        $text = rtrim(rtrim(sprintf('%.7F', $value), '0'), '.');
        return $text === '-0' ? '0' : $text;
    }
}
