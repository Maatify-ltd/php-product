<?php

declare(strict_types=1);

namespace Maatify\Product\ImageAssignment\Management\Service;

use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\PhpPersistence\Pagination\DTO\PageResultDTO;
use Maatify\Product\Common\Enum\ProductDeletedStateEnum;
use Maatify\Product\Common\Exception\ProductBusinessRuleException;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;
use Maatify\Product\Common\Exception\ProductPersistenceException;
use Maatify\Product\ImageAssignment\DTO\CreatedImageAssignmentDTO;
use Maatify\Product\ImageAssignment\DTO\ImageAssignmentCollectionDTO;
use Maatify\Product\ImageAssignment\DTO\ImageAssignmentDTO;
use Maatify\Product\ImageAssignment\Exception\ImageAssignmentAlreadyExistsException;
use Maatify\Product\ImageAssignment\Exception\ImageAssignmentDefaultConflictException;
use Maatify\Product\ImageAssignment\Exception\ImageAssignmentNotFoundException;
use Maatify\Product\ImageAssignment\Management\Command\CreateImageAssignmentCommand;
use Maatify\Product\ImageAssignment\Management\Command\UpdateImageAssignmentDisplayOrderCommand;
use Maatify\Product\ImageAssignment\Management\Criteria\ImageAssignmentListCriteriaDTO;
use Maatify\Product\ImageRole\Exception\ImageRoleNotFoundException;
use Maatify\Product\Product\Exception\ProductNotFoundException;
use Maatify\Product\Variant\Exception\VariantNotFoundException;

/**
 * Management contract for Image Assignments: creation, the explicit default operations,
 * lifecycle, ordering, and management reads (lookup by identity, the Product-scoped list with its
 * exact selectors, and its paginated twin).
 *
 * Every mutation runs in the savepoint transaction runner and locks, in this order, the Product
 * row, the target Variant row (when any), the Role row (when any), and the assignment row. Default
 * replacement is atomic within the exact `(target, role, language, platform)` scope, restore-as-default
 * never replaces a live default, and the database unique key on the default scope is the final guard.
 * No operation ever promotes another assignment.
 */
interface ImageAssignmentManagementServiceInterface
{
    /**
     * Assigns a Media Asset to a non-deleted Product, or to a non-deleted Variant of that Product, within an exact
     * scope. A role-scoped assignment needs an active, non-deleted Role. The new assignment is not the default.
     *
     * @throws ProductNotFoundException When no Product has that identity.
     * @throws VariantNotFoundException When no Variant has that identity.
     * @throws ImageRoleNotFoundException When no Role has that identity.
     * @throws ProductInvalidArgumentException When the Variant does not belong to the Product.
     * @throws ProductBusinessRuleException When the Product or Variant is soft deleted, or the Role is inactive or soft deleted.
     * @throws ImageAssignmentAlreadyExistsException When the identity exists, including as a soft-deleted assignment (restore it instead).
     * @throws ProductPersistenceException When stored state changed or is invalid.
     */
    public function create(CreateImageAssignmentCommand $command): CreatedImageAssignmentDTO;

    /**
     * Makes a non-deleted assignment the single default of its exact scope, clearing the previous default of that scope
     * in the same operation. It requires a non-deleted target Product and Variant and a non-deleted Role when one is set;
     * an inactive Product, Variant, or Role does not block it. A request for the current default changes nothing.
     *
     * @throws ProductInvalidArgumentException When the identity is not a positive integer.
     * @throws ImageAssignmentNotFoundException When no assignment has that identity.
     * @throws ProductBusinessRuleException When the assignment, its target, or its Role is soft deleted.
     * @throws ProductPersistenceException When stored state changed or is invalid.
     */
    public function setDefault(int $assignmentId): void;

    /**
     * Clears the default marker of a non-deleted assignment, even when its target or Role is soft deleted.
     * It promotes no other assignment and changes nothing for a non-default assignment.
     *
     * @throws ProductInvalidArgumentException When the identity is not a positive integer.
     * @throws ImageAssignmentNotFoundException When no assignment has that identity.
     * @throws ProductBusinessRuleException When the assignment is soft deleted.
     * @throws ProductPersistenceException When stored state changed or is invalid.
     */
    public function clearDefault(int $assignmentId): void;

