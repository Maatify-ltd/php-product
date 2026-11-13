<?php

declare(strict_types=1);

namespace Maatify\Product\Content\Repository;

use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\PhpPersistence\Pagination\DTO\PageResultDTO;
use Maatify\Product\Common\Enum\ProductDeletedStateEnum;
use Maatify\Product\Content\DTO\ContentRecordDTO;
use Maatify\Product\Content\ValueObject\ContentLanguageFilter;
use Maatify\Product\Content\ValueObject\ExactContentLanguage;

/**
 * Read port for the Content records of one owner kind. It performs no write and holds no lock.
 *
 * @internal Not part of the Public Runtime API.
 */
interface ContentQueryReaderInterface
{
    /**
     * Finds a record by identity whatever its soft-deletion state (management read).
     */
    public function findById(int $contentId): ?ContentRecordDTO;

    /**
     * The owner's records matching the language selector and deleted state, ordered by
     * `language_code` (the unlocalized record first) then `id`, at most `$maxResults`.
     *
     * @return list<ContentRecordDTO>
     */
    public function listForManagement(
        int $ownerId,
        ContentLanguageFilter $language,
        ProductDeletedStateEnum $deletedState,
        int $maxResults,
    ): array;

    /**
     * One page of the owner's records matching the language selector and deleted state, through the
     * shared `maatify/php-persistence` paginator; the total and filtered counts use the same filter.
     *
     * @template T of object
     *
     * @param callable(ContentRecordDTO): T $mapper
     *
     * @return PageResultDTO<T>
     */
    public function paginateForManagement(
        int $ownerId,
        ContentLanguageFilter $language,
        ProductDeletedStateEnum $deletedState,
        PageRequest $request,
        callable $mapper,
    ): PageResultDTO;

    /**
     * The consumer-visible record of the exact language selector: not deleted, with an active,
     * non-deleted owner chain. At most one record exists per selector.
     *
     * @return list<ContentRecordDTO>
     */
    public function listVisible(int $ownerId, ExactContentLanguage $language): array;
}
