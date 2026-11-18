<?php

declare(strict_types=1);

namespace Maatify\Product\Tests\Unit\Common\Repository\Pdo;

use Maatify\Product\Common\Repository\Pdo\DuplicateKey;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Duplicate-key classification uses the quoted key in the MySQL diagnostic, including localized layouts.
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

    public function testLocalizedDiagnosticUsesTheFinalQuotedKey(): void
    {
        self::assertSame('uq_name', DuplicateKey::keyName(self::error(1062, "Duplicata du champ 'x' pour la clef 'maa_t.uq_name'")));
        self::assertSame('uq_name', DuplicateKey::keyName(self::error(1062, "'x' は索引 'maa_t.uq_name' で重複しています。")));
        self::assertNull(DuplicateKey::keyName(self::error(1062, "Only one quoted value 'uq_name'")));
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
