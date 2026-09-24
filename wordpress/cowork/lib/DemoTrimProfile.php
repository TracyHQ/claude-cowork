<?php
/**
 * Which posts of a published WordPress quickstart are the vendor's demo, and may be hidden on a
 * bound site.
 *
 * A quickstart ships its whole demo site — a blog about a company that does not exist — and a
 * content-only contract forbids unpublishing, correctly, because a hidden row is a changed site.
 * This profile is the one reviewed list of exceptions. It is pinned to the base contract's bytes
 * (`baseHash`, the Joomla formula: one `<name>:<sha256>` line per base file), so it can never be
 * applied to a contract it was not generated from, and the three base files keep the hash every
 * already-bound site is held to.
 *
 * VISIBILITY ONLY. A row may move between `from` (what the release shipped) and `to`, on
 * `post_status`, and nothing else. Rows carry the post id because this plugin keeps no inventory:
 * a demo post is not a contract entity, so nothing else on this side could name it.
 */
final class DemoTrimProfile
{
    public const SCHEMA = 'tracy-quickstart-demo-trim/wordpress/v1';
    public const FIELD = 'post_status';
    private const BASE_FILES = ['manifest.json', 'content-map.json', 'presentation-lock.json'];

    private array $profile;
    private string $hash;
    /** @var array<string,array{key:string,kind:string,id:int,field:string,from:string,to:string,language:string,reason:string}> */
    private array $rows = [];

    /** The `baseHash` formula, shared with `editions.json`: one line per base file, in this order. */
    public static function baseHash(string $directory): string
    {
        $lines = '';
        foreach (self::BASE_FILES as $name) {
            $file = $directory . '/' . $name;
            if (!is_file($file)) {
                throw new RuntimeException('Missing contract file: ' . $name);
            }
            $lines .= $name . ':' . hash_file('sha256', $file) . "\n";
        }
        return hash('sha256', $lines);
    }

    public function __construct(array $profile, string $directory, string $rawProfile)
    {
        $this->profile = $profile;
        $this->hash = hash('sha256', $rawProfile);
        if (($profile['schemaVersion'] ?? '') !== self::SCHEMA) {
            throw new RuntimeException('Unsupported demo-trim profile');
        }
        if (!hash_equals(self::baseHash($directory), (string) ($profile['baseHash'] ?? ''))) {
            throw new RuntimeException('The demo-trim profile does not belong to this contract');
        }
        if (!isset($profile['hide']) || !is_array($profile['hide']) || $profile['hide'] === []) {
            throw new RuntimeException('Incomplete demo-trim profile: hide');
        }
        $ids = [];
        foreach ($profile['hide'] as $row) {
            if (!is_array($row)) {
                throw new RuntimeException('Demo-trim row is not an object');
            }
            $key = (string) ($row['key'] ?? '');
            $kind = (string) ($row['kind'] ?? '');
            $id = (int) ($row['id'] ?? 0);
            $from = (string) ($row['from'] ?? '');
            $to = (string) ($row['to'] ?? '');
            $language = (string) ($row['language'] ?? '');
            if ($key === '' || isset($this->rows[$key])) {
                throw new RuntimeException('Demo-trim row has no key, or is listed twice: ' . $key);
            }
            if (!in_array($kind, ['post', 'page'], true)) {
                throw new RuntimeException('Demo-trim row may only hide a post or a page: ' . $key);
            }
            if ($id <= 0 || isset($ids[$id])) {
                throw new RuntimeException('Demo-trim row has no post id, or names one twice: ' . $key);
            }
            if ((string) ($row['field'] ?? '') !== self::FIELD) {
                throw new RuntimeException('Demo-trim row may only change ' . self::FIELD . ' of ' . $key);
            }
            foreach (['from' => $from, 'to' => $to] as $end => $status) {
                if (!preg_match('/^[a-z][a-z0-9_-]{0,19}$/D', $status)) {
                    throw new RuntimeException('Demo-trim row ' . $key . ' has no usable ' . $end . ' status');
                }
            }
            if ($from === $to) {
                throw new RuntimeException('Demo-trim row moves nowhere: ' . $key);
            }
            if (!preg_match('/^[a-z]{2,3}(-[a-z]{2,4})?$/D', $language)) {
                throw new RuntimeException('Demo-trim row has no language: ' . $key);
            }
            $ids[$id] = true;
            $this->rows[$key] = [
                'key' => $key, 'kind' => $kind, 'id' => $id, 'field' => self::FIELD,
                'from' => $from, 'to' => $to, 'language' => $language, 'reason' => (string) ($row['reason'] ?? ''),
            ];
        }
    }

    public function hash(): string
    {
        return $this->hash;
    }

    public function version(): string
    {
        return (string) ($this->profile['extensionVersion'] ?? '');
    }

    /** @return array<string,array{key:string,kind:string,id:int,field:string,from:string,to:string,language:string,reason:string}> */
    public function rows(): array
    {
        return $this->rows;
    }

    public function row(string $key): ?array
    {
        return $this->rows[$key] ?? null;
    }

    /** The row that names a post id, if any — what inspect asks when a governed post is also a demo row. */
    public function rowForPost(int $id): ?array
    {
        foreach ($this->rows as $row) {
            if ($row['id'] === $id) {
                return $row;
            }
        }
        return null;
    }

    /** @return array<string,int> rows per kind, for a plan that says what it would do */
    public function counts(): array
    {
        $out = [];
        foreach ($this->rows as $row) {
            $out[$row['kind']] = ($out[$row['kind']] ?? 0) + 1;
        }
        ksort($out);
        return $out;
    }
}
