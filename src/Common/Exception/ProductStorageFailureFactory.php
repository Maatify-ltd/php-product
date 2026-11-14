<?php

declare(strict_types=1);

namespace Maatify\Product\Common\Exception;

use Throwable;

/**
 * Builds the package's {@see ProductPersistenceException} for its own storage adapters.
 *
 * Internal: this class is not part of the Public Runtime API. Consumers depend on the
 * exception type and on the {@see \Maatify\Product\Exception\ProductExceptionInterface}
 * marker only; the exception itself exposes no named constructors.
 *
 * @internal
 */
final class ProductStorageFailureFactory
{
    private function __construct() {}

    /**
     * AUTO_INCREMENT did not yield a positive identity for the named resource.
     */
    public static function invalidAutoIncrementIdentity(string $resource): ProductPersistenceException
    {
        return new ProductPersistenceException(
            sprintf('Product %s AUTO_INCREMENT did not return a valid identity.', $resource),
        );
    }

    /**
     * A stored column holds a value the package cannot interpret.
     *
     * @param Throwable|null $previous The failure that rejected the value, when one was raised.
     */
    public static function invalidStorageValue(string $column, ?Throwable $previous = null): ProductPersistenceException
    {
        return new ProductPersistenceException(
            sprintf('Product storage column "%s" contains an invalid value.', $column),
            previous: $previous,
        );
    }

    /**
     * A stored column was returned with an unexpected PHP type.
     */
    public static function unexpectedColumnType(string $column): ProductPersistenceException
    {
        return new ProductPersistenceException(
            sprintf('Product storage column "%s" has an unexpected type.', $column),
        );
    }

    /**
     * A stored composition fingerprint does not match the Variant's actual composition pairs.
     */
    public static function compositionFingerprintMismatch(int $variantId): ProductPersistenceException
    {
        return new ProductPersistenceException(
            sprintf('Variant [%d] stores a composition fingerprint that does not match its composition pairs.', $variantId),
        );
    }

    /**
     * The original empty-composition Variant a simple Product must retain is missing from storage.
     */
    public static function missingEmptyCompositionVariant(int $productId): ProductPersistenceException
    {
        return new ProductPersistenceException(
            sprintf('Product [%d] has no empty-composition Variant in its history; the transition to a simple Product cannot reuse the original identity.', $productId),
        );
    }

    /**
     * A write that the package expected to affect exactly one locked row affected none.
     */
    public static function lockedRowVanished(string $resource, int $id): ProductPersistenceException
    {
        return new ProductPersistenceException(
            sprintf('Product storage did not update the locked %s [%d].', $resource, $id),
        );
    }
}
