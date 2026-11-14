<?php

declare(strict_types=1);

namespace Maatify\Product\Variant\Management\Command;

use JsonSerializable;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;

/**
 * Validated intent to move one Variant to a position within its Product. A position above the
 * current maximum is clamped to it by the shared Ordering API.
 */
final readonly class UpdateVariantDisplayOrderCommand implements JsonSerializable
{
    /**
     * @throws ProductInvalidArgumentException When the identity or the position is not a positive integer.
     */
    public function __construct(
        public int $variantId,
        public int $displayOrder,
    ) {
        if ($variantId < 1) {
            throw ProductInvalidArgumentException::nonPositiveId('variantId');
        }
        if ($displayOrder < 1) {
            throw ProductInvalidArgumentException::invalidDisplayOrder($displayOrder);
        }
    }

    /** @return array{variantId: int, displayOrder: int} */
    public function jsonSerialize(): array
    {
        return ['variantId' => $this->variantId, 'displayOrder' => $this->displayOrder];
    }
}
