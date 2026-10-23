<?php
/**
 * Which menu entries of a published quickstart are the TEMPLATE'S SHOWCASE — Typography, the "J! Pages"
 * drawer of sample screens, the Home colour variants — and may be taken out of a bound site's menus.
 *
 * 🔒 A SIXTH FILE, NOT A NEW DEMO TRIM. `demo-trim-map.json` unpublishes demo content; this map only
 * takes entries out of navigation. Every page keeps its URL and its published state, and no article,
 * category or ACL is touched. It has its own schema and its own hash, pinned to the base contract's
 * bytes (`baseHash`, the formula of the other profiles), so neither profile changes the other and the
 * three base files keep the hash every bound site is held to. Generated and reviewed in Tracy
 * (`scripts/build-joomla-showcase-nav.mjs`), never sent by a request.
 *
 * 🔒 TWO OPERATIONS, ONE DIRECTION. `menuItem.param` sets a menu item's `menu_show` param ("Display in
 * Menu") from "1" — or from absent, `from: null` — to "0"; `module.published` takes a module from "1"
 * to "0" on its visibility column. Anything else in the file refuses the whole map.
 */
final class ShowcaseNavProfile
{
    public const SCHEMA = 'tracy-quickstart-showcase-nav/joomla/v1';
    /** The one param of a menu item this map may move. */
    public const MENU_PARAM = 'menu_show';
    /** The fields an operation may carry; `label` is the reviewer's, `path`/`field` name what moves. */
    private const FIELDS = ['op', 'key', 'identity', 'label', 'path', 'field', 'from', 'to', 'rule', 'reason'];

    private array $profile;
    private string $hash;
    /** @var array<string,array{key:string,op:string,kind:string,from:?string,to:string,reason:string}> */
    private array $operations = [];

    public function __construct(array $profile, array $map, array $lock, string $directory, string $rawProfile, string $contractId)
    {
        $this->profile = $profile;
        $this->hash = hash('sha256', $rawProfile);
        if (($profile['schemaVersion'] ?? '') !== self::SCHEMA) throw new RuntimeException('Unsupported showcase-navigation map');
        if (($profile['contract'] ?? null) !== $contractId) throw new RuntimeException('The showcase-navigation map names another contract');
        $lines = '';
        foreach (['manifest.json', 'content-map.json', 'presentation-lock.json'] as $name) {
            $file = $directory . '/' . $name;
            if (!is_file($file)) throw new RuntimeException('Missing contract file: ' . $name);
            $lines .= $name . ':' . hash_file('sha256', $file) . "\n";
        }
        if (!hash_equals((string) ($profile['baseHash'] ?? ''), hash('sha256', $lines)))
            throw new RuntimeException('The showcase-navigation map does not belong to this contract');
        $status = $profile['status'] ?? null;
        if ($status !== 'checked' && $status !== 'not-covered') throw new RuntimeException('Showcase-navigation map status must be checked or not-covered');
        if (!isset($profile['operations']) || !is_array($profile['operations']) || !array_is_list($profile['operations']))
            throw new RuntimeException('Incomplete showcase-navigation map: operations');
        if ($status === 'not-covered' && $profile['operations'] !== []) throw new RuntimeException('A not-covered showcase-navigation map carries operations');
        $entities = array_column($map['entities'], null, 'key');
        foreach ($profile['operations'] as $row) {
            if (!is_array($row) || array_diff(array_keys($row), self::FIELDS)) throw new RuntimeException('Showcase-navigation operation carries a field it may not');
            $key = (string) ($row['key'] ?? '');
            $op = (string) ($row['op'] ?? '');
            $entity = $entities[$key] ?? null;
            $locked = $lock['entities'][$key] ?? null;
            if ($entity === null || !is_array($locked)) throw new RuntimeException('Showcase-navigation operation names an entity outside the contract: ' . $key);
            if (!is_array($row['identity'] ?? null) || !$row['identity']) throw new RuntimeException('Showcase-navigation operation carries no identity: ' . $key);
            foreach ($row['identity'] as $name => $value)
                if (!array_key_exists($name, $entity['identity']) || (string) $entity['identity'][$name] !== (string) $value)
                    throw new RuntimeException('Showcase-navigation operation identity does not match the contract for ' . $key);
            if (isset($this->operations[$key])) throw new RuntimeException('Showcase-navigation operation listed twice: ' . $key);
            if (!is_string($row['reason'] ?? null) || $row['reason'] === '' || !is_string($row['rule'] ?? null) || $row['rule'] === '')
                throw new RuntimeException('Showcase-navigation operation has no rule or reason: ' . $key);
            if (($row['to'] ?? null) !== '0') throw new RuntimeException('Showcase-navigation operation must hide ' . $key);
            if ($op === 'menuItem.param') {
                if ($entity['kind'] !== 'menuItem') throw new RuntimeException('Showcase-navigation operation names the wrong kind for ' . $key);
                if (($row['path'] ?? null) !== self::MENU_PARAM || array_key_exists('field', $row))
                    throw new RuntimeException('Showcase-navigation operation may only change menu_show of ' . $key);
                $from = $row['from'] ?? null;
                if ($from !== '1' && $from !== null) throw new RuntimeException('Showcase-navigation operation must start from a shown entry: ' . $key);
                // The locked value is what the map says it starts from: absent for null, "1" (string or number) for "1".
                $params = json_decode((string) ($locked['params'] ?? ''), true);
                if (!is_array($params)) throw new RuntimeException('Showcase-navigation operation names a menu item without params: ' . $key);
                $lockedShow = array_key_exists(self::MENU_PARAM, $params) ? (string) $params[self::MENU_PARAM] : null;
                if ($lockedShow !== $from) throw new RuntimeException('Showcase-navigation operation does not start from the locked value of ' . $key);
                $this->operations[$key] = ['key' => $key, 'op' => $op, 'kind' => 'menuItem', 'from' => $from, 'to' => '0', 'reason' => $row['reason']];
            } elseif ($op === 'module.published') {
                if ($entity['kind'] !== 'module') throw new RuntimeException('Showcase-navigation operation names the wrong kind for ' . $key);
                if (($row['field'] ?? null) !== 'published' || array_key_exists('path', $row))
                    throw new RuntimeException('Showcase-navigation operation may only change published of ' . $key);
                if (($row['from'] ?? null) !== '1' || (string) ($locked['published'] ?? '') !== '1')
                    throw new RuntimeException('Showcase-navigation operation does not start from the locked value of ' . $key);
                $this->operations[$key] = ['key' => $key, 'op' => $op, 'kind' => 'module', 'from' => '1', 'to' => '0', 'reason' => $row['reason']];
            } else {
                throw new RuntimeException('Showcase-navigation operation is not one this receiver applies: ' . $op);
            }
        }
    }

    public function hash(): string { return $this->hash; }
    public function version(): string { return (string) ($this->profile['profileVersion'] ?? ''); }
    /** Whether the map judged this design at all; a not-covered map says why it did not. */
    public function covered(): bool { return $this->profile['status'] === 'checked'; }
    public function reason(): string { return (string) ($this->profile['reason'] ?? ''); }
    /** @return array<string,array{key:string,op:string,kind:string,from:?string,to:string,reason:string}> */
    public function operations(): array { return $this->operations; }
    /** @return array<string,int> operations per kind, for a status that says what an apply would do */
    public function counts(): array {
        $out = [];
        foreach ($this->operations as $row) $out[$row['kind']] = ($out[$row['kind']] ?? 0) + 1;
        ksort($out);
        return $out;
    }
}
