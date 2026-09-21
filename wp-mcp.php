<?php
/**
 * Plugin Name: WP MCP
 * Description: Self-hosted MCP server for WordPress with admin-minted, hashed-at-rest session tokens. Read tools by default; admin-scope adds content/media/comment writes and (opt-in) jailed theme code editing. Endpoint: /wp-json/wpmcp/mcp, credential: Authorization: Bearer <token>
 * Version: 1.1.0
 * Requires at least: 5.5
 * Requires PHP: 8.1
 * Author: Max Konstantinovski
 * Author URI: https://github.com/mkonstan
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * Copyright (C) 2026 Max Konstantinovski. Designed and built by Max Konstantinovski
 * (with Claude). This program is free software under the GNU General Public License
 * v2 or later; see the LICENSE file. Concept, design, and architecture by Max
 * Konstantinovski. Please retain this attribution in derivative works.
 *
 * Auth model (by design):
 *  - Admin mints a token in Settings > WP MCP. Token is shown ONCE.
 *  - Token is a 256-bit random value; only its SHA-256 hash is stored.
 *  - TWO TIMERS. An active window (6h by default, 12h at most - 30 days on a site whose
 *    environment type is 'local', see wpmcp_max_window()) after which the token
 *    goes DORMANT: refused, row kept, and an admin's Renew restarts the window without
 *    changing the token. A hard lifetime (30 days by default, 365 at most) after which
 *    it is DEAD: refused, not renewable, removed by the hourly cron. Both enforced on
 *    every request.
 *  - Scope: 'read' (default) or 'admin'. Read tokens are refused write tools.
 *  - Identity: every token is bound to a real WordPress user chosen at mint time.
 *    Requests run as that user, so WordPress capabilities bound reach and scope
 *    narrows on top. Delete the user and the token stops working.
 *  - The endpoint is dormant when no live token exists.
 *  - Token travels in an Authorization: Bearer header and nowhere else. The URL is
 *    constant and never carries it. Apache under CGI/FastCGI strips that header, and
 *    WordPress core restores it from REDIRECT_HTTP_AUTHORIZATION before this plugin sees
 *    the request - see wpmcp_extract_token().
 *  - HTTPS is required: over plaintext the endpoint answers 403 before it reads the
 *    token. It decides with is_ssl(), so the gate is only as strong as the proxy in
 *    front of WordPress - a proxy that FORWARDS the client's X-Forwarded-Proto rather
 *    than OVERWRITING it lets a client claim HTTPS over a plaintext connection. Your
 *    reverse proxy must set that header itself and never pass the client's value.
 *    WPMCP_ALLOW_INSECURE === true in wp-config.php is the local-dev override.
 *  - A browser Origin must be one of the site's own; absent Origin is allowed, which
 *    is what non-browser clients send. POST must be application/json, or 415.
 *  - A token is NOT bound to a client address. An Anthropic-hosted connector calls
 *    from a pool of egress addresses, so there is nothing single to hold it to; the
 *    address is recorded on every auth event and decides nothing.
 *  - Every token refusal is one byte-identical 401. Which of the six it was lives in
 *    the wpmcp_auth_event action, not on the wire.
 */

if (!defined('ABSPATH')) { exit; }

define('WPMCP_VER', '1.1.0');
define('WPMCP_TABLE', 'wpmcp_tokens');

/**
 * The plugin's own main file, for the one hook that has to name it (the Plugins screen
 * row). admin.php cannot work it out from its own __FILE__.
 */
define('WPMCP_PLUGIN_FILE', __FILE__);

/**
 * THE BUILD STAMP. Which build is this, and it must not lie.
 *
 * WHY IT EXISTS. Every dev zip cut from this repository reported `Version: 1.1.0`, so
 * the Plugins screen, initialize's serverInfo and site-info all said the same string for
 * Sprint 10's build and for Sprint 14b's. On 2026-09-16 an older zip was installed on a
 * live site, everything looked right, and about an hour went into diagnosing a "stale
 * file" that was really a stale ZIP. Only the FILENAME told the two apart, and a
 * filename is gone the moment the plugin is installed.
 *
 * GIT'S OWN SUBSTITUTION, NOT A NUMBER ANYBODY MAINTAINS. build.txt is marked
 * `export-subst` in .gitattributes, so `git archive` - which is how every zip of this
 * plugin is cut, dev or release - replaces its placeholders with the commit it is
 * archiving. Nothing has to be bumped, and a build cannot claim a commit it was not
 * built from.
 *
 * A CHECKOUT IS THE NORMAL CASE AND MUST NOT LOOK LIKE A BUILD. Running out of a git
 * working tree leaves the placeholders literal: both Local development sites junction
 * this tree into their plugins directory, and CI maps it into wp-env. There is then no
 * build to report and the plugin says exactly that - WPMCP_BUILD_UNKNOWN, the word
 * `source` - rather than falling back to the version, to a file's mtime, or to anything
 * else that would read as a build id and be wrong. NOTHING fails, warns or behaves
 * differently because the stamp is absent: an unstamped copy is a fact about the copy.
 *
 * THE VERSION IS NOT TOUCHED BY ANY OF THIS. WPMCP_VER, the `Version:` header and
 * serverInfo.version stay a clean semantic version on every build; the stamp sits
 * BESIDE the version and never inside it. docs/RELEASE.md carries the decision and what
 * it costs.
 */
define('WPMCP_BUILD_STAMP_FILE', 'build.txt');

/**
 * The word every surface prints when this copy is not a build.
 *
 * `source` and not `dev`, `unknown` or an empty string: it answers the question a reader
 * is actually asking - where did this code come from - it is a word no commit hash can
 * ever collide with, and it reads as a sentence in every place it appears ("build:
 * source"). An empty field would read as a bug; `unknown` says the plugin lost track of
 * something, which is not what happened.
 */
define('WPMCP_BUILD_UNKNOWN', 'source');

/**
 * One build.txt, parsed. Returns commit, short and date, each '' when it is not there.
 *
 * PURE, SO THE RULE CAN BE TESTED WITHOUT A SITE - and the rule is the whole point: a
 * value that still starts with a `$` is an unsubstituted git placeholder, which is the
 * one thing that must never be reported as a build.
 *
 * @param string $text the file's contents
 * @return array{commit:string,short:string,date:string}
 */
function wpmcp_build_stamp_parse($text) {
    $out = array('commit' => '', 'short' => '', 'date' => '');

    foreach ((array) preg_split('/\r\n|\r|\n/', (string) $text) as $line) {
        $line = trim((string) $line);

        if ($line === '' || $line[0] === '#') { continue; }

        $eq = strpos($line, '=');
        if ($eq === false) { continue; }

        $key = trim(substr($line, 0, $eq));
        $val = trim(substr($line, $eq + 1));

        // A key nothing reads is ignored rather than collected: the file travels in a
        // zip and whoever edits it is not necessarily us.
        if (!array_key_exists($key, $out) || $val === '') { continue; }

        // AN UNSUBSTITUTED PLACEHOLDER IS NOT A VALUE. This is a checkout.
        //
        // IT IS NOT REDUNDANT WITH wpmcp_build_id_valid(), although both refuse a `$`,
        // and the first version of this comment said it was. This loop is a FOLD over
        // lines and keys are last-wins, while the validity pass below runs once, on the
        // last value only. So the two rules differ exactly where a file carries the same
        // key twice:
        //
        //   short=abc1234        with this line: the placeholder is skipped and `short`
        //   short=$Format:%h$    stays abc1234. Without it, line 2 overwrites and the
        //                        validity pass then refuses the `$`, giving ''.
        //
        // What the guard buys is that a build whose stamp has had a placeholder line
        // appended to it still reports the build it was cut from, instead of falling
        // back to `source`. What it costs is the mirror image, which is why the order
        // matters: a checkout whose build.txt has a valid line ABOVE the placeholder
        // claims that build. Both take a hand edit of a file git wrote; neither is
        // reachable from `git archive`, which substitutes each placeholder in place and
        // never duplicates a key.
        if ($val[0] === '$') { continue; }

        $out[$key] = $val;
    }

    if (!wpmcp_build_id_valid($out['commit']))  { $out['commit'] = ''; }
    if (!wpmcp_build_id_valid($out['short']))   { $out['short']  = ''; }
    if (!wpmcp_build_date_valid($out['date']))  { $out['date']   = ''; }

    return $out;
}

/**
 * Could this be a build id at all?
 *
 * THE SHAPE IS CHECKED BECAUSE THE VALUE IS PRINTED. It reaches the settings page, the
 * Plugins screen, the initialize result and a tool result, so a sentence, a path, a
 * fragment of markup or a half-substituted placeholder must be dropped rather than
 * shown. A git short hash passes; so does a packager's own `r2026.09.18+4`.
 *
 * AND THE UNKNOWN WORD ITSELF IS REFUSED, which the pattern alone does not do.
 * `source` matches it perfectly well, so a hand-edited `short=source` - or a filter
 * that returns the word - used to produce the settings line "build `source` - the
 * commit this zip was built from", which is a sentence about a build that does not
 * exist. The word means "there is no build"; it can never BE one - in any case, so
 * `Source` and `SOURCE` are refused too (round 2 compared exactly and let them pass).
 */
function wpmcp_build_id_valid($id) {
    return is_string($id)
        && strcasecmp($id, WPMCP_BUILD_UNKNOWN) !== 0
        && preg_match('/^[0-9A-Za-z][0-9A-Za-z._+-]{0,39}$/', $id) === 1;
}

/** Could this be the build's date? An ISO 8601 instant, which is what %cI writes. */
function wpmcp_build_date_valid($date) {
    return is_string($date)
        && preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(Z|[+-][0-9]{2}:?[0-9]{2})$/', $date) === 1;
}

/**
 * The stamp this copy carries, read from disk ONCE per request.
 *
 * A MISSING FILE IS NOT AN ERROR. A copy assembled by hand, or unpacked by something
 * that dropped a text file, simply has no stamp - which is reported, not complained
 * about. There is no warning, no notice and no admin nag anywhere in this path.
 *
 * @return array{commit:string,short:string,date:string}
 */
