<?php

declare(strict_types=1);

namespace Maatify\Product\ImageRole\Repository;

use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\PhpPersistence\Pagination\DTO\PageResultDTO;
use Maatify\Product\ImageRole\DTO\ImageRoleDTO;
use Maatify\Product\ImageRole\Management\Criteria\ImageRoleListCriteriaDTO;

/**
 * Read port for stored Image Roles. It performs no write and holds no lock.
 *
 * @internal Not part of the Public Runtime API.
 */
interface ImageRoleQueryReaderInterface
{
    /**
     * Finds an Image Role by identity whatever its status and soft-deletion state (management read).
     */
    public function findById(int $roleId): ?ImageRoleDTO;

    /**
     * Finds a Role by its key under the database collation, whether or not it is soft deleted.
     */
    public function findByKey(string $roleKey): ?ImageRoleDTO;

    /**
     * Lists Roles for management by status and deleted state, ordered by the binary role key then
     * identity, at most `maxResults`.
     *
     * @return list<ImageRoleDTO>
     */
    public function listForManagement(ImageRoleListCriteriaDTO $criteria): array;

    /**
     * One page of Roles for management through the shared paginator; the counts use the same filter.
     *
     * @return PageResultDTO<ImageRoleDTO>
     */
    public function paginateForManagement(ImageRoleListCriteriaDTO $criteria, PageRequest $request): PageResultDTO;
}
