<?php

declare(strict_types=1);

namespace Maatify\Product\Tests\Integration\Runtime;

use DateTimeImmutable;
use DateTimeZone;
use Maatify\PhpPersistence\Ordering\Repository\ScopedOrderingManager;
use Maatify\PhpPersistence\Transaction\Repository\PdoSavepointTransactionRunner;
use Maatify\Product\Common\Enum\ProductDeletedStateEnum;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;
use Maatify\Product\Factory\ProductFactory;
use Maatify\Product\Product\Enum\ProductStatusEnum;
use Maatify\Product\Product\Exception\ProductCodeAlreadyExistsException;
use Maatify\Product\Product\Exception\ProductNotFoundException;
use Maatify\Product\Product\Management\Command\CreateProductCommand;
use Maatify\Product\Product\Management\Service\ProductManagementService;
use Maatify\Product\Product\Repository\Pdo\PdoProductCommandRepository;
use Maatify\Product\Product\Repository\Pdo\PdoProductConfigurationRepository;
use Maatify\Product\Product\Repository\Pdo\PdoProductQueryReader;
use Maatify\Product\Tests\Support\UnusedVariantLifecycleWrites;
use Maatify\Product\Variant\Repository\VariantCommandRepositoryInterface;
use Maatify\Product\Variant\ValueObject\CompositionFingerprint;
use Maatify\SharedCommon\Infrastructure\SystemClock;
use PDO;
use RuntimeException;

final class ProductCreationTest extends RuntimeTestCase
{
    public function testCreatesAnInactiveProductWithItsSingleActiveEmptyVariant(): void
    {
        $service = $this->productManagementService();

        $created = $service->create(new CreateProductCommand('SIMPLE-1'));

        $product = $service->getByIdForManagement($created->productId);
        self::assertSame('SIMPLE-1', $product->code);
        self::assertSame(ProductStatusEnum::INACTIVE, $product->status);
        self::assertNull($product->deletedAt);
        self::assertSame('UTC', $product->createdAt->getTimezone()->getName());
        self::assertEquals($product->createdAt, $product->updatedAt);

        self::assertSame(1, self::rowCount('maa_product_variants'));
        $statement = self::$pdo->query(
            'SELECT id, product_id, composition_fingerprint, sku, barcode, status, display_order, deleted_at FROM maa_product_variants',
        );
        self::assertNotFalse($statement);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertEquals($created->variantId, $row['id']);
        self::assertEquals($created->productId, $row['product_id']);
        self::assertSame(CompositionFingerprint::forEmptyComposition()->value, $row['composition_fingerprint']);
        self::assertNull($row['sku']);
        self::assertNull($row['barcode']);
        self::assertSame('active', $row['status']);
        self::assertEquals(1, $row['display_order']);
        self::assertNull($row['deleted_at']);
        self::assertSame(0, self::rowCount('maa_product_options'));
        self::assertSame(0, self::rowCount('maa_product_variant_option_values'));
    }

    public function testEachProductGetsItsOwnVariantIdentity(): void
    {
        $service = $this->productManagementService();

        $first = $service->create(new CreateProductCommand('A'));
        $second = $service->create(new CreateProductCommand('B'));

        self::assertNotSame($first->variantId, $second->variantId);
        self::assertSame(2, self::rowCount('maa_product_variants'));
        self::assertSame(2, self::rowCount('maa_product_variants', 'display_order = 1'));
    }

    public function testDuplicateCodeConflictsCaseInsensitivelyAndLeavesNothingBehind(): void
    {
        $service = $this->productManagementService();
        $service->create(new CreateProductCommand('P-1'));

        foreach (['P-1', 'p-1'] as $code) {
            try {
                $service->create(new CreateProductCommand($code));
                self::fail('A duplicate code must conflict: ' . $code);
            } catch (ProductCodeAlreadyExistsException $e) {
                self::assertInstanceOf(\PDOException::class, $e->getPrevious());
            }
        }

        self::assertSame(1, self::rowCount('maa_product_products'));
        self::assertSame(1, self::rowCount('maa_product_variants'));
    }

