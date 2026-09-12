# WP MCP

Connect your WordPress site to Claude (or any MCP client) with no passwords and almost no setup.

**WP MCP** is a small, self-hosted WordPress plugin that exposes your site to AI assistants over the [Model Context Protocol (MCP)](https://modelcontextprotocol.io). Instead of handing an AI your admin password or a permanent API key, you generate a short-lived token in your dashboard, paste it into your client, and you are connected. The token expires on its own, locks to one machine, and the whole endpoint stays dormant until you mint one.

No external services. No OAuth app to register. No background daemon. Four PHP files.

## What you can do with it

Once connected, an AI assistant can work with your site through clean, scoped tools:

- **Read:** site info, posts and pages, media, categories and tags, comments.
- **Write (admin tokens only):** create / update / delete posts and pages (drafts by default), manage categories and tags, upload and remove media, moderate and reply to comments.
- **Edit theme code (opt-in, off by default):** read and write files in the active theme, fenced inside a sandbox (see Security).

A read token can only ever read. The write and code tools are simply invisible to it.

## Why it is built this way

Application Passwords and static API keys are the usual way to let software into WordPress, and they are the wrong fit for an autonomous assistant: they live until you remember to revoke them, they work from anywhere, and they carry their user's full power. WP MCP trades that for:

- **Short-lived tokens** — you choose an expiry, hard-capped at 12 hours. A leaked token is useless tomorrow.
- **IP pinning** — a token locks to the first machine that actually uses it. A copy lifted from a log is dead on arrival anywhere else.
- **Hashed at rest** — only a SHA-256 of the token is stored, never the token itself. A database leak exposes no usable credentials.
- **Least privilege** — read by default; admin power is a deliberate choice per token.
- **Dormant by default** — with no live token, the endpoint answers every request with 401.

See [SECURITY.md](SECURITY.md) for the full threat model and design reasoning.

## Install

1. Download [`wp-mcp.zip`](https://github.com/mkonstan/wp-mcp/releases/latest/download/wp-mcp.zip) (always the latest release).
2. In WordPress: **Plugins → Add New → Upload Plugin**, choose the zip, **Install**, then **Activate**.
3. A new page appears under **Settings → WP MCP**.

(Or clone this repo into `wp-content/plugins/wp-mcp/`.)

Requires WordPress 5.6+ and PHP 7.4+ (developed against WP 7.0 / PHP 8.2).

## Generate a token

**Settings → WP MCP → Generate a token.**

- **Scope:** `read` (safe default) or `admin` (full).
- **Expires in:** up to 12 hours.

The token is shown **once**, as both a ready-to-use URL and an `Authorization: Bearer` header. Copy it then; it is never displayed again (you can always mint another). The active-tokens table shows what is live, when each was last used, and the IP it bound to, with one-click revoke.

## Connect a client

The endpoint speaks MCP over HTTP (JSON-RPC). The token can travel two ways.

**Header style (recommended)** — keeps the token out of server access logs:

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

**Path style** — the whole address is the credential, simplest to paste:

```
https://your-site.example/wp-json/wpmcp/mcp/YOUR_TOKEN
```

Both are validated the same way on every request.

## Scopes and tools

| Tool | Scope |
|------|-------|
| `site-info`, `list-posts`, `get-post`, `list-terms`, `list-media`, `get-media`, `list-comments` | read |
| `create-post`, `update-post`, `delete-post`, `create-term`, `delete-term`, `upload-media`, `delete-media`, `moderate-comment`, `reply-comment` | admin |
| `code-list`, `code-read`, `code-write`, `code-delete` | admin + code editing enabled |

`create-post` defaults to **draft**. `delete-post` **trashes** unless you pass `force: true`.

## Code editing (opt-in)

Off by default. When you enable it in **Settings → WP MCP**, an admin token gains four tools that read and write files **inside the active theme only**. The sandbox is strict:

- Confined to the active theme directory; no path traversal, no symlinks out.
- A configurable **denylist** (default: `functions.php`, `index.php`, `inc/`, `includes/`, `lib/`) is never read or written.
- Only text extensions; size-capped.
- Every write backs up the old file and, for PHP, parse-checks the new content and **auto-reverts** on a syntax error, so a bad edit can't take the site down.

It cannot reach the plugin's own files, so it can't disable its own guard.

## How it works

See [ARCHITECTURE.md](ARCHITECTURE.md) for the request lifecycle, the per-request tool catalog, the `wpmcp_tools` filter for adding your own tools, and how scopes are enforced.

## Security

Read [SECURITY.md](SECURITY.md). Short version: the model is solid for its purpose, with two things to know before production, behind a proxy/CDN you must configure the real client IP (a filter is provided), and the path-in-URL option lands the token in access logs (use the header instead).

### HTTPS enforcement depends on your proxy

The endpoint refuses any request that is not over HTTPS — 403, before the token is read. It decides with WordPress's `is_ssl()`, which reads what the web server told PHP, which on a proxied deployment is a header. WordPress cannot tell whether that header came from your proxy or from the client.

**Your reverse proxy must set `X-Forwarded-Proto` itself, and must never pass the client's value through.** In nginx: `proxy_set_header X-Forwarded-Proto $scheme;`. A proxy, CDN or local development stack that forwards the client's value makes this gate advisory — a client can POST over plain HTTP with `X-Forwarded-Proto: https`, and the request is accepted with the token in cleartext.

`composer test:infra` is the one-test suite that checks this against a running host. It is excluded from `composer test` and from CI because it is a fact about your deployment, not about the code — run it against the production host after any proxy change. It is expected to FAIL on Local by Flywheel, whose router forwards the client's header.

For a local development site with no certificate, and nowhere else, `define('WPMCP_ALLOW_INSECURE', true);` in `wp-config.php` turns the gate off.

## Testing

Dev dependencies only — the plugin itself ships as plain PHP with nothing vendored. `composer install`, then:

| Command | What it runs |
|---|---|
| `composer test` | Everything below except the two gates that are excluded by design. |
| `composer test:unit` | Pure PHP. No WordPress, no Docker, seconds. |
| `composer test:integration` | Black-box HTTP against `WPMCP_TEST_URL` — a real site, real TLS, real capability checks. Skips itself when that variable is unset. |
| `composer test:infra` | One test, about your **deployment** rather than the code. See [HTTPS enforcement](#https-enforcement-depends-on-your-proxy). |
| `composer test:client` | The client gate: a real MCP client (the Claude Code CLI) handshakes with your site, lists its tools and calls one. |

`composer test:client` is **out of `composer test` and out of CI**, because it costs a Claude API call and needs the `claude` CLI on PATH. It is the only test in the repository that is not our own client asserting our own beliefs — it sends what a real client actually sends, and the assertion is a value read straight from your database that the model had no other way to know. Run it by hand when the handshake, the transport or the tool registry changes. See [docs/CONNECT-CLIENTS.md](docs/CONNECT-CLIENTS.md).

## Support

WP MCP is free and GPL-licensed — use it however you like. If you like what you
see and you're putting it to use, you can [sponsor me on GitHub](https://github.com/sponsors/mkonstan)
and buy me a coffee. ☕ Entirely optional; the plugin is and stays free.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).

## Credits

Built by Max Konstantinovski, with Claude. Designed, implemented, reviewed, and hardened collaboratively with AI. See [BUILD-NOTES.md](BUILD-NOTES.md).
