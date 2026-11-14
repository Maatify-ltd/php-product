<?php

declare(strict_types=1);

namespace Maatify\Product\Content\Exception;

use Maatify\Exceptions\Exception\NotFound\ResourceNotFoundMaatifyException;
use Maatify\Product\Exception\ProductExceptionInterface;

/**
 * Raised when a Option Content identity does not exist, or does not match the deleted state of the read that asked.
 */
final class OptionContentNotFoundException extends ResourceNotFoundMaatifyException implements ProductExceptionInterface
{
    public static function withId(int $contentId): self
    {
        return new self(sprintf('Option Content [%d] was not found.', $contentId));
    }
}
