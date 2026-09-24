# WP MCP

WP MCP is a self-hosted WordPress plugin that lets an AI assistant work on your site over
the [Model Context Protocol](https://modelcontextprotocol.io). You mint a short-lived
token in your dashboard, pick the WordPress user it authenticates as, paste it into your
client, and the client gets a tool list bounded by that user's capabilities. There is no
external service, no OAuth app to register and nothing vendored at runtime: a handful of
PHP files, a token table, and one REST route that stays dormant until a live token exists.

## Requirements

| | |
|---|---|
| PHP | 8.1 or newer |
| WordPress | 6.9 or newer |
| HTTPS | required; the endpoint refuses plaintext with 403 before it reads the token |

**What works at which WordPress version.** One row, because there is nothing to put in a
second: the whole documented tool set works at 6.9, and every version above it. If a later
release gates a feature on a newer WordPress, that feature gets its own row here and says so
in its own tool description - WordPress has ONE `Requires at least` field for the whole
plugin, not one per feature, so a per-feature condition has to be documented rather than
implied.

| WordPress | What you get |
|---|---|
| 6.9 and newer | Everything this README documents |

**Where 6.9 comes from, and it is a choice rather than a derivation.** Earlier releases of
this plugin derived the floor from the oldest core function they called - 5.5, then 6.4, the
latter because `_wp_put_post_revision`'s `$post_id` argument is `@since 6.4.0` and
`restore-revision` filters on it. That is still the oldest WordPress the code would RUN on,
and it is no longer the floor. 6.9 is where the Abilities API begins
(`wp_register_ability()`, `@since 6.9.0`), which is the surface the WordPress ecosystem has
converged on: core registers three abilities, Rank Math 24, Gravity Forms 32 behind a flag,
and ACF Pro 6.8.10 ships its own for field groups, post types, taxonomies and per-post-type
CRUD. Supporting below it costs a version question on every future feature, and a 91-minute
CI job, to serve sites that are unlikely to run an agent at all. W3Techs, 23 September 2026:
62.6% of WordPress sites run 7.x and 30.4% the whole of 6.x, so a 6.9 floor keeps the
overwhelming majority and drops versions that are updating themselves out of existence.

**And 6.9 is executed, not asserted.** `Requires at least` is a gate, not a hint: core's
`validate_plugin_requirements()` refuses to ACTIVATE a plugin below the version it declares,
so a number nobody runs is a promise nobody has checked. CI runs the integration suite twice
on every change to the plugin's code - once against the current WordPress release and once
against **WordPress 6.9 on PHP 8.4**, which is the pairing this floor is actually tested at.
6.9 shipped on 2 December 2025, twelve days after PHP 8.5, so 8.4 is the newest PHP that was
in active support when that WordPress was released - the same rule that made the old 6.4 leg
run on 8.2 rather than on the PHP of its own release month. 6.9 itself requires PHP 7.2.24
(`$required_php_version` in its `wp-includes/version.php`), so 8.4 is well inside what it
supports, and the `Requires PHP: 8.1` floor is proven separately by the unit tier, which runs
on 8.1, 8.2, 8.3 and 8.4. Declaring a combination nobody can execute is exactly the mistake
the "WordPress 5.5 with PHP 8.1" claim of 1.0 made.

HTTPS is not optional, and behind a proxy it needs one line of configuration. Read
[HTTPS enforcement depends on your proxy](#https-enforcement-depends-on-your-proxy)
before you put this on a production host.

## Install

1. Download [`wp-mcp.zip`](https://github.com/mkonstan/wp-mcp/releases/latest/download/wp-mcp.zip).
2. **Plugins > Add New > Upload Plugin**, choose the zip, **Install**, then **Activate**.
3. A new page appears under **Settings > WP MCP**.

Or clone the repository into `wp-content/plugins/wp-mcp/`.

Activation creates the token table and the trace log directory. Deleting the plugin
removes both, along with the plugin's options and its cron hook. Deactivating leaves
everything in place.

### Which build is this site running?

The version alone cannot tell two builds apart - every build of 1.1.0 says `1.1.0` - so
every copy also carries the **commit it was built from**, and four places will tell you
which:

| Where | What you see |
|---|---|
| **Plugins** screen, beside the version | `Build: 3b5d129` |
| **Settings > WP MCP**, under the heading | `Version 1.1.0 · build 3b5d129 (committed …)` |
| `initialize` → `serverInfo.build` | what your client can report |
| `site-info` → `wp_mcp.build` | what an agent can read without leaving the tools |

All four print the same string. It is written into the zip by `git archive` when the zip
is built, so it cannot drift from the code it names.

**`source` means this is not a zip.** A site running the plugin out of a git checkout - a
clone in `wp-content/plugins`, a symlink, a development junction - has no build to report,
and the plugin says `source` rather than inventing a number. Nothing is wrong with such a
site; that is how the plugin is developed. But if a site you *uploaded a zip to* says
`source`, that zip was not built by the recipe in [`docs/RELEASE.md`](docs/RELEASE.md) and
there is no way to tell which one it is.

**The stamp names the zip, not the folder - so replace the plugin rather than copying
files over it.** `build.txt` arrives with the zip and is not touched again. If you copy new
PHP files into an existing `wp-mcp/` folder instead of deleting the old plugin and
installing the new one, the previous build's `build.txt` stays behind and the plugin
cheerfully reports a build it is not running. Nothing git or the recipes do can produce
that; only a hand copy can.

The `Version:` header, `WPMCP_VER` and `serverInfo.version` stay a plain semantic version
on every build; the build sits beside the version and never inside it.

## Mint a token

**Settings > WP MCP**. Four fields decide what the token can do.

**Runs as** is the WordPress user the token authenticates as. Every request runs as that
user, so that user's capabilities are the ceiling on what the token can see or change. A
token minted for an Editor cannot read another author's private post, cannot delete
someone else's page, and sees only approved comments unless that Editor holds
`moderate_comments`. Deleting the user stops the token working. The field defaults to you.

**Scope** narrows from there. A `read` token is served the read tools and nothing else:
the write tools are not listed to it, and are refused if it calls one anyway. That is
fourteen tools, plus `get-post-meta` when the site has declared post meta keys. An `admin`
token is served those and fifteen more - the thirteen that write, `list-plugins` and
`list-themes` - plus `set-post-meta` with the same meta keys declared, `sql-select` when SQL
reads are on, and six more when code editing is on.
Scope only subtracts. It cannot hand a token a capability its user does not have.

**Read scope is not privacy.** A `read` token can read everything its user can - every
post that user may see, every comment, and, when the user is an administrator, every
user's email address through `list-users`. Scope gates *writing* only. To give an
assistant less to read, mint the token for a user who can see less. The mint form and the
server's handshake instructions both say this.

**Active window** and **Lifetime** are two separate timers, and the split is what lets a
token be both short-lived and long-lived at once.

The *active window* - 6 hours by default, 12 at most - is how long the token answers.
When it elapses the token goes **dormant**: refused with the same anonymous `401` as any
other bad credential, but its row stays in the table and **Renew** restarts the window.
The token itself never changes, so whatever is holding it needs no edit.

**On a local development site the window may run to 30 days.** If
`wp_get_environment_type()` answers exactly `local` - WordPress reads that from the
`WP_ENVIRONMENT_TYPE` constant or environment variable, and Local by Flywheel and
`wp-env` both set it - mint, Renew and the mint form allow a window of up to 30 days, and
the form says so. Any other answer, `development` and `staging` included, keeps the
12-hour cap: a development server can face the internet. The daily checkpoint exists for
connectors on sites the internet can reach; on a developer's own machine it only makes an
MCP client fail to connect every morning. The lifetime still bounds the window.

**The cap is the serving site's, and it holds on every request.** A row's window is read as
`min(the window it was granted, this site's maximum)`, counted from the moment that window
last started. So a database copied from a local site to a public one brings no 30-day
windows with it: each of those tokens is **dormant** at most 12 hours after its last renewal
there - at once, if that renewal was clipped by the token's lifetime - and pressing Renew
gives it 12 hours like any other token. The row is never deleted or revoked, the token
itself never changes, and a local site goes on honouring its own 30 days.

**A Renew away from the local site narrows the row for good.** Renewing on a site with a
12-hour ceiling stores that ceiling as the row's window, so the same token renewed back on
the local site gets 12 hours, not 30 days. There is no screen for widening a stored window:
mint a new token on the local site to get 30 days again.

The *lifetime* - 30 days by default, 365 at most - is the hard end. Past it the token is
**dead**: Renew is not offered, the hourly cleanup removes the row, and the only way on is
a new token.

That is the shape the clients need. claude.ai and Claude Desktop cannot edit a connector's
request header once the connector has been added, so replacing a token means deleting and
re-adding the connector; with one timer, a cap short enough to matter made that a
twice-daily chore. Renew moves the window without touching the credential.

**Label** is for you, so the active-tokens table means something a day later.

The token is shown once. Only its SHA-256 hash is stored, so the page cannot show it
again. The table below it lists what is live, the user each token runs as, each token's
state (active / dormant / dead, or **owner missing** when the WordPress user it runs as
has been deleted), when its window and its lifetime end, **which client is on the other end**,
and its last use, with **Renew** and **Revoke** buttons per row.

The **Client** column is the name and version the client sends in the first message of every
connection, so a row reads `Claude Desktop 1.4` and the last-use cell reads
`2026-09-23 08:41:07 (3 minutes ago)`. A dash means nothing has ever negotiated with that token.
It is evidence about what connected and never an identity check: a client is free to call itself
anything, and the strings are stored as text and escaped on the way out. Renew is offered only where it can work: not on a dead row,
and not on one whose owner is gone.

When a client starts getting `401`, the table is where you find out which timer ran out:
dormant needs Renew and nothing else, dead needs a new token and one edit of the client.

## Connect a client

The endpoint speaks JSON-RPC over HTTP at `/wp-json/wpmcp/mcp`. That URL is constant for
the life of the site and never contains the token. There is exactly one way to present a
credential:

```
Authorization: Bearer <64 lowercase hex characters>
```

A URL that carried the token used to be accepted as well. It is gone: such a URL is written
into every access log, proxy log and browser history it passes through, and a hosted
connector keeps re-sending it for months. A request to that endpoint path with a token
appended to it is now a plain REST `404`.

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

```bash
claude mcp add --transport http wpmcp https://your-site.example/wp-json/wpmcp/mcp   --header "Authorization: Bearer YOUR_TOKEN"
```

claude.ai and Claude Desktop custom connectors send the header too: **Add custom
connector**, paste the URL, pick **No sign-in** for authentication, then add a request
header named `authorization` with the value `Bearer YOUR_TOKEN`.

**If every request is refused with `reason=missing` while you are certain the header is
being sent**, the web server is eating it. Apache running PHP as CGI or FastCGI does not
pass `Authorization` through to PHP. WordPress handles that itself, provided its own
`.htaccess` block is present - so check for this line rather than looking at the plugin:

```apache
RewriteRule ^ - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
```

A Desktop or claude.ai connector dials from Anthropic's servers rather than from your
machine, so it can only reach a site on the public internet with a publicly valid
certificate. A `.local` development site cannot be connected that way at all. The Claude
Code CLI dials from your own machine and can.

[docs/CONNECT-CLIENTS.md](docs/CONNECT-CLIENTS.md) has the walkthrough for both, and a
table of log lines to check when a client will not connect.

## The tools

Thirty-eight tools. Each declares the four MCP annotation hints, so a client can tell a
listing from a deletion before it asks you to approve anything.

| Tool | Scope | readOnly | destructive | idempotent | openWorld |
|---|---|:--:|:--:|:--:|:--:|
| `site-info` | read | yes | no | yes | no |
| `list-posts` | read | yes | no | yes | no |
| `get-post` | read | yes | no | yes | no |
| `list-revisions` | read | yes | no | yes | no |
| `get-revision` | read | yes | no | yes | no |
| `list-terms` | read | yes | no | yes | no |
| `list-media` | read | yes | no | yes | no |
| `get-media` | read | yes | no | yes | no |
| `list-comments` | read | yes | no | yes | no |
| `list-menus` | read | yes | no | yes | no |
| `get-menu` | read | yes | no | yes | no |
| `list-users` | read | yes | no | yes | no |
| `get-user` | read | yes | no | yes | no |
| `get-option` | read | yes | no | yes | no |
| `create-post` | admin | no | no | no | no |
| `update-post` | admin | no | yes | yes | no |
| `delete-post` | admin | no | yes | yes | no |
| `restore-revision` | admin | no | yes | yes | no |
| `create-term` | admin | no | no | no | no |
| `delete-term` | admin | no | yes | yes | no |
| `upload-media` | admin | no | no | no | yes |
| `delete-media` | admin | no | yes | yes | no |
| `moderate-comment` | admin | no | yes | yes | no |
| `reply-comment` | admin | no | no | no | no |
| `add-menu-item` | admin | no | no | no | no |
| `update-menu-item` | admin | no | yes | yes | no |
| `remove-menu-item` | admin | no | yes | yes | no |
| `list-plugins` | admin | no | no | yes | no |
| `list-themes` | admin | no | no | yes | no |
| `code-list` | admin + code editing | no | no | yes | no |
| `code-read` | admin + code editing | no | no | yes | no |
| `code-write` | admin + code editing | no | yes | no | no |
| `code-delete` | admin + code editing | no | yes | yes | no |
| `code-history` | admin + code editing | no | no | yes | no |
| `code-restore` | admin + code editing | no | yes | no | no |
| `sql-select` | admin + SQL reads | no | no | yes | no |
| `get-post-meta` | read + meta keys | yes | no | yes | no |
| `set-post-meta` | admin + meta keys | no | yes | yes | no |

Three rows in that table need a sentence.

`readOnlyHint` is the inverse of the scope gate, not of what the tool does to your
database. `code-list`, `code-read`, `code-history`, `sql-select`, `list-plugins` and
`list-themes` only look, but they sit behind the admin gate, so they report `false`. The
active theme is source code, not content, a SELECT over `wp_users` is not content either,
and neither is what is installed on the server. `destructiveHint` is where
each of them says it destroys nothing.

`destructiveHint: false` is MCP's own narrow promise that an update is additive. The five
tools that make a new object per call keep it. `update-post` does not: it replaces every
field it is given, and its `terms` argument replaces the post's terms in that taxonomy
rather than adding to them.

`openWorldHint` is true for `upload-media` alone, which fetches a URL you supply. Every
other tool's reach stops at this site's own database and files; `list-plugins` and
`list-themes` read files and stored settings directly and run no update, auto-update,
plugin-header, theme or per-option filter - the ones other plugins fetch update data from.

`get-post-meta` is the one read tool behind an opt-in: it is listed only when the site
has declared post meta keys (see *Post meta*), and it reports `readOnlyHint: true` like
every other `read`-scope tool.

`create-post` defaults to `draft`. `delete-post` moves a post of any type to the trash
unless you pass `force: true`; a post already in the trash stays there, and only a site
with the trash switched off (`EMPTY_TRASH_DAYS` 0) deletes it outright. `delete-media` is
different because WordPress is: unless the site defines `MEDIA_TRASH`, an attachment is
deleted permanently, file and all, whatever `force` says. Both answer `deleted` and
`trashed` as read back after the call, never as assumed. Every argument is validated
against the tool's schema before the tool runs: a wrong type or an unknown key comes back
as an error naming the field, and the tool never executes.

Every description says what the tool returns - field names and their formats - so a
client does not have to call a tool to learn its shape.

**Four tools say it in an `outputSchema` instead** (1.1.1, a pilot): `site-info`, `get-post`,
`get-media` and `get-user` declare the shape of their result as JSON Schema, a sentence per
field, and send `structuredContent` beside the usual text block - which stays, because the
specification says it must. A client that reads only the text block sees no difference.

Two reasons, and neither is "clients need it". First, each of the four declares its fields ONCE
in this plugin's own source, and both the schema and the result are generated from that
declaration, so they cannot drift - which is a class of bug this project has had more than once.
Second, it gives back the 1,000 description characters a client keeps: the field list is in the
schema now, and the description carries the warnings. Whether the other thirty-four tools follow
depends on what a real client actually does with the structured half.

### Lists: one envelope, one date format, and an end

Six tools page: `list-posts`, `list-revisions`, `list-terms`, `list-media`, `list-comments`
and `list-users`. All six take `limit` (1-100, default 20) and `page`, and answer
`{count, page, limit, has_more, items}` with no total. `list-media` and `list-comments`
still accept `per_page`, `limit`'s old name.

**Paging ends at page 100, and says so.** `page` is clamped to 100, and at page 100
`has_more` is `false` whatever lies beyond - so "page until `has_more` is false" always
terminates. Before 1.1.0 a page past 100 answered page 100's rows with `has_more: true`
for ever. To reach further, narrow the filter.

**Every date a list tool returns is ISO 8601, site-local, with no offset** -
`2026-03-04T09:30:00`, the form `get-post` gives `date` - including `list-media` and
`list-comments`, which returned the raw UTC column before 1.1.0, and
`list-users.registered` and `code-history.saved_at`, which are stored in UTC and converted.

### Reading it back: every value can be written back

A field a tool returns and another tool accepts is returned in the form you would type,
and **writing it back unchanged stores the same bytes**. That is measured for every such
field on two sites and held by the tests: post title, content, excerpt, slug, status,
date, author, featured image and terms through `update-post`; term names through
`create-term` and `update-post`'s `terms`; a menu item's label and url through
`update-menu-item`; a comment's status through `moderate-comment`; a media title and alt
text through `upload-media`; a theme file through `code-write`; a meta value through
`set-post-meta`.

Two things make that true:

- **Names WordPress escapes come back decoded.** Core stores a term name `Arts & Crafts`
  as `Arts &amp; Crafts`; `list-terms`, `get-post`'s `terms`, `create-term` and the menu
  names in `list-menus` and `get-menu` return `Arts & Crafts`, and sending that back finds
  the same term. A menu label wp-admin saved as `FDA &#038; GMP` reads as `FDA & GMP`. Only
  `&amp;`, `&lt;`, `&gt;` and their numeric forms are decoded - the escaping core applies -
  so a curly quote stored as `&#8217;` stays as stored.
- **A value sent back unchanged is not re-written.** `update-post` leaves out every field
  equal to what is stored, so re-shaping cannot change it: a title wp-admin stored as
  `x<y z` is no longer stripped to `x`, and a draft nobody dated stays undated. An update
  that changes nothing writes nothing - no revision, no new modified date. An update that
  changes anything, terms or the featured image alone included, is a real save: `modified`
  moves and WordPress's save hooks fire, so cache and search plugins hear of it.
  `update-menu-item` keeps a label's stored bytes when it is sent back as `get-menu` read
  it.

`get-post`'s `terms` entries can be sent to `update-post` as they are: `terms` takes an id,
a name, or the `{id, name, slug}` object itself. An empty list clears that taxonomy - except
that WordPress gives a post left with no category its default category.

### Finding content

`list-posts` is the search surface. Every argument is optional:

| Argument | Takes | Default |
|---|---|---|
| `post_type` | one post type this tool serves | `post` |
| `status` | one post status, a plugin's own included | every status the caller may see |
| `search` | text matched against title, excerpt and content; a leading `-` on a word **excludes** it | - |
| `category`, `tag` | a slug or a term id | - |
| `term` | `"taxonomy:slug"`, for any other taxonomy | - |
| `author` | a user id or a user login | - |
| `after`, `before` | `YYYY-MM-DD` or `YYYY-MM-DDTHH:MM[:SS]`, both inclusive | - |
| `orderby` | `date`, `modified` or `title` | `date` |
| `order` | `asc` or `desc` | `desc` |
| `limit` | 1-100 | 20 |
| `page` | 1-100; `has_more` is false at 100 | 1 |

It answers with `count`, `page`, `limit`, `has_more` and `items`. Each item is `id`,
`title`, `type`, `status`, `slug`, `link`, `date` and `modified` - the dates in ISO 8601,
null where the column holds no date, exactly as `get-post` reports them, and `link` in the
same form `get-post` gives it. There is no total, on purpose: a total is a count of posts
the caller has not been shown, and on the own-unpublished side it would be a count of
somebody's drafts. Page until `has_more` is `false`; at page 100 it is, and a narrower
filter reaches the rest.

Two things it deliberately does not do. **Sticky posts are ignored**: WordPress pins them to
the front of a home query regardless of what was asked for, which would mean a date window or
a status filter quietly returning posts outside it. And **ties are broken by ID**, in the same
direction as the sort, so paging over rows that share a date or a title cannot show one row
twice and another never.

**A plugin's CUSTOM post statuses are listable, scoped by the same capabilities** (1.1.1).
WordPress's status registry decides, not a list in this plugin: a status registered `public` is
listable by anybody, because the site already shows it on the front end; one registered
`protected` - which is where core's `draft`, `pending` and `future` live - needs
`edit_others_posts` for somebody else's post and is always visible on your own; one registered
`private` needs `read_private_posts`; and a status registered with none of the three is listable
by nobody, which is WordPress's own answer for it. `trash` and `auto-draft` are internal and
stay out.

**A filter that names something the caller may not see returns an empty list, not an
error.** An unknown category, a tag holding only somebody else's draft, an author who has
published nothing, a term in a taxonomy that is not public, and a taxonomy nobody
registered all give the same answer as a real category with nothing in it. That is
deliberate: three different answers would let a caller probe the site's term names and
user logins one guess at a time.

The two things that *are* errors are a date that is not a date and an `orderby` that is
not one of the three. Those are mistakes about this protocol rather than facts about your
site, and an agent told "no results" would conclude the site is empty and stop.

A filter never widens what a token may see. The statuses a caller is allowed to list are
decided from their capabilities first; filters only narrow inside that.

`get-post {id}` returns the whole post: `title`, `type`, `status`, `slug`, `link`,
`content` and `excerpt` as stored, `author` as `{id, name}`, `date`, `date_gmt`,
`modified` and `modified_gmt` as ISO 8601, `featured_image` as `{id, url}` or `null`,
`terms` keyed by taxonomy with `{id, name, slug}` entries, and `revisions`.

**`link` is what WordPress renders, and that is not always the pretty permalink.** While a
post is a draft, pending, scheduled (`future`) or in the trash, WordPress renders the plain
`?p=ID` form whatever the site's permalink structure is and whatever slug the post already
holds - measured on both test sites. A `private` post gets the pretty permalink. Every tool
that returns `link` - `list-posts`, `get-post`, `create-post` and `update-post` - returns
the same form, read after the last write, so publishing a draft answers its new permalink.

The title, like `content` and `excerpt`, is the stored column, not WordPress's display
rendering, so quotes, apostrophes, ampersands and backslashes read back as stored, and a
title written back as read is left as it is (see *Reading it back*).

**A title you CHANGE is shaped by WordPress and by nothing of ours** (1.1.1). This plugin used
to run `wp_strip_all_tags()` on it, so a title typed `x<y z` was stored as `x`; that is gone,
and core's own `title_save_pre` decides, exactly as it does when a person saves the post in
wp-admin. Which means the stored bytes depend on the capability of the user your token runs as:

| you send | with `unfiltered_html` | without it |
|---|---|---|
| `x<y z` | `x<y z` | `x&lt;y z` |
| `Tom's "quoted" A\B` | `Tom's "quoted" A\B` | `Tom's "quoted" A\B` |
| `Arts & Crafts` | `Arts & Crafts` | `Arts &amp; Crafts` |

Administrators and editors on a single site hold `unfiltered_html`; authors and contributors do
not, and `DISALLOW_UNFILTERED_HTML` takes it from everybody. Nothing is destroyed on either
path - markup is ENCODED, never stripped - and reading a title back and writing it unchanged
still stores the same bytes, because an unchanged field is not written at all.

Three details in that list are decisions rather than data:

- The author is a **display name** and an id. Never the login, which is half of a
  credential, and never the email.
- A `0000-00-00` date column comes back as **`null`**. WordPress stores drafts, pending
  and scheduled posts with both GMT columns zeroed; formatting that yields
  `-0001-11-30T00:00:00`, which a client parses without complaint.
- `revisions` is a **count**, and only for a caller who can edit the post. Anyone else
  gets `null` rather than `0`, because how many times something was rewritten is
  editorial - wp-admin puts the revisions panel behind the same capability.

`terms` lists only taxonomies that are attached to the post type and public. A private
taxonomy is a plugin's internal bookkeeping, and its term names are often customer
segments or workflow states rather than anything the post says.

### Writing content

`create-post` and `update-post` take the same fields, shaped and checked in one place so
the two cannot disagree. `update-post` needs `id`; everything else is optional on both.

| Argument | Takes | Capability it needs |
|---|---|---|
| `title`, `content`, `excerpt` | text | to edit the post |
| `slug` | text, turned into a URL slug | to edit the post |
| `status` | `draft`, `pending`, `publish`, `private`, `future`, `trash` | publishing and trashing each need their own |
| `terms` | `{taxonomy: [id, name, or get-post's {id, name, slug}]}` | to assign in that taxonomy; naming a term that does not exist yet also needs to create one |
| `date` | ISO 8601 date or datetime | none of its own - see below |
| `author` | a user id or a user login | to edit other people's posts of that type |
| `featured_image` | an image attachment id, or `0` | to edit **that attachment** |

**`date` is the site's local time unless you say otherwise.** `2026-03-04T09:30:00` means
half past nine on your site's clock - the time an editor sees in wp-admin. Add an offset
(`Z`, `+02:00`, `-0500`) and the instant is fixed regardless of where the site thinks it
is. Either way both columns WordPress stores, `post_date` and `post_date_gmt`, are written
to describe one instant. A date that is not a date is an error, not a guess: there is no
`next tuesday`.

**Scheduling is publishing.** To schedule, send a future `date` with `status: "future"`;
the capability is `publish_posts`, the same one a Contributor does not have. Two silent
things WordPress does with dates, which the result therefore reports rather than hides:

- `status: "publish"` with a **future** date is stored as `future`. It is a schedule.
- `status: "future"` with a **past** date is stored as `publish`. It goes live now.

Read `status` and `date` in the reply for what actually happened. A date on a **draft** is
kept, which is not WordPress's default - core re-dates a draft to "now" on every update
unless it is told the date was deliberate.

**`author` needs the capability wp-admin gates its Author box on** to change it - sending
the post's current author back needs nothing - and the user you name
has to be one who could write that post type - naming a Subscriber is an error. The reply
gives `{id, name}`, with the display name; never a login, never an email.

**`featured_image` is checked against the attachment, not against your post.** You need to
be able to edit that attachment, which for an Author means their own uploads and not
someone else's - whose post the file happens to be attached to makes no difference either
way - and it has to be an image. `0` removes the image. It round-trips through
`get-post`'s `featured_image`.

**A field you send equal to its stored value is not written, and `changed` is a diff.**
`update-post` reads the row before and after the write and answers with `changed`: every
field whose stored value differs, in the order title, content, status, excerpt, slug, date,
author, featured_image, terms - whoever changed it. So an update that sends back what it
read answers `changed: []` and writes nothing, and a field WordPress moved on its own is
named although you never sent it. Measured on both test sites (WP 7.0 and 7.1), those are:

| What core does unasked | When |
|---|---|
| `date` moves to "now" | any write to a draft whose `post_date_gmt` is still empty - core's "drafts shouldn't be assigned a date unless the user did so" rule. A date you send, even the unchanged one, is kept. |
| `slug` is derived from the title | a post with no slug whose status LEAVES draft or pending - to `publish`, `future`, `private` or `trash` |
| `slug` gets a `-2`, `-3` ... suffix | a slug another post already holds, on a move from draft or pending to `publish`, `future` or `private` |
| `slug` gets `__trashed` appended | every trashed post, slugged or not (`""` -> `__trashed`) |
| nothing | draft <-> pending, and moves among `publish`, `future` and `private` |
| `status` is not what you sent | `publish` with a future date is stored `future`; `future` with a past date is stored `publish` |

The full table - five starting statuses by six targets, for a slug-less post, a post with
its own slug and a post whose slug is taken - is in the sprint-14d report. When `date` moved
or was sent, the reply also carries the stored `date` and `date_gmt`.

`create-post` answers `changed` with the fields the call set, since everything on a new
post is new.

Backslashes survive. A Windows path, a regular expression or a JSON document written
into a title, a body, an excerpt, a term name, a media title or alt text, or a comment
comes back byte for byte - which was not true before 1.1.0.

### Undoing a content edit

`update-post` saves the post's current title, content and excerpt as a revision **before**
it writes - WordPress on its own saves one only afterwards, of the new text, so the first
edit of a post that had no revisions (every post `create-post` makes, and every imported
one) used to leave nothing to go back to. On a post whose latest revision already matches
it, that save is skipped and costs nothing, and an update that changes nothing writes
nothing - no revision either.

**What that means for the revision count**, measured on both test sites:

| Update | Revisions added |
|---|---|
| the first write that CHANGES something to a post with no revisions, even a status change | 1, holding the text as it stands (WordPress's own `post_updated` handler saves it too) |
| the first write to a post with no revisions, changing its text | 2: the pre-edit text, then the new text above it |
| a later update that changes title, content or excerpt | 1, holding the new text |
| a later update that changes only terms or the featured image | 0 - it is still a save: `modified` moves and `save_post` fires |
| a later update that changes anything else | 0 |
| an update that sends back only what is stored | 0 - nothing is written |

So "no text change, no revision" holds from the second write on; the first write that
CHANGES something on a never-revised post always leaves one. A write that changes nothing
is not that first write: it writes nothing at all, and leaves no revision.

**Which revision is the undo, and it is not the newest one.** After any edit, the newest
revision holds *the text you just wrote* - that is the one WordPress saves afterwards - and
the one below it holds the text as it was before the call. Restoring the newest revision
therefore changes nothing; restoring the one below it is the undo. Measured over three
successive edits of one post:

| After | newest revision | the one below it |
|---|---|---|
| edit 1, `C0` → `C1` | `C1` | `C0` |
| edit 2, `C1` → `C2` | `C2` | `C1` |
| edit 3, `C2` → `C3` | `C3` | `C2` |

- `list-revisions {id, limit?, page?}` lists a post's revisions newest first, paged like
  `list-posts` with `has_more`: id, date, author `{id, name}`, title and `autosave`. No
  content.
- `get-revision {revision_id}` returns one in full - title, content and excerpt raw, as
  `get-post` returns them, so you can compare the two yourself.
- `restore-revision {revision_id}` puts that title, content and excerpt back. Author, slug
  and terms are not revisioned and do not change. Status and date normally do not either,
  but a restore is an ordinary update, so WordPress re-derives the status: a scheduled post
  whose date has already passed is published, and a published post dated in the future
  becomes scheduled. The text it replaces is kept as a revision first and the restored text becomes the
  newest revision, so the title, content and excerpt of a restore can be undone. The reply
  gives `fields`, `new_revision_id` - the revision holding the *restored* text - and
  `pre_restore_revision_id`, a revision saved by this call of what the post said *before* it.
  **`pre_restore_revision_id` is null in the ordinary case**: after any edit made through
  these tools or wp-admin, the newest revision already holds the current text, so there is
  nothing to save. The undo copy is then that revision - the newest non-autosave one
  `list-revisions` showed before the restore. It is non-null only when the post was changed
  without a revision, such as by an import or a direct database edit. `new_revision_id` is
  null when the post already held the restored text. Both are null when revisions are off
  for the post, because nothing could be saved.

**A restore also brings back what WordPress and plugins keep with revisions, and not all of
it can be taken back.** WordPress copies the meta it revisions (core's `footnotes`, and any
key registered as revisioned) from the revision onto the post, and ACF copies its field
values the same way. Measured on a site running ACF Pro 6.3.11:

- Restoring a revision saved from wp-admin's ACF form rewinds the post's ACF fields to that
  revision's values.
- The copy saved before the restore holds core's revisioned meta but no ACF values - ACF
  writes fields into a revision only during its own form save - so restoring that copy
  brings back title, content, excerpt and `footnotes` and **leaves the ACF fields at the
  rewound values**. That part of a restore is not undoable through these tools.
- `fields` in the reply lists the post columns only; it does not say that ACF data moved.

All three need the capability to edit the post the revision belongs to - wp-admin's own
rule - and anything else answers exactly like an id that is not there. `update-post` and
`restore-revision` both refuse, and name who, while another user has the post open in the
editor; your own open editor does not count. `restore-revision` also refuses when revisions
are turned off for the post unless the revision is an autosave. Where revisions are turned
off (`WP_POST_REVISIONS` false, or a post type that does not keep them), `list-revisions` is
empty and `update-post` has nothing to save. Where `WP_POST_REVISIONS` is `1`, WordPress
keeps one revision per post, so the copy `update-post` saves first is deleted by the same
update and there is nothing to restore.

### Menus

Classic menus only - the ones **Appearance > Menus** edits and a classic theme's
`wp_nav_menu()` shows. A block theme's Navigation block keeps its links somewhere else and
is not touched; on such a site `list-menus` says `block_theme: true`, as a warning that a
classic menu you change may not be what visitors see.

- `list-menus` returns every menu (id, name, slug, item count, the theme locations it is
  assigned to), the locations the theme registers with the menu each one holds, and
  `block_theme`.
- `get-menu {id}` returns one menu's items as a tree: id, title, type, object, object_id,
  url, target, classes, parent, position among its siblings, menu_order in the whole menu,
  status, and children. **The order is WordPress's own** (1.1.1): the tree is built by core's
  `Walker`, the same engine `wp_nav_menu()` renders through, so `position` cannot disagree with
  what a visitor sees. One consequence is worth knowing if your menus are untidy: an item whose
  stored parent is not an item of that menu - the parent was deleted, or is in another menu -
  is shown AFTER every top-level tree rather than interleaved by `menu_order`, which is where a
  classic theme shows it, and its own children are shown flat beside it rather than nested under
  it. The next write to that menu renumbers `menu_order` to match. Before 1.1.1 such an item was
  listed at the top level in `menu_order` position, which disagreed with the site. `title` is the item's own label as typed - a label wp-admin stored
  as `FDA &#038; GMP` reads `FDA & GMP` - or, when it has none, the linked page's stored
  title or the linked term's name, decoded like every term name.
- `add-menu-item {menu_id, type, object_id?, url?, title?, parent_id?, position?, target?,
  classes?}` adds one. `type` is `custom` for a plain link, a post type such as `page`, or a
  taxonomy such as `category`. A linked post must exist and be readable by you. A custom url
  must be http, https, `mailto:`, `tel:` or a path on this site; `//host` is allowed and is a
  link to another host. `javascript:`, every other scheme and any url with a backslash are
  refused, where WordPress itself would quietly store an empty link or a different one.
- `update-menu-item {id, title?, url?, target?, classes?, parent_id?, position?}` changes
  what you send and leaves everything else as stored. A title or url sent back exactly as
  `get-menu` gave it keeps its stored bytes - even a parent that points at a deleted
  item, which a theme shows at the end of the menu. A parent you send must be an item of the
  same menu, and not the
  item itself or one inside it.
- `remove-menu-item {id}` deletes one - menu items have no trash. Its children move up one
  level into its place, as they do in wp-admin.

**Every write renumbers the menu** so its order runs 1, 2, 3 from top to bottom, depth first.
WordPress's own function stores the position it is given and moves nothing else, so two
items would end up claiming one place; wp-admin renumbers in the browser before it saves,
and these tools do it on the server.

Reading needs what WordPress's REST API needs - the capability to edit posts or theme
options - so an Editor can read menus and a Subscriber cannot. Writing needs
`edit_theme_options`: Administrators, not Editors. An item that links to something you may
not read, such as another user's draft or private page, is still listed, with its title,
url and object_id null and `withheld: true`. Draft items - in a menu but not shown to
visitors - are listed only to callers who can edit theme options, as in the REST API. An id that is not a menu, or not a menu item,
answers exactly like an id that is not there.

**A menu write is live at once.** Menus have no draft state: an item added to, changed in or
removed from a menu the theme has assigned to a location is what visitors see on their next
page load. Practise on a menu assigned to no location.

### Users, settings, plugins and themes

Five tools that only read, each drawing its line where WordPress's own REST API draws it.
None of them returns a password hash, an activation key, a session or any user meta.

- `list-users {role?, search?, limit?, page?}` - with `list_users` (Administrators), every
  user with id, name, login, email, roles and registered date (ISO 8601, site-local, like
  every list tool's dates), filterable by role and by a
  search: a term with `@` searches emails only, a number searches logins and ids, a term
  starting with `http://` or `https://` searches URLs only, and anything else searches login,
  URL, email, nicename and display name - WordPress's own rule. Without it - an Editor, Author or
  Contributor - only users who have published posts, as id and display name, and `role` or
  `search` is refused by name. A Subscriber is refused.
- `get-user {id}` - one user, with the same fields by the same rule, plus your own record in
  full. When you can neither list nor edit users, you see a user only if they have posts you
  may read - published posts, or private posts when you can read those - so an Editor also
  sees a user whose only posts are private, whom `list-users` does not show. That is the REST
  API's own rule. Anyone else answers exactly like an id that does not exist.
- `get-option {name}` reads one of ten settings the public site already shows: `blogname`,
  `blogdescription`, `timezone_string`, `gmt_offset`, `date_format`, `time_format`,
  `start_of_week`, `permalink_structure`, `siteurl` and `home`. Any other name - `admin_email`,
  this plugin's own options, one that does not exist - gets one identical refusal, so it cannot
  tell you what a site has installed. It needs permission to edit posts, which is wider than
  the REST API's settings endpoint (`manage_options`) on purpose: an Editor scheduling a post
  needs the timezone and the date formats.
- `list-plugins` (admin scope, `activate_plugins`) lists each plugin's file, name, version,
  whether it is active, whether it is network-active on a multisite network, and whether it is
  in the site's stored auto-update list. That is not always whether it will auto-update:
  automatic updates switched off for the whole site, a plugin that forces its own answer, and
  whether an update source exists are not reflected, because finding them out means running
  other plugins' code.
- `list-themes` (admin scope, `switch_themes`) lists each theme's stylesheet, name, version,
  parent, whether it is active and whether it is a block theme, and - for a caller who can
  edit theme options - the active theme's classic menu locations.

`name`, in both user tools, is the display name each user chose - on many sites their login
or an email address. WordPress's REST API shows the same field; the plugin shows whatever the
user set.

Neither `list-plugins` nor `list-themes` runs an update, auto-update, plugin-header, theme or
per-option filter - the filters Gravity Forms, LiteSpeed Cache and Rank Math make update and
licence requests from once their own caches expire. They read the plugin and theme files and
the stored settings directly, so neither triggers an update check. The hooks every tool call
runs still run, as on any page load: `init` and the other request hooks, the capability
filters, the database `query` filter, the option filters on this plugin's own settings, and the
`wpmcp_tools` filter. A plugin that goes remote from one of those does so during these calls
too, and that is outside these tools' control.

### Post meta (opt-in)

Off by default, and the third switch in the same **Settings > WP MCP** form - a textarea,
**Post meta keys**, one exact key name per line. While it is empty, `get-post-meta` and
`set-post-meta` are **not listed at all** and calling either by name answers exactly what
a tool nobody registered answers.

The list exists because post meta has no capability of its own that separates a subtitle
from a plugin's private state. WordPress offers no way to tell them apart, so this plugin
does not guess: you name the keys, and those are the only keys MCP can see. Keys WordPress
calls protected - anything starting with an underscore, such as `_thumbnail_id` or
`_edit_lock` - are dropped when you save and refused if called anyway, and so is any key
containing a backslash (the meta API strips one slash from every key it is given, so
`\_thumbnail_id` would arrive as `_thumbnail_id`).

`get-post-meta {id, key?}` returns `meta`, an object of key to value, holding every
allow-listed key that has a value on that post - or just the one key you name. A key
holding one row comes back as a value; a key holding N rows comes back as a list of N
values. Reading needs only what `get-post` needs, so a post a caller may not read answers
identically to a post that is not there.

`set-post-meta {id, key, value}` replaces the key: a scalar writes **one** row, a flat
list of N scalars writes **N separate rows**, `null` deletes it. Nothing else is accepted
- an object, a nested list, and an empty list or object are all errors, and `null` is the
only way to delete. It needs the capability to edit the post *and* WordPress's own
`edit_post_meta` for that key. WordPress stores meta as text, so a number or a boolean
comes back as its string form; a backslash survives unchanged.

**One row or N rows is a convention, and it is the one WordPress calls `single: false`.**
A field that expects a single *serialised* array instead - an ACF repeater, gallery or
flexible-content field, or any key a plugin registered with `single: true` and an array
type - cannot be written through this tool: a list would become N rows where that field
expects one.

A key that is not on the list is refused by name, and the refusal never mentions the keys
that are.

**ACF.** An Advanced Custom Fields value is an ordinary meta row under the field's name,
so naming the field here is what lets a token read and write it. ACF also keeps a second
row, `_<field name>`, holding the field key; these tools write only the value. Measured on
a site running ACF Pro:

- A field that has been set through ACF before - so the reference row exists - reads back
  through `get_field()` exactly as written, formatted by the field type.
- A field that has **never** had a value has no reference row, and `get_field()` then
  returns the raw stored string. For a text or URL field that is right. For an image or a
  relationship field the caller gets the id as a string instead of the shaped array ACF
  would normally build.

So: set a field once in wp-admin before handing it to an agent, or keep the tools to
simple field types. There is no ACF-specific code in this plugin, deliberately - it is one
vendor's convention, and a plugin that special-cased it would be wrong for the next one.

## HTTPS enforcement depends on your proxy

The endpoint refuses any request that is not over HTTPS with 403, before the token is
read. It decides with WordPress's `is_ssl()`, which reads what the web server told PHP,
which on a proxied deployment is a header. WordPress cannot tell whether that header came
from your proxy or from the client.

So your reverse proxy must set `X-Forwarded-Proto` itself, and must never pass the
client's value through. In nginx:

```nginx
proxy_set_header X-Forwarded-Proto $scheme;
```

A proxy, CDN or development stack that forwards the client's value makes this gate
advisory. A client can then POST over plain HTTP with `X-Forwarded-Proto: https` and be
accepted, with the token in cleartext. `composer test:infra` asks a running host whether
that is the case, and it is expected to fail on Local by Flywheel, whose router forwards
the client's header. That pair of tests is the only part of the suite that needs a host
with TLS, which is why it has its own command rather than living in the main run.

For a development site with no certificate, and nowhere else,
`define('WPMCP_ALLOW_INSECURE', true);` in `wp-config.php` turns the gate off.

## The trace log

An unexpected failure returns one generic JSON-RPC error, `-32603` with an eight-character
trace id - `Internal error (trace 1f53b972)`, with the same id in `error.data.trace_id` - and
nothing else. The class, message, file, line and stack go to a private log, so the trace id is
something you can quote to the operator and a client cannot read. The id is in the message
because a client that renders only `error.message` would otherwise show you a dead end.

The stack in that log carries each argument's SHAPE and never its value: an array's keys, a
string's length, an object's class. PHP's own formatter prints the first fifteen characters of
every string argument, which is enough to be somebody's data.

**The log is capped at 2 MiB, and it trims its OLDEST entries.** Before 1.1.1 it grew for ever;
two development sites reached 1.7 MB and 1.5 MB in eleven days and nothing rotated or aged any
of it out. When a write takes the file over the cap, the plugin keeps the newest three quarters
of it and discards the rest, cutting between entries rather than through one, and writes a line
at the top of the file saying so - so a file that is suddenly shorter is not a mystery:

```
2026-09-24T01:17:39+00:00 truncated=1 cap=2097152 removed=549120 kept=1572864
    wp-mcp trimmed this log, and this is not a corrupted file. The OLDEST entries were
    discarded so the file stays under its cap; ...
```

The newest entries are the ones that survive, deliberately: a trace id is quoted to you shortly
after it is issued, so a cap that discarded the newest would throw away exactly the id somebody
is asking about. A host that wants a different ceiling can raise it -
`add_filter('wpmcp_trace_log_max_bytes', fn() => 8 * MB_IN_BYTES);` - and a value below 64 KiB is
ignored rather than obeyed, leaving the 2 MiB default in place.

If the trim cannot finish - a full disk is the case that does it - the plugin does not pretend it
did: the whole entry goes to the PHP error log and the same admin notice as an unwritable directory
appears, so the trace id you were given still resolves to something.

The log lives at `wp-content/wpmcp/trace-<32 hex>.log`. The random name is generated once
per site and kept in an option, so the URL cannot be derived from anything a client sees.
Once a day the plugin fetches that URL itself, and if the web server answers `200` it
raises an error notice on every admin screen for anyone holding `manage_options`. The same
place warns you when the directory is not writable and traces are going to the PHP error
log instead.

The directory ships with an `index.php` and an `.htaccess`, which covers Apache. nginx
reads neither, so deny the directory in your server configuration:

```nginx
location ^~ /wp-content/wpmcp/ { deny all; }
```

## Code editing (opt-in)

Off by default. Switching it on in **Settings > WP MCP** gives an admin token six tools
that read and write files inside the active theme.

The switch is not the only thing that has to be true. If `DISALLOW_FILE_EDIT` or
`DISALLOW_FILE_MODS` is set in your `wp-config.php`, the six are **not listed at all**,
whatever the switch says - a tool that can never run is not advertised. Either constant
also turns the feature off for every token, including one minted before you set it. The
third gate is the token's user: they need `edit_themes`, and a token whose user does not
have it sees the tools listed (another token's user may) and is refused when it calls one.

The file API is fenced:

- Confined to the active theme directory. `..` traversal and symlinks pointing out are
  rejected on read, write and delete.
- **One file has one spelling.** Every path is resolved to its canonical form before
  anything acts on it, so `./inc/x.php`, `inc//x.php` and `inc/x.php` are the same file to
  the denylist, to the history and to the retention cap.
- A configurable denylist (default `functions.php`, `index.php`, `inc/`, `includes/`,
  `lib/`) is never read or written, under any spelling.
- Text extensions only, size-capped per write at 512 KB.
- **Every change is versioned into the database first.** Before `code-write` overwrites a
  file or `code-delete` removes one, the bytes that are there go into
  `{prefix}wpmcp_file_versions`. If they cannot be stored, the change does not happen.
- PHP is parse-checked after every write and reverted automatically on a syntax error, so
  a broken edit does not stick. The revert writes back the bytes that were just versioned.
- **The opcode cache is told, so the change is normally live when the tool returns.** Every
  write, revert, restore and delete of a `.php` file calls WordPress's own
  `wp_opcache_invalidate()` - after the write, and again after a revert, exactly where
  core's theme editor calls it. Without that, a host running
  `opcache.validate_timestamps=0`, or any `opcache.revalidate_freq`, keeps executing the file
  it compiled earlier: the tool reports the bytes it wrote and the site does not change until
  the next revalidation or a restart - for ever with timestamps off, and at the default
  `revalidate_freq` of 2, for up to two seconds. A delete invalidates just *before* removing
  the file, because PHP resolves the path on disk before it looks in the cache.

  On a host with no opcode cache there is nothing to tell and nothing happens - core's
  function says so by returning false, which is not an error.

  **Three cases where a cache that exists keeps running the old file anyway**, so a write can
  be on disk and not yet running. Check them first if a change does not take effect, and
  restart PHP - PHP-FPM, or whatever runs it on your host - to make it take effect now:

  - `opcache.restrict_api` is set to a path that does not cover the script serving
    `/wp-json/`, so WordPress never makes the call.
  - Something on the site returns false from the `wp_opcache_invalidate_file` filter,
    WordPress's own opt-out.
  - The PHP pool spans more than one node on a shared filesystem. Here the local cache IS
    told: the write lands for all of them, the invalidation only for the node that served the
    request, and the others keep their copy until their next revalidation or a restart - so
    restarting means every node, not only the one you called.

  **The plugin does not report any of this, and that is a choice rather than a limit.** For
  the first two cases it could tell - the state is readable in the same request - but a cache
  field in a result would read to an agent as "the change is not live", and would say that on
  every host that never had the problem. The cases above are the operator's to check, which is
  why they are written here and not returned. What a code tool tells you is what it did to the
  file.

### Versions, history and restore

`code-history {path}` lists what is stored for a file - newest first, with an id,
`saved_at`, `size`, `sha256`, the `reason` (`write`, `delete`, `restore` or `sweep`) and
the login of whoever caused it. A path nobody has changed returns an empty list, which is
not an error.

`code-restore {version_id}` writes one of them back. It resolves the stored path through
the same jail and denylist a caller's path goes through, versions the current contents
first (so a restore can itself be undone), applies the same parse check, and tells you
whether the bytes it wrote match the stored hash. A deleted file comes back this way.

Every version records **which theme it was taken from**. The code tools' jail is the
active theme, so `style.css` is a different file once you switch themes: `code-history`
lists only the active theme's versions, and `code-restore` refuses a version belonging to
another theme and names it. Switch back to that theme to restore it.

Twenty versions are kept per theme and path; storing a twenty-first drops the oldest.
Change that with the `wpmcp_file_versions_keep` filter. The table is dropped when the
plugin is deleted.

**Upgrading from 1.0.x.** Earlier versions backed a file up by writing a copy of it beside
the original inside the active theme. That copy is under your document root with an
extension nothing executes and nothing blocks, so its URL served the complete source of a
theme file to anybody who asked for it. The upgrade walks the active theme on the first
request after the plugin files change, moves those files into the versions table and
deletes them. It runs whether or not code editing is switched on, and running it twice does
nothing the second time.

It takes only what the code tools could give back: the original name (the one without the
backup extension) has to be a text extension this plugin writes, and the file has to be
inside the 512 KB cap. **Anything else is left exactly where it is** - including a backup
larger than the cap, and one that cannot be read. The upgrade writes a single line to your
PHP error log naming what it moved and what it left, so check it once after upgrading and
deal with anything still on disk yourself: those files are still being served.

One case is worth knowing about. A backup of `functions.php` **is** collected - `.php` is
a text extension - but `functions.php` itself is on the default denylist, so
`code-restore` will refuse to write it back. The bytes are in the table and the log line
gives the version id. Leaving the complete source of your theme's functions file readable
over HTTP is the worse of the two options.

What that fence does and does not cover is in [SECURITY.md](SECURITY.md). Read it before
enabling this: an admin token with code editing on can run PHP on your server.

## SQL reads (opt-in)

Off by default, and a separate switch in the same **Settings > WP MCP** form. Switching it
on gives an admin token one more tool, `sql-select`, which runs a single read-only SQL
statement and hands back the rows.

**It reads every table the WordPress database user can read, and - if your MySQL lets it -
files on the server too.** That is the whole point of it and it is the whole of the risk:
`wp_users` and its password hashes, every plugin's tables, every option including API keys
other plugins have stored there. See *The file-reading escape hatch* below for the second
half of that sentence. There is no
per-table permission to configure, because there is nothing this plugin can configure -
the connection it borrows is WordPress's own and it already has those privileges. Two
tables are refused by name (below); everything else the connection can see, the tool can
read. Do not switch this on for a token you would not hand a database password to.

Three things have to be true for a call to run: the switch is on, the token is
admin-scope, and the token's user holds `manage_options`. With the switch off the tool is
not listed and calling it by name is refused exactly the way a tool that does not exist is
refused - there is no answer that says "it is here but switched off".

### Why a derived table and READ ONLY, not a parser

The obvious implementation reads the statement, decides whether it is "really" a SELECT,
and runs it if so. That is a SQL parser written in PHP, and it has to be exactly as
correct as MySQL's grammar to be worth anything. Every one of them has been walked around
by a comment, a case, a whitespace or an encoding the parser and the server disagreed
about, and the failure is silent: the parser says SELECT and the server does something
else.

So nothing here inspects your SQL to decide whether it is safe. The statement is handed to
the database wrapped as a derived table and run inside a read-only transaction:

```sql
SET SESSION MAX_EXECUTION_TIME = 5000;      -- max_statement_time = 5 on MariaDB
SET SESSION optimizer_switch = 'derived_merge=off';
START TRANSACTION READ ONLY;
SELECT * FROM ( your statement ) AS wpmcp_q LIMIT 201;
ROLLBACK;
```

The wrapper is the first wall. A derived table has to be a query expression, so `UPDATE`,
`DELETE`, `SHOW`, a second statement after a semicolon, `INTO OUTFILE`, `INTO DUMPFILE`
and `INTO @var` are all **syntax errors from the server** - error 1064, decided by MySQL's
own parser rather than by a guess about it.

The transaction is the second wall, and it is not decoration: `SELECT ... FOR UPDATE`
parses perfectly happily inside a derived table (measured on MySQL 8.4), so the wrapper
does not stop it. `START TRANSACTION READ ONLY` does, with error 1792. The `ROLLBACK`
happens whatever the statement did, because WordPress reuses that connection for the rest
of the request.

What still works: joins, `UNION`, `GROUP BY`, subqueries, CTEs (`WITH ... SELECT`,
recursive ones included), and an inner `ORDER BY`. Two real limits come with the wrapper -
a derived table's columns must be **uniquely named**, so `SELECT p.ID, m.post_id AS ID`
needs a different alias, and `SHOW` / `DESCRIBE` are not query expressions, so use
`information_schema` instead.

### What comes back

```json
{ "columns": ["ID", "post_title"], "rows": [["12", "Hello"]], "row_count": 1,
  "truncated": false, "truncated_by": null }
```

`truncated_by` is `"rows"` or `"bytes"` when `truncated` is true and `null` when it is
not - always present, so a client can read it unconditionally. **Every value is a string,
as MySQL sends it**: `COUNT(*)` comes back as `"3"`, not `3`.

| Cap | Value | What happens |
|---|---|---|
| Rows | 200 | 201 are fetched; the 201st is why `truncated` is true |
| Bytes of rows | 256 KB | appending stops, `truncated_by: "bytes"` |
| One cell | 8 KB | cut and marked with an ellipsis |
| Time | 5 seconds | the server ends the statement (error 3024) |

`NULL` comes back as JSON `null`. A value that is not valid UTF-8 comes back as
`0x`-prefixed hex rather than as text: WordPress's JSON encoder does not fail on such a
value and does not null it either, it silently rewrites the offending byte as `?`, and a
blob that looks like text and is not the data is worse than no answer.

### The file-reading escape hatch, and what is yours to close

`LOAD_FILE()` reads a file off the **server's disk** and it passes both walls: it is a query
expression, so the wrapper takes it, and it is a read, so the transaction takes it too.
Whether bytes actually come back is then MySQL's decision, not this plugin's - it depends on
`secure_file_priv` and on whether your database user holds `FILE`.

So `load_file` is refused by name, the same blunt way the two tables below are. **That is one
function, not a boundary.** It is the only file-reading function reachable inside a `SELECT`
expression (`INTO OUTFILE` and `INTO DUMPFILE`, the write side of the same privilege, are
already syntax errors inside the wrapper), and refusing it closes the obvious route - but
the plugin cannot promise anything about a database server it does not configure.

If this matters to you, and it should if the site holds anything, set it at the server:

```ini
# my.cnf - disables LOAD_FILE, SELECT ... INTO OUTFILE and LOAD DATA INFILE outright
secure_file_priv = NULL
```

or give the WordPress database user no `FILE` privilege. Either is worth doing whether or
not you enable this tool: they are the only things that actually decide what MySQL will read
for whoever can reach it.

### The two tables it will not read

`{prefix}wpmcp_tokens` and `{prefix}wpmcp_file_versions` - the token hashes and the stored
theme-file bytes. This is the one rule the server cannot enforce, because the database user
owns those tables and there is no privilege the plugin can drop on its own connection. So
it is a name check, and it is deliberately blunt: **naming either table anywhere in the
statement refuses it**, including inside a comment or a string literal. `SELECT
'wpmcp_tokens' AS label` is harmless and is refused too. Over-refusing costs you an alias;
the alternative is a comment-and-string stripper that has to be exactly as correct as
MySQL's lexer, which is the parser this design exists to avoid.

Every call that runs is logged as a `sql_select` auth event with the caller, the row count
and the first 200 characters of the statement. A statement the server refused comes back as
an error carrying the MySQL error number and a trace id; the server's own message and the
whole statement go to the private trace log and nowhere else.

## Hooks

Seven. Five are stable surface from 1.0; `wpmcp_file_versions_keep` arrived with the code
tools' version store in 1.1, and `wpmcp_tool_call` with 1.1.1.

`wpmcp_tools` (filter) adds your own tools to the catalog. It runs on every request, after
the built-ins are assembled and before scope filtering. An entry must declare a boolean
`write`, a string `description`, an array `inputSchema`, all four boolean `annotations`,
and a callable `run`. An entry missing any of them is refused at registration rather than
given a default, and it cannot re-declare a built-in's name.

Two things about the description. A `description`, or any
`inputSchema.properties.*.description`, over 1,000 characters is refused: clients cap
these by truncating, so the end of a long one would silently never reach the model. And
keep the first sentence under 50 characters, because clients show only that until the tool
loads.

```php
add_filter('wpmcp_tools', function ($tools) {
    $tools['say-hello'] = array(
        'write'       => false,
        'description' => 'Returns a greeting.',
        'annotations' => array(
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ),
        'inputSchema' => array('type' => 'object', 'properties' => new stdClass()),
        'run'         => function ($args) { return array('message' => 'hello'); },
    );
    return $tools;
});
```

`wpmcp_allowed_origins` (filter) adds origins to the CSRF check. A request carrying a
browser `Origin` that is not one of the site's own is refused with 403; an absent
`Origin`, which is what non-browser clients send, is allowed.

```php
add_filter('wpmcp_allowed_origins', fn($o) => array_merge($o, ['https://claude.ai']));
```

`wpmcp_client_ip` (filter) supplies the real client IP, which is written to the auth
events and decides nothing. Behind a proxy `REMOTE_ADDR` is the proxy, so a log that is
worth reading needs this filter. Only trust a forwarded header from a proxy you control.

`wpmcp_auth_event` (action) reports what the wire deliberately does not: all five ways a
token can fail are one byte-identical 401, and the reason lives here. It receives the event
type and a context array, and every context carries `ip`.

There is no success event for the endpoint itself. A request that is accepted fires
nothing at all, so an audit listener that waits for an "ok" waits forever. One TOOL is
the exception - `sql-select` logs every statement it runs, because "somebody read the
database" is the event an operator wants. The twelve types:

| `$type` | Fired when | Context beyond `ip` |
|---|---|---|
| `mint` | a token was created | `token_id`, `user_id`, `created_by`, `scope`, `window`, `lifetime` |
| `revoke` | a token row was deleted | `token_id`, `user_id` |
| `renew` | a token's active window was restarted | `token_id`, `user_id`, `actor`, `window` |
| `validate_fail` | a token was refused | `reason`, sometimes `token_id` and `user_id` |
| `scope_deny` | a read token asked for a write tool | `token_id`, `user_id`, `tool`, `scope` |
| `origin_deny` | the `Origin` header was not one of ours | `origin` |
| `insecure_deny` | the request was not over HTTPS | nothing |
| `content_type_deny` | the POST was not `application/json` | `content_type` |
| `body_too_large` | `Content-Length` over the cap | `length` |
| `registry_reject` | a filter-added tool was refused at registration | `tool`, `reason` |
| `stale_backup_sweep` | a schema upgrade swept the active theme and found backup files an older version had left there | `found`, `moved`, `skipped_extension`, `skipped_unreadable`, `skipped_too_big`, `skipped_undeletable`, `moved_paths`, `skipped_paths` |
| `sql_select` | `sql-select` ran a statement | `token_id`, `user_id`, `row_count`, `truncated`, `elapsed_ms`, `sql` |

`sql` is the **first 200 characters** of the statement and never more. The whole of it
can carry a value out of the database into a log that is not the private trace log; the
full statement goes to the trace log on the one path where it is worth having, which is
a statement the server refused.

`stale_backup_sweep` fires on the request that performs a schema upgrade, and only when
the sweep found something - so it is usually once, on the upgrade to 1.1, but any later
schema bump that finds a backup file in the theme fires it again.

`moved_paths` names each collected file with the version id it became and
`skipped_paths` names each one left on disk with the reason; both are capped at 25 entries
with an `and N more` tail, because the rest is in the table and this is one log line.

`reason` on `validate_fail` is one of `missing`, `malformed`, `not_found`, `user_missing`,
`dormant`, `expired`. The last two are the same `401` on the wire and different advice to
the operator: `dormant` means press Renew, `expired` means mint. A context never contains a
token or its hash.

```php
add_action('wpmcp_auth_event', function ($type, $context) {
    if ($type === 'validate_fail') { /* $context['reason'], $context['ip'] */ }
}, 10, 2);
```

`wpmcp_auth_event_redacted_keys` (filter) adds key names to redact from that context
array. Tokens, hashes and authorization headers are redacted already, at every depth.

`wpmcp_tool_call` (action) reports what a token is DOING, once per `tools/call` - a refusal
and a crash included, because an operator asking this question is usually asking because
something is not working. It receives the tool name, a boolean `ok` (false for any refusal,
tool error or crash) and a context array:

| Key | |
|---|---|
| `arg_keys` | the argument NAMES the call carried. Never the values: a listener is an ordinary plugin callback, and the values are somebody's content |
| `token_id` | the token row id, 0 when there is no session |
| `user_id` | the WordPress user the call ran as |
| `scope` | `read` or `admin` |
| `duration_ms` | wall-clock milliseconds, one decimal place |

There is no built-in log for this, deliberately: a log would mean this plugin choosing a
location, a rotation policy, a retention period and a disclosure rule for every site. Write the
four lines that suit yours, or write nothing and pay one empty hook call.

```php
add_action('wpmcp_tool_call', function ($tool, $ok, $context) {
    error_log(sprintf('wpmcp %s %s user=%d %.1fms', $tool, $ok ? 'ok' : 'FAILED',
        $context['user_id'], $context['duration_ms']));
}, 10, 3);
```

`wpmcp_file_versions_keep` (filter) sets how many versions of one theme file the code
tools keep. The default is 20, pruned oldest-first on insert. A value that is not a
positive number is ignored rather than obeyed: "keep nothing" makes every write
unrecoverable and is far more likely to be a mistake than a decision.

```php
add_filter('wpmcp_file_versions_keep', fn() => 50);
```

## Testing

Dev dependencies only; the plugin itself is plain PHP with nothing vendored. `composer
install`, then:

| Command | What it runs | What it needs |
|---|---|---|
| `composer test` | Both tiers below. | Nothing, though integration self-skips without a site. |
| `composer test:unit` | Pure PHP: framing, the schema validator, serialization, version negotiation, cursors, the version invariant. | PHP 8.1+. Seconds. |
| `composer test:integration` | Black-box HTTP against a real site: real users, real roles, real capability checks, real TLS, the real credential header. | `WPMCP_TEST_URL`, and `wp` on PATH to seed fixtures. Minutes. |
| `composer test:infra` | Two tests that only a host with real TLS can answer: that a valid token over plain HTTP is refused, and that a forwarded `X-Forwarded-Proto` cannot talk its way past that refusal. | An `https://` host at `WPMCP_TEST_URL`. Fails rather than skips on a host without TLS. Out of `composer test` and out of CI. |
| `composer test:client` | A real MCP client (the Claude Code CLI) handshakes with your site, lists its tools, calls one, and the answer is checked against your database. | `claude` on PATH, and one Claude API call. Out of `composer test` and out of CI. |

`test:client` is the only test here that is not our own client asserting our own beliefs.
It sends what a real client actually sends, and the value it asserts is read straight from
your database, so the model had no other way to know it. Run it by hand when the
handshake, the transport or the tool registry changes. See
[docs/CONNECT-CLIENTS.md](docs/CONNECT-CLIENTS.md).

## Reference

- [ARCHITECTURE.md](ARCHITECTURE.md) for the request lifecycle and the gate order.
- [SECURITY.md](SECURITY.md) for the threat model and the known limits.
- [docs/CONFORMANCE.md](docs/CONFORMANCE.md) for the MCP revision, what is served, and
  what is deliberately not.
- [docs/CONNECT-CLIENTS.md](docs/CONNECT-CLIENTS.md) for connecting a real client.
- [docs/RELEASE.md](docs/RELEASE.md) for cutting a release.
- [CHANGELOG.md](CHANGELOG.md).

## Support

WP MCP is free and GPL-licensed, so use it however you like. If you are putting it to use
and you want to, you can [sponsor me on GitHub](https://github.com/sponsors/mkonstan) and
buy me a coffee. Entirely optional; the plugin is and stays free.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).

## Credits

Built by Max Konstantinovski, with Claude. Designed, implemented, reviewed and hardened
collaboratively with AI. See [BUILD-NOTES.md](BUILD-NOTES.md).
