<?php

/**
 * Example 4: make a Product configurable, resolve a shopper's choices, and go back to simple.
 *
 * Input -> Public Call -> Result -> Boundary:
 *  - Input:    prepared Options and Values (created inactive) with their labels, a Product name, and
 *              the combinations the Host offers.
 *  - Calls:    OptionManagementServiceInterface::create(), OptionValueManagementServiceInterface::create(),
 *              ProductContentManagementServiceInterface::create(), OptionContentManagementServiceInterface::create(),
 *              OptionValueContentManagementServiceInterface::create(), ProductContentConsumerServiceInterface::listVisible(),
 *              OptionManagementServiceInterface::updateDisplayOrder(),
 *              ProductManagementServiceInterface::reconfigure(), updateStatus(),
 *              VariantConsumerServiceInterface::resolveByComposition().
 *  - Result:   a ProductReconfigurationResultDTO with the created and deactivated Variant identities,
 *              and the VariantDTO a complete selection resolves to.
 *  - Boundary: reconfiguration is one atomic Product-local operation over a complete target state; an
 *              existing composition is reused only when the command names its Variant. Every active
 *              Option and Value needs a label and an active Product needs a name, all Content records;
 *              a consumer Content read is exact and never falls back to another language.
 *
 * Environment: see example 01.
 */

declare(strict_types=1);

use Maatify\Product\Common\Exception\ProductInvalidArgumentException;
use Maatify\Product\Content\Management\Command\CreateOptionContentCommand;
use Maatify\Product\Content\Management\Command\CreateOptionValueContentCommand;
use Maatify\Product\Content\Management\Command\CreateProductContentCommand;
use Maatify\Product\Content\ValueObject\ExactContentLanguage;
use Maatify\Product\Factory\ProductFactory;
use Maatify\Product\Option\Management\Command\CreateOptionCommand;
use Maatify\Product\Option\Management\Command\UpdateOptionDisplayOrderCommand;
use Maatify\Product\OptionValue\Management\Command\CreateOptionValueCommand;
use Maatify\Product\Product\Enum\ProductStatusEnum;
use Maatify\Product\Product\Management\Command\CreateProductCommand;
use Maatify\Product\Product\Management\Command\ReconfigureOptionTarget;
use Maatify\Product\Product\Management\Command\ReconfigureProductCommand;
use Maatify\Product\Product\Management\Command\ReconfigureVariantTarget;
use Maatify\Product\Variant\Consumer\Criteria\VariantCompositionCriteriaDTO;
use Maatify\Product\Variant\Exception\VariantCompositionConflictException;
use Maatify\Product\Variant\ValueObject\OptionValueSelection;
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
$options = ProductFactory::createOptionManagementService($pdo, $clock);
$values = ProductFactory::createOptionValueManagementService($pdo, $clock);
$consumerVariants = ProductFactory::createVariantConsumerService($pdo, $clock);
$productContent = ProductFactory::createProductContentManagementService($pdo, $clock);
$consumerProductContent = ProductFactory::createProductContentConsumerService($pdo, $clock);
$optionContent = ProductFactory::createOptionContentManagementService($pdo, $clock);
$valueContent = ProductFactory::createOptionValueContentManagementService($pdo, $clock);

$created = $products->create(new CreateProductCommand('SHIRT-' . bin2hex(random_bytes(4))));
$productId = $created->productId;

// Prepare the configuration while it is inactive: Options and Values are created inactive.
$color = $options->create(new CreateOptionCommand($productId, 'color'))->optionId;
$size = $options->create(new CreateOptionCommand($productId, 'size'))->optionId;
$red = $values->create(new CreateOptionValueCommand($color, 'red'))->optionValueId;
$blue = $values->create(new CreateOptionValueCommand($color, 'blue'))->optionValueId;
$medium = $values->create(new CreateOptionValueCommand($size, 'm'))->optionValueId;

// Ordering: move Size before Color within the Product; the shared Ordering API shifts Color down.
$options->updateDisplayOrder(new UpdateOptionDisplayOrderCommand($size, 1));
if ($options->getByIdForManagement($size)->displayOrder !== 1 || $options->getByIdForManagement($color)->displayOrder !== 2) {
    throw new RuntimeException('Unexpected Option order.');
}
echo "option order: size, color\n";

