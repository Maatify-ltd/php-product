<?php

declare(strict_types=1);

namespace Maatify\Product\Tests\Unit\Product\Command;

use Maatify\Product\Common\Exception\ProductInvalidArgumentException;
use Maatify\Product\Product\Management\Command\ReconfigureOptionTarget;
use Maatify\Product\Product\Management\Command\ReconfigureProductCommand;
use Maatify\Product\Product\Management\Command\ReconfigureVariantTarget;
use Maatify\Product\Variant\ValueObject\OptionValueSelection;
use PHPUnit\Framework\TestCase;

final class ReconfigureProductCommandTest extends TestCase
{
    public function testTargetsAreCanonicalizedAndExposed(): void
    {
        $option = new ReconfigureOptionTarget(7, [30, 10, 20]);
        self::assertSame([10, 20, 30], $option->activeOptionValueIds);

        $variant = new ReconfigureVariantTarget([new OptionValueSelection(9, 1), new OptionValueSelection(7, 2)], 55);
        self::assertSame([[7, 2], [9, 1]], array_map(static fn(OptionValueSelection $s): array => [$s->optionId, $s->optionValueId], $variant->composition));
        self::assertSame('v1|7=2|9=1', $variant->fingerprint()->canonical);
        self::assertSame(55, $variant->reuseVariantId);

        $command = new ReconfigureProductCommand(3, 1, [$option], [$variant, new ReconfigureVariantTarget([])]);
        self::assertSame(3, $command->productId);
        self::assertCount(1, $command->options);
        self::assertCount(2, $command->variants);
        $expected = ['productId' => 3, 'expectedRevision' => 1, 'options' => [['optionId' => 7, 'activeOptionValueIds' => [10, 20, 30]]], 'variants' => [
            ['composition' => [['optionId' => 7, 'optionValueId' => 2], ['optionId' => 9, 'optionValueId' => 1]], 'reuseVariantId' => 55],
            ['composition' => [], 'reuseVariantId' => null],
        ]];
        self::assertSame($expected, $command->jsonSerialize());
    }

    public function testInvalidInputIsRejectedAtConstruction(): void
    {
        $cases = [
            'non-positive revision' => static fn() => new ReconfigureProductCommand(1, 0, [], []),
            'non-positive product' => static fn() => new ReconfigureProductCommand(0, 1, [], []),
            'non-positive option' => static fn() => new ReconfigureOptionTarget(0, [1]),
            'empty value list' => static fn() => new ReconfigureOptionTarget(1, []),
            'non-positive value' => static fn() => new ReconfigureOptionTarget(1, [0]),
            'repeated value in one option' => static fn() => new ReconfigureOptionTarget(1, [2, 2]),
            'non-positive reuse identity' => static fn() => new ReconfigureVariantTarget([], 0),
            'repeated option in a composition' => static fn() => new ReconfigureVariantTarget([new OptionValueSelection(1, 1), new OptionValueSelection(1, 2)]),
            'repeated option across targets' => static fn() => new ReconfigureProductCommand(1, 1, [new ReconfigureOptionTarget(1, [1]), new ReconfigureOptionTarget(1, [2])], []),
            'value under two options' => static fn() => new ReconfigureProductCommand(1, 1, [new ReconfigureOptionTarget(1, [5]), new ReconfigureOptionTarget(2, [5])], []),
            'repeated composition in any order' => static fn() => new ReconfigureProductCommand(1, 1, [], [
                new ReconfigureVariantTarget([new OptionValueSelection(1, 1), new OptionValueSelection(2, 2)]),
                new ReconfigureVariantTarget([new OptionValueSelection(2, 2), new OptionValueSelection(1, 1)]),
            ]),
            'repeated empty composition' => static fn() => new ReconfigureProductCommand(1, 1, [], [new ReconfigureVariantTarget([]), new ReconfigureVariantTarget([])]),
            'repeated reuse identity' => static fn() => new ReconfigureProductCommand(1, 1, [], [
                new ReconfigureVariantTarget([new OptionValueSelection(1, 1)], 9),
                new ReconfigureVariantTarget([new OptionValueSelection(1, 2)], 9),
            ]),
        ];
        foreach ($cases as $case => $call) {
            try {
                $call();
                self::fail($case . ' must be rejected.');
            } catch (ProductInvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
