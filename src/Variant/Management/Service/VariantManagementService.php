<?php

declare(strict_types=1);

namespace Maatify\Product\Variant\Management\Service;

use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\PhpPersistence\Pagination\DTO\PageResultDTO;
use Maatify\PhpPersistence\Transaction\Repository\SavepointTransactionRunnerInterface;
use Maatify\Product\Common\Enum\ProductDeletedStateEnum;
use Maatify\Product\Common\Exception\ProductBusinessRuleException;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;
use Maatify\Product\Common\Exception\ProductStorageFailureFactory;
use Maatify\Product\Common\Validation\TextInputRules;
use Maatify\Product\Product\DTO\ProductConfigurationSnapshotDTO;
use Maatify\Product\Product\Repository\ProductConfigurationRepositoryInterface;
use Maatify\Product\Product\Service\ProductConfigurationValidator;
use Maatify\Product\Variant\DTO\VariantCollectionDTO;
use Maatify\Product\Variant\DTO\VariantDTO;
use Maatify\Product\Variant\DTO\VariantStateDTO;
use Maatify\Product\Variant\Enum\VariantStatusEnum;
use Maatify\Product\Variant\Exception\VariantNotFoundException;
use Maatify\Product\Variant\Management\Command\UpdateVariantDisplayOrderCommand;
use Maatify\Product\Variant\Management\Command\UpdateVariantIdentifiersCommand;
use Maatify\Product\Variant\Management\Criteria\VariantListCriteriaDTO;
use Maatify\Product\Variant\Repository\VariantCommandRepositoryInterface;
use Maatify\Product\Variant\Repository\VariantQueryReaderInterface;
use Maatify\Product\Variant\ValueObject\VariantIdentifierChange;
use Maatify\SharedCommon\Contracts\ClockInterface;

/**
 * Orchestrates Variant management reads, status and lifecycle mutations, the SKU and barcode
 * update, and ordering.
 *
 * Reads write nothing and open no transaction. Every mutation runs in the savepoint transaction
 * runner and locks the owning Product row first. Status and lifecycle mutations read the
 * configuration under that lock, validate the resulting graph in memory, and write only when the
 * invariants hold; the identifier update and ordering change no invariant and rely on the database
 * keys and the shared Ordering API.
 *
 * @internal Not part of the Public Runtime API.
 */
