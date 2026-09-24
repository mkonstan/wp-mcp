# Changelog

All notable changes to WP MCP. From 1.0.0 on, the version is semantic.

## 1.1.2

**Unreleased.** The private trace log stops being a file. Still planned for this cycle: the tool
surface split out of `tools.php` behind a declared seam.

### Changed: the trace log is a table, and the file is deleted

- **BEFORE YOU UPDATE, IF YOU HAVE A LIVE SUPPORT CASE: take a copy of
  `wp-content/wpmcp/trace-*.log`.** The upgrade DELETES that file, its directory, its two guard
  files, three options and a transient, and it does NOT copy the old entries into the new table.
  A trace id issued before the update stops resolving. This is deliberate twice over: the file is
  the exposure the change exists to remove, so leaving it would make the fix cosmetic, and
  importing a year of unswept entries would carry that whole history into every database backup
  you ever take from then on.
- **Traced failures now go to a new table, `{prefix}wpmcp_traces`, instead of
  `wp-content/wpmcp/trace-<32 hex>.log`.** The reason is the web server: the log sat behind an
  `.htaccess`, and `.htaccess` is an APACHE file. nginx has no per-directory configuration and
  never reads it - MEASURED on the development host, `GET /wp-content/wpmcp/trace.log` answered
  `200` with 14 KB of absolute paths, the OS username, the plugin inventory, tool names, user ids
  and every stack frame, to anybody, with no token. The random file name hid that URL; it did not
  remove it. **No web server can serve a table.**
- **Both trace-log admin notices are gone**, and an operator will notice: the red "the trace log
  is readable from the web" and the amber "could not check whether the trace log is readable"
  both described a file that no longer exists. So did the daily outbound HTTP request the plugin
  made to fetch its own log on every admin page load. A third notice, "the trace log could not be
  written", is also gone; an INSERT that fails still sends the whole entry to the PHP error log,
  now prefixed `wp-mcp trace (could not be stored)`.
- **`wp-content/wpmcp/` is removed entirely** - the log, the empty `index.php`, the `.htaccess`
  and the directory. Nothing in the plugin writes outside the database any more except the theme
  files the code tools are asked to edit.
- **Traces are kept 7 days and at most 2,000 of them**, swept on the hourly `wpmcp_flush_expired`
  event that already clears dead tokens, oldest first. The file was never swept at all: two
  development sites reached 1.7 MB and 1.5 MB in eleven days, and on a customer host nothing ever
  came along to clean it up. Retention is now days rather than for ever for a cost the file did
  not have - a row rides in every database backup, export and staging clone. Both numbers are
  filterable, `wpmcp_trace_keep_days` and `wpmcp_trace_keep_rows`, and a value under 1 day or
  100 rows is ignored rather than obeyed.
- **The sweep deletes in batches of 500 - 20 batches for the age cap, 200 for the row cap.** A
  site whose cron has not fired for a month would otherwise delete a month of rows in one
  statement: one transaction, with a `longtext` per row in the undo log, inside an ordinary page
  load. The row cap gets ten times the rounds because it is a PRIMARY KEY range delete, the cheap
  shape, and because it is the pass the size ceiling below depends on. A healthy site runs exactly
  one statement per pass - the loop stops the moment a batch comes back short.
- **Every field of a trace is capped in bytes against its own column, and the stack is capped at
  8 KiB as well as at 200 frames** - so what the table can cost a backup is a MAXIMUM and not an
  average. One row is at most 12,960 bytes (`method` 64, `tool` 191, `class` 191, `at` 255,
  `message` and `data` 2,000 each, `stack` 8,192), which puts **2,000 rows under 26 MB of column
  data**. A cap on the NUMBER of rows is not a cap on their SIZE unless the row is bounded too:
  with the stack bounded only in frames, the runaway recursion a frame cap exists for wrote
  40-400 KB rows, and 2,000 of those is not 4.6 MB.
- **And column data is not what a disk carries, so both figures are stated - MEASURED**, by
  planting 500 rows at exactly those caps on MySQL 8.4 with InnoDB `ROW_FORMAT=Dynamic`: **about
  48 MB of tablespace** (23,888 bytes a row - an 8 KiB stack does not fit in half a 16 KB page, so
  it goes off-page into a page of its own) and **about 27 MB in a `mysqldump`** (13,502 bytes a
  row; escaping costs 4.2%). The tablespace figure is a HIGH-WATER MARK: deleting rows frees them
  for reuse but does not return the space to the filesystem - measured, the file stayed at 12 MB
  after the rows went and only `OPTIMIZE TABLE` shrank it. In practice all of it is far smaller -
  the measured mean entry is 2,283 bytes, so seven days at a development site's 69 failures a day
  is 486 rows, about 1.1 MB.
- **And the ceiling has a stated CONDITION, which the first version of it did not:** the sweep
  removes at most 100,000 rows an hour, so the cap holds up to about **27 traced failures a second
  sustained**. Above that more arrive than leave and the table grows until the rate drops. An AI
  client retry-looping against a throwing tool at ~350 ms a call is about 2.8 a second, so the
  headroom is roughly tenfold.
- **An upgrade that could NOT remove the old log raises an error notice on every admin screen**,
  naming what is left. On nginx that file is still being served, which is the whole reason the
  log moved, so an upgrade that did not manage it must not look like one that did. Deleting the
  path by hand and reactivating the plugin clears it.
- **A symlinked `wp-content/wpmcp` is reported and not followed.** `glob()` and `unlink()` follow
  a link, so the upgrade would delete files somewhere the plugin has never written - a volume
  mount, a shared directory, a backup target - and removing the link would leave every exposed
  byte where it is while reporting success.
- **A new auth event, `trace_file_removed`,** fires once on the upgrade request with what it
  deleted and what it could not - the latter being the sites where the file is still readable.
- **`sql-select` refuses the new table by name**, exactly as it already refuses the token and
  file-version tables, anywhere in the statement, comments and string literals included. A trace
  row holds the class, the message, the absolute file:line, the `WP_Error` data (which is where
  wpdb puts a failing query) and the whole stack - precisely what the error boundary hands a
  caller eight hex digits INSTEAD of. While the traces were a file no token could read them at
  all; this denial is what replaces the filesystem as the wall.
- **New on the settings screen: Look up a trace id.** Paste the eight hex digits a client was
  given and see that one entry, `manage_options` only. It exists because the change would
  otherwise have made diagnosis harder for exactly the person the id is for: while the traces
  were a file, an operator opened the file. It is deliberately not a log browser - no list, no
  search, no pagination - because what it prints is the detail the API is refused.
- **A stored stack is bounded at 200 frames**, the middle dropped with a line saying how many.
  That is the one value the file's 2 MiB cap used to bound and a column does not: a runaway
  recursion could otherwise make one row a megabyte, 2,000 times over, in every backup.
- **Deleting the plugin now drops three tables**, not two.
- **Gone with the file, for anybody who was relying on them:** the `wpmcp_trace_log_max_bytes`
  filter, the `wpmcp_trace_log_name`, `wpmcp_trace_log_readable` and `wpmcp_trace_log_unwritable`
  options, and the `wpmcp_trace_checked` transient. The upgrade deletes the three options and the
  transient for you.

## 1.1.1

**Released 2026-09-24.** The platform-swap release: less code of ours doing what WordPress
already does, a failure that says what failed, and the WordPress floor moved to where the
ecosystem is. The private trace log is bounded for the first time, and CI that took an hour and
three quarters now takes twenty-four minutes.

### Fixed: a failure now says what failed

- **`upload-media` reports the remote server's HTTP STATUS instead of "Internal error".**
  Measured: a Wikimedia thumbnail URL answered a bare `Internal error` while the private log
  held `class=WP_Error:http_404 message=Bad Request data={"code":400,...}` - the CDN had
  refused WordPress's user agent. Core's `download_url()` turns EVERY non-2xx into
  `WP_Error('http_404', ...)` whatever the status was, and that code is not relayable, so the
  boundary correctly hid a failure that was not the site's fault and that no agent could act
  on. The status and the reason phrase now come back - `The server at source_url answered HTTP
  403 Forbidden instead of the file.` - and the response body NEVER does. Core attaches up to a
  kilobyte of it; in the measured case it was a whole HTML error page.
- **The trace id is in the error MESSAGE, not only in `error.data`.** A cold client rendered
  `error.message` and nothing else, so the one identifier that could find the log line was
  invisible. Every generic failure now reads `Internal error (trace 1f53b972)`, and the same id
  stays in `data.trace_id` for anything already parsing it.
- **A fetch that never CONNECTED is now generic**, where it used to relay the transport's own
  sentence. `http_request_failed` came off the relayable list because cURL's message names the
  host it could not reach, which on a proxied site is the operator's internal
  `WP_PROXY_HOST`. The sentence is in the private log; the caller gets the trace id.
- **`upload-media`'s description** says the remote server has to be willing to serve the file
  to THIS site - a URL that opens in your browser may still be refused - and what the two
  failures look like.
- **The trace log records argument SHAPES, never argument values.** PHP's own
  `getTraceAsString()` prints the first fifteen characters of every string argument (verified
  on PHP 8.2.29 with `zend.exception_ignore_args=0`), which is the start of a URL, a title or
  anything else a caller sent. A frame now reads
  `{closure}(array{source_url,filename}, string(41), stdClass)`: an array's keys, a string's
  length, an object's class.

### Changed: the trace log stops growing for ever

**You will notice this on a site that has been running a while: the file gets shorter.** That is
the plugin trimming it, and it says so in the file.

- **The private trace log is capped at 2 MiB**, and it discards its OLDEST entries to stay there.
  Until now it only grew: measured on two development sites, 1,743,937 bytes over 763 entries and
  1,504,358 over 660, written in eleven days, with nothing rotating, truncating or ageing any of it
  out. On a customer host nothing ever comes along to clean it up.
- **The NEWEST entries are the ones that survive**, and that is the whole design rather than a
  detail. A failure hands the caller a trace id and tells it to quote that id to you, so a cap
  that discarded the newest entries would throw away exactly the id somebody is about to ask about
  - which is worse than no cap at all. A test pushes the log past the cap, breaks something over
  HTTP, and looks the brand-new id up in the file.
