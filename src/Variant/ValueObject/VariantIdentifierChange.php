<?php

declare(strict_types=1);

namespace Maatify\Product\Variant\ValueObject;

use JsonSerializable;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;
use Maatify\Product\Common\Validation\TextInputRules;

/**
 * The intended change of one secondary Variant identifier (SKU or barcode), in exactly one of
 * three mutually exclusive states: leave the stored value unchanged, set a value, or clear it to
 * `NULL`. "Unchanged" and "clear" are distinct states, never one nullable parameter.
 */
final readonly class VariantIdentifierChange implements JsonSerializable
{
    /** Maximum number of characters in a SKU or barcode. */
    public const int MAX_LENGTH = 128;

    private const string UNCHANGED = 'unchanged';
    private const string SET = 'set';
    private const string CLEAR = 'clear';

    private function __construct(
        private string $mode,
        private ?string $value,
    ) {}

    /**
     * Leaves the stored value as it is.
     */
    public static function unchanged(): self
    {
        return new self(self::UNCHANGED, null);
    }

    /**
     * Stores this exact value (a barcode keeps its leading zeroes).
     *
     * @param string $value Valid UTF-8, not empty or whitespace only (any Unicode whitespace), at most 128
     *                      characters; never trimmed.
     *
     * @throws ProductInvalidArgumentException When the value violates its contract.
     */
    public static function set(string $value): self
    {
        TextInputRules::requiredText($value, 'value', self::MAX_LENGTH);

        return new self(self::SET, $value);
    }

    /**
     * Clears the stored value to `NULL`, which releases it.
     */
    public static function clear(): self
    {
        return new self(self::CLEAR, null);
    }

    public function isUnchanged(): bool
    {
        return $this->mode === self::UNCHANGED;
    }

    public function isSet(): bool
    {
        return $this->mode === self::SET;
    }

    public function isClear(): bool
    {
        return $this->mode === self::CLEAR;
    }

    /**
     * The value to store in the set state; `null` in the two other states.
     */
    public function value(): ?string
    {
        return $this->value;
    }

    /**
     * The value stored after applying this change to the current one: the current value when
     * unchanged, the new value when set, and `null` when cleared.
     */
    public function resolve(?string $current): ?string
    {
        return match ($this->mode) {
            self::UNCHANGED => $current,
            self::SET => $this->value,
            default => null,
        };
    }

    /** @return array{change: string, value: ?string} */
    public function jsonSerialize(): array
    {
        return ['change' => $this->mode, 'value' => $this->value];
    }
}
