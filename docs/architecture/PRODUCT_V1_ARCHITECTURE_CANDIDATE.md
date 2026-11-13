# Product Package V1 Architecture

**Status:** Owner-approved architecture contract for Product V1, approved under [`DEC-007`](../decisions/DEC-007-product-v1-implementation-start-authorization.md) (2026-10-12) and maintained in place before the first release: the decisions it carries are kept current in this text. Implementation is authorized only within the local Product scope defined here.<br>
**Package:** `maatify/php-product`  
**Repository:** `Maatify-ltd/php-product`<br>
**Document path:** `docs/architecture/PRODUCT_V1_ARCHITECTURE_CANDIDATE.md`<br>
**Architecture type:** Standalone, reusable, host-agnostic Composer package  
**Scope:** Product identity, variants, variant-defining options and values, product content, and product-owned image assignments  
**Review date:** 2026-10-11  
**Review basis:** Current `maatify/php-category` package reference, usage guide, SQL schema, and the Product repository's currently pinned standards manifest.

## 1. Executive Decision

`php-product` owns the product domain and the identity of each sellable unit. It MUST NOT own pricing, inventory, supplier integrations, customer account types, categories, orders, or physical media storage.

The core identity model is:

- `Product` is the commercial/catalog identity displayed to a shopper.
- `Variant` is the stable identity of a specific sellable version of that product.
- Every product MUST have at least one internal Variant identity.
- A simple product gets its one empty-composition Variant automatically. A host operator MUST NOT have to create that Variant manually.
- A configurable product has multiple Variants distinguished by their complete option-value compositions.
- Pricing resolves a price for a Variant within a pricing context and currency. The Product package does not store or calculate prices.
- Inventory may track a Variant when the fulfilment model needs quantity tracking. The Product package does not require stock or an Inventory row for every Variant.
- Product, Variant, Option, and Option Value do not know the host's customer-account types or depend on Pricing, Inventory, Supplier, Category, Order, or Media Storage packages.

This is a deliberate redesign of parts of the historical Catalog V1 specification. The previous Product Base Price plus Option Value adjustment equation is not the required V1 pricing contract. The Catalog integration layer and the Pricing contract must be updated consistently before integrated sellability is accepted.

### 1.1 Review status and approval boundary

This document has been reviewed against the current Category package contract and the applicable Product repository standards references. The review tightened several previously under-specified areas: content identity and exact locale reads, composite foreign-key enforcement for Variant composition and image-assignment targets, mutation serialization, active Option Value coverage, status casing, and the persistence design (§11).

This document itself adds no SQL DDL, migrations, or persistence implementation. The Owner approved it as the architecture contract for Product V1 under [`DEC-007`](../decisions/DEC-007-product-v1-implementation-start-authorization.md). The pre-implementation standards applicability re-evaluation required by that decision was completed on 2026-10-12 and is recorded in `STANDARDS_MANIFEST.md`: package-owned SQL persistence and manually maintained PHP are applicable, so the conditional persistence requirements of the Package Building Standard, and the persistence-conditioned requirements of the CI Workflow, Testing, Composer Package, and Library Presentation Standards, apply to the implementation.

Owner approval authorizes the start of implementation within the approved scope; it is not evidence of implementation, schema execution, or test success, and it does not authorize Pricing or Inventory libraries, full Catalog integration, a Product-to-Category relationship inside either core library, merge, release, or publish. No Product SQL schema or PHP implementation has been executed or validated by this review.

## 2. Design Principles

1. **One sellable identity:** All price, stock, supplier-offer, and order-item integrations refer to the same stable Variant identity.
2. **Simple stays simple for consumers:** A simple product is added to a cart without a visible Variant selection. Its Variant exists internally and is created automatically.
3. **No pricing duplication:** Option values describe a variant; they do not implicitly add money. Price changes based on account class or other pricing context belong to Pricing.
4. **Host-agnostic boundaries:** No Host classes, account types, Category identifiers, Pricing tables, Inventory tables, Supplier tables, or external-media foreign keys are embedded in Product persistence.
5. **Stable identity:** A logical identity or a Variant composition is not mutated into a different identity. Replace it with a new identity through a controlled replacement workflow.
6. **Explicit lifecycle:** `status` and `deleted_at` are different dimensions. Restore reuses the original identity, not a replacement identity.
7. **No duplicated state:** Simple/configurable mode is derived from options and Variant compositions; it is not independently persisted as another state field.
8. **No cross-domain sellability policy in Product:** Product-local validity is distinct from whether the item can be sold at a price, from stock, or from an external fulfilment provider.
9. **Typed public API:** Commands, criteria, DTOs, enums, value objects, exceptions, and interfaces form an explicit typed public contract. Public APIs do not use associative arrays as domain contracts.
10. **Consumer-neutral documentation:** Public package documentation MUST NOT name a specific consuming website or application. Use `Host Application`, `Catalog coordinator`, and generic business examples instead.

## 3. Domain Ownership

### 3.1 Owned by `php-product`

- Product identity and lifecycle.
- Product content records (name, short description, description), with optional localization and no implicit locale fallback.
- Product-owned Image Role registry and Product/Variant Image Assignments, scoped independently of Category Image Roles and referencing external Media Asset identities.
- Variant identity and lifecycle.
- Product Options and Option Values that define selectable variants.
- Immutable Variant-to-Option-Value composition.
- Structural validation, duplicate-composition prevention, ordering, and bounded management/consumer reads where exposed by the package contract.

### 3.2 Explicitly outside the package

- Price amounts, currencies as pricing records, price lists, pricing contexts, adjustments, discounts, promotions, and account-based price selection.
- Quantity on hand, reservations, stock movements, inventory availability, and stock-ledger behavior.
- Suppliers, provider credentials, upstream SKU mapping, digital-code allocation, external fulfilment, and provider verification.
- Category/Taxonomy entities and Product-to-Category mapping.
- Catalog/Tenant/Store/Channel ownership as a first-class domain entity unless separately approved.
- Cart, order, payment, tax, shipping, customer identity, customer account type, permissions, HTTP, UI, or framework integration.
- Upload, physical media storage, media processing, and media lifecycle. Product stores assignments/references only.
- A universal arbitrary attribute engine in V1. A selectable attribute that differentiates a sellable unit is an Option; descriptive attributes that do not define a Variant are not silently modelled as Options.

## 4. Product and Variant Model

### 4.1 Product

A Product represents the shared commercial identity. It owns a required stable `code`, lifecycle state, and content records that may be localized or unlocalized. Product content is called **Content**, not only “Translation”, because `language_code = NULL` represents unlocalized content. `name` is a label, not HTML; `short_description` and `description` are stored content values only. Product does not render or sanitize HTML, and the Host owns any markup/security policy. **URL-facing slugs are Host-owned and are out of scope for `php-product` V1** because URL identity may differ by language or channel.

Product does not own a price, inventory count, stock state, supplier record, customer account-type mapping, Category relationship, or URL/SEO policy.

Identity rules:

- Product `code` is required, non-empty after validation, stored as `VARCHAR(100)`, unique across the V1 package persistence namespace, immutable after creation, and remains reserved after soft deletion. Reject empty and surrounding-whitespace-only values; do not silently trim or rewrite an identifier after it has been supplied. Code uniqueness uses the package table collation `utf8mb4_unicode_ci` (case-insensitive comparison); changing only letter case does not create a different code.
- `product_id` is the canonical internal identity. `code` is a stable package-owned business key, not a replacement for the primary key.
- Identity corrections must use an explicit migration/replacement process; ordinary update commands do not mutate Product `id` or `code`.
- Product content is stored separately from stable Product identity. Product/Option/Option Value Content uses the same nullable-language identity semantics as `maatify/php-category`: `language_code = NULL` is one unlocalized record; a non-NULL language code identifies content for that exact language. Exact lookup does not silently substitute the NULL record for a requested language.
- `language_code` is limited to the shared contract's maximum length of 16 characters; NULL is distinct from an empty string, empty/whitespace-only non-NULL values are rejected, and semantic language support plus locale/fallback choice belong to the Host. Product performs no implicit fallback.
- For each content table, the logical identity `(owning_entity_id, language_code)` is immutable and remains unique across soft-deleted history, including the NULL language identity. Content updates change content fields by content-record ID, not the owner or language identity. The schema must use a NULL-safe generated identity column or an equally strong MySQL mechanism so multiple NULL-language rows cannot bypass uniqueness.
- V1 supports one Product identity namespace per deployed set of Product tables. It does not support multiple isolated Catalogs sharing the same Product tables while reusing codes. Such a deployment must use isolated Product table sets or obtain an explicitly designed future scoped-identity capability; the Executor must not add `catalog_id`, tenant, or store scope speculatively.

### 4.2 Variant as the internal sellable unit

Every Product has at least one Variant identity, including a simple Product. A Variant has a stable ID independent of its current status and supports optional SKU and barcode identifiers.

A Variant is the reference used by external domains to identify exactly what is sold. It does not have a price or stock quantity column in the Product schema.

Identity and identifier rules:

- Variant belongs to exactly one Product; Variant ID is immutable and is the canonical identity used by Pricing, Inventory, supplier integrations, and order lines.
- SKU and barcode are optional secondary identifiers, not Variant identity. Store them as strings up to 128 characters so leading zeroes are preserved. Each value must be either `NULL` or a non-empty, non-whitespace-only string; an empty identifier is represented by `NULL`. They are unique within the package persistence namespace while assigned, with equality following the package table collation (`utf8mb4_unicode_ci`); case-only differences therefore do not create distinct identifiers. They may be corrected/replaced through an explicit typed identifier-update mutation with collision validation.
- A soft-deleted Variant retains its current SKU/barcode values, so those values remain reserved while still assigned. If an identifier is explicitly removed or replaced, its previous value is released; historical orders must retain purchase-time snapshots and use `variant_id` as the canonical reference.
- SKU/barcode must not be used as foreign references by another Maatify domain. Supplier-specific product identifiers belong to the Supplier/Integration domain.
- Variant is not a separate Composer package in V1; it is a first-class capability inside `php-product`.

### 4.3 Simple Product

A Product is structurally **simple** when it has no active variant-defining Options and exactly one active, non-deleted Variant with an empty composition.

Creation of a simple Product automatically creates that empty-composition Variant in the same local atomic operation. The caller receives both identities through typed results. The host may add the simple item to a cart without asking the shopper to select a Variant.

