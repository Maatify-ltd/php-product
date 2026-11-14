<?php

declare(strict_types=1);

namespace Maatify\Product\Content\Consumer\Service;

use Maatify\Product\Common\Exception\ProductInvalidArgumentException;
use Maatify\Product\Content\DTO\ProductContentCollectionDTO;
use Maatify\Product\Content\ValueObject\ExactContentLanguage;

/**
 * Consumer contract for Product Content: reads only the visible record of one exact language, or the
 * unlocalized record, and writes nothing. Reads never fall back from one language to another or to the
 * unlocalized record.
 */
interface ProductContentConsumerServiceInterface
{
    /**
     * Consumer read of the record of one exact language, or of the unlocalized record: zero or one record,
     * returned only when it is not soft deleted and the Product is active and not soft deleted. Otherwise the collection is empty; there is
     * no fallback.
     *
     * @throws ProductInvalidArgumentException When the owner identity is not a positive integer.
     */
    public function listVisible(int $productId, ExactContentLanguage $language): ProductContentCollectionDTO;
}
