<?php

/**
 * Example 7: Image Roles and exact-scope Image Assignments with their single default.
 *
 * Input -> Public Call -> Result -> Boundary:
 *  - Input:    a Role key, Host-owned Media Asset identities, and exact (role, language, platform) scopes
 *              at the Product level and at the Variant level.
 *  - Calls:    ImageRoleManagementServiceInterface::create(), getByIdForManagement(), getByKeyForManagement(),
 *              listForManagement(), paginateForManagement(), updateStatus(), softDelete(), restore(),
 *              ImageAssignmentManagementServiceInterface::create(), setDefault(), clearDefault(), softDelete(),
 *              restore(), restoreAsDefault(), updateDisplayOrder(), getByIdForManagement(), listForManagement(),
 *              paginateForManagement(),
 *              ImageAssignmentConsumerServiceInterface::listVisibleByExactScope().
 *  - Result:   CreatedImageRoleDTO and CreatedImageAssignmentDTO identities, ImageRoleDTO and
 *              ImageAssignmentDTO snapshots, and ImageAssignmentCollectionDTO lists of one exact scope.
 *  - Boundary: a scope is exact (`NULL` is a value, never a wildcard) and holds at most one default;
 *              setDefault() replaces it, clearDefault() and softDelete() promote nothing, restore() never
 *              restores the marker, and restoreAsDefault() never replaces a live default. The package never
 *              resolves a Media Asset and the consumer read never chooses a default. A Role status change,
 *              soft delete, or restore changes no assignment that uses the Role.
 *
 * Environment: see example 01.
 */

declare(strict_types=1);

use Maatify\PhpPersistence\Pagination\Criteria\PageRequest;
use Maatify\Product\Common\Enum\ProductDeletedStateEnum;
use Maatify\Product\Content\Management\Command\CreateProductContentCommand;
use Maatify\Product\Factory\ProductFactory;
use Maatify\Product\ImageAssignment\Consumer\Criteria\ImageAssignmentExactScopeCriteriaDTO;
use Maatify\Product\ImageAssignment\DTO\ImageAssignmentDTO;
use Maatify\Product\ImageAssignment\Exception\ImageAssignmentDefaultConflictException;
use Maatify\Product\ImageAssignment\Management\Command\CreateImageAssignmentCommand;
use Maatify\Product\ImageAssignment\Management\Command\UpdateImageAssignmentDisplayOrderCommand;
use Maatify\Product\ImageAssignment\Management\Criteria\ImageAssignmentListCriteriaDTO;
use Maatify\Product\ImageAssignment\ValueObject\ImageAssignmentLanguageFilter;
use Maatify\Product\ImageAssignment\ValueObject\ImageAssignmentPlatformFilter;
use Maatify\Product\ImageAssignment\ValueObject\ImageAssignmentRoleFilter;
use Maatify\Product\ImageAssignment\ValueObject\ImageAssignmentTargetFilter;
use Maatify\Product\ImageRole\DTO\ImageRoleDTO;
use Maatify\Product\ImageRole\Enum\ImageRoleStatusEnum;
use Maatify\Product\ImageRole\Management\Command\CreateImageRoleCommand;
use Maatify\Product\ImageRole\Management\Criteria\ImageRoleListCriteriaDTO;
use Maatify\Product\Product\Enum\ProductStatusEnum;
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

$expect = static function (bool $condition, string $claim): void {
    if (!$condition) {
        throw new RuntimeException('Unexpected result: ' . $claim);
    }
};