The empty-composition Variant is still the price/stock/order integration identity. “Simple” is a consumer-facing behavior, not an exemption from having a Variant.

### 4.4 Configurable Product

A Product is structurally **configurable** when it has active variant-defining Options and one or more active Variants with a complete composition.

- Each active Variant composition MUST equal the complete active Option set for its Product: exactly one active Option Value for each active Option, and no value from an inactive/deleted Option.
- Every selected Option Value MUST belong to the selected Option.
- An Option, its Values, a Variant, and the Product MUST belong to the same Product scope as applicable; cross-Product compositions are invalid.
- Duplicate active or historical compositions MUST NOT result in duplicate Variant identities for the same Product.
- A configurable Product has no implicit domain-level default Variant; `is_default` is not required in V1. The Host owns shopper-selection presentation, UI facets, and user choice gathering.
- Before adding a configurable item to a cart/order, the Host resolves customer option selections to one concrete active `variant_id` by executing the Product-owned typed composition resolution query (§5.5). The Host manages presentation, facet navigation, and user input, while the Product package validates the composition against active configuration invariants and returns the matching Variant identity. If only one Variant is eligible, a Host may preselect it under its own explicit storefront policy; `php-product` itself does not silently select a default.
- The simple/configurable classification is derived. Do not persist `product_type = simple|configurable` unless a future contract establishes a distinct lifecycle or invariant requiring it.

### 4.5 Simple/Configurable transitions

The initial Product creation transaction MUST create the Product and one active, non-deleted Variant with an empty composition. The Product itself is created `inactive` by default so it can be populated and validated before consumer visibility; it is not activated merely because its empty Variant exists. The initial empty Variant is the only Variant that may be created outside the configuration/replacement operation; non-empty Variant identities are created only by atomic reconfiguration and are active members of the validated replacement set.

Changing a Product's configuration is a dedicated Product-local atomic reconfiguration, not a sequence of ordinary Option CRUD calls:

- **Simple → configurable:** activate/establish the new Option set, create complete replacement Variants with immutable compositions, and deactivate the empty-composition Variant in the same Product-owned transaction.
- **Configurable → simple:** in the same Product-owned transaction, deactivate every currently active variant-defining Option, deactivate all active non-empty-composition Variants, and reactivate or explicitly restore the Product's original empty-composition Variant. Retain the Options and Option Values as historical identities; values under an inactive Option are not selectable. Do not create a second empty-composition Variant.
- The empty-composition fingerprint remains unique across the Product's full history, including inactive or soft-deleted records. If the original empty Variant is soft-deleted, it must be explicitly restored and revalidated; a new identity with the same composition is forbidden.
- The local operation does not claim cross-domain atomicity. Before reactivating a historical Variant, the Catalog/Host coordinator must reconcile its existing Pricing, Inventory, Supplier, and fulfilment mappings. The Product package does not inspect or copy those records.
- If the expected historical empty Variant is missing or invalid, the transition fails closed and reports the reason; the implementation must not silently generate a duplicate historical composition.

A Product may contain historical inactive Variants whose compositions no longer match today's active Option set. Those Variants remain for stable historical references, but they MUST NOT be consumer-selectable or restored to active status unless they once again satisfy all current structural invariants.

## 5. Options, Option Values, and Composition

### 5.1 Meaning of Option

An Option is a shopper-selectable, Variant-defining dimension, such as color, size, storage capacity, region, or a denomination.

An Option Value is one allowed value of that dimension. Codes are stable business keys; translated/unlocalized labels live in separate Content records.

V1 Options are always Variant-defining and required dimensions of every active Variant while the Option is active. V1 does not support optional per-Variant Options; a genuinely optional add-on is outside this capability and must not be disguised as an incomplete composition. An explicit `none` Option Value is allowed only where “none” is a genuine selectable value in the business domain.

Options are not a general-purpose attribute system. Facts such as brand, material description, warranty text, platform notes, or a provider note do not create Variants merely because they are stored as product data. A general descriptive-attribute engine, brand registry, and extensible product-facts model are out of scope for V1; these must be designed as separate capabilities if a real consumer requirement establishes them.

### 5.2 Stable codes

- Option `code` and Option Value `code` are required, non-empty, `VARCHAR(100)`, immutable, and compared using the package collation `utf8mb4_unicode_ci`. Reject empty and surrounding-whitespace-only identifiers at the API boundary and add compatible DDL checks. Option `code` is unique within its Product; Option Value `code` is unique within its Option. These identities remain reserved across soft-deleted history.
- Option and Option Value labels live in separate optionally localized Content tables. Each Content table has the Category-compatible nullable-language identity and no implicit fallback.
- Deleting an Option or Option Value logically does not release its identity for a different meaning.

### 5.3 Composition immutability

A Variant's composition is immutable after creation. The package MUST NOT change an existing Variant from, for example, `(color=blue, size=M)` into `(color=red, size=L)`.

Changing a composition means creating a new Variant identity and deactivating/retiring the old identity through a controlled replacement flow. The old Variant identity remains available for historical references and is not repurposed.

To prevent duplicate combinations reliably under concurrent requests, compute a deterministic canonical composition fingerprint and enforce uniqueness per Product across **all Variant rows, including inactive and soft-deleted history**. Use this exact V1 canonicalization:

1. Sort composition pairs by numeric `option_id` ascending.
2. Encode the empty composition as the UTF-8 string `v1`.
3. Encode each non-empty pair as `|{option_id}={option_value_id}` appended to `v1`; for example: `v1|12=58|13=70`.
4. Store the lowercase hexadecimal SHA-256 digest of that UTF-8 string.

The fingerprint is an internal enforcement key, not a consumer-facing identifier. Before treating a matching digest as the same composition, compare the actual sorted pairs. If a digest matches but the actual composition differs, fail closed with an integrity error rather than conflating identities. A request to create a composition that already exists in history must not create a second Variant or silently reactivate the old one: return a typed conflict identifying the existing Variant; restoration/reactivation must be an explicit operation that revalidates current invariants.

### 5.4 Option changes and replacement

- Adding, removing, activating, or deactivating an Option or Option Value may invalidate existing Variant compositions. Such mutations MUST NOT leave active Variants with partial or extra coverage. New Options and Option Values are created `inactive` by default so their codes/content can be prepared without changing the current configuration. Any change to the active Option set, or to active Values represented by current Variants, is applied by the dedicated atomic reconfiguration operation.
- Adding an active Option to a Product with active Variants is invalid unless the same atomic reconfiguration also supplies replacement complete Variants. Removing/deactivating an Option or Value used by active Variants similarly requires the reconfiguration flow. Normal Option/Value mutations must reject any resulting invalid active state.
- Product V1 MUST NOT automatically generate the Cartesian product of all Option Values. The caller/Host supplies the set of combinations that are genuinely offered; unavailable or commercially invalid combinations may be omitted.

Provide an explicit atomic Product-local configuration/replacement operation that:

1. Validates the intended new active Option/Value set and requires every active Option and every active Option Value belonging to an active Option to have a non-empty label in at least one non-deleted localized or unlocalized Content record.
2. Validates every proposed Variant composition, requiring exact coverage of every active Option, rejecting extra inactive/deleted Options, cross-Product values, invalid Option Values, and duplicate compositions.
3. Creates replacement Variant identities and their immutable compositions.
4. Deactivates replaced Variants without deleting historical identities; reuses/restores a historical matching composition only through explicit identity-aware handling. A matching historical fingerprint is a conflict unless the command explicitly identifies the existing Variant for reactivation/restoration.
5. Preserves at least one active valid Variant if the Product is active.
6. Ensures all Product-local invariants before the local operation completes.

When the Product already participates in Pricing, Inventory, or Supplier/fulfilment, the Host/Catalog coordinator is responsible for cross-domain orchestration. The Product package never calls those packages directly and MUST NOT pretend that its local transaction alone makes the whole Catalog mutation atomic.

New Variants do not automatically inherit prices, inventory, supplier offers, or fulfilment mappings from old Variants. Such copying must be an explicit policy owned by the relevant external domain and orchestrated by the Host.

### 5.5 Product-owned Variant composition resolution contract

While the Host Application owns the storefront user interface, customer interaction flow, and presentation of selectable option facets, the responsibility for validating option choices and resolving them to an internal Variant identity belongs strictly to a **Product-owned typed read query contract**.

The contract enforces these exact behaviors:

1. **Typed domain contract:** The query accepts a target Product identity (`product_id`) and a choice composition represented by an explicit typed contract (for example, `VariantCompositionCriteria` or a typed collection of `OptionValueSelection` pairs: `option_id` and `option_value_id`). Public domain contracts MUST NOT expose or accept raw associative arrays (`array<string, mixed>`) as selection contracts.
2. **Order-independent matching:** The physical sequence of Option/Value pairs within the request contract does not affect matching. The query canonicalizes pairs by sorting according to the canonical fingerprint rules (§5.3).
3. **Strict structural validation:** The query rejects any request that duplicates an Option, references an Option or Option Value belonging to a different Product, pairs an Option Value with an incorrect Option, or references an inactive or soft-deleted Option or Option Value.
4. **Exact active Option coverage for Configurable Products:** For a configurable Product, the requested selection MUST match the entire set of currently active variant-defining Options for that Product: exactly one active Option Value for each active Option, with no missing active Options and no extraneous Options.
5. **No implicit creation or reactivation:** The resolution query is strictly read-only. It MUST NOT create a new Variant, MUST NOT automatically reactivate an inactive or historical Variant composition, and MUST NOT invent or assume an implicit default Variant.
6. **Typed failure contract:** When no matching active, non-deleted Variant exists for the requested valid composition, the query fails closed by returning a documented typed result or throwing a documented typed domain exception conforming to the `maatify/exceptions` package hierarchy (e.g., `VariantNotFoundException`).
7. **Simple Product resolution:** For a simple Product, an empty selection composition is the internal identity resolution mechanism, returning the single active empty-composition Variant, provided both the Product and its Variant are active, non-deleted, and viewable under consumer rules.
8. **Clear boundary separation:** The Host Application decides how options are presented to the shopper, which values are selected or preselected in the UI, and when to invoke resolution; the Product package owns verifying whether the selected composition constitutes an existing, active, structurally valid Variant.

## 6. Lifecycle and Structural Invariants

### 6.1 Status, deletion, and default states

