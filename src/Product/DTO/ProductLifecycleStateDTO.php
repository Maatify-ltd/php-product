<?php

declare(strict_types=1);

namespace Maatify\Product\Product\DTO;

use JsonSerializable;

/**
 * Lifecycle state of a stored Product as read under its row lock.
 *
 * @internal Not part of the Public Runtime API.
 */
final readonly class ProductLifecycleStateDTO implements JsonSerializable
{
    public function __construct(
        public int $id,
        public bool $active,
        public bool $deleted,
    ) {}

    /** @return array{id: int, active: bool, deleted: bool} */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'active' => $this->active,
            'deleted' => $this->deleted,
        ];
    }
}
