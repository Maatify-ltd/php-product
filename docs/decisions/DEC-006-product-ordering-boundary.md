# DEC-006 — Product Ordering Boundary for Product V1

- **Decision ID:** `DEC-006`
- **Title:** Product Ordering Boundary for Product V1
- **Status:** `ACTIVE`
- **Date:** `2026-10-12`
- **Decision Authority / Deciders:** Owner
- **Scope / Concern:** Product Ordering Boundary / Exclusion of Package-Wide Product Display Order / Category Mapping Ordering Boundary
- **Canonical Contract / Current Owner:** [`docs/architecture/PRODUCT_V1_ARCHITECTURE_CANDIDATE.md`](../architecture/PRODUCT_V1_ARCHITECTURE_CANDIDATE.md) (§10, §11, §20)
- **Supersedes:** None
- **Superseded By:** None

---

## Context

During the architectural design and specification of `Product V1` ([`docs/architecture/PRODUCT_V1_ARCHITECTURE_CANDIDATE.md`](../architecture/PRODUCT_V1_ARCHITECTURE_CANDIDATE.md)), the candidate model tentatively included a `display_order` column on `maa_product_products` and a dedicated consumer-listing index `(status, deleted_at, display_order, id)`. This represented a global sequence across all products in the package-owned database namespace.

However, during the evaluation of Product V1 architecture and domain boundaries:
1. Persistent contextual ordering belongs on the relationship representing that specific scope (such as category-product mapping), rather than on the core Product entity as an artificial package-wide sequence.
2. If `Product` retains a package-wide `display_order`, consuming applications or developers may conflate this global integer with category-specific ordering, leading to architectural leakage and invalid ordering assumptions across the catalog.
3. Neither `Maatify/php-product` nor `Maatify/php-category` owns the relationship between products and categories. Introducing category-specific ordering into `Product` would violate the core architectural separation of the two independent packages.
4. If contextual ordering belongs on the category-product relationship, where should that relationship live, and how should it be ordered?

These architectural questions were isolated as Owner Decision Gate 6 in [`docs/architecture/PRODUCT_V1_ARCHITECTURE_CANDIDATE.md`](../architecture/PRODUCT_V1_ARCHITECTURE_CANDIDATE.md) §20 to establish a binding boundary before implementation.

---

## Decision

The project Owner formally approved the following decision on 2026-10-12.

### Owner Approval Trace (Arabic Original)

The Owner's exact verbatim response was:

> اعتمد

This was given in direct approval of the Lead recommendation governing the Product V1 ordering boundary.

### English Translation of Owner Approval

> Approved

### Explicit Contract Details of the Approved Decision

1. **Exclusion of Package-Wide `Product.display_order`:** Gate 6 is resolved by excluding the proposed package-wide `display_order` field from `maa_product_products` in Product V1. The dedicated Product consumer-listing index `(status, deleted_at, display_order, id)` is removed from the candidate schema.
2. **Preservation of Independence Between Product and Category:** `php-product` does not depend on `maatify/category`, and neither library owns a product-to-category relationship. Product-to-Category mapping remains strictly outside both core libraries.
3. **Category-Scoped Ordering on the Mapping Relationship:** When category-product mapping is implemented, persistent display ordering belongs on the mapping relationship itself, scoped by `category_id`. Scoped ordering on this relationship will use the stable `maatify/php-persistence` scoped-ordering API where applicable.
4. **No Speculative Integration Package:** A new integration package must not be introduced solely by assumption or premature abstraction. A consuming application (or Catalog coordinator) may directly own the integration; a reusable package may be evaluated only when an actual, demonstrated cross-project contract warrants it.
5. **Presentation and Read-Sort Flexibility:** Consuming applications may apply different presentation, filtering, or read sort orders (such as newest, price, popularity, or alphabetical title) to product listings without treating those presentation choices as Product-owned persistent ordering.
6. **Preservation of Scoped Ordering for Internal Child Entities:** Ordering for internal child entities and relationships where ordering is scoped, local, and semantically necessary is fully preserved:
   - `maa_product_options`: ordered within owning `product_id`.
   - `maa_product_option_values`: ordered within owning `option_id`.
   - `maa_product_variants`: ordered within owning `product_id`.
   - `maa_product_image_assignments`: ordered within exact target and image role/language/platform scope.
   These entities continue to use positive `display_order` values managed via the shared `maatify/php-persistence` scoped-ordering API where applicable.

