<?php
/**
 * The undo of one content write, as the span it changed rather than the row it left.
 *
 * WHY. A receipt used to hold the whole row from before the write — for a post, the whole
 * `post_content`. Reverting it wrote that row back, so reverting receipt 1 after receipt 3 had
 * changed another block of the same post silently took receipt 3's words away too (Tracy stand
 * E2E, 29/09/2026: the image block of item 3 went back with item 1's heading). An Apply's revert
 * must undo that Apply and nothing else.
 *
 * WHAT IS RECORDED. For each field the write changed, the smallest byte span that differs between
 * the value before and the value after — `old` and `new` — plus up to CONTEXT bytes on each side
 * of it as it stood after the write (`pre`, `post`) and where it started (`at`). A value that is
 * not a string (a menu position, a category list, a serialized meta) is recorded whole: `was`
 * and `now`.
 *
 * WHAT A REVERT DOES. It finds `pre . new . post` in the field as it is NOW and puts `old` back in
 * place of `new`, leaving every other byte alone — including every later change. When that text
 * is gone (edited since) or appears more than once and none of the places is where the write put
 * it, the revert is refused. It never falls back to writing the whole old value: that fallback is
 * exactly the bug this exists to remove. A whole value comes back only if the field still holds
 * exactly what the write left there.
 */
final class ContentUndo
{
    /** Bytes of context kept on each side of a span: enough to tell twin blocks apart, small enough
     * that an edit in the NEXT block does not count as touching this one. */
    public const CONTEXT = 32;

    /**
     * What one write changed, field by field, or null when it cannot be described that way (a
     * create, a row that could not be read back, a field the write named that the row does not
     * show). A null record keeps the whole-row undo, with its own safety rule in the engine.
     *
     * @param array<string,mixed>|null $before
     * @param array<string,mixed>|null $after
     * @param string[] $fields The fields the write was given.
     * @return array<string,array<string,mixed>>|null
     */
    public static function record(?array $before, ?array $after, array $fields): ?array
    {
        if ($before === null || $after === null || $fields === []) {
            return null;
        }
        $changes = [];
        foreach ($fields as $field) {
            if (!array_key_exists($field, $before) || !array_key_exists($field, $after)) {
                return null;
            }
            $was = $before[$field];
            $now = $after[$field];
            if ($was === $now) {
                continue;
            }
            $changes[$field] = is_string($was) && is_string($now)
                ? self::span($was, $now)
                : ['was' => $was, 'now' => $now];
        }
        return $changes;
    }

    /**
     * The row to write back to undo one recorded write: only the fields it changed, each with the
     * write taken out of the value as it stands now. Or the reason it cannot be done, naming the
     * field — nothing is half-restored.
     *
     * @param array<string,mixed>|null $current The row as it is now; null when it is gone.
     * @param array<string,array<string,mixed>> $changes What {@see record()} answered.
     * @return array{fields:array<string,mixed>}|array{field:?string,reason:string}
     */
    public static function restore(?array $current, array $changes): array
    {
        if ($changes === []) {
            return ['fields' => []];
        }
        if ($current === null) {
            return ['field' => null, 'reason' => 'gone'];
        }
        $fields = [];
        foreach ($changes as $field => $change) {
            if (!array_key_exists($field, $current)) {
                return ['field' => (string) $field, 'reason' => 'gone'];
            }
            $value = $current[$field];
            if (array_key_exists('now', $change) || array_key_exists('was', $change)) {
                if ($value !== ($change['now'] ?? null)) {
                    return ['field' => (string) $field, 'reason' => 'edited'];
                }
                $fields[$field] = $change['was'] ?? null;
                continue;
            }
            if (!is_string($value)) {
                return ['field' => (string) $field, 'reason' => 'edited'];
            }
            $at = self::locate($value, $change);
            if (is_string($at)) {
                return ['field' => (string) $field, 'reason' => $at];
            }
            $fields[$field] = substr_replace($value, (string) $change['old'], $at, strlen((string) $change['new']));
        }
        return ['fields' => $fields];
    }

    /**
     * The smallest span that differs, with its context as it stood after the write.
     *
     * @return array{at:int,old:string,new:string,pre:string,post:string}
     */
    private static function span(string $was, string $now): array
    {
        $lenWas = strlen($was);
        $lenNow = strlen($now);
        $shorter = min($lenWas, $lenNow);
        $head = 0;
        while ($head < $shorter && $was[$head] === $now[$head]) {
            $head++;
        }
        $tail = 0;
        while ($tail < $shorter - $head && $was[$lenWas - 1 - $tail] === $now[$lenNow - 1 - $tail]) {
            $tail++;
        }
        $from = max(0, $head - self::CONTEXT);
        return [
            'at' => $head,
            'old' => substr($was, $head, $lenWas - $head - $tail),
            'new' => substr($now, $head, $lenNow - $head - $tail),
            'pre' => substr($now, $from, $head - $from),
            'post' => substr($now, $lenNow - $tail, min(self::CONTEXT, $tail)),
        ];
    }

    /**
     * Where the write's own bytes start in the value now, or why they cannot be found: `edited`
     * when they are not there any more, `ambiguous` when they are there more than once and none
     * of those places is where the write put them.
     *
     * @param array<string,mixed> $change
     * @return int|string
     */
    private static function locate(string $value, array $change)
    {
        $pre = (string) $change['pre'];
        $new = (string) $change['new'];
        $needle = $pre . $new . (string) $change['post'];
        if ($needle === '') {
            // The write emptied the field: it can only be put back while it is still empty.
            return $value === '' ? 0 : 'edited';
        }
        $found = [];
        for ($from = 0; ($hit = strpos($value, $needle, $from)) !== false; $from = $hit + 1) {
            $found[] = $hit + strlen($pre);
        }
        if (count($found) === 1) {
            return $found[0];
        }
        if ($found === []) {
            return 'edited';
        }
        // Twins: the one still at the recorded offset is the one this write made. Anything before
        // it moving would move it too, so a twin elsewhere is a guess, and a guess is refused.
        $at = (int) $change['at'];
        return in_array($at, $found, true) ? $at : 'ambiguous';
    }
}