$pdo = new PDO(
    $setting('PRODUCT_EXAMPLE_DSN'),
    $setting('PRODUCT_EXAMPLE_USER'),
    $setting('PRODUCT_EXAMPLE_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
);
$clock = new SystemClock(new DateTimeZone('UTC'));
$products = ProductFactory::createProductManagementService($pdo, $clock);
$productContent = ProductFactory::createProductContentManagementService($pdo, $clock);
$roles = ProductFactory::createImageRoleManagementService($pdo, $clock);
$images = ProductFactory::createImageAssignmentManagementService($pdo, $clock);
$consumerImages = ProductFactory::createImageAssignmentConsumerService($pdo, $clock);

// An active simple Product: its Variant is the original empty-composition Variant.
$suffix = bin2hex(random_bytes(4));
$created = $products->create(new CreateProductCommand('IMAGES-' . $suffix));
$productId = $created->productId;
$variantId = $created->variantId;
$productContent->create(new CreateProductContentCommand($productId, null, 'Poster'));
$products->updateStatus($productId, ProductStatusEnum::ACTIVE);

// Image Roles: a Product-owned namespace, created active, read by identity or by key.
$roleKey = 'gallery-' . $suffix;
$roleId = $roles->create(new CreateImageRoleCommand($roleKey))->roleId;
$role = $roles->getByIdForManagement($roleId);
$expect($role->roleKey === $roleKey && $role->status === ImageRoleStatusEnum::ACTIVE, 'a new Role is active');
$expect($roles->getByKeyForManagement(strtoupper($roleKey))->id === $roleId, 'the key compares under the collation');
echo "role {$role->roleKey} ({$role->status->value}) is role {$roleId}\n";

// Assignments: two in the Product-level scope (gallery, en, web), one at the Variant level, one with no role.
$first = $images->create(new CreateImageAssignmentCommand($productId, null, 2001, $roleId, 'en', 'web'))->assignmentId;
$second = $images->create(new CreateImageAssignmentCommand($productId, null, 2002, $roleId, 'en', 'web'))->assignmentId;
$variantImage = $images->create(new CreateImageAssignmentCommand($productId, $variantId, 2003, $roleId))->assignmentId;
$plain = $images->create(new CreateImageAssignmentCommand($productId, null, '2004'))->assignmentId;
$expect(!$images->getByIdForManagement($first)->isDefault, 'a new assignment is not the default');

$isDefault = static fn(int $id): bool => $images->getByIdForManagement($id)->isDefault;

// One default per exact scope: setDefault() on another assignment of the scope replaces it.
$images->setDefault($first);
$images->setDefault($second);
$expect($isDefault($second) && !$isDefault($first), 'setDefault replaces the default of the scope');
echo "default of (gallery, en, web): {$second}, replacing {$first}\n";

// clearDefault() removes the marker and promotes nothing.
$images->clearDefault($second);
$expect(!$isDefault($first) && !$isDefault($second), 'clearDefault promotes nothing');
echo "after clearDefault: no default\n";

// softDelete() clears the marker; restore() brings the row back as a non-default at the end of its scope.
$images->setDefault($first);
$images->softDelete($first);
$images->restore($first);
$expect(!$isDefault($first), 'restore never restores the default marker');
echo "after softDelete and restore: {$first} is not the default\n";

// restoreAsDefault() restores and marks in one update when the scope has no live default ...
$images->softDelete($second);
$images->restoreAsDefault($second);
$expect($isDefault($second), 'restoreAsDefault marks the restored row');
echo "restoreAsDefault: {$second} is the default again\n";

// ... and never replaces a live default: the row stays deleted and the default is unchanged.
$images->softDelete($first);
try {
    $images->restoreAsDefault($first);
    throw new RuntimeException('restoreAsDefault must not replace a live default.');
} catch (ImageAssignmentDefaultConflictException) {
    $expect($isDefault($second), 'the live default is unchanged');
    echo "restoreAsDefault over a live default: conflict\n";
}
$images->restore($first);

// Ordering within the exact scope: move the first image to position 1.
$images->updateDisplayOrder(new UpdateImageAssignmentDisplayOrderCommand($first, 1));
$expect($images->getByIdForManagement($first)->displayOrder === 1, 'the moved row is at position 1');

$ids = static fn(ImageAssignmentDTO ...$items): string => implode(', ', array_map(static fn(ImageAssignmentDTO $item): string => (string) $item->id, $items));

// Management lists: exact selectors per dimension; an omitted selector applies no condition.
$scope = $images->listForManagement(new ImageAssignmentListCriteriaDTO(
    $productId,
    ImageAssignmentTargetFilter::productLevel(),
    ImageAssignmentRoleFilter::forRole($roleId),
    ImageAssignmentLanguageFilter::exact('EN'),
    ImageAssignmentPlatformFilter::exact('web'),
));
$expect($ids(...$scope->items()) === "{$first}, {$second}", 'the exact scope lists both images in display order');
echo 'scope (product, gallery, en, web): ' . $ids(...$scope->items()) . "\n";

$variantLevel = $images->listForManagement(new ImageAssignmentListCriteriaDTO($productId, ImageAssignmentTargetFilter::variant($variantId)));
$expect($ids(...$variantLevel->items()) === (string) $variantImage, 'the Variant target lists only the Variant image');
echo 'variant level: ' . $ids(...$variantLevel->items()) . "\n";

$noRole = $images->listForManagement(new ImageAssignmentListCriteriaDTO($productId, role: ImageAssignmentRoleFilter::exactNull()));
$expect($ids(...$noRole->items()) === (string) $plain, 'exactNull() matches only a NULL role');
echo 'no role: ' . $ids(...$noRole->items()) . "\n";

$noLanguage = $images->listForManagement(new ImageAssignmentListCriteriaDTO(
    $productId,
    ImageAssignmentTargetFilter::productLevel(),
    language: ImageAssignmentLanguageFilter::exactNull(),
    platform: ImageAssignmentPlatformFilter::exactNull(),
));
$expect($ids(...$noLanguage->items()) === (string) $plain, 'a NULL language and platform never match a value');
echo 'product level, no language, no platform: ' . $ids(...$noLanguage->items()) . "\n";

$expect(count($images->listForManagement(new ImageAssignmentListCriteriaDTO($productId))) === 4, 'all four assignments');
$imagePage = $images->paginateForManagement(new ImageAssignmentListCriteriaDTO($productId), new PageRequest(1, 3));
$expect(count($imagePage->data) === 3 && $imagePage->total === 4 && $imagePage->totalPages === 2, 'the assignment page 1');
echo "assignments page 1/{$imagePage->totalPages}: " . count($imagePage->data) . " of {$imagePage->total}\n";

// The Role registry is listed and paged for management, with a status filter.
$activeRoles = $roles->listForManagement(new ImageRoleListCriteriaDTO(status: ImageRoleStatusEnum::ACTIVE));
$expect(in_array($roleId, array_map(static fn(ImageRoleDTO $item): int => $item->id, $activeRoles->items()), true), 'the active Role is listed');
$rolePage = $roles->paginateForManagement(new ImageRoleListCriteriaDTO(), new PageRequest(1, 20, 'role_key'));
$expect($rolePage->total >= 1, 'the Role page counts the registry');
echo "roles: {$rolePage->total} in the registry\n";

// Consumer read: one exact scope, display order, `isDefault` returned as stored, no fallback between levels.
$visible = $consumerImages->listVisibleByExactScope(new ImageAssignmentExactScopeCriteriaDTO($productId, null, $roleId, 'en', 'web'));
$expect($ids(...$visible->items()) === "{$first}, {$second}" && !$visible->items()[0]->isDefault && $visible->items()[1]->isDefault, 'consumer scope');
echo 'consumer (product, gallery, en, web): ' . $ids(...$visible->items()) . ", default {$second}\n";

$variantVisible = $consumerImages->listVisibleByExactScope(new ImageAssignmentExactScopeCriteriaDTO($productId, $variantId, $roleId, null, null));
$otherLanguage = $consumerImages->listVisibleByExactScope(new ImageAssignmentExactScopeCriteriaDTO($productId, null, $roleId, 'ar', 'web'));
$expect($ids(...$variantVisible->items()) === (string) $variantImage && $otherLanguage->isEmpty(), 'consumer reads are exact');
echo "consumer (product, gallery, ar, web): none, no language fallback\n";

// Role lifecycle: deactivate, soft-delete, and restore the Role; the assignments that use it are unchanged.
$roles->updateStatus($roleId, ImageRoleStatusEnum::INACTIVE);
$expect($roles->getByIdForManagement($roleId)->status === ImageRoleStatusEnum::INACTIVE, 'the Role is inactive');
$roles->softDelete($roleId);
$expect($roles->getByIdForManagement($roleId, ProductDeletedStateEnum::DELETED_ONLY)->deletedAt !== null, 'the Role is soft deleted');
$roles->restore($roleId);
$restoredRole = $roles->getByIdForManagement($roleId);
$expect($restoredRole->deletedAt === null && $restoredRole->status === ImageRoleStatusEnum::INACTIVE, 'restore keeps the saved status');
$kept = $images->getByIdForManagement($second);
$expect($kept->roleId === $roleId && $kept->isDefault && $kept->deletedAt === null, 'the assignments of the Role are unchanged');
echo "role {$roleKey}: deactivated, soft deleted, and restored, still inactive; its default assignment {$second} unchanged\n";

echo "OK: example 07 completed\n";
