<?php

declare(strict_types=1);

namespace Maatify\Product\Product\Management\Command;

use JsonSerializable;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;

/**
 * One Option of the target active configuration of a Product reconfiguration, with the
 * complete set of its Values that must be active afterwards.
 *
 * Every other non-deleted Value of the Option becomes inactive. The Option and its Values
 * must already exist under the Product; reconfiguration never creates them.
 */
final readonly class ReconfigureOptionTarget implements JsonSerializable
{
    /** @var non-empty-list<int> Distinct, ascending Option Value identities. */
    public array $activeOptionValueIds;

    /**
     * @param list<int> $activeOptionValueIds At least one positive identity, none repeated.
     *
     * @throws ProductInvalidArgumentException When an identity is not positive, the list is empty, or a Value repeats.
     */
    public function __construct(
        public int $optionId,
        array $activeOptionValueIds,
    ) {
        if ($optionId < 1) {
            throw ProductInvalidArgumentException::nonPositiveId('optionId');
        }
        if ($activeOptionValueIds === []) {
            throw ProductInvalidArgumentException::emptyCollection('activeOptionValueIds');
        }
        $distinct = [];
        foreach ($activeOptionValueIds as $valueId) {
            if ($valueId < 1) {
                throw ProductInvalidArgumentException::nonPositiveId('activeOptionValueIds');
            }
            if (isset($distinct[$valueId])) {
                throw ProductInvalidArgumentException::duplicateIdentity('activeOptionValueIds', $valueId);
            }
            $distinct[$valueId] = true;
        }
        ksort($distinct, SORT_NUMERIC);
        /** @var non-empty-list<int> $ids */
        $ids = array_keys($distinct);
        $this->activeOptionValueIds = $ids;
    }

    /** @return array{optionId: int, activeOptionValueIds: list<int>} */
    public function jsonSerialize(): array
    {
        return ['optionId' => $this->optionId, 'activeOptionValueIds' => $this->activeOptionValueIds];
    }
}
