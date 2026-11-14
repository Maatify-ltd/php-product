<?php

declare(strict_types=1);

namespace Maatify\Product\Tests\Unit;

use FilesystemIterator;
use Maatify\Product\Content\Consumer\Service\OptionContentConsumerServiceInterface;
use Maatify\Product\Content\Consumer\Service\OptionValueContentConsumerServiceInterface;
use Maatify\Product\Content\Consumer\Service\ProductContentConsumerServiceInterface;
use Maatify\Product\Content\Management\Service\OptionContentManagementServiceInterface;
use Maatify\Product\Content\Management\Service\OptionValueContentManagementServiceInterface;
use Maatify\Product\Content\Management\Service\ProductContentManagementServiceInterface;
use Maatify\Product\ImageAssignment\Consumer\Service\ImageAssignmentConsumerServiceInterface;
use Maatify\Product\ImageAssignment\Management\Service\ImageAssignmentManagementServiceInterface;
use Maatify\Product\ImageRole\Management\Service\ImageRoleManagementServiceInterface;
use Maatify\Product\Option\Consumer\Service\OptionConsumerServiceInterface;
use Maatify\Product\Option\Management\Service\OptionManagementServiceInterface;
use Maatify\Product\OptionValue\Consumer\Service\OptionValueConsumerServiceInterface;
use Maatify\Product\OptionValue\Management\Service\OptionValueManagementServiceInterface;
use Maatify\Product\Product\Consumer\Service\ProductConsumerServiceInterface;
use Maatify\Product\Product\Management\Service\ProductManagementServiceInterface;
use Maatify\Product\Variant\Consumer\Service\VariantConsumerServiceInterface;
use Maatify\Product\Variant\Management\Service\VariantManagementServiceInterface;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

/**
 * Identity stability is enforced by the application, not by database triggers
 * (architecture 4.1: ordinary update commands do not mutate `id` or `code`). These tests
 * prove that no package write path can change an identity column, so a future write path
 * that does must fail them.
 */
final class IdentityStabilityTest extends TestCase
{
    /**
     * Columns no write path may assign: package and owner identities, business keys, exact-scope
     * dimensions, the composition fingerprint, and the generated identity and scope keys.
     */
    private const array IDENTITY_COLUMNS = [
        'id', 'code', 'role_key', 'composition_fingerprint', 'product_id', 'variant_id', 'option_id',
        'option_value_id', 'language_code', 'platform', 'media_asset_id', 'role_id',
        'language_code_identity', 'ordering_scope', 'default_scope_identity',
    ];

    /**
     * One SQL `UPDATE ... SET ... WHERE` statement, whatever form the table name takes in the source
     * (a literal, a class constant, or an expression concatenated between backticks).
     */
    private const string UPDATE_STATEMENT = '/\bUPDATE\s+`(.*?)`\s+SET\s+(.*?)\bWHERE\b/s';

    /** Every SQL `UPDATE` in the source; the table name is always quoted with a backtick. */
    private const string UPDATE_KEYWORD = '/\bUPDATE\s+`/';

    /**
     * A public service method that would replace an identity. Unanchored, so a longer name such as
     * `updateCodeAndStatus` is caught too; only `updateIdentifiers` (the secondary SKU and barcode,
     * which are not identities) is excluded.
     */
    private const string IDENTITY_METHOD = '/::(update|rename|change|replace|set)(?:Code|Identity|Key|Id(?!entifiers\\b))/i';

    public function testNoUpdateStatementSetsAnIdentityColumn(): void
    {
        $offences = [];
        $scanned = 0;
        $present = 0;
        foreach ($this->sourceFiles() as $file) {
            $source = (string) file_get_contents($file);
            $present += preg_match_all(self::UPDATE_KEYWORD, $source);
            [$fileOffences, $fileScanned] = self::scan($source);
            $scanned += $fileScanned;
            foreach ($fileOffences as $column) {
                $offences[] = $file . ': sets ' . $column;
            }
        }

        self::assertSame([], $offences);
        self::assertGreaterThan(0, $present);
        self::assertSame($present, $scanned, 'Every UPDATE statement in src/ must be scanned; none may escape the guard.');
    }

