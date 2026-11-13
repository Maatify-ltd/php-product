<?php

declare(strict_types=1);

namespace Maatify\Product\Product\DTO;

use JsonSerializable;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;

/**
 * The deterministic identities a Product reconfiguration produced or changed, so the Host can
 * reconcile Pricing, Inventory, and other external mappings of the old and new Variants.
 *
 * Every list is ascending by identity except `createdVariantIds`, which follows the order of the
 * requested Variants that were created. A reused Variant that was already active appears in no list.
 */
final readonly class ProductReconfigurationResultDTO implements JsonSerializable
{
    /**
     * @param list<int> $createdVariantIds New Variant identities, in request order.
     * @param list<int> $reactivatedVariantIds Existing inactive Variants that were reused and are active again.
     * @param list<int> $restoredVariantIds Existing soft-deleted Variants that were restored and activated.
     * @param list<int> $deactivatedVariantIds Previously active Variants absent from the target set.
     * @param list<int> $activatedOptionIds
     * @param list<int> $deactivatedOptionIds
     * @param list<int> $activatedOptionValueIds
     * @param list<int> $deactivatedOptionValueIds
     */
    public function __construct(
        public array $createdVariantIds,
        public array $reactivatedVariantIds,
        public array $restoredVariantIds,
        public array $deactivatedVariantIds,
        public array $activatedOptionIds,
        public array $deactivatedOptionIds,
        public array $activatedOptionValueIds,
        public array $deactivatedOptionValueIds,
    ) {
        foreach ([
            'createdVariantIds' => $createdVariantIds,
            'reactivatedVariantIds' => $reactivatedVariantIds,
            'restoredVariantIds' => $restoredVariantIds,
            'deactivatedVariantIds' => $deactivatedVariantIds,
            'activatedOptionIds' => $activatedOptionIds,
            'deactivatedOptionIds' => $deactivatedOptionIds,
            'activatedOptionValueIds' => $activatedOptionValueIds,
            'deactivatedOptionValueIds' => $deactivatedOptionValueIds,
        ] as $field => $ids) {
            foreach ($ids as $id) {
                self::assertPositiveIdentity($id, $field);
            }
        }
    }

    /**
     * @return array{
     *     createdVariantIds: list<int>,
     *     reactivatedVariantIds: list<int>,
     *     restoredVariantIds: list<int>,
     *     deactivatedVariantIds: list<int>,
     *     activatedOptionIds: list<int>,
     *     deactivatedOptionIds: list<int>,
     *     activatedOptionValueIds: list<int>,
     *     deactivatedOptionValueIds: list<int>
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'createdVariantIds' => $this->createdVariantIds,
            'reactivatedVariantIds' => $this->reactivatedVariantIds,
            'restoredVariantIds' => $this->restoredVariantIds,
            'deactivatedVariantIds' => $this->deactivatedVariantIds,
            'activatedOptionIds' => $this->activatedOptionIds,
            'deactivatedOptionIds' => $this->deactivatedOptionIds,
            'activatedOptionValueIds' => $this->activatedOptionValueIds,
            'deactivatedOptionValueIds' => $this->deactivatedOptionValueIds,
        ];
    }

    private static function assertPositiveIdentity(mixed $id, string $field): void
    {
        if (!is_int($id) || $id < 1) {
            throw ProductInvalidArgumentException::nonPositiveId($field);
        }
    }
}
