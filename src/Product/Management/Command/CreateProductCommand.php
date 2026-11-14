<?php

declare(strict_types=1);

namespace Maatify\Product\Product\Management\Command;

use JsonSerializable;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;

/**
 * Validated intent to create a Product.
 *
 * A new Product is always created inactive and receives its single
 * empty-composition Variant in the same operation, so the command carries only
 * the stable business key.
 */
final readonly class CreateProductCommand implements JsonSerializable
{
    /** Maximum number of characters in a Product code. */
    public const int MAX_CODE_LENGTH = 100;

    /**
     * Stable, immutable Product business key, stored exactly as supplied.
     */
    public string $code;

    /**
     * @param string $code Required, valid UTF-8, at most {@see self::MAX_CODE_LENGTH}
     *                     characters, and not empty or whitespace only. It is never trimmed
     *                     or otherwise rewritten. Uniqueness is compared by the database
     *                     collation (case-insensitive) across all history.
     *
     * @throws ProductInvalidArgumentException When the code violates the contract above.
     */
    public function __construct(string $code)
    {
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

    /** @return array{code: string} */
    public function jsonSerialize(): array
    {
        return ['code' => $this->code];
    }
}
