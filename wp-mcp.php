<?php
/**
 * Plugin Name: WP MCP
 * Description: Self-hosted MCP server for WordPress with short-lived, admin-minted, IP-pinned session tokens. Read tools by default; admin-scope adds content/media/comment writes and (opt-in) jailed theme code editing. Endpoint: /wp-json/wpmcp/mcp/{token}
 * Version: 0.3.5
 * Author: Max Konstantinovski
 * Author URI: https://github.com/mkonstan
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * Copyright (C) 2026 Max Konstantinovski. Designed and built by Max Konstantinovski
 * (with Claude). This program is free software under the GNU General Public License
 * v2 or later; see the LICENSE file. Concept, design, and architecture by Max
 * Konstantinovski — please retain this attribution in derivative works.
 *
 * Auth model (by design):
 *  - Admin mints a token in Settings > WP MCP. Token is shown ONCE.
 *  - Token is a 256-bit random value; only its SHA-256 hash is stored.
 *  - Hard expiry, capped at 12h. Enforced on every request (not by cron).
 *  - TOFU IP pinning: token locks on the first tool call, then all requests enforce it.
 *  - Scope: 'read' (default) or 'admin'. Read tokens are refused write tools.
 *  - Identity: every token is bound to a real WordPress user chosen at mint time.
 *    Requests run as that user, so WordPress capabilities bound reach and scope
 *    narrows on top. Delete the user and the token stops working.
 *  - The endpoint is dormant when no live token exists.
 *  - Token travels in the URL path or an Authorization: Bearer header.
 *  - HTTPS is required: over plaintext the endpoint answers 403 before it reads the
 *    token. It decides with is_ssl(), so the gate is only as strong as the proxy in
 *    front of WordPress - a proxy that FORWARDS the client's X-Forwarded-Proto rather
 *    than OVERWRITING it lets a client claim HTTPS over a plaintext connection. Your
 *    reverse proxy must set that header itself and never pass the client's value.
 *    WPMCP_ALLOW_INSECURE === true in wp-config.php is the local-dev override.
 *  - A browser Origin must be one of the site's own; absent Origin is allowed, which
 *    is what non-browser clients send. POST must be application/json, or 415.
 *  - Every token refusal is one byte-identical 401. Which of the six it was lives in
 *    the wpmcp_auth_event action, not on the wire.
 */

if (!defined('ABSPATH')) { exit; }

define('WPMCP_VER', '0.3.5');
define('WPMCP_TABLE', 'wpmcp_tokens');
define('WPMCP_MAX_TTL', 12 * HOUR_IN_SECONDS); // 43200s hard cap

// Token-table schema revision. Bump it whenever the CREATE TABLE below changes:
// wpmcp_maybe_upgrade() compares it against the wpmcp_db_ver option on every load and
// re-runs dbDelta plus the data migrations. The activation hook alone is not enough -
// it fires on activate, which never happens to a plugin that is updated in place.
//   1 = 0.3.5 and earlier: no user_id column, requests ran as the minting admin
//   2 = user-bound tokens: user_id column, backfilled from created_by
define('WPMCP_DB_VER', 2);
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
  expires_at datetime NOT NULL,
  bound_ip varchar(45) DEFAULT NULL,
  last_used_at datetime DEFAULT NULL,
  use_count bigint(20) unsigned NOT NULL DEFAULT 0,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY token_hash (token_hash),
  KEY expires_at (expires_at)
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
    if (wpmcp_migrate_token_user_ids() === false) { return false; }

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
 *   mint              a token was created              (token_id, user_id, created_by, scope, ttl)
 *   revoke            a token row was deleted          (token_id, user_id)
 *   validate_fail     a token was refused              (reason, token_id?, user_id?)
 *   pin_bind          a token's TOFU IP was set        (token_id, user_id)
 *   scope_deny        a read token asked for a write   (token_id, user_id, tool, scope)
 *   origin_deny       the Origin header was not ours   (origin)
 *   insecure_deny     the request was not over HTTPS   (-)
 *   content_type_deny the POST was not application/json (content_type)
 *   registry_reject   a filter-added tool was refused  (tool, reason)
 *
 * Every context also carries `ip`. `reason` on validate_fail is the INTERNAL reason -
 * missing, malformed, not_found, user_missing, expired, ip_mismatch - which is
 * deliberately the only place it exists: the wire answer to all six is one byte-
 * identical 401, so the log is where an operator finds out which it was.
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

/**
 * One event as one log line, in a shape a grep or a log shipper can rely on:
 *
 *   wp-mcp auth <type> key=value key=value ...
 *
 * Keys are sorted, so two occurrences of the same event produce the same field order.
 * Newlines are flattened, so one event is always one line.
 */
