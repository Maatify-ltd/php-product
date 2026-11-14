<?php

declare(strict_types=1);

namespace Maatify\Product\ImageRole\DTO;

use DateTimeImmutable;
use JsonSerializable;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;
use Maatify\Product\ImageRole\Enum\ImageRoleStatusEnum;

/**
 * Snapshot of a stored Product Image Role. Timestamps are hydrated in the Host Clock timezone without normalization.
 */
final readonly class ImageRoleDTO implements JsonSerializable
{
    /**
     * @throws ProductInvalidArgumentException When the identity is not positive.
     */
    public function __construct(
        public int $id,
        public string $roleKey,
        public ImageRoleStatusEnum $status,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?DateTimeImmutable $deletedAt,
    ) {
        if ($id < 1) {
            throw ProductInvalidArgumentException::nonPositiveId('id');
        }
    }

    /** @return array{id: int, roleKey: string, status: string, createdAt: string, updatedAt: string, deletedAt: ?string} */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'roleKey' => $this->roleKey,
            'status' => $this->status->value,
            'createdAt' => $this->createdAt->format(DATE_ATOM),
            'updatedAt' => $this->updatedAt->format(DATE_ATOM),
            'deletedAt' => $this->deletedAt?->format(DATE_ATOM),
        ];
    }
}
