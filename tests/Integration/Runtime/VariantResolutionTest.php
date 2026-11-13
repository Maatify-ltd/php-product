<?php

declare(strict_types=1);

namespace Maatify\Product\Tests\Integration\Runtime;

use Maatify\Product\Common\Exception\ProductInvalidArgumentException;
use Maatify\Product\Common\Exception\ProductPersistenceException;
use Maatify\Product\Product\Enum\ProductStatusEnum;
use Maatify\Product\Product\Exception\ProductNotFoundException;
use Maatify\Product\Variant\Consumer\Criteria\VariantCompositionCriteriaDTO;
use Maatify\Product\Variant\Exception\VariantNotFoundException;
use Maatify\Product\Variant\ValueObject\OptionValueSelection;

/**
 * `RES-01` composition resolution against real MySQL, with the failure classes the Lead settled
 * of the resolution contract: structural selection errors are validation failures, an invisible Product is not
 * found, a valid composition with no visible Variant is a Variant not-found, and inconsistent
 * stored data is a persistence failure.
 */
final class VariantResolutionTest extends RuntimeTestCase
{
    private int $product;
    private int $color;
    private int $size;
    private int $red;
    private int $blue;
    private int $m;
    private int $l;
    private int $green;
    private int $material;
    private int $redM;
    private int $blueL;
    private int $redL;

    /**
     * An active configurable Product offering red-M, blue-L, and red-L, with a prepared but inactive
     * Value (green) and a prepared but inactive Option (material).
     */
    private function configurable(): void
    {
        $created = $this->namedProduct('CFG');
        $this->product = $created->productId;
        $this->color = $this->preparedOption($this->product, 'color', 'Color');
        $this->size = $this->preparedOption($this->product, 'size', 'Size');
        $this->red = $this->preparedValue($this->color, 'red', 'Red');
        $this->blue = $this->preparedValue($this->color, 'blue', 'Blue');
        $this->m = $this->preparedValue($this->size, 'm', 'M');
        $this->l = $this->preparedValue($this->size, 'l', 'L');
        $this->green = $this->preparedValue($this->color, 'green', 'Green');
        $this->material = $this->preparedOption($this->product, 'material', 'Material');

        $offered = [
            [[[$this->color, $this->red], [$this->size, $this->m]]],
            [[[$this->color, $this->blue], [$this->size, $this->l]]],
            [[[$this->color, $this->red], [$this->size, $this->l]]],
        ];
        $result = $this->productManagementService()->reconfigure($this->reconfiguration(
            $this->product,
            [$this->color => [$this->red, $this->blue], $this->size => [$this->m, $this->l]],
            $offered,
        ));
        [$this->redM, $this->blueL, $this->redL] = $result->createdVariantIds;
        $this->activateProduct($this->product);
    }

    /**
     * @param list<array{int, int}> $pairs
     */
    private static function selection(array $pairs): VariantCompositionCriteriaDTO
    {
        return new VariantCompositionCriteriaDTO(array_map(
            static fn(array $pair): OptionValueSelection => new OptionValueSelection($pair[0], $pair[1]),
            $pairs,
        ));
    }

    public function testResolvesAnyPairOrderToTheSameVisibleVariant(): void
    {
        $this->configurable();
        $service = $this->variantConsumerService();

        self::assertSame($this->redM, $service->resolveByComposition($this->product, self::selection([[$this->size, $this->m], [$this->color, $this->red]]))->id);
        self::assertSame($this->redM, $service->resolveByComposition($this->product, self::selection([[$this->color, $this->red], [$this->size, $this->m]]))->id);
        self::assertSame($this->blueL, $service->resolveByComposition($this->product, self::selection([[$this->color, $this->blue], [$this->size, $this->l]]))->id);
        $resolved = $service->resolveByComposition($this->product, self::selection([[$this->size, $this->l], [$this->color, $this->red]]));
        self::assertSame($this->redL, $resolved->id);
        self::assertSame([[$this->color, $this->red], [$this->size, $this->l]], array_map(static fn($pair): array => [$pair->optionId, $pair->optionValueId], $resolved->composition));
    }

