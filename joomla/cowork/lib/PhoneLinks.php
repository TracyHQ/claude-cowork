<?php
/** Reviewed scalar HTML phone slots only. No general HTML, renderer or contact-field editing. */
final class PhoneLinks
{
    public static function review(string $file, string $contract, string $mapFile): array
    {
        $all = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (($all['version'] ?? null) !== 1) return [];
        $entry = $all['contracts'][$contract] ?? null;
        if (!is_array($entry) || !is_file($mapFile) || !hash_equals((string) ($entry['contentMapSha256'] ?? ''), (string) hash_file('sha256', $mapFile))) return [];
        return array_values(array_filter($entry['slots'] ?? [], 'is_string'));
    }

    public static function ability(string $lib): array
    {
        $file = $lib . '/phone-links.json';
        $all = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $contracts = [];
        foreach (is_array($all['contracts'] ?? null) ? $all['contracts'] : [] as $id => $entry) {
            $slots = self::review($file, (string) $id, $lib . '/contracts/' . $id . '/content-map.json');
            if ($slots) $contracts[$id] = ['slots' => $slots, 'contentMapSha256' => $entry['contentMapSha256']];
        }
        return ['version' => 1, 'storage' => 'html', 'scalar' => false, 'contracts' => $contracts];
    }

    public static function target(string $expected, string $tel): void
    {
        $shown = trim($expected, ' ');
        if (!preg_match('/^(?:\+[1-9]|[0-9])[0-9]{5,14}$/D', $tel) || !preg_match('/^(?:\+|00)?(?:[0-9]+|\([0-9]+\))(?:[ .-]*(?:[0-9]+|\([0-9]+\)))*$/D', $shown))
            throw new ContractProblem('PHONE_LINK_INVALID', 'An exact phone and canonical numeric tel target are required');
        $digits = preg_replace('/[^0-9]/', '', $shown);
        if (strpos($shown, '00') === 0) $digits = substr($digits, 2);
        if (!hash_equals(strpos($tel, '+') === 0 ? substr($tel, 1) : $tel, $digits))
            throw new ContractProblem('PHONE_LINK_INVALID', 'The tel target must dial exactly the expected phone');
    }
}
