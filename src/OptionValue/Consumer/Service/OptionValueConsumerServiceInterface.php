<?php

declare(strict_types=1);

namespace Maatify\Product\OptionValue\Consumer\Service;

use Maatify\Product\OptionValue\DTO\OptionValueDTO;
use Maatify\PhpPersistence\Pagination\DTO\PageResultDTO;
use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;
use Maatify\Product\OptionValue\Consumer\Criteria\OptionValueVisibleListCriteriaDTO;
use Maatify\Product\OptionValue\DTO\OptionValueCollectionDTO;

/**
 * Consumer contract for Option Values: reads only the selectable Values of an Option and writes nothing.
 */
interface OptionValueConsumerServiceInterface
{
    /**
     * Consumer read of the selectable Values of an Option: active, not deleted, and labelled Values of an
     * active, non-deleted, labelled Option, each represented by at least one consumer-visible Variant
     * (the Variant visibility rule, including the product-wide rule that hides every Variant of a
     * Product while one of its active Values is unrepresented), ordered by display order then identity,
     * at most `maxResults`. Anything else yields an empty collection.
     *
     * @throws ProductInvalidArgumentException When the Option identity is not a positive integer.
     */
    public function listVisibleByOptionId(
        int $optionId,
        OptionValueVisibleListCriteriaDTO $criteria = new OptionValueVisibleListCriteriaDTO(),
    ): OptionValueCollectionDTO;

    /**
     * Returns a page with the same visibility and order as listVisibleByOptionId().
     * Uses only the page and page size from the request. Counts include visible rows only.
     * @throws ProductInvalidArgumentException When $optionId is not positive.
     * @return PageResultDTO<OptionValueDTO>
     */
    public function paginateVisibleByOptionId(int $optionId, PageRequest $request): PageResultDTO;
}
