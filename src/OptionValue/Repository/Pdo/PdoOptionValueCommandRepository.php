<?php

declare(strict_types=1);

namespace Maatify\Product\OptionValue\Repository\Pdo;

use Closure;
use DateTimeImmutable;
use Maatify\PhpPersistence\Ordering\Config\ScopedOrderingConfig;
use Maatify\PhpPersistence\Ordering\Repository\ScopedOrderingManager;
use Maatify\Product\Common\Exception\ProductStorageFailureFactory;
use Maatify\Product\Common\Repository\Pdo\DigitString;
use Maatify\Product\Common\Repository\Pdo\DuplicateKey;
use Maatify\Product\Common\Repository\Pdo\OwnerIdentityLookup;
use Maatify\Product\Common\Repository\Pdo\RowHydrator;
use Maatify\Product\OptionValue\Enum\OptionValueStatusEnum;
use Maatify\Product\OptionValue\Exception\OptionValueCodeAlreadyExistsException;
use Maatify\Product\OptionValue\Management\Command\CreateOptionValueCommand;
use Maatify\Product\OptionValue\Repository\OptionValueCommandRepositoryInterface;
use PDO;
use PDOException;

/**
 * PDO write adapter for Option Value persistence on MySQL 8.0.16 or later.
 *
 * Only a duplicate-key error (MySQL driver code 1062) on insert is converted, to
 * {@see OptionValueCodeAlreadyExistsException}; the `(option_id, code)` key is the only unique
 * key an insert can violate besides the generated keys. Every other {@see PDOException} propagates.
 *
 * @internal Not part of the Public Runtime API.
 */
final readonly class PdoOptionValueCommandRepository implements OptionValueCommandRepositoryInterface
{
    private const string TABLE = 'maa_product_option_values';

    private OwnerIdentityLookup $ownerLookup;

    /** @param PDO|(Closure(): PDO)|null $ownerLookupPdo */
    public function __construct(
        private PDO $pdo,
        private ScopedOrderingManager $orderingManager,
        PDO|Closure|null $ownerLookupPdo = null,
    ) {
        $this->ownerLookup = new OwnerIdentityLookup($pdo, $ownerLookupPdo);
    }

    public function create(CreateOptionValueCommand $command, DateTimeImmutable $occurredAt): int
    {
        $displayOrder = $this->orderingManager->getNextPositionForUpdate(
            $this->pdo,
            self::orderingConfig(),
            $command->optionId,
        );
        $timestamp = $occurredAt->format('Y-m-d H:i:s');
        $statement = $this->pdo->prepare(
            'INSERT INTO `' . self::TABLE . '` (`option_id`, `code`, `status`, `display_order`, `created_at`, `updated_at`, `deleted_at`) '
            . 'VALUES (:option_id, :code, \'inactive\', :display_order, :created_at, :updated_at, NULL)',
        );
        try {
            $statement->execute([
                'option_id' => $command->optionId,
                'code' => $command->code,
                'display_order' => $displayOrder,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        } catch (PDOException $exception) {
            if (DuplicateKey::isDuplicate($exception)) {
                throw OptionValueCodeAlreadyExistsException::withCode($command->optionId, $command->code, $exception);
            }

            throw $exception;
        }

        $id = DigitString::toPositiveInt($this->pdo->lastInsertId());
        if ($id === null) {
            throw ProductStorageFailureFactory::invalidAutoIncrementIdentity('Option Value');
        }

        return $id;
    }

    public function findProductId(int $optionValueId): ?int
    {
        $row = $this->ownerLookup->find(
            'SELECT o.`product_id` FROM `' . self::TABLE . '` ov JOIN `maa_product_options` o ON o.`id` = ov.`option_id` WHERE '
            . 'ov.`id` = :id LIMIT 1',
            $optionValueId,
        );

        return $row === false ? null : RowHydrator::positiveInt($row, 'product_id');
    }

    public function updateStatus(int $optionValueId, OptionValueStatusEnum $status, DateTimeImmutable $occurredAt): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE `' . self::TABLE . '` SET `status` = :status, `updated_at` = :updated_at WHERE `id` = :id AND `deleted_at` IS NULL',
        );
        $statement->execute([
            'status' => $status->value,
            'updated_at' => $occurredAt->format('Y-m-d H:i:s'),
            'id' => $optionValueId,
        ]);

        return $statement->rowCount() > 0;
    }

    public function softDelete(int $optionValueId, DateTimeImmutable $occurredAt): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE `' . self::TABLE . '` SET `deleted_at` = :deleted_at, `updated_at` = :updated_at WHERE `id` = :id AND `deleted_at` IS NULL',
        );
        $timestamp = $occurredAt->format('Y-m-d H:i:s');
        $statement->execute(['deleted_at' => $timestamp, 'updated_at' => $timestamp, 'id' => $optionValueId]);

        return $statement->rowCount() > 0;
    }

    public function restore(int $optionValueId, int $optionId, DateTimeImmutable $occurredAt): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE `' . self::TABLE . '` SET `deleted_at` = NULL, `display_order` = :display_order, `updated_at` = :updated_at '
            . 'WHERE `id` = :id AND `deleted_at` IS NOT NULL',
        );
        $statement->execute([
            'display_order' => $this->orderingManager->getNextPositionForUpdate($this->pdo, self::orderingConfig(), $optionId),
            'updated_at' => $occurredAt->format('Y-m-d H:i:s'),
            'id' => $optionValueId,
        ]);

        return $statement->rowCount() > 0;
    }

    public function updateDisplayOrder(int $optionValueId, int $optionId, int $displayOrder, DateTimeImmutable $occurredAt): bool
    {
        return $this->orderingManager->moveWithinScope($this->pdo, self::orderingConfig(), $optionId, $optionValueId, $displayOrder, $occurredAt->format('Y-m-d H:i:s'));
    }

    /**
     * Option Values are ordered within their Option; soft-deleted Values are outside the live order.
     */
    private static function orderingConfig(): ScopedOrderingConfig
    {
        return new ScopedOrderingConfig(
            table: self::TABLE,
            scopeColumn: 'option_id',
            idColumn: 'id',
            orderColumn: 'display_order',
            deletedAtColumn: 'deleted_at',
            nullableScope: false,
            updatedAtColumn: 'updated_at',
        );
    }
}
