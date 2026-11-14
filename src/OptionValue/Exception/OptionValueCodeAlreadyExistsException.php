<?php

declare(strict_types=1);

namespace Maatify\Product\OptionValue\Exception;

use Maatify\Exceptions\Exception\Conflict\GenericConflictMaatifyException;
use Maatify\Product\Exception\ProductExceptionInterface;
use Throwable;

/**
 * Raised when an Option Value code is already taken within its Option, including by a soft-deleted Value.
 */
final class OptionValueCodeAlreadyExistsException extends GenericConflictMaatifyException implements ProductExceptionInterface
{
    public static function withCode(int $optionId, string $code, ?Throwable $previous = null): self
    {
        return new self(sprintf('Option Value code "%s" already exists in Option [%d].', $code, $optionId), 0, $previous);
    }
}
