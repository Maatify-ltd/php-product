<?php

declare(strict_types=1);

namespace Maatify\Product\Tests\Integration\Runtime;

use Maatify\Product\Common\Exception\ProductBusinessRuleException;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;
use Maatify\Product\Option\Enum\OptionStatusEnum;
use Maatify\Product\Option\Exception\OptionNotFoundException;
use Maatify\Product\OptionValue\Enum\OptionValueStatusEnum;
use Maatify\Product\OptionValue\Exception\OptionValueNotFoundException;
use PDO;

/**
 * The standalone Option and Option Value status commands (`OPT-02`, `OVL-02`) against
 * real MySQL: the whole configuration is validated with the new status under the Product lock, a
 * change that breaks `INV-01` to `INV-07` writes nothing, the current status is a no-op, a deleted
 * row or owner is rejected, and a successful change writes only `status` and `updated_at`.
 */
final class OptionStatusTest extends RuntimeTestCase
{
    private const string OPTIONS = 'maa_product_options';
    private const string VALUES = 'maa_product_option_values';

    /** @var array{productId: int, color: int, red: int, blue: int, green: int, size: int, m: int} */
    private array $graph;

    public function testAValueOfAnInactiveOptionCanBeActivatedAndDeactivated(): void
    {
        $this->configuredProduct();
        $later = $this->optionValueManagementService(self::laterClock());
        $before = self::row(self::VALUES, $this->graph['m']);

        $later->updateStatus($this->graph['m'], OptionValueStatusEnum::ACTIVE);
        self::assertSame(OptionValueStatusEnum::ACTIVE, $this->optionValueManagementService()->getByIdForManagement($this->graph['m'])->status);
        $after = self::row(self::VALUES, $this->graph['m']);
        self::assertSame(['status' => 'active', 'updated_at' => self::LATER_TS], array_diff_assoc($after, $before), 'Only status and updated_at change.');

        $this->execute('UPDATE maa_product_option_values SET updated_at = :t WHERE id = :i', ['t' => self::SEED_TS, 'i' => $this->graph['m']]);
        $later->updateStatus($this->graph['m'], OptionValueStatusEnum::INACTIVE);
        self::assertSame(OptionValueStatusEnum::INACTIVE, $this->optionValueManagementService()->getByIdForManagement($this->graph['m'])->status);
        self::assertSame(self::LATER_TS, self::scalar('SELECT updated_at FROM maa_product_option_values WHERE id = :i', ['i' => $this->graph['m']]));
    }

    public function testChangesThatBreakTheActiveConfigurationAreRejectedWithoutAWrite(): void
    {
        $this->configuredProduct();
        $options = $this->optionManagementService(self::laterClock());
        $values = $this->optionValueManagementService(self::laterClock());
        // m is active under the inactive size Option, so activating size fails on Variant coverage alone.
        $this->optionValueManagementService()->updateStatus($this->graph['m'], OptionValueStatusEnum::ACTIVE);

        $cases = [
            'deactivating an active Option of an active configuration' => fn() => $options->updateStatus($this->graph['color'], OptionStatusEnum::INACTIVE),
            'activating an Option that the active Variants do not cover' => fn() => $options->updateStatus($this->graph['size'], OptionStatusEnum::ACTIVE),
            'deactivating a Value that an active Variant selects' => fn() => $values->updateStatus($this->graph['red'], OptionValueStatusEnum::INACTIVE),
            'activating a Value of an active Option that no active Variant represents' => fn() => $values->updateStatus($this->graph['green'], OptionValueStatusEnum::ACTIVE),
        ];
        foreach ($cases as $case => $call) {
            foreach ([self::OPTIONS, self::VALUES] as $table) {
                $this->assertNoWrite($table, fn() => $this->assertThrows(ProductBusinessRuleException::class, $call, $case), $case . ' writes nothing to ' . $table . '.');
            }
        }
        self::assertSame(OptionStatusEnum::ACTIVE, $this->optionManagementService()->getByIdForManagement($this->graph['color'])->status);
        self::assertSame(OptionStatusEnum::INACTIVE, $this->optionManagementService()->getByIdForManagement($this->graph['size'])->status);
        self::assertSame(OptionValueStatusEnum::ACTIVE, $this->optionValueManagementService()->getByIdForManagement($this->graph['red'])->status);
        self::assertSame(OptionValueStatusEnum::INACTIVE, $this->optionValueManagementService()->getByIdForManagement($this->graph['green'])->status);
    }

    public function testTheCurrentStatusIsANoOp(): void
    {
        $this->configuredProduct();
        $options = $this->optionManagementService(self::laterClock());
        $values = $this->optionValueManagementService(self::laterClock());

        $this->assertNoWrite(self::OPTIONS, fn() => $options->updateStatus($this->graph['color'], OptionStatusEnum::ACTIVE), 'An active Option asked to be active writes nothing.');
        $this->assertNoWrite(self::OPTIONS, fn() => $options->updateStatus($this->graph['size'], OptionStatusEnum::INACTIVE), 'An inactive Option asked to be inactive writes nothing.');
        $this->assertNoWrite(self::VALUES, fn() => $values->updateStatus($this->graph['red'], OptionValueStatusEnum::ACTIVE), 'An active Value asked to be active writes nothing.');
        $this->assertNoWrite(self::VALUES, fn() => $values->updateStatus($this->graph['green'], OptionValueStatusEnum::INACTIVE), 'An inactive Value asked to be inactive writes nothing.');
    }

