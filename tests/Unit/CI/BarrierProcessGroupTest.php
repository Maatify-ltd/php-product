<?php

declare(strict_types=1);

namespace Maatify\Product\Tests\Unit\CI;

use Maatify\Product\Verification\BarrierProcessGroup;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once dirname(__DIR__, 3) . '/consumer-verification/BarrierProcessGroup.php';

/** Exercises the same worker coordinator used by Integration and the consumer fixture. */
final class BarrierProcessGroupTest extends TestCase
{
    public function testReadinessAndResultAreBoundedByTheProtocol(): void
    {
        $command = [PHP_BINARY, '-r', 'echo "ready\n"; if (fgets(STDIN) === "go\n") { echo "done"; }'];

        self::assertSame(['done'], BarrierProcessGroup::run([$command]));
    }

    public function testAnEarlyStartupFailureRetainsDiagnostics(): void
    {
        $command = [PHP_BINARY, '-r', 'fwrite(STDERR, "fixture startup failed"); exit(1);'];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('fixture startup failed');
        BarrierProcessGroup::run([$command]);
    }

    public function testWorkerWaitingForInputBeforeReadinessTimesOut(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Timed out waiting for worker output');
        BarrierProcessGroup::run([[PHP_BINARY, '-r', 'fgets(STDIN);']], startupSeconds: 1);
    }

    public function testWorkerThatStopsAfterReleaseTimesOut(): void
    {
        $command = [PHP_BINARY, '-r', 'echo "ready\n"; fgets(STDIN); sleep(30);'];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Timed out waiting for worker output');
        BarrierProcessGroup::run([$command], resultSeconds: 1);
    }
}
