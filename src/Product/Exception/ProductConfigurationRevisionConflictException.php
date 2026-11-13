<?php

declare(strict_types=1);

namespace Maatify\Product\Product\Exception;

use Maatify\Exceptions\Exception\Conflict\GenericConflictMaatifyException;
use Maatify\Product\Exception\ProductExceptionInterface;

/** The Product configuration changed after the editor loaded it. */
final class ProductConfigurationRevisionConflictException extends GenericConflictMaatifyException implements ProductExceptionInterface
{
    public static function forProduct(int $productId): self
    {
        return new self(sprintf('Product [%d] configuration changed. Reload it before reconfiguring.', $productId));
    }
}
