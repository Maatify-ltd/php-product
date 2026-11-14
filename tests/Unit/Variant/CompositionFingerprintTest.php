<?php

declare(strict_types=1);

namespace Maatify\Product\Tests\Unit\Variant;

use Maatify\Product\Variant\ValueObject\CompositionFingerprint;
use Maatify\Product\Variant\ValueObject\OptionValueSelection;
use PHPUnit\Framework\TestCase;

final class CompositionFingerprintTest extends TestCase
{
    public function testEmptyCompositionIsTheSha256OfTheCanonicalString(): void
    {
        // Known SHA-256 vector of the UTF-8 string "v1" (architecture section 5.3).
        $empty = CompositionFingerprint::forEmptyComposition();
        self::assertSame('v1', $empty->canonical);
        self::assertSame('3bfc269594ef649228e9a74bab00f042efc91d5acc6fbee31a382e80d42388fe', $empty->value);
    }

    public function testNonEmptyVectorsFromThePackageReference(): void
    {
        $two = CompositionFingerprint::forSelections([new OptionValueSelection(13, 70), new OptionValueSelection(12, 58)]);
        self::assertSame('v1|12=58|13=70', $two->canonical);
        self::assertSame('496340f00cb8b95f0534b757625de9b9bd38d6bcd8173b49dcabaa3dcf229d2c', $two->value);

        $numeric = CompositionFingerprint::forSelections([new OptionValueSelection(10, 2), new OptionValueSelection(9, 1)]);
        self::assertSame('v1|9=1|10=2', $numeric->canonical, 'Sorting is numeric, not lexicographic.');
        self::assertSame('d855b95fc8dfd49bc8b26ac1b606737bf38a2bce6c161f3d0f54ffcf5b24794e', $numeric->value);
        self::assertSame(
            $numeric->value,
            CompositionFingerprint::forSelections([new OptionValueSelection(9, 1), new OptionValueSelection(10, 2)])->value,
            'Input order does not change the result.',
        );
    }

    public function testIsLowercaseSixtyFourCharacterHex(): void
    {
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', CompositionFingerprint::forEmptyComposition()->value);
    }

    public function testIsDeterministic(): void
    {
        self::assertSame(
            CompositionFingerprint::forEmptyComposition()->value,
            CompositionFingerprint::forEmptyComposition()->value,
        );
    }
}
