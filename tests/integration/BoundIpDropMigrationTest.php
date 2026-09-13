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

use WpMcp\Tests\Support\FixtureIntegrationTestCase;
use WpMcp\Tests\Support\WpCli;

final class BoundIpDropMigrationTest extends FixtureIntegrationTestCase
{
    /** The v2 column. Spelled once, here. */
    private const COLUMN = 'bound_ip';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::requireSite();
    }

    public static function tearDownAfterClass(): void
    {
        // Tolerant, and unconditional: a failure between the add and the drop must not
        // leave a v2 column on somebody's site.
        self::dropColumn();

        parent::tearDownAfterClass();
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
     * And the site under test really is at revision 3 with no such column - the outcome,
     * as opposed to the function in isolation. Read-only: nothing is installed here.
     *
     * @group sprint-7
     */
    public function testTheLiveSiteIsAtRevisionThreeWithoutTheColumn(): void
    {
        self::dropColumn(); // in case this method runs after the fixture add

        self::assertSame(
            '3',
            WpCli::evaluate('echo (int) WPMCP_DB_VER;'),
            'WPMCP_DB_VER is not 3, so the column drop ships without a schema bump and'
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
}
