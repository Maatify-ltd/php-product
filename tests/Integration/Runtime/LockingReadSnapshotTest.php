<?php

declare(strict_types=1);

namespace Maatify\Product\Tests\Integration\Runtime;

use Maatify\Product\Common\Exception\ProductBusinessRuleException;
use Maatify\Product\Content\Management\Command\CreateProductContentCommand;
use Maatify\Product\Factory\ProductFactory;
use Maatify\Product\Product\Enum\ProductStatusEnum;
use Maatify\Product\Product\Management\Command\CreateProductCommand;
use Maatify\Product\Variant\Enum\VariantStatusEnum;

/**
 * The configuration graph is read with locking reads only, so a mutation validates the latest
 * committed state even inside a Host transaction whose consistent-read snapshot is older.
 *
 * Connection A (the Host) opens a transaction and establishes its snapshot with a plain read;
 * connection B then changes the state through the package and commits; A still sees the old state
 * with a plain read, yet the package call on A is rejected because the latest state forbids it.
 * Validated against A's snapshot, the same call would have been accepted.
 */
final class LockingReadSnapshotTest extends RuntimeTestCase
{
    public function testActivationInsideAnOlderSnapshotSeesTheNameDeletedAndCommittedElsewhere(): void
    {
        $productId = $this->productManagementService()->create(new CreateProductCommand('SNAPSHOT'))->productId;
        $contentId = $this->productContentManagementService()->create(new CreateProductContentCommand($productId, null, 'Only name'))->contentId;
        $other = self::connect(self::$database);

        self::$pdo->beginTransaction();
        self::assertSame(1, self::rowCount('maa_product_product_contents', 'deleted_at IS NULL'), 'A establishes its snapshot with the name live.');

        ProductFactory::createProductContentManagementService($other, self::clock())->softDelete($contentId);
        self::assertSame(1, self::rowCount('maa_product_product_contents', 'deleted_at IS NULL'), 'A plain read on A still sees its older snapshot.');

        $this->assertThrows(
            ProductBusinessRuleException::class,
            fn() => $this->productManagementService()->updateStatus($productId, ProductStatusEnum::ACTIVE),
            'Activation is validated on the latest committed state, where the Product has no name (INV-06).',
        );
        self::assertTrue(self::$pdo->inTransaction());
        self::$pdo->commit();

        self::assertSame(1, self::rowCount('maa_product_products', "status = 'inactive' AND id = " . $productId));
    }

    public function testVariantDeactivationInsideAnOlderSnapshotSeesTheSiblingDeactivatedElsewhere(): void
    {
        $created = $this->namedProduct('SNAPSHOT-VARIANTS');
        $color = $this->preparedOption($created->productId, 'color', 'Color');
        $red = $this->preparedValue($color, 'red', 'Red');
        $blue = $this->preparedValue($color, 'blue', 'Blue');
        $size = $this->preparedOption($created->productId, 'size', 'Size');
        $m = $this->preparedValue($size, 'm', 'M');
        $l = $this->preparedValue($size, 'l', 'L');
        [$redM, $redL] = $this->productManagementService()->reconfigure($this->reconfiguration(
            $created->productId,
            [$color => [$red, $blue], $size => [$m, $l]],
            [[[[$color, $red], [$size, $m]]], [[[$color, $red], [$size, $l]]], [[[$color, $blue], [$size, $m]]], [[[$color, $blue], [$size, $l]]]],
        ))->createdVariantIds;
        $other = self::connect(self::$database);

        self::$pdo->beginTransaction();
        self::assertSame(2, self::rowCount('maa_product_variants', "status = 'active' AND id IN (" . $redM . ', ' . $redL . ')'), 'A establishes its snapshot with both red Variants active.');

        ProductFactory::createVariantManagementService($other, self::clock())->updateStatus($redM, VariantStatusEnum::INACTIVE);
        self::assertSame(2, self::rowCount('maa_product_variants', "status = 'active' AND id IN (" . $redM . ', ' . $redL . ')'), 'A plain read on A still sees its older snapshot.');

        $this->assertThrows(
            ProductBusinessRuleException::class,
            fn() => $this->variantManagementService()->updateStatus($redL, VariantStatusEnum::INACTIVE),
            'Deactivation is validated on the latest committed state, where red would be left unrepresented.',
        );
        self::assertTrue(self::$pdo->inTransaction());
        self::$pdo->commit();

        self::assertSame(1, self::rowCount('maa_product_variants', "status = 'active' AND id = " . $redL));
    }
}
