<?php

declare(strict_types=1);

namespace Maatify\Product\Option\DTO;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use JsonSerializable;

/**
 * Typed result of a management Option list read, ordered by `display_order` then `id`.
 *
 * @implements IteratorAggregate<int, OptionDTO>
 */
final readonly class OptionCollectionDTO implements IteratorAggregate, Countable, JsonSerializable
{
    /**
     * @param list<OptionDTO> $items
     */
    public function __construct(private array $items) {}

    public function count(): int
    {
        return count($this->items);
    }

    /**
     * @return ArrayIterator<int, OptionDTO>
     */
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->items);
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /** @return list<OptionDTO> */
    public function items(): array
    {
        return $this->items;
    }

    /** @return list<array<string, mixed>> */
    public function jsonSerialize(): array
    {
        return array_map(static fn(OptionDTO $item): array => $item->jsonSerialize(), $this->items);
    }
}
