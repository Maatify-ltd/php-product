<?php

declare(strict_types=1);

namespace Maatify\Product\Variant\Management\Command;

use JsonSerializable;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;
use Maatify\Product\Variant\ValueObject\VariantIdentifierChange;

/**
 * Validated intent to change the secondary identifiers of one Variant: each of the SKU and the
 * barcode is independently left unchanged, set, or cleared. The Variant identity never changes.
 */
final readonly class UpdateVariantIdentifiersCommand implements JsonSerializable
{
    /**
     * @throws ProductInvalidArgumentException When the Variant identity is not positive.
     */
    public function __construct(
        public int $variantId,
        public VariantIdentifierChange $sku,
        public VariantIdentifierChange $barcode,
    ) {
        if ($variantId < 1) {
            throw ProductInvalidArgumentException::nonPositiveId('variantId');
        }
    }

    /** @return array{variantId: int, sku: array{change: string, value: ?string}, barcode: array{change: string, value: ?string}} */
    public function jsonSerialize(): array
    {
        return ['variantId' => $this->variantId, 'sku' => $this->sku->jsonSerialize(), 'barcode' => $this->barcode->jsonSerialize()];
    }
}
