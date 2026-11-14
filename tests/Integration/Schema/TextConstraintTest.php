<?php

declare(strict_types=1);

namespace Maatify\Product\Tests\Integration\Schema;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * F-01: empty and whitespace-only values (space, tab, LF, CR, VT, FF, mixed,
 * NBSP U+00A0, ideographic space U+3000) are rejected by CHECK constraints on
 * every text identity or label column, while values that merely contain
 * whitespace stay accepted.
 */
final class TextConstraintTest extends SchemaTestCase
{
    /** @return iterable<string, array{string}> */
    public static function blankValues(): iterable
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

    #[DataProvider('blankValues')]
    public function testIdentityColumnsRejectBlankValues(string $blank): void
    {
        $this->assertInsertRejected(self::CHECK_VIOLATED, 'maa_product_products', ['code' => $blank], 'products.code');
        $this->assertInsertRejected(self::CHECK_VIOLATED, 'maa_product_options', ['product_id' => $this->p1, 'code' => $blank, 'display_order' => 50], 'options.code');
        $this->assertInsertRejected(self::CHECK_VIOLATED, 'maa_product_option_values', ['option_id' => $this->colorOption, 'code' => $blank, 'display_order' => 50], 'option_values.code');
        $this->assertInsertRejected(self::CHECK_VIOLATED, 'maa_product_image_roles', ['role_key' => $blank], 'image_roles.role_key');
        $this->assertInsertRejected(self::CHECK_VIOLATED, 'maa_product_variants', [
            'product_id' => $this->p1, 'composition_fingerprint' => self::fingerprint('ws-sku'), 'sku' => $blank, 'status' => 'inactive', 'display_order' => 50,
        ], 'variants.sku');
        $this->assertInsertRejected(self::CHECK_VIOLATED, 'maa_product_variants', [
            'product_id' => $this->p1, 'composition_fingerprint' => self::fingerprint('ws-barcode'), 'barcode' => $blank, 'status' => 'inactive', 'display_order' => 50,
        ], 'variants.barcode');
    }

    #[DataProvider('blankValues')]
    public function testContentNamesRejectBlankValues(string $blank): void
    {
        $this->assertInsertRejected(self::CHECK_VIOLATED, 'maa_product_product_contents', ['product_id' => $this->p1, 'language_code' => 'fr', 'name' => $blank], 'product_contents.name');
        $this->assertInsertRejected(self::CHECK_VIOLATED, 'maa_product_option_contents', ['option_id' => $this->colorOption, 'language_code' => 'fr', 'name' => $blank], 'option_contents.name');
        $this->assertInsertRejected(self::CHECK_VIOLATED, 'maa_product_option_value_contents', ['option_value_id' => $this->red, 'language_code' => 'fr', 'name' => $blank], 'option_value_contents.name');
    }

    #[DataProvider('blankValues')]
    public function testNonNullLanguageAndPlatformRejectBlankValues(string $blank): void
    {
        $this->assertInsertRejected(self::CHECK_VIOLATED, 'maa_product_product_contents', ['product_id' => $this->p1, 'language_code' => $blank, 'name' => 'N'], 'product_contents.language_code');
        $this->assertInsertRejected(self::CHECK_VIOLATED, 'maa_product_option_contents', ['option_id' => $this->colorOption, 'language_code' => $blank, 'name' => 'N'], 'option_contents.language_code');
        $this->assertInsertRejected(self::CHECK_VIOLATED, 'maa_product_option_value_contents', ['option_value_id' => $this->red, 'language_code' => $blank, 'name' => 'N'], 'option_value_contents.language_code');
        $this->assertInsertRejected(self::CHECK_VIOLATED, 'maa_product_image_assignments', [
            'product_id' => $this->p1, 'media_asset_id' => 900, 'language_code' => $blank, 'is_default' => 0, 'display_order' => 50,
        ], 'image_assignments.language_code');
        $this->assertInsertRejected(self::CHECK_VIOLATED, 'maa_product_image_assignments', [
            'product_id' => $this->p1, 'media_asset_id' => 900, 'platform' => $blank, 'is_default' => 0, 'display_order' => 50,
        ], 'image_assignments.platform');
    }

    #[DataProvider('blankValues')]
    public function testUpdatesCannotBlankAnExistingValue(string $blank): void
    {
        $this->assertRejected(self::CHECK_VIOLATED, 'UPDATE maa_product_products SET code = :v WHERE id = :i', ['v' => $blank, 'i' => $this->p1]);
        $this->assertRejected(self::CHECK_VIOLATED, 'UPDATE maa_product_variants SET sku = :v WHERE id = :i', ['v' => $blank, 'i' => $this->emptyVariant1]);
    }

    public function testValuesThatContainWhitespaceButAreNotBlankAreAccepted(): void
    {
        $this->insert('maa_product_products', ['code' => "A\tB\n"]);
        $this->insert('maa_product_variants', [
            'product_id' => $this->p1, 'composition_fingerprint' => self::fingerprint('keep'), 'sku' => ' X1', 'status' => 'inactive', 'display_order' => 60,
        ]);
        $this->insert('maa_product_product_contents', ['product_id' => $this->p1, 'language_code' => 'zz', 'name' => "\tN\t"]);
        $this->assignment(['media_asset_id' => 901, 'platform' => "web\n"]);
        $this->assignment(['media_asset_id' => 902, 'language_code' => "\u{00A0}x"]);
        self::assertSame(1, (int) self::scalar('SELECT COUNT(*) FROM maa_product_products WHERE code = :c', ['c' => "A\tB\n"]));
    }

    public function testNullIsStillAllowedForOptionalTextScopes(): void
    {
        $this->insert('maa_product_product_contents', ['product_id' => $this->p1, 'language_code' => null, 'name' => 'Unlocalized']);
        $this->assignment(['media_asset_id' => 903, 'language_code' => null, 'platform' => null]);
        $this->insert('maa_product_variants', [
            'product_id' => $this->p1, 'composition_fingerprint' => self::fingerprint('nulls'), 'sku' => null, 'barcode' => null, 'status' => 'inactive', 'display_order' => 61,
        ]);
        self::assertSame(1, (int) self::scalar('SELECT COUNT(*) FROM maa_product_product_contents WHERE product_id = :p AND language_code IS NULL', ['p' => $this->p1]));
    }

    public function testLengthLimitsAreEnforced(): void
    {
        $this->assertInsertRejected(self::DATA_TOO_LONG, 'maa_product_product_contents', ['product_id' => $this->p1, 'language_code' => str_repeat('a', 17), 'name' => 'N']);
        $this->assertInsertRejected(self::DATA_TOO_LONG, 'maa_product_image_assignments', [
            'product_id' => $this->p1, 'media_asset_id' => 904, 'platform' => str_repeat('a', 256), 'is_default' => 0, 'display_order' => 50,
        ]);
        $this->assertInsertRejected(self::DATA_TOO_LONG, 'maa_product_variants', [
            'product_id' => $this->p1, 'composition_fingerprint' => self::fingerprint('long'), 'sku' => str_repeat('a', 129), 'status' => 'inactive', 'display_order' => 62,
        ]);
        self::assertGreaterThan(0, $this->assignment(['media_asset_id' => 905, 'platform' => str_repeat('a', 255), 'language_code' => str_repeat('b', 16)]));
    }
}
