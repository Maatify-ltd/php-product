<?php

declare(strict_types=1);

namespace Maatify\Product\OptionValue\Consumer\Criteria;

use JsonSerializable;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;

/**
 * Bounded criteria for the consumer Option Value list.
 */
final readonly class OptionValueVisibleListCriteriaDTO implements JsonSerializable
{
    public const int DEFAULT_MAX_RESULTS = 100;
    public const int MAX_MAX_RESULTS = 100;

    /**
     * @throws ProductInvalidArgumentException When `maxResults` is outside 1 to 100.
     */
    public function __construct(public int $maxResults = self::DEFAULT_MAX_RESULTS)
    {
        if ($maxResults < 1 || $maxResults > self::MAX_MAX_RESULTS) {
            throw ProductInvalidArgumentException::invalidListLimit($maxResults, self::MAX_MAX_RESULTS);
        }
    }

    /** @return array{maxResults: int} */
    public function jsonSerialize(): array
    {
        return [
            'maxResults' => $this->maxResults,
        ];
    }
}
