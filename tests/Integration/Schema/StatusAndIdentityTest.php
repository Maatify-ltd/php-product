<?php

declare(strict_types=1);

namespace Maatify\Product\Tests\Integration\Schema;

use PHPUnit\Framework\Attributes\DataProvider;

final class StatusAndIdentityTest extends SchemaTestCase
{
    /** @return iterable<string, array{string}> */
    public static function badStatuses(): iterable
    {
        foreach (['Active', 'ACTIVE', 'Inactive', 'INACTIVE', 'archived', ''] as $status) {
            yield "'" . $status . "'" => [$status];
        }
    }

    #[DataProvider('badStatuses')]
    public function testStatusMustBeExactLowercaseOnEveryStatusTable(string $status): void
    {
        $this->assertInsertRejected(self::CHECK_VIOLATED, 'maa_product_products', ['code' => 'S-1', 'status' => $status]);
        $this->assertInsertRejected(self::CHECK_VIOLATED, 'maa_product_options', ['product_id' => $this->p1, 'code' => 'x', 'status' => $status, 'display_order' => 9]);
        $this->assertInsertRejected(self::CHECK_VIOLATED, 'maa_product_option_values', ['option_id' => $this->colorOption, 'code' => 'x', 'status' => $status, 'display_order' => 9]);
        $this->assertInsertRejected(self::CHECK_VIOLATED, 'maa_product_image_roles', ['role_key' => 'r-x', 'status' => $status]);
        $this->assertRejected(self::CHECK_VIOLATED, 'UPDATE maa_product_variants SET status = :s WHERE id = :id', ['s' => $status, 'id' => $this->emptyVariant1]);
    }

    public function testDefaultsFollowTheArchitecture(): void
    {
        self::assertSame('inactive', self::scalar('SELECT status FROM maa_product_products WHERE id = :i', ['i' => $this->p1]));
        self::assertSame('inactive', self::scalar('SELECT status FROM maa_product_options WHERE id = :i', ['i' => $this->colorOption]));
        self::assertSame('inactive', self::scalar('SELECT status FROM maa_product_option_values WHERE id = :i', ['i' => $this->red]));
        self::assertSame('active', self::scalar('SELECT status FROM maa_product_image_roles WHERE id = :i', ['i' => $this->role]));
        $this->assertInsertRejected(self::NO_DEFAULT, 'maa_product_variants', [
            'product_id' => $this->p1,
            'composition_fingerprint' => self::fingerprint('v1|none'),
            'display_order' => 9,
        ], 'Variant status has no database default');
    }

    public function testSoftDeleteIsIndependentOfStatus(): void
    {
        $this->execute('UPDATE maa_product_products SET deleted_at = :t WHERE id = :i', ['t' => self::TS, 'i' => $this->p2]);
        self::assertSame('inactive', self::scalar('SELECT status FROM maa_product_products WHERE id = :i', ['i' => $this->p2]));
    }

    public function testCodesAreUniqueCaseInsensitivelyAndStayReservedWhenSoftDeleted(): void
    {
        $this->assertInsertRejected(self::DUPLICATE_KEY, 'maa_product_products', ['code' => 'P-1']);
        $this->assertInsertRejected(self::DUPLICATE_KEY, 'maa_product_products', ['code' => 'p-1']);
        $this->execute('UPDATE maa_product_products SET deleted_at = :t WHERE id = :i', ['t' => self::TS, 'i' => $this->p2]);
        $this->assertInsertRejected(self::DUPLICATE_KEY, 'maa_product_products', ['code' => 'P-2']);
        $this->assertInsertRejected(self::DUPLICATE_KEY, 'maa_product_options', ['product_id' => $this->p1, 'code' => 'COLOR', 'display_order' => 9]);
        $this->assertInsertRejected(self::DUPLICATE_KEY, 'maa_product_option_values', ['option_id' => $this->colorOption, 'code' => 'RED', 'display_order' => 9]);
        $this->assertInsertRejected(self::DUPLICATE_KEY, 'maa_product_image_roles', ['role_key' => 'HERO']);

        self::assertGreaterThan(0, $this->insert('maa_product_options', ['product_id' => $this->p2, 'code' => 'size', 'display_order' => 2]), 'Same Option code in another Product is accepted.');
        self::assertGreaterThan(0, $this->insert('maa_product_option_values', ['option_id' => $this->sizeOption, 'code' => 'red', 'display_order' => 5]), 'Same Value code under another Option is accepted.');
    }

    public function testCodeLengthIsBounded(): void
    {
        $this->assertInsertRejected(self::DATA_TOO_LONG, 'maa_product_products', ['code' => str_repeat('a', 101)]);
        $this->insert('maa_product_products', ['code' => str_repeat('a', 100)]);
    }

    public function testDisplayOrderMustBePositiveAndHasNoDefault(): void
    {
        foreach ([0, -1] as $order) {
            $this->assertInsertRejected(self::CHECK_VIOLATED, 'maa_product_options', ['product_id' => $this->p1, 'code' => 'o' . $order, 'display_order' => $order]);
            $this->assertInsertRejected(self::CHECK_VIOLATED, 'maa_product_option_values', ['option_id' => $this->colorOption, 'code' => 'o' . $order, 'display_order' => $order]);
            $this->assertInsertRejected(self::CHECK_VIOLATED, 'maa_product_variants', [
                'product_id' => $this->p1, 'composition_fingerprint' => self::fingerprint('o' . $order), 'status' => 'inactive', 'display_order' => $order,
            ]);
            $this->assertInsertRejected(self::CHECK_VIOLATED, 'maa_product_image_assignments', [
                'product_id' => $this->p1, 'media_asset_id' => 5, 'is_default' => 0, 'display_order' => $order,
            ]);
        }
        $this->assertRejected(self::NO_DEFAULT, 'INSERT INTO maa_product_options (product_id, code, created_at, updated_at) VALUES (:p, :c, :t, :t2)', [
            'p' => $this->p1, 'c' => 'nodefault', 't' => self::TS, 't2' => self::TS,
        ]);
    }

    public function testParentsMustExistAndAreRestricted(): void
    {
        $this->assertInsertRejected(self::FK_CHILD_MISSING_PARENT, 'maa_product_options', ['product_id' => 999999, 'code' => 'q', 'display_order' => 1]);
        $this->assertInsertRejected(self::FK_CHILD_MISSING_PARENT, 'maa_product_variants', [
            'product_id' => 999999, 'composition_fingerprint' => self::fingerprint('q'), 'status' => 'inactive', 'display_order' => 1,
        ]);
        $this->assertRejected(self::FK_PARENT_REFERENCED, 'DELETE FROM maa_product_products WHERE id = :i', ['i' => $this->p1]);
        $this->assignment(['variant_id' => $this->emptyVariant1]);
        $this->assertRejected(self::FK_PARENT_REFERENCED, 'DELETE FROM maa_product_variants WHERE id = :i', ['i' => $this->emptyVariant1]);
        $this->assertRejected(self::FK_PARENT_REFERENCED, 'UPDATE maa_product_options SET id = 900000 WHERE id = :i', ['i' => $this->colorOption]);
    }
}
