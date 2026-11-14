<?php

declare(strict_types=1);

namespace Maatify\Product\Tests\Unit\Common\Repository\Pdo;

use Maatify\Product\Common\Repository\Pdo\DigitString;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `DigitString::matches()` replaces the `ctype` digit check in the PDO adapters without requiring
 * the `ctype` extension. Each case pins the expected answer, and where the extension is loaded the
 * answer is also compared with the extension's own, so the two stay equivalent.
 */
final class DigitStringTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function cases(): iterable
    {
        yield 'single digit' => ['7', true];
        yield 'digit string' => ['1234567890', true];
        yield 'zero' => ['0', true];
        yield 'leading zeroes' => ['007', true];
        yield 'PHP_INT_MAX' => [(string) PHP_INT_MAX, true];
        yield 'above PHP_INT_MAX' => ['99999999999999999999999', true];
        yield 'empty string' => ['', false];
        yield 'negative' => ['-1', false];
        yield 'explicit plus' => ['+1', false];
        yield 'decimal point' => ['1.0', false];
        yield 'leading space' => [' 1', false];
        yield 'trailing space' => ['1 ', false];
        yield 'trailing newline' => ["1\n", false];
        yield 'exponent' => ['1e3', false];
        yield 'hexadecimal' => ['0x1A', false];
        yield 'letters' => ['abc', false];
        yield 'NUL byte' => ["1\x002", false];
        yield 'non-ASCII digit' => ['١٢٣', false];
    }

    #[DataProvider('cases')]
    public function testMatchesExactlyTheUnsignedAsciiDigitStrings(string $value, bool $expected): void
    {
        self::assertSame($expected, DigitString::matches($value));
    }

    #[DataProvider('cases')]
    public function testAgreesWithTheCtypeDigitCheckWhereTheExtensionIsLoaded(string $value, bool $expected): void
    {
        if (!extension_loaded('ctype')) {
            self::markTestSkipped('The ctype extension is not loaded; the expected values above still pin the rule.');
        }

        self::assertSame(ctype_digit($value), DigitString::matches($value));
        self::assertSame($expected, ctype_digit($value));
    }

    public function testTheFailureValueOfLastInsertIdIsNotADigitString(): void
    {
        self::assertFalse(DigitString::matches(false));
        self::assertNull(DigitString::toPositiveInt(false));
    }

    /**
     * @return iterable<string, array{string, int|null}>
     */
    public static function positiveIntegers(): iterable
    {
        yield 'one' => ['1', 1];
        yield 'leading zeroes' => ['007', 7];
        yield 'PHP_INT_MAX' => [(string) PHP_INT_MAX, PHP_INT_MAX];
        yield 'zero' => ['0', null];
        yield 'zeroes' => ['000', null];
        yield 'one above PHP_INT_MAX' => ['9223372036854775808', null];
        yield 'unsigned BIGINT maximum' => ['18446744073709551615', null];
        yield 'not a digit string' => ['-1', null];
    }

    #[DataProvider('positiveIntegers')]
    public function testToPositiveIntConvertsOnlyAValueBetweenOneAndTheIntegerMaximum(string $value, ?int $expected): void
    {
        self::assertSame($expected, DigitString::toPositiveInt($value));
    }
}
