<?php

declare(strict_types=1);

namespace Maatify\Product\ImageAssignment\Repository\Pdo;

use DateTimeImmutable;
use Maatify\PhpPersistence\Ordering\Config\ScopedOrderingConfig;
use Maatify\PhpPersistence\Ordering\Repository\ScopedOrderingManager;
use Maatify\Product\Common\Exception\ProductStorageFailureFactory;
use Maatify\Product\Common\Repository\Pdo\DigitString;
use Maatify\Product\Common\Repository\Pdo\DuplicateKey;
use Maatify\Product\Common\Repository\Pdo\OwnerIdentityLookup;
use Maatify\Product\Common\Repository\Pdo\RowHydrator;
use Maatify\Product\ImageAssignment\DTO\ImageAssignmentIdentityDTO;
use Maatify\Product\ImageAssignment\DTO\ImageAssignmentStateDTO;
use Maatify\Product\ImageAssignment\Exception\ImageAssignmentAlreadyExistsException;
use Maatify\Product\ImageAssignment\Exception\ImageAssignmentDefaultConflictException;
use Maatify\Product\ImageAssignment\Management\Command\CreateImageAssignmentCommand;
use Maatify\Product\ImageAssignment\Repository\ImageAssignmentCommandRepositoryInterface;
use PDO;
use PDOException;

/**
 * PDO write adapter for Image Assignment persistence on MySQL 8.0.16 or later.
 *
 * Two duplicate-key errors (MySQL driver code 1062) are converted. On insert, to
 * {@see ImageAssignmentAlreadyExistsException}: a new row is never default, so the identity key is
 * the only unique key an insert can violate besides the primary key. On restore-as-default, a
 * violation of exactly the default-scope key, matched by name, to
 * {@see ImageAssignmentDefaultConflictException}. Every other {@see PDOException} propagates,
 * including a duplicate default caught by the database unique key during set-default.
 *
 * The live default of a scope is read through the Product-prefixed identity key, never through the
 * default-scope key, so that the locking read takes no gap lock that another Product's default
 * write could need.
 *
 * @internal Not part of the Public Runtime API.
 */
