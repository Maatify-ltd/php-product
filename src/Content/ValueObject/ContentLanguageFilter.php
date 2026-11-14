<?php

declare(strict_types=1);

namespace Maatify\Product\Content\ValueObject;

use JsonSerializable;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;
use Maatify\Product\Common\Validation\TextInputRules;

/**
 * Language selector of the management Content reads, in exactly one of three mutually exclusive
 * states: one exact language, the unlocalized record only (`language_code IS NULL`), or all
 * languages. "All languages" and "unlocalized" are distinct states, never one nullable parameter.
 *
 * An exact language compares under the database collation (`utf8mb4_unicode_ci`, so `EN` matches
 * `en`) and never falls back to the unlocalized record or another language.
 */
final readonly class ContentLanguageFilter implements JsonSerializable
{
    private const string EXACT = 'exact_language';
    private const string UNLOCALIZED = 'unlocalized_only';
    private const string ALL = 'all_languages';

    private function __construct(
        private string $mode,
        private ?string $languageCode,
    ) {}

    /**
     * Selects only the records of this exact language.
     *
     * @param string $languageCode Non-empty, not whitespace only, at most 16 characters; kept verbatim.
     *
     * @throws ProductInvalidArgumentException When the language code violates its contract.
     */
    public static function exactLanguage(string $languageCode): self
    {
        TextInputRules::requiredText($languageCode, 'languageCode', ExactContentLanguage::MAX_LANGUAGE_CODE_LENGTH);

        return new self(self::EXACT, $languageCode);
    }

    /**
     * Selects only the unlocalized record (`language_code IS NULL`).
     */
    public static function unlocalizedOnly(): self
    {
        return new self(self::UNLOCALIZED, null);
    }

    /**
     * Applies no language filter: every localized record and the unlocalized one.
     */
    public static function allLanguages(): self
    {
        return new self(self::ALL, null);
    }

    public function isExactLanguage(): bool
    {
        return $this->mode === self::EXACT;
    }

    public function isUnlocalizedOnly(): bool
    {
        return $this->mode === self::UNLOCALIZED;
    }

    public function isAllLanguages(): bool
    {
        return $this->mode === self::ALL;
    }

    /**
     * The selected language for the exact-language state; `null` in the two other states.
     */
    public function languageCode(): ?string
    {
        return $this->languageCode;
    }

    /** @return array{mode: string, languageCode: ?string} */
    public function jsonSerialize(): array
    {
        return ['mode' => $this->mode, 'languageCode' => $this->languageCode];
    }
}