- **A trimmed file announces itself on its first line** -
  `truncated=1 cap=2097152 removed=549120 kept=1572864`, with a sentence indented under it - and
  the cut is made BETWEEN entries, never through one. So a shorter file does not read as a
  corrupted one, no entry begins part-way through, and the marker carries no `trace=` field, so
  grepping for an id can never return it.
- **2 MiB is about 900 traced failures** at the measured mean entry of 2,283 bytes, and it is just
  above both measured files - so installing 1.1.1 does not by itself throw away the log you have.
  A busy host can raise the ceiling with
  `add_filter('wpmcp_trace_log_max_bytes', fn() => 8 * MB_IN_BYTES)`; a value below 64 KiB is
  ignored, because a cap smaller than one entry would truncate the entry it had just written.
- **Enforcing it costs one `fstat()` per traced failure**, on the descriptor the write already has
  open, and traces are only written when something has already broken. The rewrite itself happens
  once per quarter-cap of new log - roughly every 230 failures - not on every write.
- **The trim will not discard an entry it has not read, and will not call a short write a finished
  one.** Two ways it could otherwise have lost the newest entry - the one the trace id names. `flock`
  succeeds and protects nothing on NFS and some shared hosting, so an entry appended while the trim
  was running could have been thrown away with its id already quoted to a caller; the trim now
  re-checks the file's size immediately before cutting and ABSORBS what arrived instead. And `fwrite`
  returns a short count on a full disk, which used to read as success and leave the file ending
  part-way through that same entry; it is now retried, and a rewrite that genuinely cannot finish
  sends the whole entry to the PHP error log and raises the operator notice rather than pretending.
- **A filtered cap below 64 KiB falls back to the 2 MiB default rather than being clamped**, which is
  what the README already said and what the constant is now named for.

### Changed: titles are stored the way wp-admin stores them

- **`create-post` and `update-post` no longer strip tags from a title.** A title typed
  `x<y z` used to be stored as `x` - text destroyed, silently, by us. Neither wp-admin nor the
  REST API does that. Core's `title_save_pre` decides instead: only `trim` for a user with
  `unfiltered_html`, and kses for one without, which ENCODES rather than strips.
- **So the same title stores different bytes depending on the token's user**, and that is the
  contract, not a defect: an administrator's `x<y z` is stored `x<y z`; an author's is stored
  `x&lt;y z`, exactly as wp-admin would store it. `Tom's "quoted" A\B` is stored byte for byte
  for both. `changed` can therefore report a title as changed when an administrator re-saves a
  subscriber's post - wp-admin does the same.
- **Reading a title and writing it back still stores the same bytes**, for both roles, because
  a field equal to what is stored is not written at all.
- The whole rule, with its measurements, is now written down in `ARCHITECTURE.md` under **The
  tool layer is the browser and the form**.

### Changed: less of our code, more of the platform's

Five swaps and a deletion. None changes a capability; each retires something we maintained.

- **`list-posts` can now return a plugin's CUSTOM post statuses.** The five core statuses were
  written out in our code; the status registry and the `public` / `private` / `protected` flags
  decide now - the same flags `WP_Query` itself consults. A workflow plugin's `archived` or
  `expired` is listable, scoped by the same capabilities: public to everybody, protected to a
  caller with `edit_others_posts` (and to the author for their own posts), private to one with
  `read_private_posts`, and a status with none of the three to nobody, which is core's own
  answer. On the ACF-heavy test site this adds exactly one status, ACF's own `acf-disabled`.
- **A `date` naming a local time that does not exist is stored as typed.** Date parsing is now
  core's `rest_get_date_with_gmt()` behind our grammar (which accepts `2026-03-04` and
  `T09:30`, both of which core's refuses) and our guard (core's parser ends in `strtotime()`
  and rolls 30 February forward). 95 old-against-new comparisons across five timezones differ
  in exactly one case: `2026-03-08T02:30:00` on a site in America/New_York, the hour that
  spring-forward skips, is now stored as 02:30 local with the correct GMT instant - which is
  what the REST API stores - instead of being moved to 03:30.
- **The theme-code tools now respect a hardening plugin.** The `DISALLOW_FILE_MODS` half of the
  gate asks `wp_is_file_mod_allowed()`, which is what core asks for `edit_themes`, so a plugin
  that switches file editing off through the `file_mod_allowed` filter switches the six code
  tools out of `tools/list` as well. Before this they were advertised and then refused, one
  call at a time. `DISALLOW_FILE_EDIT` stays a direct constant read, because core's has no
  filter in front of it either.
- **Settings are written through the Settings API.** Nothing changes for an operator beyond a
  post-redirect-get and core's own "Settings saved." - but the sanitiser has moved to where
  `update_option()` runs it on EVERY path, so a protected meta key typed into the allow-list is
  dropped whether it arrives from the form, from `wp option update`, or from a restored backup
  being re-saved.
- **The JSON body is decoded once**, by core, instead of twice. Nothing observable; the batch
  refusal still reads the raw first byte, because `[]` and `{}` decode to the same value.
- **`get-media`'s `url` is `null` when the attachment has no file**, where it used to be the
  JSON literal `false`. Found by declaring the field's type (see below).

### Changed: an orphaned menu item moves to where WordPress shows it

- **The menu tree is core's `Walker::walk()` now**, with a small collecting subclass fed our own
  raw rows. Our traversal, our parent map and our orphan handling are gone.
- **The one observable difference: an item whose stored parent is not an item of its menu** -
  because the parent was deleted, or is in another menu - used to appear interleaved at the top
  level by `menu_order`, and now appears AFTER every top-level tree, flat, which is where
  `wp_nav_menu()` shows it. So `get-menu`'s `position` finally agrees with what a visitor sees.
  Such an item's own children are shown flat beside it rather than nested under it, which is
  also core's behaviour.
- **The next write to that menu persists the new order**, because the renumbering is built from
  the same walk. ZERO orphaned items were measured on both real test sites (81 items in 5 menus,
  and 44 items), so nothing changes there - but if your menus have one, its `menu_order` will
  move the first time anything is added, moved or removed.
- **`get-menu` primes every linked post and term in two queries** (`update_menu_item_cache()`)
  before reading them one at a time. On a 66-item menu that is 66 pairs of queries that no
  longer happen. No value changes: it is the cache, not the read.

### Added: an operator can see what a token is doing

- **The settings screen names the connected CLIENT.** Every MCP client sends its name and
  version in the first message of every connection, and those two strings are now stored on the
  token row beside `last_used_at` and `use_count`, so a row reads "Claude Desktop 1.4, last seen
  3 minutes ago, 412 calls". The server keeps no session state; this is the visibility a session
  feature would have bought. Token-table schema revision 6.
- **`do_action('wpmcp_tool_call', $tool, $ok, $context)` fires once per tool call**, including
  for a refusal and for a crash, so a site can observe usage without this plugin inventing a log
  format, a location, a rotation policy or a retention rule. `$context` carries the argument
  KEYS - never values - the token row id, the user id, the scope and the duration in
  milliseconds.

### Added: `outputSchema` on four read tools (a pilot)

- **`site-info`, `get-post`, `get-media` and `get-user` declare an `outputSchema` and send
  `structuredContent`** beside the text block, which the specification requires to stay. Four
  tools and not thirty-six, because a tool that has one sends its data twice and a client cannot
  tell us whether it wants the second copy.
- **Each of the four declares its fields once** - name, type, description, and the closure that
  produces the value - and both the schema and the result are generated from that declaration,
  so they cannot drift. `structuredContent` is decoded from the text block, so the two wire
  copies cannot drift either.
- **The field lists moved out of those four descriptions into their schemas**, a sentence per
  field, which gives the 1,000 characters a client keeps back to the warnings that need them.

### Changed: the declared WordPress floor is 6.9, and CI executes it

- **`Requires at least` is now `6.9`.** The floor is a CHOICE now rather than a derivation. 5.5,
  then 6.4, were each the oldest version the code would run on - 6.4 because
  `_wp_put_post_revision`'s `$post_id` argument is `@since 6.4.0` and `restore-revision` filters
  on it. That is still the oldest WordPress the code would run on, and it is no longer the floor.
- **6.9 is where the Abilities API begins**, and that is where the ecosystem has gone: core
  registers three abilities, Rank Math 24, Gravity Forms 32 behind a flag, and ACF Pro 6.8.10
  ships its own for field groups, post types, taxonomies and per-post-type CRUD. Supporting
  below it bought a version question on every future feature, and a 91-minute CI job, to serve
  sites unlikely to run an agent at all. W3Techs, 23 September 2026: 62.6% of WordPress sites run
  7.x and 30.4% the whole of 6.x, so the floor keeps the overwhelming majority and drops versions
  that are updating themselves out of existence.
- **`Requires at least` is a GATE, not a hint**, which is why the header moves rather than the
  README explaining itself: core's `validate_plugin_requirements()` refuses to ACTIVATE a plugin
  below the version it declares. A header of 6.9 with prose promising "6.4 works" would be false
  for exactly the sites it was addressed to.
- **CI runs the whole integration suite on WordPress 6.9 with PHP 8.4**, beside the leg on
  current WordPress, and a red floor blocks a release. The PHP was re-derived rather than carried
  over: 6.9 shipped on 2 December 2025, twelve days after PHP 8.5, so the leg runs the newest PHP
  that was in active support when that WordPress was released - the same rule that put the old
  6.4 leg on 8.2 rather than 8.3. The job asserts the container really came up on that WordPress
  and that PHP, because a `WP_ENV_CORE` wp-env quietly ignored would leave the leg testing
  current core twice.
- **The README opens with a requirements table and the version each feature needs.** One row
  today, because everything documented works at the floor.

### Fixed: a test that had to win a race with cron

- **`TokenLifecycleTest` no longer loses its own fixture to the plugin's hourly sweep.** On
  run 35669745657 a dead token's row was deleted between the request that was supposed to be
  refused and the assertion that read the row back. A dead row is precisely what the sweep
  deletes, so no fixture shape avoids the race: the five test classes that need a dead row to
  survive now take the sweep off the schedule for their duration and put it back afterwards,
  and the dead-token test FIRES the sweep at the worst possible moment on every run, so the
  hold-off is proved rather than hoped for. No plugin code changed.

