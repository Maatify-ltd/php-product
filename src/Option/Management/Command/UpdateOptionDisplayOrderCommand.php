<?php

declare(strict_types=1);

namespace Maatify\Product\Option\Management\Command;

use JsonSerializable;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;

/**
 * Validated intent to move one Option to a position within its Product. A position above the
 * current maximum is clamped to it by the shared Ordering API.
 */
final readonly class UpdateOptionDisplayOrderCommand implements JsonSerializable
{
    /**
     * @throws ProductInvalidArgumentException When the identity or the position is not a positive integer.
     */
    public function __construct(
        public int $optionId,
        public int $displayOrder,
    ) {
        if ($optionId < 1) {
            throw ProductInvalidArgumentException::nonPositiveId('optionId');
        }
        if ($displayOrder < 1) {
            throw ProductInvalidArgumentException::invalidDisplayOrder($displayOrder);
        }
    }

    /** @return array{optionId: int, displayOrder: int} */
    public function jsonSerialize(): array
    {
        return ['optionId' => $this->optionId, 'displayOrder' => $this->displayOrder];
    }
}
