# Security model

WP MCP exists to let an AI assistant reach a WordPress site without handing it a password or a permanent key. This document explains what it defends against, how, and where the limits are. Nothing here is secret; the design is meant to be read.

## The token

- **256 bits of randomness** (`random_bytes(32)`), so guessing or brute force is not a threat.
- **Stored as a SHA-256 hash, never in plaintext.** The raw token is shown once at mint and never again. A read-only database compromise (a SQL-injection elsewhere, a leaked backup) exposes zero usable tokens.
- **Two timers, both enforced on every request** rather than by a cleanup cron.
  - The **active window** (6 h by default, 12 h maximum) is how long the token answers. When it elapses the token is **dormant**: refused like any other bad credential, its row kept, and an admin's **Renew** restarts the window without changing the token. This is the number that bounds the damage of a leak, and it stays short precisely because Renew exists.
  - **One relaxation, for local development only.** When `wp_get_environment_type()` answers exactly `local`, the maximum window is 30 days instead of 12 hours - for mint, for Renew and for the mint form, which states it. `development` and `staging` do not qualify, because a development server can face the internet. **Setting `WP_ENVIRONMENT_TYPE` to `'local'` on a server the internet can reach widens every token's window on that site to 30 days**, which is a real loss: the window is what bounds the damage of a leaked token. Set it only on a machine nobody else can reach. The lifetime still bounds the window, Renew still cannot reach past it, and a database carrying 30-day windows is renewed at 12 hours as soon as it is served from a site that does not report `local`.
  - The **lifetime** (30 days by default, 365 maximum) is the hard end. Past it the token is **dead**: not renewable, and removed by the hourly cleanup.
  - Both are the same anonymous `401` to the caller. Which one ran out appears only in the auth log, as `reason=dormant` or `reason=expired`.
  - Renew can never push the window past the lifetime, so a short window renewed indefinitely cannot outlive the hard end.
- **Bound to a WordPress user**, chosen at mint time. The request runs as that user, so that user's capabilities are the ceiling on what the token can reach. Deleting the user stops the token working.
- **Scope: `read` or `admin`**, narrowing from there. Read tokens are refused every write and code tool, and those tools are not even listed to them. Scope only subtracts; it cannot grant a capability the user does not have.

## No address binding, and why not

A token used to lock to a single client address on its first tool call and refuse every later request from anywhere else. That is removed as of 1.1.0.

Measured on a public test site on 2026-09-13: an Anthropic-hosted connector (claude.ai on the web, Claude Desktop) reaches a server from a **pool** of egress addresses - `160.79.106.164`, `.185`, `.186` and `.187` were all seen inside one minute. There is no single address to hold a token to, so the lock authenticated the first call of a session and refused the rest of it. No amount of address bookkeeping fixes that.

The caller's address is still recorded on every auth event, where an operator can read it. It decides nothing.

**What this costs.** A token copied out of a log or intercepted is usable from anywhere. Two things carry that weight instead: the credential is a request header rather than a URL, so it is not written to access logs in the first place, and the active window - short, because Renew makes a short one practical - is enforced on every request. Mint `read` unless you need writes.

## Dormant by default

With no live token in the table, the endpoint returns 401 to everything. There is no anonymous surface. Enabling the plugin does not open anything until you mint a token; deleting all tokens closes it again.

## Code editing sandbox (opt-in, off by default)

When enabled, the code tools' file API is fenced:

