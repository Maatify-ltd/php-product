<?php

declare(strict_types=1);

namespace Maatify\Product\Tests\Unit;

use Maatify\Product\Common\Exception\ProductInvalidArgumentException;
use Maatify\Product\ImageAssignment\Management\Command\UpdateImageAssignmentDisplayOrderCommand;
use Maatify\Product\Option\Management\Command\UpdateOptionDisplayOrderCommand;
use Maatify\Product\OptionValue\Management\Command\UpdateOptionValueDisplayOrderCommand;
use Maatify\Product\Variant\Management\Command\UpdateVariantDisplayOrderCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Construction-time validation of the four ordering commands (`FC-VAL`).
 */
final class DisplayOrderCommandTest extends TestCase
{
    public function testValidCommandsCarryTheIdentityAndThePosition(): void
    {
        self::assertSame(['variantId' => 3, 'displayOrder' => 1], (new UpdateVariantDisplayOrderCommand(3, 1))->jsonSerialize());
        self::assertSame(['optionId' => 3, 'displayOrder' => 2], (new UpdateOptionDisplayOrderCommand(3, 2))->jsonSerialize());
        self::assertSame(['optionValueId' => 3, 'displayOrder' => 3], (new UpdateOptionValueDisplayOrderCommand(3, 3))->jsonSerialize());
        self::assertSame(['assignmentId' => 3, 'displayOrder' => PHP_INT_MAX], (new UpdateImageAssignmentDisplayOrderCommand(3, PHP_INT_MAX))->jsonSerialize(), 'A large position is accepted and clamped later.');
    }

    /**
     * @return iterable<string, array{callable(): mixed, string}>
     */
    public static function invalidInputs(): iterable
    {
        yield 'variant id' => [static fn() => new UpdateVariantDisplayOrderCommand(0, 1), 'variantId'];
        yield 'variant position' => [static fn() => new UpdateVariantDisplayOrderCommand(1, 0), 'got 0'];
        yield 'option id' => [static fn() => new UpdateOptionDisplayOrderCommand(-1, 1), 'optionId'];
        yield 'option position' => [static fn() => new UpdateOptionDisplayOrderCommand(1, -5), 'got -5'];
        yield 'value id' => [static fn() => new UpdateOptionValueDisplayOrderCommand(0, 1), 'optionValueId'];
        yield 'value position' => [static fn() => new UpdateOptionValueDisplayOrderCommand(1, 0), 'got 0'];
        yield 'assignment id' => [static fn() => new UpdateImageAssignmentDisplayOrderCommand(0, 1), 'assignmentId'];
        yield 'assignment position' => [static fn() => new UpdateImageAssignmentDisplayOrderCommand(1, 0), 'got 0'];
    }

    /**
     * @param callable(): mixed $construct
     */
    #[DataProvider('invalidInputs')]
    public function testInvalidInputIsRejectedAtConstruction(callable $construct, string $message): void
    {
        $this->expectException(ProductInvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        $construct();
    }
}
