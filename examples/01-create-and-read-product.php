<?php

/**
 * Example 1: create a Product with its Variant and read it back.
 *
 * Input -> Public Call -> Result -> Boundary:
 *  - Input:    a Product code supplied by the Host.
 *  - Calls:    ProductFactory::createProductManagementService(), ProductFactory::createProductConsumerService(),
 *              ProductManagementServiceInterface::create(), getByIdForManagement(),
 *              ProductConsumerServiceInterface::getById().
 *  - Result:   a CreatedProductDTO (Product and Variant identities) and a ProductDTO.
 *  - Boundary: the Host owns the PDO connection, the Clock, and the installed schema;
 *              the package owns Product and Variant data only.
 *
 * Environment: PRODUCT_EXAMPLE_DSN, PRODUCT_EXAMPLE_USER, PRODUCT_EXAMPLE_PASSWORD point at a
 * MySQL 8.0.16+ database where schema/product.sql is installed. PRODUCT_EXAMPLE_AUTOLOAD may
 * name the Composer autoloader of your project (default: ../vendor/autoload.php).
 */

declare(strict_types=1);

use Maatify\Product\Common\Enum\ProductDeletedStateEnum;
use Maatify\Product\Factory\ProductFactory;
use Maatify\Product\Product\Enum\ProductStatusEnum;
use Maatify\Product\Product\Exception\ProductCodeAlreadyExistsException;
use Maatify\Product\Product\Exception\ProductNotFoundException;
use Maatify\Product\Product\Management\Command\CreateProductCommand;
use Maatify\SharedCommon\Infrastructure\SystemClock;

$autoload = getenv('PRODUCT_EXAMPLE_AUTOLOAD');
require is_string($autoload) && $autoload !== '' ? $autoload : __DIR__ . '/../vendor/autoload.php';

$setting = static function (string $name): string {
    $value = getenv($name);
    if (!is_string($value) || $value === '') {
        fwrite(STDERR, "Set {$name} (see the header of this example).\n");
        exit(2);
    }

    return $value;
};

// The Host supplies a PDO connection that reports errors as exceptions, and its own Clock.
$pdo = new PDO(
    $setting('PRODUCT_EXAMPLE_DSN'),
    $setting('PRODUCT_EXAMPLE_USER'),
    $setting('PRODUCT_EXAMPLE_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
);
$clock = new SystemClock(new DateTimeZone('UTC'));
$products = ProductFactory::createProductManagementService($pdo, $clock);
$consumerProducts = ProductFactory::createProductConsumerService($pdo, $clock);

// Input -> Public Call -> Result.
$code = 'EXAMPLE-' . bin2hex(random_bytes(4));
$created = $products->create(new CreateProductCommand($code));
echo "created product {$created->productId} with variant {$created->variantId}\n";

// A new Product is inactive, so the management read sees it and the consumer read does not.
$product = $products->getByIdForManagement($created->productId);
echo "management read: {$product->code} is {$product->status->value}\n";
if ($product->status !== ProductStatusEnum::INACTIVE) {
    throw new RuntimeException('A new Product must be inactive.');
}

try {
    $consumerProducts->getById($created->productId);
    throw new RuntimeException('An inactive Product must not be consumer-visible.');
} catch (ProductNotFoundException) {
    echo "consumer read: not visible while inactive\n";
}

// Boundary: soft-deleted Products stay reserved; the management read filters by deleted state.
$all = $products->getByIdForManagement($created->productId, ProductDeletedStateEnum::INCLUDE_DELETED);
echo "include-deleted read: deletedAt is " . ($all->deletedAt === null ? 'null' : $all->deletedAt->format(DATE_ATOM)) . "\n";

// Failure handling: a code is unique case-insensitively across all history.
try {
    $products->create(new CreateProductCommand(strtolower($code)));
    throw new RuntimeException('A duplicate code must conflict.');
} catch (ProductCodeAlreadyExistsException) {
    echo "duplicate code rejected\n";
}

echo "OK: example 01 completed\n";
