<?php

declare(strict_types=1);

namespace Maatify\Product\ImageAssignment\ValueObject;

use JsonSerializable;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;

/**
 * Target selector of the management Image Assignment list, in exactly one of three states: any
 * target of the Product (the Product level and every Variant), the Product level only
 * (`variant_id IS NULL`), or one Variant only.
 */
final readonly class ImageAssignmentTargetFilter implements JsonSerializable
{
    private const string ANY = 'any_target';
    private const string PRODUCT = 'product_level';
    private const string VARIANT = 'variant';

    private function __construct(
        private string $mode,
        private ?int $variantId,
    ) {}

    public static function anyTarget(): self
    {
        return new self(self::ANY, null);
    }

    public static function productLevel(): self
    {
        return new self(self::PRODUCT, null);
    }

    /**
     * @throws ProductInvalidArgumentException When the Variant identity is not positive.
     */
    public static function variant(int $variantId): self
    {
        if ($variantId < 1) {
            throw ProductInvalidArgumentException::nonPositiveId('variantId');
        }

        return new self(self::VARIANT, $variantId);
    }

    public function isAnyTarget(): bool
    {
        return $this->mode === self::ANY;
    }

    public function isProductLevel(): bool
    {
        return $this->mode === self::PRODUCT;
    }

    /**
     * The selected Variant in the Variant state; `null` in the two other states.
     */
    public function variantId(): ?int
    {
        return $this->variantId;
    }

    /** @return array{target: string, variantId: ?int} */
    public function jsonSerialize(): array
    {
        return ['target' => $this->mode, 'variantId' => $this->variantId];
    }
}
