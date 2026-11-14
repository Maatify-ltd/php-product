<?php

declare(strict_types=1);

namespace Maatify\Product\Tests\Unit\Common\Repository\Pdo;

use Maatify\Product\Common\Repository\Pdo\DuplicateKey;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * The violated key of a duplicate-key error is read from the trailing `for key '...'` clause only.
 */
final class DuplicateKeyTest extends TestCase
{
    public function testTheKeyIsReadFromBothMessageForms(): void
    {
        self::assertSame('uq_a', DuplicateKey::keyName(self::error(1062, "Duplicate entry 'x' for key 'uq_a'")), 'MySQL 8.0.16 to 8.0.18.');
        self::assertSame('uq_a', DuplicateKey::keyName(self::error(1062, "Duplicate entry 'x' for key 'maa_t.uq_a'")), 'MySQL 8.0.19 and later.');
        self::assertSame('uq_a', DuplicateKey::keyName(self::error('1062', "Duplicate entry 'x' for key 'uq_a'")), 'A string driver code.');
    }

    public function testTheDuplicatedValueNeverDecidesTheKey(): void
    {
        $error = self::error(1062, "Duplicate entry 'uq_maa_product_variants_sku-1' for key 'maa_product_variants.uq_maa_product_variants_barcode'");

        self::assertSame('uq_maa_product_variants_barcode', DuplicateKey::keyName($error));
        self::assertTrue(DuplicateKey::isOnKey($error, 'uq_maa_product_variants_barcode'));
        self::assertFalse(DuplicateKey::isOnKey($error, 'uq_maa_product_variants_sku'), 'Equality, not a substring.');
        self::assertFalse(DuplicateKey::isOnKey(self::error(1062, "Duplicate entry 'v' for key 'uq_a_identity_x'"), 'uq_a_identity'));
    }

    public function testOtherErrorsHaveNoKey(): void
    {
        self::assertFalse(DuplicateKey::isDuplicate(self::error(1213, 'Deadlock found')));
        self::assertNull(DuplicateKey::keyName(self::error(1452, "Cannot add or update a child row for key 'fk'")));
        self::assertNull(DuplicateKey::keyName(self::error(1062, 'Duplicate entry without a key clause')));
        self::assertNull(DuplicateKey::keyName(new PDOException('no driver information')));
        self::assertTrue(DuplicateKey::isDuplicate(self::error(1062, 'any')));
    }

    private static function error(int|string $driverCode, string $message): PDOException
    {
        $exception = new PDOException($message);
        $exception->errorInfo = ['23000', $driverCode, $message];

        return $exception;
    }
}
