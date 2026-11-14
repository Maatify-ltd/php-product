<?php

declare(strict_types=1);

namespace Maatify\Product\ImageAssignment\ValueObject;

use JsonSerializable;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;
use Maatify\Product\Common\Validation\TextInputRules;
use Maatify\Product\ImageAssignment\Management\Command\CreateImageAssignmentCommand;

/**
 * Language selector of the management Image Assignment list, in exactly one of three states: omitted
 * (no condition), exactly `NULL` (`language_code IS NULL`), or one exact value compared under the database
 * collation. `NULL` is an exact value, never a wildcard, and omitting the filter is a different state.
 */
final readonly class ImageAssignmentLanguageFilter implements JsonSerializable
{
    private const string OMITTED = 'omitted';
    private const string EXACT_NULL = 'exact_null';
    private const string EXACT = 'exact';

    private function __construct(
        private string $mode,
        private ?string $value,
    ) {}

    public static function omitted(): self
    {
        return new self(self::OMITTED, null);
    }

    public static function exactNull(): self
    {
        return new self(self::EXACT_NULL, null);
    }

    /**
     * @param string $value The rule of `CreateImageAssignmentCommand::$languageCode`: not empty or whitespace only,
     *                      at most 16 characters; kept verbatim.
     *
     * @throws ProductInvalidArgumentException When the value violates its contract.
     */
    public static function exact(string $value): self
    {
        TextInputRules::requiredText($value, 'languageCode', CreateImageAssignmentCommand::MAX_LANGUAGE_CODE_LENGTH);

        return new self(self::EXACT, $value);
    }

    public function isOmitted(): bool
    {
        return $this->mode === self::OMITTED;
    }

    public function isExactNull(): bool
    {
        return $this->mode === self::EXACT_NULL;
    }

    /**
     * The selected value in the exact state; `null` in the two other states.
     */
    public function value(): ?string
    {
        return $this->value;
    }

    /** @return array{languageCode: string, value: ?string} */
    public function jsonSerialize(): array
    {
        return ['languageCode' => $this->mode, 'value' => $this->value];
    }
}
