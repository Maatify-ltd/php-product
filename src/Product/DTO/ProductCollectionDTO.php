<?php

declare(strict_types=1);

namespace Maatify\Product\Product\DTO;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use JsonSerializable;

/**
 * Typed result of a management Product list read, ordered by the requested or default sort (identity ascending by default).
 *
 * @implements IteratorAggregate<int, ProductDTO>
 */
final readonly class ProductCollectionDTO implements IteratorAggregate, Countable, JsonSerializable
{
    /**
     * @param list<ProductDTO> $items
     */
    public function __construct(private array $items) {}

    public function count(): int
    {
        return count($this->items);
    }

    /**
     * @return ArrayIterator<int, ProductDTO>
     */
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->items);
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /** @return list<ProductDTO> */
    public function items(): array
    {
        return $this->items;
    }

    /** @return list<array<string, mixed>> */
    public function jsonSerialize(): array
    {
        return array_map(static fn(ProductDTO $item): array => $item->jsonSerialize(), $this->items);
    }
}
