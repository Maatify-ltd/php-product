<?php

declare(strict_types=1);

namespace Maatify\Product\Content\DTO;

use JsonSerializable;

/**
 * The immutable owner identity of a Content record and the Product that owns it, read without a
 * lock. No write path changes these columns, so they only select the rows to lock.
 *
 * @internal Not part of the Public Runtime API.
 */
final readonly class ContentIdentityDTO implements JsonSerializable
{
    public function __construct(
        public int $productId,
        public int $ownerId,
    ) {}

    /** @return array{productId: int, ownerId: int} */
    public function jsonSerialize(): array
    {
        return [
            'productId' => $this->productId,
            'ownerId' => $this->ownerId,
        ];
    }
}
