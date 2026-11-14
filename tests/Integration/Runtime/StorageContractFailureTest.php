<?php

declare(strict_types=1);

namespace Maatify\Product\Tests\Integration\Runtime;

use DateTimeZone;
use Maatify\Exceptions\Exception\System\SystemMaatifyException;
use Maatify\Product\Common\Exception\ProductPersistenceException;
use Maatify\Product\Exception\ProductExceptionInterface;
use Maatify\Product\Factory\ProductFactory;
use Maatify\Product\Product\Management\Command\CreateProductCommand;
use Maatify\SharedCommon\Infrastructure\SystemClock;
use PDO;
use Throwable;

/**
 * ProductPersistenceException is a documented public failure: it is raised when storage
 * returns data that breaks the schema contract, it is catchable by class and by the package
 * marker, and a failed create() is rolled back.
 */
final class StorageContractFailureTest extends RuntimeTestCase
{
    private const string STATUS_CHECK = 'chk_maa_product_products_status';

    public function testAnUnknownStoredStatusRaisesThePublicPersistenceException(): void
    {
        $service = $this->productManagementService();
        $created = $service->create(new CreateProductCommand('P-CORRUPT'));

        self::$pdo->exec('ALTER TABLE maa_product_products DROP CHECK `' . self::STATUS_CHECK . '`');
        try {
            $this->execute("UPDATE maa_product_products SET status = 'bogus' WHERE id = :i", ['i' => $created->productId]);

            try {
                $service->getByIdForManagement($created->productId);
                self::fail('A stored status the package cannot interpret must raise ProductPersistenceException.');
            } catch (Throwable $caught) {
                self::assertInstanceOf(ProductPersistenceException::class, $caught);
                self::assertInstanceOf(ProductExceptionInterface::class, $caught);
                self::assertInstanceOf(SystemMaatifyException::class, $caught);
                self::assertStringContainsString('status', $caught->getMessage());
            }
        } finally {
            $this->execute("UPDATE maa_product_products SET status = 'inactive' WHERE id = :i", ['i' => $created->productId]);
            self::$pdo->exec(
                'ALTER TABLE maa_product_products ADD CONSTRAINT `' . self::STATUS_CHECK . '` '
                . "CHECK (BINARY `status` IN (BINARY 'active', BINARY 'inactive'))",
            );
        }
    }

    public function testAnInvalidAutoIncrementIdentityRollsTheOwnedTransactionBack(): void
    {
        $service = ProductFactory::createProductManagementService($this->pdoWithBrokenIdentity(), new SystemClock(new DateTimeZone('UTC')));

        try {
            $service->create(new CreateProductCommand('P-NO-ID'));
            self::fail('An invalid AUTO_INCREMENT identity must raise ProductPersistenceException.');
        } catch (Throwable $caught) {
            self::assertInstanceOf(ProductPersistenceException::class, $caught);
        }

        self::assertSame(0, self::rowCount('maa_product_products'));
        self::assertSame(0, self::rowCount('maa_product_variants'));
    }

    public function testAnInvalidAutoIncrementIdentityInsideAHostTransactionLeavesNoWrite(): void
    {
        $pdo = $this->pdoWithBrokenIdentity();
        $service = ProductFactory::createProductManagementService($pdo, new SystemClock(new DateTimeZone('UTC')));

        $pdo->beginTransaction();
        try {
            try {
                $service->create(new CreateProductCommand('P-HOST-NO-ID'));
                self::fail('An invalid AUTO_INCREMENT identity must raise ProductPersistenceException.');
            } catch (Throwable $caught) {
                self::assertInstanceOf(ProductPersistenceException::class, $caught);
            }
            self::assertTrue($pdo->inTransaction());
            $pdo->commit();
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }

        self::assertSame(0, self::rowCount('maa_product_products'));
    }

    private function pdoWithBrokenIdentity(): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;charset=utf8mb4;dbname=%s',
            self::env('PRODUCT_TEST_DB_HOST'),
            self::env('PRODUCT_TEST_DB_PORT'),
            self::$database,
        );

        return new class ($dsn, self::env('PRODUCT_TEST_DB_USER'), self::env('PRODUCT_TEST_DB_PASSWORD')) extends PDO {
            public function __construct(string $dsn, string $user, string $password)
            {
                parent::__construct($dsn, $user, $password, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);
            }

            public function lastInsertId(?string $name = null): string
            {
                return '0';
            }
        };
    }
}
