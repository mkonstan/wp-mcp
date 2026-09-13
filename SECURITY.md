# Security model

WP MCP exists to let an AI assistant reach a WordPress site without handing it a password or a permanent key. This document explains what it defends against, how, and where the limits are. Nothing here is secret; the design is meant to be read.

## The token

- **256 bits of randomness** (`random_bytes(32)`), so guessing or brute force is not a threat.
- **Stored as a SHA-256 hash, never in plaintext.** The raw token is shown once at mint and never again. A read-only database compromise (a SQL-injection elsewhere, a leaked backup) exposes zero usable tokens.
- **Hard expiry, capped at 12 hours**, enforced on every request, not just by a cleanup cron. An expired token is deleted the moment it is presented.
- **Bound to a WordPress user**, chosen at mint time. The request runs as that user, so that user's capabilities are the ceiling on what the token can reach. Deleting the user stops the token working.
- **Scope: `read` or `admin`**, narrowing from there. Read tokens are refused every write and code tool, and those tools are not even listed to them. Scope only subtracts; it cannot grant a capability the user does not have.

## No address binding, and why not

A token used to lock to a single client address on its first tool call and refuse every later request from anywhere else. That is removed as of 1.1.0.

Measured on a public test site on 2026-09-13: an Anthropic-hosted connector (claude.ai on the web, Claude Desktop) reaches a server from a **pool** of egress addresses - `160.79.106.164`, `.185`, `.186` and `.187` were all seen inside one minute. There is no single address to hold a token to, so the lock authenticated the first call of a session and refused the rest of it. No amount of address bookkeeping fixes that.

The caller's address is still recorded on every auth event, where an operator can read it. It decides nothing.

**What this costs.** A token copied out of a log or intercepted is usable from anywhere. Two things carry that weight instead: the credential is a request header rather than a URL, so it is not written to access logs in the first place, and expiry is enforced on every request. Mint `read` unless you need writes.

## Dormant by default

With no live token in the table, the endpoint returns 401 to everything. There is no anonymous surface. Enabling the plugin does not open anything until you mint a token; deleting all tokens closes it again.

## Code editing sandbox (opt-in, off by default)

When enabled, the code tools' file API is fenced:

- **Jailed to the active theme directory.** Paths are resolved and re-checked; `..` traversal and symlinks pointing outside the jail are rejected on read, write, and delete (including the `.bak` backup path).
- **Denylist** (configurable; default `functions.php`, `index.php`, `inc/`, `includes/`, `lib/`) is never read or written, so the files most likely to take down the whole site or hold secrets are off limits.
- **Text extensions only**, size-capped.
- **Backup + auto-revert.** Every write copies the old file aside first; PHP writes are parse-checked and reverted automatically on a syntax error.
- **Self-protection, and exactly how far it goes.** The jail is the active theme, and the plugin lives elsewhere, so the code tools' file API cannot open, overwrite or delete this plugin's own files. That is the whole of it. The jail constrains which files the tools touch; it does not constrain what the PHP written into those files does when WordPress runs it. A template in the active theme is executable code: it can read the database, rewrite this plugin's options, write anywhere the web server user can write, and so switch the guard off from the inside.

  So an admin-scope token with code editing enabled is arbitrary code execution on your server, with the web server's privileges. Nothing in the sandbox changes that, and the denylist, the parse check and the backup are there to stop accidents rather than an attacker. Leave code editing off unless you are actively using it.

## What is deliberately NOT built

These were considered and left out on purpose: installing plugins from a URL (downloads and runs code), deleting users, managing site options or users, activating/deactivating plugins, and any arbitrary `eval` / WP-CLI passthrough. They are the high-blast-radius actions; a scoped tool list that includes them is not much better than a shell.

## Known limits (read before production)

- **The HTTPS gate is only as strong as your proxy.** The endpoint refuses plaintext with 403, deciding with `is_ssl()`, which on a proxied deployment reads a header. A proxy that forwards the client's `X-Forwarded-Proto` instead of setting it lets a client claim HTTPS over a plaintext connection, token in cleartext. Set it at the proxy and never pass the client's value through; `composer test:infra` asks a running host whether you did.
- **Behind a proxy or CDN**, `REMOTE_ADDR` is the proxy, so every auth event names the proxy rather than the caller. Read the real client address via the provided `wpmcp_client_ip` filter, and only trust a forwarded header from a proxy you control. Nothing is enforced from that value; the log is the reason to get it right.
- **The credential is a request header, never a URL.** `Authorization: Bearer <token>` is the only form accepted; a path that carries a token is a plain `404`. Request paths are written to access logs, proxy logs and browser history by default, and headers are not. If a client cannot send a header, it cannot use this endpoint.
- **Anybody holding the token has that token's full scope, from anywhere.** Mint `read` unless you specifically need writes, and keep code editing off unless you are actively using it.

## Reporting

For anything that looks like a vulnerability, use GitHub's private reporting on this
repository: **Security > Advisories > Report a vulnerability**
(<https://github.com/mkonstan/wp-mcp/security/advisories/new>). That opens a thread only
the maintainer can see. Please do not file a public issue first.

Anything else, including a security question that is not a finding, belongs in a normal
issue: <https://github.com/mkonstan/wp-mcp/issues>.
