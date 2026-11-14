<?php

declare(strict_types=1);

namespace Maatify\Product\Option\DTO;

use JsonSerializable;

/**
 * Lifecycle state of a stored Option as read under the Product lock.
 *
 * @internal Not part of the Public Runtime API.
 */
final readonly class OptionStateDTO implements JsonSerializable
{
    /**
     * @param bool $labelled Whether a non-deleted Content record with a non-empty label exists.
     */
    public function __construct(
        public int $id,
        public int $productId,
        public bool $active,
        public bool $deleted,
        public bool $labelled,
    ) {}

    public function withActive(bool $active): self
    {
        return new self($this->id, $this->productId, $active, $this->deleted, $this->labelled);
    }

    public function withDeleted(bool $deleted): self
    {
        return new self($this->id, $this->productId, $this->active, $deleted, $this->labelled);
    }

    public function withLabelled(bool $labelled): self
    {
        return new self($this->id, $this->productId, $this->active, $this->deleted, $labelled);
    }

    /** @return array{id: int, productId: int, active: bool, deleted: bool, labelled: bool} */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'productId' => $this->productId,
            'active' => $this->active,
            'deleted' => $this->deleted,
            'labelled' => $this->labelled,
        ];
    }
}
