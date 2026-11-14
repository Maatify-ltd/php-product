<?php

declare(strict_types=1);

namespace Maatify\Product\Option\Exception;

use Maatify\Exceptions\Exception\NotFound\ResourceNotFoundMaatifyException;
use Maatify\Product\Exception\ProductExceptionInterface;

/**
 * Raised when an Option identity does not exist, or does not match the deleted state of the read that asked.
 */
final class OptionNotFoundException extends ResourceNotFoundMaatifyException implements ProductExceptionInterface
{
    public static function withId(int $optionId): self
    {
        return new self(sprintf('Option [%d] was not found.', $optionId));
    }
}
