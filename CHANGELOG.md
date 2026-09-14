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

### Security: a theme file's backup is no longer served over the web

- **Nothing is written beside a theme file any more.** `code-write` used to copy the file
  it was about to overwrite to a sibling with a backup extension, and `code-delete`
  renamed the file to one instead of removing it. Both sat in the **active theme**, which
  is inside the document root, with an extension nothing executes and nothing blocks - so
  the URL returned the complete source of a theme file to anybody who guessed it. It was
  also a backup of exactly one generation: the next write overwrote the only copy.
- **Previous contents now go into a table**, `{prefix}wpmcp_file_versions`, which is the
  one store WordPress never serves. Schema revision 4 creates it and revision 5 adds the
  `theme` column below. Each row holds the
  path, the bytes, the size, a SHA-256, why it was stored, who caused it and which token
  they were using. Twenty versions are kept per path - change that with the new
  `wpmcp_file_versions_keep` filter - and the table is dropped when the plugin is deleted.
- **The upgrade collects what is already on disk.** On the first request after the plugin
  files change, the upgrade walks the active theme for the old sibling backups, stores each
  one under its original path with reason `sweep`, and deletes it. It follows no symlinks,
  it does not touch the live file next to a backup, it does not recreate a file whose
  deletion was deliberate, and running it again does nothing. It runs whether or not code
  editing is switched on: those files are on disk either way.
- **If a version cannot be stored, the change does not happen.** `code-write` and
  `code-delete` now return an error rather than touching a file they could not back up
  first. The PHP parse-error revert writes back the bytes it just versioned, from memory.
- `code-delete`'s `backup` return field is gone; both writers return `version_id` instead.
- **The sweep takes only what the tools could give back.** A backup whose original name is
  not a text extension this plugin writes, or that is over the 512 KB cap, or that cannot
  be read, is left exactly where it is and named in the log line - a file `code-restore`
  would refuse is a file the upgrade should not have taken. The report names every path it
  moved and every path it left, and it now fires late enough on `plugins_loaded` for the
  plugin's own log listener to hear it; before, on the path almost every upgrade takes, it
  went nowhere at all.

### Fixed: a leading `./` walked past the code-editing denylist

- `code-read`, `code-write`, `code-delete` and the new tools resolved a caller's path but
  matched the denylist against the caller's **spelling**, so `./inc/x.php` was allowed
  where `inc/x.php` was refused. Directory rules (`inc/`, `includes/`, `lib/`) were
  affected; bare-filename rules (`functions.php`) were not, because those match on
  basename. Measured on a live theme by review. Every path is now canonicalised from its
  resolved location before anything acts on it.
- The same fix ends a second defect that arrived with the version table: the spelling was
  its lookup key, so `./style.css` and `style.css` had separate histories and separate
  retention caps, and `code-history style.css` after a `code-write ./style.css` returned
  nothing.
- **Versions record their theme.** The jail is the active theme, so `style.css` is a
  different file after a theme switch. `code-history` lists only the active theme's
  versions and `code-restore` refuses one belonging to another theme, naming it.

### New: `code-history` and `code-restore`

- `code-history {path}` lists the stored versions of one file, newest first: `id`,
  `saved_at`, `size`, `sha256`, `reason` and `saved_by` (a login, never an email). A path
  with no stored versions returns an empty list, which is not an error.
- `code-restore {version_id}` writes one back. It resolves the stored path through the
  same jail and denylist a caller's path goes through - a denylist can be widened after a
  version was stored - versions the current contents first under reason `restore`, applies
  the same PHP parse check and revert, and reports whether the bytes it wrote match the
  stored hash. A deleted file comes back this way.
- Both appear only when code editing is enabled, and both sit behind the same gate as the
  other four: an admin-scope token whose user holds `edit_themes`, with `DISALLOW_FILE_EDIT`
  and `DISALLOW_FILE_MODS` honoured. The catalog was 22 tools at that point.

### New: `sql-select`, one read-only SQL statement (opt-in, off by default)

- A second switch in **Settings > WP MCP**, `Allow SQL reads`, off by default. While it is
  off the tool is absent from `tools/list` and calling it by name is refused exactly the
  way a tool that does not exist is refused - there is no answer that says "it is here but
  switched off".
