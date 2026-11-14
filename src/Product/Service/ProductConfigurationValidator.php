<?php

declare(strict_types=1);

namespace Maatify\Product\Product\Service;

use Maatify\Product\Product\DTO\ProductConfigurationSnapshotDTO;

/**
 * Evaluates the structural invariants of a Product configuration (`INV-01` to `INV-06`) on an
 * in-memory graph, so every mutation can reject itself before writing.
 *
 * Fingerprint uniqueness (`INV-08`) and same-Product ownership (`INV-03`) are enforced by the
 * database keys and are not re-evaluated here.
 *
 * @internal Not part of the Public Runtime API.
 */
final class ProductConfigurationValidator
{
    private function __construct() {}

    /**
     * Returns the first violated invariant as a human-readable reason, or null when the graph is valid.
     */
    public static function firstViolation(ProductConfigurationSnapshotDTO $snapshot): ?string
    {
        $activeOptions = [];
        foreach ($snapshot->options as $option) {
            if (!$option->deleted && $option->active) {
                $activeOptions[$option->id] = $option;
            }
        }

        /** @var array<int, array<int, true>> $activeValuesByOption */
        $activeValuesByOption = [];
        foreach ($snapshot->optionValues as $value) {
            if (!$value->deleted && $value->active && isset($activeOptions[$value->optionId])) {
                $activeValuesByOption[$value->optionId][$value->id] = true;
            }
        }

        foreach ($activeOptions as $option) {
            if (!$option->labelled) {
                return sprintf('active Option [%d] has no label in any non-deleted Content record', $option->id);
            }
            if (!isset($activeValuesByOption[$option->id])) {
                return sprintf('active Option [%d] has no active, non-deleted Option Value', $option->id);
            }
        }
        foreach ($snapshot->optionValues as $value) {
            if (isset($activeValuesByOption[$value->optionId][$value->id]) && !$value->labelled) {
                return sprintf('active Option Value [%d] has no label in any non-deleted Content record', $value->id);
            }
        }

        $activeVariantCount = 0;
        /** @var array<int, true> $representedValues */
        $representedValues = [];
        foreach ($snapshot->variants as $variant) {
            if ($variant->deleted || !$variant->active) {
                continue;
            }
            ++$activeVariantCount;
            $covered = [];
            foreach ($variant->composition as $pair) {
                if (!isset($activeOptions[$pair->optionId])) {
                    return sprintf('active Variant [%d] selects Option [%d], which is not an active Option', $variant->id, $pair->optionId);
                }
                if (!isset($activeValuesByOption[$pair->optionId][$pair->optionValueId])) {
                    return sprintf(
                        'active Variant [%d] selects Option Value [%d], which is not an active Value of Option [%d]',
                        $variant->id,
                        $pair->optionValueId,
                        $pair->optionId,
                    );
                }
                $covered[$pair->optionId] = true;
                $representedValues[$pair->optionValueId] = true;
            }
            foreach ($activeOptions as $option) {
                if (!isset($covered[$option->id])) {
                    return sprintf('active Variant [%d] has no Value for active Option [%d]', $variant->id, $option->id);
                }
            }
        }

        if ($activeOptions === [] && $activeVariantCount !== 1) {
            return sprintf('a simple Product needs exactly one active empty-composition Variant, found %d active Variants', $activeVariantCount);
        }
        if ($activeOptions !== [] && $activeVariantCount === 0) {
            return 'a configurable Product needs at least one active Variant';
        }

        foreach ($activeValuesByOption as $optionId => $values) {
            foreach (array_keys($values) as $valueId) {
                if (!isset($representedValues[$valueId])) {
                    return sprintf('active Option Value [%d] of Option [%d] is not represented by any active Variant', $valueId, $optionId);
                }
            }
        }

        if ($snapshot->productActive && !$snapshot->hasName) {
            return 'an active Product needs a non-empty name in at least one non-deleted Product Content record';
        }

        return null;
    }
}