### Changed: how this repository tests itself

None of this is visible on a site. It is recorded because it changes what a green tick means.

- **One integration run, not two.** CI ran the suite and then re-ran PHPUnit once per closed
  sprint group, to prove no gate had silently skipped - 1 h 35 m 23 s followed by 1 h 38 m 28 s
  of re-executing the same tests in the same container (run 35514259397). The same three
  numbers per group are now read out of the one run's JUnit log. PHPUnit 10.5 refuses to
  combine `--group` with `--list-tests`, so the group map comes from `--list-tests-xml`,
  which is the only form that carries `groups=`.
- **The gate-group list is one file**, `.github/sprint-gate-groups.txt`, read by both
  workflows. It used to be written out twice and kept in step by a comment.
- **A commit that ships byte-identical PHP is not re-tested.** `bin/code-fingerprint.sh`
  hashes the git blob ids of exactly the files that go into the zip, and beside it the files
  that decide what the tests are and where they run. A green run files its verdict under that
  fingerprint; a later commit with the same fingerprint skips the WordPress tiers and PRINTS
  which run it is reusing. The lint job and the unit tier always run, documentation included,
  because `VersionConsistencyTest` ties this changelog's top heading to the version in the
  code. A weekly scheduled run re-proves everything, because a matching fingerprint says the
  code is identical and says nothing about WordPress or the container.
- **The integration tier runs on eight machines, not one.** Eight containers, each running a
  disjoint set of test classes balanced by measured cost, each writing its own JUnit log, and a
  merge job assembling them into the one log the gate reads. About seventy minutes becomes about
  fifteen; total test time goes UP by roughly 30%, because each shard builds its own fixtures and
  none shares a warm database. Free on a public repository and not worth it on a private one. The
  merge refuses a missing, truncated, empty or duplicated shard rather than reporting a smaller
  suite as a complete one, and it found a real defect on its first run: one test depended on how
  much other work had populated the database before it.
- **The WordPress-6.9 floor leg is sharded too, six ways.** It was the one unsharded leg and
  therefore the one that decided the whole run's wall clock - 5734 s of suite time inside a
  1 h 44 m job. Two numbers from run 35886384855 settled why: WP 6.9 on PHP 8.4 costs only +4.7%
  over the old 6.4 baseline (5734 s against 5478 s), so the VERSION is not the cost; and the eight
  current-core shards spent 7220 s of machine time doing what this leg did in 5751 s on one
  machine. It was slow only because nobody had sharded it. Measured after: the leg now takes
  **1313 s** end to end for 6880 s of machine time - 4.8 times off the wall clock for 10% more
  work, and the whole CI run finishes in 22 minutes. Same planner, same merge, same `needs:` without `always()` so a shard that
  DIES reds the run instead of vanishing - six rather than eight only because both tiers now shard
  at once and GitHub runs twenty jobs concurrently. The earlier plan (move the floor to
  releases-only) was dropped: it rested on believing 6.x was inherently slow, and it is not.
- **A release reuses that verdict instead of re-running the gate.** Publishing still requires
  a green full run for the code being published; what changed is that the run may be the one
  CI already did. A one-word changelog commit used to get a 90-minute release gate.

## 1.1.0

**Released 2026-09-21.** The auth surface, rebuilt around what hosted MCP clients actually do.
Three breaking changes, all in how a token is presented and how long it lives. The protocol
negotiation and the wire format are untouched. The tools grew, and some of their results
changed shape so that what they return is true and can be written back: every such change
is marked **(shape)** under the first heading below.

Upgrading is one database migration (schema revision 3) that runs on the first request
after the plugin files change. Existing tokens keep answering until exactly the moment
they always would have; at that moment they go **dormant** instead of vanishing, and an
admin can renew them for thirty days from when they were minted - see below.

### Changed: every sentence a tool tells a client is true, and every value it returns can be written back

Found by three cold clients and two live runs reading the tools on real sites, and measured
on both test sites before anything was changed. **Shape changes a client can notice are
marked (shape).**

**Writing back what was read**

- **`update-post` does not write a field sent with the value it already has**, and an update
  that changes nothing writes nothing - no revision, no new modified date, no re-dated draft.
  Measured before: a title wp-admin stored as `x<y z` was stripped to `x` when written back;
  a draft nobody dated became a dated one when its `date` was written back; an identical
  title created a revision. An update that changes anything - terms or the featured image
  alone included - still goes through a real save, so `modified` moves and `save_post` fires.
  A save WordPress refuses leaves nothing half-written: the columns are saved first, and
  terms and the featured image are applied only after that save succeeds. Every field of
  the result, `link` included, is read after the last write.
- **`update-post`'s `terms`: an empty list clears that taxonomy** (it was silently a no-op).
  A `post` left with no category is given the default category by WordPress's own save.
- **`changed` is now what differs, not what was sent (shape).** It compares the row before
  and after the write, so an identical title is no longer reported, and whatever core moved
  on its own - a re-dated draft, a re-slug, a default category - is. On `create-post`
  `changed` still lists the fields the call set.
- **`update-post`'s `terms` takes `get-post`'s own `{id, name, slug}` entries.** Sending them
  back used to create a category named `Array` and assign it. Any other non-scalar is refused
  by name in `terms_refused`.
- **An unchanged `author` or `featured_image` needs no capability.** An Author writing back
  their own post's author id was refused.
- **Term names come back as typed (shape):** `list-terms`, `get-post`'s `terms`, `create-term`,
  and the menu `name` in `list-menus` and `get-menu` decode `&amp;`, `&lt;`, `&gt;` and their
  numeric forms, which core adds on save. `Arts &amp; Crafts` is now `Arts & Crafts`; sending
  it back names the same term and stores the same bytes.
- **Menu labels come back as typed (shape):** `get-menu` decodes the same set in an item's own
  label, so a label wp-admin stored as `FDA &#038; GMP` reads `FDA & GMP`, and
  `update-menu-item` keeps the stored bytes when that is sent back. A category item with no label of its own shows
  the term's name decoded too, and sending that back leaves the item label-less. A url sent back as read is
  also kept rather than re-validated.
- **`create-term` refuses a name the taxonomy already has, naming the existing term's id.**
  Core's own refusal used to reach the client as an opaque `-32603`, and a name holding a
  backslash was not refused at all - core's duplicate check strips the backslash - so it was
  created twice.
- **`moderate-comment` accepts `approved` and `unapproved`**, the words `list-comments`
  returns, and `list-comments`' `status` filter accepts them too. Moderating a comment into the
  state it is already in answers with that state instead of "Action failed."
- **`set-post-meta` refuses a list sent to a key holding ONE serialised array**, which
  `get-post-meta` returns as a list; writing it back used to split one array into one row per
  element.

**One envelope, one date format, and paging that ends**

- **`has_more` is false at page 100 on every paged tool (shape).** `page` was clamped to 100
  and a page past it answered page 100's rows with `has_more: true`, so an agent paging to
  the end looped for ever (found on `list-users`). Every description names the cap.
- **`list-terms` pages (shape):** `limit` (default 20, max 100) and `page`, and the result is
  `count, page, limit, has_more, items`. It was every term of the taxonomy under `terms`.
- **`list-media` and `list-comments` answer in the same envelope (shape):** `page`, `limit`
  and `has_more` are new; `limit` replaces `per_page`, which is still accepted.
- **Dates (shape):** `list-media.date` is `post_date` in ISO 8601 site-local (it was the raw
  `post_date_gmt`, `2026-08-27 02:17:03`) and `modified` is new; `list-comments.date` is
  `comment_date` in the same form (it was the raw GMT column); `list-users.registered` and
  `get-user.registered` are ISO 8601 site-local with no offset (they were UTC with
  `+00:00`); `code-history.saved_at` is ISO 8601 site-local (it was the raw UTC column).

**Descriptions and results that said something untrue**

- **`update-post`**: "no text change, no revision" was false - the first write that CHANGES
  something on a post with no revisions leaves one whatever it changed (WordPress's own
  `post_updated` handler saves it). The description now says so. A write that changes nothing
  is not that first write: it writes nothing at all, so it leaves no revision either. The
  description also states the re-slug rule rather than two cases: scheduling and going private
  derive a slug too, and a taken slug gets a `-N` suffix. README carries both measured tables.
- **`delete-post`**: "force=false trashes" was false for a custom post type and for a post
  already in the trash - both were deleted permanently while the result said `trashed: true`.
  force=false now trashes a post of any type and leaves a trashed one where it is, and
  `deleted` / `trashed` are read back after the call.
- **`delete-media`** now says that WordPress deletes an attachment permanently whatever `force`
  says unless the site defines `MEDIA_TRASH`, and returns `trashed` beside `deleted`, both
  read back (shape: `trashed` is new).
- **`delete-term`** on the taxonomy's default term answered "Term not found."; it now says it
  is the default term.
- **`site-info.active_theme` is the theme's name alone, as `list-themes` gives it (shape)**,
  and `active_theme_version` is new. It was "Name Version" glued together, `"JDA "` for a theme
  with no Version header.
- **`sql-select` always returns `truncated_by` (shape)**, `null` when nothing was cut, and says
  that every value is a string as MySQL sends it.
- **`list-revisions`**, which a read-scope token sees without ever seeing `update-post` or
  `restore-revision`, now says that the newest revision normally holds the post's current text,
  and offers restoring only to an admin-scope token.
- **Every tool's description says what it returns**, field names and formats: `list-media`,
  `list-terms`, `list-comments`, `upload-media` (a URL is the only way in; types and size
  limit), the code tools, `create-term`, `delete-term`, `moderate-comment` and `reply-comment`
  had not. A test now fails for a description without one.
- **`link` is not always the pretty permalink.** While a post is a draft, pending, scheduled
  or in the trash, WordPress renders the plain `?p=ID` form whatever the site's permalink
  structure is and whatever slug the post holds - measured on both test sites. `list-posts`,
  `get-post`, `create-post` and `update-post` all say so now; a `private` post does get the
  pretty permalink.