    /**
     * Soft-deletes a non-deleted assignment whose target Product and Variant are not deleted. A default loses its
     * marker, and no other assignment is promoted.
     *
     * @throws ProductInvalidArgumentException When the identity is not a positive integer.
     * @throws ImageAssignmentNotFoundException When no assignment has that identity.
     * @throws ProductBusinessRuleException When the assignment or its target is soft deleted.
     * @throws ProductPersistenceException When stored state changed or is invalid.
     */
    public function softDelete(int $assignmentId): void;

    /**
     * Moves a non-deleted assignment to a position within its exact `(target, role, language, platform)`
     * scope through the shared Ordering API: a position above the current maximum is clamped to it, the
     * current position changes nothing, and the other assignments of the scope shift to keep the positions
     * contiguous. The moved row's `updated_at` is stamped. Other scopes are never touched. The Product row
     * is locked first, then the target Variant when there is one, then the assignment.
     *
     * @throws ImageAssignmentNotFoundException When no assignment has that identity.
     * @throws ProductBusinessRuleException When the assignment or its target Product or Variant is soft deleted.
     * @throws ProductPersistenceException When stored state changed or is invalid.
     */
    public function updateDisplayOrder(UpdateImageAssignmentDisplayOrderCommand $command): void;

    /**
     * Restores a soft-deleted assignment as non-default at the end of its exact scope's order; its target Product and Variant must not be deleted.
     *
     * @throws ProductInvalidArgumentException When the identity is not a positive integer.
     * @throws ImageAssignmentNotFoundException When no assignment has that identity.
     * @throws ProductBusinessRuleException When the assignment is not soft deleted, or its target is soft deleted.
     * @throws ProductPersistenceException When stored state changed or is invalid.
     */
    public function restore(int $assignmentId): void;

    /**
     * Restores a soft-deleted assignment and makes it the default of its exact scope in one write, at the end of
     * the scope's order. It requires a non-deleted target Product and Variant and a non-deleted Role when one is set;
     * an inactive Product, Variant, or Role does not block it. It never replaces a live default: when the exact scope
     * already has one, it fails and writes nothing. The database unique key on the default scope is the final guard.
     *
     * @throws ProductInvalidArgumentException When the identity is not a positive integer.
     * @throws ImageAssignmentNotFoundException When no assignment has that identity.
     * @throws ProductBusinessRuleException When the assignment is not soft deleted, or its target or Role is soft deleted.
     * @throws ImageAssignmentDefaultConflictException When the exact scope already has a live default.
     * @throws ProductPersistenceException When stored state changed or is invalid.
     */
    public function restoreAsDefault(int $assignmentId): void;

    /**
     * Returns an assignment for management, filtered only by soft-deletion state.
     *
     * @throws ProductInvalidArgumentException When the identity is not a positive integer.
     * @throws ImageAssignmentNotFoundException When no assignment with that identity matches the deleted state.
     */
    public function getByIdForManagement(
        int $assignmentId,
        ProductDeletedStateEnum $deletedState = ProductDeletedStateEnum::NON_DELETED,
    ): ImageAssignmentDTO;

    /**
     * Lists the assignments of a Product for management under exact target, role, language, and platform
     * selectors and the deleted state, ordered by exact scope, display order, then identity, at most
     * `maxResults`. An omitted selector adds no condition; an exact `NULL` matches only `NULL`. A missing
     * Product yields an empty collection; the state of the Product, Variant, or Role hides nothing.
     */
    public function listForManagement(ImageAssignmentListCriteriaDTO $criteria): ImageAssignmentCollectionDTO;

    /**
     * One page of the assignments of a Product for management under the same criteria, through the shared `maatify/php-persistence` paginator: 20 per page by default, 1 to 100 (a larger request is
     * clamped), ties broken by `id` ascending, and an unknown sort key falls back to the default. The criteria's
     * `maxResults` does not apply. Sort keys:
     * `business_order` (default: exact scope, display order, then identity), `id`, `created_at`, `updated_at`.
     *
     * @return PageResultDTO<ImageAssignmentDTO>
     */
    public function paginateForManagement(ImageAssignmentListCriteriaDTO $criteria, PageRequest $request): PageResultDTO;
}
