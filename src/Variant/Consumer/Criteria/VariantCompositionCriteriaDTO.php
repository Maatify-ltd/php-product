<?php

declare(strict_types=1);

namespace Maatify\Product\Variant\Consumer\Criteria;

use JsonSerializable;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;
use Maatify\Product\Variant\ValueObject\OptionValueSelection;

/**
 * Typed selection input of Variant composition resolution: the shopper's `(optionId, optionValueId)`
 * choices for one Product.
 *
 * The order of the pairs does not matter; they are canonicalized by numeric Option identity.
 * An empty selection is valid input and resolves a simple Product's Variant.
 */
final readonly class VariantCompositionCriteriaDTO implements JsonSerializable
{
    /** @var list<OptionValueSelection> Canonical order, by numeric Option identity. */
    public array $selections;

    /**
     * @param list<OptionValueSelection> $selections
     *
     * @throws ProductInvalidArgumentException When an Option appears more than once.
     */
    public function __construct(array $selections = [])
    {
        $this->selections = OptionValueSelection::canonicalize($selections, 'selections');
    }

    /** @return array{selections: list<array{optionId: int, optionValueId: int}>} */
    public function jsonSerialize(): array
    {
        return [
            'selections' => array_map(static fn(OptionValueSelection $selection): array => $selection->jsonSerialize(), $this->selections),
        ];
    }
}
