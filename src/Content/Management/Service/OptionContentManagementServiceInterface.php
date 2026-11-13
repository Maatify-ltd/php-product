<?php

declare(strict_types=1);

namespace Maatify\Product\Content\Management\Service;

use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\PhpPersistence\Pagination\DTO\PageResultDTO;
use Maatify\Product\Common\Enum\ProductDeletedStateEnum;
use Maatify\Product\Common\Exception\ProductBusinessRuleException;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;
use Maatify\Product\Common\Exception\ProductPersistenceException;
use Maatify\Product\Content\DTO\CreatedOptionContentDTO;
use Maatify\Product\Content\DTO\OptionContentCollectionDTO;
use Maatify\Product\Content\DTO\OptionContentDTO;
use Maatify\Product\Content\Exception\OptionContentAlreadyExistsException;
use Maatify\Product\Content\Exception\OptionContentNotFoundException;
use Maatify\Product\Content\Management\Command\CreateOptionContentCommand;
use Maatify\Product\Content\Management\Command\UpdateOptionContentCommand;
use Maatify\Product\Content\Management\Criteria\OptionContentListCriteriaDTO;
use Maatify\Product\Option\Exception\OptionNotFoundException;

/**
 * Management contract for Option Content: the records of one `(Option, language)`
 * identity each, where a `null` language is the single unlocalized record.
 *
 * Every mutation runs in the savepoint transaction runner and locks the owning Product's
 * configuration before the Content row. A soft-deleted record accepts nothing but `restore()`,
 * and no record of a soft-deleted owner chain (the Option or its Product) can be created or changed. Reads never
 * fall back from one language to another or to the unlocalized record.
 */
interface OptionContentManagementServiceInterface
{
    /**
     * Adds the record of a `(Option, language)` identity. An inactive owner does not block it.
     *
     * @throws OptionNotFoundException When no Option has that identity.
     * @throws ProductBusinessRuleException When the Option or its Product is soft deleted.
     * @throws OptionContentAlreadyExistsException When the identity is taken under the collation, including by
     *                                             a soft-deleted record; restore that record instead.
     * @throws ProductPersistenceException When stored state changed or is invalid.
     */
    public function create(CreateOptionContentCommand $command): CreatedOptionContentDTO;

    /**
     * Replaces every content field of a non-deleted record. The owner and language never change. A
     * request equal to the stored fields writes nothing.
     *
     * @throws OptionContentNotFoundException When no record has that identity.
     * @throws ProductBusinessRuleException When the record or the Option or its Product is soft deleted.
     * @throws ProductPersistenceException When stored state changed or is invalid.
     */
    public function update(UpdateOptionContentCommand $command): void;

    /**
     * Soft-deletes a non-deleted record. Rejected when it would remove the last non-deleted label of an active Option (INV-05).
     *
     * @throws ProductInvalidArgumentException When the identity is not a positive integer.
     * @throws OptionContentNotFoundException When no record has that identity.
     * @throws ProductBusinessRuleException When the record or the Option or its Product is soft deleted, or the deletion would violate an invariant.
     * @throws ProductPersistenceException When stored state changed or is invalid.
     */
    public function softDelete(int $contentId): void;

    /**
     * Restores a soft-deleted record with its original identity, after revalidating the configuration invariants.
     *
     * @throws ProductInvalidArgumentException When the identity is not a positive integer.
     * @throws OptionContentNotFoundException When no record has that identity.
     * @throws ProductBusinessRuleException When the record is not soft deleted, the Option or its Product is soft deleted, or the
     *                                      restored state would violate an invariant.
     * @throws ProductPersistenceException When stored state changed or is invalid.
     */
    public function restore(int $contentId): void;

    /**
     * Returns a record for management whatever its owner's state, filtered only by soft-deletion state.
     *
     * @throws ProductInvalidArgumentException When the identity is not a positive integer.
     * @throws OptionContentNotFoundException When no record with that identity matches the deleted state.
     */
    public function getByIdForManagement(
        int $contentId,
        ProductDeletedStateEnum $deletedState = ProductDeletedStateEnum::NON_DELETED,
    ): OptionContentDTO;

    /**
     * Lists the owner's records for management under the criteria's language selector and deleted state,
     * ordered by `language_code` (the unlocalized record first) then `id`, at most `maxResults`. A missing
     * owner yields an empty collection.
     */
    public function listForManagement(OptionContentListCriteriaDTO $criteria): OptionContentCollectionDTO;

    /**
     * One page of the owner's records for management under the criteria's language selector and deleted
     * state, through the shared `maatify/php-persistence` paginator: 20 per page by default, 1 to 100, sortable
     * by `language_code` (default, ascending), `id`, `created_at`, or `updated_at`, ties broken by `id`
     * ascending. The criteria's `maxResults` does not apply.
     *
     * @return PageResultDTO<OptionContentDTO>
     */
    public function paginateForManagement(OptionContentListCriteriaDTO $criteria, PageRequest $request): PageResultDTO;
}
