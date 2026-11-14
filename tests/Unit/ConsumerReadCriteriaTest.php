<?php

declare(strict_types=1);

namespace Maatify\Product\Tests\Unit;

use Maatify\Product\Common\Exception\ProductInvalidArgumentException;
use Maatify\Product\ImageAssignment\Consumer\Criteria\ImageAssignmentExactScopeCriteriaDTO;
use Maatify\Product\Option\Consumer\Criteria\OptionVisibleListCriteriaDTO;
use Maatify\Product\OptionValue\Consumer\Criteria\OptionValueVisibleListCriteriaDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Construction-time validation of the consumer read criteria.
 */
final class ConsumerReadCriteriaTest extends TestCase
{
    public function testDefaultsAndExactValues(): void
    {
        self::assertSame(100, (new OptionVisibleListCriteriaDTO())->maxResults);
        self::assertSame(1, (new OptionValueVisibleListCriteriaDTO(1))->maxResults);
        $scope = new ImageAssignmentExactScopeCriteriaDTO(1, null, null, null, null);
        self::assertSame(
            ['productId' => 1, 'variantId' => null, 'roleId' => null, 'languageCode' => null, 'platform' => null, 'maxResults' => 100],
            $scope->jsonSerialize(),
        );
        $exact = new ImageAssignmentExactScopeCriteriaDTO(1, 2, 3, ' en', str_repeat('p', 255), 7);
        self::assertSame([2, 3, ' en', 7], [$exact->variantId, $exact->roleId, $exact->languageCode, $exact->maxResults], 'Values are kept verbatim.');
    }

    /**
     * @return iterable<string, array{callable(): mixed}>
     */
    public static function invalidInputs(): iterable
    {
        yield 'option maxResults low' => [static fn() => new OptionVisibleListCriteriaDTO(0)];
        yield 'option maxResults high' => [static fn() => new OptionVisibleListCriteriaDTO(101)];
        yield 'value maxResults' => [static fn() => new OptionValueVisibleListCriteriaDTO(101)];
        yield 'product id' => [static fn() => new ImageAssignmentExactScopeCriteriaDTO(0, null, null, null, null)];
        yield 'variant id' => [static fn() => new ImageAssignmentExactScopeCriteriaDTO(1, 0, null, null, null)];
        yield 'role id' => [static fn() => new ImageAssignmentExactScopeCriteriaDTO(1, null, -1, null, null)];
        yield 'empty language' => [static fn() => new ImageAssignmentExactScopeCriteriaDTO(1, null, null, '', null)];
        yield 'blank language' => [static fn() => new ImageAssignmentExactScopeCriteriaDTO(1, null, null, "\u{00A0}", null)];
        yield 'long language' => [static fn() => new ImageAssignmentExactScopeCriteriaDTO(1, null, null, str_repeat('a', 17), null)];
        yield 'blank platform' => [static fn() => new ImageAssignmentExactScopeCriteriaDTO(1, null, null, null, ' ')];
        yield 'long platform' => [static fn() => new ImageAssignmentExactScopeCriteriaDTO(1, null, null, null, str_repeat('a', 256))];
        yield 'maxResults' => [static fn() => new ImageAssignmentExactScopeCriteriaDTO(1, null, null, null, null, 0)];
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