function wpmcp_build_stamp() {
    static $stamp = null;

    if ($stamp === null) {
        $path  = plugin_dir_path(__FILE__) . WPMCP_BUILD_STAMP_FILE;
        $text  = is_readable($path) ? file_get_contents($path) : '';
        $stamp = wpmcp_build_stamp_parse($text === false ? '' : $text);
    }

    return $stamp;
}

/**
 * This build's short commit hash, or '' when this copy is not a build.
 *
 * THE FILTER IS SHAPE-CONSTRAINED, NOT NARROW-ONLY, and the first version of this
 * comment got that wrong. `wpmcp_build_id` exists for a packager that stamps builds some
 * other way - and for this project's own suite, which has no second copy of the plugin to
 * point at and must not rewrite a file that is junctioned into two live sites. What the
 * shape check buys is that nothing can put a sentence, a version number, a placeholder or
 * an empty string on the admin page or on the wire. What it does NOT buy is honesty: a
 * filter can make a checkout claim any build id it likes, and the gate's own G2 does
 * exactly that. That is the ordinary power of PHP running on the site - the filter is not
 * reachable from a request, an argument or a header - so it is documented rather than
 * defended against.
 */
function wpmcp_build_id() {
    $stamp = wpmcp_build_stamp();
    $id    = apply_filters('wpmcp_build_id', $stamp['short']);

    return wpmcp_build_id_valid($id) ? (string) $id : '';
}

/**
 * The build's commit date, or '' - shown beside the build on the settings page.
 *
 * NOT FILTERED, AND THE DATE FOLLOWS THE ID RATHER THAN GETTING A SEAM OF ITS OWN.
 * A second filter would be a second thing to keep in step for no caller that exists;
 * worse, without one a packager filtering the ID on a git-archived zip would have its own
 * build id printed beside GIT's date, for a different commit - two facts about two builds
 * on one line, which is precisely the class of lie this sprint exists to remove. So the
 * date is emitted only while the id still IS the file's, and a filtered id simply has no
 * date. If a packager ever wants to carry one, it writes build.txt; that is what the file
 * is for.
 */
function wpmcp_build_date() {
    return wpmcp_build_date_of(wpmcp_build_stamp(), wpmcp_build_id());
}

/**
 * The decision above, as a pure function, because the one that matters cannot be reached
 * otherwise: a checkout's build.txt carries no date at all, so a test of
 * wpmcp_build_date() on a checkout passes whatever the rule is. This takes the stamp and
 * the id it is being shown beside.
 *
 * @param array{commit:string,short:string,date:string} $stamp
 * @param string $id the id actually being reported, filter and all
 */
function wpmcp_build_date_of($stamp, $id) {
    return ($id !== '' && $id === $stamp['short']) ? $stamp['date'] : '';
}

/**
 * What every surface prints: the build, or the word for "this is not a build".
 *
 * ONE FUNCTION, FOUR SURFACES. The settings page, the Plugins screen row, serverInfo
 * and site-info all call this, which is what makes "they all say the same thing" a
 * property of the code rather than a coincidence four places have to keep.
 */
function wpmcp_build_label() {
    $id = wpmcp_build_id();

    return $id === '' ? WPMCP_BUILD_UNKNOWN : $id;
}

/**
 * Where a theme file's previous contents go before code-write or code-delete changes
 * it on disk.
 *
 * A TABLE AND NOT A FILE, and the reason is the web server. The code tools used to copy
 * the file they were about to change to a SIBLING file beside it, with a backup extension
 * appended - inside the active theme, which is inside the document root. The result is
 * neither `.css` nor `.php`, so nothing executes it and nothing in a default server
 * configuration refuses it: the URL returns the complete previous source of a theme file
 * to anybody who asks. A saved copy of PHP that reads credentials is then a public file.
 * The database is the one store WordPress never serves.
 *
 * That shape was also a backup of exactly ONE generation - the second write overwrote the
 * only copy there was - and it left files behind that nothing ever collected. A table
 * keeps a bounded history per path and is removed with the plugin.
 */
define('WPMCP_VERSIONS_TABLE', 'wpmcp_file_versions');
/**
 * The two caps, because a token carries two timers.
 *
 * WPMCP_MAX_WINDOW is the ACTIVE WINDOW: how long a token answers before it goes
 * dormant and has to be renewed by an admin. Short, because this is the window in which
 * a leaked token is useful.
 *
 * WPMCP_MAX_LIFETIME is the hard end. Past it the token is dead and Renew is not
 * offered; the only way on is a new token, which for a hosted connector also means one
 * edit of the connector.
 *
 * ONE CAP COULD NOT DO BOTH, which is why WPMCP_MAX_TTL is gone rather than renamed. It
 * had to be short so a leaked token died quickly AND long so a claude.ai connector was
 * not deleted and re-added twice a day - claude.ai cannot edit a connector's request
 * header after the connector exists, so a new token means a new connector. Splitting the
 * two lets the short number stay short.
 */
define('WPMCP_MAX_WINDOW', 12 * HOUR_IN_SECONDS);   // 43200s
define('WPMCP_MAX_LIFETIME', 365 * DAY_IN_SECONDS); // 31536000s

/**
 * The active-window cap on a LOCAL development site, and nowhere else (sprint 14b).
 *
 * WPMCP_MAX_WINDOW above keeps its meaning - the cap on every other site - and every
 * place that must stay at twelve hours whatever the site says still names it. The
 * places that follow the site (mint, renew, the mint form) ask wpmcp_max_window().
 *
 * WHY A LOCAL SITE MAY WAIT A MONTH. The twelve-hour ceiling makes a human look at a
 * connector token every day, which is right for a site the internet can reach. On a
 * developer's own machine it only makes Claude's MCP servers fail to connect every
 * morning. The lifetime still bounds the window, as everywhere.
 */
define('WPMCP_LOCAL_MAX_WINDOW', 30 * DAY_IN_SECONDS); // 2592000s

/** The shortest active window that can be minted. Below this, nothing could use it. */
define('WPMCP_MIN_WINDOW', 60);

/**
 * The defaults, as constants rather than as numbers typed into the mint form.
 *
 * They are read in two places that must agree: the form (admin.php), and the v2 -> v3
 * migration, which gives a pre-existing token the same hard lifetime a freshly minted one
 * would get. When those two disagreed, a migrated token was renewable for a period the
 * documentation did not describe.
 */
define('WPMCP_DEFAULT_WINDOW', 6 * HOUR_IN_SECONDS);
define('WPMCP_DEFAULT_LIFETIME', 30 * DAY_IN_SECONDS);

/**
 * Is this site a LOCAL development environment, for the purpose of the token window?
 *
 * EXACTLY 'local', and nothing else. 'development' and 'staging' are refused on purpose:
 * a development server can face the internet, and WordPress's own documentation lets a
 * host set either on a public machine. Setting WP_ENVIRONMENT_TYPE to 'local' on a public
 * server widens every token's window to thirty days there; SECURITY.md says so.
 *
 * THE FILTER CAN ONLY NARROW. It is asked only once the site already reports 'local', so
 * no filter can make any other site local. It exists for the test suite, which needs the
 * twelve-hour branch on a local site: core caches wp_get_environment_type() for the life
 * of the process, so the answer cannot be changed any other way. Nothing reads it from a
 * request.
 */
function wpmcp_is_local_environment() {
    if (wp_get_environment_type() !== 'local') {
        return false;
    }

    return (bool) apply_filters('wpmcp_local_environment', true);
}

/**
 * The longest active window this site allows: WPMCP_LOCAL_MAX_WINDOW on a local site,
 * WPMCP_MAX_WINDOW everywhere else. Mint, renew and the mint form all clamp to this.
 */
function wpmcp_max_window() {
    return wpmcp_is_local_environment() ? (int) WPMCP_LOCAL_MAX_WINDOW : (int) WPMCP_MAX_WINDOW;
}

// Token-table schema revision. Bump it whenever the CREATE TABLE below changes:
// wpmcp_maybe_upgrade() compares it against the wpmcp_db_ver option on every load and
// re-runs dbDelta plus the data migrations. The activation hook alone is not enough -
// it fires on activate, which never happens to a plugin that is updated in place.
//   1 = 0.3.5 and earlier: no user_id column, requests ran as the minting admin
//   2 = user-bound tokens: user_id column, backfilled from created_by
//   3 = two timers and no address binding. Adds active_until and window_secs (the
//       active window; see WPMCP_MAX_WINDOW), re-reads expires_at as the hard lifetime,
//       and DROPS the column the address binding lived in. dbDelta does the two adds;
//       it cannot drop, so the drop is an explicit ALTER in
//       wpmcp_migrate_drop_address_column(). Existing rows are backfilled by
//       wpmcp_migrate_token_lifetimes() so that they behave exactly as they did.
//   4 = file versions. Adds a SECOND table, WPMCP_VERSIONS_TABLE, which is where the
//       code tools now put a file's previous contents instead of writing a sibling file
//       the web server will serve. The upgrade also sweeps the active theme for the
//       sibling files the old code left behind - see wpmcp_migrate_sweep_stale_backups().
//   5 = the `theme` column on that table, which arrived after revision 4 had already been
//       stamped on the sites it was developed against. A column added to a revision that
//       is already recorded reaches nobody: wpmcp_maybe_upgrade() runs the installer only
//       when the stamp is BEHIND, so a site at 4 would keep a table without the column,
//       every INSERT would fail, and all three code writers would fail closed. A schema
//       change ships with a bump - the same rule the note above this list states, and the
//       reason the activation hook alone is not enough.
define('WPMCP_DB_VER', 5);
define('WPMCP_DB_VER_OPTION', 'wpmcp_db_ver');

/* ============================================================
 * Activation / upgrade: create the tokens table, migrate data
 * ========================================================== */
register_activation_hook(__FILE__, 'wpmcp_activate');
function wpmcp_activate() {
    wpmcp_install();

    if (!wp_next_scheduled('wpmcp_flush_expired')) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', 'wpmcp_flush_expired');
    }

    // The trace log's directory, and the one question worth asking about it: can the web
    // read it? See trace.php - .htaccess is Apache's, and most hosts are not Apache.
    wpmcp_trace_ensure_dir();
    delete_transient(WPMCP_TRACE_CHECK_TRANSIENT);
    wpmcp_trace_selfcheck();
}

