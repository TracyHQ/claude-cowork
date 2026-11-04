<?php
/**
 * Slot corrections: what Tracy's template kit knows better than a released contract, beside it. A contract is never
 * edited and a bound site stores its hash, so `lib/slot-corrections.json` is receiver data, read per contract and tied to
 * the sha256 of the content map it was read against. `scripts/slot-corrections.mjs` writes it from the kit.
 *
 *   limits  the kit's limit in place of a text slot's fit
 *   icons   a kept icon (fit.replace "keep") becomes fit.replace "icon": SlotFit takes a transparent drawing there
 *   alts    a gallery alt with an empty sample takes this limit, and may be filled (`opensEmpty`)
 *
 * 🔒 ONLY WHAT A SLOT READS IS CORRECTED, NEVER THE MAP. The contract hash is the digest of the map as released, so the
 * slots change where they are handed out and the map itself stays as it came. No list, another contract, other map
 * bytes, or an unreadable file: nothing is corrected, and every slot answers as it always did.
 */
final class SlotCorrections
{
    public const VERSION = 1;

    /** @var array<string,int> */
    private $limits = [];
    /** @var array<string,bool> */
    private $icons = [];
    /** @var array<string,int> */
    private $alts = [];

    /** The corrections of a contract, or null when the list holds none that apply to these map bytes. */
    public static function load(string $file, string $contract, string $mapFile): ?self
    {
        $entry = self::contracts($file)[$contract] ?? null;
        if ($entry === null || !is_file($mapFile) || !hash_equals((string) $entry['contentMapSha256'], (string) hash_file('sha256', $mapFile))) return null;
        $out = new self();
        foreach ($entry['limits'] as $key => $limit) if (is_int($limit) && $limit > 0) $out->limits[(string) $key] = $limit;
        foreach ($entry['icons'] as $key) if (is_string($key)) $out->icons[$key] = true;
        foreach ($entry['alts'] as $key => $limit) if (is_int($limit) && $limit > 0) $out->alts[(string) $key] = $limit;
        return $out;
    }

    /** What `info` says: the ability and, per contract, how many slots each rule reaches. */
    public static function ability(string $file): array
    {
        $contracts = [];
        foreach (self::contracts($file) as $id => $entry)
            $contracts[$id] = ['limits' => count($entry['limits']), 'icons' => count($entry['icons']), 'alts' => count($entry['alts'])];
        return ['version' => self::VERSION, 'contracts' => $contracts];
    }

    /** @return array<string,array> the file's well-formed entries by contract id; none when it is absent or unreadable */
    private static function contracts(string $file): array
    {
        if (!is_file($file)) return [];
        $data = json_decode((string) file_get_contents($file), true);
        $out = [];
        foreach (is_array($data['contracts'] ?? null) ? $data['contracts'] : [] as $id => $entry) {
            if (!is_array($entry) || !is_string($entry['contentMapSha256'] ?? null)) continue;
            foreach (['limits', 'icons', 'alts'] as $rule) $entry[$rule] = is_array($entry[$rule] ?? null) ? $entry[$rule] : [];
            $out[(string) $id] = $entry;
        }
        return $out;
    }

    public function hasIcons(): bool
    {
        return $this->icons !== [];
    }

    /** The slot as it is held now. A translation is held as its source is, so it is found by the source's key. */
    public function apply(array $slot): array
    {
        $key = (string) ($slot['sourceKey'] ?? $slot['key']);
        $type = $slot['type'] ?? '';
        if ($type === 'text' && isset($this->limits[$key]) && is_array($slot['fit'] ?? null)) {
            $slot['fit']['maxCharacters'] = $this->limits[$key];
            unset($slot['fit']['maxChars']);
        }
        if ($type === 'image' && isset($this->icons[$key]) && ($slot['fit']['replace'] ?? null) === 'keep') $slot['fit']['replace'] = 'icon';
        if ($type === 'text' && isset($this->alts[$key]) && trim((string) ($slot['sample'] ?? '')) === '') {
            $slot['maxCharacters'] = $this->alts[$key];
            $slot['opensEmpty'] = true;
        }
        return $slot;
    }
}
