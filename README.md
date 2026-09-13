# WP MCP

WP MCP is a self-hosted WordPress plugin that lets an AI assistant work on your site over
the [Model Context Protocol](https://modelcontextprotocol.io). You mint a short-lived
token in your dashboard, pick the WordPress user it authenticates as, paste it into your
client, and the client gets a tool list bounded by that user's capabilities. There is no
external service, no OAuth app to register and nothing vendored at runtime: a handful of
PHP files, a token table, and one REST route that stays dormant until a live token exists.

## Requirements

| | |
|---|---|
| PHP | 8.1 or newer |
| WordPress | 5.5 or newer |
| HTTPS | required; the endpoint refuses plaintext with 403 before it reads the token |

The WordPress floor is `wp_new_comment()`, the function `reply-comment` hands its comment
to. Core's history for it reads `@since 5.5.0 Introduced the comment_type argument`: from
5.5 that key in the data you pass is an input the function reads, defaulting to `comment`
when it is empty. `reply-comment` passes it, so on anything older it is passing an argument
the function did not take. Everything else the plugin calls is older than 5.5.

HTTPS is not optional, and behind a proxy it needs one line of configuration. Read
[HTTPS enforcement depends on your proxy](#https-enforcement-depends-on-your-proxy)
before you put this on a production host.

## Install

1. Download [`wp-mcp.zip`](https://github.com/mkonstan/wp-mcp/releases/latest/download/wp-mcp.zip).
2. **Plugins > Add New > Upload Plugin**, choose the zip, **Install**, then **Activate**.
3. A new page appears under **Settings > WP MCP**.

Or clone the repository into `wp-content/plugins/wp-mcp/`.

Activation creates the token table and the trace log directory. Deleting the plugin
removes both, along with the plugin's options and its cron hook. Deactivating leaves
everything in place.

## Mint a token

**Settings > WP MCP**. Four fields decide what the token can do.

**Runs as** is the WordPress user the token authenticates as. Every request runs as that
user, so that user's capabilities are the ceiling on what the token can see or change. A
token minted for an Editor cannot read another author's private post, cannot delete
someone else's page, and sees only approved comments unless that Editor holds
`moderate_comments`. Deleting the user stops the token working. The field defaults to you.

**Scope** narrows from there. A `read` token is served the seven read tools and nothing
else: the write tools are not listed to it, and are refused if it calls one anyway. An
`admin` token is served sixteen, those seven plus the nine that write, and four more when
code editing is switched on. Scope only subtracts. It cannot hand a token a capability its
user does not have.

**Expires in** is capped at 12 hours with no override. The client stops working when the
token expires; you mint another and paste it again.

**Label** is for you, so the active-tokens table means something a day later.

The token is shown once. Only its SHA-256 hash is stored, so the page cannot show it
again. The table below it lists what is live, the user each token runs as, the IP it bound
to and its last use, with a revoke button per row.

## Connect a client

The endpoint speaks JSON-RPC over HTTP at `/wp-json/wpmcp/mcp`. The token travels one of
two ways, and which one you can use is decided by the client.

Header form, for a client that can send request headers (Claude Code, most CLI and library
clients). The token stays out of your web server's access log:

```json
{
  "mcpServers": {
    "wp-mcp": {
      "type": "http",
      "url": "https://your-site.example/wp-json/wpmcp/mcp",
      "headers": { "Authorization": "Bearer YOUR_TOKEN" }
    }
  }
}
```

Path form, where the whole address is the credential:

```
https://your-site.example/wp-json/wpmcp/mcp/YOUR_TOKEN
```

Claude Desktop and claude.ai custom connectors have no field for a request header, so the
path form is the only one they can use. That puts the token in your access log, which is
part of why the expiry is capped. Both forms are validated identically.

A Desktop or claude.ai connector also dials from Anthropic's servers rather than from your
machine, so it can only reach a site on the public internet with a publicly valid
certificate. A `.local` development site cannot be connected that way at all. The Claude
Code CLI dials from your own machine and can.

[docs/CONNECT-CLIENTS.md](docs/CONNECT-CLIENTS.md) has the walkthrough for both, and a
table of log lines to check when a client will not connect.

## The tools

Twenty tools. Each declares the four MCP annotation hints, so a client can tell a listing
from a deletion before it asks you to approve anything.

| Tool | Scope | readOnly | destructive | idempotent | openWorld |
|---|---|:--:|:--:|:--:|:--:|
| `site-info` | read | yes | no | yes | no |
| `list-posts` | read | yes | no | yes | no |
| `get-post` | read | yes | no | yes | no |
| `list-terms` | read | yes | no | yes | no |
| `list-media` | read | yes | no | yes | no |
| `get-media` | read | yes | no | yes | no |
| `list-comments` | read | yes | no | yes | no |
| `create-post` | admin | no | no | no | no |
| `update-post` | admin | no | yes | yes | no |
| `delete-post` | admin | no | yes | yes | no |
| `create-term` | admin | no | no | no | no |
| `delete-term` | admin | no | yes | yes | no |
| `upload-media` | admin | no | no | no | yes |
| `delete-media` | admin | no | yes | yes | no |
| `moderate-comment` | admin | no | yes | yes | no |
| `reply-comment` | admin | no | no | no | no |
| `code-list` | admin + code editing | no | no | yes | no |
| `code-read` | admin + code editing | no | no | yes | no |
| `code-write` | admin + code editing | no | yes | no | no |
| `code-delete` | admin + code editing | no | yes | yes | no |

Three rows in that table need a sentence.

`readOnlyHint` is the inverse of the scope gate, not of what the tool does to your
database. `code-list` and `code-read` only look, but they sit behind the admin gate with
the other code tools, so they report `false`.

`destructiveHint: false` is MCP's own narrow promise that an update is additive. The four
tools that make a new object per call keep it. `update-post` does not: it replaces every
field it is given, and its `terms` argument replaces the post's terms in that taxonomy
rather than adding to them.

`openWorldHint` is true for `upload-media` alone, which fetches a URL you supply. Every
other tool's reach stops at this site's database and active theme.

`create-post` defaults to `draft`. `delete-post` and `delete-media` trash unless you pass
`force: true`. Every argument is validated against the tool's schema before the tool runs:
a wrong type or an unknown key comes back as an error naming the field, and the tool never
executes.

## HTTPS enforcement depends on your proxy

The endpoint refuses any request that is not over HTTPS with 403, before the token is
read. It decides with WordPress's `is_ssl()`, which reads what the web server told PHP,
which on a proxied deployment is a header. WordPress cannot tell whether that header came
from your proxy or from the client.

So your reverse proxy must set `X-Forwarded-Proto` itself, and must never pass the
client's value through. In nginx:

```nginx
proxy_set_header X-Forwarded-Proto $scheme;
```

A proxy, CDN or development stack that forwards the client's value makes this gate
advisory. A client can then POST over plain HTTP with `X-Forwarded-Proto: https` and be
accepted, with the token in cleartext. `composer test:infra` asks a running host whether
that is the case, and it is expected to fail on Local by Flywheel, whose router forwards
the client's header. That pair of tests is the only part of the suite that needs a host
with TLS, which is why it has its own command rather than living in the main run.

For a development site with no certificate, and nowhere else,
`define('WPMCP_ALLOW_INSECURE', true);` in `wp-config.php` turns the gate off.

## The trace log

An unexpected failure returns one generic JSON-RPC error, `-32603` with an eight-character
trace id, and nothing else. The class, message, file, line and stack go to a private log,
so the trace id is something you can look up and a client cannot read.

The log lives at `wp-content/wpmcp/trace-<32 hex>.log`. The random name is generated once
per site and kept in an option, so the URL cannot be derived from anything a client sees.
Once a day the plugin fetches that URL itself, and if the web server answers `200` it
raises an error notice on every admin screen for anyone holding `manage_options`. The same
place warns you when the directory is not writable and traces are going to the PHP error
log instead.

The directory ships with an `index.php` and an `.htaccess`, which covers Apache. nginx
reads neither, so deny the directory in your server configuration:

```nginx
location ^~ /wp-content/wpmcp/ { deny all; }
```

## Code editing (opt-in)

Off by default. Switching it on in **Settings > WP MCP** gives an admin token four tools
that read and write files inside the active theme. The file API is fenced:

- Confined to the active theme directory. `..` traversal and symlinks pointing out are
  rejected on read, write and delete, including the `.bak` path.
- A configurable denylist (default `functions.php`, `index.php`, `inc/`, `includes/`,
  `lib/`) is never read or written.
- Text extensions only, size-capped per write.
- Every write copies the old file aside first. PHP is parse-checked and reverted
  automatically on a syntax error, so a broken edit does not stick.

What that fence does and does not cover is in [SECURITY.md](SECURITY.md). Read it before
enabling this: an admin token with code editing on can run PHP on your server.

## Hooks

Five, all of them stable surface in 1.0.

`wpmcp_tools` (filter) adds your own tools to the catalog. It runs on every request, after
the built-ins are assembled and before scope filtering. An entry must declare a boolean
`write`, a string `description`, an array `inputSchema`, all four boolean `annotations`,
and a callable `run`. An entry missing any of them is refused at registration rather than
given a default, and it cannot re-declare a built-in's name.

Two things about the description. A `description`, or any
`inputSchema.properties.*.description`, over 1,000 characters is refused: clients cap
these by truncating, so the end of a long one would silently never reach the model. And
keep the first sentence under 50 characters, because clients show only that until the tool
loads.

```php
add_filter('wpmcp_tools', function ($tools) {
    $tools['say-hello'] = array(
        'write'       => false,
        'description' => 'Returns a greeting.',
        'annotations' => array(
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ),
        'inputSchema' => array('type' => 'object', 'properties' => new stdClass()),
        'run'         => function ($args) { return array('message' => 'hello'); },
    );
    return $tools;
});
```

`wpmcp_allowed_origins` (filter) adds origins to the CSRF check. A request carrying a
browser `Origin` that is not one of the site's own is refused with 403; an absent
`Origin`, which is what non-browser clients send, is allowed.

```php
add_filter('wpmcp_allowed_origins', fn($o) => array_merge($o, ['https://claude.ai']));
```

`wpmcp_client_ip` (filter) supplies the real client IP. Behind a proxy `REMOTE_ADDR` is
the proxy, which makes the IP pin see every client as the same machine. Only trust a
forwarded header from a proxy you control.

`wpmcp_auth_event` (action) reports what the wire deliberately does not: all six ways a
token can fail are one byte-identical 401, and the reason lives here. It receives the event
type and a context array, and every context carries `ip`.

There is no success event. A request that is accepted fires nothing, apart from `pin_bind`
the first time a token calls a tool, so an audit listener that waits for an "ok" waits
forever. The ten types:

| `$type` | Fired when | Context beyond `ip` |
|---|---|---|
| `mint` | a token was created | `token_id`, `user_id`, `created_by`, `scope`, `ttl` |
| `revoke` | a token row was deleted | `token_id`, `user_id` |
| `validate_fail` | a token was refused | `reason`, sometimes `token_id` and `user_id` |
| `pin_bind` | a token's IP was pinned by its first tool call | `token_id`, `user_id` |
| `scope_deny` | a read token asked for a write tool | `token_id`, `user_id`, `tool`, `scope` |
| `origin_deny` | the `Origin` header was not one of ours | `origin` |
| `insecure_deny` | the request was not over HTTPS | nothing |
| `content_type_deny` | the POST was not `application/json` | `content_type` |
| `body_too_large` | `Content-Length` over the cap | `length` |
| `registry_reject` | a filter-added tool was refused at registration | `tool`, `reason` |

`reason` on `validate_fail` is one of `missing`, `malformed`, `not_found`, `user_missing`,
`expired`, `ip_mismatch`. A context never contains a token or its hash.

```php
add_action('wpmcp_auth_event', function ($type, $context) {
    if ($type === 'validate_fail') { /* $context['reason'], $context['ip'] */ }
}, 10, 2);
```

`wpmcp_auth_event_redacted_keys` (filter) adds key names to redact from that context
array. Tokens, hashes and authorization headers are redacted already, at every depth.

## Testing

Dev dependencies only; the plugin itself is plain PHP with nothing vendored. `composer
install`, then:

| Command | What it runs | What it needs |
|---|---|---|
| `composer test` | Both tiers below. | Nothing, though integration self-skips without a site. |
| `composer test:unit` | Pure PHP: framing, the schema validator, serialization, version negotiation, cursors, the version invariant. | PHP 8.1+. Seconds. |
| `composer test:integration` | Black-box HTTP against a real site: real users, real roles, real capability checks, real TLS, both token forms. | `WPMCP_TEST_URL`, and `wp` on PATH to seed fixtures. Minutes. |
| `composer test:infra` | Two tests that only a host with real TLS can answer: that a valid token over plain HTTP is refused, and that a forwarded `X-Forwarded-Proto` cannot talk its way past that refusal. | An `https://` host at `WPMCP_TEST_URL`. Fails rather than skips on a host without TLS. Out of `composer test` and out of CI. |
| `composer test:client` | A real MCP client (the Claude Code CLI) handshakes with your site, lists its tools, calls one, and the answer is checked against your database. | `claude` on PATH, and one Claude API call. Out of `composer test` and out of CI. |

`test:client` is the only test here that is not our own client asserting our own beliefs.
It sends what a real client actually sends, and the value it asserts is read straight from
your database, so the model had no other way to know it. Run it by hand when the
handshake, the transport or the tool registry changes. See
[docs/CONNECT-CLIENTS.md](docs/CONNECT-CLIENTS.md).

## Reference

- [ARCHITECTURE.md](ARCHITECTURE.md) for the request lifecycle and the gate order.
- [SECURITY.md](SECURITY.md) for the threat model and the known limits.
- [docs/CONFORMANCE.md](docs/CONFORMANCE.md) for the MCP revision, what is served, and
  what is deliberately not.
- [docs/CONNECT-CLIENTS.md](docs/CONNECT-CLIENTS.md) for connecting a real client.
- [docs/RELEASE.md](docs/RELEASE.md) for cutting a release.
- [CHANGELOG.md](CHANGELOG.md).

## Support

WP MCP is free and GPL-licensed, so use it however you like. If you are putting it to use
and you want to, you can [sponsor me on GitHub](https://github.com/sponsors/mkonstan) and
buy me a coffee. Entirely optional; the plugin is and stays free.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).

## Credits

Built by Max Konstantinovski, with Claude. Designed, implemented, reviewed and hardened
collaboratively with AI. See [BUILD-NOTES.md](BUILD-NOTES.md).
