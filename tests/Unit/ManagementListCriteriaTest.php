<?php

declare(strict_types=1);

namespace Maatify\Product\Tests\Unit;

use Maatify\Product\Common\Enum\ProductDeletedStateEnum;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;
use Maatify\Product\ImageAssignment\Management\Criteria\ImageAssignmentListCriteriaDTO;
use Maatify\Product\ImageAssignment\ValueObject\ImageAssignmentLanguageFilter;
use Maatify\Product\ImageAssignment\ValueObject\ImageAssignmentPlatformFilter;
use Maatify\Product\ImageAssignment\ValueObject\ImageAssignmentRoleFilter;
use Maatify\Product\ImageAssignment\ValueObject\ImageAssignmentTargetFilter;
use Maatify\Product\ImageRole\Management\Criteria\ImageRoleListCriteriaDTO;
use Maatify\Product\Option\Management\Criteria\OptionListCriteriaDTO;
use Maatify\Product\OptionValue\Management\Criteria\OptionValueListCriteriaDTO;
use Maatify\Product\Product\Management\Criteria\ProductListCriteriaDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Construction-time validation of the management list criteria and the exact image-assignment selectors.
 */
final class ManagementListCriteriaTest extends TestCase
{
    public function testDefaultsAndBounds(): void
    {
        $product = new ProductListCriteriaDTO();
        self::assertSame([null, ProductDeletedStateEnum::NON_DELETED, 100, null], [$product->status, $product->deletedState, $product->maxResults, $product->search]);
        self::assertSame('a%b', (new ProductListCriteriaDTO(search: " \u{00A0}a%b\t"))->search, 'The search is trimmed of Unicode whitespace and otherwise kept.');
        self::assertSame(str_repeat('é', 100), (new ProductListCriteriaDTO(search: str_repeat('é', 100)))->search);
        self::assertSame(1, (new OptionListCriteriaDTO(maxResults: 1))->maxResults);
        self::assertSame(100, (new OptionValueListCriteriaDTO())->maxResults);
        self::assertSame(ProductDeletedStateEnum::NON_DELETED, (new ImageRoleListCriteriaDTO())->deletedState);

        $assignments = new ImageAssignmentListCriteriaDTO(5);
        self::assertTrue($assignments->target->isAnyTarget());
        self::assertTrue($assignments->role->isOmitted());
        self::assertTrue($assignments->language->isOmitted());
        self::assertTrue($assignments->platform->isOmitted());
        self::assertSame([5, ProductDeletedStateEnum::NON_DELETED, 100], [$assignments->productId, $assignments->deletedState, $assignments->maxResults]);
    }

    public function testTheImageSelectorsHaveDistinctStates(): void
    {
        self::assertSame([false, true, null], [ImageAssignmentTargetFilter::productLevel()->isAnyTarget(), ImageAssignmentTargetFilter::productLevel()->isProductLevel(), ImageAssignmentTargetFilter::productLevel()->variantId()]);
        self::assertSame([false, false, 7], [ImageAssignmentTargetFilter::variant(7)->isAnyTarget(), ImageAssignmentTargetFilter::variant(7)->isProductLevel(), ImageAssignmentTargetFilter::variant(7)->variantId()]);
        self::assertSame([false, true, null], [ImageAssignmentRoleFilter::exactNull()->isOmitted(), ImageAssignmentRoleFilter::exactNull()->isExactNull(), ImageAssignmentRoleFilter::exactNull()->roleId()]);
        self::assertSame([false, false, 3], [ImageAssignmentRoleFilter::forRole(3)->isOmitted(), ImageAssignmentRoleFilter::forRole(3)->isExactNull(), ImageAssignmentRoleFilter::forRole(3)->roleId()]);
        self::assertSame([false, true, null], [ImageAssignmentLanguageFilter::exactNull()->isOmitted(), ImageAssignmentLanguageFilter::exactNull()->isExactNull(), ImageAssignmentLanguageFilter::exactNull()->value()]);
        self::assertSame([false, false, ' en'], [ImageAssignmentLanguageFilter::exact(' en')->isOmitted(), ImageAssignmentLanguageFilter::exact(' en')->isExactNull(), ImageAssignmentLanguageFilter::exact(' en')->value()]);
        self::assertSame(str_repeat('p', 255), ImageAssignmentPlatformFilter::exact(str_repeat('p', 255))->value());
        self::assertNotEquals(ImageAssignmentRoleFilter::omitted()->jsonSerialize(), ImageAssignmentRoleFilter::exactNull()->jsonSerialize(), 'Omitted and NULL are different intents.');
        self::assertNotEquals(ImageAssignmentPlatformFilter::omitted()->jsonSerialize(), ImageAssignmentPlatformFilter::exactNull()->jsonSerialize());
        self::assertSame(['target' => 'any_target', 'variantId' => null], ImageAssignmentTargetFilter::anyTarget()->jsonSerialize());
    }

    /**
     * @return iterable<string, array{callable(): mixed}>
     */
    public static function invalidInputs(): iterable
    {
        yield 'product maxResults low' => [static fn() => new ProductListCriteriaDTO(maxResults: 0)];
        yield 'product maxResults high' => [static fn() => new ProductListCriteriaDTO(maxResults: 101)];
        yield 'empty search' => [static fn() => new ProductListCriteriaDTO(search: '')];
        yield 'whitespace search' => [static fn() => new ProductListCriteriaDTO(search: " \u{3000}\t")];
        yield 'long search' => [static fn() => new ProductListCriteriaDTO(search: str_repeat('a', 101))];
        yield 'invalid utf-8 search' => [static fn() => new ProductListCriteriaDTO(search: "\xFF")];
        yield 'option maxResults' => [static fn() => new OptionListCriteriaDTO(maxResults: 101)];
        yield 'value maxResults' => [static fn() => new OptionValueListCriteriaDTO(maxResults: 0)];
        yield 'role maxResults' => [static fn() => new ImageRoleListCriteriaDTO(maxResults: 0)];
        yield 'assignment product' => [static fn() => new ImageAssignmentListCriteriaDTO(0)];
        yield 'assignment maxResults' => [static fn() => new ImageAssignmentListCriteriaDTO(1, maxResults: 101)];
        yield 'variant target' => [static fn() => ImageAssignmentTargetFilter::variant(0)];
        yield 'role id' => [static fn() => ImageAssignmentRoleFilter::forRole(-1)];
        yield 'empty language' => [static fn() => ImageAssignmentLanguageFilter::exact('')];
        yield 'blank language' => [static fn() => ImageAssignmentLanguageFilter::exact("\u{00A0}")];
        yield 'long language' => [static fn() => ImageAssignmentLanguageFilter::exact(str_repeat('a', 17))];
        yield 'empty platform' => [static fn() => ImageAssignmentPlatformFilter::exact(' ')];
        yield 'long platform' => [static fn() => ImageAssignmentPlatformFilter::exact(str_repeat('a', 256))];
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
