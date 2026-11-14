<?php

/**
 * Child process for the concurrency test: opens its own connection, builds the public service
 * through the factory, reports `ready` on stdout, and waits for one line on stdin. The parent
 * releases every worker at once only after all of them are ready, so no timing is assumed. It then
 * creates one Product and prints the outcome.
 *
 * Usage: php create-product-worker.php <code>
 */

declare(strict_types=1);

use Maatify\Product\Factory\ProductFactory;
use Maatify\Product\Product\Exception\ProductCodeAlreadyExistsException;
use Maatify\Product\Product\Management\Command\CreateProductCommand;
use Maatify\SharedCommon\Infrastructure\SystemClock;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

/** @var list<string> $argv */
$code = $argv[1] ?? '';

$env = static function (string $name): string {
    $value = getenv($name);

    return is_string($value) ? $value : '';
};

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $env('PRODUCT_TEST_DB_HOST'), $env('PRODUCT_TEST_DB_PORT'), $env('PRODUCT_TEST_DB_NAME')),
    $env('PRODUCT_TEST_DB_USER'),
    $env('PRODUCT_TEST_DB_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
);
$service = ProductFactory::createProductManagementService($pdo, new SystemClock(new DateTimeZone('UTC')));

fwrite(STDOUT, "ready\n");
fflush(STDOUT);
if (fgets(STDIN) === false) {
    fwrite(STDERR, 'The release signal never arrived.');
    exit(1);
}

try {
    $created = $service->create(new CreateProductCommand($code));
    echo 'created:' . $created->productId . ':' . $created->variantId;
} catch (ProductCodeAlreadyExistsException) {
    echo 'conflict';
} catch (Throwable $e) {
    echo 'error:' . $e::class . ':' . $e->getMessage();
}
