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
 *  - TWO TIMERS. An active window (6h by default, 12h at most) after which the token
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
 *    constant and never carries it. Where Apache under CGI/FastCGI strips the header,
 *    the value is read from REDIRECT_HTTP_AUTHORIZATION instead - see
 *    wpmcp_extract_token().
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
define('WPMCP_DB_VER', 3);
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
 */
add_action('plugins_loaded', 'wpmcp_maybe_upgrade');
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

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    // Record the revision ONLY once the schema and the data are both actually there.
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
    if (wpmcp_migrate_drop_address_column() === false) { return false; }

    update_option(WPMCP_DB_VER_OPTION, WPMCP_DB_VER);
    return true;
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
 * Returns the query result, or true when there was nothing to do. false is the
 * documented failure, and wpmcp_install() refuses to stamp the revision on it - see the
 * comment there about failing closed and retryable.
 */
function wpmcp_migrate_drop_address_column() {
    global $wpdb;

    if (!wpmcp_token_column_exists('bound_ip')) { return true; }

    return $wpdb->query('ALTER TABLE ' . wpmcp_table() . ' DROP COLUMN bound_ip');
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
    add_action('wpmcp_auth_event', 'wpmcp_log_auth_event', 10, 2);
}

/**
 * Mint a token. Returns array('raw'=>..., 'id'=>...) or WP_Error.
 *
 * TWO TIMERS, BOTH IN SECONDS.
 *
 *   $window_secs    how long the token answers before going DORMANT. Clamped to
 *                   [WPMCP_MIN_WINDOW, WPMCP_MAX_WINDOW]. A dormant token is refused
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
    $window_secs = max(WPMCP_MIN_WINDOW, min(WPMCP_MAX_WINDOW, (int) $window_secs));
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
 */
function wpmcp_token_state($row) {
    $now = time();

    if (strtotime($row->expires_at . ' UTC') <= $now)   { return 'dead'; }
    if (strtotime($row->active_until . ' UTC') <= $now) { return 'dormant'; }

    return 'active';
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

    $window   = max(WPMCP_MIN_WINDOW, (int) $row->window_secs);
    $lifetime = strtotime($row->expires_at . ' UTC');
    $until    = gmdate('Y-m-d H:i:s', min(time() + $window, $lifetime));

    $wpdb->update(
        wpmcp_table(),
        array('active_until' => $until),
        array('id' => $id),
        array('%s'), array('%d')
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

/** How many tokens are ACTIVE - inside their window, and therefore answering. */
function wpmcp_active_count() {
    global $wpdb;
    return (int) $wpdb->get_var(
        'SELECT COUNT(*) FROM ' . wpmcp_table()
        . ' WHERE active_until > UTC_TIMESTAMP() AND expires_at > UTC_TIMESTAMP()'
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
