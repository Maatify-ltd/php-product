<?php

declare(strict_types=1);

namespace Maatify\Product\Content\Repository;

use DateTimeImmutable;
use Maatify\Product\Content\DTO\ContentFieldsDTO;
use Maatify\Product\Content\DTO\ContentIdentityDTO;
use Maatify\Product\Content\DTO\ContentStateDTO;
use Maatify\Product\Content\Exception\OptionContentAlreadyExistsException;
use Maatify\Product\Content\Exception\OptionValueContentAlreadyExistsException;
use Maatify\Product\Content\Exception\ProductContentAlreadyExistsException;

/**
 * Write port for the Content records of one owner kind. The calling Service owns the transaction
 * and holds the Product configuration locks before calling a locking or writing method.
 *
 * @internal Not part of the Public Runtime API.
 */
interface ContentCommandRepositoryInterface
{
    /**
     * The Product that owns the given owner (the owner itself for Product Content), whatever its
     * state, read without a lock; null when no owner has that identity.
     */
    public function findOwnerProductId(int $ownerId): ?int;

    /**
     * The immutable owner identity of a Content record and its Product, whatever its state, read
     * without a lock; null when no record has that identity.
     */
    public function findIdentity(int $contentId): ?ContentIdentityDTO;

    /**
     * Inserts a non-deleted Content record and returns its identity.
     *
     * @return positive-int
     *
     * @throws ProductContentAlreadyExistsException|OptionContentAlreadyExistsException|OptionValueContentAlreadyExistsException
     *         When the `(owner, language)` identity is already taken, including by a soft-deleted record.
     */
    public function create(int $ownerId, ?string $languageCode, ContentFieldsDTO $fields, DateTimeImmutable $occurredAt): int;

    /**
     * Locks the Content record and returns its state, or null when none has that identity.
     */
    public function lockForUpdate(int $contentId): ?ContentStateDTO;

    /**
     * Whether the owner keeps a non-deleted record with a non-empty name besides the given one, read
     * with a shared lock.
     */
    public function hasOtherNamedRecord(int $ownerId, int $excludedContentId): bool;

    /**
     * Replaces the content fields of a non-deleted record. Returns whether a row was changed.
     */
    public function update(int $contentId, ContentFieldsDTO $fields, DateTimeImmutable $occurredAt): bool;

    public function softDelete(int $contentId, DateTimeImmutable $occurredAt): bool;

    public function restore(int $contentId, DateTimeImmutable $occurredAt): bool;
}
