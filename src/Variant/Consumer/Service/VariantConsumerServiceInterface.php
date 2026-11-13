<?php

declare(strict_types=1);

namespace Maatify\Product\Variant\Consumer\Service;

use Maatify\PhpPersistence\Pagination\DTO\PageResultDTO;
use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;
use Maatify\Product\Common\Exception\ProductPersistenceException;
use Maatify\Product\Product\Exception\ProductNotFoundException;
use Maatify\Product\Variant\Consumer\Criteria\VariantCompositionCriteriaDTO;
use Maatify\Product\Variant\Consumer\Criteria\VariantVisibleListCriteriaDTO;
use Maatify\Product\Variant\DTO\VariantCollectionDTO;
use Maatify\Product\Variant\DTO\VariantDTO;
use Maatify\Product\Variant\Exception\VariantNotFoundException;

/**
 * Consumer contract for Variants: reads and composition resolution that return only
 * consumer-visible Variants and write nothing. List reads never fail for a missing Product:
 * they return an empty collection.
 */
interface VariantConsumerServiceInterface
{
    /**
     * Returns a consumer-visible Variant: active and not deleted, of an active and not deleted
     * Product, with a structurally valid composition, in a Product whose configuration is valid
     * as a whole (every active Value of an active Option represented by an active valid Variant).
     *
     * @throws ProductInvalidArgumentException When the identity is not a positive integer.
     * @throws VariantNotFoundException When no visible Variant has that identity.
     */
    public function getById(int $variantId): VariantDTO;

    /**
     * Lists the consumer-visible Variants of a Product, at most 100, ordered by display order then identity.
     *
     * @throws ProductInvalidArgumentException When the identity is not a positive integer.
     */
    public function listByProductId(
        int $productId,
        VariantVisibleListCriteriaDTO $criteria = new VariantVisibleListCriteriaDTO(),
    ): VariantCollectionDTO;

    /**
     * Resolves a shopper's option choices to the one consumer-visible Variant of a Product (read-only).
     *
     * The selection must name exactly one active Value for every active Option of the Product; an
     * empty selection resolves a simple Product's Variant. It never creates or reactivates a Variant
     * and never invents a default. A repeated Option is rejected earlier, by the criteria at
     * construction ({@see VariantCompositionCriteriaDTO}, `ProductInvalidArgumentException`).
     *
     * @throws ProductInvalidArgumentException When the Product identity is not positive, or the selection is structurally invalid:
     *                                         an Option or Value that is not an active, non-deleted member of the Product, a Value
     *                                         paired with the wrong Option, or an active Option left unselected.
     * @throws ProductNotFoundException When the Product does not exist or is not consumer-visible.
     * @throws VariantNotFoundException When the structurally valid selection matches no consumer-visible Variant.
     * @throws ProductPersistenceException When the matching Variant's stored fingerprint does not match its composition pairs.
     */
    public function resolveByComposition(int $productId, VariantCompositionCriteriaDTO $criteria): VariantDTO;

    /**
     * Returns a page with the same visibility and order as listByProductId().
     * Uses only the page and page size from the request. Counts include visible rows only.
     * @throws ProductInvalidArgumentException When $productId is not positive.
     * @return PageResultDTO<VariantDTO>
     */
    public function paginateByProductId(int $productId, PageRequest $request): PageResultDTO;
}