- **`create-term`** now says that tags are stripped from the name, as `create-post` says of a
  title: `Arts & Crafts <b>` is stored as `Arts & Crafts` and the result does not mention it.
  (Measured: a menu item's label, through `add-menu-item` and `update-menu-item`, is NOT
  stripped, so those two say nothing of the kind.)
- **`list-terms`** now says that `count` is how many PUBLISHED posts are in the term - a term
  used only on drafts or scheduled posts reads `0` - and that no item carries a date.
- **`code-history` and `list-users`** said their date was "ISO 8601 site-local, as every list
  tool gives dates", and `list-terms` returns no date at all. Both now say "as every date
  these tools return".
- **Read scope is not privacy.** Settings > WP MCP says, where a token is minted, that a
  read-scope token reads everything its user can - every user's email, for an administrator -
  and that scope gates writing only. The handshake instructions carry the same line.

### Added: every build says which build it is

- **A build stamp, written by git, never by hand.** `build.txt` ships at the root of the
  plugin carrying three placeholders; `.gitattributes` marks that one file `export-subst`,
  so `git archive` - which is how every zip of this plugin is cut - replaces them with the
  commit it is archiving. Nothing has to be bumped, and a build cannot claim a commit it
  was not built from.
- **Four places report it**, and all four print the same string because all four call
  `wpmcp_build_label()`: the **Plugins** screen (in the row meta, beside the version the
  header supplies), **Settings > WP MCP** (under the heading, with the commit date), the
  `initialize` handshake (`serverInfo.build`), and `site-info` (`wp_mcp.build`, a new
  nested object also carrying `wp_mcp.version`).
- **A copy that is not a build says `source`.** Running the plugin out of a git checkout
  leaves the placeholders literal, and the plugin reports the word `source` rather than the
  version, a file's mtime, or the placeholder itself. Nothing fails, warns or behaves
  differently when the stamp is absent - that is the ordinary case on a development site.
- **The version is untouched.** `Version:`, `WPMCP_VER` and `serverInfo.version` stay a
  plain semantic version on every build, release or dev. The stamp sits *beside* the
  version, never inside it, so there is no `-dev+<sha>` anywhere and nothing for a release
  to strip. `docs/RELEASE.md` records the decision, what it costs (the Plugins screen's own
  `Version` column still reads `1.1.0` for every build, which is why the row meta exists)
  and both zip recipes.
- **Why:** every dev zip so far reported `Version: 1.1.0`, so an installed zip could not be
  told from an older installed zip at all. Only the filename distinguished them, and a
  filename is gone the moment the plugin is installed.
- New filter **`wpmcp_build_id`**, for a packager that stamps builds some other way. It is
  shape-constrained, not narrow: PHP on the site can make a checkout claim any build id
  that looks like one, which is the ordinary power of site PHP and is not reachable from a
  request. What the check guarantees is that a sentence, a version number, a placeholder,
  the word `source` or an empty string can never appear as a build. There is deliberately
  no filter on the build DATE: it is shown only while the reported id is still the one in
  `build.txt`, so a packager's id is never printed beside git's date for another commit.
- **The stamp names the zip, not the folder.** Replace the plugin rather than copying new
  files over an installed one: a hand copy leaves the previous `build.txt` behind and the
  plugin then reports a build it is not running. Nothing git or the recipes do can produce
  that. README and `docs/RELEASE.md` both say so.

### Fixed: a theme-file change now reaches the opcode cache, so the site really runs what you wrote

- **`code-write`, `code-restore` and `code-delete` changed a PHP file and never told PHP's
  opcode cache.** The hosts this bites are the ones running `opcache.validate_timestamps=0`,
  where PHP does not stat a file it has already compiled, or any
  `opcache.revalidate_freq`, where it stats it no more often than that - at the default of 2
  the old code ran for up to two seconds, at `revalidate_freq=60` for up to a minute. So
  before this fix, on such a host: `code-write`
  answered `bytes: 4096` and your site went on running the OLD `functions.php` until the
  PHP pool was restarted; a write reverted for a syntax error could leave the *rejected*
  bytes compiled and running while the tool reported `reverted: true`; and a file
  `code-delete` removed could keep executing with nothing on disk to explain it. Nothing in
  the result said so, because as far as the file was concerned everything had worked. If you
  have used these tools on such a host and a change appeared not to take effect, that is
  what happened - and a PHP-FPM or web-server restart was the workaround.
- **Every path in the plugin that changes a file PHP compiles now calls
  `wp_opcache_invalidate()`**, which is WordPress core's own function (since 5.5, this
  plugin's floor) and exactly what core's built-in theme editor calls. Following core, the
  call is made after a write **and again after a rollback**, so a reverted write does not
  leave the reverted bytes cached. `code-delete` is the one that invalidates *before* its
  unlink rather than after: PHP resolves the path on disk before it looks in the cache, so
  once the file is gone there is nothing left to drop - measured, not assumed.
- **Nothing behaves differently on a host with no opcode cache, which is most of them.**
  Core's function returns false, silently, when there is no cache, when the cache is off, or
  when the file is not a `.php` file at all. That is "there was nothing to tell", not a
  failure, and it is why **no tool result gained a field about the cache**: `opcache: false`
  would read to a client as "the change is not live", which would be untrue on every host
  without one. What the tools claim is unchanged - what they did to the file.
- **Three cases where a cache that exists keeps running the old file anyway, so a code tool
  can report a successful write the site is not yet running.** They are things you can check
  and the plugin **does not report** them - not because it cannot tell (for the first two it
  can), but because a cache field in a tool's result would read to an agent as "the change is
  not live", and would say that on every host that never had the problem. So if a change does
  not take effect, check these first; restarting PHP - PHP-FPM, or whatever runs it on your
  host - remains the way to make it take effect now:
  - **`opcache.restrict_api` is set** to a path that does not cover the script serving
    `/wp-json/`. WordPress will not call the invalidation at all. Clear it, or widen it.
  - **A plugin or `mu-plugin` returns false from the `wp_opcache_invalidate_file` filter**,
    which is WordPress's own documented opt-out. Something on your site refused the call.
  - **The PHP pool runs on more than one node over a shared filesystem.** Here the local
    cache IS told: the write lands for every node, and the invalidation lands only on the node
    that served the request. The others keep their compiled copy until their next revalidation
    or a restart - which means restarting **every** node, not just the one you called.
- Also covered: the empty `index.php` the plugin writes into `wp-content/wpmcp/`, and the
  same file when Delete removes it. The 1.0.x backup sweep needs nothing - what it deletes
  is a `.bak`, which PHP does not compile.
- Found by reading core's theme editor beside ours, and proved on both test sites by a
  test that reads the file from disk at the instant the call is made - so the *order* is
  measured rather than inferred from the source.

### Fixed: two sentences in `update-post` that described the opposite of what it does

- **"Only the fields you send change" was false for a draft nobody dated.** WordPress
  re-dates a draft whose `post_date_gmt` is still empty to "now" on *any* update, so editing
  the title alone moved `date`. The description now names that case, and `changed` now
  names `date` when it happened - with `date` and `date_gmt` returned beside it, so a
  caller does not have to re-read the post to find out. A dated draft and a published post
  are unaffected, and `changed` does not name `date` for them.
- **"The current title, content and excerpt are saved as a revision first" read backwards.**
  The revision an edit *creates* holds the NEW text; the pre-edit text is the one BELOW it.
  A reader following that sentence literally restored what they had just written and
  concluded the undo was broken. `update-post` now says which revision is which and names
  `restore-revision` as what undoes to the lower one; `restore-revision`'s own wording,
  corrected earlier, already said the same thing and the two now agree. README's *Undoing a
  content edit* carries the measured table.
- **And a second unsent change, measured after the first was fixed:** publishing a post
  whose slug is still empty makes core derive one from the title, so a status-only update
  changes `slug` as well. `changed` names that too. Moving the same draft to `pending`
  does not - core fills `post_name` only when a status leaves the draft/pending set - and
  a post that already has a slug keeps it on publishing. **Trashing re-slugs every post**:
  core appends `__trashed` (`""` -> `__trashed`, `x` -> `x__trashed`), and `changed`
  names that as well - it compares the column before and after, so it reports whatever
  core did rather than following a list of statuses.
- **The revision sentence now needs a text change.** Core saves a revision only when
  title, content or excerpt differ, so after a status-only, terms-only or same-text update
  there is no new revision and "the one below the newest" is some earlier edit's text.
- Both of the original two were found by a client reading the tool's own contract on a
  real site, not by the test suite; the slug case was found by a review doubting the word
  "exactly one".

### Added: a 30-day active window on a local development site

- **The window cap now follows the site's environment type.** `wpmcp_max_window()` answers
  30 days (`WPMCP_LOCAL_MAX_WINDOW`) when `wp_get_environment_type()` is exactly `local`,
  and 12 hours (`WPMCP_MAX_WINDOW`, unchanged) everywhere else. Mint, Renew and the mint
  form's hours field all clamp to it, and the mint form states the 30-day cap on a local
  site. `development` and `staging` do NOT qualify: a development server can face the
  internet. Setting `WP_ENVIRONMENT_TYPE` to `local` on a public server widens that site's
  windows to 30 days - see SECURITY.md.
- **Why:** a dormant token makes a local MCP server fail to connect at the next client
  start, and the daily human checkpoint the 12-hour cap exists for is about connectors on
  sites the internet can reach.
- **The cap is enforced where a token is USED, not only where one is written.** A row's
  window is honoured as `min(the window it was granted, this site's maximum)`, counted from
  the moment that window last started, so a database copied from a local site to a public
  one carries no 30-day windows with it: those rows are **dormant** at most 12 hours after
  their last renewal there - at once where that renewal was clipped by the lifetime - and an
  ordinary **Renew** restores them at 12 hours. Nothing is deleted or revoked. Renew stores
  a clamp it had to apply, so the row it writes says what the site actually granted; the
  admin table shows the moment the window really ends and says when it was capped. That
  store is permanent: a token renewed off the local site gets 12 hours when it is renewed
  back on it, and a new mint is the only way to widen a stored window.
