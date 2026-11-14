<?php

declare(strict_types=1);

namespace Maatify\Product\Variant\ValueObject;

use JsonSerializable;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;

/**
 * One selected `(optionId, optionValueId)` pair of a Variant composition.
 *
 * It is the typed selection element of composition resolution and of Product reconfiguration;
 * no associative array stands in for it. Both identities must be positive integers.
 */
final readonly class OptionValueSelection implements JsonSerializable
{
    /**
     * @throws ProductInvalidArgumentException When an identity is not a positive integer.
     */
    public function __construct(
        public int $optionId,
        public int $optionValueId,
    ) {
        if ($optionId < 1) {
            throw ProductInvalidArgumentException::nonPositiveId('optionId');
        }
        if ($optionValueId < 1) {
            throw ProductInvalidArgumentException::nonPositiveId('optionValueId');
        }
    }

    /**
     * Sorts selections by numeric Option identity, the canonical composition order, and
     * rejects a repeated Option.
     *
     * @param list<self> $selections
     *
     * @return list<self>
     *
     * @throws ProductInvalidArgumentException When an Option appears more than once.
     */
    public static function canonicalize(array $selections, string $field = 'selections'): array
    {
        $byOption = [];
        foreach ($selections as $selection) {
            if (isset($byOption[$selection->optionId])) {
                throw ProductInvalidArgumentException::duplicateIdentity($field, $selection->optionId);
            }
            $byOption[$selection->optionId] = $selection;
        }
        ksort($byOption, SORT_NUMERIC);

        return array_values($byOption);
    }

    /** @return array{optionId: int, optionValueId: int} */
    public function jsonSerialize(): array
    {
        return ['optionId' => $this->optionId, 'optionValueId' => $this->optionValueId];
    }
}
