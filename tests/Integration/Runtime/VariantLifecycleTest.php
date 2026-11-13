<?php

declare(strict_types=1);

namespace Maatify\Product\Tests\Integration\Runtime;

use Maatify\Product\Common\Enum\ProductDeletedStateEnum;
use Maatify\Product\Common\Exception\ProductBusinessRuleException;
use Maatify\Product\Variant\Enum\VariantStatusEnum;
use Maatify\Product\Variant\Exception\VariantNotFoundException;

/**
 * Variant status, soft delete, and restore: every change is validated against the whole Product
 * configuration under the Product lock, so the last valid active Variant stays, a historical Variant
 * is reactivated only when its composition is valid today, and restore preserves the saved status.
 */
final class VariantLifecycleTest extends RuntimeTestCase
{
    public function testTheLastValidActiveVariantCanNeitherBeDeactivatedNorDeleted(): void
    {
        $created = $this->namedProduct('SIMPLE');
        $service = $this->variantManagementService();

        $this->assertThrows(ProductBusinessRuleException::class, fn() => $service->updateStatus($created->variantId, VariantStatusEnum::INACTIVE));
        $this->assertThrows(ProductBusinessRuleException::class, fn() => $service->softDelete($created->variantId));
        $this->assertThrows(ProductBusinessRuleException::class, fn() => $service->restore($created->variantId), 'A live row cannot be restored.');
        self::assertSame(VariantStatusEnum::ACTIVE, $service->getByIdForManagement($created->variantId)->status);

        $this->assertNoWrite(
            'maa_product_variants',
            fn() => $this->variantManagementService(self::laterClock())->updateStatus($created->variantId, VariantStatusEnum::ACTIVE),
            'A same-status request writes nothing.',
        );
    }

    public function testAHistoricalVariantIsReactivatedOnlyWhenItsCompositionIsValidToday(): void
    {
        $created = $this->namedProduct('CFG');
        $color = $this->preparedOption($created->productId, 'color', 'Color');
        $size = $this->preparedOption($created->productId, 'size', 'Size');
        $red = $this->preparedValue($color, 'red', 'Red');
        $blue = $this->preparedValue($color, 'blue', 'Blue');
        $m = $this->preparedValue($size, 'm', 'M');
        $l = $this->preparedValue($size, 'l', 'L');
        $products = $this->productManagementService();
        $service = $this->variantManagementService();

        [$redM, $blueL, $redL] = $products->reconfigure($this->reconfiguration(
            $created->productId,
            [$color => [$red, $blue], $size => [$m, $l]],
            [[[[$color, $red], [$size, $m]]], [[[$color, $blue], [$size, $l]]], [[[$color, $red], [$size, $l]]]],
        ))->createdVariantIds;
        $products->reconfigure($this->reconfiguration(
            $created->productId,
            [$color => [$red, $blue], $size => [$m, $l]],
            [[[[$color, $red], [$size, $m]], $redM], [[[$color, $blue], [$size, $l]], $blueL]],
        ));
        self::assertSame(VariantStatusEnum::INACTIVE, $service->getByIdForManagement($redL)->status);

        $service->updateStatus($redL, VariantStatusEnum::ACTIVE);
        self::assertSame(VariantStatusEnum::ACTIVE, $service->getByIdForManagement($redL)->status, 'red-L is valid against the current configuration.');
        $service->updateStatus($redL, VariantStatusEnum::INACTIVE);

        // The configuration drops the size Option; red-L now selects an inactive Option.
        $products->reconfigure($this->reconfiguration($created->productId, [$color => [$red, $blue]], [[[[$color, $red]]], [[[$color, $blue]]]]));
        $failure = $this->assertThrows(ProductBusinessRuleException::class, fn() => $service->updateStatus($redL, VariantStatusEnum::ACTIVE));
        self::assertStringContainsString('[' . $size . ']', $failure->getMessage());
        self::assertSame(VariantStatusEnum::INACTIVE, $service->getByIdForManagement($redL)->status);
        $this->assertThrows(ProductBusinessRuleException::class, fn() => $service->updateStatus($redM, VariantStatusEnum::ACTIVE), 'red-M is historical too.');
    }

