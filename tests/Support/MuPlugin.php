<?php
/**
 * Drop a must-use plugin onto the site under test, and take it away again.
 *
 * WHY THE SUITE NEEDS THIS AT ALL. Sprint 2 adds behaviour that is only observable
 * from INSIDE the WordPress request that the test made over HTTP: did
 * `preprocess_comment` fire, did `wpmcp_auth_event` fire once or twice, does the tool
 * registry reject an entry a third party added through the `wpmcp_tools` filter. None
 * of that can be seen from the outside, and `wp eval` runs in a different process
 * from the request. A mu-plugin is the only hook point that is loaded by every
 * request without being activated, so it is the one place a test can stand.
 *
 * WRITTEN THROUGH wp-cli, NOT file_put_contents. Locally the site is on this
 * filesystem and either would work; in CI WordPress lives inside a @wordpress/env
 * container whose wp-content the host cannot reach. Going through `wp eval` uses the
 * one path that exists in both, and WPMU_PLUGIN_DIR is WordPress's own answer to
 * "where do these live" rather than a guess about wp-content.
 *
 * PER RUN, LIKE EVERY OTHER FIXTURE. The file name carries the run id, so two runners
 * drop two files and neither removes the other's. The body of each file is expected to
 * gate its own side effects on the run id too - see the `mine()` helper pattern in the
 * sprint-2 tests: a mu-plugin is loaded by EVERY request to the site, including the
 * other runner's, so an ungated recorder would count the other runner's events.
 *
 * The source is carried as base64 because it travels as one argv element through
 * `wp eval` and, in CI, through wp-env's own re-quoting for the container.
 */

declare(strict_types=1);

namespace WpMcp\Tests\Support;

use RuntimeException;

final class MuPlugin
{
    /** Slugs this process has dropped, so removeOurs() can take them back out. */
    private static array $dropped = [];

    /**
     * How long an armed run stays armed. Re-armed by every drop(), and a full suite run
     * is minutes, so this only ever expires on a run that is no longer running.
     */
    private const ARMED_TTL = 1800;

    /**
     * The transient that says "run <id> is live". Every dropped file returns early
     * unless it is set - see drop().
     */
    public static function armedTransient(): string
    {
        return Fixtures::name('armed');
    }

    /** Say this run is live, for the next ARMED_TTL seconds. */
    public static function arm(): void
    {
        WpCli::evaluate(sprintf(
            'echo (int) set_transient(%s, 1, %d);',
            self::phpString(self::armedTransient()),
            self::ARMED_TTL
        ));
    }

    /** Say this run is not live. Every file this run left behind goes inert at once. */
    public static function disarm(): void
    {
        WpCli::tryEvaluate(sprintf(
            'echo (int) delete_transient(%s);',
            self::phpString(self::armedTransient())
        ));
    }

    /**
     * Write `wpmcp-test-<run id>-<slug>.php` into the site's mu-plugins directory.
     *
     * THE FILE IS BORN INERT AND STAYS INERT UNLESS THIS RUN IS ARMED. Every dropped
     * body is wrapped in a guard that registers nothing unless the armed transient is
     * present (see guarded()), and arm() is called here with a 30-minute TTL.
     *
     * This is what makes a leftover safe. buildFixtures() cleans up after a THROW, but
     * a killed PHPUnit process leaves the file on the site, and until somebody deleted
     * it by hand it kept running: the bad-tools fixture put a callable tool named
     * `wpmcp-test-<runid>-tool-control` in every token holder's tools/list - leaking the
     * run id, which is the value that gates the recorder - and two registry_reject lines
     * into the production log per MCP request. A later run with a different id never
     * removes it, because purge() only deletes its own. Now the transient expires and
     * the file does nothing at all, whoever is still holding it.
     *
     * @param string $slug short name, e.g. 'events'
     * @param string $php  the file's body WITHOUT the opening `<?php`
     */
    public static function drop(string $slug, string $php): void
    {
        $name = self::fileName($slug);

        self::arm();

        $written = WpCli::evaluate(sprintf(
            'if (!wp_mkdir_p(WPMU_PLUGIN_DIR)) { echo "NO-DIR"; return; }'
            . ' echo (int) file_put_contents(WPMU_PLUGIN_DIR . "/" . %s,'
            . ' "<?php\n" . base64_decode(%s));',
            self::phpString($name),
            self::phpString(base64_encode(self::guarded($php)))
        ));

        if ((int) $written <= 0) {
            throw new RuntimeException(
                "Could not write the mu-plugin {$name} to the site under test: {$written}"
            );
        }

        self::$dropped[$slug] = $name;
    }

