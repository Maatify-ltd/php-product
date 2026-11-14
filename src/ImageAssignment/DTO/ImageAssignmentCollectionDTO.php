<?php

declare(strict_types=1);

namespace Maatify\Product\ImageAssignment\DTO;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use JsonSerializable;

/**
 * Typed result of a management Image Assignment list read, ordered by exact scope (`ordering_scope`), then `display_order`, then `id`.
 *
 * @implements IteratorAggregate<int, ImageAssignmentDTO>
 */
final readonly class ImageAssignmentCollectionDTO implements IteratorAggregate, Countable, JsonSerializable
{
    /**
     * @param list<ImageAssignmentDTO> $items
     */
    public function __construct(private array $items) {}

    public function count(): int
    {
        return count($this->items);
    }

    /**
     * @return ArrayIterator<int, ImageAssignmentDTO>
     */
    public function getIterator(): ArrayIterator
    {
        return new ArrayIterator($this->items);
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /** @return list<ImageAssignmentDTO> */
    public function items(): array
    {
        return $this->items;
    }

    /** @return list<array<string, mixed>> */
    public function jsonSerialize(): array
    {
        return array_map(static fn(ImageAssignmentDTO $item): array => $item->jsonSerialize(), $this->items);
    }
}
