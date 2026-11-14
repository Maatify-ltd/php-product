<?php

declare(strict_types=1);

namespace Maatify\Product\Common\Exception;

use Maatify\Exceptions\Exception\BusinessRule\BusinessRuleMaatifyException;
use Maatify\Product\Exception\ProductExceptionInterface;

/**
 * Raised when an operation would leave an invalid Product configuration, or when a
 * precondition of the lifecycle contract fails: the target row exists but its state, or the
 * state of its owner chain, does not allow the requested operation.
 *
 * It is the package's business-rule failure (`FC-INV`). A missing identity is a not-found
 * failure instead, and an input that violates its own contract is an invalid-argument failure.
 */
final class ProductBusinessRuleException extends BusinessRuleMaatifyException implements ProductExceptionInterface
{
    /**
     * The row is soft deleted, and only an explicit restore may change it.
     */
    public static function softDeleted(string $resource, int $id): self
    {
        return new self(sprintf('%s [%d] is soft deleted; restore it before changing it.', $resource, $id));
    }

    /**
     * Restore was requested for a row that is not soft deleted.
     */
    public static function notSoftDeleted(string $resource, int $id): self
    {
        return new self(sprintf('%s [%d] is not soft deleted and cannot be restored.', $resource, $id));
    }

    /**
     * The owner of the row is soft deleted; the owner chain must be restored first.
     */
    public static function ownerSoftDeleted(string $resource, int $id, string $owner, int $ownerId): self
    {
        return new self(sprintf(
            '%s [%d] cannot be changed while its owner %s [%d] is soft deleted; restore the owner first.',
            $resource,
            $id,
            $owner,
            $ownerId,
        ));
    }

    /**
     * A child cannot be created under a soft-deleted owner.
     */
    public static function ownerSoftDeletedForCreate(string $resource, string $owner, int $ownerId): self
    {
        return new self(sprintf(
            'A %s cannot be created under %s [%d] while it is soft deleted; restore the owner first.',
            $resource,
            $owner,
            $ownerId,
        ));
    }

    /**
     * The operation would leave, or finds, a Product configuration that violates a structural invariant.
     */
    public static function invalidConfiguration(int $productId, string $reason): self
    {
        return new self(sprintf('Product [%d] configuration would be invalid: %s', $productId, $reason));
    }

    /**
     * A role-scoped Image Assignment needs an active, non-deleted Image Role.
     */
    public static function imageRoleNotActive(int $roleId): self
    {
        return new self(sprintf('Image Role [%d] must be active and not soft deleted for this operation.', $roleId));
    }
}
