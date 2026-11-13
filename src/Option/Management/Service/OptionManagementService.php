<?php

declare(strict_types=1);

namespace Maatify\Product\Option\Management\Service;

use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\PhpPersistence\Pagination\DTO\PageResultDTO;
use Maatify\PhpPersistence\Transaction\Repository\SavepointTransactionRunnerInterface;
use Maatify\Product\Common\Enum\ProductDeletedStateEnum;
use Maatify\Product\Common\Exception\ProductBusinessRuleException;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;
use Maatify\Product\Common\Exception\ProductStorageFailureFactory;
use Maatify\Product\Option\DTO\CreatedOptionDTO;
use Maatify\Product\Option\DTO\OptionCollectionDTO;
use Maatify\Product\Option\DTO\OptionDTO;
use Maatify\Product\Option\DTO\OptionStateDTO;
use Maatify\Product\Option\Enum\OptionStatusEnum;
use Maatify\Product\Option\Exception\OptionNotFoundException;
use Maatify\Product\Option\Management\Command\CreateOptionCommand;
use Maatify\Product\Option\Management\Command\UpdateOptionDisplayOrderCommand;
use Maatify\Product\Option\Management\Criteria\OptionListCriteriaDTO;
use Maatify\Product\Option\Repository\OptionCommandRepositoryInterface;
use Maatify\Product\Option\Repository\OptionQueryReaderInterface;
use Maatify\Product\Product\DTO\ProductConfigurationSnapshotDTO;
use Maatify\Product\Product\Exception\ProductNotFoundException;
use Maatify\Product\Product\Repository\ProductCommandRepositoryInterface;
use Maatify\Product\Product\Repository\ProductConfigurationRepositoryInterface;
use Maatify\Product\Product\Service\ProductConfigurationValidator;
use Maatify\SharedCommon\Contracts\ClockInterface;

/**
 * Orchestrates Option creation, status, lifecycle, ordering, and management reads; every
 * mutation runs under the Product lock.
 *
 * Every status change, soft delete, and restore locks the Product's whole configuration graph first and
 * validates the graph as it would be after the change before writing.
 *
 * @internal Not part of the Public Runtime API.
 */
