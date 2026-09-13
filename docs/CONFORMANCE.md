# MCP conformance

What WP MCP 1.0 implements, and what it does not. The point of this file is that a client
author can tell which is which without reading the code.

## Revision

`2025-11-25`. That is what `initialize` answers with when a client asks for a revision this
server does not know, and a client asking for `2025-11-25` gets it back unchanged.

Also accepted on the wire: `2025-06-18` and `2025-03-26`. A request whose
`MCP-Protocol-Version` header names one of the three is served. A header naming anything
else is refused with HTTP 400 and a JSON-RPC `-32600` listing the three. A request with no
such header is treated as `2025-03-26`, which is what the specification asks for.

## Transport

HTTP POST, one JSON response per request. One route, one credential form:

```
POST /wp-json/wpmcp/mcp           Authorization: Bearer <64 lowercase hex>
```

The URL is constant and never carries the token. A path that does - `/wp-json/wpmcp/mcp/`
followed by a token - is not a registered route and answers `404 rest_no_route`.

Stateless. No session id is issued, and an `Mcp-Session-Id` sent by a client is ignored.

## Methods served

| Method | Notes |
|---|---|
| `initialize` | Negotiates the revision. An unknown revision is a success carrying `2025-11-25`, not an error. |
| `ping` | Empty result. |
| `tools/list` | Filtered by the token's scope, so a `read` token is not shown the write tools. Paginated with an opaque `cursor`; the page size is larger than the catalog, so `nextCursor` is absent today. An unreadable cursor is `-32602`. |
| `tools/call` | Arguments validated against the tool's `inputSchema` before the tool runs. A tool error is HTTP 200 with `isError: true`. |

Any request with no `id` key is a notification: it is answered with HTTP 202 and an empty
body, whatever its method, because JSON-RPC 2.0 section 4.1 says a server must not reply to
one. `notifications/initialized` therefore needs no handler. A request that carries an `id`
is answered even when its method name begins with `notifications/`, with `-32601`.

Any other method is `-32601`.

## Capabilities declared

```json
{ "tools": {} }
```

`tools` and nothing else. Not even `listChanged` inside it: this server has no way to tell
a client the tool list changed, so claiming the capability would be a lie a client could
act on.

## Not served

One line each, with the reason rather than a promise.

| Feature | Why not |
|---|---|
| `prompts` | Prompts are macros over the tools. The tools had to be correct first. A later release can add them without changing the transport. |
| `resources` | A resource list of a site's posts is unusable at any real post count, and `list-posts` plus `get-post` already cover the reads a client makes. |
| `resources/subscribe` | Needs resources, and needs a push channel this transport does not have. |
| `completion/complete` | Argument completion is worth having once there are prompts to complete arguments for. |
| `logging` | The protocol's log channel sends server detail to the client. This server deliberately sends a client one generic error and an eight-character trace id, and keeps the detail in a log only the operator can read. The two designs contradict each other. |
| Progress notifications | Every tool here completes inside one request. There is nothing to report progress about, and no channel to report it on. |
| Batch requests | A JSON array body is refused with `-32600` "Batch requests are not supported". Batching multiplies the work one unauthenticated request can ask for, and no client needs it. |
| Sessions | Every request carries its own credential and is validated on its own. No session id, no server-side state between requests, nothing to expire or to fixate. |
| SSE and streaming | One JSON response per POST. A streamable HTTP server has to hold a connection open inside PHP-FPM, which is the wrong shape for WordPress on shared hosting. |
| stdio | There is no local process to speak to. The server is a WordPress site. |
| OAuth | The token scheme is the authentication: minted by an admin, bound to a WordPress user, hashed at rest, answering only inside an active window of at most 12 hours and never past a hard lifetime of at most a year. An OAuth authorization server inside a WordPress plugin would be a larger attack surface than the thing it protects. |
| Revision `2026-07-28` | No client speaks it yet. Supporting two eras at once doubles the negotiation paths and the tests for a capability nobody can use. It is the next revision to add. |
| Abilities API bridge | WordPress core registers three read-only abilities today. The bridge becomes worth building when plugins with real abilities ship. |

## Where the behaviour is asserted

`tests/integration/HandshakeTest.php` for negotiation and the version header,
`JsonRpcFramingTest.php` for batch, notifications and the body cap,
`ErrorBoundaryTest.php` for the disclosure boundary, `ToolContractTest.php` for validation
and the annotations. `composer test:client` runs a real MCP client against a real site.
