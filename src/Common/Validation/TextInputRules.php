<?php

declare(strict_types=1);

namespace Maatify\Product\Common\Validation;

use Maatify\Product\Common\Exception\ProductInvalidArgumentException;

/**
 * Shared text rules that Commands, Criteria, value objects, and Services apply to text input.
 *
 * Whitespace means any Unicode whitespace or separator (`\s` and `\p{Z}`), and a character
 * length counts UTF-8 characters. No rule trims or rewrites the value it checks.
 *
 * @internal Not part of the Public Runtime API.
 */
final class TextInputRules
{
    private function __construct() {}

    /**
     * Requires valid UTF-8 that is not empty or whitespace only and has at most `$maxLength` characters.
     *
     * @throws ProductInvalidArgumentException When the value violates one of the rules.
     */
    public static function requiredText(string $value, string $field, int $maxLength): void
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            throw ProductInvalidArgumentException::invalidEncoding($field);
        }
        if (preg_match('/^[\s\p{Z}]*$/u', $value) === 1) {
            throw ProductInvalidArgumentException::emptyField($field);
        }
        if (mb_strlen($value, 'UTF-8') > $maxLength) {
            throw ProductInvalidArgumentException::fieldTooLong($field, $maxLength);
        }
    }

    /**
     * Accepts `null` as an exact value; any other value must satisfy {@see self::requiredText()}.
     *
     * @throws ProductInvalidArgumentException When a non-null value violates one of the rules.
     */
    public static function nullableRequiredText(?string $value, string $field, int $maxLength): void
    {
        if ($value !== null) {
            self::requiredText($value, $field, $maxLength);
        }
    }

    /**
     * Accepts `null` and any valid UTF-8 value, including an empty one, of at most `$maxLength` characters.
     *
     * @throws ProductInvalidArgumentException When the value is not valid UTF-8 or is too long.
     */
    public static function optionalText(?string $value, string $field, int $maxLength): void
    {
        if ($value === null) {
            return;
        }
        if (!mb_check_encoding($value, 'UTF-8')) {
            throw ProductInvalidArgumentException::invalidEncoding($field);
        }
        if (mb_strlen($value, 'UTF-8') > $maxLength) {
            throw ProductInvalidArgumentException::fieldTooLong($field, $maxLength);
        }
    }

    /**
     * Accepts `null` and any valid UTF-8 value, including an empty one, of at most `$maxBytes` bytes.
     *
     * @throws ProductInvalidArgumentException When the value is not valid UTF-8 or is too long.
     */
    public static function optionalBytes(?string $value, string $field, int $maxBytes): void
    {
        if ($value === null) {
            return;
        }
        if (!mb_check_encoding($value, 'UTF-8')) {
            throw ProductInvalidArgumentException::invalidEncoding($field);
        }
        if (strlen($value) > $maxBytes) {
            throw ProductInvalidArgumentException::fieldTooLongInBytes($field, $maxBytes);
        }
    }
}
