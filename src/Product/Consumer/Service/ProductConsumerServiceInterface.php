<?php

declare(strict_types=1);

namespace Maatify\Product\Product\Consumer\Service;

use Maatify\Product\Common\Exception\ProductInvalidArgumentException;
use Maatify\Product\Product\DTO\ProductDTO;
use Maatify\Product\Product\Exception\ProductNotFoundException;

/**
 * Consumer contract for Products: reads only consumer-visible Products and writes nothing.
 */
interface ProductConsumerServiceInterface
{
    /**
     * Returns a consumer-visible Product: one that is active and not soft deleted.
     *
     * @throws ProductInvalidArgumentException When the identity is not a positive integer.
     * @throws ProductNotFoundException When no visible Product has that identity.
     */
    public function getById(int $productId): ProductDTO;

    /**
     * Returns an active, non-deleted Product by code under the database collation.
     *
     * @param string $code Valid UTF-8, at most 100 characters, neither empty nor whitespace only. Never trimmed.
     * @throws ProductInvalidArgumentException When the code is invalid.
     * @throws ProductNotFoundException When the code does not match a visible Product.
     */
    public function getByCode(string $code): ProductDTO;
}
