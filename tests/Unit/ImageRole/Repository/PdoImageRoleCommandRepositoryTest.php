<?php

declare(strict_types=1);

namespace Maatify\Product\Tests\Unit\ImageRole\Repository;

use DateTimeImmutable;
use DateTimeZone;
use Maatify\Product\Common\Exception\ProductPersistenceException;
use Maatify\Product\ImageRole\Management\Command\CreateImageRoleCommand;
use Maatify\Product\ImageRole\Repository\Pdo\PdoImageRoleCommandRepository;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The identity returned by `PDO::lastInsertId()` is range-checked before it is used: a value that is
 * not a positive integer a PHP integer can represent breaks the contract instead of being truncated.
 * Every adapter that inserts converts the identity through the same `DigitString::toPositiveInt()`.
 */
final class PdoImageRoleCommandRepositoryTest extends TestCase
{
    /**
     * @return iterable<string, array{string|false}>
     */
    public static function invalidIdentities(): iterable
    {
        yield 'one above PHP_INT_MAX' => ['9223372036854775808'];
        yield 'unsigned BIGINT maximum' => ['18446744073709551615'];
        yield 'zero' => ['0'];
        yield 'not a digit string' => ['abc'];
        yield 'lastInsertId failure' => [false];
    }

    #[DataProvider('invalidIdentities')]
    public function testAnIdentityOutsideThePositiveIntegerRangeBreaksTheContract(string|false $lastInsertId): void
    {
        $this->expectException(ProductPersistenceException::class);
        $this->expectExceptionMessage('Product Image Role AUTO_INCREMENT did not return a valid identity.');

        $this->repository($lastInsertId)->create(new CreateImageRoleCommand('hero'), self::now());
    }

    public function testTheLargestRepresentableIdentityIsReturnedExactly(): void
    {
        self::assertSame(PHP_INT_MAX, $this->repository((string) PHP_INT_MAX)->create(new CreateImageRoleCommand('hero'), self::now()));
    }

    private function repository(string|false $lastInsertId): PdoImageRoleCommandRepository
    {
        $statement = $this->createStub(PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturn($statement);
        $pdo->method('lastInsertId')->willReturn($lastInsertId);

        return new PdoImageRoleCommandRepository($pdo);
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC'));
    }
}
