<?php

declare(strict_types=1);

namespace Maatify\Product\Tests\Integration\Runtime;

use Maatify\Product\Common\Enum\ProductDeletedStateEnum;
use Maatify\Product\Common\Exception\ProductBusinessRuleException;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;
use Maatify\Product\Common\Exception\ProductPersistenceException;
use Maatify\Product\Option\Enum\OptionStatusEnum;
use Maatify\Product\OptionValue\Enum\OptionValueStatusEnum;
use Maatify\Product\Product\Exception\ProductNotFoundException;
use Maatify\Product\Product\Management\Command\CreateProductCommand;
use Maatify\Product\Product\Management\Command\ReconfigureOptionTarget;
use Maatify\Product\Product\Management\Command\ReconfigureProductCommand;
use Maatify\Product\Product\Management\Command\ReconfigureVariantTarget;
use Maatify\Product\Variant\DTO\VariantDTO;
use Maatify\Product\Variant\Enum\VariantStatusEnum;
use Maatify\Product\Variant\Exception\VariantCompositionConflictException;
use Maatify\Product\Variant\Exception\VariantNotFoundException;
use Maatify\Product\Variant\ValueObject\OptionValueSelection;
use PDOException;
use Throwable;

/**
 * `CFG-01` Product reconfiguration against real MySQL under its contract: one atomic
 * Product-local operation over a complete target state, explicit reuse of historical identities,
 * the simple/configurable transitions, the failure classes, and full rollback whoever owns the transaction.
 */
final class ProductReconfigurationTest extends RuntimeTestCase
{
    private const string FAILING_TRIGGER = 'trg_product_it_reconfigure_failure';

    private int $product;
    private int $empty;
    private int $color;
    private int $size;
    private int $red;
    private int $blue;
    private int $m;
    private int $l;

    protected function cleanUp(): void
    {
        self::$pdo->exec('DROP TRIGGER IF EXISTS `' . self::FAILING_TRIGGER . '`');
    }

    private function prepared(): void
    {
        $created = $this->namedProduct('CFG');
        $this->product = $created->productId;
        $this->empty = $created->variantId;
        $this->color = $this->preparedOption($this->product, 'color', 'Color');
        $this->size = $this->preparedOption($this->product, 'size', 'Size');
        $this->red = $this->preparedValue($this->color, 'red', 'Red');
        $this->blue = $this->preparedValue($this->color, 'blue', 'Blue');
        $this->m = $this->preparedValue($this->size, 'm', 'M');
        $this->l = $this->preparedValue($this->size, 'l', 'L');
    }

    /** @return array{0: list<array{int, int}>, 1?: int|null} */
    private function target(int $colorValue, ?int $sizeValue = null, ?int $reuse = null): array
    {
        $pairs = [[$this->color, $colorValue]];
        if ($sizeValue !== null) {
            $pairs[] = [$this->size, $sizeValue];
        }

        return [$pairs, $reuse];
    }

    /** @return array{0: list<array{int, int}>, 1?: int|null} */
    private function emptyTarget(?int $reuse): array
    {
        return [[], $reuse];
    }

    /** @return list<int> */
    private function activeVariantIds(): array
    {
        return array_map(
            static fn(VariantDTO $v): int => $v->id,
            $this->variantManagementService()->listByProductIdForManagement($this->product, new \Maatify\Product\Variant\Management\Criteria\VariantListCriteriaDTO(VariantStatusEnum::ACTIVE))->items(),
        );
    }

