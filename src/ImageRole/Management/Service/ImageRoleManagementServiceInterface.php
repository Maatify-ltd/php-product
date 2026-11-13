<?php

declare(strict_types=1);

namespace Maatify\Product\ImageRole\Management\Service;

use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\PhpPersistence\Pagination\DTO\PageResultDTO;
use Maatify\Product\Common\Enum\ProductDeletedStateEnum;
use Maatify\Product\Common\Exception\ProductBusinessRuleException;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;
use Maatify\Product\Common\Exception\ProductPersistenceException;
use Maatify\Product\ImageRole\DTO\CreatedImageRoleDTO;
use Maatify\Product\ImageRole\DTO\ImageRoleCollectionDTO;
use Maatify\Product\ImageRole\DTO\ImageRoleDTO;
use Maatify\Product\ImageRole\Enum\ImageRoleStatusEnum;
use Maatify\Product\ImageRole\Exception\ImageRoleKeyAlreadyExistsException;
use Maatify\Product\ImageRole\Exception\ImageRoleNotFoundException;
use Maatify\Product\ImageRole\Management\Command\CreateImageRoleCommand;
use Maatify\Product\ImageRole\Management\Criteria\ImageRoleListCriteriaDTO;

/**
 * Management contract for Product Image Roles: creation, status, lifecycle, and management reads
 * (lookup by identity or key, the list, and its paginated twin).
 *
 * Role IDs are a Product-owned namespace, never Category Image Role IDs. Changing a Role's status
 * or deleting it never changes an existing assignment; consumer reads hide such assignments.
 */
interface ImageRoleManagementServiceInterface
{
    /**
     * Creates an active Image Role.
     *
     * @throws ImageRoleKeyAlreadyExistsException When the key is already reserved, including by a soft-deleted Role.
     * @throws ProductPersistenceException When stored state changed or is invalid.
     */
    public function create(CreateImageRoleCommand $command): CreatedImageRoleDTO;

    /**
     * Changes the status of a non-deleted Role. A request for the current status changes nothing.
     *
     * @throws ProductInvalidArgumentException When the identity is not a positive integer.
     * @throws ImageRoleNotFoundException When no Role has that identity.
     * @throws ProductBusinessRuleException When the Role is soft deleted.
     * @throws ProductPersistenceException When stored state changed or is invalid.
     */
    public function updateStatus(int $roleId, ImageRoleStatusEnum $status): void;

    /**
     * Soft-deletes a non-deleted Role. Existing assignments are not changed.
     *
     * @throws ProductInvalidArgumentException When the identity is not a positive integer.
     * @throws ImageRoleNotFoundException When no Role has that identity.
     * @throws ProductBusinessRuleException When the Role is already soft deleted.
     * @throws ProductPersistenceException When stored state changed or is invalid.
     */
    public function softDelete(int $roleId): void;

    /**
     * Restores a soft-deleted Role with its saved status.
     *
     * @throws ProductInvalidArgumentException When the identity is not a positive integer.
     * @throws ImageRoleNotFoundException When no Role has that identity.
     * @throws ProductBusinessRuleException When the Role is not soft deleted.
     * @throws ProductPersistenceException When stored state changed or is invalid.
     */
    public function restore(int $roleId): void;

    /**
     * Returns a Role for management, whatever its status, filtered only by soft-deletion state.
     *
     * @throws ProductInvalidArgumentException When the identity is not a positive integer.
     * @throws ImageRoleNotFoundException When no Role with that identity matches the deleted state.
     */
    public function getByIdForManagement(
        int $roleId,
        ProductDeletedStateEnum $deletedState = ProductDeletedStateEnum::NON_DELETED,
    ): ImageRoleDTO;

    /**
     * Returns a Role for management by its key, compared under the database collation, whatever its status,
     * filtered only by soft-deletion state.
     *
     * @throws ProductInvalidArgumentException When the key is empty, whitespace only, longer than 100 characters, or not valid UTF-8.
     * @throws ImageRoleNotFoundException When no Role with that key matches the deleted state.
     */
    public function getByKeyForManagement(
        string $roleKey,
        ProductDeletedStateEnum $deletedState = ProductDeletedStateEnum::NON_DELETED,
    ): ImageRoleDTO;

    /**
     * Lists Roles for management by status (`null` means any) and deleted state, ordered by the binary role
     * key then identity, at most `maxResults`.
     */
    public function listForManagement(ImageRoleListCriteriaDTO $criteria = new ImageRoleListCriteriaDTO()): ImageRoleCollectionDTO;

    /**
     * One page of Roles for management under the same criteria, through the shared `maatify/php-persistence` paginator: 20 per page by default, 1 to 100 (a larger request is
     * clamped), ties broken by `id` ascending, and an unknown sort key falls back to the default. The criteria's
     * `maxResults` does not apply. Sort keys: `role_key` (default,
     * ascending by binary value), `id`, `created_at`, `updated_at`.
     *
     * @return PageResultDTO<ImageRoleDTO>
     */
    public function paginateForManagement(ImageRoleListCriteriaDTO $criteria, PageRequest $request): PageResultDTO;
}
