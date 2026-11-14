<?php

declare(strict_types=1);

namespace Maatify\Product\ImageRole\Exception;

use Maatify\Exceptions\Exception\Conflict\GenericConflictMaatifyException;
use Maatify\Product\Exception\ProductExceptionInterface;
use Throwable;

/**
 * Raised when an Image Role key is already taken, including by a soft-deleted Role.
 */
final class ImageRoleKeyAlreadyExistsException extends GenericConflictMaatifyException implements ProductExceptionInterface
{
    public static function withKey(string $roleKey, ?Throwable $previous = null): self
    {
        return new self(sprintf('Image Role key "%s" already exists.', $roleKey), 0, $previous);
    }
}
