<?php

declare(strict_types=1);

namespace Maatify\Product\Option\Management\Service;

use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\PhpPersistence\Pagination\DTO\PageResultDTO;
use Maatify\Product\Common\Enum\ProductDeletedStateEnum;
use Maatify\Product\Common\Exception\ProductBusinessRuleException;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;
use Maatify\Product\Common\Exception\ProductPersistenceException;
use Maatify\Product\Option\DTO\CreatedOptionDTO;
use Maatify\Product\Option\DTO\OptionCollectionDTO;
use Maatify\Product\Option\DTO\OptionDTO;
use Maatify\Product\Option\Enum\OptionStatusEnum;
use Maatify\Product\Option\Exception\OptionCodeAlreadyExistsException;
use Maatify\Product\Option\Exception\OptionNotFoundException;
use Maatify\Product\Option\Management\Command\CreateOptionCommand;
use Maatify\Product\Option\Management\Command\UpdateOptionDisplayOrderCommand;
use Maatify\Product\Option\Management\Criteria\OptionListCriteriaDTO;
use Maatify\Product\Product\Exception\ProductNotFoundException;

/**
 * Management contract for Options: creation, status, lifecycle, ordering, and management reads
 * (lookup by identity, the owner-scoped list, and its paginated twin).
 *
 * An Option's status changes through the standalone `updateStatus()` when the whole configuration
 * stays valid, and Product reconfiguration changes the active set atomically. Every mutation
 * runs in the savepoint transaction runner and takes the owning Product's row lock first.
 */
interface OptionManagementServiceInterface
{
    /**
     * Creates an inactive Option under a non-deleted Product; its position comes from the shared Ordering API.
     *
     * @throws ProductNotFoundException When no Product has that identity.
     * @throws ProductBusinessRuleException When the Product is soft deleted.
     * @throws OptionCodeAlreadyExistsException When the code is already reserved in the Product, including by a soft-deleted Option.
     * @throws ProductPersistenceException When stored state changed or is invalid.
     */
    public function create(CreateOptionCommand $command): CreatedOptionDTO;

    /**
     * Changes the status of a non-deleted Option of a non-deleted Product. The Product's whole configuration graph
     * is locked first and validated with the new status; a change that would break a structural invariant
     * (`INV-01` to `INV-07`) is rejected and nothing is written. A request for the current status changes nothing.
     * Because an active configuration always has an active Variant, a change of the active Option set belongs to
     * reconfiguration.
     *
     * @throws ProductInvalidArgumentException When the identity is not a positive integer.
     * @throws OptionNotFoundException When no Option has that identity.
     * @throws ProductBusinessRuleException When the Option or its Product is soft deleted, or the change would violate an invariant.
     * @throws ProductPersistenceException When stored state changed or is invalid.
     */
    public function updateStatus(int $optionId, OptionStatusEnum $status): void;

    /**
     * Soft-deletes a non-deleted Option of a non-deleted Product. Its Values are not changed.
     * Rejected when the Option is part of the active configuration, because that would leave an
     * invalid configuration; reconfiguration deactivates it first.
     *
     * @throws ProductInvalidArgumentException When the identity is not a positive integer.
     * @throws OptionNotFoundException When no Option has that identity.
     * @throws ProductBusinessRuleException When the Option or its Product is soft deleted, or the deletion would violate an invariant.
     * @throws ProductPersistenceException When stored state changed or is invalid.
     */
    public function softDelete(int $optionId): void;

    /**
     * Moves a non-deleted Option to a position within its Product's order through the shared Ordering API: a
     * position above the current maximum is clamped to it, the current position changes nothing, and the
     * other rows of the scope shift to keep the positions contiguous. The moved row's `updated_at` is stamped.
     * Ordering never changes the configuration's validity, so no invariant is revalidated.
     *
     * @throws OptionNotFoundException When no Option has that identity.
     * @throws ProductBusinessRuleException When the Option or its Product is soft deleted.
     * @throws ProductPersistenceException When stored state changed or is invalid.
     */
    public function updateDisplayOrder(UpdateOptionDisplayOrderCommand $command): void;

    /**
     * Restores a soft-deleted Option with its saved status at the end of its Product's order, after revalidating the configuration invariants.
     *
     * @throws ProductInvalidArgumentException When the identity is not a positive integer.
     * @throws OptionNotFoundException When no Option has that identity.
     * @throws ProductBusinessRuleException When the Option is not soft deleted, its Product is soft deleted, or the restored state would violate an invariant.
     * @throws ProductPersistenceException When stored state changed or is invalid.
     */
    public function restore(int $optionId): void;

    /**
     * Returns an Option for management, whatever its status, filtered only by soft-deletion state.
     *
     * @throws ProductInvalidArgumentException When the identity is not a positive integer.
     * @throws OptionNotFoundException When no Option with that identity matches the deleted state.
     */
    public function getByIdForManagement(
        int $optionId,
        ProductDeletedStateEnum $deletedState = ProductDeletedStateEnum::NON_DELETED,
    ): OptionDTO;

    /**
     * Lists the Options of a Product for management by status (`null` means any) and deleted state,
     * ordered by display order then identity, at most `maxResults`. A missing Product yields an empty collection.
     *
     * @throws ProductInvalidArgumentException When the Product identity is not a positive integer.
     */
    public function listByProductIdForManagement(int $productId, OptionListCriteriaDTO $criteria = new OptionListCriteriaDTO()): OptionCollectionDTO;

    /**
     * One page of the Options of a Product for management under the same criteria, through the shared `maatify/php-persistence` paginator: 20 per page by default, 1 to 100 (a larger request is
     * clamped), ties broken by `id` ascending, and an unknown sort key falls back to the default. The criteria's
     * `maxResults` does not apply.
     * Sort keys: `display_order` (default, ascending), `id`, `created_at`, `updated_at`.
     *
     * @return PageResultDTO<OptionDTO>
     *
     * @throws ProductInvalidArgumentException When the Product identity is not a positive integer.
     */
    public function paginateByProductIdForManagement(int $productId, OptionListCriteriaDTO $criteria, PageRequest $request): PageResultDTO;
}
