<?php

declare(strict_types=1);

namespace Maatify\Product\Variant\Repository;

use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\PhpPersistence\Pagination\DTO\PageResultDTO;
use Maatify\Product\Variant\Consumer\Criteria\VariantVisibleListCriteriaDTO;
use Maatify\Product\Variant\DTO\VariantDTO;
use Maatify\Product\Variant\DTO\VariantStateDTO;
use Maatify\Product\Variant\Management\Criteria\VariantListCriteriaDTO;

/**
 * Read port for stored Variants. It performs no write and holds no lock.
 *
 * @internal Not part of the Public Runtime API.
 */
interface VariantQueryReaderInterface
{
    /**
     * Finds a Variant by identity whatever its status and soft-deletion state (management read).
     */
    public function findById(int $variantId): ?VariantDTO;

    /**
     * Finds a Variant only when it is consumer-visible.
     *
     * A Variant is visible when its Product is active and not deleted, the Variant is active
     * and not deleted, its composition is structurally valid (every selected Option and
     * Option Value is active, not deleted, and labelled, and the composition covers exactly
     * the Product's active Options), and the Product's configuration is valid as a whole:
     * every active Value of an active Option is represented by at least one active, valid
     * Variant. When that product-wide rule is broken, every Variant of the Product is hidden.
     */
    public function findVisibleById(int $variantId): ?VariantDTO;

    /**
     * Lists the Variants of a Product for management, filtered by status and deleted state.
     *
     * @return list<VariantDTO> Ordered by display order then identity.
     */
    public function listForManagement(int $productId, VariantListCriteriaDTO $criteria): array;

    /**
     * One page of the Variants of a Product for management, filtered by status and deleted state, through the
     * shared paginator; the counts use the same filter.
     *
     * @return PageResultDTO<VariantDTO>
     */
    public function paginateForManagement(int $productId, VariantListCriteriaDTO $criteria, PageRequest $request): PageResultDTO;

    /**
     * Lists the consumer-visible Variants of a Product (see {@see self::findVisibleById()}).
     *
     * @return list<VariantDTO> Ordered by display order then identity.
     */
    public function listVisible(int $productId, VariantVisibleListCriteriaDTO $criteria): array;

    /**
     * Whether the Product exists, is active, and is not soft deleted.
     */
    public function isProductVisible(int $productId): bool;

    /**
     * @return list<int> Active, non-deleted Option IDs of the Product, ordered by ID.
     */
    public function listActiveOptionIds(int $productId): array;

    /**
     * @param list<int> $valueIds
     * @return array<int, int> Selected active, non-deleted Value IDs mapped to their owning Option IDs within the Product.
     */
    public function findActiveValueOptionIds(int $productId, array $valueIds): array;

    /**
     * Finds the active, non-deleted Variant of a Product that stores the given composition fingerprint,
     * with its actual composition pairs, whatever its consumer visibility.
     */
    public function findActiveByFingerprint(int $productId, string $fingerprint): ?VariantStateDTO;
}
