# Changelog

All notable changes to WP MCP. From 1.0.0 on, the version is semantic.

## 1.1.0

**Unreleased.** The auth surface, rebuilt around what hosted MCP clients actually do.
Three breaking changes, all in how a token is presented and how long it lives. The tools,
the protocol negotiation and the wire format are untouched.

Upgrading is one database migration (schema revision 3) that runs on the first request
after the plugin files change. Existing tokens keep answering until exactly the moment
they always would have; at that moment they go **dormant** instead of vanishing, and an
admin can renew them for thirty days from when they were minted - see below.

### Breaking: the token travels in a header, and only in a header

- The route whose path carried the token, `/wp-json/wpmcp/mcp/<token>`, **is gone**. A URL
  with a token in it is now a plain REST `404`. Every client must send
  `Authorization: Bearer <64 lowercase hex>` against the constant URL
  `/wp-json/wpmcp/mcp`.
- **Why.** A URL is written into every access log, proxy log and browser history it passes
  through, and a hosted connector re-sends the same one for months. The path form existed
  because Claude Desktop and claude.ai were believed to have no field for a request
  header. Measured on a public test site on 2026-09-13, they do: the custom-connector
  *Request headers* setting delivers `authorization: Bearer <token>` intact.
- **What to change.** In a `.mcp.json`, move the token out of `url` and into a `headers`
  map. With the Claude Code CLI, `claude mcp add --transport http wpmcp <url> --header
  "Authorization: Bearer <token>"`. In claude.ai or Claude Desktop, *Add custom connector*
  → the URL → Authentication *No sign-in* → a request header named `authorization` with
  the value `Bearer <token>`.
- If every request is now refused with `reason=missing` although the client is sending the
  header, the web server is eating it: Apache running PHP as CGI or FastCGI does not pass
  `Authorization` to PHP. WordPress handles that itself, provided its own `.htaccess`
  block is present to re-export the value - so the thing to check is the block, not the
  plugin.

### Breaking: no IP pinning

- A token no longer locks to the address of its first tool call, and is no longer refused
  from anywhere else. The `bound_ip` column, the `pin_bind` and `ip_mismatch` events and
  the *Bound IP* admin column are all removed.
- **Why.** On a public test site on 2026-09-13 an Anthropic-hosted connector was observed
  calling from four egress addresses inside one minute - `160.79.106.164`, `.185`, `.186`,
  `.187`. The pin bound the token to whichever arrived first and answered `401` to the
  rest of the session. There is no single address to hold a token to.
- The caller's address is still recorded on every auth event. It decides nothing. Behind a
  proxy, use the `wpmcp_client_ip` filter so the log is worth reading.
- **What this costs**, stated plainly: a token copied out of a log is now usable from
  anywhere. The credential being a header rather than a URL, and the short active window
  below, are what carry that weight instead.

### Breaking: two timers per token, and Renew

- A token now has an **active window** and a **lifetime**, and the old single expiry is
  neither of them on its own.
  - *Active window*: 6 hours by default, 12 at most. While it is open the token answers.
    When it closes the token is **dormant** - refused exactly like any other bad
    credential, but its row survives and **Renew** restarts the window. The token itself
    does not change, so nothing holding it has to be edited.
  - *Lifetime*: 30 days by default, 365 at most. Past it the token is **dead**: Renew is
    refused and the hourly cleanup removes the row.
- **Why.** One expiry had to be short enough to bound a leak and long enough that a
  connector was not re-added twice a day, and claude.ai cannot edit a connector's header
  after the fact - so a new token means a new connector. Splitting the two lets the short
  number stay short.
- `wpmcp_mint()` takes two durations instead of one:
  `wpmcp_mint($scope, $label, $window_secs, $lifetime_secs, $user_id = 0)`. Any code
  calling it must be updated. `WPMCP_MAX_TTL` is replaced by `WPMCP_MAX_WINDOW` (12 h) and
  `WPMCP_MAX_LIFETIME` (365 d).
