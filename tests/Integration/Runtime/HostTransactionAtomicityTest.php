<?php

declare(strict_types=1);

namespace Maatify\Product\Tests\Integration\Runtime;

use DateTimeImmutable;
use DateTimeZone;
use Maatify\PhpPersistence\Ordering\Repository\ScopedOrderingManager;
use Maatify\PhpPersistence\Transaction\Repository\PdoSavepointTransactionRunner;
use Maatify\Product\Product\Exception\ProductCodeAlreadyExistsException;
use Maatify\Product\Product\Management\Command\CreateProductCommand;
use Maatify\Product\Product\Management\Service\ProductManagementService;
use Maatify\Product\Product\Repository\Pdo\PdoProductCommandRepository;
use Maatify\Product\Product\Repository\Pdo\PdoProductConfigurationRepository;
use Maatify\Product\Product\Repository\Pdo\PdoProductQueryReader;
use Maatify\Product\Tests\Support\UnusedVariantLifecycleWrites;
use Maatify\Product\Variant\Repository\Pdo\PdoVariantCommandRepository;
use Maatify\Product\Variant\Repository\VariantCommandRepositoryInterface;
use Maatify\SharedCommon\Infrastructure\SystemClock;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * A failed create() must never leave a Product without its Variant, whoever owns the
 * transaction. When the Host owns it, the failure is rolled back to an operation-local
 * savepoint, so the Host can catch the exception and still commit without persisting the
 * partial write, and its other work in the same transaction survives.
 */
final class HostTransactionAtomicityTest extends RuntimeTestCase
{
    private const string FAILING_TRIGGER = 'trg_product_it_variant_failure';

    public function testAFailedCallLeavesNoWriteInTheHostTransactionWhichStaysActive(): void
    {
        $service = $this->serviceWithVariants($this->variantsFailingBeforeTheWrite(new RuntimeException('variant write failed')));

        self::$pdo->beginTransaction();
        try {
            $service->create(new CreateProductCommand('P-HOST-FAIL'));
            self::fail('The failure must propagate.');
        } catch (RuntimeException $caught) {
            self::assertSame('variant write failed', $caught->getMessage());
        }

        self::assertTrue(self::$pdo->inTransaction(), 'The package must neither commit nor roll back the Host transaction.');
        self::assertSame(0, self::rowCount('maa_product_products'), 'The Product insert must already be rolled back inside the Host transaction.');
        self::assertSame(0, self::rowCount('maa_product_variants'));
        self::$pdo->rollBack();
    }

    public function testAHostThatCatchesTheFailureAndCommitsPersistsNeitherRow(): void
    {
        $service = $this->serviceWithVariants($this->variantsFailingBeforeTheWrite(new RuntimeException('variant write failed')));

        self::$pdo->beginTransaction();
        try {
            $service->create(new CreateProductCommand('P-HOST-SWALLOW'));
            self::fail('The failure must propagate.');
        } catch (RuntimeException) {
            // The Host deliberately swallows the failure and carries on to COMMIT.
        }
        self::$pdo->commit();

        self::assertSame(0, self::rowCount('maa_product_products'));
        self::assertSame(0, self::rowCount('maa_product_variants'));
        self::assertNoProductWithoutItsVariant();
    }

    public function testAFailureAfterBothWritesRemovesBothRows(): void
    {
        $real = new PdoVariantCommandRepository(self::$pdo, new ScopedOrderingManager());
        $failAfterWrite = new class ($real) implements VariantCommandRepositoryInterface {
            use UnusedVariantLifecycleWrites;

            public function __construct(private PdoVariantCommandRepository $real) {}

            public function createEmptyComposition(int $productId, DateTimeImmutable $occurredAt): int
            {
                $this->real->createEmptyComposition($productId, $occurredAt);

                throw new RuntimeException('failure after the Variant write');
            }
        };
        $service = $this->serviceWithVariants($failAfterWrite);

        self::$pdo->beginTransaction();
        try {
            $service->create(new CreateProductCommand('P-AFTER-WRITE'));
            self::fail('The failure must propagate.');
        } catch (RuntimeException) {
        }
        self::assertTrue(self::$pdo->inTransaction());
        self::$pdo->commit();

        self::assertSame(0, self::rowCount('maa_product_products'));
        self::assertSame(0, self::rowCount('maa_product_variants'));
    }

