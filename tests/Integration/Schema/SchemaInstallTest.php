<?php

declare(strict_types=1);

namespace Maatify\Product\Tests\Integration\Schema;

use PDO;

final class SchemaInstallTest extends SchemaTestCase
{
    private const array TABLES = [
        'maa_product_image_assignments',
        'maa_product_image_roles',
        'maa_product_option_contents',
        'maa_product_option_value_contents',
        'maa_product_option_values',
        'maa_product_options',
        'maa_product_product_contents',
        'maa_product_products',
        'maa_product_variant_option_values',
        'maa_product_variants',
    ];

    public function testInstallsExactlyTheTenPackageTables(): void
    {
        $rows = self::$pdo->query(
            "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY table_name",
        );
        self::assertNotFalse($rows);
        $names = $rows->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame(self::TABLES, $names);
    }

    public function testEveryTableIsInnoDbUtf8mb4UnicodeWithPrimaryKeyIdAndComment(): void
    {
        self::assertSame(0, (int) self::scalar(
            "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()
             AND (engine <> 'InnoDB' OR table_collation <> 'utf8mb4_unicode_ci' OR table_comment = '')",
        ));
        self::assertSame(0, (int) self::scalar(
            "SELECT COUNT(*) FROM information_schema.tables t WHERE t.table_schema = DATABASE() AND NOT EXISTS (
                SELECT 1 FROM information_schema.statistics s WHERE s.table_schema = t.table_schema
                AND s.table_name = t.table_name AND s.index_name = 'PRIMARY' AND s.column_name = 'id' AND s.seq_in_index = 1)",
        ));
    }

    public function testTheAssignmentDefaultInvariantUsesTheCanonicalCategoryCompatibleNames(): void
    {
        $columns = self::$pdo->query(
            "SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE()
             AND table_name = 'maa_product_image_assignments' AND column_name IN ('is_default', 'default_scope_identity')
             ORDER BY column_name",
        );
        self::assertNotFalse($columns);
        self::assertSame(['default_scope_identity', 'is_default'], $columns->fetchAll(PDO::FETCH_COLUMN));

        self::assertSame(1, (int) self::scalar(
            "SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE()
             AND index_name = 'uq_maa_product_image_assignments_default_scope' AND non_unique = 0",
        ));
        self::assertSame(2, (int) self::scalar(
            "SELECT COUNT(*) FROM information_schema.table_constraints WHERE table_schema = DATABASE()
             AND constraint_name IN ('chk_maa_product_image_assignments_is_default', 'chk_maa_product_image_assignments_default_not_deleted')",
        ));
        self::assertSame(0, (int) self::scalar(
            "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE()
             AND (column_name LIKE '%primary%' OR column_name LIKE 'media\\_role%' OR column_name LIKE 'media\\_assignment%')",
        ));
    }

    public function testTheSchemaDefinesNoTriggersOrRoutines(): void
    {
        self::assertSame(0, (int) self::scalar('SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema = DATABASE()'));
        self::assertSame(0, (int) self::scalar('SELECT COUNT(*) FROM information_schema.routines WHERE routine_schema = DATABASE()'));
    }

    public function testEveryColumnHasAComment(): void
    {
        self::assertSame(0, (int) self::scalar(
            "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND column_comment = ''",
        ));
    }

    public function testForeignKeysAreInternalAndRestrictOnly(): void
    {
        self::assertSame(12, (int) self::scalar(
            'SELECT COUNT(*) FROM information_schema.referential_constraints WHERE constraint_schema = DATABASE()',
        ));
        self::assertSame(0, (int) self::scalar(
            "SELECT COUNT(*) FROM information_schema.referential_constraints WHERE constraint_schema = DATABASE()
             AND (update_rule <> 'RESTRICT' OR delete_rule <> 'RESTRICT' OR referenced_table_name NOT LIKE 'maa\\_product\\_%')",
        ));
    }

    public function testCandidateKeysAndCompositeForeignKeysExist(): void
    {
        self::assertSame(3, (int) self::scalar(
            "SELECT COUNT(*) FROM (
                SELECT table_name, GROUP_CONCAT(column_name ORDER BY seq_in_index) AS cols
                FROM information_schema.statistics WHERE table_schema = DATABASE() AND non_unique = 0
                GROUP BY table_name, index_name) k
             WHERE (table_name IN ('maa_product_variants', 'maa_product_options') AND cols = 'id,product_id')
                OR (table_name = 'maa_product_option_values' AND cols = 'id,option_id')",
        ));
        self::assertSame(3, (int) self::scalar(
            "SELECT COUNT(*) FROM (
                SELECT constraint_name FROM information_schema.key_column_usage
                WHERE table_schema = DATABASE() AND table_name = 'maa_product_variant_option_values'
                  AND referenced_table_name IS NOT NULL GROUP BY constraint_name HAVING COUNT(*) = 2) c",
        ));
        self::assertSame(1, (int) self::scalar(
            "SELECT COUNT(*) FROM (
                SELECT constraint_name FROM information_schema.key_column_usage
                WHERE table_schema = DATABASE() AND table_name = 'maa_product_image_assignments'
                  AND referenced_table_name = 'maa_product_variants' GROUP BY constraint_name HAVING COUNT(*) = 2) c",
        ));
    }

    public function testNoDatabaseGeneratedTimestampsTriggersOrSeedData(): void
    {
        self::assertSame(0, (int) self::scalar(
            "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE()
             AND (column_default LIKE '%CURRENT\\_TIMESTAMP%' OR extra LIKE '%on update%')",
        ));
        self::assertSame(0, (int) self::scalar(
            'SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema = DATABASE()',
        ));
        // Fixture rows are inside this test's transaction; the committed tables are empty.
        self::$pdo->rollBack();
        foreach (self::TABLES as $table) {
            self::assertSame(0, (int) self::scalar('SELECT COUNT(*) FROM `' . $table . '`'), $table . ' must have no seed rows.');
        }
        self::$pdo->beginTransaction();
    }

    public function testSchemaReinstallsOnAFreshDatabase(): void
    {
        $name = 'product_it_' . bin2hex(random_bytes(6));
        $admin = self::connect(null);
        $admin->exec('CREATE DATABASE `' . $name . '`');
        try {
            $fresh = self::connect($name);
            foreach (self::schemaStatements() as $statement) {
                $fresh->exec($statement);
            }
            $count = $fresh->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()');
            self::assertNotFalse($count);
            self::assertSame('10', (string) $count->fetchColumn());
        } finally {
            $admin->exec('DROP DATABASE `' . $name . '`');
        }
    }
}