    public function testSimpleToConfigurableAndBackReusesTheOriginalEmptyVariant(): void
    {
        $this->prepared();
        $products = $this->productManagementService();
        $variants = $this->variantManagementService();
        $consumerVariants = $this->variantConsumerService();

        $result = $products->reconfigure($this->reconfiguration(
            $this->product,
            [$this->color => [$this->red, $this->blue], $this->size => [$this->m, $this->l]],
            [$this->target($this->red, $this->m), $this->target($this->blue, $this->l)],
        ));
        self::assertCount(2, $result->createdVariantIds);
        self::assertSame([$this->empty], $result->deactivatedVariantIds);
        self::assertSame([], $result->reactivatedVariantIds);
        self::assertSame([], $result->restoredVariantIds);
        self::assertSame([$this->color, $this->size], $result->activatedOptionIds);
        self::assertSame([$this->red, $this->blue, $this->m, $this->l], $result->activatedOptionValueIds);
        self::assertSame([], $result->deactivatedOptionIds);
        [$redM, $blueL] = $result->createdVariantIds;
        self::assertSame(VariantStatusEnum::INACTIVE, $variants->getByIdForManagement($this->empty)->status, 'The empty-composition Variant is deactivated, not deleted.');
        $created = $variants->getByIdForManagement($redM);
        self::assertSame([[$this->color, $this->red], [$this->size, $this->m]], array_map(static fn($pair): array => [$pair->optionId, $pair->optionValueId], $created->composition));
        self::assertNull($created->sku);
        self::assertNull($created->barcode);
        self::assertSame(2, $created->displayOrder, 'Positions continue after the empty Variant.');
        self::assertSame(3, $variants->getByIdForManagement($blueL)->displayOrder);
        self::assertSame(OptionStatusEnum::ACTIVE, $this->optionManagementService()->getByIdForManagement($this->color)->status);
        $this->activateProduct($this->product);
        self::assertSame([$redM, $blueL], array_map(static fn(VariantDTO $v): int => $v->id, $consumerVariants->listByProductId($this->product)->items()));

        $back = $products->reconfigure($this->reconfiguration($this->product, [], [$this->emptyTarget($this->empty)]));
        self::assertSame([], $back->createdVariantIds);
        self::assertSame([$this->empty], $back->reactivatedVariantIds);
        self::assertSame([$redM, $blueL], $back->deactivatedVariantIds);
        self::assertSame([$this->color, $this->size], $back->deactivatedOptionIds);
        self::assertSame([], $back->deactivatedOptionValueIds, 'Values of a retired Option are retained as history unchanged.');
        self::assertSame(OptionValueStatusEnum::ACTIVE, $this->optionValueManagementService()->getByIdForManagement($this->red)->status);
        self::assertSame(1, self::rowCount('maa_product_variants', 'product_id = ' . $this->product . " AND composition_fingerprint = '" . hash('sha256', 'v1') . "'"), 'Never a second empty-composition identity.');
        self::assertSame([$this->empty], array_map(static fn(VariantDTO $v): int => $v->id, $consumerVariants->listByProductId($this->product)->items()));
        self::assertSame(3, self::rowCount('maa_product_variants', 'product_id = ' . $this->product), 'History is kept.');
    }

