<?php

declare(strict_types=1);

namespace Maatify\Product\Tests\Unit\Product\Command;

use Maatify\Product\Common\Exception\ProductInvalidArgumentException;
use Maatify\Product\Exception\ProductExceptionInterface;
use Maatify\Product\Product\Management\Command\CreateProductCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CreateProductCommandTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function blankCodes(): iterable
    {
        yield 'empty' => [''];
        yield 'space' => [' '];
        yield 'tab' => ["\t"];
        yield 'LF' => ["\n"];
        yield 'CR' => ["\r"];
        yield 'VT' => ["\x0B"];
        yield 'FF' => ["\x0C"];
        yield 'mixed' => [" \t\n"];
        yield 'NBSP' => ["\u{00A0}"];
        yield 'ideographic space' => ["\u{3000}"];
    }

    #[DataProvider('blankCodes')]
    public function testRejectsEmptyAndWhitespaceOnlyCodes(string $code): void
    {
        $this->expectException(ProductInvalidArgumentException::class);
        new CreateProductCommand($code);
    }

    public function testKeepsTheCodeExactlyAsSuppliedWithoutTrimming(): void
    {
        $command = new CreateProductCommand(" A-1\t");

        self::assertSame(" A-1\t", $command->code);
    }

    public function testAcceptsTheMaximumLengthAndRejectsOneMore(): void
    {
        self::assertSame(100, mb_strlen((new CreateProductCommand(str_repeat('a', 100)))->code));

        $this->expectException(ProductInvalidArgumentException::class);
        new CreateProductCommand(str_repeat('a', 101));
    }

    public function testLengthIsCountedInCharactersNotBytes(): void
    {
        self::assertSame(100, mb_strlen((new CreateProductCommand(str_repeat("\u{0639}", 100)))->code));
    }

    public function testRejectsInvalidUtf8(): void
    {
        $this->expectException(ProductInvalidArgumentException::class);
        new CreateProductCommand("abc\xFF");
    }

    public function testFailuresCarryThePackageMarker(): void
    {
        try {
            new CreateProductCommand('');
            self::fail('An empty code must be rejected.');
        } catch (ProductInvalidArgumentException $e) {
            self::assertInstanceOf(ProductExceptionInterface::class, $e);
            self::assertStringContainsString('code', $e->getMessage());
        }
    }

    public function testSerializesToItsCode(): void
    {
        self::assertSame('{"code":"P-1"}', json_encode(new CreateProductCommand('P-1'), JSON_THROW_ON_ERROR));
    }
}
