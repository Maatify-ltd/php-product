<?php

declare(strict_types=1);

namespace Maatify\Product\Tests\Integration\Runtime;

/**
 * Real concurrent writers: separate PHP processes, separate MySQL connections, released together
 * by the worker barrier once every one of them is ready.
 */
final class ConcurrentCreationTest extends RuntimeTestCase
{
    use ConcurrentWorkers;

    private const int WORKERS = 8;

    public function testConcurrentCreatesOfOneCodeYieldExactlyOneProductAndOneVariant(): void
    {
        $outcomes = $this->runWorkers(array_fill(0, self::WORKERS, 'RACE-1'));

        $created = array_values(array_filter($outcomes, static fn(string $o): bool => str_starts_with($o, 'created:')));
        $conflicts = array_filter($outcomes, static fn(string $o): bool => $o === 'conflict');
        self::assertCount(1, $created, implode(' | ', $outcomes));
        self::assertCount(self::WORKERS - 1, $conflicts, implode(' | ', $outcomes));
        self::assertSame(1, self::rowCount('maa_product_products'));
        self::assertSame(1, self::rowCount('maa_product_variants'));
    }

    public function testConcurrentCreatesOfDifferentCodesEachGetExactlyOneActiveEmptyVariant(): void
    {
        $codes = [];
        for ($i = 0; $i < self::WORKERS; ++$i) {
            $codes[] = 'RACE-D' . $i;
        }

        $outcomes = $this->runWorkers($codes);

        foreach ($outcomes as $outcome) {
            self::assertStringStartsWith('created:', $outcome, implode(' | ', $outcomes));
        }
        self::assertSame(self::WORKERS, self::rowCount('maa_product_products'));
        self::assertSame(self::WORKERS, self::rowCount('maa_product_variants', "status = 'active' AND display_order = 1"));
        self::assertSame(0, (int) self::scalar(
            'SELECT COUNT(*) FROM (SELECT product_id FROM maa_product_variants GROUP BY product_id HAVING COUNT(*) <> 1) x',
        ));
    }

    /**
     * @param list<string> $codes one worker is started per entry
     *
     * @return list<string> the printed outcome of each worker
     */
    private function runWorkers(array $codes): array
    {
        return $this->runWorkerScript('create-product-worker.php', array_map(static fn(string $code): array => [$code], $codes));
    }
}
