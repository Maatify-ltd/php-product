<?php

declare(strict_types=1);

namespace Maatify\Product\Tests\Integration\Runtime;

use DateTimeImmutable;
use DateTimeZone;
use Maatify\Product\Content\Consumer\Service\OptionContentConsumerServiceInterface;
use Maatify\Product\Content\Consumer\Service\OptionValueContentConsumerServiceInterface;
use Maatify\Product\Content\Consumer\Service\ProductContentConsumerServiceInterface;
use Maatify\Product\Content\Management\Service\OptionContentManagementServiceInterface;
use Maatify\Product\Content\Management\Service\OptionValueContentManagementServiceInterface;
use Maatify\Product\Content\Management\Service\ProductContentManagementServiceInterface;
use Maatify\Product\Factory\ProductFactory;
use Maatify\Product\ImageAssignment\Consumer\Service\ImageAssignmentConsumerServiceInterface;
use Maatify\Product\ImageAssignment\Management\Service\ImageAssignmentManagementServiceInterface;
use Maatify\Product\ImageRole\Management\Service\ImageRoleManagementServiceInterface;
use Maatify\Product\Option\Consumer\Service\OptionConsumerServiceInterface;
use Maatify\Product\Option\Management\Command\CreateOptionCommand;
use Maatify\Product\Option\Management\Service\OptionManagementServiceInterface;
use Maatify\Product\OptionValue\Consumer\Service\OptionValueConsumerServiceInterface;
use Maatify\Product\OptionValue\Management\Command\CreateOptionValueCommand;
use Maatify\Product\OptionValue\Management\Service\OptionValueManagementServiceInterface;
use Maatify\Product\Product\Consumer\Service\ProductConsumerServiceInterface;
use Maatify\Product\Product\DTO\CreatedProductDTO;
use Maatify\Product\Product\Enum\ProductStatusEnum;
use Maatify\Product\Product\Management\Command\CreateProductCommand;
use Maatify\Product\Product\Management\Command\ReconfigureOptionTarget;
use Maatify\Product\Product\Management\Command\ReconfigureProductCommand;
use Maatify\Product\Product\Management\Command\ReconfigureVariantTarget;
use Maatify\Product\Product\Management\Service\ProductManagementServiceInterface;
use Maatify\Product\Tests\Integration\DatabaseTestCase;
use Maatify\Product\Variant\Consumer\Service\VariantConsumerServiceInterface;
use Maatify\Product\Variant\Management\Service\VariantManagementServiceInterface;
use Maatify\Product\Variant\ValueObject\OptionValueSelection;
use Maatify\SharedCommon\Contracts\ClockInterface;
use Maatify\SharedCommon\Infrastructure\SystemClock;
use PDO;
use Throwable;

/**
 * Base for runtime tests: each test starts from empty tables and commits for real,
 * so the service's own transaction handling is exercised without an outer transaction.
 *
 * Labels and Product names are seeded by direct SQL as a fixture shortcut; the Content commands
 * themselves are proven by ContentCommandTest. Everything else goes through the public API.
 *
 * After every test, a transaction still open on the shared connection is rolled back, so no
 * transaction leaks into the next test whatever the test did (std-ci-workflow §11).
 */
abstract class RuntimeTestCase extends DatabaseTestCase
{
    protected const string SEED_TS = '2026-01-01 00:00:00';
    public const string LATER_TS = '2099-01-01 00:00:00';

    private const array TABLES_CHILD_FIRST = [
        'maa_product_image_assignments',
        'maa_product_image_roles',
        'maa_product_variant_option_values',
        'maa_product_variants',
        'maa_product_option_value_contents',
        'maa_product_option_values',
        'maa_product_option_contents',
        'maa_product_options',
        'maa_product_product_contents',
        'maa_product_products',
    ];

    final protected function setUp(): void
    {
        foreach (self::TABLES_CHILD_FIRST as $table) {
            self::$pdo->exec('DELETE FROM `' . $table . '`');
        }
    }

    /**
     * Rolls back any transaction a test left open on the shared connection, then runs the test's own
     * cleanup. The rollback comes first because a cleanup DDL statement would implicitly commit it.
     */
    final protected function tearDown(): void
    {
        if (self::$pdo->inTransaction()) {
            self::$pdo->rollBack();
        }
        $this->cleanUp();
    }

    /**
     * Per-test cleanup that runs after the leaked-transaction rollback, such as dropping a trigger.
     */
    protected function cleanUp(): void {}

    protected static function clock(): SystemClock
    {
        return new SystemClock(new DateTimeZone('UTC'));
    }

