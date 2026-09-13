# Connecting a real MCP client

There are two paths, and which one is open to you is decided by where the client dials
from.

| Client | Dials from | Works against |
|---|---|---|
| Claude Code CLI | your own machine | any site your machine can reach, a Local site like `example.local` included |
| Claude Desktop or a claude.ai custom connector | Anthropic's infrastructure | only a publicly reachable HTTPS site with a publicly valid certificate |

A `.local` site cannot be used with a Claude Desktop or claude.ai custom connector at all.
The request is made from Anthropic's servers, which cannot resolve the hostname or route to
your machine, and no amount of local certificate trust changes that.

So the automated client check runs against the Claude Code CLI (section 1), and the
Desktop and claude.ai walkthrough (section 2) is for a real site.

---

## 1. Claude Code CLI, the automated check

```bash
source bin/local-env.sh
composer test:client          # or: bash bin/claude-code-smoke.sh
```

`bin/claude-code-smoke.sh` does the whole exchange and cleans up after itself:

1. Mints a fresh read-scope token for user 1 with `wp eval`, labelled for this run.
2. Writes a throwaway `.mcp.json` and `CLAUDE.md` into a temp directory.
3. Runs `claude -p` against that config and asks it to call `site-info`.
4. Asserts the output contains the site name and WordPress version read from the database.
   Those two values appear nowhere in the prompt, the `CLAUDE.md` or the URL, so the only
   way they can be in the output is a completed handshake, a served tool list, and a tool
   that actually ran.
5. Revokes the token and removes the temp directory on any exit, through a trap.

Real output, against a Local site, 2026-09-12:

```
smoke: claude 2.1.266 (Claude Code)
smoke: site   https://example.local
smoke: NODE_EXTRA_CA_CERTS=%APPDATA%\Local\run\router\nginx\certs\example.local.crt
smoke: expecting the tool to report site name 'Example' and WordPress 7.1
smoke: token minted (read scope, 15 min, label wpmcp-test-claude-code-...).
smoke: running the client...
{"name":"Example","url":"https:\/\/example.local","wp_version":"7.1","active_theme":"Example Theme","active_plugins":18}
smoke: PASS - the client reported 'Example' on WordPress 7.1, both read from the database ...
smoke: token revoked.
```

### Two things that had to be measured rather than assumed

Node and Local's certificate. Local issues a per-site certificate that is its own CA, and
checking one with `openssl_x509_parse` shows it self-issued, `CA:TRUE`, with the site host
as CN. So `NODE_EXTRA_CA_CERTS` pointed at that one file is enough and there is no separate
Local root CA to go looking for. Local's Trust button writes to the Windows store, which
Node does not read, so clicking it does not help here. The script finds
`%APPDATA%\Local\run\router\nginx\certs\<host>.crt` on its own.
`NODE_TLS_REJECT_UNAUTHORIZED=0` is deliberately not used, because it would disable
verification for every connection the client makes, Anthropic's included.

Trusting a project-scope `.mcp.json`. A `.mcp.json` merely discovered in the working
directory has to be approved interactively the first time, which a `-p` run cannot do. What
works is passing the same file explicitly, with `--mcp-config <file> --strict-mcp-config`:
it becomes this invocation's configuration rather than a project's, and nothing else on the
machine is loaded alongside it. `claude mcp add --transport http --scope user` also works,
but it writes to the real user configuration and a crashed run would leave it behind. Tool
permission is granted with `--allowed-tools mcp__wpmcp__site-info`, which is one read-only
tool rather than `--dangerously-skip-permissions`.

### Doing it by hand

```bash
claude mcp add --transport http wpmcp "https://<site>/wp-json/wpmcp/mcp/<token>"
claude            # then: /mcp   to see the server, and ask it to call site-info
claude mcp remove wpmcp
```

---

## 2. Claude Desktop and claude.ai, against a public site

This needs a site on the public internet, over HTTPS, with a certificate the world already
trusts. Everything below assumes `https://example.com` is such a site and the plugin is
active on it.

### Mint the token

