<?php

declare(strict_types=1);

namespace Maatify\Product\Variant\Repository;

use DateTimeImmutable;
use Maatify\Product\Variant\DTO\VariantLifecycleStateDTO;
use Maatify\Product\Variant\Enum\VariantStatusEnum;
use Maatify\Product\Variant\Exception\VariantBarcodeAlreadyExistsException;
use Maatify\Product\Variant\Exception\VariantSkuAlreadyExistsException;

/**
 * Write port for Variant persistence.
 *
 * Implementations never open or commit a transaction; the calling Service owns the
 * transaction boundary and must already hold one.
 *
 * @internal Not part of the Public Runtime API.
 */
interface VariantCommandRepositoryInterface
{
    /**
     * Creates the initial active empty-composition Variant of a Product just inserted in this transaction.
     *
     * The Product row is locked first so concurrent writers to the same Product
     * serialize, and the display order is allocated through the shared Ordering API.
     *
     * @param positive-int $productId Newly inserted Product identity.
     * @param DateTimeImmutable $occurredAt Host Clock instant stored as created and updated time.
     *
     * @return positive-int The new Variant identity.
     */
    public function createEmptyComposition(int $productId, DateTimeImmutable $occurredAt): int;

    /**
     * Locks the Variant row (`SELECT ... FOR UPDATE`) and returns its lifecycle state, or null when
     * no Variant has that identity. The owning Product must already be locked.
     */
    public function lockForUpdate(int $variantId): ?VariantLifecycleStateDTO;

    /**
     * Sets the status of a non-deleted Variant. Returns whether a row was changed.
     */
    public function updateStatus(int $variantId, VariantStatusEnum $status, DateTimeImmutable $occurredAt): bool;

    /**
     * Stores both secondary identifiers of a non-deleted Variant. Returns whether a row was changed.
     *
     * @throws VariantSkuAlreadyExistsException When another Variant, including a soft-deleted one, holds the SKU.
     * @throws VariantBarcodeAlreadyExistsException When another Variant, including a soft-deleted one, holds the barcode.
     */
    public function updateIdentifiers(int $variantId, ?string $sku, ?string $barcode, DateTimeImmutable $occurredAt): bool;

    /**
     * Soft-deletes a non-deleted Variant. Returns whether a row was changed.
     */
    public function softDelete(int $variantId, DateTimeImmutable $occurredAt): bool;

    /**
     * Restores a soft-deleted Variant, preserving its saved status, at the end of its Product's live
     * order (the next position of the shared Ordering API), in the same write. Returns whether a row was changed.
     */
    public function restore(int $variantId, int $productId, DateTimeImmutable $occurredAt): bool;

    /**
     * Moves a non-deleted row within its ordering scope through the shared Ordering API
     * (`moveWithinScope()`), which locks the scope, clamps a position above the maximum to it, leaves
     * a row already at the position unchanged, and stamps the moved row's `updated_at`. Returns false
     * when the row is not a live member of that scope or the target write affected no rows.
     */
    public function updateDisplayOrder(int $variantId, int $productId, int $displayOrder, DateTimeImmutable $occurredAt): bool;
}