// The Product name and the labels are Content records: one per language, `null` is the unlocalized one.
$productContent->create(new CreateProductContentCommand($productId, null, 'Shirt', 'A plain shirt'));
$productContent->create(new CreateProductContentCommand($productId, 'ar', 'قميص'));
$optionContent->create(new CreateOptionContentCommand($color, null, 'Color'));
$optionContent->create(new CreateOptionContentCommand($size, null, 'Size'));
foreach ([[$red, 'Red'], [$blue, 'Blue'], [$medium, 'Medium']] as [$valueId, $label]) {
    $valueContent->create(new CreateOptionValueContentCommand($valueId, null, $label));
}

// Simple -> configurable: the complete target state, with only the combinations the Host offers.
$offered = [
    new ReconfigureVariantTarget([new OptionValueSelection($color, $red), new OptionValueSelection($size, $medium)]),
    new ReconfigureVariantTarget([new OptionValueSelection($size, $medium), new OptionValueSelection($color, $blue)]),
];
$result = $products->reconfigure(new ReconfigureProductCommand(
    $productId,
    $products->getConfigurationRevision($productId),
    [new ReconfigureOptionTarget($color, [$red, $blue]), new ReconfigureOptionTarget($size, [$medium])],
    $offered,
));
echo 'created Variants: ' . implode(', ', $result->createdVariantIds) . '; deactivated: ' . implode(', ', $result->deactivatedVariantIds) . "\n";
$products->updateStatus($productId, ProductStatusEnum::ACTIVE);

// The shopper picks blue and M, in any order; resolution returns the one visible Variant.
$resolved = $consumerVariants->resolveByComposition($productId, new VariantCompositionCriteriaDTO([
    new OptionValueSelection($size, $medium),
    new OptionValueSelection($color, $blue),
]));
echo "resolved variant: {$resolved->id}\n";

// Content reads are exact: Arabic exists, English does not, and nothing falls back to the unlocalized name.
$arabic = $consumerProductContent->listVisible($productId, ExactContentLanguage::exactLanguage('ar'));
$english = $consumerProductContent->listVisible($productId, ExactContentLanguage::exactLanguage('en'));
if (count($arabic) !== 1 || !$english->isEmpty()) {
    throw new RuntimeException('Content reads must be exact.');
}
echo 'name (ar): ' . $arabic->items()[0]->name . "; name (en): none\n";

try {
    $consumerVariants->resolveByComposition($productId, new VariantCompositionCriteriaDTO([new OptionValueSelection($color, $red)]));
    throw new RuntimeException('A partial selection must be rejected.');
} catch (ProductInvalidArgumentException) {
    echo "partial selection: rejected\n";
}

// Repeating an existing composition without naming its Variant is a conflict: identities are never duplicated.
try {
    $products->reconfigure(new ReconfigureProductCommand(
        $productId,
        $products->getConfigurationRevision($productId),
        [new ReconfigureOptionTarget($color, [$red]), new ReconfigureOptionTarget($size, [$medium])],
        [new ReconfigureVariantTarget([new OptionValueSelection($color, $red), new OptionValueSelection($size, $medium)])],
    ));
    throw new RuntimeException('An existing composition must conflict.');
} catch (VariantCompositionConflictException) {
    echo "existing composition without reuseVariantId: conflict\n";
}

// Configurable -> simple: reuse the original empty-composition Variant by identity.
$back = $products->reconfigure(new ReconfigureProductCommand($productId, $products->getConfigurationRevision($productId), [], [new ReconfigureVariantTarget([], $created->variantId)]));
if ($back->reactivatedVariantIds !== [$created->variantId] || count($back->deactivatedVariantIds) !== 2) {
    throw new RuntimeException('Unexpected transition result.');
}
echo 'back to simple: ' . $consumerVariants->resolveByComposition($productId, new VariantCompositionCriteriaDTO())->id . "\n";

echo "OK: example 04 completed\n";
