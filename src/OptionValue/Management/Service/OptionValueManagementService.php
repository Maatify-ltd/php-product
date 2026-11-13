<?php

declare(strict_types=1);

namespace Maatify\Product\OptionValue\Management\Service;

use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\PhpPersistence\Pagination\DTO\PageResultDTO;
use Maatify\PhpPersistence\Transaction\Repository\SavepointTransactionRunnerInterface;
use Maatify\Product\Common\Enum\ProductDeletedStateEnum;
use Maatify\Product\Common\Exception\ProductBusinessRuleException;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;
use Maatify\Product\Common\Exception\ProductStorageFailureFactory;
use Maatify\Product\Option\Exception\OptionNotFoundException;
use Maatify\Product\Option\Repository\OptionCommandRepositoryInterface;
use Maatify\Product\OptionValue\DTO\CreatedOptionValueDTO;
use Maatify\Product\OptionValue\DTO\OptionValueCollectionDTO;
use Maatify\Product\OptionValue\DTO\OptionValueDTO;
use Maatify\Product\OptionValue\DTO\OptionValueStateDTO;
use Maatify\Product\OptionValue\Enum\OptionValueStatusEnum;
use Maatify\Product\OptionValue\Exception\OptionValueNotFoundException;
use Maatify\Product\OptionValue\Management\Command\CreateOptionValueCommand;
use Maatify\Product\OptionValue\Management\Command\UpdateOptionValueDisplayOrderCommand;
use Maatify\Product\OptionValue\Management\Criteria\OptionValueListCriteriaDTO;
use Maatify\Product\OptionValue\Repository\OptionValueCommandRepositoryInterface;
use Maatify\Product\OptionValue\Repository\OptionValueQueryReaderInterface;
use Maatify\Product\Product\DTO\ProductConfigurationSnapshotDTO;
use Maatify\Product\Product\Repository\ProductCommandRepositoryInterface;
use Maatify\Product\Product\Repository\ProductConfigurationRepositoryInterface;
use Maatify\Product\Product\Service\ProductConfigurationValidator;
use Maatify\SharedCommon\Contracts\ClockInterface;

/**
 * Orchestrates Option Value creation, status, lifecycle, ordering, and management reads; every
 * mutation runs under the Product lock.
 *
 * Every status change, soft delete, and restore locks the Product's whole configuration graph first and
 * validates the graph as it would be after the change before writing.
 *
 * @internal Not part of the Public Runtime API.
 */
