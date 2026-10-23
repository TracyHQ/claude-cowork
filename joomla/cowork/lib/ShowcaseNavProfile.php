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
 *
 * 🔒 V2 ADDS ONE: `templateStyle.megaSubRemove`. A T3 style keeps a mega-menu `sub` for an item whose
 * every child this map hides, and T3 opens a dropdown from it whatever the children: it would open
 * empty. The operation drops `mm_config.<menutype>.item-<id>.sub` from the style's params (T3MegaSub),
 * named by the sha256 of the whole column before and after. A style's operations chain — the next one
 * starts from the bytes the last one left — and every link is replayed here from the lock, so a map
 * whose edit this receiver does not reach byte for byte is refused before anything reads it.
 */
require_once __DIR__ . '/T3MegaSub.php';

final class ShowcaseNavProfile
{
    public const SCHEMA = 'tracy-quickstart-showcase-nav/joomla/v1';
    public const SCHEMA_V2 = 'tracy-quickstart-showcase-nav/joomla/v2';
    public const MEGA_SUB = 'templateStyle.megaSubRemove';
    /** The one param of a menu item this map may move. */
    public const MENU_PARAM = 'menu_show';
    /** The fields an operation may carry; `label` is the reviewer's, `path`/`field` name what moves. */
    private const FIELDS = ['op', 'key', 'identity', 'label', 'path', 'field', 'from', 'to', 'rule', 'reason'];
    /** The fields of a megaSubRemove operation. */
    private const MEGA_FIELDS = ['op', 'key', 'identity', 'label', 'style', 'menutype', 'item', 'path', 'before', 'after', 'rule', 'reason'];

    private array $profile;
    /** The column each style's chain has reached so far, while the map is read. */
    private array $replayed = [];
    private string $hash;
    /**
     * Hides first, then one entry per template style, its steps in map order.
     * @var array<string,array{key:string,op:string,kind:string,from:?string,to:string,reason:string,steps?:list<array{menutype:string,item:int,itemKey:string,children:list<string>,before:string,after:string}>}>
     */
    private array $operations = [];

