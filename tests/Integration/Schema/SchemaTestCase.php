<?php

declare(strict_types=1);

namespace Maatify\Product\Tests\Integration\Schema;

use Maatify\Product\Tests\Integration\DatabaseTestCase;
use PDOException;

/**
 * Base for schema tests. Each test runs inside a transaction that is rolled back
 * and starts from a small fixture of two Products, their Options, Values, Variants,
 * and one Image Role.
 */
abstract class SchemaTestCase extends DatabaseTestCase
{
    protected const string TS = '2026-01-01 00:00:00';

    protected int $p1;
    protected int $p2;
    protected int $colorOption;
    protected int $sizeOption;
    protected int $colorOption2;
    protected int $red;
    protected int $blue;
    protected int $medium;
    protected int $red2;
    protected int $emptyVariant1;
    protected int $compositionVariant1;
    protected int $emptyVariant2;
    protected int $role;

    final protected function setUp(): void
    {
        self::$pdo->beginTransaction();
        $this->buildFixture();
    }

    final protected function tearDown(): void
    {
        if (self::$pdo->inTransaction()) {
            self::$pdo->rollBack();
        }
    }

    /**
     * @param array<string, int|string|null> $row column => value; timestamps are added when absent
     */
    protected function insert(string $table, array $row): int
    {
        $row += ['created_at' => self::TS, 'updated_at' => self::TS];
        if (in_array($table, ['maa_product_variant_option_values'], true)) {
            unset($row['created_at'], $row['updated_at']);
        }
        $columns = array_keys($row);
        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $table,
            implode(',', array_map(static fn(string $c): string => '`' . $c . '`', $columns)),
            implode(',', array_map(static fn(string $c): string => ':' . $c, $columns)),
        );
        $this->execute($sql, $row);

        return (int) self::$pdo->lastInsertId();
    }

    /** @param array<string, int|string|null> $params */
    protected function assertRejected(int $errno, string $sql, array $params = [], string $message = ''): void
    {
        try {
            $this->execute($sql, $params);
        } catch (PDOException $e) {
            $code = $e->errorInfo[1] ?? null;
            self::assertSame($errno, $code, ($message === '' ? '' : $message . ': ') . $e->getMessage());

            return;
        }
        self::fail(($message === '' ? '' : $message . ': ') . 'statement was accepted, expected MySQL error ' . $errno);
    }

    /** @param array<string, int|string|null> $row */
    protected function assertInsertRejected(int $errno, string $table, array $row, string $message = ''): void
    {
        try {
            $this->insert($table, $row);
        } catch (PDOException $e) {
            $code = $e->errorInfo[1] ?? null;
            self::assertSame($errno, $code, ($message === '' ? '' : $message . ': ') . $e->getMessage());

            return;
        }
        self::fail(($message === '' ? '' : $message . ': ') . 'insert into ' . $table . ' was accepted, expected MySQL error ' . $errno);
    }

    protected static function fingerprint(string $canonical): string
    {
        return hash('sha256', $canonical);
    }

    /** @param array<string, int|string|null> $overrides */
    protected function variant(int $productId, string $canonical, array $overrides = []): int
    {
        return $this->insert('maa_product_variants', $overrides + [
            'product_id' => $productId,
            'composition_fingerprint' => self::fingerprint($canonical),
            'status' => 'inactive',
            'display_order' => 50,
        ]);
    }

    /** @param array<string, int|string|null> $overrides */
    protected function assignment(array $overrides): int
    {
        return $this->insert('maa_product_image_assignments', $overrides + [
            'product_id' => $this->p1,
            'variant_id' => null,
            'media_asset_id' => 10,
            'role_id' => null,
            'language_code' => null,
            'platform' => null,
            'is_default' => 0,
            'display_order' => 1,
        ]);
    }

    private function buildFixture(): void
    {
        $this->p1 = $this->insert('maa_product_products', ['code' => 'P-1']);
        $this->p2 = $this->insert('maa_product_products', ['code' => 'P-2']);
        $this->colorOption = $this->insert('maa_product_options', ['product_id' => $this->p1, 'code' => 'color', 'display_order' => 1]);
        $this->sizeOption = $this->insert('maa_product_options', ['product_id' => $this->p1, 'code' => 'size', 'display_order' => 2]);
        $this->colorOption2 = $this->insert('maa_product_options', ['product_id' => $this->p2, 'code' => 'color', 'display_order' => 1]);
        $this->red = $this->insert('maa_product_option_values', ['option_id' => $this->colorOption, 'code' => 'red', 'display_order' => 1]);
        $this->blue = $this->insert('maa_product_option_values', ['option_id' => $this->colorOption, 'code' => 'blue', 'display_order' => 2]);
        $this->medium = $this->insert('maa_product_option_values', ['option_id' => $this->sizeOption, 'code' => 'm', 'display_order' => 1]);
        $this->red2 = $this->insert('maa_product_option_values', ['option_id' => $this->colorOption2, 'code' => 'red', 'display_order' => 1]);
        $this->emptyVariant1 = $this->variant($this->p1, 'v1', ['sku' => 'SKU-1', 'barcode' => '0001', 'status' => 'active', 'display_order' => 1]);
        $this->compositionVariant1 = $this->variant($this->p1, 'v1|1=1', ['display_order' => 2]);
        $this->emptyVariant2 = $this->variant($this->p2, 'v1', ['status' => 'active', 'display_order' => 1]);
        $this->role = $this->insert('maa_product_image_roles', ['role_key' => 'hero']);
    }
}
