#!/usr/bin/env bash
# Sprint 4's client gate, automated: a REAL MCP client handshakes with this endpoint over
# real HTTPS, lists its tools, calls one, and the result is checked against the database.
#
#   source bin/local-env.sh
#   composer test:client          # or: bash bin/claude-code-smoke.sh
#
# WHY THE CLAUDE CODE CLI AND NOT CLAUDE DESKTOP. The gate was written as "Max connects his
# own Claude Desktop". He cannot: a Claude Desktop custom connector is reached from
# Anthropic's infrastructure, which cannot resolve `jaygroup.local` or route to this
# machine, and no certificate trust changes that. Claude Code runs ON this machine and
# dials the site itself, so it is the only real client that can reach a Local site - and it
# speaks the same protocol revision, over the same transport, with the same negotiation.
# docs/CONNECT-CLIENTS.md carries the Claude Desktop path for a public site.
#
# WHAT THIS PROVES THAT THE PHPUNIT SUITE CANNOT. The suite is our own client asserting our
# own beliefs: it sends what we think a client sends. This sends what a client actually
# sends - a real initialize, a real capability exchange, a real tools/list, a real
# tools/call - and the only way it passes is if all of that is right. It is the one test
# that would have caught a handshake this repository misunderstood in the same way twice.
#
# IT COSTS A CLAUDE CALL, so it is NOT in `composer test` and NOT in CI. Run it by hand
# when the handshake, the transport or the tool registry changes.
#
# EVERY ARTEFACT IS TEMPORARY AND CLEANED UP BY A TRAP: the token is revoked and the temp
# directory removed on any exit, including a failure or a Ctrl-C. Nothing global is touched
# - no `claude mcp add`, no settings file - see the --mcp-config note below.

set -u

# ---------------------------------------------------------------- environment
#
# SOURCED HERE RATHER THAN REQUIRED OF THE CALLER. `wp` and `php` are shell FUNCTIONS, and a
# function does not survive into a child shell - so `source bin/local-env.sh && bash
# bin/claude-code-smoke.sh` would arrive with the variables set and the functions gone, which
# is a confusing way to fail. Sourcing it is idempotent: it only exports and defines.
# Anything already set in the caller's environment still wins (every assignment in that file
# is `${X:-default}`), so `WPMCP_TEST_URL=... composer test:client` works as expected.
SMOKE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=/dev/null
. "${SMOKE_DIR}/local-env.sh" >/dev/null

: "${WPMCP_TEST_URL:?local-env.sh did not set WPMCP_TEST_URL}"
: "${WPMCP_SITE_PATH:?local-env.sh did not set WPMCP_SITE_PATH}"

if ! command -v claude >/dev/null 2>&1; then
    echo "smoke: the claude CLI is not on PATH. This gate needs a real MCP client." >&2
    exit 1
fi

SITE_URL="${WPMCP_TEST_URL%/}"
HOST="${SITE_URL#https://}"
HOST="${HOST#http://}"
HOST="${HOST%%/*}"

echo "smoke: claude $(claude --version)"
echo "smoke: site   ${SITE_URL}"

# ---------------------------------------------------------------- TLS trust for Node
#
# MEASURED, NOT ASSUMED. Local issues a per-site certificate that is its own CA
# (basicConstraints CA:TRUE, CN = the site host, self-issued - verified with
# openssl_x509_parse on jaygroup.local.crt), so handing Node that one file is enough; there
# is no separate Local root CA to hunt for. Windows' own trust store, which Local's "Trust"
# button writes to, is invisible to Node - hence this variable rather than a GUI click.
#
# NODE_TLS_REJECT_UNAUTHORIZED=0 would also "work" and is deliberately not used: it turns
# verification off for every connection the client makes, including the one to Anthropic.
# If you are debugging and nothing else explains a TLS failure, set it in your own shell
# for one run - never in this file.
LOCAL_CERTS="${WPMCP_LOCAL_CERTS:-${APPDATA:-}/Local/run/router/nginx/certs}"
SITE_CERT="${LOCAL_CERTS}/${HOST}.crt"

