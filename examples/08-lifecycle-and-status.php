<?php

/**
 * Example 8: the lifecycle of Products, Variants, Options, and Option Values, and their ordering.
 *
 * Input -> Public Call -> Result -> Boundary:
 *  - Input:    a simple Product to activate, deactivate, delete, and restore, a configurable Product
 *              with an active configuration plus an inactive Option kept outside it, and a configurable
 *              Product whose every Value is represented by two Variants.
 *  - Calls:    ProductManagementServiceInterface::updateStatus(), softDelete(), restore(), getByIdForManagement(),
 *              reconfigure(), ProductConsumerServiceInterface::getById(),
 *              VariantManagementServiceInterface::updateStatus(), softDelete(), restore(), updateDisplayOrder(),
 *              getByIdForManagement(), listByProductIdForManagement(), paginateByProductIdForManagement(),
 *              OptionManagementServiceInterface::create(), updateStatus(), softDelete(), restore(), getByIdForManagement(),
 *              OptionValueManagementServiceInterface::create(), updateStatus(), softDelete(), restore(),
 *              updateDisplayOrder(), getByIdForManagement(), listByOptionIdForManagement(),
 *              ProductContentManagementServiceInterface::create(), update(), softDelete(), restore(),
 *              getByIdForManagement(), OptionContentManagementServiceInterface::create(),
 *              OptionValueContentManagementServiceInterface::create().
 *  - Result:   status and deleted state read back through the management reads; `void` commands.
 *  - Boundary: a soft-deleted row accepts nothing but restore(), and restore() keeps the saved status and
 *              places an ordered row at the end of its scope. Every lifecycle change is validated on the
 *              whole Product configuration: a change that would break the active configuration raises
 *              ProductBusinessRuleException and writes nothing; reconfigure() changes the active set.
 *
 * Environment: see example 01.
 */

declare(strict_types=1);

use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\Product\Common\Enum\ProductDeletedStateEnum;
use Maatify\Product\Common\Exception\ProductBusinessRuleException;
use Maatify\Product\Content\Management\Command\CreateOptionContentCommand;
use Maatify\Product\Content\Management\Command\CreateOptionValueContentCommand;
use Maatify\Product\Content\Management\Command\CreateProductContentCommand;
use Maatify\Product\Content\Management\Command\UpdateProductContentCommand;
use Maatify\Product\Factory\ProductFactory;
use Maatify\Product\Option\Enum\OptionStatusEnum;
use Maatify\Product\Option\Management\Command\CreateOptionCommand;
use Maatify\Product\OptionValue\Enum\OptionValueStatusEnum;
use Maatify\Product\OptionValue\Management\Command\CreateOptionValueCommand;
use Maatify\Product\OptionValue\Management\Command\UpdateOptionValueDisplayOrderCommand;
use Maatify\Product\Product\Enum\ProductStatusEnum;
use Maatify\Product\Product\Exception\ProductNotFoundException;
use Maatify\Product\Product\Management\Command\CreateProductCommand;
use Maatify\Product\Product\Management\Command\ReconfigureOptionTarget;
use Maatify\Product\Product\Management\Command\ReconfigureProductCommand;
use Maatify\Product\Product\Management\Command\ReconfigureVariantTarget;
use Maatify\Product\Variant\DTO\VariantDTO;
use Maatify\Product\Variant\Enum\VariantStatusEnum;
use Maatify\Product\Variant\Management\Command\UpdateVariantDisplayOrderCommand;
use Maatify\Product\Variant\Management\Criteria\VariantListCriteriaDTO;
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

$expect = static function (bool $condition, string $claim): void {
    if (!$condition) {
        throw new RuntimeException('Unexpected result: ' . $claim);
    }
};

// Runs a change that the lifecycle contract must reject, and proves it raised ProductBusinessRuleException.
$rejected = static function (callable $change, string $claim): void {
    try {
        $change();
    } catch (ProductBusinessRuleException) {
        echo "rejected: {$claim}\n";

        return;
    }

    throw new RuntimeException('Expected ProductBusinessRuleException: ' . $claim);
};

