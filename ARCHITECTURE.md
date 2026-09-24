# How WP MCP works

The tour of the code: what the files are, what happens to a request, and the decisions
that are not obvious from reading it. Start here if you want to change or extend it.

## The idea

You want an assistant to help run your site without handing it your password, so you hand
it a temporary pass instead: easy to create, expiring on its own, working from one machine,
and carrying one WordPress user's permissions rather than yours. This plugin is the doorman
who checks that pass on every knock and then does the work as that user.

## The files

| File | Its job |
|---|---|
| `wp-mcp.php` | Bootstrap, the three tables, and the pass system: mint, validate, revoke, flush expired. Also the file-version store the code tools write to, the hourly sweep of dead tokens and old traces, and the class loader for `src/`. |
| `endpoint.php` | The front door. The REST routes, the ten gates, JSON-RPC framing, the handshake, scope enforcement, the tool registry, and the error boundary. Defines no tools. |
| `tools.php` | The thirty-eight tools and the helpers they share. |
| `admin.php` | The Settings > WP MCP screen: mint, list, revoke, and the three opt-in surfaces (code editing, SQL reads, the post-meta allow-list) in one form. |
| `trace.php` | The private side of the error boundary: one row per traced failure, the lookup by id, the retention sweep, and the `error_log()` fallback. |
| `src/ProtocolVersion.php` | The MCP revisions this server speaks, as an enum, newest first. |
| `src/SchemaValidator.php` | The JSON Schema subset every `tools/call` argument is checked against. |
| `uninstall.php` | Deleting the plugin: all three tables, the options, the cron hook, and whatever 1.1.1 left in `wp-content/wpmcp/`. |
| `build.txt` | Three git placeholders. The only `export-subst` file: `git archive` writes the commit into it when a zip is cut. |

`src/` is namespaced `WpMcp\`, one class per file, loaded by a nine-line
`spl_autoload_register` in `wp-mcp.php`. There is no Composer at runtime: `composer.json`
is dev-only and nothing is vendored, so the plugin ships as plain PHP. Code moves out of
the flat files into `src/` when something needs a type rather than a convention, which so
far is twice.

## What a tool is

A name, a one-line description, the arguments it takes, four annotation hints, a boolean
saying whether it writes, and a function:

```php
'list-posts' => array(
    'write'       => false,
    'description' => 'Find content the caller may see. Filter and page it. Args: ...',
    'annotations' => array(
        'readOnlyHint' => true, 'destructiveHint' => false,
        'idempotentHint' => true, 'openWorldHint' => false,
    ),
    'inputSchema' => array( /* what arguments are allowed */ ),
    'run'         => function ($args) { /* fetch posts, return them */ },
),
```

All six keys are required. `wpmcp_tools()` refuses an entry missing any of them and fires
a `registry_reject` event naming the entry and the reason, because a missing declaration is
not a declaration of safety: a tool with no `write` key would otherwise be published to
every read-scope token on the site. [README.md](README.md) has the filter and the rules for
adding your own.

## The catalog is rebuilt every request

Not built once at startup and kept. Assembling a few small arrays per request costs
nothing, and in exchange there is no stored state to drift or go stale, and no "the
registry says X but the code does Y" bug class, because there is no registry. That is also
why the functions are named `wpmcp_content_tools()` rather than `wpmcp_register_content_tools()`:
you ask, they return a list, they register nothing.

## What happens on a request

Ten gates, in this order. The order is the security contract, so it is worth reading as
one thing.

```
1.  HTTPS            is_ssl(), unless WPMCP_ALLOW_INSECURE     -> 403
2.  Origin           present and not ours -> 403; absent is allowed (non-browser)
3.  Content-Type     not application/json                      -> 415
3b. Body size        CONTENT_LENGTH over WPMCP_MAX_BODY (4 MiB) -> 413
4.  Token shape      64 lower-case hex, from Authorization: Bearer -> 401
5.  Token lookup     by SHA-256 hash                            -> 401
6.  User exists      get_userdata(user_id)                      -> 401
7.  Timers           window closed -> 401 (dormant, renewable)
                     lifetime over -> 401 (dead)               -> 401
