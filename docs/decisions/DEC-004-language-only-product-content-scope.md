# DEC-004 — Language-Only Product Content Scope for Product V1

- **Decision ID:** `DEC-004`
- **Title:** Language-Only Product Content Scope for Product V1
- **Status:** `ACTIVE`
- **Date:** `2026-10-11`
- **Decision Authority / Deciders:** Owner
- **Scope / Concern:** Content scoping for Product, Option, and Option Value entities; adoption of language-only identity; support for unlocalized content; exclusion of platform/channel/interface textual divergence in V1
- **Canonical Contract / Current Owner:** [`docs/architecture/PRODUCT_V1_ARCHITECTURE_CANDIDATE.md`](../architecture/PRODUCT_V1_ARCHITECTURE_CANDIDATE.md) (§4.1, §11.2, §20)
- **Supersedes:** None
- **Superseded By:** None

---

## Context

During the specification and architectural design of `Product V1`, the dimensions of textual content scoping were evaluated for the core entities: Product (`Product`), Option (`Option`), and Option Value (`Option Value`). The core question was whether textual content (names, descriptions, and labels) required additional scoping dimensions—such as platform, channel, or storefront interface (`platform`, `channel`, `storefront`) alongside language—or whether it should be scoped strictly by language identity while supporting unlocalized content.

This question was formulated as an Owner Decision Gate in the candidate architecture document ([`docs/architecture/PRODUCT_V1_ARCHITECTURE_CANDIDATE.md`](../architecture/PRODUCT_V1_ARCHITECTURE_CANDIDATE.md) §20 Gate 4) to resolve the boundary of core content prior to schema freeze.

---

## Decision

The project Owner formally approved the following decision on 2026-10-11:

### Owner-Approved Source Text (Arabic Original)

> يعتمد محتوى Product وOption وOption Value في V1 هوية قائمة على اللغة فقط، مع دعم المحتوى غير المترجم عبر language_code = NULL، ودون fallback تلقائي. ولا يدعم المحتوى الأساسي اختلاف النص حسب الواجهة أو القناة في V1. ويظل أي احتياج لمحتوى خاص بالمنصة أو الواجهة مسؤولية امتداد مستقل أو قرار معماري لاحق.

### English Translation

> Product V1 adopts language-only identity for Product, Option, and Option Value content, supporting unlocalized content via `language_code = NULL`, with no automatic fallback. Core V1 content does not support textual divergence across interfaces or channels. Any requirement for platform- or interface-specific content remains the responsibility of an independent extension or a subsequent architectural decision.

### Key Dimensions of the Approved Decision

1. **Language-Only Identity for Core Content:** Textual content for core entities (`Product`, `Option`, and `Option Value`) is scoped exclusively by language (`language_code`). Core content tables (`maa_product_product_contents`, `maa_product_option_contents`, and `maa_product_option_value_contents`) do not carry `platform`, store, or `channel` columns.
2. **Support for Unlocalized Content via `language_code = NULL`:** A value of `language_code = NULL` represents an explicit unlocalized content identity, rather than an instruction to fall back to another language. Uniqueness is enforced on `(owner_id, language_code_identity)`, treating `NULL` as a distinct, valid identity.
3. **No Automatic Language Fallback in the Core Library:** Read queries in `php-product` enforce exact matching. Requesting content for a specific language (e.g., `en` or `ar`) does not silently return a `NULL` record or fall back to an alternate language. All fallback policies, resolution chains, and locale defaults belong strictly to the Host Application.
4. **Exclusion of Platform- or Channel-Specific Text in V1 Core:** Core V1 content does not provide differentiated textual attributes (names, descriptions, labels) based on the presentation surface, client interface, application type, or sales channel.
5. **Independence of Extensions and Future Specializations:** Any requirement for platform- or storefront-specific content remains the responsibility of an independent extension outside the core library, or a coordinated future architectural decision. This preserves the strict distinction between core Content and optional extensions (such as Content Fields) without copying `Category` package extensions into `Product` merely because they exist in `Category`.

---

## Rationale

1. **Simplicity and Avoidance of Speculative Complexity (KISS / YAGNI):** Restricting content scoping to language prevents schema bloating, composite index overhead, and unnecessary query complexity. The purpose of this decision is to limit scope dimensions, complexity, and indexes; it does not claim that language-only scoping covers every market or catalog requirement.
2. **Separation of Concerns:** The `php-product` package focuses strictly on defining commercial products and their intrinsic content. Presentational variation across channels or marketing surfaces belongs to presentation layers, content management extensions, or the Host Application.
3. **Architectural Consistency with Ecosystem Standards:** This decision aligns directly with the established Maatify content identity pattern (such as the Category Translation pattern), which treats `language_code = NULL` as a first-class identity, rejects implicit fallback, and delegates localization policies to the Host.
4. **Deterministic Behavior:** Eliminating implicit fallback guarantees predictable, testable, and transparent query results, ensuring that consuming applications explicitly control their own content presentation fallback logic.

---

## Consequences

### Positive Consequences

- Core content tables (`maa_product_product_contents`, `maa_product_option_contents`, and `maa_product_option_value_contents`) remain minimal, focused strictly on owner identity and language.
- Deterministic uniqueness constraints per owner and language identity without cross-channel contention.
- Predictable exact-match read queries that are straightforward to maintain, optimize, and test.
- Full flexibility for the Host Application to implement custom fallback and presentation policies.

### Follow-up Requirements

- Implementation of `php-product` V1 must strictly adhere to this schema and query contract (language-only scoping, NULL unlocalized identity support, no automatic fallback, and no platform columns in core content tables).
- The Host Application or Catalog orchestration layer is responsible for providing any required interface fallback or presentation resolution chains.
- The architecture document (`docs/architecture/PRODUCT_V1_ARCHITECTURE_CANDIDATE.md`) carries this decision in §4.1, §11.2, and §20; the remaining Owner decision gates are resolved by `DEC-005` and `DEC-006`, and the start of implementation is authorized by `DEC-007`.

---

## Boundaries & Non-Goals

This decision is strictly confined to the content scoping of core Product, Option, and Option Value entities. The following areas remain explicitly out of scope:

- Image Assignment scoping, which retains platform, language, and role dimensions under its dedicated specification.
- Design or activation of optional Content Fields extensions.
- Resolution of the Shared Media Asset identity contract (Gate 5).
- Resolution of the retention or exclusion of `Product.display_order` in V1 (Gate 6).
- Authorization of PHP runtime implementation, database migrations, or physical schema creation.