    public function testASoftDeletedProductKeepsItsCodeReservedAndIsNeverRestoredByCreate(): void
    {
        $service = $this->productManagementService();
        $created = $service->create(new CreateProductCommand('P-DEL'));
        $this->execute('UPDATE maa_product_products SET deleted_at = :t WHERE id = :i', ['t' => '2026-01-01 00:00:00', 'i' => $created->productId]);

        foreach (['P-DEL', 'p-del'] as $code) {
            try {
                $service->create(new CreateProductCommand($code));
                self::fail('Create must conflict with the soft-deleted identity: ' . $code);
            } catch (ProductCodeAlreadyExistsException $conflict) {
                self::assertStringContainsString($code, $conflict->getMessage(), 'The conflict identifies the colliding code.');
                self::assertInstanceOf(\PDOException::class, $conflict->getPrevious());
            }
        }

        self::assertSame(1, self::rowCount('maa_product_products'), 'No second row is inserted.');
        self::assertSame(1, self::rowCount('maa_product_variants'), 'No Variant is created for the rejected attempts.');
        self::assertSame(1, self::rowCount('maa_product_products', 'deleted_at IS NOT NULL'), 'Create never restores the soft-deleted identity.');
    }

    public function testManagementReadReturnsSoftDeletedProductsOnlyWhenAskedToAndHydratesInTheHostTimezone(): void
    {
        $service = $this->productManagementService();
        $created = $service->create(new CreateProductCommand('P-DEL'));
        $this->execute('UPDATE maa_product_products SET deleted_at = :t WHERE id = :i', ['t' => '2026-02-03 04:05:06', 'i' => $created->productId]);

        $product = $service->getByIdForManagement($created->productId, ProductDeletedStateEnum::INCLUDE_DELETED);

        self::assertNotNull($product->deletedAt);
        self::assertSame('2026-02-03 04:05:06', $product->deletedAt->format('Y-m-d H:i:s'));
        self::assertSame('UTC', $product->deletedAt->getTimezone()->getName());
        self::assertSame($product->id, $service->getByIdForManagement($created->productId, ProductDeletedStateEnum::DELETED_ONLY)->id);

        $this->expectException(ProductNotFoundException::class);
        $service->getByIdForManagement($created->productId);
    }

    public function testManagementReadExcludesDeletedOnlyWhenNotDeleted(): void
    {
        $service = $this->productManagementService();
        $created = $service->create(new CreateProductCommand('P-LIVE'));

        self::assertSame($created->productId, $service->getByIdForManagement($created->productId)->id);
        self::assertSame($created->productId, $service->getByIdForManagement($created->productId, ProductDeletedStateEnum::INCLUDE_DELETED)->id);

        $this->expectException(ProductNotFoundException::class);
        $service->getByIdForManagement($created->productId, ProductDeletedStateEnum::DELETED_ONLY);
    }

    public function testConsumerReadRequiresAnActiveNonDeletedProduct(): void
    {
        $service = $this->productManagementService();
        $consumer = $this->productConsumerService();
        $created = $service->create(new CreateProductCommand('P-VIS'));

        try {
            $consumer->getById($created->productId);
            self::fail('A new inactive Product is not consumer-visible.');
        } catch (ProductNotFoundException) {
        }

        $this->execute("UPDATE maa_product_products SET status = 'active' WHERE id = :i", ['i' => $created->productId]);
        self::assertSame($created->productId, $consumer->getById($created->productId)->id);

        $this->execute('UPDATE maa_product_products SET deleted_at = :t WHERE id = :i', ['t' => '2026-02-03 04:05:06', 'i' => $created->productId]);
        $this->expectException(ProductNotFoundException::class);
        $consumer->getById($created->productId);
    }

    public function testReadsThrowNotFoundForAnUnknownIdentity(): void
    {
        $service = $this->productManagementService();
        $consumer = $this->productConsumerService();

        foreach ([static fn() => $consumer->getById(999999), static fn() => $service->getByIdForManagement(999999, ProductDeletedStateEnum::INCLUDE_DELETED)] as $read) {
            try {
                $read();
                self::fail('An unknown identity must not be found.');
            } catch (ProductNotFoundException) {
            }
        }
        $this->addToAssertionCount(1);
    }

    public function testAFailureAfterTheProductWriteRollsTheWholeOperationBack(): void
    {
        $failure = new RuntimeException('variant write failed');
        $failingVariants = new class ($failure) implements VariantCommandRepositoryInterface {
            use UnusedVariantLifecycleWrites;

            public function __construct(private RuntimeException $failure) {}

            public function createEmptyComposition(int $productId, DateTimeImmutable $occurredAt): int
            {
                throw $this->failure;
            }
        };
        $service = new ProductManagementService(
            new PdoProductCommandRepository(self::$pdo),
            $failingVariants,
            new PdoProductQueryReader(self::$pdo, new SystemClock(new DateTimeZone('UTC'))),
            new PdoProductConfigurationRepository(self::$pdo, new ScopedOrderingManager()),
            new PdoSavepointTransactionRunner(self::$pdo),
            new SystemClock(new DateTimeZone('UTC')),
        );

        try {
            $service->create(new CreateProductCommand('P-ROLLBACK'));
            self::fail('The failure must propagate.');
        } catch (RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }

        self::assertSame(0, self::rowCount('maa_product_products'), 'The Product insert must be rolled back.');
        self::assertFalse(self::$pdo->inTransaction());
    }

