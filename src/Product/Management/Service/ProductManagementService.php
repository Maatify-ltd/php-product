<?php

declare(strict_types=1);

namespace Maatify\Product\Product\Management\Service;

use DateTimeImmutable;
use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\PhpPersistence\Pagination\DTO\PageResultDTO;
use Maatify\PhpPersistence\Transaction\Repository\SavepointTransactionRunnerInterface;
use Maatify\Product\Common\Enum\ProductDeletedStateEnum;
use Maatify\Product\Common\Exception\ProductBusinessRuleException;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;
use Maatify\Product\Common\Exception\ProductStorageFailureFactory;
use Maatify\Product\Common\Validation\TextInputRules;
use Maatify\Product\Product\DTO\CreatedProductDTO;
use Maatify\Product\Product\DTO\ProductCollectionDTO;
use Maatify\Product\Product\DTO\ProductConfigurationSnapshotDTO;
use Maatify\Product\Product\DTO\ProductDTO;
use Maatify\Product\Product\DTO\ProductReconfigurationResultDTO;
use Maatify\Product\Product\Enum\ProductStatusEnum;
use Maatify\Product\Product\Exception\ProductNotFoundException;
use Maatify\Product\Product\Management\Command\CreateProductCommand;
use Maatify\Product\Product\Management\Command\ReconfigureProductCommand;
use Maatify\Product\Product\Management\Command\ReconfigureVariantTarget;
use Maatify\Product\Product\Management\Criteria\ProductListCriteriaDTO;
use Maatify\Product\Product\Repository\ProductCommandRepositoryInterface;
use Maatify\Product\Product\Repository\ProductConfigurationRepositoryInterface;
use Maatify\Product\Product\Repository\ProductQueryReaderInterface;
use Maatify\Product\Product\Service\ProductConfigurationValidator;
use Maatify\Product\Variant\Exception\VariantCompositionConflictException;
use Maatify\Product\Variant\Exception\VariantNotFoundException;
use Maatify\Product\Variant\Repository\VariantCommandRepositoryInterface;
use Maatify\Product\Variant\ValueObject\CompositionFingerprint;
use Maatify\SharedCommon\Contracts\ClockInterface;

/**
 * Orchestrates Product creation, management reads, status and lifecycle, and reconfiguration.
 *
 * Every mutation runs in the savepoint transaction runner, so it is atomic whoever owns the
 * transaction: the runner commits a transaction it opened and rolls back to an operation-local
 * savepoint inside a Host transaction. Graph-affecting mutations lock the Product row first,
 * read the configuration under that lock, validate the resulting graph in memory, and write only
 * when the invariants hold. Storage exceptions propagate unchanged.
 *
 * @internal Not part of the Public Runtime API.
 */
