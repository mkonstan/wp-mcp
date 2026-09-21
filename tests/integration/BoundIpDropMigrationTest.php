<?php
/**
 * Schema revision 3: the column the IP pin lived in is dropped, for real, by an
 * explicit ALTER TABLE.
 *
 * WHY AN EXPLICIT ALTER AND NOT dbDelta. dbDelta only ever adds and widens. It compares
 * the CREATE TABLE it is given against the live table and issues ALTER TABLE ADD /
 * CHANGE for what is missing or different; a column present in the table and absent
 * from the SQL is simply not mentioned, and stays forever. So deleting the line from
 * wpmcp_install()'s CREATE TABLE removes the column from a FRESH install and from
 * nowhere else - every existing site would keep carrying it, which is the quiet failure
 * this test exists to prevent.
 *
 * Integration tier, but no HTTP: like TokenUserIdMigrationTest, this asserts on a real
 * $wpdb against the real table, because "an ALTER TABLE removed a column" is not a
 * question the unit tier can answer.
 *
 * IT PUTS THE COLUMN BACK IN ORDER TO WATCH IT GO. That is the only honest way to test
 * a migration that has already run on the site under test: re-create the v2 shape, call
 * the migration, assert the v2 shape is gone. Nothing reads or writes that column any
 * more, so a site carrying it for the second between the two calls behaves identically,
 * and tearDown drops it again whatever happens.
 *
 * @group sprint-7
 */

declare(strict_types=1);

namespace WpMcp\Tests\Integration;

use WpMcp\Tests\Support\Fixtures;
use WpMcp\Tests\Support\FixtureIntegrationTestCase;
use WpMcp\Tests\Support\MuPlugin;
use WpMcp\Tests\Support\WpCli;

final class BoundIpDropMigrationTest extends FixtureIntegrationTestCase
{
    /** The v2 column. Spelled once, here. */
    private const COLUMN = 'bound_ip';

