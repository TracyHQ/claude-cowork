<?php
/**
 * DerivedMap — a content map computed from an imported site's own rows (Tracy spec
 * tasks/todo-import-site-contract.md). Same schema as a quickstart profile's content-map.json, so the
 * contract, the reader and the Apply door read it unchanged; slots are only text/image/url.
 *
 * Plain PHP, no CMS. Identical in the Joomla and WordPress engines.
 */
declare(strict_types=1);

final class DerivedMap
{
    public const ALGORITHM = 1;

    /**
     * @param list<array{kind:string,id:int|string,identity:array,core:array<string,string>,html:array<string,string>,nested:array<string,string>}> $rows
     * @param array{text:string,paths:array<string,true>}|null $seen the rendered pages, at derive time; null on a read
     * @param list<string>|null $keep nested slot keys kept at derive time: null keeps every nested leaf (derive could
     *   not fetch a page, or this IS derive), a list keeps only those keys
     * @return array{manifest:array,map:array}
     */
    public static function build(array $rows, ?array $seen, string $label, int $algorithm = self::ALGORITHM, ?array $keep = null): array
    {
        $entities = [];
        $slots = [];
        $kept = $keep === null ? null : array_fill_keys($keep, true);
        foreach ($rows as $row) {
            $entity = $row['kind'] . '-' . $row['id'];
            $mine = [];
            foreach ($row['core'] as $column => $value) {
                $type = LeafCodec::typeOf(trim((string) $value));
                if ($type !== null) $mine[] = self::slot($entity, $column, null, $type, (string) $value, false);
            }
            foreach ($row['html'] as $column => $value) {
                foreach (LeafCodec::leaves((string) $value) as $leaf) $mine[] = self::slot($entity, $column, $leaf['path'], $leaf['type'], $leaf['text'], false);
            }
            foreach ($row['nested'] as $column => $value) {
                foreach (LeafCodec::leaves((string) $value) as $leaf) {
                    if ($seen !== null && !VisibleText::shows($seen, $leaf)) continue;
                    $slot = self::slot($entity, $column, $leaf['path'], $leaf['type'], $leaf['text']);
                    if ($seen === null && $kept !== null && !isset($kept[$slot['key']])) continue;
                    $mine[] = $slot;
                }
            }
            if ($mine === []) continue;
            $entities[] = ['key' => $entity, 'kind' => $row['kind'], 'identity' => $row['identity'], 'sourceId' => $row['id']];
            array_push($slots, ...$mine);
        }
        return [
            'manifest' => ['id' => 'derived/' . $label, 'mode' => 'derived', 'algorithm' => $algorithm,
                'calibrated' => $seen !== null, 'quickstart' => ['release' => 'derived', 'version' => (string) $algorithm]],
            'map' => ['schemaVersion' => 'tracy-derived-content-map/v1', 'entities' => $entities, 'slots' => $slots],
        ];
    }

    /**
     * build() over rows that arrive in batches (a generator of lists), so a large site is never held
     * whole: each batch is built and let go before the next one is read. The same map as build()
     * over all the rows at once, since a row's slots depend on that row alone.
     *
     * @param iterable<list<array>> $batches
     * @param callable(list<array>):list<array>|null $each what a batch becomes before it is built (a filter, a tap)
     * @param array<string,array>|null $mapped when an array, gains the rows that carry a slot, by entity key
     * @return array{manifest:array,map:array}
     */
    public static function buildBatches(iterable $batches, ?array $seen, string $label, int $algorithm = self::ALGORITHM, ?array $keep = null,
        ?callable $each = null, ?array &$mapped = null): array
    {
        $built = self::build([], $seen, $label, $algorithm, $keep);
        foreach ($batches as $batch) {
            if ($each !== null) $batch = $each($batch);
            $part = self::build($batch, $seen, $label, $algorithm, $keep)['map'];
            if ($mapped !== null && $part['entities'] !== []) {
                $keys = array_flip(array_column($part['entities'], 'key'));
                foreach ($batch as $row) if (isset($keys[$row['kind'] . '-' . $row['id']])) $mapped[$row['kind'] . '-' . $row['id']] = $row;
            }
            foreach ($part['entities'] as $entity) $built['map']['entities'][] = $entity;
            foreach ($part['slots'] as $slot) $built['map']['slots'][] = $slot;
        }
        return $built;
    }

    /**
     * The nested slot keys of a derive-time map, stored in the binding as `keep` so later reads (which do not
     * fetch pages) keep the same nested leaves. Null when the map was not calibrated: keep them all.
     *
     * @return list<string>|null
     */
    public static function keepOf(array $built): ?array
    {
        if (!$built['manifest']['calibrated']) return null;
        return array_values(array_column(array_filter($built['map']['slots'], static fn($s) => $s['nested']), 'key'));
    }

    /** What contractHash digests for a derived contract: rows change on every apply, the algorithm does not. */
    public static function hashBasis(int $algorithm = self::ALGORITHM): array
    {
        return ['mode' => 'derived', 'algorithm' => $algorithm];
    }

    private static function slot(string $entity, string $column, ?string $leaf, string $type, string $text, bool $nested = true): array
    {
        $length = mb_strlen($text);
        return ['key' => $entity . '.' . $column . '.' . substr(sha1((string) $leaf), 0, 10), 'entity' => $entity, 'nested' => $nested,
            'column' => $column, 'leaf' => $leaf, 'type' => $type, 'sample' => mb_substr($text, 0, 160),
            'maxCharacters' => max(40, (int) ceil($length * 1.25)), 'requiresEvidence' => false];
    }
}
