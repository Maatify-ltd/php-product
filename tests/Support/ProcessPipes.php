<?php

declare(strict_types=1);

namespace Maatify\Product\Tests\Support;

use RuntimeException;

/**
 * Typed access to the pipes that `proc_open()` returns. The parameter is `mixed` on purpose: the
 * pipe array is typed differently by different static-analysis versions.
 */
final class ProcessPipes
{
    private function __construct() {}

    /**
     * @return resource
     */
    public static function stream(mixed $pipes, int $index)
    {
        $stream = is_array($pipes) ? ($pipes[$index] ?? null) : null;
        if (!is_resource($stream)) {
            throw new RuntimeException(sprintf('Process pipe %d is not available.', $index));
        }

        return $stream;
    }
}
