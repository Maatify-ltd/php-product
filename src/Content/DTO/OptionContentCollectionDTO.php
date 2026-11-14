<?php

declare(strict_types=1);

namespace Maatify\Product\Content\DTO;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use JsonSerializable;

/**
 * Typed result of a Option Content list read, ordered by `language_code` (the unlocalized
 * record first) then `id`.
 *
 * @implements IteratorAggregate<int, OptionContentDTO>
 */
final readonly class OptionContentCollectionDTO implements IteratorAggregate, Countable, JsonSerializable
{
    /**
     * @param list<OptionContentDTO> $items
     */
    public function __construct(private array $items) {}

    public function count(): int
    {
        return count($this->items);
    }

    /**
     * @return ArrayIterator<int, OptionContentDTO>
     */
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->items);
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /** @return list<OptionContentDTO> */
    public function items(): array
    {
        return $this->items;
    }

    /** @return list<array<string, mixed>> */
    public function jsonSerialize(): array
    {
        return array_map(static fn(OptionContentDTO $content): array => $content->jsonSerialize(), $this->items);
    }
}