    public function testSoftDeleteAndRestoreKeepTheConfigurationValidAndTheSavedStatus(): void
    {
        $created = $this->namedProduct('CFG');
        $color = $this->preparedOption($created->productId, 'color', 'Color');
        $size = $this->preparedOption($created->productId, 'size', 'Size');
        $red = $this->preparedValue($color, 'red', 'Red');
        $blue = $this->preparedValue($color, 'blue', 'Blue');
        $m = $this->preparedValue($size, 'm', 'M');
        $l = $this->preparedValue($size, 'l', 'L');
        $products = $this->productManagementService();
        $service = $this->variantManagementService();
        $consumer = $this->variantConsumerService();
        [$redM, $redL, $blueM, $blueL] = $products->reconfigure($this->reconfiguration(
            $created->productId,
            [$color => [$red, $blue], $size => [$m, $l]],
            [[[[$color, $red], [$size, $m]]], [[[$color, $red], [$size, $l]]], [[[$color, $blue], [$size, $m]]], [[[$color, $blue], [$size, $l]]]],
        ))->createdVariantIds;

        $service->softDelete($redL);
        $this->assertThrows(VariantNotFoundException::class, fn() => $service->getByIdForManagement($redL));
        self::assertSame(VariantStatusEnum::ACTIVE, $service->getByIdForManagement($redL, ProductDeletedStateEnum::DELETED_ONLY)->status, 'Deletion keeps the status.');
        $this->assertThrows(ProductBusinessRuleException::class, fn() => $service->softDelete($redL), 'Re-deleting is rejected.');
        $this->assertThrows(ProductBusinessRuleException::class, fn() => $service->updateStatus($redL, VariantStatusEnum::INACTIVE), 'A status change on a deleted row is rejected.');

        $this->assertThrows(ProductBusinessRuleException::class, fn() => $service->softDelete($blueL), 'Deleting blue-L would leave L unrepresented.');
        $this->assertThrows(ProductBusinessRuleException::class, fn() => $service->updateStatus($blueL, VariantStatusEnum::INACTIVE));
        self::assertSame(VariantStatusEnum::ACTIVE, $service->getByIdForManagement($blueL)->status);

        $service->restore($redL);
        self::assertSame(VariantStatusEnum::ACTIVE, $service->getByIdForManagement($redL)->status, 'Restore preserves the saved status.');
        $this->activateProduct($created->productId);
        self::assertCount(4, $consumer->listByProductId($created->productId), 'All four Variants are visible again.');

        // A deleted Variant whose composition no longer matches the configuration cannot be restored.
        $service->softDelete($redL);
        $products->reconfigure($this->reconfiguration($created->productId, [$color => [$red, $blue]], [[[[$color, $red]]], [[[$color, $blue]]]]));
        $this->assertThrows(ProductBusinessRuleException::class, fn() => $service->restore($redL));
        self::assertNotNull($service->getByIdForManagement($redL, ProductDeletedStateEnum::DELETED_ONLY)->deletedAt);
        self::assertSame($blueM, $service->getByIdForManagement($blueM)->id, 'Sanity: untouched identities remain.');
        self::assertSame($redM, $service->getByIdForManagement($redM)->id);
    }

    public function testUnknownIdentitiesAreNotFound(): void
    {
        $service = $this->variantManagementService();
        $this->assertThrows(VariantNotFoundException::class, fn() => $service->updateStatus(999999, VariantStatusEnum::ACTIVE));
        $this->assertThrows(VariantNotFoundException::class, fn() => $service->softDelete(999999));
        $this->assertThrows(VariantNotFoundException::class, fn() => $service->restore(999999));
    }
}
