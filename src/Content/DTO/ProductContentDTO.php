<?php

declare(strict_types=1);

namespace Maatify\Product\Content\DTO;

use DateTimeImmutable;
use JsonSerializable;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;

/**
 * Snapshot of a stored Product Content record. `languageCode` is `null` for the unlocalized record.
 * Timestamps are hydrated in the Host Clock timezone without normalization.
 */
final readonly class ProductContentDTO implements JsonSerializable
{
    /**
     * @throws ProductInvalidArgumentException When an identity is not positive.
     */
    public function __construct(
        public int $id,
        public int $productId,
        public ?string $languageCode,
        public string $name,
        public ?string $shortDescription,
        public ?string $description,
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
    }

    /**
     * @return array{id: int, productId: int, languageCode: ?string, name: string, shortDescription: ?string, description: ?string, createdAt: string, updatedAt: string, deletedAt: ?string}
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'productId' => $this->productId,
            'languageCode' => $this->languageCode,
            'name' => $this->name,
            'shortDescription' => $this->shortDescription,
            'description' => $this->description,
            'createdAt' => $this->createdAt->format(DATE_ATOM),
            'updatedAt' => $this->updatedAt->format(DATE_ATOM),
            'deletedAt' => $this->deletedAt?->format(DATE_ATOM),
        ];
    }
}
