<?php

declare(strict_types=1);

namespace Maatify\Product\Product\Repository\Pdo;

use DateTimeImmutable;
use Maatify\Product\Common\Exception\ProductStorageFailureFactory;
use Maatify\Product\Common\Repository\Pdo\DigitString;
use Maatify\Product\Common\Repository\Pdo\DuplicateKey;
use Maatify\Product\Common\Repository\Pdo\RowHydrator;
use Maatify\Product\Product\DTO\ProductLifecycleStateDTO;
use Maatify\Product\Product\Enum\ProductStatusEnum;
use Maatify\Product\Product\Exception\ProductCodeAlreadyExistsException;
use Maatify\Product\Product\Management\Command\CreateProductCommand;
use Maatify\Product\Product\Repository\ProductCommandRepositoryInterface;
use PDO;
use PDOException;

/**
 * PDO write adapter for Product persistence on MySQL 8.0.16 or later.
 *
 * Only a duplicate-key error (MySQL driver code 1062) is converted, to
 * {@see ProductCodeAlreadyExistsException}; the `code` unique key is the only
 * unique key an insert can violate besides the generated primary key. Every other
 * {@see PDOException} propagates unchanged. The connection must report errors as
 * exceptions.
 *
 * @internal Not part of the Public Runtime API.
 */
final readonly class PdoProductCommandRepository implements ProductCommandRepositoryInterface
{
    private const string TABLE = 'maa_product_products';

    public function __construct(private PDO $pdo) {}

    public function create(CreateProductCommand $command, DateTimeImmutable $occurredAt): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO `' . self::TABLE . '` (`code`, `status`, `created_at`, `updated_at`, `deleted_at`) '
            . 'VALUES (:code, :status, :created_at, :updated_at, NULL)',
        );
        $timestamp = $occurredAt->format('Y-m-d H:i:s');

        try {
            $statement->execute([
                'code' => $command->code,
                'status' => ProductStatusEnum::INACTIVE->value,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        } catch (PDOException $exception) {
            if (DuplicateKey::isDuplicate($exception)) {
                throw ProductCodeAlreadyExistsException::withCode($command->code, $exception);
            }

            throw $exception;
        }

        $id = DigitString::toPositiveInt($this->pdo->lastInsertId());
        if ($id === null) {
            throw ProductStorageFailureFactory::invalidAutoIncrementIdentity('Product');
        }

        return $id;
    }

    public function lockForUpdate(int $productId): ?ProductLifecycleStateDTO
    {
        $statement = $this->pdo->prepare(
            'SELECT `id`, `status`, (`deleted_at` IS NOT NULL) AS `deleted` FROM `' . self::TABLE . '` WHERE `id` = :id FOR UPDATE',
        );
        $statement->execute(['id' => $productId]);

        /** @var array<string, mixed>|false $row */
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return new ProductLifecycleStateDTO(
            id: RowHydrator::positiveInt($row, 'id'),
            active: RowHydrator::isActive($row),
            deleted: RowHydrator::flag($row, 'deleted'),
        );
    }

    public function updateStatus(int $productId, ProductStatusEnum $status, DateTimeImmutable $occurredAt): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE `' . self::TABLE . '` SET `status` = :status, `updated_at` = :updated_at WHERE '
            . '`id` = :id AND `deleted_at` IS NULL',
        );
        $statement->execute([
            'status' => $status->value,
            'updated_at' => $occurredAt->format('Y-m-d H:i:s'),
            'id' => $productId,
        ]);

        return $statement->rowCount() > 0;
    }

    public function softDelete(int $productId, DateTimeImmutable $occurredAt): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE `' . self::TABLE . '` SET `deleted_at` = :deleted_at, `updated_at` = :updated_at WHERE '
            . '`id` = :id AND `deleted_at` IS NULL',
        );
        $timestamp = $occurredAt->format('Y-m-d H:i:s');
        $statement->execute(['deleted_at' => $timestamp, 'updated_at' => $timestamp, 'id' => $productId]);

        return $statement->rowCount() > 0;
    }

    public function restore(int $productId, DateTimeImmutable $occurredAt): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE `' . self::TABLE . '` SET `deleted_at` = NULL, `updated_at` = :updated_at WHERE '
            . '`id` = :id AND `deleted_at` IS NOT NULL',
        );
        $statement->execute(['updated_at' => $occurredAt->format('Y-m-d H:i:s'), 'id' => $productId]);

        return $statement->rowCount() > 0;
    }
}
