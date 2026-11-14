<?php

declare(strict_types=1);

namespace Maatify\Product\ImageRole\DTO;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use JsonSerializable;

/**
 * Typed result of a management Image Role list read, ordered by the binary role key then `id`.
 *
 * @implements IteratorAggregate<int, ImageRoleDTO>
 */
final readonly class ImageRoleCollectionDTO implements IteratorAggregate, Countable, JsonSerializable
{
    /**
     * @param list<ImageRoleDTO> $items
     */
    public function __construct(private array $items) {}

    public function count(): int
    {
        return count($this->items);
    }

    /**
     * @return ArrayIterator<int, ImageRoleDTO>
     */
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->items);
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /** @return list<ImageRoleDTO> */
    public function items(): array
    {
        return $this->items;
    }

    /** @return list<array<string, mixed>> */
    public function jsonSerialize(): array
    {
        return array_map(static fn(ImageRoleDTO $item): array => $item->jsonSerialize(), $this->items);
    }
}