/**
 * Run the installer whenever the recorded schema revision is behind the code's.
 * Hooked on plugins_loaded rather than admin_init because an MCP call is a REST
 * request: it can easily be the first thing that touches the site after an update,
 * and it must not run against a table that is missing user_id.
 *
 * PRIORITY 11, AND THE NUMBER IS LOAD-BEARING. The default auth-event listener is
 * attached by another plugins_loaded callback at priority 10 (see
 * wpmcp_attach_default_auth_log), and at the same priority registration order decides -
 * this file registers the upgrade first, so the upgrade used to run while nothing was
 * listening. Measured by the sprint-8 review on sample: a single front-end request that
 * performed the revision-4 upgrade and swept a planted backup file added ZERO lines to
 * the log, while a 401 in the very next request added one. The only report the operator
 * gets of what the sweep took was being thrown away on the path almost every upgrade
 * takes. Nothing in the plugin reads the database between priority 10 and 11.
 */
add_action('plugins_loaded', 'wpmcp_maybe_upgrade', 11);
function wpmcp_maybe_upgrade() {
    if ((int) get_option(WPMCP_DB_VER_OPTION, 1) >= WPMCP_DB_VER) { return; }
    wpmcp_install();
}

/**
 * Create or upgrade the tokens table, run the data migrations, record the revision.
 * Idempotent, so activation and upgrade can both call it. Returns true when the
 * revision was recorded, false when it deliberately was not - see the comment at the
 * bottom of the function.
 *
 * user_id is the identity a token runs as. created_by is who minted it and is kept
 * for audit only: the two are equal for a token minted for oneself and differ when an
 * admin mints one for somebody else.
 */
function wpmcp_install() {
    global $wpdb;
    $table   = $wpdb->prefix . WPMCP_TABLE;
    $charset = $wpdb->get_charset_collate();

    // dbDelta is whitespace-sensitive: two spaces after PRIMARY KEY, one field per line.
    $sql = "CREATE TABLE $table (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  token_hash char(64) NOT NULL,
  scope varchar(16) NOT NULL DEFAULT 'read',
  label varchar(191) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  active_until datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  window_secs int(10) unsigned NOT NULL DEFAULT 0,
  expires_at datetime NOT NULL,
  last_used_at datetime DEFAULT NULL,
  use_count bigint(20) unsigned NOT NULL DEFAULT 0,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY token_hash (token_hash),
  KEY expires_at (expires_at),
  KEY active_until (active_until)
) $charset;";

    // Revision 4's table. One `path` may hold many versions, and the only question ever
    // asked of it is "the versions of this path, newest first", so that pair is the key.
    //
    // `theme` IS THE STYLESHEET SLUG THAT WAS ACTIVE WHEN THE ROW WAS WRITTEN, and it is
    // not decoration: the code tools' jail is "the active theme", so `style.css` names a
    // different file on Monday and Tuesday if the theme changed in between. Without it
    // code-history listed the old theme's versions of `style.css` as though they were
    // this theme's, and code-restore wrote the old theme's bytes into the new theme's
    // file - recoverable, because the write is versioned first, but nothing said a word.
    // Every read is scoped by it and code-restore refuses a row that belongs elsewhere.
    //
    // `content` is a LONGBLOB and not a TEXT column on purpose: a theme file is bytes,
    // not characters. A BLOB column also makes $wpdb treat the whole table as binary, so
    // it never runs its invalid-text stripper over a value on the way in.
    //
    // The index takes a 191-character PREFIX of `path`, which is what WordPress core does
    // for every indexed varchar it owns (wp_posts.post_name is varchar(200) keyed on
    // post_name(191)). The full 255 characters of utf8mb4 is 1020 bytes, which is over
    // the 767-byte index limit of a table in the old COMPACT row format - a shape no
    // modern MySQL creates but plenty of older sites still carry.
    $versions = "CREATE TABLE " . $wpdb->prefix . WPMCP_VERSIONS_TABLE . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  theme varchar(191) NOT NULL DEFAULT '',
  path varchar(255) NOT NULL DEFAULT '',
  content longblob NOT NULL,
  size int(10) unsigned NOT NULL DEFAULT 0,
  sha256 char(64) NOT NULL DEFAULT '',
  reason varchar(16) NOT NULL DEFAULT '',
  saved_by bigint(20) unsigned NOT NULL DEFAULT 0,
  token_id bigint(20) unsigned DEFAULT NULL,
  saved_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY path_saved_at (path(191),saved_at)
) $charset;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
    dbDelta($versions);

    // Record the revision once the schema and the data are both actually there - see the
    // comment on the drop below for the one step that is deliberately not a precondition.
    //
    // dbDelta never throws and returns a report, not a status; the migration's own
    // documented failure return is a bare false. Stamping the version regardless
    // bricked the plugin in a way nothing could recover from: if the ALTER TABLE
    // failed (permissions, a hosting proxy, a concurrent request), every row would
    // lack user_id, (int) null would make get_userdata(0) false, every token would
    // 401 - and wpmcp_maybe_upgrade() would never run again to fix it. Fail closed
    // and RETRYABLE: leave the option behind so the next request tries once more.
    if (!wpmcp_token_column_exists('user_id')) { return false; }
    if (!wpmcp_token_column_exists('active_until')) { return false; }
    if (!wpmcp_token_column_exists('window_secs')) { return false; }
    if (wpmcp_migrate_token_user_ids() === false) { return false; }
    if (wpmcp_migrate_token_lifetimes() === false) { return false; }

    // THE VERSIONS TABLE IS A PRECONDITION, exactly like the three columns above and
    // unlike the cosmetic drop below. code-write and code-delete refuse to touch a file
    // they cannot version first, so a site whose second CREATE TABLE failed has working
    // read tools and dead code tools. Fail closed and retryable: leave the option behind
    // and the next request tries again.
    if (!wpmcp_versions_table_exists()) { return false; }

    // And the column that scopes every read of it. Same reasoning as the three token
    // columns above: without it `SELECT ... WHERE theme = %s` is an error, so the code
    // tools are dead rather than merely narrower. Fail closed and retryable.
    if (!wpmcp_versions_column_exists('theme')) { return false; }

    // Only now, with somewhere to put them, are the stale sibling backups collected. It
    // does not gate the stamp - see wpmcp_migrate_sweep_stale_backups() for why.
    wpmcp_migrate_sweep_stale_backups();

    // THE DROP IS THE ONE STEP THAT DOES NOT GATE THE STAMP, and the difference is
    // whether the plugin is CORRECT without it. The three columns and the two backfills
    // are preconditions: without them a token authenticates as nobody, or is dormant the
    // instant the site updated. A leftover column that nothing reads or writes costs a
    // few bytes a row and changes no behaviour at all.
    //
    // Gating on it was a real hazard on a host whose database user may ADD but not DROP -
    // managed hosts do hand out grants like that. The revision would never be recorded,
    // so wpmcp_maybe_upgrade() would run dbDelta plus three SHOW COLUMNS plus two UPDATEs
    // on EVERY REQUEST, forever, with the plugin otherwise working perfectly and nothing
    // anywhere saying why the site had got slower.
    //
    // So: stamp, and say so once per attempt in the log, because a silent unexplained
    // leftover is how the next person loses an afternoon.
    // The function logs its own failure; the column's name belongs inside it. See the
    // allow list in tests/unit/SurfaceSweepTest.php for why that matters.
    wpmcp_migrate_drop_address_column();

    update_option(WPMCP_DB_VER_OPTION, WPMCP_DB_VER);
    return true;
}

/** Is the file-versions table really there? The upgrade gate, not decoration. */
function wpmcp_versions_table_exists() {
    global $wpdb;
    $table = wpmcp_versions_table();
    $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
    return $found === $table;
}

/**
 * Does the file-versions table really have this column?
 *
 * A SECOND FUNCTION RATHER THAN A TABLE ARGUMENT on the token one, because the two
 * tables are checked for different reasons at different points and the token version's
 * name says which table it means at every call site.
 */
function wpmcp_versions_column_exists($column) {
    global $wpdb;
    $found = $wpdb->get_col($wpdb->prepare(
        'SHOW COLUMNS FROM ' . wpmcp_versions_table() . ' LIKE %s',
        $column
    ));
    return is_array($found) && $found !== array();
}

/** Does the tokens table really have this column? The upgrade gate, not decoration. */
function wpmcp_token_column_exists($column) {
    global $wpdb;
    $found = $wpdb->get_col($wpdb->prepare(
        'SHOW COLUMNS FROM ' . wpmcp_table() . ' LIKE %s',
        $column
    ));
    return is_array($found) && $found !== array();
}

/**
 * One-time backfill for tokens minted before identity existed. Those ran as the
 * minting admin, so created_by *is* their effective identity - copying it forward is
 * what keeps an already-issued token working exactly as it did before the upgrade.
 * Rows that already carry a user_id are untouched, which is what makes re-running
 * this safe (dbDelta and this migration run together, more than once in a site's life).
 *
 * Returns the number of rows changed, or false if the query failed.
 */
function wpmcp_migrate_token_user_ids() {
    global $wpdb;
    $table = wpmcp_table();
    return $wpdb->query("UPDATE $table SET user_id = created_by WHERE user_id = 0");
}

/**
 * Revision 3: drop the column the address pin lived in.
 *
 * WHY THIS IS NOT A LINE DELETED FROM THE CREATE TABLE ABOVE. dbDelta only ever ADDS and
 * WIDENS. It diffs the SQL it is handed against the live table and issues ALTER TABLE
 * ADD / CHANGE for what is missing or narrower; a column the table has and the SQL does
 * not is never mentioned and stays forever. Deleting the line removes the column from a
 * FRESH install and from nowhere else, so every existing site would keep carrying a
 * column nothing reads - and keep the value in it, ready for anything that started
 * reading it again.
 *
 * GUARDED BY A COLUMN-EXISTS CHECK, because this runs on every schema bump for the rest
 * of the plugin's life and DROP COLUMN on a column that is not there is an error, not a
 * no-op. MySQL gained `DROP COLUMN IF EXISTS` in 8.0.29 and MariaDB has had it longer;
 * neither is a floor this plugin can assume, so the check is done in PHP.
 *
 * Returns the query result, or true when there was nothing to do. false is the documented
 * failure, and it does NOT stop wpmcp_install() recording the revision: nothing reads or
 * writes this column, so a site that cannot drop it is fully upgraded and correct, and
 * refusing to stamp would put it in a dbDelta-per-request loop forever. The failure is
 * written to the log instead - see wpmcp_install().
 */