if [ -f "$SITE_CERT" ]; then
    export NODE_EXTRA_CA_CERTS="$(cygpath -w "$SITE_CERT" 2>/dev/null || echo "$SITE_CERT")"
    echo "smoke: NODE_EXTRA_CA_CERTS=${NODE_EXTRA_CA_CERTS}"
else
    echo "smoke: no certificate at ${SITE_CERT} - assuming ${HOST} has one Node already trusts."
fi

# ---------------------------------------------------------------- fixtures

RUN_ID="$(date +%s)-$$"
LABEL="wpmcp-test-claude-code-${RUN_ID}"
WORKDIR=""
TOKEN=""

cleanup() {
    local status=$?

    if [ -n "$TOKEN" ]; then
        # Revoke by LABEL, not by the id we think we got: a partial failure above may have
        # left a row behind whose id was never echoed.
        wp eval "global \$wpdb; \$ids = \$wpdb->get_col(\$wpdb->prepare('SELECT id FROM ' . wpmcp_table() . ' WHERE label = %s', '${LABEL}')); foreach (\$ids as \$id) { wpmcp_revoke((int) \$id); } echo count(\$ids);" --user=1 >/dev/null 2>&1 \
            && echo "smoke: token revoked." \
            || echo "smoke: WARNING - could not revoke the token labelled ${LABEL}. Revoke it in Settings > WP MCP." >&2
    fi

    [ -n "$WORKDIR" ] && [ -d "$WORKDIR" ] && rm -rf "$WORKDIR"

    exit $status
}
trap cleanup EXIT INT TERM

BLOGNAME="$(wp option get blogname 2>/dev/null | tr -d '\r')"
WPVERSION="$(wp eval 'echo get_bloginfo("version");' 2>/dev/null | tr -d '\r')"

if [ -z "$BLOGNAME" ] || [ -z "$WPVERSION" ]; then
    echo "smoke: could not read blogname/version from the site. Is WPMCP_SITE_PATH right?" >&2
    exit 1
fi

# TWO NEEDLES, BECAUSE ONE CAN BE SHORT. A site named "JG" is two characters and could
# plausibly appear in a refusal message by accident; the WordPress version could not, and
# neither value is anywhere in the prompt, in CLAUDE.md or in the URL.
echo "smoke: expecting the tool to report site name '${BLOGNAME}' and WordPress ${WPVERSION}"

# A READ-SCOPE token, minted for user 1 exactly as Settings > WP MCP would. Read scope is
# the point: this gate is about the handshake and the tool surface, and a write token would
# put a destructive tool in a live agent's hands for no extra assurance.
# A FIFTEEN-MINUTE ACTIVE WINDOW INSIDE A ONE-DAY LIFETIME. The window is what the gate
# needs - one run takes seconds - and the lifetime is the shortest the model allows, so a
# token this script somehow fails to revoke is rubbish within the day rather than within
# the year. The two arguments are the sprint-7 shape: wpmcp_mint(scope, label, window,
# lifetime, user).
TOKEN="$(wp eval "\$r = wpmcp_mint('read', '${LABEL}', 900, DAY_IN_SECONDS, 1); echo is_wp_error(\$r) ? 'MINT-ERROR: ' . \$r->get_error_message() : \$r['raw'];" --user=1 | tr -d '\r')"

case "$TOKEN" in
    [0-9a-f][0-9a-f]*) ;;
    *) echo "smoke: wpmcp_mint() did not return a token: ${TOKEN}" >&2; exit 1 ;;
esac

