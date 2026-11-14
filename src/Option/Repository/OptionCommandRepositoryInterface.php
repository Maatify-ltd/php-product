<?php

declare(strict_types=1);

namespace Maatify\Product\Option\Repository;

use DateTimeImmutable;
use Maatify\Product\Option\DTO\OptionStateDTO;
use Maatify\Product\Option\Enum\OptionStatusEnum;
use Maatify\Product\Option\Exception\OptionCodeAlreadyExistsException;
use Maatify\Product\Option\Management\Command\CreateOptionCommand;

/**
 * Write port for Option persistence. The calling Service owns the transaction and holds the Product lock.
 *
 * @internal Not part of the Public Runtime API.
 */
interface OptionCommandRepositoryInterface
{
    /**
     * Inserts an inactive Option whose position comes from the shared Ordering API.
     *
     * @return positive-int
     *
     * @throws OptionCodeAlreadyExistsException When the code is already reserved in the Product, including by a soft-deleted Option.
     *
     * The caller must hold the Product lock in an active transaction.
     */
    public function create(CreateOptionCommand $command, DateTimeImmutable $occurredAt): int;

    /**
     * Locks the Option row and returns its state, or null when no Option has that identity.
     */
    public function lockForUpdate(int $optionId): ?OptionStateDTO;

    /**
     * The Product an Option belongs to, whatever the Option's state, or null when no Option has that identity.
     */
    public function findProductId(int $optionId): ?int;

    /**
     * Writes the status and `updated_at` of a non-deleted Option. Returns whether a row was changed.
     */
    public function updateStatus(int $optionId, OptionStatusEnum $status, DateTimeImmutable $occurredAt): bool;

    public function softDelete(int $optionId, DateTimeImmutable $occurredAt): bool;

    /**
     * Restores a soft-deleted Option at the end of its Product's live order (the next position of the
     * shared Ordering API), in the same write. The caller must hold the Product lock in an active transaction. Returns whether a row was changed.
     */
    public function restore(int $optionId, int $productId, DateTimeImmutable $occurredAt): bool;

    /**
     * Moves a non-deleted row within its ordering scope through the shared Ordering API
     * (`moveWithinScope()`), which locks the scope, clamps a position above the maximum to it, leaves
     * a row already at the position unchanged, and stamps the moved row's `updated_at`. Returns false
     * when the row is not a live member of that scope or the target write affected no rows.
     */
    public function updateDisplayOrder(int $optionId, int $productId, int $displayOrder, DateTimeImmutable $occurredAt): bool;
}
