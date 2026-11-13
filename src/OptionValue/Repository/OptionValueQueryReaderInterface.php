<?php

declare(strict_types=1);

namespace Maatify\Product\OptionValue\Repository;

use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\PhpPersistence\Pagination\DTO\PageResultDTO;
use Maatify\Product\OptionValue\DTO\OptionValueDTO;
use Maatify\Product\OptionValue\Management\Criteria\OptionValueListCriteriaDTO;

/**
 * Read port for stored Option Values. It performs no write and holds no lock.
 *
 * @internal Not part of the Public Runtime API.
 */
interface OptionValueQueryReaderInterface
{
    /**
     * Finds an Option Value by identity whatever its status and soft-deletion state (management read).
     */
    public function findById(int $optionValueId): ?OptionValueDTO;

    /**
     * Lists the Option Values of a Option for management by status and deleted state, ordered by
     * display order then identity, at most `maxResults`.
     *
     * @return list<OptionValueDTO>
     */
    public function listForManagement(int $optionId, OptionValueListCriteriaDTO $criteria): array;

    /**
     * One page of the Option Values of a Option for management through the shared paginator; the counts use the same filter.
     *
     * @return PageResultDTO<OptionValueDTO>
     */
    public function paginateForManagement(int $optionId, OptionValueListCriteriaDTO $criteria, PageRequest $request): PageResultDTO;

    /**
     * The consumer-visible Values of an Option: active, not deleted, and labelled, under an active,
     * non-deleted, labelled Option, and represented by at least one consumer-visible Variant (see
     * {@see \Maatify\Product\Variant\Repository\Pdo\VariantVisibilitySql}), ordered by display order then
     * identity, at most `$maxResults`.
     *
     * @return list<OptionValueDTO>
     */
    public function listVisible(int $optionId, int $maxResults): array;
}
