<?php

declare(strict_types=1);

namespace Maatify\Product\Product\Repository;

use DateTimeImmutable;
use Maatify\Product\Product\DTO\ProductLifecycleStateDTO;
use Maatify\Product\Product\Enum\ProductStatusEnum;
use Maatify\Product\Product\Exception\ProductCodeAlreadyExistsException;
use Maatify\Product\Product\Management\Command\CreateProductCommand;

/**
 * Write port for Product persistence.
 *
 * Implementations never open or commit a transaction; the calling Service owns the
 * transaction boundary.
 *
 * @internal Not part of the Public Runtime API.
 */
interface ProductCommandRepositoryInterface
{
    /**
     * Inserts a new inactive Product and returns its identity.
     *
     * @param DateTimeImmutable $occurredAt Host Clock instant stored as created and updated time.
     *
     * @return positive-int The new Product identity.
     *
     * @throws ProductCodeAlreadyExistsException When the code is already reserved, including by a soft-deleted Product.
     */
    public function create(CreateProductCommand $command, DateTimeImmutable $occurredAt): int;

    /**
     * Takes the Product aggregate lock (`SELECT ... FOR UPDATE`) for the surrounding transaction
     * and returns the Product's current lifecycle state, or null when no Product has that identity.
     *
     * Every mutation that can affect a Product's graph takes this lock before reading dependent state.
     */
    public function lockForUpdate(int $productId): ?ProductLifecycleStateDTO;

    /**
     * Sets the status of a non-deleted Product. Returns whether a row was changed.
     */
    public function updateStatus(int $productId, ProductStatusEnum $status, DateTimeImmutable $occurredAt): bool;

    /**
     * Soft-deletes a non-deleted Product. Returns whether a row was changed.
     */
    public function softDelete(int $productId, DateTimeImmutable $occurredAt): bool;

    /**
     * Restores a soft-deleted Product, preserving its saved status. Returns whether a row was changed.
     */
    public function restore(int $productId, DateTimeImmutable $occurredAt): bool;
}