$pdo = new PDO(
    $setting('PRODUCT_EXAMPLE_DSN'),
    $setting('PRODUCT_EXAMPLE_USER'),
    $setting('PRODUCT_EXAMPLE_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
);
$clock = new SystemClock(new DateTimeZone('UTC'));
$products = ProductFactory::createProductManagementService($pdo, $clock);
$consumerProducts = ProductFactory::createProductConsumerService($pdo, $clock);
$variants = ProductFactory::createVariantManagementService($pdo, $clock);
$options = ProductFactory::createOptionManagementService($pdo, $clock);
$values = ProductFactory::createOptionValueManagementService($pdo, $clock);
$productContent = ProductFactory::createProductContentManagementService($pdo, $clock);
$optionContent = ProductFactory::createOptionContentManagementService($pdo, $clock);
$valueContent = ProductFactory::createOptionValueContentManagementService($pdo, $clock);
$suffix = bin2hex(random_bytes(4));

// 1. Product: activate (needs a name and a valid configuration), then deactivate.
$lamp = $products->create(new CreateProductCommand('LAMP-' . $suffix))->productId;
$lampName = $productContent->create(new CreateProductContentCommand($lamp, null, 'Lamp'))->contentId;
$products->updateStatus($lamp, ProductStatusEnum::ACTIVE);
$expect($consumerProducts->getById($lamp)->status === ProductStatusEnum::ACTIVE, 'an active Product is consumer-visible');
echo "product {$lamp}: active and consumer-visible\n";

$products->updateStatus($lamp, ProductStatusEnum::INACTIVE);
try {
    $consumerProducts->getById($lamp);
    throw new RuntimeException('An inactive Product must not be consumer-visible.');
} catch (ProductNotFoundException) {
    echo "product {$lamp}: inactive, hidden from consumers\n";
}

// Soft deletion: the default management read stops finding the row; the deleted-state filter still does.
$products->softDelete($lamp);
$expect($products->getByIdForManagement($lamp, ProductDeletedStateEnum::DELETED_ONLY)->deletedAt !== null, 'the Product is soft deleted');
try {
    $products->getByIdForManagement($lamp);
    throw new RuntimeException('A soft-deleted Product must be hidden from the default read.');
} catch (ProductNotFoundException) {
    echo "product {$lamp}: soft deleted\n";
}

// A soft-deleted row accepts nothing but restore().
$rejected(static fn() => $products->updateStatus($lamp, ProductStatusEnum::ACTIVE), 'status change of a soft-deleted Product');
$rejected(static fn() => $products->softDelete($lamp), 'second softDelete of a soft-deleted Product');

// restore() keeps the saved status: the Product comes back inactive.
$products->restore($lamp);
$restored = $products->getByIdForManagement($lamp);
$expect($restored->deletedAt === null && $restored->status === ProductStatusEnum::INACTIVE, 'restore keeps the inactive status');
echo "product {$lamp}: restored, still {$restored->status->value}\n";

// Content: update() replaces every field; an inactive owner may lose its last name, and restore() brings it back.
$productContent->update(new UpdateProductContentCommand($lampName, 'Desk lamp', 'LED desk lamp', null));
$renamed = $productContent->getByIdForManagement($lampName);
$expect($renamed->name === 'Desk lamp' && $renamed->shortDescription === 'LED desk lamp', 'update replaces the Content fields');
$productContent->softDelete($lampName);
$expect($productContent->getByIdForManagement($lampName, ProductDeletedStateEnum::DELETED_ONLY)->deletedAt !== null, 'the last name of an inactive Product can be soft deleted');
$rejected(static fn() => $productContent->update(new UpdateProductContentCommand($lampName, 'Lamp', null, null)), 'update of a soft-deleted Content record');
$productContent->restore($lampName);
$expect($productContent->getByIdForManagement($lampName)->name === 'Desk lamp', 'restore brings the Content record back unchanged');
echo "content {$lampName}: updated, soft deleted, and restored\n";

// 2. A configurable Product: color (red, blue, green) and size (m) are prepared, and a material Option
// (cotton, linen) stays outside the active configuration.
$created = $products->create(new CreateProductCommand('SOFA-' . $suffix));
$sofa = $created->productId;
$color = $options->create(new CreateOptionCommand($sofa, 'color'))->optionId;
$size = $options->create(new CreateOptionCommand($sofa, 'size'))->optionId;
$material = $options->create(new CreateOptionCommand($sofa, 'material'))->optionId;
$red = $values->create(new CreateOptionValueCommand($color, 'red'))->optionValueId;
$blue = $values->create(new CreateOptionValueCommand($color, 'blue'))->optionValueId;
$green = $values->create(new CreateOptionValueCommand($color, 'green'))->optionValueId;
$medium = $values->create(new CreateOptionValueCommand($size, 'm'))->optionValueId;
$cotton = $values->create(new CreateOptionValueCommand($material, 'cotton'))->optionValueId;
$linen = $values->create(new CreateOptionValueCommand($material, 'linen'))->optionValueId;
$optionContent->create(new CreateOptionContentCommand($color, null, 'Color'));
$optionContent->create(new CreateOptionContentCommand($size, null, 'Size'));
foreach ([[$red, 'Red'], [$blue, 'Blue'], [$medium, 'Medium']] as [$valueId, $label]) {
    $valueContent->create(new CreateOptionValueContentCommand($valueId, null, $label));
}

$offered = [
    new ReconfigureVariantTarget([new OptionValueSelection($color, $red), new OptionValueSelection($size, $medium)]),
    new ReconfigureVariantTarget([new OptionValueSelection($color, $blue), new OptionValueSelection($size, $medium)]),
];
$result = $products->reconfigure(new ReconfigureProductCommand(
    $sofa,
    $products->getConfigurationRevision($sofa),
    [new ReconfigureOptionTarget($color, [$red, $blue]), new ReconfigureOptionTarget($size, [$medium])],
    $offered,
));
[$redVariant, $blueVariant] = $result->createdVariantIds;
$original = $created->variantId;
$expect($variants->getByIdForManagement($original)->status === VariantStatusEnum::INACTIVE, 'reconfigure deactivates the empty-composition Variant');
echo "configured: red-m {$redVariant}, blue-m {$blueVariant}; original Variant {$original} inactive\n";

// 3. Changes that would break the active configuration are rejected and write nothing.
$rejected(static fn() => $options->updateStatus($color, OptionStatusEnum::INACTIVE), 'deactivating an Option that active Variants select');
$rejected(static fn() => $options->updateStatus($material, OptionStatusEnum::ACTIVE), 'activating an Option that no active Variant covers');
$rejected(static fn() => $values->softDelete($red), 'soft deleting a Value of the active configuration');
$rejected(static fn() => $variants->softDelete($redVariant), 'soft deleting the only Variant that represents red');
$expect($options->getByIdForManagement($color)->status === OptionStatusEnum::ACTIVE, 'the rejected change wrote nothing');

// 4. Outside the active configuration, the standalone status change is free: a Value of the inactive
// material Option is activated and deactivated again, without a label and without reconfiguration.
$values->updateStatus($cotton, OptionValueStatusEnum::ACTIVE);
$expect($values->getByIdForManagement($cotton)->status === OptionValueStatusEnum::ACTIVE, 'a Value of an inactive Option changes freely');
$values->updateStatus($cotton, OptionValueStatusEnum::INACTIVE);
echo "value cotton of the inactive material Option: activated and deactivated freely\n";

// 5. softDelete() and restore() where valid: the inactive material Option, the unused green Value,
// and the inactive original Variant. A restored row keeps its status and goes to the end of its scope.
$options->softDelete($material);
$rejected(static fn() => $values->updateStatus($linen, OptionValueStatusEnum::ACTIVE), 'changing a Value of a soft-deleted Option');
$options->restore($material);
$expect($options->getByIdForManagement($material)->status === OptionStatusEnum::INACTIVE, 'the restored Option stays inactive');
echo "option material: soft deleted and restored, still inactive\n";

$values->softDelete($green);
$rejected(static fn() => $values->updateStatus($green, OptionValueStatusEnum::ACTIVE), 'status change of a soft-deleted Value');
$values->restore($green);
$restoredGreen = $values->getByIdForManagement($green);
$expect($restoredGreen->status === OptionValueStatusEnum::INACTIVE && $restoredGreen->displayOrder === 3, 'the restored Value is inactive at the end of its Option');
echo "value green: soft deleted and restored at position {$restoredGreen->displayOrder}\n";

$variants->softDelete($original);
$variants->restore($original);
$restoredVariant = $variants->getByIdForManagement($original);
$expect($restoredVariant->status === VariantStatusEnum::INACTIVE && $restoredVariant->displayOrder === 4, 'the restored Variant is inactive at the end of its Product');
echo "variant {$original}: soft deleted and restored at position {$restoredVariant->displayOrder}, still inactive\n";

// 6. Ordering: move blue-m to the top of its Product and green to the top of its Option.
$variants->updateDisplayOrder(new UpdateVariantDisplayOrderCommand($blueVariant, 1));
$variantOrder = [];
foreach ($variants->listByProductIdForManagement($sofa) as $variant) {
    $variantOrder[] = $variant->id;
}
$expect($variantOrder[0] === $blueVariant && $variants->getByIdForManagement($blueVariant)->displayOrder === 1, 'blue-m is first');
echo 'variant order: ' . implode(', ', $variantOrder) . "\n";

$values->updateDisplayOrder(new UpdateOptionValueDisplayOrderCommand($green, 1));
$valueOrder = [];
foreach ($values->listByOptionIdForManagement($color) as $value) {
    $valueOrder[] = $value->code;
}
$expect($valueOrder === ['green', 'red', 'blue'], 'green moved to the top of color');
echo 'color value order: ' . implode(', ', $valueOrder) . "\n";

// 7. Variant status: with every Value represented twice, one Variant can be deactivated on its own, but
// not a second one that would leave a Value unrepresented; the Variants are paged for management.
$chair = $products->create(new CreateProductCommand('CHAIR-' . $suffix))->productId;
$finish = $options->create(new CreateOptionCommand($chair, 'finish'))->optionId;
$seat = $options->create(new CreateOptionCommand($chair, 'seat'))->optionId;
$oak = $values->create(new CreateOptionValueCommand($finish, 'oak'))->optionValueId;
$ash = $values->create(new CreateOptionValueCommand($finish, 'ash'))->optionValueId;
$wool = $values->create(new CreateOptionValueCommand($seat, 'wool'))->optionValueId;
$felt = $values->create(new CreateOptionValueCommand($seat, 'felt'))->optionValueId;
$optionContent->create(new CreateOptionContentCommand($finish, null, 'Finish'));
$optionContent->create(new CreateOptionContentCommand($seat, null, 'Seat'));
foreach ([[$oak, 'Oak'], [$ash, 'Ash'], [$wool, 'Wool'], [$felt, 'Felt']] as [$valueId, $label]) {
    $valueContent->create(new CreateOptionValueContentCommand($valueId, null, $label));
}
$compositions = [];
foreach ([$oak, $ash] as $finishValue) {
    foreach ([$wool, $felt] as $seatValue) {
        $compositions[] = new ReconfigureVariantTarget([new OptionValueSelection($finish, $finishValue), new OptionValueSelection($seat, $seatValue)]);
    }
}
[$oakWool, $oakFelt] = $products->reconfigure(new ReconfigureProductCommand(
    $chair,
    $products->getConfigurationRevision($chair),
    [new ReconfigureOptionTarget($finish, [$oak, $ash]), new ReconfigureOptionTarget($seat, [$wool, $felt])],
    $compositions,
))->createdVariantIds;

$variants->updateStatus($oakWool, VariantStatusEnum::INACTIVE);
$expect($variants->getByIdForManagement($oakWool)->status === VariantStatusEnum::INACTIVE, 'oak-wool is deactivated; oak and wool stay represented');
$rejected(static fn() => $variants->updateStatus($oakFelt, VariantStatusEnum::INACTIVE), 'deactivating oak-felt too would leave oak unrepresented');
$variants->updateStatus($oakWool, VariantStatusEnum::ACTIVE);
$expect($variants->getByIdForManagement($oakWool)->status === VariantStatusEnum::ACTIVE, 'oak-wool is active again');
echo "variant {$oakWool}: deactivated and activated again\n";

$variantPage = $variants->paginateByProductIdForManagement($chair, new VariantListCriteriaDTO(status: VariantStatusEnum::ACTIVE), new PageRequest(1, 2));
$expect(count($variantPage->data) === 2 && $variantPage->filtered === 4 && $variantPage->totalPages === 2, 'the active Variants of the chair, page 1');
echo "active chair variants page 1/{$variantPage->totalPages}: " . implode(', ', array_map(static fn(VariantDTO $variant): int => $variant->id, $variantPage->data)) . "\n";

echo "OK: example 08 completed\n";
