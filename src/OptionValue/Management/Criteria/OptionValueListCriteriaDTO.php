<?php

declare(strict_types=1);

namespace Maatify\Product\OptionValue\Management\Criteria;

use JsonSerializable;
use Maatify\Product\Common\Enum\ProductDeletedStateEnum;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;
use Maatify\Product\OptionValue\Enum\OptionValueStatusEnum;

/**
 * Bounded criteria for the management Option Value list and its paginated read.
 *
 * A `null` status means any status. The deleted-state filter defaults to non-deleted rows.
 * `maxResults` bounds the unpaginated list only; the paginated read takes its page size from the page request.
 */
final readonly class OptionValueListCriteriaDTO implements JsonSerializable
{
    public const int DEFAULT_MAX_RESULTS = 100;
    public const int MAX_MAX_RESULTS = 100;

    /**
     * @throws ProductInvalidArgumentException When `maxResults` is outside 1 to 100.
     */
    public function __construct(
        public ?OptionValueStatusEnum $status = null,
        public ProductDeletedStateEnum $deletedState = ProductDeletedStateEnum::NON_DELETED,
        public int $maxResults = self::DEFAULT_MAX_RESULTS,
    ) {
        if ($maxResults < 1 || $maxResults > self::MAX_MAX_RESULTS) {
            throw ProductInvalidArgumentException::invalidListLimit($maxResults, self::MAX_MAX_RESULTS);
        }
    }

    /** @return array{status: ?string, deletedState: string, maxResults: int} */
    public function jsonSerialize(): array
    {
        return [
            'status' => $this->status?->value,
            'deletedState' => $this->deletedState->value,
            'maxResults' => $this->maxResults,
        ];
    }
}
