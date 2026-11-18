# DEC-008 — Fresh Product Trial Schema and Clock Instants

- **Decision ID** `DEC-008`
- **Status** `ACTIVE`
- **Date** `2026-11-18`
- **Decision Authority** Owner for the trial data policy, Lead for the timestamp implementation
- **Scope** Product release-candidate schema provisioning and persistence of Host Clock timestamps
- **Canonical Contract** [Product architecture §11.3](../architecture/PRODUCT_V1_ARCHITECTURE_CANDIDATE.md#113-schema-defaults-status-casing-and-timestamps), [Package Reference §1.3](../../PRODUCT_PACKAGE_REFERENCE.md#13-timestamps-and-the-clock), and [schema installation](../../schema/README.md#install)

## Context

[Issue #18](https://github.com/Maatify-ltd/php-product/issues/18) showed that MySQL local `DATETIME` storage collapses distinct instants during a repeated hour. The Owner directs that Product release candidates can start with fresh data while the package is pre-stable.

## Decision

Provision the pre-stable Product release candidates with the fresh schema shipped in each candidate. Persist the instant from the injected Clock as UTC `DATETIME(6)`. Read it as UTC and use the reading Host Clock's timezone for the returned value.

## Rationale

UTC storage keeps distinct instants distinct and lets MySQL order them chronologically. The Host continues to choose the timezone presented by the API.

## Consequences

The next release candidate uses its own schema on an empty database as part of provisioning. Stable-release upgrade policy is outside this decision.
