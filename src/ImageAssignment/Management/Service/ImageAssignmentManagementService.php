<?php

declare(strict_types=1);

namespace Maatify\Product\ImageAssignment\Management\Service;

use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\PhpPersistence\Pagination\DTO\PageResultDTO;
use Maatify\PhpPersistence\Transaction\Repository\SavepointTransactionRunnerInterface;
use Maatify\Product\Common\Enum\ProductDeletedStateEnum;
use Maatify\Product\Common\Exception\ProductBusinessRuleException;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;
use Maatify\Product\Common\Exception\ProductStorageFailureFactory;
use Maatify\Product\ImageAssignment\DTO\CreatedImageAssignmentDTO;
use Maatify\Product\ImageAssignment\DTO\ImageAssignmentCollectionDTO;
use Maatify\Product\ImageAssignment\DTO\ImageAssignmentDTO;
use Maatify\Product\ImageAssignment\DTO\ImageAssignmentStateDTO;
use Maatify\Product\ImageAssignment\Exception\ImageAssignmentDefaultConflictException;
use Maatify\Product\ImageAssignment\Exception\ImageAssignmentNotFoundException;
use Maatify\Product\ImageAssignment\Management\Command\CreateImageAssignmentCommand;
use Maatify\Product\ImageAssignment\Management\Command\UpdateImageAssignmentDisplayOrderCommand;
use Maatify\Product\ImageAssignment\Management\Criteria\ImageAssignmentListCriteriaDTO;
use Maatify\Product\ImageAssignment\Repository\ImageAssignmentCommandRepositoryInterface;
use Maatify\Product\ImageAssignment\Repository\ImageAssignmentQueryReaderInterface;
use Maatify\Product\ImageRole\Exception\ImageRoleNotFoundException;
use Maatify\Product\ImageRole\Repository\ImageRoleCommandRepositoryInterface;
use Maatify\Product\Product\Exception\ProductNotFoundException;
use Maatify\Product\Product\Repository\ProductCommandRepositoryInterface;
use Maatify\Product\Variant\Exception\VariantNotFoundException;
use Maatify\Product\Variant\Repository\VariantCommandRepositoryInterface;
use Maatify\SharedCommon\Contracts\ClockInterface;

/**
 * Orchestrates Image Assignment creation, default operations, lifecycle, ordering, and management reads.
 *
 * Lock order inside every mutation: Product, Variant (when the target is a Variant), Role (when the
 * scope has one), then the assignment row. The default write clears the scope's previous default
 * and sets the new one in the same transaction; restore-as-default instead fails when the scope
 * has a live default, read under those locks. The database unique key is the final guard.
 *
 * @internal Not part of the Public Runtime API.
 */