final readonly class OptionValueManagementService implements OptionValueManagementServiceInterface
{
    public function __construct(
        private OptionValueCommandRepositoryInterface $values,
        private OptionValueQueryReaderInterface $reader,
        private OptionCommandRepositoryInterface $options,
        private ProductCommandRepositoryInterface $products,
        private ProductConfigurationRepositoryInterface $configuration,
        private SavepointTransactionRunnerInterface $transactions,
        private ClockInterface $clock,
    ) {}

    public function create(CreateOptionValueCommand $command): CreatedOptionValueDTO
    {
        $occurredAt = $this->clock->now();

        return $this->transactions->run(function () use ($command, $occurredAt): CreatedOptionValueDTO {
            $productId = $this->options->findProductId($command->optionId);
            if ($productId === null) {
                throw OptionNotFoundException::withId($command->optionId);
            }
            $product = $this->products->lockForUpdate($productId);
            $option = $this->options->lockForUpdate($command->optionId);
            if ($product === null || $option === null) {
                throw OptionNotFoundException::withId($command->optionId);
            }
            if ($product->deleted) {
                throw ProductBusinessRuleException::ownerSoftDeletedForCreate('Option Value', 'Product', $productId);
            }
            if ($option->deleted) {
                throw ProductBusinessRuleException::ownerSoftDeletedForCreate('Option Value', 'Option', $command->optionId);
            }

            return new CreatedOptionValueDTO($this->values->create($command, $occurredAt));
        });
    }

    public function updateStatus(int $optionValueId, OptionValueStatusEnum $status): void
    {
        self::requirePositive($optionValueId);
        $occurredAt = $this->clock->now();

        $this->transactions->run(function () use ($optionValueId, $status, $occurredAt): void {
            [$snapshot, $value] = $this->lockedValue($optionValueId);
            $this->assertOwnersLive($snapshot, $value);
            if ($value->deleted) {
                throw ProductBusinessRuleException::softDeleted('Option Value', $optionValueId);
            }
            $activate = $status === OptionValueStatusEnum::ACTIVE;
            if ($value->active === $activate) {
                return;
            }
            self::assertValid($snapshot->withOptionValue($value->withActive($activate)));
            if (!$this->values->updateStatus($optionValueId, $status, $occurredAt)) {
                throw ProductStorageFailureFactory::lockedRowVanished('Option Value', $optionValueId);
            }
        });
    }

    public function softDelete(int $optionValueId): void
    {
        self::requirePositive($optionValueId);
        $occurredAt = $this->clock->now();

        $this->transactions->run(function () use ($optionValueId, $occurredAt): void {
            [$snapshot, $value] = $this->lockedValue($optionValueId);
            $this->assertOwnersLive($snapshot, $value);
            if ($value->deleted) {
                throw ProductBusinessRuleException::softDeleted('Option Value', $optionValueId);
            }
            self::assertValid($snapshot->withOptionValue($value->withDeleted(true)));
            if (!$this->values->softDelete($optionValueId, $occurredAt)) {
                throw ProductStorageFailureFactory::lockedRowVanished('Option Value', $optionValueId);
            }
        });
    }

    public function updateDisplayOrder(UpdateOptionValueDisplayOrderCommand $command): void
    {
        $optionValueId = $command->optionValueId;
        $occurredAt = $this->clock->now();

        $this->transactions->run(function () use ($command, $optionValueId, $occurredAt): void {
            [$snapshot, $value] = $this->lockedValue($optionValueId);
            $this->assertOwnersLive($snapshot, $value);
            if ($value->deleted) {
                throw ProductBusinessRuleException::softDeleted('Option Value', $optionValueId);
            }
            if (!$this->values->updateDisplayOrder($optionValueId, $value->optionId, $command->displayOrder, $occurredAt)) {
                throw ProductStorageFailureFactory::lockedRowVanished('Option Value', $optionValueId);
            }
        });
    }

    public function restore(int $optionValueId): void
    {
        self::requirePositive($optionValueId);
        $occurredAt = $this->clock->now();

        $this->transactions->run(function () use ($optionValueId, $occurredAt): void {
            [$snapshot, $value] = $this->lockedValue($optionValueId);
            $this->assertOwnersLive($snapshot, $value);
            if (!$value->deleted) {
                throw ProductBusinessRuleException::notSoftDeleted('Option Value', $optionValueId);
            }
            self::assertValid($snapshot->withOptionValue($value->withDeleted(false)));
            if (!$this->values->restore($optionValueId, $value->optionId, $occurredAt)) {
                throw ProductStorageFailureFactory::lockedRowVanished('Option Value', $optionValueId);
            }
        });
    }

    public function getByIdForManagement(
        int $optionValueId,
        ProductDeletedStateEnum $deletedState = ProductDeletedStateEnum::NON_DELETED,
    ): OptionValueDTO {
        self::requirePositive($optionValueId);
        $value = $this->reader->findById($optionValueId);
        $matches = $value !== null && match ($deletedState) {
            ProductDeletedStateEnum::NON_DELETED => $value->deletedAt === null,
            ProductDeletedStateEnum::DELETED_ONLY => $value->deletedAt !== null,
            ProductDeletedStateEnum::INCLUDE_DELETED => true,
        };
        if ($value === null || !$matches) {
            throw OptionValueNotFoundException::withId($optionValueId);
        }

        return $value;
    }

    public function listByOptionIdForManagement(int $optionId, OptionValueListCriteriaDTO $criteria = new OptionValueListCriteriaDTO()): OptionValueCollectionDTO
    {
        if ($optionId < 1) {
            throw ProductInvalidArgumentException::nonPositiveId('optionId');
        }

        return new OptionValueCollectionDTO($this->reader->listForManagement($optionId, $criteria));
    }

    public function paginateByOptionIdForManagement(int $optionId, OptionValueListCriteriaDTO $criteria, PageRequest $request): PageResultDTO
    {
        if ($optionId < 1) {
            throw ProductInvalidArgumentException::nonPositiveId('optionId');
        }

        return $this->reader->paginateForManagement($optionId, $criteria, $request);
    }

    /**
     * @return array{ProductConfigurationSnapshotDTO, OptionValueStateDTO}
     */
    private function lockedValue(int $optionValueId): array
    {
        $productId = $this->values->findProductId($optionValueId);
        $snapshot = $productId === null ? null : $this->configuration->lockSnapshot($productId);
        $value = $snapshot?->optionValues[$optionValueId] ?? null;
        if ($snapshot === null || $value === null) {
            throw OptionValueNotFoundException::withId($optionValueId);
        }

        return [$snapshot, $value];
    }

    private function assertOwnersLive(ProductConfigurationSnapshotDTO $snapshot, OptionValueStateDTO $value): void
    {
        if ($snapshot->productDeleted) {
            throw ProductBusinessRuleException::ownerSoftDeleted('Option Value', $value->id, 'Product', $snapshot->productId);
        }
        $option = $snapshot->options[$value->optionId] ?? null;
        if ($option === null || $option->deleted) {
            throw ProductBusinessRuleException::ownerSoftDeleted('Option Value', $value->id, 'Option', $value->optionId);
        }
    }

    private static function assertValid(ProductConfigurationSnapshotDTO $snapshot): void
    {
        $violation = ProductConfigurationValidator::firstViolation($snapshot);
        if ($violation !== null) {
            throw ProductBusinessRuleException::invalidConfiguration($snapshot->productId, $violation);
        }
    }

    private static function requirePositive(int $optionValueId): void
    {
        if ($optionValueId < 1) {
            throw ProductInvalidArgumentException::nonPositiveId('optionValueId');
        }
    }
}
