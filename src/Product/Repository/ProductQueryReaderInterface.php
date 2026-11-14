<?php

declare(strict_types=1);

namespace Maatify\Product\Product\Repository;

use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\PhpPersistence\Pagination\DTO\PageResultDTO;
use Maatify\Product\Product\DTO\ProductDTO;
use Maatify\Product\Product\Management\Criteria\ProductListCriteriaDTO;

/**
 * Read port for stored Products.
 *
 * @internal Not part of the Public Runtime API.
 */
interface ProductQueryReaderInterface
{
    /**
     * Finds a Product by identity, whether or not it is soft deleted.
     *
     * This is a management read: it applies no status or deletion visibility rule,
     * and callers inspect {@see ProductDTO::$status} and {@see ProductDTO::$deletedAt}.
     *
     * @return ProductDTO|null Null when no Product has that identity.
     */
    public function findById(int $productId): ?ProductDTO;

    /**
     * Finds a Product by its code under the database collation, whether or not it is soft deleted.
     */
    public function findByCode(string $code): ?ProductDTO;

    /**
     * Lists Products for management by status, deleted state, and the optional code search,
     * ordered by identity, at most `maxResults`.
     *
     * @return list<ProductDTO>
     */
    public function listForManagement(ProductListCriteriaDTO $criteria): array;

    /**
     * One page of Products for management through the shared paginator: `total` counts the status and
     * deleted-state filter, `filtered` and the page apply the search too.
     *
     * @return PageResultDTO<ProductDTO>
     */
    public function paginateForManagement(ProductListCriteriaDTO $criteria, PageRequest $request): PageResultDTO;
}
