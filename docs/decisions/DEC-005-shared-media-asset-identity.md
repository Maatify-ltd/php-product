# DEC-005 — Shared Media Asset Identity Contract for Product V1

- **Decision ID:** `DEC-005`
- **Title:** Shared Media Asset Identity Contract for Product V1
- **Status:** `ACTIVE`
- **Date:** `2026-10-12`
- **Decision Authority / Deciders:** Owner
- **Scope / Concern:** Shared Media Asset Identity Contract / Shared Media Interoperability with Category / Host-Owned Media Boundary
- **Canonical Contract / Current Owner:** [`docs/architecture/PRODUCT_V1_ARCHITECTURE_CANDIDATE.md`](../architecture/PRODUCT_V1_ARCHITECTURE_CANDIDATE.md) (§9.1, §20)
- **Supersedes:** None
- **Superseded By:** None

---

## Context

During the architectural design and specification of `Product V1` ([`docs/architecture/PRODUCT_V1_ARCHITECTURE_CANDIDATE.md`](../architecture/PRODUCT_V1_ARCHITECTURE_CANDIDATE.md)), the identity contract and boundaries for image assignments of external Media Assets (`maa_product_image_assignments`) required formal definition. Earlier draft concepts considered arbitrary opaque string or UUID identifiers for external media assets.

However, in the broader Maatify catalog ecosystem, `Maatify/php-category` already defines an established public contract and relational schema for media assignments (`maa_category_category_image_assignments`). In that contract, `media_asset_id` represents an external, Host-owned scalar identity persisted as `BIGINT UNSIGNED`, exposed at the PHP boundary as a native integer, and normalized at the input boundary through `CategoryIdDTO` to accept positive integers and canonical decimal digit strings bounded by `PHP_INT_MAX`.

If `Product V1` had introduced an arbitrary string or UUID media identity while `Category` used a numeric identity, consuming Host applications managing shared catalog media would face an impedance mismatch and incompatible identity representations for the same underlying host media asset.

Furthermore, clear architectural boundaries were needed regarding media ownership and role namespaces:
1. Does the Product library own media file storage, binary upload, processing, URL generation, or lifecycle operations?
2. Does Product persistence maintain foreign key constraints or SQL JOINs against external media storage?
3. Does Product reuse Category Image Role identities, or maintain its own independent role namespace?
4. What process governs any future transition to UUID or string media identities?

These architectural questions were formulated as Owner Decision Gate 5 in [`docs/architecture/PRODUCT_V1_ARCHITECTURE_CANDIDATE.md`](../architecture/PRODUCT_V1_ARCHITECTURE_CANDIDATE.md) §20 to establish a binding contract prior to implementation.

---

## Decision

The project Owner formally approved the following decision on 2026-10-12:

### Owner-Approved Source Text (Arabic Original)

> أعتمد في Product V1 استخدام عقد هوية Media Asset المتوافق مع Category: تكون `media_asset_id` هوية خارجية يملكها الـHost، عددية وموجبة، وتُخزّن كـ `BIGINT UNSIGNED` مع فرض شرط الإيجابية والتحقق من القيمة قبل تحويلها إلى نوع PHP المدعوم. تتوافق حدود الإدخال والإخراج مع العقد العام الحالي لـCategory، بما في ذلك قبول `int|string` عند الإدخال حيث يسمح العقد، وإخراج الهوية كعدد صحيح.
>
> لا تملك Product تخزين Media Asset أو دورة حياته، ولا تنشئ مفتاحًا أجنبيًا أو JOIN إلى مخزن الـMedia. وتظل هويات Product Media Roles منفصلة تمامًا عن هويات Category Image Roles.
>
> أي حاجة مستقبلية إلى UUID أو هوية نصية تتطلب قرارًا منسقًا يغيّر عقد الحزمتين، أو خريطة تحويل يملكها الـHost؛ ولا يجوز تغيير نوع الهوية في Product وحدها.

### English Translation

