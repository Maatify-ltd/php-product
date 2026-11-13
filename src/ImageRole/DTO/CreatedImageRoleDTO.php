<?php

declare(strict_types=1);

namespace Maatify\Product\ImageRole\DTO;

use JsonSerializable;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;

/**
 * Identity produced by creating a Product Image Role.
 */
final readonly class CreatedImageRoleDTO implements JsonSerializable
{
    public function __construct(public int $roleId)
    {
        if ($roleId < 1) {
            throw ProductInvalidArgumentException::nonPositiveId('roleId');
        }
    }

    /** @return array{roleId: int} */
    public function jsonSerialize(): array
    {
        return ['roleId' => $this->roleId];
    }
}
