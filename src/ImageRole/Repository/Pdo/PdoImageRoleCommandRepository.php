<?php

declare(strict_types=1);

namespace Maatify\Product\ImageRole\Repository\Pdo;

use DateTimeImmutable;
use Maatify\Product\Common\Exception\ProductStorageFailureFactory;
use Maatify\Product\Common\Repository\Pdo\DigitString;
use Maatify\Product\Common\Repository\Pdo\DuplicateKey;
use Maatify\Product\Common\Repository\Pdo\RowHydrator;
use Maatify\Product\ImageRole\DTO\ImageRoleStateDTO;
use Maatify\Product\ImageRole\Enum\ImageRoleStatusEnum;
use Maatify\Product\ImageRole\Exception\ImageRoleKeyAlreadyExistsException;
use Maatify\Product\ImageRole\Management\Command\CreateImageRoleCommand;
use Maatify\Product\ImageRole\Repository\ImageRoleCommandRepositoryInterface;
use PDO;
use PDOException;

/**
 * PDO write adapter for Image Role persistence on MySQL 8.0.16 or later.
 *
 * Only a duplicate-key error (MySQL driver code 1062) on insert is converted, to
 * {@see ImageRoleKeyAlreadyExistsException}; the `role_key` key is the only unique key an insert
 * can violate besides the primary key. Every other {@see PDOException} propagates.
 *
 * @internal Not part of the Public Runtime API.
 */
final readonly class PdoImageRoleCommandRepository implements ImageRoleCommandRepositoryInterface
{
    private const string TABLE = 'maa_product_image_roles';

    public function __construct(private PDO $pdo) {}

    public function create(CreateImageRoleCommand $command, DateTimeImmutable $occurredAt): int
    {
        $timestamp = $occurredAt->format('Y-m-d H:i:s');
        $statement = $this->pdo->prepare(
            'INSERT INTO `' . self::TABLE . '` (`role_key`, `status`, `created_at`, `updated_at`, `deleted_at`) '
            . 'VALUES (:role_key, \'active\', :created_at, :updated_at, NULL)',
        );
        try {
            $statement->execute(['role_key' => $command->roleKey, 'created_at' => $timestamp, 'updated_at' => $timestamp]);
        } catch (PDOException $exception) {
            if (DuplicateKey::isDuplicate($exception)) {
                throw ImageRoleKeyAlreadyExistsException::withKey($command->roleKey, $exception);
            }

            throw $exception;
        }

        $id = DigitString::toPositiveInt($this->pdo->lastInsertId());
        if ($id === null) {
            throw ProductStorageFailureFactory::invalidAutoIncrementIdentity('Image Role');
        }

        return $id;
    }

    public function lockForUpdate(int $roleId): ?ImageRoleStateDTO
    {
        $statement = $this->pdo->prepare(
            'SELECT `id`, `status`, (`deleted_at` IS NOT NULL) AS `deleted` FROM `' . self::TABLE . '` WHERE `id` = :id FOR UPDATE',
        );
        $statement->execute(['id' => $roleId]);

        /** @var array<string, mixed>|false $row */
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return new ImageRoleStateDTO(
            id: RowHydrator::positiveInt($row, 'id'),
            active: RowHydrator::isActive($row),
            deleted: RowHydrator::flag($row, 'deleted'),
        );
    }

    public function updateStatus(int $roleId, ImageRoleStatusEnum $status, DateTimeImmutable $occurredAt): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE `' . self::TABLE . '` SET `status` = :status, `updated_at` = :updated_at WHERE `id` = :id AND `deleted_at` IS NULL',
        );
        $statement->execute(['status' => $status->value, 'updated_at' => $occurredAt->format('Y-m-d H:i:s'), 'id' => $roleId]);

        return $statement->rowCount() > 0;
    }

    public function softDelete(int $roleId, DateTimeImmutable $occurredAt): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE `' . self::TABLE . '` SET `deleted_at` = :deleted_at, `updated_at` = :updated_at WHERE `id` = :id AND `deleted_at` IS NULL',
        );
        $timestamp = $occurredAt->format('Y-m-d H:i:s');
        $statement->execute(['deleted_at' => $timestamp, 'updated_at' => $timestamp, 'id' => $roleId]);

        return $statement->rowCount() > 0;
    }

    public function restore(int $roleId, DateTimeImmutable $occurredAt): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE `' . self::TABLE . '` SET `deleted_at` = NULL, `updated_at` = :updated_at WHERE `id` = :id AND `deleted_at` IS NOT NULL',
        );
        $statement->execute(['updated_at' => $occurredAt->format('Y-m-d H:i:s'), 'id' => $roleId]);

        return $statement->rowCount() > 0;
    }
}
