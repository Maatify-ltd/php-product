<?php

declare(strict_types=1);

namespace Maatify\Product\Tests\Integration\Runtime;

use Maatify\Product\Common\Enum\ProductDeletedStateEnum;
use Maatify\Product\Common\Exception\ProductBusinessRuleException;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;
use Maatify\Product\Option\Management\Command\CreateOptionCommand;
use Maatify\Product\Product\Enum\ProductStatusEnum;
use Maatify\Product\Product\Exception\ProductNotFoundException;
use Maatify\Product\Variant\Enum\VariantStatusEnum;

/**
 * Product status, soft delete, and restore under the lifecycle contract:
 * a soft-deleted row accepts nothing but restore, restore preserves the saved status and revalidates,
 * a missing identity is not found, and inactivity alone blocks no management operation.
 */
final class ProductLifecycleTest extends RuntimeTestCase
{
    public function testActivationNeedsANameAndAValidConfigurationAndDeactivationNeedsNothing(): void
    {
        $service = $this->productManagementService();
        $consumer = $this->productConsumerService();
        $created = $service->create(new \Maatify\Product\Product\Management\Command\CreateProductCommand('NO-NAME'));

        $failure = $this->assertThrows(ProductBusinessRuleException::class, fn() => $service->updateStatus($created->productId, ProductStatusEnum::ACTIVE));
        self::assertStringContainsString('name', $failure->getMessage());
        self::assertSame(ProductStatusEnum::INACTIVE, $service->getByIdForManagement($created->productId)->status);

        $this->nameProduct($created->productId, 'Named');
        $service->updateStatus($created->productId, ProductStatusEnum::ACTIVE);
        self::assertSame(ProductStatusEnum::ACTIVE, $consumer->getById($created->productId)->status, 'An activated Product is consumer-visible.');
        $later = $this->productManagementService(self::laterClock());
        $this->assertNoWrite('maa_product_products', fn() => $later->updateStatus($created->productId, ProductStatusEnum::ACTIVE), 'A same-status request writes nothing.');

        $later->updateStatus($created->productId, ProductStatusEnum::INACTIVE);
        self::assertSame(self::LATER_TS, self::scalar('SELECT updated_at FROM maa_product_products WHERE id = :i', ['i' => $created->productId]), 'A real write carries the later instant.');
        $this->assertThrows(ProductNotFoundException::class, fn() => $consumer->getById($created->productId));

        // A broken graph (only possible through direct SQL) blocks activation but not deactivation.
        $this->execute("UPDATE maa_product_variants SET status = 'inactive' WHERE id = :i", ['i' => $created->variantId]);
        $invalid = $this->assertThrows(ProductBusinessRuleException::class, fn() => $service->updateStatus($created->productId, ProductStatusEnum::ACTIVE));
        self::assertStringContainsString('active Variant', $invalid->getMessage());
    }

    public function testSoftDeleteAndRestorePreserveTheSavedStatusAndRejectRepeats(): void
    {
        $service = $this->productManagementService();
        $consumer = $this->productConsumerService();
        $active = $this->namedProduct('ACTIVE');
        $this->activateProduct($active->productId);
        $inactive = $this->namedProduct('INACTIVE');

        $service->softDelete($active->productId);
        $service->softDelete($inactive->productId);
        $this->assertThrows(ProductNotFoundException::class, fn() => $service->getByIdForManagement($active->productId));
        self::assertSame(ProductStatusEnum::ACTIVE, $service->getByIdForManagement($active->productId, ProductDeletedStateEnum::DELETED_ONLY)->status, 'Deletion keeps the status.');

        $this->assertThrows(ProductBusinessRuleException::class, fn() => $service->softDelete($active->productId), 'Re-deleting is not a substitute for restore.');
        $this->assertThrows(ProductBusinessRuleException::class, fn() => $service->updateStatus($active->productId, ProductStatusEnum::INACTIVE), 'A status change on a soft-deleted row is rejected.');
        $this->assertThrows(ProductBusinessRuleException::class, fn() => $service->updateStatus($active->productId, ProductStatusEnum::ACTIVE));
        self::assertSame(1, self::rowCount('maa_product_variants', 'product_id = ' . $active->productId . " AND status = 'active' AND deleted_at IS NULL"), 'Deleting the Product changes no Variant.');

        $service->restore($active->productId);
        $service->restore($inactive->productId);
        self::assertSame(ProductStatusEnum::ACTIVE, $consumer->getById($active->productId)->status, 'Restore preserves active and the Product is visible again.');
        self::assertSame(ProductStatusEnum::INACTIVE, $service->getByIdForManagement($inactive->productId)->status, 'Restoring an inactive Product never activates it.');
        $this->assertThrows(ProductBusinessRuleException::class, fn() => $service->restore($active->productId), 'Restoring a live row is rejected.');
    }

