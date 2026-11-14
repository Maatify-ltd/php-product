<?php

declare(strict_types=1);

namespace Maatify\Product\ImageAssignment\ValueObject;

use JsonSerializable;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;

/**
 * Role selector of the management Image Assignment list, in exactly one of three states: omitted
 * (no condition), exactly `NULL` (`role_id IS NULL`), or one Role by identity. `NULL` is an exact
 * value, never a wildcard, and omitting the filter is a different state.
 */
final readonly class ImageAssignmentRoleFilter implements JsonSerializable
{
    private const string OMITTED = 'omitted';
    private const string EXACT_NULL = 'exact_null';
    private const string ROLE = 'role';

    private function __construct(
        private string $mode,
        private ?int $roleId,
    ) {}

    public static function omitted(): self
    {
        return new self(self::OMITTED, null);
    }

    public static function exactNull(): self
    {
        return new self(self::EXACT_NULL, null);
    }

    /**
     * @throws ProductInvalidArgumentException When the Role identity is not positive.
     */
    public static function forRole(int $roleId): self
    {
        if ($roleId < 1) {
            throw ProductInvalidArgumentException::nonPositiveId('roleId');
        }

        return new self(self::ROLE, $roleId);
    }

    public function isOmitted(): bool
    {
        return $this->mode === self::OMITTED;
    }

    public function isExactNull(): bool
    {
        return $this->mode === self::EXACT_NULL;
    }

    /**
     * The selected Role in the Role state; `null` in the two other states.
     */
    public function roleId(): ?int
    {
        return $this->roleId;
    }

    /** @return array{role: string, roleId: ?int} */
    public function jsonSerialize(): array
    {
        return ['role' => $this->mode, 'roleId' => $this->roleId];
    }
}
