<?php

namespace App\Services\Common;

use InvalidArgumentException;

class SortOrderValidator
{
    /**
     * Inspect completeness and validity of a submitted reorder array against authoritative expected IDs.
     *
     * @param array<string|int> $submittedOrder
     * @param array<string|int> $authoritativeIds
     * @return array{is_valid: bool, missing: array<string>, unexpected: array<string>, duplicates: array<string>}
     */
    public static function inspect(array $submittedOrder, array $authoritativeIds): array
    {
        $submitted = array_map('strval', array_values($submittedOrder));
        $authoritative = array_map('strval', array_values($authoritativeIds));

        $authSet = array_flip($authoritative);
        $seen = [];
        $duplicates = [];
        $unexpected = [];

        foreach ($submitted as $item) {
            if (isset($seen[$item])) {
                if (! in_array($item, $duplicates, true)) {
                    $duplicates[] = $item;
                }
            } else {
                $seen[$item] = true;
            }

            if (! isset($authSet[$item])) {
                $unexpected[] = $item;
            }
        }

        $missing = [];
        foreach ($authoritative as $expected) {
            if (! isset($seen[$expected])) {
                $missing[] = $expected;
            }
        }

        $isValid = empty($missing) && empty($unexpected) && empty($duplicates) && count($submitted) === count($authoritative);

        return [
            'is_valid' => $isValid,
            'missing' => $missing,
            'unexpected' => $unexpected,
            'duplicates' => $duplicates,
        ];
    }

    /**
     * Determine if submitted order array is an exact, complete permutation of authoritative expected IDs.
     * Rejects missing IDs, duplicate IDs, and unexpected IDs.
     *
     * @param array<string|int> $submittedOrder
     * @param array<string|int> $authoritativeIds
     * @return bool
     */
    public static function isValid(array $submittedOrder, array $authoritativeIds): bool
    {
        $result = static::inspect($submittedOrder, $authoritativeIds);
        return $result['is_valid'];
    }

    /**
     * Reorder an array of items according to submitted order, strictly validating completeness.
     * Throws InvalidArgumentException if submitted order is incomplete, contains duplicates, or unexpected IDs.
     *
     * @param array<mixed> $authoritativeItems Array of items (arrays or objects)
     * @param array<string|int> $submittedOrder Reordered list of IDs
     * @param string|callable $idResolver Field name or callback to resolve ID from each item
     * @return array<mixed> Strictly reordered items
     * @throws InvalidArgumentException
     */
    public static function applyStrictOrder(
        array $authoritativeItems,
        array $submittedOrder,
        string|callable $idResolver = 'id'
    ): array {
        $itemsById = [];
        $authoritativeIds = [];

        foreach ($authoritativeItems as $idx => $item) {
            $id = is_callable($idResolver)
                ? (string) $idResolver($item, $idx)
                : (string) (is_array($item) ? ($item[$idResolver] ?? $idx) : ($item->{$idResolver} ?? $idx));

            $itemsById[$id] = $item;
            $authoritativeIds[] = $id;
        }

        $inspection = static::inspect($submittedOrder, $authoritativeIds);
        if (! $inspection['is_valid']) {
            $reasons = [];
            if (! empty($inspection['missing'])) {
                $reasons[] = 'missing IDs: ' . implode(', ', $inspection['missing']);
            }
            if (! empty($inspection['duplicates'])) {
                $reasons[] = 'duplicate IDs: ' . implode(', ', $inspection['duplicates']);
            }
            if (! empty($inspection['unexpected'])) {
                $reasons[] = 'unexpected IDs: ' . implode(', ', $inspection['unexpected']);
            }
            throw new InvalidArgumentException('Invalid reorder payload (' . implode('; ', $reasons) . ').');
        }

        $reordered = [];
        foreach ($submittedOrder as $id) {
            $reordered[] = $itemsById[(string) $id];
        }

        return $reordered;
    }
}
