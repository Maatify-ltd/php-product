<?php

declare(strict_types=1);

namespace Maatify\Product\Option\Management\Command;

use JsonSerializable;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;

/**
 * Validated intent to create an Option under a Product.
 *
 * A new Option is always created inactive, so its code and labels can be prepared without
 * changing the Product's active configuration; reconfiguration activates it.
 */
final readonly class CreateOptionCommand implements JsonSerializable
{
    /** Maximum number of characters in an Option code. */
    public const int MAX_CODE_LENGTH = 100;

    /**
     * Stable, immutable Option business key, stored exactly as supplied.
     */
    public string $code;

    /**
     * @param int $productId The owning Product.
     * @param string $code Required, valid UTF-8, at most {@see self::MAX_CODE_LENGTH} characters, and not empty
     *                     or whitespace only. Never trimmed. Unique within the Product under the database
     *                     collation (case-insensitive) across all history.
     *
     * @throws ProductInvalidArgumentException When the identity is not positive or the code violates the contract.
     */
    public function __construct(
        public int $productId,
        string $code,
    ) {
        if ($productId < 1) {
            throw ProductInvalidArgumentException::nonPositiveId('productId');
        }
        if (!mb_check_encoding($code, 'UTF-8')) {
            throw ProductInvalidArgumentException::invalidEncoding('code');
        }
        if (preg_match('/^[\s\p{Z}]*$/u', $code) === 1) {
            throw ProductInvalidArgumentException::emptyField('code');
        }
        if (mb_strlen($code, 'UTF-8') > self::MAX_CODE_LENGTH) {
            throw ProductInvalidArgumentException::fieldTooLong('code', self::MAX_CODE_LENGTH);
        }
        $this->code = $code;
    }

    /** @return array{productId: int, code: string} */
    public function jsonSerialize(): array
    {
        return ['productId' => $this->productId, 'code' => $this->code];
    }
}
