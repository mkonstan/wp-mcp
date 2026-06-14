# Changelog

All notable changes to WP MCP. Versioning is informal pre-1.0.

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
