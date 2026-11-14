<?php

declare(strict_types=1);

namespace Maatify\Product\Variant\Repository\Pdo;

use DateTimeImmutable;
use Maatify\PhpPersistence\Ordering\Config\ScopedOrderingConfig;
use Maatify\PhpPersistence\Ordering\Repository\ScopedOrderingManager;
use Maatify\Product\Common\Exception\ProductStorageFailureFactory;
use Maatify\Product\Common\Repository\Pdo\DigitString;
use Maatify\Product\Common\Repository\Pdo\DuplicateKey;
use Maatify\Product\Common\Repository\Pdo\RowHydrator;
use Maatify\Product\Variant\DTO\VariantLifecycleStateDTO;
use Maatify\Product\Variant\Enum\VariantStatusEnum;
use Maatify\Product\Variant\Exception\VariantBarcodeAlreadyExistsException;
use Maatify\Product\Variant\Exception\VariantSkuAlreadyExistsException;
use Maatify\Product\Variant\Repository\VariantCommandRepositoryInterface;
use Maatify\Product\Variant\ValueObject\CompositionFingerprint;
use PDO;
use PDOException;

/**
 * PDO write adapter for Variant persistence on MySQL 8.0.16 or later.
 *
 * Only a duplicate-key error (MySQL driver code 1062) naming `uq_maa_product_variants_sku` or
 * `uq_maa_product_variants_barcode` is converted, to {@see VariantSkuAlreadyExistsException} or
 * {@see VariantBarcodeAlreadyExistsException} with the original exception as `previous`. Every other
 * {@see PDOException} propagates unchanged. The connection
 * must report errors as exceptions and must be the connection of the transaction
 * the caller owns.
 *
 * @internal Not part of the Public Runtime API.
 */
