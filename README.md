<div align="center">

# Maatify Product

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="https://www.maatify.dev/company-profile/maatify_logo_white.svg">
  <source media="(prefers-color-scheme: light)" srcset="https://www.maatify.dev/company-profile/maatify_logo_dark.svg">
  <img alt="Maatify.dev" src="https://www.maatify.dev/company-profile/maatify_logo_dark.svg">
</picture>

[![Status](https://img.shields.io/badge/Status-Development-blue)](README.md)
[![PHP](https://img.shields.io/badge/PHP-8.4.27%2B%20%7C%208.5.12%2B-8892BF)](composer.json)
[![License](https://img.shields.io/badge/License-Proprietary-green)](LICENSE)
[![Maatify Ecosystem](https://img.shields.io/badge/Maatify-Ecosystem-blueviolet)](https://github.com/Maatify)

[![Usage Guide](https://img.shields.io/badge/Docs-Usage%20Guide-informational)](docs/guides/USAGE_GUIDE.md)
[![Examples](https://img.shields.io/badge/Docs-Examples-informational)](examples/)
[![Package Reference](https://img.shields.io/badge/Docs-Package%20Reference-informational)](PRODUCT_PACKAGE_REFERENCE.md)
[![Changelog](https://img.shields.io/badge/Docs-Changelog-informational)](CHANGELOG.md)
[![Security Policy](https://img.shields.io/badge/Docs-Security%20Policy-informational)](SECURITY.md)
[![Contributing Guide](https://img.shields.io/badge/Docs-Contributing%20Guide-informational)](https://github.com/Maatify-ltd/php-product/blob/c853504edf9837b7cc987cdd25c2cc0c850c4393/CONTRIBUTING.md)

Framework-agnostic PHP library for Product and Variant identity on MySQL: a stable Product code, Variant identities with immutable compositions, atomic reconfiguration, composition resolution, Image Roles and Assignments, and a MySQL schema that enforces identity, ownership, status, and exact-scope uniqueness while the package enforces the configuration invariants under a Product lock.

</div>

---

## Key Features

- **Product with its Variant, atomically.** `create()` writes an inactive Product and its single active empty-composition Variant together: both rows exist or neither does, whether the package or the Host owns the transaction.
- **Stable identity.** A Product `code` is validated, immutable, unique case-insensitively, and stays reserved after soft deletion; the same holds for Option and Option Value codes, Image Role keys, Variant compositions, and Image Assignment identities.
- **Atomic reconfiguration.** `reconfigure()` replaces the active Option, Value, and Variant set of a Product in one Product-local operation over a complete target state: it creates new Variant identities, deactivates the replaced ones without deleting history, reuses a historical identity only when the command names it, and rejects the whole operation when an invariant would break. Read `getConfigurationRevision()` before loading the editor data and pass that value as `expectedRevision` when saving.
- **Composition resolution.** `resolveByComposition()` turns a shopper's typed `(optionId, optionValueId)` choices into the one consumer-visible Variant, in any pair order, and fails closed with a typed exception.
- **One lifecycle contract.** Soft delete and restore for Products, Variants, Options, Option Values, Content, Image Roles, and Image Assignments, and a status change for Products, Variants, Options, Option Values, and Image Roles (an Option or Value status change keeps the whole configuration valid, and `reconfigure()` changes the active set atomically; Content and Image Assignments have no status): a soft-deleted row accepts nothing but restore, a soft-deleted owner blocks its children, restore keeps the saved status and revalidates the invariants, and inactivity alone blocks no management operation.
- **Exact Content.** Product names and descriptions and Option and Option Value labels are Content records per language, with `null` as the single unlocalized record; reads select an exact language, the unlocalized record, or (for management only) all languages, and never fall back.
- **Separate Management and Consumer contracts.** Every capability has a Management service (creation, lifecycle, and management reads) and, when it has a consumer read, a Consumer service that returns only consumer-visible records and writes nothing. `getById()` on a Consumer service is the consumer read (active, not deleted, structurally valid); `getByIdForManagement()` on a Management service filters only by soft-deletion state.
- **Host-owned boundaries.** The Host supplies the PDO connection and the Clock. The package performs no Pricing, Inventory, Supplier, Category, or media storage work.
- **Database-enforced rules.** `schema/product.sql` defines ten tables with foreign keys, case-sensitive status checks, and exact-scope uniqueness, verified against real MySQL, including concurrent writers. The configuration invariants that need transactions and locking are enforced by the package (see [`schema/README.md`](schema/README.md), *What the schema does not prove*).

The package is in development and no version is released. Every capability of the approved contract is implemented; the [Package Reference](PRODUCT_PACKAGE_REFERENCE.md) is the complete contract.

## Requirements

- PHP `>=8.4.27 <8.5.0 || >=8.5.12 <8.6.0` with `ext-pdo`, `ext-pdo_mysql`, and `ext-mbstring`
- MySQL `8.0.16` or later (verified on `8.0.16` and `8.0.46`)
- `maatify/exceptions` `^1.0`, `maatify/php-persistence` `1.0.0-rc.1`, `maatify/shared-common` `^1.0`

## Installation

No version of this package is published to a Composer distribution source yet, so there is no registry install command. Until a release exists, consume a checkout of this repository through a Composer path repository:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "/path/to/php-product",
            "options": { "symlink": false }
        }
    ],
    "require": {
        "maatify/php-product": "*@dev",
        "maatify/php-persistence": ">=1.0.0-rc.1 <=1.0.0-rc.1"
    },
    "minimum-stability": "stable",
    "prefer-stable": true
}
```

This is a trial installation. See [License](#license) to request written authorization before use. The consumer root explicitly permits the Product development package and the exact Persistence release candidate. See the [Persistence RC trial plan](docs/verification/CI_GATES.md#persistence-rc-trial-plan) for the verified version and the release hold.

Then install the schema once into your database:

```bash
mysql your_database < schema/product.sql
```

## Quick Usage

```php
use Maatify\Product\Factory\ProductFactory;
use Maatify\Product\Product\Management\Command\CreateProductCommand;
use Maatify\SharedCommon\Infrastructure\SystemClock;

// $pdo: your PDO connection with PDO::ERRMODE_EXCEPTION, on a database that has schema/product.sql.
$clock = new SystemClock(new DateTimeZone('UTC'));
$products = ProductFactory::createProductManagementService($pdo, $clock);
$catalog = ProductFactory::createProductConsumerService($pdo, $clock);

$created = $products->create(new CreateProductCommand('SIMPLE-1')); // Product + its Variant
$product = $products->getByIdForManagement($created->productId);    // inactive until activated
// $catalog->getById($created->productId) raises ProductNotFoundException until the Product is active.
```

Runnable versions are in [`examples/`](examples/); the walkthroughs are in the [Usage Guide](docs/guides/USAGE_GUIDE.md).

## Public Runtime API

The [Package Reference](PRODUCT_PACKAGE_REFERENCE.md#implemented-public-api) gives the complete Public Runtime API inventory and the current contract for this unreleased package.

| Type | Role |
|---|---|
| `Factory\ProductFactory` | The only construction entry point: a `create{X}ManagementService()` for every capability (`Product`, `Variant`, `Option`, `OptionValue`, `ImageRole`, `ImageAssignment`, `ProductContent`, `OptionContent`, `OptionValueContent`) and a `create{X}ConsumerService()` for every capability except `ImageRole`, which has no consumer read. Use `forHost()` to configure the Host PDO and Clock once for both sets of builders. |
| `Product\Management\Service\ProductManagementServiceInterface` | `create()`, `getByIdForManagement()`, `getByCodeForManagement()`, `listForManagement()`, `paginateForManagement()`, `updateStatus()`, `softDelete()`, `restore()`, `getConfigurationRevision()`, `reconfigure()`. |
| `Product\Consumer\Service\ProductConsumerServiceInterface` | `getById()`, `getByCode()`. |
| `Variant\Management\Service\VariantManagementServiceInterface` | `getByIdForManagement()`, `getBySkuForManagement()`, `getByBarcodeForManagement()`, `listByProductIdForManagement()`, `paginateByProductIdForManagement()`, `updateStatus()`, `updateIdentifiers()`, `updateDisplayOrder()`, `softDelete()`, `restore()`. |
| `Variant\Consumer\Service\VariantConsumerServiceInterface` | `getById()`, `listByProductId()`, `resolveByComposition()`. |
| `Option\Management\Service\OptionManagementServiceInterface`, `OptionValue\Management\Service\OptionValueManagementServiceInterface` | `create()`, `updateStatus()`, `updateDisplayOrder()`, `softDelete()`, `restore()`, `getByIdForManagement()`, and the owner-scoped `list…ForManagement()` and `paginate…ForManagement()`. |
| `Option\Consumer\Service\OptionConsumerServiceInterface`, `OptionValue\Consumer\Service\OptionValueConsumerServiceInterface` | `listVisibleByProductId()` / `listVisibleByOptionId()`. |
| `ImageRole\Management\Service\ImageRoleManagementServiceInterface` | `create()`, `updateStatus()`, `softDelete()`, `restore()`, `getByIdForManagement()`, `getByKeyForManagement()`, `listForManagement()`, `paginateForManagement()`. |
| `ImageAssignment\Management\Service\ImageAssignmentManagementServiceInterface` | `create()`, `setDefault()`, `clearDefault()`, `updateDisplayOrder()`, `softDelete()`, `restore()`, `restoreAsDefault()`, `getByIdForManagement()`, `listForManagement()`, `paginateForManagement()`. |
| `ImageAssignment\Consumer\Service\ImageAssignmentConsumerServiceInterface` | `listVisibleByExactScope()`. |
| `Content\Management\Service\ProductContentManagementServiceInterface`, `Content\Management\Service\OptionContentManagementServiceInterface`, `Content\Management\Service\OptionValueContentManagementServiceInterface` | `create()`, `update()`, `softDelete()`, `restore()`, `getByIdForManagement()`, `listForManagement()`, `paginateForManagement()`. |
| `Content\Consumer\Service\ProductContentConsumerServiceInterface`, `Content\Consumer\Service\OptionContentConsumerServiceInterface`, `Content\Consumer\Service\OptionValueContentConsumerServiceInterface` | `listVisible()`. |
| Commands | `CreateProductCommand`, `ReconfigureProductCommand` (with `ReconfigureOptionTarget`, `ReconfigureVariantTarget`), `CreateOptionCommand`, `CreateOptionValueCommand`, `CreateImageRoleCommand`, `CreateImageAssignmentCommand`, `UpdateVariantIdentifiersCommand` (with `Variant\ValueObject\VariantIdentifierChange`), the `Update*DisplayOrderCommand` of Variants, Options, Option Values, and Image Assignments, the `Create*ContentCommand` and `Update*ContentCommand` of each Content owner; `Content\ValueObject\ContentLanguageFilter` and `Content\ValueObject\ExactContentLanguage` for language selection; `Variant\ValueObject\OptionValueSelection` and `Variant\Consumer\Criteria\VariantCompositionCriteriaDTO` for compositions. |
| DTOs and enums | Typed results (`CreatedProductDTO`, `ProductDTO`, `ProductReconfigurationResultDTO`, `VariantDTO` and its collection and criteria types, the list criteria and collection types of every family, the exact image-assignment selectors (`ImageAssignmentTargetFilter`, `ImageAssignmentRoleFilter`, `ImageAssignmentLanguageFilter`, `ImageAssignmentPlatformFilter`), `OptionDTO`, `OptionValueDTO`, `ImageRoleDTO`, `ImageAssignmentDTO`, the Content DTOs with their collection and criteria types, and the `Created*DTO` identities), the status enums of each family, and `Common\Enum\ProductDeletedStateEnum`. |
| Exceptions | Named failures for not-found, conflict, validation (`ProductInvalidArgumentException`), business-rule (`ProductBusinessRuleException`), and storage-contract (`ProductPersistenceException`) conditions; all implement `Exception\ProductExceptionInterface`. |

Everything under `Maatify\Product\` that the Package Reference does not list is internal.

## Critical Runtime Behavior

- **Transactions.** The package never commits or rolls back a transaction it did not start. Inside a Host transaction on the same connection, every mutation runs in an operation-local savepoint, so a failed call leaves none of its writes behind and the Host can still commit its other work.
- **Locking.** Every mutation that can affect a Product's graph locks the Product row first, then its Options, Option Values, and Variants by ascending identity, then the Image Role, Image Assignment, or Content rows it touches; it reads the state it validates only after taking the Product lock.
- **Concurrency.** Concurrent creates of one code yield exactly one Product; concurrent reconfigurations of one Product serialize into one winner and typed conflicts; concurrent default replacements within one exact scope leave exactly one default, and concurrent restores as default into one exact scope yield one winner and a typed conflict. All proven against real MySQL with separate processes.
- **Timestamps.** Values come from the Host Clock and are returned as `DateTimeImmutable` in the Host Clock's timezone, without normalization.

## Exception and Error Propagation

A stale configuration save raises `ProductConfigurationRevisionConflictException`. Reload the configuration and reconcile the edits before retrying.

A taken identity becomes the family's conflict exception (`ProductCodeAlreadyExistsException`, `OptionCodeAlreadyExistsException`, `OptionValueCodeAlreadyExistsException`, `ImageRoleKeyAlreadyExistsException`, `ImageAssignmentAlreadyExistsException`, `VariantSkuAlreadyExistsException`, `VariantBarcodeAlreadyExistsException`, the `*ContentAlreadyExistsException` of each Content owner, `VariantCompositionConflictException`), and a restore-as-default into a scope that already has a live default becomes `ImageAssignmentDefaultConflictException`; a missing or not-visible identity becomes its not-found exception; invalid input, including a structurally invalid composition selection, becomes `ProductInvalidArgumentException`; a soft-deleted row or owner, or an operation that would break a Product invariant, becomes `ProductBusinessRuleException`; data the schema contract does not allow becomes `ProductPersistenceException`. Every other `PDOException` or `Throwable` propagates unchanged. Details: [Failure contract](PRODUCT_PACKAGE_REFERENCE.md#behavior-and-guarantees).

## Schema

[`schema/product.sql`](schema/product.sql) creates ten tables with the `maa_product_` prefix and defines no triggers, routines, or seed data. See [`schema/README.md`](schema/README.md).

## Examples

- [`01-create-and-read-product.php`](examples/01-create-and-read-product.php): create, read, consumer visibility, duplicate handling.
- [`02-host-transaction.php`](examples/02-host-transaction.php): composing `create()` inside a Host-owned transaction.
- [`03-read-variants.php`](examples/03-read-variants.php): management and consumer Variant reads, and the SKU and barcode update.
- [`04-reconfigure-and-resolve.php`](examples/04-reconfigure-and-resolve.php): prepare Options and Values, name and label them with Content commands, make the Product configurable, resolve a shopper's choices, read exact Content, and go back to simple.
- [`05-consumer-presentation.php`](examples/05-consumer-presentation.php): the storefront reads of selectable Options and Values, exact Content, and exact-scope images.
- [`06-management-lists.php`](examples/06-management-lists.php): management lists with status and deleted-state filters, the Product code search, pagination, and business-key reads.
- [`07-image-roles-and-assignments.php`](examples/07-image-roles-and-assignments.php): Image Roles, Product- and Variant-level assignments, `setDefault()`, `clearDefault()`, restore and `restoreAsDefault()`, ordering, the Role and assignment lists and pages, and the exact management and consumer reads.
- [`08-lifecycle-and-status.php`](examples/08-lifecycle-and-status.php): activation, deactivation, soft delete, and restore; Option and Option Value `updateStatus()` against the configuration; and Variant and Option Value ordering.

## Documentation

- [Usage Guide](docs/guides/USAGE_GUIDE.md)
- [Package Reference](PRODUCT_PACKAGE_REFERENCE.md)
- [Changelog](CHANGELOG.md)
- [Security policy](SECURITY.md)
- [CI gates and local verification](docs/verification/CI_GATES.md)

## Quality Status

Continuous integration runs Composer validation, PHP syntax, PHPStan at level max with no baseline, PER-CS 3.1 style, Unit tests, Integration tests on real MySQL (`8.0.16` and `8.0.46`, PHP 8.4 and 8.5), lowest-dependency resolution, a Composer security audit, a Consumer Verification Harness that also smoke-executes every example, workflow lint, and whitespace verification. The stable `Final Gate` job aggregates them and is a required check on `main`. No release, stability, or support claim is made.

## Development and Testing

Every gate has a local command; see [CI gates and local verification](docs/verification/CI_GATES.md). Typical commands:

```bash
composer update --no-interaction --prefer-dist
composer analyse
composer format:check
composer test:unit
composer test:integration   # needs Docker with the Compose plugin
composer verify:consumer    # needs Docker with the Compose plugin
```

## License

This package is proprietary. Request a free license from
[support@maatify.dev](mailto:support@maatify.dev) before using it. Include your
organization name, if applicable, and an email address for the person who should
receive security advisories and critical update notices. Maatify Ltd. uses those
details to administer licenses and send those notices. See [LICENSE](LICENSE)
for the terms.

## Author

Engineered by **Mohamed Abdulalim** ([@megyptm](https://github.com/megyptm))<br>
Backend Lead & Technical Architect<br>
[https://www.maatify.dev](https://www.maatify.dev)

---

<div align="center">

[Built with ❤️ by Maatify.dev — Unified Ecosystem for Modern PHP Libraries](https://www.maatify.dev)

</div>
