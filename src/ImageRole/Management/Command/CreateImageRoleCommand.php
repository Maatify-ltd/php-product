<?php

declare(strict_types=1);

namespace Maatify\Product\ImageRole\Management\Command;

use JsonSerializable;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;

/**
 * Validated intent to create a Product Image Role. A new Role is created active. The Host
 * defines what a role means; the package ships no seeded roles.
 */
final readonly class CreateImageRoleCommand implements JsonSerializable
{
    /** Maximum number of characters in a role key. */
    public const int MAX_ROLE_KEY_LENGTH = 100;

    /**
     * Immutable role key, stored exactly as supplied.
     */
    public string $roleKey;

    /**
     * @param string $roleKey Required, valid UTF-8, at most {@see self::MAX_ROLE_KEY_LENGTH} characters, and not empty
     *                        or whitespace only. Never trimmed. Unique under the database collation
     *                        (case-insensitive) across all history.
     *
     * @throws ProductInvalidArgumentException When the key violates the contract.
     */
    public function __construct(string $roleKey)
    {
        if (!mb_check_encoding($roleKey, 'UTF-8')) {
            throw ProductInvalidArgumentException::invalidEncoding('roleKey');
        }
        if (preg_match('/^[\s\p{Z}]*$/u', $roleKey) === 1) {
            throw ProductInvalidArgumentException::emptyField('roleKey');
        }
        if (mb_strlen($roleKey, 'UTF-8') > self::MAX_ROLE_KEY_LENGTH) {
            throw ProductInvalidArgumentException::fieldTooLong('roleKey', self::MAX_ROLE_KEY_LENGTH);
        }
        $this->roleKey = $roleKey;
    }

    /** @return array{roleKey: string} */
    public function jsonSerialize(): array
    {
        return ['roleKey' => $this->roleKey];
    }
}