8.  Scope            a read token calling a write tool -> 200 with isError
9.  Protocol version an MCP-Protocol-Version we do not speak -> 400 with -32600

There is no gate on the caller's address, and its absence is a decision. Measured on a
public test site on 2026-09-13, an Anthropic-hosted connector calls from a pool of egress
addresses - 160.79.106.164, .185, .186 and .187 within one minute - so a per-address rule
refuses everything after the first call. The address is recorded on every auth event and
enforced nowhere.
```

Gates 1 to 3 are properties of the envelope and cost nothing, so they run before the
credential is read at all. A request refused for being plaintext must not first have its
token looked up, or the refusal becomes a token oracle with a timing side channel. Gates 4
to 8 narrow from "is this string even a token" to "is it this token, still alive, from the
right place", cheapest first, each one a precondition of the next.

Gates 9 and 10 are not in `wpmcp_authorize()` and cannot be: both need the parsed body,
which is the thing the rest of the ordering exists to postpone. Gate 9 needs the tool name,
and its refusal is an MCP tool error rather than an HTTP status, because a read token
calling a write tool is a correctly authenticated request asking for something it may not
have. Gate 10 needs to know whether the method is `initialize`, which is exempt, and its
refusal is a JSON-RPC envelope, a shape a `permission_callback` cannot produce.

All six ways gates 4 to 8 can fail return one byte-identical 401. Which one it was goes to
the `wpmcp_auth_event` action and the debug log, where the operator is.

Past the gates: the request runs as the token's user, the catalog is assembled and filtered
to the token's scope, arguments are validated against the tool's schema, and then the tool
runs.

## The disclosure boundary

One catch-all around dispatch. Anything unexpected becomes `-32603`
**`Internal error (trace 1f53b972)`** plus the same id in `error.data.trace_id`, and nothing
else crosses. The throwable goes to one row of **`wp_wpmcp_traces`** under that id, with its
class, message, file, line, `WP_Error` data and stack.

**The id is in the message as well as in `data` since 1.1.1**, and the reason is a measurement:
a cold client rendered `error.message` and nothing else, so the one identifier that makes a
deliberately empty message actionable was invisible to the person reading it and the log line
could not be found. Eight hex digits generated per event disclose nothing.

**The stack carries argument SHAPES, never argument values.** PHP's own `getTraceAsString()`
prints the first fifteen characters of every string argument - verified on this project's PHP,
8.2.29 with `zend.exception_ignore_args=0` - which is the start of a URL, a title, or whatever
a caller sent. The stack is stored as `{closure}(array{source_url,filename}, string(41),
stdClass)` instead: an array's KEYS, a string's length, an object's class. It is bounded at 200
frames, which is the one value the file's byte cap used to bound and a column does not.

`trace.php` is the only file allowed to touch a throwable's `getMessage()`, `getFile()`,
`getTraceAsString()` or a string cast of it. Every one of those puts the filesystem layout,
and often the arguments, somewhere a client can read, so `tests/unit/NoDisclosureTest.php`
greps the other files and fails if one appears. The only value that crosses back out is a
line number, as an integer.

**A TABLE AND NOT A FILE, SINCE 1.1.2, AND THE REASON IS THE WEB SERVER.** The log was
`wp-content/wpmcp/trace-<32 hex>.log` behind an `.htaccess`, and `.htaccess` is an APACHE file:
nginx has no per-directory configuration and never reads it. MEASURED on the development host -
`GET /wp-content/wpmcp/trace.log` answered `200` with 14 KB of absolute paths, the OS username,
the plugin inventory, tool names, user ids and every stack frame, to anybody, with no token. The
plugin's own daily self-check NOTICED and the plugin carried on writing, which made it an
observation rather than a guard; randomising the file name hid the URL without removing it. **No
web server can serve a table**, so the class of failure is gone rather than mitigated - and with
it the two guard files, the self-check, both admin notices, the 2 MiB size cap, the
keep-newest-75% rewrite, the absorb-and-retry trim, the short-write retry, `flock`, `fstat` and
`ftruncate`. Atomicity became the database's problem: one INSERT either happens or does not.

The objection that did not survive is worth recording, because it is the obvious one: a trace
must survive a broken database, since database failures are among the things it records. It
cannot happen. A database that is genuinely down means WordPress never boots and this plugin is
not running to log anything; the realistic case is a SINGLE query failing - bad SQL in
`sql-select`, a missing table, a deadlock - where the connection is fine and an INSERT succeeds.

**The two costs of a table were designed against, not discovered.** (1) A trace now rides in
every database backup, export and staging clone, where a file did not - so retention is DAYS:
seven of them, and at most 2,000 rows, swept on the hourly `wpmcp_flush_expired` event that
already clears dead tokens, oldest first for the same reason the file's cap cut that way. The
sweep deletes in batches of 500 with a round cap, because the site that most needs it is the
site whose cron died a month ago, and an unbounded `DELETE` is one transaction inside somebody's
page load. **A ROW CAP IS NOT A SIZE CAP UNLESS THE ROW IS BOUNDED, which round 1 of this sprint
got wrong and documented wrongly:** every field is now capped in bytes against its own column
and the stack at 8 KiB as well as 200 frames, so one row is at most 12,960 bytes and 2,000 rows
is **under 26 MB** - where a count cap over a `longtext` bounded only in frames put the real
worst case in the hundreds of megabytes, because the runaway recursion a frame cap exists for
writes 40-400 KB rows. The measured mean entry is 2,283 bytes, so a development site's 69
failures a day fills 486 rows, about 1.1 MB, in seven days. Both retention numbers are
filterable and a useless value is ignored rather than obeyed.
(2) `sql-select` must refuse this table, and does - it is the third name in
`wpmcp_sql_denied_identifiers()` beside the tokens and the file-version tables. A trace row is
exactly the detail the boundary withholds, so leaving it readable would undo the boundary
through the back door for any admin-scope token that had just caused a failure.

**A trace id still resolves, and that is the whole promise.** `trace_id` is indexed, and
**Settings > WP MCP > Look up a trace id** takes the eight hex digits a client was given and
prints that one entry, `manage_options` only. It is deliberately not a log browser: no list, no
search, no pagination, because what it renders is the detail the API is refused. An INSERT that
fails still sends the whole entry to `error_log()`, exactly as an unwritable directory did.

**The upgrade DELETES an existing site's log, its directory and its three options.** That is the
only way the exposure goes away; the old entries are not migrated, because they would then ride
in every backup, and the changelog tells an operator with a live support case to take a copy
first. **An upgrade that could not manage it says so on every admin screen** - the one notice
this change adds, having deleted three, and the difference is that this one describes a file the
plugin has FINISHED with and could not delete, which on nginx is still being served. A symlinked
`wp-content/wpmcp` is reported the same way and deliberately not followed: `glob()` and
`unlink()` follow a link, so acting would delete files somewhere the plugin has never written
and would take the link while leaving every exposed byte in place.

## Five error codes and no more

`-32700` for a body that is valid JSON but not an object, `-32600` for a batch or an
unsupported protocol version, `-32601` for an unknown method, `-32602` for a request whose
own shape is wrong (an unknown tool name, a cursor this server did not issue), `-32603` for
anything unexpected. An argument that fails a tool's schema is none of these, and neither
is a tool refusing something: both are HTTP 200 with `isError: true` and a sentence an
author wrote for the caller.
Adding a code means a client has to act differently on it, which is a higher bar than
wanting to be specific.

**Four core `WP_Error` codes are relayed instead of hidden**, because each one is the caller's
own mistake in words it can act on: `term_exists`, `comment_duplicate`, `comment_flood`,
`empty_content`. `http_request_failed` was a fifth and came off the list in 1.1.1: its message is
the HTTP transport's, and cURL's names the host it could not reach, which on a proxied site is
the operator's own `WP_PROXY_HOST`. The case that list was really protecting is a remote server
that ANSWERS and refuses, and `upload-media` now turns that into a `wpmcp_fetch_failed` naming
the status and the reason phrase - never the response body, which core attaches up to a
kilobyte of and which in the measured case was a whole HTML error page.

## What an operator can watch

Three surfaces, and each answers a different question without the plugin inventing a log format:

- **the auth events** (`wpmcp_auth_event`) - was a credential accepted, and whose;
- **the trace store** - what broke, keyed by the id the caller was given; a table since 1.1.2,
  kept seven days and 2,000 rows, looked up by id on the settings screen;
- **`do_action('wpmcp_tool_call', $tool, $ok, $context)`**, since 1.1.1 - what a token is
  actually DOING. One firing per `tools/call`, in a `finally` so a crash fires it too, with `ok`
  read off the response so a scope refusal, a schema failure, a tool's own error and a thrown
  `TypeError` all report `false` without four call sites having to remember to. `$context`
  carries the argument KEYS - never values - the token row id, the user id, the scope and the
  duration. A site that wants none of it pays one empty hook call.

And on the settings screen, the token table now names **what is connected**: the client's own
`name` and `version` from `initialize`'s `clientInfo`, stored on the token row beside
`last_used_at` and `use_count`, so a row reads "Claude Desktop 1.4, last seen 3 minutes ago,
412 calls". That is the operator visibility a session feature would have bought, and the server
stays stateless.

## The pass system, and what each piece is for

The token is 256 bits from `random_bytes()`, so guessing is off the table, and only its
SHA-256 hash is stored. A stolen database gives an attacker hashes, not passes.

It is bound to a WordPress user picked at mint time. The request runs as that user, so
capabilities bound what the token can reach and scope narrows from there. This is the piece
that changed in 1.0: before it, every token ran as the admin who minted it, and an Editor's
token could read anything.

Its active window closes within twelve hours - thirty days on a site whose environment
type is `local`, see `wpmcp_max_window()` - checked on every request rather than by the
cleanup cron, and the window a row is honoured for is this site's ceiling whatever the row
itself was granted, so a database copied here from a site with a wider ceiling brings no
wider window with it. A refused row is KEPT, not deleted: dormant is what **Renew** exists
for, and only the hourly cron removes a row, once it is past its hard lifetime. What the
cap buys is a bounded window, and that is all it buys: a token used inside its window has
its full scope for that window, so the cap is no reason to mint `admin` casually.

With no live token in the table, every request gets 401. Activating the plugin opens
nothing; deleting the tokens closes it again.

## Editing theme code

Off by default. When it is on, an admin token's six code tools can read and write inside
the active theme, with path resolution and symlink checks on every operation, a denylist,
a text-extension and size cap, and a parse check that reverts a PHP file whose new content
does not compile.

Every path is canonicalised from its resolved location before the denylist, the extension
check or the version table sees it, so one file has one spelling and one history.

Every change is versioned first. Before a file is overwritten or removed, the bytes that
are there go into a second table, `{prefix}wpmcp_file_versions`, keyed by the active
theme and that canonical path, and if they cannot be stored the change does not happen; `code-history` lists what is kept for a path and
`code-restore` writes one back. A TABLE and not a file, because the active theme is inside
the document root: the sibling backup this used to write had an extension nothing executes
and nothing blocks, so its URL returned the complete source of a theme file to anyone who
asked. The database is the one store WordPress never serves. The schema upgrade creates
the table and sweeps any of those sibling files still on disk into it.

That fence is about accidents. It is not a security boundary, because a theme template is
executable PHP and PHP can reach the database and the filesystem regardless of which file
it was written into. [SECURITY.md](SECURITY.md) says this at length. Read it before
enabling the feature.

## The second opt-in: one read-only SQL statement

`sql-select` is gated the same way - a switch in Settings >
WP MCP, off by default, and the tool is absent from `tools/list` until it is on - but the
thing it is fenced against is refused somewhere else entirely. The code tools' fence is
PHP: this plugin decides which path is allowed. sql-select's fence is the DATABASE, and
nothing in this repository looks at the SQL to decide whether it is safe.

The statement is wrapped as `SELECT * FROM ( ... ) AS wpmcp_q LIMIT 201` and run inside
`START TRANSACTION READ ONLY`, with a five-second session statement timeout. A derived
table must be a query expression, so anything that is not one - `UPDATE`, `SHOW`, a second
statement after a semicolon, `INTO OUTFILE` - is a syntax error from MySQL's own parser;
and what the wrapper lets through, the transaction refuses (`SELECT ... FOR UPDATE` parses
inside a derived table and is stopped with 1792). The `ROLLBACK` is in a `finally`, because
WordPress reuses that connection for the rest of the request.

The reason it is built this way rather than as a validator is that a SQL validator is a
second implementation of MySQL's grammar, living in PHP, that has to agree with the real
one on every comment, quote, case and encoding. When it does not, it fails silently in the
direction of running something. Letting the server be the parser has one implementation
and no second opinion to drift.

What the server cannot decide is what the WordPress database user should not have been
given. It created the plugin's own three tables and can read them - the tokens, the theme-file
versions, and, since 1.1.2, the TRACES, which is the one that most has to be refused, because a
trace row is precisely the detail the error boundary hands a caller eight hex digits instead of.
On many hosts the same user also holds `FILE`, which makes `LOAD_FILE()` - a query expression,
and a read, so accepted by both walls - a way to read the server's disk. Those four names are
therefore the tool's only string inspection, and it refuses the statement if any of them appears
anywhere in it, comments and string literals included. It is a short denylist over a surface whose real fence is the
server, not a second fence: `secure_file_priv` and the `FILE` grant are the operator's, and
[SECURITY.md](SECURITY.md) says so.

## The third opt-in: an operator's list of meta keys

`get-post-meta` and `set-post-meta` are gated by the same invariant - absent from
`tools/list` until they can run - but by a LIST rather than a switch, and the difference is
the point. The other two opt-ins are a yes/no about a surface whose shape this plugin
knows. Post meta has no shape: it is one table holding a marketing user's subtitle, an ACF
value, a page builder's serialised payload and `_edit_lock`, with no capability that
separates any of them. WordPress itself only distinguishes "protected" - a leading
underscore - which is a naming convention, not a permission.

So the plugin does not decide. `wpmcp_meta_keys` holds the exact key names an
administrator typed into Settings > WP MCP, and those are the only keys that exist for
MCP; an empty list means the two tools are not there. Normalisation happens on save AND on
every read, because that option is an ordinary row and wp-cli, another plugin or a restored
backup can write it without ever passing through the form.

The capability checks are then the ordinary ones: `read_post` to read, `edit_post` plus
WordPress's own `edit_post_meta` for the key to write. The allow-list narrows inside them
and never widens - a key on the list is still refused on a post the caller cannot reach.

## One place shapes a post field

`create-post` and `update-post` do not each know what an `excerpt` or a `date` is. Both
call `wpmcp_post_fields()`, which turns every optional field into one `wp_insert_post` key
or one deferred core setter, applies the capability WordPress puts in front of that field
in wp-admin, and hands back `changed`. Two tools that wrote the same five fields out twice
would have drifted on the sixth, and the interesting part of each field is a gate rather
than an assignment: `author` answers to `edit_others_posts`, and `featured_image` answers
to `edit_post` on the ATTACHMENT, which resolves through the attachment's own author
exactly as a post's does - the parent plays no part, because `map_meta_cap` consults
`post_parent` only for a revision.

The deferred half exists for one reason: `set_post_thumbnail()` needs a post id, which on
create does not exist yet - but its capability check does not. So the refusal happens
before `wp_insert_post()` runs and only the writing waits, which is what stops a refused
create leaving an orphan behind.

## The tool layer is the browser and the form

Every question about a text value - strip it? encode it? decode it? pass it through? - is
settled by one sentence, and it is the contract this plugin is built on:

> **A tool returns what the wp-admin field would show, accepts what a user would type, and
> storage is whatever core makes of it FOR THAT TOKEN'S USER.**

The tool layer mimics a person using wp-admin. So on write, hand core the value as typed and
let the `*_save_pre` filters run - kses included when the user lacks `unfiltered_html` - and add
no stripping of our own. On read, decode one level for a value wp-admin renders in a TEXT INPUT,
and pass through untouched for one it renders in an HTML EDITOR. A new field is settled by
looking at wp-admin's markup for it, not by argument.

**The measurements that justify it**, all taken on sample.local against WordPress 7.1.1 on
2026-09-21, and re-measured on jaygroup in 1.1.1:

- **kses is a fixed point.** `wp_filter_kses('x<y z')` is `x&lt;y z`, and a second and third
  pass change nothing. A bare `&` becomes `&amp;`; an existing `&lt;` is left alone.
- **`esc_attr()` does not double-encode.** A stored `x&lt;y z` reaches the browser as
  `x&lt;y z`, and the browser shows the user `x<y z`.
- **The two are inverses**, which is the entire reason wp-admin never compounds an encoding.
  Nothing tracks state; nothing needs to.
- **Core's own hookup.** `title_save_pre` carries only `trim`
  (`wp-includes/default-filters.php:328`); `wp_filter_kses` is added to it ONLY for a user
  without `unfiltered_html` (`wp-includes/kses.php:2548`), and `DISALLOW_UNFILTERED_HTML`
  forces that path for everyone, administrators included. `wp_insert_post()` expects SLASHED
  data and unslashes at `wp-includes/post.php:4981`, after those filters have run at `:4632`.
- **A term stored `Arts &amp; Crafts`** comes back that way from `sanitize_term_field` in every
  context, so wp-admin's edit field holds those bytes and shows `Arts & Crafts` - which is what
  these tools return, and the reason the sprint-14d decode was right.

**Titles follow it as of 1.1.1.** `create-post` and `update-post` used to run
`wp_strip_all_tags()` on a title, so a title typed `x<y z` was stored as `x` - text destroyed,
silently, by us. That rule is gone. Measured on both capability paths:

| sent | administrator stores | author stores |
|---|---|---|
| `x<y z` | `x<y z` | `x&lt;y z` |
| `Tom's "quoted" A\B` | `Tom's "quoted" A\B` | `Tom's "quoted" A\B` |
| `Arts & Crafts` | `Arts & Crafts` | `Arts &amp; Crafts` |

