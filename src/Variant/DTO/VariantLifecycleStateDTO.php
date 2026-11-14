<?php

declare(strict_types=1);

namespace Maatify\Product\Variant\DTO;

use JsonSerializable;

/**
 * Lifecycle state of a stored Variant as read under its row lock, for operations that target the
 * Variant as an owner (Image Assignments) or change its secondary identifiers, and need no composition.
 *
 * @internal Not part of the Public Runtime API.
 */
final readonly class VariantLifecycleStateDTO implements JsonSerializable
{
    public function __construct(
        public int $id,
        public int $productId,
        public bool $active,
        public bool $deleted,
        public ?string $sku = null,
        public ?string $barcode = null,
    ) {}

    /** @return array{id: int, productId: int, active: bool, deleted: bool, sku: ?string, barcode: ?string} */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'productId' => $this->productId,
            'active' => $this->active,
            'deleted' => $this->deleted,
            'sku' => $this->sku,
            'barcode' => $this->barcode,
        ];
    }
}
