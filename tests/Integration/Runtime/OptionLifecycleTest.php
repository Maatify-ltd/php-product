<?php

declare(strict_types=1);

namespace Maatify\Product\Tests\Integration\Runtime;

use Maatify\Product\Common\Enum\ProductDeletedStateEnum;
use Maatify\Product\Common\Exception\ProductBusinessRuleException;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;
use Maatify\Product\Option\Enum\OptionStatusEnum;
use Maatify\Product\Option\Exception\OptionCodeAlreadyExistsException;
use Maatify\Product\Option\Exception\OptionNotFoundException;
use Maatify\Product\Option\Management\Command\CreateOptionCommand;
use Maatify\Product\OptionValue\Enum\OptionValueStatusEnum;
use Maatify\Product\OptionValue\Exception\OptionValueCodeAlreadyExistsException;
use Maatify\Product\OptionValue\Exception\OptionValueNotFoundException;
use Maatify\Product\OptionValue\Management\Command\CreateOptionValueCommand;
use Maatify\Product\Product\Exception\ProductNotFoundException;

/**
 * Option and Option Value creation, soft delete, and restore against real MySQL: new rows are
 * inactive and ordered by the shared Ordering API, codes stay reserved across history, a deleted
 * owner blocks creation and mutation, and every change is validated against the configuration.
 */
final class OptionLifecycleTest extends RuntimeTestCase
{
    public function testCreateIsInactiveOrderedWithinItsOwnerAndUniqueAcrossHistory(): void
    {
        $created = $this->namedProduct('P');
        $options = $this->optionManagementService();
        $values = $this->optionValueManagementService();

        $color = $options->create(new CreateOptionCommand($created->productId, 'color'))->optionId;
        $size = $options->create(new CreateOptionCommand($created->productId, 'size'))->optionId;
        $colorDto = $options->getByIdForManagement($color);
        self::assertSame(OptionStatusEnum::INACTIVE, $colorDto->status);
        self::assertSame(1, $colorDto->displayOrder);
        self::assertSame(2, $options->getByIdForManagement($size)->displayOrder);
        self::assertSame('color', $colorDto->code);
        self::assertSame($created->productId, $colorDto->productId);
        $this->assertThrows(OptionCodeAlreadyExistsException::class, fn() => $options->create(new CreateOptionCommand($created->productId, 'COLOR')), 'Codes compare case-insensitively.');

        $red = $values->create(new CreateOptionValueCommand($color, 'red'))->optionValueId;
        $sizeRed = $values->create(new CreateOptionValueCommand($size, 'red'))->optionValueId;
        $redDto = $values->getByIdForManagement($red);
        self::assertSame(OptionValueStatusEnum::INACTIVE, $redDto->status);
        self::assertSame(1, $redDto->displayOrder);
        self::assertSame(1, $values->getByIdForManagement($sizeRed)->displayOrder, 'Values are ordered within their Option.');
        $this->assertThrows(OptionValueCodeAlreadyExistsException::class, fn() => $values->create(new CreateOptionValueCommand($color, 'Red')));

        $options->softDelete($size);
        $this->assertThrows(OptionCodeAlreadyExistsException::class, fn() => $options->create(new CreateOptionCommand($created->productId, 'size')), 'A soft-deleted Option keeps its code reserved.');
        $values->softDelete($red);
        $this->assertThrows(OptionValueCodeAlreadyExistsException::class, fn() => $values->create(new CreateOptionValueCommand($color, 'red')));

        $this->assertThrows(ProductNotFoundException::class, fn() => $options->create(new CreateOptionCommand(999999, 'x')));
        $this->assertThrows(OptionNotFoundException::class, fn() => $values->create(new CreateOptionValueCommand(999999, 'x')));
        $this->assertThrows(ProductInvalidArgumentException::class, fn() => new CreateOptionCommand($created->productId, "  \t"));
        $this->assertThrows(ProductInvalidArgumentException::class, fn() => new CreateOptionValueCommand($color, str_repeat('x', 101)));
    }

    public function testCreationUnderASoftDeletedOwnerIsRejected(): void
    {
        $created = $this->namedProduct('P');
        $color = $this->preparedOption($created->productId, 'color', 'Color');
        $material = $this->preparedOption($created->productId, 'material', 'Material');

        $this->optionManagementService()->softDelete($material);
        $this->assertThrows(ProductBusinessRuleException::class, fn() => $this->optionValueManagementService()->create(new CreateOptionValueCommand($material, 'wood')), 'Value under a deleted Option.');

        $this->productManagementService()->softDelete($created->productId);
        $this->assertThrows(ProductBusinessRuleException::class, fn() => $this->optionManagementService()->create(new CreateOptionCommand($created->productId, 'size')), 'Option under a deleted Product.');
        $this->assertThrows(ProductBusinessRuleException::class, fn() => $this->optionValueManagementService()->create(new CreateOptionValueCommand($color, 'red')), 'Value under a live Option of a deleted Product.');
        $this->assertThrows(ProductBusinessRuleException::class, fn() => $this->optionManagementService()->restore($material), 'Restore needs the owner chain restored first.');

        $this->productManagementService()->restore($created->productId);
        $this->optionManagementService()->restore($material);
        $wood = $this->optionValueManagementService()->create(new CreateOptionValueCommand($material, 'wood'))->optionValueId;
        self::assertSame($material, $this->optionValueManagementService()->getByIdForManagement($wood)->optionId, 'After restoring the owner chain, creation succeeds.');
    }

