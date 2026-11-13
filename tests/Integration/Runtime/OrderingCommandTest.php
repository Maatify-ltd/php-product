<?php

declare(strict_types=1);

namespace Maatify\Product\Tests\Integration\Runtime;

use Maatify\Product\Common\Exception\ProductBusinessRuleException;
use Maatify\Product\Factory\ProductFactory;
use Maatify\Product\ImageAssignment\Exception\ImageAssignmentNotFoundException;
use Maatify\Product\ImageAssignment\Management\Command\CreateImageAssignmentCommand;
use Maatify\Product\ImageAssignment\Management\Command\UpdateImageAssignmentDisplayOrderCommand;
use Maatify\Product\Option\Exception\OptionNotFoundException;
use Maatify\Product\Option\Management\Command\CreateOptionCommand;
use Maatify\Product\Option\Management\Command\UpdateOptionDisplayOrderCommand;
use Maatify\Product\OptionValue\Exception\OptionValueNotFoundException;
use Maatify\Product\OptionValue\Management\Command\CreateOptionValueCommand;
use Maatify\Product\OptionValue\Management\Command\UpdateOptionValueDisplayOrderCommand;
use Maatify\Product\Variant\Exception\VariantNotFoundException;
use Maatify\Product\Variant\Management\Command\UpdateVariantDisplayOrderCommand;

/**
 * The ordering commands (`VAR-05`, `OPT-05`, `OVL-05`, `IAS-06`) and the restore position
 * against real MySQL: moves go through the shared Ordering API within one scope only, clamp above
 * the maximum, leave the current position unchanged, respect the lifecycle contract, compose with
 * a Host transaction, and a restored row takes the end of its live scope so positions stay unique.
 */
final class OrderingCommandTest extends RuntimeTestCase
{
    public function testOptionsMoveWithinTheirProductOnly(): void
    {
        $p = $this->namedProduct('P');
        $q = $this->namedProduct('Q');
        [$o1, $o2, $o3] = $this->options($p->productId, ['a', 'b', 'c']);
        [$q1, $q2] = $this->options($q->productId, ['a', 'b']);
        $service = $this->optionManagementService();

        $service->updateDisplayOrder(new UpdateOptionDisplayOrderCommand($o3, 1));
        self::assertSame([$o3 => 1, $o1 => 2, $o2 => 3], $this->positions('maa_product_options', 'product_id', $p->productId), 'Moving up shifts the others down.');
        $service->updateDisplayOrder(new UpdateOptionDisplayOrderCommand($o3, 99));
        self::assertSame([$o1 => 1, $o2 => 2, $o3 => 3], $this->positions('maa_product_options', 'product_id', $p->productId), 'A position above the maximum is clamped.');
        $this->assertNoWrite('maa_product_options', fn() => $this->optionManagementService(self::laterClock())->updateDisplayOrder(new UpdateOptionDisplayOrderCommand($o2, 2)), 'The current position changes nothing.');
        $this->optionManagementService(self::laterClock())->updateDisplayOrder(new UpdateOptionDisplayOrderCommand($o1, 2));
        self::assertSame([$o2 => 1, $o1 => 2, $o3 => 3], $this->positions('maa_product_options', 'product_id', $p->productId), 'Moving down shifts the others up.');
        self::assertSame(self::LATER_TS, self::scalar('SELECT updated_at FROM maa_product_options WHERE id = :i', ['i' => $o1]), 'The moved row is stamped.');
        self::assertSame([$q1 => 1, $q2 => 2], $this->positions('maa_product_options', 'product_id', $q->productId), 'Another Product is untouched.');

        $this->assertThrows(OptionNotFoundException::class, fn() => $service->updateDisplayOrder(new UpdateOptionDisplayOrderCommand(999999, 1)));
        $service->softDelete($o3);
        $this->assertThrows(ProductBusinessRuleException::class, fn() => $service->updateDisplayOrder(new UpdateOptionDisplayOrderCommand($o3, 1)), 'A deleted Option cannot move.');
        $this->productManagementService()->softDelete($q->productId);
        $this->assertThrows(ProductBusinessRuleException::class, fn() => $service->updateDisplayOrder(new UpdateOptionDisplayOrderCommand($q1, 2)), 'An Option of a deleted Product cannot move.');
    }

