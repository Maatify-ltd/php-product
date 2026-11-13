<?php

declare(strict_types=1);

namespace Maatify\Product\Tests\Integration\Runtime;

use Maatify\Product\Common\Enum\ProductDeletedStateEnum;
use Maatify\Product\Common\Exception\ProductBusinessRuleException;
use Maatify\Product\Variant\Exception\VariantBarcodeAlreadyExistsException;
use Maatify\Product\Variant\Exception\VariantNotFoundException;
use Maatify\Product\Variant\Exception\VariantSkuAlreadyExistsException;
use Maatify\Product\Variant\Management\Command\UpdateVariantIdentifiersCommand;
use Maatify\Product\Variant\ValueObject\VariantIdentifierChange;
use PDOException;

/**
 * `VAR-01` against real MySQL: the SKU and barcode are set, replaced, and cleared independently,
 * a replaced or cleared value is released, a value held by another Variant (including a
 * soft-deleted one, and including a case variant) is a typed conflict, an unchanged request writes
 * nothing, and a soft-deleted Variant or Product blocks the change.
 */
final class VariantIdentifiersTest extends RuntimeTestCase
{
    public function testIdentifiersAreSetReplacedClearedAndReleased(): void
    {
        $a = $this->namedProduct('A');
        $b = $this->namedProduct('B');
        $service = $this->variantManagementService();

        $service->updateIdentifiers(self::change($a->variantId, VariantIdentifierChange::set(' SKU-1 '), VariantIdentifierChange::set('000123')));
        $variant = $service->getByIdForManagement($a->variantId);
        self::assertSame([$a->variantId, ' SKU-1 ', '000123'], [$variant->id, $variant->sku, $variant->barcode], 'Values are stored verbatim, leading zeroes included; the identity is unchanged.');

        $service->updateIdentifiers(self::change($a->variantId, VariantIdentifierChange::set('SKU-2'), VariantIdentifierChange::unchanged()));
        self::assertSame(['SKU-2', '000123'], $this->identifiersOf($a->variantId), 'An unchanged field keeps its value.');
        $service->updateIdentifiers(self::change($b->variantId, VariantIdentifierChange::set(' SKU-1 '), VariantIdentifierChange::unchanged()));
        self::assertSame([' SKU-1 ', null], $this->identifiersOf($b->variantId), 'A replaced value is released.');

        $service->updateIdentifiers(self::change($a->variantId, VariantIdentifierChange::unchanged(), VariantIdentifierChange::clear()));
        self::assertSame(['SKU-2', null], $this->identifiersOf($a->variantId));
        $service->updateIdentifiers(self::change($b->variantId, VariantIdentifierChange::unchanged(), VariantIdentifierChange::set('000123')));
        self::assertSame([' SKU-1 ', '000123'], $this->identifiersOf($b->variantId), 'A cleared value is released.');
    }

    public function testAValueHeldByAnotherVariantIsATypedConflict(): void
    {
        $a = $this->namedProduct('A');
        $b = $this->namedProduct('B');
        $service = $this->variantManagementService();
        $service->updateIdentifiers(self::change($a->variantId, VariantIdentifierChange::set('SKU-A'), VariantIdentifierChange::set('111')));

        $sku = $this->assertThrows(VariantSkuAlreadyExistsException::class, fn() => $service->updateIdentifiers(self::change($b->variantId, VariantIdentifierChange::set('sku-a'), VariantIdentifierChange::unchanged())), 'Case variants conflict.');
        self::assertInstanceOf(PDOException::class, $sku->getPrevious());
        self::assertStringContainsString('sku-a', $sku->getMessage());
        $barcode = $this->assertThrows(VariantBarcodeAlreadyExistsException::class, fn() => $service->updateIdentifiers(self::change($b->variantId, VariantIdentifierChange::unchanged(), VariantIdentifierChange::set('111'))));
        self::assertInstanceOf(PDOException::class, $barcode->getPrevious());
        self::assertSame([null, null], $this->identifiersOf($b->variantId), 'A rejected change writes nothing.');

        // The original empty-composition Variant becomes inactive after reconfiguration, so it can be soft deleted.
        $color = $this->preparedOption($a->productId, 'color', 'Color');
        $red = $this->preparedValue($color, 'red', 'Red');
        $this->productManagementService()->reconfigure($this->reconfiguration($a->productId, [$color => [$red]], [[[[$color, $red]]]]));
        $service->softDelete($a->variantId);
        $this->assertThrows(VariantSkuAlreadyExistsException::class, fn() => $service->updateIdentifiers(self::change($b->variantId, VariantIdentifierChange::set('SKU-A'), VariantIdentifierChange::unchanged())), 'A soft-deleted Variant keeps its SKU reserved.');
        $this->assertThrows(VariantBarcodeAlreadyExistsException::class, fn() => $service->updateIdentifiers(self::change($b->variantId, VariantIdentifierChange::unchanged(), VariantIdentifierChange::set('111'))));
        $this->assertThrows(ProductBusinessRuleException::class, fn() => $service->updateIdentifiers(self::change($a->variantId, VariantIdentifierChange::clear(), VariantIdentifierChange::clear())), 'A soft-deleted Variant accepts only restore.');
        self::assertSame('SKU-A', $service->getByIdForManagement($a->variantId, ProductDeletedStateEnum::DELETED_ONLY)->sku);
    }

