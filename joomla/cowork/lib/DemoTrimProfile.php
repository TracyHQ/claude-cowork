<?php
/**
 * Which rows of a published quickstart are the vendor's demo, and may be hidden on a bound site.
 *
 * 🔒 A FIFTH FILE, NOT AN EDIT OF THE OTHER THREE. A quickstart ships its whole demo site — ja-kinetic
 * carries 241 blog posts about a company that does not exist — and a content-only contract forbids
 * unpublishing, correctly, because a hidden row is a changed site. This profile is the one reviewed
 * list of exceptions. It is pinned to the base contract's bytes (`baseHash`, the same formula as the
 * multilingual profile), so it can never be applied to a contract it was not generated from, and the
 * three base files keep the hash every already-bound site is held to.
 *
 * 🔒 VISIBILITY ONLY. A row may move between `from` (what the contract locked) and `to`, on its
 * kind's own visibility column, and nothing else. Deleting would break the inventory the contract
 * counts; any other field would be a design change wearing a content label.
 */
final class DemoTrimProfile
{
    /** The one column per kind that decides whether Joomla serves the row. */
    public const VISIBILITY = ['article' => 'state', 'menuItem' => 'published', 'module' => 'published'];

    private array $profile;
    private string $hash;
    /** @var array<string,array{key:string,kind:string,field:string,from:string,to:string,reason:string}> */
    private array $rows = [];

    public function __construct(array $profile, array $map, array $lock, string $directory, string $rawProfile)
    {
        $this->profile = $profile;
        $this->hash = hash('sha256', $rawProfile);
        if (($profile['schemaVersion'] ?? '') !== 'tracy-quickstart-demo-trim/v1')
            throw new RuntimeException('Unsupported demo-trim profile');
        $lines = '';
        foreach (['manifest.json', 'content-map.json', 'presentation-lock.json'] as $name) {
            $file = $directory . '/' . $name;
            if (!is_file($file)) throw new RuntimeException('Missing contract file: ' . $name);
            $lines .= $name . ':' . hash_file('sha256', $file) . "\n";
        }
        if (!hash_equals((string) ($profile['baseHash'] ?? ''), hash('sha256', $lines)))
            throw new RuntimeException('The demo-trim profile does not belong to this contract');
        if (!isset($profile['hide']) || !is_array($profile['hide']) || !$profile['hide'])
            throw new RuntimeException('Incomplete demo-trim profile: hide');
        $kinds = array_column($map['entities'], 'kind', 'key');
        foreach ($profile['hide'] as $row) {
            $key = (string) ($row['key'] ?? '');
            $kind = (string) ($row['kind'] ?? '');
            $field = (string) ($row['field'] ?? '');
            if (!isset($kinds[$key], $lock['entities'][$key])) throw new RuntimeException('Demo-trim row names an entity outside the contract: ' . $key);
            if ($kinds[$key] !== $kind) throw new RuntimeException('Demo-trim row names the wrong kind for ' . $key);
            if ((self::VISIBILITY[$kind] ?? null) !== $field) throw new RuntimeException('Demo-trim row may only change the visibility column of ' . $key);
            if ((string) ($lock['entities'][$key][$field] ?? '') !== (string) ($row['from'] ?? null))
                throw new RuntimeException('Demo-trim row does not start from the locked value of ' . $key);
            if ((string) ($row['to'] ?? '') !== '0') throw new RuntimeException('Demo-trim row must hide ' . $key);
            if (isset($this->rows[$key])) throw new RuntimeException('Demo-trim row listed twice: ' . $key);
            $this->rows[$key] = ['key' => $key, 'kind' => $kind, 'field' => $field, 'from' => (string) $row['from'], 'to' => '0', 'reason' => (string) ($row['reason'] ?? '')];
        }
    }

    public function hash(): string { return $this->hash; }
    public function version(): string { return (string) ($this->profile['extensionVersion'] ?? ''); }
    /** @return array<string,array{key:string,kind:string,field:string,from:string,to:string,reason:string}> */
    public function rows(): array { return $this->rows; }
    public function row(string $key): ?array { return $this->rows[$key] ?? null; }
    /** @return array<string,int> rows per kind, for a plan that says what it would do */
    public function counts(): array {
        $out = [];
        foreach ($this->rows as $row) $out[$row['kind']] = ($out[$row['kind']] ?? 0) + 1;
        ksort($out);
        return $out;
    }
}
