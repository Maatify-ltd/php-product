<?php

declare(strict_types=1);

namespace Maatify\Product\Option\Repository;

use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\PhpPersistence\Pagination\DTO\PageResultDTO;
use Maatify\Product\Option\DTO\OptionDTO;
use Maatify\Product\Option\Management\Criteria\OptionListCriteriaDTO;

/**
 * Read port for stored Options. It performs no write and holds no lock.
 *
 * @internal Not part of the Public Runtime API.
 */
interface OptionQueryReaderInterface
{
    /**
     * Finds an Option by identity whatever its status and soft-deletion state (management read).
     */
    public function findById(int $optionId): ?OptionDTO;

    /**
     * Lists the Options of a Product for management by status and deleted state, ordered by
     * display order then identity, at most `maxResults`.
     *
     * @return list<OptionDTO>
     */
    public function listForManagement(int $productId, OptionListCriteriaDTO $criteria): array;

    /**
     * One page of the Options of a Product for management through the shared paginator; the counts use the same filter.
     *
     * @return PageResultDTO<OptionDTO>
     */
    public function paginateForManagement(int $productId, OptionListCriteriaDTO $criteria, PageRequest $request): PageResultDTO;

    /**
     * The consumer-visible Options of a Product: active, not deleted, and labelled, of a Product that
     * has at least one consumer-visible Variant (see {@see \Maatify\Product\Variant\Repository\Pdo\VariantVisibilitySql}),
     * ordered by display order then identity, at most `$maxResults`.
     *
     * @return list<OptionDTO>
     */
    public function listVisible(int $productId, int $maxResults): array;

    /** @return PageResultDTO<OptionDTO> A page and counts from the same visible rows as listVisible(). */
    public function paginateVisible(int $productId, PageRequest $request): PageResultDTO;
}
