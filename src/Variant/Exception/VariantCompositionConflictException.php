<?php

declare(strict_types=1);

namespace Maatify\Product\Variant\Exception;

use Maatify\Exceptions\Exception\Conflict\GenericConflictMaatifyException;
use Maatify\Product\Exception\ProductExceptionInterface;

/**
 * Raised by Product reconfiguration when a requested composition collides with a Variant
 * identity: the composition already exists in the Product's history and the command did not
 * name that Variant for reuse, or the named Variant does not match the composition or the Product.
 *
 * The message identifies the existing Variant, so the Host can decide whether to reuse it.
 */
final class VariantCompositionConflictException extends GenericConflictMaatifyException implements ProductExceptionInterface
{
    /**
     * @param int $existingVariantId The Variant that already carries the composition.
     */
    public static function compositionExists(int $existingVariantId, string $canonical): self
    {
        return new self(sprintf(
            'Composition "%s" already exists as Variant [%d]; name it with reuseVariantId to reuse that identity.',
            $canonical,
            $existingVariantId,
        ));
    }

    /**
     * The Variant named for reuse does not carry the composition that names it.
     */
    public static function reuseCompositionMismatch(int $reuseVariantId, string $canonical): self
    {
        return new self(sprintf(
            'Variant [%d] cannot be reused for composition "%s": its stored composition differs.',
            $reuseVariantId,
            $canonical,
        ));
    }

    /**
     * The composition exists under another Variant than the one named for reuse.
     */
    public static function reuseIdentityMismatch(int $reuseVariantId, int $existingVariantId): self
    {
        return new self(sprintf(
            'Variant [%d] cannot be reused: the composition already belongs to Variant [%d].',
            $reuseVariantId,
            $existingVariantId,
        ));
    }

    /**
     * The Variant named for reuse belongs to another Product.
     */
    public static function reuseVariantOfAnotherProduct(int $reuseVariantId, int $productId): self
    {
        return new self(sprintf('Variant [%d] cannot be reused: it does not belong to Product [%d].', $reuseVariantId, $productId));
    }
}
