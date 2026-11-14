<?php

declare(strict_types=1);

namespace Maatify\Product\Tests\Integration\Runtime;

use Maatify\Product\Content\Management\Command\CreateProductContentCommand;
use Maatify\Product\ImageAssignment\Management\Command\CreateImageAssignmentCommand;
use Maatify\Product\Product\Management\Command\CreateProductCommand;
use Maatify\Product\Variant\Enum\VariantStatusEnum;
use Maatify\Product\Variant\Exception\VariantSkuAlreadyExistsException;
use Maatify\Product\Variant\Management\Command\UpdateVariantIdentifiersCommand;
use Maatify\Product\Variant\ValueObject\VariantIdentifierChange;
use PDO;
use PDOException;
use Throwable;

/**
 * Savepoint evidence inside a Host transaction for Content create and soft delete, `setDefault()`,
 * `reconfigure()`, Variant `updateStatus()`, and `updateIdentifiers()`: a real MySQL failure inside the operation
 * propagates, leaves none of the operation's writes in the Host transaction, leaves that
 * transaction active, and keeps the Host's earlier work, which the Host then commits.
 */
final class HostTransactionSavepointTest extends RuntimeTestCase
{
    private const string FAILING_TRIGGER = 'trg_product_it_savepoint_failure';
    private const string FAILURE = 'forced operation failure';

    protected function cleanUp(): void
    {
        self::$pdo->exec('DROP TRIGGER IF EXISTS `' . self::FAILING_TRIGGER . '`');
    }

    public function testAFailedContentCreateLeavesNoWriteAndKeepsTheHostWork(): void
    {
        $productId = $this->productManagementService()->create(new CreateProductCommand('CONTENT-CREATE'))->productId;
        $this->failingTrigger('BEFORE INSERT ON `maa_product_product_contents`', 'TRUE');

        $this->assertFailureInsideHostTransaction(
            'maa_product_product_contents',
            fn() => $this->productContentManagementService()->create(new CreateProductContentCommand($productId, 'en', 'Name')),
        );

        self::assertSame(0, self::rowCount('maa_product_product_contents'));
    }

    public function testAFailedContentSoftDeleteLeavesNoWriteAndKeepsTheHostWork(): void
    {
        $productId = $this->productManagementService()->create(new CreateProductCommand('CONTENT-DELETE'))->productId;
        $contentId = $this->productContentManagementService()->create(new CreateProductContentCommand($productId, null, 'Name'))->contentId;
        $this->failingTrigger('BEFORE UPDATE ON `maa_product_product_contents`', 'NEW.`deleted_at` IS NOT NULL');

        $this->assertFailureInsideHostTransaction(
            'maa_product_product_contents',
            fn() => $this->productContentManagementService(self::laterClock())->softDelete($contentId),
        );

        self::assertSame(1, self::rowCount('maa_product_product_contents', 'deleted_at IS NULL AND id = ' . $contentId));
    }

    /**
     * The failure comes after the operation's first write (the previous default is cleared), so only
     * the rollback to the operation's savepoint can restore it.
     */
    public function testAFailedSetDefaultRestoresThePreviousDefaultItAlreadyClearedAndKeepsTheHostWork(): void
    {
        $productId = $this->productManagementService()->create(new CreateProductCommand('DEFAULT'))->productId;
        $assignments = $this->imageAssignmentManagementService();
        $current = $assignments->create(new CreateImageAssignmentCommand($productId, null, 1))->assignmentId;
        $assignments->setDefault($current);
        $next = $assignments->create(new CreateImageAssignmentCommand($productId, null, 2))->assignmentId;
        $this->failingTrigger('BEFORE UPDATE ON `maa_product_image_assignments`', 'NEW.`is_default` = 1 AND OLD.`is_default` = 0');

        $this->assertFailureInsideHostTransaction(
            'maa_product_image_assignments',
            fn() => $this->imageAssignmentManagementService(self::laterClock())->setDefault($next),
        );

        self::assertSame(1, self::rowCount('maa_product_image_assignments', 'is_default = 1 AND id = ' . $current), 'The previous default is still the default.');
        self::assertSame(1, self::rowCount('maa_product_image_assignments', 'is_default = 1'));
    }

    /**
     * The failure comes on the insert of the new Variant's composition pair, after the operation's
     * first writes (the Option and Value activations, the deactivation of the empty Variant, and the
     * new Variant row), so only the rollback to the operation's savepoint can undo them. The identity
     * the rolled-back Variant row consumed proves that row was written.
     */
    public function testAFailedReconfigurationUndoesItsPartialWritesAndKeepsTheHostWork(): void
    {
        $created = $this->namedProduct('RECONFIGURE');
        $color = $this->preparedOption($created->productId, 'color', 'Color');
        $red = $this->preparedValue($color, 'red', 'Red');
        $lastVariant = (int) self::scalar('SELECT MAX(id) FROM maa_product_variants');
        $this->failingTrigger('BEFORE INSERT ON `maa_product_variant_option_values`', 'TRUE');

        $this->assertFailureInsideHostTransaction(
            'maa_product_variants',
            fn() => $this->productManagementService(self::laterClock())->reconfigure($this->reconfiguration($created->productId, [$color => [$red]], [[[[$color, $red]]]])),
            'maa_product_options',
            'maa_product_option_values',
            'maa_product_variant_option_values',
        );

        self::assertSame(1, self::rowCount('maa_product_options', "status = 'inactive' AND id = " . $color));
        self::assertSame(1, self::rowCount('maa_product_option_values', "status = 'inactive' AND id = " . $red));
        self::assertSame(1, self::rowCount('maa_product_variants', "status = 'active' AND id = " . $created->variantId));
        self::assertSame(0, self::rowCount('maa_product_variant_option_values'));
        self::assertSame(
            $lastVariant + 3,
            $this->productManagementService()->create(new CreateProductCommand('AFTER'))->variantId,
            'The Host work took the next Variant identity and the rolled-back Variant row the one after it.',
        );
    }

