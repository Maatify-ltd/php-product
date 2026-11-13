<?php

/**
 * Example 5: what a storefront reads to present a configurable Product.
 *
 * Input -> Public Call -> Result -> Boundary:
 *  - Input:    an active, configured Product with labels, a name per language, and exact-scope images.
 *  - Calls:    OptionConsumerServiceInterface::listVisibleByProductId(), OptionValueConsumerServiceInterface::listVisibleByOptionId(),
 *              ProductContentConsumerServiceInterface::listVisible(), OptionContentConsumerServiceInterface::listVisible(),
 *              ImageAssignmentConsumerServiceInterface::listVisibleByExactScope().
 *  - Result:   the selectable Options and Values, the exact-language Content, and the images of one exact scope.
 *  - Boundary: consumer reads apply the visibility rules and never fall back: no language fallback, no
 *              Product-level images for a Variant, and no default chosen by the read. The Host owns fallback.
 *
 * Environment: see example 01.
 */

declare(strict_types=1);

use Maatify\Product\Content\Management\Command\CreateOptionContentCommand;
use Maatify\Product\Content\Management\Command\CreateOptionValueContentCommand;
use Maatify\Product\Content\Management\Command\CreateProductContentCommand;
use Maatify\Product\Content\ValueObject\ExactContentLanguage;
use Maatify\Product\Factory\ProductFactory;
use Maatify\Product\ImageAssignment\Consumer\Criteria\ImageAssignmentExactScopeCriteriaDTO;
use Maatify\Product\ImageAssignment\Management\Command\CreateImageAssignmentCommand;
use Maatify\Product\ImageRole\Management\Command\CreateImageRoleCommand;
use Maatify\Product\Option\Management\Command\CreateOptionCommand;
use Maatify\Product\OptionValue\Management\Command\CreateOptionValueCommand;
use Maatify\Product\Product\Enum\ProductStatusEnum;
use Maatify\Product\Product\Management\Command\CreateProductCommand;
use Maatify\Product\Product\Management\Command\ReconfigureOptionTarget;
use Maatify\Product\Product\Management\Command\ReconfigureProductCommand;
use Maatify\Product\Product\Management\Command\ReconfigureVariantTarget;
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
$consumerOptions = ProductFactory::createOptionConsumerService($pdo, $clock);
$values = ProductFactory::createOptionValueManagementService($pdo, $clock);
$consumerValues = ProductFactory::createOptionValueConsumerService($pdo, $clock);
$productContent = ProductFactory::createProductContentManagementService($pdo, $clock);
$consumerProductContent = ProductFactory::createProductContentConsumerService($pdo, $clock);
$optionContent = ProductFactory::createOptionContentManagementService($pdo, $clock);
$consumerOptionContent = ProductFactory::createOptionContentConsumerService($pdo, $clock);
$valueContent = ProductFactory::createOptionValueContentManagementService($pdo, $clock);
$consumerValueContent = ProductFactory::createOptionValueContentConsumerService($pdo, $clock);
$roles = ProductFactory::createImageRoleManagementService($pdo, $clock);
$images = ProductFactory::createImageAssignmentManagementService($pdo, $clock);
$consumerImages = ProductFactory::createImageAssignmentConsumerService($pdo, $clock);

// Catalog setup (management side): a colour-configurable Product with labels, names, and images.
$suffix = bin2hex(random_bytes(4));
$productId = $products->create(new CreateProductCommand('MUG-' . $suffix))->productId;
$color = $options->create(new CreateOptionCommand($productId, 'color'))->optionId;
$white = $values->create(new CreateOptionValueCommand($color, 'white'))->optionValueId;
$black = $values->create(new CreateOptionValueCommand($color, 'black'))->optionValueId;
$productContent->create(new CreateProductContentCommand($productId, null, 'Mug'));
$productContent->create(new CreateProductContentCommand($productId, 'ar', 'كوب'));
$optionContent->create(new CreateOptionContentCommand($color, null, 'Colour'));
$valueContent->create(new CreateOptionValueContentCommand($white, null, 'White'));
$valueContent->create(new CreateOptionValueContentCommand($black, null, 'Black'));
$result = $products->reconfigure(new ReconfigureProductCommand($productId, $products->getConfigurationRevision($productId), [new ReconfigureOptionTarget($color, [$white, $black])], [
    new ReconfigureVariantTarget([new OptionValueSelection($color, $white)]),
    new ReconfigureVariantTarget([new OptionValueSelection($color, $black)]),
]));
$whiteVariant = $result->createdVariantIds[0];
$products->updateStatus($productId, ProductStatusEnum::ACTIVE);
$hero = $roles->create(new CreateImageRoleCommand('hero-' . $suffix))->roleId;
$images->create(new CreateImageAssignmentCommand($productId, null, 1001, $hero));
$images->create(new CreateImageAssignmentCommand($productId, $whiteVariant, 1002, $hero));

// Presentation (consumer side): selectable Options and Values with their exact labels.
foreach ($consumerOptions->listVisibleByProductId($productId) as $option) {
    $label = $consumerOptionContent->listVisible($option->id, ExactContentLanguage::unlocalizedOnly())->items()[0]->name ?? $option->code;
    $choices = [];
    foreach ($consumerValues->listVisibleByOptionId($option->id) as $value) {
        $choices[] = $consumerValueContent->listVisible($value->id, ExactContentLanguage::unlocalizedOnly())->items()[0]->name ?? $value->code;
    }
    echo $label . ': ' . implode(', ', $choices) . "\n";
}

// Exact Content: Arabic exists; French does not, and the Host decides whether to fall back.
$arabic = $consumerProductContent->listVisible($productId, ExactContentLanguage::exactLanguage('ar'));
$french = $consumerProductContent->listVisible($productId, ExactContentLanguage::exactLanguage('fr'));
if (count($arabic) !== 1 || !$french->isEmpty()) {
    throw new RuntimeException('Content reads must be exact.');
}

// Exact-scope images: the white Variant has its own hero image; the black Variant has none and gets no Product-level fallback.
$productHero = $consumerImages->listVisibleByExactScope(new ImageAssignmentExactScopeCriteriaDTO($productId, null, $hero, null, null));
$whiteHero = $consumerImages->listVisibleByExactScope(new ImageAssignmentExactScopeCriteriaDTO($productId, $whiteVariant, $hero, null, null));
$blackHero = $consumerImages->listVisibleByExactScope(new ImageAssignmentExactScopeCriteriaDTO($productId, $result->createdVariantIds[1], $hero, null, null));
if (count($productHero) !== 1 || count($whiteHero) !== 1 || !$blackHero->isEmpty()) {
    throw new RuntimeException('Image reads must be exact.');
}
echo 'hero images: product ' . $productHero->items()[0]->mediaAssetId . ', white variant ' . $whiteHero->items()[0]->mediaAssetId . ", black variant none\n";

echo "OK: example 05 completed\n";