    public function testAnExistingCompositionIsReusedOnlyWhenItsIdentityIsNamed(): void
    {
        $this->prepared();
        $products = $this->productManagementService();
        $other = $this->namedProduct('OTHER');
        $all = [$this->color => [$this->red, $this->blue], $this->size => [$this->m, $this->l]];
        [$redM, $blueL] = $products->reconfigure($this->reconfiguration($this->product, $all, [$this->target($this->red, $this->m), $this->target($this->blue, $this->l)]))->createdVariantIds;

        $conflict = $this->assertThrows(
            VariantCompositionConflictException::class,
            fn() => $products->reconfigure($this->reconfiguration($this->product, $all, [$this->target($this->red, $this->m), $this->target($this->blue, $this->l)])),
            'An existing composition without reuseVariantId is a conflict even when it is currently active.',
        );
        self::assertStringContainsString('[' . $redM . ']', $conflict->getMessage(), 'The conflict identifies the existing Variant.');

        $kept = $products->reconfigure($this->reconfiguration($this->product, $all, [$this->target($this->red, $this->m, $redM), $this->target($this->blue, $this->l, $blueL)]));
        self::assertSame([[], [], [], []], [$kept->createdVariantIds, $kept->deactivatedVariantIds, $kept->reactivatedVariantIds, $kept->restoredVariantIds], 'Reusing the active set changes nothing.');

        $this->assertThrows(VariantCompositionConflictException::class, fn() => $products->reconfigure($this->reconfiguration($this->product, $all, [$this->target($this->red, $this->m, $blueL), $this->target($this->blue, $this->l, $redM)])), 'The named Variant must be the one that carries the composition.');
        $this->assertThrows(VariantCompositionConflictException::class, fn() => $products->reconfigure($this->reconfiguration($this->product, $all, [$this->target($this->red, $this->m, $redM), $this->target($this->blue, $this->l, $blueL), $this->target($this->red, $this->l, $this->empty)])), 'A new composition cannot borrow another Variant identity.');
        $this->assertThrows(VariantCompositionConflictException::class, fn() => $products->reconfigure($this->reconfiguration($this->product, $all, [$this->target($this->red, $this->m, $redM), $this->target($this->blue, $this->l, $blueL), $this->target($this->red, $this->l, $other->variantId)])), 'A Variant of another Product cannot be reused.');
        $this->assertThrows(VariantNotFoundException::class, fn() => $products->reconfigure($this->reconfiguration($this->product, $all, [$this->target($this->red, $this->m, $redM), $this->target($this->blue, $this->l, $blueL), $this->target($this->red, $this->l, 999999)])), 'An unknown reuse identity is not found.');
        $this->assertThrows(ProductInvalidArgumentException::class, fn() => $this->reconfiguration($this->product, $all, [$this->target($this->red, $this->m, $redM), $this->target($this->red, $this->m)]), 'A repeated composition is rejected by the command.');
        $this->assertThrows(ProductInvalidArgumentException::class, fn() => $this->reconfiguration($this->product, $all, [$this->target($this->red, $this->m, $redM), $this->target($this->blue, $this->l, $redM)]), 'A repeated reuse identity is rejected by the command.');
        self::assertSame([$redM, $blueL], $this->activeVariantIds(), 'Every rejected call left the configuration unchanged.');
    }

    public function testHistoricalAndSoftDeletedVariantsComeBackOnlyByExplicitIdentity(): void
    {
        $this->prepared();
        $products = $this->productManagementService();
        $variants = $this->variantManagementService();
        $all = [$this->color => [$this->red, $this->blue], $this->size => [$this->m, $this->l]];
        [$redM, $blueL, $redL] = $products->reconfigure($this->reconfiguration($this->product, $all, [$this->target($this->red, $this->m), $this->target($this->blue, $this->l), $this->target($this->red, $this->l)]))->createdVariantIds;

        $dropped = $products->reconfigure($this->reconfiguration($this->product, $all, [$this->target($this->red, $this->m, $redM), $this->target($this->blue, $this->l, $blueL)]));
        self::assertSame([$redL], $dropped->deactivatedVariantIds);
        self::assertSame(VariantStatusEnum::INACTIVE, $variants->getByIdForManagement($redL)->status);

        $this->assertThrows(VariantCompositionConflictException::class, fn() => $products->reconfigure($this->reconfiguration($this->product, $all, [$this->target($this->red, $this->m, $redM), $this->target($this->blue, $this->l, $blueL), $this->target($this->red, $this->l)])), 'A historical composition is never reused implicitly.');
        $back = $products->reconfigure($this->reconfiguration($this->product, $all, [$this->target($this->red, $this->m, $redM), $this->target($this->blue, $this->l, $blueL), $this->target($this->red, $this->l, $redL)]));
        self::assertSame([$redL], $back->reactivatedVariantIds);
        self::assertSame([], $back->createdVariantIds);
        self::assertSame(VariantStatusEnum::ACTIVE, $variants->getByIdForManagement($redL)->status);

        $variants->softDelete($redL);
        $this->assertThrows(VariantCompositionConflictException::class, fn() => $products->reconfigure($this->reconfiguration($this->product, $all, [$this->target($this->red, $this->m, $redM), $this->target($this->blue, $this->l, $blueL), $this->target($this->red, $this->l)])), 'A soft-deleted composition is a conflict too.');
        $restored = $products->reconfigure($this->reconfiguration($this->product, $all, [$this->target($this->red, $this->m, $redM), $this->target($this->blue, $this->l, $blueL), $this->target($this->red, $this->l, $redL)]));
        self::assertSame([$redL], $restored->restoredVariantIds);
        self::assertSame([], $restored->reactivatedVariantIds);
        $redLDto = $variants->getByIdForManagement($redL);
        self::assertNull($redLDto->deletedAt);
        self::assertSame(VariantStatusEnum::ACTIVE, $redLDto->status, 'Restored and activated inside the same operation.');
        self::assertSame(4, self::rowCount('maa_product_variants', 'product_id = ' . $this->product), 'No duplicate identity was created.');
    }

