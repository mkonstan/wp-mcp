<?php
/**
 * The upgrade path: a token minted before identity existed keeps working.
 *
 * Rows written by 0.3.5 have no user_id. Left at 0 they would authenticate as nobody
 * and, after this sprint, be refused outright - so every token in flight would break
 * the moment somebody updated the plugin. wpmcp_migrate_token_user_ids() copies
 * created_by across, which is precisely the identity those tokens already ran as.
 *
 * Integration tier, but no HTTP: this asserts on a real $wpdb against the real table,
 * which is the only place a `UPDATE ... WHERE user_id = 0` can be shown to do what it
 * says. Both rows carry the fixture label and are deleted in teardown.
 *
 * WHAT THIS DOES NOT COVER. The migration function is called DIRECTLY, so
 * wpmcp_maybe_upgrade() and its option gate are not exercised as a sequence - only
 * their outcome on the site under test is (see the second test). Driving the real
 * upgrade would mean deleting wpmcp_db_ver and dropping the user_id column on
 * somebody's live database, which is not a thing an integration test should do; the
 * option gate is three lines and the SQL semantics are what can actually be wrong.
 *
 * @group sprint-1
 */

declare(strict_types=1);

namespace WpMcp\Tests\Integration;

use WpMcp\Tests\Support\Fixtures;
use WpMcp\Tests\Support\FixtureIntegrationTestCase;
use WpMcp\Tests\Support\WpCli;

final class TokenUserIdMigrationTest extends FixtureIntegrationTestCase
{
    private const LABEL = Fixtures::PREFIX . 'migration';

    /** Not a real user. The migration is pure SQL; it must not clobber this. */
    private const ALREADY_BOUND_TO = 987654;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::requireSite();

        self::buildFixtures(
            static fn () => Fixtures::deleteTokensLabelled(self::LABEL),
            static fn () => Fixtures::deleteTokensLabelled(self::LABEL)
        );
    }

    public static function tearDownAfterClass(): void
    {
        Fixtures::deleteTokensLabelled(self::LABEL);

        parent::tearDownAfterClass();
    }

    /**
     * (f) A pre-existing-shape row (user_id = 0, created_by = 1) is backfilled, and a
     * row that already has an owner is left alone - which is what makes the migration
     * safe to re-run, and it does re-run: wpmcp_install() calls it on every schema
     * bump, not once in a site's life.
     *
     * @group sprint-1
     */
    public function testTheMigrationBackfillsUserIdFromCreatedByWithoutTouchingBoundRows(): void
    {
        $legacyId = $this->insertTokenRow(0, 1);
        $boundId  = $this->insertTokenRow(self::ALREADY_BOUND_TO, 1);

        self::assertSame(0, $this->userIdOf($legacyId), 'The legacy fixture row was not written with user_id = 0.');
        self::assertSame(
            self::ALREADY_BOUND_TO,
            $this->userIdOf($boundId),
            'The already-bound fixture row was not written with its owner.'
        );

        $changed = WpCli::evaluate('echo (int) wpmcp_migrate_token_user_ids();');

        self::assertGreaterThanOrEqual(
            1,
            (int) $changed,
            'The migration reported no rows changed, but there was one to change.'
        );
        self::assertSame(
            1,
            $this->userIdOf($legacyId),
            'The migration did not copy created_by into user_id, so tokens minted'
            . ' before this sprint would be refused after the upgrade.'
        );
        self::assertSame(
            self::ALREADY_BOUND_TO,
            $this->userIdOf($boundId),
            'The migration overwrote a row that already had an owner. Re-running it'
            . ' would then re-point every token at whoever minted it.'
        );
    }

    /**
     * The upgrade actually happened here, and the version was stamped for the right
     * reason. wpmcp_install() now records the revision only after confirming the
     * column exists and the UPDATE did not return false - stamping it regardless was
     * unrecoverable: a failed ALTER TABLE would leave every row without user_id,
     * every token refused, and wpmcp_maybe_upgrade() would never run again.
     *
     * Read-only: no install is triggered, because forcing one would mean dropping a
     * column on a live database.
     *
     * @group sprint-1
     */
    public function testTheLiveSiteRecordedTheSchemaRevisionAndHasTheColumn(): void
    {
        self::assertSame(
            '1',
            WpCli::evaluate('echo wpmcp_token_column_exists("user_id") ? "1" : "0";'),
            'The tokens table has no user_id column, so the upgrade never completed.'
        );

        self::assertSame(
            (string) (int) WpCli::evaluate('echo (int) WPMCP_DB_VER;'),
            WpCli::evaluate('echo (int) get_option("wpmcp_db_ver", 0);'),
            'The recorded schema revision does not match the code\'s, so'
            . ' wpmcp_maybe_upgrade() either never ran or refused to stamp.'
        );

        self::assertSame(
            '0',
            WpCli::evaluate(
                'global $wpdb; echo (int) $wpdb->get_var("SELECT COUNT(*) FROM "'
                . ' . wpmcp_table() . " WHERE user_id = 0");'
            ),
            'A token row still has user_id = 0 after the migration; every such token'
            . ' authenticates as nobody and is refused.'
        );
    }

    /**
     * Insert a token row directly, the shape 0.3.5 wrote (plus the new column), and
     * return its id. A random token_hash keeps the UNIQUE index happy across re-runs.
     */
    private function insertTokenRow(int $userId, int $createdBy): int
    {
        $id = (int) WpCli::evaluate(sprintf(
            'global $wpdb; $wpdb->insert(wpmcp_table(), array('
            . '"token_hash" => hash("sha256", (string) mt_rand()),'
            . '"scope" => "read",'
            . '"label" => %s,'
            . '"created_at" => current_time("mysql", true),'
            . '"expires_at" => gmdate("Y-m-d H:i:s", time() + 600),'
            . '"bound_ip" => null,'
            . '"use_count" => 0,'
            . '"created_by" => %d,'
            . '"user_id" => %d'
            . '), array("%%s","%%s","%%s","%%s","%%s","%%s","%%d","%%d","%%d"));'
            . ' echo (int) $wpdb->insert_id;',
            "'" . self::LABEL . "'",
            $createdBy,
            $userId
        ));

        self::assertGreaterThan(0, $id, 'Could not insert the fixture token row.');

        return $id;
    }

    private function userIdOf(int $rowId): int
    {
        return (int) WpCli::evaluate(sprintf(
            'global $wpdb; echo (int) $wpdb->get_var($wpdb->prepare('
            . '"SELECT user_id FROM " . wpmcp_table() . " WHERE id = %%d", %d));',
            $rowId
        ));
    }
}