    public function testActiveMembersOfTheConfigurationLeaveOnlyThroughReconfiguration(): void
    {
        $created = $this->namedProduct('CFG');
        $color = $this->preparedOption($created->productId, 'color', 'Color');
        $size = $this->preparedOption($created->productId, 'size', 'Size');
        $red = $this->preparedValue($color, 'red', 'Red');
        $blue = $this->preparedValue($color, 'blue', 'Blue');
        $m = $this->preparedValue($size, 'm', 'M');
        $green = $this->preparedValue($color, 'green', 'Green');
        $this->productManagementService()->reconfigure($this->reconfiguration(
            $created->productId,
            [$color => [$red, $blue], $size => [$m]],
            [[[[$color, $red], [$size, $m]]], [[[$color, $blue], [$size, $m]]]],
        ));
        $options = $this->optionManagementService();
        $values = $this->optionValueManagementService();

        $this->assertThrows(ProductBusinessRuleException::class, fn() => $options->softDelete($color), 'An active Option is part of every active Variant.');
        $this->assertThrows(ProductBusinessRuleException::class, fn() => $values->softDelete($red), 'red is needed by an active Variant.');
        self::assertNull($options->getByIdForManagement($color)->deletedAt);
        self::assertNull($values->getByIdForManagement($red)->deletedAt);

        $values->softDelete($green);
        self::assertSame(OptionValueStatusEnum::INACTIVE, $values->getByIdForManagement($green, ProductDeletedStateEnum::DELETED_ONLY)->status);
        $this->assertThrows(ProductBusinessRuleException::class, fn() => $values->softDelete($green), 'Re-deleting is rejected.');
        $values->restore($green);
        self::assertSame(OptionValueStatusEnum::INACTIVE, $values->getByIdForManagement($green)->status, 'Restore preserves inactive.');
        $this->assertThrows(ProductBusinessRuleException::class, fn() => $values->restore($green), 'Restoring a live row is rejected.');

        // Reconfiguration retires size; then size and its Value can be deleted and restored freely.
        $this->productManagementService()->reconfigure($this->reconfiguration($created->productId, [$color => [$red, $blue]], [[[[$color, $red]]], [[[$color, $blue]]]]));
        self::assertSame(OptionStatusEnum::INACTIVE, $options->getByIdForManagement($size)->status);
        $options->softDelete($size);
        $this->assertThrows(ProductBusinessRuleException::class, fn() => $values->softDelete($m), 'A Value under a deleted Option cannot be changed.');
        $options->restore($size);
        $values->softDelete($m);
        $values->restore($m);
        self::assertSame(OptionValueStatusEnum::ACTIVE, $values->getByIdForManagement($m)->status, 'The saved status (active under an inactive Option) is preserved.');
    }

    public function testRestoreRevalidatesTheConfigurationTheRestoredRowWouldJoin(): void
    {
        $created = $this->namedProduct('CFG');
        $color = $this->preparedOption($created->productId, 'color', 'Color');
        $size = $this->preparedOption($created->productId, 'size', 'Size');
        $red = $this->preparedValue($color, 'red', 'Red');
        $m = $this->preparedValue($size, 'm', 'M');
        $this->productManagementService()->reconfigure($this->reconfiguration($created->productId, [$color => [$red], $size => [$m]], [[[[$color, $red], [$size, $m]]]]));

        // size leaves through direct SQL while saved active, then the configuration is rebuilt without it.
        $this->execute('UPDATE maa_product_options SET deleted_at = :t WHERE id = :i', ['t' => self::SEED_TS, 'i' => $size]);
        $this->productManagementService()->reconfigure($this->reconfiguration($created->productId, [$color => [$red]], [[[[$color, $red]]]]));

        $failure = $this->assertThrows(ProductBusinessRuleException::class, fn() => $this->optionManagementService()->restore($size), 'Restoring an active Option that no active Variant covers breaks INV-02.');
        self::assertStringContainsString('[' . $size . ']', $failure->getMessage());
        self::assertNotNull($this->optionManagementService()->getByIdForManagement($size, ProductDeletedStateEnum::DELETED_ONLY)->deletedAt);
    }

    public function testManagementReadsAndUnknownIdentities(): void
    {
        $created = $this->namedProduct('P');
        $color = $this->preparedOption($created->productId, 'color');
        $red = $this->preparedValue($color, 'red');
        $options = $this->optionManagementService();
        $values = $this->optionValueManagementService();

        $values->softDelete($red);
        $this->assertThrows(OptionValueNotFoundException::class, fn() => $values->getByIdForManagement($red));
        self::assertSame($red, $values->getByIdForManagement($red, ProductDeletedStateEnum::INCLUDE_DELETED)->id);
        $this->assertThrows(OptionNotFoundException::class, fn() => $options->getByIdForManagement($color, ProductDeletedStateEnum::DELETED_ONLY));
        self::assertSame('UTC', $options->getByIdForManagement($color)->createdAt->getTimezone()->getName());

        foreach ([
            fn() => $options->softDelete(999999),
            fn() => $options->restore(999999),
            fn() => $options->getByIdForManagement(999999),
        ] as $call) {
            $this->assertThrows(OptionNotFoundException::class, $call);
        }
        foreach ([
            fn() => $values->softDelete(999999),
            fn() => $values->restore(999999),
            fn() => $values->getByIdForManagement(999999),
        ] as $call) {
            $this->assertThrows(OptionValueNotFoundException::class, $call);
        }
        $this->assertThrows(ProductInvalidArgumentException::class, fn() => $options->softDelete(0));
        $this->assertThrows(ProductInvalidArgumentException::class, fn() => $values->restore(0));
    }
}
