<?php

declare(strict_types=1);

namespace Maatify\Product\Tests\Integration\Runtime;

use Maatify\PhpPersistence\Ordering\Repository\ScopedOrderingManager;
use Maatify\Product\Product\Repository\Pdo\PdoProductConfigurationRepository;
use Maatify\Product\Product\Service\ProductConfigurationValidator;
use Maatify\Product\Verification\BarrierProcessGroup;

require_once dirname(__DIR__, 3) . '/consumer-verification/BarrierProcessGroup.php';

/**
 * Runs one public command per child process, each on its own MySQL connection, and returns the
 * printed outcome of each worker. Every worker script it starts (`command-worker.php`,
 * `create-product-worker.php`) follows the same protocol: report `ready` on stdout, wait for one
 * line on stdin, run, print the outcome.
 *
 * Coordination is an explicit barrier, not timing: every worker connects, builds its services, and
 * reports `ready`; only when all of them are ready does the parent release them, by writing one
 * line to each worker's stdin in a single loop.
 */
trait ConcurrentWorkers
{
    /**
     * @param list<array{string, array<string, mixed>}> $commands one worker per entry: operation and JSON arguments
     *
     * @return list<string>
     */
    private function runCommandWorkers(array $commands): array
    {
        return $this->runWorkerScript('command-worker.php', array_map(
            static fn(array $command): array => [$command[0], json_encode($command[1], JSON_THROW_ON_ERROR)],
            $commands,
        ));
    }

    /**
     * Starts one process of the given `tests/Support` script per argument list, waits until every
     * one of them has reported `ready`, releases them together, and returns their printed outcomes.
     *
     * @param list<list<string>> $argumentLists
     *
     * @return list<string>
     */
    private function runWorkerScript(string $script, array $argumentLists): array
    {
        $environment = array_merge(getenv(), ['PRODUCT_TEST_DB_NAME' => self::$database]);
        $path = dirname(__DIR__, 2) . '/Support/' . $script;

        return BarrierProcessGroup::run(
            array_map(static fn(array $arguments): array => [PHP_BINARY, $path, ...$arguments], $argumentLists),
            $environment,
        );
    }

    /**
     * Reads the Product's whole configuration graph under the package's own locks and asserts that
     * it satisfies every structural invariant.
     */
    private function assertValidConfiguration(int $productId, string $message = ''): void
    {
        self::$pdo->beginTransaction();
        try {
            $snapshot = (new PdoProductConfigurationRepository(self::$pdo, new ScopedOrderingManager()))->lockSnapshot($productId);
            self::assertNotNull($snapshot);
            self::assertNull(ProductConfigurationValidator::firstViolation($snapshot), $message);
        } finally {
            self::$pdo->rollBack();
        }
    }
}