function wpmcp_migrate_drop_address_column() {
    global $wpdb;

    if (!wpmcp_token_column_exists('bound_ip')) { return true; }

    $dropped = $wpdb->query('ALTER TABLE ' . wpmcp_table() . ' DROP COLUMN bound_ip');

    // SAID ONCE, HERE, rather than by the caller - because the message has to name the
    // column, and this function is the one place in the plugin allowed to.
    if ($dropped === false) {
        error_log(
            'wp-mcp: could not drop the unused legacy bound_ip column from '
            . wpmcp_table() . '. The plugin is fully upgraded and works correctly; the'
            . ' column is read by nothing and can be dropped by hand. The usual cause is'
            . ' a database user with no DROP privilege.'
        );
    }

    return $dropped;
}

/**
 * Revision 4: collect the sibling backup files the old code tools left in the theme.
 *
 * Every version of this plugin up to 1.1.0 answered code-write by copying the file it
 * was about to overwrite to `<file>.bak` beside it, and code-delete by renaming the file
 * to `<file>.bak`. Those files are inside the active theme, which is inside the document
 * root, and their extension is neither `.php` nor anything a default server config
 * refuses - so each one is a URL that returns the complete source of a theme file to
 * anybody who guesses it. Deleting the code that writes them does nothing about the ones
 * already on disk, so the upgrade collects them: the bytes go into the versions table
 * under the ORIGINAL path with reason `sweep`, and the file on disk is removed.
 *
 * IT RUNS WHETHER OR NOT CODE EDITING IS ENABLED. The switch decides whether the tools
 * are offered; it has nothing to do with whether an earlier session already left files
 * behind, and the operator who turned the switch off is exactly the one who will never
 * find them.
 *
 * IDEMPOTENT because it is driven entirely by what is on disk: the second run walks the
 * same tree, finds nothing it is willing to take, and inserts nothing. wpmcp_install()
 * calls every migration on every schema bump for the rest of the plugin's life.
 *
 * IT TAKES ONLY WHAT THE CODE TOOLS COULD GIVE BACK, which is the correction the sprint-8
 * review earned. The walker finds every name ending `.bak`, case-insensitively, and the
 * old code moved all of them - including files this plugin never wrote. `README.BAK` was
 * stored under `README` and `config.bak` under `config`, and code-restore then refuses
 * both at the extension allow-list; the bytes were reachable only through SQL. So the
 * ORIGINAL path (the name without the suffix) must pass wpmcp_code_ext_ok(), and the file
 * must be inside the same 512KB cap code-write enforces. Anything else is LEFT WHERE IT
 * IS and named in the report: a file this plugin cannot hand back is not a file it should
 * be taking, and an operator who can see the name can move it themselves.
 *
 * (`functions.php.bak` is taken - `.php` is an allowed extension - and code-restore will
 * then refuse it, because `functions.php` is on the default denylist. That is deliberate
 * and the report says so: leaving the complete source of the theme's functions file
 * readable over HTTP is worse than storing it somewhere only an administrator can reach.)
 *
 * IT DOES NOT GATE THE SCHEMA STAMP, for the same reason the column drop does not: the
 * plugin is correct without it. A theme directory the web server owns and PHP may not
 * write is a real hosting shape, and a site that cannot remove the files is still fully
 * upgraded - refusing to stamp would put it in a dbDelta-per-request loop forever. What
 * it does instead is say so once, with the counts AND the paths, through
 * wpmcp_auth_event() - see the priority note on wpmcp_maybe_upgrade for why that line
 * reaches the log at all.
 *
 * SYMLINKS ARE NOT FOLLOWED, at either level: a linked directory is not descended into
 * and a linked file is not read or removed. The jail the code tools enforce on a caller's
 * path is the same rule, and a migration walking a tree unattended is not the place to
 * relax it.
 *
 * @return array{found:int,moved:int,skipped_extension:int,skipped_unreadable:int,
 *               skipped_too_big:int,skipped_undeletable:int,skipped_paths:list<string>,
 *               moved_paths:list<string>}
 */
function wpmcp_migrate_sweep_stale_backups() {
    $tally = array(
        'found'               => 0,
        'moved'               => 0,
        'skipped_extension'   => 0,
        'skipped_unreadable'  => 0,
        'skipped_too_big'     => 0,
        'skipped_undeletable' => 0,
        'skipped_paths'       => array(),
        'moved_paths'         => array(),
    );

    if (!function_exists('wpmcp_code_root')) { return $tally; }

    $root = wpmcp_code_root();
    if ($root === '' || !is_dir($root)) { return $tally; }

    // THE ONE PLACE IN THE PLUGIN THAT STILL SPELLS THE OLD SUFFIX, which is why it is
    // passed down rather than pulled from a constant: tests/unit/SurfaceSweepTest.php
    // greps the whole repository for it and exempts exactly this function.
    $suffix = '.bak';

    $stale = array();
    wpmcp_collect_stale_backups($root, $suffix, $stale, 0);

    $tally['found'] = count($stale);

    foreach ($stale as $abs) {
        $found = ltrim(str_replace('\\', '/', substr($abs, strlen($root))), '/');
        // The original path: the same name without the suffix the old code appended.
        $rel = substr($found, 0, -strlen($suffix));

        // The same allow-list code-write applies, on the ORIGINAL name. A file whose
        // original this plugin could never write is a file it could never give back.
        if (!wpmcp_code_ext_ok($rel)) {
            $tally['skipped_extension']++;
            $tally['skipped_paths'][] = $found . ' (not a text extension this plugin writes)';
            continue;
        }

        $content = @file_get_contents($abs);

        if ($content === false) {
            $tally['skipped_unreadable']++;
            $tally['skipped_paths'][] = $found . ' (could not be read)';
            continue;
        }

        if (strlen($content) > wpmcp_version_max_bytes()) {
            $tally['skipped_too_big']++;
            $tally['skipped_paths'][] = $found . ' (' . strlen($content) . ' bytes, over the 512KB cap)';
            continue;
        }

        // saved_by 0 and token_id NULL: nobody did this, the upgrade did.
        $id = wpmcp_file_version_save($rel, $content, 'sweep', 0, null);

        if ($id === false) {
            $tally['skipped_unreadable']++;
            $tally['skipped_paths'][] = $found . ' (could not be stored)';
            continue;
        }

        // NO OPCODE-CACHE INVALIDATION HERE, AND THAT IS NOT AN OVERSIGHT. What this
        // unlinks is `<name>.bak` - the ORIGINAL is untouched, and `.bak` is not an
        // extension PHP compiles, so there is nothing in the opcode cache under this
        // path to tell. wp_opcache_invalidate() would refuse it on exactly that test
        // (wp-admin/includes/file.php:2762-2765). The sweep writes no file at all; it
        // reads one, stores the bytes in the table and deletes it.
        if (!@unlink($abs)) {
            $tally['skipped_undeletable']++;
            $tally['skipped_paths'][] = $found . ' (stored as version ' . $id . ' but could not be deleted)';
            continue;
        }

        $tally['moved']++;
        $tally['moved_paths'][] = $rel . ' (version ' . $id . ')';
    }

    if ($tally['found'] > 0) {
        // THE PATHS, NOT JUST THE COUNTS. This is the only notice anybody gets that a
        // file left their theme directory, and "3 files were collected" does not let an
        // operator check whether one of them was theirs. Both lists are bounded because
        // the log line is a log line; the table has the rest.
        wpmcp_auth_event('stale_backup_sweep', array(
            'found'               => $tally['found'],
            'moved'               => $tally['moved'],
            'skipped_extension'   => $tally['skipped_extension'],
            'skipped_unreadable'  => $tally['skipped_unreadable'],
            'skipped_too_big'     => $tally['skipped_too_big'],
            'skipped_undeletable' => $tally['skipped_undeletable'],
            'moved_paths'         => wpmcp_sweep_path_sample($tally['moved_paths']),
            'skipped_paths'       => wpmcp_sweep_path_sample($tally['skipped_paths']),
        ));
    }

    return $tally;
}

/**
 * At most WPMCP_SWEEP_PATHS_LOGGED entries, with a count of what was left out.
 *
 * A theme with two hundred stale backups in it would otherwise put two hundred paths on
 * one log line, and wpmcp_format_auth_event() would truncate the whole thing mid-path -
 * which is worse than an honest "and 180 more", because a half-written path reads like a
 * complete one.
 */
function wpmcp_sweep_path_sample(array $paths) {
    if (count($paths) <= WPMCP_SWEEP_PATHS_LOGGED) { return $paths; }

    $shown = array_slice($paths, 0, WPMCP_SWEEP_PATHS_LOGGED);
    $shown[] = 'and ' . (count($paths) - WPMCP_SWEEP_PATHS_LOGGED) . ' more';

    return $shown;
}

/**
 * Append every file under $dir whose name ends in $suffix to $out. Depth-capped, and it
 * steps over every symlink it meets - see wpmcp_migrate_sweep_stale_backups().
 *
 * scandir() and not RecursiveDirectoryIterator: the iterator descends into symlinked
 * directories by default, and the guard against that is easier to read wrong than this
 * loop is to read.
 */
function wpmcp_collect_stale_backups($dir, $suffix, &$out, $depth) {
    if ($depth > 20) { return; }

    $names = @scandir($dir);
    if ($names === false) { return; }

    foreach ($names as $name) {
        if ($name === '.' || $name === '..') { continue; }

        $full = $dir . '/' . $name;

        if (is_link($full)) { continue; }

        if (is_dir($full)) {
            wpmcp_collect_stale_backups($full, $suffix, $out, $depth + 1);
            continue;
        }

        if (is_file($full)
            && strlen($name) > strlen($suffix)
            && strtolower(substr($name, -strlen($suffix))) === strtolower($suffix)) {
            $out[] = $full;
        }
    }
}

