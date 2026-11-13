<?php

declare(strict_types=1);

namespace Maatify\Product\Option\Consumer\Service;

use Maatify\Product\Option\DTO\OptionDTO;
use Maatify\PhpPersistence\Pagination\DTO\PageResultDTO;
use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;
use Maatify\Product\Option\Consumer\Criteria\OptionVisibleListCriteriaDTO;
use Maatify\Product\Option\DTO\OptionCollectionDTO;

/**
 * Consumer contract for Options: reads only the selectable Options of a Product and writes nothing.
 */
interface OptionConsumerServiceInterface
{
    /**
     * Consumer read of the selectable Options of a Product: active, not deleted, and labelled Options of a
     * Product that has at least one consumer-visible Variant (the Variant visibility rule, including the
     * product-wide rule that hides every Variant of a Product while one of its active Values is unrepresented), ordered by display order then identity, at most `maxResults`. A
     * missing, inactive, deleted, simple, or invalidly configured Product yields an empty collection.
     *
     * @throws ProductInvalidArgumentException When the Product identity is not a positive integer.
     */
    public function listVisibleByProductId(
        int $productId,
        OptionVisibleListCriteriaDTO $criteria = new OptionVisibleListCriteriaDTO(),
    ): OptionCollectionDTO;

    /**
     * Returns a page with the same visibility and order as listVisibleByProductId().
     * Uses only the page and page size from the request. Counts include visible rows only.
     * @throws ProductInvalidArgumentException When $productId is not positive.
     * @return PageResultDTO<OptionDTO>
     */
    public function paginateVisibleByProductId(int $productId, PageRequest $request): PageResultDTO;
}