- **Unchanged:** the lifetime still bounds the window (Renew is still
  `min(now + window, expires_at)`), the default window is still 6 hours everywhere, and the
  v2 -> v3 migration still caps a backfilled window at `WPMCP_MAX_WINDOW`, so a database
  upgraded on a local copy carries no 30-day windows.
- **New filter `wpmcp_local_environment`**, asked only on a site that already reports
  `local`. It can turn the relaxation OFF; nothing can turn it on anywhere else. The test
  suite uses it, because WordPress caches the environment type for the life of a process.
- **New: `bin/dev-tokens.php`** (not shipped in the release zip), run with
  `wp eval-file`, and the wrapper `bin/dev-tokens.sh`. `status`, `label` and `mint` for the
  `.mcp.json` servers whose URL host is this site's own, found by the sha256 of their
  bearer value. It refuses any site that is not `local`, prints no token and no hash, and
  `mint` backs the file up, writes the new file beside it and renames it into place -
  never a truncating write - and replaces only the matching servers' `Authorization`
  values, without revoking the old tokens. Every failure names the backup.

### Changed: the debris check no longer calls an operator's switch debris

- `wpmcp_sql_enabled` left ON is a **notice**, and the report still says clean. No test
  LEAVES that option changed - the SQL tests arm it with a per-request filter, and the one
  test that writes it restores the operator's value in the same process - so when it is on,
  an operator turned it on. A `wpmcp-test-` key left in the post-meta allow-list is still
  debris and still fails the check, as is any foreign fixture.

### Security: a theme file's backup is no longer served over the web

- **Nothing is written beside a theme file any more.** `code-write` used to copy the file
  it was about to overwrite to a sibling with a backup extension, and `code-delete`
  renamed the file to one instead of removing it. Both sat in the **active theme**, which
  is inside the document root, with an extension nothing executes and nothing blocks - so
  the URL returned the complete source of a theme file to anybody who guessed it. It was
  also a backup of exactly one generation: the next write overwrote the only copy.
- **Previous contents now go into a table**, `{prefix}wpmcp_file_versions`, which is the
  one store WordPress never serves. Schema revision 4 creates it and revision 5 adds the
  `theme` column below. Each row holds the
  path, the bytes, the size, a SHA-256, why it was stored, who caused it and which token
  they were using. Twenty versions are kept per path - change that with the new
  `wpmcp_file_versions_keep` filter - and the table is dropped when the plugin is deleted.
- **The upgrade collects what is already on disk.** On the first request after the plugin
  files change, the upgrade walks the active theme for the old sibling backups, stores each
  one under its original path with reason `sweep`, and deletes it. It follows no symlinks,
  it does not touch the live file next to a backup, it does not recreate a file whose
  deletion was deliberate, and running it again does nothing. It runs whether or not code
  editing is switched on: those files are on disk either way.
- **If a version cannot be stored, the change does not happen.** `code-write` and
  `code-delete` now return an error rather than touching a file they could not back up
  first. The PHP parse-error revert writes back the bytes it just versioned, from memory.
- `code-delete`'s `backup` return field is gone; both writers return `version_id` instead.
- **The sweep takes only what the tools could give back.** A backup whose original name is
  not a text extension this plugin writes, or that is over the 512 KB cap, or that cannot
  be read, is left exactly where it is and named in the log line - a file `code-restore`
  would refuse is a file the upgrade should not have taken. The report names every path it
  moved and every path it left, and it now fires late enough on `plugins_loaded` for the
  plugin's own log listener to hear it; before, on the path almost every upgrade takes, it
  went nowhere at all.

### Fixed: a leading `./` walked past the code-editing denylist

- `code-read`, `code-write`, `code-delete` and the new tools resolved a caller's path but
  matched the denylist against the caller's **spelling**, so `./inc/x.php` was allowed
  where `inc/x.php` was refused. Directory rules (`inc/`, `includes/`, `lib/`) were
  affected; bare-filename rules (`functions.php`) were not, because those match on
  basename. Measured on a live theme by review. Every path is now canonicalised from its
  resolved location before anything acts on it.
- The same fix ends a second defect that arrived with the version table: the spelling was
  its lookup key, so `./style.css` and `style.css` had separate histories and separate
  retention caps, and `code-history style.css` after a `code-write ./style.css` returned
  nothing.
- **Versions record their theme.** The jail is the active theme, so `style.css` is a
  different file after a theme switch. `code-history` lists only the active theme's
  versions and `code-restore` refuses one belonging to another theme, naming it.

### New: `code-history` and `code-restore`

- `code-history {path}` lists the stored versions of one file, newest first: `id`,
  `saved_at`, `size`, `sha256`, `reason` and `saved_by` (a login, never an email). A path
  with no stored versions returns an empty list, which is not an error.
- `code-restore {version_id}` writes one back. It resolves the stored path through the
  same jail and denylist a caller's path goes through - a denylist can be widened after a
  version was stored - versions the current contents first under reason `restore`, applies
  the same PHP parse check and revert, and reports whether the bytes it wrote match the
  stored hash. A deleted file comes back this way.
- Both appear only when code editing is enabled, and both sit behind the same gate as the
  other four: an admin-scope token whose user holds `edit_themes`, with `DISALLOW_FILE_EDIT`
  and `DISALLOW_FILE_MODS` honoured. The catalog was 22 tools at that point.

### New: `sql-select`, one read-only SQL statement (opt-in, off by default)

- A second switch in **Settings > WP MCP**, `Allow SQL reads`, off by default. While it is
  off the tool is absent from `tools/list` and calling it by name is refused exactly the
  way a tool that does not exist is refused - there is no answer that says "it is here but
  switched off".
- `sql-select {sql}` runs one statement and returns `{columns, rows, row_count, truncated,
  truncated_by}` as JSON. Caps: 200 rows, 256 KB of rows, 8 KB per cell (cut and marked
  with an ellipsis) and a 5-second server-side statement timeout. `NULL` is JSON `null`; a
  value that is not valid UTF-8 comes back as `0x`-prefixed hex, because WordPress's JSON
  encoder silently rewrites the offending byte as `?` rather than failing.
- **Writes are refused by the database, not by a parser in this plugin.** The statement is
  wrapped as `SELECT * FROM ( ... ) AS wpmcp_q LIMIT 201` and run inside `START TRANSACTION
  READ ONLY`. The wrapper makes `UPDATE`, `DELETE`, `SHOW`, a stacked second statement,
  `INTO OUTFILE`, `INTO DUMPFILE` and `INTO @var` syntax errors from MySQL itself; the
  transaction refuses what the wrapper lets through, which is measurably not nothing -
  `SELECT ... FOR UPDATE` parses fine inside a derived table on MySQL 8.4 and is stopped by
  the transaction with 1792. `ROLLBACK` runs in a `finally`, so the connection WordPress
  reuses for the rest of the request is never left inside a transaction.
- CTEs (including recursive ones), joins, `UNION` and an inner `ORDER BY` all work. Two
  limits come with the wrapper: a derived table's columns must be uniquely named, and
  `SHOW` / `DESCRIBE` are not query expressions - use `information_schema`.
- **Three gates, all required:** the switch, an admin-scope token, and `manage_options` on
  the token's user. Admin scope is not an administrator, since a token can be minted to run
  as any user.
- **The plugin's own two tables, and `LOAD_FILE`, are refused by name.**
  `{prefix}wpmcp_tokens` and `{prefix}wpmcp_file_versions` - the database user owns them, so
  this is the one rule the server cannot enforce. `LOAD_FILE()` because it passes both walls
  (a query expression, and a read) and reads the server's disk whenever `secure_file_priv`
  and the `FILE` privilege permit it; that is one function and not a file-read boundary, and
  SECURITY.md says to pin `secure_file_priv` or deny `FILE` regardless. All three are a blunt
  name check that refuses the statement if the name appears anywhere in it, comments and
  string literals included.
- **The session is handed back as it was found.** The prior `MAX_EXECUTION_TIME` (or
  `max_statement_time`) and `optimizer_switch` are read before they are changed and restored
  in the same `finally` as the `ROLLBACK`, so the connection WordPress uses for the rest of
  the request does not carry a 5-second cap and an altered plan away from this tool.
- **It reads everything else that connection can read**, `wp_users` and its password hashes
  included. Read [SECURITY.md](SECURITY.md) before switching it on.
- New auth event `sql_select` (`token_id`, `user_id`, `row_count`, `truncated`,
  `elapsed_ms`, and the first 200 characters of the statement). A refused statement returns
  the MySQL error number and a trace id; the server's own message and the whole statement
  go to the private trace log only.
- New option `wpmcp_sql_enabled`, removed on uninstall. No schema change. The catalog is
  23 tools.

### Added: `list-revisions`, `get-revision` and `restore-revision`

- **Undo for content, using the revisions WordPress already keeps.** `list-revisions {id}`
  lists a post's revisions newest first, paged with `has_more` - id, date, author
  `{id, name}`, title, and `autosave` (autosaves are listed and flagged). `get-revision`
  returns one revision's title, content and excerpt raw, in the shape `get-post` uses, for
  a client to compare; no diff is computed on the server. `restore-revision` (admin scope)
  puts a revision's title, content and excerpt back. The catalog is 28 tools.
- **One gate, WordPress's own:** the capability to edit the post the revision belongs to,
  which is what wp-admin's revision screen and the REST revisions controller check. A
  revision of a post you may not edit, an id that is not a revision, and an id that is not
  there all answer the same `No revision with that ID.`
- **A restore changes only what revisions hold.** Status, date, author, slug and terms come
  out exactly as they went in. The replaced text is saved as a revision first and the
  restored text becomes the newest revision, so a restore is itself undoable; the reply
  names `new_revision_id`.
- **Two refusals that say why**, as wp-admin makes them: the post is being edited by
  another user right now (named by display name), or revisions are turned off for the post
  and the revision is not an autosave.
