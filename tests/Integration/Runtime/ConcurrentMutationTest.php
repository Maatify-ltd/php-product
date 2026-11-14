<?php

declare(strict_types=1);

namespace Maatify\Product\Tests\Integration\Runtime;

use Maatify\Product\Content\Management\Command\CreateProductContentCommand;
use Maatify\Product\ImageAssignment\Management\Command\CreateImageAssignmentCommand;
use Maatify\Product\Option\Management\Command\CreateOptionCommand;
use Maatify\Product\Product\Management\Command\CreateProductCommand;
use PDO;

/**
 * Race-prone invariants under real concurrency (std-package-building §25, std-testing §3.4):
 * separate processes, separate MySQL connections, released together by the worker barrier, each
 * round repeated. Every outcome is classified; an unclassified error, including a deadlock
 * (driver error 1213), fails the test.
 */
final class ConcurrentMutationTest extends RuntimeTestCase
{
    use ConcurrentWorkers;

    private const int ROUNDS = 6;

    /**
     * Either deactivation alone is valid, both together would leave the red Value unrepresented
     * (`INV-04`, `INV-07`). The Product lock serializes them: the second one is validated on the
     * committed result of the first and rejected.
     */
    public function testConcurrentDeactivationsOfTwoVariantsThatAreEachDispensableLeaveOneWinner(): void
    {
        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            $created = $this->namedProduct('LIFECYCLE-' . $round);
            $color = $this->preparedOption($created->productId, 'color', 'Color');
            $red = $this->preparedValue($color, 'red', 'Red');
            $blue = $this->preparedValue($color, 'blue', 'Blue');
            $size = $this->preparedOption($created->productId, 'size', 'Size');
            $m = $this->preparedValue($size, 'm', 'M');
            $l = $this->preparedValue($size, 'l', 'L');
            [$redM, $redL] = $this->productManagementService()->reconfigure($this->reconfiguration(
                $created->productId,
                [$color => [$red, $blue], $size => [$m, $l]],
                [[[[$color, $red], [$size, $m]]], [[[$color, $red], [$size, $l]]], [[[$color, $blue], [$size, $m]]], [[[$color, $blue], [$size, $l]]]],
            ))->createdVariantIds;
            $this->activateProduct($created->productId);

            $outcomes = $this->runCommandWorkers([
                ['variant-status', ['variantId' => $redM, 'status' => 'inactive']],
                ['variant-status', ['variantId' => $redL, 'status' => 'inactive']],
            ]);

            $summary = 'Round ' . $round . ': ' . implode(' | ', $outcomes);
            self::assertSame(['fc-inv', 'ok'], self::sortedKinds($outcomes), $summary);
            self::assertSame(1, self::rowCount('maa_product_variants', "status = 'active' AND id IN (" . $redM . ', ' . $redL . ')'), $summary);
            $this->assertValidConfiguration($created->productId, $summary);
        }
    }

    /**
     * Several Options of one Product moved at once to different positions: the shared Ordering API
     * under the Product lock keeps the positions unique and contiguous.
     */
    public function testConcurrentMovesWithinOneProductKeepPositionsUniqueAndContiguous(): void
    {
        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            $productId = $this->productManagementService()->create(new CreateProductCommand('ORDER-' . $round))->productId;
            $options = [];
            foreach (['a', 'b', 'c', 'd', 'e', 'f'] as $code) {
                $options[] = $this->optionManagementService()->create(new CreateOptionCommand($productId, $code))->optionId;
            }

            $outcomes = $this->runCommandWorkers([
                ['move-option', ['optionId' => $options[0], 'position' => 6]],
                ['move-option', ['optionId' => $options[1], 'position' => 4]],
                ['move-option', ['optionId' => $options[4], 'position' => 1]],
                ['move-option', ['optionId' => $options[5], 'position' => 2]],
            ]);

            $summary = 'Round ' . $round . ': ' . implode(' | ', $outcomes);
            self::assertSame(['ok', 'ok', 'ok', 'ok'], self::sortedKinds($outcomes), $summary);
            $statement = self::$pdo->prepare('SELECT display_order FROM maa_product_options WHERE product_id = :p ORDER BY display_order');
            $statement->execute(['p' => $productId]);
            self::assertSame([1, 2, 3, 4, 5, 6], $statement->fetchAll(PDO::FETCH_COLUMN), $summary);
        }
    }

    /**
     * Activating an unnamed Product is rejected (`INV-06`), and deleting the only name of an active
     * Product is rejected; both lock the Product first, so exactly one of them wins and the database
     * never holds an active Product without a name.
     */
    public function testConcurrentActivationAndDeletionOfTheOnlyNameNeverLeaveAnActiveProductWithoutAName(): void
    {
        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            $productId = $this->productManagementService()->create(new CreateProductCommand('NAME-' . $round))->productId;
            $contentId = $this->productContentManagementService()->create(new CreateProductContentCommand($productId, null, 'Only name'))->contentId;

            $outcomes = $this->runCommandWorkers([
                ['product-status', ['productId' => $productId, 'status' => 'active']],
                ['delete-product-content', ['contentId' => $contentId]],
            ]);

            $summary = 'Round ' . $round . ': ' . implode(' | ', $outcomes);
            self::assertSame(['fc-inv', 'ok'], self::sortedKinds($outcomes), $summary);
            $active = self::rowCount('maa_product_products', "status = 'active' AND id = " . $productId) === 1;
            $named = self::rowCount('maa_product_product_contents', 'deleted_at IS NULL AND id = ' . $contentId) === 1;
            self::assertSame($active, $named, $summary . ': either activation won and the name stayed, or the deletion won and the Product stayed inactive.');
            self::assertSame($active, str_starts_with($outcomes[0], 'ok:'), $summary);
        }
    }

    /**
     * Two Variants of different Products take the same SKU at once: the unique key decides, the loser
     * gets the typed conflict, never an unclassified error.
     */
    public function testConcurrentAssignmentOfOneSkuToTwoVariantsYieldsOneWinnerAndATypedConflict(): void
    {
        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            $first = $this->productManagementService()->create(new CreateProductCommand('SKU-A-' . $round))->variantId;
            $second = $this->productManagementService()->create(new CreateProductCommand('SKU-B-' . $round))->variantId;
            $sku = 'RACE-SKU-' . $round;

            $outcomes = $this->runCommandWorkers([
                ['variant-sku', ['variantId' => $first, 'sku' => $sku]],
                ['variant-sku', ['variantId' => $second, 'sku' => $sku]],
            ]);

            $summary = 'Round ' . $round . ': ' . implode(' | ', $outcomes);
            self::assertSame(['ok', 'sku-conflict'], self::sortedKinds($outcomes), $summary);
            self::assertSame(1, self::rowCount('maa_product_variants', "sku = '" . $sku . "'"), $summary);
        }
    }

    /**
     * `setDefault()` of one assignment and `updateDisplayOrder()` of the scope's current default, on
     * one Product and one scope. The two touch each other's rows: set-default clears the current
     * default, and the move locks every row of the scope. Both lock the Product first and their own
     * assignment row next, so they serialize instead of deadlocking; both succeed, the new default is
     * the only one, and the positions stay unique and contiguous.
     */
    public function testConcurrentSetDefaultAndMoveOfTheCurrentDefaultInOneScopeBothSucceed(): void
    {
        $assignments = $this->imageAssignmentManagementService();
        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            $productId = $this->productManagementService()->create(new CreateProductCommand('IMAGE-' . $round))->productId;
            $current = $assignments->create(new CreateImageAssignmentCommand($productId, null, 1))->assignmentId;
            $assignments->setDefault($current);
            $next = $assignments->create(new CreateImageAssignmentCommand($productId, null, 2))->assignmentId;
            $assignments->create(new CreateImageAssignmentCommand($productId, null, 3));

            $outcomes = $this->runCommandWorkers([
                ['set-default', ['assignmentId' => $next]],
                ['move-assignment', ['assignmentId' => $current, 'position' => 3]],
            ]);

            $summary = 'Round ' . $round . ': ' . implode(' | ', $outcomes);
            self::assertSame(['ok', 'ok'], self::sortedKinds($outcomes), $summary);
            self::assertSame(1, self::rowCount('maa_product_image_assignments', 'is_default = 1 AND product_id = ' . $productId), $summary);
            self::assertSame(1, self::rowCount('maa_product_image_assignments', 'is_default = 1 AND id = ' . $next), $summary);
            self::assertSame(1, self::rowCount('maa_product_image_assignments', 'display_order = 3 AND id = ' . $current), $summary);
            $statement = self::$pdo->prepare('SELECT display_order FROM maa_product_image_assignments WHERE product_id = :p ORDER BY display_order');
            $statement->execute(['p' => $productId]);
            self::assertSame([1, 2, 3], $statement->fetchAll(PDO::FETCH_COLUMN), $summary);
        }
    }

    /**
     * @param list<string> $outcomes
     *
     * @return list<string> the outcome kinds (`ok`, `fc-inv`, `sku-conflict`, `conflict`, or the full error), sorted
     */
    private static function sortedKinds(array $outcomes): array
    {
        $kinds = array_map(static fn(string $outcome): string => str_starts_with($outcome, 'ok:') ? 'ok' : $outcome, $outcomes);
        sort($kinds);

        return $kinds;
    }
}
