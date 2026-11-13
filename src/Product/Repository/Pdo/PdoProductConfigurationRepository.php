<?php

declare(strict_types=1);

namespace Maatify\Product\Product\Repository\Pdo;

use DateTimeImmutable;
use Maatify\PhpPersistence\Ordering\Config\ScopedOrderingConfig;
use Maatify\PhpPersistence\Ordering\Repository\ScopedOrderingManager;
use Maatify\Product\Common\Exception\ProductStorageFailureFactory;
use Maatify\Product\Common\Repository\Pdo\DigitString;
use Maatify\Product\Common\Repository\Pdo\RowHydrator;
use Maatify\Product\Option\DTO\OptionStateDTO;
use Maatify\Product\OptionValue\DTO\OptionValueStateDTO;
use Maatify\Product\Product\DTO\ProductConfigurationSnapshotDTO;
use Maatify\Product\Product\Repository\ProductConfigurationRepositoryInterface;
use Maatify\Product\Variant\DTO\VariantStateDTO;
use Maatify\Product\Variant\ValueObject\CompositionFingerprint;
use Maatify\Product\Variant\ValueObject\OptionValueSelection;
use PDO;

/**
 * PDO adapter over the configuration graph of one Product on MySQL 8.0.16 or later.
 *
 * The snapshot is read with locking reads only, so it reflects the latest committed state
 * even when the Host transaction established an earlier consistent-read snapshot. Lock order:
 * Product row, then its Options, Option Values, and Variants, each returned by ascending identity;
 * composition pairs and Content labels are read with shared locks after them. Every read after the
 * Product row goes through a key that starts with the Product identity or with the identities of
 * the rows already locked, pinned with `FORCE INDEX` so that no plan scans or record-locks another
 * Product's entries. Under `REPEATABLE READ` each range read still takes gap locks: a read keyed by
 * the Product locks the gap before its first entry and the gap after its last one, and a read
 * keyed by `IN (...)` does the same around the entries of each listed owner. A gap can border another
 * Product's entries, so an insert of that Product can wait on it, and concurrent mutations of
 * different Products can deadlock (driver error 1213); the exception propagates unchanged.
 *
 * @internal Not part of the Public Runtime API.
 */
