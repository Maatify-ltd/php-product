<?php

declare(strict_types=1);

namespace Maatify\Product\Tests\Unit\Common\Repository\Pdo;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use Maatify\Product\Common\Exception\ProductPersistenceException;
use Maatify\Product\Common\Repository\Pdo\RowHydrator;
use Maatify\SharedCommon\Contracts\ClockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A stored value outside the schema contract raises {@see ProductPersistenceException}: an identity
 * that a PHP integer cannot represent is rejected instead of truncated, and an unparsable timestamp
 * keeps the parser's failure as the previous exception.
 */
final class RowHydratorTest extends TestCase
{
    /**
     * @return iterable<string, array{int|string, int}>
     */
    public static function representableIdentities(): iterable
    {
        yield 'integer' => [42, 42];
        yield 'digit string' => ['42', 42];
        yield 'leading zeroes' => ['007', 7];
        yield 'PHP_INT_MAX' => [(string) PHP_INT_MAX, PHP_INT_MAX];
        yield 'PHP_INT_MAX with leading zeroes' => ['00' . PHP_INT_MAX, PHP_INT_MAX];
    }

    #[DataProvider('representableIdentities')]
    public function testAPositiveIdentityWithinTheIntegerRangeIsReadExactly(int|string $stored, int $expected): void
    {
        self::assertSame($expected, RowHydrator::positiveInt(['id' => $stored], 'id'));
    }

    /**
     * @return iterable<string, array{int|string}>
     */
    public static function unrepresentableIdentities(): iterable
    {
        yield 'zero' => ['0'];
        yield 'zeroes' => ['000'];
        yield 'negative integer' => [-1];
        yield 'one above PHP_INT_MAX' => ['9223372036854775808'];
        yield 'unsigned BIGINT maximum' => ['18446744073709551615'];
        yield 'longer than PHP_INT_MAX' => ['99999999999999999999999'];
        yield 'not a digit string' => ['12a'];
    }

    #[DataProvider('unrepresentableIdentities')]
    public function testAnIdentityOutsideThePositiveIntegerRangeBreaksTheContract(int|string $stored): void
    {
        $this->expectException(ProductPersistenceException::class);
        $this->expectExceptionMessage('Product storage column "id" contains an invalid value.');

        RowHydrator::positiveInt(['id' => $stored], 'id');
    }

    public function testAnUnparsableTimestampKeepsTheParserFailureAsThePreviousException(): void
    {
        $hydrator = new RowHydrator(new class implements ClockInterface {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC'));
            }

            public function getTimezone(): DateTimeZone
            {
                return new DateTimeZone('UTC');
            }
        });

        try {
            $hydrator->timestamp(['created_at' => 'not a timestamp'], 'created_at');
            self::fail('An unparsable timestamp must break the contract.');
        } catch (ProductPersistenceException $failure) {
            self::assertSame('Product storage column "created_at" contains an invalid value.', $failure->getMessage());
            self::assertInstanceOf(Exception::class, $failure->getPrevious());
            self::assertNotInstanceOf(ProductPersistenceException::class, $failure->getPrevious());
        }
    }
}
