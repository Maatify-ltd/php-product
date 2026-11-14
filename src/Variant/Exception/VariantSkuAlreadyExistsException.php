<?php

declare(strict_types=1);

namespace Maatify\Product\Variant\Exception;

use Maatify\Exceptions\Exception\Conflict\GenericConflictMaatifyException;
use Maatify\Product\Exception\ProductExceptionInterface;
use Throwable;

/**
 * Raised when a Variant SKU is already assigned to another Variant under the database collation,
 * including a soft-deleted Variant, which keeps its values reserved.
 */
final class VariantSkuAlreadyExistsException extends GenericConflictMaatifyException implements ProductExceptionInterface
{
    public static function withSku(string $sku, ?Throwable $previous = null): self
    {
        return new self(sprintf('Variant SKU "%s" is already assigned to another Variant.', $sku), 0, $previous);
    }
}
