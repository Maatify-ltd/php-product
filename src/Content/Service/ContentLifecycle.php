<?php

declare(strict_types=1);

namespace Maatify\Product\Content\Service;

use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\PhpPersistence\Pagination\DTO\PageResultDTO;
use Maatify\PhpPersistence\Transaction\Repository\SavepointTransactionRunnerInterface;
use Maatify\Product\Common\Enum\ProductDeletedStateEnum;
use Maatify\Product\Common\Exception\ProductBusinessRuleException;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;
use Maatify\Product\Common\Exception\ProductStorageFailureFactory;
use Maatify\Product\Content\DTO\ContentFieldsDTO;
use Maatify\Product\Content\DTO\ContentRecordDTO;
use Maatify\Product\Content\DTO\ContentStateDTO;
use Maatify\Product\Content\Enum\ContentOwnerEnum;
use Maatify\Product\Content\Repository\ContentCommandRepositoryInterface;
use Maatify\Product\Content\Repository\ContentQueryReaderInterface;
use Maatify\Product\Content\ValueObject\ContentLanguageFilter;
use Maatify\Product\Content\ValueObject\ExactContentLanguage;
use Maatify\Product\Product\DTO\ProductConfigurationSnapshotDTO;
use Maatify\Product\Product\Repository\ProductConfigurationRepositoryInterface;
use Maatify\Product\Product\Service\ProductConfigurationValidator;
use Maatify\SharedCommon\Contracts\ClockInterface;

/**
 * The Content commands and reads shared by the three owner kinds; each owner's Service delegates
 * here and maps the records to its own public DTOs.
 *
 * Every mutation locks the owner's Product configuration first (Product row, then its Options,
 * Option Values, and Variants by ascending identity, with Content labels read under shared locks),
 * then the Content row it changes, and validates only that locked state. The owner identity used to
 * find the Product is an immutable column read without a lock; it only selects the rows to lock.
 * Removing a label is validated on the configuration graph as it would be after the change, so the
 * last name of an active Product (`INV-06`) and the last label of an active Option or of an active
 * Value of an active Option (`INV-05`) cannot be removed.
 *
 * @internal Not part of the Public Runtime API.
 */