/**
 * Revision 3: give every pre-existing row the two timers.
 *
 * A v2 row had ONE expiry, and the upgrade has to answer two questions with it: when does
 * this token stop answering, and how long may it be renewed for.
 *
 *   active_until = the old expires_at
 *       The token stops answering at exactly the moment it always would have. Leaving the
 *       new column at its default would make every already-issued token dormant the
 *       instant the site updated - a plugin update that logged every connector out.
 *
 *   window_secs  = how long it was originally granted, capped at WPMCP_MAX_WINDOW
 *       So the first Renew gives the token the window it had. The cap is not decoration:
 *       a hand-extended row can carry a 90-day grant, and without it that row's first
 *       Renew would hand out a 90-day active window on a model whose whole point is a
 *       twelve-hour ceiling.
 *
 *       TWELVE HOURS EVEN ON A LOCAL SITE, and not wpmcp_max_window() (sprint 14b). Every
 *       row this touches was minted by a version that capped windows at twelve hours, so
 *       twelve is the most any of them was ever granted; a site's environment type says
 *       nothing about a grant made before this migration existed. And a migration's
 *       result should not depend on where it happened to run: a database upgraded on a
 *       local copy and pushed to a public site would otherwise carry thirty-day windows.
 *
 *   expires_at   = created_at + WPMCP_DEFAULT_LIFETIME
 *       So the row is RENEWABLE, which is the half that was wrong in the first cut of this
 *       function. Setting the hard lifetime to the old expiry as well made the row go
 *       straight to DEAD at that instant - wpmcp_token_state() tests the lifetime before
 *       the window - never dormant, with Renew a write that changed nothing, while the
 *       CHANGELOG, this docblock and the KB all promised the opposite. A token that
 *       existed before the upgrade now behaves like one minted after it: it goes dormant
 *       when it always would have, and an admin can renew it for thirty days from when it
 *       was minted.
 *
 * THE ONE GUARD on that last line: a row whose old expiry is ALREADY further out than
 * created_at + the default keeps its old expiry. Shortening it would kill a token that
 * works today, and would leave active_until past expires_at - a row that is inside its
 * window and past its end at the same time, which is not a state anything downstream can
 * describe.
 *
 * window_secs = 0 IS THE SENTINEL, and it is a sound one: wpmcp_mint() clamps the window
 * to at least WPMCP_MIN_WINDOW, so no row this plugin ever wrote can carry 0. That makes
 * the UPDATE idempotent - it runs on every schema bump for the rest of the plugin's life
 * and touches a row exactly once.
 *
 * THE ASSIGNMENTS ARE ORDER-DEPENDENT and the order is deliberate. MySQL evaluates a
 * multi-column UPDATE left to right and a later assignment sees the NEW value of an
 * earlier column, so expires_at is written last and the two lines above it read the old
 * one. Swapping them silently changes every migrated row.
 *
 * COALESCE on both date expressions, because TIMESTAMPDIFF and DATE_ADD both answer NULL
 * for a zero created_at, and a NULL into a NOT NULL column under a strict SQL mode fails
 * the whole migration - which parks the plugin in wpmcp_install()'s retry path forever.
 * No row this plugin writes has a zero created_at; a hand-edited one might.
 *
 * GREATEST(..., WPMCP_MIN_WINDOW) on the computed window, because a row whose created_at
 * and expires_at are equal would otherwise get a zero-length window - which is both the
 * sentinel and a token that is dormant the instant it is renewed.
 *
 * Returns the number of rows changed, or false if the query failed.
 */
function wpmcp_migrate_token_lifetimes() {
    global $wpdb;
    $table = wpmcp_table();

    $min      = (int) WPMCP_MIN_WINDOW;
    $max      = (int) WPMCP_MAX_WINDOW;
    $lifetime = (int) WPMCP_DEFAULT_LIFETIME;

    return $wpdb->query(
        "UPDATE $table SET"
        . ' active_until = expires_at,'
        . " window_secs = LEAST(GREATEST(COALESCE(TIMESTAMPDIFF(SECOND, created_at, expires_at), {$min}), {$min}), {$max}),"
        . " expires_at = GREATEST(COALESCE(DATE_ADD(created_at, INTERVAL {$lifetime} SECOND), expires_at), expires_at)"
        . ' WHERE window_secs = 0'
    );
}

register_deactivation_hook(__FILE__, function () {
    $ts = wp_next_scheduled('wpmcp_flush_expired');
    if ($ts) { wp_unschedule_event($ts, 'wpmcp_flush_expired'); }
});

/* ============================================================
 * Token model
 * ========================================================== */
function wpmcp_table() {
    global $wpdb;
    return $wpdb->prefix . WPMCP_TABLE;
}

/* ============================================================
 * File version store - the code tools' undo, in the one place WordPress never serves
 * ========================================================== */
function wpmcp_versions_table() {
    global $wpdb;
    return $wpdb->prefix . WPMCP_VERSIONS_TABLE;
}

/**
 * How many paths the sweep's one log line names before it says "and N more". The line
 * has to be readable; the table holds everything it took.
 */
define('WPMCP_SWEEP_PATHS_LOGGED', 25);

/** The largest file the store will take: the same 512KB cap code-write enforces. */
function wpmcp_version_max_bytes() {
    return 524288;
}

/**
 * How many versions are kept per path. Filterable; 20 by default.
 *
 * A CAP AND NOT A RETENTION PERIOD, because the thing being bounded is a table that
 * grows by up to half a megabyte per write and is never read by anything but a human
 * asking for an undo. Twenty is deep enough to walk back through a session of edits and
 * shallow enough that a runaway agent cannot fill a database with them.
 *
 * A filter returning something useless - zero, a negative, a non-number - is ignored
 * rather than obeyed, because "keep nothing" turns every write into an unrecoverable one
 * and is far more likely to be a mistake than a decision.
 */
function wpmcp_file_versions_keep() {
    $keep = (int) apply_filters('wpmcp_file_versions_keep', 20);
    return $keep > 0 ? $keep : 20;
}

/**
 * Store $content as the version of $rel that existed until now. Returns the new row's
 * id, or false if it could not be stored.
 *
 * CALLED BEFORE THE DISK CHANGES, NEVER AFTER, and its false is a refusal the caller has
 * to honour: a write that cannot be undone must not happen. Every caller in tools.php
 * returns a tool error on false rather than carrying on.
 *
 * WRITTEN THROUGH UNHEX() RATHER THAN $wpdb->insert(). The value is arbitrary bytes, and
 * every byte of it would otherwise be escaped into a SQL string literal that MySQL then
 * parses under the connection's utf8mb4 charset. Bytes that are not valid UTF-8 - a
 * latin1 theme file, a stray 0x80 in a comment - make that literal invalid at the point
 * the server reads the statement, and what comes back out is not what went in. A hex
 * literal has no charset and cannot be misread; the cost is that the statement is twice
 * the size of the file, which at half a megabyte is nothing.
 *
 * @param string   $rel      path relative to the code root, canonical (see
 *                           wpmcp_code_resolve(): one file has one spelling)
 * @param string   $content  the bytes as they are on disk right now
 * @param string   $reason   write | delete | restore | sweep
 * @param int|null $userId   who caused it; null = the current user, 0 = nobody (the sweep)
 * @param int|null $tokenId  the session's token row id; null when there is no session
 * @return int|false
 */
function wpmcp_file_version_save($rel, $content, $reason, $userId = null, $tokenId = null) {
    global $wpdb;

    $content = (string) $content;
    if (strlen($content) > wpmcp_version_max_bytes()) { return false; }

    // The theme this path is relative to, recorded at save time. Read once and used for
    // both the insert and the prune, so a theme switch in between cannot make the row go
    // into one group and be counted against another.
    $theme = (string) get_stylesheet();

    if ($userId === null) { $userId = get_current_user_id(); }
    if ($tokenId === null) {
        $session = isset($GLOBALS['wpmcp_session']) ? $GLOBALS['wpmcp_session'] : null;
        $tokenId = $session ? (int) $session->id : null;
    }

    // token_id is a LITERAL `NULL` rather than a placeholder when there is no session,
    // because $wpdb->prepare() turns a null argument into an empty string whatever the
    // placeholder says - and '' in a bigint column is 0, which is a real token's id.
    // "No token was involved" and "token 0" must not be the same row.
    $sql = 'INSERT INTO ' . wpmcp_versions_table()
        . ' (theme, path, content, size, sha256, reason, saved_by, token_id, saved_at)'
        . ' VALUES (%s, %s, UNHEX(%s), %d, %s, %s, %d, '
        . ($tokenId === null ? 'NULL' : '%d') . ', %s)';

    $args = array(
        $theme,
        (string) $rel,
        bin2hex($content),
        strlen($content),
        hash('sha256', $content),
        (string) $reason,
        (int) $userId,
    );

    if ($tokenId !== null) { $args[] = (int) $tokenId; }

    $args[] = gmdate('Y-m-d H:i:s');

    $ok = $wpdb->query($wpdb->prepare($sql, $args));

    if ($ok === false) { return false; }

    $id = (int) $wpdb->insert_id;
    wpmcp_file_versions_prune($rel, $theme);

    return $id;
}

/**
 * Drop everything past the newest wpmcp_file_versions_keep() rows for one path in one
 * theme.
 *
 * SCOPED BY THEME as well as path, and it has to be: the cap is per file, and `style.css`
 * in two themes is two files. Pruning on path alone would let a busy theme delete a
 * dormant one's only copy of a same-named file.
 *
 * ORDERED BY saved_at AND THEN id, both here and in code-history, because saved_at has
 * one-second resolution and a agent editing a file writes several versions inside one
 * second. Without the id tiebreak "the oldest" is whichever row MySQL felt like
 * returning last, so the wrong version could be the one deleted - and the listing and
 * the pruner could disagree about which rows exist.
 */
function wpmcp_file_versions_prune($rel, $theme) {
    global $wpdb;

    $doomed = $wpdb->get_col($wpdb->prepare(
        'SELECT id FROM ' . wpmcp_versions_table()
        . ' WHERE path = %s AND theme = %s ORDER BY saved_at DESC, id DESC LIMIT %d, 4294967295',
        (string) $rel,
        (string) $theme,
        wpmcp_file_versions_keep()
    ));

    if (!is_array($doomed) || $doomed === array()) { return 0; }

    $ids = implode(',', array_map('intval', $doomed));

    // Interpolated, and safe: every element has been through intval().
    return (int) $wpdb->query('DELETE FROM ' . wpmcp_versions_table() . " WHERE id IN ({$ids})"); // phpcs:ignore
}