    /**
     * A Host Clock fixed at {@see self::LATER_TS}, past every instant the setup can stamp, so any
     * write made through a service built with it carries a later `updated_at` than the setup left.
     */
    protected static function laterClock(): ClockInterface
    {
        return new class implements ClockInterface {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable(RuntimeTestCase::LATER_TS, new DateTimeZone('UTC'));
            }

            public function getTimezone(): DateTimeZone
            {
                return new DateTimeZone('UTC');
            }
        };
    }

    /**
     * A Host Clock fixed at one instant in a given timezone, for timestamp-source and hydration evidence.
     */
    protected static function fixedClock(string $instant, string $timezone): ClockInterface
    {
        return new class ($instant, $timezone) implements ClockInterface {
            public function __construct(
                private string $instant,
                private string $timezone,
            ) {}

            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable($this->instant, $this->getTimezone());
            }

            public function getTimezone(): DateTimeZone
            {
                return new DateTimeZone($this->timezone);
            }
        };
    }

    /**
     * Proves that the call writes nothing to the table: every row, `updated_at` included, is
     * identical before and after. Run the call through a service built with {@see self::laterClock()}
     * so that a write landing within the same second as the setup is still detected.
     */
    protected function assertNoWrite(string $table, callable $call, string $message): void
    {
        $before = self::tableRows($table);
        $call();
        self::assertSame($before, self::tableRows($table), $message);
    }

    /** @return list<array<string, mixed>> */
    private static function tableRows(string $table): array
    {
        $statement = self::$pdo->query('SELECT * FROM `' . $table . '` ORDER BY `id`');
        self::assertNotFalse($statement);

        /** @var list<array<string, mixed>> */
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    protected function productManagementService(?ClockInterface $clock = null): ProductManagementServiceInterface
    {
        return ProductFactory::createProductManagementService(self::$pdo, $clock ?? self::clock());
    }

    protected function productConsumerService(?ClockInterface $clock = null): ProductConsumerServiceInterface
    {
        return ProductFactory::createProductConsumerService(self::$pdo, $clock ?? self::clock());
    }

    protected function variantManagementService(?ClockInterface $clock = null): VariantManagementServiceInterface
    {
        return ProductFactory::createVariantManagementService(self::$pdo, $clock ?? self::clock());
    }

    protected function variantConsumerService(?ClockInterface $clock = null): VariantConsumerServiceInterface
    {
        return ProductFactory::createVariantConsumerService(self::$pdo, $clock ?? self::clock());
    }

    protected function optionManagementService(?ClockInterface $clock = null): OptionManagementServiceInterface
    {
        return ProductFactory::createOptionManagementService(self::$pdo, $clock ?? self::clock());
    }

    protected function optionConsumerService(?ClockInterface $clock = null): OptionConsumerServiceInterface
    {
        return ProductFactory::createOptionConsumerService(self::$pdo, $clock ?? self::clock());
    }

    protected function optionValueManagementService(?ClockInterface $clock = null): OptionValueManagementServiceInterface
    {
        return ProductFactory::createOptionValueManagementService(self::$pdo, $clock ?? self::clock());
    }

    protected function optionValueConsumerService(?ClockInterface $clock = null): OptionValueConsumerServiceInterface
    {
        return ProductFactory::createOptionValueConsumerService(self::$pdo, $clock ?? self::clock());
    }

    protected function imageRoleManagementService(?ClockInterface $clock = null): ImageRoleManagementServiceInterface
    {
        return ProductFactory::createImageRoleManagementService(self::$pdo, $clock ?? self::clock());
    }

    protected function imageAssignmentManagementService(?ClockInterface $clock = null): ImageAssignmentManagementServiceInterface
    {
        return ProductFactory::createImageAssignmentManagementService(self::$pdo, $clock ?? self::clock());
    }

    protected function imageAssignmentConsumerService(?ClockInterface $clock = null): ImageAssignmentConsumerServiceInterface
    {
        return ProductFactory::createImageAssignmentConsumerService(self::$pdo, $clock ?? self::clock());
    }

    protected function productContentManagementService(?ClockInterface $clock = null): ProductContentManagementServiceInterface
    {
        return ProductFactory::createProductContentManagementService(self::$pdo, $clock ?? self::clock());
    }

    protected function productContentConsumerService(?ClockInterface $clock = null): ProductContentConsumerServiceInterface
    {
        return ProductFactory::createProductContentConsumerService(self::$pdo, $clock ?? self::clock());
    }

    protected function optionContentManagementService(?ClockInterface $clock = null): OptionContentManagementServiceInterface
    {
        return ProductFactory::createOptionContentManagementService(self::$pdo, $clock ?? self::clock());
    }

    protected function optionContentConsumerService(?ClockInterface $clock = null): OptionContentConsumerServiceInterface
    {
        return ProductFactory::createOptionContentConsumerService(self::$pdo, $clock ?? self::clock());
    }

    protected function optionValueContentManagementService(?ClockInterface $clock = null): OptionValueContentManagementServiceInterface
    {
        return ProductFactory::createOptionValueContentManagementService(self::$pdo, $clock ?? self::clock());
    }

    protected function optionValueContentConsumerService(?ClockInterface $clock = null): OptionValueContentConsumerServiceInterface
    {
        return ProductFactory::createOptionValueContentConsumerService(self::$pdo, $clock ?? self::clock());
    }

    protected static function rowCount(string $table, string $where = '1 = 1'): int
    {
        return (int) self::scalar('SELECT COUNT(*) FROM `' . $table . '` WHERE ' . $where);
    }

    /**
     * Creates a Product through the public API and seeds an unlocalized name for it.
     */
    protected function namedProduct(string $code): CreatedProductDTO
    {
        $created = $this->productManagementService()->create(new CreateProductCommand($code));
        $this->nameProduct($created->productId, 'Name of ' . $code);

        return $created;
    }

    protected function nameProduct(int $productId, string $name): void
    {
        $this->execute(
            'INSERT INTO maa_product_product_contents (product_id, language_code, name, created_at, updated_at) VALUES (:p, NULL, :n, :t, :u)',
            ['p' => $productId, 'n' => $name, 't' => self::SEED_TS, 'u' => self::SEED_TS],
        );
    }

    /**
     * Creates an inactive Option through the public API and seeds its label.
     */
    protected function preparedOption(int $productId, string $code, ?string $label = null): int
    {
        $optionId = $this->optionManagementService()->create(new CreateOptionCommand($productId, $code))->optionId;
        if ($label !== null) {
            $this->labelOption($optionId, $label);
        }

        return $optionId;
    }

    protected function labelOption(int $optionId, string $label): void
    {
        $this->execute(
            'INSERT INTO maa_product_option_contents (option_id, language_code, name, created_at, updated_at) VALUES (:i, NULL, :n, :t, :u)',
            ['i' => $optionId, 'n' => $label, 't' => self::SEED_TS, 'u' => self::SEED_TS],
        );
    }

    /**
     * Creates an inactive Option Value through the public API and seeds its label.
     */
    protected function preparedValue(int $optionId, string $code, ?string $label = null): int
    {
        $valueId = $this->optionValueManagementService()->create(new CreateOptionValueCommand($optionId, $code))->optionValueId;
        if ($label !== null) {
            $this->labelValue($valueId, $label);
        }

        return $valueId;
    }

    protected function labelValue(int $valueId, string $label): void
    {
        $this->execute(
            'INSERT INTO maa_product_option_value_contents (option_value_id, language_code, name, created_at, updated_at) VALUES (:i, NULL, :n, :t, :u)',
            ['i' => $valueId, 'n' => $label, 't' => self::SEED_TS, 'u' => self::SEED_TS],
        );
    }

    /**
     * Builds a reconfiguration command from plain identities.
     *
     * @param array<int, list<int>> $options optionId => active value ids
     * @param list<array{0: list<array{int, int}>, 1?: int|null}> $variants each: [[optionId, valueId], ...] and an optional reuse identity
     */
    protected function reconfiguration(int $productId, array $options, array $variants, ?int $revision = null): ReconfigureProductCommand
    {
        $optionTargets = [];
        foreach ($options as $optionId => $valueIds) {
            $optionTargets[] = new ReconfigureOptionTarget($optionId, $valueIds);
        }
        $variantTargets = [];
        foreach ($variants as $variant) {
            $variantTargets[] = new ReconfigureVariantTarget(
                array_map(static fn(array $pair): OptionValueSelection => new OptionValueSelection($pair[0], $pair[1]), $variant[0]),
                $variant[1] ?? null,
            );
        }

        return new ReconfigureProductCommand($productId, $revision ?? $this->productManagementService()->getConfigurationRevision($productId), $optionTargets, $variantTargets);
    }

    protected function activateProduct(int $productId): void
    {
        $this->productManagementService()->updateStatus($productId, ProductStatusEnum::ACTIVE);
    }

    /**
     * @param class-string<Throwable> $exception
     */
    protected function assertThrows(string $exception, callable $call, string $message = ''): Throwable
    {
        try {
            $call();
        } catch (Throwable $caught) {
            self::assertInstanceOf($exception, $caught, $message !== '' ? $message : 'Unexpected exception: ' . $caught::class . ': ' . $caught->getMessage());

            return $caught;
        }
        self::fail($message !== '' ? $message : 'Expected ' . $exception . ' was not raised.');
    }
}