    /** mu-plugin that makes the DROP fail, the way a host without the grant would. */
    private const SABOTAGE = 'no-drop';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::requireSite();
    }

    public static function tearDownAfterClass(): void
    {
        // Tolerant, and unconditional: a failure between the add and the drop must not
        // leave a v2 column on somebody's site, and a crashed run must not leave a
        // mu-plugin behind that breaks the drop for every later run.
        MuPlugin::remove(self::SABOTAGE);
        self::dropColumn();

        parent::tearDownAfterClass();
    }

    /**
     * A host that can ADD a column but not DROP one is fully upgraded anyway, and the
     * schema revision is recorded.
     *
     * WHY THIS IS WORTH A TEST. The drop is the only step in wpmcp_install() that is not
     * a correctness precondition - nothing reads or writes that column - and it used to
     * gate the version stamp along with the rest. On a host whose database user has no
     * DROP privilege (managed hosts do hand out grants like that) the revision would then
     * never be recorded, so wpmcp_maybe_upgrade() would fire dbDelta, three SHOW COLUMNS
     * and two UPDATEs on EVERY REQUEST forever - with the plugin otherwise working
     * perfectly and nothing saying why the site had got slower. That is the kind of
     * failure nobody reports as a bug; they just conclude WordPress is slow.
     *
     * HOW THE FAILURE IS SIMULATED. Not by revoking a grant, which would need a second
     * database user. A mu-plugin hooks WordPress's own `query` filter and rewrites this
     * one exact `ALTER TABLE ... DROP COLUMN bound_ip` statement into one that cannot
     * succeed. Everything else about the install runs for real.
     *
     * @group sprint-7
     */
    public function testTheRevisionIsStampedEvenWhenTheDropCannotRun(): void
    {
        self::addColumn();
        self::assertTrue(self::columnExists(), 'The fixture ALTER TABLE did not add the column back.');

        MuPlugin::drop(self::SABOTAGE, self::sabotage());

        // The option is what wpmcp_install() writes; clear it so "it was stamped" is a
        // claim about THIS call rather than about a value that was already there.
        WpCli::evaluate('echo (int) delete_option("wpmcp_db_ver");');

        $installed = WpCli::evaluate('echo wpmcp_install() ? "1" : "0";');

        MuPlugin::remove(self::SABOTAGE);

        self::assertSame(
            '1',
            $installed,
            'wpmcp_install() reported failure because the cosmetic column drop failed.'
        );

        self::assertSame(
            WpCli::evaluate('echo (int) WPMCP_DB_VER;'),
            WpCli::evaluate('echo (int) get_option("wpmcp_db_ver", 0);'),
            'The schema revision was not recorded, so every request from now on re-runs'
            . ' dbDelta and both backfills - forever, silently.'
        );

        self::assertTrue(
            self::columnExists(),
            'The column was dropped after all, so the sabotage did not work and this test'
            . ' proved nothing about the failure path.'
        );

        self::dropColumn();
    }

    /**
     * The control for the sabotage above: with the mu-plugin gone, the same install drops
     * the column. Without this, "the drop failed" could equally mean the fixture never
     * added it.
     *
     * @group sprint-7
     */
    public function testTheSameInstallDropsTheColumnWhenNothingIsInTheWay(): void
    {
        self::addColumn();
        self::assertTrue(self::columnExists());

        self::assertSame('1', WpCli::evaluate('echo wpmcp_install() ? "1" : "0";'));

        self::assertFalse(
            self::columnExists(),
            'A normal install left the column behind, so the sabotage test above is not'
            . ' distinguishing anything.'
        );
    }

    /**
     * Put the v2 column back, run the migration, watch it go.
     *
     * @group sprint-7
     */
    public function testTheMigrationDropsTheColumnFromATableThatStillHasIt(): void
    {
        self::addColumn();

        self::assertTrue(
            self::columnExists(),
            'The fixture ALTER TABLE did not add the column back, so the drop below'
            . ' would have nothing to do and this test would pass without testing.'
        );

        $result = WpCli::evaluate(
            '$r = wpmcp_migrate_drop_address_column(); echo $r === false ? "FALSE" : "OK";'
        );

        self::assertSame(
            'OK',
            $result,
            'wpmcp_migrate_drop_address_column() returned false, which is its documented "the'
            . ' ALTER TABLE failed" answer. wpmcp_install() treats that as a reason not'
            . ' to stamp the schema version, so the plugin is now in its retry loop.'
        );

        self::assertFalse(
            self::columnExists(),
            'The column survived the migration. dbDelta cannot drop a column, so without'
            . ' this ALTER TABLE every existing site keeps carrying it.'
        );
    }

    /**
     * Running it twice is a no-op rather than an error. wpmcp_install() calls every
     * migration on every schema bump, so this WILL run again in a site's life.
     *
     * @group sprint-7
     */
    public function testTheMigrationIsSafeToRunWhenTheColumnIsAlreadyGone(): void
    {
        self::dropColumn();

        self::assertFalse(self::columnExists(), 'The column is still there before the no-op run.');

        self::assertSame(
            'OK',
            WpCli::evaluate('$r = wpmcp_migrate_drop_address_column(); echo $r === false ? "FALSE" : "OK";'),
            'A second run reported failure, which would stop wpmcp_install() stamping'
            . ' the revision and put the plugin into a permanent retry.'
        );
    }

    /**
     * And the site under test really is past revision 3 with no such column - the
     * outcome, as opposed to the function in isolation. Read-only: nothing is installed
     * here.
     *
     * AT LEAST 3, NOT EXACTLY 3. The claim this test makes is that the drop shipped
     * WITH a schema bump, so wpmcp_maybe_upgrade() runs it on an existing site. A later
     * sprint bumping the revision again (4 added the file-version table) does not make
     * that claim false, and pinning the number here turned a sprint-7 gate red for a
     * sprint-8 change that had nothing to do with it.
     *
     * @group sprint-7
     */
    public function testTheLiveSiteIsPastRevisionThreeWithoutTheColumn(): void
    {
        self::dropColumn(); // in case this method runs after the fixture add

        self::assertGreaterThanOrEqual(
            3,
            (int) WpCli::evaluate('echo (int) WPMCP_DB_VER;'),
            'WPMCP_DB_VER is below 3, so the column drop ships without a schema bump and'
            . ' wpmcp_maybe_upgrade() will never run it on an existing site.'
        );

        self::assertSame(
            WpCli::evaluate('echo (int) WPMCP_DB_VER;'),
            WpCli::evaluate('echo (int) get_option("wpmcp_db_ver", 0);'),
            'The recorded schema revision does not match the code\'s, so'
            . ' wpmcp_maybe_upgrade() either never ran or refused to stamp.'
        );

        self::assertFalse(self::columnExists(), 'The live table still has the v2 column.');
    }

    private static function columnExists(): bool
    {
        return WpCli::evaluate(sprintf(
            'echo wpmcp_token_column_exists("%s") ? "1" : "0";',
            self::COLUMN
        )) === '1';
    }

    private static function addColumn(): void
    {
        WpCli::tryEvaluate(sprintf(
            'global $wpdb; if (!wpmcp_token_column_exists("%1$s")) {'
            . ' $wpdb->query("ALTER TABLE " . wpmcp_table() . " ADD COLUMN %1$s varchar(45) DEFAULT NULL");'
            . ' } echo "done";',
            self::COLUMN
        ));
    }

    private static function dropColumn(): void
    {
        WpCli::tryEvaluate(sprintf(
            'global $wpdb; if (wpmcp_token_column_exists("%1$s")) {'
            . ' $wpdb->query("ALTER TABLE " . wpmcp_table() . " DROP COLUMN %1$s");'
            . ' } echo "done";',
            self::COLUMN
        ));
    }

    /**
     * A mu-plugin body that breaks exactly one statement.
     *
     * `query` is WordPress's own filter on every $wpdb query. The rewrite matches the
     * plugin's DROP and nothing else - no other code on any site issues that statement -
     * and the replacement names a column that does not exist, so MySQL refuses it and
     * $wpdb->query() returns false: the same answer a missing DROP grant produces.
     */
    private static function sabotage(): string
    {
        $column = self::COLUMN;
        $run    = Fixtures::runId();

        return <<<PHP
/**
 * wp-mcp integration-suite DROP-COLUMN sabotage for run {$run}. Dropped and removed by
 * tests/integration/BoundIpDropMigrationTest.php. If you are reading this on a live site,
 * the run that wrote it crashed; deleting the file is safe.
 */
add_filter('query', static function (\$sql) {
    if (strpos(\$sql, 'DROP COLUMN {$column}') === false) {
        return \$sql;
    }

    return str_replace(
        'DROP COLUMN {$column}',
        'DROP COLUMN wpmcp_test_no_such_column',
        \$sql
    );
});
PHP;
    }
}
