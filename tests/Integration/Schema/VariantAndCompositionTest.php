<?php

declare(strict_types=1);

namespace Maatify\Product\Tests\Integration\Schema;

final class VariantAndCompositionTest extends SchemaTestCase
{
    public function testFingerprintIsUniquePerProductAcrossAllHistory(): void
    {
        $this->assertInsertRejected(self::DUPLICATE_KEY, 'maa_product_variants', [
            'product_id' => $this->p1, 'composition_fingerprint' => self::fingerprint('v1|1=1'), 'status' => 'active', 'display_order' => 9,
        ], 'against an inactive variant');
        $this->execute('UPDATE maa_product_variants SET deleted_at = :t WHERE id = :i', ['t' => self::TS, 'i' => $this->compositionVariant1]);
        $this->assertInsertRejected(self::DUPLICATE_KEY, 'maa_product_variants', [
            'product_id' => $this->p1, 'composition_fingerprint' => self::fingerprint('v1|1=1'), 'status' => 'active', 'display_order' => 9,
        ], 'against a soft-deleted variant');
        self::assertGreaterThan(0, $this->variant($this->p2, 'v1|1=1'), 'The same composition in another Product is accepted.');
    }

    public function testFingerprintFormatIsLowercaseSha256Hex(): void
    {
        $upper = strtoupper(self::fingerprint('format'));
        foreach (['abc', $upper, str_repeat('g', 64), str_repeat('a', 63), str_repeat('a', 65)] as $bad) {
            $this->assertInsertRejected(
                strlen($bad) === 65 ? self::DATA_TOO_LONG : self::CHECK_VIOLATED,
                'maa_product_variants',
                ['product_id' => $this->p1, 'composition_fingerprint' => $bad, 'status' => 'inactive', 'display_order' => 9],
                'fingerprint ' . $bad,
            );
        }
    }

    public function testSkuAndBarcodeAreUniqueWhileAssignedAndNullable(): void
    {
        $this->assertInsertRejected(self::DUPLICATE_KEY, 'maa_product_variants', [
            'product_id' => $this->p1, 'composition_fingerprint' => self::fingerprint('s1'), 'sku' => 'SKU-1', 'status' => 'inactive', 'display_order' => 9,
        ]);
        $this->assertInsertRejected(self::DUPLICATE_KEY, 'maa_product_variants', [
            'product_id' => $this->p1, 'composition_fingerprint' => self::fingerprint('s2'), 'sku' => 'sku-1', 'status' => 'inactive', 'display_order' => 9,
        ], 'case-only difference');
        $this->assertInsertRejected(self::DUPLICATE_KEY, 'maa_product_variants', [
            'product_id' => $this->p1, 'composition_fingerprint' => self::fingerprint('b1'), 'barcode' => '0001', 'status' => 'inactive', 'display_order' => 9,
        ]);
        $this->variant($this->p1, 'b2', ['barcode' => '1']);
        $this->variant($this->p1, 'n1');
        $this->variant($this->p1, 'n2');

        $this->execute('UPDATE maa_product_variants SET deleted_at = :t WHERE id = :i', ['t' => self::TS, 'i' => $this->emptyVariant1]);
        $this->assertInsertRejected(self::DUPLICATE_KEY, 'maa_product_variants', [
            'product_id' => $this->p1, 'composition_fingerprint' => self::fingerprint('s3'), 'sku' => 'SKU-1', 'status' => 'inactive', 'display_order' => 9,
        ], 'soft-deleted variant keeps its SKU reserved');
        $this->execute('UPDATE maa_product_variants SET sku = NULL WHERE id = :i', ['i' => $this->emptyVariant1]);
        self::assertGreaterThan(0, $this->variant($this->p1, 's4', ['sku' => 'SKU-1']), 'A cleared SKU is released.');
    }

    public function testValidCompositionIsAcceptedOncePerOption(): void
    {
        $this->insert('maa_product_variant_option_values', [
            'product_id' => $this->p1, 'variant_id' => $this->compositionVariant1, 'option_id' => $this->colorOption, 'option_value_id' => $this->red,
        ]);
        $this->assertInsertRejected(self::DUPLICATE_KEY, 'maa_product_variant_option_values', [
            'product_id' => $this->p1, 'variant_id' => $this->compositionVariant1, 'option_id' => $this->colorOption, 'option_value_id' => $this->blue,
        ], 'two values of one Option in one Variant');
    }

    public function testCompositeForeignKeysRejectCrossProductAndMismatchedOwnership(): void
    {
        $row = static fn(int $p, int $v, int $o, int $ov): array => ['product_id' => $p, 'variant_id' => $v, 'option_id' => $o, 'option_value_id' => $ov];
        $table = 'maa_product_variant_option_values';

        $this->assertInsertRejected(self::FK_CHILD_MISSING_PARENT, $table, $row($this->p1, $this->emptyVariant2, $this->colorOption, $this->red), 'Variant of another Product');
        $this->assertInsertRejected(self::FK_CHILD_MISSING_PARENT, $table, $row($this->p1, $this->compositionVariant1, $this->colorOption2, $this->red2), 'Option of another Product');
        $this->assertInsertRejected(self::FK_CHILD_MISSING_PARENT, $table, $row($this->p1, $this->compositionVariant1, $this->colorOption, $this->medium), 'Value of a different Option');
        $this->assertInsertRejected(self::FK_CHILD_MISSING_PARENT, $table, $row($this->p1, $this->compositionVariant1, $this->colorOption, $this->red2), 'Value of another Product option');
        $this->assertInsertRejected(self::FK_CHILD_MISSING_PARENT, $table, $row($this->p1, 999999, $this->colorOption, $this->red), 'unknown Variant');
        $this->assertInsertRejected(self::FK_CHILD_MISSING_PARENT, $table, $row($this->p1, $this->compositionVariant1, $this->colorOption, 999999), 'unknown Value');
    }

    public function testCompositionCannotBeRewrittenToAnInvalidGraphOrDeletedWhileReferenced(): void
    {
        $pair = $this->insert('maa_product_variant_option_values', [
            'product_id' => $this->p1, 'variant_id' => $this->compositionVariant1, 'option_id' => $this->colorOption, 'option_value_id' => $this->red,
        ]);
        $this->assertRejected(self::FK_CHILD_MISSING_PARENT, 'UPDATE maa_product_variant_option_values SET option_value_id = :v WHERE id = :i', ['v' => $this->red2, 'i' => $pair]);
        $this->assertRejected(self::FK_PARENT_REFERENCED, 'DELETE FROM maa_product_variants WHERE id = :i', ['i' => $this->compositionVariant1]);
        $this->assertRejected(self::FK_PARENT_REFERENCED, 'DELETE FROM maa_product_options WHERE id = :i', ['i' => $this->colorOption]);
        $this->assertRejected(self::FK_PARENT_REFERENCED, 'DELETE FROM maa_product_option_values WHERE id = :i', ['i' => $this->red]);
        $this->assertRejected(self::FK_PARENT_REFERENCED, 'UPDATE maa_product_option_values SET id = 900000 WHERE id = :i', ['i' => $this->red]);
    }
}
