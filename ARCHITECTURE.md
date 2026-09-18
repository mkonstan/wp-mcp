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
| `wp-mcp.php` | Bootstrap, the two tables, and the pass system: mint, validate, revoke, flush expired. Also the file-version store the code tools write to, and the class loader for `src/`. |
| `endpoint.php` | The front door. The REST routes, the ten gates, JSON-RPC framing, the handshake, scope enforcement, the tool registry, and the error boundary. Defines no tools. |
| `tools.php` | The thirty-eight tools and the helpers they share. |
| `admin.php` | The Settings > WP MCP screen: mint, list, revoke, and the three opt-in surfaces (code editing, SQL reads, the post-meta allow-list) in one form. |
| `trace.php` | The private side of the error boundary: the log, its unguessable name, the daily self-check, and the admin warnings. |
| `src/ProtocolVersion.php` | The MCP revisions this server speaks, as an enum, newest first. |
| `src/SchemaValidator.php` | The JSON Schema subset every `tools/call` argument is checked against. |
| `uninstall.php` | Deleting the plugin: both tables, the options, the log directory, the cron hook. |
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

One catch-all around dispatch. Anything unexpected becomes `-32603` "Internal error" plus
an eight-character trace id, and nothing else crosses. The throwable goes to
`wp-content/wpmcp/trace-<32 hex>.log` under that id, with its class, message, file, line,
`WP_Error` data and stack.

`trace.php` is the only file allowed to touch a throwable's `getMessage()`, `getFile()`,
`getTraceAsString()` or a string cast of it. Every one of those puts the filesystem layout,
and often the arguments, somewhere a client can read, so `tests/unit/NoDisclosureTest.php`
greps the other files and fails if one appears. The only value that crosses back out is a
line number, as an integer.

The log's file name is random and stored in an option, so its URL cannot be derived from
anything a client sees. Once a day the plugin fetches that URL and, on a `200`, raises an
error notice on every admin screen. The first version of this used an `.htaccess` and the
name `trace.log`, which nginx happily served to anybody.

## Five error codes and no more

`-32700` for a body that is valid JSON but not an object, `-32600` for a batch or an
unsupported protocol version, `-32601` for an unknown method, `-32602` for a request whose
own shape is wrong (an unknown tool name, a cursor this server did not issue), `-32603` for
anything unexpected. An argument that fails a tool's schema is none of these, and neither
is a tool refusing something: both are HTTP 200 with `isError: true` and a sentence an
author wrote for the caller.
Adding a code means a client has to act differently on it, which is a higher bar than
wanting to be specific.

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
given. It created the plugin's own two tables and can read them; on many hosts it also holds
`FILE`, which makes `LOAD_FILE()` - a query expression, and a read, so accepted by both walls
- a way to read the server's disk. Those three names are therefore the tool's only string
inspection, and it refuses the statement if any of them appears anywhere in it, comments and
string literals included. It is a short denylist over a surface whose real fence is the
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