    public function testOptionValuesMoveWithinTheirOptionOnly(): void
    {
        $p = $this->namedProduct('P');
        [$color, $size] = $this->options($p->productId, ['color', 'size']);
        [$v1, $v2, $v3] = $this->values($color, ['red', 'green', 'blue']);
        [$s1, $s2] = $this->values($size, ['s', 'm']);
        $service = $this->optionValueManagementService();

        $service->updateDisplayOrder(new UpdateOptionValueDisplayOrderCommand($v3, 1));
        self::assertSame([$v3 => 1, $v1 => 2, $v2 => 3], $this->positions('maa_product_option_values', 'option_id', $color));
        $service->updateDisplayOrder(new UpdateOptionValueDisplayOrderCommand($v3, 50));
        self::assertSame([$v1 => 1, $v2 => 2, $v3 => 3], $this->positions('maa_product_option_values', 'option_id', $color));
        $this->assertNoWrite('maa_product_option_values', fn() => $this->optionValueManagementService(self::laterClock())->updateDisplayOrder(new UpdateOptionValueDisplayOrderCommand($v1, 1)), 'The current position changes nothing.');
        $this->optionValueManagementService(self::laterClock())->updateDisplayOrder(new UpdateOptionValueDisplayOrderCommand($v1, 3));
        self::assertSame(self::LATER_TS, self::scalar('SELECT updated_at FROM maa_product_option_values WHERE id = :i', ['i' => $v1]));
        self::assertSame([$s1 => 1, $s2 => 2], $this->positions('maa_product_option_values', 'option_id', $size), 'Another Option is untouched.');

        $this->assertThrows(OptionValueNotFoundException::class, fn() => $service->updateDisplayOrder(new UpdateOptionValueDisplayOrderCommand(999999, 1)));
        $service->softDelete($v2);
        $this->assertThrows(ProductBusinessRuleException::class, fn() => $service->updateDisplayOrder(new UpdateOptionValueDisplayOrderCommand($v2, 1)));
        $this->optionManagementService()->softDelete($size);
        $this->assertThrows(ProductBusinessRuleException::class, fn() => $service->updateDisplayOrder(new UpdateOptionValueDisplayOrderCommand($s2, 1)), 'A Value of a deleted Option cannot move.');
    }

    public function testVariantsMoveWithinTheirProductOnly(): void
    {
        $p = $this->namedProduct('P');
        $q = $this->namedProduct('Q');
        [$color] = $this->options($p->productId, ['color'], labelled: true);
        [$red, $green, $blue] = $this->values($color, ['red', 'green', 'blue'], labelled: true);
        $this->productManagementService()->reconfigure(self::reconfiguration($p->productId, [$color => [$red, $green, $blue]], [[[[$color, $red]]], [[[$color, $green]]], [[[$color, $blue]]]]));
        $ids = array_keys($this->positions('maa_product_variants', 'product_id', $p->productId));
        [$v0, $vr, $vg, $vb] = $ids;
        $service = $this->variantManagementService();

        $service->updateDisplayOrder(new UpdateVariantDisplayOrderCommand($vb, 1));
        self::assertSame([$vb => 1, $v0 => 2, $vr => 3, $vg => 4], $this->positions('maa_product_variants', 'product_id', $p->productId), 'An inactive Variant keeps its place in the order.');
        $service->updateDisplayOrder(new UpdateVariantDisplayOrderCommand($vb, 1000));
        self::assertSame([$v0 => 1, $vr => 2, $vg => 3, $vb => 4], $this->positions('maa_product_variants', 'product_id', $p->productId));
        $this->assertNoWrite('maa_product_variants', fn() => $this->variantManagementService(self::laterClock())->updateDisplayOrder(new UpdateVariantDisplayOrderCommand($vr, 2)), 'The current position changes nothing.');
        $this->variantManagementService(self::laterClock())->updateDisplayOrder(new UpdateVariantDisplayOrderCommand($vr, 1));
        self::assertSame(self::LATER_TS, self::scalar('SELECT updated_at FROM maa_product_variants WHERE id = :i', ['i' => $vr]));
        self::assertSame([$q->variantId => 1], $this->positions('maa_product_variants', 'product_id', $q->productId), 'Another Product is untouched.');

        $this->assertThrows(VariantNotFoundException::class, fn() => $service->updateDisplayOrder(new UpdateVariantDisplayOrderCommand(999999, 1)));
        $service->softDelete($v0);
        $this->assertThrows(ProductBusinessRuleException::class, fn() => $service->updateDisplayOrder(new UpdateVariantDisplayOrderCommand($v0, 1)), 'A deleted Variant cannot move.');
        $this->productManagementService()->softDelete($q->productId);
        $this->assertThrows(ProductBusinessRuleException::class, fn() => $service->updateDisplayOrder(new UpdateVariantDisplayOrderCommand($q->variantId, 1)), 'A Variant of a deleted Product cannot move.');
    }

