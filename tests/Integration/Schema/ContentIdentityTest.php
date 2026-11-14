<?php

declare(strict_types=1);

namespace Maatify\Product\Tests\Integration\Schema;

use PHPUnit\Framework\Attributes\DataProvider;

final class ContentIdentityTest extends SchemaTestCase
{
    /** @return iterable<string, array{string, string}> table => owner column */
    public static function contentTables(): iterable
    {
        yield 'product' => ['maa_product_product_contents', 'product_id'];
        yield 'option' => ['maa_product_option_contents', 'option_id'];
        yield 'option value' => ['maa_product_option_value_contents', 'option_value_id'];
    }

    private function owner(string $column): int
    {
        return match ($column) {
            'product_id' => $this->p1,
            'option_id' => $this->colorOption,
            default => $this->red,
        };
    }

    #[DataProvider('contentTables')]
    public function testOneUnlocalizedRowAndOneRowPerLanguagePerOwner(string $table, string $column): void
    {
        $owner = $this->owner($column);
        $this->insert($table, [$column => $owner, 'language_code' => null, 'name' => 'N']);
        $this->assertInsertRejected(self::DUPLICATE_KEY, $table, [$column => $owner, 'language_code' => null, 'name' => 'N2'], 'second NULL-language row');
        $this->insert($table, [$column => $owner, 'language_code' => 'en', 'name' => 'E']);
        $this->assertInsertRejected(self::DUPLICATE_KEY, $table, [$column => $owner, 'language_code' => 'EN', 'name' => 'E2'], 'case-insensitive language');
    }

    #[DataProvider('contentTables')]
    public function testSoftDeletedIdentityStaysReserved(string $table, string $column): void
    {
        $owner = $this->owner($column);
        $id = $this->insert($table, [$column => $owner, 'language_code' => null, 'name' => 'N']);
        $this->execute('UPDATE `' . $table . '` SET deleted_at = :t WHERE id = :i', ['t' => self::TS, 'i' => $id]);
        $this->assertInsertRejected(self::DUPLICATE_KEY, $table, [$column => $owner, 'language_code' => null, 'name' => 'again']);
    }

    #[DataProvider('contentTables')]
    public function testOwnerMustExist(string $table, string $column): void
    {
        $this->assertInsertRejected(self::FK_CHILD_MISSING_PARENT, $table, [$column => 999999, 'language_code' => 'de', 'name' => 'X']);
    }

    public function testReadsAreExactWithNoImplicitFallback(): void
    {
        $this->insert('maa_product_product_contents', ['product_id' => $this->p1, 'language_code' => null, 'name' => 'Neutral']);
        $this->insert('maa_product_product_contents', ['product_id' => $this->p1, 'language_code' => 'en', 'name' => 'English']);

        self::assertSame('Neutral', self::scalar('SELECT name FROM maa_product_product_contents WHERE product_id = :p AND language_code IS NULL', ['p' => $this->p1]));
        self::assertSame('English', self::scalar('SELECT name FROM maa_product_product_contents WHERE product_id = :p AND language_code = :l', ['p' => $this->p1, 'l' => 'en']));
        self::assertNull(self::scalar('SELECT name FROM maa_product_product_contents WHERE product_id = :p AND language_code = :l', ['p' => $this->p1, 'l' => 'fr']), 'No fallback from fr to NULL or en.');
        self::assertSame(2, (int) self::scalar('SELECT COUNT(*) FROM maa_product_product_contents WHERE product_id = :p', ['p' => $this->p1]));
    }

    public function testDescriptionColumnsAreOptional(): void
    {
        $id = $this->insert('maa_product_product_contents', [
            'product_id' => $this->p1, 'language_code' => 'it', 'name' => 'N', 'short_description' => str_repeat('s', 500), 'description' => str_repeat('d', 1000),
        ]);
        self::assertGreaterThan(0, $id);
        $this->assertInsertRejected(self::DATA_TOO_LONG, 'maa_product_product_contents', [
            'product_id' => $this->p1, 'language_code' => 'es', 'name' => 'N', 'short_description' => str_repeat('s', 501),
        ]);
    }
}