**The accepted cost** is in that table: the same wire value stores different bytes per
capability, so `changed` can report a title as changed when an administrator re-saves a
subscriber's post. wp-admin does the same thing.

**The slashing is the trap, and one call closes it.** `wp_filter_kses` is
`addslashes(wp_kses(stripslashes($data)))`, so it expects slashed input exactly as
`wp_insert_post()` does. Both write tools slash the whole postarr once, at the boundary, so the
pair cancels and the middle row of that table survives byte for byte for both roles. Hand kses
an UNSLASHED title instead and it becomes `Tom\'s \"quoted\" AB` - the backslash eaten, the
quotes mangled. Slash the array, never the field.

**One field does not yet have the read half, and that is named rather than hidden.** `get-post`
returns the stored `post_title` column, so an author's `x<y z` reads back as `x&lt;y z` while
wp-admin's field would show `x<y z`. Writing back what was read is still exact, because an
unchanged field is not written at all - so nothing is lost today. Decoding a title on read is a
separate decision: it would change what five read tools return, and it would let an
administrator's write-back rewrite an author's stored bytes (faithfully, as wp-admin does, but
on a call that only meant to echo the object). It is open.

## What a tool returns can be written back

A client reads a field and later sends it back - to change something beside it, or because
it is echoing the object it was given. So every field a tool returns that some tool accepts
is held to one rule: **written back unchanged, it stores the same bytes.** Two halves make
that true, and which half a field gets depends on how WordPress stores it:

