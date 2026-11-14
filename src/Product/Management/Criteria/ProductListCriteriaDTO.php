<?php

declare(strict_types=1);

namespace Maatify\Product\Product\Management\Criteria;

use JsonSerializable;
use Maatify\Product\Common\Enum\ProductDeletedStateEnum;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;
use Maatify\Product\Product\Enum\ProductStatusEnum;

/**
 * Bounded criteria for the management Product list and its paginated read.
 *
 * A `null` status means any status, and the deleted-state filter defaults to non-deleted rows.
 * `maxResults` bounds the unpaginated list only; the paginated read takes its page size from the
 * page request. `search` is an optional substring of the Product `code`, matched literally (`%`,
 * `_`, `\`, and `!` are plain characters) under the database collation.
 */
final readonly class ProductListCriteriaDTO implements JsonSerializable
{
    public const int DEFAULT_MAX_RESULTS = 100;
    public const int MAX_MAX_RESULTS = 100;

    /** Maximum number of characters in a search term after trimming. */
    public const int MAX_SEARCH_LENGTH = 100;

    /** The search term with surrounding whitespace removed, or null when no search is requested. */
    public ?string $search;

    /**
     * @param string|null $search Trimmed of surrounding Unicode whitespace before use; then it must be valid UTF-8, not empty, and at most 100 characters.
     *
     * @throws ProductInvalidArgumentException When `maxResults` is outside 1 to 100 or the search term violates its contract.
     */
    public function __construct(
        public ?ProductStatusEnum $status = null,
        public ProductDeletedStateEnum $deletedState = ProductDeletedStateEnum::NON_DELETED,
        public int $maxResults = self::DEFAULT_MAX_RESULTS,
        ?string $search = null,
    ) {
        if ($maxResults < 1 || $maxResults > self::MAX_MAX_RESULTS) {
            throw ProductInvalidArgumentException::invalidListLimit($maxResults, self::MAX_MAX_RESULTS);
        }
        if ($search !== null) {
            if (!mb_check_encoding($search, 'UTF-8')) {
                throw ProductInvalidArgumentException::invalidEncoding('search');
            }
            $search = (string) preg_replace('/^[\s\p{Z}]+|[\s\p{Z}]+$/u', '', $search);
            if ($search === '') {
                throw ProductInvalidArgumentException::emptyField('search');
            }
            if (mb_strlen($search, 'UTF-8') > self::MAX_SEARCH_LENGTH) {
                throw ProductInvalidArgumentException::fieldTooLong('search', self::MAX_SEARCH_LENGTH);
            }
        }
        $this->search = $search;
    }

    /** @return array{search: ?string, status: ?string, deletedState: string, maxResults: int} */
    public function jsonSerialize(): array
    {
        return [
            'search' => $this->search,
            'status' => $this->status?->value,
            'deletedState' => $this->deletedState->value,
            'maxResults' => $this->maxResults,
        ];
    }
}