    public function testAssignmentsMoveWithinTheirExactScopeOnly(): void
    {
        $p = $this->namedProduct('P');
        $q = $this->namedProduct('Q');
        $service = $this->imageAssignmentManagementService();
        [$a1, $a2, $a3] = array_map(fn(int $media): int => $service->create(new CreateImageAssignmentCommand($p->productId, null, $media))->assignmentId, [1, 2, 3]);
        [$e1, $e2] = array_map(fn(int $media): int => $service->create(new CreateImageAssignmentCommand($p->productId, null, $media, null, 'en'))->assignmentId, [1, 2]);
        [$v1, $v2] = array_map(fn(int $media): int => $service->create(new CreateImageAssignmentCommand($p->productId, $p->variantId, $media))->assignmentId, [1, 2]);
        $other = $service->create(new CreateImageAssignmentCommand($q->productId, null, 1))->assignmentId;

        $service->updateDisplayOrder(new UpdateImageAssignmentDisplayOrderCommand($a3, 1));
        self::assertSame(self::byId([$a3 => 1, $a1 => 2, $a2 => 3]), $this->assignmentPositions([$a1, $a2, $a3]));
        $service->updateDisplayOrder(new UpdateImageAssignmentDisplayOrderCommand($a3, 7));
        self::assertSame(self::byId([$a1 => 1, $a2 => 2, $a3 => 3]), $this->assignmentPositions([$a1, $a2, $a3]));
        $this->assertNoWrite('maa_product_image_assignments', fn() => $this->imageAssignmentManagementService(self::laterClock())->updateDisplayOrder(new UpdateImageAssignmentDisplayOrderCommand($a2, 2)), 'The current position changes nothing.');
        $this->imageAssignmentManagementService(self::laterClock())->updateDisplayOrder(new UpdateImageAssignmentDisplayOrderCommand($a1, 3));
        self::assertSame(self::LATER_TS, self::scalar('SELECT updated_at FROM maa_product_image_assignments WHERE id = :i', ['i' => $a1]));
        self::assertSame(self::byId([$e1 => 1, $e2 => 2, $v1 => 1, $v2 => 2, $other => 1]), $this->assignmentPositions([$e1, $e2, $v1, $v2, $other]), 'Another exact scope (language, Variant target, Product) is untouched, NULL scopes included.');
        $service->updateDisplayOrder(new UpdateImageAssignmentDisplayOrderCommand($v2, 1));
        self::assertSame(self::byId([$a2 => 1, $a3 => 2, $a1 => 3, $v2 => 1, $v1 => 2]), $this->assignmentPositions([$a1, $a2, $a3, $v1, $v2]), 'The Product-level scope is untouched by a Variant-level move.');

        $this->assertThrows(ImageAssignmentNotFoundException::class, fn() => $service->updateDisplayOrder(new UpdateImageAssignmentDisplayOrderCommand(999999, 1)));
        $service->softDelete($e2);
        $this->assertThrows(ProductBusinessRuleException::class, fn() => $service->updateDisplayOrder(new UpdateImageAssignmentDisplayOrderCommand($e2, 1)), 'A deleted assignment cannot move.');
        $this->productManagementService()->softDelete($q->productId);
        $this->assertThrows(ProductBusinessRuleException::class, fn() => $service->updateDisplayOrder(new UpdateImageAssignmentDisplayOrderCommand($other, 1)), 'An assignment of a deleted Product cannot move.');
    }

