<?php

/**
 * Example 3: read the Variants of a Product for management and for consumers, and set its SKU and barcode.
 *
 * Input -> Public Call -> Result -> Boundary:
 *  - Input:    a Product code supplied by the Host.
 *  - Calls:    ProductManagementServiceInterface::create(), VariantManagementServiceInterface::getByIdForManagement(),
 *              listByProductIdForManagement(), updateIdentifiers(), VariantConsumerServiceInterface::getById(),
 *              listByProductId().
 *  - Result:   a VariantDTO and VariantCollectionDTO; consumer reads return only visible Variants.
 *  - Boundary: a Variant is consumer-visible only while its Product and the Variant are active
 *              and its composition is valid; activating a Product is the Host's concern here. A SKU or
 *              barcode is left unchanged, set, or cleared explicitly, and is unique across all Variants.
 *
 * Environment: see example 01.
 */

declare(strict_types=1);

use Maatify\Product\Factory\ProductFactory;
use Maatify\Product\Product\Management\Command\CreateProductCommand;
use Maatify\Product\Variant\Enum\VariantStatusEnum;
use Maatify\Product\Variant\Exception\VariantNotFoundException;
use Maatify\Product\Variant\Management\Command\UpdateVariantIdentifiersCommand;
use Maatify\Product\Variant\Management\Criteria\VariantListCriteriaDTO;
use Maatify\Product\Variant\ValueObject\VariantIdentifierChange;
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
$clock = new SystemClock(new DateTimeZone('UTC'));
$products = ProductFactory::createProductManagementService($pdo, $clock);
$variants = ProductFactory::createVariantManagementService($pdo, $clock);
$consumerVariants = ProductFactory::createVariantConsumerService($pdo, $clock);

$created = $products->create(new CreateProductCommand('VARIANTS-' . bin2hex(random_bytes(4))));

// Management read: sees the Variant whatever the Product's status.
$managed = $variants->getByIdForManagement($created->variantId);
echo "management read: variant {$managed->id} of product {$managed->productId} is {$managed->status->value}, composition pairs: " . count($managed->composition) . "\n";

$list = $variants->listByProductIdForManagement($created->productId, new VariantListCriteriaDTO(VariantStatusEnum::ACTIVE));
echo 'management list (active): ' . count($list) . "\n";

// Consumer read: the Product is inactive, so nothing is visible.
echo 'consumer list while the Product is inactive: ' . count($consumerVariants->listByProductId($created->productId)) . "\n";
try {
    $consumerVariants->getById($created->variantId);
    throw new RuntimeException('The Variant of an inactive Product must not be consumer-visible.');
} catch (VariantNotFoundException) {
    echo "consumer read: not visible\n";
}

if (count($list) !== 1 || count($consumerVariants->listByProductId($created->productId)) !== 0) {
    throw new RuntimeException('Unexpected Variant read results.');
}

// Secondary identifiers: set a SKU and a barcode (leading zeroes are kept), then clear the SKU only.
$suffix = bin2hex(random_bytes(4));
$variants->updateIdentifiers(new UpdateVariantIdentifiersCommand($created->variantId, VariantIdentifierChange::set('SKU-' . $suffix), VariantIdentifierChange::set('00' . $suffix)));
$variants->updateIdentifiers(new UpdateVariantIdentifiersCommand($created->variantId, VariantIdentifierChange::clear(), VariantIdentifierChange::unchanged()));
$identified = $variants->getByIdForManagement($created->variantId);
if ($identified->sku !== null || $identified->barcode !== '00' . $suffix) {
    throw new RuntimeException('Unexpected Variant identifiers.');
}
echo "identifiers: sku cleared, barcode {$identified->barcode}\n";

echo "OK: example 03 completed\n";
