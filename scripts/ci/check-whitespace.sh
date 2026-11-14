#!/usr/bin/env bash
# Whitespace gate over every tracked file, run against the checked-out tree (not
# against a diff), so it is independent of checkout depth and of the PR base.
#
#   1. Non-Markdown files: any trailing blank, and `git diff --check` against the
#      empty tree (trailing space, space-before-tab, blank line at EOF, conflict
#      markers).
#   2. Markdown files: trailing tabs, and trailing spaces other than exactly two
#      after a non-space character. Exactly two trailing spaces are a deliberate
#      Markdown hard line break and are the only exemption; a single space, three or
#      more spaces, a tab, or spaces on an otherwise blank line are defects.
set -euo pipefail

cd "$(git rev-parse --show-toplevel)"
empty_tree="$(git hash-object -t tree /dev/null)"
failed=0

if non_md="$(git grep -nI -E '[[:blank:]]$' -- . ':(exclude)vendor' ':(exclude)*.md')"; then
    echo 'Trailing whitespace in non-Markdown files:' >&2
    printf '%s\n' "$non_md" >&2
    failed=1
elif [[ "$?" -ne 1 ]]; then
    echo 'Unable to inspect tracked files for trailing whitespace.' >&2
    exit 1
fi

if md_candidates="$(git grep -nI -E '[[:blank:]]$' -- '*.md' ':(exclude)vendor')"; then
    # Keep only lines that are NOT "<non-space>" followed by exactly two spaces.
    # grep exits 0 when it keeps a line, 1 when every candidate is a hard break, and
    # 2 or more on an error, which fails the gate.
    md_status=0
    md_defects="$(printf '%s\n' "$md_candidates" | grep -v -E '^[^:]+:[0-9]+:.*[^[:space:]]  $')" || md_status=$?
    if [[ "$md_status" -gt 1 ]]; then
        echo 'Unable to filter Markdown trailing-whitespace candidates.' >&2
        exit 1
    fi
    if [[ "$md_status" -eq 0 ]]; then
        echo 'Markdown whitespace defects (only exactly two trailing spaces, a hard break, are allowed):' >&2
        printf '%s\n' "$md_defects" >&2
        failed=1
    fi
elif [[ "$?" -ne 1 ]]; then
    echo 'Unable to inspect tracked Markdown files for trailing whitespace.' >&2
    exit 1
fi

if ! git diff --check "$empty_tree" HEAD -- . ':(exclude)vendor' ':(exclude)*.md' >&2; then
    failed=1
fi

if [[ "$failed" -ne 0 ]]; then
    exit 1
fi
echo 'Whitespace verification passed.'
