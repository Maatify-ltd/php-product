<?php

declare(strict_types=1);

namespace Maatify\Product\Content\Management\Command;

use JsonSerializable;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;
use Maatify\Product\Common\Validation\TextInputRules;
use Maatify\Product\Content\ValueObject\ExactContentLanguage;

/**
 * Validated intent to add the label record of one `(Option, language)` identity, where a `null`
 * language is the single unlocalized record. Every value is stored exactly as supplied.
 */
final readonly class CreateOptionContentCommand implements JsonSerializable
{
    /** Maximum number of characters in a label name. */
    public const int MAX_NAME_LENGTH = 255;

    /**
     * @param int $optionId The owning Option.
     * @param string|null $languageCode `null` is the unlocalized identity; otherwise non-empty, not whitespace
     *                                  only, at most 16 characters. Case variants are one identity under the collation.
     * @param string $name Required, valid UTF-8, not empty or whitespace only, at most 255 characters; never trimmed.
     *
     * @throws ProductInvalidArgumentException When a value violates its contract.
     */
    public function __construct(
        public int $optionId,
        public ?string $languageCode,
        public string $name,
    ) {
        if ($optionId < 1) {
            throw ProductInvalidArgumentException::nonPositiveId('optionId');
        }
        TextInputRules::nullableRequiredText($languageCode, 'languageCode', ExactContentLanguage::MAX_LANGUAGE_CODE_LENGTH);
        TextInputRules::requiredText($name, 'name', self::MAX_NAME_LENGTH);
    }

    /** @return array{optionId: int, languageCode: ?string, name: string} */
    public function jsonSerialize(): array
    {
        return ['optionId' => $this->optionId, 'languageCode' => $this->languageCode, 'name' => $this->name];
    }
}
