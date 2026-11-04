<?php
require_once __DIR__ . '/ContentSlots.php';
/**
 * What `rows.remove` takes out of a stored row, and where the slots left behind then stand.
 *
 * One removal is an op: a row of parallel lists (`index`), a row of a subform (`key`), or a node of an HTML field (`node`).
 * An op names its place as the row stood just before it, so a slot's address after several ops is a fold over them in order,
 * and the same fold moves the masked baseline: the rest of the module still compares, byte for byte.
 */
final class SlotRemoval
{
    /** A slot's address after `$ops`, or null when one of them took it. */
    public static function slot(array $slot, array $ops): ?array
    {
        foreach ($ops as $op) {
            if (($slot['column'] ?? null) !== $op['column'] || ($slot['nestedJson'] ?? null) !== ($op['nestedJson'] ?? null)) continue;
            $path = $slot['jsonPath'] ?? null;
            if ($op['op'] === 'node') {
                if ($path !== ($op['jsonPath'] ?? null) || !isset($slot['xpath'])) continue;
                $moved = self::xpath((string) $slot['xpath'], $op);
                if ($moved === null) return null;
                $slot['xpath'] = $moved;
                continue;
            }
            if (!is_array($path) || !self::under($path, $op['parent'])) continue;
            $at = count($op['parent']);
            if ($op['op'] === 'key') { if (($path[$at] ?? null) === $op['key']) return null; continue; }
            if (!in_array($path[$at] ?? null, $op['arrays'], true) || !is_int($path[$at + 1] ?? null)) continue;
            if ($path[$at + 1] === $op['index']) return null;
            if ($path[$at + 1] > $op['index']) { $path[$at + 1]--; $slot['jsonPath'] = $path; }
        }
        return $slot;
    }

    private static function under(array $path, array $parent): bool
    {
        return array_slice($path, 0, count($parent)) === $parent;
    }

    /** An HTML address after a node op, or null when the op took it. The next same-named sibling steps down into the gap. */
    private static function xpath(string $xpath, array $op): ?string
    {
        $gone = (string) $op['xpath'];
        if ($xpath === $gone || strpos($xpath, $gone . '/') === 0) return null;
        $cut = strrpos($gone, '/');
        $parent = substr($gone, 0, $cut + 1);
        if (strpos($xpath, $parent) !== 0 || !preg_match('~^(.*?)\[(\d+)\]$~', substr($gone, $cut + 1), $was)) return $xpath;
        $rest = substr($xpath, strlen($parent));
        $end = strpos($rest, '/');
        $step = $end === false ? $rest : substr($rest, 0, $end);
        if (!preg_match('~^(.*?)\[(\d+)\]$~', $step, $is) || $is[1] !== $was[1] || (int) $is[2] <= (int) $was[2]) return $xpath;
        return $parent . $is[1] . '[' . ((int) $is[2] - 1) . ']' . ($end === false ? '' : substr($rest, $end));
    }

    /** `$column`'s text with one op applied: the same on a live row and on the masked baseline. */
    public static function apply(string $text, array $op): string
    {
        if ($op['op'] === 'node' && !isset($op['jsonPath'])) return ContentSlots::removeNode($text, $op);
        $outer = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        if (isset($op['nestedJson'])) {
            // The baseline holds the config decoded ({@see QuickstartContract::column}), a live row as a string.
            $nested = $outer[$op['nestedJson']];
            $outer[$op['nestedJson']] = is_array($nested) ? self::inBody($nested, $op) : self::encode(self::inBody(json_decode($nested, true, 512, JSON_THROW_ON_ERROR), $op));
            return self::encode($outer);
        }
        return self::encode(self::inBody($outer, $op));
    }

    private static function encode(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private static function inBody(array $body, array $op): array
    {
        $node =& $body;
        foreach ($op['op'] === 'node' ? $op['jsonPath'] : $op['parent'] as $step) {
            if (!is_array($node) || !array_key_exists($step, $node)) throw new RuntimeException('The row to remove is missing');
            $node =& $node[$step];
        }
        if ($op['op'] === 'node') {
            if (!is_string($node)) throw new RuntimeException('The field to remove from is not text');
            $node = ContentSlots::removeNode($node, $op);
        } elseif ($op['op'] === 'key') {
            unset($node[$op['key']]);
        } else {
            foreach ($op['arrays'] as $name) array_splice($node[$name], $op['index'], 1);
        }
        return $body;
    }

    /** The value inside a row's column at `$op`'s place: `$body` is the decoded column or nested config. */
    public static function at(array $body, array $path)
    {
        foreach ($path as $step) {
            if (!is_array($body) || !array_key_exists($step, $body)) return null;
            $body = $body[$step];
        }
        return $body;
    }
}
