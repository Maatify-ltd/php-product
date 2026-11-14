<?php

declare(strict_types=1);

namespace Maatify\Product\ImageRole\Repository;

use DateTimeImmutable;
use Maatify\Product\ImageRole\DTO\ImageRoleStateDTO;
use Maatify\Product\ImageRole\Enum\ImageRoleStatusEnum;
use Maatify\Product\ImageRole\Exception\ImageRoleKeyAlreadyExistsException;
use Maatify\Product\ImageRole\Management\Command\CreateImageRoleCommand;

/**
 * Write port for Image Role persistence. The calling Service owns the transaction.
 *
 * @internal Not part of the Public Runtime API.
 */
interface ImageRoleCommandRepositoryInterface
{
    /**
     * Inserts an active Image Role.
     *
     * @return positive-int
     *
     * @throws ImageRoleKeyAlreadyExistsException When the key is already reserved, including by a soft-deleted Role.
     */
    public function create(CreateImageRoleCommand $command, DateTimeImmutable $occurredAt): int;

    /**
     * Locks the Role row and returns its state, or null when no Role has that identity.
     */
    public function lockForUpdate(int $roleId): ?ImageRoleStateDTO;

    public function updateStatus(int $roleId, ImageRoleStatusEnum $status, DateTimeImmutable $occurredAt): bool;

    public function softDelete(int $roleId, DateTimeImmutable $occurredAt): bool;

    public function restore(int $roleId, DateTimeImmutable $occurredAt): bool;
}
