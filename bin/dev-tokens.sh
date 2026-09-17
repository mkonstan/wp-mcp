#!/usr/bin/env bash
# Run bin/dev-tokens.php against both of THIS CHECKOUT's Local sites: jaygroup, then
# sample. Those two are this machine's; another copy of the repository edits run_site
# below. docs/CONNECT-CLIENTS.md section 3b documents the PHP script, which is general -
# and this wrapper is named there rather than spelled out, because it is not in the zip.
#
#   DEVTOKENS_MCP_JSON=/d/Projects/wp-mcp-adapter/.mcp.json bin/dev-tokens.sh status
#   DEVTOKENS_MCP_JSON=... bin/dev-tokens.sh label
#   DEVTOKENS_MCP_JSON=... bin/dev-tokens.sh mint
#
# The .mcp.json path is never assumed: DEVTOKENS_MCP_JSON is required. Each site runs in
# its own subshell, with its variables set BEFORE bin/local-env.sh is sourced - that file
# keeps any value already set, so a variable set after it would be silently ignored and
# both runs would reach jaygroup.
#
# The PHP script refuses any site whose environment type is not "local", prints no token
# and no hash, and touches only servers whose URL host is the site's own. See its header.

set -u

cmd="${1:-}"

case "$cmd" in
    status|label|mint) ;;
    *) echo "usage: DEVTOKENS_MCP_JSON=<path to .mcp.json> $0 status|label|mint" >&2; exit 2 ;;
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
        # default, so jaygroup starts from nothing and sample sets its own.
        unset PHPRC PHP WPCLI WPMCP_LOCAL_SITE_ID WPMCP_SITE_PATH WPMCP_TEST_URL
        for pair in "$@"; do export "$pair"; done
        # shellcheck source=/dev/null
        source "$here/local-env.sh" >/dev/null
        export DEVTOKENS_CMD="$cmd" DEVTOKENS_MCP_JSON="$json"
        wp eval-file "$script"
    ) || status=1
}

run_site jaygroup
run_site sample \
    "WPMCP_LOCAL_SITE_ID=q6Urfd_zx" \
    "WPMCP_SITE_PATH=C:/Users/vbwiz/Local Sites/sample/app/public" \
    "WPMCP_TEST_URL=http://sample.local"

exit $status
