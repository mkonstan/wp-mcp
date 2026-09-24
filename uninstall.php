<?php
/**
 * Copyright (C) 2026 Max Konstantinovski. GPLv2 or later (see LICENSE).
 *
 * WP MCP - what Delete leaves behind, which is nothing.
 *
 * WordPress runs this file when the plugin is DELETED, not when it is deactivated.
 * Deactivating is reversible and leaves every token, option and log in place on purpose.
 *
 * NOTHING FROM THE PLUGIN IS LOADED HERE. WordPress includes this file on its own, in a
 * request where wp-mcp.php has not run: no WPMCP_TABLE, no WPMCP_TRACE_NAME_OPTION, no
 * wpmcp_trace_dir(). Every name below is therefore spelled out as a literal, which is a
 * duplication and the reason tests/unit/UninstallTest.php exists: it reads the option
 * names out of the plugin's source and fails if one of them is not named here. A new
 * option without a line in this file is a red test rather than a row left in wp_options
 * on somebody's site.
 *
 * THE ORDER IS DELIBERATE. The cron hook goes first, so a scheduled flush cannot fire
 * against a table that is about to disappear. Options next, then the table, then the log
 * directory - the thing most likely to fail is last, and when it does the rest is already
 * gone.
 */

// Called any other way, this file does nothing. Without the guard it is a URL that drops
// a table.
if (!defined('WP_UNINSTALL_PLUGIN')) { exit; }

/**
 * Every option this plugin writes.
 *
 * wpmcp_db_ver              schema revision, compared on every load
 * wpmcp_trace_log_name      this site's random trace-log file name
 * wpmcp_trace_log_readable  set when the self-check found the log served over HTTP
 * wpmcp_trace_log_unwritable set when a trace went to error_log() instead
 * wpmcp_client_columns_missing set when revision 6's two optional columns could not be added
 * wpmcp_code_enabled        the code-editing switch
 * wpmcp_code_denylist       the code-editing denylist
 * wpmcp_sql_enabled         the sql-select switch
 * wpmcp_meta_keys           the post meta keys the meta tools may read and write
 */
$wpmcp_options = array(
    'wpmcp_db_ver',
    'wpmcp_trace_log_name',
    'wpmcp_trace_log_readable',
    'wpmcp_trace_log_unwritable',
    'wpmcp_client_columns_missing',
    'wpmcp_code_enabled',
    'wpmcp_code_denylist',
    'wpmcp_sql_enabled',
    'wpmcp_meta_keys',
);

/** Every transient. Deleted through the API, because an object cache holds them too. */
$wpmcp_transients = array(
    'wpmcp_trace_checked',
);

/**
 * Clean one site: its cron hook, its options, its token table.
 *
 * The trace log lives in wp-content, which a network shares, so it is handled once
 * afterwards rather than per site.
 */
function wpmcp_uninstall_site(array $options, array $transients) {
    global $wpdb;

    wp_clear_scheduled_hook('wpmcp_flush_expired');

    foreach ($options as $option) { delete_option($option); }
    foreach ($transients as $transient) { delete_transient($transient); }

    // The table names are built the same way wpmcp_install() builds them: $wpdb->prefix
    // plus the bare name. On multisite $wpdb->prefix is the CURRENT site's prefix, which
    // is why this runs inside the per-site loop below.
    //
    // BOTH TABLES. wpmcp_file_versions holds the previous contents of theme files the
    // code tools changed - up to half a megabyte per row - so a plugin that left it
    // behind would leave the largest thing it ever wrote.
    foreach (array('wpmcp_tokens', 'wpmcp_file_versions') as $bare) {
        $table = $wpdb->prefix . $bare;

        // Interpolated rather than prepared: DROP TABLE takes no placeholders, and the
        // only variable part is a prefix WordPress itself set.
        $wpdb->query("DROP TABLE IF EXISTS `{$table}`"); // phpcs:ignore
    }
}

/** Remove the trace log directory and everything the plugin put in it. */
function wpmcp_uninstall_trace_dir() {
    $dir = WP_CONTENT_DIR . '/wpmcp';

    if (!is_dir($dir)) { return; }

    // Named rather than globbed, with one glob for the random log name. A blind
    // unlink of everything in the directory would delete a file somebody else put
    // there, and this plugin does not own the whole of wp-content.
    $known = array($dir . '/index.php', $dir . '/.htaccess', $dir . '/trace.log');

    foreach (glob($dir . '/trace-*.log') as $log) { $known[] = $log; }

    // wp-admin/includes/file.php is loaded when the Plugins screen deletes a plugin, and
    // is not when WP-CLI does; either way this is the one file here PHP compiles, and a
    // delete is a change to it. The guard is spelled out rather than calling
    // wpmcp_opcache_invalidate(): nothing from the plugin is loaded in this request (see
    // the header), which is the same reason every option name above is a literal.
    if (!function_exists('wp_opcache_invalidate') && is_readable(ABSPATH . 'wp-admin/includes/file.php')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }

    foreach ($known as $file) {
        // BEFORE the unlink, for the reason code-delete gives: opcache_invalidate()
        // resolves the path on disk first, so after the unlink it answers false and the
        // entry for the deleted path survives.
        if (str_ends_with($file, '.php') && function_exists('wp_opcache_invalidate')) {
            wp_opcache_invalidate($file, true);
        }

        if (is_file($file)) { @unlink($file); }
    }

    // Fails, silently and correctly, if anything else is still in there.
    @rmdir($dir);
}

if (is_multisite()) {
    // One pass per site: each has its own prefix, its own options and its own tokens.
    // get_sites() rather than wp_get_sites(), which has been deprecated since 4.6.
    foreach (get_sites(array('fields' => 'ids', 'number' => 0)) as $wpmcp_site_id) {
        switch_to_blog((int) $wpmcp_site_id);
        wpmcp_uninstall_site($wpmcp_options, $wpmcp_transients);
        restore_current_blog();
    }
} else {
    wpmcp_uninstall_site($wpmcp_options, $wpmcp_transients);
}

wpmcp_uninstall_trace_dir();

unset($wpmcp_options, $wpmcp_transients);
