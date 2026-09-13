# Changelog

All notable changes to WP MCP. From 1.0.0 on, the version is semantic.

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
- Every accept and refusal fires a `wpmcp_auth_event` action once, carrying the reason the
  wire deliberately does not. Values are redacted at every depth before the action fires.

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

- An array body is refused with `-32600` and the message "batch not supported" rather than
  being half-processed.
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
  fully loaded. `site-info`, `list-comments` and `code-write` were reworded; the detail
  after the first sentence is unchanged.

### Uninstall

- Deleting the plugin now drops the token table, removes its options and the trace log
  directory, and clears the cron hook. Deactivating still leaves everything in place.

### Testing and release

- Two test tiers, one command each. `composer test:unit` is pure PHP. `composer
  test:integration` sends real HTTP at a real site with real users and roles.
- `composer test:client` runs a real MCP client (the Claude Code CLI) against your site
  and checks the answer against your database. It is out of `composer test` and out of CI
  because it costs an API call.
- `composer test:infra` asks your deployment, not the code, whether HTTPS enforcement is
  real.
- CI runs the unit suite on PHP 8.1 through 8.4 and the integration suite against a
  `wp-env` container, and asserts that each sprint gate actually executed rather than
  skipped. The release workflow cannot publish while any of it is red.


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
