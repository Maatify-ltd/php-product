<?php

declare(strict_types=1);

namespace Maatify\Product\ImageAssignment\Repository;

use DateTimeImmutable;
use Maatify\Product\ImageAssignment\DTO\ImageAssignmentIdentityDTO;
use Maatify\Product\ImageAssignment\DTO\ImageAssignmentStateDTO;
use Maatify\Product\ImageAssignment\Exception\ImageAssignmentAlreadyExistsException;
use Maatify\Product\ImageAssignment\Exception\ImageAssignmentDefaultConflictException;
use Maatify\Product\ImageAssignment\Management\Command\CreateImageAssignmentCommand;
use Maatify\Product\ImageAssignment\ValueObject\ImageAssignmentScope;

/**
 * Write port for Image Assignment persistence. The calling Service owns the transaction and holds
 * the Product (and Variant, Role) locks in the stable order before calling.
 *
 * @internal Not part of the Public Runtime API.
 */
interface ImageAssignmentCommandRepositoryInterface
{
    /**
     * Inserts a non-default assignment whose position within its exact scope comes from the shared Ordering API.
     *
     * @return positive-int
     *
     * @throws ImageAssignmentAlreadyExistsException When the identity is already taken, including by a soft-deleted assignment.
     *
     * The caller must hold the Product lock in an active transaction.
     */
    public function create(CreateImageAssignmentCommand $command, ImageAssignmentScope $scope, DateTimeImmutable $occurredAt): int;

    /**
     * The immutable owner identity (Product, target Variant, Role) of an assignment, whatever its
     * state, read without a lock; null when none has that identity.
     */
    public function findIdentity(int $assignmentId): ?ImageAssignmentIdentityDTO;

    /**
     * Locks the assignment row and returns its state, or null when none has that identity.
     */
    public function lockForUpdate(int $assignmentId): ?ImageAssignmentStateDTO;

    /**
     * Clears the default marker of the scope's live default, read as {@see lockLiveDefault()} does,
     * then marks this one default. The database unique key on the default scope is the final guard.
     */
    public function setDefault(int $assignmentId, int $productId, string $scope, DateTimeImmutable $occurredAt): bool;

    public function clearDefault(int $assignmentId, DateTimeImmutable $occurredAt): bool;

    /**
     * Soft-deletes the assignment and clears its default marker in the same write; no other row is promoted.
     */
    public function softDelete(int $assignmentId, DateTimeImmutable $occurredAt): bool;

    /**
     * Restores the assignment as non-default, at the end of its exact scope's live order (the next
     * position of the shared Ordering API), in the same write. The caller must hold the Product lock in an active transaction.
     */
    public function restore(int $assignmentId, string $scope, DateTimeImmutable $occurredAt): bool;

    /**
     * The live default of the exact scope of the given Product, read with a locking read so it
     * reflects the latest committed state; null when the scope has no live default. The read locks
     * only that Product's assignment rows.
     */
    public function lockLiveDefault(int $productId, string $scope): ?int;

    /**
     * Restores the assignment as the default of its exact scope, at the end of the scope's live
     * order (the next position of the shared Ordering API), in one write. It never clears another
     * row. The database unique key on the default scope is the final guard.
     *
     * @throws ImageAssignmentDefaultConflictException When that key reports a live default in the scope.
     *
     * The caller must hold the Product lock in an active transaction.
     */
    public function restoreAsDefault(int $assignmentId, int $productId, string $scope, DateTimeImmutable $occurredAt): bool;

    /**
     * Moves a non-deleted assignment within its exact scope through the shared Ordering API
     * (`moveWithinScope()`), which locks the scope, clamps a position above the maximum to it, leaves
     * a row already at the position unchanged, and stamps the moved row's `updated_at`. Returns false
     * when the assignment is not a live member of that scope or the target write affected no rows.
     */
    public function updateDisplayOrder(int $assignmentId, string $scope, int $displayOrder, DateTimeImmutable $occurredAt): bool;
}