- Status values are exactly lowercase `active` or `inactive`. Every status-bearing table must enforce exact casing in MySQL (for example, a binary comparison in `CHECK`); a case-insensitive table collation alone is not sufficient. Public mutations use typed status enums.
- `deleted_at IS NULL` means the row is not soft-deleted. Status and deletion are separate dimensions. Runtime hard-delete is not used for normal lifecycle operations.
- Product creation defaults to `inactive`; its initial empty-composition Variant is `active`. New Options and Option Values default to `inactive`. Product Image Roles default to `active`, matching Category Image Role creation semantics. Reconfiguration may explicitly activate prepared Options, Values, and replacement Variants in one transaction.
- Restore reuses the same identity and preserves the saved `status`; it MUST validate the restored state. Restoring a saved `inactive` row never activates it. Status changes on a soft-deleted row are not a substitute for restore; restore first, then issue an explicit status mutation where allowed.
- No automatic cascading soft-delete changes child status or `deleted_at`. Parent visibility suppresses child consumer visibility without rewriting child state.
- Re-adding a soft-deleted logical identity never restores it implicitly and never inserts a second row to bypass the identity key: create fails with a typed conflict that identifies the existing identity, and the only way back is an explicit restore, which reuses the original identity and revalidates the invariants. This applies to every family that keeps identity across soft-deleted history: Product code, Option and Option Value codes, the Image Role key, Content identity, Variant composition, Variant SKU and barcode, and Image Assignment identity.

### 6.2 Product structural validity

Every Product configuration must remain structurally valid after every committed mutation, even while Product status is `inactive`; inactivity is a visibility/lifecycle decision, not permission to commit a broken Variant graph. A Product can be activated only if it also has at least one usable name in a non-deleted Content record.

A valid Product configuration is exactly one of these forms:

- **Simple:** no active Options and exactly one active, non-deleted empty-composition Variant; no non-empty Variant may be active.
- **Configurable:** at least one active Option and at least one active, non-deleted Variant; every active Variant has exactly one active Value for every active Option and no value belonging to an inactive/deleted Option.

Every active Option must have at least one active, non-deleted Value. Every active Value belonging to an active Option must appear in at least one active valid Variant; if the Host no longer offers a Value in any active combination, it must be made inactive in the same valid reconfiguration. This prevents a consumer from seeing an active selectable value that cannot resolve to any Variant. The package does not generate Cartesian products.

Product activation MUST NOT depend on a price record, currency, non-zero stock, inventory row, supplier offer, or customer account type. These are external sellability/fulfilment concerns.

### 6.3 Variant validity

A Variant can be active only when:

- Its Product exists. A logically deleted or inactive Product suppresses consumer visibility but does not rewrite Variant state.
- Its composition fingerprint is unique for that Product across active, inactive, and soft-deleted history.
- If the Product has no active Options, the only active Variant is the single empty-composition Variant.
- If the Product has active Options, the composition contains exactly one active Option Value for every active Option and no value for any inactive/deleted Option.
- Each selected Option and Option Value belongs to the same Product; each selected Value belongs to its selected Option; every referenced Option/Value is active and non-deleted and has a usable label in at least one non-deleted Content record.

Historical inactive Variants may refer to now-inactive/deleted Options or Values, but they cannot be reactivated until their complete composition satisfies all current invariants.

### 6.4 Option/Value and Content lifecycle

- Every Content record must have a usable non-empty `name`/label. A localized Content record is not required for every supported language. Product activation needs at least one non-deleted Product Content name in any language or in the unlocalized (`NULL`) record. That does **not** promise that a name exists for every requested locale; the Host owns language availability and fallback policy.
- Product, Option, and Option Value Content uses `language_code VARCHAR(16) NULL`. `NULL` means unlocalized content; a non-NULL value is content for that exact language. Empty and whitespace-only non-NULL language codes are invalid. Product performs no locale normalization, semantic language validation, or implicit fallback; the Host owns those policies.
- The immutable Content identity is `(owner_id, language_code)`, and only the content fields may change through a record-ID update. The owner and language identity cannot be edited in place. The identity remains unique even after soft deletion, including the single NULL-language row; re-addition requires explicit restoration of the original record.
- A content mutation, status mutation, restore, reconfiguration, or image-assignment operation that changes an aggregate invariant must be serialized with the relevant Product row lock. Read-before-write checks without transaction/locking and database uniqueness are not sufficient under concurrent writers.
- An Option/Value status mutation that would invalidate an active configuration must be rejected unless included in the dedicated atomic Product reconfiguration. A Product may not commit a partial active Option set or a Variant with missing/extra Values.
- Option and Option Value each have a standalone status mutation (`updateStatus`). It locks the Product graph, rejects a soft-deleted row or a soft-deleted owner as a business-rule failure, treats a request for the current status as a no-op with no write, and validates the complete configuration with the new status, rejecting any result that breaks a structural invariant (§6.2, §6.5). Changing the active set atomically remains the dedicated reconfiguration operation.

### 6.5 Mutation-time invariants

These are postconditions for every committed mutation, not activation-only checks:

- A Product in the simple form always retains its original empty-composition Variant identity. A Product in the configurable form always has at least one active complete Variant.
- Whenever `Product.status = active`, Product has at least one non-deleted Product Content record with a non-empty name.
- No committed mutation may deactivate/delete the last valid active Variant, remove the last usable name from an active Product, or remove/deactivate a Value needed by an active Variant unless the same transaction finishes a valid replacement configuration.
- Any operation changing the active Option set or active Variant set is one Product-local atomic reconfiguration. All rows are validated before commit; if a complete valid replacement cannot be produced, reject the whole operation.
- Restore preserves the saved status and must revalidate both entity-level and aggregate invariants. The package never silently cascades child states or silently repairs invalid history.

## 7. Pricing Contract and Customer Account Types

### 7.1 Pricing is Variant-addressed

Pricing is owned entirely by `php-pricing`. The V1 direction is direct pricing for a Variant per pricing context and currency, rather than a mandatory Product base price plus Option Value adjustments.

Recommended logical lookup:

```text
price = Pricing.resolve(
    variant_id,
    pricing_context_id,  // for example, a Price List owned by Pricing
    currency_code
)
```

The exact type names and schema of `pricing_context_id` / Price List are owned by the Pricing package and MUST be confirmed there before integration. `php-product` does not define Price Lists or currency policy.

### 7.2 Host-owned customer-classification mapping

A consuming Host may use a customer account type or another business classification to choose the applicable pricing context:

```text
customer classification (Host-owned)
        -> Host-owned mapping
        -> Pricing context / Price List
        -> Pricing lookup for variant_id + currency
```

The Product and Variant models do not contain `account_type_id` and do not branch on any Host-specific account taxonomy. This keeps the Product package reusable across different applications and business domains.

Different customer classifications receiving different prices for the same sellable unit do not require duplicate Products or Variants. They require different pricing contexts or an equivalent rule defined by Pricing.

### 7.3 Missing price and price safety

- A missing applicable price means “not sellable in this pricing context/currency”; it does not make the Product entity invalid.
- The resolved price must satisfy Pricing's monetary invariants (including non-negative final price where that is the agreed contract).
- Product attributes and Option Values do not add price adjustments automatically.
- Discounts, promotions, taxes, fees, and time-effective prices are Pricing/other-domain concerns, not Product responsibilities.
- Pricing changes do not alter Product or Variant identity.

The Catalog coordinator owns cross-domain sellability decisions, combining Product structural validity with price availability and the relevant fulfilment/availability result.

## 8. Inventory, Supplier, and Order Integration

### 8.1 Inventory

Inventory MAY reference `variant_id` when the fulfilment model requires quantity tracking. Product does not require every Variant to have an Inventory row, does not store `quantity_on_hand`, and does not implement `force_out_of_stock`.

A digital item fulfilled by a provider, a service item, a made-to-order item, and a physically stocked item can share the same Product identity model while using different availability/fulfilment policies outside Product.

### 8.2 Supplier and digital delivery

Supplier/provider offers and external product identifiers belong to a separate Supplier/Integration domain. Supplier mapping can reference `variant_id`, but Product does not know provider credentials, upstream APIs, code pools, or fulfilment states.

An independent supplier price is not a customer sale price. Supplier cost/offer data and customer-context sale pricing remain separate concerns.

### 8.3 Orders

An order line references `variant_id` and stores its own purchase-time snapshot (such as product name, selected options, SKU where applicable, currency, unit price, and other commercial details required by the Order contract). Product remains the current catalog source, not the historical order snapshot store.

The Product package does not create orders or decide how an order snapshot is retained.

## 9. Image Roles and Assignments

Product owns Product/Variant image-role metadata and image-assignment relations, but never owns Media Asset storage, upload, processing, URL resolution, or Media Asset lifecycle. This capability is Product-owned and independent of `maatify/php-category`'s Category Image Role registry and Category Image Assignments. Their role IDs are separate namespaces; Product must not consume or persist Category `role_id` values.

The capability is named Image Role and Image Assignment, persisted as `maa_product_image_roles` and `maa_product_image_assignments`, with the explicit default marker `is_default` and the generated `default_scope_identity` (§11.4). Single-record reads follow the package read pair: `getById` is the consumer read and returns only a consumer-visible record, and `getByIdForManagement` is the management read, which ignores status and filters only by soft-deletion state. Image Roles and Image Assignments are read one by one through `getByIdForManagement`; consumers read assignments only through the exact-scope read (§12).

### 9.1 Media Asset ID interoperability

V1 formally adopts the shared Media Asset identity contract approved by the Owner under [`DEC-005`](../decisions/DEC-005-shared-media-asset-identity.md), maintaining complete compatibility with the current `maatify/php-category` public contract: `media_asset_id` is an external, Host-owned scalar identity persisted as `BIGINT UNSIGNED`, enforced as positive (`CHECK (media_asset_id > 0)`), and exposed across public PHP read DTOs and interfaces as a native integer (`int`). The identity remains strictly Host-owned and has **no foreign key or JOIN** from Product to Media storage. It is not a Product primary key.

Input normalization conforms to Category's `CategoryIdDTO` boundary contract: command inputs accept `int|string`, require positive integers (`>= 1`), and for strings reject zero, negative/positive signs, leading/trailing whitespace, leading zeroes, decimal notation, scientific notation, malformed representations, and values greater than `PHP_INT_MAX` **before casting** to integer. The PHP layer explicitly does not claim to accept the entire unsigned 64-bit integer range; the Category contract's supported PHP integer boundary remains binding.

