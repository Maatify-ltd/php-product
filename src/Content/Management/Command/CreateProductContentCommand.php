<?php

declare(strict_types=1);

namespace Maatify\Product\Content\Management\Command;

use JsonSerializable;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;
use Maatify\Product\Common\Validation\TextInputRules;
use Maatify\Product\Content\ValueObject\ExactContentLanguage;

/**
 * Validated intent to add the Content record of one `(Product, language)` identity, where a
 * `null` language is the single unlocalized record. Every value is stored exactly as supplied.
 */
final readonly class CreateProductContentCommand implements JsonSerializable
{
    /** Maximum number of characters in a Content name. */
    public const int MAX_NAME_LENGTH = 255;

    /** Maximum number of characters in a short description. */
    public const int MAX_SHORT_DESCRIPTION_LENGTH = 500;

    /** Maximum number of bytes in a description (the `TEXT` column). */
    public const int MAX_DESCRIPTION_BYTES = 65535;

    /**
     * @param int $productId The owning Product.
     * @param string|null $languageCode `null` is the unlocalized identity; otherwise non-empty, not whitespace
     *                                  only, at most 16 characters. Case variants are one identity under the collation.
     * @param string $name Required, valid UTF-8, not empty or whitespace only, at most 255 characters; never trimmed.
     * @param string|null $shortDescription Optional, valid UTF-8, at most 500 characters; an empty string is kept.
     * @param string|null $description Optional, valid UTF-8, at most 65535 bytes; an empty string is kept.
     *
     * @throws ProductInvalidArgumentException When a value violates its contract.
     */
    public function __construct(
        public int $productId,
        public ?string $languageCode,
        public string $name,
        public ?string $shortDescription = null,
        public ?string $description = null,
    ) {
        if ($productId < 1) {
            throw ProductInvalidArgumentException::nonPositiveId('productId');
        }
        TextInputRules::nullableRequiredText($languageCode, 'languageCode', ExactContentLanguage::MAX_LANGUAGE_CODE_LENGTH);
        TextInputRules::requiredText($name, 'name', self::MAX_NAME_LENGTH);
        TextInputRules::optionalText($shortDescription, 'shortDescription', self::MAX_SHORT_DESCRIPTION_LENGTH);
        TextInputRules::optionalBytes($description, 'description', self::MAX_DESCRIPTION_BYTES);
    }

    /** @return array{productId: int, languageCode: ?string, name: string, shortDescription: ?string, description: ?string} */
    public function jsonSerialize(): array
    {
        return [
            'productId' => $this->productId,
            'languageCode' => $this->languageCode,
            'name' => $this->name,
            'shortDescription' => $this->shortDescription,
            'description' => $this->description,
        ];
    }
}
