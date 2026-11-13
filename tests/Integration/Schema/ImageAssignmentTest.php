<?php

declare(strict_types=1);

namespace Maatify\Product\Tests\Integration\Schema;

final class ImageAssignmentTest extends SchemaTestCase
{
    private const string TABLE = 'maa_product_image_assignments';

    public function testProductLevelAndVariantLevelTargetsAreAccepted(): void
    {
        self::assertGreaterThan(0, $this->assignment([]));
        self::assertGreaterThan(0, $this->assignment(['variant_id' => $this->emptyVariant1]));
    }

    public function testProductLevelTargetStillRequiresAnExistingProduct(): void
    {
        $this->assertInsertRejected(self::FK_CHILD_MISSING_PARENT, self::TABLE, [
            'product_id' => 999999, 'variant_id' => null, 'media_asset_id' => 10, 'is_default' => 0, 'display_order' => 1,
        ]);
    }

    public function testVariantTargetMustExistAndBelongToTheSameProduct(): void
    {
        $this->assertInsertRejected(self::FK_CHILD_MISSING_PARENT, self::TABLE, [
            'product_id' => $this->p1, 'variant_id' => 999999, 'media_asset_id' => 10, 'is_default' => 0, 'display_order' => 1,
        ], 'unknown Variant');
        $this->assertInsertRejected(self::FK_CHILD_MISSING_PARENT, self::TABLE, [
            'product_id' => $this->p1, 'variant_id' => $this->emptyVariant2, 'media_asset_id' => 10, 'is_default' => 0, 'display_order' => 1,
        ], 'Variant of another Product');
    }

    public function testRoleMustExistWhenPresentAndIsRestricted(): void
    {
        $this->assertInsertRejected(self::FK_CHILD_MISSING_PARENT, self::TABLE, [
            'product_id' => $this->p1, 'media_asset_id' => 10, 'role_id' => 999999, 'is_default' => 0, 'display_order' => 1,
        ]);
        $this->assignment(['role_id' => $this->role]);
        $this->assertRejected(self::FK_PARENT_REFERENCED, 'DELETE FROM maa_product_image_roles WHERE id = :i', ['i' => $this->role]);
        $this->assignment(['variant_id' => $this->emptyVariant1, 'media_asset_id' => 11]);
        $this->assertRejected(self::FK_PARENT_REFERENCED, 'DELETE FROM maa_product_variants WHERE id = :i', ['i' => $this->emptyVariant1]);
    }

    public function testStableIdentityIsUniquePerExactTargetAssetAndScopeAcrossHistory(): void
    {
        $id = $this->assignment([]);
        $this->assertInsertRejected(self::DUPLICATE_KEY, self::TABLE, $this->row([]), 'product-level, all-NULL scope');
        $this->assignment(['variant_id' => $this->emptyVariant1]);
        $this->assertInsertRejected(self::DUPLICATE_KEY, self::TABLE, $this->row(['variant_id' => $this->emptyVariant1]), 'variant-level');

        $this->assignment(['platform' => 'web']);
        $this->assertInsertRejected(self::DUPLICATE_KEY, self::TABLE, $this->row(['platform' => 'WEB']), 'case-insensitive platform');

        $this->execute('UPDATE maa_product_image_assignments SET deleted_at = :t WHERE id = :i', ['t' => self::TS, 'i' => $id]);
        $this->assertInsertRejected(self::DUPLICATE_KEY, self::TABLE, $this->row([]), 'soft-deleted identity stays reserved');
    }

    public function testSameAssetOnDifferentExactScopesIsAccepted(): void
    {
        $this->assignment([]);
        $this->assignment(['role_id' => $this->role]);
        $this->assignment(['language_code' => 'en']);
        $this->assignment(['platform' => 'web']);
        $this->assignment(['variant_id' => $this->emptyVariant1]);
        $this->assignment(['product_id' => $this->p2, 'variant_id' => $this->emptyVariant2]);
        self::assertSame(6, (int) self::scalar('SELECT COUNT(*) FROM maa_product_image_assignments WHERE media_asset_id = 10'));
    }

    public function testMediaAssetIdContract(): void
    {
        foreach ([0] as $bad) {
            $this->assertInsertRejected(self::CHECK_VIOLATED, self::TABLE, $this->row(['media_asset_id' => $bad]), 'media_asset_id = ' . $bad);
        }
        $this->assertInsertRejected(self::OUT_OF_RANGE, self::TABLE, $this->row(['media_asset_id' => -5]), 'negative media_asset_id');
        self::assertGreaterThan(0, $this->assignment(['media_asset_id' => '18446744073709551615']));
    }

    public function testSentinelValuesAreUnreachable(): void
    {
        $this->assertInsertRejected(self::CHECK_VIOLATED, self::TABLE, $this->row(['role_id' => 0, 'media_asset_id' => 11]), 'role_id = 0');
        $this->assertInsertRejected(self::CHECK_VIOLATED, self::TABLE, $this->row(['variant_id' => 0, 'media_asset_id' => 11]), 'variant_id = 0');
    }

    public function testDefaultAndOrderColumnsAreConstrained(): void
    {
        $this->assertInsertRejected(self::CHECK_VIOLATED, self::TABLE, $this->row(['is_default' => 2, 'media_asset_id' => 11]), 'is_default = 2');
        $this->assertInsertRejected(self::CHECK_VIOLATED, self::TABLE, $this->row(['display_order' => 0, 'media_asset_id' => 11]), 'display_order = 0');
        $this->assertRejected(
            self::NO_DEFAULT,
            'INSERT INTO maa_product_image_assignments (product_id, media_asset_id, created_at, updated_at) VALUES (:p, 12, :t, :t2)',
            ['p' => $this->p1, 't' => self::TS, 't2' => self::TS],
            'display_order has no database default',
        );
    }

    public function testOrderingScopeEncodesTargetAndExactScope(): void
    {
        $product = $this->assignment([]);
        $variant = $this->assignment(['variant_id' => $this->emptyVariant1, 'language_code' => 'en', 'platform' => 'web', 'role_id' => $this->role]);
        self::assertSame(sprintf('P%d|0|N|N|0', $this->p1), self::scalar('SELECT ordering_scope FROM maa_product_image_assignments WHERE id = :i', ['i' => $product]));
        self::assertMatchesRegularExpression(
            sprintf('/^P%d\\|%d\\|[0-9a-f]{64}\\|[0-9a-f]{64}\\|%d$/', $this->p1, $this->emptyVariant1, $this->role),
            (string) self::scalar('SELECT ordering_scope FROM maa_product_image_assignments WHERE id = :i', ['i' => $variant]),
        );
    }

    /**
     * @param array<string, int|string|null> $overrides
     * @return array<string, int|string|null>
     */
    private function row(array $overrides): array
    {
        return $overrides + [
            'product_id' => $this->p1, 'variant_id' => null, 'media_asset_id' => 10, 'role_id' => null,
            'language_code' => null, 'platform' => null, 'is_default' => 0, 'display_order' => 1,
        ];
    }
}
