<?php

declare(strict_types=1);

namespace Maatify\Product\OptionValue\DTO;

use DateTimeImmutable;
use JsonSerializable;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;
use Maatify\Product\OptionValue\Enum\OptionValueStatusEnum;

/**
 * Snapshot of a stored Option Value. Timestamps are hydrated in the Host Clock timezone without normalization.
 */
final readonly class OptionValueDTO implements JsonSerializable
{
    /**
     * @throws ProductInvalidArgumentException When an identity or the display order is not positive.
     */
    public function __construct(
        public int $id,
        public int $optionId,
        public string $code,
        public OptionValueStatusEnum $status,
        public int $displayOrder,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?DateTimeImmutable $deletedAt,
    ) {
        if ($id < 1) {
            throw ProductInvalidArgumentException::nonPositiveId('id');
        }
        if ($optionId < 1) {
            throw ProductInvalidArgumentException::nonPositiveId('optionId');
        }
        if ($displayOrder < 1) {
            throw ProductInvalidArgumentException::invalidDisplayOrder($displayOrder);
        }
    }

    /**
     * @return array{id: int, optionId: int, code: string, status: string, displayOrder: int, createdAt: string, updatedAt: string, deletedAt: ?string}
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'optionId' => $this->optionId,
            'code' => $this->code,
            'status' => $this->status->value,
            'displayOrder' => $this->displayOrder,
            'createdAt' => $this->createdAt->format(DATE_ATOM),
            'updatedAt' => $this->updatedAt->format(DATE_ATOM),
            'deletedAt' => $this->deletedAt?->format(DATE_ATOM),
        ];
    }
}