    public function testRestoreRevalidatesTheInvariantsOfTheRestoredState(): void
    {
        $service = $this->productManagementService();
        $consumer = $this->productConsumerService();
        $created = $this->namedProduct('REVALIDATE');
        $this->activateProduct($created->productId);
        $service->softDelete($created->productId);

        // While deleted, the only name disappears (direct SQL: Content has no command yet); an active Product needs a name.
        $this->execute('UPDATE maa_product_product_contents SET deleted_at = :t WHERE product_id = :p', ['t' => self::SEED_TS, 'p' => $created->productId]);
        $this->assertThrows(ProductBusinessRuleException::class, fn() => $service->restore($created->productId));
        self::assertNotNull($service->getByIdForManagement($created->productId, ProductDeletedStateEnum::DELETED_ONLY)->deletedAt, 'A rejected restore leaves the row deleted.');

        $this->execute('UPDATE maa_product_product_contents SET deleted_at = NULL WHERE product_id = :p', ['p' => $created->productId]);
        $service->restore($created->productId);
        self::assertNull($consumer->getById($created->productId)->deletedAt);
    }

    public function testUnknownAndInvalidIdentities(): void
    {
        $service = $this->productManagementService();
        foreach ([
            fn() => $service->updateStatus(999999, ProductStatusEnum::ACTIVE),
            fn() => $service->softDelete(999999),
            fn() => $service->restore(999999),
        ] as $call) {
            $this->assertThrows(ProductNotFoundException::class, $call);
        }
        foreach ([
            fn() => $service->updateStatus(0, ProductStatusEnum::ACTIVE),
            fn() => $service->softDelete(-1),
            fn() => $service->restore(0),
        ] as $call) {
            $this->assertThrows(ProductInvalidArgumentException::class, $call);
        }
    }

    public function testADeletedProductBlocksEveryChildMutationExceptReads(): void
    {
        $created = $this->namedProduct('PARENT');
        $color = $this->preparedOption($created->productId, 'color', 'Color');
        $red = $this->preparedValue($color, 'red', 'Red');
        $result = $this->productManagementService()->reconfigure($this->reconfiguration($created->productId, [$color => [$red]], [[[[$color, $red]]]]));
        $redVariant = $result->createdVariantIds[0];
        $reconfigure = $this->reconfiguration($created->productId, [], [[[], $created->variantId]]);
        $this->productManagementService()->softDelete($created->productId);

        self::assertSame('active', self::scalar('SELECT status FROM maa_product_options WHERE id = :i', ['i' => $color]), 'No cascade to the Option.');
        self::assertNull(self::scalar('SELECT deleted_at FROM maa_product_variants WHERE id = :i', ['i' => $redVariant]), 'No cascade to the Variant.');

        $this->assertThrows(ProductBusinessRuleException::class, fn() => $this->optionManagementService()->create(new CreateOptionCommand($created->productId, 'size')), 'Create under a deleted owner.');
        $this->assertThrows(ProductBusinessRuleException::class, fn() => $this->optionManagementService()->softDelete($color));
        $this->assertThrows(ProductBusinessRuleException::class, fn() => $this->optionValueManagementService()->softDelete($red));
        $this->assertThrows(ProductBusinessRuleException::class, fn() => $this->variantManagementService()->updateStatus($redVariant, VariantStatusEnum::INACTIVE));
        $this->assertThrows(ProductBusinessRuleException::class, fn() => $this->variantManagementService()->softDelete($created->variantId));
        $this->assertThrows(ProductBusinessRuleException::class, fn() => $this->productManagementService()->reconfigure($reconfigure));
        self::assertSame($redVariant, $this->variantManagementService()->getByIdForManagement($redVariant)->id, 'Management reads still work.');
        self::assertSame($color, $this->optionManagementService()->getByIdForManagement($color)->id);

        $this->productManagementService()->restore($created->productId);
        $this->optionManagementService()->create(new CreateOptionCommand($created->productId, 'size'));
        self::assertSame(2, self::rowCount('maa_product_options'));
    }
}
