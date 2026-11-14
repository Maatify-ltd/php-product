# DEC-007 — Product V1 Implementation Start Authorization

- **Decision ID:** `DEC-007`
- **Title:** Product V1 Implementation Start Authorization
- **Status:** `ACTIVE`
- **Date:** `2026-10-12`
- **Decision Authority / Deciders:** Owner
- **Scope / Concern:** Product V1 Architecture Approval / Implementation Start Authorization / Pre-Implementation Standards Applicability Re-evaluation
- **Canonical Contract / Current Owner:** [`docs/architecture/PRODUCT_V1_ARCHITECTURE_CANDIDATE.md`](../architecture/PRODUCT_V1_ARCHITECTURE_CANDIDATE.md) (§1.1, §19, §20, §21) and [`STANDARDS_MANIFEST.md`](../php-engineering-standards/STANDARDS_MANIFEST.md)
- **Supersedes:** None
- **Superseded By:** None

---

## Context

`DEC-001` through `DEC-006` resolved the six Owner Decision Gates in [`docs/architecture/PRODUCT_V1_ARCHITECTURE_CANDIDATE.md`](../architecture/PRODUCT_V1_ARCHITECTURE_CANDIDATE.md) §20. That document stated that, until implementation was explicitly authorized, it remained a `CANDIDATE` and that no PHP or SQL implementation was authorized.

The document also stated (§1.1, §11, §21) that, before any PHP or SQL implementation begins, standards applicability must be formally re-evaluated under the canonical standards governance procedure, because the settled adoption state in `STANDARDS_MANIFEST.md` recorded that the repository had no manually maintained PHP source and no package-owned persistence behavior.

---

## Decision

The Owner approved the architecture and the start of implementation on 2026-10-12, in a chat message to the Lead in the session that produced this record. This section separates the Owner's words from the Lead's interpretation.

### Owner Approval Trace (Arabic Original, Verbatim)

The Owner's exact words, as received in that message:

> أعتمد الوثيقة `docs/architecture/PRODUCT_V1_ARCHITECTURE_CANDIDATE.md` في مستودع `Maatify/php-product` على الـBaseline `main` عند commit `cdbefe7d4296d29292f051f2f7db52b79cb40d62` كمرجع معماري معتمد لـ Product V1، وأوافق على بدء التنفيذ ضمن نطاق الوثيقة وبالالتزام بقرارات `DEC-001` إلى `DEC-006` المسجلة بحالة `ACTIVE`. هذه الموافقة تخص بدء التنفيذ، ولا تُعد موافقة مسبقة على دمج أي PR.

### English Translation of Owner Approval

> I approve the document `docs/architecture/PRODUCT_V1_ARCHITECTURE_CANDIDATE.md` in the `Maatify/php-product` repository, at the `main` baseline commit `cdbefe7d4296d29292f051f2f7db52b79cb40d62`, as the approved architecture reference for Product V1, and I approve starting implementation within the document's scope and in compliance with decisions `DEC-001` through `DEC-006` recorded as `ACTIVE`. This approval concerns the start of implementation and is not advance approval to merge any PR.

### Lead Interpretation (Not Owner Words)

The items below are the Lead's reading of the Owner's words together with the approved architecture document. They are not quoted from the Owner.

1. **Approved architecture reference:** `docs/architecture/PRODUCT_V1_ARCHITECTURE_CANDIDATE.md`, approved at `main` commit `cdbefe7d4296d29292f051f2f7db52b79cb40d62` and maintained in place before the first release, including the Owner decisions of 2026-10-14 recorded in `Maatify/php-product#7` (comment 6161032003).
2. **Implementation start:** Authorized within that document's scope, bound by `DEC-001` through `DEC-006`, which remain `ACTIVE`.
3. **Pre-implementation gate:** The approved document itself (§1.1, §11, §21) requires a formal standards applicability re-evaluation before any PHP or SQL implementation. Its recorded outcome is in `STANDARDS_MANIFEST.md`.
4. **Scope boundaries:** The approved document keeps Pricing, Inventory, Supplier, Category mapping, and full Catalog integration outside Product V1 (§3.2, §10, §18). This record does not extend the approval to them.
5. **Merge:** The Owner states the approval is not advance approval to merge any PR. Every GitHub Merge needs separate explicit Owner authorization, and this record does not authorize release or publish.

---

## Rationale

The architecture candidate was reviewed and its six Owner Decision Gates were closed. Recording the approval durably, with the exact pre-implementation gate, prevents dependent implementation from relying on a decision that exists only in chat, as `DECISION_GOVERNANCE_STANDARD_AR.md` §3 requires.

## Consequences

- Product V1 implementation may start once the standards applicability re-evaluation is complete and recorded.
- Executors treat the approved architecture and `DEC-001` through `DEC-007` as locked and stop rather than reopen them.
- The pre-implementation applicability outcome is recorded in `STANDARDS_MANIFEST.md`, not in this record.