    public function testTheConflictIsClassifiedByTheViolatedKeyNotByTheValue(): void
    {
        $a = $this->namedProduct('A');
        $b = $this->namedProduct('B');
        $service = $this->variantManagementService();
        $service->updateIdentifiers(self::change($a->variantId, VariantIdentifierChange::unchanged(), VariantIdentifierChange::set('uq_maa_product_variants_sku-1')));

        $conflict = $this->assertThrows(
            VariantBarcodeAlreadyExistsException::class,
            fn() => $service->updateIdentifiers(self::change($b->variantId, VariantIdentifierChange::set('B-SKU'), VariantIdentifierChange::set('uq_maa_product_variants_sku-1'))),
            'A barcode that contains the SKU key name is still a barcode conflict.',
        );
        self::assertStringContainsString('uq_maa_product_variants_sku-1', $conflict->getMessage());
        self::assertSame([null, null], $this->identifiersOf($b->variantId));
    }

    public function testAnUnchangedRequestWritesNothingAndOwnersAreChecked(): void
    {
        $a = $this->namedProduct('A');
        $this->variantManagementService()->updateIdentifiers(self::change($a->variantId, VariantIdentifierChange::set('SKU-A'), VariantIdentifierChange::unchanged()));
        $later = $this->variantManagementService(self::laterClock());

        $this->assertNoWrite('maa_product_variants', fn() => $later->updateIdentifiers(self::change($a->variantId, VariantIdentifierChange::unchanged(), VariantIdentifierChange::unchanged())), 'Unchanged fields write nothing.');
        $this->assertNoWrite('maa_product_variants', fn() => $later->updateIdentifiers(self::change($a->variantId, VariantIdentifierChange::set('SKU-A'), VariantIdentifierChange::clear())), 'The same value and clearing a NULL write nothing.');
        $later->updateIdentifiers(self::change($a->variantId, VariantIdentifierChange::set('sku-a'), VariantIdentifierChange::unchanged()));
        self::assertSame(self::LATER_TS, self::scalar('SELECT updated_at FROM maa_product_variants WHERE id = :i', ['i' => $a->variantId]), 'A case change is a real change.');

        $this->assertThrows(VariantNotFoundException::class, fn() => $later->updateIdentifiers(self::change(999999, VariantIdentifierChange::clear(), VariantIdentifierChange::clear())));
        $this->productManagementService()->softDelete($a->productId);
        $this->assertThrows(ProductBusinessRuleException::class, fn() => $later->updateIdentifiers(self::change($a->variantId, VariantIdentifierChange::clear(), VariantIdentifierChange::unchanged())), 'A soft-deleted Product blocks the change.');
        self::assertSame(['sku-a', null], $this->identifiersOf($a->variantId));
    }

    private static function change(int $variantId, VariantIdentifierChange $sku, VariantIdentifierChange $barcode): UpdateVariantIdentifiersCommand
    {
        return new UpdateVariantIdentifiersCommand($variantId, $sku, $barcode);
    }

    /** @return array{int|string|null, int|string|null} */
    private function identifiersOf(int $variantId): array
    {
        return [
            self::scalar('SELECT sku FROM maa_product_variants WHERE id = :i', ['i' => $variantId]),
            self::scalar('SELECT barcode FROM maa_product_variants WHERE id = :i', ['i' => $variantId]),
        ];
    }
}
