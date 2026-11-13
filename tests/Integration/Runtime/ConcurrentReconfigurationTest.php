<?php

declare(strict_types=1);

namespace Maatify\Product\Tests\Integration\Runtime;

use Maatify\Product\Factory\ProductFactory;
use Maatify\Product\Product\Exception\ProductConfigurationRevisionConflictException;

/**
 * Real concurrent reconfigurations of one Product: separate processes and connections released at
 * once. The Product row lock serializes them, so writers with the same revision yield a single
 * winner and typed revision conflicts, and the
 * final configuration is valid.
 */
final class ConcurrentReconfigurationTest extends RuntimeTestCase
{
    use ConcurrentWorkers;

    private const int WORKERS = 8;

    public function testSecondEditorCannotReplaceFirstEditorChanges(): void
    {
        $created = $this->namedProduct('EDITORS');
        $productId = $created->productId;
        $color = $this->preparedOption($productId, 'color', 'Color');
        $red = $this->preparedValue($color, 'red', 'Red');
        $blue = $this->preparedValue($color, 'blue', 'Blue');
        $first = $this->productManagementService();
        $secondConnection = self::connect(self::$database);
        $second = ProductFactory::createProductManagementService($secondConnection, self::clock());
        $secondConnection->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $secondConnection->beginTransaction();
        $firstRevision = $first->getConfigurationRevision($productId);
        $secondRevision = $second->getConfigurationRevision($productId);
        self::assertSame($firstRevision, $secondRevision);
        $first->reconfigure($this->reconfiguration($productId, [$color => [$red]], [[[[$color, $red]]]], $firstRevision));
        $stale = $this->reconfiguration($productId, [$color => [$blue]], [[[[$color, $blue]]]], $secondRevision);

        $this->assertThrows(ProductConfigurationRevisionConflictException::class, fn() => $second->reconfigure($stale));
        self::assertTrue($secondConnection->inTransaction());
        $secondConnection->rollBack();
        self::assertSame($firstRevision + 1, $second->getConfigurationRevision($productId));
        self::assertSame(2, self::rowCount('maa_product_variants'));
        self::assertSame(1, self::rowCount('maa_product_option_values', "id = $red AND status = 'active'"));
        self::assertSame(0, self::rowCount('maa_product_option_values', "id = $blue AND status = 'active'"));
    }

    public function testLifecycleChangeInvalidatesEditorAndRollbackPreservesRevision(): void
    {
        $created = $this->namedProduct('REVISION-LIFECYCLE');
        $productId = $created->productId;
        $products = $this->productManagementService();
        $before = $products->getConfigurationRevision($productId);
        $old = $this->reconfiguration($productId, [], [[[], $created->variantId]], $before);

        $this->preparedOption($productId, 'color');
        $this->assertThrows(ProductConfigurationRevisionConflictException::class, fn() => $products->reconfigure($old));
        $current = $products->getConfigurationRevision($productId);
        self::assertSame($before + 1, $current);

        self::$pdo->beginTransaction();
        $products->reconfigure($this->reconfiguration($productId, [], [[[], $created->variantId]], $current));
        self::assertSame($current + 1, $products->getConfigurationRevision($productId));
        self::$pdo->rollBack();
        self::assertSame($current, $products->getConfigurationRevision($productId));
    }

    public function testConcurrentIdenticalReconfigurationsYieldOneWinnerAndTypedConflicts(): void
    {
        $created = $this->namedProduct('RACE');
        $color = $this->preparedOption($created->productId, 'color', 'Color');
        $red = $this->preparedValue($color, 'red', 'Red');
        $blue = $this->preparedValue($color, 'blue', 'Blue');
        $arguments = [
            'productId' => $created->productId,
            'revision' => $this->productManagementService()->getConfigurationRevision($created->productId),
            'options' => [$color => [$red, $blue]],
            'variants' => [[[[$color, $red]]], [[[$color, $blue]]]],
        ];

        $outcomes = $this->runCommandWorkers(array_fill(0, self::WORKERS, ['reconfigure', $arguments]));

        $created = array_values(array_filter($outcomes, static fn(string $o): bool => str_starts_with($o, 'ok:')));
        $conflicts = array_filter($outcomes, static fn(string $o): bool => $o === 'stale-revision');
        self::assertCount(1, $created, implode(' | ', $outcomes));
        self::assertCount(self::WORKERS - 1, $conflicts, implode(' | ', $outcomes));
        self::assertSame(3, self::rowCount('maa_product_variants'), 'The empty Variant plus exactly one Variant per composition.');
        self::assertSame(2, self::rowCount('maa_product_variants', "status = 'active'"));
        $this->assertValidConfiguration($arguments['productId']);
    }

    public function testConcurrentDifferentTargetsLeaveOneValidConfiguration(): void
    {
        $created = $this->namedProduct('RACE-2');
        $color = $this->preparedOption($created->productId, 'color', 'Color');
        $red = $this->preparedValue($color, 'red', 'Red');
        $blue = $this->preparedValue($color, 'blue', 'Blue');
        $green = $this->preparedValue($color, 'green', 'Green');
        $revision = $this->productManagementService()->getConfigurationRevision($created->productId);
        $commands = [];
        for ($i = 0; $i < self::WORKERS; ++$i) {
            $value = [$red, $blue, $green][$i % 3];
            $commands[] = ['reconfigure', ['productId' => $created->productId, 'revision' => $revision, 'options' => [$color => [$value]], 'variants' => [[[[$color, $value]]]]]];
        }

        $outcomes = $this->runCommandWorkers($commands);

        foreach ($outcomes as $outcome) {
            self::assertTrue(str_starts_with($outcome, 'ok:') || $outcome === 'stale-revision', implode(' | ', $outcomes));
        }
        self::assertSame(1, count(array_filter($outcomes, static fn(string $o): bool => str_starts_with($o, 'ok:'))));
        self::assertSame(1, self::rowCount('maa_product_variants', "status = 'active' AND deleted_at IS NULL"), 'Exactly one target set is the committed configuration.');
        self::assertSame(1, self::rowCount('maa_product_option_values', "status = 'active'"));
        self::assertLessThanOrEqual(4, self::rowCount('maa_product_variants'), 'At most one identity per composition exists.');
        $this->assertValidConfiguration($created->productId);
    }
}
