<?php

declare(strict_types=1);

namespace Maatify\Product\Content\Management\Criteria;

use JsonSerializable;
use Maatify\Product\Common\Enum\ProductDeletedStateEnum;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;
use Maatify\Product\Content\ValueObject\ContentLanguageFilter;

/**
 * Criteria of the management Product Content reads: the owner, an explicit language selector,
 * and the soft-deletion state (non-deleted by default). `maxResults` bounds the unpaginated list
 * only; the paginated read takes its page size from the page request instead.
 */
final readonly class ProductContentListCriteriaDTO implements JsonSerializable
{
    public const int DEFAULT_MAX_RESULTS = 100;
    public const int MAX_MAX_RESULTS = 100;

    /**
     * @throws ProductInvalidArgumentException When the owner identity is not positive or `maxResults` is outside 1 to 100.
     */
    public function __construct(
        public int $productId,
        public ContentLanguageFilter $language,
        public ProductDeletedStateEnum $deletedState = ProductDeletedStateEnum::NON_DELETED,
        public int $maxResults = self::DEFAULT_MAX_RESULTS,
    ) {
        if ($productId < 1) {
            throw ProductInvalidArgumentException::nonPositiveId('productId');
        }
        if ($maxResults < 1 || $maxResults > self::MAX_MAX_RESULTS) {
            throw ProductInvalidArgumentException::invalidListLimit($maxResults, self::MAX_MAX_RESULTS);
        }
    }

    /** @return array{productId: int, language: array{mode: string, languageCode: ?string}, deletedState: string, maxResults: int} */
    public function jsonSerialize(): array
    {
        return [
            'productId' => $this->productId,
            'language' => $this->language->jsonSerialize(),
            'deletedState' => $this->deletedState->value,
            'maxResults' => $this->maxResults,
        ];
    }
}