    public function testResolvesASimpleProductWithAnEmptySelectionOnly(): void
    {
        $created = $this->namedProduct('SIMPLE');
        $this->activateProduct($created->productId);
        $service = $this->variantConsumerService();

        self::assertSame($created->variantId, $service->resolveByComposition($created->productId, new VariantCompositionCriteriaDTO())->id);

        $foreign = $this->preparedOption($created->productId, 'color', 'Color');
        $value = $this->preparedValue($foreign, 'red', 'Red');
        $this->assertThrows(
            ProductInvalidArgumentException::class,
            fn() => $service->resolveByComposition($created->productId, self::selection([[$foreign, $value]])),
            'A simple Product has no active Option, so a non-empty selection names an Option that is not selectable.',
        );
    }

    public function testStructurallyInvalidSelectionsAreValidationFailures(): void
    {
        $this->configurable();
        $service = $this->variantConsumerService();
        $other = $this->namedProduct('OTHER');
        $otherOption = $this->preparedOption($other->productId, 'color', 'Color');
        $otherValue = $this->preparedValue($otherOption, 'red', 'Red');
        $this->optionValueManagementService()->softDelete($this->green);
        $this->optionManagementService()->softDelete($this->material);

        $inactiveValue = $this->preparedValue($this->color, 'yellow', 'Yellow');

        $cases = [
            'inactive Value' => fn() => $service->resolveByComposition($this->product, self::selection([[$this->color, $inactiveValue], [$this->size, $this->m]])),
            'unknown Value' => fn() => $service->resolveByComposition($this->product, self::selection([[$this->color, 999999], [$this->size, $this->m]])),
            'repeated Option' => fn() => self::selection([[$this->color, $this->red], [$this->color, $this->blue], [$this->size, $this->m]]),
            'Option of another Product' => fn() => $service->resolveByComposition($this->product, self::selection([[$otherOption, $otherValue], [$this->size, $this->m]])),
            'Value of another Product under a local Option' => fn() => $service->resolveByComposition($this->product, self::selection([[$this->color, $otherValue], [$this->size, $this->m]])),
            'Value paired with the wrong Option' => fn() => $service->resolveByComposition($this->product, self::selection([[$this->color, $this->m], [$this->size, $this->red]])),
            'soft-deleted Value' => fn() => $service->resolveByComposition($this->product, self::selection([[$this->color, $this->green], [$this->size, $this->m]])),
            'soft-deleted Option' => fn() => $service->resolveByComposition($this->product, self::selection([[$this->color, $this->red], [$this->size, $this->m], [$this->material, $this->red]])),
            'unknown Option' => fn() => $service->resolveByComposition($this->product, self::selection([[$this->color, $this->red], [$this->size, $this->m], [999999, $this->red]])),
            'partial coverage' => fn() => $service->resolveByComposition($this->product, self::selection([[$this->color, $this->red]])),
            'empty selection on a configurable Product' => fn() => $service->resolveByComposition($this->product, new VariantCompositionCriteriaDTO()),
        ];
        foreach ($cases as $case => $call) {
            $this->assertThrows(ProductInvalidArgumentException::class, $call, $case . ' must be a validation failure.');
        }
        $partial = $this->assertThrows(ProductInvalidArgumentException::class, fn() => $service->resolveByComposition($this->product, self::selection([[$this->color, $this->red]])));
        self::assertStringContainsString('[' . $this->size . ']', $partial->getMessage(), 'The failure names the missing active Option.');
    }

    public function testAnInvisibleProductIsNotFoundBeforeTheSelectionIsJudged(): void
    {
        $this->configurable();
        $service = $this->variantConsumerService();
        $complete = self::selection([[$this->color, $this->red], [$this->size, $this->m]]);
        $partial = self::selection([[$this->color, $this->red]]);

        $this->assertThrows(ProductNotFoundException::class, fn() => $service->resolveByComposition(999999, $complete));

        $this->productManagementService()->updateStatus($this->product, ProductStatusEnum::INACTIVE);
        $this->assertThrows(ProductNotFoundException::class, fn() => $service->resolveByComposition($this->product, $complete), 'An inactive Product is not visible.');
        $this->assertThrows(ProductNotFoundException::class, fn() => $service->resolveByComposition($this->product, $partial), 'Product visibility is judged before the selection.');

        $this->productManagementService()->updateStatus($this->product, ProductStatusEnum::ACTIVE);
        $this->productManagementService()->softDelete($this->product);
        $this->assertThrows(ProductNotFoundException::class, fn() => $service->resolveByComposition($this->product, $complete), 'A soft-deleted Product is not visible.');
    }

