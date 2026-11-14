<?php

declare(strict_types=1);

namespace Maatify\Product\Product\Repository;

use DateTimeImmutable;
use Maatify\Product\Product\DTO\ProductConfigurationSnapshotDTO;
use Maatify\Product\Variant\ValueObject\CompositionFingerprint;
use Maatify\Product\Variant\ValueObject\OptionValueSelection;

/**
 * Write port over the configuration graph of one Product: the locked snapshot every
 * graph-affecting mutation reads, and the status, restore, and Variant-creation writes
 * that reconfiguration and the lifecycle commands apply to it.
 *
 * Implementations never open or commit a transaction; the calling Service owns the
 * transaction boundary and must already hold one.
 *
 * @internal Not part of the Public Runtime API.
 */
interface ProductConfigurationRepositoryInterface
{
    /** Current revision of a live Product. */
    public function findRevision(int $productId): ?int;

    /** Advance the revision inside the mutation transaction while holding the Product lock. */
    public function advanceRevision(int $productId): void;

    /**
     * Locks the Product row, then its Options, Option Values, and Variants in that order
     * (ascending identity within each table), and returns the complete graph as read under
     * those locks. Returns null when no Product has that identity.
     */
    public function lockSnapshot(int $productId): ?ProductConfigurationSnapshotDTO;

    /**
     * The Product a Variant belongs to, whatever the Variant's state, or null when no Variant has that identity.
     */
    public function findVariantProductId(int $variantId): ?int;

    /**
     * @param list<int> $optionIds
     */
    public function updateOptionStatus(array $optionIds, bool $active, DateTimeImmutable $occurredAt): void;

    /**
     * @param list<int> $optionValueIds
     */
    public function updateOptionValueStatus(array $optionValueIds, bool $active, DateTimeImmutable $occurredAt): void;

    /**
     * @param list<int> $variantIds
     */
    public function updateVariantStatus(array $variantIds, bool $active, DateTimeImmutable $occurredAt): void;

    /**
     * Restores a soft-deleted Variant and activates it in one write, at the end of its Product's live
     * order (the next position of the shared Ordering API). Returns whether a row was changed.
     *
     * The caller must hold the Product lock in an active transaction.
     */
    public function restoreVariantAsActive(int $variantId, int $productId, DateTimeImmutable $occurredAt): bool;

    /**
     * Inserts an active Variant with an immutable composition, allocating its position through the
     * shared Ordering API, and returns its identity. The caller must hold the Product lock in an active transaction.
     *
     * @param list<OptionValueSelection> $composition
     *
     * @return positive-int
     */
    public function createActiveVariant(
        int $productId,
        CompositionFingerprint $fingerprint,
        array $composition,
        DateTimeImmutable $occurredAt,
    ): int;
}
