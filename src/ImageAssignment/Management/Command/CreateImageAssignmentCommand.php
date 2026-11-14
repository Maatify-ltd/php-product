<?php

declare(strict_types=1);

namespace Maatify\Product\ImageAssignment\Management\Command;

use JsonSerializable;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;
use Maatify\Product\Common\Validation\TextInputRules;

/**
 * Validated intent to assign one external Media Asset to a Product (`variantId` null) or to one
 * Variant of that Product, within an exact `(role, language, platform)` scope.
 *
 * `NULL` is an exact scope value, never a wildcard. The package never resolves or verifies the
 * Media Asset. A new assignment is never the default and takes no position; both come later from
 * the explicit default operation and the shared Ordering API.
 */
final readonly class CreateImageAssignmentCommand implements JsonSerializable
{
    public const int MAX_LANGUAGE_CODE_LENGTH = 16;
    public const int MAX_PLATFORM_LENGTH = 255;

    /** Host-owned Media Asset identity, as a positive integer. */
    public int $mediaAssetId;

    /**
     * @param int|string $mediaAssetId A positive `int`, or a string of digits without sign, whitespace, or leading
     *                                 zeroes that does not exceed `PHP_INT_MAX`; checked before the cast.
     * @param string|null $languageCode `NULL` is the unlocalized scope; otherwise non-empty, non-whitespace, at most 16 characters.
     * @param string|null $platform `NULL` is the exact `NULL` scope; otherwise non-empty, non-whitespace, at most 255 characters.
     *
     * @throws ProductInvalidArgumentException When a value violates its contract.
     */
    public function __construct(
        public int $productId,
        public ?int $variantId,
        int|string $mediaAssetId,
        public ?int $roleId = null,
        public ?string $languageCode = null,
        public ?string $platform = null,
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
        $this->mediaAssetId = self::mediaAssetId($mediaAssetId);
        TextInputRules::nullableRequiredText($languageCode, 'languageCode', self::MAX_LANGUAGE_CODE_LENGTH);
        TextInputRules::nullableRequiredText($platform, 'platform', self::MAX_PLATFORM_LENGTH);
    }

    private static function mediaAssetId(int|string $value): int
    {
        if (is_int($value)) {
            if ($value < 1) {
                throw ProductInvalidArgumentException::invalidIdentityRepresentation('mediaAssetId');
            }

            return $value;
        }
        if (preg_match('/^[1-9][0-9]*\z/', $value) !== 1) {
            throw ProductInvalidArgumentException::invalidIdentityRepresentation('mediaAssetId');
        }
        $max = (string) PHP_INT_MAX;
        if (strlen($value) > strlen($max) || (strlen($value) === strlen($max) && strcmp($value, $max) > 0)) {
            throw ProductInvalidArgumentException::invalidIdentityRepresentation('mediaAssetId');
        }

        return (int) $value;
    }

    /** @return array{productId: int, variantId: ?int, mediaAssetId: int, roleId: ?int, languageCode: ?string, platform: ?string} */
    public function jsonSerialize(): array
    {
        return [
            'productId' => $this->productId,
            'variantId' => $this->variantId,
            'mediaAssetId' => $this->mediaAssetId,
            'roleId' => $this->roleId,
            'languageCode' => $this->languageCode,
            'platform' => $this->platform,
        ];
    }
}
