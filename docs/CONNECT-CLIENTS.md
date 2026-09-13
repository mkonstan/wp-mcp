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
claude mcp add --transport http wpmcp "https://<site>/wp-json/wpmcp/mcp"   --header "Authorization: Bearer <token>"
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
5. Active window (hours): 6 by default, 12 at most. This is how long the token answers
   before it goes **dormant** - refused, but renewable in one click, with the token itself
   unchanged. Lifetime (days): 30 by default, 365 at most; that is the hard end, past which
   the token is **dead** and only a new mint helps.

   For a connector, take the defaults or raise the lifetime. Claude cannot edit a
   connector's request header once the connector has been added, so a new *token* means
   deleting and re-adding the connector - while a Renew costs one click and the connector
   never notices. Short window, long lifetime, Renew when it goes dormant.
6. Generate token. The green notice shows it once, with the URL, the header line and the
   recipe below already filled in. The site stores only a SHA-256 hash and cannot show the
   token again.

The token travels in a header and nowhere else:

```
URL:    https://example.com/wp-json/wpmcp/mcp
Header: Authorization: Bearer <64 lowercase hex characters>
```

The URL is constant for the life of the site. A URL that carried the token used to be
accepted and is not any more - it lands in every access log, proxy log and browser history
it passes through, and a hosted connector re-sends it for months. `/wp-json/wpmcp/mcp/`
followed by a token is now a plain `404`.

### Add the connector

Settings > Connectors > **Add custom connector**. Look for the option that takes a remote
MCP server URL rather than the one that edits a local JSON config for a stdio server: this
endpoint speaks HTTP and has no stdio transport.

1. **URL**: `https://example.com/wp-json/wpmcp/mcp`
2. **Authentication**: *No sign-in*. This server does not speak OAuth, and the connector
   does not need it.
3. **Request headers** (under Advanced settings): name `authorization`, value
   `Bearer <your token>`.

Nothing else is needed: no session id, no state between requests.

Measured on a public test site, 2026-09-13: claude.ai delivers that header intact on every
call, and **cannot edit it once the connector has been added**. Changing the token means
deleting the connector and adding it again.

Also measured: during *Connect*, claude.ai first probes the URL with **no credential at
all**. One `validate_fail reason=missing` line in the log at connect time is normal and does
not stop the connector from connecting.

**If the connector will not authenticate and the log says `reason=missing` every time**, the
web server is eating the header. Apache running PHP as CGI or FastCGI does not pass
`Authorization` to PHP at all. WordPress core handles that case itself - `get_headers()` in
`wp-includes/rest-api/class-wp-rest-server.php` maps `REDIRECT_HTTP_AUTHORIZATION` back onto
`AUTHORIZATION` - provided WordPress's own `.htaccess` block is present to do the
re-export. So the thing to check is the block, not the plugin:

```apache
RewriteRule ^ - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
```

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
and plugin count. Watch Last used and Uses fill in on the admin page.

The token is not tied to a client address. It cannot be: measured on a public test site on
2026-09-13, this connector called from `160.79.106.164`, `.185`, `.186` and `.187` inside
one minute, all four from the same session.

---

## 3. When it does not connect

Check `wp-content/debug.log` first, with `WP_DEBUG` and `WP_DEBUG_LOG` on. Every refusal
this endpoint makes writes one line beginning `wp-mcp auth`, and that line names the cause
the wire deliberately does not: all five token failures are one byte-identical 401. An
accepted request writes nothing at all, so silence after a working connection is normal.

| Log line | What happened | Fix |
|---|---|---|
| *(nothing at all)* | The request never reached WordPress. | DNS or firewall. For a Desktop or claude.ai connector against a non-public host, the client is not dialling your machine at all. See the table at the top. |
| `origin_deny origin=...` | The client sent an `Origin` that is not one of this site's own, and the CSRF gate refused it. | Add the exact origin the log printed: `add_filter('wpmcp_allowed_origins', fn($o) => array_merge($o, ['https://claude.ai']));` |
| `insecure_deny` | The request arrived as plain HTTP. | The URL must be `https://`. |
| `content_type_deny content_type=...` | The POST was not `application/json`. | A client bug. Report the value. |
| `validate_fail reason=missing` | No `Authorization: Bearer` header reached PHP. | Normal once, during a claude.ai *Connect* probe. Every time means the client is not sending it, or Apache under CGI/FastCGI is eating it - see the `.htaccess` block in section 2. |
| `validate_fail reason=dormant` | The active window has closed. | Press **Renew** on that row in Settings > WP MCP. The token does not change, so the connector needs no edit. |
| `validate_fail reason=expired` | The token is past its hard lifetime. | Mint a new token, then delete and re-add the connector with the new header value. |
| `validate_fail reason=not_found` | The token is not in the table. | A truncated paste, or the row was revoked. |
| HTTP 400, `-32600`, "Unsupported MCP-Protocol-Version" | The client declared a revision this server does not speak. | The message names the three it does. There is nothing to configure, so report the value. |

---

## 4. Afterwards

Revoke the token in Settings > WP MCP, with the revoke button on that row. Its active
window would close within hours anyway, but a dormant token is a renewable one, and a
connector you are done with is a credential nobody is watching.
