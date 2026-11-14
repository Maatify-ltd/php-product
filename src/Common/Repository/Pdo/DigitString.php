<?php

declare(strict_types=1);

namespace Maatify\Product\Common\Repository\Pdo;

/**
 * Recognition of an unsigned decimal digit string, as returned for an identity by
 * `PDO::lastInsertId()` or a fetched integer column.
 *
 * {@see matches()} is exactly the digit test of the PHP `ctype` extension on a string, without
 * depending on that extension: a non-empty string of the ASCII digits `0` to `9` only. It accepts
 * `'0'` and leading zeroes, rejects the empty string, signs, whitespace, and decimal points, and does
 * not bound the value, so a digit string above `PHP_INT_MAX` matches. A `false` (the failure value of
 * `PDO::lastInsertId()`) is not a digit string. {@see toPositiveInt()} adds the range: it converts
 * only a digit string whose value is between 1 and `PHP_INT_MAX`, so no value is truncated by a cast.
 *
 * @internal Not part of the Public Runtime API.
 */
final class DigitString
{
    private function __construct() {}

    public static function matches(string|false $value): bool
    {
        return $value !== false && preg_match('/\A[0-9]+\z/', $value) === 1;
    }

    /**
     * The value of a digit string between 1 and `PHP_INT_MAX`, or null for anything else, including
     * zero and a digit string too large for a PHP integer.
     *
     * @return positive-int|null
     */
    public static function toPositiveInt(string|false $value): ?int
    {
        if (!self::matches($value)) {
            return null;
        }
        $digits = ltrim((string) $value, '0');
        $maximum = (string) PHP_INT_MAX;
        if ($digits === '' || strlen($digits) > strlen($maximum) || (strlen($digits) === strlen($maximum) && strcmp($digits, $maximum) > 0)) {
            return null;
        }
        $integer = (int) $digits;

        return $integer > 0 ? $integer : null;
    }
}