- **Decode on read, where core escapes on write.** A term name is stored with `&`, `<` and
  `>` as entities by core's own filter, and menu labels wp-admin saved can carry `&#038;`
  for `&` (eight do on the stress site). Both come back through
  `wpmcp_decode_specialchars()` - the exact inverse of that escaping, no wider - so a
  client sees the text a person typed, and core re-escapes it identically when it is sent.
- **Leave alone on write, where the stored value is already the read value.** `update-post`
  compares every sent field with the stored row BEFORE shaping it, and does not write one
  that is equal: re-shaping is what turned a wp-admin title `x<y z` into `x`, and what
  turned a draft nobody dated into a dated one. An update that changes nothing writes
  nothing at all. `update-menu-item` keeps a label's stored bytes when it is sent the
  decoded form of them.

`changed` follows from the same idea: it is a diff of the row taken before and after the
write (`wpmcp_post_state()`), not a list of what was sent, so it names core's own moves - a
re-dated draft, a re-slug on a status change, a default category - as well as the caller's,
and never names a field that did not move. The table of every field, its read tool, its
write tool and what was measured on both test sites is in the sprint-14d report.

## One declaration per result: the output-schema pilot

Four small-payload read tools - `site-info`, `get-post`, `get-media`, `get-user` - declare an
`outputSchema` and send `structuredContent` beside the text block. It is a PILOT, and the case
for it is INTERNAL rather than client-side:

