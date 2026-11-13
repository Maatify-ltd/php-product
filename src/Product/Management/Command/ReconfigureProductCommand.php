<?php

declare(strict_types=1);

namespace Maatify\Product\Product\Management\Command;

use JsonSerializable;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;

/**
 * Validated intent to replace the active configuration of a Product in one atomic operation.
 *
 * The command is a complete target state, not a list of changes: `options` is the whole set
 * of Options that must be active afterwards, each with the whole set of its active Values, and
 * `variants` is the whole set of Variant compositions that must be active afterwards. An empty
 * `options` list with one empty-composition Variant describes a simple Product. Options and
 * Values must already exist and be labelled; the operation activates and deactivates, it never
 * creates them. The command carries no SKU or barcode: new Variants start without either.
 */
final readonly class ReconfigureProductCommand implements JsonSerializable
{
    /** @var list<ReconfigureOptionTarget> */
    public array $options;

    /** @var list<ReconfigureVariantTarget> */
    public array $variants;

    /**
     * @param list<ReconfigureOptionTarget> $options The target active Options; no Option repeated, no Value listed under two Options.
     * @param list<ReconfigureVariantTarget> $variants The target active Variants; no composition repeated, no reuse identity repeated.
     *
     * @throws ProductInvalidArgumentException When the Product identity is not positive or an element repeats.
     */
    public function __construct(
        public int $productId,
        public int $expectedRevision,
        array $options,
        array $variants,
    ) {
        if ($productId < 1) {
            throw ProductInvalidArgumentException::nonPositiveId('productId');
        }

        if ($expectedRevision < 1) {
            throw ProductInvalidArgumentException::nonPositiveId('expectedRevision');
        }

        $optionIds = [];
        $valueIds = [];
        foreach ($options as $option) {
            if (isset($optionIds[$option->optionId])) {
                throw ProductInvalidArgumentException::duplicateIdentity('options', $option->optionId);
            }
            $optionIds[$option->optionId] = true;
            foreach ($option->activeOptionValueIds as $valueId) {
                if (isset($valueIds[$valueId])) {
                    throw ProductInvalidArgumentException::duplicateIdentity('options.activeOptionValueIds', $valueId);
                }
                $valueIds[$valueId] = true;
            }
        }

        $fingerprints = [];
        $reused = [];
        foreach ($variants as $variant) {
            $fingerprint = $variant->fingerprint();
            if (isset($fingerprints[$fingerprint->value])) {
                throw ProductInvalidArgumentException::duplicateComposition($fingerprint->canonical);
            }
            $fingerprints[$fingerprint->value] = true;
            if ($variant->reuseVariantId !== null) {
                if (isset($reused[$variant->reuseVariantId])) {
                    throw ProductInvalidArgumentException::duplicateIdentity('variants.reuseVariantId', $variant->reuseVariantId);
                }
                $reused[$variant->reuseVariantId] = true;
            }
        }

        $this->options = $options;
        $this->variants = $variants;
    }

    /** @return array{productId: int, expectedRevision: int, options: list<array<string, mixed>>, variants: list<array<string, mixed>>} */
    public function jsonSerialize(): array
    {
        return [
            'productId' => $this->productId,
            'expectedRevision' => $this->expectedRevision,
            'options' => array_map(static fn(ReconfigureOptionTarget $option): array => $option->jsonSerialize(), $this->options),
            'variants' => array_map(static fn(ReconfigureVariantTarget $variant): array => $variant->jsonSerialize(), $this->variants),
        ];
    }
}