    public function testTheTransitionToSimpleFailsClosedWhenTheOriginalEmptyVariantIsMissing(): void
    {
        $this->prepared();
        $products = $this->productManagementService();
        $all = [$this->color => [$this->red], $this->size => [$this->m]];
        [$redM] = $products->reconfigure($this->reconfiguration($this->product, $all, [$this->target($this->red, $this->m)]))->createdVariantIds;

        $this->assertThrows(VariantCompositionConflictException::class, fn() => $products->reconfigure($this->reconfiguration($this->product, [], [$this->emptyTarget(null)])), 'The empty composition exists in history and must be named.');

        $this->execute('DELETE FROM maa_product_variants WHERE id = :i', ['i' => $this->empty]);
        $this->assertThrows(ProductPersistenceException::class, fn() => $products->reconfigure($this->reconfiguration($this->product, [], [$this->emptyTarget(null)])), 'The original identity is missing: fail closed, never create a second one.');
        self::assertSame([$redM], $this->activeVariantIds(), 'The failed transition changed nothing.');
        self::assertSame(OptionStatusEnum::ACTIVE, $this->optionManagementService()->getByIdForManagement($this->color)->status);
        self::assertSame(0, self::rowCount('maa_product_variants', "composition_fingerprint = '" . hash('sha256', 'v1') . "'"));
    }

