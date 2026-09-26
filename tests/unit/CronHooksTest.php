<?php
/**
 * wpmcp_cron_hooks() is the ONE list, and uninstall.php's literal copy of it is complete.
 *
 * THE SAME DUPLICATION UninstallTest EXISTS FOR, one layer over. WordPress includes uninstall.php
 * in a request where wp-mcp.php has not run, so that file cannot ask the plugin which hooks it
 * schedules and every name there has to be a literal. That is the bug waiting to happen: somebody
 * adds a second `wp_schedule_event()` in 2027, ships it, and a cron event fires hourly against a
 * plugin that is no longer installed on every site that ever had it - because nothing connected
 * the two files.
 *
 * SO THIS TEST IS THE CONNECTION, and it reads the plugin's SOURCE to make it. Not the loaded
 * function, deliberately: what has to be held is the pair of LISTS, and a runtime check of
 * wpmcp_cron_hooks() alone would say nothing about the file that cannot call it. The direction
 * matters too - a name in the plugin and not in uninstall.php is a row left behind on somebody's
 * site, which is a failure; a name in uninstall.php and not in the plugin is a delete_option for
 * something that was never written, which is harmless and is how a removed hook is cleaned up on
 * sites that still have it. Only the first direction is asserted, and the second is asserted NOT
 * to be, so a future author does not "tidy" a legacy name out of uninstall.php.
 *
 * @group sprint-core-fix
 */

declare(strict_types=1);

namespace WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WpMcp\Tests\Support\RepoFile;
use WpMcp\Tests\Support\WordPressStubs;

final class CronHooksTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        WordPressStubs::loadPlugin();
    }

    /**
     * The plugin's list and uninstall.php's literal list are the same set.
     *
     * @group sprint-core-fix
     */
    public function testUninstallNamesEveryCronHookThePluginSchedules(): void
    {
        $declared = self::hooksIn(RepoFile::read('wp-mcp.php'), 'wpmcp_cron_hooks() {');
        $literal  = self::hooksIn(RepoFile::read('uninstall.php'), '$wpmcp_cron_hooks = array(');

        self::assertNotEmpty(
            $declared,
            'wpmcp_cron_hooks() was not found in wp-mcp.php, or it declares nothing - either way'
            . ' the scan is broken rather than the plugin scheduling nothing. Check the anchor.'
        );

        self::assertSame(
            $declared,
            array_values(array_intersect($literal, $declared)),
            'uninstall.php does not name every hook wpmcp_cron_hooks() declares. The plugin'
            . ' schedules ' . implode(', ', $declared) . ' and that file names '
            . implode(', ', $literal) . ', so deleting the plugin would leave a scheduled event'
            . ' behind on every site that installed it.'
        );

        // AND THE RUNTIME LIST AGREES WITH THE SOURCE SCAN. Without this the scan could be reading
        // a commented-out array and reporting a green pair of lists that the plugin never uses.
        self::assertSame(
            $declared,
            array_values(\wpmcp_cron_hooks()),
            'The list wpmcp_cron_hooks() RETURNS is not the list this test read out of its body,'
            . ' so the scan is looking at the wrong thing.'
        );
    }

    /**
     * A name uninstall.php still clears and the plugin no longer schedules is CORRECT, and this
     * says so, because the opposite assertion is the tempting one to add next.
     *
     * The plugin has had one cron hook since 1.0 and the case is therefore hypothetical today -
     * which is exactly when to write the rule down, before a future author deletes a hook,
     * "tidies" its name out of uninstall.php on the same commit, and leaves a live event on every
     * site that had the old version. The three legacy OPTION names in that file are the same
     * decision, already taken, with its reasoning in that file's docblock.
     *
     * @group sprint-core-fix
     */
    public function testUninstallMayNameAHookThePluginNoLongerSchedules(): void
    {
        $declared = self::hooksIn(RepoFile::read('wp-mcp.php'), 'wpmcp_cron_hooks() {');
        $literal  = self::hooksIn(RepoFile::read('uninstall.php'), '$wpmcp_cron_hooks = array(');

        // BOTH LISTS EXIST. Without this the subset assertion below is vacuously true on a plugin
        // that declares no list at all, which is exactly the state this sprint started from.
        self::assertNotEmpty($declared, 'wp-mcp.php declares no wpmcp_cron_hooks() list.');
        self::assertNotEmpty($literal, 'uninstall.php declares no $wpmcp_cron_hooks list.');

        self::assertSame(
            [],
            array_values(array_diff($declared, $literal)),
            'This is the assertion above, restated so that the one below cannot be read as its'
            . ' inverse.'
        );

        // Not assertSame([], array_diff($literal, $declared)) - see the docblock. The assertion is
        // that uninstall.php is a SUPERSET, and that is what the test above checks.
        self::assertGreaterThanOrEqual(
            count($declared),
            count($literal),
            'uninstall.php clears fewer hooks than the plugin schedules.'
        );
    }

    /**
     * Every single-quoted string inside the block that begins at $anchor, up to the first `);`.
     *
     * @return list<string>
     */
    private static function hooksIn(string $source, string $anchor): array
    {
        $start = strpos($source, $anchor);

        if ($start === false) {
            return [];
        }

        $end   = strpos($source, ');', $start);
        $block = substr($source, $start, $end === false ? null : $end - $start);

        preg_match_all("/'([a-z0-9_]+)'/", $block, $matches);

        return array_values(array_unique($matches[1]));
    }
}
