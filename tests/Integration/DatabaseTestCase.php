<?php

declare(strict_types=1);

namespace Maatify\Product\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Base for tests against the real MySQL service provisioned by
 * scripts/ci/run-integration.sh.
 *
 * Each test class installs schema/product.sql into its own fresh database and drops
 * it afterwards. Missing configuration fails the test; nothing is skipped.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected const int DUPLICATE_KEY = 1062;
    protected const int FK_CHILD_MISSING_PARENT = 1452;
    protected const int FK_PARENT_REFERENCED = 1451;
    protected const int CHECK_VIOLATED = 3819;
    protected const int NO_DEFAULT = 1364;
    protected const int DATA_TOO_LONG = 1406;
    protected const int OUT_OF_RANGE = 1264;

    protected static PDO $pdo;

    protected static string $database = '';

    final public static function setUpBeforeClass(): void
    {
        self::$pdo = self::connect(null);
        self::$database = 'product_it_' . bin2hex(random_bytes(6));
        self::$pdo->exec('CREATE DATABASE `' . self::$database . '`');
        self::$pdo = self::connect(self::$database);

        $expected = self::env('PRODUCT_TEST_EXPECTED_VERSION');
        $actual = self::scalar('SELECT VERSION()');
        self::assertIsString($actual);
        self::assertStringStartsWith($expected, $actual, 'Connected to an unexpected MySQL server version.');

        self::installSchema();
    }

    final public static function tearDownAfterClass(): void
    {
        if (self::$database !== '') {
            self::$pdo = self::connect(null);
            self::$pdo->exec('DROP DATABASE IF EXISTS `' . self::$database . '`');
            self::$database = '';
        }
    }

    /** @return non-empty-string */
    protected static function env(string $name): string
    {
        $value = getenv($name);
        if (!is_string($value) || $value === '') {
            self::fail(sprintf('Required Integration environment variable %s is not set.', $name));
        }

        return $value;
    }

    protected static function connect(?string $database): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;charset=utf8mb4%s',
            self::env('PRODUCT_TEST_DB_HOST'),
            self::env('PRODUCT_TEST_DB_PORT'),
            $database === null ? '' : ';dbname=' . $database,
        );

        return new PDO($dsn, self::env('PRODUCT_TEST_DB_USER'), self::env('PRODUCT_TEST_DB_PASSWORD'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_NUM,
        ]);
    }

    protected static function schemaPath(): string
    {
        return dirname(__DIR__, 2) . '/schema/product.sql';
    }

    /** @return list<string> */
    protected static function schemaStatements(): array
    {
        $sql = file_get_contents(self::schemaPath());
        self::assertIsString($sql, 'schema/product.sql must be readable.');
        $withoutComments = preg_replace('/^--.*$/m', '', $sql);
        self::assertIsString($withoutComments);
        $statements = [];
        foreach (explode(";\n", $withoutComments) as $statement) {
            $statement = trim($statement);
            if ($statement !== '') {
                $statements[] = $statement;
            }
        }

        return $statements;
    }

    protected static function installSchema(): void
    {
        foreach (self::schemaStatements() as $statement) {
            self::$pdo->exec($statement);
        }
    }

    /** @param array<string, int|string|null> $params */
    protected static function scalar(string $sql, array $params = []): int|string|null
    {
        $statement = self::$pdo->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch(PDO::FETCH_NUM);
        if (!is_array($row)) {
            return null;
        }
        $value = $row[0] ?? null;
        self::assertTrue($value === null || is_int($value) || is_string($value));

        return $value;
    }

    /** @param array<string, int|string|null> $params */
    protected function execute(string $sql, array $params = []): void
    {
        self::$pdo->prepare($sql)->execute($params);
    }
}
