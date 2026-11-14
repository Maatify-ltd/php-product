<?php

declare(strict_types=1);

namespace Maatify\Product\Content\DTO;

use JsonSerializable;

/**
 * State of a stored Content record as read under its row lock.
 *
 * @internal Not part of the Public Runtime API.
 */
final readonly class ContentStateDTO implements JsonSerializable
{
    public function __construct(
        public int $id,
        public int $ownerId,
        public ContentFieldsDTO $fields,
        public bool $deleted,
    ) {}

    /** @return array{id: int, ownerId: int, fields: array{name: string, shortDescription: ?string, description: ?string}, deleted: bool} */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'ownerId' => $this->ownerId,
            'fields' => $this->fields->jsonSerialize(),
            'deleted' => $this->deleted,
        ];
    }
}