    public function testHostWorkAroundAFailedCallSurvivesAndOnlyCompleteProductsAreCommitted(): void
    {
        $ok = $this->productManagementService();
        $failing = $this->serviceWithVariants($this->variantsFailingBeforeTheWrite(new RuntimeException('variant write failed')));

        self::$pdo->beginTransaction();
        $before = $ok->create(new CreateProductCommand('P-BEFORE'));
        try {
            $failing->create(new CreateProductCommand('P-FAILED'));
            self::fail('The failure must propagate.');
        } catch (RuntimeException) {
        }
        $after = $ok->create(new CreateProductCommand('P-AFTER'));
        self::$pdo->commit();

        self::assertSame(2, self::rowCount('maa_product_products'));
        self::assertSame(2, self::rowCount('maa_product_variants'));
        self::assertSame(0, self::rowCount('maa_product_products', "code = 'P-FAILED'"));
        self::assertSame(1, self::rowCount('maa_product_variants', 'product_id = ' . $before->productId));
        self::assertSame(1, self::rowCount('maa_product_variants', 'product_id = ' . $after->productId));
        self::assertNoProductWithoutItsVariant();
    }

    public function testAHostRollbackAfterAFailedCallLeavesNothing(): void
    {
        $ok = $this->productManagementService();
        $failing = $this->serviceWithVariants($this->variantsFailingBeforeTheWrite(new RuntimeException('variant write failed')));

        self::$pdo->beginTransaction();
        $ok->create(new CreateProductCommand('P-ROLLED-BACK'));
        try {
            $failing->create(new CreateProductCommand('P-FAILED'));
            self::fail('The failure must propagate.');
        } catch (RuntimeException) {
        }
        self::$pdo->rollBack();

        self::assertSame(0, self::rowCount('maa_product_products'));
        self::assertSame(0, self::rowCount('maa_product_variants'));
    }

    public function testARealMySqlStatementFailureIsRolledBackAndPropagatesUnchanged(): void
    {
        $pdo = self::$pdo;
        $statementFailure = new class ($pdo) implements VariantCommandRepositoryInterface {
            use UnusedVariantLifecycleWrites;

            public function __construct(private \PDO $pdo) {}

            public function createEmptyComposition(int $productId, DateTimeImmutable $occurredAt): int
            {
                // A genuine storage error raised after the Product row exists: the Variant
                // references a Product identity that does not exist (foreign key violation).
                $this->pdo->exec(
                    'INSERT INTO `maa_product_variants` (`product_id`, `composition_fingerprint`, `status`, `display_order`, '
                    . '`created_at`, `updated_at`) VALUES (' . ($productId + 1000000) . ", '" . str_repeat('a', 64) . "', 'active', 1, NOW(), NOW())",
                );

                return 1;
            }
        };
        $service = $this->serviceWithVariants($statementFailure);

        self::$pdo->beginTransaction();
        try {
            $service->create(new CreateProductCommand('P-STATEMENT'));
            self::fail('The storage failure must propagate.');
        } catch (Throwable $caught) {
            self::assertInstanceOf(PDOException::class, $caught);
            self::assertSame(self::FK_CHILD_MISSING_PARENT, $caught->errorInfo[1] ?? null);
        }

        self::assertTrue(self::$pdo->inTransaction());
        self::assertSame(0, self::rowCount('maa_product_products'));
        self::$pdo->commit();
        self::assertSame(0, self::rowCount('maa_product_products', "code = 'P-STATEMENT'"));
        self::assertSame(0, self::rowCount('maa_product_variants'));
    }

