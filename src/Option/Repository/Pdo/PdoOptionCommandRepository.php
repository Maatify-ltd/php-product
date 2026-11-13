<?php

declare(strict_types=1);

namespace Maatify\Product\Option\Repository\Pdo;

use DateTimeImmutable;
use Maatify\PhpPersistence\Ordering\Config\ScopedOrderingConfig;
use Maatify\PhpPersistence\Ordering\Repository\ScopedOrderingManager;
use Maatify\Product\Common\Exception\ProductStorageFailureFactory;
use Maatify\Product\Common\Repository\Pdo\DigitString;
use Maatify\Product\Common\Repository\Pdo\DuplicateKey;
use Maatify\Product\Common\Repository\Pdo\OwnerIdentityLookup;
use Maatify\Product\Common\Repository\Pdo\RowHydrator;
use Maatify\Product\Option\DTO\OptionStateDTO;
use Maatify\Product\Option\Enum\OptionStatusEnum;
use Maatify\Product\Option\Exception\OptionCodeAlreadyExistsException;
use Maatify\Product\Option\Management\Command\CreateOptionCommand;
use Maatify\Product\Option\Repository\OptionCommandRepositoryInterface;
use PDO;
use PDOException;

/**
 * PDO write adapter for Option persistence on MySQL 8.0.16 or later.
 *
 * Only a duplicate-key error (MySQL driver code 1062) on insert is converted, to
 * {@see OptionCodeAlreadyExistsException}; the `(product_id, code)` key is the only unique key
 * an insert can violate besides the generated keys. Every other {@see PDOException} propagates.
 *
 * @internal Not part of the Public Runtime API.
 */
final readonly class PdoOptionCommandRepository implements OptionCommandRepositoryInterface
{
    private const string TABLE = 'maa_product_options';

    private OwnerIdentityLookup $ownerLookup;

    public function __construct(
        private PDO $pdo,
        private ScopedOrderingManager $orderingManager,
        ?PDO $ownerLookupPdo = null,
    ) {
        $this->ownerLookup = new OwnerIdentityLookup($pdo, $ownerLookupPdo);
    }

    public function create(CreateOptionCommand $command, DateTimeImmutable $occurredAt): int
    {
        $displayOrder = $this->orderingManager->getNextPositionForUpdate(
            $this->pdo,
            self::orderingConfig(),
            $command->productId,
        );
        $timestamp = $occurredAt->format('Y-m-d H:i:s');
        $statement = $this->pdo->prepare(
            'INSERT INTO `' . self::TABLE . '` (`product_id`, `code`, `status`, `display_order`, `created_at`, `updated_at`, `deleted_at`) '
            . 'VALUES (:product_id, :code, \'inactive\', :display_order, :created_at, :updated_at, NULL)',
        );
        try {
            $statement->execute([
                'product_id' => $command->productId,
                'code' => $command->code,
                'display_order' => $displayOrder,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        } catch (PDOException $exception) {
            if (DuplicateKey::isDuplicate($exception)) {
                throw OptionCodeAlreadyExistsException::withCode($command->productId, $command->code, $exception);
            }

            throw $exception;
        }

        $id = DigitString::toPositiveInt($this->pdo->lastInsertId());
        if ($id === null) {
            throw ProductStorageFailureFactory::invalidAutoIncrementIdentity('Option');
        }

        return $id;
    }

    public function lockForUpdate(int $optionId): ?OptionStateDTO
    {
        $statement = $this->pdo->prepare(
            'SELECT o.`id`, o.`product_id`, o.`status`, (o.`deleted_at` IS NOT NULL) AS `deleted`, '
            . 'EXISTS (SELECT 1 FROM `maa_product_option_contents` c WHERE c.`option_id` = o.`id` AND c.`deleted_at` IS NULL '
            . 'AND c.`name` REGEXP \'[^[:space:]]\') AS `labelled` '
            . 'FROM `' . self::TABLE . '` o WHERE o.`id` = :id FOR UPDATE',
        );
        $statement->execute(['id' => $optionId]);

        /** @var array<string, mixed>|false $row */
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return new OptionStateDTO(
            id: RowHydrator::positiveInt($row, 'id'),
            productId: RowHydrator::positiveInt($row, 'product_id'),
            active: RowHydrator::isActive($row),
            deleted: RowHydrator::flag($row, 'deleted'),
            labelled: RowHydrator::flag($row, 'labelled'),
        );
    }

    public function findProductId(int $optionId): ?int
    {
        $row = $this->ownerLookup->find('SELECT `product_id` FROM `' . self::TABLE . '` WHERE `id` = :id LIMIT 1', $optionId);

        return $row === false ? null : RowHydrator::positiveInt($row, 'product_id');
    }

    public function updateStatus(int $optionId, OptionStatusEnum $status, DateTimeImmutable $occurredAt): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE `' . self::TABLE . '` SET `status` = :status, `updated_at` = :updated_at WHERE `id` = :id AND `deleted_at` IS NULL',
        );
        $statement->execute([
            'status' => $status->value,
            'updated_at' => $occurredAt->format('Y-m-d H:i:s'),
            'id' => $optionId,
        ]);

        return $statement->rowCount() > 0;
    }

    public function softDelete(int $optionId, DateTimeImmutable $occurredAt): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE `' . self::TABLE . '` SET `deleted_at` = :deleted_at, `updated_at` = :updated_at WHERE `id` = :id AND `deleted_at` IS NULL',
        );
        $timestamp = $occurredAt->format('Y-m-d H:i:s');
        $statement->execute(['deleted_at' => $timestamp, 'updated_at' => $timestamp, 'id' => $optionId]);

        return $statement->rowCount() > 0;
    }

    public function restore(int $optionId, int $productId, DateTimeImmutable $occurredAt): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE `' . self::TABLE . '` SET `deleted_at` = NULL, `display_order` = :display_order, `updated_at` = :updated_at '
            . 'WHERE `id` = :id AND `deleted_at` IS NOT NULL',
        );
        $statement->execute([
            'display_order' => $this->orderingManager->getNextPositionForUpdate($this->pdo, self::orderingConfig(), $productId),
            'updated_at' => $occurredAt->format('Y-m-d H:i:s'),
            'id' => $optionId,
        ]);

        return $statement->rowCount() > 0;
    }

    public function updateDisplayOrder(int $optionId, int $productId, int $displayOrder, DateTimeImmutable $occurredAt): bool
    {
        return $this->orderingManager->moveWithinScope($this->pdo, self::orderingConfig(), $productId, $optionId, $displayOrder, $occurredAt->format('Y-m-d H:i:s'));
    }

    /**
     * Options are ordered within their Product; soft-deleted Options are outside the live order.
     */
    private static function orderingConfig(): ScopedOrderingConfig
    {
        return new ScopedOrderingConfig(
            table: self::TABLE,
            scopeColumn: 'product_id',
            idColumn: 'id',
            orderColumn: 'display_order',
            deletedAtColumn: 'deleted_at',
            nullableScope: false,
            updatedAtColumn: 'updated_at',
        );
    }
}
