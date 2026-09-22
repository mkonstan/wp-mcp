#!/usr/bin/env bash
#
# IS THERE A GREEN FULL RUN ON FILE FOR THIS RUN KEY? Prints the run id if so, nothing if
# not, and exits 0 either way - "no" is an answer, not a failure.
#
#   bin/reusable-green-run.sh <owner/repo> <run-key> [max-age-days]
#
# Needs `gh` authenticated with `actions: read`, and a git checkout of the repository.
#
# WHY THIS IS A SCRIPT AND NOT SIX LINES OF YAML. It used to be six lines of yaml, in two
# workflows, and it trusted the ARTIFACT NAME: "an unexpired artifact called
# wpmcp-green-<key> exists" was taken to mean "a green run proved that key". A name is not a
# proof. This repository is PUBLIC, `ci.yml` has a `pull_request` trigger, a pull_request run
# executes the PR's OWN `ci.yml`, and fork-PR artifacts are stored in the BASE repository -
# so once a contributor is past the approval gate, a PR that edits `ci.yml` can upload an
# artifact under any name it likes, and the next tag on that key would have published
# untested code (analysis/58 §3b). Four checks close it, and the fourth closes it on its own.
#
#   1. SAME REPOSITORY. `head_repository_id == repository_id` - so nothing that ran from a
#      fork counts, whatever it called its artifact.
#   2. IT WAS `ci.yml`. The run's `path` must be `.github/workflows/ci.yml`; a seal from some
#      other workflow, present or future, is not this workflow's verdict.
#   3. IT WAS GREEN. `conclusion == "success"`. Inside the repository the `seal` job's
#      `needs:`-without-`always()` already guarantees this, but the check costs one field and
#      does not depend on a job list staying the way it is.
#   4. THE HEAD COMMIT REALLY HAS THIS KEY. The clincher, and the only one that does not
#      trust a label: fetch the run's `head_sha` and recompute the fingerprint from ITS tree.
#      A forged artifact name has to be accompanied by a commit in this repository whose
#      shipped PHP and whose test suite are byte-identical to the one being released - at
#      which point it is not a forgery, it is the same code.
#
# AND AN AGE CAP, because a verdict about "current WordPress" decays (analysis/58 §3c).
# `.wp-env.json` pins `"core": null`, so the current-core leg means "whatever WordPress was
# current on the day it ran". The only refresh is a schedule, and GitHub runs schedules on
# the DEFAULT branch, so a release branch's seal is never re-proved. 14 DAYS, and the
# defence is WordPress's own release cadence: minor releases land every few weeks and a
# security release lands with days of notice, so a fortnight bounds a reused verdict to at
# most one WordPress generation behind, and usually zero. It is also far longer than the
# case reuse exists for - a documentation commit minutes or hours after a green run - and
# far shorter than the 90 days an artifact lives, so the cap, not the expiry, is what
# decides. A tag cut months after the code was proved re-runs everything, which is what the
# old workflow did for every tag.

set -euo pipefail

repo="${1:?usage: reusable-green-run.sh <owner/repo> <run-key> [max-age-days]}"
key="${2:?usage: reusable-green-run.sh <owner/repo> <run-key> [max-age-days]}"
max_age_days="${3:-14}"

seal="wpmcp-green-${key}"
cutoff=$(( $(date -u +%s) - max_age_days * 86400 ))

note() { echo "reusable-green-run: $*" >&2; }

# FAILING CLOSED HAS TO BE A DECISION, NOT A COINCIDENCE. `gh api` writes the JSON error body
# to STDOUT on an HTTP error, so an earlier version of this script - which discarded stderr and
# appended `|| true` - fed `{"message": "Bad credentials", ...}` into the candidate loop and
# rejected each line on the workflow-path check. The outcome was right (no seal, full gate) and
# the reason was wrong: the safety rested on error JSON failing a string comparison, and the log
# said "rejected: it is unknown, not .github/workflows/ci.yml" about a call that never happened.
# The next refactor of the parsing would have removed the property in silence. So every call
# captures its exit status explicitly, and a call that failed is reported as a call that failed
# (analysis/58 R2.2).
gh_err=$(mktemp)
trap 'rm -f "$gh_err"' EXIT

