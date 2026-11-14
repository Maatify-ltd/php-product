<?php

declare(strict_types=1);

namespace Maatify\Product\Tests\Unit\Variant\ValueObject;

use Maatify\Product\Common\Exception\ProductInvalidArgumentException;
use Maatify\Product\Variant\Consumer\Criteria\VariantCompositionCriteriaDTO;
use Maatify\Product\Variant\ValueObject\OptionValueSelection;
use PHPUnit\Framework\TestCase;

final class OptionValueSelectionTest extends TestCase
{
    public function testCanonicalOrderIsNumericByOptionIdentity(): void
    {
        $sorted = OptionValueSelection::canonicalize([new OptionValueSelection(10, 2), new OptionValueSelection(9, 1)]);
        self::assertSame([[9, 1], [10, 2]], array_map(static fn(OptionValueSelection $s): array => [$s->optionId, $s->optionValueId], $sorted));

        $criteria = new VariantCompositionCriteriaDTO([new OptionValueSelection(13, 70), new OptionValueSelection(12, 58)]);
        self::assertSame([12, 13], array_map(static fn(OptionValueSelection $s): int => $s->optionId, $criteria->selections));
        self::assertSame([], (new VariantCompositionCriteriaDTO())->selections, 'An empty selection is valid input.');
        self::assertSame(['optionId' => 12, 'optionValueId' => 58], (new OptionValueSelection(12, 58))->jsonSerialize());
    }

    public function testNonPositiveIdentitiesAndRepeatedOptionsAreRejected(): void
    {
        foreach ([
            static fn() => new OptionValueSelection(0, 1),
            static fn() => new OptionValueSelection(1, -1),
            static fn() => new VariantCompositionCriteriaDTO([new OptionValueSelection(1, 1), new OptionValueSelection(1, 2)]),
        ] as $call) {
            try {
                $call();
                self::fail('The input must be rejected.');
            } catch (ProductInvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
