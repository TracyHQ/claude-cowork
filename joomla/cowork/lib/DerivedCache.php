<?php
/**
 * DerivedCache — a derived contract's built map, kept between requests.
 *
 * A derived map is the site's own rows run through LeafCodec and DerivedMap, and on a large import
 * (2,500 posts, 120,000 meta rows) building it costs seconds and well over 128 MB. Every read,
 * locate and inspect needs it, so it is kept, keyed by:
 *
 * - a FINGERPRINT of the source tables, which the engine computes cheaply in SQL (counts, the
 *   highest ids, a checksum of the columns the rows are read from): any write to what the map is
 *   made of changes it, through this plugin or behind its back;
 * - the BASIS the binding asks for: label, algorithm, `keep`, calibration;
 * - the receiver's version, so an upgrade that reads rows differently never serves an old map.
 *
 * Never keyed by the content revision: that is computed FROM the map. Stored compressed through two
 * engine callables (an option on WordPress, a component table row on Joomla); an entry too large
 * for one row drops its rows first, then is not stored at all, and a build then happens as before.
 * Nothing here is trusted beyond a cache: a stored entry that does not decode is a miss.
 *
 * Plain PHP, no CMS. Identical in the Joomla and WordPress engines.
 */
declare(strict_types=1);

final class DerivedCache
{
    /** Stored bytes past which an entry drops its rows, and past which again it is not stored: under a 4 MB max_allowed_packet. */
    public const MAX_BYTES = 3145728;
    /** The memory a derive or a map build asks for when the site gives less (wp-admin's own default). */
    public const MEMORY_FLOOR = 268435456;

    /** Maps this object had to build; what the tests count. */
    public int $builds = 0;
    /** @var callable(): string */
    private $fingerprint;
    /** @var callable(): ?string */
    private $load;
    /** @var callable(?string): void */
    private $save;
    private string $version;
    private ?string $key = null;
    private ?array $entry = null;
    /** Whether a store waits for flush(): the caller reads inside a READ ONLY transaction. */
    private bool $held = false;
    /** @var array{0:?string}|null the store held back, until flush() */
    private ?array $pending = null;
    /**
     * The fingerprint, taken once per object and per snapshot: one object serves a whole request
     * (the contract and the content reader share it), and forgets it at every snapshot edge and on clear().
     */
    private ?string $print = null;

    /**
     * @param callable(): string $fingerprint the source tables, now
     * @param callable(): ?string $load the stored entry, or null
     * @param callable(?string): void $save stores an entry; null removes it
     */
    public function __construct(callable $fingerprint, callable $load, callable $save, string $version = '')
    {
        $this->fingerprint = $fingerprint;
        $this->load = $load;
        $this->save = $save;
        $this->version = $version;
    }

    /**
     * The map for `$basis` on the site as it is now: stored, or built by `$make` and stored.
     *
     * @param callable(): array{built:array,rows?:list<array>|null} $make
     * @return array{built:array,rows:array<string,array>|null} rows: the rows that carry a slot, by entity key; null when not kept
     */
    public function get(array $basis, callable $make): array
    {
        $this->print ??= $this->fingerprint();
        if ($this->print === null) {
            // The tables could not be fingerprinted (a collation clash, a permission): build, keep nothing.
            $made = $make();
            $this->builds++;
            return ['built' => $made['built'], 'rows' => isset($made['rows']) ? self::mapped($made['built'], $made['rows']) : null];
        }
        $key = $this->key($basis, $this->print);
        if ($this->key === $key && $this->entry !== null) return $this->entry;
        $entry = null;
        try {
            $entry = self::decode(($this->load)(), $key);
        } catch (Throwable $e) {
            // An unreadable store is a miss.
        }
        if ($entry === null) {
            $made = $make();
            $this->builds++;
            $entry = ['built' => $made['built'], 'rows' => isset($made['rows']) ? self::mapped($made['built'], $made['rows']) : null];
            $stored = self::encode($key, $entry);
            if ($this->held) {
                // Inside a snapshot the rows and the fingerprint were read at one instant: stored after it ends.
                $this->pending = [$stored];
            } elseif ($this->fingerprint() === $this->print) {
                $this->store($stored);
            } else {
                // Another writer moved the tables while this build read them: the map may mix both states.
                $this->print = null;
                return $entry;
            }
        }
        $this->key = $key;
        $this->entry = $entry;
        return $entry;
    }