    public function testAValidCompositionWithNoVisibleVariantIsAVariantNotFound(): void
    {
        $this->configurable();
        $service = $this->variantConsumerService();

        $this->assertThrows(
            VariantNotFoundException::class,
            fn() => $service->resolveByComposition($this->product, self::selection([[$this->color, $this->blue], [$this->size, $this->m]])),
            'blue-M is structurally valid but is not offered.',
        );

        // red-L becomes a historical inactive Variant while red and L stay active through other Variants.
        $kept = [
            [[[$this->color, $this->red], [$this->size, $this->m]], $this->redM],
            [[[$this->color, $this->blue], [$this->size, $this->l]], $this->blueL],
        ];
        $result = $this->productManagementService()->reconfigure($this->reconfiguration(
            $this->product,
            [$this->color => [$this->red, $this->blue], $this->size => [$this->m, $this->l]],
            $kept,
        ));
        self::assertSame([$this->redL], $result->deactivatedVariantIds);
        $notFound = $this->assertThrows(
            VariantNotFoundException::class,
            fn() => $service->resolveByComposition($this->product, self::selection([[$this->color, $this->red], [$this->size, $this->l]])),
            'A historical inactive composition resolves to no Variant and is never reactivated.',
        );
        self::assertStringContainsString('v1|' . $this->color . '=' . $this->red . '|' . $this->size . '=' . $this->l, $notFound->getMessage());
        self::assertSame('inactive', self::scalar('SELECT status FROM maa_product_variants WHERE id = :i', ['i' => $this->redL]));
        self::assertSame(4, self::rowCount('maa_product_variants'), 'Resolution never creates a Variant.');
    }

    public function testAMatchHiddenByTheProductWideRuleIsAVariantNotFound(): void
    {
        $this->configurable();
        $service = $this->variantConsumerService();
        $complete = self::selection([[$this->color, $this->red], [$this->size, $this->m]]);
        self::assertSame($this->redM, $service->resolveByComposition($this->product, $complete)->id);

        // green becomes an active, unrepresented Value only through direct SQL; the product-wide rule then hides the whole Product.
        $this->execute("UPDATE maa_product_option_values SET status = 'active' WHERE id = :i", ['i' => $this->green]);
        $this->assertThrows(VariantNotFoundException::class, fn() => $service->resolveByComposition($this->product, $complete));
        $this->execute("UPDATE maa_product_option_values SET status = 'inactive' WHERE id = :i", ['i' => $this->green]);
        self::assertSame($this->redM, $service->resolveByComposition($this->product, $complete)->id);
    }

    public function testAStoredFingerprintThatDoesNotMatchItsPairsIsAPersistenceFailure(): void
    {
        $this->configurable();
        $service = $this->variantConsumerService();

        // Corrupt storage: red-M keeps its fingerprint but its size pair now says L.
        $this->execute(
            'UPDATE maa_product_variant_option_values SET option_value_id = :l WHERE variant_id = :v AND option_id = :o',
            ['l' => $this->l, 'v' => $this->redM, 'o' => $this->size],
        );
        $failure = $this->assertThrows(
            ProductPersistenceException::class,
            fn() => $service->resolveByComposition($this->product, self::selection([[$this->color, $this->red], [$this->size, $this->m]])),
        );
        self::assertStringContainsString('[' . $this->redM . ']', $failure->getMessage());
    }

    public function testNonPositiveIdentitiesAreRejectedBeforeAnyRead(): void
    {
        $this->assertThrows(ProductInvalidArgumentException::class, fn() => $this->variantConsumerService()->resolveByComposition(0, new VariantCompositionCriteriaDTO()));
        $this->assertThrows(ProductInvalidArgumentException::class, fn() => new OptionValueSelection(1, 0));
        $this->assertThrows(ProductInvalidArgumentException::class, fn() => new OptionValueSelection(0, 1));
    }
}