This formally replaces the earlier draft's arbitrary opaque string ID. The Category package currently persists `media_asset_id` as `BIGINT UNSIGNED` and exposes the assignment identity as an integer; using one shared numeric ID prevents Product and Category from referring to the same Host Media Asset under incompatible IDs. If a future Host requires UUID/string Media IDs, the Owner must approve a coordinated contract migration for both packages or introduce an explicit Host-owned mapping; the Executor must not silently reintroduce string IDs into Product alone.

### 9.2 Product Image Roles

Product owns a small `Product Image Role` registry because Product image roles qualify Product/Variant assignments and must not depend on Category. The Host defines role meanings and UI/presentation policy; Product ships no seeded roles and does not decide that a role means `hero`, `gallery`, `swatch`, or any other business meaning.

- `role_key` is required, non-empty after trimming, limited to 100 characters, immutable, unique within the Product package role namespace, and remains reserved after soft deletion. Use the same `utf8mb4_unicode_ci` identifier comparison as the current Category schema; do not implement a different PHP-only case-folding rule.
- Role lifecycle uses the exact lowercase values `active` and `inactive`, independently of `deleted_at`. Enforce the exact values through a typed enum and a case-sensitive database constraint. Restore preserves the same Role identity and status.
- A new role-scoped assignment requires an active, non-deleted Product Image Role. Existing role-scoped assignments are omitted from consumer reads while their Role is inactive or deleted, but remain available to management reads.
- NULL `role_id` is the exact generic/unclassified role scope, not a fallback request. Product Image Role IDs must never be treated as Category Image Role IDs.

### 9.3 Assignment target and scope

An assignment targets exactly one of: the Product itself (`variant_id = NULL`), or one Variant belonging to that Product (concrete `variant_id`). Its logical data includes assignment ID, `product_id`, nullable `variant_id`, `media_asset_id`, nullable `role_id`, nullable `language_code`, nullable `platform`, `is_default`, `display_order`, timestamps, and `deleted_at`.

Internal relational integrity for `maa_product_image_assignments` is explicitly closed as follows:
- `product_id` MUST have an independent foreign key referencing `maa_product_products(id)` with `ON DELETE RESTRICT` and `ON UPDATE RESTRICT`. This enforces that every image assignment row—whether Product-level or Variant-level—is strictly anchored to a valid, existing Product identity in the database. A Product-level assignment for a non-existent Product is rejected at the database level.
- When `variant_id` is present (non-NULL), a composite foreign key on `(variant_id, product_id)` referencing `maa_product_variants(id, product_id)` with `ON DELETE RESTRICT` and `ON UPDATE RESTRICT` proves that the referenced Variant belongs to the exact same Product. A Variant belonging to a different Product cannot be used in an assignment.
- `variant_id = NULL` is valid only to represent a Product-level target. Setting `variant_id = NULL` does not exempt the row from Product identity validation; the independent `product_id` foreign key always enforces that the Product exists.
- Media Asset constraints (`BIGINT UNSIGNED > 0` with no external FK), Product Image Role references (internal FK to `maa_product_image_roles(id)` when `role_id` is non-NULL), exact role/language/platform scope dimensions, historical immutable identity, and at most one active default per exact target scope continue unchanged without external foreign keys.

`role_id`, `language_code`, and `platform` are exact scope dimensions. NULL means exact NULL, never “all roles”, “all languages”, or “all platforms”. Exact means no fallback; non-NULL string equality follows the shared `utf8mb4_unicode_ci` schema collation, like the corresponding Category fields. Empty non-NULL language/platform strings are invalid. `language_code` uses the same storage contract as Category (`VARCHAR(16)` maximum); `platform` uses `VARCHAR(255)` maximum, also matching Category. The Host validates semantic language/platform support and owns display/fallback policy. No platform enum is hardcoded.

The immutable assignment identity is `(target kind, target identity, media_asset_id, role_id, language_code, platform)`, including soft-deleted history. Enforce `media_asset_id > 0`, positive non-NULL `role_id` and `variant_id`, `is_default IN (0, 1)`, positive `display_order`, language length/blank checks matching Category, and platform length/blank checks matching Category. Reserve the NULL-sentinel values in CHECK constraints so generated identities cannot collide with valid stored values. The same Media Asset may be assigned to a different target or exact scope. Re-adding the same logical assignment never creates a replacement row and never restores it implicitly: create fails with a typed conflict that identifies the existing identity, and the explicit restore is the only way back. A deleted or inactive role never changes the identity dimensions. Product/Variant consumer reads MUST also require the target Product and (for a Variant target) the Variant to be active and non-deleted; an inactive/deleted ancestor target suppresses the assignment without mutating it.

### 9.4 Default and ordering behavior

- Each exact `(target, role_id, language_code, platform)` scope allows zero or one active default assignment. Default is independent of `display_order`; deleting or clearing the current default does not automatically select or promote another assignment. A new default is selected only through the explicit atomic replacement operation below.
- Setting a default assignment is an explicit atomic replacement within its exact `(target, role_id, language_code, platform)` scope: clear the previous default in that scope and set the requested assignment as default in the same transaction. It must be protected by database-enforced uniqueness plus transaction/target-scope locking. A nullable-scope uniqueness rule must not rely on a plain MySQL UNIQUE key that permits multiple NULLs.
- Soft-deleting a default assignment clears its default status and does not promote another assignment; restoring it leaves it non-default. Restore as default is a separate explicit command (`restoreAsDefault`): it restores a soft-deleted assignment and makes it the default of its exact scope in the same operation, and it fails with a typed conflict if that exact scope already has a live default. Setting a live assignment as default is the explicit set-default operation (`setDefault`), which replaces any current default atomically.
- Ordering is independent for each exact target and role/language/platform scope. Use the shared `maatify/php-persistence` Ordering API where its public contract applies; lists sort deterministically by `display_order, id`. Positions are positive and managed by the application, not supplied by database defaults. A restored assignment takes the next position at the end of its live exact scope, in the same operation that restores it; soft deletion does not compact positions. This deliberately differs from `maatify/php-category`, which keeps the original position, because a newer row may already hold it.
- Duplicate target/asset/scope identities, including historical rows and concurrent attempts, must be blocked by database-enforced identity keys. The application must not rely on read-before-write as the integrity mechanism.
- Product-level image assignments do not implicitly become Variant-level assignments, and Variant assignments do not replace Product-level assignments.
- Product never uploads, deletes, transforms, resolves, or checks the external Media Asset itself.

## 10. Category, Catalog, and Host Boundaries

`php-product` does not depend on `maatify/php-category` and does not own a Product-to-Category relation. The Catalog/Host integration layer owns the mapping between `category_id` and `product_id`. Per `DEC-006`, package-wide `Product.display_order` is excluded from Product V1. When category-product mapping is implemented, ordering is persisted on the mapping relationship scoped by `category_id`, using the stable `maatify/php-persistence` scoped-ordering API where applicable. Consuming applications may select different read/presentation sort orders without treating that as Product-owned persistent ordering. A new integration package is not introduced solely by assumption; consuming applications may own this integration directly until a demonstrated cross-project contract warrants a reusable package.

The package contracts are deliberately aligned where the same concepts cross package boundaries:

- **Media Asset identity:** Product and Category use the same positive integer Media Asset ID contract; each package owns its own target-to-asset assignment table and internal role registry. Neither package adds an external Media FK or calls the other package.
- **Localized content:** Product/Option/Option Value content uses nullable `language_code` with `NULL` as exact unlocalized content, a maximum length of 16 for non-NULL language codes, no implicit fallback, and Host-owned semantic locale policy, matching Category's content contract.
- **Status/lifecycle:** `active|inactive` status remains distinct from `deleted_at`; normal mutations use soft deletion, do not cascade child state, and restore the original identity only after revalidating invariants.
- **Timestamps and ordering:** Product uses the Host-supplied `ClockInterface`, does not normalize timezone independently from the Host clock, and does not use DB timestamp defaults. Positive scoped `display_order` values are assigned through `maatify/php-persistence` ordering capabilities where applicable. The Host supplies locale, presentation, and semantic Media/Platform policy.

These alignments are package-contract compatibility decisions, not runtime dependencies or cross-package foreign keys. The Product Image Role registry is independent of Category's similarly named registry because each package owns roles for its own assignment target and lifecycle.

The Product package does not introduce a first-class Catalog/Tenant/Store entity in V1. The V1 deployment contract is one Product identity namespace per deployed set of Product tables. Multiple isolated Catalogs sharing the same Product tables and reusing Product codes are explicitly unsupported in V1. Do not invent `catalog_id`, tenant, store, or channel scope during implementation.

No cross-module foreign keys or joins are allowed to Category, Pricing, Inventory, Supplier, Orders, Customer, or Media Asset storage. Internal foreign keys among Product-owned tables use `ON DELETE RESTRICT` and `ON UPDATE RESTRICT`.

## 11. V1 Persistence Outline

The following is the approved schema contract that implementation must preserve. This document specifies it only; it does NOT add executable SQL DDL, schema files, or database behavior. The standards applicability re-evaluation required before implementation was completed on 2026-10-12 and recorded in `STANDARDS_MANIFEST.md` under `DEC-007`; this document does not itself alter the manifest. All applicable conditional requirements of the Package Building Standard (including physical DDL structure, comments, naming, indexes, transactions, and executable constraints) must be strictly satisfied by the implementation.

