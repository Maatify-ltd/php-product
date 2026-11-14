<?php

declare(strict_types=1);

namespace Maatify\Product\Variant\DTO;

use JsonSerializable;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;

/**
 * One selected Option and Option Value of a Variant composition.
 */
final readonly class VariantCompositionPairDTO implements JsonSerializable
{
    /**
     * @throws ProductInvalidArgumentException When an identity is not a positive integer.
     */
    public function __construct(
        public int $optionId,
        public int $optionValueId,
    ) {
        if ($optionId < 1) {
            throw ProductInvalidArgumentException::nonPositiveId('optionId');
        }
        if ($optionValueId < 1) {
            throw ProductInvalidArgumentException::nonPositiveId('optionValueId');
        }
    }

    /** @return array{optionId: int, optionValueId: int} */
    public function jsonSerialize(): array
    {
        return ['optionId' => $this->optionId, 'optionValueId' => $this->optionValueId];
    }
}
