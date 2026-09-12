# Connecting Claude Desktop to a local wp-mcp site

This is the **Sprint 4 manual gate**. It is the one test nothing can automate, and it is the
test the `2025-11-25` revision decision was about: a real client, speaking the real protocol,
over the real transport, listing the real tools.

Target in this document: `https://jaygroup.local`. Substitute your own site throughout.

---

## 0. Before you start

Three things have to already be true, and each one fails in its own way if it is not.

| Check | How | Why it matters |
|---|---|---|
| The site is on HTTPS | open `https://jaygroup.local` in a browser | The endpoint answers `403 HTTPS required` before it reads the token. Settings > WP MCP prints a red notice and refuses to show you an address when this is wrong. |
| The plugin is active | Plugins screen | Without it the URL is `404 rest_no_route`. |
| `wp-content/debug.log` is being written | `WP_DEBUG` and `WP_DEBUG_LOG` true in `wp-config.php` | Every refusal this endpoint makes is byte-identical on the wire. **The log is the only place that says which one it was.** Tail it while you do this. |

---

## 1. Mint the token

1. **wp-admin > Settings > WP MCP** (`/wp-admin/options-general.php?page=wp-mcp`).
2. Under **Generate a token**:
   - **Scope** — `read` to start. Read-scope is the honest first test: it proves the
     handshake and the tool list without giving an agent a single write. Come back for
     `admin` once the list appears.
   - **Runs as** — the WordPress user the token authenticates as. *That user's capabilities
     are the ceiling on everything the connector can see or do.* Default is you.
   - **Label** — `claude desktop`. You will want to recognise it in the token table.
   - **Expires in (hours)** — **12 is the hard cap and there is no way to raise it.** The
     connector stops working when the token expires; you re-mint and re-paste. This is by
     design, not an oversight.
3. **Generate token**.
4. The green notice shows the token **once**. On an HTTPS site the copy field already
   contains the full URL form:

   ```
   https://jaygroup.local/wp-json/wpmcp/mcp/<64 lowercase hex characters>
   ```

   Hit **Copy**. If you lose it, revoke the row and mint another — the site stores only a
   SHA-256 hash and cannot show it again.

**The token is in the URL path on purpose.** Claude's connector UI has no field for a
request header, so `Authorization: Bearer` — the form that keeps the token out of access
logs, and the one the test suite uses — is not available here. The path form exists for
exactly this client. It means the token appears in your web server's access log, which is
an accepted trade for a 12-hour credential on your own machine.

---

## 2. Trust Local's certificate

Local by Flywheel issues its own certificate for `*.local`. It is not signed by anything
your OS trusts until you say so, and a client that cannot verify it will refuse the
connection — usually with nothing more useful than "could not connect".

**In Local: select the site > the SSL row on the site's overview > click `Trust`.**

That is the whole procedure. Local installs the site's certificate into the Windows
certificate store for you, and Windows will prompt you to confirm. Do not go looking for a
`certutil` command line — Local's button is the supported path and it is doing the same job.

Then **quit Claude Desktop completely and start it again.** It reads the trust store at
startup; trusting the certificate under a running client changes nothing until it restarts.

If the browser at `https://jaygroup.local` shows a padlock with no warning, the trust took.

---

## 3. Add the connector

In Claude Desktop: **Settings > Connectors > Add custom connector**, paste the URL from
step 1, and save. (Label wording moves between Claude Desktop versions — look for the
option that takes a **remote MCP server URL**, not the one that edits a local JSON config
for a stdio server. This endpoint speaks HTTP and has no stdio transport.)

Claude Desktop then performs the handshake by itself. Nothing else is required: this server
issues no session id, asks for no OAuth, and keeps no state between requests.

---

## 4. What success looks like

The connector appears as **connected** / **enabled**, and expanding it lists its tools.

With a **`read`-scope token — exactly 7 tools:**

```
site-info      list-posts     get-post      list-terms
list-media     get-media      list-comments
```

With an **`admin`-scope token — 16**: those 7 plus `create-post`, `update-post`,
`delete-post`, `create-term`, `delete-term`, `upload-media`, `delete-media`,
`moderate-comment`, `reply-comment`. **20** if you have also ticked *Enable code-edit tools*
in Settings > WP MCP, which adds `code-list`, `code-read`, `code-write`, `code-delete`.

A read-scope token does not show the write tools greyed out — they are **not in the list at
all**. The list you see is already what the token may do.

Then ask Claude something that needs a tool — *"what WordPress site am I connected to?"*
should get it to call `site-info` and come back with your site's name, URL and WordPress
version. Watch the **Uses** and **Bound IP** columns in Settings > WP MCP fill in: the
token pins to the IP of its **first tool call** and every later request must come from
there.

**The gate has passed when you have seen the tool list and one tool return real data from
the site.** Nothing in this repository can assert that for you.

---

## 5. When it does not connect

Check `wp-content/debug.log` first. Every decision this endpoint makes writes one line
beginning `wp-mcp auth`, and the line names the cause that the wire deliberately does not.

| Log line | What happened | Fix |
|---|---|---|
| *(nothing at all)* | The request never reached WordPress. | Certificate not trusted (step 2), client not restarted, or — see below — the client is not connecting from this machine. |
| `wp-mcp auth origin_deny origin=...` | The client sent an `Origin` header that is not one of this site's own, and the CSRF gate refused it. | Expected for a desktop app if it sends one. Add it: `add_filter('wpmcp_allowed_origins', fn($o) => array_merge($o, ['https://claude.ai']));` in a mu-plugin, using **the exact origin the log line printed**. |
| `wp-mcp auth insecure_deny` | The request arrived as plain HTTP. | The URL must be `https://`. |
| `wp-mcp auth content_type_deny content_type=...` | The POST was not `application/json`. | A client bug; report the value. |
| `wp-mcp auth validate_fail reason=expired` | 12 hours are up. | Mint a new token, paste the new URL. |
| `wp-mcp auth validate_fail reason=ip_mismatch` | The token is pinned to a different IP than the one this request came from. | Mint a fresh token. The pin is per-token and permanent. |
| `wp-mcp auth validate_fail reason=not_found` | The token in the URL is not in the table. | Truncated paste, or the row was revoked. |

**The one failure no certificate can fix.** If the log stays empty and the browser is happy,
the client is not dialling `jaygroup.local` from your machine at all — a connector fetched
from Anthropic's servers cannot resolve a `.local` hostname or reach your laptop, and no
amount of local trust changes that. **That outcome is itself the gate's finding, and it is
worth writing down rather than working around.** The fallback, if you want the manual check
anyway, is to put the site behind a tunnel with a publicly valid certificate (`cloudflared
tunnel`, `ngrok http`) and mint a token for *that* hostname — the endpoint does not care
which host it is reached on, only that the scheme is HTTPS.

---

## 6. Afterwards

**Revoke the token.** Settings > WP MCP, the token table, the revoke button on that row.
It would expire within 12 hours regardless, but a connector you are done testing is a
credential with no owner watching it.
