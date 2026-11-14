<?php

/**
 * Example 2: compose Product creation inside a Host-owned transaction.
 *
 * Input -> Public Call -> Result -> Boundary:
 *  - Input:    the Host opens its own transaction on the PDO connection it gave the factory.
 *  - Calls:    ProductManagementServiceInterface::create() inside that transaction.
 *  - Result:   a successful create is committed or rolled back by the Host; a failed create
 *              leaves none of its writes in the Host transaction.
 *  - Boundary: the package never commits or rolls back a transaction it did not start.
 *              Composition requires every participant to use that one connection.
 *
 * Environment: see example 01.
 */

declare(strict_types=1);

use Maatify\Product\Factory\ProductFactory;
use Maatify\Product\Product\Exception\ProductCodeAlreadyExistsException;
use Maatify\Product\Product\Management\Command\CreateProductCommand;
use Maatify\SharedCommon\Infrastructure\SystemClock;

$autoload = getenv('PRODUCT_EXAMPLE_AUTOLOAD');
require is_string($autoload) && $autoload !== '' ? $autoload : __DIR__ . '/../vendor/autoload.php';

$setting = static function (string $name): string {
    $value = getenv($name);
    if (!is_string($value) || $value === '') {
        fwrite(STDERR, "Set {$name} (see the header of example 01).\n");
        exit(2);
    }

    return $value;
};

$pdo = new PDO(
    $setting('PRODUCT_EXAMPLE_DSN'),
    $setting('PRODUCT_EXAMPLE_USER'),
    $setting('PRODUCT_EXAMPLE_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
);
$products = ProductFactory::createProductManagementService($pdo, new SystemClock(new DateTimeZone('UTC')));

$first = 'HOST-TX-' . bin2hex(random_bytes(4));
$second = 'HOST-TX-' . bin2hex(random_bytes(4));

// The Host owns this transaction; the package joins it and never ends it.
$pdo->beginTransaction();
$products->create(new CreateProductCommand($first));

try {
    // A failing call: the code is already used by the first create in this same transaction.
    $products->create(new CreateProductCommand(strtolower($first)));
} catch (ProductCodeAlreadyExistsException) {
    echo "duplicate rejected; the Host transaction is still active: " . ($pdo->inTransaction() ? 'yes' : 'no') . "\n";
}

$products->create(new CreateProductCommand($second));
$pdo->commit();

$products_count = $pdo->prepare('SELECT COUNT(*) FROM maa_product_products WHERE code IN (?, ?)');
$products_count->execute([$first, $second]);
$variants_count = $pdo->prepare(
    'SELECT COUNT(*) FROM maa_product_variants v JOIN maa_product_products p ON p.id = v.product_id WHERE p.code IN (?, ?)',
);
$variants_count->execute([$first, $second]);
$committedProducts = (int) $products_count->fetchColumn();
$committedVariants = (int) $variants_count->fetchColumn();
echo "committed products: {$committedProducts}, variants: {$committedVariants}\n";

// Every committed Product has its Variant, and the failed call left nothing behind.
if ($committedProducts !== 2 || $committedVariants !== 2) {
    throw new RuntimeException('Expected exactly two committed Products with one Variant each.');
}

echo "OK: example 02 completed\n";
