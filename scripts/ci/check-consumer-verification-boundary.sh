#!/usr/bin/env bash
# Fails when the Consumer Verification Harness bypasses Composer or reaches into
# the package repository's internals.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
fixture="$root/consumer-verification"

for file in composer.json.template verify.php; do
    [ -f "$fixture/$file" ] || { echo "Consumer verification fixture is incomplete: $file" >&2; exit 1; }
done

if grep -n -E "(require|include)(_once)?[[:space:]]*\(?[^;]*(\.\./|/src/|/tests/|autoload-dev|Maatify\\\\Product\\\\Tests)" "$fixture/verify.php" \
    | grep -v "vendor/autoload.php"; then
    echo "Harness must not include repository files, test namespaces, or autoload-dev." >&2
    exit 1
fi
if grep -n -E "PRODUCT_PACKAGE_ROOT|\\\$_SERVER\[.?PWD" "$fixture/verify.php"; then
    echo "Harness must not locate the package repository directly." >&2
    exit 1
fi
echo "Consumer verification boundary check passed."
