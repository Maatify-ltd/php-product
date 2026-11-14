<?php

declare(strict_types=1);

namespace Maatify\Product\Tests\Integration\Schema;

final class DefaultScopeTest extends SchemaTestCase
{
    public function testOnlyOneActiveDefaultPerExactScope(): void
    {
        $this->assignment(['media_asset_id' => 20, 'is_default' => 1]);
        $this->assertInsertRejected(self::DUPLICATE_KEY, 'maa_product_image_assignments', [
            'product_id' => $this->p1, 'media_asset_id' => 21, 'is_default' => 1, 'display_order' => 2,
        ], 'second default in the same exact scope');
        $this->assignment(['media_asset_id' => 21]);
        self::assertSame(1, (int) self::scalar('SELECT COUNT(*) FROM maa_product_image_assignments WHERE is_default = 1 AND product_id = :p AND variant_id IS NULL', ['p' => $this->p1]));
    }

    public function testDefaultsInDifferentExactScopesAreIndependent(): void
    {
        $this->assignment(['media_asset_id' => 20, 'is_default' => 1]);
        $this->assignment(['media_asset_id' => 22, 'is_default' => 1, 'language_code' => 'en']);
        $this->assignment(['media_asset_id' => 23, 'is_default' => 1, 'role_id' => $this->role]);
        $this->assignment(['media_asset_id' => 24, 'is_default' => 1, 'platform' => 'web']);
        $this->assignment(['media_asset_id' => 25, 'is_default' => 1, 'variant_id' => $this->emptyVariant1]);
        $this->assignment(['media_asset_id' => 26, 'is_default' => 1, 'product_id' => $this->p2, 'variant_id' => $this->emptyVariant2]);
        self::assertSame(6, (int) self::scalar('SELECT COUNT(*) FROM maa_product_image_assignments WHERE is_default = 1'));
    }

    public function testCaseVariantOfAScopeCollides(): void
    {
        $this->assignment(['media_asset_id' => 22, 'is_default' => 1, 'language_code' => 'en']);
        $this->assertInsertRejected(self::DUPLICATE_KEY, 'maa_product_image_assignments', [
            'product_id' => $this->p1, 'media_asset_id' => 26, 'language_code' => 'EN', 'is_default' => 1, 'display_order' => 3,
        ]);
    }

    public function testASoftDeletedRowCanNeverBeDefault(): void
    {
        $id = $this->assignment(['media_asset_id' => 27, 'is_default' => 1]);
        $this->assertRejected(self::CHECK_VIOLATED, 'UPDATE maa_product_image_assignments SET deleted_at = :t WHERE id = :i', ['t' => self::TS, 'i' => $id]);
        $this->assertInsertRejected(self::CHECK_VIOLATED, 'maa_product_image_assignments', [
            'product_id' => $this->p1, 'media_asset_id' => 28, 'is_default' => 1, 'display_order' => 1, 'deleted_at' => self::TS,
        ]);
    }

    public function testAtomicReplacementDeleteAndRestoreKeepASingleDefaultWithoutPromotion(): void
    {
        $first = $this->assignment(['media_asset_id' => 20, 'is_default' => 1]);
        $second = $this->assignment(['media_asset_id' => 21]);

        $this->execute('UPDATE maa_product_image_assignments SET is_default = 0 WHERE id = :i', ['i' => $first]);
        $this->execute('UPDATE maa_product_image_assignments SET is_default = 1 WHERE id = :i', ['i' => $second]);

        $this->execute('UPDATE maa_product_image_assignments SET is_default = 0, deleted_at = :t WHERE id = :i', ['t' => self::TS, 'i' => $second]);
        self::assertSame(0, (int) self::scalar('SELECT COUNT(*) FROM maa_product_image_assignments WHERE is_default = 1 AND deleted_at IS NULL'), 'No automatic promotion.');

        $this->execute('UPDATE maa_product_image_assignments SET deleted_at = NULL WHERE id = :i', ['i' => $second]);
        self::assertSame('0', (string) self::scalar('SELECT is_default FROM maa_product_image_assignments WHERE id = :i', ['i' => $second]), 'Restore leaves the row non-default.');

        $this->execute('UPDATE maa_product_image_assignments SET is_default = 1 WHERE id = :i', ['i' => $first]);
        $this->assertRejected(self::DUPLICATE_KEY, 'UPDATE maa_product_image_assignments SET is_default = 1 WHERE id = :i', ['i' => $second], 'restore-as-default conflicts');
    }

    public function testClearingTheDefaultLeavesNoDefaultAndPromotesNoOtherRow(): void
    {
        $first = $this->assignment(['media_asset_id' => 20, 'is_default' => 1]);
        $second = $this->assignment(['media_asset_id' => 21]);

        $this->execute('UPDATE maa_product_image_assignments SET is_default = 0 WHERE id = :i', ['i' => $first]);

        self::assertSame(0, (int) self::scalar('SELECT COUNT(*) FROM maa_product_image_assignments WHERE is_default = 1'), 'Clearing is explicit and promotes nothing.');
        self::assertSame('0', (string) self::scalar('SELECT is_default FROM maa_product_image_assignments WHERE id = :i', ['i' => $second]));
    }

    public function testReplacingTheDefaultMustClearThePreviousOneInTheSameScope(): void
    {
        $first = $this->assignment(['media_asset_id' => 20, 'is_default' => 1]);
        $second = $this->assignment(['media_asset_id' => 21]);

        $this->assertRejected(self::DUPLICATE_KEY, 'UPDATE maa_product_image_assignments SET is_default = 1 WHERE id = :i', ['i' => $second], 'a second default needs the first to be cleared');

        $this->execute('UPDATE maa_product_image_assignments SET is_default = 0 WHERE id = :i', ['i' => $first]);
        $this->execute('UPDATE maa_product_image_assignments SET is_default = 1 WHERE id = :i', ['i' => $second]);

        self::assertSame($second, (int) self::scalar('SELECT id FROM maa_product_image_assignments WHERE is_default = 1'));
    }

    public function testTheNullRoleScopeAndEachConcreteRoleScopeAreDistinct(): void
    {
        $this->assignment(['media_asset_id' => 20, 'is_default' => 1, 'role_id' => null]);
        $this->assignment(['media_asset_id' => 21, 'is_default' => 1, 'role_id' => $this->role]);

        $this->assertInsertRejected(self::DUPLICATE_KEY, 'maa_product_image_assignments', [
            'product_id' => $this->p1, 'media_asset_id' => 22, 'role_id' => null, 'is_default' => 1, 'display_order' => 3,
        ], 'the NULL role is one exact scope, not a wildcard');
    }

    public function testTheDefaultMarkerIsIndependentOfRoleAndTargetStatus(): void
    {
        $this->execute("UPDATE maa_product_image_roles SET status = 'inactive' WHERE id = :i", ['i' => $this->role]);
        $this->execute("UPDATE maa_product_products SET status = 'inactive' WHERE id = :i", ['i' => $this->p1]);

        $this->assignment(['media_asset_id' => 30, 'is_default' => 1, 'role_id' => $this->role]);

        self::assertSame(1, (int) self::scalar('SELECT COUNT(*) FROM maa_product_image_assignments WHERE is_default = 1 AND role_id = :r', ['r' => $this->role]));
    }
}
