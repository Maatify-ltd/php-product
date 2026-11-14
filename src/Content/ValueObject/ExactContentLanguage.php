<?php

declare(strict_types=1);

namespace Maatify\Product\Content\ValueObject;

use JsonSerializable;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;
use Maatify\Product\Common\Validation\TextInputRules;

/**
 * Language selector of the consumer Content read, in exactly one of two states: one exact
 * language, or the unlocalized record only. It has no "all languages" state, so a consumer read
 * cannot ask for every language (architecture §12).
 *
 * An exact language compares under the database collation and never falls back to the
 * unlocalized record or another language; the unlocalized state never returns a localized record.
 */
final readonly class ExactContentLanguage implements JsonSerializable
{
    /** Maximum number of characters in a Content language code. */
    public const int MAX_LANGUAGE_CODE_LENGTH = 16;

    private function __construct(private ?string $languageCode) {}

    /**
     * Selects the record of this exact language.
     *
     * @param string $languageCode Non-empty, not whitespace only, at most 16 characters; kept verbatim.
     *
     * @throws ProductInvalidArgumentException When the language code violates its contract.
     */
    public static function exactLanguage(string $languageCode): self
    {
        TextInputRules::requiredText($languageCode, 'languageCode', self::MAX_LANGUAGE_CODE_LENGTH);

        return new self($languageCode);
    }

    /**
     * Selects the unlocalized record (`language_code IS NULL`).
     */
    public static function unlocalizedOnly(): self
    {
        return new self(null);
    }

    public function isUnlocalizedOnly(): bool
    {
        return $this->languageCode === null;
    }

    /**
     * The selected language, or `null` for the unlocalized state.
     */
    public function languageCode(): ?string
    {
        return $this->languageCode;
    }

    /** @return array{languageCode: ?string} */
    public function jsonSerialize(): array
    {
        return ['languageCode' => $this->languageCode];
    }
}