final readonly class PdoProductConfigurationRepository implements ProductConfigurationRepositoryInterface
{
    public function __construct(
        private PDO $pdo,
        private ScopedOrderingManager $orderingManager,
    ) {}

    public function findRevision(int $productId): ?int
    {
        $statement = $this->pdo->prepare('SELECT `configuration_revision` FROM `maa_product_products` WHERE `id` = :id AND `deleted_at` IS NULL');
        $statement->execute(['id' => $productId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : RowHydrator::positiveInt($row, 'configuration_revision');
    }

    public function advanceRevision(int $productId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE `maa_product_products` SET `configuration_revision` = `configuration_revision` + 1 WHERE `id` = :id',
        );
        $statement->execute(['id' => $productId]);
        if ($statement->rowCount() !== 1) {
            throw ProductStorageFailureFactory::lockedRowVanished('Product', $productId);
        }
    }

    public function lockSnapshot(int $productId): ?ProductConfigurationSnapshotDTO
    {
        $product = $this->pdo->prepare(
            'SELECT `id`, `status`, `configuration_revision`, (`deleted_at` IS NOT NULL) AS `deleted` FROM `maa_product_products` WHERE `id` = :id FOR UPDATE',
        );
        $product->execute(['id' => $productId]);
        /** @var array<string, mixed>|false $productRow */
        $productRow = $product->fetch(PDO::FETCH_ASSOC);
        if ($productRow === false) {
            return null;
        }

        $options = $this->pdo->prepare(
            'SELECT `id`, `product_id`, `status`, (`deleted_at` IS NOT NULL) AS `deleted` FROM `maa_product_options` '
            . 'FORCE INDEX (`uq_maa_product_options_product_code`) WHERE `product_id` = :product_id ORDER BY `id` ASC FOR UPDATE',
        );
        $options->execute(['product_id' => $productId]);
        /** @var list<array<string, mixed>> $optionRows */
        $optionRows = $options->fetchAll(PDO::FETCH_ASSOC);

        $values = $this->pdo->prepare(
            'SELECT ov.`id`, ov.`option_id`, ov.`status`, (ov.`deleted_at` IS NOT NULL) AS `deleted` '
            . 'FROM `maa_product_options` o FORCE INDEX (`uq_maa_product_options_product_code`) '
            . 'STRAIGHT_JOIN `maa_product_option_values` ov FORCE INDEX (`uq_maa_product_option_values_option_code`) '
            . 'ON ov.`option_id` = o.`id` WHERE o.`product_id` = :product_id ORDER BY ov.`id` ASC FOR UPDATE',
        );
        $values->execute(['product_id' => $productId]);
        /** @var list<array<string, mixed>> $valueRows */
        $valueRows = $values->fetchAll(PDO::FETCH_ASSOC);

        $variants = $this->pdo->prepare(
            'SELECT `id`, `product_id`, `composition_fingerprint`, `status`, (`deleted_at` IS NOT NULL) AS `deleted` '
            . 'FROM `maa_product_variants` FORCE INDEX (`idx_maa_product_variants_product_status_deleted_order`) '
            . 'WHERE `product_id` = :product_id ORDER BY `id` ASC FOR UPDATE',
        );
        $variants->execute(['product_id' => $productId]);
        /** @var list<array<string, mixed>> $variantRows */
        $variantRows = $variants->fetchAll(PDO::FETCH_ASSOC);

        $compositions = $this->compositionsOf(array_map(
            static fn(array $row): int => RowHydrator::positiveInt($row, 'id'),
            $variantRows,
        ));
        $labelledOptions = $this->labelledOwners(
            'maa_product_option_contents',
            'option_id',
            'uq_maa_product_option_contents_identity',
            array_map(static fn(array $row): int => RowHydrator::positiveInt($row, 'id'), $optionRows),
        );
        $labelledValues = $this->labelledOwners(
            'maa_product_option_value_contents',
            'option_value_id',
            'uq_maa_product_option_value_contents_identity',
            array_map(static fn(array $row): int => RowHydrator::positiveInt($row, 'id'), $valueRows),
        );

        $name = $this->pdo->prepare(
            'SELECT 1 FROM `maa_product_product_contents` FORCE INDEX (`uq_maa_product_product_contents_identity`) '
            . 'WHERE `product_id` = :product_id AND `deleted_at` IS NULL '
            . 'AND `name` REGEXP \'[^[:space:]]\' LIMIT 1 FOR SHARE',
        );
        $name->execute(['product_id' => $productId]);

        $optionStates = [];
        foreach ($optionRows as $row) {
            $id = RowHydrator::positiveInt($row, 'id');
            $optionStates[$id] = new OptionStateDTO(
                id: $id,
                productId: RowHydrator::positiveInt($row, 'product_id'),
                active: RowHydrator::isActive($row),
                deleted: RowHydrator::flag($row, 'deleted'),
                labelled: isset($labelledOptions[$id]),
            );
        }
        $valueStates = [];
        foreach ($valueRows as $row) {
            $id = RowHydrator::positiveInt($row, 'id');
            $valueStates[$id] = new OptionValueStateDTO(
                id: $id,
                optionId: RowHydrator::positiveInt($row, 'option_id'),
                active: RowHydrator::isActive($row),
                deleted: RowHydrator::flag($row, 'deleted'),
                labelled: isset($labelledValues[$id]),
            );
        }
        $variantStates = [];
        foreach ($variantRows as $row) {
            $id = RowHydrator::positiveInt($row, 'id');
            $variantStates[$id] = new VariantStateDTO(
                id: $id,
                productId: RowHydrator::positiveInt($row, 'product_id'),
                fingerprint: RowHydrator::string($row, 'composition_fingerprint'),
                active: RowHydrator::isActive($row),
                deleted: RowHydrator::flag($row, 'deleted'),
                composition: $compositions[$id] ?? [],
            );
        }

        return new ProductConfigurationSnapshotDTO(
            productId: RowHydrator::positiveInt($productRow, 'id'),
            productActive: RowHydrator::isActive($productRow),
            productDeleted: RowHydrator::flag($productRow, 'deleted'),
            hasName: $name->fetchColumn() !== false,
            options: $optionStates,
            optionValues: $valueStates,
            variants: $variantStates,
            configurationRevision: RowHydrator::positiveInt($productRow, 'configuration_revision'),
        );
    }

    public function findVariantProductId(int $variantId): ?int
    {
        $statement = $this->pdo->prepare('SELECT `product_id` FROM `maa_product_variants` WHERE `id` = :id LIMIT 1');
        $statement->execute(['id' => $variantId]);

        /** @var array<string, mixed>|false $row */
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : RowHydrator::positiveInt($row, 'product_id');
    }

    public function updateOptionStatus(array $optionIds, bool $active, DateTimeImmutable $occurredAt): void
    {
        $this->updateStatusOf('maa_product_options', $optionIds, $active, $occurredAt);
    }

    public function updateOptionValueStatus(array $optionValueIds, bool $active, DateTimeImmutable $occurredAt): void
    {
        $this->updateStatusOf('maa_product_option_values', $optionValueIds, $active, $occurredAt);
    }

    public function updateVariantStatus(array $variantIds, bool $active, DateTimeImmutable $occurredAt): void
    {
        $this->updateStatusOf('maa_product_variants', $variantIds, $active, $occurredAt);
    }

    public function restoreVariantAsActive(int $variantId, int $productId, DateTimeImmutable $occurredAt): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE `maa_product_variants` SET `deleted_at` = NULL, `status` = \'active\', `display_order` = :display_order, '
            . '`updated_at` = :updated_at WHERE `id` = :id AND `deleted_at` IS NOT NULL',
        );
        $statement->execute([
            'display_order' => $this->orderingManager->getNextPositionForUpdate($this->pdo, self::variantOrderingConfig(), $productId),
            'updated_at' => $occurredAt->format('Y-m-d H:i:s'),
            'id' => $variantId,
        ]);

        return $statement->rowCount() > 0;
    }

    public function createActiveVariant(
        int $productId,
        CompositionFingerprint $fingerprint,
        array $composition,
        DateTimeImmutable $occurredAt,
    ): int {
        $displayOrder = $this->orderingManager->getNextPositionForUpdate(
            $this->pdo,
            self::variantOrderingConfig(),
            $productId,
        );
        $timestamp = $occurredAt->format('Y-m-d H:i:s');
        $insert = $this->pdo->prepare(
            'INSERT INTO `maa_product_variants` (`product_id`, `composition_fingerprint`, `sku`, `barcode`, `status`, '
            . '`display_order`, `created_at`, `updated_at`, `deleted_at`) '
            . 'VALUES (:product_id, :fingerprint, NULL, NULL, \'active\', :display_order, :created_at, :updated_at, NULL)',
        );
        $insert->execute([
            'product_id' => $productId,
            'fingerprint' => $fingerprint->value,
            'display_order' => $displayOrder,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        $id = DigitString::toPositiveInt($this->pdo->lastInsertId());
        if ($id === null) {
            throw ProductStorageFailureFactory::invalidAutoIncrementIdentity('Variant');
        }
        $variantId = $id;

        if ($composition !== []) {
            $pair = $this->pdo->prepare(
                'INSERT INTO `maa_product_variant_option_values` (`product_id`, `variant_id`, `option_id`, `option_value_id`) '
                . 'VALUES (:product_id, :variant_id, :option_id, :option_value_id)',
            );
            foreach ($composition as $selection) {
                $pair->execute([
                    'product_id' => $productId,
                    'variant_id' => $variantId,
                    'option_id' => $selection->optionId,
                    'option_value_id' => $selection->optionValueId,
                ]);
            }
        }

        return $variantId;
    }

    /**
     * Variants are ordered within their Product; soft-deleted Variants are outside the live order.
     */
    private static function variantOrderingConfig(): ScopedOrderingConfig
    {
        return new ScopedOrderingConfig(
            table: 'maa_product_variants',
            scopeColumn: 'product_id',
            idColumn: 'id',
            orderColumn: 'display_order',
            deletedAtColumn: 'deleted_at',
            nullableScope: false,
            updatedAtColumn: 'updated_at',
        );
    }

    /**
     * @param list<int> $ids
     */
    private function updateStatusOf(string $table, array $ids, bool $active, DateTimeImmutable $occurredAt): void
    {
        if ($ids === []) {
            return;
        }
        $statement = $this->pdo->prepare(
            'UPDATE `' . $table . '` SET `status` = ?, `updated_at` = ? WHERE `deleted_at` IS NULL AND `id` IN (' . self::placeholders($ids) . ')',
        );
        $statement->execute([$active ? 'active' : 'inactive', $occurredAt->format('Y-m-d H:i:s'), ...$ids]);
    }

    /**
     * Composition pairs of the locked Variants, read with shared locks through the
     * (variant_id, option_id) key so that only those Variants' index ranges are locked.
     *
     * @param list<int> $variantIds
     *
     * @return array<int, list<OptionValueSelection>>
     */
    private function compositionsOf(array $variantIds): array
    {
        if ($variantIds === []) {
            return [];
        }
        $statement = $this->pdo->prepare(
            'SELECT `variant_id`, `option_id`, `option_value_id` FROM `maa_product_variant_option_values` '
            . 'FORCE INDEX (`uq_maa_product_variant_option_values_variant_option`) WHERE `variant_id` IN ('
            . self::placeholders($variantIds) . ') FOR SHARE',
        );
        $statement->execute($variantIds);

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $compositions = [];
        foreach ($rows as $row) {
            $compositions[RowHydrator::positiveInt($row, 'variant_id')][] = new OptionValueSelection(
                RowHydrator::positiveInt($row, 'option_id'),
                RowHydrator::positiveInt($row, 'option_value_id'),
            );
        }
        foreach ($compositions as &$composition) {
            usort($composition, static fn(OptionValueSelection $a, OptionValueSelection $b): int => $a->optionId <=> $b->optionId);
        }
        unset($composition);

        return $compositions;
    }

    /**
     * Identities of the given locked owners that have a non-deleted, non-empty label, read with
     * shared locks through the owner's (owner_id, language_code_identity) key so that only those
     * owners' index ranges are locked.
     *
     * @param list<int> $ownerIds
     *
     * @return array<int, true>
     */
    private function labelledOwners(string $table, string $ownerColumn, string $identityKey, array $ownerIds): array
    {
        if ($ownerIds === []) {
            return [];
        }
        $statement = $this->pdo->prepare(
            'SELECT DISTINCT `' . $ownerColumn . '` AS `owner` FROM `' . $table . '` FORCE INDEX (`' . $identityKey . '`) WHERE `'
            . $ownerColumn . '` IN (' . self::placeholders($ownerIds) . ') AND `deleted_at` IS NULL '
            . 'AND `name` REGEXP \'[^[:space:]]\' FOR SHARE',
        );
        $statement->execute($ownerIds);

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $owners = [];
        foreach ($rows as $row) {
            $owners[RowHydrator::positiveInt($row, 'owner')] = true;
        }

        return $owners;
    }

    /**
     * @param list<int> $ids
     */
    private static function placeholders(array $ids): string
    {
        return implode(',', array_fill(0, count($ids), '?'));
    }
}
