<?php

declare(strict_types=1);

namespace Maatify\Product\Content\Management\Service;

use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\PhpPersistence\Pagination\DTO\PageResultDTO;
use Maatify\Product\Common\Enum\ProductDeletedStateEnum;
use Maatify\Product\Common\Exception\ProductBusinessRuleException;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;
use Maatify\Product\Common\Exception\ProductPersistenceException;
use Maatify\Product\Content\DTO\CreatedProductContentDTO;
use Maatify\Product\Content\DTO\ProductContentCollectionDTO;
use Maatify\Product\Content\DTO\ProductContentDTO;
use Maatify\Product\Content\Exception\ProductContentAlreadyExistsException;
use Maatify\Product\Content\Exception\ProductContentNotFoundException;
use Maatify\Product\Content\Management\Command\CreateProductContentCommand;
use Maatify\Product\Content\Management\Command\UpdateProductContentCommand;
use Maatify\Product\Content\Management\Criteria\ProductContentListCriteriaDTO;
use Maatify\Product\Product\Exception\ProductNotFoundException;

/**
 * Management contract for Product Content: the records of one `(Product, language)`
 * identity each, where a `null` language is the single unlocalized record.
 *
 * Every mutation runs in the savepoint transaction runner and locks the owning Product's
 * configuration before the Content row. A soft-deleted record accepts nothing but `restore()`,
 * and no record of a soft-deleted owner chain (the Product) can be created or changed. Reads never
 * fall back from one language to another or to the unlocalized record.
 */
interface ProductContentManagementServiceInterface
{
    /**
     * Adds the record of a `(Product, language)` identity. An inactive owner does not block it.
     *
     * @throws ProductNotFoundException When no Product has that identity.
     * @throws ProductBusinessRuleException When the Product is soft deleted.
     * @throws ProductContentAlreadyExistsException When the identity is taken under the collation, including by
     *                                             a soft-deleted record; restore that record instead.
     * @throws ProductPersistenceException When stored state changed or is invalid.
     */
    public function create(CreateProductContentCommand $command): CreatedProductContentDTO;

    /**
     * Replaces every content field of a non-deleted record. The owner and language never change. A
     * request equal to the stored fields writes nothing.
     *
     * @throws ProductContentNotFoundException When no record has that identity.
     * @throws ProductBusinessRuleException When the record or the Product is soft deleted.
     * @throws ProductPersistenceException When stored state changed or is invalid.
     */
    public function update(UpdateProductContentCommand $command): void;

    /**
     * Soft-deletes a non-deleted record. Rejected when it would remove the last non-deleted name of an active Product (INV-06).
     *
     * @throws ProductInvalidArgumentException When the identity is not a positive integer.
     * @throws ProductContentNotFoundException When no record has that identity.
     * @throws ProductBusinessRuleException When the record or the Product is soft deleted, or the deletion would violate an invariant.
     * @throws ProductPersistenceException When stored state changed or is invalid.
     */
    public function softDelete(int $contentId): void;

    /**
     * Restores a soft-deleted record with its original identity, after revalidating the configuration invariants.
     *
     * @throws ProductInvalidArgumentException When the identity is not a positive integer.
     * @throws ProductContentNotFoundException When no record has that identity.
     * @throws ProductBusinessRuleException When the record is not soft deleted, the Product is soft deleted, or the
     *                                      restored state would violate an invariant.
     * @throws ProductPersistenceException When stored state changed or is invalid.
     */
    public function restore(int $contentId): void;

    /**
     * Returns a record for management whatever its owner's state, filtered only by soft-deletion state.
     *
     * @throws ProductInvalidArgumentException When the identity is not a positive integer.
     * @throws ProductContentNotFoundException When no record with that identity matches the deleted state.
     */
    public function getByIdForManagement(
        int $contentId,
        ProductDeletedStateEnum $deletedState = ProductDeletedStateEnum::NON_DELETED,
    ): ProductContentDTO;

    /**
     * Lists the owner's records for management under the criteria's language selector and deleted state,
     * ordered by `language_code` (the unlocalized record first) then `id`, at most `maxResults`. A missing
     * owner yields an empty collection.
     */
    public function listForManagement(ProductContentListCriteriaDTO $criteria): ProductContentCollectionDTO;

    /**
     * One page of the owner's records for management under the criteria's language selector and deleted
     * state, through the shared `maatify/php-persistence` paginator: 20 per page by default, 1 to 100, sortable
     * by `language_code` (default, ascending), `id`, `created_at`, or `updated_at`, ties broken by `id`
     * ascending. The criteria's `maxResults` does not apply.
     *
     * @return PageResultDTO<ProductContentDTO>
     */
    public function paginateForManagement(ProductContentListCriteriaDTO $criteria, PageRequest $request): PageResultDTO;
}