    public function testInvalidTargetsAreRejectedAsAWholeWithTheSettledClasses(): void
    {
        $this->prepared();
        $products = $this->productManagementService();
        $other = $this->namedProduct('OTHER');
        $otherOption = $this->preparedOption($other->productId, 'color', 'Color');
        $otherValue = $this->preparedValue($otherOption, 'red', 'Red');
        $unlabelledOption = $this->preparedOption($this->product, 'material');
        $unlabelledValue = $this->preparedValue($unlabelledOption, 'wood');
        $this->labelOption($unlabelledOption, 'Material');
        $deletedValue = $this->preparedValue($this->color, 'green', 'Green');
        $this->optionValueManagementService()->softDelete($deletedValue);
        $deletedOption = $this->preparedOption($this->product, 'finish', 'Finish');
        $finishValue = $this->preparedValue($deletedOption, 'matte', 'Matte');
        $this->optionManagementService()->softDelete($deletedOption);
        $all = [$this->color => [$this->red, $this->blue], $this->size => [$this->m, $this->l]];

        $businessRule = [
            'Option of another Product' => $this->reconfiguration($this->product, [$otherOption => [$otherValue]], [[[[$otherOption, $otherValue]]]]),
            'Value of another Product' => $this->reconfiguration($this->product, [$this->color => [$otherValue]], [[[[$this->color, $otherValue]]]]),
            'Value of the wrong Option' => $this->reconfiguration($this->product, [$this->color => [$this->m]], [[[[$this->color, $this->m]]]]),
            'soft-deleted Option' => $this->reconfiguration($this->product, [$deletedOption => [$finishValue]], [[[[$deletedOption, $finishValue]]]]),
            'soft-deleted Value' => $this->reconfiguration($this->product, [$this->color => [$deletedValue]], [[[[$this->color, $deletedValue]]]]),
            'unlabelled Value' => $this->reconfiguration($this->product, [$unlabelledOption => [$unlabelledValue]], [[[[$unlabelledOption, $unlabelledValue]]]]),
            'composition missing a target Option' => $this->reconfiguration($this->product, $all, [$this->target($this->red), $this->target($this->blue, $this->l)]),
            'composition selecting an Option outside the target set' => $this->reconfiguration($this->product, [$this->color => [$this->red, $this->blue]], [$this->target($this->red, $this->m), $this->target($this->blue)]),
            'composition selecting a Value outside the target set' => $this->reconfiguration($this->product, [$this->color => [$this->red], $this->size => [$this->m]], [$this->target($this->red, $this->m), $this->target($this->blue, $this->m)]),
            'unrepresented target Value' => $this->reconfiguration($this->product, $all, [$this->target($this->red, $this->m), $this->target($this->blue, $this->m)]),
            'no Variant at all' => $this->reconfiguration($this->product, [$this->color => [$this->red]], []),
            'empty composition in a configurable target' => $this->reconfiguration($this->product, [$this->color => [$this->red]], [$this->emptyTarget($this->empty), $this->target($this->red)]),
            'non-empty composition in a simple target' => $this->reconfiguration($this->product, [], [$this->target($this->red)]),
        ];
        foreach ($businessRule as $case => $command) {
            $this->assertThrows(ProductBusinessRuleException::class, fn() => $products->reconfigure($command), $case . ' must be an FC-INV failure.');
        }
        $this->assertThrows(ProductInvalidArgumentException::class, fn() => new ReconfigureProductCommand($this->product, 1, [new ReconfigureOptionTarget($this->color, [$this->red]), new ReconfigureOptionTarget($this->color, [$this->blue])], []), 'A repeated Option is rejected by the command.');
        $this->assertThrows(ProductInvalidArgumentException::class, fn() => new ReconfigureProductCommand($this->product, 1, [new ReconfigureOptionTarget($this->color, [$this->red]), new ReconfigureOptionTarget($this->size, [$this->red])], []), 'A Value under two Options is rejected by the command.');
        $this->assertThrows(ProductInvalidArgumentException::class, fn() => new ReconfigureVariantTarget([new OptionValueSelection($this->color, $this->red), new OptionValueSelection($this->color, $this->blue)]), 'A repeated Option in a composition is rejected by the command.');
        $this->assertThrows(ProductInvalidArgumentException::class, fn() => new ReconfigureOptionTarget($this->color, []));

        self::assertSame([$this->empty], $this->activeVariantIds(), 'Nothing was written by any rejected call.');
        self::assertSame(1, self::rowCount('maa_product_variants', 'product_id = ' . $this->product));
        self::assertSame(0, self::rowCount('maa_product_options', "product_id = {$this->product} AND status = 'active'"));
        self::assertSame(0, self::rowCount('maa_product_option_values', "status = 'active'"));

        $this->assertThrows(ProductNotFoundException::class, fn() => $products->reconfigure($this->reconfiguration(999999, [], [$this->emptyTarget(null)], 1)));
        $command = $this->reconfiguration($this->product, $all, [$this->target($this->red, $this->m), $this->target($this->blue, $this->l)]);
        $products->softDelete($this->product);
        $this->assertThrows(ProductBusinessRuleException::class, fn() => $products->reconfigure($command), 'A soft-deleted Product cannot be reconfigured.');
    }

