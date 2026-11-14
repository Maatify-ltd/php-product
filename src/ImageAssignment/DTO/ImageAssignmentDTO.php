<?php

declare(strict_types=1);

namespace Maatify\Product\ImageAssignment\DTO;

use DateTimeImmutable;
use JsonSerializable;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;

/**
 * Snapshot of a stored Image Assignment. `variantId` null is the Product-level target; `roleId`,
 * `languageCode`, and `platform` are exact scope values where null means exactly null.
 */
final readonly class ImageAssignmentDTO implements JsonSerializable
{
    /**
     * @throws ProductInvalidArgumentException When an identity or the display order is not positive.
     */
    public function __construct(
        public int $id,
        public int $productId,
        public ?int $variantId,
        public int $mediaAssetId,
        public ?int $roleId,
        public ?string $languageCode,
        public ?string $platform,
        public bool $isDefault,
        public int $displayOrder,
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
        if ($mediaAssetId < 1) {
            throw ProductInvalidArgumentException::nonPositiveId('mediaAssetId');
        }
        if ($variantId !== null && $variantId < 1) {
            throw ProductInvalidArgumentException::nonPositiveId('variantId');
        }
        if ($roleId !== null && $roleId < 1) {
            throw ProductInvalidArgumentException::nonPositiveId('roleId');
        }
        if ($displayOrder < 1) {
            throw ProductInvalidArgumentException::invalidDisplayOrder($displayOrder);
        }
    }

    /**
     * @return array{
     *     id: int, productId: int, variantId: ?int, mediaAssetId: int, roleId: ?int, languageCode: ?string,
     *     platform: ?string, isDefault: bool, displayOrder: int, createdAt: string, updatedAt: string, deletedAt: ?string
     * }
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'productId' => $this->productId,
            'variantId' => $this->variantId,
            'mediaAssetId' => $this->mediaAssetId,
            'roleId' => $this->roleId,
            'languageCode' => $this->languageCode,
            'platform' => $this->platform,
            'isDefault' => $this->isDefault,
            'displayOrder' => $this->displayOrder,
            'createdAt' => $this->createdAt->format(DATE_ATOM),
            'updatedAt' => $this->updatedAt->format(DATE_ATOM),
            'deletedAt' => $this->deletedAt?->format(DATE_ATOM),
        ];
    }
}
