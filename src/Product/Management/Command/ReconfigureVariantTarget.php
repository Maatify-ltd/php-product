<?php

declare(strict_types=1);

namespace Maatify\Product\Product\Management\Command;

use JsonSerializable;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;
use Maatify\Product\Variant\ValueObject\CompositionFingerprint;
use Maatify\Product\Variant\ValueObject\OptionValueSelection;

/**
 * One Variant of the target active set of a Product reconfiguration: its immutable composition
 * and, optionally, the identity of the existing Variant (active, inactive, or soft-deleted)
 * that already carries that composition and must be reused instead of creating a new identity.
 *
 * An empty composition is the simple Product's Variant; a configurable target never contains it.
 */
final readonly class ReconfigureVariantTarget implements JsonSerializable
{
    /** @var list<OptionValueSelection> Canonical order, by numeric Option identity. */
    public array $composition;

    /**
     * @param list<OptionValueSelection> $composition The `(optionId, optionValueId)` pairs, in any order.
     * @param int|null $reuseVariantId The existing Variant to reuse for this composition, when it exists in the Product's history.
     *
     * @throws ProductInvalidArgumentException When an Option repeats in the composition, or the reuse identity is not positive.
     */
    public function __construct(
        array $composition,
        public ?int $reuseVariantId = null,
    ) {
        $this->composition = OptionValueSelection::canonicalize($composition, 'composition');
        if ($reuseVariantId !== null && $reuseVariantId < 1) {
            throw ProductInvalidArgumentException::nonPositiveId('reuseVariantId');
        }
    }

    /**
     * The canonical fingerprint of the composition.
     */
    public function fingerprint(): CompositionFingerprint
    {
        return CompositionFingerprint::forSelections($this->composition);
    }

    /** @return array{composition: list<array{optionId: int, optionValueId: int}>, reuseVariantId: ?int} */
    public function jsonSerialize(): array
    {
        return [
            'composition' => array_map(static fn(OptionValueSelection $pair): array => $pair->jsonSerialize(), $this->composition),
            'reuseVariantId' => $this->reuseVariantId,
        ];
    }
}
