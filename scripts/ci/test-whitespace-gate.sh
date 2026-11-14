#!/usr/bin/env bash
# Negative and positive tests for check-whitespace.sh, run in a throwaway Git
# repository: the gate must fail on each deliberate defect and pass on clean and
# hard-break Markdown. It fails if the gate ever stops detecting a defect.
set -euo pipefail

gate="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/check-whitespace.sh"
work="$(mktemp -d "${TMPDIR:-/tmp}/maatify-whitespace-test.XXXXXX")"
trap 'rm -rf -- "$work"' EXIT

init() {
    rm -rf -- "$work/repo" && mkdir "$work/repo" && cd "$work/repo"
    git init -q . && git config user.email t@example.invalid && git config user.name t
}
commit() { git add -A && git commit -q -m t; }
run_gate() { bash "$gate" >/dev/null 2>&1; }

expect_pass() {
    init; "$1"; commit
    run_gate || { echo "FAIL: gate rejected a clean case: $2" >&2; exit 1; }
    echo "ok: accepts $2"
}
expect_fail() {
    init; "$1"; commit
    if run_gate; then echo "FAIL: gate accepted a defect: $2" >&2; exit 1; fi
    echo "ok: rejects $2"
}

clean()         { printf 'line\n' > a.md; printf '<?php\n' > a.php; }
hard_break()    { printf 'line one  \nline two\n' > a.md; }
md_one_space()  { printf 'line \n' > a.md; }
md_three()      { printf 'line   \n' > a.md; }
md_tab()        { printf 'line\t\n' > a.md; }
md_blank_line() { printf 'a\n  \nb\n' > a.md; }
php_trailing()  { printf '<?php \n' > a.php; }
sh_tab()        { printf 'echo x\t\n' > a.sh; }
php_eof_blank() { printf '<?php\n\n' > a.php; }

expect_pass clean 'clean files'
expect_pass hard_break 'Markdown hard break (exactly two spaces)'
expect_fail md_one_space 'Markdown with one trailing space'
expect_fail md_three 'Markdown with three trailing spaces'
expect_fail md_tab 'Markdown with a trailing tab'
expect_fail md_blank_line 'Markdown whitespace-only line'
expect_fail php_trailing 'PHP with a trailing space'
expect_fail sh_tab 'shell script with a trailing tab'
expect_fail php_eof_blank 'PHP with a blank line at end of file'
echo 'Whitespace gate self-test passed.'
