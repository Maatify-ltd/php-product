<?php

declare(strict_types=1);

namespace Maatify\Product\OptionValue\Management\Service;

use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\PhpPersistence\Pagination\DTO\PageResultDTO;
use Maatify\Product\Common\Enum\ProductDeletedStateEnum;
use Maatify\Product\Common\Exception\ProductBusinessRuleException;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;
use Maatify\Product\Common\Exception\ProductPersistenceException;
use Maatify\Product\Option\Exception\OptionNotFoundException;
use Maatify\Product\OptionValue\DTO\CreatedOptionValueDTO;
use Maatify\Product\OptionValue\DTO\OptionValueCollectionDTO;
use Maatify\Product\OptionValue\DTO\OptionValueDTO;
use Maatify\Product\OptionValue\Enum\OptionValueStatusEnum;
use Maatify\Product\OptionValue\Exception\OptionValueCodeAlreadyExistsException;
use Maatify\Product\OptionValue\Exception\OptionValueNotFoundException;
use Maatify\Product\OptionValue\Management\Command\CreateOptionValueCommand;
use Maatify\Product\OptionValue\Management\Command\UpdateOptionValueDisplayOrderCommand;
use Maatify\Product\OptionValue\Management\Criteria\OptionValueListCriteriaDTO;

/**
 * Management contract for Option Values: creation, status, lifecycle, ordering, and management reads
 * (lookup by identity, the owner-scoped list, and its paginated twin).
 *
 * A Value's status changes through the standalone `updateStatus()` when the whole configuration
 * stays valid, and Product reconfiguration changes the active set atomically. Every mutation
 * runs in the savepoint transaction runner and takes the owning Product's row lock first.
 */
interface OptionValueManagementServiceInterface
{
    /**
     * Creates an inactive Value under a non-deleted Option of a non-deleted Product; its position comes from the shared Ordering API.
     *
     * @throws OptionNotFoundException When no Option has that identity.
     * @throws ProductBusinessRuleException When the Option or its Product is soft deleted.
     * @throws OptionValueCodeAlreadyExistsException When the code is already reserved in the Option, including by a soft-deleted Value.
     * @throws ProductPersistenceException When stored state changed or is invalid.
     */
    public function create(CreateOptionValueCommand $command): CreatedOptionValueDTO;

    /**
     * Changes the status of a non-deleted Value whose Option and Product are not deleted. The Product's whole
     * configuration graph is locked first and validated with the new status; a change that would break a structural
     * invariant (`INV-01` to `INV-07`) is rejected and nothing is written. A request for the current status changes
     * nothing. An inactive Value of an inactive Option can always change, because it is outside the active configuration.
     *
     * @throws ProductInvalidArgumentException When the identity is not a positive integer.
     * @throws OptionValueNotFoundException When no Value has that identity.
     * @throws ProductBusinessRuleException When the Value, its Option, or its Product is soft deleted, or the change would violate an invariant.
     * @throws ProductPersistenceException When stored state changed or is invalid.
     */
    public function updateStatus(int $optionValueId, OptionValueStatusEnum $status): void;

    /**
     * Soft-deletes a non-deleted Value whose Option and Product are not deleted. Rejected when an active
     * Variant needs it, because that would leave an invalid configuration.
     *
     * @throws ProductInvalidArgumentException When the identity is not a positive integer.
     * @throws OptionValueNotFoundException When no Value has that identity.
     * @throws ProductBusinessRuleException When the Value, its Option, or its Product is soft deleted, or the deletion would violate an invariant.
     * @throws ProductPersistenceException When stored state changed or is invalid.
     */
    public function softDelete(int $optionValueId): void;

    /**
     * Moves a non-deleted Option Value to a position within its Option's order through the shared Ordering API: a
     * position above the current maximum is clamped to it, the current position changes nothing, and the
     * other rows of the scope shift to keep the positions contiguous. The moved row's `updated_at` is stamped.
     * Ordering never changes the configuration's validity, so no invariant is revalidated.
     *
     * @throws OptionValueNotFoundException When no Option Value has that identity.
     * @throws ProductBusinessRuleException When the Option Value, its Option, or its Product is soft deleted.
     * @throws ProductPersistenceException When stored state changed or is invalid.
     */
    public function updateDisplayOrder(UpdateOptionValueDisplayOrderCommand $command): void;

    /**
     * Restores a soft-deleted Value with its saved status at the end of its Option's order, after revalidating the configuration invariants.
     *
     * @throws ProductInvalidArgumentException When the identity is not a positive integer.
     * @throws OptionValueNotFoundException When no Value has that identity.
     * @throws ProductBusinessRuleException When the Value is not soft deleted, its Option or Product is soft deleted, or the restored state would violate an invariant.
     * @throws ProductPersistenceException When stored state changed or is invalid.
     */
    public function restore(int $optionValueId): void;

    /**
     * Returns an Option Value for management, whatever its status, filtered only by soft-deletion state.
     *
     * @throws ProductInvalidArgumentException When the identity is not a positive integer.
     * @throws OptionValueNotFoundException When no Value with that identity matches the deleted state.
     */
    public function getByIdForManagement(
        int $optionValueId,
        ProductDeletedStateEnum $deletedState = ProductDeletedStateEnum::NON_DELETED,
    ): OptionValueDTO;

    /**
     * Lists the Option Values of an Option for management by status (`null` means any) and deleted state,
     * ordered by display order then identity, at most `maxResults`. A missing Option yields an empty collection.
     *
     * @throws ProductInvalidArgumentException When the Option identity is not a positive integer.
     */
    public function listByOptionIdForManagement(int $optionId, OptionValueListCriteriaDTO $criteria = new OptionValueListCriteriaDTO()): OptionValueCollectionDTO;

    /**
     * One page of the Option Values of an Option for management under the same criteria, through the shared `maatify/php-persistence` paginator: 20 per page by default, 1 to 100 (a larger request is
     * clamped), ties broken by `id` ascending, and an unknown sort key falls back to the default. The criteria's
     * `maxResults` does not apply.
     * Sort keys: `display_order` (default, ascending), `id`, `created_at`, `updated_at`.
     *
     * @return PageResultDTO<OptionValueDTO>
     *
     * @throws ProductInvalidArgumentException When the Option identity is not a positive integer.
     */
    public function paginateByOptionIdForManagement(int $optionId, OptionValueListCriteriaDTO $criteria, PageRequest $request): PageResultDTO;
}
