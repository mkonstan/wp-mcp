#!/usr/bin/env bash
#
# THE CODE FINGERPRINT, and beside it the ENVIRONMENT fingerprint.
#
# Why this exists (analysis/53-open-decisions.md, D17). One commit used to get the same
# test set four times, and the worst case was measured: `a811891` differed from `349e2b0`
# by one word in CHANGELOG.md and still got a 90-minute release gate. The rule that
# replaces "don't re-run on minor changes" is COMPUTED, never judged - "minor" is a
# judgement, and a judgement is what lets a real change through.
#
#   code  - a sha256 over the git BLOB IDS of exactly the PHP files that go into the zip:
#           wp-mcp.php, endpoint.php, tools.php, trace.php, admin.php, uninstall.php and
#           src/*.php. Two commits with the same value ship byte-identical PHP, so a test
#           cannot tell them apart. Editing analysis/, the tests or a markdown file cannot
#           be mistaken for a code change; editing a description INSIDE tools.php is
#           correctly treated as one, because that file ships.
#   env   - a sha256 over the files that decide WHAT the tests are and WHERE they run:
#           both workflows, the sprint-gate group list, .wp-env.json (the WordPress
#           version), composer.json, composer.lock, phpunit.xml.dist, .gitattributes, and
#           everything under tests/ and bin/. A matching CODE fingerprint says the code is
#           identical, NOT the
#           environment - so a change to any of these must force a full run, or we would be
#           trusting a result from a suite, or a container, that no longer exists.
#   key   - sha256 of the two together. This is what a green run is filed under and what a
#           later run looks up. Both halves are printed so a human can see WHICH half moved.
#
# BLOB IDS, NOT FILE CONTENT HASHES, and not the working tree: the answer must be a
# property of a COMMIT, so that CI, the release workflow and a person on a laptop all
# compute the same string for the same commit. git already hashed every blob; this reads
# those ids out of the tree rather than hashing the bytes a second time.
#
#   bin/code-fingerprint.sh            # HEAD, three `name=value` lines
#   bin/code-fingerprint.sh <ref>      # any commit in which every listed path exists
#   bin/code-fingerprint.sh HEAD key   # just the run key, bare
#
# NOT EVERY COMMIT IN THE HISTORY, and that is the price of the refusal below. A ref that is
# missing one of these paths is refused rather than hashed, so in this repository the script
# answers for `fc75a58` onwards - it refuses `fc39f72` and everything before it, which predate
# `.github/sprint-gate-groups.txt`. The D17 measurement that started this (`a811891` and
# `349e2b0`, one word of CHANGELOG apart, the same code fingerprint) was taken with the first
# version of this script, which hashed those paths without requiring them; it cannot be
# re-derived with this one, and `analysis/56` records that.
#
# tests/unit/CodeFingerprintTest.php pins the CODE list against the release workflow's
# staging step, so a new shipped file that this script does not hash makes the unit tier
# red rather than silently inheriting somebody else's green run.

set -euo pipefail

ref="${1:-HEAD}"
want="${2:-all}"

# The shipped PHP, and nothing else. `src` is passed as a directory so a nested class
# added later is hashed without this list being edited; the .php filter is what keeps a
# stray README inside src/ out of the CODE half.
code_paths=(wp-mcp.php endpoint.php tools.php trace.php admin.php uninstall.php src)

# What decides the test set and the container. Not shipped, so it is deliberately a
# SEPARATE number: a test-only commit must re-run everything and must not be able to
# reuse a green run taken under the previous suite.
env_paths=(
    .github/workflows
    .github/sprint-gate-groups.txt
    .wp-env.json
    composer.json
    composer.lock
    phpunit.xml.dist
    .gitattributes
    tests
    bin
)

# `git ls-tree -r` prints `<mode> <type> <object><TAB><path>`, so $3 is the blob id and $4
# the path. Sorting under the C locale makes the order a property of the bytes rather than
# of the runner's locale, and the path is hashed beside the id so that MOVING a file
# without changing it still changes the answer.
tree_lines() {
    git ls-tree -r "$ref" -- "$@" | awk '$2 == "blob" { print $3, $4 }'
}

# EVERY LISTED PATH MUST STILL MATCH SOMETHING, and this is the only way the fingerprint can
# quietly get WEAKER rather than noisier. `git ls-tree -r <ref> -- does/not/exist` prints
# nothing and exits 0, so a path that is renamed or moved out of the tree drops out of the
# hash in silence: the number keeps being computed, keeps looking stable, and simply stops
# covering that file. A fingerprint that covers less than it says is worse than no
# fingerprint, because a reuse is decided on it. Found by review (analysis/58 §2).
#
# The CODE half was accidentally protected already - its `grep '[.]php$'` exits 1 on no match
# and `pipefail` aborts the script - but "accidentally" is not a guarantee, so both halves are
# checked the same way, out loud, naming the path.
require_paths() {
    local half="$1"; shift
    local path
    for path in "$@"; do
        if [ -z "$(git ls-tree -r "$ref" -- "$path" | head -1)" ]; then
            echo "code-fingerprint: the $half half lists '$path' and $ref has nothing there." >&2
            echo "code-fingerprint: a path that no longer matches is silently dropped from the hash," >&2
            echo "code-fingerprint: so the fingerprint would stop covering it. Fix the list or the path." >&2
            exit 1
        fi
    done
}

require_paths code "${code_paths[@]}"
require_paths environment "${env_paths[@]}"

# PHP only: only .php is executed on a site.
code=$(tree_lines "${code_paths[@]}" | grep -E '[.]php$' | LC_ALL=C sort | sha256sum | cut -d' ' -f1)
# Every file: a fixture .json or a shell script in bin/ changes what the suite does just
# as much as a .php does.
env=$(tree_lines "${env_paths[@]}" | LC_ALL=C sort | sha256sum | cut -d' ' -f1)
key=$(printf 'wpmcp-run-key-1\ncode=%s\nenv=%s\n' "$code" "$env" | sha256sum | cut -d' ' -f1)

case "$want" in
    code) echo "$code" ;;
    env)  echo "$env" ;;
    key)  echo "$key" ;;
    *)
        echo "code=$code"
        echo "env=$env"
        echo "key=$key"
        ;;
esac
