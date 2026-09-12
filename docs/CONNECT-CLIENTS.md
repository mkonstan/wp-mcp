# Connecting a real MCP client

Two paths, and which one you can use is decided by **where the client dials from**.

| Client | Dials from | Works against |
|---|---|---|
| **Claude Code CLI** | your own machine | any site your machine can reach — **including a Local site** like `jaygroup.local` |
| **Claude Desktop / claude.ai custom connector** | Anthropic's infrastructure | only a **publicly reachable HTTPS site** with a publicly valid certificate |

**A `.local` site cannot be used with a Claude Desktop or claude.ai custom connector at all:**
the request is made from Anthropic's servers, which cannot resolve the hostname or route to
your machine, and no amount of local certificate trust changes that.

So the Sprint 4 client gate is automated against the Claude Code CLI (§1), and the
Desktop/claude.ai walkthrough (§2) is for a real site.

---

## 1. Claude Code CLI — the automated gate

```bash
source bin/local-env.sh
composer test:client          # or: bash bin/claude-code-smoke.sh
```

`bin/claude-code-smoke.sh` does the whole exchange and cleans up after itself:

1. Mints a fresh **read-scope** token for user 1 with `wp eval`, labelled for this run.
2. Writes a throwaway `.mcp.json` and `CLAUDE.md` into a temp directory.
3. Runs `claude -p` against that config and asks it to call `site-info`.
4. Asserts the output contains **the site name and WordPress version read from the
   database** — two values that appear nowhere in the prompt, the `CLAUDE.md` or the URL, so
   the only way they can be in the output is a completed handshake, a served tool list, and
   a tool that actually ran.
5. Revokes the token and removes the temp directory on **any** exit, via a trap.

Real output, `jaygroup.local`, 2026-09-12:

```
smoke: claude 2.1.266 (Claude Code)
smoke: site   https://jaygroup.local
smoke: NODE_EXTRA_CA_CERTS=C:\Users\vbwiz\AppData\Roaming\Local\run\router\nginx\certs\jaygroup.local.crt
smoke: expecting the tool to report site name 'JG' and WordPress 7.1
smoke: token minted (read scope, 15 min, label wpmcp-test-claude-code-...).
smoke: running the client...
{"name":"JG","url":"https:\/\/jaygroup.local","wp_version":"7.1","active_theme":"JayGroup 2.00","active_plugins":18}
smoke: PASS - the client reported 'JG' on WordPress 7.1, both read from the database ...
smoke: token revoked.
```

### Two things that had to be measured rather than assumed

**Node and Local's certificate.** Local issues a per-site certificate that is its own CA
(self-issued, `CA:TRUE`, CN = the site host — checked with `openssl_x509_parse`), so
`NODE_EXTRA_CA_CERTS` pointed at that one file is enough; there is no separate Local root CA
to find. Local's **Trust** button writes to the *Windows* store, which Node does not read, so
clicking it does not help here. The script finds
`%APPDATA%\Local\run\router\nginx\certs\<host>.crt` on its own. `NODE_TLS_REJECT_UNAUTHORIZED=0`
is deliberately **not** used: it would disable verification for every connection the client
makes, Anthropic's included.

**Project-scope `.mcp.json` trust.** A `.mcp.json` merely *discovered* in the working
directory has to be approved interactively the first time, which a `-p` run cannot do. What
worked is passing the same file explicitly: **`--mcp-config <file> --strict-mcp-config`** —
it becomes this invocation's configuration rather than a project's, and nothing else on the
machine is loaded alongside it. `claude mcp add --transport http --scope user` also works, but it
writes to the real user configuration and a crashed run would leave it behind. Tool
permission is granted with `--allowed-tools mcp__wpmcp__site-info` — one read-only tool, not
`--dangerously-skip-permissions`.

### Doing it by hand

```bash
claude mcp add --transport http wpmcp "https://<site>/wp-json/wpmcp/mcp/<token>"
claude            # then: /mcp   to see the server, and ask it to call site-info
claude mcp remove wpmcp
```

---

## 2. Claude Desktop / claude.ai — against a public site

Requires a site on the public internet, over HTTPS, with a certificate the world already
trusts. Everything below assumes `https://example.com` is such a site and the plugin is
active on it.