final readonly class PdoVariantCommandRepository implements VariantCommandRepositoryInterface
{
    private const string PRODUCT_TABLE = 'maa_product_products';
    private const string VARIANT_TABLE = 'maa_product_variants';

    public function __construct(
        private PDO $pdo,
        private ScopedOrderingManager $orderingManager,
    ) {}

    public function createEmptyComposition(int $productId, DateTimeImmutable $occurredAt): int
    {
        $this->lockProduct($productId);

        // Only Product creation calls this method. Its newly inserted Product has no
        // visible competing writers or prior Variants. Avoid locking a shared empty gap.
        $displayOrder = $this->orderingManager->getNextPosition(
            $this->pdo,
            self::orderingConfig(),
            $productId,
        );

        $statement = $this->pdo->prepare(
            'INSERT INTO `' . self::VARIANT_TABLE . '` '
            . '(`product_id`, `composition_fingerprint`, `sku`, `barcode`, `status`, `display_order`, '
            . '`created_at`, `updated_at`, `deleted_at`) '
            . 'VALUES (:product_id, :fingerprint, NULL, NULL, :status, :display_order, '
            . ':created_at, :updated_at, NULL)',
        );
        $timestamp = $occurredAt->format('Y-m-d H:i:s');
        $statement->execute([
            'product_id' => $productId,
            'fingerprint' => CompositionFingerprint::forEmptyComposition()->value,
            'status' => VariantStatusEnum::ACTIVE->value,
            'display_order' => $displayOrder,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $id = DigitString::toPositiveInt($this->pdo->lastInsertId());
        if ($id === null) {
            throw ProductStorageFailureFactory::invalidAutoIncrementIdentity('Variant');
        }

        return $id;
    }

    public function lockForUpdate(int $variantId): ?VariantLifecycleStateDTO
    {
        $statement = $this->pdo->prepare(
            'SELECT `id`, `product_id`, `status`, `sku`, `barcode`, (`deleted_at` IS NOT NULL) AS `deleted` FROM `' . self::VARIANT_TABLE . '` WHERE `id` = :id FOR UPDATE',
        );
        $statement->execute(['id' => $variantId]);

        /** @var array<string, mixed>|false $row */
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return new VariantLifecycleStateDTO(
            id: RowHydrator::positiveInt($row, 'id'),
            productId: RowHydrator::positiveInt($row, 'product_id'),
            active: RowHydrator::isActive($row),
            deleted: RowHydrator::flag($row, 'deleted'),
            sku: RowHydrator::nullableString($row, 'sku'),
            barcode: RowHydrator::nullableString($row, 'barcode'),
        );
    }

    public function updateStatus(int $variantId, VariantStatusEnum $status, DateTimeImmutable $occurredAt): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE `' . self::VARIANT_TABLE . '` SET `status` = :status, `updated_at` = :updated_at WHERE '
            . '`id` = :id AND `deleted_at` IS NULL',
        );
        $statement->execute([
            'status' => $status->value,
            'updated_at' => $occurredAt->format('Y-m-d H:i:s'),
            'id' => $variantId,
        ]);

        return $statement->rowCount() > 0;
    }

    public function updateIdentifiers(int $variantId, ?string $sku, ?string $barcode, DateTimeImmutable $occurredAt): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE `' . self::VARIANT_TABLE . '` SET `sku` = :sku, `barcode` = :barcode, `updated_at` = :updated_at WHERE '
            . '`id` = :id AND `deleted_at` IS NULL',
        );
        try {
            $statement->execute(['sku' => $sku, 'barcode' => $barcode, 'updated_at' => $occurredAt->format('Y-m-d H:i:s'), 'id' => $variantId]);
        } catch (PDOException $exception) {
            $key = DuplicateKey::keyName($exception);
            if ($sku !== null && $key === 'uq_maa_product_variants_sku') {
                throw VariantSkuAlreadyExistsException::withSku($sku, $exception);
            }
            if ($barcode !== null && $key === 'uq_maa_product_variants_barcode') {
                throw VariantBarcodeAlreadyExistsException::withBarcode($barcode, $exception);
            }

            throw $exception;
        }

        return $statement->rowCount() > 0;
    }

    public function softDelete(int $variantId, DateTimeImmutable $occurredAt): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE `' . self::VARIANT_TABLE . '` SET `deleted_at` = :deleted_at, `updated_at` = :updated_at WHERE '
            . '`id` = :id AND `deleted_at` IS NULL',
        );
        $timestamp = $occurredAt->format('Y-m-d H:i:s');
        $statement->execute(['deleted_at' => $timestamp, 'updated_at' => $timestamp, 'id' => $variantId]);

        return $statement->rowCount() > 0;
    }

    public function restore(int $variantId, int $productId, DateTimeImmutable $occurredAt): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE `' . self::VARIANT_TABLE . '` SET `deleted_at` = NULL, `display_order` = :display_order, `updated_at` = :updated_at WHERE '
            . '`id` = :id AND `deleted_at` IS NOT NULL',
        );
        $statement->execute([
            'display_order' => $this->orderingManager->getNextPositionForUpdate($this->pdo, self::orderingConfig(), $productId),
            'updated_at' => $occurredAt->format('Y-m-d H:i:s'),
            'id' => $variantId,
        ]);

        return $statement->rowCount() > 0;
    }

    public function updateDisplayOrder(int $variantId, int $productId, int $displayOrder, DateTimeImmutable $occurredAt): bool
    {
        return $this->orderingManager->moveWithinScope($this->pdo, self::orderingConfig(), $productId, $variantId, $displayOrder, $occurredAt->format('Y-m-d H:i:s'));
    }

    /**
     * Variants are ordered within their Product; soft-deleted Variants are outside the live order.
     */
    private static function orderingConfig(): ScopedOrderingConfig
    {
        return new ScopedOrderingConfig(
            table: self::VARIANT_TABLE,
            scopeColumn: 'product_id',
            idColumn: 'id',
            orderColumn: 'display_order',
            deletedAtColumn: 'deleted_at',
            nullableScope: false,
            updatedAtColumn: 'updated_at',
        );
    }

    /**
     * Takes the Product aggregate lock for the surrounding transaction.
     *
     * Every mutation that can affect a Product's Variant graph serializes on this
     * row before reading or writing dependent state.
     */
    private function lockProduct(int $productId): void
    {
        $statement = $this->pdo->prepare(
            'SELECT `id` FROM `' . self::PRODUCT_TABLE . '` WHERE `id` = :id FOR UPDATE',
        );
        $statement->execute(['id' => $productId]);
    }
}