- `sql-select {sql}` runs one statement and returns `{columns, rows, row_count, truncated,
  truncated_by}` as JSON. Caps: 200 rows, 256 KB of rows, 8 KB per cell (cut and marked
  with an ellipsis) and a 5-second server-side statement timeout. `NULL` is JSON `null`; a
  value that is not valid UTF-8 comes back as `0x`-prefixed hex, because WordPress's JSON
  encoder silently rewrites the offending byte as `?` rather than failing.
- **Writes are refused by the database, not by a parser in this plugin.** The statement is
  wrapped as `SELECT * FROM ( ... ) AS wpmcp_q LIMIT 201` and run inside `START TRANSACTION
  READ ONLY`. The wrapper makes `UPDATE`, `DELETE`, `SHOW`, a stacked second statement,
  `INTO OUTFILE`, `INTO DUMPFILE` and `INTO @var` syntax errors from MySQL itself; the
  transaction refuses what the wrapper lets through, which is measurably not nothing -
  `SELECT ... FOR UPDATE` parses fine inside a derived table on MySQL 8.4 and is stopped by
  the transaction with 1792. `ROLLBACK` runs in a `finally`, so the connection WordPress
  reuses for the rest of the request is never left inside a transaction.
- CTEs (including recursive ones), joins, `UNION` and an inner `ORDER BY` all work. Two
  limits come with the wrapper: a derived table's columns must be uniquely named, and
  `SHOW` / `DESCRIBE` are not query expressions - use `information_schema`.
- **Three gates, all required:** the switch, an admin-scope token, and `manage_options` on
  the token's user. Admin scope is not an administrator, since a token can be minted to run
  as any user.
- **The plugin's own two tables, and `LOAD_FILE`, are refused by name.**
  `{prefix}wpmcp_tokens` and `{prefix}wpmcp_file_versions` - the database user owns them, so
  this is the one rule the server cannot enforce. `LOAD_FILE()` because it passes both walls
  (a query expression, and a read) and reads the server's disk whenever `secure_file_priv`
  and the `FILE` privilege permit it; that is one function and not a file-read boundary, and
  SECURITY.md says to pin `secure_file_priv` or deny `FILE` regardless. All three are a blunt
  name check that refuses the statement if the name appears anywhere in it, comments and
  string literals included.
- **The session is handed back as it was found.** The prior `MAX_EXECUTION_TIME` (or
  `max_statement_time`) and `optimizer_switch` are read before they are changed and restored
  in the same `finally` as the `ROLLBACK`, so the connection WordPress uses for the rest of
  the request does not carry a 5-second cap and an altered plan away from this tool.
- **It reads everything else that connection can read**, `wp_users` and its password hashes
  included. Read [SECURITY.md](SECURITY.md) before switching it on.
- New auth event `sql_select` (`token_id`, `user_id`, `row_count`, `truncated`,
  `elapsed_ms`, and the first 200 characters of the statement). A refused statement returns
  the MySQL error number and a trace id; the server's own message and the whole statement
  go to the private trace log only.
- New option `wpmcp_sql_enabled`, removed on uninstall. No schema change. The catalog is
  23 tools.

### New: `list-posts` can find things, and `get-post` returns the rest of the post

- **`list-posts` gains nine filters**: `search` (title, excerpt and content), `category`
  and `tag` (slug or term id), `term` (`"taxonomy:slug"`, for any other taxonomy),
  `author` (user id or login), `after` and `before` (ISO 8601 date or datetime, both
  inclusive), and `orderby` (`date`, `modified` or `title`; default `date`) with `order`
  (`asc` or `desc`; default `desc`). `search` is WordPress's own search, so a leading `-` on
  a word excludes it - that is documented in the tool's description rather than stripped,
  because silently turning an exclusion into its opposite is worse than a syntax to learn.
- **A filter that names something you may not see returns an empty list, not an error.**
  An unknown category, a tag holding only somebody else's draft, an author with nothing
  published, a term in a private taxonomy and a taxonomy nobody registered all answer
  identically: `count: 0`, no items, no message. "There is no such thing", "it is empty"
  and "it is not yours" have to be one answer, or the filter is an oracle for the site's
  user logins and term names.
- **Only a malformed argument SHAPE is an error** (`wpmcp_bad_arg`): a date that is not a
  date, an `orderby` that is not one of the three. Those are the caller's own mistake
  about the protocol, they say nothing about the site, and an agent answered with an
  empty list instead concludes the site is empty and stops looking. Dates are matched
  against `YYYY-MM-DD[THH:MM[:SS]]` rather than passed to `strtotime()`, which would
  have accepted `next tuesday` and rolled `2021-13-45` over into 2022.
