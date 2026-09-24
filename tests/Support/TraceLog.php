<?php
/**
 * The plugin's private trace log, read from the site under test.
 *
 * READ THROUGH `wp eval`, NOT file_get_contents, for the same reason MuPlugin writes
 * through it: locally the site is on this filesystem, in CI it is inside a
 * @wordpress/env container whose wp-content the host cannot reach. WP_CONTENT_DIR is
 * WordPress's own answer to "where is it", which beats rebuilding the path from
 * WPMCP_SITE_PATH and being wrong on a site with a moved wp-content.
 *
 * NOTHING HERE TRUNCATES THE LOG. It is an operator's file, not a fixture, and a second
 * runner may be writing to it; every assertion is therefore "this trace id appears",
 * never "the log contains only this". That also means the tests stay correct when run
 * twice in a row, which clearing would not.
 *
 * THE PLUGIN ITSELF TRUNCATES IT SINCE 1.1.1, at 2 MiB, oldest entries first - which does not
 * change the rule above, it is the reason for it. `contents()` can therefore begin with a
 * `truncated=1` marker line, and an entry that was in the file a moment ago may be gone. The one
 * class that exercises that on purpose (tests/integration/TraceLogCapTest.php) points the SITE at
 * a throwaway log file name for its duration rather than truncating the operator's.
 *
 * THE FILE NAME IS A SECRET AND IS ASKED FOR, NEVER BUILT. It is `trace-<32 hex>.log`,
 * generated once per site and kept in the `wpmcp_trace_log_name` option, so the test has to
 * read it from the site the same way the plugin does. A test that hardcoded the old
 * `trace.log` would be asserting against the very name B1 removed.
 */

declare(strict_types=1);

namespace WpMcp\Tests\Support;

final class TraceLog
{
    /** The name the log had before B1 gave it a random one. Must not be servable. */
    public const LEGACY_NAME = 'trace.log';

    /** This site's log file name, from the plugin itself. */
    public static function fileName(): string
    {
        return WpCli::evaluate('echo wpmcp_trace_file_name();');
    }

    /**
     * The log's size in bytes, or 0 when it is not there yet.
     *
     * FOR "DID THE LOG GROW", WHICH IS THE ONLY QUESTION contents() WAS EVER ASKED FOR THAT DOES
     * NOT NEED THE BYTES (round 3). This log is append-only and, until 1.1.1, never trimmed, so on
     * a machine that has run the suite for weeks it is over a megabyte - the cap holds it at 2 MiB
     * now, which is still a megabyte. A growth check written as
     * `assertNotSame($before, contents())` then ships two megabyte-long strings through a wp-cli
     * subprocess AND hands one of them to a PHPUnit constraint. The queen's full-suite run died
     * inside PHPUnit's own failure formatting at exactly that assertion, on both sites, with the
     * real outcome invisible underneath a TypeError. An integer cannot do that.
     */
    public static function size(): int
    {
        return (int) trim(WpCli::evaluate(
            '$f = wpmcp_trace_path(); echo is_file($f) ? (int) filesize($f) : 0;'
        ));
    }

    /** Whole log, or '' when the file does not exist yet. */
    public static function contents(): string
    {
        // base64 so the text survives wp-env's own re-quoting and WpCli::clean(), which
        // keeps only non-empty lines of a container's stdout.
        $encoded = WpCli::evaluate(
            '$f = wpmcp_trace_path();'
            . ' echo is_file($f) ? base64_encode((string) file_get_contents($f)) : "";'
        );

        if (trim($encoded) === '') {
            return '';
        }

        return (string) base64_decode(trim($encoded), true);
    }

    /**
     * The one log entry carrying $traceId: its header line plus the indented stack
     * frames under it, up to the next entry. '' when the trace id is not in the log.
     */
    public static function entry(string $traceId): string
    {
        $entry = '';

        foreach (explode("\n", self::contents()) as $line) {
            $line = rtrim($line, "\r");

            if ($entry !== '' && $line !== '' && $line[0] !== ' ') {
                break; // the next entry starts at column 0
            }

            if ($entry !== '' || str_contains($line, 'trace=' . $traceId . ' ')) {
                $entry .= $line . "\n";
            }
        }

        return $entry;
    }

    /** The URL the log would be served at, as the plugin computes it. */
    public static function url(): string
    {
        return WpCli::evaluate('echo wpmcp_trace_url();');
    }

    /** The three outcomes wpmcp_trace_selfcheck() can store, as the plugin names them. */
    public const READABLE     = 'readable';
    public const NOT_READABLE = 'not_readable';
    public const UNVERIFIED   = 'unverified';

    /** Run the plugin's own self-check and return the state it stored. */
    public static function selfCheck(): string
    {
        return WpCli::evaluate('echo wpmcp_trace_selfcheck();');
    }

    /** The state the last self-check stored, without re-running it. */
    public static function selfCheckState(): string
    {
        return WpCli::evaluate('echo wpmcp_trace_selfcheck_state();');
    }

    /** Why the last self-check could not answer, or '' when it could. */
    public static function selfCheckReason(): string
    {
        $encoded = WpCli::evaluate('echo base64_encode(wpmcp_trace_selfcheck_reason());');

        return trim($encoded) === '' ? '' : (string) base64_decode(trim($encoded), true);
    }

    /** Does the site-wide red "readable from the web" warning stand? */
    public static function exposedOptionIsSet(): bool
    {
        return WpCli::evaluate('echo (int) wpmcp_trace_log_is_exposed();') === '1';
    }

    /** PHP_OS_FAMILY on the SITE, which is not this runner's on a containerised CI. */
    public static function osFamily(): string
    {
        return WpCli::evaluate('echo PHP_OS_FAMILY;');
    }

    /** The file's permission bits, as four octal digits ('0600'), or '' when absent. */
    public static function mode(): string
    {
        return WpCli::evaluate(
            '$f = wpmcp_trace_path();'
            . ' echo is_file($f) ? substr(sprintf("%o", fileperms($f)), -4) : "";'
        );
    }

    /** The `.htaccess` the plugin wrote beside the log, or '' when there is none. */
    public static function htaccess(): string
    {
        $encoded = WpCli::evaluate(
            '$f = wpmcp_trace_dir() . "/.htaccess";'
            . ' echo is_file($f) ? base64_encode((string) file_get_contents($f)) : "";'
        );

        return trim($encoded) === '' ? '' : (string) base64_decode(trim($encoded), true);
    }
}
