<?php

declare(strict_types=1);

namespace Maatify\Product\Tests\Integration\Runtime;

use Maatify\Product\Exception\ProductExceptionInterface;
use Maatify\Product\Product\Management\Command\CreateProductCommand;
use Maatify\Product\Tests\Support\ProcessPipes;
use PDOException;
use Throwable;

/**
 * A real deadlock (MySQL driver error 1213) between two Host transactions on two connections that
 * lock two Product rows in opposite orders, where the losing lock request is made inside a package
 * call. The package propagates the original `PDOException` unchanged, and MySQL has ended the whole
 * Host transaction on the server, the Host's earlier work included.
 *
 * Determinism: the parent holds Product B and requests Product A only through the package; the
 * child holds Product A, then requests Product B, so the wait-for cycle always forms. The child has
 * modified many more rows, and InnoDB rolls back the transaction with the smaller weight, so the
 * victim is always the parent's transaction. The child reports its lock through a pipe; nothing is
 * timed.
 */
final class DeadlockPropagationTest extends RuntimeTestCase
{
    private const int DEADLOCK = 1213;
    private const int WEIGHT_ROWS = 40;
    private const int ROUNDS = 3;

    public function testADeadlockInsideAPackageCallPropagatesUnchangedAndEndsTheHostTransaction(): void
    {
        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            $products = $this->productManagementService();
            $lockedByChild = $products->create(new CreateProductCommand('DEADLOCK-A-' . $round))->productId;
            $lockedByHost = $products->create(new CreateProductCommand('DEADLOCK-B-' . $round))->productId;
            $weight = [];
            for ($i = 1; $i <= self::WEIGHT_ROWS; ++$i) {
                $weight[] = $products->create(new CreateProductCommand('WEIGHT-' . $round . '-' . $i))->productId;
            }

            self::$pdo->beginTransaction();
            $hostWork = $products->create(new CreateProductCommand('HOST-WORK-' . $round))->productId;
            self::$pdo->prepare('SELECT id FROM maa_product_products WHERE id = :i FOR UPDATE')->execute(['i' => $lockedByHost]);
            $hostConnection = (int) self::scalar('SELECT CONNECTION_ID()');

            $child = $this->startChild($lockedByChild, $lockedByHost, $weight);
            try {
                $products->softDelete($lockedByChild);
                self::fail('Round ' . $round . ': the package call must lose the deadlock.');
            } catch (Throwable $caught) {
                self::assertInstanceOf(PDOException::class, $caught);
                self::assertSame(PDOException::class, $caught::class, 'Round ' . $round . ': the original PDOException, never wrapped.');
                self::assertNotInstanceOf(ProductExceptionInterface::class, $caught);
                self::assertSame(self::DEADLOCK, $caught->errorInfo[1] ?? null, 'Round ' . $round . ': ' . $caught->getMessage());
                self::assertNull($caught->getPrevious());
            }

            self::assertSame('acquired', $this->finishChild($child), 'Round ' . $round . ': the other Host transaction proceeds.');
            self::assertSame(
                0,
                (int) self::scalar('SELECT COUNT(*) FROM information_schema.innodb_trx WHERE trx_mysql_thread_id = :c', ['c' => $hostConnection]),
                'Round ' . $round . ': MySQL ended the Host transaction on the server.',
            );
            self::assertSame(0, self::rowCount('maa_product_products', 'id = ' . $hostWork), 'Round ' . $round . ': the Host work before the call is gone with it.');
            self::assertSame(0, self::rowCount('maa_product_variants', 'product_id = ' . $hostWork));
            if (self::$pdo->inTransaction()) {
                self::$pdo->rollBack();
            }

            self::assertSame(1, self::rowCount('maa_product_products', 'deleted_at IS NULL AND id = ' . $lockedByChild), 'Round ' . $round . ': the package call wrote nothing.');
            self::assertSame(0, self::rowCount('maa_product_products', "updated_at = '2030-01-01 00:00:00'"), 'Round ' . $round . ': the other Host rolled its work back.');
        }
    }

    /**
     * @param list<int> $weight
     *
     * @return array{process: resource, stdout: resource, stderr: string}
     */
    private function startChild(int $first, int $second, array $weight): array
    {
        $stderr = tempnam(sys_get_temp_dir(), 'product-deadlock-');
        self::assertIsString($stderr);
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/Support/deadlock-worker.php', (string) $first, (string) $second, implode(',', $weight)],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $stderr, 'w']],
            $pipes,
            null,
            array_merge(getenv(), ['PRODUCT_TEST_DB_NAME' => self::$database]),
        );
        self::assertIsResource($process, 'The child process must start.');
        $stdin = ProcessPipes::stream($pipes, 0);
        $stdout = ProcessPipes::stream($pipes, 1);
        fclose($stdin);
        self::assertSame("locked\n", fgets($stdout), 'The child did not take its lock: ' . file_get_contents($stderr));

        return ['process' => $process, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /**
     * @param array{process: resource, stdout: resource, stderr: string} $child
     */
    private function finishChild(array $child): string
    {
        $output = (string) stream_get_contents($child['stdout']);
        fclose($child['stdout']);
        $exit = proc_close($child['process']);
        $errors = (string) file_get_contents($child['stderr']);
        unlink($child['stderr']);
        self::assertSame(0, $exit, 'The child failed: ' . $errors . $output);

        return $output;
    }
}