/** One version row by id, or null. `content` comes back as the raw bytes. */
function wpmcp_file_version_get($id) {
    global $wpdb;

    $row = $wpdb->get_row($wpdb->prepare(
        'SELECT id, theme, path, content, size, sha256, reason, saved_by, token_id, saved_at'
        . ' FROM ' . wpmcp_versions_table() . ' WHERE id = %d',
        (int) $id
    ));

    return $row ? $row : null;
}

/**
 * Every stored version of one path IN THE ACTIVE THEME, newest first. Without `content`:
 * a listing is a table of contents, and the bodies are up to half a megabyte each.
 *
 * The theme scope is the point of the column: `style.css` is a different file in a
 * different theme, and a listing that mixed them would offer an agent an undo that
 * silently replaces this theme's file with another theme's bytes.
 *
 * @return array list of row objects
 */
function wpmcp_file_versions_for($rel, $limit = 50) {
    global $wpdb;

    $rows = $wpdb->get_results($wpdb->prepare(
        'SELECT id, theme, path, size, sha256, reason, saved_by, token_id, saved_at'
        . ' FROM ' . wpmcp_versions_table()
        . ' WHERE path = %s AND theme = %s ORDER BY saved_at DESC, id DESC LIMIT %d',
        (string) $rel,
        (string) get_stylesheet(),
        (int) $limit
    ));

    return is_array($rows) ? $rows : array();
}

function wpmcp_hash($raw) {
    // High-entropy token (256-bit) -> a fast cryptographic hash is appropriate.
    return hash('sha256', $raw);
}

/**
 * Best-effort client IP. On a direct-served site REMOTE_ADDR is the real client.
 * Behind a trusted proxy/CDN you must read a forwarded header instead - filterable
 * so prod can opt in deliberately (don't trust blindly).
 */
function wpmcp_client_ip() {
    $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
    return (string) apply_filters('wpmcp_client_ip', $ip);
}

/* ============================================================
 * Auth events - the one place an operator can see the auth layer
 * ========================================================== */

/**
 * The event types this plugin fires, and what each one means.
 *
 *   mint              a token was created              (token_id, user_id, created_by, scope, window, lifetime)
 *   revoke            a token row was deleted          (token_id, user_id)
 *   renew             a token's window was restarted   (token_id, user_id, actor, window)
 *   validate_fail     a token was refused              (reason, token_id?, user_id?)
 *   scope_deny        a read token asked for a write   (token_id, user_id, tool, scope)
 *   origin_deny       the Origin header was not ours   (origin)
 *   insecure_deny     the request was not over HTTPS   (-)
 *   content_type_deny the POST was not application/json (content_type)
 *   body_too_large    CONTENT_LENGTH over the cap      (length)
 *   registry_reject   a filter-added tool was refused  (tool, reason)
 *   stale_backup_sweep a schema upgrade collected what an older version left beside
 *                     theme files                   (found, moved, skipped_*, *_paths)
 *   sql_select        sql-select ran a statement     (token_id, user_id, row_count,
 *                                                    truncated, elapsed_ms, sql)
 *
 * `sql` on a sql_select is the FIRST 200 CHARACTERS of the statement and never more. The
 * whole of it can carry a value out of somebody's database, and this log is not the trace
 * log; the full statement reaches the trace log on the one path where it is worth having,
 * which is a statement the server refused.
 *
 * Every context also carries `ip` - which is there to be READ, not enforced: nothing in
 * this plugin decides anything from the caller's address. `reason` on validate_fail is
 * the INTERNAL reason - missing, malformed, not_found, user_missing, dormant, expired -
 * which is deliberately the only place it exists: the wire answer to all six is one
 * byte-identical 401, so the log is where an operator finds out which it was, and in
 * particular whether the answer is Renew (dormant) or a new token (expired).
 *
 * NEVER IN A CONTEXT: the raw token or its hash. Tokens are identified by their ROW
 * ID, which is already visible in the admin table and is useless to anybody who
 * obtains a log. The redaction in the default listener is a second line of defence
 * against a third-party listener being careless, not the first.
 */
function wpmcp_auth_event($type, $context = array()) {
    $context = (array) $context;
    if (!isset($context['ip'])) { $context['ip'] = wpmcp_client_ip(); }
    /**
     * Fires on every authentication and authorization decision worth seeing.
     *
     * @param string $type    one of the types listed above.
     * @param array  $context event-specific detail; see above. Never token material.
     */
    do_action('wpmcp_auth_event', (string) $type, $context);
}

/**
 * Context keys that are NEVER written to the log, by name.
 *
 * By KEY, not by substring of the value. A redactor that greps values for something
 * token-shaped is a guess that fails quietly in both directions: it misses a short
 * secret and it mangles a post title that happens to be 64 hex digits. A fixed key
 * list is checkable by reading it.
 *
 * Filterable so a site adding its own listener context can extend the list; the
 * built-in names cannot be removed, because the point of the list is that it holds.
 */
function wpmcp_auth_event_redacted_keys() {
    $fixed = array(
        'token', 'raw', 'raw_token', 'token_hash', 'hash', 'secret', 'password',
        'pass', 'pwd', 'authorization', 'bearer', 'nonce', 'cookie', 'session',
    );
    $extra = array_map('strtolower', array_map('strval', (array) apply_filters('wpmcp_auth_event_redacted_keys', array())));
    return array_values(array_unique(array_merge($fixed, $extra)));
}

/** Longest a single logged value may be. Past this it is cut and marked with an ellipsis. */
define('WPMCP_LOG_VALUE_MAX', 200);

/**
 * Redact by key at EVERY depth, and bound every string.
 *
 * Depth matters because a third-party listener's context is not flat. The plugin's own
 * contexts are - row id, user id, scope, window, lifetime, reason, ip, origin,
 * content_type, tool - but a site that adds its own listener and passes
 * `['request' => ['authorization' => ...]]` would have had that written out verbatim,
 * because the old formatter json_encoded a nested array without looking inside it.
 *
 * Bounding matters because three of those keys are ATTACKER-CONTROLLED: `origin` and
 * `content_type` are request headers, and `tool` in a scope_deny is params.name. A
 * 100 KB Origin header became a 100 KB log line, once per request, for free.
 */
function wpmcp_redact_for_log($value, $depth = 0) {
    if (is_array($value)) {
        if ($depth >= 6) { return '[too deep]'; }

        $redact = wpmcp_auth_event_redacted_keys();
        $out    = array();

        foreach ($value as $key => $inner) {
            $out[$key] = in_array(strtolower((string) $key), $redact, true)
                ? '[redacted]'
                : wpmcp_redact_for_log($inner, $depth + 1);
        }

        return $out;
    }

    if (is_string($value) && strlen($value) > WPMCP_LOG_VALUE_MAX) {
        return substr($value, 0, WPMCP_LOG_VALUE_MAX) . '...';
    }

    if (is_object($value)) { return '[' . get_class($value) . ']'; }

    return $value;
}

/**
 * One event as one log line, in a shape a grep or a log shipper can rely on:
 *
 *   wp-mcp auth <type> key=value key=value ...
 *
 * Keys are sorted, so two occurrences of the same event produce the same field order.
 * Newlines are flattened, so one event is always one line. An empty value is written as
 * "" rather than nothing, because `content_type= ip=127.0.0.1` reads to a key=value
 * parser as content_type holding "ip=127.0.0.1".
 */
function wpmcp_format_auth_event($type, $context) {
    $redact  = wpmcp_auth_event_redacted_keys();
    $context = (array) $context;
    ksort($context);
    $parts = array();

    foreach ($context as $key => $value) {
        $key = (string) $key;

        if (in_array(strtolower($key), $redact, true)) {
            $parts[] = $key . '=[redacted]';
            continue;
        }

        $value = wpmcp_redact_for_log($value);

        if (is_bool($value)) {
            $value = $value ? 'true' : 'false';
        } elseif ($value === null) {
            $value = 'null';
        } elseif (!is_scalar($value)) {
            $value = (string) wp_json_encode($value);
            if (strlen($value) > WPMCP_LOG_VALUE_MAX) {
                $value = substr($value, 0, WPMCP_LOG_VALUE_MAX) . '...';
            }
        }

        $value = str_replace(array("\r", "\n"), ' ', (string) $value);

        $parts[] = $key . '=' . ($value === '' ? '""' : $value);
    }

    return 'wp-mcp auth ' . (string) $type . ($parts ? ' ' . implode(' ', $parts) : '');
}

/** The default listener. One error_log line per event. */
function wpmcp_log_auth_event($type, $context) {
    error_log(wpmcp_format_auth_event($type, $context));
}

/**
 * Attach the default listener.
 *
 * On plugins_loaded rather than at file scope so a site CAN take it off:
 *
 *     add_action('plugins_loaded', function () {
 *         remove_action('wpmcp_auth_event', 'wpmcp_log_auth_event');
 *     }, 11);
 *
 * A listener added at file scope would be attached before any other plugin's code
 * runs at all, and a mu-plugin wanting it gone would have to know to hook later
 * anyway. This way there is one documented place and one documented priority.
 */
add_action('plugins_loaded', 'wpmcp_attach_default_auth_log');
function wpmcp_attach_default_auth_log() {
    // Priority 10 here and 11 on wpmcp_maybe_upgrade is what puts the schema upgrade's
    // own events - the stale-backup sweep above all - inside earshot of this listener.
    add_action('wpmcp_auth_event', 'wpmcp_log_auth_event', 10, 2);
}

/**
 * Mint a token. Returns array('raw'=>..., 'id'=>...) or WP_Error.
 *
 * TWO TIMERS, BOTH IN SECONDS.
 *
 *   $window_secs    how long the token answers before going DORMANT. Clamped to
 *                   [WPMCP_MIN_WINDOW, wpmcp_max_window()] - twelve hours, or thirty
 *                   days on a local site. A dormant token is refused
 *                   like any other bad credential and its row is kept, so an admin can
 *                   press Renew and the client never has to be touched.
 *   $lifetime_secs  the hard end. Clamped to [$window_secs, WPMCP_MAX_LIFETIME]. Past
 *                   it the token is DEAD and only a new mint helps.
 *
 * THE LIFETIME'S LOWER BOUND IS THE WINDOW, not a fixed minimum. A lifetime shorter than
 * the window would put active_until past expires_at - a row that is inside its window
 * and past its end at the same time, which is not a state anything downstream can
 * describe. Clamping up is the only answer that keeps the two timers consistent.
 *
 * $user_id is the WordPress user the token authenticates as. 0 means the current
 * user, which is both the historical behaviour and the right default for an admin
 * minting for themselves. The user must exist: a token bound to nobody would run as
 * nobody, every capability check inside the tools would fail, and the failure would
 * surface as a confusing empty result instead of a refusal. Refuse at mint instead.
 */