> For Product V1, I approve the adoption of the Media Asset identity contract compatible with Category: `media_asset_id` is an external, Host-owned identity, numeric and positive, persisted as `BIGINT UNSIGNED` with enforcement of the positivity constraint and validation of the value before casting to the supported PHP type. The input and output boundaries conform to the current public contract of Category, including accepting `int|string` at input where permitted by the contract, and outputting the identity as an integer.
>
> Product does not own Media Asset storage or its lifecycle, and does not create a foreign key or JOIN to Media storage. Product Media Role identities remain completely separate from Category Image Role identities.
>
> Any future requirement for a UUID or string identity requires a coordinated decision that changes both package contracts, or a Host-owned mapping; the identity type must not be changed in Product alone.

### Explicit Contract Details of the Approved Decision

1. **External and Host-Owned Identity:** `media_asset_id` is an external, Host-owned identity. Product does not create or manage media asset identities.
2. **Persistence Specification:** Persisted in relational storage as `BIGINT UNSIGNED NOT NULL`. A database check constraint enforces strict positivity: `CHECK (media_asset_id > 0)`.
3. **Public PHP Representation:** In public PHP boundaries that expose `media_asset_id`, the identity is represented as an integer (`int`), conforming to the Category contract.
4. **Input Normalization and Validation Boundary:** Input normalization strictly conforms to the existing Category `CategoryIdDTO` contract:
   - Command inputs accept `int|string` at the boundary where the contract permits.
   - For `int` inputs, values must be strictly positive (`$value >= 1`); zero and negative integers are rejected.
   - For `string` inputs, the value must match `/^[1-9][0-9]*\z/D`. This rejects zero (`"0"`), signs (`"+1"`, `"-1"`), leading or trailing whitespace (`" 1 "`), leading zeroes (`"01"`), decimal notation (`"1.0"`), scientific notation (`"1e2"`), and any other non-canonical or malformed string representation.
   - Values exceeding `PHP_INT_MAX` are rejected **before casting** to integer, comparing against `(string) PHP_INT_MAX` by length and string comparison.
   - The PHP layer explicitly does not claim to accept the entire unsigned 64-bit integer range (`0` to `2^64 - 1`). The supported PHP integer range bounded by `PHP_INT_MAX` remains binding.
5. **No Ownership of Media Storage or Lifecycle:** `php-product` owns neither Media Asset file storage nor its upload, processing, resizing, transcoding, URL resolution, or lifecycle deletion. Product only owns the relational assignment linking a Product or Variant target to the external `media_asset_id` within an exact assignment scope.
6. **No External Foreign Keys or JOINs:** Product persistence maintains no foreign key constraint (`FOREIGN KEY`) referencing external media storage, and execution queries perform no SQL `JOIN` operations against external media tables.
7. **Strict Namespace Separation for Image Roles:** Product owns its own Image Role registry (`maa_product_image_roles`), completely separate from Category Image Roles (`maa_category_category_image_roles`). Product Image Role IDs (`role_id`) and Category Image Role IDs occupy independent namespaces and must never be interchanged or cross-assigned.
8. **Coordinated Evolution Policy:** Any future requirement for UUID or string-based media identities requires either a coordinated architectural decision updating both package contracts (`php-category` and `php-product`) synchronously, or an explicit translation mapping owned by the Host application. Unilateral alteration of the media identity type in Product alone is strictly prohibited.

---

## Grounding in `Maatify/php-category` Implementation

The compatibility claims in this decision are grounded directly in the actual, current `Maatify/php-category` contract and implementation:

- **`CategoryIdDTO.php` (`Maatify\Category\Common\DTO\CategoryIdDTO`):** Enforces boundary validation accepting `int|string`, rejecting non-positive integers, enforcing regex `^[1-9][0-9]*\z/D` on strings, and verifying `strlen($value) <= strlen((string) PHP_INT_MAX)` and `strcmp($value, (string) PHP_INT_MAX) <= 0` before casting to `(int) $value`.
- **`CreateCategoryImageAssignmentCommand.php` (`Maatify\Category\ImageAssignment\Assignment\Command\CreateCategoryImageAssignmentCommand`):** Accepts `int|string $mediaAssetId` and normalizes it via `(new CategoryIdDTO($mediaAssetId, 'mediaAssetId'))->value`, storing and exposing `public int $mediaAssetId`.
- **`schema/category.sql` (`maa_category_category_image_assignments`):** Defines column `` `media_asset_id` BIGINT UNSIGNED NOT NULL COMMENT 'Host-provided external Media Asset identity; deliberately has no foreign key' `` with `CONSTRAINT chk_maa_category_category_image_assignments_media_asset_id CHECK (media_asset_id > 0)`.

No speculative assumptions or unverified guarantees beyond this existing contract are introduced.

---

## Rationale

1. **Catalog Ecosystem Interoperability:** When a Host application uses the same Media Asset identity across both packages, a compatible identity representation (`int` in PHP, `BIGINT UNSIGNED` in SQL, matching the Category contract) simplifies integration and reduces the need for conversion caused by type mismatch.
2. **Defensive Input Validation and Overflow Prevention:** Replicating the Category `CategoryIdDTO` validation rules provides robust protection against malformed input, scientific notation, sign manipulation, and 64-bit integer overflow truncation before values reach domain logic or persistence.
3. **Domain Decoupling:** Decoupling Product from media storage, binary assets, and URL generation allows `php-product` to remain a pure, focused commerce domain package, adaptable to any storage infrastructure (local filesystem, cloud buckets, or external digital asset management systems).
4. **Namespace Integrity for Roles:** Product Image Roles serve product-specific classification needs (e.g., gallery, packaging, specifications) that are distinct from category taxonomy display roles. Separate namespaces prevent cross-domain coupling and protect role key governance.

---

## Consequences

### Positive Consequences

- A compatible Media Asset identity representation across `php-product` and `php-category` simplifies Host integration when the Host uses the same Media Asset identity in both packages; it does not guarantee direct integration in every case.
- Robust boundary validation that rejects malformed, negative, or overflowing identifiers before integer casting.
- No external foreign key from Product tables to Media Asset storage, so Product schema does not depend on how the Host stores media; preserving Media Asset identities, or a Host-owned mapping, remains the Host's responsibility when media data is migrated.
- Strict namespace boundaries between Product Image Roles and Category Image Roles.

### Follow-up Requirements

- The `php-product` Image Assignment commands, DTOs, and persistence implement this contract.
- The architecture document ([`docs/architecture/PRODUCT_V1_ARCHITECTURE_CANDIDATE.md`](../architecture/PRODUCT_V1_ARCHITECTURE_CANDIDATE.md)) §9.1 and §20 Gate 5 carry this decision.
- Gate 6 (`Product.display_order` retention or exclusion in V1) is resolved by [`DEC-006`](DEC-006-product-ordering-boundary.md).
- This decision itself does not authorize runtime PHP implementation, SQL migrations, or database behavior, and does not claim full Catalog integration; the start of implementation is authorized by [`DEC-007`](DEC-007-product-v1-implementation-start-authorization.md).
- The standards applicability re-evaluation required before persistence implementation is recorded in `STANDARDS_MANIFEST.md`.

---

## Boundaries & Non-Goals

This decision is strictly bounded to the shared media asset identity contract for Product V1. The following areas are explicitly non-goals and out of scope:

- Product ownership of media file storage, binary uploading, transcoding, thumbnail generation, CDN delivery, or URL resolution.
- Defining or hardcoding standard business meanings for Product Image Roles (roles remain Host-defined).
- Interchanging or sharing role identifiers between Product and Category packages.
- Resolving Owner Decision Gate 6 (`Product.display_order`).
- Implementing runtime PHP code, classes, interfaces, traits, or exceptions.
- Creating SQL DDL files, database migrations, or schema tables.
- Modifying `STANDARDS_MANIFEST.md` or activating conditional persistence standards.
