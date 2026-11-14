<?php

declare(strict_types=1);

namespace Maatify\Product\Tests\Unit\Product\DTO;

use DateTimeImmutable;
use DateTimeZone;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;
use Maatify\Product\Product\DTO\ProductDTO;
use Maatify\Product\Product\Enum\ProductStatusEnum;
use PHPUnit\Framework\TestCase;

final class ProductDTOTest extends TestCase
{
    public function testItRejectsANonPositiveIdentity(): void
    {
        $at = new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC'));

        $this->expectException(ProductInvalidArgumentException::class);
        new ProductDTO(0, 'P-0', ProductStatusEnum::ACTIVE, $at, $at, null);
    }

    public function testItSerializesTimestampsAsAtomStrings(): void
    {
        $at = new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC'));
        $deleted = new DateTimeImmutable('2026-01-02 03:04:05', new DateTimeZone('UTC'));

        $expected = [
            'id' => 7,
            'code' => 'P-7',
            'status' => 'active',
            'createdAt' => '2026-01-01T00:00:00+00:00',
            'updatedAt' => '2026-01-01T00:00:00+00:00',
            'deletedAt' => '2026-01-02T03:04:05+00:00',
        ];

        self::assertSame($expected, (new ProductDTO(7, 'P-7', ProductStatusEnum::ACTIVE, $at, $at, $deleted))->jsonSerialize());
    }
}
