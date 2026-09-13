<?php
/**
 * Schema revision 3, the other half: a v2 row gains two timers without changing
 * behaviour.
 *
 * THE RULE, and the reason it is that rule. A v2 row had one expiry. If the upgrade
 * simply left the new columns at their defaults, every already-issued token would be
 * dormant the instant the site updated - active_until would sit at the epoch - and a
 * plugin update would log every connector out. So the migration says: the old expiry
 * becomes BOTH the end of the first active window AND the hard lifetime, and the window
 * length is however long the token was originally granted. A token minted for twelve
 * hours still answers for those twelve hours and then stops, exactly as before. The only
 * difference is what stopping means: it goes dormant, its row survives, and an admin can
 * renew it instead of re-issuing it.
 *
 * WHY window_secs = 0 IS A SOUND SENTINEL. wpmcp_mint() clamps the window to at least
 * WPMCP_MIN_WINDOW, so no row this plugin has ever written can carry zero. Only a column
 * that dbDelta has just added can, which makes the UPDATE idempotent - it runs on every
 * future schema bump and touches each row exactly once.
 *
 * Integration tier, no HTTP, same shape as TokenUserIdMigrationTest: a real $wpdb
 * against the real table is the only place the SQL can be shown to do what it says. The
 * fixture row is labelled and deleted in teardown, and the migration is a
 * `WHERE window_secs = 0` so it cannot touch a live token.
 *
 * @group sprint-7
 */

declare(strict_types=1);

namespace WpMcp\Tests\Integration;

use WpMcp\Tests\Support\Fixtures;
use WpMcp\Tests\Support\FixtureIntegrationTestCase;
use WpMcp\Tests\Support\WpCli;

final class TokenLifetimeMigrationTest extends FixtureIntegrationTestCase
{
    private static function label(): string { return Fixtures::name('lifetime-migration'); }

    /** The v2 row: created six hours ago, expiring in six. A twelve-hour grant. */
    private const CREATED_AGO = 21600;
    private const EXPIRES_IN  = 21600;
    private const GRANTED     = self::CREATED_AGO + self::EXPIRES_IN;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::requireSite();