    /**
     * Hold every store until flush(). A reader inside `START TRANSACTION ... READ ONLY` cannot write:
     * MySQL refuses the statement, and WordPress's update_option() only answers false (measured on
     * an imported shop, 30/09/2026: the map was built on every read and never kept).
     */
    public function hold(): void
    {
        $this->held = true;
        $this->print = null; // taken again inside the snapshot, with the rows it reads
    }

    /** Write what hold() kept back, once the read-only transaction is over. */
    public function flush(): void
    {
        $this->held = false;
        $this->print = null; // the snapshot is over: a write may follow
        if ($this->pending !== null) {
            [$stored] = $this->pending;
            $this->pending = null;
            $this->store($stored);
        }
    }

    /** The source tables now, or null when they cannot be read. */
    private function fingerprint(): ?string
    {
        try {
            return (string) ($this->fingerprint)();
        } catch (Throwable $e) {
            return null;
        }
    }

    private function key(array $basis, string $print): string
    {
        return hash('sha256', (string) json_encode([$this->version, DerivedMap::ALGORITHM, $print, $basis]));
    }

    private function store(?string $stored): void
    {
        try {
            ($this->save)($stored);
        } catch (Throwable $e) {
            // A map that cannot be stored is only slower next time.
        }
    }

    /** Drop the stored entry: after a write this receiver made, the next read builds again. */
    public function clear(): void
    {
        $this->key = null;
        $this->entry = null;
        $this->pending = null;
        $this->print = null;
        ($this->save)(null);
    }

    /** A php.ini byte count (`128M`, `1G`, `262144K`, `-1`); an empty one is no limit. */
    public static function bytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') return -1;
        $number = (int) $value;
        switch (strtoupper(substr($value, -1))) {
            case 'G': return $number * 1073741824;
            case 'M': return $number * 1048576;
            case 'K': return $number * 1024;
        }
        return $number;
    }

    /** The limit to set so at least `$floor` bytes are there, or null when the current one already allows it. */
    public static function raisedLimit(string $current, int $floor = self::MEMORY_FLOOR): ?string
    {
        $have = self::bytes($current);
        return $have === -1 || $have >= $floor ? null : (string) $floor;
    }

    /** Raise this request's memory limit to `$floor`, as wp-admin does for its heavy screens; never lowers it. */
    public static function raiseMemory(int $floor = self::MEMORY_FLOOR): void
    {
        $limit = self::raisedLimit((string) ini_get('memory_limit'), $floor);
        if ($limit !== null) @ini_set('memory_limit', $limit);
    }

    /** @param list<array> $rows @return array<string,array> */
    private static function mapped(array $built, array $rows): array
    {
        $mapped = array_flip(array_column($built['map']['entities'], 'key'));
        $out = [];
        foreach ($rows as $row) {
            $key = $row['kind'] . '-' . $row['id'];
            if (isset($mapped[$key])) $out[$key] = $row;
        }
        return $out;
    }

    /** `<key>:<base64 of gzcompressed serialize>`; the key in clear, so a stale entry is not decompressed. */
    private static function encode(string $key, array $entry): ?string
    {
        if (!function_exists('gzcompress')) return null;
        $pack = static fn(array $value): string => $key . ':' . base64_encode((string) gzcompress(serialize($value), 6));
        $stored = $pack($entry);
        if (strlen($stored) > self::MAX_BYTES && $entry['rows'] !== null) $stored = $pack(['rows' => null] + $entry);
        return strlen($stored) > self::MAX_BYTES ? null : $stored;
    }

    private static function decode(?string $stored, string $key): ?array
    {
        if ($stored === null || strncmp($stored, $key . ':', strlen($key) + 1) !== 0 || !function_exists('gzuncompress')) return null;
        $raw = base64_decode(substr($stored, strlen($key) + 1), true);
        $raw = $raw === false ? false : @gzuncompress($raw);
        $entry = $raw === false ? false : @unserialize($raw, ['allowed_classes' => false]);
        return is_array($entry) && isset($entry['built']['manifest'], $entry['built']['map']) && array_key_exists('rows', $entry) ? $entry : null;
    }
}
