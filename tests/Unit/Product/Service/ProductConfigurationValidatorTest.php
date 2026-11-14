<?php

declare(strict_types=1);

namespace Maatify\Product\Tests\Unit\Product\Service;

use Maatify\Product\Option\DTO\OptionStateDTO;
use Maatify\Product\OptionValue\DTO\OptionValueStateDTO;
use Maatify\Product\Product\DTO\ProductConfigurationSnapshotDTO;
use Maatify\Product\Product\Service\ProductConfigurationValidator;
use Maatify\Product\Variant\DTO\VariantStateDTO;
use Maatify\Product\Variant\ValueObject\OptionValueSelection;
use PHPUnit\Framework\TestCase;

final class ProductConfigurationValidatorTest extends TestCase
{
    public function testASimpleProductNeedsExactlyOneActiveEmptyVariant(): void
    {
        self::assertNull(ProductConfigurationValidator::firstViolation($this->simple()));
        self::assertStringContainsString('exactly one', (string) ProductConfigurationValidator::firstViolation($this->simple()->withVariant($this->variant(1, [], active: false))));
        self::assertStringContainsString('exactly one', (string) ProductConfigurationValidator::firstViolation($this->simple()->withVariant($this->variant(2, []))));
        self::assertStringContainsString('name', (string) ProductConfigurationValidator::firstViolation($this->simple(hasName: false)->withProduct(true, false)));
        self::assertNull(ProductConfigurationValidator::firstViolation($this->simple(hasName: false)), 'An inactive Product needs no name.');
    }

    public function testAConfigurableProductIsValidatedAgainstEveryInvariant(): void
    {
        $valid = $this->configurable();
        self::assertNull(ProductConfigurationValidator::firstViolation($valid));

        $cases = [
            'unlabelled active Option' => [$valid->withOption(new OptionStateDTO(10, 1, true, false, false)), 'Option [10] has no label'],
            'active Option without active Value' => [$valid->withOptionValue(new OptionValueStateDTO(102, 10, false, false, true))->withOptionValue(new OptionValueStateDTO(101, 10, false, false, true)), 'Option [10] has no active'],
            'unlabelled active Value' => [$valid->withOptionValue(new OptionValueStateDTO(101, 10, true, false, false)), 'Option Value [101] has no label'],
            'Variant selecting an inactive Option' => [$valid->withVariant($this->variant(3, [[10, 101], [20, 201], [30, 301]])), 'Option [30], which is not an active Option'],
            'Variant selecting an inactive Value' => [$valid->withVariant($this->variant(3, [[10, 103], [20, 201]])), 'Option Value [103], which is not an active Value'],
            'Variant with partial coverage' => [$valid->withVariant($this->variant(3, [[10, 101]])), 'Variant [3] has no Value for active Option [20]'],
            'no active Variant' => [$valid->withVariant($this->variant(1, [[10, 101], [20, 201]], active: false))->withVariant($this->variant(2, [[10, 102], [20, 201]], active: false)), 'at least one active Variant'],
            'unrepresented active Value' => [$valid->withVariant($this->variant(2, [[10, 102], [20, 201]], deleted: true)), 'Option Value [102] of Option [10] is not represented'],
            'active Product without a name' => [$valid->withProduct(true, false), 'name'],
        ];
        foreach ($cases as $case => [$snapshot, $expected]) {
            self::assertStringContainsString($expected, (string) ProductConfigurationValidator::firstViolation($snapshot), $case);
        }

        $retired = $valid->withOption(new OptionStateDTO(30, 1, false, true, true))->withOptionValue(new OptionValueStateDTO(301, 30, true, false, false));
        self::assertNull(ProductConfigurationValidator::firstViolation($retired), 'Values of an inactive or deleted Option are history and are not evaluated.');
    }

    private function simple(bool $hasName = true): ProductConfigurationSnapshotDTO
    {
        return new ProductConfigurationSnapshotDTO(1, false, false, $hasName, [], [], [1 => $this->variant(1, [])]);
    }

    /**
     * Options 10 (values 101, 102 active; 103 inactive) and 20 (value 201), plus an inactive Option 30 with value 301;
     * Variants 1 = (10:101, 20:201) and 2 = (10:102, 20:201), both active; the Product is inactive and has no name.
     */
    private function configurable(): ProductConfigurationSnapshotDTO
    {
        return new ProductConfigurationSnapshotDTO(
            productId: 1,
            productActive: false,
            productDeleted: false,
            hasName: false,
            options: [
                10 => new OptionStateDTO(10, 1, true, false, true),
                20 => new OptionStateDTO(20, 1, true, false, true),
                30 => new OptionStateDTO(30, 1, false, false, true),
            ],
            optionValues: [
                101 => new OptionValueStateDTO(101, 10, true, false, true),
                102 => new OptionValueStateDTO(102, 10, true, false, true),
                103 => new OptionValueStateDTO(103, 10, false, false, true),
                201 => new OptionValueStateDTO(201, 20, true, false, true),
                301 => new OptionValueStateDTO(301, 30, true, false, true),
            ],
            variants: [
                1 => $this->variant(1, [[10, 101], [20, 201]]),
                2 => $this->variant(2, [[10, 102], [20, 201]]),
            ],
        );
    }

    /**
     * @param list<array{int, int}> $pairs
     */
    private function variant(int $id, array $pairs, bool $active = true, bool $deleted = false): VariantStateDTO
    {
        return new VariantStateDTO(
            $id,
            1,
            hash('sha256', 'test|' . $id),
            $active,
            $deleted,
            array_map(static fn(array $pair): OptionValueSelection => new OptionValueSelection($pair[0], $pair[1]), $pairs),
        );
    }
}