- **A taxonomy has to be `is_taxonomy_viewable()` and attached to the post type.** A
  private taxonomy is a plugin's internal bookkeeping - customer segments, workflow
  states - and WP_Query will filter an ordinary post listing by one of its terms without
  complaint; `term` is not a way to read one. Attachment and existence are an allow-list
  rather than a fix: measured, WP_Query does *not* ignore `cat` on a post type with no
  categories, it joins and returns nothing. What all of it buys is that a filter which
  cannot be resolved ENDS the query instead of being quietly dropped - that shape is the
  one that answers a question about one category with every post on the site.
- **Paging.** `page` (1-100, default 1) on top of `limit` (1-100, default 20). The result
  now carries `page`, `limit` and `has_more` beside `count` and `items`. There is still no
  total: a total is a count of posts the caller has not been shown, and on the
  own-unpublished side it would be a count of somebody's drafts.
- **Sticky posts are ignored, and that was a bug worth naming.** WordPress decides a query
  is a "home" query from its arguments, and `after`/`before`, `status` and `orderby` set no
  argument that says otherwise - so a listing filtered only by those was a home query, and
  core splices every sticky post into the front of one, fetched as `publish` with none of the
  original conditions. `after: "2030-01-01"` returned posts from 2021; `status: "draft"` on
  an editor's token returned published ones. Not a permission leak - stickies are published -
  but a false answer to the question asked, which is the failure this tool exists not to have.
  Both queries now pass `ignore_sticky_posts`.
- **Ties are broken by ID in the SQL, not only in the merge.** The ordering handed to both
  queries is now `<column>, ID`, in the same direction. Without it, rows sharing a `post_date`
  to the second - which any import produces - could come back in a different order, and a
  different subset, from the `LIMIT` behind page one and the `LIMIT` behind page two, so a
  caller paging through them could see one twice and another never.
- **The listing no longer primes the postmeta cache.** It reads id, title, type, status, slug
  and the permalink and no meta at all, while the paging fetch can ask for up to 10,001 rows
  per query; on a site carrying ACF or SEO meta, priming that is a memory problem rather than
  a slow one. The term cache stays on - `get_permalink()` needs it on a `%category%`
  permalink structure.
- **One ordering across the merge.** list-posts runs two queries - everything you may see,
  plus your own unpublished work, which WP_Query cannot express in one - and both are now
  given the same explicit `orderby`/`order`, with the merge comparator following the same
  column and direction and tie-breaking on ID. Before this the merge was hard-coded to
  `post_date` descending, and a `search` would also have switched one half of the listing
  into relevance ordering on its own.
- **No filter can widen what a token may see.** The two capability-decided status sets are
  still the guard; every filter narrows inside it. The filters are built in one function,
  `wpmcp_list_posts_filters()`, from named arguments mapped to an allow-list of eight
  WP_Query keys, and both queries consume the same array - a filter applied to one and not
  the other would hand an Author their own drafts back under somebody else's category.
- **`get-post` returns `excerpt`, `link`, `author` `{id, name}`, `date`, `date_gmt`,
  `modified`, `modified_gmt`, `featured_image` `{id, url}` or `null`, `terms` keyed by
  taxonomy (each `{id, name, slug}`), and `revisions`.** The author is a display name and
  an id - never the login, which is half of a credential, and never the email.
- **Dates are ISO 8601, and a `0000-00-00` column is `null`.** Every date-floating status
  (draft, pending, auto-draft) is stored with `post_date_gmt` and `post_modified_gmt` set
  to zero; formatting that produces `-0001-11-30T00:00:00`, which a client parses without
  complaint.
- **`revisions` is a count, and only for a caller who can `edit_post`** - `null`
  otherwise, not `0`. Revisions are editorial data and wp-admin puts the panel behind the
  same capability; the ids are counted with `fields => ids`, so no revision body is loaded.
- The three `get-post` refusals - missing id, unreadable post, wrong kind of thing - are
  still one byte-identical message, and they still run before any of the above.
- No schema change, no new option, and the catalog is still 23 tools.

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
- The revision is recorded once every column exists and every backfill has succeeded, so a
  failed migration is retried on the next request rather than stamped and forgotten. The
  column drop is the one step that does not gate it: nothing reads that column, so a host
  whose database user cannot `DROP` is fully upgraded and correct. A failed drop writes one
  `wp-mcp:` line to the error log instead. Gating on it would have left such a host running
  `dbDelta()` and both backfills on every request forever, with nothing saying why.

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