function wpmcp_mint($scope, $label, $window_secs, $lifetime_secs, $user_id = 0) {
    global $wpdb;
    $scope       = ($scope === 'admin') ? 'admin' : 'read';
    $window_secs = max(WPMCP_MIN_WINDOW, min(wpmcp_max_window(), (int) $window_secs));
    $lifetime_secs = max($window_secs, min(WPMCP_MAX_LIFETIME, (int) $lifetime_secs));
    $user_id     = (int) $user_id;
    if ($user_id === 0) { $user_id = (int) get_current_user_id(); }
    if (!get_userdata($user_id)) {
        return new WP_Error('wpmcp_no_such_user', 'No WordPress user with ID ' . $user_id . '.');
    }
    // Minting FOR SOMEBODY ELSE needs authority over that someone. On single-site an
    // administrator holds edit_user over everyone, so this is lateral. On multisite it
    // is not: get_userdata() resolves network-wide, so without this a site
    // administrator could mint an admin-scope token carrying a super admin's ID and
    // current_user_can() would then answer true for every capability on that site.
    // edit_user maps to do_not_allow for a super admin target unless the actor is one.
    if ($user_id !== (int) get_current_user_id() && !current_user_can('edit_user', $user_id)) {
        return new WP_Error(
            'wpmcp_not_allowed',
            'You are not allowed to mint a token for user ' . $user_id . '.'
        );
    }
    $raw = bin2hex(random_bytes(32)); // 256-bit
    $now = current_time('mysql', true); // UTC

    $ok = $wpdb->insert(wpmcp_table(), array(
        'token_hash'   => wpmcp_hash($raw),
        'scope'        => $scope,
        'label'        => sanitize_text_field((string) $label),
        'created_at'   => $now,
        'active_until' => gmdate('Y-m-d H:i:s', time() + $window_secs),
        'window_secs'  => $window_secs,
        'expires_at'   => gmdate('Y-m-d H:i:s', time() + $lifetime_secs),
        'use_count'    => 0,
        'created_by'   => (int) get_current_user_id(),
        'user_id'      => $user_id,
    ), array('%s','%s','%s','%s','%s','%d','%s','%d','%d','%d'));

    if (!$ok) { return new WP_Error('wpmcp_insert_failed', 'Could not store token.'); }

    $id = (int) $wpdb->insert_id;

    wpmcp_auth_event('mint', array(
        'token_id'   => $id,
        'user_id'    => $user_id,
        'created_by' => (int) get_current_user_id(),
        'scope'      => $scope,
        'window'     => $window_secs,
        'lifetime'   => $lifetime_secs,
    ));

    return array('raw' => $raw, 'id' => $id);
}

/**
 * When this row's active window actually ends ON THIS SITE, as a UNIX time.
 *
 * THE CAP HAS TO HOLD WHERE A TOKEN IS USED, not only where one is written (sprint 14b
 * round 2, review B1). Mint and renew clamp to wpmcp_max_window(), so no row minted or
 * renewed HERE can carry more than this site's ceiling - but a row does not have to have
 * been written here. Copying a local database to staging or production, token table and
 * all, is the ordinary WordPress workflow, and every 30-day row in it would otherwise go
 * on answering there for the rest of those thirty days: the number SECURITY.md calls the
 * bound on a leaked token would be one this site never agreed to.
 *
 * So the window is read as min(window_secs, wpmcp_max_window()) counted from the moment
 * it last started - active_until minus the grant - and whatever a row was granted beyond
 * this site's ceiling is subtracted from its end. On a local site nothing is subtracted,
 * and a row inside the ceiling is untouched everywhere.
 *
 * NOTHING IS DELETED OR REVOKED. Such a row is simply DORMANT, which is the state the
 * model already has for "the window closed": the operator presses Renew and gets a
 * twelve-hour window on this site, without the token changing.
 *
 * Renew stores the clamp it applies (see wpmcp_renew), so a row it wrote is consistent:
 * without that, a twelve-hour active_until beside a thirty-day grant would read here as
 * dormant the instant Renew wrote it.
 */
function wpmcp_effective_active_until($row) {
    $until  = strtotime($row->active_until . ' UTC');
    $window = (int) $row->window_secs;
    $cap    = wpmcp_max_window();

    return $window > $cap ? $until - ($window - $cap) : $until;
}

/**
 * Which of the three states a token row is in, right now.
 *
 *   active   inside its window - the only state that answers
 *   dormant  the window has closed, the lifetime has not. Refused, row kept, renewable.
 *   dead     past its lifetime. Refused, not renewable, removed by the hourly cron.
 *
 * BOTH BOUNDARIES REFUSE. A window that ended exactly now is dormant and a lifetime that
 * ended exactly now is dead, so there is no instant in which a token is neither one
 * thing nor the other.
 *
 * A row whose lifetime has passed is DEAD whatever its window says: renewing a window
 * cannot reach past the hard end, so a row in that shape is a bug elsewhere and dead is
 * the safe reading of it.
 *
 * THE WINDOW IS THIS SITE'S, not the column's: wpmcp_effective_active_until() shortens a
 * row granted more than this site's ceiling allows, which is what makes a copied database
 * behave here the way a token minted here would.
 */
function wpmcp_token_state($row) {
    $now = time();

    if (strtotime($row->expires_at . ' UTC') <= $now) { return 'dead'; }
    if (wpmcp_effective_active_until($row) <= $now)   { return 'dormant'; }

    return 'active';
}

/**
 * What to SHOW an operator about a row: its timer state, or the fact that its owner is
 * gone.
 *
 * SEPARATE FROM wpmcp_token_state(), which is about the two timers and nothing else and
 * is called on every request. This one asks WordPress a question - does this user still
 * exist - and is for the admin table and for wpmcp_renew(), both of which happen by hand.
 *
 * WHY IT EXISTS. A token whose bound user has been deleted is refused on every request
 * with reason=user_missing (see wpmcp_validate), and yet its timers can say `active`
 * indefinitely. The admin table showed exactly that: `active`, a Renew button, and a
 * green "Token renewed - the client needs no edit" notice for a token the endpoint
 * refuses every time. A status column that says a token is fine when it is not is worse
 * than no status column.
 *
 * DEAD WINS over owner_missing, because a dead row is dead either way and the cron is
 * about to remove it; there is nothing an operator can do about either fact.
 */
function wpmcp_token_status($row) {
    $state = wpmcp_token_state($row);

    if ($state === 'dead') { return 'dead'; }
    if (!get_userdata((int) $row->user_id)) { return 'owner_missing'; }

    return $state;
}

/**
 * Validate a raw token against the current request.
 *
 * Returns the token row (object) on success, or a WP_Error whose code is the INTERNAL
 * reason: missing | malformed | not_found | user_missing | dormant | expired.
 *
 * dormant AND expired ARE THE SAME ANSWER TO THE CALLER and different answers to the
 * operator. A dormant token's window has closed and an admin can press Renew, which
 * restarts it without changing the token, so the client never has to be touched; an
 * expired one is past its hard lifetime and needs a new mint. Telling those two apart on
 * the wire would tell a caller holding a stolen token whether it is worth keeping.
 *
 * THE CALLER MUST NOT PUT THAT REASON ON THE WIRE. All six are one byte-identical 401
 * (see wpmcp_unauthorized() in endpoint.php) and the reason survives only in the
 * validate_fail auth event, which is fired here so that every refusal path fires it -
 * including the deleted-user one, which the Sprint 1 review asked to be sure of.
 *
 * Why one answer: each distinguishable refusal is an oracle. "expired" confirms the
 * token was real and tells an attacker to look for a newer one; a 403 instead of a 401
 * confirms it by the status code alone. None of that is information a caller holding a
 * bad token has any claim to.
 *
 * $ip IS FOR THE RECORD, NOT FOR A DECISION. It is passed in so that every refusal fired
 * from here says where it came from, and no branch below compares it to anything. A
 * token used to be locked to the address of its first tool call; measured on a public
 * test site on 2026-09-13, an Anthropic-hosted connector (claude.ai, Claude Desktop)
 * calls from a pool of egress addresses - 160.79.106.164, .185, .186 and .187 within one
 * minute - so that lock refused the whole session after the first call. There is no
 * single address to hold a token to.
 *
 * Side effects on success: the use counters, and nothing else.
 */
function wpmcp_validate($raw, $ip) {
    global $wpdb;

    if (!is_string($raw) || $raw === '') {
        wpmcp_auth_event('validate_fail', array('reason' => 'missing', 'ip' => $ip));
        return new WP_Error('missing', 'Invalid token.');
    }
    if (strlen($raw) !== 64 || !ctype_xdigit($raw)) {
        wpmcp_auth_event('validate_fail', array('reason' => 'malformed', 'ip' => $ip));
        return new WP_Error('malformed', 'Invalid token.');
    }

    $hash = wpmcp_hash($raw);
    $row  = $wpdb->get_row($wpdb->prepare(
        'SELECT * FROM ' . wpmcp_table() . ' WHERE token_hash = %s', $hash
    ));
    if (!$row) {
        wpmcp_auth_event('validate_fail', array('reason' => 'not_found', 'ip' => $ip));
        return new WP_Error('not_found', 'Token not found.');
    }

    // Identity BEFORE any side effect, and before the timers, so a token whose user was
    // deleted cannot bump use_count and cannot show as recently used in the admin
    // table: activity a refused request has no business recording.
    if (!get_userdata((int) $row->user_id)) {
        wpmcp_auth_event('validate_fail', array(
            'reason'   => 'user_missing',
            'token_id' => (int) $row->id,
            'user_id'  => (int) $row->user_id,
            'ip'       => $ip,
        ));
        return new WP_Error('user_missing', 'Token not found.');
    }

    // Both timers, enforced on use. The cron flush is only housekeeping.
    //
    // NOTHING IS DELETED HERE, and that changed in Sprint 7. A refused token's row used
    // to be deleted on the spot, which made Renew impossible: by the time an admin saw
    // the 401 there was nothing left to renew, and a hosted connector had to be deleted
    // and re-added rather than reactivated. Dead rows are removed by the hourly cron
    // instead, which is where database housekeeping belongs.
    $state = wpmcp_token_state($row);

    if ($state !== 'active') {
        $reason = ($state === 'dormant') ? 'dormant' : 'expired';

        wpmcp_auth_event('validate_fail', array(
            'reason'   => $reason,
            'token_id' => (int) $row->id,
            'user_id'  => (int) $row->user_id,
            'ip'       => $ip,
        ));

        return new WP_Error(
            $reason,
            $reason === 'dormant'
                ? 'Token is dormant - press Renew in Settings > WP MCP.'
                : 'Token has reached the end of its lifetime - mint a new one.'
        );
    }

    $wpdb->update(
        wpmcp_table(),
        array('last_used_at' => current_time('mysql', true), 'use_count' => (int) $row->use_count + 1),
        array('id' => $row->id),
        array('%s','%d'), array('%d')
    );
    return $row;
}

