# Security model

WP MCP exists to let an AI assistant reach a WordPress site without handing it a password or a permanent key. This document explains what it defends against, how, and where the limits are. Nothing here is secret; the design is meant to be read.

## The token

- **256 bits of randomness** (`random_bytes(32)`), so guessing or brute force is not a threat.
- **Stored as a SHA-256 hash, never in plaintext.** The raw token is shown once at mint and never again. A read-only database compromise (a SQL-injection elsewhere, a leaked backup) exposes zero usable tokens.
- **Hard expiry, capped at 12 hours**, enforced on every request, not just by a cleanup cron. An expired token is deleted the moment it is presented.
- **Scope: `read` or `admin`.** Read tokens are refused every write and code tool, and those tools are not even listed to them.

## IP pinning (TOFU)

A token locks to a single IP, trust on first use. Two deliberate refinements:

1. **Binding waits for the first real tool call.** Discovery requests (the `initialize` / `tools/list` handshake a client runs at setup) do not create the binding. This matters because a client's setup handshake can originate from a different IP than its live session; binding on the handshake would pin the wrong address and lock the real client out.
2. **Once bound, the pin is universal.** After the first tool call sets it, *every* request, discovery included, must match the bound IP or gets a 403.

Net effect: a token copied out of a log or intercepted is useless from any other machine.

## Dormant by default

With no live token in the table, the endpoint returns 401 to everything. There is no anonymous surface. Enabling the plugin does not open anything until you mint a token; deleting all tokens closes it again.

## Code editing sandbox (opt-in, off by default)

When enabled, the code tools are fenced hard:

- **Jailed to the active theme directory.** Paths are resolved and re-checked; `..` traversal and symlinks pointing outside the jail are rejected on read, write, and delete (including the `.bak` backup path).
- **Denylist** (configurable; default `functions.php`, `index.php`, `inc/`, `includes/`, `lib/`) is never read or written, so the files most likely to take down the whole site or hold secrets are off limits.
- **Text extensions only**, size-capped.
- **Backup + auto-revert.** Every write copies the old file aside first; PHP writes are parse-checked and reverted automatically on a syntax error.
- **Self-protection.** The jail is the theme; the plugin lives elsewhere, so the tools can never edit their own auth code.

## What is deliberately NOT built

These were considered and left out on purpose: installing plugins from a URL (downloads and runs code), deleting users, managing site options or users, activating/deactivating plugins, and any arbitrary `eval` / WP-CLI passthrough. They are the high-blast-radius actions; a scoped tool list that includes them is not much better than a shell.

## Known limits (read before production)

- **Behind a proxy or CDN**, `REMOTE_ADDR` is the proxy, so IP pinning sees every client as the same address. Read the real client IP via the provided `wpmcp_client_ip` filter, and only trust a forwarded header from a proxy you control.
- **Path-in-URL tokens land in access logs.** The full request path is written by most web servers. Prefer the `Authorization: Bearer` header, which is not logged by default. (The IP pin still protects a logged path token, but the header avoids the exposure entirely.)
- **A token holder on the bound IP has that token's full scope.** Mint `read` unless you specifically need writes, and keep code editing off unless you are actively using it.

## Reporting

Found an issue? Open a GitHub issue, or for anything sensitive, contact the maintainer privately rather than filing publicly.
