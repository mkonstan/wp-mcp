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
| `wp-mcp.php` | Bootstrap, the token table, and the pass system: mint, validate, revoke, flush expired. Also the class loader for `src/`. |
| `endpoint.php` | The front door. The REST routes, the ten gates, JSON-RPC framing, the handshake, scope enforcement, the tool registry, and the error boundary. Defines no tools. |
| `tools.php` | The twenty tools and the helpers they share. |
| `admin.php` | The Settings > WP MCP screen: mint, list, revoke, and the code-editing switch. |
| `trace.php` | The private side of the error boundary: the log, its unguessable name, the daily self-check, and the admin warnings. |
| `src/ProtocolVersion.php` | The MCP revisions this server speaks, as an enum, newest first. |
| `src/SchemaValidator.php` | The JSON Schema subset every `tools/call` argument is checked against. |
| `uninstall.php` | Deleting the plugin: the table, the options, the log directory, the cron hook. |

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
    'description' => 'List recent content the caller is allowed to see. Args: ...',
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
7.  Expiry           expires_at <= now                          -> 401
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

It expires within twelve hours, checked on every request rather than by the cleanup cron,
and the row is deleted the moment an expired token is presented. What the cap buys is a
bounded window, and that is all it buys: a token used inside its window has its full scope
for that window, so the cap is no reason to mint `admin` casually.

With no live token in the table, every request gets 401. Activating the plugin opens
nothing; deleting the tokens closes it again.

## Editing theme code

Off by default. When it is on, an admin token's four code tools can read and write inside
the active theme, with path resolution and symlink checks on every operation, a denylist,
a text-extension and size cap, and a backup plus parse check that reverts a PHP file whose
new content does not compile.

That fence is about accidents. It is not a security boundary, because a theme template is
executable PHP and PHP can reach the database and the filesystem regardless of which file
it was written into. [SECURITY.md](SECURITY.md) says this at length. Read it before
enabling the feature.

## Why it looks plain

Mostly flat functions, two classes, no framework, small enough to read in one sitting. For
something that guards a site, being readable is worth more than being clever.
