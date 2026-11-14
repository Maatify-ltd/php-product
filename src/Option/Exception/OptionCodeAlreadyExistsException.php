<?php

declare(strict_types=1);

namespace Maatify\Product\Option\Exception;

use Maatify\Exceptions\Exception\Conflict\GenericConflictMaatifyException;
use Maatify\Product\Exception\ProductExceptionInterface;
use Throwable;

/**
 * Raised when an Option code is already taken within its Product, including by a soft-deleted Option.
 */
final class OptionCodeAlreadyExistsException extends GenericConflictMaatifyException implements ProductExceptionInterface
{
    public static function withCode(int $productId, string $code, ?Throwable $previous = null): self
    {
        return new self(sprintf('Option code "%s" already exists in Product [%d].', $code, $productId), 0, $previous);
    }
}
