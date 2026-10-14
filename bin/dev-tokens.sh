#!/usr/bin/env bash
# Run bin/dev-tokens.php against each of THIS CHECKOUT's Local sites, in order. Which
# sites those are is machine-specific and is configured outside this file; see the
# below. docs/CONNECT-CLIENTS.md section 3b documents the PHP script, which is general -
# and this wrapper is named there rather than spelled out, because it is not in the zip.
#
#   DEVTOKENS_MCP_JSON=/d/Projects/wp-mcp-adapter/.mcp.json bin/dev-tokens.sh status
#   DEVTOKENS_MCP_JSON=... bin/dev-tokens.sh label
#   DEVTOKENS_MCP_JSON=... bin/dev-tokens.sh mint
#
# THE LABEL IS THE SCRIPT'S OWN, always: this wrapper UNSETS DEVTOKENS_LABEL before each
# site. That variable is the test suite's seam, and a copy left in your shell from debugging
# a test would otherwise be written onto your real dev tokens by `label`. To choose a label
# deliberately, set DEVTOKENS_WRAPPER_LABEL - the wrapper passes that one through, and the
# script's first output line always names the label a write would use.
#
# The .mcp.json path is never assumed: DEVTOKENS_MCP_JSON is required. Each site runs in
# its own subshell, with its variables set BEFORE bin/local-env.sh is sourced - that file
# keeps any value already set, so a variable set after it would be silently ignored and
# both runs would reach the first site.
#
# The PHP script refuses any site whose environment type is not "local", prints no token
# and no hash, and touches only servers whose URL host is the site's own. See its header.

set -u

cmd="${1:-}"

case "$cmd" in
    status|label|mint) ;;
    *) echo "usage: DEVTOKENS_MCP_JSON=<path to .mcp.json> [DEVTOKENS_WRAPPER_LABEL=<label>] $0 status|label|mint" >&2; exit 2 ;;
esac

if [ -z "${DEVTOKENS_MCP_JSON:-}" ]; then
    echo "dev-tokens.sh: set DEVTOKENS_MCP_JSON to the .mcp.json to read." >&2
    exit 2
fi

if [ ! -f "$DEVTOKENS_MCP_JSON" ]; then
    echo "dev-tokens.sh: $DEVTOKENS_MCP_JSON does not exist." >&2
    exit 2
fi

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
script="$(cygpath -w "$here/dev-tokens.php" 2>/dev/null || echo "$here/dev-tokens.php")"
json="$(cygpath -w "$DEVTOKENS_MCP_JSON" 2>/dev/null || echo "$DEVTOKENS_MCP_JSON")"

status=0

run_site() {
    # $1 label, then VAR=value pairs for that site
    local label="$1"; shift
    echo "== $label"
    (
        # A value inherited from the caller's shell would win over local-env.sh's
        # default, so the first site starts from nothing and each later one sets its own.
        unset PHPRC PHP WPCLI WPMCP_LOCAL_SITE_ID WPMCP_SITE_PATH WPMCP_TEST_URL
        # And the suite's label seam never reaches an operator's run by accident: a stray
        # `export DEVTOKENS_LABEL=...` would otherwise be written onto real dev tokens.
        unset DEVTOKENS_LABEL
        for pair in "$@"; do export "$pair"; done
        # shellcheck source=/dev/null
        source "$here/local-env.sh" >/dev/null
        export DEVTOKENS_CMD="$cmd" DEVTOKENS_MCP_JSON="$json"

        if [ -n "${DEVTOKENS_WRAPPER_LABEL:-}" ]; then
            export DEVTOKENS_LABEL="$DEVTOKENS_WRAPPER_LABEL"
        fi

        wp eval-file "$script"
    ) || status=1
}

# WHICH SITES TO RUN AGAINST IS MACHINE-SPECIFIC and is therefore NOT in this public
# repository - a site name identifies a real project and a path carries a username.
# Define them in the UNTRACKED file beside this one, which local-env.sh also sources:
#
#     # bin/local-env.local.sh   (gitignored)
#     WPMCP_SITE_NAME=my-primary-site
#     dev_token_sites() {
#         run_site primary
#         run_site secondary #             "WPMCP_LOCAL_SITE_ID=xxxxxxxxx" #             "WPMCP_SITE_PATH=$(cygpath -m "$HOME/Local Sites/secondary/app/public")" #             "WPMCP_TEST_URL=http://secondary.local"
#     }
#
# Failing loudly beats silently defaulting to somebody else's machine.
# shellcheck source=/dev/null
[ -f "${BASH_SOURCE[0]%/*}/local-env.local.sh" ] && . "${BASH_SOURCE[0]%/*}/local-env.local.sh"

if ! declare -f dev_token_sites >/dev/null 2>&1; then
    echo "dev-tokens: no sites configured. Define dev_token_sites() in" >&2
    echo "            ${BASH_SOURCE[0]%/*}/local-env.local.sh - see the comment above." >&2
    exit 2
fi
dev_token_sites

exit $status