- **it kills shape drift**, which has bitten this project repeatedly. Each of the four declares
  its fields ONCE - name, JSON type, description, and the closure that produces the value - and
  `wpmcp_result_schema()` and `wpmcp_result_build()` read that same declaration. A field that is
  added, renamed or re-typed moves in both at once, and a field with no producer is a PHP error
  rather than a schema promising something nobody sends. Declaring the types is what found
  `get-media`'s `url` answering the JSON literal `false` when an attachment has no file.
- **it buys back description budget**, which is genuinely scarce: the cap clients apply is 1,000
  characters, and much of these four descriptions was a list of returned fields. That list is now
  in the schema, a sentence per field, and the descriptions carry the warnings instead.
- `structuredContent` is **decoded from the text block**, so the two copies of the data cannot
  disagree - not about a value, not about a type, not about whether an empty field is `{}` or
  `[]`.

The cost is that a tool with a schema sends its data twice, because the specification requires
the text block to stay. That is why it is four tools and not thirty-six. Whether it goes further
depends on what a real client does with the structured half, which is a measurement nobody has
made yet.

## One envelope, one date, and an end

Every paged list tool - `list-posts`, `list-revisions`, `list-terms`, `list-media`,
`list-comments`, `list-users` - answers `{count, page, limit, has_more, items}` through
`wpmcp_page_envelope()`, fetches `limit + 1` rows to answer `has_more` without a total, and
stops at page 100 (`WPMCP_PAGE_CAP`): **`has_more` is false at the cap**, and every
description says so, because a clamp that kept answering `true` made an agent paging to the
end loop on page 100 for ever. Every date a list tool returns is ISO 8601, site-local, with
no offset - the form `get-post` gives `date` - whether the column is stored local or UTC.

