<?php

declare(strict_types=1);

namespace Maatify\Product\Content\Repository\Pdo;

use DateTimeImmutable;
use Maatify\Product\Common\Exception\ProductStorageFailureFactory;
use Maatify\Product\Common\Repository\Pdo\DigitString;
use Maatify\Product\Common\Repository\Pdo\DuplicateKey;
use Maatify\Product\Common\Repository\Pdo\OwnerIdentityLookup;
use Maatify\Product\Common\Repository\Pdo\RowHydrator;
use Maatify\Product\Content\DTO\ContentFieldsDTO;
use Maatify\Product\Content\DTO\ContentIdentityDTO;
use Maatify\Product\Content\DTO\ContentStateDTO;
use Maatify\Product\Content\Enum\ContentOwnerEnum;
use Maatify\Product\Content\Repository\ContentCommandRepositoryInterface;
use PDO;
use PDOException;

/**
 * PDO write adapter for the Content records of one owner kind on MySQL 8.0.16 or later.
 *
 * Only a duplicate-key error (MySQL driver code 1062) on the `(owner, language_code_identity)`
 * unique key is converted, to the owner's Content conflict exception with the original
 * {@see PDOException} as `previous`. Every other {@see PDOException} propagates unchanged.
 *
 * @internal Not part of the Public Runtime API.
 */
