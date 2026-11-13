#!/usr/bin/env bash
# Exercise the actual package constraint with Composer and simulated PHP versions.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/../.."
constraint="$(php -r '
    $manifest = json_decode(file_get_contents("composer.json"), true, flags: JSON_THROW_ON_ERROR);
    $constraint = $manifest["require"]["php"] ?? null;
    if (!is_string($constraint) || $constraint === "") {
        exit(1);
    }
    echo $constraint;
')"
fixture="$(mktemp -d "${TMPDIR:-/tmp}/product-php-constraint.XXXXXX")"
trap 'rm -rf -- "$fixture"' EXIT
export COMPOSER_HOME="$fixture/home"

check_version() {
    local php_version="$1" expected="$2" status=0
    php -r '
        echo json_encode([
            "name" => "maatify/product-php-constraint-probe",
            "version" => "1.0.0",
            "repositories" => [["packagist.org" => false]],
            "require" => ["php" => $argv[1]],
            "config" => ["platform" => ["php" => $argv[2]]],
        ], JSON_THROW_ON_ERROR);
    ' "$constraint" "$php_version" > "$fixture/composer.json"
    composer --working-dir="$fixture" update --dry-run --no-install --no-interaction --no-audit --no-scripts --no-plugins > "$fixture/output" 2>&1 || status=$?
    if [[ "$expected" == reject && "$status" -eq 2 ]] && grep -Fq "Root composer.json requires php" "$fixture/output"; then
        printf 'PASS: PHP %s %s\n' "$php_version" "$expected"
        return
    fi
    if [[ "$expected" == accept && "$status" -eq 0 ]]; then
        printf 'PASS: PHP %s %s\n' "$php_version" "$expected"
        return
    fi
    cat "$fixture/output" >&2
    printf 'FAIL: PHP %s expected %s; Composer exit %s\n' "$php_version" "$expected" "$status" >&2
    return 1
}

bounds="$(php -r '
    require "scripts/ci/php-patch-floors.php";
    $floors = pinnedPhpPatchFloors();
    foreach (["8.4", "8.5"] as $minor) {
        $floor = $floors[$minor] ?? null;
        if ($floor === null) {
            throw new RuntimeException("Missing pinned floor for " . $minor);
        }
        $patch = (int) substr($floor, strrpos($floor, ".") + 1);
        if ($patch > 0) {
            echo $minor . "." . ($patch - 1) . " reject\n";
        }
        echo $floor . " accept\n";
    }
    if (isset($floors["8.6"])) {
        throw new RuntimeException("Review the package support matrix for the newly qualified minor.");
    }
    echo "8.6.0 reject\n";
')"
while read -r version expected; do
    check_version "$version" "$expected"
done <<< "$bounds"
