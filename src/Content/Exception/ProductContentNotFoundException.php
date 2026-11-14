<?php

declare(strict_types=1);

namespace Maatify\Product\Content\Exception;

use Maatify\Exceptions\Exception\NotFound\ResourceNotFoundMaatifyException;
use Maatify\Product\Exception\ProductExceptionInterface;

/**
 * Raised when a Product Content identity does not exist, or does not match the deleted state of the read that asked.
 */
final class ProductContentNotFoundException extends ResourceNotFoundMaatifyException implements ProductExceptionInterface
{
    public static function withId(int $contentId): self
    {
        return new self(sprintf('Product Content [%d] was not found.', $contentId));
    }
}
