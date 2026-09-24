<?php
/**
 * The plugin's private trace store, read from the site under test.
 *
 * A TABLE SINCE 1.1.2, NOT A FILE, and that is why nothing here opens anything: traces are
 * rows in `wp_wpmcp_traces`. The old class read `wp-content/wpmcp/trace-<32 hex>.log` through
 * `wp eval` because in CI the site is inside a container whose wp-content the host cannot
 * reach; the reason to go through `wp eval` is now simpler - the database is the site's, and
 * the rendering has to be the plugin's own.
 *
 * `entry()` RETURNS THE SAME TEXT THE FILE HELD, because it calls the plugin's
 * wpmcp_trace_entry() - a `key=value` header line with the stack indented under it. Every
 * assertion written against the file era therefore still means what it meant, and an operator
 * reading the settings screen's lookup sees the identical shape.
 *
 * NOTHING HERE DELETES A ROW IT DID NOT PLANT. The table is an operator's, not a fixture's,
 * and a second runner may be writing to it; every assertion is "this trace id is there",
 * never "the table holds only this". The plugin sweeps it hourly (seven days, 2,000 rows), so
 * a trace that was there a moment ago may be gone - which is the reason for the rule, not a
 * change to it.
 */

declare(strict_types=1);

namespace WpMcp\Tests\Support;

final class TraceLog
{
    /** How many traces the site is holding. For "did a trace get written". */
    public static function count(): int
    {
        return (int) trim(WpCli::evaluate(
            'global $wpdb;'
            . ' echo (int) $wpdb->get_var("SELECT COUNT(*) FROM " . wpmcp_traces_table());'
        ));
    }

    /**
     * The one trace carrying $traceId, rendered exactly as the error-log fallback and the
     * settings screen render it: the header line plus the indented stack. '' when there is
     * none.
     *
     * Read through the plugin's own wpmcp_trace_find(), so the test exercises the lookup the
     * operator's promise depends on rather than a query of its own.
     */
    public static function entry(string $traceId): string
    {
        $encoded = WpCli::evaluate(
            '$out = "";'
            . ' foreach (wpmcp_trace_find(' . self::php($traceId) . ') as $r) {'
            . ' $out .= wpmcp_trace_entry($r); }'
            . ' echo base64_encode($out);'
        );

        return trim($encoded) === '' ? '' : (string) base64_decode(trim($encoded), true);
    }

    /**
     * Is $needle anywhere in any stored trace?
     *
     * THE SEARCH HAPPENS ON THE SITE, and that is deliberate: the alternative is shipping
     * every retained trace through a wp-cli subprocess and into a PHPUnit constraint, which
     * is how the file era's `contents()` killed a full-suite run - PHPUnit builds a constraint
     * description from the expected value, and a megabyte of it made `preg_replace` give up
     * and return null inside `LogicalNot::negate()`. A boolean cannot do that.
     */
    public static function contains(string $needle): bool
    {
        return trim(WpCli::evaluate(
            'global $wpdb; $n = ' . self::php($needle) . '; $hit = 0;'
            . ' foreach ($wpdb->get_results("SELECT * FROM " . wpmcp_traces_table()) as $r) {'
            . ' if (strpos(wpmcp_trace_entry($r), $n) !== false) { $hit = 1; break; } }'
            . ' echo $hit;'
        )) === '1';
    }

    /** Run the plugin's own retention sweep and return how many rows it removed. */
    public static function sweep(): int
    {
        return (int) trim(WpCli::evaluate('echo (int) wpmcp_trace_sweep();'));
    }

    /** The two retention caps the site is honouring: [days, rows]. */
    public static function retention(): array
    {
        $raw = WpCli::evaluate('echo wpmcp_trace_keep_days(), "\t", wpmcp_trace_keep_rows();');
        $parts = explode("\t", trim($raw));

        return [(int) ($parts[0] ?? 0), (int) ($parts[1] ?? 0)];
    }

    /**
     * Plant one trace row with a chosen id and age, and return its id.
     *
     * DIRECTLY THROUGH $wpdb, which is the one thing in this class that is not the plugin's
     * own path - because there is no plugin path to a trace of a chosen AGE, and the sweep
     * cannot be tested without one. Everything else about the row is what the plugin writes.
     */
    public static function plant(string $traceId, int $daysAgo): string
    {
        return trim(WpCli::evaluate(
            'global $wpdb;'
            . ' echo (int) $wpdb->insert(wpmcp_traces_table(), array('
            . ' "trace_id" => ' . self::php($traceId) . ','
            . ' "logged_at" => gmdate("Y-m-d H:i:s", time() - ' . (int) $daysAgo . ' * DAY_IN_SECONDS),'
            . ' "method" => "tools/call", "tool" => "wpmcp-test-planted",'
            . ' "user_id" => 0, "token_id" => 0, "class" => "RuntimeException",'
            . ' "message" => "wpmcp-test planted trace", "at" => "planted.php:1",'
            . ' "data" => "", "stack" => "#0 {main}"));'
        ));
    }

    /** Remove every trace this run planted, by tool name. Teardown only. */
    public static function forgetPlanted(): void
    {
        WpCli::tryEvaluate(
            'global $wpdb;'
            . ' echo (int) $wpdb->delete(wpmcp_traces_table(), array("tool" => "wpmcp-test-planted"));'
        );
    }