    public function testAnOptionChangeThatRestoresAValidConfigurationIsWritten(): void
    {
        $this->configuredProduct();
        // Only direct SQL can leave an active Option that no active Variant covers; deactivating it makes the graph valid again.
        $this->execute('UPDATE maa_product_option_values SET status = \'active\' WHERE id = :i', ['i' => $this->graph['m']]);
        $this->execute('UPDATE maa_product_options SET status = \'active\' WHERE id = :i', ['i' => $this->graph['size']]);
        $before = self::row(self::OPTIONS, $this->graph['size']);

        $this->optionManagementService(self::laterClock())->updateStatus($this->graph['size'], OptionStatusEnum::INACTIVE);

        self::assertSame(['status' => 'inactive', 'updated_at' => self::LATER_TS], array_diff_assoc(self::row(self::OPTIONS, $this->graph['size']), $before), 'Only status and updated_at change.');
        self::assertSame(OptionValueStatusEnum::ACTIVE, $this->optionValueManagementService()->getByIdForManagement($this->graph['m'])->status, 'No cascade to the Values.');
    }

    public function testADeletedRowOrOwnerIsRejected(): void
    {
        $this->configuredProduct();
        $options = $this->optionManagementService();
        $values = $this->optionValueManagementService();

        $values->softDelete($this->graph['green']);
        $this->assertThrows(ProductBusinessRuleException::class, fn() => $values->updateStatus($this->graph['green'], OptionValueStatusEnum::INACTIVE), 'A soft-deleted Value, even for its current status.');

        $options->softDelete($this->graph['size']);
        $this->assertThrows(ProductBusinessRuleException::class, fn() => $options->updateStatus($this->graph['size'], OptionStatusEnum::INACTIVE), 'A soft-deleted Option, even for its current status.');
        $this->assertThrows(ProductBusinessRuleException::class, fn() => $values->updateStatus($this->graph['m'], OptionValueStatusEnum::ACTIVE), 'A Value of a soft-deleted Option.');

        $this->productManagementService()->softDelete($this->graph['productId']);
        $this->assertThrows(ProductBusinessRuleException::class, fn() => $options->updateStatus($this->graph['color'], OptionStatusEnum::ACTIVE), 'An Option of a soft-deleted Product.');
        $this->assertThrows(ProductBusinessRuleException::class, fn() => $values->updateStatus($this->graph['red'], OptionValueStatusEnum::ACTIVE), 'A Value of a soft-deleted Product.');
    }

    public function testUnknownAndNonPositiveIdentities(): void
    {
        $this->assertThrows(OptionNotFoundException::class, fn() => $this->optionManagementService()->updateStatus(999999, OptionStatusEnum::ACTIVE));
        $this->assertThrows(OptionValueNotFoundException::class, fn() => $this->optionValueManagementService()->updateStatus(999999, OptionValueStatusEnum::ACTIVE));
        $this->assertThrows(ProductInvalidArgumentException::class, fn() => $this->optionManagementService()->updateStatus(0, OptionStatusEnum::ACTIVE));
        $this->assertThrows(ProductInvalidArgumentException::class, fn() => $this->optionValueManagementService()->updateStatus(-1, OptionValueStatusEnum::ACTIVE));
    }

    /**
     * A configurable Product: color (red, blue) is active with one active Variant per Value, green is
     * an inactive labelled Value of color, and size (m) is a prepared inactive Option.
     */
    private function configuredProduct(): void
    {
        $productId = $this->namedProduct('CFG')->productId;
        $color = $this->preparedOption($productId, 'color', 'Color');
        $red = $this->preparedValue($color, 'red', 'Red');
        $blue = $this->preparedValue($color, 'blue', 'Blue');
        $this->productManagementService()->reconfigure($this->reconfiguration($productId, [$color => [$red, $blue]], [[[[$color, $red]]], [[[$color, $blue]]]]));
        $green = $this->preparedValue($color, 'green', 'Green');
        $size = $this->preparedOption($productId, 'size', 'Size');
        $m = $this->preparedValue($size, 'm', 'M');
        $this->graph = ['productId' => $productId, 'color' => $color, 'red' => $red, 'blue' => $blue, 'green' => $green, 'size' => $size, 'm' => $m];
    }

    /** @return array<string, int|string|null> */
    private static function row(string $table, int $id): array
    {
        $statement = self::$pdo->prepare('SELECT * FROM `' . $table . '` WHERE id = :i');
        $statement->execute(['i' => $id]);
        /** @var array<string, int|string|null>|false $row */
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        return $row;
    }
}