final readonly class PdoImageAssignmentCommandRepository implements ImageAssignmentCommandRepositoryInterface
{
    private const string TABLE = 'maa_product_image_assignments';

    private const string DEFAULT_SCOPE_KEY = 'uq_maa_product_image_assignments_default_scope';

    private const string PRODUCT_IDENTITY_KEY = 'uq_maa_product_image_assignments_identity';

    private OwnerIdentityLookup $ownerLookup;

    public function __construct(
        private PDO $pdo,
        private ScopedOrderingManager $orderingManager,
        ?PDO $ownerLookupPdo = null,
    ) {
        $this->ownerLookup = new OwnerIdentityLookup($pdo, $ownerLookupPdo);
    }

    public function create(CreateImageAssignmentCommand $command, DateTimeImmutable $occurredAt): int
    {
        $scope = $this->scopeFor($command);
        $displayOrder = $this->orderingManager->getNextPositionForUpdate(
            $this->pdo,
            self::orderingConfig(),
            $scope,
        );
        $timestamp = $occurredAt->format('Y-m-d H:i:s');
        $statement = $this->pdo->prepare(
            'INSERT INTO `' . self::TABLE . '` (`product_id`, `variant_id`, `media_asset_id`, `role_id`, `language_code`, `platform`, '
            . '`is_default`, `display_order`, `created_at`, `updated_at`, `deleted_at`) '
            . 'VALUES (:product_id, :variant_id, :media_asset_id, :role_id, :language_code, :platform, 0, :display_order, :created_at, :updated_at, NULL)',
        );
        try {
            $statement->execute([
                'product_id' => $command->productId,
                'variant_id' => $command->variantId,
                'media_asset_id' => $command->mediaAssetId,
                'role_id' => $command->roleId,
                'language_code' => $command->languageCode,
                'platform' => $command->platform,
                'display_order' => $displayOrder,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        } catch (PDOException $exception) {
            if (DuplicateKey::isDuplicate($exception)) {
                throw ImageAssignmentAlreadyExistsException::forIdentity($command, $exception);
            }

            throw $exception;
        }

        $id = DigitString::toPositiveInt($this->pdo->lastInsertId());
        if ($id === null) {
            throw ProductStorageFailureFactory::invalidAutoIncrementIdentity('Image Assignment');
        }

        return $id;
    }

    /** @return non-empty-string */
    private function scopeFor(CreateImageAssignmentCommand $command): string
    {
        // Match the generated ordering_scope column using the same MySQL collation.
        $statement = $this->pdo->prepare(
            "SELECT CONCAT('P', CAST(:product_id AS UNSIGNED), '|', COALESCE(CAST(:variant_id AS UNSIGNED), 0), '|'"
            . ", COALESCE(SHA2(TRIM(TRAILING WEIGHT_STRING(_utf8mb4' ' COLLATE utf8mb4_unicode_ci) FROM WEIGHT_STRING(CONVERT(:language USING utf8mb4) COLLATE utf8mb4_unicode_ci)), 256), 'N'), '|'"
            . ", COALESCE(SHA2(TRIM(TRAILING WEIGHT_STRING(_utf8mb4' ' COLLATE utf8mb4_unicode_ci) FROM WEIGHT_STRING(CONVERT(:platform USING utf8mb4) COLLATE utf8mb4_unicode_ci)), 256), 'N'), '|'"
            . ', COALESCE(CAST(:role_id AS UNSIGNED), 0))',
        );
        $statement->execute([
            'product_id' => $command->productId,
            'variant_id' => $command->variantId,
            'role_id' => $command->roleId,
            'language' => $command->languageCode,
            'platform' => $command->platform,
        ]);
        $scope = $statement->fetchColumn();
        if (!is_string($scope) || $scope === '') {
            throw ProductStorageFailureFactory::invalidStorageValue('ordering_scope');
        }

        return $scope;
    }

    public function findIdentity(int $assignmentId): ?ImageAssignmentIdentityDTO
    {
        $row = $this->ownerLookup->find(
            'SELECT `product_id`, `variant_id`, `role_id` FROM `' . self::TABLE . '` WHERE `id` = :id LIMIT 1',
            $assignmentId,
        );
        if ($row === false) {
            return null;
        }

        return new ImageAssignmentIdentityDTO(
            productId: RowHydrator::positiveInt($row, 'product_id'),
            variantId: RowHydrator::nullablePositiveInt($row, 'variant_id'),
            roleId: RowHydrator::nullablePositiveInt($row, 'role_id'),
        );
    }

    public function lockForUpdate(int $assignmentId): ?ImageAssignmentStateDTO
    {
        $statement = $this->pdo->prepare(
            'SELECT `id`, `product_id`, `variant_id`, `role_id`, `ordering_scope`, `is_default`, (`deleted_at` IS NOT NULL) AS `deleted` '
            . 'FROM `' . self::TABLE . '` WHERE `id` = :id FOR UPDATE',
        );
        $statement->execute(['id' => $assignmentId]);

        /** @var array<string, mixed>|false $row */
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return new ImageAssignmentStateDTO(
            id: RowHydrator::positiveInt($row, 'id'),
            productId: RowHydrator::positiveInt($row, 'product_id'),
            variantId: RowHydrator::nullablePositiveInt($row, 'variant_id'),
            roleId: RowHydrator::nullablePositiveInt($row, 'role_id'),
            scope: RowHydrator::string($row, 'ordering_scope'),
            isDefault: RowHydrator::flag($row, 'is_default'),
            deleted: RowHydrator::flag($row, 'deleted'),
        );
    }

    public function setDefault(int $assignmentId, int $productId, string $scope, DateTimeImmutable $occurredAt): bool
    {
        $timestamp = $occurredAt->format('Y-m-d H:i:s');
        $previous = $this->lockLiveDefault($productId, $scope);
        if ($previous !== null && $previous !== $assignmentId) {
            $clear = $this->pdo->prepare(
                'UPDATE `' . self::TABLE . '` SET `is_default` = 0, `updated_at` = :updated_at WHERE `id` = :id',
            );
            $clear->execute(['updated_at' => $timestamp, 'id' => $previous]);
        }

        $set = $this->pdo->prepare(
            'UPDATE `' . self::TABLE . '` SET `is_default` = 1, `updated_at` = :updated_at WHERE '
            . '`id` = :id AND `deleted_at` IS NULL AND `is_default` = 0',
        );
        $set->execute(['updated_at' => $timestamp, 'id' => $assignmentId]);

        return $set->rowCount() > 0;
    }

    public function clearDefault(int $assignmentId, DateTimeImmutable $occurredAt): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE `' . self::TABLE . '` SET `is_default` = 0, `updated_at` = :updated_at WHERE '
            . '`id` = :id AND `deleted_at` IS NULL AND `is_default` = 1',
        );
        $statement->execute(['updated_at' => $occurredAt->format('Y-m-d H:i:s'), 'id' => $assignmentId]);

        return $statement->rowCount() > 0;
    }

    public function softDelete(int $assignmentId, DateTimeImmutable $occurredAt): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE `' . self::TABLE . '` SET `is_default` = 0, `deleted_at` = :deleted_at, `updated_at` = :updated_at WHERE '
            . '`id` = :id AND `deleted_at` IS NULL',
        );
        $timestamp = $occurredAt->format('Y-m-d H:i:s');
        $statement->execute(['deleted_at' => $timestamp, 'updated_at' => $timestamp, 'id' => $assignmentId]);

        return $statement->rowCount() > 0;
    }

    public function restore(int $assignmentId, string $scope, DateTimeImmutable $occurredAt): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE `' . self::TABLE . '` SET `is_default` = 0, `deleted_at` = NULL, `display_order` = :display_order, `updated_at` = :updated_at WHERE '
            . '`id` = :id AND `deleted_at` IS NOT NULL',
        );
        $statement->execute([
            'display_order' => $this->orderingManager->getNextPositionForUpdate($this->pdo, self::orderingConfig(), $scope),
            'updated_at' => $occurredAt->format('Y-m-d H:i:s'),
            'id' => $assignmentId,
        ]);

        return $statement->rowCount() > 0;
    }

    public function lockLiveDefault(int $productId, string $scope): ?int
    {
        $statement = $this->pdo->prepare(
            'SELECT `id` FROM `' . self::TABLE . '` FORCE INDEX (`' . self::PRODUCT_IDENTITY_KEY . '`) '
            . 'WHERE `product_id` = :product_id AND `default_scope_identity` = :scope FOR UPDATE',
        );
        $statement->execute(['product_id' => $productId, 'scope' => $scope]);

        /** @var array<string, mixed>|false $row */
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : RowHydrator::positiveInt($row, 'id');
    }

    public function restoreAsDefault(int $assignmentId, int $productId, string $scope, DateTimeImmutable $occurredAt): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE `' . self::TABLE . '` SET `is_default` = 1, `deleted_at` = NULL, `display_order` = :display_order, `updated_at` = :updated_at WHERE '
            . '`id` = :id AND `deleted_at` IS NOT NULL',
        );
        try {
            $statement->execute([
                'display_order' => $this->orderingManager->getNextPositionForUpdate($this->pdo, self::orderingConfig(), $scope),
                'updated_at' => $occurredAt->format('Y-m-d H:i:s'),
                'id' => $assignmentId,
            ]);
        } catch (PDOException $exception) {
            if (DuplicateKey::isOnKey($exception, self::DEFAULT_SCOPE_KEY)) {
                throw ImageAssignmentDefaultConflictException::forRestoreAsDefault($assignmentId, $this->lockLiveDefault($productId, $scope), $exception);
            }

            throw $exception;
        }

        return $statement->rowCount() > 0;
    }

    public function updateDisplayOrder(int $assignmentId, string $scope, int $displayOrder, DateTimeImmutable $occurredAt): bool
    {
        return $this->orderingManager->moveWithinScope($this->pdo, self::orderingConfig(), $scope, $assignmentId, $displayOrder, $occurredAt->format('Y-m-d H:i:s'));
    }

    /**
     * Assignments are ordered within their exact scope, the generated `ordering_scope` key;
     * soft-deleted assignments are outside the live order.
     */
    private static function orderingConfig(): ScopedOrderingConfig
    {
        return new ScopedOrderingConfig(
            table: self::TABLE,
            scopeColumn: 'ordering_scope',
            idColumn: 'id',
            orderColumn: 'display_order',
            deletedAtColumn: 'deleted_at',
            nullableScope: false,
            updatedAtColumn: 'updated_at',
        );
    }
}