### Mint the token

1. **wp-admin > Settings > WP MCP** (`/wp-admin/options-general.php?page=wp-mcp`).
2. **Scope** — `read` first. A read-scope token proves the handshake and the tool list
   without handing an agent a single write. Come back for `admin` once the list appears.
3. **Runs as** — the WordPress user the token authenticates as. *That user's capabilities are
   the ceiling on everything the connector can see or do.*
4. **Label** — something you will recognise in the token table.
5. **Expires in (hours)** — **12 is a hard cap with no override.** The connector stops working
   when the token expires and you re-mint and re-paste. By design.
6. **Generate token.** The green notice shows it **once**; the site stores only a SHA-256
   hash and cannot show it again. On an HTTPS site the copy field already holds the full URL:

   ```
   https://example.com/wp-json/wpmcp/mcp/<64 lowercase hex characters>
   ```

**The token is in the URL path on purpose.** Claude's connector UI has no field for a request
header, so `Authorization: Bearer` — the form that keeps the token out of access logs, and the
one the test suite uses — is not available here. The path form exists for exactly this client,
and it does mean the token appears in your web server's access log: an accepted trade for a
12-hour credential.

### Add the connector

**Settings > Connectors > Add custom connector**, paste that URL, save. Look for the option
that takes a **remote MCP server URL**, not the one that edits a local JSON config for a
stdio server — this endpoint speaks HTTP and has no stdio transport. Nothing else is needed:
no OAuth, no session id, no state between requests.

### What success looks like

The connector shows as connected and lists its tools.

With a **`read`-scope token — exactly 7:**

```
site-info      list-posts     get-post      list-terms
list-media     get-media      list-comments
```

With an **`admin`-scope token — 16**: those 7 plus `create-post`, `update-post`,
`delete-post`, `create-term`, `delete-term`, `upload-media`, `delete-media`,
`moderate-comment`, `reply-comment`. **20** if *Enable code-edit tools* is also ticked in
Settings > WP MCP, which adds `code-list`, `code-read`, `code-write`, `code-delete`.

Write tools are not greyed out for a read token — they are **not in the list at all**. The
list you see is already what the token may do.

Then ask for something that needs a tool: *"what WordPress site am I connected to?"* should
call `site-info` and come back with your site's name, URL, WordPress version, active theme
and plugin count. Watch **Uses** and **Bound IP** fill in on the admin page — the token pins
to the IP of its **first tool call**, and every later request must come from there.

---

## 3. When it does not connect

Check `wp-content/debug.log` first (`WP_DEBUG` and `WP_DEBUG_LOG` on). Every decision this
endpoint makes writes one line beginning `wp-mcp auth`, and that line names the cause the
wire deliberately does not — all six token failures are one byte-identical 401.

| Log line | What happened | Fix |
|---|---|---|
| *(nothing at all)* | The request never reached WordPress. | DNS, firewall, or — for a Desktop/claude.ai connector against a non-public host — the client is not dialling your machine at all. See the table at the top. |
| `origin_deny origin=...` | The client sent an `Origin` that is not one of this site's own, and the CSRF gate refused it. | Add the exact origin the log printed: `add_filter('wpmcp_allowed_origins', fn($o) => array_merge($o, ['https://claude.ai']));` |
| `insecure_deny` | The request arrived as plain HTTP. | The URL must be `https://`. |
| `content_type_deny content_type=...` | The POST was not `application/json`. | A client bug; report the value. |
| `validate_fail reason=expired` | 12 hours are up. | Mint a new token, paste the new URL. |
| `validate_fail reason=ip_mismatch` | The token is pinned to a different IP than this request came from. | Mint a fresh token. The pin is per-token and permanent. |
| `validate_fail reason=not_found` | The token is not in the table. | Truncated paste, or the row was revoked. |
| HTTP 400, `-32600`, "Unsupported MCP-Protocol-Version" | The client declared a revision this server does not speak. | The message names the three it does. Nothing to configure; report the value. |

---

## 4. Afterwards

**Revoke the token** — Settings > WP MCP, the revoke button on that row. It would expire
within 12 hours anyway, but a connector you are done with is a credential nobody is watching.