---

## Rationale

1. **Minimality of the Core Entity:** Removing package-wide `display_order` keeps the `Product` entity focused strictly on product identity and lifecycle, without maintaining an unneeded package-wide sequence column within the Product V1 boundary, where contextual product ordering is owned externally (such as on the category-product relationship or by consuming workflows).
2. **Prevention of Misuse:** Excluding global product ordering prevents consuming code from incorrectly relying on `Product.display_order` as a surrogate for category-specific or storefront-specific ordering.
3. **Strict Domain Decoupling:** Keeping category-product relationship mapping and its ordering outside `php-product` preserves complete modular independence between `php-product` and `php-category`.
4. **Proper Scoping of Persistent Ordering:** Contextual ordering is inherently relational: a product appears at position 1 in "Featured" but at position 42 in "Apparel". Persisting ordering on the relationship scoped by `category_id` correctly reflects domain reality and aligns with `maatify/php-persistence` scoped ordering.
5. **Avoidance of Premature Abstraction:** Avoiding the creation of an unneeded intermediate integration package prevents unnecessary repository proliferation, build overhead, and governance drag until concrete cross-project contracts require it.

---

## Consequences

### Positive Consequences

- `maa_product_products` schema remains minimal, clean, and free of unnecessary sequence columns.
- Clear separation between persistent relational ordering (scoped by parent or relation) and dynamic presentation sorting.
- Zero coupling between `php-product` and `php-category`.
- Predictable, standardized use of `maatify/php-persistence` scoped-ordering capabilities across all entities that genuinely require persistent ordering.
- All six Owner Decision Gates (`DEC-001` through `DEC-006`) are now fully resolved.

### Completed Reconciliations

The following documentation reconciliations were completed atomically as part of recording this decision:
- Reconciled `docs/architecture/PRODUCT_V1_ARCHITECTURE_CANDIDATE.md`:
  - Removed `display_order` from the `maa_product_products` entity outline (§11).
  - Removed the dedicated consumer-listing index `(status, deleted_at, display_order, id)` from index requirements (§11.5).
  - Updated Section 10 to reflect the exclusion of package-wide `Product.display_order` and the placement of category-scoped ordering on the external mapping relationship.
  - Resolved Gate 6 as approved by Owner under `DEC-006` in Section 20, marking all six V1 decision gates resolved.
- Updated `docs/decisions/DECISIONS_INDEX.md` to index `DEC-006` as `ACTIVE` and reflect that all six Owner decision gates are resolved.

### Follow-up Requirements

- Any future implementation of Product persistence, mapping integration, or query services must strictly preserve this boundary: `maa_product_products` does not maintain a persistent sequence column, and persistent product ordering within a category belongs to the mapping relationship scoped by `category_id` (managed via `maatify/php-persistence`).
- This decision itself does not authorize runtime PHP implementation, SQL migrations, database behavior, or modifications to `STANDARDS_MANIFEST.md`; the start of implementation is authorized by [`DEC-007`](DEC-007-product-v1-implementation-start-authorization.md).
- The standards applicability re-evaluation required before persistence implementation is recorded in `STANDARDS_MANIFEST.md`.

---

## Boundaries & Non-Goals

This decision is strictly bounded to the ordering boundary for Product V1. The following areas are explicitly non-goals and out of scope:

- Implementing Category-to-Product mapping schema, tables, or APIs.
- Creating a new Catalog or integration package.
- Altering scoped ordering on `Option`, `Option Value`, `Variant`, or `Image Assignment`.
- Implementing runtime PHP code, classes, interfaces, traits, or exceptions.
- Creating SQL DDL files, database migrations, or schema tables.
- Modifying `STANDARDS_MANIFEST.md` or activating conditional persistence standards.
- Authorizing implementation or merging the Draft umbrella PR.