    public function testAFailedVariantStatusChangeLeavesNoWriteAndKeepsTheHostWork(): void
    {
        $created = $this->namedProduct('VARIANT-STATUS');
        $color = $this->preparedOption($created->productId, 'color', 'Color');
        $red = $this->preparedValue($color, 'red', 'Red');
        $blue = $this->preparedValue($color, 'blue', 'Blue');
        $size = $this->preparedOption($created->productId, 'size', 'Size');
        $m = $this->preparedValue($size, 'm', 'M');
        $l = $this->preparedValue($size, 'l', 'L');
        [$redM] = $this->productManagementService()->reconfigure($this->reconfiguration(
            $created->productId,
            [$color => [$red, $blue], $size => [$m, $l]],
            [[[[$color, $red], [$size, $m]]], [[[$color, $red], [$size, $l]]], [[[$color, $blue], [$size, $m]]], [[[$color, $blue], [$size, $l]]]],
        ))->createdVariantIds;
        $this->failingTrigger('BEFORE UPDATE ON `maa_product_variants`', 'NEW.`status` <> OLD.`status`');

        $this->assertFailureInsideHostTransaction(
            'maa_product_variants',
            fn() => $this->variantManagementService(self::laterClock())->updateStatus($redM, VariantStatusEnum::INACTIVE),
        );

        self::assertSame(1, self::rowCount('maa_product_variants', "status = 'active' AND id = " . $redM));
    }

    /**
     * A real duplicate key (MySQL driver error 1062) inside the Host transaction: the typed conflict
     * propagates, and the Host's earlier identifier change in the same transaction survives.
     */
    public function testAConflictingIdentifierChangeLeavesNoWriteAndKeepsTheHostsEarlierChange(): void
    {
        $holder = $this->productManagementService()->create(new CreateProductCommand('SKU-HOLDER'))->variantId;
        $hostTarget = $this->productManagementService()->create(new CreateProductCommand('SKU-HOST'))->variantId;
        $failing = $this->productManagementService()->create(new CreateProductCommand('SKU-FAILING'))->variantId;
        $variants = $this->variantManagementService();
        $variants->updateIdentifiers(new UpdateVariantIdentifiersCommand($holder, VariantIdentifierChange::set('TAKEN'), VariantIdentifierChange::unchanged()));

        self::$pdo->beginTransaction();
        $variants->updateIdentifiers(new UpdateVariantIdentifiersCommand($hostTarget, VariantIdentifierChange::set('HOST-SKU'), VariantIdentifierChange::set('0001')));
        $before = self::rows('maa_product_variants');
        $this->assertThrows(
            VariantSkuAlreadyExistsException::class,
            fn() => $this->variantManagementService(self::laterClock())->updateIdentifiers(new UpdateVariantIdentifiersCommand($failing, VariantIdentifierChange::set('taken'), VariantIdentifierChange::set('0002'))),
        );
        self::assertTrue(self::$pdo->inTransaction(), 'The package neither commits nor rolls back the Host transaction.');
        self::assertSame($before, self::rows('maa_product_variants'), 'The failed operation left no write in the Host transaction.');
        self::$pdo->commit();

        self::assertSame(1, self::rowCount('maa_product_variants', "sku = 'HOST-SKU' AND barcode = '0001' AND id = " . $hostTarget), 'The Host work before the failure is committed.');
        self::assertSame(1, self::rowCount('maa_product_variants', 'sku IS NULL AND barcode IS NULL AND id = ' . $failing));
    }

    /**
     * Opens a Host transaction, does Host work through the package (a new Product), runs the failing
     * call, and proves: the original MySQL failure propagates, every given table is exactly as before
     * the call, the transaction is still active, and the Host's commit persists its own work.
     */
    private function assertFailureInsideHostTransaction(string $table, callable $call, string ...$moreTables): void
    {
        self::$pdo->beginTransaction();
        $hostWork = $this->productManagementService()->create(new CreateProductCommand('HOST-WORK'))->productId;
        $before = array_map(self::rows(...), [$table, ...$moreTables]);

        try {
            $call();
            self::fail('The forced failure must propagate.');
        } catch (Throwable $caught) {
            self::assertInstanceOf(PDOException::class, $caught, 'The MySQL failure propagates unchanged.');
            self::assertStringContainsString(self::FAILURE, $caught->getMessage());
        }

        self::assertTrue(self::$pdo->inTransaction(), 'The package neither commits nor rolls back the Host transaction.');
        self::assertSame($before, array_map(self::rows(...), [$table, ...$moreTables]), 'The failed operation left no write in the Host transaction.');
        self::$pdo->commit();

        self::assertSame(1, self::rowCount('maa_product_products', 'id = ' . $hostWork), 'The Host work before the failure is committed.');
        self::assertSame(1, self::rowCount('maa_product_variants', 'product_id = ' . $hostWork));
    }

    private function failingTrigger(string $timing, string $condition): void
    {
        self::$pdo->exec(
            'CREATE TRIGGER `' . self::FAILING_TRIGGER . '` ' . $timing . ' FOR EACH ROW BEGIN IF ' . $condition
            . " THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '" . self::FAILURE . "'; END IF; END",
        );
    }

    /** @return list<array<string, mixed>> */
    private static function rows(string $table): array
    {
        $statement = self::$pdo->query('SELECT * FROM `' . $table . '` ORDER BY `id`');
        self::assertNotFalse($statement);

        /** @var list<array<string, mixed>> */
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
