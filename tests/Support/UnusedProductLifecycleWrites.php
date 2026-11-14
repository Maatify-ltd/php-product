<?php

declare(strict_types=1);

namespace Maatify\Product\Tests\Support;

use DateTimeImmutable;
use LogicException;
use Maatify\Product\Product\DTO\ProductLifecycleStateDTO;
use Maatify\Product\Product\Enum\ProductStatusEnum;

/**
 * Lifecycle writes of {@see \Maatify\Product\Product\Repository\ProductCommandRepositoryInterface}
 * for test doubles that only exercise Product creation; calling one is a test error.
 */
trait UnusedProductLifecycleWrites
{
    public function lockForUpdate(int $productId): ?ProductLifecycleStateDTO
    {
        throw new LogicException('lockForUpdate is not expected in this test.');
    }

    public function updateStatus(int $productId, ProductStatusEnum $status, DateTimeImmutable $occurredAt): bool
    {
        throw new LogicException('updateStatus is not expected in this test.');
    }

    public function softDelete(int $productId, DateTimeImmutable $occurredAt): bool
    {
        throw new LogicException('softDelete is not expected in this test.');
    }

    public function restore(int $productId, DateTimeImmutable $occurredAt): bool
    {
        throw new LogicException('restore is not expected in this test.');
    }
}