- `wpmcp_renew(int $id)` restarts a token's window. It works on an active or a dormant
  row, is refused on a dead one, and can never push the window past the lifetime.
- A refused token's row is **no longer deleted** when it is presented. That deletion is
  what made Renew impossible: by the time an admin saw the `401` there was nothing left to
  renew. Dead rows are removed by the hourly `wpmcp_flush_expired` cron, which leaves
  dormant rows alone.
- The auth events gain `renew` (`token_id`, `user_id`, `actor`, `window`); `mint` now
  carries `window` and `lifetime` instead of `ttl`; `validate_fail` gains the reason
  `dormant`. The full reason list is `missing`, `malformed`, `not_found`, `user_missing`,
  `dormant`, `expired` - all six are still one byte-identical `401` on the wire.

### Schema revision 3

Run automatically on the first request after the update, and idempotent.

- Adds `active_until` and `window_secs`; `expires_at` keeps its name and now means the
  hard lifetime.
- Backfills every existing row with both timers:
  - `active_until` becomes the old `expires_at`, so the token stops answering at exactly
    the moment it always would have. A token minted for twelve hours still answers for
    those twelve hours.
  - `window_secs` becomes however long the token was originally granted, capped at 12
    hours - so its first Renew gives it the window it had, and a hand-extended row cannot
    hand out a 90-day active window.
  - `expires_at` becomes `created_at` + 30 days, the same default a freshly minted token
    gets, **so the row is renewable**. It then goes dormant rather than being deleted, and
    Renew brings the same token back without the client being touched. A row whose old
    expiry is already further out than that keeps its old expiry rather than being
    shortened.
- Drops `bound_ip`, with an explicit `ALTER TABLE` guarded by a column-exists check,
  because `dbDelta()` only ever adds and widens and cannot drop a column.
- The revision is recorded only once every column exists and every backfill has succeeded,
  so a failed migration is retried on the next request rather than stamped and forgotten.

### Admin page

- The mint form asks for an active window in hours and a lifetime in days.
- The token is shown once, with the constant URL, the `Authorization` header line, a
  three-step recipe for a claude.ai or Claude Desktop custom connector, and the equivalent
  `claude mcp add` line.
- The table gains **Status** (active / dormant / dead, or *owner missing* when the
  WordPress user the token runs as has been deleted), *Active until* and *Lifetime ends*,
  and a **Renew** button beside Revoke - offered only on rows where renewing can actually
  work, so not on a dead row and not on one whose owner is gone. `wpmcp_renew()` refuses
  both, naming which.

### Docs and tooling

- `docs/CONNECT-CLIENTS.md` carries the connector recipe, the measured claude.ai
  behaviour (the header arrives intact, it cannot be edited afterwards, and *Connect*
  probes the URL with no credential at all - one `reason=missing` line at connect time is
  normal), the Apache `.htaccess` fix, and the renew workflow.
- `bin/claude-code-smoke.sh` uses the header form.
- README, SECURITY, ARCHITECTURE, CONFORMANCE and the knowledge base are updated
  throughout.

## 1.0.0

First release with a stable tool contract. Requires WordPress 5.5 and PHP 8.1.

If you are upgrading from 0.3.x, read the identity section first: existing tokens keep
working, but what they can reach is now decided by a WordPress user rather than by the
admin who minted them.

### Identity and authorization

- Every token is bound to a WordPress user, picked when you mint it and defaulting to
  you. The request runs as that user, so that user's capabilities set the ceiling and the
  token's scope narrows from there. Delete the user and the token stops working.
- The read tools honor those capabilities. `list-posts` no longer lists another author's
  private or draft posts, `get-post` refuses an id the user cannot read, and
  `list-comments` returns only what the user is allowed to see (approved comments unless
  they hold `moderate_comments`).
- The write tools check the capability for the thing being changed, on every mutating
  call, rather than matching against a list of tool names. A token whose user is an Editor
  can no longer delete another author's post through a tool that forgot to ask.
