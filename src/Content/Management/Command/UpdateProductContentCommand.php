<?php

declare(strict_types=1);

namespace Maatify\Product\Content\Management\Command;

use JsonSerializable;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;
use Maatify\Product\Common\Validation\TextInputRules;

/**
 * Validated intent to replace every content field of one Product Content record. The owner and
 * the language are the record's immutable identity and are not part of the command; a `null`
 * optional field clears it. Every value is stored exactly as supplied.
 */
final readonly class UpdateProductContentCommand implements JsonSerializable
{
    /**
     * @param int $contentId The Product Content record.
     * @param string $name Required, valid UTF-8, not empty or whitespace only, at most 255 characters; never trimmed.
     * @param string|null $shortDescription Optional, valid UTF-8, at most 500 characters; an empty string is kept.
     * @param string|null $description Optional, valid UTF-8, at most 65535 bytes; an empty string is kept.
     *
     * @throws ProductInvalidArgumentException When a value violates its contract.
     */
    public function __construct(
        public int $contentId,
        public string $name,
        public ?string $shortDescription,
        public ?string $description,
    ) {
        if ($contentId < 1) {
            throw ProductInvalidArgumentException::nonPositiveId('contentId');
        }
        TextInputRules::requiredText($name, 'name', CreateProductContentCommand::MAX_NAME_LENGTH);
        TextInputRules::optionalText($shortDescription, 'shortDescription', CreateProductContentCommand::MAX_SHORT_DESCRIPTION_LENGTH);
        TextInputRules::optionalBytes($description, 'description', CreateProductContentCommand::MAX_DESCRIPTION_BYTES);
    }

    /** @return array{contentId: int, name: string, shortDescription: ?string, description: ?string} */
    public function jsonSerialize(): array
    {
        return [
            'contentId' => $this->contentId,
            'name' => $this->name,
            'shortDescription' => $this->shortDescription,
            'description' => $this->description,
        ];
    }
}
