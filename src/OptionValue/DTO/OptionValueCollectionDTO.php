<?php

declare(strict_types=1);

namespace Maatify\Product\OptionValue\DTO;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use JsonSerializable;

/**
 * Typed result of a management Option Value list read, ordered by `display_order` then `id`.
 *
 * @implements IteratorAggregate<int, OptionValueDTO>
 */
final readonly class OptionValueCollectionDTO implements IteratorAggregate, Countable, JsonSerializable
{
    /**
     * @param list<OptionValueDTO> $items
     */
    public function __construct(private array $items) {}

    public function count(): int
    {
        return count($this->items);
    }

    /**
     * @return ArrayIterator<int, OptionValueDTO>
     */
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->items);
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /** @return list<OptionValueDTO> */
    public function items(): array
    {
        return $this->items;
    }

    /** @return list<array<string, mixed>> */
    public function jsonSerialize(): array
    {
        return array_map(static fn(OptionValueDTO $item): array => $item->jsonSerialize(), $this->items);
    }
}
