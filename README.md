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

**Active window** and **Lifetime** are two separate timers, and the split is what lets a
token be both short-lived and long-lived at once.

The *active window* - 6 hours by default, 12 at most - is how long the token answers.
When it elapses the token goes **dormant**: refused with the same anonymous `401` as any
other bad credential, but its row stays in the table and **Renew** restarts the window.
The token itself never changes, so whatever is holding it needs no edit.

The *lifetime* - 30 days by default, 365 at most - is the hard end. Past it the token is
**dead**: Renew is not offered, the hourly cleanup removes the row, and the only way on is
a new token.

That is the shape the clients need. claude.ai and Claude Desktop cannot edit a connector's
request header once the connector has been added, so replacing a token means deleting and
re-adding the connector; with one timer, a cap short enough to matter made that a
twice-daily chore. Renew moves the window without touching the credential.

**Label** is for you, so the active-tokens table means something a day later.

The token is shown once. Only its SHA-256 hash is stored, so the page cannot show it
again. The table below it lists what is live, the user each token runs as, each token's
state (active / dormant / dead, or **owner missing** when the WordPress user it runs as
has been deleted), when its window and its lifetime end, and its last use, with **Renew**
and **Revoke** buttons per row. Renew is offered only where it can work: not on a dead row,
and not on one whose owner is gone.

When a client starts getting `401`, the table is where you find out which timer ran out:
dormant needs Renew and nothing else, dead needs a new token and one edit of the client.

## Connect a client

The endpoint speaks JSON-RPC over HTTP at `/wp-json/wpmcp/mcp`. That URL is constant for
the life of the site and never contains the token. There is exactly one way to present a
credential:

```
Authorization: Bearer <64 lowercase hex characters>
```

A URL that carried the token used to be accepted as well. It is gone: such a URL is written
into every access log, proxy log and browser history it passes through, and a hosted
connector keeps re-sending it for months. A request to that endpoint path with a token
appended to it is now a plain REST `404`.

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

```bash
claude mcp add --transport http wpmcp https://your-site.example/wp-json/wpmcp/mcp   --header "Authorization: Bearer YOUR_TOKEN"
```

claude.ai and Claude Desktop custom connectors send the header too: **Add custom
connector**, paste the URL, pick **No sign-in** for authentication, then add a request
header named `authorization` with the value `Bearer YOUR_TOKEN`.

**If every request is refused with `reason=missing` while you are certain the header is
being sent**, the web server is eating it. Apache running PHP as CGI or FastCGI does not
pass `Authorization` through to PHP. WordPress handles that itself, provided its own
`.htaccess` block is present - so check for this line rather than looking at the plugin:

```apache
RewriteRule ^ - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
```

A Desktop or claude.ai connector dials from Anthropic's servers rather than from your
machine, so it can only reach a site on the public internet with a publicly valid
certificate. A `.local` development site cannot be connected that way at all. The Claude
Code CLI dials from your own machine and can.

[docs/CONNECT-CLIENTS.md](docs/CONNECT-CLIENTS.md) has the walkthrough for both, and a
table of log lines to check when a client will not connect.

## The tools

Twenty-two tools. Each declares the four MCP annotation hints, so a client can tell a
listing from a deletion before it asks you to approve anything.

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
| `code-history` | admin + code editing | no | no | yes | no |
| `code-restore` | admin + code editing | no | yes | no | no |

Three rows in that table need a sentence.

`readOnlyHint` is the inverse of the scope gate, not of what the tool does to your
database. `code-list`, `code-read` and `code-history` only look, but they sit behind the
admin gate with the other code tools, so they report `false`. The active theme is source
code, not content.

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

Off by default. Switching it on in **Settings > WP MCP** gives an admin token six tools
that read and write files inside the active theme.