    public function testAMoveInsideAHostTransactionIsRolledBackWithIt(): void
    {
        $p = $this->namedProduct('P');
        [$o1, $o2, $o3] = $this->options($p->productId, ['a', 'b', 'c']);
        $service = $this->optionManagementService();

        self::$pdo->beginTransaction();
        try {
            $service->updateDisplayOrder(new UpdateOptionDisplayOrderCommand($o3, 1));
            self::assertTrue(self::$pdo->inTransaction(), 'The Host transaction stays open.');
            self::assertSame([$o3 => 1, $o1 => 2, $o2 => 3], $this->positions('maa_product_options', 'product_id', $p->productId));
        } finally {
            self::$pdo->rollBack();
        }
        self::assertSame([$o1 => 1, $o2 => 2, $o3 => 3], $this->positions('maa_product_options', 'product_id', $p->productId), 'The Host rollback removes the move.');

        self::$pdo->beginTransaction();
        $service->updateDisplayOrder(new UpdateOptionDisplayOrderCommand($o1, 3));
        self::$pdo->commit();
        self::assertSame([$o2 => 1, $o3 => 2, $o1 => 3], $this->positions('maa_product_options', 'product_id', $p->productId));
    }

    public function testAllocatingAnOptionUsesCurrentOrderingInsideAnOlderHostSnapshot(): void
    {
        $product = $this->namedProduct('P');
        [$first] = $this->options($product->productId, ['first']);
        $writer = ProductFactory::createOptionManagementService(self::connect(self::$database), self::clock());

        self::$pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        self::$pdo->beginTransaction();
        try {
            self::assertSame([$first => 1], $this->positions('maa_product_options', 'product_id', $product->productId));
            $second = $writer->create(new CreateOptionCommand($product->productId, 'second'))->optionId;
            $third = $this->optionManagementService()->create(new CreateOptionCommand($product->productId, 'third'))->optionId;
            self::assertTrue(self::$pdo->inTransaction());
            self::$pdo->commit();
        } finally {
            if (self::$pdo->inTransaction()) {
                self::$pdo->rollBack();
            }
        }

        self::assertSame(
            [$first => 1, $second => 2, $third => 3],
            $this->positions('maa_product_options', 'product_id', $product->productId),
        );
    }

    public function testARestoredRowTakesTheEndOfItsLiveScope(): void
    {
        $p = $this->namedProduct('P');
        [$o1, $o2, $o3] = $this->options($p->productId, ['a', 'b', 'c']);
        $this->optionManagementService()->softDelete($o3);
        [$o4] = $this->options($p->productId, ['d']);
        $this->optionManagementService()->restore($o3);
        self::assertSame([$o1 => 1, $o2 => 2, $o4 => 3, $o3 => 4], $this->positions('maa_product_options', 'product_id', $p->productId), 'The new Option took the free position; the restored one goes last.');

        [$v1, $v2] = $this->values($o1, ['x', 'y']);
        $this->optionValueManagementService()->softDelete($v2);
        [$v3] = $this->values($o1, ['z']);
        $this->optionValueManagementService()->restore($v2);
        self::assertSame([$v1 => 1, $v3 => 2, $v2 => 3], $this->positions('maa_product_option_values', 'option_id', $o1));

        $assignments = $this->imageAssignmentManagementService();
        $a1 = $assignments->create(new CreateImageAssignmentCommand($p->productId, null, 1))->assignmentId;
        $a2 = $assignments->create(new CreateImageAssignmentCommand($p->productId, null, 2))->assignmentId;
        $assignments->softDelete($a2);
        $a3 = $assignments->create(new CreateImageAssignmentCommand($p->productId, null, 3))->assignmentId;
        $assignments->restore($a2);
        self::assertSame(self::byId([$a1 => 1, $a3 => 2, $a2 => 3]), $this->assignmentPositions([$a1, $a2, $a3]));
        $assignments->updateDisplayOrder(new UpdateImageAssignmentDisplayOrderCommand($a2, 1));
        self::assertSame(self::byId([$a2 => 1, $a1 => 2, $a3 => 3]), $this->assignmentPositions([$a1, $a2, $a3]), 'Moves stay contiguous after a restore.');
    }

