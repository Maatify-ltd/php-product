<?php

declare(strict_types=1);

namespace Maatify\Product\Tests\Support;

use Closure;
use PDOStatement;

/** Schedules a second session after a real buffered count has executed. */
final class AfterCountStatement extends PDOStatement
{
    /** @param Closure(): void $afterCount */
    protected function __construct(private readonly Closure $afterCount) {}

    /** @param array<array-key, mixed>|null $params */
    public function execute(?array $params = null): bool
    {
        $result = parent::execute($params);
        if ($result && str_starts_with($this->queryString, 'SELECT COUNT(*)')) {
            ($this->afterCount)();
        }

        return $result;
    }
}