final readonly class ProductManagementService implements ProductManagementServiceInterface
{
    public function __construct(
        private ProductCommandRepositoryInterface $products,
        private VariantCommandRepositoryInterface $variants,
        private ProductQueryReaderInterface $reader,
        private ProductConfigurationRepositoryInterface $configuration,
        private SavepointTransactionRunnerInterface $transactions,
        private ClockInterface $clock,
    ) {}

    public function create(CreateProductCommand $command): CreatedProductDTO
    {
        $occurredAt = $this->clock->now();

        return $this->transactions->run(
            function () use ($command, $occurredAt): CreatedProductDTO {
                $productId = $this->products->create($command, $occurredAt);
                $variantId = $this->variants->createEmptyComposition($productId, $occurredAt);

                return new CreatedProductDTO($productId, $variantId);
            },
        );
    }

    public function getByIdForManagement(
        int $productId,
        ProductDeletedStateEnum $deletedState = ProductDeletedStateEnum::NON_DELETED,
    ): ProductDTO {
        self::requirePositive($productId);
        $product = $this->reader->findById($productId);
        $matches = $product !== null && match ($deletedState) {
            ProductDeletedStateEnum::NON_DELETED => $product->deletedAt === null,
            ProductDeletedStateEnum::DELETED_ONLY => $product->deletedAt !== null,
            ProductDeletedStateEnum::INCLUDE_DELETED => true,
        };
        if ($product === null || !$matches) {
            throw ProductNotFoundException::withId($productId);
        }

        return $product;
    }

    public function getByCodeForManagement(
        string $code,
        ProductDeletedStateEnum $deletedState = ProductDeletedStateEnum::NON_DELETED,
    ): ProductDTO {
        TextInputRules::requiredText($code, 'code', CreateProductCommand::MAX_CODE_LENGTH);
        $product = $this->reader->findByCode($code);
        $matches = $product !== null && match ($deletedState) {
            ProductDeletedStateEnum::NON_DELETED => $product->deletedAt === null,
            ProductDeletedStateEnum::DELETED_ONLY => $product->deletedAt !== null,
            ProductDeletedStateEnum::INCLUDE_DELETED => true,
        };
        if ($product === null || !$matches) {
            throw ProductNotFoundException::withCode($code);
        }

        return $product;
    }

    public function listForManagement(ProductListCriteriaDTO $criteria = new ProductListCriteriaDTO()): ProductCollectionDTO
    {
        return new ProductCollectionDTO($this->reader->listForManagement($criteria));
    }

    public function paginateForManagement(ProductListCriteriaDTO $criteria, PageRequest $request): PageResultDTO
    {
        return $this->reader->paginateForManagement($criteria, $request);
    }

    public function updateStatus(int $productId, ProductStatusEnum $status): void
    {
        self::requirePositive($productId);
        $occurredAt = $this->clock->now();

        $this->transactions->run(function () use ($productId, $status, $occurredAt): void {
            $snapshot = $this->lockedSnapshot($productId);
            if ($snapshot->productDeleted) {
                throw ProductBusinessRuleException::softDeleted('Product', $productId);
            }
            $activate = $status === ProductStatusEnum::ACTIVE;
            if ($snapshot->productActive === $activate) {
                return;
            }
            if ($activate) {
                self::assertValid($snapshot->withProduct(true, false));
            }
            if (!$this->products->updateStatus($productId, $status, $occurredAt)) {
                throw ProductStorageFailureFactory::lockedRowVanished('Product', $productId);
            }
        });
    }

    public function softDelete(int $productId): void
    {
        self::requirePositive($productId);
        $occurredAt = $this->clock->now();

        $this->transactions->run(function () use ($productId, $occurredAt): void {
            $state = $this->products->lockForUpdate($productId);
            if ($state === null) {
                throw ProductNotFoundException::withId($productId);
            }
            if ($state->deleted) {
                throw ProductBusinessRuleException::softDeleted('Product', $productId);
            }
            if (!$this->products->softDelete($productId, $occurredAt)) {
                throw ProductStorageFailureFactory::lockedRowVanished('Product', $productId);
            }
        });
    }

    public function restore(int $productId): void
    {
        self::requirePositive($productId);
        $occurredAt = $this->clock->now();

        $this->transactions->run(function () use ($productId, $occurredAt): void {
            $snapshot = $this->lockedSnapshot($productId);
            if (!$snapshot->productDeleted) {
                throw ProductBusinessRuleException::notSoftDeleted('Product', $productId);
            }
            self::assertValid($snapshot->withProduct($snapshot->productActive, false));
            if (!$this->products->restore($productId, $occurredAt)) {
                throw ProductStorageFailureFactory::lockedRowVanished('Product', $productId);
            }
        });
    }

    public function reconfigure(ReconfigureProductCommand $command): ProductReconfigurationResultDTO
    {
        $occurredAt = $this->clock->now();

        return $this->transactions->run(
            fn(): ProductReconfigurationResultDTO => $this->applyReconfiguration($command, $occurredAt),
        );
    }

    private function applyReconfiguration(ReconfigureProductCommand $command, DateTimeImmutable $occurredAt): ProductReconfigurationResultDTO
    {
        $productId = $command->productId;
        $snapshot = $this->lockedSnapshot($productId);
        if ($snapshot->productDeleted) {
            throw ProductBusinessRuleException::softDeleted('Product', $productId);
        }

        // 1. The target active Option/Value set must exist under the Product, be non-deleted, and be labelled.
        /** @var array<int, array<int, true>> $targetValues optionId => valueId => true */
        $targetValues = [];
        foreach ($command->options as $target) {
            $option = $snapshot->options[$target->optionId] ?? null;
            if ($option === null) {
                throw ProductBusinessRuleException::invalidConfiguration($productId, sprintf('Option [%d] does not belong to the Product', $target->optionId));
            }
            if ($option->deleted) {
                throw ProductBusinessRuleException::invalidConfiguration($productId, sprintf('Option [%d] is soft deleted and cannot be activated', $option->id));
            }
            if (!$option->labelled) {
                throw ProductBusinessRuleException::invalidConfiguration($productId, sprintf('Option [%d] has no label in any non-deleted Content record', $option->id));
            }
            foreach ($target->activeOptionValueIds as $valueId) {
                $value = $snapshot->optionValues[$valueId] ?? null;
                if ($value === null || $value->optionId !== $option->id) {
                    throw ProductBusinessRuleException::invalidConfiguration($productId, sprintf('Option Value [%d] does not belong to Option [%d]', $valueId, $option->id));
                }
                if ($value->deleted) {
                    throw ProductBusinessRuleException::invalidConfiguration($productId, sprintf('Option Value [%d] is soft deleted and cannot be activated', $valueId));
                }
                if (!$value->labelled) {
                    throw ProductBusinessRuleException::invalidConfiguration($productId, sprintf('Option Value [%d] has no label in any non-deleted Content record', $valueId));
                }
                $targetValues[$option->id][$valueId] = true;
            }
        }

        // 2. Every target composition covers exactly the target Options with target Values, and every target Value is represented.
        if ($command->variants === []) {
            throw ProductBusinessRuleException::invalidConfiguration($productId, 'the target set contains no Variant; at least one active valid Variant is required');
        }
        /** @var array<int, true> $represented */
        $represented = [];
        foreach ($command->variants as $target) {
            $covered = [];
            foreach ($target->composition as $pair) {
                if (!isset($targetValues[$pair->optionId])) {
                    throw ProductBusinessRuleException::invalidConfiguration($productId, sprintf('composition "%s" selects Option [%d], which is not in the target active Option set', $target->fingerprint()->canonical, $pair->optionId));
                }
                if (!isset($targetValues[$pair->optionId][$pair->optionValueId])) {
                    throw ProductBusinessRuleException::invalidConfiguration($productId, sprintf('composition "%s" selects Option Value [%d], which is not a target active Value of Option [%d]', $target->fingerprint()->canonical, $pair->optionValueId, $pair->optionId));
                }
                $covered[$pair->optionId] = true;
                $represented[$pair->optionValueId] = true;
            }
            foreach (array_keys($targetValues) as $optionId) {
                if (!isset($covered[$optionId])) {
                    throw ProductBusinessRuleException::invalidConfiguration($productId, sprintf('composition "%s" has no Value for target Option [%d]', $target->fingerprint()->canonical, $optionId));
                }
            }
        }
        foreach ($targetValues as $optionId => $values) {
            foreach (array_keys($values) as $valueId) {
                if (!isset($represented[$valueId])) {
                    throw ProductBusinessRuleException::invalidConfiguration($productId, sprintf('target Option Value [%d] of Option [%d] is not represented by any target Variant', $valueId, $optionId));
                }
            }
        }

        // 3. Resolve each target composition to an identity: reuse an existing one only when named, otherwise create.
        $byFingerprint = [];
        foreach ($snapshot->variants as $variant) {
            $byFingerprint[$variant->fingerprint] = $variant;
        }
        /** @var list<ReconfigureVariantTarget> $toCreate */
        $toCreate = [];
        $reactivate = [];
        $restore = [];
        $keptActive = [];
        foreach ($command->variants as $target) {
            $fingerprint = $target->fingerprint();
            $existing = $byFingerprint[$fingerprint->value] ?? null;
            if ($existing !== null) {
                if (CompositionFingerprint::forSelections($existing->composition)->canonical !== $fingerprint->canonical) {
                    throw ProductStorageFailureFactory::compositionFingerprintMismatch($existing->id);
                }
                if ($target->reuseVariantId === null) {
                    throw VariantCompositionConflictException::compositionExists($existing->id, $fingerprint->canonical);
                }
                if ($target->reuseVariantId !== $existing->id) {
                    throw VariantCompositionConflictException::reuseIdentityMismatch($target->reuseVariantId, $existing->id);
                }
                if ($existing->deleted) {
                    $restore[] = $existing->id;
                } elseif (!$existing->active) {
                    $reactivate[] = $existing->id;
                } else {
                    $keptActive[] = $existing->id;
                }
                continue;
            }
            if ($target->composition === []) {
                throw ProductStorageFailureFactory::missingEmptyCompositionVariant($productId);
            }
            if ($target->reuseVariantId !== null) {
                if (isset($snapshot->variants[$target->reuseVariantId])) {
                    throw VariantCompositionConflictException::reuseCompositionMismatch($target->reuseVariantId, $fingerprint->canonical);
                }
                if ($this->configuration->findVariantProductId($target->reuseVariantId) === null) {
                    throw VariantNotFoundException::withId($target->reuseVariantId);
                }
                throw VariantCompositionConflictException::reuseVariantOfAnotherProduct($target->reuseVariantId, $productId);
            }
            $toCreate[] = $target;
        }

        // 4. Compute the status changes: the command is the complete target state.
        $retained = array_flip([...$keptActive, ...$reactivate]);
        $deactivateVariants = [];
        foreach ($snapshot->variants as $variant) {
            if (!$variant->deleted && $variant->active && !isset($retained[$variant->id])) {
                $deactivateVariants[] = $variant->id;
            }
        }
        $activateOptions = [];
        $deactivateOptions = [];
        foreach ($snapshot->options as $option) {
            if ($option->deleted) {
                continue;
            }
            if (isset($targetValues[$option->id]) && !$option->active) {
                $activateOptions[] = $option->id;
            } elseif (!isset($targetValues[$option->id]) && $option->active) {
                $deactivateOptions[] = $option->id;
            }
        }
        $activateValues = [];
        $deactivateValues = [];
        foreach ($snapshot->optionValues as $value) {
            if ($value->deleted || !isset($targetValues[$value->optionId])) {
                continue;
            }
            if (isset($targetValues[$value->optionId][$value->id]) && !$value->active) {
                $activateValues[] = $value->id;
            } elseif (!isset($targetValues[$value->optionId][$value->id]) && $value->active) {
                $deactivateValues[] = $value->id;
            }
        }

        // 5. Write in lock order: Options, Option Values, Variants.
        $this->configuration->updateOptionStatus($deactivateOptions, false, $occurredAt);
        $this->configuration->updateOptionStatus($activateOptions, true, $occurredAt);
        $this->configuration->updateOptionValueStatus($deactivateValues, false, $occurredAt);
        $this->configuration->updateOptionValueStatus($activateValues, true, $occurredAt);
        $this->configuration->updateVariantStatus($deactivateVariants, false, $occurredAt);
        $this->configuration->updateVariantStatus($reactivate, true, $occurredAt);
        foreach ($restore as $variantId) {
            if (!$this->configuration->restoreVariantAsActive($variantId, $productId, $occurredAt)) {
                throw ProductStorageFailureFactory::lockedRowVanished('Variant', $variantId);
            }
        }
        $created = [];
        foreach ($toCreate as $target) {
            $created[] = $this->configuration->createActiveVariant($productId, $target->fingerprint(), $target->composition, $occurredAt);
        }

        // 6. Every Product-local invariant must hold before the operation completes.
        self::assertValid($this->lockedSnapshot($productId));

        return new ProductReconfigurationResultDTO(
            createdVariantIds: $created,
            reactivatedVariantIds: self::ascending($reactivate),
            restoredVariantIds: self::ascending($restore),
            deactivatedVariantIds: self::ascending($deactivateVariants),
            activatedOptionIds: self::ascending($activateOptions),
            deactivatedOptionIds: self::ascending($deactivateOptions),
            activatedOptionValueIds: self::ascending($activateValues),
            deactivatedOptionValueIds: self::ascending($deactivateValues),
        );
    }

    private function lockedSnapshot(int $productId): ProductConfigurationSnapshotDTO
    {
        $snapshot = $this->configuration->lockSnapshot($productId);
        if ($snapshot === null) {
            throw ProductNotFoundException::withId($productId);
        }

        return $snapshot;
    }

    private static function assertValid(ProductConfigurationSnapshotDTO $snapshot): void
    {
        $violation = ProductConfigurationValidator::firstViolation($snapshot);
        if ($violation !== null) {
            throw ProductBusinessRuleException::invalidConfiguration($snapshot->productId, $violation);
        }
    }

    /**
     * @param list<int> $ids
     *
     * @return list<int>
     */
    private static function ascending(array $ids): array
    {
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    private static function requirePositive(int $productId): void
    {
        if ($productId < 1) {
            throw ProductInvalidArgumentException::nonPositiveId('productId');
        }
    }
}