final readonly class ContentLifecycle
{
    public function __construct(
        private ContentOwnerEnum $owner,
        private ContentCommandRepositoryInterface $contents,
        private ContentQueryReaderInterface $reader,
        private ProductConfigurationRepositoryInterface $configuration,
        private SavepointTransactionRunnerInterface $transactions,
        private ClockInterface $clock,
    ) {}

    /**
     * @return positive-int
     */
    public function create(int $ownerId, ?string $languageCode, ContentFieldsDTO $fields): int
    {
        $occurredAt = $this->clock->now();

        return $this->transactions->run(function () use ($ownerId, $languageCode, $fields, $occurredAt): int {
            $productId = $this->contents->findOwnerProductId($ownerId);
            $snapshot = $productId === null ? null : $this->configuration->lockSnapshot($productId);
            if ($snapshot === null || !$this->ownerExists($snapshot, $ownerId)) {
                throw $this->owner->ownerNotFound($ownerId);
            }
            $deletedOwner = $this->deletedOwner($snapshot, $ownerId);
            if ($deletedOwner !== null) {
                throw ProductBusinessRuleException::ownerSoftDeletedForCreate($this->owner->resource(), $deletedOwner[0], $deletedOwner[1]);
            }

            return $this->contents->create($ownerId, $languageCode, $fields, $occurredAt);
        });
    }

    public function update(int $contentId, ContentFieldsDTO $fields): void
    {
        $occurredAt = $this->clock->now();

        $this->transactions->run(function () use ($contentId, $fields, $occurredAt): void {
            [, $content] = $this->lockedContent($contentId);
            if ($content->deleted) {
                throw ProductBusinessRuleException::softDeleted($this->owner->resource(), $contentId);
            }
            if ($content->fields->equals($fields)) {
                return;
            }
            if (!$this->contents->update($contentId, $fields, $occurredAt)) {
                throw ProductStorageFailureFactory::lockedRowVanished($this->owner->resource(), $contentId);
            }
        });
    }

    public function softDelete(int $contentId): void
    {
        $this->requirePositive($contentId);
        $occurredAt = $this->clock->now();

        $this->transactions->run(function () use ($contentId, $occurredAt): void {
            [$snapshot, $content] = $this->lockedContent($contentId);
            if ($content->deleted) {
                throw ProductBusinessRuleException::softDeleted($this->owner->resource(), $contentId);
            }
            $stillNamed = $this->contents->hasOtherNamedRecord($content->ownerId, $contentId);
            $this->assertValid($this->withOwnerNamed($snapshot, $content->ownerId, $stillNamed));
            if (!$this->contents->softDelete($contentId, $occurredAt)) {
                throw ProductStorageFailureFactory::lockedRowVanished($this->owner->resource(), $contentId);
            }
        });
    }

    public function restore(int $contentId): void
    {
        $this->requirePositive($contentId);
        $occurredAt = $this->clock->now();

        $this->transactions->run(function () use ($contentId, $occurredAt): void {
            [$snapshot, $content] = $this->lockedContent($contentId);
            if (!$content->deleted) {
                throw ProductBusinessRuleException::notSoftDeleted($this->owner->resource(), $contentId);
            }
            $this->assertValid($this->withOwnerNamed($snapshot, $content->ownerId, true));
            if (!$this->contents->restore($contentId, $occurredAt)) {
                throw ProductStorageFailureFactory::lockedRowVanished($this->owner->resource(), $contentId);
            }
        });
    }

    public function getByIdForManagement(int $contentId, ProductDeletedStateEnum $deletedState): ContentRecordDTO
    {
        $this->requirePositive($contentId);
        $record = $this->reader->findById($contentId);
        $matches = $record !== null && match ($deletedState) {
            ProductDeletedStateEnum::NON_DELETED => $record->deletedAt === null,
            ProductDeletedStateEnum::DELETED_ONLY => $record->deletedAt !== null,
            ProductDeletedStateEnum::INCLUDE_DELETED => true,
        };
        if ($record === null || !$matches) {
            throw $this->owner->contentNotFound($contentId);
        }

        return $record;
    }

    /**
     * @return list<ContentRecordDTO>
     */
    public function listForManagement(int $ownerId, ContentLanguageFilter $language, ProductDeletedStateEnum $deletedState, int $maxResults): array
    {
        return $this->reader->listForManagement($ownerId, $language, $deletedState, $maxResults);
    }

    /**
     * @template T of object
     *
     * @param callable(ContentRecordDTO): T $mapper
     *
     * @return PageResultDTO<T>
     */
    public function paginateForManagement(
        int $ownerId,
        ContentLanguageFilter $language,
        ProductDeletedStateEnum $deletedState,
        PageRequest $request,
        callable $mapper,
    ): PageResultDTO {
        return $this->reader->paginateForManagement($ownerId, $language, $deletedState, $request, $mapper);
    }

    /**
     * @return list<ContentRecordDTO>
     */
    public function listVisible(int $ownerId, ExactContentLanguage $language): array
    {
        if ($ownerId < 1) {
            throw ProductInvalidArgumentException::nonPositiveId($this->ownerField());
        }

        return $this->reader->listVisible($ownerId, $language);
    }

    /**
     * Locks the Product configuration and then the Content row, and rejects a record whose owner
     * chain is soft deleted.
     *
     * @return array{ProductConfigurationSnapshotDTO, ContentStateDTO}
     */
    private function lockedContent(int $contentId): array
    {
        $identity = $this->contents->findIdentity($contentId);
        $snapshot = $identity === null ? null : $this->configuration->lockSnapshot($identity->productId);
        $content = $snapshot === null ? null : $this->contents->lockForUpdate($contentId);
        if ($snapshot === null || $content === null || !$this->ownerExists($snapshot, $content->ownerId)) {
            throw $this->owner->contentNotFound($contentId);
        }
        $deletedOwner = $this->deletedOwner($snapshot, $content->ownerId);
        if ($deletedOwner !== null) {
            throw ProductBusinessRuleException::ownerSoftDeleted($this->owner->resource(), $contentId, $deletedOwner[0], $deletedOwner[1]);
        }

        return [$snapshot, $content];
    }

    private function ownerExists(ProductConfigurationSnapshotDTO $snapshot, int $ownerId): bool
    {
        return match ($this->owner) {
            ContentOwnerEnum::PRODUCT => $snapshot->productId === $ownerId,
            ContentOwnerEnum::OPTION => isset($snapshot->options[$ownerId]),
            ContentOwnerEnum::OPTION_VALUE => isset($snapshot->optionValues[$ownerId]),
        };
    }

    /**
     * The first soft-deleted member of the owner chain, from the Product down to the owner, as
     * `[resource, id]`, or null when the whole chain is live.
     *
     * @return array{string, int}|null
     */
    private function deletedOwner(ProductConfigurationSnapshotDTO $snapshot, int $ownerId): ?array
    {
        if ($snapshot->productDeleted) {
            return ['Product', $snapshot->productId];
        }
        if ($this->owner === ContentOwnerEnum::PRODUCT) {
            return null;
        }
        $optionId = $this->owner === ContentOwnerEnum::OPTION ? $ownerId : $snapshot->optionValues[$ownerId]->optionId;
        if ($snapshot->options[$optionId]->deleted) {
            return ['Option', $optionId];
        }
        if ($this->owner === ContentOwnerEnum::OPTION_VALUE && $snapshot->optionValues[$ownerId]->deleted) {
            return ['Option Value', $ownerId];
        }

        return null;
    }

    /**
     * The configuration graph with the owner's label state replaced, as after the Content change.
     */
    private function withOwnerNamed(ProductConfigurationSnapshotDTO $snapshot, int $ownerId, bool $named): ProductConfigurationSnapshotDTO
    {
        return match ($this->owner) {
            ContentOwnerEnum::PRODUCT => $snapshot->withHasName($named),
            ContentOwnerEnum::OPTION => $snapshot->withOption($snapshot->options[$ownerId]->withLabelled($named)),
            ContentOwnerEnum::OPTION_VALUE => $snapshot->withOptionValue($snapshot->optionValues[$ownerId]->withLabelled($named)),
        };
    }

    private function assertValid(ProductConfigurationSnapshotDTO $snapshot): void
    {
        $violation = ProductConfigurationValidator::firstViolation($snapshot);
        if ($violation !== null) {
            throw ProductBusinessRuleException::invalidConfiguration($snapshot->productId, $violation);
        }
    }

    private function requirePositive(int $contentId): void
    {
        if ($contentId < 1) {
            throw ProductInvalidArgumentException::nonPositiveId('contentId');
        }
    }

    private function ownerField(): string
    {
        return match ($this->owner) {
            ContentOwnerEnum::PRODUCT => 'productId',
            ContentOwnerEnum::OPTION => 'optionId',
            ContentOwnerEnum::OPTION_VALUE => 'optionValueId',
        };
    }
}
