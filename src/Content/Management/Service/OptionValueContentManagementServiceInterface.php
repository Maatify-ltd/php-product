<?php

declare(strict_types=1);

namespace Maatify\Product\Content\Management\Service;

use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\PhpPersistence\Pagination\DTO\PageResultDTO;
use Maatify\Product\Common\Enum\ProductDeletedStateEnum;
use Maatify\Product\Common\Exception\ProductBusinessRuleException;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;
use Maatify\Product\Common\Exception\ProductPersistenceException;
use Maatify\Product\Content\DTO\CreatedOptionValueContentDTO;
use Maatify\Product\Content\DTO\OptionValueContentCollectionDTO;
use Maatify\Product\Content\DTO\OptionValueContentDTO;
use Maatify\Product\Content\Exception\OptionValueContentAlreadyExistsException;
use Maatify\Product\Content\Exception\OptionValueContentNotFoundException;
use Maatify\Product\Content\Management\Command\CreateOptionValueContentCommand;
use Maatify\Product\Content\Management\Command\UpdateOptionValueContentCommand;
use Maatify\Product\Content\Management\Criteria\OptionValueContentListCriteriaDTO;
use Maatify\Product\OptionValue\Exception\OptionValueNotFoundException;

/**
 * Management contract for Option Value Content: the records of one `(Option Value, language)`
 * identity each, where a `null` language is the single unlocalized record.
 *
 * Every mutation runs in the savepoint transaction runner and locks the owning Product's
 * configuration before the Content row. A soft-deleted record accepts nothing but `restore()`,
 * and no record of a soft-deleted owner chain (the Option Value, its Option, or its Product) can be created or changed. Reads never
 * fall back from one language to another or to the unlocalized record.
 */
interface OptionValueContentManagementServiceInterface
{
    /**
     * Adds the record of a `(Option Value, language)` identity. An inactive owner does not block it.
     *
     * @throws OptionValueNotFoundException When no Option Value has that identity.
     * @throws ProductBusinessRuleException When the Option Value, its Option, or its Product is soft deleted.
     * @throws OptionValueContentAlreadyExistsException When the identity is taken under the collation, including by
     *                                             a soft-deleted record; restore that record instead.
     * @throws ProductPersistenceException When stored state changed or is invalid.
     */
    public function create(CreateOptionValueContentCommand $command): CreatedOptionValueContentDTO;

    /**
     * Replaces every content field of a non-deleted record. The owner and language never change. A
     * request equal to the stored fields writes nothing.
     *
     * @throws OptionValueContentNotFoundException When no record has that identity.
     * @throws ProductBusinessRuleException When the record or the Option Value, its Option, or its Product is soft deleted.
     * @throws ProductPersistenceException When stored state changed or is invalid.
     */
    public function update(UpdateOptionValueContentCommand $command): void;

    /**
     * Soft-deletes a non-deleted record. Rejected when it would remove the last non-deleted label of an active Value of an active Option (INV-05).
     *
     * @throws ProductInvalidArgumentException When the identity is not a positive integer.
     * @throws OptionValueContentNotFoundException When no record has that identity.
     * @throws ProductBusinessRuleException When the record or the Option Value, its Option, or its Product is soft deleted, or the deletion would violate an invariant.
     * @throws ProductPersistenceException When stored state changed or is invalid.
     */
    public function softDelete(int $contentId): void;

    /**
     * Restores a soft-deleted record with its original identity, after revalidating the configuration invariants.
     *
     * @throws ProductInvalidArgumentException When the identity is not a positive integer.
     * @throws OptionValueContentNotFoundException When no record has that identity.
     * @throws ProductBusinessRuleException When the record is not soft deleted, the Option Value, its Option, or its Product is soft deleted, or the
     *                                      restored state would violate an invariant.
     * @throws ProductPersistenceException When stored state changed or is invalid.
     */
    public function restore(int $contentId): void;

    /**
     * Returns a record for management whatever its owner's state, filtered only by soft-deletion state.
     *
     * @throws ProductInvalidArgumentException When the identity is not a positive integer.
     * @throws OptionValueContentNotFoundException When no record with that identity matches the deleted state.
     */
    public function getByIdForManagement(
        int $contentId,
        ProductDeletedStateEnum $deletedState = ProductDeletedStateEnum::NON_DELETED,
    ): OptionValueContentDTO;

    /**
     * Lists the owner's records for management under the criteria's language selector and deleted state,
     * ordered by `language_code` (the unlocalized record first) then `id`, at most `maxResults`. A missing
     * owner yields an empty collection.
     */
    public function listForManagement(OptionValueContentListCriteriaDTO $criteria): OptionValueContentCollectionDTO;

    /**
     * One page of the owner's records for management under the criteria's language selector and deleted
     * state, through the shared `maatify/php-persistence` paginator: 20 per page by default, 1 to 100, sortable
     * by `language_code` (default, ascending), `id`, `created_at`, or `updated_at`, ties broken by `id`
     * ascending. The criteria's `maxResults` does not apply.
     *
     * @return PageResultDTO<OptionValueContentDTO>
     */
    public function paginateForManagement(OptionValueContentListCriteriaDTO $criteria, PageRequest $request): PageResultDTO;
}