final readonly class PdoContentCommandRepository implements ContentCommandRepositoryInterface
{
    private OwnerIdentityLookup $ownerLookup;

    public function __construct(
        private PDO $pdo,
        private ContentOwnerEnum $owner,
        ?PDO $ownerLookupPdo = null,
    ) {
        $this->ownerLookup = new OwnerIdentityLookup($pdo, $ownerLookupPdo);
    }

    public function findOwnerProductId(int $ownerId): ?int
    {
        $sql = match ($this->owner) {
            ContentOwnerEnum::PRODUCT => 'SELECT `id` AS `product_id` FROM `maa_product_products` WHERE `id` = :id LIMIT 1',
            ContentOwnerEnum::OPTION => 'SELECT `product_id` FROM `maa_product_options` WHERE `id` = :id LIMIT 1',
            ContentOwnerEnum::OPTION_VALUE => 'SELECT o.`product_id` FROM `maa_product_option_values` ov '
                . 'JOIN `maa_product_options` o ON o.`id` = ov.`option_id` WHERE ov.`id` = :id LIMIT 1',
        };
        $row = $this->ownerLookup->find($sql, $ownerId);

        return $row === false ? null : RowHydrator::positiveInt($row, 'product_id');
    }

    public function findIdentity(int $contentId): ?ContentIdentityDTO
    {
        $table = $this->owner->table();
        $sql = match ($this->owner) {
            ContentOwnerEnum::PRODUCT => 'SELECT c.`product_id` AS `product_id`, c.`product_id` AS `owner_id` FROM `' . $table . '` c '
                . 'WHERE c.`id` = :id LIMIT 1',
            ContentOwnerEnum::OPTION => 'SELECT o.`product_id` AS `product_id`, c.`option_id` AS `owner_id` FROM `' . $table . '` c '
                . 'JOIN `maa_product_options` o ON o.`id` = c.`option_id` WHERE c.`id` = :id LIMIT 1',
            ContentOwnerEnum::OPTION_VALUE => 'SELECT o.`product_id` AS `product_id`, c.`option_value_id` AS `owner_id` FROM `' . $table . '` c '
                . 'JOIN `maa_product_option_values` ov ON ov.`id` = c.`option_value_id` '
                . 'JOIN `maa_product_options` o ON o.`id` = ov.`option_id` WHERE c.`id` = :id LIMIT 1',
        };
        $row = $this->ownerLookup->find($sql, $contentId);
        if ($row === false) {
            return null;
        }

        return new ContentIdentityDTO(RowHydrator::positiveInt($row, 'product_id'), RowHydrator::positiveInt($row, 'owner_id'));
    }

    public function create(int $ownerId, ?string $languageCode, ContentFieldsDTO $fields, DateTimeImmutable $occurredAt): int
    {
        $timestamp = $occurredAt->format('Y-m-d H:i:s');
        $columns = '`' . $this->owner->ownerColumn() . '`, `language_code`, `name`';
        $values = ':owner_id, :language_code, :name';
        $params = ['owner_id' => $ownerId, 'language_code' => $languageCode, 'name' => $fields->name];
        if ($this->owner->hasDescriptions()) {
            $columns .= ', `short_description`, `description`';
            $values .= ', :short_description, :description';
            $params['short_description'] = $fields->shortDescription;
            $params['description'] = $fields->description;
        }
        $statement = $this->pdo->prepare(
            'INSERT INTO `' . $this->owner->table() . '` (' . $columns . ', `created_at`, `updated_at`, `deleted_at`) '
            . 'VALUES (' . $values . ', :created_at, :updated_at, NULL)',
        );
        try {
            $statement->execute($params + ['created_at' => $timestamp, 'updated_at' => $timestamp]);
        } catch (PDOException $exception) {
            if (DuplicateKey::isOnKey($exception, $this->owner->identityKey())) {
                throw $this->owner->identityTaken($ownerId, $languageCode, $exception);
            }

            throw $exception;
        }

        $id = DigitString::toPositiveInt($this->pdo->lastInsertId());
        if ($id === null) {
            throw ProductStorageFailureFactory::invalidAutoIncrementIdentity($this->owner->resource());
        }

        return $id;
    }

    public function lockForUpdate(int $contentId): ?ContentStateDTO
    {
        $statement = $this->pdo->prepare(
            'SELECT `id`, `' . $this->owner->ownerColumn() . '` AS `owner_id`, `name`, '
            . ($this->owner->hasDescriptions() ? '`short_description`, `description`, ' : '')
            . '(`deleted_at` IS NOT NULL) AS `deleted` FROM `' . $this->owner->table() . '` WHERE `id` = :id FOR UPDATE',
        );
        $statement->execute(['id' => $contentId]);

        /** @var array<string, mixed>|false $row */
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return new ContentStateDTO(
            id: RowHydrator::positiveInt($row, 'id'),
            ownerId: RowHydrator::positiveInt($row, 'owner_id'),
            fields: new ContentFieldsDTO(
                RowHydrator::string($row, 'name'),
                $this->owner->hasDescriptions() ? RowHydrator::nullableString($row, 'short_description') : null,
                $this->owner->hasDescriptions() ? RowHydrator::nullableString($row, 'description') : null,
            ),
            deleted: RowHydrator::flag($row, 'deleted'),
        );
    }

    public function hasOtherNamedRecord(int $ownerId, int $excludedContentId): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM `' . $this->owner->table() . '` WHERE `' . $this->owner->ownerColumn() . '` = :owner_id '
            . 'AND `id` <> :id AND `deleted_at` IS NULL AND `name` REGEXP \'[^[:space:]]\' LIMIT 1 FOR SHARE',
        );
        $statement->execute(['owner_id' => $ownerId, 'id' => $excludedContentId]);

        return $statement->fetchColumn() !== false;
    }

    public function update(int $contentId, ContentFieldsDTO $fields, DateTimeImmutable $occurredAt): bool
    {
        $params = ['name' => $fields->name, 'updated_at' => $occurredAt->format('Y-m-d H:i:s'), 'id' => $contentId];
        if ($this->owner->hasDescriptions()) {
            $statement = $this->pdo->prepare(
                'UPDATE `' . $this->owner->table() . '` SET `name` = :name, `short_description` = :short_description, '
                . '`description` = :description, `updated_at` = :updated_at WHERE `id` = :id AND `deleted_at` IS NULL',
            );
            $params['short_description'] = $fields->shortDescription;
            $params['description'] = $fields->description;
        } else {
            $statement = $this->pdo->prepare(
                'UPDATE `' . $this->owner->table() . '` SET `name` = :name, `updated_at` = :updated_at WHERE `id` = :id AND `deleted_at` IS NULL',
            );
        }
        $statement->execute($params);

        return $statement->rowCount() > 0;
    }

    public function softDelete(int $contentId, DateTimeImmutable $occurredAt): bool
    {
        $timestamp = $occurredAt->format('Y-m-d H:i:s');
        $statement = $this->pdo->prepare(
            'UPDATE `' . $this->owner->table() . '` SET `deleted_at` = :deleted_at, `updated_at` = :updated_at WHERE '
            . '`id` = :id AND `deleted_at` IS NULL',
        );
        $statement->execute(['deleted_at' => $timestamp, 'updated_at' => $timestamp, 'id' => $contentId]);

        return $statement->rowCount() > 0;
    }

    public function restore(int $contentId, DateTimeImmutable $occurredAt): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE `' . $this->owner->table() . '` SET `deleted_at` = NULL, `updated_at` = :updated_at WHERE '
            . '`id` = :id AND `deleted_at` IS NOT NULL',
        );
        $statement->execute(['updated_at' => $occurredAt->format('Y-m-d H:i:s'), 'id' => $contentId]);

        return $statement->rowCount() > 0;
    }
}
