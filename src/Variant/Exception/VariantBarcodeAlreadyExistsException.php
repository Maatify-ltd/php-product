<?php

declare(strict_types=1);

namespace Maatify\Product\Variant\Exception;

use Maatify\Exceptions\Exception\Conflict\GenericConflictMaatifyException;
use Maatify\Product\Exception\ProductExceptionInterface;
use Throwable;

/**
 * Raised when a Variant barcode is already assigned to another Variant under the database collation,
 * including a soft-deleted Variant, which keeps its values reserved.
 */
final class VariantBarcodeAlreadyExistsException extends GenericConflictMaatifyException implements ProductExceptionInterface
{
    public static function withBarcode(string $barcode, ?Throwable $previous = null): self
    {
        return new self(sprintf('Variant barcode "%s" is already assigned to another Variant.', $barcode), 0, $previous);
    }
}