The switch is not the only thing that has to be true. If `DISALLOW_FILE_EDIT` or
`DISALLOW_FILE_MODS` is set in your `wp-config.php`, the six are **not listed at all**,
whatever the switch says - a tool that can never run is not advertised. Either constant
also turns the feature off for every token, including one minted before you set it. The
third gate is the token's user: they need `edit_themes`, and a token whose user does not
have it sees the tools listed (another token's user may) and is refused when it calls one.

The file API is fenced:

- Confined to the active theme directory. `..` traversal and symlinks pointing out are
  rejected on read, write and delete.
- **One file has one spelling.** Every path is resolved to its canonical form before
  anything acts on it, so `./inc/x.php`, `inc//x.php` and `inc/x.php` are the same file to
  the denylist, to the history and to the retention cap.
- A configurable denylist (default `functions.php`, `index.php`, `inc/`, `includes/`,
  `lib/`) is never read or written, under any spelling.
- Text extensions only, size-capped per write at 512 KB.
- **Every change is versioned into the database first.** Before `code-write` overwrites a
  file or `code-delete` removes one, the bytes that are there go into
  `{prefix}wpmcp_file_versions`. If they cannot be stored, the change does not happen.
- PHP is parse-checked after every write and reverted automatically on a syntax error, so
  a broken edit does not stick. The revert writes back the bytes that were just versioned.

### Versions, history and restore

`code-history {path}` lists what is stored for a file - newest first, with an id,
`saved_at`, `size`, `sha256`, the `reason` (`write`, `delete`, `restore` or `sweep`) and
the login of whoever caused it. A path nobody has changed returns an empty list, which is
not an error.

`code-restore {version_id}` writes one of them back. It resolves the stored path through
the same jail and denylist a caller's path goes through, versions the current contents
first (so a restore can itself be undone), applies the same parse check, and tells you
whether the bytes it wrote match the stored hash. A deleted file comes back this way.

Every version records **which theme it was taken from**. The code tools' jail is the
active theme, so `style.css` is a different file once you switch themes: `code-history`
lists only the active theme's versions, and `code-restore` refuses a version belonging to
another theme and names it. Switch back to that theme to restore it.

Twenty versions are kept per theme and path; storing a twenty-first drops the oldest.
Change that with the `wpmcp_file_versions_keep` filter. The table is dropped when the
plugin is deleted.

**Upgrading from 1.0.x.** Earlier versions backed a file up by writing a copy of it beside
the original inside the active theme. That copy is under your document root with an
extension nothing executes and nothing blocks, so its URL served the complete source of a
theme file to anybody who asked for it. The upgrade walks the active theme on the first
request after the plugin files change, moves those files into the versions table and
deletes them. It runs whether or not code editing is switched on, and running it twice does
nothing the second time.

It takes only what the code tools could give back: the original name (the one without the
backup extension) has to be a text extension this plugin writes, and the file has to be
inside the 512 KB cap. **Anything else is left exactly where it is** - including a backup
larger than the cap, and one that cannot be read. The upgrade writes a single line to your
PHP error log naming what it moved and what it left, so check it once after upgrading and
deal with anything still on disk yourself: those files are still being served.

One case is worth knowing about. A backup of `functions.php` **is** collected - `.php` is
a text extension - but `functions.php` itself is on the default denylist, so
`code-restore` will refuse to write it back. The bytes are in the table and the log line
gives the version id. Leaving the complete source of your theme's functions file readable
over HTTP is the worse of the two options.

What that fence does and does not cover is in [SECURITY.md](SECURITY.md). Read it before
enabling this: an admin token with code editing on can run PHP on your server.

## Hooks

Six. Five are stable surface from 1.0; `wpmcp_file_versions_keep` arrived with the code
tools' version store in 1.1.

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

`wpmcp_client_ip` (filter) supplies the real client IP, which is written to the auth
events and decides nothing. Behind a proxy `REMOTE_ADDR` is the proxy, so a log that is
worth reading needs this filter. Only trust a forwarded header from a proxy you control.

`wpmcp_auth_event` (action) reports what the wire deliberately does not: all five ways a
token can fail are one byte-identical 401, and the reason lives here. It receives the event
type and a context array, and every context carries `ip`.

There is no success event. A request that is accepted fires nothing at all, so an audit
listener that waits for an "ok" waits forever. The eleven types:

| `$type` | Fired when | Context beyond `ip` |
|---|---|---|
| `mint` | a token was created | `token_id`, `user_id`, `created_by`, `scope`, `window`, `lifetime` |
| `revoke` | a token row was deleted | `token_id`, `user_id` |
| `renew` | a token's active window was restarted | `token_id`, `user_id`, `actor`, `window` |
| `validate_fail` | a token was refused | `reason`, sometimes `token_id` and `user_id` |
| `scope_deny` | a read token asked for a write tool | `token_id`, `user_id`, `tool`, `scope` |
| `origin_deny` | the `Origin` header was not one of ours | `origin` |
| `insecure_deny` | the request was not over HTTPS | nothing |
| `content_type_deny` | the POST was not `application/json` | `content_type` |
| `body_too_large` | `Content-Length` over the cap | `length` |
| `registry_reject` | a filter-added tool was refused at registration | `tool`, `reason` |
| `stale_backup_sweep` | a schema upgrade swept the active theme and found backup files an older version had left there | `found`, `moved`, `skipped_extension`, `skipped_unreadable`, `skipped_too_big`, `skipped_undeletable`, `moved_paths`, `skipped_paths` |

`stale_backup_sweep` fires on the request that performs a schema upgrade, and only when
the sweep found something - so it is usually once, on the upgrade to 1.1, but any later
schema bump that finds a backup file in the theme fires it again.

`moved_paths` names each collected file with the version id it became and
`skipped_paths` names each one left on disk with the reason; both are capped at 25 entries
with an `and N more` tail, because the rest is in the table and this is one log line.

`reason` on `validate_fail` is one of `missing`, `malformed`, `not_found`, `user_missing`,
`dormant`, `expired`. The last two are the same `401` on the wire and different advice to
the operator: `dormant` means press Renew, `expired` means mint. A context never contains a
token or its hash.

```php
add_action('wpmcp_auth_event', function ($type, $context) {
    if ($type === 'validate_fail') { /* $context['reason'], $context['ip'] */ }
}, 10, 2);
```

`wpmcp_auth_event_redacted_keys` (filter) adds key names to redact from that context
array. Tokens, hashes and authorization headers are redacted already, at every depth.

`wpmcp_file_versions_keep` (filter) sets how many versions of one theme file the code
tools keep. The default is 20, pruned oldest-first on insert. A value that is not a
positive number is ignored rather than obeyed: "keep nothing" makes every write
unrecoverable and is far more likely to be a mistake than a decision.

```php
add_filter('wpmcp_file_versions_keep', fn() => 50);
```

## Testing

Dev dependencies only; the plugin itself is plain PHP with nothing vendored. `composer
install`, then:

| Command | What it runs | What it needs |
|---|---|---|
| `composer test` | Both tiers below. | Nothing, though integration self-skips without a site. |
| `composer test:unit` | Pure PHP: framing, the schema validator, serialization, version negotiation, cursors, the version invariant. | PHP 8.1+. Seconds. |
| `composer test:integration` | Black-box HTTP against a real site: real users, real roles, real capability checks, real TLS, the real credential header. | `WPMCP_TEST_URL`, and `wp` on PATH to seed fixtures. Minutes. |
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