| Table | Owns | Identity and required invariants |
|---|---|---|
| `maa_product_products` | Product identity and lifecycle | `id BIGINT UNSIGNED` PK; `code VARCHAR(100)` unique/immutable across all history; exact `active|inactive`; application-managed timestamps; no slug |
| `maa_product_product_contents` | Product name and descriptions | `id` PK; `product_id`; nullable `language_code VARCHAR(16)`; generated NULL-safe language identity; one row per Product/language including NULL and deleted history; `name VARCHAR(255)` non-empty; optional `short_description VARCHAR(500)` and `description TEXT` |
| `maa_product_options` | Variant-defining Option identity | `id` PK; internal `product_id`; stable `code VARCHAR(100)` unique within Product across deleted history; exact status; positive order; unique `(id, product_id)` candidate key for composite FKs |
| `maa_product_option_contents` | Option labels | `id` PK; `option_id`; nullable language and generated identity; one row per Option/language including NULL and deleted history; non-empty name/label |
| `maa_product_option_values` | Option Value identity | `id` PK; internal `option_id`; stable `code VARCHAR(100)` unique within Option across deleted history; exact status; positive order; unique `(id, option_id)` candidate key for composite FKs |
| `maa_product_option_value_contents` | Option Value labels | `id` PK; `option_value_id`; nullable language and generated identity; one row per Value/language including NULL and deleted history; non-empty name/label |
| `maa_product_variants` | Sellable-unit identity | `id` PK; internal `product_id`; immutable `composition_fingerprint CHAR(64)` unique with Product across all history; nullable `sku` and `barcode` strings up to 128 characters, each unique while assigned; exact status; positive order; unique `(id, product_id)` candidate key for composite FKs |
| `maa_product_variant_option_values` | Immutable composition pairs | `id` PK; `product_id`, `variant_id`, `option_id`, `option_value_id`; unique `(variant_id, option_id)`; composite FK `(variant_id, product_id)` to `maa_product_variants(id, product_id)`; composite FK `(option_id, product_id)` to `maa_product_options(id, product_id)`; composite FK `(option_value_id, option_id)` to `maa_product_option_values(id, option_id)`; composition rows have no independent update/delete lifecycle |
| `maa_product_image_roles` | Product-owned role registry | `id` PK; `role_key VARCHAR(100)` unique/immutable across deleted history under `utf8mb4_unicode_ci`; exact status; separate namespace from Category roles |
| `maa_product_image_assignments` | Product/Variant media links | `id` PK; `product_id` (independent FK to `maa_product_products(id)` RESTRICT); nullable `variant_id`; composite FK `(variant_id, product_id)` to `maa_product_variants(id, product_id)` RESTRICT when `variant_id` is non-NULL (`variant_id = NULL` represents Product-level target only); positive external `media_asset_id BIGINT UNSIGNED` (no external FK); nullable exact role/language/platform scope; internal FK to Product Image Role when `role_id` is non-NULL; generated NULL-safe identity/ordering/primary keys; target/asset/scope identity unique across deleted history; at most one active default per exact target scope |

### 11.1 Required relational integrity

- Every physical table has `PRIMARY KEY (id)`, suitable indexes and meaningful column `COMMENT`s as required by the Package Building Standard. SQL assets live under the package-root `schema/`; provide `schema/README.md`. Use table prefix `maa_product_`. Product/Option/Option Value codes and role keys must reject empty or whitespace-only values through application validation plus compatible DDL checks; optional SKU/barcode values must be `NULL` or non-empty strings.
- Internal foreign keys use `ON DELETE RESTRICT` and `ON UPDATE RESTRICT`; no automatic cascade. No FK or JOIN crosses into Category, Pricing, Inventory, Supplier, Orders, Customer/Account Type, or Media Storage.
- A soft-deleted Image Assignment is never the default: `CHECK (deleted_at IS NULL OR is_default = 0)` on `maa_product_image_assignments` is the final database guard of that rule.
- The schema defines no triggers and no routines. Identity stability (§4.1) is an application contract: no package write path updates an identity column and no service method replaces one. The database does not stop an `UPDATE` of an identity column issued outside the package; such writes are the Host's responsibility, and identity corrections use an explicit migration or replacement process.
- Explicit candidate keys and composite relational integrity:
  - `maa_product_variants` defines candidate key `UNIQUE (id, product_id)` to serve as the referenced target for Variant-to-Product composite relationships.
  - `maa_product_options` defines candidate key `UNIQUE (id, product_id)` as the explicit composite candidate/reference key for an Option within a Product.
  - `maa_product_option_values` defines candidate key `UNIQUE (id, option_id)` as the explicit composite candidate/reference key for an Option Value within an Option.
  - `maa_product_variant_option_values` MUST carry `product_id` and enforce three explicit composite foreign keys with `ON DELETE RESTRICT` and `ON UPDATE RESTRICT`:
    1. `(variant_id, product_id)` references `maa_product_variants(id, product_id)`, proving the Variant belongs to the Product.
    2. `(option_id, product_id)` references `maa_product_options(id, product_id)`, proving the Option belongs to the same Product.
    3. `(option_value_id, option_id)` references `maa_product_option_values(id, option_id)`, proving the Option Value belongs to the referenced Option.
  - The existence of a `PRIMARY KEY (id)` on each table alone is structurally insufficient to enforce aggregate consistency across related child rows and does NOT replace the composite candidate and reference keys specified in this contract. Aggregate relational integrity MUST be enforced at the database schema level; the package MUST NOT rely solely on application-level PHP validation or prior read-before-write checks.
- `maa_product_image_assignments` relational integrity:
  - `product_id` MUST have an independent foreign key referencing `maa_product_products(id)` with `ON DELETE RESTRICT` and `ON UPDATE RESTRICT`. This guarantees that every assignment row—whether Product-level or Variant-level—is strictly bound to a valid, existing Product identity in the database. A Product-level assignment for a non-existent Product is rejected at the database level.
  - When `variant_id` is non-NULL, a composite foreign key on `(variant_id, product_id)` referencing `maa_product_variants(id, product_id)` with `ON DELETE RESTRICT` and `ON UPDATE RESTRICT` proves that the referenced Variant belongs to the exact same Product. Assigning a Variant belonging to a different Product is rejected by the database.
  - Setting `variant_id = NULL` is valid exclusively to represent a Product-level target; it never exempts the assignment row from valid Product identity, which remains guaranteed by the independent `product_id` foreign key.
  - Media Asset constraints, Product Image Role reference, exact scope dimensions, historical immutable identity, and single active default uniqueness continue unchanged.
- Composition fingerprint is `SHA-256` of the canonical UTF-8 string defined in §5.3, stored as lowercase 64-character hexadecimal. Enforce `UNIQUE(product_id, composition_fingerprint)` across all Variant history; do not include `deleted_at` in this key. A digest match must be confirmed against actual canonical composition pairs before treating it as a duplicate.
- Product code, Option code, Option Value code, image role key, and image assignment logical identity remain reserved across soft deletion. Do not add `deleted_at` into these unique identity keys. Add explicit required/non-empty checks and API validation to code/role identifiers; never treat whitespace-only identifiers as valid. Current SKU/barcode values remain reserved while assigned; each is `NULL` or a non-empty string, and clearing/replacing an identifier releases its previous value.

### 11.2 Translation/Content identity: Category comparison

The three Product Content tables deliberately reuse the Category schema pattern:

1. `language_code VARCHAR(16) NULL`; `NULL` is one unlocalized Content identity, not a request to fall back from another language.
2. Reject empty/whitespace-only non-NULL language codes; semantic language support and fallback policy remain Host-owned.
3. Generate a stored `language_code_identity` using the equivalent of `COALESCE(language_code, '')`; enforce unique `(owner_id, language_code_identity)` without `deleted_at`, so MySQL nullable uniqueness cannot create duplicate NULL rows or revive a deleted identity. Empty non-NULL codes are rejected so they cannot collide with the NULL sentinel.
4. Use the same `utf8mb4`/`utf8mb4_unicode_ci` storage comparison behavior as Category. Case variants of a language code compare under that collation; canonical language normalization remains Host-owned.
5. Read queries are exact. A request for `en` does not silently return `NULL` content or another language; a caller must explicitly choose any fallback chain.
6. Content identity `(owner_id, language_code)` is immutable. Updating content fields does not reassign a record to a different owner or language; remove/re-add requires explicit restore of the original identity.

The Content tables use their domain fields (`name`, optional descriptions, or labels) rather than Category's single name/description shape. This is a deliberate Product-specific difference; language identity and lifecycle semantics remain aligned. Application validation MUST reject empty or whitespace-only names/labels; add compatible database checks against empty/space-only values as a second line of defense, while not pretending MySQL `TRIM()` validates every Unicode whitespace code point.

### 11.3 Schema defaults, status casing, and timestamps

- Runtime baseline: PHP `>=8.4.27 <8.5.0 || >=8.5.12 <8.6.0`, `ext-pdo`, `ext-pdo_mysql`, and `ext-mbstring`, matching the current Category/runtime ecosystem where these requirements are actually used. Composer constraints and shared-package minimum versions follow the exact public APIs the package uses; no extension is declared merely for symmetry.
- InnoDB, `utf8mb4`, and the approved package collation; `BIGINT UNSIGNED` package-owned IDs and Media Asset IDs; MySQL `8.0.16+` with PDO MySQL is the only claimed database runtime for V1 because enforced `CHECK` constraints are part of the contract.
- All `status` constraints must reject uppercase or mixed-case variants in the database itself (e.g. `CHECK (BINARY status IN (BINARY 'active', BINARY 'inactive'))`); relying on a case-insensitive table collation is invalid. This is intentionally stricter than the current Category taxonomy status check; Category Image Role uses exact binary status enforcement.
- `created_at`, `updated_at`, and `deleted_at` are assigned by the application from the Host-injected `ClockInterface`. Do not add `DEFAULT CURRENT_TIMESTAMP`, `ON UPDATE CURRENT_TIMESTAMP`, or package-owned timezone normalization. Document the public timestamp representation explicitly and preserve the injected Host Clock semantics; do not claim UTC normalization when none is performed.
- For entities and relationships with persistent ordering (`maa_product_options`, `maa_product_option_values`, `maa_product_variants`, and `maa_product_image_assignments`), `display_order` is positive and managed by the shared `maatify/php-persistence` Ordering API where its public API applies. Do not accept `display_order` in Create/Update Commands; use a separate typed ordering command. Lists end with deterministic `display_order, id`. Restoring a soft-deleted ordered row places it at the end of its live scope: its position is the shared Ordering API's next position, computed under the operation's locks and written in the same operation that clears `deleted_at`; soft deletion does not compact positions. Per `DEC-006`, package-wide `display_order` is excluded from `Product` itself.
- Runtime hard delete is not used for normal lifecycle operations. Content, role, and assignment soft deletion and restore use application-managed timestamps. The immutable composition relation has no independent soft-delete/update API; its lifecycle follows the Variant.
- Validate `media_asset_id` using the same canonical positive integer boundary as Category: integer or canonical decimal string where permitted by the Category-compatible contract, rejecting zero, negatives, signs, whitespace, leading-zero/non-canonical strings, malformed values, and values outside PHP's supported integer range before casting. Persist as `BIGINT UNSIGNED`; never create an external Media FK.
- Use direct PDO, not an ORM or external query builder. Database/runtime behavior must follow the current pinned Package Building Standard and real MySQL integration tests.

