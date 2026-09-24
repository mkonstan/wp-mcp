<?php
/**
 * Copyright (C) 2026 Max Konstantinovski. GPLv2 or later (see LICENSE).
 *
 * WP MCP - what Delete leaves behind, which is nothing.
 *
 * WordPress runs this file when the plugin is DELETED, not when it is deactivated.
 * Deactivating is reversible and leaves every token, option and trace in place on purpose.
 *
 * NOTHING FROM THE PLUGIN IS LOADED HERE. WordPress includes this file on its own, in a
 * request where wp-mcp.php has not run: no WPMCP_TABLE, no WPMCP_TRACES_TABLE,
 * no wpmcp_traces_table(). Every name below is therefore spelled out as a literal, which is
 * a duplication and the reason tests/unit/UninstallTest.php exists: it reads the option
 * names and the CREATE TABLE statements out of the plugin's source and fails if one of them
 * is not named here. A new option or table without a line in this file is a red test rather
 * than a row left in somebody's database.
 *
 * THE ORDER IS DELIBERATE. The cron hook goes first, so a scheduled flush cannot fire
 * against a table that is about to disappear. Options next, then the tables, then what
 * 1.1.1 and earlier left in `wp-content` - the thing most likely to fail is last, and when
 * it does the rest is already gone.
 */

// Called any other way, this file does nothing. Without the guard it is a URL that drops
// a table.
if (!defined('WP_UNINSTALL_PLUGIN')) { exit; }

/**
 * Every option this plugin writes, plus the three it used to.
 *
 * wpmcp_db_ver              schema revision, compared on every load
 * wpmcp_client_columns_missing set when revision 6's two optional columns could not be added
 * wpmcp_trace_file_left    set when revision 7 could not remove 1.1.1's trace log
 * wpmcp_code_enabled        the code-editing switch
 * wpmcp_code_denylist       the code-editing denylist
 * wpmcp_sql_enabled         the sql-select switch
 * wpmcp_meta_keys           the post meta keys the meta tools may read and write
 *
 * THE LAST THREE ARE 1.1.1'S AND NOTHING WRITES THEM ANY MORE. The trace log became a table
 * in 1.1.2 and revision 7's upgrade deletes them - but only on a site that RAN that upgrade,
 * and a plugin deactivated before the update and then deleted never loads, so `plugins_loaded`
 * never fires and that migration never runs. Deleting them is three rows and covers exactly
 * that site; leaving them out would leave a stale option behind on it for ever.
 *
 * wpmcp_trace_log_name      the random trace-log file name (1.1.1 and earlier)
 * wpmcp_trace_log_readable  the daily self-check's outcome (1.1.1 and earlier)
 * wpmcp_trace_log_unwritable set when a trace went to error_log() instead (1.1.1 and earlier)
 */
$wpmcp_options = array(
    'wpmcp_db_ver',
    'wpmcp_client_columns_missing',
    'wpmcp_trace_file_left',
    'wpmcp_code_enabled',
    'wpmcp_code_denylist',
    'wpmcp_sql_enabled',
    'wpmcp_meta_keys',
    'wpmcp_trace_log_name',
    'wpmcp_trace_log_readable',
    'wpmcp_trace_log_unwritable',
);

/**
 * Every transient, which is now only 1.1.1's. Deleted through the API, because an object
 * cache holds them too - and for the reason the three legacy options above are still here.
 */
$wpmcp_transients = array(
    'wpmcp_trace_checked',
);

/**
 * Clean one site: its cron hook, its options, its three tables.
 *
 * What 1.1.1 and earlier left in wp-content is handled once afterwards rather than per
 * site, because that directory is shared across a network.
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
    // ALL THREE TABLES. wpmcp_file_versions holds the previous contents of theme files the
    // code tools changed - up to half a megabyte per row - so a plugin that left it
    // behind would leave the largest thing it ever wrote. wpmcp_traces holds stack traces,
    // absolute paths and, when a database call failed, SQL: the one thing worse to leave in
    // a stranger's database than a token hash. tests/unit/UninstallTest.php reads the
    // CREATE TABLE statements out of the plugin and requires every one of them here.
    foreach (array('wpmcp_tokens', 'wpmcp_file_versions', 'wpmcp_traces') as $bare) {
        $table = $wpdb->prefix . $bare;

        // Interpolated rather than prepared: DROP TABLE takes no placeholders, and the
        // only variable part is a prefix WordPress itself set.
        $wpdb->query("DROP TABLE IF EXISTS `{$table}`"); // phpcs:ignore
    }
}

/**
 * Remove what 1.1.1 and earlier left in `wp-content/wpmcp/`: the trace log, its two guard
 * files and the directory.
 *
 * NOTHING THE CURRENT PLUGIN WROTE IS IN HERE. The trace log became a table in 1.1.2 and
 * revision 7's upgrade deletes this directory - so on almost every site this function finds
 * nothing and returns at the first line. It stays for the one site it is about: a plugin
 * DEACTIVATED before the update and then deleted never loads, so `plugins_loaded` never
 * fires, revision 7 never runs, and the file full of stack traces is still sitting under the
 * document root where nginx will serve it. Deleting the plugin has to take it.
 */
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
