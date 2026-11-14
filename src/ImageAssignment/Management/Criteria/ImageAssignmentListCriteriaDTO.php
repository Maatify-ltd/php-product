<?php

declare(strict_types=1);

namespace Maatify\Product\ImageAssignment\Management\Criteria;

use JsonSerializable;
use Maatify\Product\Common\Enum\ProductDeletedStateEnum;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;
use Maatify\Product\ImageAssignment\ValueObject\ImageAssignmentLanguageFilter;
use Maatify\Product\ImageAssignment\ValueObject\ImageAssignmentPlatformFilter;
use Maatify\Product\ImageAssignment\ValueObject\ImageAssignmentRoleFilter;
use Maatify\Product\ImageAssignment\ValueObject\ImageAssignmentTargetFilter;

/**
 * Criteria of the management Image Assignment list and its paginated read: the Product, a target,
 * role, language, and platform selector each, and the soft-deletion state. Each dimension is matched
 * exactly; `NULL` is an exact value, and an omitted selector applies no condition. An omitted
 * constructor argument (`null`) means the selector's default: any target, and omitted role,
 * language, and platform. `maxResults` bounds the unpaginated list only.
 */
final readonly class ImageAssignmentListCriteriaDTO implements JsonSerializable
{
    public const int DEFAULT_MAX_RESULTS = 100;
    public const int MAX_MAX_RESULTS = 100;

    public ImageAssignmentTargetFilter $target;
    public ImageAssignmentRoleFilter $role;
    public ImageAssignmentLanguageFilter $language;
    public ImageAssignmentPlatformFilter $platform;

    /**
     * @throws ProductInvalidArgumentException When the Product identity is not positive or `maxResults` is outside 1 to 100.
     */
    public function __construct(
        public int $productId,
        ?ImageAssignmentTargetFilter $target = null,
        ?ImageAssignmentRoleFilter $role = null,
        ?ImageAssignmentLanguageFilter $language = null,
        ?ImageAssignmentPlatformFilter $platform = null,
        public ProductDeletedStateEnum $deletedState = ProductDeletedStateEnum::NON_DELETED,
        public int $maxResults = self::DEFAULT_MAX_RESULTS,
    ) {
        if ($productId < 1) {
            throw ProductInvalidArgumentException::nonPositiveId('productId');
        }
        if ($maxResults < 1 || $maxResults > self::MAX_MAX_RESULTS) {
            throw ProductInvalidArgumentException::invalidListLimit($maxResults, self::MAX_MAX_RESULTS);
        }
        $this->target = $target ?? ImageAssignmentTargetFilter::anyTarget();
        $this->role = $role ?? ImageAssignmentRoleFilter::omitted();
        $this->language = $language ?? ImageAssignmentLanguageFilter::omitted();
        $this->platform = $platform ?? ImageAssignmentPlatformFilter::omitted();
    }

    /** @return array{target: array{target: string, variantId: ?int}, role: array{role: string, roleId: ?int}, language: array{languageCode: string, value: ?string}, platform: array{platform: string, value: ?string}, productId: int, deletedState: string, maxResults: int} */
    public function jsonSerialize(): array
    {
        return [
            'target' => $this->target->jsonSerialize(),
            'role' => $this->role->jsonSerialize(),
            'language' => $this->language->jsonSerialize(),
            'platform' => $this->platform->jsonSerialize(),
            'productId' => $this->productId,
            'deletedState' => $this->deletedState->value,
            'maxResults' => $this->maxResults,
        ];
    }
}