### 11.4 Nullable scope keys and default uniqueness

For image assignments, ordinary MySQL UNIQUE keys containing NULL do not guarantee the required identity. Provide stored generated identity columns for nullable `variant_id`, `role_id`, `language_code`, and `platform` (using reserved sentinels that CHECK constraints make impossible as real values), and use them in the target/asset/scope UNIQUE key. The identity key excludes `deleted_at` and remains stable across history.

Generate deterministic exact-scope keys with unambiguous, length-prefixed encoding for the target kind/identity, role, language, and platform. The scope must distinguish Product-level target from Variant-level target and distinguish NULL from every accepted non-NULL value. Use that same scope contract for ordering and default uniqueness.

A generated conditional `default_scope_identity` participates in a UNIQUE key only when `is_default = 1 AND deleted_at IS NULL`; otherwise it is NULL. `is_default` is `0|1` only. Setting a new default clears the previous default in that exact target scope and sets the requested row in the same transaction. The DB unique key is the final concurrency defense; transaction locking is also mandatory. Clearing/soft-deleting a default never auto-promotes another row; restore leaves it non-default. Restore-as-default is a separate explicit operation (`restoreAsDefault`) and conflicts if that exact scope already has a live default. Setting a live assignment as default is the separate explicit set-default operation, which replaces any current default atomically.

### 11.5 Index requirements

Review actual queries and add, at minimum, indexes for:

- Content exact lookup and NULL-safe uniqueness for each owning ID and language identity;
- Options per Product and Values per Option: owner/status/deleted/order/id;
- Variants per Product: `product_id/status/deleted_at/display_order/id` and unique `(product_id, composition_fingerprint)`;
- Composition joins by `(variant_id, option_id)` and by Value/Option where queried;
- Image roles by status/deleted/key/id and unique immutable `role_key`;
- Image assignment target listing, stable target/asset/scope identity, exact ordering scope, and conditional default-scope uniqueness.

Do not add unused indexes merely because they appear in this outline; prove indexes against actual query plans and all restore/concurrency invariants.

### 11.6 Aggregate locking and transaction boundaries

Every Product mutation that can affect Product content validity, Option/Value activation, Variant composition/status, or configuration must serialize on the Product row (or a formally equivalent package-owned aggregate lock) before checking state and applying writes. This protects postconditions from concurrent operations that would each pass a read-before-write check but collectively break the Product. Use the approved shared transaction/locking contract where applicable.

Image default replacement locks the owning Product/Variant target scope and uses the database unique key as the final guard. Use a stable lock order across Product, Variant, Role, and assignment rows to reduce deadlocks; retry only where the canonical transaction contract explicitly permits it. All multi-table Product mutations are atomic locally, and the package must not commit or roll back an outer transaction it does not own.

## 12. Query Semantics

- Management reads can explicitly filter status and deleted state. Consumer reads cannot request deleted records or bypass inactive Product/Variant/Option visibility rules.
- Every unpaginated consumer or management list is bounded to at most 100 records. Large management lists use the shared `maatify/php-persistence` pagination API (`PageRequest`/`PageResultDTO`) when applicable; do not implement a package-local competing pagination engine.
- All `display_order` positions are positive and managed through the shared `maatify/php-persistence` Ordering API where applicable. Ordering is scope-specific and deterministic, ending with a stable unique key (`display_order, id`).
- Content reads are exact and strictly differentiate between three distinct query intentions in the typed criteria contract:
  1. **Exact-language query:** Explicitly requests content for a specific non-NULL `language_code` (e.g. `'ar'`). Returns only content records for that exact language. If none exists, returns no match; never automatically falls back to unlocalized (`NULL`) content or another language.
  2. **Exact unlocalized query:** Explicitly requests unlocalized content (`language_code IS NULL`). Returns only the unlocalized content record. If none exists, never falls back to any localized record.
  3. **Unfiltered management list query:** Management queries can list all content records for an entity across all languages (displaying both localized records and unlocalized records) without filtering by language.
  - **No parameter conflation:** The absence of a language filter (requesting all languages in management listings) and passing `NULL` (requesting specifically unlocalized content) MUST NOT be conflated as conflicting meanings of the same parameter. The typed query contract must model these as distinct, mutually exclusive selectors (for example, explicit criteria methods `withExactLanguage(string $code)`, `withUnlocalizedOnly()`, and `withAllLanguages()` or omitting the language filter on management criteria).
  - The Host Application manages semantic language validation, locale negotiation, and fallback policy. The Product package performs no implicit fallback across languages or to `NULL`.
- Consumer Variant lists contain only structurally valid, active, non-deleted Variants of an active, non-deleted Product. Every selectable active Option Value must be represented by at least one active valid Variant.
- **Variant composition resolution query contract:** Resolving shopper selections to a concrete Variant ID is owned by a Product-provided typed read query (§5.5):
  - Accepts a Product identifier and a typed selection composition contract (e.g. `VariantCompositionCriteria`), never a raw associative array.
  - Matching is order-independent: the sequence of Option/Value pairs within the request contract does not affect the resolution result.
  - Rejects duplicate option references, cross-Product options/values, option-value mismatches, and inactive or soft-deleted options/values.
  - For configurable Products, requires complete exact coverage of the active Option set: exactly one active Value for every active Option, with no missing or extra options.
  - The query is strictly read-only: it never creates Variants, never auto-reactivates historical records, and never invents an implicit default.
  - If no matching active, valid Variant exists, the query fails closed with a documented typed result or throws a documented typed domain exception conforming to `maatify/exceptions`.
  - For simple Products, empty composition is the internal identity resolution mechanism, returning the single active empty-composition Variant when active and viewable.
  - The Host Application owns storefront UI controls, facet navigation, user input collection, and presentation flow; the Product package owns verifying whether the selected composition constitutes an existing, active, structurally valid Variant.
- Image assignment consumer reads require exact target/role/language/platform scope and hide assignments whose Product, target Variant, or Role is inactive/deleted. Product queries do not claim “in stock” or “sellable” because they do not query Inventory or Pricing.

## 13. Public API and Package Topology

Use the canonical Package Building topology rules: `Domain → Capability → Responsibility`, choosing only responsibilities that actually exist. `php-product` is one domain; Product, Variant, Option, Option Value, Image Role, and Image Assignment may be separate capabilities if the public contract and implementation justify the split. Do not create empty or ceremonial layers.

Management and consumption are distinct use cases, so each capability exposes a separate Management contract and Consumer contract inside the Capability (Package Building §5 and §15): `{Capability}/Management/{Command,Criteria,Service}/` and `{Capability}/Consumer/{Criteria,Service}/`. Types shared by both sides (result DTOs, enums, exceptions, the repository, and value objects) stay at the Capability level. A capability without a consumer read, such as Image Role, has a Management side only. The factory constructs each side separately (`create{X}ManagementService` and `create{X}ConsumerService`).

The public API should expose typed commands, criteria, DTOs/results, enums/value objects, repository/service contracts, PDO adapters, and a thin factory/facade where it improves consumer discovery. Every interface, enum, DTO, and exception must follow the naming suffix conventions in the active Package Building Standard. Public APIs must not expose SQL rows or associative arrays as domain contracts. Specifically:
- Variant composition resolution is exposed via a typed query interface (e.g. `ResolveVariantByCompositionQueryInterface`) accepting typed criteria and returning a typed result or domain exception, never accepting raw arrays.
- Content queries expose explicit criteria selectors separating exact language, unlocalized only, and unfiltered all-languages listing.

Use ecosystem shared packages rather than package-local duplicates when required by the active standards, including:

- `maatify/exceptions` for owned exceptions, using the approved hierarchy.
- `maatify/shared-common` for `ClockInterface` when the package requires a clock.
- `maatify/php-persistence` for supported pagination/order-management/transaction capabilities where its public API is applicable.

The Operational Read / Reporting classification required by Package Building Standard §15 (In Scope) and its read-contract boundaries are recorded in the root `PRODUCT_PACKAGE_REFERENCE.md`. Exact public class/method inventory must be documented in that same file; this architecture document under `docs/architecture/` supports that reference and must not become a competing Package Reference.

## 14. Transaction and Replacement Boundaries

All multi-table Product mutations are atomic locally. Cross-domain orchestration is owned by the Host/Catalog coordinator. The package must cooperate with the approved shared transaction contract and must not commit an outer transaction owned by the Host. Mutations that can affect aggregate invariants acquire the Product aggregate lock before reading the current configuration; image-assignment default operations serialize on the owning target/scope and use DB uniqueness as a final guard.

Variant replacement is a cross-domain risk boundary because external Pricing, Inventory, Supplier, and order records may reference existing Variant IDs. The Product package owns local composition validity and produces deterministic old/new identities. The Host/Catalog coordinator owns the whole workflow, including whether external records are transferred, recreated, preserved, or left unavailable.

A replacement flow must fail closed if the complete replacement set is invalid. It must not leave an active Product with zero active valid Variants. It must not silently copy external state from old Variants to new Variants.

## 15. Sellability Is an Aggregated Result

Product V1 does not expose a single persisted `is_sellable` value. The Catalog/Host layer derives sellability for a specific request context from at least:

1. Product and selected Variant are structurally valid, active, and non-deleted.
2. Pricing returns an applicable price for the selected Variant, pricing context, and currency.
3. The selected sales channel / fulfilment path can fulfil the item; when that path uses Inventory, its inventory rules apply; when it uses an external digital provider, that provider's availability/fulfilment rules apply.

The Host Application maps any customer classification or pricing policy it owns to the appropriate Pricing context. That mapping does not change Product or Variant identity.

## 16. Compatibility and Deliberate Changes from Historical Catalog V1

The following historical decisions are not carried forward as Product package requirements:

- `product_id` is no longer the required direct Pricing subject; Pricing resolves a Variant.
- Option Value price adjustments are not an intrinsic Product/Option behavior.
- Every Variant is not required to have a local Inventory row.
- Product activation does not require a Base Price or Inventory row.
- `force_out_of_stock` is not a Product property; availability/fulfilment policy belongs outside Product.
- Product-to-Category mapping remains outside the Product package.
- The historical default-selection field on Variant/Product (`is_default` in the Catalog V1 schema) is omitted in favor of derived simple/configurable selection behavior. This is unrelated to the `is_default` marker of Image Assignments (§9.4).

