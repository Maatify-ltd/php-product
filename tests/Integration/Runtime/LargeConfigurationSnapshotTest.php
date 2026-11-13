<?php

declare(strict_types=1);

namespace Maatify\Product\Tests\Integration\Runtime;

use Maatify\PhpPersistence\Ordering\Repository\ScopedOrderingManager;
use Maatify\Product\Product\Repository\Pdo\PdoProductConfigurationRepository;
use PDO;

final class LargeConfigurationSnapshotTest extends RuntimeTestCase
{
    private const int HISTORY_SIZE = 65536;

    public function testSnapshotReadsHistoryBeyondTheNativePlaceholderLimit(): void
    {
        $created = $this->namedProduct('LARGE-HISTORY');
        $option = $this->preparedOption($created->productId, 'historical', 'Historical');
        self::assertFalse((bool) self::$pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES));

        self::$pdo->beginTransaction();
        try {
            // Use set-based fixtures so this test measures the snapshot path, not thousands of setup round trips.
            $this->execute(
                "INSERT INTO maa_product_option_values (option_id, code, display_order, created_at, updated_at, deleted_at)
                WITH digits AS (SELECT 0 AS n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3
                    UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7
                    UNION ALL SELECT 8 UNION ALL SELECT 9),
                numbers AS (SELECT 1 + a.n + 10*b.n + 100*c.n + 1000*d.n + 10000*e.n AS n
                    FROM digits a CROSS JOIN digits b CROSS JOIN digits c CROSS JOIN digits d CROSS JOIN digits e)
                SELECT :option_id, CONCAT('value-', n), n, :created, :updated, :deleted
                FROM numbers WHERE n <= :size ORDER BY n",
                ['option_id' => $option, 'created' => self::SEED_TS, 'updated' => self::SEED_TS, 'deleted' => self::SEED_TS, 'size' => self::HISTORY_SIZE],
            );
            $this->execute(
                "INSERT INTO maa_product_variants (product_id, composition_fingerprint, status, display_order, created_at, updated_at, deleted_at)
                SELECT :product_id, SHA2(CONCAT('v1|', option_id, '=', id), 256), 'inactive',
                    display_order + 1, :created, :updated, :deleted
                FROM maa_product_option_values WHERE option_id = :option_id ORDER BY id",
                ['product_id' => $created->productId, 'option_id' => $option, 'created' => self::SEED_TS, 'updated' => self::SEED_TS, 'deleted' => self::SEED_TS],
            );
            $this->execute(
                "INSERT INTO maa_product_variant_option_values (product_id, variant_id, option_id, option_value_id)
                SELECT v.product_id, v.id, ov.option_id, ov.id FROM maa_product_option_values ov
                JOIN maa_product_variants v ON v.product_id = :product_id
                    AND v.composition_fingerprint = SHA2(CONCAT('v1|', ov.option_id, '=', ov.id), 256)
                WHERE ov.option_id = :option_id",
                ['option_id' => $option, 'product_id' => $created->productId],
            );
            $lastValue = (int) self::scalar('SELECT MAX(id) FROM maa_product_option_values');
            $this->labelValue($lastValue, 'Last historical Value');

            $repository = new PdoProductConfigurationRepository(self::$pdo, new ScopedOrderingManager());
            $snapshot = $repository->lockSnapshot($created->productId);
            self::assertNotNull($snapshot);
            self::assertTrue(self::$pdo->inTransaction());
            self::assertCount(self::HISTORY_SIZE, $snapshot->optionValues);
            self::assertCount(self::HISTORY_SIZE + 1, $snapshot->variants);
            self::assertTrue($snapshot->options[$option]->labelled);
            self::assertTrue($snapshot->optionValues[$lastValue]->labelled);
            $lastVariantId = (int) self::scalar('SELECT MAX(id) FROM maa_product_variants');
            $lastVariant = $snapshot->variants[$lastVariantId];
            self::assertCount(1, $lastVariant->composition);
            self::assertSame($lastValue, $lastVariant->composition[0]->optionValueId);

            $this->execute('SAVEPOINT before_status_fixture');
            // Keep one deleted row in the input to verify the bulk update still excludes it.
            $ids = array_keys($snapshot->variants);
            unset($snapshot);
            $this->execute(
                'UPDATE maa_product_variants SET deleted_at = NULL, status = \'inactive\' WHERE product_id = :product_id AND id <> :deleted_id',
                ['product_id' => $created->productId, 'deleted_id' => $lastVariantId],
            );
            $this->execute('SAVEPOINT before_status_batches');
            $repository->updateVariantStatus($ids, true, self::laterClock()->now());

            self::assertTrue(self::$pdo->inTransaction());
            self::assertSame(self::HISTORY_SIZE, self::rowCount('maa_product_variants', "status = 'active' AND updated_at = '" . self::LATER_TS . "'"));
            self::assertSame(1, self::rowCount('maa_product_variants', "status = 'inactive' AND deleted_at IS NOT NULL"));
            $this->execute('ROLLBACK TO SAVEPOINT before_status_batches');
            self::assertSame(0, self::rowCount('maa_product_variants', "status = 'active'"));

            // Restore the valid simple starting state before exercising the public command.
            $this->execute('ROLLBACK TO SAVEPOINT before_status_fixture');
            unset($ids);
            $value = $this->preparedValue($option, 'current', 'Current');
            $products = $this->productManagementService();
            $command = $this->reconfiguration(
                $created->productId,
                [$option => [$value]],
                [[[[$option, $value]]]],
            );

            // setup-php may configure a different CLI limit. Exercise the default 128 MiB budget.
            $previousLimit = ini_set('memory_limit', '128M');
            self::assertNotFalse($previousLimit);
            try {
                $result = $products->reconfigure($command);
            } finally {
                ini_set('memory_limit', $previousLimit);
            }

            self::assertTrue(self::$pdo->inTransaction());
            self::assertCount(1, $result->createdVariantIds);
            self::assertSame([$created->variantId], $result->deactivatedVariantIds);
            self::assertSame([$option], $result->activatedOptionIds);
            self::assertSame([$value], $result->activatedOptionValueIds);
            self::assertSame($command->expectedRevision + 1, $products->getConfigurationRevision($created->productId));
            self::assertSame(self::HISTORY_SIZE, self::rowCount('maa_product_variants', 'deleted_at IS NOT NULL'));
        } finally {
            self::$pdo->rollBack();
        }
    }
}