# Runs `gh api` with the given arguments, putting stdout in $gh_out. Returns gh's exit status.
gh_try() {
    local status=0
    set +e
    gh_out=$(gh api "$@" 2>"$gh_err")
    status=$?
    set -e
    return $status
}

# The first line of whatever gh said about it - enough to tell a 401 from a 403 from no network.
gh_why() {
    local why
    why=$(head -2 "$gh_err" | tr '\n' ' ' | tr -s ' ')
    [ -n "${why// /}" ] || why="(it said nothing)"
    echo "$why"
}

# Newest first. More than one run can seal the same key - the same code on two branches, or
# a re-run - and any of them is a valid verdict, so each is checked until one passes.
if ! gh_try -X GET "repos/${repo}/actions/artifacts" \
    -f "name=${seal}" -f per_page=100 \
    --jq '[.artifacts[]
           | select(.expired == false)
           | {run: .workflow_run.id,
              created: .created_at,
              head: .workflow_run.head_sha,
              repo_id: .workflow_run.repository_id,
              head_repo_id: .workflow_run.head_repository_id}]
          | sort_by(.created) | reverse | .[]
          | [.run, .created, .head, (.repo_id|tostring), (.head_repo_id|tostring)] | @tsv'
then
    note "the artifact listing for ${seal} FAILED, so nothing is known: $(gh_why)"
    note "a call that failed is not an answer - no seal is being reused, and the full gate runs"
    exit 0
fi

candidates="$gh_out"

if [ -z "${candidates:-}" ]; then
    note "no unexpired artifact named ${seal}"
    exit 0
fi

while IFS=$'\t' read -r run created head repo_id head_repo_id; do
    [ -n "${run:-}" ] || continue

    if [ "$repo_id" != "$head_repo_id" ]; then
        note "run ${run} rejected: it ran from a fork (head repository ${head_repo_id}, this repository ${repo_id})"
        continue
    fi

    age_ok=$(date -u -d "$created" +%s 2>/dev/null || echo 0)
    if [ "$age_ok" -lt "$cutoff" ]; then
        note "run ${run} rejected: sealed ${created}, older than the ${max_age_days}-day cap"
        continue
    fi

    if ! gh_try "repos/${repo}/actions/runs/${run}" --jq '[.path, .conclusion] | @tsv'; then
        note "run ${run} rejected: asking about it FAILED, so its workflow and its conclusion are unknown: $(gh_why)"
        continue
    fi

    path=$(printf '%s' "$gh_out" | cut -f1)
    conclusion=$(printf '%s' "$gh_out" | cut -f2)

    if [ "$path" != ".github/workflows/ci.yml" ]; then
        note "run ${run} rejected: it is ${path:-unknown}, not .github/workflows/ci.yml"
        continue
    fi

    if [ "$conclusion" != "success" ]; then
        note "run ${run} rejected: its conclusion is ${conclusion:-unknown}"
        continue
    fi

    # THE ONE THAT DOES NOT TRUST A NAME. actions/checkout is shallow, so the sealing
    # commit is usually not in this clone; fetch just that object. A fetch that fails
    # rejects the candidate, which is the safe direction.
    if ! git cat-file -e "${head}^{commit}" 2>/dev/null; then
        git fetch --quiet --depth=1 origin "$head" 2>/dev/null || true
    fi

    if ! git cat-file -e "${head}^{commit}" 2>/dev/null; then
        note "run ${run} rejected: its head commit ${head} could not be fetched, so its fingerprint cannot be checked"
        continue
    fi

    sealed_key=$(bash "$(dirname "$0")/code-fingerprint.sh" "$head" key)

    if [ "$sealed_key" != "$key" ]; then
        note "run ${run} rejected: its head commit ${head} fingerprints as ${sealed_key}, not ${key}"
        continue
    fi

    note "run ${run} accepted: ${repo}, ci.yml, success, sealed ${created}, head ${head} fingerprints as ${key}"
    echo "$run"
    exit 0
done <<< "$candidates"

note "no candidate for ${seal} survived the provenance checks"
exit 0
