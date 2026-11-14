<?php

declare(strict_types=1);

namespace Maatify\Product\Product\DTO;

use DateTimeImmutable;
use JsonSerializable;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;
use Maatify\Product\Product\Enum\ProductStatusEnum;

/**
 * Snapshot of a stored Product.
 *
 * Timestamps are the stored values exactly as the Host Clock produced them at write
 * time, hydrated in the Host Clock timezone; the package applies no timezone
 * normalization and makes no UTC claim. `deletedAt` is null while the Product is not
 * soft deleted.
 */
final readonly class ProductDTO implements JsonSerializable
{
    /**
     * @throws ProductInvalidArgumentException When the identity is not a positive integer.
     */
    public function __construct(
        public int $id,
        public string $code,
        public ProductStatusEnum $status,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?DateTimeImmutable $deletedAt,
    ) {
        if ($id < 1) {
            throw ProductInvalidArgumentException::nonPositiveId('id');
        }
    }

    /** @return array{id: int, code: string, status: string, createdAt: string, updatedAt: string, deletedAt: ?string} */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'status' => $this->status->value,
            'createdAt' => $this->createdAt->format(DATE_ATOM),
            'updatedAt' => $this->updatedAt->format(DATE_ATOM),
            'deletedAt' => $this->deletedAt?->format(DATE_ATOM),
        ];
    }
}
