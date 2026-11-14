#!/usr/bin/env bash
# Inspect archives from a committed tree. Release identity and publication are separate checks.
set -euo pipefail

root=$(git rev-parse --show-toplevel)
if [[ $# != 1 || ! "$1" =~ ^[0-9a-f]{40}$ ]] || [[ "$(git rev-parse --verify "$1^{commit}")" != "$1" ]]; then
    echo 'Usage: bash scripts/ci/check-artifact-contents.sh <full commit SHA>' >&2
    exit 2
fi
sha=$1
workspace=$(mktemp -d)
trap 'rm -rf -- "$workspace"' EXIT

git clone --quiet --shared --no-checkout "$root" "$workspace/source"
cd "$workspace/source"
git -c advice.detachedHead=false checkout --quiet --detach "$sha"
git archive --format=tar --output="$workspace/git.tar" "$sha"
COMPOSER=composer.json COMPOSER_HOME="$workspace/composer-home" COMPOSER_CACHE_DIR="$workspace/composer-cache" \
    composer archive --no-interaction --no-plugins --no-scripts --format=tar --dir="$workspace" --file=composer

git ls-tree -r --name-only "$sha" -- src schema examples > "$workspace/required"
printf '%s\n' composer.json LICENSE README.md CHANGELOG.md SECURITY.md PRODUCT_PACKAGE_REFERENCE.md \
    docs/guides/USAGE_GUIDE.md llms.txt >> "$workspace/required"

for kind in git composer; do
    tar -tf "$workspace/$kind.tar" > "$workspace/entries"
    mkdir "$workspace/$kind"
    while IFS= read -r entry; do
        path=${entry%/}
        case "$path" in
            CHANGELOG.md|LICENSE|README.md|SECURITY.md|PRODUCT_PACKAGE_REFERENCE.md|composer.json|llms.txt|src|src/*|schema|schema/*|examples|examples/*|docs|docs/*) ;;
            *) printf 'Unexpected %s archive path: %s\n' "$kind" "$path" >&2; exit 1 ;;
        esac
        case "/$path/" in
            */../*|*/./*|*/.env*/*|*/.git*/*|*/.idea/*|*/.vscode/*|*/vendor/*|*/composer.lock/*|*/auth.json/*|*.key/|*.pem/|*.pfx/|*.p12/)
                printf 'Disallowed %s archive path: %s\n' "$kind" "$path" >&2
                exit 1 ;;
        esac
    done < "$workspace/entries"
    tar -xf "$workspace/$kind.tar" -C "$workspace/$kind"
    if [[ -n "$(find "$workspace/$kind" ! -type d ! -type f -print -quit)" ]] ||
        grep -rIlE -- '-----BEGIN ([A-Z0-9]+ )*PRIVATE KEY-----' "$workspace/$kind" >/dev/null; then
        printf 'Unexpected file type or private key in %s archive\n' "$kind" >&2
        exit 1
    fi
    while IFS= read -r path; do
        if ! cmp -s "$path" "$workspace/$kind/$path"; then
            printf 'Missing or changed required file in %s archive: %s\n' "$kind" "$path" >&2
            exit 1
        fi
    done < "$workspace/required"
    printf 'Archive contents PASS archive=%s sha=%s\n' "$kind" "$sha"
done
