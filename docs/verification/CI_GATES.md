# CI gates and local verification

This document maps every required CI gate to the local command that runs the same
verification contract. The governing contracts are `std-ci-workflow` (§2.1, §8,
§11, §16, §19), `std-testing` (§3.2, §3.4), and `std-composer-package` (§21), as
pinned in [`STANDARDS_MANIFEST.md`](../php-engineering-standards/STANDARDS_MANIFEST.md).
The workflow is [`.github/workflows/ci.yml`](../../.github/workflows/ci.yml); branch
protection should require only the stable `Final Gate` job.

## Prerequisites

A PHP patch admitted by `composer.json` with `pdo_mysql`, Composer 2.10 or later (the dependency-policy
model), Docker with the Compose plugin, `openssl`, and `bash`. Install development
dependencies once:

```bash
composer update --no-interaction --prefer-dist --no-progress
```

`composer.lock` is not committed. No credential is stored: every run generates a
random, run-scoped database password.

## Persistence RC trial plan

The owner approved migrating and testing the successor Persistence package on
[Draft PR #7](https://github.com/Maatify-ltd/php-product/pull/7) while retaining the release
hold. The trial follows [Package Building §11](../php-engineering-standards/standards/packages/PACKAGE_BUILDING_STANDARD.md#11-shared-ordering-and-pagination-utilities)
and [Composer §24](../php-engineering-standards/standards/packages/COMPOSER_PACKAGE_STANDARD.md#24-stability-policy)
at the adopted standards commit `c5d70cbe005fc26c38a7218967efe991194e76d6`.

- **Selection.** The trial pins `maatify/php-persistence` `1.0.0-rc.1` at
  `34c3b874525d36ae064c162cdd553bc75d18772b`, as recorded by the
  [published release](https://github.com/Maatify-ltd/php-persistence/releases/tag/v1.0.0-rc.1).
  The Composer constraint has equal inclusive endpoints, so it admits only this RC.
  Before selecting another RC, verify the latest published candidate by SemVer
  precedence and match the release tag, Composer distribution metadata, and source
  revision. Record the new version and revision and rerun the affected gates.
- **Installation and validation.** Use the [README path installation](../../README.md#installation)
  for trial hosts. The consumer root requests the exact Persistence RC and permits
  the Product path package individually. Both the package and fixture use
  `minimum-stability: stable` and `prefer-stable: true`. Run the Composer and
  PHP boundary checks, PHPStan, style, unit and MySQL Integration suites, and
  `composer verify:consumer`. The Harness checks the resolved version and revision,
  the absence of the legacy dependency, and the public pagination response from
  clean Composer roots.
- **Evidence.** Migration commit `3463f9d` passed two Integration runs in each
  PHP `8.4.27` / `8.5.12` × MySQL `8.0.16` / `8.0.46` cell (237 tests
  and 3,364 assertions per run). The Harness passed from two clean roots on
  PHP `8.4.27` and MySQL `8.0.46`, including the exact dependency identity and
  all eight usage examples. These are trial verification results.
- **Exit.** The RC exception ends when a Stable Persistence version is published.
  Verify the Stable release and its API, update the package and fixture constraints
  and identity checks, then rerun dependency, runtime and external consumer gates
  on that revision. Product Stable requires the compatible Stable Persistence
  integration, the first-Stable gates, and separate owner release authorization.
- **First Product RC.** The current owner hold remains in force. This plan authorizes
  testing the dependency on the Draft PR only. Publishing a Product RC while it
  depends on a Persistence RC requires a separate owner-approved release plan
  that explicitly changes the hold for that candidate. Record the exact Product
  and Persistence versions, their source identities, the Composer distribution
  channel and the consumer root's package-specific stability requirements.
  Qualify the candidate artifact before publication, then verify installation
  and run the Consumer Harness against the exact published Product RC.

## Gates

| Gate | Local command | CI job |
|---|---|---|
| Composer validation | `composer validate --strict` | Composer Validate |
| PHP patch floor | `php scripts/ci/check-php-patch-floor.php 8.4` (or `8.5` for the matching interpreter) | Each PHP job |
| PHP constraint boundaries | `bash scripts/ci/check-php-constraint.sh` | Composer Validate |
| Platform requirements | `composer check-platform-reqs` | each dependency job |
| PHP syntax | `bash scripts/ci/check-php-syntax.sh` | PHP Syntax Check |
| Autoload (strict PSR-4) | `composer dump-autoload --optimize --strict-psr` | Static Analysis, Unit, Integration |
| Static analysis (PHPStan max over `src`, `tests`, and `examples`, no baseline or suppressions) | `composer analyse` | Static Analysis (PHPStan) |
| Coding style (PER-CS 3.1, non-mutating; the repository delta verifier also checks the header block order, that every class-like type is namespaced, and PSR-1 naming: StudlyCaps class-like names, upper-case class constants, camelCase methods) | `composer format:check` | PHP Coding Style (PER 3.1) |
| Unit suite (no Docker needed) | `composer test:unit` | Unit (PHP 8.4 and 8.5) |
| Integration suite on real MySQL | `composer test:integration` (`composer test` runs Unit then the same Integration orchestration) | Integration (PHP x MySQL matrix) |
| Lowest supported dependencies | `composer update --prefer-lowest --prefer-stable` then `composer analyse` and `composer test` | Lowest Supported Dependencies |
| Dependency-policy audit | `composer audit --no-interaction --abandoned=fail` | Composer Security Audit |
| Consumer Verification Harness | `composer verify:consumer` | Consumer Verification Harness |
| Workflow lint (pinned, checksum-verified actionlint) | `bash scripts/ci/install-actionlint.sh <dir>` then `ACTIONLINT_BIN=<dir>/actionlint bash scripts/ci/lint-workflows.sh` | Workflow Lint |
| Whitespace (tracked tree, Markdown included) | `bash scripts/ci/test-whitespace-gate.sh` then `bash scripts/ci/check-whitespace.sh` | Whitespace Verification |

## Integration infrastructure contract

One Compose definition ([`docker-compose.yml`](../../docker-compose.yml)) serves the
Integration suite and the Harness through `scripts/ci/mysql-service.sh`:

- the image tag is the parameter `PRODUCT_TEST_MYSQL_VERSION` (default `8.0.16`, the
  supported minimum; CI also runs `8.0.46`), never a copied definition;
- readiness is the service health check (a TCP `mysqladmin ping`), not a `sleep`;
- there is no `container_name`; each run uses its own Compose project, a loopback-only
  dynamic host port, and `tmpfs` storage, so no state survives and no developer or
  Host database is touched;
- teardown is `docker compose down -v`, on success and on failure; a failed teardown is reported on
  stderr and fails the run, while a failed run keeps its own exit status (logs captured before a
  failed start are diagnostics only);
- the PHP runner stays on the host PHP runtime, so the PHP matrix is truthful;
- the suite runs twice against the same service and then asserts that no test
  database remains.

Integration variables are `PRODUCT_TEST_*` and are set by the orchestration; running
PHPUnit directly without them fails (nothing is skipped). Docker and Compose are
verification-time dependencies only, never consumer runtime dependencies.

## What the suites prove

**Unit** (`tests/Unit`, no infrastructure): command validation including whitespace-only and
encoding cases (Product, Option, Value, Role, Assignment, and reconfiguration commands, the Media Asset identity
contract, composition selections), the composition fingerprint vectors, the in-memory configuration validator,
DTO invariants, Service orchestration with test doubles,
the `ProductPersistenceException` consumer contract, identity stability (no package SQL statement sets an
identity column and no service method replaces one), the digit-string rule used for stored identities (equal to
the `ctype` digit check without requiring that extension) and its range check (a stored identity above
`PHP_INT_MAX` is rejected, not truncated), an unparsable stored timestamp keeping the parser's failure as
the previous exception, every `*DTO` type implementing `JsonSerializable`,
and the Package Reference conformity test, which fails when the "Implemented Public API" table and the code
disagree (a listed type or method that does not exist, an undocumented public method of a service or the
factory, or a public type that is neither listed nor internal) or when a capability row of the inventory names
no method or a method that does not exist.

**Integration** (`tests/Integration`, real MySQL, exact MySQL error numbers):
- `Schema/`: install shape; status casing; code, SKU, barcode, language, platform, and role-key
  uniqueness including soft-deleted history; whitespace-only rejection (space, tab, LF, CR, VT,
  FF, NBSP, U+3000); composite ownership foreign keys; NULL-safe Content and image-assignment identity;
  image-assignment target, scope, and default rules.
- `Runtime/`: the public use cases through the factory, each family through its separate Management
  and Consumer services:
  - Product creation (inactive Product plus one active empty-composition Variant, case-insensitive and
    soft-delete-reserved conflicts) and reconfiguration (transitions, explicit reuse, conflicts, restore,
    failure classes, full rollback), with reconfigured Variants placed after the highest live position of
    the Product, soft-deleted Variants excluded (`ProductCreationTest`).
  - Variant reads (consumer visibility evaluated by the database including the product-wide
    unrepresented-Value rule, management filters, ordering and bounds), composition resolution with its
    four failure classes, and Variant identifiers (SKU and barcode set, replaced, cleared, released,
    classified by the violated key).
  - The lifecycle contract of every family (soft-deleted row, soft-deleted owner chain, restore with the
    saved status and revalidation, inactivity blocking nothing), Option and Option Value creation,
    `updateStatus()`, and ordering commands; restore at the end of the live scope.
  - Content of Products, Options, and Option Values (identity including the unlocalized record and
    soft-deleted history, full-replacement update, the `INV-05` and `INV-06` rules on soft delete, exact
    language selectors, consumer visibility).
  - Consumer reads of Options, Option Values, and exact-scope Image Assignments; management lists,
    Product search under any Host `sql_mode`, business-key reads, and pagination; identity and
    uniqueness under the `utf8mb4_unicode_ci` collation.
  - Image Roles and Assignments (identity, target integrity, the scope key shared with the Ordering API,
    atomic default replacement, no promotion, `restoreAsDefault()`), and a Role status
    change, deletion, or restore that leaves every assignment column unchanged.
  - Timestamps: `created_at` and `deleted_at` are the Host Clock's instant stored verbatim as
    `Y-m-d H:i:s`, and DTO timestamps are hydrated in the reading Clock's timezone (`Asia/Tokyo`
    and `America/New_York` in the test) with no normalization (`ClockTimestampTest`).
  - Transactions: rollback of a failed operation the package started; inside a caller-owned
    transaction, savepoint rollback of an operation that fails after its own writes (Product creation
    after both rows, `HostTransactionAtomicityTest`; `reconfigure()` after its status writes and the
    new Variant row, and `setDefault()` after it cleared the previous default,
    `HostTransactionSavepointTest`) and of an operation that fails on its write (Content create and
    soft delete, Variant `updateStatus()`, and `updateIdentifiers()` on a real duplicate key,
    `HostTransactionSavepointTest`), while a `reconfigure()` or `restoreAsDefault()` rejected before
    any write leaves nothing behind (`ProductReconfigurationTest`, `RestoreAsDefaultTest`); in every
    case the Host transaction stays active, the Host's other work is preserved, and the Host's commit
    or rollback decides the rest; locking reads
    that validate the latest committed state inside a Host transaction holding an older snapshot
    (`LockingReadSnapshotTest`); and a real deadlock (driver error 1213) between two Host
    transactions, lost inside a package call, propagated as the original `PDOException` with the Host
    transaction ended on the server (`DeadlockPropagationTest`).
  - Real concurrent writers in separate processes and connections, released together by an explicit
    worker barrier and repeated in rounds: creation, reconfiguration of one Product, default
    replacement and restore-as-default within one scope, deactivation of two Variants that are each
    dispensable but not both (one winner, one `FC-INV`), moves of several Options of one Product
    (positions unique and contiguous), activation against deletion of the only Product name (never an
    active Product without a name), one SKU on two Variants (one winner, one typed conflict), and
    `setDefault()` against a move of the scope's current default within one Product (both succeed,
    serialized by the Product lock). Across Products, concurrent `reconfigure()` calls that each add
    a Variant to an already configurable Product, concurrent `restoreAsDefault()` calls, and
    concurrent `setDefault()` calls all succeed with no deadlock; each of these tests fails when its
    locking read is no longer keyed by the locked Product's own identities
    (`ConcurrentCrossProductLockTest`).

Every Runtime test rolls back a transaction left open on the shared connection in its `tearDown()`,
so no transaction leaks into the next test.

**Resolved regressions** each keep a recurrence test in the suites above; there is no separate
`test:regression` entry. Among them: Product creation stays atomic inside a Host-owned transaction
(`HostTransactionAtomicityTest`); the Product code search is escaped independently of the Host
`sql_mode` (the `NO_BACKSLASH_ESCAPES` case of `ManagementListTest`); the identity guard scans every
`UPDATE`, and duplicate keys are classified by key name only (`IdentityStabilityTest`,
`DuplicateKeyTest`, and `VariantIdentifiersTest::testTheConflictIsClassifiedByTheViolatedKeyNotByTheValue`);
the configuration snapshot and the live-default reads record-lock only the locked Product's own rows,
and the three cross-Product paths above no longer deadlock (`ConcurrentCrossProductLockTest`); and a stored
identity above `PHP_INT_MAX` is rejected instead of truncated (`RowHydratorTest`).

## Consumer Verification Harness

`composer verify:consumer` installs the package into an external Composer root (path
repository, copied, not symlinked), loads it only through the production PSR-4 autoloader
(asserting that the public classes resolve from the installed package and that test namespaces
are not autoloadable), installs the delivered `schema/product.sql`, and runs the workflow
`ProductFactory` -> create, name the Product through Content, activate, read the Content as a
consumer (exact language, no fallback), reject deleting the last name of an active Product, prepare
Options and Values with their Content labels, reconfigure, resolve a composition, assign images and
replace the default, soft-delete and restore, against a real MySQL service. It also runs one real
race: four processes of the same harness script, on the same consumer autoloader, released together
by a barrier on stdin, create one Product code; exactly one wins and every other gets the typed
conflict. It runs
twice from clean consumer roots, uses the same Compose lifecycle as Integration, and is part of
the stable `Final Gate`.

## Whitespace gate

`check-whitespace.sh` scans every tracked file in the checked-out tree, so it does
not depend on checkout depth or on the PR base. Non-Markdown files may not end a line
with a blank, and also pass `git diff --check` against the empty tree. Markdown is
scanned too: the only exemption is exactly two trailing spaces after text (a Markdown
hard line break); one space, three or more, a tab, or a whitespace-only line fail.
`test-whitespace-gate.sh` proves, in a throwaway repository, that each defect is
rejected and that clean and hard-break files are accepted.

## Configuration revision and performance trial

Real MySQL integration tests use separate editor connections. The second editor retains a
`REPEATABLE READ` snapshot while the first commits a different target. The second save raises
`ProductConfigurationRevisionConflictException`, keeps the Host transaction open, and leaves the
first target unchanged. Tests also cover racing writers, revision invalidation by an Option
creation, and rolling back the revision with a Host transaction.

A local synthetic trial used PHP 8.4.27 and MySQL 8.0.46 with `REPEATABLE READ`. Each fixture
had one active Option and Value plus the soft-deleted history below. Each row summarizes
200 warm resolution calls, 25 reconfigurations to the same target, and 15 same-Product writer
races. PHP memory is the maximum additional peak during resolution.

| Historical Options / Values | Resolution p95 | PHP peak extra | Reconfigure p95 | Resolution p95 with writer holding locks | Stale writer p95 with 50 ms Host hold |
| --- | ---: | ---: | ---: | ---: | ---: |
| 0 / 0 | 21.5 ms | 84.8 KiB | 27.7 ms | 20.1 ms | 130.0 ms |
| 20 / 400 | 20.6 ms | 317.1 KiB | 45.9 ms | 30.0 ms | 105.8 ms |
| 100 / 5,000 | 48.4 ms | 3,412.5 KiB | 156.4 ms | 54.1 ms | 190.4 ms |

MySQL `performance_schema.data_lock_waits` showed the stale writer waiting in all 45 races.
The reader resolved the committed Variant before the lock holder committed in every race.
The stale writer timings include the artificial Host hold, the intervening resolution call,
and the stale check. The lab shared a machine with other test jobs. These results show the
cost of historical Options and Values in this fixture. Production capacity, multiple active
Options, historical Variant compositions, and lock contention between different Products
were outside this measurement.

## Composition resolution before and after

The baseline at `5329dab` and the optimized reader used the same MySQL 8.0.46 instance,
PHP 8.4.27, and Product with four active Options and one selected Value per Option.
Each version ran twice at each history size, with 20 warmup calls and 200 measured calls
per run. Every call returned the same expected Variant. The table shows the range of
per-run p95 values and the maximum additional PHP memory peak.

| Deleted Options / Values | Baseline p95 | Optimized p95 | Baseline PHP peak extra | Optimized PHP peak extra |
| --- | ---: | ---: | ---: | ---: |
| 0 / 0 | 8.6–11.3 ms | 7.9–11.0 ms | 87.5 KiB | 86 KiB |
| 20 / 1,000 | 16.9–25.2 ms | 6.9–9.3 ms | 712.8 KiB | 86 KiB |
| 100 / 5,000 | 26.8–29.6 ms | 6.4–7.0 ms | 3,457.1 KiB | 86 KiB |

The optimized validation reads active, non-deleted Option IDs and only the requested
active, non-deleted Value IDs with their owners. These synthetic timings cover resolution
without competing writes.

## Current limits

Not covered, and not claimed:

- The Harness workflow in `consumer-verification/verify.php` exercises the main path only; the other public
  calls are exercised from the installed package by the examples it smoke-executes (`examples/01` to `08`),
  which check their own results. Edge cases and failure classes of those calls are proven by the
  Integration suite.
- There is no separate `test:regression` entry; recurrence tests live in the Unit and Integration suites.
- The deadlock proof covers one shape (two Host transactions locking two Product rows in opposite
  orders, the package call losing); other deadlock shapes are not provoked.
- A deadlock between concurrent operations on different Products is possible: under
  `REPEATABLE READ` the gap locks at the ends of a Product's index ranges can block an insert of a
  neighbouring Product, in particular the first Content of an owner and a change between simple and
  configurable. The Package Reference states it as a transient failure (driver error 1213, the
  `PDOException` propagated unchanged, no write left, the whole transaction retried by the caller or
  the Host); no test provokes it.
- Release Artifact Verification and Published Artifact Verification: release-time gates, not
  applicable before a release target exists.

**Branch protection: in force.** The repository ruleset `main-final-gate` (enforcement `active`, no bypass actors) targets
the default branch `main`. GitHub reports it through `GET /repos/Maatify-ltd/php-product/rules/branches/main`: pull request
required, the stable `Final Gate` job (GitHub Actions) required with the branch up to date, no force push, and no deletion.
This was read back from GitHub after creation; it is repository configuration, not part of the checked-out tree, so it is
re-verified with that command rather than by a CI job.
