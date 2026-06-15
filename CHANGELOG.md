# Changelog

All notable changes to WP MCP. Versioning is informal pre-1.0.

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