final readonly class OptionManagementService implements OptionManagementServiceInterface
{
    public function __construct(
        private OptionCommandRepositoryInterface $options,
        private OptionQueryReaderInterface $reader,
        private ProductCommandRepositoryInterface $products,
        private ProductConfigurationRepositoryInterface $configuration,
        private SavepointTransactionRunnerInterface $transactions,
        private ClockInterface $clock,
    ) {}

    public function create(CreateOptionCommand $command): CreatedOptionDTO
    {
        $occurredAt = $this->clock->now();

        return $this->transactions->run(function () use ($command, $occurredAt): CreatedOptionDTO {
            $product = $this->products->lockForUpdate($command->productId);
            if ($product === null) {
                throw ProductNotFoundException::withId($command->productId);
            }
            if ($product->deleted) {
                throw ProductBusinessRuleException::ownerSoftDeletedForCreate('Option', 'Product', $command->productId);
            }

            return new CreatedOptionDTO($this->options->create($command, $occurredAt));
        });
    }

    public function updateStatus(int $optionId, OptionStatusEnum $status): void
    {
        self::requirePositive($optionId);
        $occurredAt = $this->clock->now();

        $this->transactions->run(function () use ($optionId, $status, $occurredAt): void {
            [$snapshot, $option] = $this->lockedOption($optionId);
            if ($snapshot->productDeleted) {
                throw ProductBusinessRuleException::ownerSoftDeleted('Option', $optionId, 'Product', $snapshot->productId);
            }
            if ($option->deleted) {
                throw ProductBusinessRuleException::softDeleted('Option', $optionId);
            }
            $activate = $status === OptionStatusEnum::ACTIVE;
            if ($option->active === $activate) {
                return;
            }
            self::assertValid($snapshot->withOption($option->withActive($activate)));
            if (!$this->options->updateStatus($optionId, $status, $occurredAt)) {
                throw ProductStorageFailureFactory::lockedRowVanished('Option', $optionId);
            }
        });
    }

    public function softDelete(int $optionId): void
    {
        self::requirePositive($optionId);
        $occurredAt = $this->clock->now();

        $this->transactions->run(function () use ($optionId, $occurredAt): void {
            [$snapshot, $option] = $this->lockedOption($optionId);
            if ($snapshot->productDeleted) {
                throw ProductBusinessRuleException::ownerSoftDeleted('Option', $optionId, 'Product', $snapshot->productId);
            }
            if ($option->deleted) {
                throw ProductBusinessRuleException::softDeleted('Option', $optionId);
            }
            self::assertValid($snapshot->withOption($option->withDeleted(true)));
            if (!$this->options->softDelete($optionId, $occurredAt)) {
                throw ProductStorageFailureFactory::lockedRowVanished('Option', $optionId);
            }
        });
    }

    public function updateDisplayOrder(UpdateOptionDisplayOrderCommand $command): void
    {
        $optionId = $command->optionId;
        $occurredAt = $this->clock->now();

        $this->transactions->run(function () use ($command, $optionId, $occurredAt): void {
            [$snapshot, $option] = $this->lockedOption($optionId);
            if ($snapshot->productDeleted) {
                throw ProductBusinessRuleException::ownerSoftDeleted('Option', $optionId, 'Product', $snapshot->productId);
            }
            if ($option->deleted) {
                throw ProductBusinessRuleException::softDeleted('Option', $optionId);
            }
            if (!$this->options->updateDisplayOrder($optionId, $snapshot->productId, $command->displayOrder, $occurredAt)) {
                throw ProductStorageFailureFactory::lockedRowVanished('Option', $optionId);
            }
        });
    }

    public function restore(int $optionId): void
    {
        self::requirePositive($optionId);
        $occurredAt = $this->clock->now();

        $this->transactions->run(function () use ($optionId, $occurredAt): void {
            [$snapshot, $option] = $this->lockedOption($optionId);
            if ($snapshot->productDeleted) {
                throw ProductBusinessRuleException::ownerSoftDeleted('Option', $optionId, 'Product', $snapshot->productId);
            }
            if (!$option->deleted) {
                throw ProductBusinessRuleException::notSoftDeleted('Option', $optionId);
            }
            self::assertValid($snapshot->withOption($option->withDeleted(false)));
            if (!$this->options->restore($optionId, $snapshot->productId, $occurredAt)) {
                throw ProductStorageFailureFactory::lockedRowVanished('Option', $optionId);
            }
        });
    }

    public function getByIdForManagement(
        int $optionId,
        ProductDeletedStateEnum $deletedState = ProductDeletedStateEnum::NON_DELETED,
    ): OptionDTO {
        self::requirePositive($optionId);
        $option = $this->reader->findById($optionId);
        $matches = $option !== null && match ($deletedState) {
            ProductDeletedStateEnum::NON_DELETED => $option->deletedAt === null,
            ProductDeletedStateEnum::DELETED_ONLY => $option->deletedAt !== null,
            ProductDeletedStateEnum::INCLUDE_DELETED => true,
        };
        if ($option === null || !$matches) {
            throw OptionNotFoundException::withId($optionId);
        }

        return $option;
    }

    public function listByProductIdForManagement(int $productId, OptionListCriteriaDTO $criteria = new OptionListCriteriaDTO()): OptionCollectionDTO
    {
        if ($productId < 1) {
            throw ProductInvalidArgumentException::nonPositiveId('productId');
        }

        return new OptionCollectionDTO($this->reader->listForManagement($productId, $criteria));
    }

    public function paginateByProductIdForManagement(int $productId, OptionListCriteriaDTO $criteria, PageRequest $request): PageResultDTO
    {
        if ($productId < 1) {
            throw ProductInvalidArgumentException::nonPositiveId('productId');
        }

        return $this->reader->paginateForManagement($productId, $criteria, $request);
    }

    /**
     * @return array{ProductConfigurationSnapshotDTO, OptionStateDTO}
     */
    private function lockedOption(int $optionId): array
    {
        $productId = $this->options->findProductId($optionId);
        $snapshot = $productId === null ? null : $this->configuration->lockSnapshot($productId);
        $option = $snapshot?->options[$optionId] ?? null;
        if ($snapshot === null || $option === null) {
            throw OptionNotFoundException::withId($optionId);
        }

        return [$snapshot, $option];
    }

    private static function assertValid(ProductConfigurationSnapshotDTO $snapshot): void
    {
        $violation = ProductConfigurationValidator::firstViolation($snapshot);
        if ($violation !== null) {
            throw ProductBusinessRuleException::invalidConfiguration($snapshot->productId, $violation);
        }
    }

    private static function requirePositive(int $optionId): void
    {
        if ($optionId < 1) {
            throw ProductInvalidArgumentException::nonPositiveId('optionId');
        }
    }
}