- **Jailed to the active theme directory.** Paths are resolved and re-checked; `..` traversal and symlinks pointing outside the jail are rejected on read, write, and delete. `code-restore` puts the path stored in a version row through the same check rather than trusting it, because a denylist can be widened after a version was stored.
- **One file, one spelling.** Every path is canonicalised from the resolved location before the denylist sees it, so `./inc/x.php` and `inc/x.php` are the same file. Until 1.1.0 they were not, and a leading `./` walked past every *directory* rule in the denylist (bare-filename rules such as `functions.php` were never affected). Found by review, measured on a live theme.
- **Nothing is written beside a theme file.** A file's previous contents go into a database table (`{prefix}wpmcp_file_versions`), never to a sibling file. Up to and including 1.1.0's predecessor the tools wrote `<file>` plus a backup extension into the active theme - inside your document root, with an extension nothing executes and nothing blocks, so the URL returned the complete source of a theme file to anyone who asked. Upgrading collects any that are still on disk and removes them; see README's *Code editing*.
- **Denylist** (configurable; default `functions.php`, `index.php`, `inc/`, `includes/`, `lib/`) is never read or written, so the files most likely to take down the whole site or hold secrets are off limits.
- **Text extensions only**, size-capped.
- **Versioned + auto-revert.** Every write and every delete stores the file's current contents in the table first, and refuses to touch the disk if it could not; PHP writes are parse-checked and reverted automatically on a syntax error, from the bytes just stored. `code-history` lists them and `code-restore` puts one back.
- **Self-protection, and exactly how far it goes.** The jail is the active theme, and the plugin lives elsewhere, so the code tools' file API cannot open, overwrite or delete this plugin's own files. That is the whole of it. The jail constrains which files the tools touch; it does not constrain what the PHP written into those files does when WordPress runs it. A template in the active theme is executable code: it can read the database, rewrite this plugin's options, write anywhere the web server user can write, and so switch the guard off from the inside.

  So an admin-scope token with code editing enabled is arbitrary code execution on your server, with the web server's privileges. Nothing in the sandbox changes that, and the denylist, the parse check and the version store are there to stop accidents rather than an attacker. Leave code editing off unless you are actively using it.

## SQL reads (opt-in, off by default)

When enabled, `sql-select` runs one read-only SQL statement per call on WordPress's own database connection.

