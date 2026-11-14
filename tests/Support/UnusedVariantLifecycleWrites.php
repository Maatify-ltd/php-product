<?php

declare(strict_types=1);

namespace Maatify\Product\Tests\Support;

use DateTimeImmutable;
use LogicException;
use Maatify\Product\Variant\DTO\VariantLifecycleStateDTO;
use Maatify\Product\Variant\Enum\VariantStatusEnum;

/**
 * Lifecycle writes of {@see \Maatify\Product\Variant\Repository\VariantCommandRepositoryInterface}
 * for test doubles that only exercise Product creation; calling one is a test error.
 */
trait UnusedVariantLifecycleWrites
{
    public function lockForUpdate(int $variantId): ?VariantLifecycleStateDTO
    {
        throw new LogicException('lockForUpdate is not expected in this test.');
    }

    public function updateStatus(int $variantId, VariantStatusEnum $status, DateTimeImmutable $occurredAt): bool
    {
        throw new LogicException('updateStatus is not expected in this test.');
    }

    public function updateIdentifiers(int $variantId, ?string $sku, ?string $barcode, DateTimeImmutable $occurredAt): bool
    {
        throw new LogicException('updateIdentifiers is not expected in this test.');
    }

    public function softDelete(int $variantId, DateTimeImmutable $occurredAt): bool
    {
        throw new LogicException('softDelete is not expected in this test.');
    }

    public function restore(int $variantId, int $productId, DateTimeImmutable $occurredAt): bool
    {
        throw new LogicException('restore is not expected in this test.');
    }

    public function updateDisplayOrder(int $variantId, int $productId, int $displayOrder, DateTimeImmutable $occurredAt): bool
    {
        throw new LogicException('updateDisplayOrder is not expected in this test.');
    }
}