- **On a site running ACF, a restore also rewinds ACF field values - and that part cannot be
  undone here.** WordPress restores the meta it keeps with revisions (core's `footnotes`),
  and ACF copies its field values from the revision onto the post. The copy saved before a
  restore holds the revisioned meta but no ACF values, because ACF writes those into a
  revision only during its own form save; restoring that copy brings back title, content,
  excerpt and `footnotes`, and the ACF fields stay rewound. Measured with ACF Pro 6.3.11.
  A restore is also an ordinary update, so a scheduled post whose date has passed is
  published by it.

### Added: `list-menus`, `get-menu`, `add-menu-item`, `update-menu-item` and `remove-menu-item`

- **Classic navigation menus** - the `nav_menu` taxonomy and its items, what Appearance >
  Menus edits. `list-menus` and `get-menu` (read scope) list the menus, the theme's menu
  locations and one menu's items as a tree; `add-menu-item`, `update-menu-item` and
  `remove-menu-item` (admin scope) change them. A block theme's Navigation block
  (`wp_navigation`) is not edited; `list-menus` reports `block_theme` so a client knows
  when a classic menu may not be what visitors see. The catalog is 33 tools.
- **WordPress's own gates.** Reading needs `edit_theme_options`, or `edit_posts` on any post
  type in the REST API, as core's menus endpoints check: Editors read menus, Subscribers do
  not. Writing needs `edit_theme_options`, which is what every capability WordPress maps
  for menu items resolves to.
- **The order stays contiguous.** `position` places an item among its siblings and every
  write renumbers the menu 1..N. WordPress's own function shifts nothing, so two items
  could hold one place, and it gives a new menu's first item 0.
- **Removing an item lifts its children** one level, into its place, as wp-admin does.
  WordPress's function leaves them pointing at the deleted item.
- **Refused, by name, where WordPress would store it:** a parent from another menu, a parent
  inside the item being moved, and a url that is not http, https, `mailto:`, `tel:` or a path
  on this site - WordPress stores a `javascript:` url as an empty link without a word - or that
  holds a backslash, which browsers read as a slash. `//host` links are allowed.
- **A menu never reveals what its reader may not read.** An item linking to another user's
  draft or private page is listed with its title, url and linked id null, and marked
  `withheld`. Draft menu items are listed only to callers who can edit theme options, as in
  the REST API; an Editor or an Author sees the items visitors see.
- **An update changes only what it is sent.** Every other field is carried as stored, including
  a parent that points at a deleted item - so a theme keeps showing that item where it did.
- **Backslashes survive** in item labels, and a linked item given its page's own title
  still follows that page when it is renamed.

### Added: `list-users`, `get-user`, `get-option`, `list-plugins` and `list-themes`

- **An inventory of the site, read-only.** `list-users`, `get-user` and `get-option` (read
  scope), `list-plugins` and `list-themes` (admin scope). None of them writes anything. The
  catalog is 38 tools.
- **Users as the REST API shows them.** With `list_users`, every user with login, email, roles
  and registered date. Without it, only users who have published posts, as id and display
  name; the `role` and `search` filters are refused by name, and a user you may not see is the
  same answer as a missing id - except that, as in the REST API, a user whose only posts are
  private is visible through `get-user` to a caller who can read them. A Subscriber is refused.
  `name` is the display name each user chose, often their login or an email address. No
  password hash, activation key, session or user meta is ever returned.
- **Ten settings, and one refusal for everything else.** `get-option` reads `blogname`,
  `blogdescription`, `timezone_string`, `gmt_offset`, `date_format`, `time_format`,
  `start_of_week`, `permalink_structure`, `siteurl` and `home` - values the public site already
  shows - for anyone who can edit posts, which is wider than the REST API's settings endpoint on
  purpose. Every other name, whether it exists or not, gets the same answer.
- **Plugins and themes without running other plugins' update code.** `list-plugins` needs
  `activate_plugins` and reports file, name, version, active, network-active (multisite) and
  whether the plugin is in the site's stored auto-update list; `list-themes` needs
  `switch_themes` and reports stylesheet, name, version, parent, active, `block_theme`, and the
  active theme's menu locations to a caller who can edit theme options. Both read the plugin
  and theme files and the stored settings directly and run no update, auto-update,
  plugin-header, theme or per-option filter - the ones Gravity Forms, LiteSpeed Cache and Rank
  Math make update and licence requests from - so neither triggers an update check. The hooks
  every tool call runs (`init`, the capability, database query and option filters, and
  `wpmcp_tools`) still run, and a plugin that goes remote from those does so here too.

### Changed: `update-post` saves the pre-change state first

- **The first edit of a post is undoable now.** WordPress saves a revision after an update,
  of the NEW text, so a post with no revisions - every post `create-post` makes, and every
  imported one - lost its original title, content and excerpt on its first `update-post`,
  with nothing to restore. `update-post` and `restore-revision` now save the current state
  as a revision before they write. On a post whose latest revision already matches it,
  WordPress skips that save, so an ordinary edit still adds exactly one revision.
- **`update-post` refuses while another user is editing the post.** It used to overwrite
  a colleague's open editor without a word, while `restore-revision` already refused. Both
  now answer the same named error, naming the other user by display name, and write
  nothing; a lock you hold yourself does not count.

### Fixed: titles came back through WordPress's display filters (since 1.0.0)

- **A title you read is the title as stored.** `get-post`, `list-posts`, `list-revisions`,
  `get-revision`, `list-media` and `get-media` built the title with `get_the_title()`, which
  runs WordPress's display filters: a title stored as `A\B "quoted"` came back as
  `A\B &#8220;quoted&#8221;`, so a client that read a title and wrote it back corrupted the
  post - and straight quotes, apostrophes and ampersands make that routine. Content and
  excerpt were always the stored columns; titles now match them. Found on a live site.
  Reading is now exact; a round trip still passes through the write side, where
  `update-post` strips HTML tags from a title and, for a caller without `unfiltered_html`,
  WordPress's kses filter encodes some characters.
- **A menu item's label** now follows the linked page's stored title rather than the filtered
  one, in `get-menu`.
- **No more `Private: ` and `Protected: ` prefixes** on the titles of private and
  password-protected posts: that prefix is display text, not the stored title.
- `link`, and a media `url`, stay as WordPress renders them - there is no column to write
  those back to. For `link` that rendering is the plain `?p=ID` form until the post is
  published or private.

### Added: `list-posts` items carry `date` and `modified`

- `orderby: date` was offered while the date itself was invisible, so a caller needed one
  `get-post` per row to sort or filter by hand. Each item now carries `date` and `modified`
  in `get-post`'s ISO 8601 convention, null where the column holds no date.

### Changed: `restore-revision` names both revisions it can store

- The reply now carries `pre_restore_revision_id` - a revision this call saved of what the
  post said *before* it - beside `new_revision_id`, which holds the *restored* text. The
  description said the current text is saved first but named only one id, so the two were
  easy to confuse. `pre_restore_revision_id` is null in the ordinary case: after any edit
  made through these tools or wp-admin, the newest non-autosave revision already holds the
  current text, and that revision, as `list-revisions` showed it before the restore, is the
  undo copy. Both ids are null when revisions are off for the post.

### Fixed: every write tool dropped a backslash (since 1.0.0)

- **A backslash in anything you wrote was silently eaten.** A post title, body or excerpt,
  a term name or description, a media title or alt text, a comment - each lost one
  backslash from every escape on the way to the database. `C:\Users\max` became
  `C:Usersmax`; a regex `\d+` became `d+`; JSON stored as a string lost its escapes. The
  tool then re-read the row and reported the mangled value, which is why it looked right.
- **Why.** WordPress's write functions take SLASHED input and unslash it on the way in -
  `wp_insert_post()`, `wp_insert_term()`, `wp_insert_comment()` and the whole meta API all
  do, and `wp_update_post()` additionally re-slashes the row it read from the database
  before merging yours over it. Every core REST controller calls `wp_slash()` immediately
  before those calls; this plugin did not. It now does, at every one of the eleven call
  sites that receive text from a caller, slashing whole arrays at the boundary rather than
  field by field.
- **A backslashed term name no longer duplicates.** Naming the same category - say
  `A\B` - on two posts used to create it twice (`ab`, then `ab-2`), because the lookup
  that decides whether a term already exists strips a backslash of its own. The lookup is
  now slashed to match the write, so the second post reuses the first term. The same
  applies to `search` in `list-posts` and `list-media`: a search for a backslashed value
  finds the post that holds it. A term that an older build stored without its backslash
  (`AB`) no longer matches `A\B`, so the next post naming it creates the correct term once.
- **Nothing to do on your side**, and nothing already stored changes: this only affects
  what happens to a value on its way in from now on.

### New: scheduling, authorship and featured images on the write tools

- **`create-post` and `update-post` gain `date`, `author` and `featured_image`**, shaped
  and capability-checked in one shared step so the two tools cannot drift apart. Both now
  also report `changed`: the list of fields the call actually touched.
- **`date` is ISO 8601**, with or without a UTC offset. Without one it is the site's local
  time, which is what wp-admin shows; with one the instant is fixed by the caller. Both
  `post_date` and `post_date_gmt` are written to describe that one instant. A malformed
  date is refused rather than guessed at - there is no `next tuesday`.
- **Scheduling works and says what it did.** Send a future `date` with `status: "future"`;
  the capability is `publish_posts`, because scheduling is publishing. WordPress silently
  turns `publish` plus a future date into `future`, and `future` plus a past date into
  `publish`, so the result carries the stored `status` and `date` rather than what was
  asked for. A date given to a **draft** is now kept - core re-dates a draft on every
  update unless told the date was deliberate, so passing one used to be a silent no-op.
- **`author`** takes a user id or a login, needs the capability to edit other people's
  posts of that type, and refuses a user who could not write that post type - with a
  sentence that says nothing else about the account. The reply gives `{id, name}` with the
  display name.
- **`featured_image`** takes an image attachment id and is checked against **that
  attachment**: you need to be able to edit it, which for an Author means their own uploads
  and not somebody else's. `0` removes the image. A create that is refused for its image
  leaves no post behind.

### New: `get-post-meta` and `set-post-meta`, behind an allow-list (opt-in, off by default)

- **Two tools for a post's custom fields**, listed only when an administrator has named at
  least one meta key in the new **Post meta keys** textarea in Settings > WP MCP. While the
  list is empty they are absent from `tools/list` and calling either by name answers what
  a tool nobody registered answers - the rule the code tools and `sql-select` already
  follow.
- **The allow-list exists because WordPress has no capability that separates a subtitle
  from a plugin's private state.** Rather than guess, the operator names the exact keys.
  Keys WordPress calls protected - anything with a leading underscore - are dropped on save
  and refused at call time as well, because the option is an ordinary row that wp-cli or a
  restored backup can write without passing through the form.
- `get-post-meta {id, key?}` returns `meta` as an object of key to value: one row as a
  value, several as a list, and only keys that have a value. It refuses a post the caller
  may not read exactly the way `get-post` does.
- `set-post-meta {id, key, value}` replaces the key - a scalar, a flat list, or `null` to
  delete it. An object is refused. It needs the capability to edit the post *and*
  WordPress's own `edit_post_meta` for that key.
- **ACF values are ordinary meta under the field name**, so naming the field lets a token
  read and write it. The `_<field name>` reference row ACF keeps is not written, which is
  measured and documented in README: a field that has been set through ACF at least once
  reads back correctly, and one that never has reads back as a raw string.
- **Values go in slashed.** WordPress's meta API takes slashed input and unslashes it, so
  a value written raw lost a backslash: `C:\Users\max` became `C:Usersmax`, a regex
  `\d+` became `d+`. `set-post-meta` now slashes the key and the value the way core's own
  REST meta layer does, and a backslash survives byte for byte. A key **containing** a
  backslash is refused outright, at save time and at call time: `is_protected_meta()` sees
  the backslash rather than the underscore behind it, so `\_thumbnail_id` would have passed
  every protected-key check and arrived at the database as `_thumbnail_id`.
- Only `null` deletes a key. An empty JSON object decodes to an empty array, which used to
  take the list branch and delete every row while reporting success; an object, a nested
  list, and an empty list or object are now all refused.
- `changed` names the fields the call named, on **create-post** as well as update-post -
  it used to omit `title`, `content` and `status` on create.
- `author` refuses anything that is not an integer id or a non-empty login string.
  `author: true` used to be cast to user id 1.
- Deleting the plugin removes the new `wpmcp_meta_keys` option with everything else.

### New: `list-posts` can find things, and `get-post` returns the rest of the post

- **`list-posts` gains nine filters**: `search` (title, excerpt and content), `category`
  and `tag` (slug or term id), `term` (`"taxonomy:slug"`, for any other taxonomy),
  `author` (user id or login), `after` and `before` (ISO 8601 date or datetime, both
  inclusive), and `orderby` (`date`, `modified` or `title`; default `date`) with `order`
  (`asc` or `desc`; default `desc`). `search` is WordPress's own search, so a leading `-` on
  a word excludes it - that is documented in the tool's description rather than stripped,
  because silently turning an exclusion into its opposite is worse than a syntax to learn.
- **A filter that names something you may not see returns an empty list, not an error.**
  An unknown category, a tag holding only somebody else's draft, an author with nothing
  published, a term in a private taxonomy and a taxonomy nobody registered all answer
  identically: `count: 0`, no items, no message. "There is no such thing", "it is empty"
  and "it is not yours" have to be one answer, or the filter is an oracle for the site's
  user logins and term names.
- **Only a malformed argument SHAPE is an error** (`wpmcp_bad_arg`): a date that is not a
  date, an `orderby` that is not one of the three. Those are the caller's own mistake
  about the protocol, they say nothing about the site, and an agent answered with an
  empty list instead concludes the site is empty and stops looking. Dates are matched
  against `YYYY-MM-DD[THH:MM[:SS]]` rather than passed to `strtotime()`, which would
  have accepted `next tuesday` and rolled `2021-13-45` over into 2022.
- **A taxonomy has to be `is_taxonomy_viewable()` and attached to the post type.** A
  private taxonomy is a plugin's internal bookkeeping - customer segments, workflow
  states - and WP_Query will filter an ordinary post listing by one of its terms without
  complaint; `term` is not a way to read one. Attachment and existence are an allow-list
  rather than a fix: measured, WP_Query does *not* ignore `cat` on a post type with no
  categories, it joins and returns nothing. What all of it buys is that a filter which
  cannot be resolved ENDS the query instead of being quietly dropped - that shape is the
  one that answers a question about one category with every post on the site.
- **Paging.** `page` (1-100, default 1) on top of `limit` (1-100, default 20). The result
  now carries `page`, `limit` and `has_more` beside `count` and `items`. There is still no
  total: a total is a count of posts the caller has not been shown, and on the
  own-unpublished side it would be a count of somebody's drafts.
- **Sticky posts are ignored, and that was a bug worth naming.** WordPress decides a query
  is a "home" query from its arguments, and `after`/`before`, `status` and `orderby` set no
  argument that says otherwise - so a listing filtered only by those was a home query, and
  core splices every sticky post into the front of one, fetched as `publish` with none of the
  original conditions. `after: "2030-01-01"` returned posts from 2021; `status: "draft"` on
  an editor's token returned published ones. Not a permission leak - stickies are published -
  but a false answer to the question asked, which is the failure this tool exists not to have.
  Both queries now pass `ignore_sticky_posts`.
- **Ties are broken by ID in the SQL, not only in the merge.** The ordering handed to both
  queries is now `<column>, ID`, in the same direction. Without it, rows sharing a `post_date`
  to the second - which any import produces - could come back in a different order, and a
  different subset, from the `LIMIT` behind page one and the `LIMIT` behind page two, so a
  caller paging through them could see one twice and another never.
- **The listing no longer primes the postmeta cache.** It reads id, title, type, status, slug
  and the permalink and no meta at all, while the paging fetch can ask for up to 10,001 rows
  per query; on a site carrying ACF or SEO meta, priming that is a memory problem rather than
  a slow one. The term cache stays on - `get_permalink()` needs it on a `%category%`
  permalink structure.
- **One ordering across the merge.** list-posts runs two queries - everything you may see,
  plus your own unpublished work, which WP_Query cannot express in one - and both are now
  given the same explicit `orderby`/`order`, with the merge comparator following the same
  column and direction and tie-breaking on ID. Before this the merge was hard-coded to
  `post_date` descending, and a `search` would also have switched one half of the listing
  into relevance ordering on its own.
- **No filter can widen what a token may see.** The two capability-decided status sets are
  still the guard; every filter narrows inside it. The filters are built in one function,
  `wpmcp_list_posts_filters()`, from named arguments mapped to an allow-list of eight
  WP_Query keys, and both queries consume the same array - a filter applied to one and not
  the other would hand an Author their own drafts back under somebody else's category.
- **`get-post` returns `excerpt`, `link`, `author` `{id, name}`, `date`, `date_gmt`,
  `modified`, `modified_gmt`, `featured_image` `{id, url}` or `null`, `terms` keyed by
  taxonomy (each `{id, name, slug}`), and `revisions`.** The author is a display name and
  an id - never the login, which is half of a credential, and never the email.
- **Dates are ISO 8601, and a `0000-00-00` column is `null`.** Every date-floating status
  (draft, pending, auto-draft) is stored with `post_date_gmt` and `post_modified_gmt` set
  to zero; formatting that produces `-0001-11-30T00:00:00`, which a client parses without
  complaint.
- **`revisions` is a count, and only for a caller who can `edit_post`** - `null`
  otherwise, not `0`. Revisions are editorial data and wp-admin puts the panel behind the
  same capability; the ids are counted with `fields => ids`, so no revision body is loaded.
- The three `get-post` refusals - missing id, unreadable post, wrong kind of thing - are
  still one byte-identical message, and they still run before any of the above.
- No schema change, no new option, and the catalog is still 23 tools.

### Breaking: the token travels in a header, and only in a header

- The route whose path carried the token, `/wp-json/wpmcp/mcp/<token>`, **is gone**. A URL
  with a token in it is now a plain REST `404`. Every client must send
  `Authorization: Bearer <64 lowercase hex>` against the constant URL
  `/wp-json/wpmcp/mcp`.
- **Why.** A URL is written into every access log, proxy log and browser history it passes
  through, and a hosted connector re-sends the same one for months. The path form existed
  because Claude Desktop and claude.ai were believed to have no field for a request
  header. Measured on a public test site on 2026-09-13, they do: the custom-connector
  *Request headers* setting delivers `authorization: Bearer <token>` intact.
- **What to change.** In a `.mcp.json`, move the token out of `url` and into a `headers`
  map. With the Claude Code CLI, `claude mcp add --transport http wpmcp <url> --header
  "Authorization: Bearer <token>"`. In claude.ai or Claude Desktop, *Add custom connector*
  → the URL → Authentication *No sign-in* → a request header named `authorization` with
  the value `Bearer <token>`.
- If every request is now refused with `reason=missing` although the client is sending the
  header, the web server is eating it: Apache running PHP as CGI or FastCGI does not pass
  `Authorization` to PHP. WordPress handles that itself, provided its own `.htaccess`
  block is present to re-export the value - so the thing to check is the block, not the
  plugin.

### Breaking: no IP pinning

- A token no longer locks to the address of its first tool call, and is no longer refused
  from anywhere else. The `bound_ip` column, the `pin_bind` and `ip_mismatch` events and
  the *Bound IP* admin column are all removed.
- **Why.** On a public test site on 2026-09-13 an Anthropic-hosted connector was observed
  calling from four egress addresses inside one minute - `160.79.106.164`, `.185`, `.186`,
  `.187`. The pin bound the token to whichever arrived first and answered `401` to the
  rest of the session. There is no single address to hold a token to.
- The caller's address is still recorded on every auth event. It decides nothing. Behind a
  proxy, use the `wpmcp_client_ip` filter so the log is worth reading.
- **What this costs**, stated plainly: a token copied out of a log is now usable from
  anywhere. The credential being a header rather than a URL, and the short active window
  below, are what carry that weight instead.

### Breaking: two timers per token, and Renew

- A token now has an **active window** and a **lifetime**, and the old single expiry is
  neither of them on its own.
  - *Active window*: 6 hours by default, 12 at most. While it is open the token answers.
    When it closes the token is **dormant** - refused exactly like any other bad
    credential, but its row survives and **Renew** restarts the window. The token itself
    does not change, so nothing holding it has to be edited.
  - *Lifetime*: 30 days by default, 365 at most. Past it the token is **dead**: Renew is
    refused and the hourly cleanup removes the row.
- **Why.** One expiry had to be short enough to bound a leak and long enough that a
  connector was not re-added twice a day, and claude.ai cannot edit a connector's header
  after the fact - so a new token means a new connector. Splitting the two lets the short
  number stay short.
- `wpmcp_mint()` takes two durations instead of one:
  `wpmcp_mint($scope, $label, $window_secs, $lifetime_secs, $user_id = 0)`. Any code
  calling it must be updated. `WPMCP_MAX_TTL` is replaced by `WPMCP_MAX_WINDOW` (12 h) and
  `WPMCP_MAX_LIFETIME` (365 d).
- `wpmcp_renew(int $id)` restarts a token's window. It works on an active or a dormant
  row, is refused on a dead one, and can never push the window past the lifetime.
- A refused token's row is **no longer deleted** when it is presented. That deletion is
  what made Renew impossible: by the time an admin saw the `401` there was nothing left to
  renew. Dead rows are removed by the hourly `wpmcp_flush_expired` cron, which leaves
  dormant rows alone.
- The auth events gain `renew` (`token_id`, `user_id`, `actor`, `window`); `mint` now
  carries `window` and `lifetime` instead of `ttl`; `validate_fail` gains the reason
  `dormant`. The full reason list is `missing`, `malformed`, `not_found`, `user_missing`,
  `dormant`, `expired` - all six are still one byte-identical `401` on the wire.

### Schema revision 3

Run automatically on the first request after the update, and idempotent.

- Adds `active_until` and `window_secs`; `expires_at` keeps its name and now means the
  hard lifetime.
- Backfills every existing row with both timers:
  - `active_until` becomes the old `expires_at`, so the token stops answering at exactly
    the moment it always would have. A token minted for twelve hours still answers for
    those twelve hours.
  - `window_secs` becomes however long the token was originally granted, capped at 12
    hours - so its first Renew gives it the window it had, and a hand-extended row cannot
    hand out a 90-day active window.
  - `expires_at` becomes `created_at` + 30 days, the same default a freshly minted token
    gets, **so the row is renewable**. It then goes dormant rather than being deleted, and
    Renew brings the same token back without the client being touched. A row whose old
    expiry is already further out than that keeps its old expiry rather than being
    shortened.
- Drops `bound_ip`, with an explicit `ALTER TABLE` guarded by a column-exists check,
  because `dbDelta()` only ever adds and widens and cannot drop a column.
- The revision is recorded once every column exists and every backfill has succeeded, so a
  failed migration is retried on the next request rather than stamped and forgotten. The
  column drop is the one step that does not gate it: nothing reads that column, so a host
  whose database user cannot `DROP` is fully upgraded and correct. A failed drop writes one
  `wp-mcp:` line to the error log instead. Gating on it would have left such a host running
  `dbDelta()` and both backfills on every request forever, with nothing saying why.

### Admin page

- The mint form asks for an active window in hours and a lifetime in days.
- The token is shown once, with the constant URL, the `Authorization` header line, a
  three-step recipe for a claude.ai or Claude Desktop custom connector, and the equivalent
  `claude mcp add` line.
- The table gains **Status** (active / dormant / dead, or *owner missing* when the
  WordPress user the token runs as has been deleted), *Active until* and *Lifetime ends*,
  and a **Renew** button beside Revoke - offered only on rows where renewing can actually
  work, so not on a dead row and not on one whose owner is gone. `wpmcp_renew()` refuses
  both, naming which.

### Docs and tooling

- `docs/CONNECT-CLIENTS.md` carries the connector recipe, the measured claude.ai
  behaviour (the header arrives intact, it cannot be edited afterwards, and *Connect*
  probes the URL with no credential at all - one `reason=missing` line at connect time is
  normal), the Apache `.htaccess` fix, and the renew workflow.
- `bin/claude-code-smoke.sh` uses the header form.
- README, SECURITY, ARCHITECTURE, CONFORMANCE and the knowledge base are updated
  throughout.

## 1.0.0

First release with a stable tool contract. Requires WordPress 5.5 and PHP 8.1.

If you are upgrading from 0.3.x, read the identity section first: existing tokens keep
working, but what they can reach is now decided by a WordPress user rather than by the
admin who minted them.

### Identity and authorization

- Every token is bound to a WordPress user, picked when you mint it and defaulting to
  you. The request runs as that user, so that user's capabilities set the ceiling and the
  token's scope narrows from there. Delete the user and the token stops working.
- The read tools honor those capabilities. `list-posts` no longer lists another author's
  private or draft posts, `get-post` refuses an id the user cannot read, and
  `list-comments` returns only what the user is allowed to see (approved comments unless
  they hold `moderate_comments`).
- The write tools check the capability for the thing being changed, on every mutating
  call, rather than matching against a list of tool names. A token whose user is an Editor
  can no longer delete another author's post through a tool that forgot to ask.
- `reply-comment` goes through `wp_new_comment()` instead of inserting the row itself, so
  Akismet, the blocklist, the moderation setting and the author notification mail all
  still happen.
- Upgrading adds a `user_id` column to the token table and backfills it from the minting
  admin. The check runs on load, not on activation, because activation does not fire for a
  plugin updated in place.
- Ten `wpmcp_auth_event` actions report what the wire deliberately does not: which of the
  six token failures it was, a refused scope, a refused origin, a tool refused at
  registration, a mint, a revoke, an IP pin. There is no success event, so a listener
  waiting for one waits forever. Values are redacted at every depth before the action
  fires, and a context never carries a token or its hash. README.md lists the ten.

### Transport

- HTTPS is required. A plaintext request is refused with 403 before the token is read.
  The gate uses `is_ssl()`, so it is only as strong as your proxy: the proxy must set
  `X-Forwarded-Proto` itself and never pass the client's value through. `composer
  test:infra` asks a running host whether that is true. `WPMCP_ALLOW_INSECURE` in
  `wp-config.php` turns the gate off for a local site with no certificate.
- A browser `Origin` must be one of the site's own, or 403. An absent `Origin`, which is
  what every non-browser client sends, is allowed. Add your own with the
  `wpmcp_allowed_origins` filter.
- A POST must be `application/json`, or 415. Anything other than POST and OPTIONS is
  refused before the token is looked up.
- All six ways a token can fail now return one byte-identical 401. Which one it was lives
  in `wpmcp_auth_event` and the debug log.
- A tool added through the `wpmcp_tools` filter without a `write` key is rejected at
  registration instead of silently becoming a read tool.

### Errors and the trace log

- One catch-all at the boundary. An unexpected failure is `-32603` "Internal error" plus
  an eight-character trace id on the wire, and nothing else. The class, message, file,
  line, `WP_Error` data and stack go to a private log under `wp-content/wpmcp/`, keyed by
  that id. No exception text reaches a client, and a test greps the code to keep it that
  way.
- The log file is named `trace-<32 hex>.log`, generated once per site, so its URL cannot
  be guessed from anything a client sees. A daily self-check fetches that URL and raises a
  site-wide admin warning if the web server serves it. A trace that cannot be written is
  reported rather than dropped.

### JSON-RPC framing

- An array body is refused with `-32600` and the message "Batch requests are not
  supported" rather than being half-processed.
- A notification (no `id`) is answered with 202 and an empty body. A request that carries
  an `id` is answered even when its method name looks like a notification.
- A body whose `Content-Length` exceeds 4 MiB is refused with 413 before the token is
  looked up.

### Handshake

- `initialize` negotiates the protocol revision. A revision this server does not know
  gets a successful response naming `2025-11-25`; a revision declared in the
  `MCP-Protocol-Version` header that it does not speak gets 400 with the list it does.
- A missing `MCP-Protocol-Version` header is treated as `2025-03-26`, which is what the
  specification asks for.
- The capability object lists `tools` and nothing else. Earlier versions advertised
  capabilities the server did not serve.

### Tool contract

- Arguments are checked against the tool's own schema before the tool runs. A wrong type
  or a missing required field comes back as `isError` with a JSON pointer to the field,
  and the tool never executes. Unknown keys are refused.
- All twenty tools declare `readOnlyHint`, `destructiveHint`, `idempotentHint` and
  `openWorldHint`, so a client can tell a listing from a deletion without reading the
  description.
- An empty object inside a schema serializes as `{}` rather than `[]`, which some clients
  rejected.
- A tool description, or any parameter description, over 1,000 characters is refused at
  registration. Clients cap these by truncating, so the end of a long one would never
  reach the model and nothing would say so.
- Every built-in description now opens with a verb-first summary that ends inside the
  first 50 characters, which is roughly all a client shows the model until the tool is
  fully loaded. `site-info`, `list-comments`, `code-write` and `code-delete` were
  reworded; the detail after the first sentence is unchanged.

### Uninstall

- Deleting the plugin now drops the token table, removes its options and the trace log
  directory, and clears the cron hook. Deactivating still leaves everything in place.

### Testing and release

- Two test tiers, one command each. `composer test:unit` is pure PHP. `composer
  test:integration` sends real HTTP at a real site with real users and roles.
- `composer test:client` runs a real MCP client (the Claude Code CLI) against your site
  and checks the answer against your database. It is out of `composer test` and out of CI
  because it costs an API call.
- `composer test:infra` holds the two checks that only a host with real TLS can answer:
  that a valid token over plain HTTP is refused, and that a forwarded `X-Forwarded-Proto`
  cannot talk its way past that refusal. It fails rather than skips on a host without
  TLS.
- CI runs the unit suite on PHP 8.1 through 8.4 and the integration suite against a
  `wp-env` container, and checks that every test in it actually ran rather than skipped.
  The release workflow cannot publish while any of it is red.


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
