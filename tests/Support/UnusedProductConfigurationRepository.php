<?php

declare(strict_types=1);

namespace Maatify\Product\Tests\Support;

use DateTimeImmutable;
use LogicException;
use Maatify\Product\Product\DTO\ProductConfigurationSnapshotDTO;
use Maatify\Product\Product\Repository\ProductConfigurationRepositoryInterface;
use Maatify\Product\Variant\ValueObject\CompositionFingerprint;

/**
 * Configuration repository for unit tests that never reach the configuration graph; any call is a test error.
 */
final class UnusedProductConfigurationRepository implements ProductConfigurationRepositoryInterface
{
    public function findRevision(int $productId): ?int
    {
        throw new LogicException('findRevision is not expected in this test.');
    }

    public function advanceRevision(int $productId): void
    {
        throw new LogicException('advanceRevision is not expected in this test.');
    }

    public function lockSnapshot(int $productId): ?ProductConfigurationSnapshotDTO
    {
        throw new LogicException('lockSnapshot is not expected in this test.');
    }

    public function findVariantProductId(int $variantId): ?int
    {
        throw new LogicException('findVariantProductId is not expected in this test.');
    }

    public function updateOptionStatus(array $optionIds, bool $active, DateTimeImmutable $occurredAt): void
    {
        throw new LogicException('updateOptionStatus is not expected in this test.');
    }

    public function updateOptionValueStatus(array $optionValueIds, bool $active, DateTimeImmutable $occurredAt): void
    {
        throw new LogicException('updateOptionValueStatus is not expected in this test.');
    }

    public function updateVariantStatus(array $variantIds, bool $active, DateTimeImmutable $occurredAt): void
    {
        throw new LogicException('updateVariantStatus is not expected in this test.');
    }

    public function restoreVariantAsActive(int $variantId, int $productId, DateTimeImmutable $occurredAt): bool
    {
        throw new LogicException('restoreVariantAsActive is not expected in this test.');
    }

    public function createActiveVariant(int $productId, CompositionFingerprint $fingerprint, array $composition, DateTimeImmutable $occurredAt): int
    {
        throw new LogicException('createActiveVariant is not expected in this test.');
    }
}
