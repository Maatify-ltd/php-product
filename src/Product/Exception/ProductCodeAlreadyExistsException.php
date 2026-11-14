<?php

declare(strict_types=1);

namespace Maatify\Product\Product\Exception;

use Maatify\Exceptions\Exception\Conflict\GenericConflictMaatifyException;
use Maatify\Product\Exception\ProductExceptionInterface;
use Throwable;

/**
 * Raised when a Product code is already taken, including by a soft-deleted Product.
 *
 * A code stays reserved after soft deletion, so re-using it is always a conflict.
 */
final class ProductCodeAlreadyExistsException extends GenericConflictMaatifyException implements ProductExceptionInterface
{
    public static function withCode(string $code, ?Throwable $previous = null): self
    {
        return new self(sprintf('Product code "%s" already exists.', $code), 0, $previous);
    }
}
