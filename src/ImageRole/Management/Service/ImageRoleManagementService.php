<?php

declare(strict_types=1);

namespace Maatify\Product\ImageRole\Management\Service;

use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\PhpPersistence\Pagination\DTO\PageResultDTO;
use Maatify\PhpPersistence\Transaction\Repository\SavepointTransactionRunnerInterface;
use Maatify\Product\Common\Enum\ProductDeletedStateEnum;
use Maatify\Product\Common\Exception\ProductBusinessRuleException;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;
use Maatify\Product\Common\Exception\ProductStorageFailureFactory;
use Maatify\Product\Common\Validation\TextInputRules;
use Maatify\Product\ImageRole\DTO\CreatedImageRoleDTO;
use Maatify\Product\ImageRole\DTO\ImageRoleCollectionDTO;
use Maatify\Product\ImageRole\DTO\ImageRoleDTO;
use Maatify\Product\ImageRole\DTO\ImageRoleStateDTO;
use Maatify\Product\ImageRole\Enum\ImageRoleStatusEnum;
use Maatify\Product\ImageRole\Exception\ImageRoleNotFoundException;
use Maatify\Product\ImageRole\Management\Command\CreateImageRoleCommand;
use Maatify\Product\ImageRole\Management\Criteria\ImageRoleListCriteriaDTO;
use Maatify\Product\ImageRole\Repository\ImageRoleCommandRepositoryInterface;
use Maatify\Product\ImageRole\Repository\ImageRoleQueryReaderInterface;
use Maatify\SharedCommon\Contracts\ClockInterface;

/**
 * Orchestrates Image Role creation, status, lifecycle, and management reads.
 *
 * @internal Not part of the Public Runtime API.
 */
final readonly class ImageRoleManagementService implements ImageRoleManagementServiceInterface
{
    public function __construct(
        private ImageRoleCommandRepositoryInterface $roles,
        private ImageRoleQueryReaderInterface $reader,
        private SavepointTransactionRunnerInterface $transactions,
        private ClockInterface $clock,
    ) {}

    public function create(CreateImageRoleCommand $command): CreatedImageRoleDTO
    {
        $occurredAt = $this->clock->now();

        return $this->transactions->run(
            fn(): CreatedImageRoleDTO => new CreatedImageRoleDTO($this->roles->create($command, $occurredAt)),
        );
    }

    public function updateStatus(int $roleId, ImageRoleStatusEnum $status): void
    {
        self::requirePositive($roleId);
        $occurredAt = $this->clock->now();

        $this->transactions->run(function () use ($roleId, $status, $occurredAt): void {
            $role = $this->lockedRole($roleId);
            if ($role->deleted) {
                throw ProductBusinessRuleException::softDeleted('Image Role', $roleId);
            }
            if ($role->active === ($status === ImageRoleStatusEnum::ACTIVE)) {
                return;
            }
            if (!$this->roles->updateStatus($roleId, $status, $occurredAt)) {
                throw ProductStorageFailureFactory::lockedRowVanished('Image Role', $roleId);
            }
        });
    }

    public function softDelete(int $roleId): void
    {
        self::requirePositive($roleId);
        $occurredAt = $this->clock->now();

        $this->transactions->run(function () use ($roleId, $occurredAt): void {
            $role = $this->lockedRole($roleId);
            if ($role->deleted) {
                throw ProductBusinessRuleException::softDeleted('Image Role', $roleId);
            }
            if (!$this->roles->softDelete($roleId, $occurredAt)) {
                throw ProductStorageFailureFactory::lockedRowVanished('Image Role', $roleId);
            }
        });
    }

    public function restore(int $roleId): void
    {
        self::requirePositive($roleId);
        $occurredAt = $this->clock->now();

        $this->transactions->run(function () use ($roleId, $occurredAt): void {
            $role = $this->lockedRole($roleId);
            if (!$role->deleted) {
                throw ProductBusinessRuleException::notSoftDeleted('Image Role', $roleId);
            }
            if (!$this->roles->restore($roleId, $occurredAt)) {
                throw ProductStorageFailureFactory::lockedRowVanished('Image Role', $roleId);
            }
        });
    }

    public function getByIdForManagement(
        int $roleId,
        ProductDeletedStateEnum $deletedState = ProductDeletedStateEnum::NON_DELETED,
    ): ImageRoleDTO {
        self::requirePositive($roleId);
        $role = $this->reader->findById($roleId);
        $matches = $role !== null && match ($deletedState) {
            ProductDeletedStateEnum::NON_DELETED => $role->deletedAt === null,
            ProductDeletedStateEnum::DELETED_ONLY => $role->deletedAt !== null,
            ProductDeletedStateEnum::INCLUDE_DELETED => true,
        };
        if ($role === null || !$matches) {
            throw ImageRoleNotFoundException::withId($roleId);
        }

        return $role;
    }

    public function getByKeyForManagement(
        string $roleKey,
        ProductDeletedStateEnum $deletedState = ProductDeletedStateEnum::NON_DELETED,
    ): ImageRoleDTO {
        TextInputRules::requiredText($roleKey, 'roleKey', CreateImageRoleCommand::MAX_ROLE_KEY_LENGTH);
        $role = $this->reader->findByKey($roleKey);
        $matches = $role !== null && match ($deletedState) {
            ProductDeletedStateEnum::NON_DELETED => $role->deletedAt === null,
            ProductDeletedStateEnum::DELETED_ONLY => $role->deletedAt !== null,
            ProductDeletedStateEnum::INCLUDE_DELETED => true,
        };
        if ($role === null || !$matches) {
            throw ImageRoleNotFoundException::withKey($roleKey);
        }

        return $role;
    }

    public function listForManagement(ImageRoleListCriteriaDTO $criteria = new ImageRoleListCriteriaDTO()): ImageRoleCollectionDTO
    {
        return new ImageRoleCollectionDTO($this->reader->listForManagement($criteria));
    }

    public function paginateForManagement(ImageRoleListCriteriaDTO $criteria, PageRequest $request): PageResultDTO
    {
        return $this->reader->paginateForManagement($criteria, $request);
    }

    private function lockedRole(int $roleId): ImageRoleStateDTO
    {
        $role = $this->roles->lockForUpdate($roleId);
        if ($role === null) {
            throw ImageRoleNotFoundException::withId($roleId);
        }

        return $role;
    }

    private static function requirePositive(int $roleId): void
    {
        if ($roleId < 1) {
            throw ProductInvalidArgumentException::nonPositiveId('roleId');
        }
    }
}