final readonly class VariantManagementService implements VariantManagementServiceInterface
{
    public function __construct(
        private VariantQueryReaderInterface $reader,
        private VariantCommandRepositoryInterface $variants,
        private ProductConfigurationRepositoryInterface $configuration,
        private SavepointTransactionRunnerInterface $transactions,
        private ClockInterface $clock,
    ) {}

    public function getByIdForManagement(
        int $variantId,
        ProductDeletedStateEnum $deletedState = ProductDeletedStateEnum::NON_DELETED,
    ): VariantDTO {
        self::requirePositive($variantId, 'variantId');
        $variant = $this->reader->findById($variantId);
        if ($variant === null || !self::matchesDeletedState($variant, $deletedState)) {
            throw VariantNotFoundException::withId($variantId);
        }

        return $variant;
    }

    public function getBySkuForManagement(
        string $sku,
        ProductDeletedStateEnum $deletedState = ProductDeletedStateEnum::NON_DELETED,
    ): VariantDTO {
        TextInputRules::requiredText($sku, 'sku', VariantIdentifierChange::MAX_LENGTH);
        $variant = $this->reader->findBySku($sku);
        if ($variant === null || !self::matchesDeletedState($variant, $deletedState)) {
            throw VariantNotFoundException::withSku($sku);
        }

        return $variant;
    }

    public function getByBarcodeForManagement(
        string $barcode,
        ProductDeletedStateEnum $deletedState = ProductDeletedStateEnum::NON_DELETED,
    ): VariantDTO {
        TextInputRules::requiredText($barcode, 'barcode', VariantIdentifierChange::MAX_LENGTH);
        $variant = $this->reader->findByBarcode($barcode);
        if ($variant === null || !self::matchesDeletedState($variant, $deletedState)) {
            throw VariantNotFoundException::withBarcode($barcode);
        }

        return $variant;
    }

    private static function matchesDeletedState(VariantDTO $variant, ProductDeletedStateEnum $deletedState): bool
    {
        return match ($deletedState) {
            ProductDeletedStateEnum::NON_DELETED => $variant->deletedAt === null,
            ProductDeletedStateEnum::DELETED_ONLY => $variant->deletedAt !== null,
            ProductDeletedStateEnum::INCLUDE_DELETED => true,
        };
    }

    public function listByProductIdForManagement(
        int $productId,
        VariantListCriteriaDTO $criteria = new VariantListCriteriaDTO(),
    ): VariantCollectionDTO {
        self::requirePositive($productId, 'productId');

        return new VariantCollectionDTO($this->reader->listForManagement($productId, $criteria));
    }

    public function paginateByProductIdForManagement(int $productId, VariantListCriteriaDTO $criteria, PageRequest $request): PageResultDTO
    {
        self::requirePositive($productId, 'productId');

        return $this->reader->paginateForManagement($productId, $criteria, $request);
    }

    public function updateStatus(int $variantId, VariantStatusEnum $status): void
    {
        self::requirePositive($variantId, 'variantId');
        $occurredAt = $this->clock->now();

        $this->transactions->run(function () use ($variantId, $status, $occurredAt): void {
            [$snapshot, $variant] = $this->lockedVariant($variantId);
            $this->assertMutable($snapshot, $variant);
            $activate = $status === VariantStatusEnum::ACTIVE;
            if ($variant->active === $activate) {
                return;
            }
            self::assertValid($snapshot->withVariant($variant->withActive($activate)));
            if (!$this->variants->updateStatus($variantId, $status, $occurredAt)) {
                throw ProductStorageFailureFactory::lockedRowVanished('Variant', $variantId);
            }
            $this->configuration->advanceRevision($snapshot->productId);
        });
    }

    public function softDelete(int $variantId): void
    {
        self::requirePositive($variantId, 'variantId');
        $occurredAt = $this->clock->now();

        $this->transactions->run(function () use ($variantId, $occurredAt): void {
            [$snapshot, $variant] = $this->lockedVariant($variantId);
            $this->assertMutable($snapshot, $variant);
            self::assertValid($snapshot->withVariant($variant->withDeleted(true)));
            if (!$this->variants->softDelete($variantId, $occurredAt)) {
                throw ProductStorageFailureFactory::lockedRowVanished('Variant', $variantId);
            }
            $this->configuration->advanceRevision($snapshot->productId);
        });
    }

    public function updateIdentifiers(UpdateVariantIdentifiersCommand $command): void
    {
        $variantId = $command->variantId;
        $occurredAt = $this->clock->now();

        $this->transactions->run(function () use ($command, $variantId, $occurredAt): void {
            [$snapshot, $variant] = $this->lockedVariant($variantId);
            $this->assertMutable($snapshot, $variant);
            $stored = $this->variants->lockForUpdate($variantId);
            if ($stored === null) {
                throw ProductStorageFailureFactory::lockedRowVanished('Variant', $variantId);
            }
            $sku = $command->sku->resolve($stored->sku);
            $barcode = $command->barcode->resolve($stored->barcode);
            if ($sku === $stored->sku && $barcode === $stored->barcode) {
                return;
            }
            if (!$this->variants->updateIdentifiers($variantId, $sku, $barcode, $occurredAt)) {
                throw ProductStorageFailureFactory::lockedRowVanished('Variant', $variantId);
            }
        });
    }

    public function updateDisplayOrder(UpdateVariantDisplayOrderCommand $command): void
    {
        $variantId = $command->variantId;
        $occurredAt = $this->clock->now();

        $this->transactions->run(function () use ($command, $variantId, $occurredAt): void {
            [$snapshot, $variant] = $this->lockedVariant($variantId);
            $this->assertMutable($snapshot, $variant);
            if (!$this->variants->updateDisplayOrder($variantId, $snapshot->productId, $command->displayOrder, $occurredAt)) {
                throw ProductStorageFailureFactory::lockedRowVanished('Variant', $variantId);
            }
        });
    }

    public function restore(int $variantId): void
    {
        self::requirePositive($variantId, 'variantId');
        $occurredAt = $this->clock->now();

        $this->transactions->run(function () use ($variantId, $occurredAt): void {
            [$snapshot, $variant] = $this->lockedVariant($variantId);
            if ($snapshot->productDeleted) {
                throw ProductBusinessRuleException::ownerSoftDeleted('Variant', $variantId, 'Product', $snapshot->productId);
            }
            if (!$variant->deleted) {
                throw ProductBusinessRuleException::notSoftDeleted('Variant', $variantId);
            }
            self::assertValid($snapshot->withVariant($variant->withDeleted(false)));
            if (!$this->variants->restore($variantId, $snapshot->productId, $occurredAt)) {
                throw ProductStorageFailureFactory::lockedRowVanished('Variant', $variantId);
            }
            $this->configuration->advanceRevision($snapshot->productId);
        });
    }

    /**
     * Locks the owning Product and returns the configuration snapshot with the Variant's state in it.
     *
     * @return array{ProductConfigurationSnapshotDTO, VariantStateDTO}
     */
    private function lockedVariant(int $variantId): array
    {
        $productId = $this->configuration->findVariantProductId($variantId);
        $snapshot = $productId === null ? null : $this->configuration->lockSnapshot($productId);
        $variant = $snapshot?->variants[$variantId] ?? null;
        if ($snapshot === null || $variant === null) {
            throw VariantNotFoundException::withId($variantId);
        }

        return [$snapshot, $variant];
    }

    private function assertMutable(ProductConfigurationSnapshotDTO $snapshot, VariantStateDTO $variant): void
    {
        if ($snapshot->productDeleted) {
            throw ProductBusinessRuleException::ownerSoftDeleted('Variant', $variant->id, 'Product', $snapshot->productId);
        }
        if ($variant->deleted) {
            throw ProductBusinessRuleException::softDeleted('Variant', $variant->id);
        }
    }

    private static function assertValid(ProductConfigurationSnapshotDTO $snapshot): void
    {
        $violation = ProductConfigurationValidator::firstViolation($snapshot);
        if ($violation !== null) {
            throw ProductBusinessRuleException::invalidConfiguration($snapshot->productId, $violation);
        }
    }

    private static function requirePositive(int $identity, string $field): void
    {
        if ($identity < 1) {
            throw ProductInvalidArgumentException::nonPositiveId($field);
        }
    }
}
