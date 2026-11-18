#!/usr/bin/env bash
# Compare an exact Packagist dist install with the qualified Product Git archive.
set -euo pipefail
cd "$(dirname "${BASH_SOURCE[0]}")/../.."

version="${1:-}"
qualified_sha="${2:-}"
if [[ ! "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?$ || ! "$qualified_sha" =~ ^[a-f0-9]{40}$ ]]; then
    echo "Usage: bash scripts/ci/verify-published-artifact.sh VERSION QUALIFIED_SHA [PACKAGE:CONSTRAINT ...]" >&2
    exit 2
fi
shift 2
tag_sha="$(git rev-parse --verify "refs/tags/v$version^{commit}")"
if [[ "$tag_sha" != "$qualified_sha" ]]; then
    echo "The version tag does not identify the qualified Product commit." >&2
    exit 1
fi

workspace="$(mktemp -d "${TMPDIR:-/tmp}/product-published.XXXXXX")"
trap 'rm -rf -- "$workspace"' EXIT
mkdir -p "$workspace/expected" "$workspace/home" "$workspace/cache"
git archive "$qualified_sha" | tar -x -C "$workspace/expected"

cd "$workspace"
php -r '
    $version = $argv[1];
    $require = ["maatify/php-product" => "$version <=$version"];
    foreach (array_slice($argv, 2) as $entry) {
        $parts = explode(":", $entry, 2);
        if (count($parts) !== 2 || !preg_match("~^[a-z0-9_.-]+/[a-z0-9_.-]+$~", $parts[0])
            || trim($parts[1]) === "" || array_key_exists($parts[0], $require)) {
            throw new RuntimeException("Invalid additional prerelease requirement.");
        }
        $require[$parts[0]] = $parts[1];
    }
    echo json_encode([
        "name" => "maatify/product-published-verification",
        "description" => "Clean Packagist installation for Product publication verification.",
        "license" => "proprietary",
        "require" => $require,
        "minimum-stability" => "stable",
        "prefer-stable" => true,
        "config" => ["preferred-install" => "dist", "allow-plugins" => false],
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), PHP_EOL;
' "$version" "$@" > composer.json

isolated_composer() {
    env -i PATH="$PATH" HOME="$workspace/home" COMPOSER_HOME="$workspace/home" \
        COMPOSER_CACHE_DIR="$workspace/cache" composer "$@"
}

isolated_composer --version
printf 'channel=Packagist preferred_install=dist qualified_sha=%s\n' "$qualified_sha"
isolated_composer update --no-interaction --prefer-dist --no-plugins --no-scripts --no-progress
isolated_composer check-platform-reqs --no-interaction --no-plugins

php -r '
    $installed = json_decode(file_get_contents("vendor/composer/installed.json"), true, flags: JSON_THROW_ON_ERROR);
    foreach ($installed["packages"] as $package) {
        if ($package["name"] !== "maatify/php-product") {
            continue;
        }
        $path = realpath("vendor/composer/" . ($package["install-path"] ?? ""));
        $expected = realpath("vendor/maatify/php-product");
        if ($expected === false || $path !== $expected || ltrim($package["version"] ?? "", "v") !== $argv[1]
            || ($package["installation-source"] ?? null) !== "dist"
            || ($package["dist"]["reference"] ?? null) !== $argv[2]
            || ($package["source"]["reference"] ?? $argv[2]) !== $argv[2]
            || empty($package["dist"]["url"]) || empty($package["dist"]["type"])) {
            throw new RuntimeException("Installed Product did not match the expected Packagist dist artifact.");
        }
        echo json_encode([
            "package" => $package["name"],
            "version" => $package["version"],
            "installation-source" => $package["installation-source"],
            "dist" => $package["dist"],
            "source-reference" => $package["source"]["reference"] ?? null,
            "path" => $path,
        ], JSON_THROW_ON_ERROR), PHP_EOL;
        exit(0);
    }
    throw new RuntimeException("Product is missing from Composer installed metadata.");
' "$version" "$qualified_sha"

diff -rq expected vendor/maatify/php-product
echo "Product dist matches the qualified archive."