These changes require the Catalog umbrella document and the Pricing integration contract to be reconciled. Do not implement the old pricing equation or mandatory inventory coupling merely because they appear in the historical PDF.

## 17. Verification Requirements

Implementation acceptance must include, at minimum:

### Product and lifecycle
- Create simple Product automatically creates exactly one empty-composition Variant.
- Product identity is stable and cannot be reused after soft deletion; status mutations accept only exact lowercase `active`/`inactive` and the database constraints reject other casing.
- Product activation fails when content or a valid active Variant is missing.
- Removing the last usable Product name, disabling/deleting the last valid active Variant, and any mutation that would leave an active Product structurally invalid are rejected unless part of an atomic valid reconfiguration.
- Product deactivation/deletion does not silently mutate child states.
- Restore preserves saved status, reuses the same identities, and rejects invalid restoration; inactive restore never auto-activates.
- Simple → configurable → simple transitions deactivate Options before activating the original empty-composition Variant, reuse its identity, and do not create duplicates; Host cross-domain reconciliation is explicit before reactivation.

### Variant and Options
- A Variant cannot use an Option Value from another Product or another Option.
- Active configurable Variants have exact active-Option coverage: no missing Option, no extra inactive/deleted Option, and each referenced Value belongs to the right active Option/Product.
- Active Options have at least one active Value; every active Value under an active Option is represented by an active valid Variant; every active Option and Value has a non-empty label in at least one non-deleted unlocalized or localized Content record.
- Duplicate compositions are rejected across active, inactive, and soft-deleted history, including concurrent attempts; matching history requires explicit restore/reuse rather than creating a second identity.
- Canonical composition fingerprint uses the documented V1 algorithm, with actual-pair comparison and fail-closed handling of an impossible digest collision.
- The implementation does not auto-generate the Cartesian product of Option Values.
- Composition cannot be updated in place.
- Option/Value lifecycle mutations that invalidate active variants are rejected unless done through an approved atomic reconfiguration.
- No implicit domain default exists for configurable Products; Host-owned selection is resolved to a concrete Variant before order creation.
- Configurable Variant composition resolution query matches active Variants using typed selection criteria regardless of option/value pair order in the request.
- The resolution query strictly rejects requests containing duplicate options, options or values belonging to a different Product, option-value mismatches, or inactive/soft-deleted options or values.
- The resolution query rejects requests with partial option coverage (missing active options) or extra options for configurable products.
- The resolution query fails closed with documented typed errors or results when no matching active Variant exists; it never creates variants, auto-reactivates historical records, or invents defaults.
- The resolution query resolves simple Products via an empty composition request to the single active empty-composition Variant when the Product and Variant are viewable under consumer rules.
- Public domain contracts expose typed selection criteria and results, never raw associative arrays.
- SKU/barcode can be explicitly corrected/replaced with uniqueness validation; Variant ID is unchanged and remains canonical.

### Boundaries
- No Product dependency or schema FK exists for Pricing, Inventory, Supplier, Customer Account Type, Category, Order, or Media storage.
- No price, quantity, account-type, supplier credential, or physical storage field is placed in Product-owned tables.
- Product remains valid when Pricing or Inventory data does not exist; Catalog sellability is tested separately in integration tests.
- Product Image Roles and assignments match the role/lifecycle/exact-scope model described in §9; external `media_asset_id` uses the same positive integer contract as `maatify/php-category`, without a cross-package FK. Assignment uniqueness, atomic default replacement, positive ordering, restore conflicts, and concurrency are verified against real MySQL.
- Verify Image Assignments relational integrity at the database layer:
  - Database rejects Product-level image assignment (`variant_id = NULL`) referencing a non-existent `product_id` via the independent foreign key.
  - Database rejects Variant-level image assignment referencing a `variant_id` belonging to a different Product via the composite foreign key `(variant_id, product_id)`.
  - Database rejects Variant-level image assignment referencing a non-existent `variant_id`.
- Verify Product/Option/Option Value Content query and lifecycle semantics:
  - Exact-language queries return only records matching that specific non-NULL language code, with no fallback to `NULL` or another language.
  - Exact unlocalized queries return only records where `language_code IS NULL`, with no fallback to localized records.
  - Unfiltered management list queries return all records across all languages (both localized and unlocalized).
  - Typed criteria contract cleanly separates omitted language filter (all languages) from explicit `NULL` (unlocalized only).
  - Database rejects duplicate unlocalized (`NULL`) records for the same owner entity via generated NULL-safe identity column.
  - Database and application reject re-creating a content identity (including `NULL`) that exists in soft-deleted history, requiring explicit restore instead.
  - Rejection of empty or whitespace-only language codes; Host owns semantic language validation and fallback policy.
- Verify Host `ClockInterface` timestamp behavior and shared `maatify/php-persistence` ordering/pagination behavior; no product-local duplicate Ordering/Pagination engine.
- Verify Product and Category use the same Host Media Asset ID value when assigning the same asset, and never reuse each other's role IDs.
- Verify composite FKs and candidate keys reject invalid relational graphs at the database layer:
  - Candidate keys exist: `UNIQUE (id, product_id)` on `maa_product_variants`, `UNIQUE (id, product_id)` on `maa_product_options`, and `UNIQUE (id, option_id)` on `maa_product_option_values`.
  - Composite FKs in `maa_product_variant_option_values` reject cross-Product Variant/Option combinations and mismatched Option Values; PK on `id` alone does not replace these composite FKs.
- Use real concurrent MySQL transactions to prove Product aggregate locking prevents invalid state races, default replacement remains single-default, and duplicate historical identity/composition attempts fail deterministically.

### Package quality
- `composer validate --strict` and optimized autoload validation pass.
- PHPStan uses the applicable strict project level and passes.
- `php -l` passes for owned PHP files.
- Unit and MySQL integration tests cover all domain invariants, uniqueness, concurrency-sensitive operations, deletion/restore, and transactions.
- Consumer Verification Harness validates clean Composer installation, autoloading, declared platform requirements, schema installation, and host isolation at the exact candidate SHA.
- CI and documentation are reviewed under the currently pinned manifest, with no unapproved exceptions or accidental adoption changes.

## 18. Required Cross-Domain Follow-Up Before Catalog Integration

Before claiming Catalog V1 is fully integrated, the Owner/Lead must review and reconcile:

1. `php-pricing` public contract: Variant-addressed price lookup and the generic pricing-context/Price List model.
2. Host mapping: a customer-classification identifier (for example, `account_type_id`) → generic Pricing context, owned by the Host.
3. `php-inventory` contract: optional quantity tracking keyed by Variant identity; no requirement for items fulfilled without tracked stock.
4. Catalog coordinator replacement flow: atomic local Product replacement plus safe cross-domain coordination; no implicit transfer of prices or inventory.
5. Catalog sellability rule: Product validity + applicable price + fulfilment/availability, without storing cross-domain state inside Product.
6. V1's one Product identity namespace per deployed set of Product tables is an explicit limit. If a single Pricing/Inventory store will accept Variant IDs from multiple independent Product table sets, the integration contract must include a collision-free source namespace owned by the Host/Catalog/Pricing boundary; this is not encoded inside Product V1 and must be resolved before that multi-source integration. Do not invent tenant/Catalog columns locally.
7. The Host/Media adapter must supply the same positive integer Media Asset identity used by `maatify/php-category`; Product stores it as `BIGINT UNSIGNED` with no external FK. A future UUID/string media identity needs a coordinated contract change, not a Product-only conversion.
8. Product-owned image-role identities and Category-owned image-role identities are separate; cross-use of role IDs is prohibited. Product image-assignment exact scope includes role, nullable language, and nullable platform, with NULL treated as an exact value and no implicit fallback.

These are integration decisions, not reasons to put foreign-domain ownership inside `php-product`.

## 19. Resolved V1 Decisions and Implementation Stop Conditions

The following decisions are the approved Product-local contract (`DEC-007`). These decisions MUST NOT be silently reopened by the Executor:

- Product owns Product, Variant, variant-defining Options/Values, optionally localized Content, Product Image Roles, and Product/Variant image assignments only.
- Product configurations remain structurally valid on every committed mutation, independent of Product visibility status; active Option Values under active Options are represented by at least one active valid Variant.
- Product/Option/Option Value Content uses exact nullable-language identity and the Category-compatible NULL-safe uniqueness pattern; language fallback is always Host-owned.
- Composite FKs enforce same-Product and same-Option composition; a set of independent single-column FKs is insufficient.
- Aggregate mutations serialize through a Product lock; default image-assignment mutations use target-scope locking and DB uniqueness.
- Every Product receives one empty-composition Variant at creation; Product starts inactive. Reconfiguration is a dedicated atomic Product-local operation.
- Active Variant composition exactly equals the set of active Product Options, with one active Value per Option and no extra values.
- Canonical V1 composition fingerprint and uniqueness across all history are defined in §5.3.
- Variant ID is canonical for sellable-unit integration. SKU/barcode are optional mutable secondary identifiers.
- Product has no package-owned slug, no product pricing, no inventory state/row requirement, no supplier data, and no account-type field.
- V1 has one Product identity namespace per deployed set of Product tables; no multi-Catalog/tenant abstraction is included.
- Media Asset references use the shared positive integer ID contract documented in §9.1 and approved under [`DEC-005`](../decisions/DEC-005-shared-media-asset-identity.md), aligned with `maatify/php-category`; Product Image Role IDs remain Product-owned and are never Category Role IDs.

The Executor MUST stop and report a blocker rather than inventing a different public contract if: an applicable canonical engineering standard conflicts with a resolved decision; implementation of the published shared transaction contract is impossible; a MySQL invariant cannot be enforced safely at the declared supported database version; or the actual repository baseline/runtime materially differs from assumptions in the execution brief. Cross-domain integration follow-ups in §18 do not block implementation of Product-local behavior, but they do block any claim that the full Catalog is integrated.

## 20. Owner Decision Gates Before Implementation

The following are not merely implementation details. The Owner MUST explicitly approve or resolve them before the Executor starts Product implementation:

