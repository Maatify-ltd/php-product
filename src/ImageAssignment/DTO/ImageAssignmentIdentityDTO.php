<?php

declare(strict_types=1);

namespace Maatify\Product\ImageAssignment\DTO;

use JsonSerializable;

/**
 * The owner identity columns of a stored Image Assignment, read without a lock. No write path
 * changes these columns, so they select the owner rows to lock; they carry no state to validate.
 *
 * @internal Not part of the Public Runtime API.
 */
final readonly class ImageAssignmentIdentityDTO implements JsonSerializable
{
    public function __construct(
        public int $productId,
        public ?int $variantId,
        public ?int $roleId,
    ) {}

    /** @return array{productId: int, variantId: ?int, roleId: ?int} */
    public function jsonSerialize(): array
    {
        return [
            'productId' => $this->productId,
            'variantId' => $this->variantId,
            'roleId' => $this->roleId,
        ];
    }
}
