<?php

declare(strict_types=1);

namespace Maatify\Product\Tests\Support;

/**
 * Ordered record of the calls that test doubles observed.
 */
final class CallLog
{
    /** @var list<string> */
    private array $entries = [];

    public function record(string $entry): void
    {
        $this->entries[] = $entry;
    }

    /** @return list<string> */
    public function entries(): array
    {
        return $this->entries;
    }
}
