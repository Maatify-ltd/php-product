<?php

declare(strict_types=1);

namespace Maatify\Product\Content\DTO;

use DateTimeImmutable;
use JsonSerializable;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;

/**
 * Snapshot of a stored Option Value label record. `languageCode` is `null` for the unlocalized record.
 * Timestamps are hydrated in the Host Clock timezone without normalization.
 */
final readonly class OptionValueContentDTO implements JsonSerializable
{
    /**
     * @throws ProductInvalidArgumentException When an identity is not positive.
     */
    public function __construct(
        public int $id,
        public int $optionValueId,
        public ?string $languageCode,
        public string $name,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?DateTimeImmutable $deletedAt,
    ) {
        if ($id < 1) {
            throw ProductInvalidArgumentException::nonPositiveId('id');
        }
        if ($optionValueId < 1) {
            throw ProductInvalidArgumentException::nonPositiveId('optionValueId');
        }
    }

    /**
     * @return array{id: int, optionValueId: int, languageCode: ?string, name: string, createdAt: string, updatedAt: string, deletedAt: ?string}
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'optionValueId' => $this->optionValueId,
            'languageCode' => $this->languageCode,
            'name' => $this->name,
            'createdAt' => $this->createdAt->format(DATE_ATOM),
            'updatedAt' => $this->updatedAt->format(DATE_ATOM),
            'deletedAt' => $this->deletedAt?->format(DATE_ATOM),
        ];
    }
}
