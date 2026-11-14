#!/usr/bin/env bash
# Canonical Integration orchestration (composer test / composer test:integration).
#
# Usage: scripts/ci/run-integration.sh [PHPUNIT_ARGUMENTS...]
# Environment:
#   PRODUCT_TEST_MYSQL_VERSION  MySQL image tag; default 8.0.16 (supported minimum)
#   PRODUCT_TEST_RUNS           repeat count against the same service; default 2
# Prerequisites: Docker with the Compose plugin, openssl, PHP with pdo_mysql, and
# `composer update` already run (vendor/bin/phpunit present).
#
# Lifecycle: validate prerequisites -> start the real MySQL service from the
# repository-owned docker-compose.yml -> wait for service-level health ->
# discover the loopback endpoint -> run the PHPUnit Integration suite (each test
# class installs schema/product.sql into its own fresh database) -> repeat ->
# check that no test database remains -> tear down with `down -v`, also on
# failure. Credentials are random, run-scoped, and never written to disk.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"
# shellcheck source=scripts/ci/mysql-service.sh
source "$ROOT/scripts/ci/mysql-service.sh"

[ -x vendor/bin/phpunit ] || { echo "ERROR: vendor/bin/phpunit not found; run composer update first" >&2; exit 2; }
[ -f schema/product.sql ] || { echo "ERROR: schema/product.sql not found" >&2; exit 2; }

RUNS="${PRODUCT_TEST_RUNS:-2}"
mysql_service_install_teardown
mysql_service_start

for run in $(seq 1 "$RUNS"); do
    echo "=== Integration run ${run}/${RUNS} ==="
    vendor/bin/phpunit --testsuite Integration "$@"
done

mysql_service_residue
echo "Integration gate passed on MySQL ${PRODUCT_TEST_MYSQL_VERSION} (${RUNS} runs)"