## Which build is running, and why the version cannot say

A version string cannot identify a build. Every build of 1.1.0 says `1.1.0`, so two zips
cut a month apart are indistinguishable once installed - the filename is the only thing
that ever differed, and a filename does not survive installation. That cost an hour of
misdiagnosis on a live site in September 2026.

So identity is a second field, and it is produced by git rather than maintained by anyone.
`build.txt` carries `$Format:%H$`, `$Format:%h$` and `$Format:%cI$`; `.gitattributes` marks
that one file `export-subst`; `git archive` substitutes them for the commit being archived.
Both zip recipes go through `git archive`, so every zip carries its commit and no zip can
carry the wrong one.

`wpmcp_build_stamp()` reads the file once per request and `wpmcp_build_stamp_parse()` turns
it into three fields, discarding anything that is still a placeholder or that does not look
like a build id - the value is printed on an admin page and put on the wire, so its shape
is checked rather than trusted. `wpmcp_build_label()` is then the one string four surfaces
print: the Plugins-screen row (`plugin_row_meta`), the settings page
(`wpmcp_admin_build_line()`), `serverInfo.build` (`wpmcp_server_info()`) and `site-info`'s
`wp_mcp.build`. Four callers, one function, so "they all agree" is a property of the code.

**A checkout is the normal case and says so.** Running out of a git working tree leaves the
placeholders literal; the plugin then reports `WPMCP_BUILD_UNKNOWN`, the word `source`, and
invents nothing. No warning, no notice, no different behaviour - an absent stamp is a fact
about the copy, not a defect.

**The stamp is beside the version, never inside it.** `Version:` in the header is what
WordPress displays and what the release tooling and the version-consistency test read, and
the substitution happens in `git archive` rather than in the tree - so a header carrying
the stamp would read `Version: 1.1.0-dev+$Format:%h$` in every checkout. Keeping them apart
costs one thing, and `plugin_row_meta` pays it: the Plugins screen's own `Version` column
still says `1.1.0`, so the build is appended to that same row.

## Why it looks plain

Mostly flat functions, two classes, no framework, small enough to read in one sitting. For
something that guards a site, being readable is worth more than being clever.