    public function __construct(array $profile, array $map, array $lock, string $directory, string $rawProfile, string $contractId)
    {
        $this->profile = $profile;
        $this->hash = hash('sha256', $rawProfile);
        $schema = $profile['schemaVersion'] ?? '';
        if ($schema !== self::SCHEMA && $schema !== self::SCHEMA_V2) throw new RuntimeException('Unsupported showcase-navigation map');
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
        $styles = [];
        foreach ($profile['operations'] as $row) {
            if (is_array($row) && ($row['op'] ?? null) === self::MEGA_SUB && $schema === self::SCHEMA_V2) { $styles[] = $row; continue; }
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
                // An empty column is Joomla's {}: no params, so no menu_show either.
                $text = (string) ($locked['params'] ?? '');
                $params = trim($text) === '' ? [] : json_decode($text, true);
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
        foreach ($styles as $row) $this->megaSub($row, $entities, $lock);
    }

    /** One `templateStyle.megaSubRemove`, checked against the contract and replayed from the lock. */
    private function megaSub(array $row, array $entities, array $lock): void
    {
        if (array_diff(array_keys($row), self::MEGA_FIELDS)) throw new RuntimeException('Showcase-navigation operation carries a field it may not');
        $key = (string) ($row['key'] ?? '');
        $entity = $entities[$key] ?? null;
        $locked = $lock['entities'][$key] ?? null;
        if ($entity === null || !is_array($locked)) throw new RuntimeException('Showcase-navigation operation names an entity outside the contract: ' . $key);
        if ($entity['kind'] !== 'templateStyle') throw new RuntimeException('Showcase-navigation operation names the wrong kind for ' . $key);
        if (!is_array($row['identity'] ?? null) || !$row['identity']) throw new RuntimeException('Showcase-navigation operation carries no identity: ' . $key);
        foreach ($row['identity'] as $name => $value)
            if (!array_key_exists($name, $entity['identity']) || (string) $entity['identity'][$name] !== (string) $value)
                throw new RuntimeException('Showcase-navigation operation identity does not match the contract for ' . $key);
        if (!is_string($row['reason'] ?? null) || $row['reason'] === '' || !is_string($row['rule'] ?? null) || $row['rule'] === '')
            throw new RuntimeException('Showcase-navigation operation has no rule or reason: ' . $key);
        $style = $row['style'] ?? null;
        if (!is_array($style) || array_keys($style) !== ['id', 'template'] || $style['id'] !== (int) $entity['sourceId'] || $style['template'] !== (string) ($locked['template'] ?? ''))
            throw new RuntimeException('Showcase-navigation operation names another template style than ' . $key);
        $menutype = $row['menutype'] ?? null;
        $item = $row['item'] ?? null;
        if (!is_string($menutype) || $menutype === '' || !is_int($item) || $item <= 0) throw new RuntimeException('Showcase-navigation operation names no menu item for ' . $key);
        if (($row['path'] ?? null) !== 'mm_config.' . $menutype . '.item-' . $item . '.sub')
            throw new RuntimeException('Showcase-navigation operation may only remove the mega-menu sub of its item: ' . $key);
        // The item is a menu item of the contract, in that menu, and every child this map hides is what empties it.
        $itemKey = null;
        foreach ($entities as $k => $e) if ($e['kind'] === 'menuItem' && (int) $e['sourceId'] === $item) $itemKey = $k;
        if ($itemKey === null || (string) ($lock['entities'][$itemKey]['menutype'] ?? '') !== $menutype)
            throw new RuntimeException('Showcase-navigation operation names a menu item outside the contract: ' . $key);
        if (isset($this->operations[$itemKey]) && $this->operations[$itemKey]['kind'] === 'menuItem')
            throw new RuntimeException('Showcase-navigation operation removes the sub of an item the map hides: ' . $key);
        $children = [];
        foreach ($this->operations as $k => $op)
            if ($op['kind'] === 'menuItem' && (string) ($lock['entities'][$k]['parent_id'] ?? '') === (string) $item) $children[] = $k;
        if (!$children) throw new RuntimeException('Showcase-navigation operation removes the sub of an item the map empties of nothing: ' . $key);
        // 🔒 THE CHAIN, REPLAYED. The first link starts from the locked column; each next one from the last one's bytes.
        $previous = $this->operations[$key] ?? null;
        $raw = $previous === null ? $locked['params'] ?? null : $this->replayed[$key];
        if (!is_string($raw)) throw new RuntimeException('The template style holds no params column: ' . $key);
        foreach ($previous['steps'] ?? [] as $step)
            if ($step['menutype'] === $menutype && $step['item'] === $item) throw new RuntimeException('Showcase-navigation operation listed twice: ' . $key . ' ' . $row['path']);
        foreach (['before', 'after'] as $name)
            if (!is_string($row[$name] ?? null) || !preg_match('/^[0-9a-f]{64}$/D', $row[$name])) throw new RuntimeException('Showcase-navigation operation names no ' . $name . ' hash: ' . $key);
        if (!hash_equals(hash('sha256', $raw), $row['before'])) throw new RuntimeException('Showcase-navigation operation does not start from the locked value of ' . $key);
        $edit = T3MegaSub::remove($raw, $menutype, $item);
        if (!isset($edit['raw']) || !hash_equals(hash('sha256', $edit['raw']), $row['after']))
            throw new RuntimeException('Showcase-navigation operation does not reach its after bytes from the lock: ' . $key . ' ' . $row['path']);
        $this->replayed[$key] = $edit['raw'];
        $step = ['menutype' => $menutype, 'item' => $item, 'itemKey' => $itemKey, 'children' => $children, 'before' => $row['before'], 'after' => $row['after']];
        $this->operations[$key] = $previous === null
            ? ['key' => $key, 'op' => self::MEGA_SUB, 'kind' => 'templateStyle', 'from' => $row['before'], 'to' => $row['after'], 'reason' => $row['reason'], 'steps' => [$step]]
            : ['to' => $row['after'], 'steps' => array_merge($previous['steps'], [$step])] + $previous;
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