    public function testJoinsACallerOwnedTransactionAndLeavesCommitToTheCaller(): void
    {
        $service = $this->productManagementService();

        self::$pdo->beginTransaction();
        try {
            $service->create(new CreateProductCommand('P-OUTER-ROLLBACK'));
            self::assertTrue(self::$pdo->inTransaction(), 'The service must not commit the caller transaction.');
        } finally {
            self::$pdo->rollBack();
        }
        self::assertSame(0, self::rowCount('maa_product_products'));
        self::assertSame(0, self::rowCount('maa_product_variants'));

        self::$pdo->beginTransaction();
        $service->create(new CreateProductCommand('P-OUTER-COMMIT'));
        self::$pdo->commit();
        self::assertSame(1, self::rowCount('maa_product_products'));
        self::assertSame(1, self::rowCount('maa_product_variants'));
    }

    /**
     * The Variants that `reconfigure()` creates take the next positions after the highest live
     * position of the Product scope, in request order; a soft-deleted Variant is outside the live
     * order, so its old position is reused rather than skipped. A constant position, a count of all
     * rows, or a maximum over soft-deleted history would each give different positions.
     */
    public function testVariantOrderIsAllocatedThroughTheSharedOrderingApi(): void
    {
        $created = $this->namedProduct('P-ORDER');
        $color = $this->preparedOption($created->productId, 'color', 'Color');
        $red = $this->preparedValue($color, 'red', 'Red');
        $blue = $this->preparedValue($color, 'blue', 'Blue');
        $green = $this->preparedValue($color, 'green', 'Green');
        $black = $this->preparedValue($color, 'black', 'Black');
        $products = $this->productManagementService();

        [$redVariant, $blueVariant] = $products->reconfigure($this->reconfiguration($created->productId, [$color => [$red, $blue]], [[[[$color, $red]]], [[[$color, $blue]]]]))->createdVariantIds;
        self::assertSame([$created->variantId => 1, $redVariant => 2, $blueVariant => 3], $this->variantPositions($created->productId));

        $products->reconfigure($this->reconfiguration($created->productId, [$color => [$red]], [[[[$color, $red]], $redVariant]]));
        $this->variantManagementService()->softDelete($blueVariant);

        [$greenVariant, $blackVariant] = $products->reconfigure($this->reconfiguration(
            $created->productId,
            [$color => [$red, $green, $black]],
            [[[[$color, $red]], $redVariant], [[[$color, $green]]], [[[$color, $black]]]],
        ))->createdVariantIds;

        self::assertSame(
            [$created->variantId => 1, $redVariant => 2, $greenVariant => 3, $blackVariant => 4],
            $this->variantPositions($created->productId),
            'New Variants follow the highest live position, in request order, ignoring the soft-deleted Variant.',
        );
        self::assertSame(3, (int) self::scalar('SELECT display_order FROM maa_product_variants WHERE id = :i', ['i' => $blueVariant]), 'Soft deletion does not compact positions.');
    }

    /** @return array<int, int> live Variant id => display_order */
    private function variantPositions(int $productId): array
    {
        $statement = self::$pdo->prepare('SELECT id, display_order FROM maa_product_variants WHERE product_id = :p AND deleted_at IS NULL ORDER BY display_order, id');
        $statement->execute(['p' => $productId]);
        $positions = [];
        foreach ($statement->fetchAll(PDO::FETCH_NUM) as $row) {
            self::assertIsArray($row);
            [$id, $position] = $row;
            self::assertIsInt($id);
            self::assertIsInt($position);
            $positions[$id] = $position;
        }

        return $positions;
    }

    public function testTheFactoryRejectsAConnectionThatDoesNotThrow(): void
    {
        $silent = self::connect(self::$database);
        $silent->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);

        $this->expectException(ProductInvalidArgumentException::class);
        ProductFactory::createProductManagementService($silent, new SystemClock(new DateTimeZone('UTC')));
    }
}
