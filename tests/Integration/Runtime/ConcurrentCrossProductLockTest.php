<?php

declare(strict_types=1);

namespace Maatify\Product\Tests\Integration\Runtime;

use Maatify\Product\ImageAssignment\Management\Command\CreateImageAssignmentCommand;

/**
 * Three cross-Product paths that once deadlocked under `REPEATABLE READ` now run concurrently on
 * different Products without a deadlock (std-package-building §25, std-testing §3.4): a
 * reconfiguration that creates a Variant of an already configurable Product, `restoreAsDefault()`,
 * and `setDefault()`. Separate processes and MySQL connections, released together by the worker
 * barrier, several Products per round, each round repeated; any deadlock (driver error 1213) fails
 * the test. It proves these paths only: gap locks at the ends of a Product's index ranges can still
 * deadlock other cross-Product paths, which the Package Reference states as a transient failure.
 */
final class ConcurrentCrossProductLockTest extends RuntimeTestCase
{
    use ConcurrentWorkers;

    private const int ROUNDS = 8;

    private const int PRODUCTS = 4;

    /**
     * Each reconfiguration locks its Product's configuration snapshot (composition pairs and labels
     * included, read by the identities of the locked Variants, Options, and Values) and then inserts a
     * new Variant with its composition pairs. Fails when the pairs or labels are read by `product_id`
     * or the Variants through the fingerprint key.
     */
    public function testConcurrentReconfigurationsOfDifferentProductsThatEachCreateAVariantAllSucceed(): void
    {
        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            $commands = [];
            $productIds = [];
            for ($index = 1; $index <= self::PRODUCTS; ++$index) {
                $productId = $this->namedProduct('CONFIG-' . $round . '-' . $index)->productId;
                $color = $this->preparedOption($productId, 'color', 'Color');
                $red = $this->preparedValue($color, 'red', 'Red');
                $blue = $this->preparedValue($color, 'blue', 'Blue');
                [$redVariant] = $this->productManagementService()->reconfigure(
                    $this->reconfiguration($productId, [$color => [$red]], [[[[$color, $red]]]]),
                )->createdVariantIds;
                $productIds[] = $productId;
                $commands[] = ['reconfigure', [
                    'productId' => $productId,
                    'revision' => $this->productManagementService()->getConfigurationRevision($productId),
                    'options' => [$color => [$red, $blue]],
                    'variants' => [[[[$color, $red]], $redVariant], [[[$color, $blue]]]],
                ]];
            }

            $outcomes = $this->runCommandWorkers($commands);

            $summary = 'Round ' . $round . ': ' . implode(' | ', $outcomes);
            foreach ($outcomes as $outcome) {
                self::assertMatchesRegularExpression('/^ok:\d+$/', $outcome, $summary);
            }
            foreach ($productIds as $productId) {
                self::assertSame(2, self::rowCount('maa_product_variants', "status = 'active' AND product_id = " . $productId), $summary);
                $this->assertValidConfiguration($productId, $summary);
            }
        }
    }

    /**
     * Each restore-as-default reads its scope's live default with a locking read and then writes the
     * default-scope key. Fails when that read goes through the default-scope key instead of the
     * Product-prefixed identity key.
     */
    public function testConcurrentRestoresAsDefaultOnDifferentProductsAllSucceed(): void
    {
        $service = $this->imageAssignmentManagementService();
        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            $commands = [];
            $assignmentIds = [];
            for ($index = 1; $index <= self::PRODUCTS; ++$index) {
                $productId = $this->namedProduct('RESTORE-' . $round . '-' . $index)->productId;
                $assignmentId = $service->create(new CreateImageAssignmentCommand($productId, null, $round))->assignmentId;
                $service->softDelete($assignmentId);
                $assignmentIds[] = $assignmentId;
                $commands[] = ['restore-as-default', ['assignmentId' => $assignmentId]];
            }

            $outcomes = $this->runCommandWorkers($commands);

            $summary = 'Round ' . $round . ': ' . implode(' | ', $outcomes);
            foreach ($outcomes as $outcome) {
                self::assertStringStartsWith('ok:', $outcome, $summary);
            }
            self::assertSame(self::PRODUCTS, self::rowCount(
                'maa_product_image_assignments',
                'is_default = 1 AND deleted_at IS NULL AND id IN (' . implode(', ', $assignmentIds) . ')',
            ), $summary);
        }
    }

    /**
     * Each set-default reads its scope's live default with a locking read, clears it when there is
     * one, and then marks the new one; the scopes of half of the Products start without a live
     * default, so that read also runs where it finds no row. Fails when that read goes through the
     * default-scope key instead of the Product-prefixed identity key.
     */
    public function testConcurrentSetDefaultsOnDifferentProductsAllSucceed(): void
    {
        $service = $this->imageAssignmentManagementService();
        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            $commands = [];
            $nextIds = [];
            for ($index = 1; $index <= self::PRODUCTS; ++$index) {
                $productId = $this->namedProduct('DEFAULT-' . $round . '-' . $index)->productId;
                $current = $service->create(new CreateImageAssignmentCommand($productId, null, 1))->assignmentId;
                if ($index > self::PRODUCTS / 2) {
                    $service->setDefault($current);
                }
                $next = $service->create(new CreateImageAssignmentCommand($productId, null, 2))->assignmentId;
                $nextIds[] = $next;
                $commands[] = ['set-default', ['assignmentId' => $next]];
            }

            $outcomes = $this->runCommandWorkers($commands);

            $summary = 'Round ' . $round . ': ' . implode(' | ', $outcomes);
            foreach ($outcomes as $outcome) {
                self::assertStringStartsWith('ok:', $outcome, $summary);
            }
            self::assertSame(self::PRODUCTS, self::rowCount(
                'maa_product_image_assignments',
                'is_default = 1 AND id IN (' . implode(', ', $nextIds) . ')',
            ), $summary);
            self::assertSame(self::PRODUCTS * $round, self::rowCount('maa_product_image_assignments', 'is_default = 1'), $summary);
        }
    }
}