        self::buildFixtures(
            static fn () => Fixtures::deleteTokensLabelled(self::label()),
            static fn () => Fixtures::deleteTokensLabelled(self::label())
        );
    }

    public static function tearDownAfterClass(): void
    {
        Fixtures::deleteTokensLabelled(self::label());

        parent::tearDownAfterClass();
    }

    /**
     * @group sprint-7
     */
    public function testAV2RowGetsItsWindowFromWhatItWasOriginallyGranted(): void
    {
        $id = $this->insertV2ShapedRow();

        $expiresBefore = $this->columnOf($id, 'expires_at');

        self::assertNotSame('', $expiresBefore, 'The fixture row was not written.');

        // The row is in the pre-migration shape, or a concurrent runner's migration has
        // already caught it - both are acceptable, and asserting "still 0 at this
        // instant" would be the same TOCTOU TokenUserIdMigrationTest documents.
        self::assertContains(
            (int) $this->columnOf($id, 'window_secs'),
            [0, self::GRANTED],
            'The fixture row was written neither in the v2 shape nor already migrated.'
        );

        self::assertNotSame(
            'FALSE',
            WpCli::evaluate('$r = wpmcp_migrate_token_lifetimes(); echo $r === false ? "FALSE" : (int) $r;'),
            'wpmcp_migrate_token_lifetimes() returned false, its documented "the UPDATE'
            . ' failed" answer. wpmcp_install() refuses to stamp the revision on that, so'
            . ' the plugin is now in its retry loop.'
        );

        self::assertSame(
            $expiresBefore,
            $this->columnOf($id, 'active_until'),
            'active_until did not become the row\'s old expiry, so an already-issued'
            . ' token would stop answering at a different moment than it used to.'
        );

        self::assertSame(
            self::GRANTED,
            (int) $this->columnOf($id, 'window_secs'),
            'window_secs is not the length the token was originally granted, so the first'
            . ' Renew would give it a different window than it had.'
        );

        self::assertSame(
            $expiresBefore,
            $this->columnOf($id, 'expires_at'),
            'The migration moved expires_at. It is the one column a v2 row already had,'
            . ' and its meaning - the hard end - is unchanged.'
        );
    }

    /**
     * Re-running it changes nothing, which is what makes it safe on every future schema
     * bump. Asserted on the values rather than on a row count, because a concurrent
     * runner's fixture row makes any count racy.
     *
     * @group sprint-7
     */
    public function testRunningItAgainLeavesAMigratedRowAlone(): void
    {
        $id = $this->insertV2ShapedRow();

        WpCli::evaluate('wpmcp_migrate_token_lifetimes(); echo "done";');

        $active = $this->columnOf($id, 'active_until');
        $window = (int) $this->columnOf($id, 'window_secs');

        // Move the window on, as a Renew would, and re-run.
        WpCli::evaluate(sprintf(
            'global $wpdb; echo (int) $wpdb->query($wpdb->prepare('
            . '"UPDATE " . wpmcp_table() . " SET active_until = %%s WHERE id = %%d",'
            . ' gmdate("Y-m-d H:i:s", time() + 60), %d));',
            $id
        ));

        $renewed = $this->columnOf($id, 'active_until');

        self::assertNotSame($active, $renewed, 'The fixture renewal did not move the window.');

        WpCli::evaluate('wpmcp_migrate_token_lifetimes(); echo "done";');

        self::assertSame(
            $renewed,
            $this->columnOf($id, 'active_until'),
            'A second run of the migration reset a renewed row back to its lifetime.'
            . ' Every schema bump would then undo every renewal on the site.'
        );
        self::assertSame($window, (int) $this->columnOf($id, 'window_secs'));
    }

    /**
     * And the site under test has no un-migrated rows left - the outcome, as opposed to
     * the function in isolation. Fixture rows excluded, because this class and a
     * concurrent runner both plant them on purpose.
     *
     * @group sprint-7
     */
    public function testNoRealRowOnTheSiteIsLeftWithoutTimers(): void
    {
        self::assertSame(
            '0',
            WpCli::evaluate(sprintf(
                'global $wpdb; echo (int) $wpdb->get_var($wpdb->prepare('
                . '"SELECT COUNT(*) FROM " . wpmcp_table()
                . " WHERE window_secs = 0 AND label NOT LIKE %%s", %s));',
                "'" . addcslashes(Fixtures::PREFIX . '%', "'\\") . "'"
            )),
            'A real token row still has no active window, so it is dormant from the'
            . ' moment the plugin was updated and its holder is locked out.'
        );
    }

    /**
     * A row exactly as revision 2 wrote one, plus the two new columns at the values
     * dbDelta's ADD COLUMN leaves behind: window_secs 0 and active_until at its default.
     */
    private function insertV2ShapedRow(): int
    {
        $id = (int) WpCli::evaluate(sprintf(
            'global $wpdb; $wpdb->insert(wpmcp_table(), array('
            . '"token_hash" => hash("sha256", (string) mt_rand()),'
            . '"scope" => "read",'
            . '"label" => %s,'
            . '"created_at" => gmdate("Y-m-d H:i:s", time() - %d),'
            . '"active_until" => "1970-01-01 00:00:00",'
            . '"window_secs" => 0,'
            . '"expires_at" => gmdate("Y-m-d H:i:s", time() + %d),'
            . '"use_count" => 0,'
            . '"created_by" => 1,'
            . '"user_id" => 1'
            . '), array("%%s","%%s","%%s","%%s","%%s","%%d","%%s","%%d","%%d","%%d"));'
            . ' echo (int) $wpdb->insert_id;',
            "'" . self::label() . "'",
            self::CREATED_AGO,
            self::EXPIRES_IN
        ));

        self::assertGreaterThan(0, $id, 'Could not insert the v2-shaped fixture row.');

        return $id;
    }

    private function columnOf(int $rowId, string $column): string
    {
        return WpCli::evaluate(sprintf(
            'global $wpdb; echo (string) $wpdb->get_var($wpdb->prepare('
            . '"SELECT %s FROM " . wpmcp_table() . " WHERE id = %%d", %d));',
            $column,
            $rowId
        ));
    }
}
