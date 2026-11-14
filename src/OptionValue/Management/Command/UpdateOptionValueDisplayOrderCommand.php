<?php

declare(strict_types=1);

namespace Maatify\Product\OptionValue\Management\Command;

use JsonSerializable;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;

/**
 * Validated intent to move one Option Value to a position within its Option. A position above the
 * current maximum is clamped to it by the shared Ordering API.
 */
final readonly class UpdateOptionValueDisplayOrderCommand implements JsonSerializable
{
    /**
     * @throws ProductInvalidArgumentException When the identity or the position is not a positive integer.
     */
    public function __construct(
        public int $optionValueId,
        public int $displayOrder,
    ) {
        if ($optionValueId < 1) {
            throw ProductInvalidArgumentException::nonPositiveId('optionValueId');
        }
        if ($displayOrder < 1) {
            throw ProductInvalidArgumentException::invalidDisplayOrder($displayOrder);
        }
    }

    /** @return array{optionValueId: int, displayOrder: int} */
    public function jsonSerialize(): array
    {
        return ['optionValueId' => $this->optionValueId, 'displayOrder' => $this->displayOrder];
    }
}
