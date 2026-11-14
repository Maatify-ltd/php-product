<?php

declare(strict_types=1);

namespace Maatify\Product\Tests\Unit\Variant\DTO;

use DateTimeImmutable;
use DateTimeZone;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;
use Maatify\Product\Variant\DTO\VariantCollectionDTO;
use Maatify\Product\Variant\DTO\VariantCompositionPairDTO;
use Maatify\Product\Variant\DTO\VariantDTO;
use Maatify\Product\Variant\Enum\VariantStatusEnum;
use PHPUnit\Framework\TestCase;

final class VariantDTOTest extends TestCase
{
    public function testItRejectsNonPositiveIdentitiesAndDisplayOrder(): void
    {
        $at = new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC'));

        foreach ([[0, 1, 1], [1, 0, 1], [1, 1, 0]] as [$id, $productId, $order]) {
            try {
                new VariantDTO($id, $productId, null, null, VariantStatusEnum::ACTIVE, $order, [], $at, $at, null);
                self::fail('A non-positive value must be rejected.');
            } catch (ProductInvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testCompositionPairsRequirePositiveIdentities(): void
    {
        $this->expectException(ProductInvalidArgumentException::class);
        new VariantCompositionPairDTO(1, 0);
    }

    public function testItSerializesTimestampsAsAtomStringsAndKeepsTheComposition(): void
    {
        $at = new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC'));
        $variant = new VariantDTO(7, 3, 'SKU-1', null, VariantStatusEnum::INACTIVE, 2, [new VariantCompositionPairDTO(4, 9)], $at, $at, null);

        $expected = [
            'id' => 7,
            'productId' => 3,
            'sku' => 'SKU-1',
            'barcode' => null,
            'status' => 'inactive',
            'displayOrder' => 2,
            'composition' => [['optionId' => 4, 'optionValueId' => 9]],
            'createdAt' => '2026-01-01T00:00:00+00:00',
            'updatedAt' => '2026-01-01T00:00:00+00:00',
            'deletedAt' => null,
        ];

        self::assertSame($expected, $variant->jsonSerialize());
        self::assertSame([$expected], (new VariantCollectionDTO([$variant]))->jsonSerialize());
    }

    public function testTheCollectionIsCountableAndIterable(): void
    {
        $at = new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC'));
        $variant = new VariantDTO(1, 1, null, null, VariantStatusEnum::ACTIVE, 1, [], $at, $at, null);
        $collection = new VariantCollectionDTO([$variant]);

        self::assertCount(1, $collection);
        self::assertFalse($collection->isEmpty());
        self::assertSame([$variant], iterator_to_array($collection));
        self::assertTrue((new VariantCollectionDTO([]))->isEmpty());
    }
}
