<?php

declare(strict_types=1);

namespace Maatify\Product\Content\DTO;

use JsonSerializable;

/**
 * The replaceable content fields of one Content record, already validated by a Command. Option and
 * Option Value Content carry only the name; their descriptions are always `null`.
 *
 * @internal Not part of the Public Runtime API.
 */
final readonly class ContentFieldsDTO implements JsonSerializable
{
    public function __construct(
        public string $name,
        public ?string $shortDescription = null,
        public ?string $description = null,
    ) {}

    public function equals(self $other): bool
    {
        return $this->name === $other->name
            && $this->shortDescription === $other->shortDescription
            && $this->description === $other->description;
    }

    /** @return array{name: string, shortDescription: ?string, description: ?string} */
    public function jsonSerialize(): array
    {
        return [
            'name' => $this->name,
            'shortDescription' => $this->shortDescription,
            'description' => $this->description,
        ];
    }
}
