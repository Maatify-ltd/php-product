<div align="center">

# Maatify Product

<picture>
  <source media="(prefers-color-scheme: dark)" srcset="https://www.maatify.dev/company-profile/maatify_logo_white.svg">
  <source media="(prefers-color-scheme: light)" srcset="https://www.maatify.dev/company-profile/maatify_logo_dark.svg">
  <img alt="Maatify.dev" src="https://www.maatify.dev/company-profile/maatify_logo_dark.svg">
</picture>

[![Release target: 1.0.0-rc.1](https://img.shields.io/badge/Release%20target-1.0.0--rc.1-blue)](#installation) [![PHP](https://img.shields.io/badge/PHP-%3E%3D8.4.27%20%3C8.5.0%20%7C%7C%20%3E%3D8.5.12%20%3C8.6.0-8892BF)](composer.json) [![License](https://img.shields.io/badge/License-Proprietary-green)](LICENSE) [![PHPStan Level Max](https://img.shields.io/badge/PHPStan-Level%20Max-brightgreen)](https://github.com/Maatify-ltd/php-product/actions/runs/43544696773)

[![Maatify Ecosystem](https://img.shields.io/badge/Maatify-Ecosystem-blueviolet)](https://github.com/Maatify)

[![Usage Guide](https://img.shields.io/badge/Docs-Usage%20Guide-informational)](docs/guides/USAGE_GUIDE.md) [![Examples](https://img.shields.io/badge/Docs-Examples-informational)](examples/) [![Package Reference](https://img.shields.io/badge/Docs-Package%20Reference-informational)](PRODUCT_PACKAGE_REFERENCE.md) [![Changelog](https://img.shields.io/badge/Docs-Changelog-informational)](CHANGELOG.md) [![Security Policy](https://img.shields.io/badge/Docs-Security%20Policy-informational)](SECURITY.md) [![Contributing Guide](https://img.shields.io/badge/Docs-Contributing%20Guide-informational)](https://github.com/Maatify-ltd/php-product/blob/c853504edf9837b7cc987cdd25c2cc0c850c4393/CONTRIBUTING.md)

Framework-agnostic PHP library for managing Products, Variants, and configurable catalogs on MySQL.

</div>

---

## Key Features

- Create Products and Variants atomically. Reconfigure Options and Values with a revision check and keep Variant history.
- Resolve shopper selections to visible Variants and paginate visible Variants, Options, Values, and exact-scope images.
- Manage localized Content, Image Roles, and Image Assignments. The Host owns pricing, inventory, category mapping, and media storage.

## Requirements

- PHP `>=8.4.27 <8.5.0 || >=8.5.12 <8.6.0` with `ext-pdo`, `ext-pdo_mysql`, and `ext-mbstring`
- MySQL `8.0.16` or later (verified on `8.0.16` and `8.0.46`)
- `maatify/exceptions` `^1.0`, `maatify/php-persistence` `1.0.0-rc.1`, `maatify/shared-common` `^1.0`

## Installation

This artifact targets `maatify/php-product:1.0.0-rc.1`. At qualification, this release candidate is unpublished. Once this exact version is available on Packagist, install it in the consumer project:

```bash
composer require maatify/php-product:1.0.0-rc.1 maatify/php-persistence:1.0.0-rc.1
```

Keep `minimum-stability: stable` and permit these exact pre-release versions in the consumer root. See [License](#license) for the free license required before use.

For checkout testing before publication, configure a Composer path repository:

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

Start with `ProductFactory::forHost()` to configure the Host PDO and clock once. The static factory methods also build individual services.

| Services | Capabilities |
|---|---|
| Products and Variants | Create and reconfigure Products, manage lifecycle, look up Products by code and Variants by SKU or barcode in Management, and resolve visible Variants by composition. |
| Options and Option Values | Manage catalog choices and read or paginate the choices visible to consumers. |
| Content | Manage Product, Option, and Value labels and read content for an exact language. |
| Image Roles and Assignments | Manage roles, order and defaults, and list or paginate consumer images within one exact scope. |

Consumer reads apply visibility rules. Management services expose lifecycle operations and explicit filters. See the [Package Reference](PRODUCT_PACKAGE_REFERENCE.md#implemented-public-api) for method signatures, behavior, and exceptions.

The Host controls its transactions. The package uses savepoints for mutations inside a Host transaction. Read `getConfigurationRevision()` before loading the editor data and send that revision as `expectedRevision` when saving. The Host owns access control and request limits.

## Documentation

- [Usage Guide](docs/guides/USAGE_GUIDE.md) and [runnable examples](examples/)
- [Package Reference](PRODUCT_PACKAGE_REFERENCE.md) and [MySQL schema](schema/README.md)
- [Changelog](CHANGELOG.md) and [Security Policy](SECURITY.md)

## Quality Status

Final Gate covers PHPStan at level max, MySQL integration tests, and the Consumer Verification Harness. See [CI and local verification](docs/verification/CI_GATES.md) for the full matrix and commands.

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
