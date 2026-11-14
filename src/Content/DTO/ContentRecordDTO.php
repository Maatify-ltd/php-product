<?php

declare(strict_types=1);

namespace Maatify\Product\Content\DTO;

use DateTimeImmutable;
use JsonSerializable;

/**
 * A stored Content record of any owner as hydrated by the read adapter; each owner's Service maps
 * it to that owner's public DTO.
 *
 * @internal Not part of the Public Runtime API.
 */
final readonly class ContentRecordDTO implements JsonSerializable
{
    public function __construct(
        public int $id,
        public int $ownerId,
        public ?string $languageCode,
        public ContentFieldsDTO $fields,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public ?DateTimeImmutable $deletedAt,
    ) {}

    /** @return array{id: int, ownerId: int, languageCode: ?string, fields: array{name: string, shortDescription: ?string, description: ?string}, createdAt: string, updatedAt: string, deletedAt: ?string} */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'ownerId' => $this->ownerId,
            'languageCode' => $this->languageCode,
            'fields' => $this->fields->jsonSerialize(),
            'createdAt' => $this->createdAt->format(DATE_ATOM),
            'updatedAt' => $this->updatedAt->format(DATE_ATOM),
            'deletedAt' => $this->deletedAt?->format(DATE_ATOM),
        ];
    }
}