    public function testADuplicateCodeInsideAHostTransactionKeepsTheHostsEarlierWork(): void
    {
        $service = $this->productManagementService();

        self::$pdo->beginTransaction();
        $service->create(new CreateProductCommand('P-DUP'));
        try {
            $service->create(new CreateProductCommand('p-dup'));
            self::fail('A duplicate code must conflict.');
        } catch (ProductCodeAlreadyExistsException) {
        }
        self::assertTrue(self::$pdo->inTransaction());
        self::$pdo->commit();

        self::assertSame(1, self::rowCount('maa_product_products'));
        self::assertSame(1, self::rowCount('maa_product_variants'));
        self::assertNoProductWithoutItsVariant();
    }

    public function testTheFactoryBuiltServiceIsAtomicInsideAHostTransactionOnARealDatabaseFailure(): void
    {
        self::$pdo->exec(
            'CREATE TRIGGER `' . self::FAILING_TRIGGER . '` BEFORE INSERT ON `maa_product_variants` '
            . "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced Variant failure'",
        );

        try {
            $service = $this->productManagementService();

            self::$pdo->beginTransaction();
            try {
                $service->create(new CreateProductCommand('P-TRIGGER'));
                self::fail('The Variant failure must propagate.');
            } catch (Throwable $caught) {
                self::assertInstanceOf(PDOException::class, $caught);
                self::assertStringContainsString('forced Variant failure', $caught->getMessage());
            }

            self::assertTrue(self::$pdo->inTransaction());
            self::assertSame(0, self::rowCount('maa_product_products'));
            self::$pdo->commit();
        } finally {
            if (self::$pdo->inTransaction()) {
                self::$pdo->rollBack();
            }
            self::$pdo->exec('DROP TRIGGER IF EXISTS `' . self::FAILING_TRIGGER . '`');
        }

        self::assertSame(0, self::rowCount('maa_product_products', "code = 'P-TRIGGER'"));
        self::assertSame(0, self::rowCount('maa_product_variants'));
    }

    public function testTheFactoryBuiltServiceOwnsAndRollsBackItsOwnTransactionOnARealDatabaseFailure(): void
    {
        self::$pdo->exec(
            'CREATE TRIGGER `' . self::FAILING_TRIGGER . '` BEFORE INSERT ON `maa_product_variants` '
            . "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced Variant failure'",
        );

        try {
            try {
                $this->productManagementService()->create(new CreateProductCommand('P-OWNED-TRIGGER'));
                self::fail('The Variant failure must propagate.');
            } catch (Throwable $caught) {
                self::assertInstanceOf(PDOException::class, $caught);
            }
            self::assertFalse(self::$pdo->inTransaction());
        } finally {
            self::$pdo->exec('DROP TRIGGER IF EXISTS `' . self::FAILING_TRIGGER . '`');
        }

        self::assertSame(0, self::rowCount('maa_product_products'));
    }

    private function serviceWithVariants(VariantCommandRepositoryInterface $variants): ProductManagementService
    {
        return new ProductManagementService(
            new PdoProductCommandRepository(self::$pdo),
            $variants,
            new PdoProductQueryReader(self::$pdo, new SystemClock(new DateTimeZone('UTC'))),
            new PdoProductConfigurationRepository(self::$pdo, new ScopedOrderingManager()),
            new PdoSavepointTransactionRunner(self::$pdo),
            new SystemClock(new DateTimeZone('UTC')),
        );
    }

    private function variantsFailingBeforeTheWrite(RuntimeException $failure): VariantCommandRepositoryInterface
    {
        return new class ($failure) implements VariantCommandRepositoryInterface {
            use UnusedVariantLifecycleWrites;

            public function __construct(private RuntimeException $failure) {}

            public function createEmptyComposition(int $productId, DateTimeImmutable $occurredAt): int
            {
                throw $this->failure;
            }
        };
    }

    private static function assertNoProductWithoutItsVariant(): void
    {
        self::assertSame(
            0,
            self::rowCount(
                'maa_product_products',
                'NOT EXISTS (SELECT 1 FROM maa_product_variants v WHERE v.product_id = maa_product_products.id)',
            ),
            'A committed Product must always have its Variant.',
        );
    }
}