- **It reads everything that connection can read.** `wp_users` and its password hashes, every plugin's tables, every row of `wp_options` including API keys and credentials other plugins have stored there. This is not a gap in the design - it is the feature, stated plainly. The plugin borrows WordPress's `$wpdb`, which already holds those privileges, and there is no `GRANT` it can drop on a connection it does not own. **Do not enable this for a token you would not hand a database password to.**
- **And, on a server whose MySQL permits it, any file MySQL itself can read.** `LOAD_FILE()` passes both walls - it is a query expression, so the wrapper accepts it, and it is a read, so the read-only transaction accepts it (measured through this tool's exact wrapper on MySQL 8.4.0: accepted by both). What decides whether bytes come back is `secure_file_priv` and whether the database user holds `FILE`, and **neither is the plugin's to set**. On a host where they permit it, `SELECT LOAD_FILE('.../wp-config.php')` is the database password, `AUTH_KEY`, `SECURE_AUTH_KEY` and every other salt. `load_file` is therefore refused by name, in the same blunt denylist as the plugin's own tables. **That is one function, not a file-read boundary** - it is the only file-reading function reachable inside a `SELECT` expression (`INTO OUTFILE` and `INTO DUMPFILE` are already syntax errors inside the wrapper), and refusing it closes the obvious route rather than the class. **Pin `secure_file_priv = NULL` in your MySQL configuration, or deny the WordPress database user `FILE`.** Both are worth doing whether or not you enable this tool.
- **Three gates, all of them required.** The switch in Settings > WP MCP, an admin-scope token, and `manage_options` on the token's user. Admin SCOPE is not an administrator - a token can be minted to run as any user - so the capability is checked per call, exactly as `edit_themes` is for the code tools. With the switch off the tool is absent from `tools/list` and calling it by name is refused identically to calling a tool that does not exist.
- **Writes are refused by the server, not by a filter.** The statement is wrapped as a derived table and run inside `START TRANSACTION READ ONLY`. The wrapper makes `UPDATE`, `DELETE`, `SHOW`, a stacked second statement, `INTO OUTFILE`, `INTO DUMPFILE` and `INTO @var` syntax errors decided by MySQL's own parser (1064). The transaction refuses what the wrapper lets through - `SELECT ... FOR UPDATE` parses fine inside a derived table on MySQL 8.4, and is stopped by the transaction with 1792. Nothing in this plugin inspects your SQL to decide whether it is safe; a SQL parser in PHP would have to be exactly as correct as MySQL's grammar, and the failure mode when it is not is silent.
- **The connection is handed back clean.** `ROLLBACK` runs in a `finally`, whatever happened, because WordPress reuses that connection for the rest of the request. A session left inside a read-only transaction would fail every write after it with 1792, in the middle of unrelated code.
- **Bounded.** 200 rows, 256 KB of rows, 8 KB per cell, and a 5-second server-side statement timeout, so one query cannot become the response or hold the database.
- **The plugin's own two tables are refused by name.** `{prefix}wpmcp_tokens` and `{prefix}wpmcp_file_versions` - the token hashes and the stored theme-file bytes. Together with the `LOAD_FILE` refusal above, this is the whole of the string inspection in the tool - two table names and one function name - and it exists because the server cannot make these decisions: the database user owns those tables, and usually holds `FILE`. The check is deliberately blunt - naming either table anywhere in the statement, comments and string literals included, refuses the whole call. Over-refusal is the safe direction.
- **Every call is logged.** A `sql_select` auth event records the token, the user, the row count and the first 200 characters of the statement. A refused statement returns the MySQL error number and a trace id; the server's message and the whole statement go to the private trace log and nowhere else, because 1064 quotes the statement back and 1054 names a column.
- **What it does not do.** It cannot write, and so it cannot switch itself on, mint a token, or change an option. That is a real difference from code editing, which is arbitrary code execution. The risk here is disclosure: total disclosure of everything in the database, and - on a server that has not set `secure_file_priv = NULL` or denied `FILE` - of whatever else MySQL can read off the disk.

## What is deliberately NOT built

These were considered and left out on purpose: installing plugins from a URL (downloads and runs code), deleting users, managing site options or users, activating/deactivating plugins, and any arbitrary `eval` / WP-CLI passthrough. They are the high-blast-radius actions; a scoped tool list that includes them is not much better than a shell.

## Known limits (read before production)

- **The HTTPS gate is only as strong as your proxy.** The endpoint refuses plaintext with 403, deciding with `is_ssl()`, which on a proxied deployment reads a header. A proxy that forwards the client's `X-Forwarded-Proto` instead of setting it lets a client claim HTTPS over a plaintext connection, token in cleartext. Set it at the proxy and never pass the client's value through; `composer test:infra` asks a running host whether you did.
- **Behind a proxy or CDN**, `REMOTE_ADDR` is the proxy, so every auth event names the proxy rather than the caller. Read the real client address via the provided `wpmcp_client_ip` filter, and only trust a forwarded header from a proxy you control. Nothing is enforced from that value; the log is the reason to get it right.
- **The credential is a request header, never a URL.** `Authorization: Bearer <token>` is the only form accepted; a path that carries a token is a plain `404`. Request paths are written to access logs, proxy logs and browser history by default, and headers are not. If a client cannot send a header, it cannot use this endpoint.
- **Anybody holding the token has that token's full scope, from anywhere.** Mint `read` unless you specifically need writes, and keep code editing and SQL reads off unless you are actively using them.

## Reporting

For anything that looks like a vulnerability, use GitHub's private reporting on this
repository: **Security > Advisories > Report a vulnerability**
(<https://github.com/mkonstan/wp-mcp/security/advisories/new>). That opens a thread only
the maintainer can see. Please do not file a public issue first.

Anything else, including a security question that is not a finding, belongs in a normal
issue: <https://github.com/mkonstan/wp-mcp/issues>.