    /** Does the traces table exist on this site? */
    public static function tableExists(): bool
    {
        return trim(WpCli::evaluate(
            'global $wpdb; $t = wpmcp_traces_table();'
            . ' echo (int) ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $t)) === $t);'
        )) === '1';
    }

    /* ----------------------------------------------------------------
     * 1.1.1's log FILE, which revision 7 deletes
     * -------------------------------------------------------------- */

    /** The content planted in the fake log, so the test can say the file it found was ours. */
    public const LEGACY_BODY = "2026-01-01T00:00:00+00:00 trace=deadbeef class=RuntimeException\n"
        . "    #0 wpmcp-test planted by the upgrade test\n";

    /**
     * Recreate 1.1.1's trace log exactly as that version left it: the directory, a
     * `trace-<32 hex>.log` with content, the empty `index.php`, the `.htaccess`, the three
     * options and the transient. Returns the log file's name.
     *
     * A SITE UPGRADING FROM 1.1.1 HAS ALL OF THIS, and every piece of it is what revision 7
     * has to take: the file is the exposure (nginx serves it, MEASURED), the directory is
     * what makes the URL exist, and the options are rows nothing will ever read again.
     */
    public static function plantLegacyFile(): string
    {
        $name = trim(WpCli::evaluate(
            '$dir = WP_CONTENT_DIR . "/wpmcp"; wp_mkdir_p($dir);'
            . ' $name = "trace-" . bin2hex(random_bytes(16)) . ".log";'
            . ' file_put_contents($dir . "/" . $name, base64_decode("'
            . base64_encode(self::LEGACY_BODY) . '"));'
            . ' file_put_contents($dir . "/index.php", "<?php\n// Silence is golden.\n");'
            . ' file_put_contents($dir . "/.htaccess", "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n");'
            . ' update_option("wpmcp_trace_log_name", $name);'
            . ' update_option("wpmcp_trace_log_readable", array("state" => "readable", "reason" => "", "checked_at" => gmdate("c")));'
            . ' update_option("wpmcp_trace_log_unwritable", 1);'
            . ' set_transient("wpmcp_trace_checked", 1, DAY_IN_SECONDS);'
            . ' echo $name;'
        ));

        return $name;
    }

    /**
     * What of 1.1.1's log is still on the site: the directory, each file, and each option.
     *
     * @return array<string, bool>
     */
    public static function legacyState(): array
    {
        $raw = WpCli::evaluate(
            '$dir = WP_CONTENT_DIR . "/wpmcp";'
            . ' $logs = glob($dir . "/trace-*.log") ?: array();'
            . ' echo (int) is_dir($dir), "\t", count($logs), "\t",'
            . ' (int) is_file($dir . "/index.php"), "\t", (int) is_file($dir . "/.htaccess"), "\t",'
            . ' (int) is_file($dir . "/trace.log"), "\t",'
            . ' (int) (get_option("wpmcp_trace_log_name", null) !== null), "\t",'
            . ' (int) (get_option("wpmcp_trace_log_readable", null) !== null), "\t",'
            . ' (int) (get_option("wpmcp_trace_log_unwritable", null) !== null), "\t",'
            . ' (int) (get_transient("wpmcp_trace_checked") !== false);'
        );

        $p = explode("\t", trim($raw));

        return [
            'directory'      => ($p[0] ?? '0') === '1',
            'logs'           => (int) ($p[1] ?? 0) > 0,
            'index'          => ($p[2] ?? '0') === '1',
            'htaccess'       => ($p[3] ?? '0') === '1',
            'legacy_log'     => ($p[4] ?? '0') === '1',
            'name_option'    => ($p[5] ?? '0') === '1',
            'readable_option' => ($p[6] ?? '0') === '1',
            'unwritable_option' => ($p[7] ?? '0') === '1',
            'transient'      => ($p[8] ?? '0') === '1',
        ];
    }

    /**
     * Remove 1.1.1's log and options, whatever state a killed run left them in. Teardown
     * only, and it is the same set revision 7's migration removes - so on a green run it
     * finds nothing, and on a crashed one it stops a planted file being left under the
     * document root, which is the exposure this whole sprint is about.
     */
    public static function forgetLegacyFile(): void
    {
        WpCli::tryEvaluate(
            '$dir = WP_CONTENT_DIR . "/wpmcp";'
            . ' foreach (array_merge(glob($dir . "/trace-*.log") ?: array(),'
            . ' array($dir . "/trace.log", $dir . "/index.php", $dir . "/.htaccess")) as $f) {'
            . ' if (is_file($f)) { @unlink($f); } }'
            . ' if (is_dir($dir)) { @rmdir($dir); }'
            . ' delete_option("wpmcp_trace_log_name");'
            . ' delete_option("wpmcp_trace_log_readable");'
            . ' delete_option("wpmcp_trace_log_unwritable");'
            . ' delete_transient("wpmcp_trace_checked"); echo 1;'
        );
    }

    /** A PHP single-quoted literal, for a `wp eval` snippet that must stay one line. */
    private static function php(string $value): string
    {
        return "'" . addcslashes($value, "'\\") . "'";
    }
}