final readonly class ImageAssignmentManagementService implements ImageAssignmentManagementServiceInterface
{
    public function __construct(
        private ImageAssignmentCommandRepositoryInterface $assignments,
        private ImageAssignmentQueryReaderInterface $reader,
        private ProductCommandRepositoryInterface $products,
        private VariantCommandRepositoryInterface $variants,
        private ImageRoleCommandRepositoryInterface $roles,
        private SavepointTransactionRunnerInterface $transactions,
        private ClockInterface $clock,
    ) {}

    public function create(CreateImageAssignmentCommand $command): CreatedImageAssignmentDTO
    {
        $occurredAt = $this->clock->now();

        return $this->transactions->run(function () use ($command, $occurredAt): CreatedImageAssignmentDTO {
            $product = $this->products->lockForUpdate($command->productId);
            if ($product === null) {
                throw ProductNotFoundException::withId($command->productId);
            }
            if ($product->deleted) {
                throw ProductBusinessRuleException::ownerSoftDeletedForCreate('Image Assignment', 'Product', $command->productId);
            }
            if ($command->variantId !== null) {
                $variant = $this->variants->lockForUpdate($command->variantId);
                if ($variant === null) {
                    throw VariantNotFoundException::withId($command->variantId);
                }
                if ($variant->productId !== $command->productId) {
                    throw ProductInvalidArgumentException::variantNotOfProduct($command->variantId, $command->productId);
                }
                if ($variant->deleted) {
                    throw ProductBusinessRuleException::ownerSoftDeletedForCreate('Image Assignment', 'Variant', $command->variantId);
                }
            }
            if ($command->roleId !== null) {
                $role = $this->roles->lockForUpdate($command->roleId);
                if ($role === null) {
                    throw ImageRoleNotFoundException::withId($command->roleId);
                }
                if ($role->deleted || !$role->active) {
                    throw ProductBusinessRuleException::imageRoleNotActive($command->roleId);
                }
            }
            return new CreatedImageAssignmentDTO($this->assignments->create($command, $occurredAt));
        });
    }

    public function setDefault(int $assignmentId): void
    {
        self::requirePositive($assignmentId);
        $occurredAt = $this->clock->now();

        $this->transactions->run(function () use ($assignmentId, $occurredAt): void {
            $assignment = $this->lockedAssignment($assignmentId, lockRole: true);
            if ($assignment->deleted) {
                throw ProductBusinessRuleException::softDeleted('Image Assignment', $assignmentId);
            }
            if ($assignment->isDefault) {
                return;
            }
            if (!$this->assignments->setDefault($assignmentId, $assignment->productId, $assignment->scope, $occurredAt)) {
                throw ProductStorageFailureFactory::lockedRowVanished('Image Assignment', $assignmentId);
            }
        });
    }

    public function clearDefault(int $assignmentId): void
    {
        self::requirePositive($assignmentId);
        $occurredAt = $this->clock->now();

        $this->transactions->run(function () use ($assignmentId, $occurredAt): void {
            $assignment = $this->lockedAssignment($assignmentId, lockRole: false, requireLiveOwners: false);
            if ($assignment->deleted) {
                throw ProductBusinessRuleException::softDeleted('Image Assignment', $assignmentId);
            }
            if (!$assignment->isDefault) {
                return;
            }
            if (!$this->assignments->clearDefault($assignmentId, $occurredAt)) {
                throw ProductStorageFailureFactory::lockedRowVanished('Image Assignment', $assignmentId);
            }
        });
    }

    public function softDelete(int $assignmentId): void
    {
        self::requirePositive($assignmentId);
        $occurredAt = $this->clock->now();

        $this->transactions->run(function () use ($assignmentId, $occurredAt): void {
            $assignment = $this->lockedAssignment($assignmentId, lockRole: false);
            if ($assignment->deleted) {
                throw ProductBusinessRuleException::softDeleted('Image Assignment', $assignmentId);
            }
            if (!$this->assignments->softDelete($assignmentId, $occurredAt)) {
                throw ProductStorageFailureFactory::lockedRowVanished('Image Assignment', $assignmentId);
            }
        });
    }

    public function updateDisplayOrder(UpdateImageAssignmentDisplayOrderCommand $command): void
    {
        $assignmentId = $command->assignmentId;
        $occurredAt = $this->clock->now();

        $this->transactions->run(function () use ($command, $assignmentId, $occurredAt): void {
            $assignment = $this->lockedAssignment($assignmentId, lockRole: false);
            if ($assignment->deleted) {
                throw ProductBusinessRuleException::softDeleted('Image Assignment', $assignmentId);
            }
            if (!$this->assignments->updateDisplayOrder($assignmentId, $assignment->scope, $command->displayOrder, $occurredAt)) {
                throw ProductStorageFailureFactory::lockedRowVanished('Image Assignment', $assignmentId);
            }
        });
    }

    public function restore(int $assignmentId): void
    {
        self::requirePositive($assignmentId);
        $occurredAt = $this->clock->now();

        $this->transactions->run(function () use ($assignmentId, $occurredAt): void {
            $assignment = $this->lockedAssignment($assignmentId, lockRole: false);
            if (!$assignment->deleted) {
                throw ProductBusinessRuleException::notSoftDeleted('Image Assignment', $assignmentId);
            }
            if (!$this->assignments->restore($assignmentId, $assignment->scope, $occurredAt)) {
                throw ProductStorageFailureFactory::lockedRowVanished('Image Assignment', $assignmentId);
            }
        });
    }

    public function restoreAsDefault(int $assignmentId): void
    {
        self::requirePositive($assignmentId);
        $occurredAt = $this->clock->now();

        $this->transactions->run(function () use ($assignmentId, $occurredAt): void {
            $assignment = $this->lockedAssignment($assignmentId, lockRole: true);
            if (!$assignment->deleted) {
                throw ProductBusinessRuleException::notSoftDeleted('Image Assignment', $assignmentId);
            }
            $default = $this->assignments->lockLiveDefault($assignment->productId, $assignment->scope);
            if ($default !== null) {
                throw ImageAssignmentDefaultConflictException::forRestoreAsDefault($assignmentId, $default);
            }
            if (!$this->assignments->restoreAsDefault($assignmentId, $assignment->productId, $assignment->scope, $occurredAt)) {
                throw ProductStorageFailureFactory::lockedRowVanished('Image Assignment', $assignmentId);
            }
        });
    }

    public function getByIdForManagement(
        int $assignmentId,
        ProductDeletedStateEnum $deletedState = ProductDeletedStateEnum::NON_DELETED,
    ): ImageAssignmentDTO {
        self::requirePositive($assignmentId);
        $assignment = $this->reader->findById($assignmentId);
        $matches = $assignment !== null && match ($deletedState) {
            ProductDeletedStateEnum::NON_DELETED => $assignment->deletedAt === null,
            ProductDeletedStateEnum::DELETED_ONLY => $assignment->deletedAt !== null,
            ProductDeletedStateEnum::INCLUDE_DELETED => true,
        };
        if ($assignment === null || !$matches) {
            throw ImageAssignmentNotFoundException::withId($assignmentId);
        }

        return $assignment;
    }

    public function listForManagement(ImageAssignmentListCriteriaDTO $criteria): ImageAssignmentCollectionDTO
    {
        return new ImageAssignmentCollectionDTO($this->reader->listForManagement($criteria));
    }

    public function paginateForManagement(ImageAssignmentListCriteriaDTO $criteria, PageRequest $request): PageResultDTO
    {
        return $this->reader->paginateForManagement($criteria, $request);
    }

    /**
     * Locks the owner chain in the stable order (Product, target Variant, Role) and then the
     * assignment row, and validates only the locked state.
     *
     * The owner identities come from an unlocked read of the assignment's identity columns, which no
     * write path changes; they only select the rows to lock, so no state is validated before the
     * Product lock.
     *
     * @param bool $lockRole Also lock the Role and require it to be non-deleted (set-default, restore-as-default).
     * @param bool $requireLiveOwners Require the target Product and Variant to be non-deleted.
     */
    private function lockedAssignment(int $assignmentId, bool $lockRole, bool $requireLiveOwners = true): ImageAssignmentStateDTO
    {
        $identity = $this->assignments->findIdentity($assignmentId);
        if ($identity === null) {
            throw ImageAssignmentNotFoundException::withId($assignmentId);
        }
        $product = $this->products->lockForUpdate($identity->productId);
        if ($product === null) {
            throw ImageAssignmentNotFoundException::withId($assignmentId);
        }
        $variant = $identity->variantId === null ? null : $this->variants->lockForUpdate($identity->variantId);
        $role = $lockRole && $identity->roleId !== null ? $this->roles->lockForUpdate($identity->roleId) : null;
        $assignment = $this->assignments->lockForUpdate($assignmentId);
        if ($assignment === null) {
            throw ImageAssignmentNotFoundException::withId($assignmentId);
        }

        if ($requireLiveOwners && $product->deleted) {
            throw ProductBusinessRuleException::ownerSoftDeleted('Image Assignment', $assignmentId, 'Product', $identity->productId);
        }
        if ($requireLiveOwners && $identity->variantId !== null && ($variant === null || $variant->deleted)) {
            throw ProductBusinessRuleException::ownerSoftDeleted('Image Assignment', $assignmentId, 'Variant', $identity->variantId);
        }
        if ($lockRole && $identity->roleId !== null && ($role === null || $role->deleted)) {
            throw ProductBusinessRuleException::ownerSoftDeleted('Image Assignment', $assignmentId, 'Image Role', $identity->roleId);
        }

        return $assignment;
    }

    private static function requirePositive(int $assignmentId): void
    {
        if ($assignmentId < 1) {
            throw ProductInvalidArgumentException::nonPositiveId('assignmentId');
        }
    }
}
