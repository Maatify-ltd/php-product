<?php

declare(strict_types=1);

namespace Maatify\Product\Variant\Exception;

use Maatify\Exceptions\Exception\NotFound\ResourceNotFoundMaatifyException;
use Maatify\Product\Exception\ProductExceptionInterface;

/**
 * Raised when a Variant identity does not exist or is not visible to the read that asked, and
 * when a structurally valid composition matches no consumer-visible Variant.
 */
final class VariantNotFoundException extends ResourceNotFoundMaatifyException implements ProductExceptionInterface
{
    public static function withId(int $variantId): self
    {
        return new self(sprintf('Variant [%d] was not found.', $variantId));
    }

    /** No Variant with this sku matches the requested management read. */
    public static function withSku(string $sku): self
    {
        return new self(sprintf('Variant with sku "%s" was not found.', $sku));
    }

    /** No Variant with this barcode matches the requested management read. */
    public static function withBarcode(string $barcode): self
    {
        return new self(sprintf('Variant with barcode "%s" was not found.', $barcode));
    }

    /**
     * @param string $canonical The canonical composition string of the selection.
     */
    public static function forComposition(int $productId, string $canonical): self
    {
        return new self(sprintf('No visible Variant of Product [%d] matches the composition "%s".', $productId, $canonical));
    }
}
