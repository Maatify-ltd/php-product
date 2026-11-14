<?php

declare(strict_types=1);

namespace Maatify\Product\Tests\Unit\Variant\Command;

use Maatify\Product\Common\Exception\ProductInvalidArgumentException;
use Maatify\Product\Variant\Management\Command\UpdateVariantIdentifiersCommand;
use Maatify\Product\Variant\ValueObject\VariantIdentifierChange;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The three exclusive states of a Variant identifier change and the command that carries two of them.
 */
final class UpdateVariantIdentifiersCommandTest extends TestCase
{
    public function testTheThreeStatesResolveAgainstTheStoredValue(): void
    {
        $unchanged = VariantIdentifierChange::unchanged();
        $set = VariantIdentifierChange::set(' 00042 ');
        $clear = VariantIdentifierChange::clear();

        self::assertSame([true, false, false, null], [$unchanged->isUnchanged(), $unchanged->isSet(), $unchanged->isClear(), $unchanged->value()]);
        self::assertSame([false, true, false, ' 00042 '], [$set->isUnchanged(), $set->isSet(), $set->isClear(), $set->value()], 'A value is kept verbatim.');
        self::assertSame([false, false, true, null], [$clear->isUnchanged(), $clear->isSet(), $clear->isClear(), $clear->value()]);
        self::assertSame(['old', ' 00042 ', null], [$unchanged->resolve('old'), $set->resolve('old'), $clear->resolve('old')]);
        self::assertNull($unchanged->resolve(null));
        self::assertNotEquals($unchanged->jsonSerialize(), $clear->jsonSerialize(), 'Unchanged and clear are different intents.');
        self::assertSame(str_repeat('é', 128), VariantIdentifierChange::set(str_repeat('é', 128))->value());
    }

    public function testTheCommandCarriesBothChanges(): void
    {
        $command = new UpdateVariantIdentifiersCommand(7, VariantIdentifierChange::set('A'), VariantIdentifierChange::clear());

        self::assertSame(
            ['variantId' => 7, 'sku' => ['change' => 'set', 'value' => 'A'], 'barcode' => ['change' => 'clear', 'value' => null]],
            $command->jsonSerialize(),
        );
    }

    /**
     * @return iterable<string, array{callable(): mixed}>
     */
    public static function invalidInputs(): iterable
    {
        yield 'empty value' => [static fn() => VariantIdentifierChange::set('')];
        yield 'unicode whitespace value' => [static fn() => VariantIdentifierChange::set("\u{00A0}\u{2003} ")];
        yield 'long value' => [static fn() => VariantIdentifierChange::set(str_repeat('a', 129))];
        yield 'invalid utf-8 value' => [static fn() => VariantIdentifierChange::set("\xFF")];
        yield 'variant id' => [static fn() => new UpdateVariantIdentifiersCommand(0, VariantIdentifierChange::unchanged(), VariantIdentifierChange::unchanged())];
    }

    /**
     * @param callable(): mixed $construct
     */
    #[DataProvider('invalidInputs')]
    public function testInvalidInputIsRejectedAtConstruction(callable $construct): void
    {
        $this->expectException(ProductInvalidArgumentException::class);
        $construct();
    }
}
