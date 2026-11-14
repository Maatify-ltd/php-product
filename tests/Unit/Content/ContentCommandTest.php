<?php

declare(strict_types=1);

namespace Maatify\Product\Tests\Unit\Content;

use Maatify\Product\Common\Enum\ProductDeletedStateEnum;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;
use Maatify\Product\Content\Management\Command\CreateOptionContentCommand;
use Maatify\Product\Content\Management\Command\CreateOptionValueContentCommand;
use Maatify\Product\Content\Management\Command\CreateProductContentCommand;
use Maatify\Product\Content\Management\Command\UpdateOptionContentCommand;
use Maatify\Product\Content\Management\Command\UpdateOptionValueContentCommand;
use Maatify\Product\Content\Management\Command\UpdateProductContentCommand;
use Maatify\Product\Content\Management\Criteria\OptionContentListCriteriaDTO;
use Maatify\Product\Content\Management\Criteria\OptionValueContentListCriteriaDTO;
use Maatify\Product\Content\Management\Criteria\ProductContentListCriteriaDTO;
use Maatify\Product\Content\ValueObject\ContentLanguageFilter;
use Maatify\Product\Content\ValueObject\ExactContentLanguage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Construction-time validation of the Content commands, criteria, and language selectors (`FC-VAL`).
 */
final class ContentCommandTest extends TestCase
{
    public function testValidValuesAreKeptVerbatimAtTheirLimits(): void
    {
        $name = str_repeat('é', 255);
        $command = new CreateProductContentCommand(1, str_repeat('a', 16), $name, str_repeat('ب', 500), str_repeat('x', 65535));
        self::assertSame($name, $command->name);
        self::assertSame(str_repeat('a', 16), $command->languageCode);

        $padded = new CreateProductContentCommand(1, ' en ', '  Name  ', '', '');
        self::assertSame([' en ', '  Name  ', '', ''], [$padded->languageCode, $padded->name, $padded->shortDescription, $padded->description], 'Nothing is trimmed and empty optional fields are kept.');
        self::assertNull((new CreateProductContentCommand(1, null, 'N'))->languageCode);
        self::assertSame(
            ['productId' => 1, 'languageCode' => null, 'name' => 'N', 'shortDescription' => null, 'description' => null],
            (new CreateProductContentCommand(1, null, 'N'))->jsonSerialize(),
        );
        self::assertSame(['contentId' => 3, 'name' => 'N', 'shortDescription' => null, 'description' => ''], (new UpdateProductContentCommand(3, 'N', null, ''))->jsonSerialize());
        self::assertSame(['optionId' => 2, 'languageCode' => 'ar', 'name' => 'لون'], (new CreateOptionContentCommand(2, 'ar', 'لون'))->jsonSerialize());
        self::assertSame(['optionValueId' => 2, 'languageCode' => null, 'name' => 'Red'], (new CreateOptionValueContentCommand(2, null, 'Red'))->jsonSerialize());
        self::assertSame(['contentId' => 4, 'name' => 'Red'], (new UpdateOptionValueContentCommand(4, 'Red'))->jsonSerialize());
        self::assertSame(['contentId' => 4, 'name' => 'Color'], (new UpdateOptionContentCommand(4, 'Color'))->jsonSerialize());
    }

