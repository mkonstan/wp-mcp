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
 *  - The endpoint is dormant when no live token exists.
 *  - Token travels in the URL path or an Authorization: Bearer header.
 */

if (!defined('ABSPATH')) { exit; }

define('WPMCP_VER', '0.3.5');
define('WPMCP_TABLE', 'wpmcp_tokens');
define('WPMCP_MAX_TTL', 12 * HOUR_IN_SECONDS); // 43200s hard cap

/* ============================================================
 * Activation: create the tokens table
 * ========================================================== */
register_activation_hook(__FILE__, 'wpmcp_activate');
function wpmcp_activate() {
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
  PRIMARY KEY  (id),
  UNIQUE KEY token_hash (token_hash),
  KEY expires_at (expires_at)
) $charset;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    if (!wp_next_scheduled('wpmcp_flush_expired')) {
        wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', 'wpmcp_flush_expired');
    }
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

/**
 * Mint a token. Returns array('raw'=>..., 'id'=>...) or WP_Error.
 * $ttl is clamped to [60s, 12h].
 */
function wpmcp_mint($scope, $label, $ttl) {
    global $wpdb;
    $scope = ($scope === 'admin') ? 'admin' : 'read';
    $ttl   = max(60, min(WPMCP_MAX_TTL, (int) $ttl));
    $raw   = bin2hex(random_bytes(32)); // 256-bit
    $now   = current_time('mysql', true); // UTC

    $ok = $wpdb->insert(wpmcp_table(), array(
        'token_hash' => wpmcp_hash($raw),
        'scope'      => $scope,
        'label'      => sanitize_text_field((string) $label),
        'created_at' => $now,
        'expires_at' => gmdate('Y-m-d H:i:s', time() + $ttl),
        'bound_ip'   => null,
        'use_count'  => 0,
        'created_by' => get_current_user_id(),
    ), array('%s','%s','%s','%s','%s','%s','%d','%d'));

    if (!$ok) { return new WP_Error('wpmcp_insert_failed', 'Could not store token.'); }
    return array('raw' => $raw, 'id' => (int) $wpdb->insert_id);
}

/**
 * Validate a raw token against the current request.
 * Returns the token row (object) on success, or WP_Error with a code:
 *   not_found | expired | ip_mismatch
 * Side effects on success: TOFU-binds IP on first IP-gated call, touches counters.
 * $enforce_ip=false lets discovery/handshake avoid creating the initial binding.
 * Once bound, every request must match the bound IP.
 */
function wpmcp_validate($raw, $ip, $enforce_ip = true) {
    global $wpdb;
    if (!is_string($raw) || strlen($raw) !== 64 || !ctype_xdigit($raw)) {
        return new WP_Error('not_found', 'Invalid token.');
    }
    $hash = wpmcp_hash($raw);
    $row  = $wpdb->get_row($wpdb->prepare(
        'SELECT * FROM ' . wpmcp_table() . ' WHERE token_hash = %s', $hash
    ));
    if (!$row) { return new WP_Error('not_found', 'Token not found.'); }

    // Expiry enforced on use (cron flush is only housekeeping).
    if (strtotime($row->expires_at . ' UTC') <= time()) {
        $wpdb->delete(wpmcp_table(), array('id' => $row->id), array('%d'));
        return new WP_Error('expired', 'Token expired - regenerate in Settings > WP MCP.');
    }

    // Discovery does not create the TOFU pin, but once a pin exists it is universal.
    if (!empty($row->bound_ip)) {
        if (!hash_equals((string) $row->bound_ip, (string) $ip)) {
            return new WP_Error('ip_mismatch', 'Token is bound to a different IP.');
        }
    } elseif ($enforce_ip) {
        $wpdb->update(wpmcp_table(), array('bound_ip' => $ip), array('id' => $row->id), array('%s'), array('%d'));
        $row->bound_ip = $ip;
    }

    $wpdb->update(
        wpmcp_table(),
        array('last_used_at' => current_time('mysql', true), 'use_count' => (int) $row->use_count + 1),
        array('id' => $row->id),
        array('%s','%d'), array('%d')
    );
    return $row;
}

function wpmcp_revoke($id) {
    global $wpdb;
    return (bool) $wpdb->delete(wpmcp_table(), array('id' => (int) $id), array('%d'));
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