- `reply-comment` goes through `wp_new_comment()` instead of inserting the row itself, so
  Akismet, the blocklist, the moderation setting and the author notification mail all
  still happen.
- Upgrading adds a `user_id` column to the token table and backfills it from the minting
  admin. The check runs on load, not on activation, because activation does not fire for a
  plugin updated in place.
- Ten `wpmcp_auth_event` actions report what the wire deliberately does not: which of the
  six token failures it was, a refused scope, a refused origin, a tool refused at
  registration, a mint, a revoke, an IP pin. There is no success event, so a listener
  waiting for one waits forever. Values are redacted at every depth before the action
  fires, and a context never carries a token or its hash. README.md lists the ten.

### Transport

- HTTPS is required. A plaintext request is refused with 403 before the token is read.
  The gate uses `is_ssl()`, so it is only as strong as your proxy: the proxy must set
  `X-Forwarded-Proto` itself and never pass the client's value through. `composer
  test:infra` asks a running host whether that is true. `WPMCP_ALLOW_INSECURE` in
  `wp-config.php` turns the gate off for a local site with no certificate.
- A browser `Origin` must be one of the site's own, or 403. An absent `Origin`, which is
  what every non-browser client sends, is allowed. Add your own with the
  `wpmcp_allowed_origins` filter.
- A POST must be `application/json`, or 415. Anything other than POST and OPTIONS is
  refused before the token is looked up.
- All six ways a token can fail now return one byte-identical 401. Which one it was lives
  in `wpmcp_auth_event` and the debug log.
- A tool added through the `wpmcp_tools` filter without a `write` key is rejected at
  registration instead of silently becoming a read tool.

### Errors and the trace log

- One catch-all at the boundary. An unexpected failure is `-32603` "Internal error" plus
  an eight-character trace id on the wire, and nothing else. The class, message, file,
  line, `WP_Error` data and stack go to a private log under `wp-content/wpmcp/`, keyed by
  that id. No exception text reaches a client, and a test greps the code to keep it that
  way.
- The log file is named `trace-<32 hex>.log`, generated once per site, so its URL cannot
  be guessed from anything a client sees. A daily self-check fetches that URL and raises a
  site-wide admin warning if the web server serves it. A trace that cannot be written is
  reported rather than dropped.

### JSON-RPC framing

- An array body is refused with `-32600` and the message "Batch requests are not
  supported" rather than being half-processed.
- A notification (no `id`) is answered with 202 and an empty body. A request that carries
  an `id` is answered even when its method name looks like a notification.
- A body whose `Content-Length` exceeds 4 MiB is refused with 413 before the token is
  looked up.

### Handshake

- `initialize` negotiates the protocol revision. A revision this server does not know
  gets a successful response naming `2025-11-25`; a revision declared in the
  `MCP-Protocol-Version` header that it does not speak gets 400 with the list it does.
- A missing `MCP-Protocol-Version` header is treated as `2025-03-26`, which is what the
  specification asks for.
- The capability object lists `tools` and nothing else. Earlier versions advertised
  capabilities the server did not serve.

### Tool contract

- Arguments are checked against the tool's own schema before the tool runs. A wrong type
  or a missing required field comes back as `isError` with a JSON pointer to the field,
  and the tool never executes. Unknown keys are refused.
- All twenty tools declare `readOnlyHint`, `destructiveHint`, `idempotentHint` and
  `openWorldHint`, so a client can tell a listing from a deletion without reading the
  description.
- An empty object inside a schema serializes as `{}` rather than `[]`, which some clients
  rejected.
- A tool description, or any parameter description, over 1,000 characters is refused at
  registration. Clients cap these by truncating, so the end of a long one would never
  reach the model and nothing would say so.
- Every built-in description now opens with a verb-first summary that ends inside the
  first 50 characters, which is roughly all a client shows the model until the tool is
  fully loaded. `site-info`, `list-comments`, `code-write` and `code-delete` were
  reworded; the detail after the first sentence is unchanged.

### Uninstall

- Deleting the plugin now drops the token table, removes its options and the trace log
  directory, and clears the cron hook. Deactivating still leaves everything in place.

### Testing and release

- Two test tiers, one command each. `composer test:unit` is pure PHP. `composer
  test:integration` sends real HTTP at a real site with real users and roles.
- `composer test:client` runs a real MCP client (the Claude Code CLI) against your site
  and checks the answer against your database. It is out of `composer test` and out of CI
  because it costs an API call.
- `composer test:infra` holds the two checks that only a host with real TLS can answer:
  that a valid token over plain HTTP is refused, and that a forwarded `X-Forwarded-Proto`
  cannot talk its way past that refusal. It fails rather than skips on a host without
  TLS.
- CI runs the unit suite on PHP 8.1 through 8.4 and the integration suite against a
  `wp-env` container, and checks that every test in it actually ran rather than skipped.
  The release workflow cannot publish while any of it is red.


## 0.3.5
- Docs: the README install link now points to the auto-resolving latest-release asset (`releases/latest/download/wp-mcp.zip`) so it never goes stale between versions.

## 0.3.4
- Tooling only, no plugin behavior change. Added GitHub Actions CI: `php -l` on every push/PR plus a guard that the `wpmcp_bak_ok` auto-revert check stays intact.
- Added a release workflow: pushing a `vX.Y.Z` tag builds the install zip, verifies each file (hash + lint) and the auto-revert marker, then publishes a GitHub Release with the zip attached.

## 0.3.3
- Internal refactor, no change to the default tool set or behavior. Split the tool catalog into per-domain provider functions (`wpmcp_core_tools`, `wpmcp_content_tools`, `wpmcp_taxonomy_tools`, `wpmcp_media_tools`, `wpmcp_comment_tools`); `endpoint.php` is now pure transport.
- Added the `wpmcp_tools` filter so other plugins or themes can add their own tools (filter-added tools default to read scope unless they set `write => true`).
- Wrapped plugin startup in `wpmcp_bootstrap()`.
- Extracted shared guard helpers (editable-post, attachment, code-path) to cut duplication.
- Docs: added ARCHITECTURE.md (request lifecycle and design rationale); README links it.
- Minor: two type-cast cleanups; documented the intentional `token_get_all` parse check.

## 0.3.2
- IP pin now binds on the first **tool call**, not on the discovery handshake, so a client whose setup enumeration comes from a different IP than its live session no longer locks itself out. Once bound, the pin is enforced on every request (discovery included).
- Version-string hygiene.

## 0.3.0 - 0.3.1
- Added `Authorization: Bearer` header auth alongside the path-in-URL token, so the credential can stay out of server access logs. Both transports validate identically.
- Mint screen shows both the URL form and the header form.

## 0.2.0 - 0.2.2
- New content tools: `create-post`, `update-post`, `delete-post` (draft by default; delete trashes unless forced), `create-term`, `delete-term`, `list-terms`.
- New media tools: `list-media`, `get-media`, `upload-media` (URL sideload, size/timeout bounded), `delete-media`.
- New comment tools: `list-comments` (no emails), `moderate-comment`, `reply-comment`.
- Opt-in theme code editing (off by default): `code-list`, `code-read`, `code-write`, `code-delete`, jailed to the active theme with a denylist, backup, and PHP parse-check auto-revert.
- Scope-filtered tool listing: read tokens see only read tools.
- Hardening: theme-jail completeness against symlink escape (including the backup path), post-type allowlist on the post tools (no touching revisions, menu items, templates, attachments), upload size/timeout bounds, clean JSON-RPC parse-error responses.

## 0.1.0
- Initial release: token model (mint / validate / revoke / hourly flush of expired), 256-bit tokens hashed at rest, hard expiry capped at 12h, TOFU IP pinning, read/admin scopes.
- Admin settings page (mint, list, revoke).
- DIY MCP-over-HTTP endpoint (JSON-RPC: initialize, tools/list, tools/call, ping) with read-only tools: `site-info`, `list-posts`, `get-post`.