    public function testAStoredFingerprintThatDoesNotMatchItsPairsIsAPersistenceFailure(): void
    {
        $this->prepared();
        $products = $this->productManagementService();
        $all = [$this->color => [$this->red, $this->blue], $this->size => [$this->m, $this->l]];
        [$redM, $blueL] = $products->reconfigure($this->reconfiguration($this->product, $all, [$this->target($this->red, $this->m), $this->target($this->blue, $this->l)]))->createdVariantIds;
        $this->execute('UPDATE maa_product_variant_option_values SET option_value_id = :l WHERE variant_id = :v AND option_id = :o', ['l' => $this->l, 'v' => $redM, 'o' => $this->size]);

        $this->assertThrows(ProductPersistenceException::class, fn() => $products->reconfigure($this->reconfiguration($this->product, $all, [$this->target($this->red, $this->m, $redM), $this->target($this->blue, $this->l, $blueL)])));
    }

    public function testAFailureInsideAHostTransactionLeavesTheHostWorkIntactAndTheConfigurationUnchanged(): void
    {
        $this->prepared();
        $products = $this->productManagementService();
        $all = [$this->color => [$this->red, $this->blue], $this->size => [$this->m, $this->l]];

        self::$pdo->beginTransaction();
        $hostWork = $products->create(new CreateProductCommand('HOST-WORK'));
        $this->assertThrows(ProductBusinessRuleException::class, fn() => $products->reconfigure($this->reconfiguration($this->product, $all, [$this->target($this->red, $this->m)])), 'blue, l are unrepresented.');
        self::assertTrue(self::$pdo->inTransaction(), 'The Host transaction is neither committed nor rolled back.');
        $result = $products->reconfigure($this->reconfiguration($this->product, $all, [$this->target($this->red, $this->m), $this->target($this->blue, $this->l)]));
        self::$pdo->commit();

        self::assertSame($hostWork->productId, $products->getByIdForManagement($hostWork->productId)->id, 'Host work before the failed call survived.');
        self::assertSame($result->createdVariantIds, $this->activeVariantIds(), 'The later successful call inside the same Host transaction was committed by the Host.');
        self::assertSame(OptionStatusEnum::ACTIVE, $this->optionManagementService()->getByIdForManagement($this->size)->status);

        self::$pdo->beginTransaction();
        $products->reconfigure($this->reconfiguration($this->product, [], [$this->emptyTarget($this->empty)]));
        self::assertSame([$this->empty], $this->activeVariantIds());
        self::$pdo->rollBack();
        self::assertSame($result->createdVariantIds, $this->activeVariantIds(), 'A Host rollback discards the package writes with the rest.');
    }

    public function testAStorageFailureAfterTheStatusWritesRollsTheWholeOperationBack(): void
    {
        $this->prepared();
        $products = $this->productManagementService();
        self::$pdo->exec(
            'CREATE TRIGGER `' . self::FAILING_TRIGGER . '` BEFORE INSERT ON `maa_product_variants` '
            . "FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'forced Variant failure'",
        );

        try {
            $products->reconfigure($this->reconfiguration($this->product, [$this->color => [$this->red], $this->size => [$this->m]], [$this->target($this->red, $this->m)]));
            self::fail('The storage failure must propagate.');
        } catch (Throwable $caught) {
            self::assertInstanceOf(PDOException::class, $caught);
            self::assertStringContainsString('forced Variant failure', $caught->getMessage());
        }
        self::assertFalse(self::$pdo->inTransaction());
        self::assertSame(OptionStatusEnum::INACTIVE, $this->optionManagementService()->getByIdForManagement($this->color)->status, 'The Option activation written before the failure was rolled back.');
        self::assertSame(OptionValueStatusEnum::INACTIVE, $this->optionValueManagementService()->getByIdForManagement($this->red)->status);
        self::assertSame(VariantStatusEnum::ACTIVE, $this->variantManagementService()->getByIdForManagement($this->empty)->status, 'The empty Variant deactivation was rolled back.');
        self::assertSame([$this->empty], $this->activeVariantIds());
        self::assertSame($this->empty, $this->variantManagementService()->getByIdForManagement($this->empty, ProductDeletedStateEnum::NON_DELETED)->id);
    }
}
