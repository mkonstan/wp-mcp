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
     * Write `wpmcp-test-<run id>-<slug>.php` into the site's mu-plugins directory.
     *
     * @param string $slug short name, e.g. 'events'
     * @param string $php  the file's body WITHOUT the opening `<?php`
     */
    public static function drop(string $slug, string $php): void
    {
        $name = self::fileName($slug);

        $written = WpCli::evaluate(sprintf(
            'if (!wp_mkdir_p(WPMU_PLUGIN_DIR)) { echo "NO-DIR"; return; }'
            . ' echo (int) file_put_contents(WPMU_PLUGIN_DIR . "/" . %s,'
            . ' "<?php\n" . base64_decode(%s));',
            self::phpString($name),
            self::phpString(base64_encode($php))
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