    public function testARestoredVariantTakesTheEndOfItsProductThroughRestoreAndReconfiguration(): void
    {
        $p = $this->namedProduct('P');
        [$color] = $this->options($p->productId, ['color'], labelled: true);
        [$red, $blue] = $this->values($color, ['red', 'blue'], labelled: true);
        $this->productManagementService()->reconfigure(self::reconfiguration($p->productId, [$color => [$red]], [[[[$color, $red]]]]));
        [$v0, $vr] = array_keys($this->positions('maa_product_variants', 'product_id', $p->productId));

        $variants = $this->variantManagementService();
        $variants->softDelete($v0);
        $variants->restore($v0);
        self::assertSame([$vr => 2, $v0 => 3], $this->positions('maa_product_variants', 'product_id', $p->productId), 'Restore places the Variant last.');

        $variants->softDelete($v0);
        $this->productManagementService()->reconfigure(self::reconfiguration($p->productId, [$color => [$red, $blue]], [[[[$color, $red]], $vr], [[[$color, $blue]]]]));
        $vb = array_key_last($this->positions('maa_product_variants', 'product_id', $p->productId));
        $this->productManagementService()->reconfigure(self::reconfiguration($p->productId, [], [[[], $v0]]));
        self::assertSame([$vr => 2, $vb => 3, $v0 => 4], $this->positions('maa_product_variants', 'product_id', $p->productId), 'A Variant restored by reconfiguration goes last, never onto a live position.');
    }

    private static function int(mixed $value): int
    {
        self::assertTrue(is_int($value) || is_string($value));

        return (int) $value;
    }

    /**
     * @param array<int, int> $positions
     *
     * @return array<int, int>
     */
    private static function byId(array $positions): array
    {
        ksort($positions);

        return $positions;
    }

    /**
     * @param list<string> $codes
     *
     * @return list<int>
     */
    private function options(int $productId, array $codes, bool $labelled = false): array
    {
        return array_map(
            fn(string $code): int => $labelled
                ? $this->preparedOption($productId, $code, ucfirst($code))
                : $this->optionManagementService()->create(new CreateOptionCommand($productId, $code))->optionId,
            $codes,
        );
    }

    /**
     * @param list<string> $codes
     *
     * @return list<int>
     */
    private function values(int $optionId, array $codes, bool $labelled = false): array
    {
        return array_map(
            fn(string $code): int => $labelled
                ? $this->preparedValue($optionId, $code, ucfirst($code))
                : $this->optionValueManagementService()->create(new CreateOptionValueCommand($optionId, $code))->optionValueId,
            $codes,
        );
    }

    /**
     * Live positions of one scope, keyed by identity in position order.
     *
     * @return array<int, int>
     */
    private function positions(string $table, string $scopeColumn, int $scope): array
    {
        $statement = self::$pdo->prepare(
            'SELECT id, display_order FROM `' . $table . '` WHERE `' . $scopeColumn . '` = :s AND deleted_at IS NULL ORDER BY display_order, id',
        );
        $statement->execute(['s' => $scope]);
        $positions = [];
        foreach ($statement->fetchAll(\PDO::FETCH_NUM) as $row) {
            self::assertIsArray($row);
            $positions[self::int($row[0])] = self::int($row[1]);
        }

        return $positions;
    }

    /**
     * Positions of the given live assignments, keyed and ordered by identity.
     *
     * @param list<int> $ids
     *
     * @return array<int, int>
     */
    private function assignmentPositions(array $ids): array
    {
        $statement = self::$pdo->prepare(
            'SELECT id, display_order FROM maa_product_image_assignments WHERE deleted_at IS NULL AND id IN ('
            . implode(',', array_map('intval', $ids)) . ') ORDER BY id',
        );
        $statement->execute();
        $positions = [];
        foreach ($statement->fetchAll(\PDO::FETCH_NUM) as $row) {
            self::assertIsArray($row);
            $positions[self::int($row[0])] = self::int($row[1]);
        }

        return $positions;
    }
}