    /**
     * @return iterable<string, array{callable(): mixed}>
     */
    public static function invalidInputs(): iterable
    {
        $tooLongName = str_repeat('é', 256);
        $blank = "\u{00A0}\u{2003}\t ";

        yield 'product id' => [static fn() => new CreateProductContentCommand(0, null, 'N')];
        yield 'option id' => [static fn() => new CreateOptionContentCommand(-1, null, 'N')];
        yield 'value id' => [static fn() => new CreateOptionValueContentCommand(0, null, 'N')];
        yield 'content id' => [static fn() => new UpdateProductContentCommand(0, 'N', null, null)];
        yield 'option content id' => [static fn() => new UpdateOptionContentCommand(0, 'N')];
        yield 'value content id' => [static fn() => new UpdateOptionValueContentCommand(0, 'N')];
        yield 'empty name' => [static fn() => new CreateProductContentCommand(1, null, '')];
        yield 'unicode whitespace name' => [static fn() => new CreateOptionContentCommand(1, null, $blank)];
        yield 'long name' => [static fn() => new CreateOptionValueContentCommand(1, null, $tooLongName)];
        yield 'long updated name' => [static fn() => new UpdateOptionContentCommand(1, $tooLongName)];
        yield 'blank updated name' => [static fn() => new UpdateOptionValueContentCommand(1, $blank)];
        yield 'invalid utf-8 name' => [static fn() => new CreateProductContentCommand(1, null, "\xC3\x28")];
        yield 'empty language' => [static fn() => new CreateProductContentCommand(1, '', 'N')];
        yield 'unicode whitespace language' => [static fn() => new CreateOptionContentCommand(1, "\u{3000}", 'N')];
        yield 'long language' => [static fn() => new CreateOptionValueContentCommand(1, str_repeat('a', 17), 'N')];
        yield 'long short description' => [static fn() => new CreateProductContentCommand(1, null, 'N', str_repeat('ب', 501))];
        yield 'long description' => [static fn() => new UpdateProductContentCommand(1, 'N', null, str_repeat('é', 32768))];
        yield 'invalid utf-8 description' => [static fn() => new CreateProductContentCommand(1, null, 'N', null, "\xFF")];
        yield 'exact filter language' => [static fn() => ContentLanguageFilter::exactLanguage(' ')];
        yield 'exact consumer language' => [static fn() => ExactContentLanguage::exactLanguage(str_repeat('a', 17))];
        yield 'criteria owner' => [static fn() => new ProductContentListCriteriaDTO(0, ContentLanguageFilter::allLanguages())];
        yield 'criteria max' => [static fn() => new OptionContentListCriteriaDTO(1, ContentLanguageFilter::allLanguages(), ProductDeletedStateEnum::NON_DELETED, 101)];
        yield 'criteria min' => [static fn() => new OptionValueContentListCriteriaDTO(1, ContentLanguageFilter::allLanguages(), ProductDeletedStateEnum::NON_DELETED, 0)];
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

    public function testTheManagementLanguageFilterHasThreeExclusiveStates(): void
    {
        $exact = ContentLanguageFilter::exactLanguage('ar');
        $unlocalized = ContentLanguageFilter::unlocalizedOnly();
        $all = ContentLanguageFilter::allLanguages();

        self::assertSame([true, false, false, 'ar'], [$exact->isExactLanguage(), $exact->isUnlocalizedOnly(), $exact->isAllLanguages(), $exact->languageCode()]);
        self::assertSame([false, true, false, null], [$unlocalized->isExactLanguage(), $unlocalized->isUnlocalizedOnly(), $unlocalized->isAllLanguages(), $unlocalized->languageCode()]);
        self::assertSame([false, false, true, null], [$all->isExactLanguage(), $all->isUnlocalizedOnly(), $all->isAllLanguages(), $all->languageCode()]);
        self::assertNotEquals($unlocalized->jsonSerialize(), $all->jsonSerialize(), 'Unlocalized and all languages are different intents.');
        self::assertSame('EN ', ContentLanguageFilter::exactLanguage('EN ')->languageCode(), 'The language is kept verbatim.');
    }

    public function testTheConsumerLanguageHasOnlyExactAndUnlocalizedStates(): void
    {
        self::assertSame([false, 'en'], [ExactContentLanguage::exactLanguage('en')->isUnlocalizedOnly(), ExactContentLanguage::exactLanguage('en')->languageCode()]);
        self::assertSame([true, null], [ExactContentLanguage::unlocalizedOnly()->isUnlocalizedOnly(), ExactContentLanguage::unlocalizedOnly()->languageCode()]);
        self::assertFalse((new \ReflectionClass(ExactContentLanguage::class))->hasMethod('allLanguages'), 'A consumer cannot ask for every language.');
    }

    public function testCriteriaDefaultToNonDeletedRecordsAndAHundredResults(): void
    {
        $criteria = new ProductContentListCriteriaDTO(5, ContentLanguageFilter::allLanguages());

        self::assertSame([5, ProductDeletedStateEnum::NON_DELETED, 100], [$criteria->productId, $criteria->deletedState, $criteria->maxResults]);
    }
}
