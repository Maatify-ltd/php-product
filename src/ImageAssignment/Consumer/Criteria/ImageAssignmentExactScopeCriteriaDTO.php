<?php

declare(strict_types=1);

namespace Maatify\Product\ImageAssignment\Consumer\Criteria;

use JsonSerializable;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;
use Maatify\Product\Common\Validation\TextInputRules;
use Maatify\Product\ImageAssignment\Management\Command\CreateImageAssignmentCommand;

/**
 * The exact scope of the consumer Image Assignment read: the Product, the target (`variantId` null
 * is the Product level, otherwise one Variant of that Product), and the role, language, and platform.
 * Every dimension is matched exactly: `null` means exactly `NULL`, never "any"; there is no omitted
 * dimension and no inheritance between the Product and Variant levels.
 */
final readonly class ImageAssignmentExactScopeCriteriaDTO implements JsonSerializable
{
    public const int DEFAULT_MAX_RESULTS = 100;
    public const int MAX_MAX_RESULTS = 100;

    /**
     * @param string|null $languageCode `null` is exactly `NULL`; otherwise non-empty, not whitespace only, at most 16 characters.
     * @param string|null $platform `null` is exactly `NULL`; otherwise non-empty, not whitespace only, at most 255 characters.
     *
     * @throws ProductInvalidArgumentException When an identity is not positive, a scope string violates its rule,
     *                                         or `maxResults` is outside 1 to 100.
     */
    public function __construct(
        public int $productId,
        public ?int $variantId,
        public ?int $roleId,
        public ?string $languageCode,
        public ?string $platform,
        public int $maxResults = self::DEFAULT_MAX_RESULTS,
    ) {
        if ($productId < 1) {
            throw ProductInvalidArgumentException::nonPositiveId('productId');
        }
        if ($variantId !== null && $variantId < 1) {
            throw ProductInvalidArgumentException::nonPositiveId('variantId');
        }
        if ($roleId !== null && $roleId < 1) {
            throw ProductInvalidArgumentException::nonPositiveId('roleId');
        }
        TextInputRules::nullableRequiredText($languageCode, 'languageCode', CreateImageAssignmentCommand::MAX_LANGUAGE_CODE_LENGTH);
        TextInputRules::nullableRequiredText($platform, 'platform', CreateImageAssignmentCommand::MAX_PLATFORM_LENGTH);
        if ($maxResults < 1 || $maxResults > self::MAX_MAX_RESULTS) {
            throw ProductInvalidArgumentException::invalidListLimit($maxResults, self::MAX_MAX_RESULTS);
        }
    }

    /** @return array{productId: int, variantId: ?int, roleId: ?int, languageCode: ?string, platform: ?string, maxResults: int} */
    public function jsonSerialize(): array
    {
        return [
            'productId' => $this->productId,
            'variantId' => $this->variantId,
            'roleId' => $this->roleId,
            'languageCode' => $this->languageCode,
            'platform' => $this->platform,
            'maxResults' => $this->maxResults,
        ];
    }
}