if [ ${#TOKEN} -ne 64 ]; then
    echo "smoke: minted token is ${#TOKEN} characters, not 64: ${TOKEN}" >&2
    exit 1
fi

echo "smoke: token minted (read scope, 15 min window, 1 day lifetime, label ${LABEL})."

# ---------------------------------------------------------------- the client's config
#
# THE HEADER FORM, AND IT IS NOW THE ONLY FORM. The endpoint registers one route, at the
# constant URL below, and takes the credential from `Authorization: Bearer` and nowhere
# else. The URL that carried the token in its path is gone - it was written into every
# access log and proxy log it passed through, and a hosted connector re-sent it for months.
#
# So this gate exercises exactly what every real client now does, GUI clients included:
# claude.ai and Claude Desktop custom connectors have a "Request headers" setting that
# delivers `authorization: Bearer <token>` intact (measured on a public site, 2026-09-13),
# and the equivalent by hand is
#
#   claude mcp add --transport http wpmcp <url> --header "Authorization: Bearer <token>"
#
# A `headers` map in the config file is that same thing for a `--mcp-config` run, which is
# what this script uses so that nothing global on this machine is touched.

WORKDIR="$(mktemp -d 2>/dev/null || mktemp -d -t wpmcp-smoke)"
ENDPOINT="${SITE_URL}/wp-json/wpmcp/mcp"

cat > "${WORKDIR}/.mcp.json" <<JSON
{
  "mcpServers": {
    "wpmcp": {
      "type": "http",
      "url": "${ENDPOINT}",
      "headers": { "Authorization": "Bearer ${TOKEN}" }
    }
  }
}
JSON

cat > "${WORKDIR}/CLAUDE.md" <<'MD'
You have one MCP server available, named `wpmcp`. It is a WordPress site.

When asked about the site, call the `site-info` tool on that server and print its result
verbatim - the raw JSON, exactly as the tool returned it, with no summary, no commentary
and no reformatting. Do not answer from memory and do not guess: if the tool call fails,
print the error text verbatim instead.
MD

# ---------------------------------------------------------------- run the client
#
# --mcp-config PLUS --strict-mcp-config, AND THAT COMBINATION IS THE ANSWER TO THE TRUST
# PROBLEM. A project-scope `.mcp.json` discovered in the working directory has to be
# approved interactively the first time it is seen, which a `-p` run cannot do - it would
# either prompt into a dead stdin or silently start with no server. Passing the same file
# explicitly makes it this invocation's configuration rather than a project's, and --strict
# means nothing else on this machine is loaded alongside it. MEASURED: this is what worked.
# `claude mcp add --scope user` also works but writes to the user's real configuration and
# has to be undone afterwards, which a crashed run would not do.
#
# --allowed-tools names the ONE tool this run may call. Not --dangerously-skip-permissions:
# the gate should prove a read tool works, not that every gate can be turned off.
#
# </dev/null because `claude -p` waits three seconds for piped stdin before giving up and
# printing a warning into the output this script then asserts against.

echo "smoke: running the client..."
echo "---------------------------------------------------------------- client output"

OUTPUT="$(
    cd "$WORKDIR" && claude -p 'Call the wpmcp site-info tool and print its result verbatim.' \
        --output-format text \
        --mcp-config "${WORKDIR}/.mcp.json" \
        --strict-mcp-config \
        --allowed-tools 'mcp__wpmcp__site-info' </dev/null 2>&1
)"
STATUS=$?

printf '%s\n' "$OUTPUT"
echo "---------------------------------------------------------------- end client output"

if [ $STATUS -ne 0 ]; then
    echo "smoke: FAIL - the client exited ${STATUS}." >&2
    exit 1
fi

# THE ASSERTION IS TWO VALUES READ FROM THE DATABASE, which the model had no way to know:
# neither is in the prompt, in CLAUDE.md or in the URL. Their presence in the output can only
# mean the handshake completed, the tool list arrived, site-info ran as the token's user, and
# the result came back. Every layer in one string.
FAILED=0

case "$OUTPUT" in
    *"$BLOGNAME"*) ;;
    *) echo "smoke: FAIL - the output does not contain the site name '${BLOGNAME}'." >&2; FAILED=1 ;;
esac

case "$OUTPUT" in
    *"$WPVERSION"*) ;;
    *) echo "smoke: FAIL - the output does not contain the WordPress version '${WPVERSION}'." >&2; FAILED=1 ;;
esac

if [ $FAILED -ne 0 ]; then
    echo "smoke: either the tool was never called, or it was called and did not answer." >&2
    echo "smoke: check wp-content/debug.log for a 'wp-mcp auth' line naming the refusal." >&2
    exit 1
fi

echo "smoke: PASS - the client reported '${BLOGNAME}' on WordPress ${WPVERSION}, both read"
echo "smoke:        from the database and neither of them in anything the model was given."