1. Go to wp-admin > Settings > WP MCP (`/wp-admin/options-general.php?page=wp-mcp`).
2. Scope: pick `read` first. A read-scope token proves the handshake and the tool list
   without handing an agent a single write. Come back for `admin` once the list appears.
3. Runs as: the WordPress user the token authenticates as. That user's capabilities are the
   ceiling on everything the connector can see or do.
4. Label: something you will recognise in the token table.
5. Expires in (hours): 12 is a hard cap with no override. The connector stops working when
   the token expires, and you re-mint and re-paste. That is the design.
6. Generate token. The green notice shows it once. The site stores only a SHA-256 hash and
   cannot show it again. On an HTTPS site the copy field already holds the full URL:

   ```
   https://example.com/wp-json/wpmcp/mcp/<64 lowercase hex characters>
   ```

The token is in the URL path on purpose. Claude's connector UI has no field for a request
header, so `Authorization: Bearer`, the form that keeps the token out of access logs and the
one the test suite uses, is not available here. The path form exists for exactly this client.
It does mean the token appears in your web server's access log, which is an accepted trade
for a credential that dies within 12 hours.

### Add the connector

Go to Settings > Connectors > Add custom connector, paste that URL, and save. Look for the
option that takes a remote MCP server URL rather than the one that edits a local JSON config
for a stdio server, because this endpoint speaks HTTP and has no stdio transport. Nothing
else is needed: no OAuth, no session id, no state between requests.

### What success looks like

The connector shows as connected and lists its tools.

A `read`-scope token gets exactly 7:

```
site-info      list-posts     get-post      list-terms
list-media     get-media      list-comments
```

An `admin`-scope token gets 16, those 7 plus `create-post`, `update-post`, `delete-post`,
`create-term`, `delete-term`, `upload-media`, `delete-media`, `moderate-comment` and
`reply-comment`. It gets 20 if Enable code-edit tools is also ticked in Settings > WP MCP,
which adds `code-list`, `code-read`, `code-write` and `code-delete`.

Write tools are not greyed out for a read token. They are not in the list at all, so the
list you see is already what the token may do.

Then ask for something that needs a tool. "What WordPress site am I connected to?" should
call `site-info` and come back with your site's name, URL, WordPress version, active theme
and plugin count. Watch Uses and Bound IP fill in on the admin page: the token pins to the
IP of its first tool call, and every later request has to come from there.

---

## 3. When it does not connect

Check `wp-content/debug.log` first, with `WP_DEBUG` and `WP_DEBUG_LOG` on. Every refusal
this endpoint makes writes one line beginning `wp-mcp auth`, and that line names the cause
the wire deliberately does not: all six token failures are one byte-identical 401. An
accepted request writes nothing, apart from one `pin_bind` line the first time a token calls
a tool, so silence after a working connection is normal.

| Log line | What happened | Fix |
|---|---|---|
| *(nothing at all)* | The request never reached WordPress. | DNS or firewall. For a Desktop or claude.ai connector against a non-public host, the client is not dialling your machine at all. See the table at the top. |
| `origin_deny origin=...` | The client sent an `Origin` that is not one of this site's own, and the CSRF gate refused it. | Add the exact origin the log printed: `add_filter('wpmcp_allowed_origins', fn($o) => array_merge($o, ['https://claude.ai']));` |
| `insecure_deny` | The request arrived as plain HTTP. | The URL must be `https://`. |
| `content_type_deny content_type=...` | The POST was not `application/json`. | A client bug. Report the value. |
| `validate_fail reason=expired` | 12 hours are up. | Mint a new token and paste the new URL. |
| `validate_fail reason=ip_mismatch` | The token is pinned to a different IP than this request came from. | Mint a fresh token. The pin is per token and permanent. |
| `validate_fail reason=not_found` | The token is not in the table. | A truncated paste, or the row was revoked. |
| HTTP 400, `-32600`, "Unsupported MCP-Protocol-Version" | The client declared a revision this server does not speak. | The message names the three it does. There is nothing to configure, so report the value. |

---

## 4. Afterwards

Revoke the token in Settings > WP MCP, with the revoke button on that row. It would expire
within 12 hours anyway, but a connector you are done with is a credential nobody is
watching.
