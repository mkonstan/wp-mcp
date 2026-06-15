# WP MCP — Handoff

A cold-start orientation for a new instance picking up this project. Read this top
to bottom and you will know what the plugin is, what it can do, how it is wired, and
where the sharp edges are. For the deep dives, the source files and the docs named
below are the authority.

- **Repository (GitHub):** https://github.com/mkonstan/wp-mcp
- **Author:** Max Konstantinovski (https://github.com/mkonstan) — built with Claude
- **License:** GPL-2.0-or-later
- **Current version:** 0.3.3 (see `WPMCP_VER` in `wp-mcp.php` and `CHANGELOG.md`)
- **MCP endpoint:** `POST /wp-json/wpmcp/mcp` (Bearer header) or `POST /wp-json/wpmcp/mcp/{token}` (token in path)
- **Requires:** WordPress 5.6+, PHP 7.4+ (developed against WP 7.0 / PHP 8.2)

## What it is in one sentence

WP MCP is a small, self-hosted WordPress plugin that exposes a site to AI assistants
over the Model Context Protocol (MCP), authenticated not with a password or permanent
key but with short-lived, admin-minted, IP-pinned session tokens. No external service,
no OAuth app, no background daemon — four PHP files.

## File map

| File | Role |
|------|------|
| `wp-mcp.php` | Plugin header + bootstrap. Owns the token model: mint, validate, revoke, hourly cron flush of expired. Creates the `{prefix}wpmcp_tokens` table on activation. |
| `endpoint.php` | The MCP-over-HTTP front door. Registers the REST routes, authorizes each request, assembles the per-request tool registry, dispatches JSON-RPC. Defines no tools itself. |
| `tools.php` | The full tool catalog (one provider function per domain) plus shared helpers and the theme code-editing jail logic. |
| `admin.php` | The **Settings → WP MCP** admin screen: mint/list/revoke tokens, configure code editing. Admin-only (`manage_options`). |

Supporting docs in the repo: `README.md` (user-facing), `ARCHITECTURE.md` (request
lifecycle and design rationale), `SECURITY.md` (threat model), `CHANGELOG.md`,
`BUILD-NOTES.md` (how it was built), `LICENSE`.

## Capabilities — the full tool catalog

Tools are grouped by domain. Each tool is an array of
`write` (bool), `description`, `inputSchema`, and a `run` callable. The registry is
rebuilt fresh on every request (no stored registry — stateless by design).

### Read tools (any valid token) — `wpmcp_core_tools`, plus read tools in other groups
| Tool | What it does |
|------|--------------|
| `site-info` | Site name, URL, WP version, active theme, active plugin count. |
| `list-posts` | List recent content. Args: `post_type` (default `post`), `status` (default `any`), `limit` (default 20, max 100). |
| `get-post` | Title/status/raw content for a post or page. Args: `id` (required). |
| `list-terms` | List taxonomy terms. Args: `taxonomy` (default `category`), `search`, `hide_empty`. |
| `list-media` | List attachments. Args: `search`, `mime_type`, `page`, `per_page` (max 100). |
| `get-media` | One media item with metadata. Args: `id` (required). |
| `list-comments` | List comments (**emails omitted**). Args: `post`, `status`, `search`, `page`, `per_page`. |

### Write tools (admin-scope token only) — `wpmcp_content_tools`, `wpmcp_taxonomy_tools`, `wpmcp_media_tools`, `wpmcp_comment_tools`
| Tool | What it does |
|------|--------------|
| `create-post` | Create a post/page. **Defaults to `draft`.** Args: `title`, `content`, `post_type` (default `post`), `status`, `excerpt`, `slug`, `terms` `{taxonomy:[id or name]}`. |
| `update-post` | Update a post/page. Args: `id` (required) + any of `title`, `content`, `status`, `excerpt`, `slug`, `terms`. Set `status=publish` to publish. |
| `delete-post` | Delete a post/page. Args: `id` (required), `force` (default false → **trashes**; true → permanently deletes). |
| `create-term` | Create a taxonomy term. Args: `taxonomy` (required), `name` (required), `slug`, `parent`, `description`. |
| `delete-term` | Delete a taxonomy term. Args: `taxonomy` (required), `id` (required). |
| `upload-media` | Upload by sideloading a URL. Args: `source_url` (required, http/https only), `filename`, `title`, `alt`, `post`. Bounded: 20s download timeout, enforces `wp_max_upload_size()`. |
| `delete-media` | Delete an attachment. Args: `id` (required), `force` (default false). |
| `moderate-comment` | Args: `id` (required), `action` ∈ `approve|unapprove|spam|trash|untrash`. |
| `reply-comment` | Reply to a comment as the minting admin. Args: `id` (parent, required), `content` (required). |

### Code-editing tools (admin scope **and** opt-in enabled) — `wpmcp_code_tools`
Off by default. Exposed only when **Settings → WP MCP → Enable code-edit tools** is on.
All four are jailed to the **active theme directory only**.
| Tool | What it does |
|------|--------------|
| `code-list` | List files/dirs in the active theme. Args: `path` (relative). Denylisted entries are flagged `blocked=true`. |
| `code-read` | Read a text file. Args: `path` (required). Denylisted/binary/oversized (>512KB) refused. |
| `code-write` | Create/overwrite a text file. Args: `path`, `content` (required). Backs up to `.bak`; **PHP is parse-checked and auto-reverted on syntax error**. 512KB cap. |
| `code-delete` | Delete a file by moving it to `.bak` (not unlinked). Args: `path` (required). |

Allowed code extensions: `php, css, js, html, json, txt, md, svg`.
Default denylist: `functions.php`, `index.php`, `inc/`, `includes/`, `lib/` (configurable).

### Extensibility
Other plugins/themes can add tools via the `wpmcp_tools` filter (added 0.3.3). Filter-added
tools default to read scope unless they set `'write' => true`, and they pass through the
same scope gate as built-ins. Example in `ARCHITECTURE.md`.

## Security model (the heart of it)

- **256-bit random tokens** (`random_bytes(32)`), **SHA-256 hashed at rest** — the raw token is shown once at mint, never stored or shown again.
- **Hard expiry, capped at 12h**, enforced on every request (not just by the hourly cron, which is housekeeping). Expired tokens are deleted on presentation.
- **TOFU IP pinning:** a token binds to one IP, but **only on the first real `tools/call`** — the `initialize`/`tools/list` discovery handshake does NOT bind (this fixed a real bug where a client enumerated from one IP and called from another, locking itself out). Once bound, **every** request including discovery must match or gets a 403.
- **Two scopes:** `read` (default, safe) and `admin` (full). Read tokens never see write/code tools and are refused them if called anyway. Scope gating lives in `endpoint.php`, not in the tools.
- **Dormant by default:** with no live token in the table, the endpoint 401s everything. Enabling the plugin opens nothing; deleting all tokens closes it again.
- **Code-edit jail:** active theme only; `..` traversal and symlink escapes rejected on read/write/delete (including the `.bak` path); text extensions only; size-capped; backup + auto-revert. The plugin's own files live outside the jail, so the tools cannot edit their own auth code.
- **Deliberately NOT built:** plugin install from URL, user deletion/management, site option changes, plugin activation/deactivation, any `eval`/WP-CLI passthrough — the high-blast-radius actions.

### Known limits before production (from `SECURITY.md`)
1. **Behind a proxy/CDN**, `REMOTE_ADDR` is the proxy, so IP pinning sees all clients as one address. Use the provided `wpmcp_client_ip` filter to read the real client IP, and only trust a forwarded header from a proxy you control.
2. **Path-in-URL tokens land in access logs.** Prefer the `Authorization: Bearer` header. (The IP pin still protects a logged path token, but the header avoids the exposure.)
3. **A token holder on the bound IP has that token's full scope.** Mint `read` unless you need writes; keep code editing off unless actively using it.

## Request lifecycle (how a call flows)

1. WordPress boots → `wpmcp_bootstrap()` loads the other three files and registers two REST routes (`/mcp` and `/mcp/{token}`). Nothing else runs.
2. A client POSTs JSON-RPC 2.0: `initialize`, `notifications/initialized`, `tools/list`, `tools/call`, or `ping`.
3. `wpmcp_authorize()` extracts the token (path wins over header), validates it (existence, expiry, IP). IP is only **bound** when the method is `tools/call`. On success it stashes the row and runs as the minting admin (`wp_set_current_user`) so capability checks behave — scope still gates.
4. `wpmcp_tools()` assembles the registry fresh: core + content + taxonomy + media + comments, plus code tools only if enabled, then `apply_filters('wpmcp_tools', …)`.
5. Dispatch: `tools/list` returns only tools the token's scope may see; `tools/call` re-checks scope, runs the tool, returns a JSON-RPC result (errors come back as `isError` tool results, not RPC errors).

Protocol detail: single JSON response per request (no SSE). `initialize` echoes the client's `protocolVersion` (default `2025-06-18`) and advertises `tools` capability.

## Admin screen (`Settings → WP MCP`)

- **Generate a token:** pick scope (`read`/`admin`), optional label, expiry in hours (0.5–12, hard cap 12). Token is shown **once** as both a ready URL and a `Authorization: Bearer` header form.
- **Active & recent tokens table:** label, scope, created/expires (UTC), bound IP, last used, use count, one-click revoke.
- **Code editing:** enable/disable the code tools, view the (fixed) editable root = active theme dir, and edit the denylist (one entry per line; bare name blocks that filename anywhere, trailing slash blocks a folder).

Options stored: `wpmcp_code_enabled` (bool), `wpmcp_code_denylist` (array). Table:
`{prefix}wpmcp_tokens`.

## Connecting a client

Header style (recommended — keeps token out of logs):
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
Path style (whole URL is the credential): `https://your-site.example/wp-json/wpmcp/mcp/YOUR_TOKEN`

## Conventions worth knowing

- Plain procedural PHP — no classes, no framework, on purpose (small enough to read in one sitting).
- Provider functions are nouns (`wpmcp_content_tools()` etc.) because they *return* lists, they do not register/init anything — the registry is rebuilt per request.
- Packaging is gated: every file is syntax-checked and the shipped zip's contents are hash-compared against source before release (see `BUILD-NOTES.md`).
