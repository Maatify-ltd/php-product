<?php

declare(strict_types=1);

namespace Maatify\Product\Variant\Management\Service;

use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\PhpPersistence\Pagination\DTO\PageResultDTO;
use Maatify\Product\Common\Enum\ProductDeletedStateEnum;
use Maatify\Product\Common\Exception\ProductBusinessRuleException;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;
use Maatify\Product\Common\Exception\ProductPersistenceException;
use Maatify\Product\Variant\DTO\VariantCollectionDTO;
use Maatify\Product\Variant\DTO\VariantDTO;
use Maatify\Product\Variant\Enum\VariantStatusEnum;
use Maatify\Product\Variant\Exception\VariantBarcodeAlreadyExistsException;
use Maatify\Product\Variant\Exception\VariantNotFoundException;
use Maatify\Product\Variant\Exception\VariantSkuAlreadyExistsException;
use Maatify\Product\Variant\Management\Command\UpdateVariantDisplayOrderCommand;
use Maatify\Product\Variant\Management\Command\UpdateVariantIdentifiersCommand;
use Maatify\Product\Variant\Management\Criteria\VariantListCriteriaDTO;

/**
 * Management contract for Variants: management reads, status and lifecycle, the SKU and barcode
 * update, and ordering. Variants are created only with their Product or by Product reconfiguration.
 *
 * Management reads ignore consumer visibility and filter by status and soft-deletion state; the
 * consumer reads are on {@see \Maatify\Product\Variant\Consumer\Service\VariantConsumerServiceInterface}. List reads never fail for a missing Product:
 * they return an empty collection. Every mutation runs in the savepoint transaction runner and
 * takes the owning Product's row lock before reading the state it validates.
 */
interface VariantManagementServiceInterface
{
    /**
     * Returns a Variant for management, whatever its status, filtered only by soft-deletion state.
     *
     * @throws ProductInvalidArgumentException When the identity is not a positive integer.
     * @throws VariantNotFoundException When no Variant with that identity matches the deleted state.
     */
    public function getByIdForManagement(
        int $variantId,
        ProductDeletedStateEnum $deletedState = ProductDeletedStateEnum::NON_DELETED,
    ): VariantDTO;

    /**
     * Lists the Variants of a Product for management, at most 100, ordered by display order then identity.
     *
     * @throws ProductInvalidArgumentException When the identity is not a positive integer.
     */
    public function listByProductIdForManagement(
        int $productId,
        VariantListCriteriaDTO $criteria = new VariantListCriteriaDTO(),
    ): VariantCollectionDTO;

    /**
     * Changes the status of a non-deleted Variant of a non-deleted Product. A request for the current status changes nothing.
     *
     * Activation requires the full composition to satisfy the current configuration invariants;
     * deactivation is rejected when it would leave the Product without a valid active Variant.
     *
     * @throws ProductInvalidArgumentException When the identity is not a positive integer.
     * @throws VariantNotFoundException When no Variant has that identity.
     * @throws ProductBusinessRuleException When the Variant or its Product is soft deleted, or the change would violate an invariant.
     * @throws ProductPersistenceException When stored state changed or is invalid.
     */
    public function updateStatus(int $variantId, VariantStatusEnum $status): void;

    /**
     * Soft-deletes a non-deleted Variant of a non-deleted Product; rejected when it is the last valid active Variant.
     *
     * @throws ProductInvalidArgumentException When the identity is not a positive integer.
     * @throws VariantNotFoundException When no Variant has that identity.
     * @throws ProductBusinessRuleException When the Variant or its Product is soft deleted, or the deletion would violate an invariant.
     * @throws ProductPersistenceException When stored state changed or is invalid.
     */
    public function softDelete(int $variantId): void;

    /**
     * Changes the SKU and barcode of a non-deleted Variant of a non-deleted Product; each is left
     * unchanged, set, or cleared. An inactive Variant or Product does not block it. A field set to its
     * exact stored value, or cleared while `NULL`, does not change; when no field changes nothing is
     * written. Replacing or clearing a value releases it. The Variant identity never changes.
     *
     * @throws VariantNotFoundException When no Variant has that identity.
     * @throws ProductBusinessRuleException When the Variant or its Product is soft deleted.
     * @throws VariantSkuAlreadyExistsException When another Variant holds the SKU under the collation, including a soft-deleted one.
     * @throws VariantBarcodeAlreadyExistsException When another Variant holds the barcode under the collation, including a soft-deleted one.
     * @throws ProductPersistenceException When stored state changed or is invalid.
     */
    public function updateIdentifiers(UpdateVariantIdentifiersCommand $command): void;

    /**
     * Moves a non-deleted Variant to a position within its Product's order through the shared Ordering API: a
     * position above the current maximum is clamped to it, the current position changes nothing, and the
     * other rows of the scope shift to keep the positions contiguous. The moved row's `updated_at` is stamped.
     * Ordering never changes the configuration's validity, so no invariant is revalidated.
     *
     * @throws VariantNotFoundException When no Variant has that identity.
     * @throws ProductBusinessRuleException When the Variant or its Product is soft deleted.
     * @throws ProductPersistenceException When stored state changed or is invalid.
     */
    public function updateDisplayOrder(UpdateVariantDisplayOrderCommand $command): void;

    /**
     * Restores a soft-deleted Variant with its saved status at the end of its Product's order, after revalidating the configuration invariants.
     *
     * @throws ProductInvalidArgumentException When the identity is not a positive integer.
     * @throws VariantNotFoundException When no Variant has that identity.
     * @throws ProductBusinessRuleException When the Variant is not soft deleted, its Product is soft deleted, or the restored state would violate an invariant.
     * @throws ProductPersistenceException When stored state changed or is invalid.
     */
    public function restore(int $variantId): void;

    /**
     * One page of the Variants of a Product for management under the list criteria, through the shared `maatify/php-persistence` paginator: 20 per page by default, 1 to 100 (a larger request is
     * clamped), ties broken by `id` ascending, and an unknown sort key falls back to the default. The criteria's
     * `maxResults` does not apply.
     * Sort keys: `display_order` (default, ascending), `id`, `created_at`, `updated_at`. A missing Product yields an empty page.
     *
     * @return PageResultDTO<VariantDTO>
     *
     * @throws ProductInvalidArgumentException When the Product identity is not a positive integer.
     */
    public function paginateByProductIdForManagement(int $productId, VariantListCriteriaDTO $criteria, PageRequest $request): PageResultDTO;
}