    /** Take one back out. Tolerant: it may already be gone. */
    public static function remove(string $slug): void
    {
        $name = self::fileName($slug);

        WpCli::tryEvaluate(sprintf(
            '$f = WPMU_PLUGIN_DIR . "/" . %s;'
            . ' echo file_exists($f) ? (int) unlink($f) : 1;',
            self::phpString($name)
        ));

        unset(self::$dropped[$slug]);
    }

    /**
     * Remove every mu-plugin file whose name carries THIS run's id - including ones a
     * crashed earlier process of the same run id left behind. Never touches another
     * run's, which is why purge() can call it while a second runner is live.
     */
    public static function removeOurs(): void
    {
        // Disarm FIRST. Even if a delete fails - a locked file, a read-only mount - the
        // files this run dropped are inert from here on.
        self::disarm();

        foreach (Fixtures::ours(self::leftovers()) as $name) {
            WpCli::tryEvaluate(sprintf(
                '$f = WPMU_PLUGIN_DIR . "/" . %s;'
                . ' echo file_exists($f) ? (int) unlink($f) : 1;',
                self::phpString($name)
            ));
        }

        self::$dropped = [];
    }

    /**
     * Every fixture-named mu-plugin file on the site, of any run.
     *
     * @return list<string> file names
     */
    public static function leftovers(): array
    {
        $raw = WpCli::evaluate(sprintf(
            'if (!is_dir(WPMU_PLUGIN_DIR)) { return; }'
            . ' foreach ((array) glob(WPMU_PLUGIN_DIR . "/" . %s . "*.php") as $f) {'
            . '  echo basename($f), "\n";'
            . ' }',
            self::phpString(Fixtures::PREFIX)
        ));

        $names = [];

        foreach (explode("\n", $raw) as $line) {
            $line = trim($line, "\r\n ");

            if (str_starts_with($line, Fixtures::PREFIX) && str_ends_with($line, '.php')) {
                $names[] = $line;
            }
        }

        return $names;
    }

    /**
     * Wrap a fixture body so it registers nothing unless this run is armed.
     *
     * ON `muplugins_loaded`, NOT AT FILE SCOPE, and that is a measurement rather than a
     * preference. Probed on the site under test with a throwaway mu-plugin that logged
     * the same three reads at three moments:
     *
     *   file scope         wp_using_ext_object_cache()=true   get_transient()=false
     *                      get_option('_transient_<name>')='1'
     *   muplugins_loaded   get_transient()='1'
     *   init               get_transient()='1'
     *
     * The LiteSpeed object-cache drop-in this site carries has already flagged itself as
     * an external cache by the time mu-plugins are included (wp-settings.php:151
     * `wp_start_object_cache()`, :505 the mu-plugin loop), so get_transient() looks in
     * the object cache - which nothing wrote, because the harness writes through wp-cli -
     * and misses. By `muplugins_loaded` (:548) it reads correctly. Reading the raw
     * `_transient_` option at file scope would also work here and would skip the expiry
     * check, which is the whole point of the TTL, so this waits for the hook instead.
     *
     * `muplugins_loaded` fires before ordinary plugins load, so a filter registered in
     * here is still in place for everything the fixtures hook: wpmcp_tools,
     * pre_option_*, wpmcp_auth_event, preprocess_comment.
     *
     * The body becomes a closure body, so its variables are function-scoped. Every
     * fixture body is self-contained, which is what makes that safe.
     */
    private static function guarded(string $php): string
    {
        return "/**\n"
            . " * wp-mcp integration-suite fixture for run " . Fixtures::runId() . ".\n"
            . " * Dropped and removed by WpMcp\\Tests\\Support\\MuPlugin. INERT unless the\n"
            . " * transient below says that run is live, so a file left behind by a killed\n"
            . " * test process stops doing anything within 30 minutes. Safe to delete.\n"
            . " */\n"
            . "add_action('muplugins_loaded', static function () {\n"
            . "    if (!get_transient('" . self::armedTransient() . "')) {\n"
            . "        return;\n"
            . "    }\n\n"
            . $php
            . "\n}, 0);\n";
    }

    private static function fileName(string $slug): string
    {
        return Fixtures::name($slug) . '.php';
    }

    /** $value as a single-quoted PHP literal, for embedding in `wp eval` source. */
    private static function phpString(string $value): string
    {
        return "'" . addcslashes($value, "'\\") . "'";
    }
}
