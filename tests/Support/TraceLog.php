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
 */

declare(strict_types=1);

namespace WpMcp\Tests\Support;

final class TraceLog
{
    /** Whole log, or '' when the file does not exist yet. */
    public static function contents(): string
    {
        // base64 so the text survives wp-env's own re-quoting and WpCli::clean(), which
        // keeps only non-empty lines of a container's stdout.
        $encoded = WpCli::evaluate(
            '$f = WP_CONTENT_DIR . "/wpmcp/trace.log";'
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

    /** Run the plugin's own self-check. '1' readable, '0' not readable, 'null' unknown. */
    public static function selfCheck(): string
    {
        return WpCli::evaluate(
            '$r = wpmcp_trace_selfcheck();'
            . ' echo $r === null ? "null" : ($r ? "1" : "0");'
        );
    }

    /** Does the admin page's red "readable from the web" warning stand? */
    public static function exposedOptionIsSet(): bool
    {
        return WpCli::evaluate('echo (int) wpmcp_trace_log_is_exposed();') === '1';
    }
}