    public function testTheGuardRejectsAnIdentityAssignmentWhateverTheTableForm(): void
    {
        $sources = [
            'constant' => <<<'SQL'
                'UPDATE `' . self::TABLE . '` SET `code` = :code, `updated_at` = :updated_at WHERE `id` = :id'
                SQL,
            'literal' => <<<'SQL'
                'UPDATE `maa_product_variants` SET `status` = :status, `product_id` = :p WHERE `id` = :id'
                SQL,
            'expression' => <<<'SQL'
                'UPDATE `' . $this->owner->table() . '` SET `name` = :name, '
                . '`language_code` = :l WHERE `id` = :id'
                SQL,
        ];
        foreach ($sources as $form => $source) {
            [$offences, $scanned] = self::scan($source);
            self::assertSame(1, $scanned, $form);
            self::assertCount(1, $offences, $form . ' must be rejected.');
        }
        $allowed = <<<'SQL'
            'UPDATE `' . self::TABLE . '` SET `status` = :status, `updated_at` = :u '
            . 'WHERE `id` = :id'
            SQL;
        self::assertSame([[], 1], self::scan($allowed), 'A status write is allowed.');
    }

    public function testNoPublicServiceMethodCanReplaceAnIdentity(): void
    {
        $methods = [];
        foreach ([
            ProductManagementServiceInterface::class,
            ProductConsumerServiceInterface::class,
            VariantManagementServiceInterface::class,
            VariantConsumerServiceInterface::class,
            OptionManagementServiceInterface::class,
            OptionConsumerServiceInterface::class,
            OptionValueManagementServiceInterface::class,
            OptionValueConsumerServiceInterface::class,
            ImageRoleManagementServiceInterface::class,
            ImageAssignmentManagementServiceInterface::class,
            ImageAssignmentConsumerServiceInterface::class,
            ProductContentManagementServiceInterface::class,
            ProductContentConsumerServiceInterface::class,
            OptionContentManagementServiceInterface::class,
            OptionContentConsumerServiceInterface::class,
            OptionValueContentManagementServiceInterface::class,
            OptionValueContentConsumerServiceInterface::class,
        ] as $interface) {
            foreach ((new ReflectionClass($interface))->getMethods() as $method) {
                $methods[] = $interface . '::' . $method->getName();
            }
        }

        $forbidden = array_filter(
            $methods,
            static fn(string $name): bool => preg_match(self::IDENTITY_METHOD, $name) === 1,
        );

        self::assertSame([], array_values($forbidden));
    }

    public function testTheIdentityMethodGuardStaysStrict(): void
    {
        foreach (['updateId', 'updateIdentity', 'updateCode', 'updateCodeAndStatus', 'setKey', 'setIdentityColumn', 'renameCode', 'changeKeys', 'replaceId'] as $name) {
            self::assertSame(1, preg_match(self::IDENTITY_METHOD, 'Service::' . $name), $name . ' must be rejected.');
        }
        foreach (['updateIdentifiers', 'updateStatus', 'updateDisplayOrder', 'setDefault'] as $name) {
            self::assertSame(0, preg_match(self::IDENTITY_METHOD, 'Service::' . $name), $name . ' must be allowed.');
        }
    }

    /**
     * The identity columns assigned by the UPDATE statements of one source, and how many statements were scanned.
     *
     * @return array{list<string>, int}
     */
    private static function scan(string $source): array
    {
        $scanned = preg_match_all(self::UPDATE_STATEMENT, $source, $matches);
        $offences = [];
        foreach ($matches[2] as $assignments) {
            foreach (self::IDENTITY_COLUMNS as $column) {
                if (preg_match('/`?\\b' . $column . '`?\\s*=/i', $assignments) === 1) {
                    $offences[] = $column;
                }
            }
        }

        return [$offences, (int) $scanned];
    }

    /** @return list<string> */
    private function sourceFiles(): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/src', FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
