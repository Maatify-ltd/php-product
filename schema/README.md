# Product schema

This directory holds the package-owned SQL persistence contract for
`maatify/php-product`. The architecture contract is
[`PRODUCT_V1_ARCHITECTURE_CANDIDATE.md`](../docs/architecture/PRODUCT_V1_ARCHITECTURE_CANDIDATE.md)
(§9, §11, §17), approved under
[`DEC-007`](../docs/decisions/DEC-007-product-v1-implementation-start-authorization.md).
The root [Package Reference](../PRODUCT_PACKAGE_REFERENCE.md) owns the stable
public package contract; this file only indexes and explains the SQL asset.

## Files

| File | Purpose |
|---|---|
| [`product.sql`](product.sql) | Creates exactly the ten Product tables. It is the only install asset; there are no migrations. |

## Requirements

- MySQL `8.0.16` or later. The minimum is part of the storage contract because
  the schema relies on enforced `CHECK` constraints.
- InnoDB, `utf8mb4`, `utf8mb4_unicode_ci`.
- Direct PDO access is the intended runtime. Driver availability alone does not
  establish database support.

## Install

Apply the file to an empty database, using a credential that may create tables:

```bash
mysql --user=<user> --password --database=<empty_database> < schema/product.sql
```

The script creates tables only. It defines no triggers, routines, seed data, or
default Product Image Roles, and it never touches tables it does not own. Install
it once per deployed set of Product tables: V1 supports one Product identity
namespace per table set, so there is no `catalog_id`, tenant, or store column.

Identity stability (`id`, `code`, `role_key`, the composition fingerprint, and the identity dimensions of Content and Image Assignments) is deliberately not enforced by triggers: the architecture (§4.1, §11.1) makes it an application contract, and the package has no write path that changes an identity column. The database therefore does not stop an `UPDATE` of an identity column issued outside the package; such writes are the Host's responsibility. `tests/Unit/IdentityStabilityTest.php` and the schema test that asserts no trigger or routine exists keep both facts true.

## Tables

Creation order follows foreign-key dependency.

| Table | Owns |
|---|---|
| `maa_product_products` | Product identity (`code`) and lifecycle |
| `maa_product_product_contents` | Product name and descriptions, nullable language |
| `maa_product_options` | Variant-defining Options |
| `maa_product_option_contents` | Option labels, nullable language |
| `maa_product_option_values` | Option Values |
| `maa_product_option_value_contents` | Option Value labels, nullable language |
| `maa_product_variants` | Sellable-unit identity, composition fingerprint, optional SKU and barcode |
| `maa_product_variant_option_values` | Immutable Variant composition pairs |
| `maa_product_image_roles` | Product-owned Image Role registry |
| `maa_product_image_assignments` | Product- and Variant-level links to external Media Asset identities |

## Database-enforced rules

- **Relationships.** Every foreign key is internal, `ON DELETE RESTRICT` and
  `ON UPDATE RESTRICT`; there is no cascade. Nothing references Category,
  Pricing, Inventory, Supplier, Order, Customer, Host, or Media Storage tables.
- **Ownership.** Composite foreign keys, backed by the candidate keys
  `UNIQUE (id, product_id)` on Options and Variants and `UNIQUE (id, option_id)`
  on Option Values, make it impossible to compose a Variant from an Option or
  Value of another Product, or to pair a Value with the wrong Option. An image
  assignment always has an independent `product_id` foreign key; a non-NULL
  `variant_id` must additionally belong to that same Product.
- **Status.** `status` is exactly lowercase `active` or `inactive`, enforced by a
  `utf8mb4_bin` column and a binary `CHECK`. It is independent of `deleted_at`.
  Defaults: Product, Option, and Option Value are `inactive`; Image Role is
  `active`; Variant has no default.
- **Identity across soft deletion.** No identity unique key includes
  `deleted_at`: Product, Option, Option Value, Image Role, Content, composition
  fingerprint, and image assignment identities stay reserved after soft deletion
  and are restored rather than re-inserted. A Variant keeps its current SKU and
  barcode reserved until the value is cleared or replaced.
- **Nullable scope.** `NULL` is an exact scope, never a wildcard or fallback.
  Content and image-assignment identities use generated NULL-safe columns (`NULL` maps to
  `''` or `0`), and `CHECK` constraints make those sentinels impossible as real
  values.
- **Media.** `media_asset_id` is `BIGINT UNSIGNED` with `CHECK (> 0)` and no
  foreign key. At most one active default exists per exact target and
  role/language/platform scope; a soft-deleted row can never be default, enforced
  by `CHECK (deleted_at IS NULL OR is_default = 0)`.
- **Text.** Codes, role keys, Content names, language codes, platforms, SKU, and
  barcode reject empty and whitespace-only values (spaces, tabs, newlines, and other
  characters matching `[[:space:]]`) through `REGEXP '[^[:space:]]'`, and enforce their
  length limits.
  Comparison follows `utf8mb4_unicode_ci`, so it is case-insensitive.
- **Ordering.** `display_order` exists only on Options, Option Values, Variants,
  and image assignments, is positive, has no database default, and is assigned by
  the application through `maatify/php-persistence`. `Product` has none (`DEC-006`).
- **Timestamps.** `created_at`, `updated_at`, and `deleted_at` come from the Host
  Clock; the schema has no `CURRENT_TIMESTAMP` default or `ON UPDATE` clause.

## What the schema does not prove

The DDL cannot establish invariants that need transactions, locking, or PHP
logic. The application owns them, serialized on the Product aggregate lock:

- structural validity of a Product configuration and exact active-Option coverage
  for each active Variant;
- the correctness of `composition_fingerprint` (the database only checks its
  format and uniqueness per Product);
- immutability of identity columns such as `code`, `role_key`, and the Content
  language;
- a usable Content name before activation, and the active-Role requirement for
  new assignments;
- atomic default replacement and positive, gap-managed ordering.

Pricing, Inventory, Supplier, Category mapping, and Media storage are outside the
package and have no table here.

## Verification

The schema is verified against a real MySQL server by the repository-owned
Integration suite (`tests/Integration/Schema/`, PHPUnit) and the Consumer
Verification Harness (`consumer-verification/`). Both run through
`composer test:integration` and `composer verify:consumer`, provision MySQL from
the single [`docker-compose.yml`](../docker-compose.yml), and run in CI. Commands,
prerequisites, the local-to-CI mapping, and the current limits are in
[`docs/verification/CI_GATES.md`](../docs/verification/CI_GATES.md).