/**
 * Restart a token's active window. Returns the new active_until (UTC string) or WP_Error.
 *
 * THIS IS THE POINT OF THE WHOLE TWO-TIMER MODEL. A hosted connector - claude.ai, Claude
 * Desktop - carries its credential in a request header that cannot be edited once the
 * connector has been added, so replacing a token means deleting and re-adding the
 * connector. Renew moves the window and leaves the token alone, so the thing the client
 * holds never changes and the client never notices.
 *
 * IT WORKS ON A DORMANT ROW, and that is the case it exists for: the admin presses this
 * BECAUSE the client started getting 401s. That is also why wpmcp_validate() no longer
 * deletes a refused row - by the time anyone looked, there would be nothing left to
 * renew. It works on an ACTIVE row too, which is the "I know I will be away tomorrow"
 * case; there is no reason to make somebody wait for a failure first.
 *
 * IT CANNOT REACH PAST THE HARD LIFETIME. min(now + window, expires_at) is what keeps
 * the second timer meaningful: without it, a window of six hours renewed every six hours
 * would make expires_at decorative, and a token nobody chose to keep would live forever
 * one press at a time.
 *
 * AND IT CANNOT HAND OUT A WINDOW LONGER THAN wpmcp_max_window(), whatever the row says:
 * twelve hours, or thirty days on a local site. It also STORES that clamp when it has to
 * apply one, so the row it leaves is consistent with how the window is read on use. See
 * the comment on the clamp below.
 *
 * A DEAD ROW IS REFUSED rather than quietly clamped to its own end. Setting
 * active_until = expires_at on a row whose expires_at is in the past would "succeed" and
 * change nothing observable, which is the worst answer available: the admin sees a
 * success notice and the client keeps failing.
 */
function wpmcp_renew($id) {
    global $wpdb;
    $id = (int) $id;

    $row = $wpdb->get_row($wpdb->prepare(
        'SELECT * FROM ' . wpmcp_table() . ' WHERE id = %d', $id
    ));

    if (!$row) {
        return new WP_Error('not_found', 'No such token.');
    }

    if (wpmcp_token_state($row) === 'dead') {
        return new WP_Error(
            'dead',
            'That token has reached the end of its lifetime. Mint a new one.'
        );
    }

    // A token whose user was deleted is refused on every request, whatever its timers
    // say. Renewing it would move a window nothing will ever look at and hand the
    // operator a success notice for a token that does not work - the same false green
    // the admin table used to show. Refuse, and say which of the two it is.
    if (!get_userdata((int) $row->user_id)) {
        return new WP_Error(
            'user_missing',
            'That token runs as WordPress user ' . (int) $row->user_id
            . ', who no longer exists. Renewing it would not make it work; revoke it and'
            . ' mint a new one for a user who does.'
        );
    }

    // CLAMPED AT BOTH ENDS, not just the bottom. Mint clamps and the mint form clamps,
    // so no row this plugin writes can carry a window over the ceiling - but a row
    // migrated from a hand-extended v2 token can, and one on a real test site carries
    // ninety days. Without the upper clamp, that single row is a way around the twelve-
    // hour ceiling the whole model exists to enforce; min(..., $lifetime) below only
    // hides it while the lifetime happens to be near.
    //
    // THE CEILING IS THE SITE'S, read now (sprint 14b). A row minted with a thirty-day
    // window on a local site renews for thirty days there - and for twelve hours on any
    // other site, which is what a database copied from a local site to a public one needs.
    $window   = min(wpmcp_max_window(), max(WPMCP_MIN_WINDOW, (int) $row->window_secs));
    $lifetime = strtotime($row->expires_at . ' UTC');
    $until    = gmdate('Y-m-d H:i:s', min(time() + $window, $lifetime));

    // AND IT STORES A CLAMP IT HAD TO APPLY, and only then (sprint 14b round 2). The
    // window is read on use as the grant capped by this site's ceiling
    // (wpmcp_effective_active_until), so a row left holding a thirty-day grant beside the
    // twelve-hour active_until this renew just wrote would read as dormant the instant it
    // was written - Renew would be a button that changes nothing. Writing the clamped
    // grant makes the row say what this site actually gave it. A window inside the
    // ceiling is not rewritten, so an ordinary renew still touches active_until alone.
    $data    = array('active_until' => $until);
    $formats = array('%s');

    if ($window !== (int) $row->window_secs) {
        $data['window_secs'] = $window;
        $formats[]           = '%d';
    }

    $wpdb->update(
        wpmcp_table(),
        $data,
        array('id' => $id),
        $formats, array('%d')
    );

    wpmcp_auth_event('renew', array(
        'token_id' => $id,
        'user_id'  => (int) $row->user_id,
        'actor'    => (int) get_current_user_id(),
        'window'   => $window,
    ));

    return $until;
}

function wpmcp_revoke($id) {
    global $wpdb;
    $id = (int) $id;

    // Read the owner before the delete, so the event can say whose token it was. One
    // extra indexed lookup on an operator action that happens by hand.
    $row = $wpdb->get_row($wpdb->prepare(
        'SELECT id, user_id, scope FROM ' . wpmcp_table() . ' WHERE id = %d', $id
    ));

    $deleted = (bool) $wpdb->delete(wpmcp_table(), array('id' => $id), array('%d'));

    if ($deleted) {
        wpmcp_auth_event('revoke', array(
            'token_id' => $id,
            'user_id'  => $row ? (int) $row->user_id : 0,
            'scope'    => $row ? (string) $row->scope : '',
            'actor'    => (int) get_current_user_id(),
        ));
    }

    return $deleted;
}

/**
 * How many tokens are ACTIVE - inside their window, and therefore answering.
 *
 * The window is this site's, exactly as wpmcp_effective_active_until() reads it: a row
 * granted more than this site's ceiling counts as answering only for the ceiling. Nothing
 * in the plugin calls this today; it is kept in step because "how many tokens answer"
 * must not have two different answers in one codebase.
 */
function wpmcp_active_count() {
    global $wpdb;
    $cap = (int) wpmcp_max_window();

    return (int) $wpdb->get_var(
        'SELECT COUNT(*) FROM ' . wpmcp_table()
        . ' WHERE active_until - INTERVAL GREATEST(CAST(window_secs AS SIGNED) - '
        . $cap . ', 0) SECOND > UTC_TIMESTAMP()'
        . ' AND expires_at > UTC_TIMESTAMP()'
    );
}

/**
 * The hourly flush: DEAD rows only.
 *
 * A dormant row is NOT deleted, and that is the one thing this function has to get
 * right. Its window has closed but its lifetime has not, so it is exactly the row an
 * admin is about to press Renew on; deleting it would turn every renewable token into a
 * re-mint the next time the cron happened to run first. Past the hard lifetime there is
 * nothing left to renew, so the row is only then rubbish.
 */
add_action('wpmcp_flush_expired', 'wpmcp_flush_expired_cb');
function wpmcp_flush_expired_cb() {
    global $wpdb;
    $wpdb->query('DELETE FROM ' . wpmcp_table() . ' WHERE expires_at <= UTC_TIMESTAMP()');
}

/**
 * The class loader for `src/`. Namespace `WpMcp\` -> `src/`, one class per file.
 *
 * HAND-ROLLED, BECAUSE THERE IS NO COMPOSER AT RUNTIME. This plugin ships as plain PHP
 * with no vendor directory (composer.json is dev-only), so the four flat files are
 * gaining a `src/` tree one sprint at a time and this is what finds it. Nine lines is
 * the whole cost.
 *
 * IT RETURNS SILENTLY WHEN THE FILE IS NOT THERE, which is not laziness - it is the
 * contract spl_autoload_register imposes. Several autoloaders are registered in any
 * WordPress process, and the test harness registers Composer's `WpMcp\Tests\` loader
 * too; a loader that fataled on a class it does not own would break every one of them.
 * A missing class surfaces as PHP's own "Class not found", naming the class.
 *
 * THE NAME IS CHECKED BEFORE IT BECOMES A PATH. $class arrives from whoever wrote the
 * `new`, which on a site with other plugins is not necessarily us, and it reaches a
 * require(). The character allow-list means no `..`, no separator but the namespace one,
 * nothing that could climb out of src/.
 */
spl_autoload_register(function ($class) {
    $prefix = 'WpMcp\\';
    if (strpos($class, $prefix) !== 0) { return; }

    $relative = substr($class, strlen($prefix));
    if ($relative === '' || !preg_match('#^[A-Za-z0-9_]+(\\\\[A-Za-z0-9_]+)*$#', $relative)) { return; }

    $path = plugin_dir_path(__FILE__) . 'src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) { require $path; }
});

function wpmcp_bootstrap() {
    // trace.php first: the activation hook above and endpoint.php both call into it.
    require_once plugin_dir_path(__FILE__) . 'trace.php';
    require_once plugin_dir_path(__FILE__) . 'tools.php';
    require_once plugin_dir_path(__FILE__) . 'admin.php';
    require_once plugin_dir_path(__FILE__) . 'endpoint.php';
}
wpmcp_bootstrap();
