<?php

declare(strict_types=1);

namespace Maatify\Product\Product\Management\Service;

use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\PhpPersistence\Pagination\DTO\PageResultDTO;
use Maatify\Product\Common\Enum\ProductDeletedStateEnum;
use Maatify\Product\Common\Exception\ProductBusinessRuleException;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;
use Maatify\Product\Common\Exception\ProductPersistenceException;
use Maatify\Product\Product\DTO\CreatedProductDTO;
use Maatify\Product\Product\DTO\ProductCollectionDTO;
use Maatify\Product\Product\DTO\ProductDTO;
use Maatify\Product\Product\DTO\ProductReconfigurationResultDTO;
use Maatify\Product\Product\Enum\ProductStatusEnum;
use Maatify\Product\Product\Exception\ProductCodeAlreadyExistsException;
use Maatify\Product\Product\Exception\ProductNotFoundException;
use Maatify\Product\Product\Management\Command\CreateProductCommand;
use Maatify\Product\Product\Management\Command\ReconfigureProductCommand;
use Maatify\Product\Product\Management\Criteria\ProductListCriteriaDTO;
use Maatify\Product\Variant\Exception\VariantCompositionConflictException;
use Maatify\Product\Variant\Exception\VariantNotFoundException;

/**
 * Management contract for Products: creation, management reads (lookup by identity or code, the
 * list with its code search, and its paginated twin), status and lifecycle, and reconfiguration.
 *
 * Every mutation runs through the savepoint transaction runner: it owns and commits a
 * transaction when none is active, and inside a Host transaction on the same connection it
 * runs in an operation-local savepoint that is rolled back on failure, so a failed call leaves
 * none of its writes behind and never commits or rolls back the Host transaction. Every
 * mutation that can affect the Product graph takes the Product row lock before reading state.
 */
interface ProductManagementServiceInterface
{
    /**
     * Creates an inactive Product together with its single active empty-composition Variant.
     *
     * Both rows are written atomically: either both exist or neither does, whoever
     * owns the transaction. The operation is not idempotent; repeating it with the same code conflicts.
     * It performs no Pricing, Inventory, or other cross-domain work.
     *
     * @throws ProductCodeAlreadyExistsException When the code is already reserved, including by a soft-deleted Product.
     * @throws ProductPersistenceException When stored state changed or is invalid.
     */
    public function create(CreateProductCommand $command): CreatedProductDTO;

    /**
     * Returns a Product for management, whatever its status, filtered only by soft-deletion state.
     *
     * @throws ProductInvalidArgumentException When the identity is not a positive integer.
     * @throws ProductNotFoundException When no Product with that identity matches the deleted state.
     */
    public function getByIdForManagement(
        int $productId,
        ProductDeletedStateEnum $deletedState = ProductDeletedStateEnum::NON_DELETED,
    ): ProductDTO;

    /**
     * Changes the status of a non-deleted Product. A request for the current status changes nothing.
     *
     * Activation requires a structurally valid configuration and a non-empty name in at least one
     * non-deleted Product Content record. Deactivation changes no child row.
     *
     * @throws ProductInvalidArgumentException When the identity is not a positive integer.
     * @throws ProductNotFoundException When no Product has that identity.
     * @throws ProductBusinessRuleException When the Product is soft deleted, or activation would violate an invariant.
     * @throws ProductPersistenceException When stored state changed or is invalid.
     */
    public function updateStatus(int $productId, ProductStatusEnum $status): void;

    /**
     * Soft-deletes a non-deleted Product. Its Variants, Options, Values, Content, and Image Assignments are not changed.
     *
     * @throws ProductInvalidArgumentException When the identity is not a positive integer.
     * @throws ProductNotFoundException When no Product has that identity.
     * @throws ProductBusinessRuleException When the Product is already soft deleted.
     * @throws ProductPersistenceException When stored state changed or is invalid.
     */
    public function softDelete(int $productId): void;

    /**
     * Restores a soft-deleted Product with its saved status, after revalidating the configuration
     * invariants (and the name requirement when the saved status is active).
     *
     * @throws ProductInvalidArgumentException When the identity is not a positive integer.
     * @throws ProductNotFoundException When no Product has that identity.
     * @throws ProductBusinessRuleException When the Product is not soft deleted, or the restored state would violate an invariant.
     * @throws ProductPersistenceException When stored state changed or is invalid.
     */
    public function restore(int $productId): void;

    /**
     * Replaces the active configuration of a Product atomically: the target active Options and
     * Values, and the target active Variant set, as described by {@see ReconfigureProductCommand}.
     *
     * Active Variants absent from the target become inactive and keep their history. A target
     * composition that already exists in the Product's history (active, inactive, or soft-deleted)
     * must name that Variant through `reuseVariantId`; it is then reactivated or restored and
     * revalidated inside the same operation. A new composition becomes a new active Variant.
     * The transition to a simple Product reuses the original empty-composition Variant and never
     * creates a second one. Every failure rejects the whole operation.
     *
     * The command is validated when it is constructed: a non-positive identity or a repeated Option,
     * Value, composition, or reuse identity raises `ProductInvalidArgumentException` there, before
     * this method is called.
     *
     * @throws ProductNotFoundException When no Product has that identity.
     * @throws VariantNotFoundException When a `reuseVariantId` names no Variant.
     * @throws VariantCompositionConflictException When a composition exists without a reuse identity, or the named Variant does not match.
     * @throws ProductBusinessRuleException When the Product is soft deleted, or the target violates a structural invariant.
     * @throws ProductPersistenceException When stored state changed or is invalid, including a fingerprint that does not match its pairs or a missing original empty-composition Variant.
     */
    public function reconfigure(ReconfigureProductCommand $command): ProductReconfigurationResultDTO;

    /**
     * Returns a Product for management by its code, compared under the database collation (`P-1` finds
     * `p-1`), whatever its status, filtered only by soft-deletion state.
     *
     * @throws ProductInvalidArgumentException When the code is empty, whitespace only, longer than 100 characters, or not valid UTF-8.
     * @throws ProductNotFoundException When no Product with that code matches the deleted state.
     */
    public function getByCodeForManagement(
        string $code,
        ProductDeletedStateEnum $deletedState = ProductDeletedStateEnum::NON_DELETED,
    ): ProductDTO;

    /**
     * Lists Products for management by status (`null` means any), deleted state, and the optional literal
     * substring search on `code`, ordered by identity, at most `maxResults`.
     */
    public function listForManagement(ProductListCriteriaDTO $criteria = new ProductListCriteriaDTO()): ProductCollectionDTO;

    /**
     * One page of Products for management under the same criteria, through the shared `maatify/php-persistence` paginator: 20 per page by default, 1 to 100 (a larger request is
     * clamped), ties broken by `id` ascending, and an unknown sort key falls back to the default. The criteria's
     * `maxResults` does not apply. Sort keys: `id` (default,
     * ascending), `code`, `created_at`, `updated_at`. `total` counts the status and deleted-state filter
     * without the search; `filtered` and the page apply the search too.
     *
     * @return PageResultDTO<ProductDTO>
     */
    public function paginateForManagement(ProductListCriteriaDTO $criteria, PageRequest $request): PageResultDTO;
}