function wpmcp_format_auth_event($type, $context) {
    $redact = wpmcp_auth_event_redacted_keys();
    $context = (array) $context;
    ksort($context);
    $parts = array();

    foreach ($context as $key => $value) {
        $key = (string) $key;

        if (in_array(strtolower($key), $redact, true)) {
            $parts[] = $key . '=[redacted]';
            continue;
        }

        if (is_bool($value)) {
            $value = $value ? 'true' : 'false';
        } elseif ($value === null) {
            $value = 'null';
        } elseif (!is_scalar($value)) {
            $value = wp_json_encode($value);
        }

        $parts[] = $key . '=' . str_replace(array("\r", "\n"), ' ', (string) $value);
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
 * $ttl is clamped to [60s, 12h].
 *
 * $user_id is the WordPress user the token authenticates as. 0 means the current
 * user, which is both the historical behaviour and the right default for an admin
 * minting for themselves. The user must exist: a token bound to nobody would run as
 * nobody, every capability check inside the tools would fail, and the failure would
 * surface as a confusing empty result instead of a refusal. Refuse at mint instead.
 */
function wpmcp_mint($scope, $label, $ttl, $user_id = 0) {
    global $wpdb;
    $scope   = ($scope === 'admin') ? 'admin' : 'read';
    $ttl     = max(60, min(WPMCP_MAX_TTL, (int) $ttl));
    $user_id = (int) $user_id;
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
        'token_hash' => wpmcp_hash($raw),
        'scope'      => $scope,
        'label'      => sanitize_text_field((string) $label),
        'created_at' => $now,
        'expires_at' => gmdate('Y-m-d H:i:s', time() + $ttl),
        'bound_ip'   => null,
        'use_count'  => 0,
        'created_by' => (int) get_current_user_id(),
        'user_id'    => $user_id,
    ), array('%s','%s','%s','%s','%s','%s','%d','%d','%d'));

    if (!$ok) { return new WP_Error('wpmcp_insert_failed', 'Could not store token.'); }

    $id = (int) $wpdb->insert_id;

    wpmcp_auth_event('mint', array(
        'token_id'   => $id,
        'user_id'    => $user_id,
        'created_by' => (int) get_current_user_id(),
        'scope'      => $scope,
        'ttl'        => $ttl,
    ));

    return array('raw' => $raw, 'id' => $id);
}

/**
 * Validate a raw token against the current request.
 *
 * Returns the token row (object) on success, or a WP_Error whose code is the INTERNAL
 * reason: missing | malformed | not_found | user_missing | expired | ip_mismatch.
 *
 * THE CALLER MUST NOT PUT THAT REASON ON THE WIRE. All six are one byte-identical 401
 * (see wpmcp_unauthorized() in endpoint.php) and the reason survives only in the
 * validate_fail auth event, which is fired here so that every refusal path fires it -
 * including the deleted-user one, which the Sprint 1 review asked to be sure of.
 *
 * Why one answer: each distinguishable refusal is an oracle. "expired" confirms the
 * token was real and tells an attacker to look for a newer one; "ip_mismatch" confirms
 * it is real AND in use from somewhere else; a 403 instead of a 401 confirms it by the
 * status code alone. None of that is information a caller holding a bad token has any
 * claim to.
 *
 * Side effects on success: the use counters. The TOFU pin is NOT created here - see
 * wpmcp_bind_token_ip(), which endpoint.php calls only once the token has passed and
 * only for a tool call. Keeping the bind out of here is what lets wpmcp_authorize()
 * finish the whole credential check before anything reads the request body.
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

    // Identity BEFORE any side effect, and before expiry, so a token whose user was
    // deleted cannot pin an IP, cannot bump use_count, and cannot show as recently
    // used in the admin table: activity a refused request has no business recording.
    if (!get_userdata((int) $row->user_id)) {
        wpmcp_auth_event('validate_fail', array(
            'reason'   => 'user_missing',
            'token_id' => (int) $row->id,
            'user_id'  => (int) $row->user_id,
            'ip'       => $ip,
        ));
        return new WP_Error('user_missing', 'Token not found.');
    }

    // Expiry enforced on use (cron flush is only housekeeping).
    if (strtotime($row->expires_at . ' UTC') <= time()) {
        $wpdb->delete(wpmcp_table(), array('id' => $row->id), array('%d'));
        wpmcp_auth_event('validate_fail', array(
            'reason'   => 'expired',
            'token_id' => (int) $row->id,
            'user_id'  => (int) $row->user_id,
            'ip'       => $ip,
        ));
        return new WP_Error('expired', 'Token expired - regenerate in Settings > WP MCP.');
    }

    // Once a pin exists it is universal - every method, not only tool calls.
    if (!empty($row->bound_ip) && !hash_equals((string) $row->bound_ip, (string) $ip)) {
        wpmcp_auth_event('validate_fail', array(
            'reason'   => 'ip_mismatch',
            'token_id' => (int) $row->id,
            'user_id'  => (int) $row->user_id,
            'bound_ip' => (string) $row->bound_ip,
            'ip'       => $ip,
        ));
        return new WP_Error('ip_mismatch', 'Token is bound to a different IP.');
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
 * Create the TOFU pin on a token that does not have one yet, and tell the operator.
 *
 * Split out of wpmcp_validate() so the binding can happen AFTER the whole credential
 * check, and only for the request shape that should create it. A no-op on a token
 * that is already bound - wpmcp_validate() has already enforced that pin.
 */
function wpmcp_bind_token_ip($row, $ip) {
    global $wpdb;

    if (!empty($row->bound_ip)) { return; }

    $wpdb->update(wpmcp_table(), array('bound_ip' => $ip), array('id' => $row->id), array('%s'), array('%d'));
    $row->bound_ip = $ip;

    wpmcp_auth_event('pin_bind', array(
        'token_id' => (int) $row->id,
        'user_id'  => (int) $row->user_id,
        'ip'       => $ip,
    ));
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

function wpmcp_active_count() {
    global $wpdb;
    return (int) $wpdb->get_var(
        'SELECT COUNT(*) FROM ' . wpmcp_table() . " WHERE expires_at > UTC_TIMESTAMP()"
    );
}

add_action('wpmcp_flush_expired', 'wpmcp_flush_expired_cb');
function wpmcp_flush_expired_cb() {
    global $wpdb;
    $wpdb->query('DELETE FROM ' . wpmcp_table() . ' WHERE expires_at <= UTC_TIMESTAMP()');
}

function wpmcp_bootstrap() {
    require_once plugin_dir_path(__FILE__) . 'tools.php';
    require_once plugin_dir_path(__FILE__) . 'admin.php';
    require_once plugin_dir_path(__FILE__) . 'endpoint.php';
}
wpmcp_bootstrap();
