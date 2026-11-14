<?php

declare(strict_types=1);

namespace Maatify\Product\OptionValue\Exception;

use Maatify\Exceptions\Exception\NotFound\ResourceNotFoundMaatifyException;
use Maatify\Product\Exception\ProductExceptionInterface;

/**
 * Raised when an Option Value identity does not exist, or does not match the deleted state of the read that asked.
 */
final class OptionValueNotFoundException extends ResourceNotFoundMaatifyException implements ProductExceptionInterface
{
    public static function withId(int $optionValueId): self
    {
        return new self(sprintf('Option Value [%d] was not found.', $optionValueId));
    }
}
