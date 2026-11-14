<?php

declare(strict_types=1);

namespace Maatify\Product\Variant\DTO;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use JsonSerializable;

/**
 * Typed result of a Variant list read, ordered by `display_order` then `id`.
 *
 * @implements IteratorAggregate<int, VariantDTO>
 */
final readonly class VariantCollectionDTO implements IteratorAggregate, Countable, JsonSerializable
{
    /**
     * @param list<VariantDTO> $items
     */
    public function __construct(private array $items) {}

    public function count(): int
    {
        return count($this->items);
    }

    /**
     * @return ArrayIterator<int, VariantDTO>
     */
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->items);
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /** @return list<VariantDTO> */
    public function items(): array
    {
        return $this->items;
    }

    /** @return list<array<string, mixed>> */
    public function jsonSerialize(): array
    {
        return array_map(static fn(VariantDTO $variant): array => $variant->jsonSerialize(), $this->items);
    }
}
