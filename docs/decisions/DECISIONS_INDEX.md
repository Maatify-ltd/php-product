# Decision Index

## Discovery Status

This index is the current discovery surface for durable engineering decisions in this repository. Local Owner decisions `DEC-001`, `DEC-002`, `DEC-003`, `DEC-004`, `DEC-005`, `DEC-006`, `DEC-007`, and `DEC-008` are recorded and indexed with status `ACTIVE`. All six Owner decision gates for Product V1 are resolved, and `DEC-007` records the Owner approval of the architecture and the start of implementation.

## Decisions

| Decision ID | Title | Status | Scope / Concern | Decision Record | Canonical Contract / Current Owner | Supersedes | Superseded By |
|---|---|---|---|---|---|---|---|
| DEC-001 | Variant-Addressed Pricing Boundary for Product V1 | ACTIVE | Product Pricing Boundary / Variant Pricing Identity | [DEC-001-product-variant-pricing.md](DEC-001-product-variant-pricing.md) | docs/architecture/PRODUCT_V1_ARCHITECTURE_CANDIDATE.md (§7, §20) | None | None |
| DEC-002 | Optional Inventory Tracking Boundary for Product V1 | ACTIVE | Product Inventory Boundary / Optional Variant Inventory Tracking | [DEC-002-optional-inventory-tracking.md](DEC-002-optional-inventory-tracking.md) | docs/architecture/PRODUCT_V1_ARCHITECTURE_CANDIDATE.md (§8.1, §20) | None | None |
| DEC-003 | Product Identity Namespace Boundary for Product V1 | ACTIVE | Product Identity Namespace / Single Deployment Table Set | [DEC-003-product-identity-namespace.md](DEC-003-product-identity-namespace.md) | docs/architecture/PRODUCT_V1_ARCHITECTURE_CANDIDATE.md (§4.1, §20) | None | None |
| DEC-004 | Language-Only Product Content Scope for Product V1 | ACTIVE | Product Content Scoping / Language Identity Only | [DEC-004-language-only-product-content-scope.md](DEC-004-language-only-product-content-scope.md) | docs/architecture/PRODUCT_V1_ARCHITECTURE_CANDIDATE.md (§4.1, §11.2, §20) | None | None |
| DEC-005 | Shared Media Asset Identity Contract for Product V1 | ACTIVE | Shared Media Asset Identity Contract / Host-Owned Media Boundary | [DEC-005-shared-media-asset-identity.md](DEC-005-shared-media-asset-identity.md) | docs/architecture/PRODUCT_V1_ARCHITECTURE_CANDIDATE.md (§9.1, §20) | None | None |
| DEC-006 | Product Ordering Boundary for Product V1 | ACTIVE | Product Ordering Boundary / Exclusion of Package-Wide Product Display Order / Category Mapping Ordering Boundary | [DEC-006-product-ordering-boundary.md](DEC-006-product-ordering-boundary.md) | docs/architecture/PRODUCT_V1_ARCHITECTURE_CANDIDATE.md (§10, §11, §20) | None | None |
| DEC-007 | Product V1 Implementation Start Authorization | ACTIVE | Product V1 Architecture Approval / Implementation Start Authorization / Pre-Implementation Standards Applicability Re-evaluation | [DEC-007-product-v1-implementation-start-authorization.md](DEC-007-product-v1-implementation-start-authorization.md) | docs/architecture/PRODUCT_V1_ARCHITECTURE_CANDIDATE.md (§1.1, §19, §20, §21); docs/php-engineering-standards/STANDARDS_MANIFEST.md | None | None |
| DEC-008 | Fresh Product Trial Schema and Clock Instants | ACTIVE | Release-candidate schema provisioning and timestamp persistence | [DEC-008-trial-schema-and-clock-instants.md](DEC-008-trial-schema-and-clock-instants.md) | Architecture §11.3, Package Reference §1.3, schema installation | None | None |