1. **Pricing subject redesign — Approved by Owner (`DEC-001`):** Variant-Addressed Pricing Boundary has been formally approved by the Owner on 2026-10-11 in [`DEC-001`](../decisions/DEC-001-product-variant-pricing.md). The approved decision is:
   > تعتمد Product V1 الـVariant بوصفه وحدة التسعير المرجعية لكل وحدة قابلة للبيع. لا تملك Product Library أسعارًا أو معادلات لتعديلات الأسعار. تتولى مكتبة Pricing المستقلة تعريف الأسعار وقوائمها وسياقاتها وسياسات حلّ السعر المناسب للـVariant. ولا يحدث fallback تلقائي إلى سعر Product عند غياب سعر الـVariant.

   This replaces the previous recommendation with the approved decision. Reconciling the `php-pricing` contract and Catalog umbrella rules remains required before integrated sellability can be accepted; Product implementation alone cannot claim Catalog sellability integration.
2. **Inventory cardinality redesign — Approved by Owner (`DEC-002`):** Optional Inventory Tracking Boundary has been formally approved by the Owner on 2026-10-11 in [`DEC-002`](../decisions/DEC-002-optional-inventory-tracking.md). The approved decision is:
   > تعتمد Product V1 عدم امتلاك بيانات المخزون أو التوافر، وعدم اشتراط وجود سجل Inventory لكل Variant. ويُسمح بوحدات البيع التي لا تحتاج إلى تتبّع كمية بالعمل دون سجل مخزون محلي، مع بقاء مسؤولية إدارة المخزون والتوافر ضمن النطاق المستقل المختص.

   This replaces the previous recommendation with the approved decision. Sellable units that do not require quantity tracking may operate without a local inventory record, while inventory and availability management remain within the independent specialized domain. Approving the changed Catalog orchestration contract remains a separate integration milestone.
3. **Single namespace limitation — Approved by Owner (`DEC-003`):** Product Identity Namespace Boundary has been formally approved by the Owner on 2026-10-11 in [`DEC-003`](../decisions/DEC-003-product-identity-namespace.md). The approved decision is:
   > تعتمد Product V1 مساحة هوية واحدة لكل مجموعة جداول Product منشورة. يكون `product_id` هوية المنتج الداخلية المرجعية، ويكون `Product.code` فريدًا داخل مساحة الهوية هذه.
   >
   > لا تنشئ Product V1 كيانًا مستقلًا باسم Catalog، ولا تضيف `catalog_id` أو `tenant_id` إلى نموذج المنتج لمجرد احتمال الاحتياج المستقبلي. ولا تدعم V1 مساحات هوية مستقلة متعددة للمنتجات داخل مجموعة الجداول نفسها.
   >
   > تظل علاقة المنتج بالأقسام، وإتاحة المنتجات والأقسام حسب الواجهة أو الجمهور، وطريقة تنظيم العرض مسؤوليات خارج المكتبتين الأساسيتين Product وCategory. ويمكن للتطبيق المستهلك إضافة هذه القدرات عند الحاجة، دون فرض إعداداتها على التطبيقات التي تستخدم النموذج الأساسي البسيط.
   >
   > إذا ظهر مستقبلًا احتياج إلى مساحات هوية مستقلة تشترك في مجموعة الجداول نفسها، فيلزم اتخاذ قرار معماري منسق قبل تغيير مخطط البيانات أو الادعاء بدعم هذا السيناريو.

   This replaces the previous recommendation with the approved decision. V1 assumes one Product identity namespace per deployed set of Product tables and does not speculatively add `catalog_id`, tenant, or store scope columns. Relations to categories, multi-interface or audience availability, and presentation organization remain outside the core Product and Category libraries. If an independent multi-namespace shared-table requirement emerges in the future, a coordinated architectural decision is required prior to changing schema or claiming support.
4. **Content scoping — Approved by Owner (`DEC-004`):** Language-Only Product Content Scope has been formally approved by the Owner on 2026-10-11 in [`DEC-004`](../decisions/DEC-004-language-only-product-content-scope.md). The approved decision is:
   > يعتمد محتوى Product وOption وOption Value في V1 هوية قائمة على اللغة فقط، مع دعم المحتوى غير المترجم عبر language_code = NULL، ودون fallback تلقائي. ولا يدعم المحتوى الأساسي اختلاف النص حسب الواجهة أو القناة في V1. ويظل أي احتياج لمحتوى خاص بالمنصة أو الواجهة مسؤولية امتداد مستقل أو قرار معماري لاحق.

   This replaces the previous recommendation with the approved decision. Core Product, Option, and Option Value content relies strictly on language-based identity, supporting unlocalized content via `language_code = NULL`, with no automatic language fallback. Core V1 content does not support textual divergence across interfaces, channels, or platforms. Any requirement for platform- or interface-specific content remains the responsibility of an independent extension or a future architectural decision, while preserving the distinction between core Content and optional extensions such as Content Fields without copying Category extensions into Product.
5. **Shared Media identity — Approved by Owner (`DEC-005`):** Shared Media Asset Identity Contract has been formally approved by the Owner on 2026-10-12 in [`DEC-005`](../decisions/DEC-005-shared-media-asset-identity.md). The approved decision is:
   > أعتمد في Product V1 استخدام عقد هوية Media Asset المتوافق مع Category: تكون `media_asset_id` هوية خارجية يملكها الـHost، عددية وموجبة، وتُخزّن كـ `BIGINT UNSIGNED` مع فرض شرط الإيجابية والتحقق من القيمة قبل تحويلها إلى نوع PHP المدعوم. تتوافق حدود الإدخال والإخراج مع العقد العام الحالي لـCategory، بما في ذلك قبول `int|string` عند الإدخال حيث يسمح العقد، وإخراج الهوية كعدد صحيح.
   >
   > لا تملك Product تخزين Media Asset أو دورة حياته، ولا تنشئ مفتاحًا أجنبيًا أو JOIN إلى مخزن الـMedia. وتظل هويات Product Media Roles منفصلة تمامًا عن هويات Category Image Roles.
   >
   > أي حاجة مستقبلية إلى UUID أو هوية نصية تتطلب قرارًا منسقًا يغيّر عقد الحزمتين، أو خريطة تحويل يملكها الـHost؛ ولا يجوز تغيير نوع الهوية في Product وحدها.

   This replaces the previous recommendation with the approved decision. `media_asset_id` is an external, Host-owned identity persisted as `BIGINT UNSIGNED > 0` with no foreign key or JOIN to Media Asset storage. Public PHP identity values are integers, and input normalization conforms to Category's `CategoryIdDTO` contract (accepting `int|string`, requiring positive values, and rejecting signs, whitespace, leading zeroes, decimal notation, scientific notation, malformed representations, and values exceeding `PHP_INT_MAX` before casting). Product owns neither media storage nor its upload, processing, URL resolution, or lifecycle. Product Image Role IDs remain in a separate namespace from Category Image Role IDs. Any future UUID/string identity requires a coordinated decision changing both package contracts or an explicit Host-owned mapping. The quotation keeps the Owner's original wording ("Product Media Roles"); the capability is named Product Image Roles and Image Assignments (§9).
6. **`Product.display_order` retention or exclusion in V1 — Approved by Owner (`DEC-006`):** Product Ordering Boundary has been formally approved by the Owner on 2026-10-12 in [`DEC-006`](../decisions/DEC-006-product-ordering-boundary.md). The Owner's exact verbatim response was:
   > اعتمد

   in direct approval of the recommendation to exclude package-wide `Product.display_order` from Product V1, preserve Product's independence from Category with mapping remaining outside both core libraries, persist ordering on the mapping relationship scoped by `category_id` using the stable `maatify/php-persistence` scoped-ordering API where applicable, avoid introducing a new integration package solely by assumption, and allow consumers to select different read/presentation sorts without treating that as Product-owned persistent ordering.

   This replaces the previous recommendation with the approved decision. Package-wide `display_order` is excluded from `maa_product_products`, and its dedicated consumer-listing index is removed. Scoped ordering for other entities and relationships where ordering is meaningful (`maa_product_options`, `maa_product_option_values`, `maa_product_variants`, and `maa_product_image_assignments`) is preserved.

All six Owner Decision Gates (`DEC-001` through `DEC-006`) are formally resolved under Owner authority. On 2026-10-12 the Owner approved this document as the architecture contract and authorized the start of implementation within the local Product scope, bound by `DEC-001` through `DEC-006`, under [`DEC-007`](../decisions/DEC-007-product-v1-implementation-start-authorization.md). That authorization is subject to the completed standards applicability re-evaluation recorded in `STANDARDS_MANIFEST.md`, and it does not authorize merge, release, or publish.

## 21. Source Inputs and Authority

This architecture was prepared from the supplied Catalog documents and deliberately redesigned where a more reusable Product package contract is recommended. Current-source review evidence on 2026-10-11 includes `maatify/php-category` `CATEGORY_PACKAGE_REFERENCE.md` blob `c177e8957786220335692b9cdf78a5b3dad64dee`, `docs/USAGE_GUIDE.md` blob `e397014cf80a7d8fc49d3238d4c46501c8b72eec`, and `schema/category.sql` blob `8b8c0f552d0df5a2513046312d1da2de93c04019`. The Product repository's current `STANDARDS_MANIFEST.md` is blob `fb09995840c495645f821899e19631e38ed4fb32`; the current `PACKAGE_BUILDING_STANDARD.md` is blob `1475bb006eefe9e1ef9a6973f6664c3d0690dc2c`. The manifest, as it stood when this review was prepared, recorded that conditional SQL persistence requirements were not activated because the repository had no package-owned database behavior. After the Owner approved implementation, the formal applicability re-evaluation was completed on 2026-10-12 and the current manifest records those requirements as activated (`DEC-007`). This is the approved contract, not a released one. Its source inputs:

- `CATALOG_PACKAGE_ARCHITECTURE.md` — package-level composition, domain ownership, and cross-domain orchestration.
- `Catalog_V1_Architecture_Locked.pdf` — historical Product/Variant/Option model and original Catalog V1 schema.
- `CATALOG_V1_ARCHITECTURE.md` — Category-package boundary and Catalog integration status.
- Current `maatify/php-category` `CATEGORY_PACKAGE_REFERENCE.md`, current consumer usage contract, and `schema/category.sql` — actual reusable package contracts used to reconcile language identity, Media Asset ID type, status/deletion, Clock, ordering, and dependency boundaries.
- Current `Maatify-ltd/php-product` repository governance and pinned Composer Package standards.

This is the Owner-approved V1 architecture contract, not evidence of implementation, tests, release readiness, or a released contract. The `CANDIDATE` filename is kept so existing references stay valid.
