<?php

declare(strict_types=1);

namespace Maatify\Product\Variant\DTO;

use DateTimeImmutable;
use JsonSerializable;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;
use Maatify\Product\Variant\Enum\VariantStatusEnum;

/**
 * Snapshot of a stored Variant.
 *
 * `composition` lists the Variant's Option and Option Value pairs ordered by numeric Option
 * identity; it is empty for the empty-composition Variant of a simple Product. Timestamps
 * are hydrated in the Host Clock timezone without normalization. The composition fingerprint
 * is an internal enforcement key and is not exposed.
 */
final readonly class VariantDTO implements JsonSerializable
{
    /**
     * @param list<VariantCompositionPairDTO> $composition
     *
     * @throws ProductInvalidArgumentException When an identity or the display order is not positive.
     */
    public function __construct(
        public int $id,
        public int $productId,
        public ?string $sku,
        public ?string $barcode,
        public VariantStatusEnum $status,
        public int $displayOrder,
        public array $composition,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?DateTimeImmutable $deletedAt,
    ) {
        if ($id < 1) {
            throw ProductInvalidArgumentException::nonPositiveId('id');
        }
        if ($productId < 1) {
            throw ProductInvalidArgumentException::nonPositiveId('productId');
        }
        if ($displayOrder < 1) {
            throw ProductInvalidArgumentException::invalidDisplayOrder($displayOrder);
        }
    }

    /**
     * @return array{
     *     id: int,
     *     productId: int,
     *     sku: ?string,
     *     barcode: ?string,
     *     status: string,
     *     displayOrder: int,
     *     composition: list<array{optionId: int, optionValueId: int}>,
     *     createdAt: string,
     *     updatedAt: string,
     *     deletedAt: ?string
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'productId' => $this->productId,
            'sku' => $this->sku,
            'barcode' => $this->barcode,
            'status' => $this->status->value,
            'displayOrder' => $this->displayOrder,
            'composition' => array_map(
                static fn(VariantCompositionPairDTO $pair): array => $pair->jsonSerialize(),
                $this->composition,
            ),
            'createdAt' => $this->createdAt->format(DATE_ATOM),
            'updatedAt' => $this->updatedAt->format(DATE_ATOM),
            'deletedAt' => $this->deletedAt?->format(DATE_ATOM),
        ];
    }
}
