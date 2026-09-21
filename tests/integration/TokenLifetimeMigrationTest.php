<?php
/**
 * Schema revision 3, the other half: a v2 row gains two timers without changing
 * behaviour.
 *
 * THE RULE, and the reason it is that rule. A v2 row had one expiry, and the upgrade has
 * to answer two questions with it: when does this token stop answering, and how long may
 * it be renewed for.
 *
 *   active_until = the old expires_at    - so the token stops answering at exactly the
 *                                          moment it always would have. Leaving the new
 *                                          column at its default would make every
 *                                          already-issued token dormant the instant the
 *                                          site updated, and a plugin update would log
 *                                          every connector out.
 *   window_secs  = how long it was originally granted, capped at WPMCP_MAX_WINDOW
 *                                        - so the first Renew gives it the window it had,
 *                                          and a hand-extended 90-day row cannot hand out
 *                                          a 90-day active window on a model whose whole
 *                                          point is a twelve-hour ceiling.
 *   expires_at   = created_at + WPMCP_DEFAULT_LIFETIME
 *                                        - so the row is RENEWABLE. This is the part that
 *                                          was wrong in the first cut: setting the hard
 *                                          lifetime to the old expiry too made the row go
 *                                          straight to DEAD at that instant, never
 *                                          dormant, and Renew a write that changed
 *                                          nothing - while the CHANGELOG, the code
 *                                          comment and this docblock all promised the
 *                                          opposite.
 *
 * The one guard on that last line: a row whose old expiry is already further out than
 * created_at + the default lifetime keeps its old expiry, because shortening it would
 * kill a token that works today, and would put active_until past expires_at - a row that
 * is inside its window and past its end at once.
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

    /** Must match WPMCP_DEFAULT_LIFETIME and WPMCP_MAX_WINDOW; asserted below. */
    private const DEFAULT_LIFETIME = 30 * 86400;
    private const MAX_WINDOW       = 12 * 3600;

    private static function dormantLabel(): string { return Fixtures::name('lifetime-migration-dormant'); }
    private static function longLabel(): string { return Fixtures::name('lifetime-migration-long'); }
    private static function login(): string { return Fixtures::name('lifetime-migration-author'); }

    private static int $userId = 0;
    private static string $dormantToken = '';

    private static function labels(): array
    {
        return [self::label(), self::dormantLabel(), self::longLabel()];
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::requireSite();

        self::buildFixtures(self::build(...), self::destroy(...));
    }

    private static function build(): void
    {
        self::destroy();

        self::$userId = Fixtures::createUser(self::login(), 'administrator');

        // A REAL token, minted by the real mint, then rewritten into the v2 shape. The
        // renewability test below needs the raw 64-character string, and that exists
        // exactly once - in wpmcp_mint()'s return value - so a hand-inserted row cannot
        // be used to prove that the same token validates again after Renew.
        self::$dormantToken = Fixtures::mintToken('read', self::dormantLabel(), self::$userId);
    }

    public static function tearDownAfterClass(): void
    {
        self::destroy();

        parent::tearDownAfterClass();
    }

    private static function destroy(): void
    {
        Fixtures::deleteUser(self::$userId);

        foreach (self::labels() as $label) {
            Fixtures::deleteTokensLabelled($label);
        }
    }

    /**
     * The two numbers this class hard-codes are the plugin's own.
     *
     * @group sprint-7
     */
    public function testTheFixtureConstantsMatchThePlugin(): void
    {
        self::assertSame(
            (string) self::DEFAULT_LIFETIME,
            WpCli::evaluate('echo (int) WPMCP_DEFAULT_LIFETIME;'),
            'WPMCP_DEFAULT_LIFETIME moved; the assertions below are now about a number'
            . ' the plugin no longer uses.'
        );
        self::assertSame(
            (string) self::MAX_WINDOW,
            WpCli::evaluate('echo (int) WPMCP_MAX_WINDOW;')
        );
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

        // THE LIFETIME. created_at + the default lifetime, which for this fixture (created
        // six hours ago) is comfortably later than the old expiry - so the row is dormant
        // at its old expiry and renewable for thirty days from when it was minted.
        $created  = strtotime($this->columnOf($id, 'created_at') . ' UTC');
        $lifetime = strtotime($this->columnOf($id, 'expires_at') . ' UTC');

        self::assertSame(
            $created + self::DEFAULT_LIFETIME,
            $lifetime,
            'The migrated row\'s hard lifetime is not created_at + the default lifetime.'
            . ' If it is still the old expiry, the row goes straight to DEAD at that'
            . ' instant instead of dormant, Renew is a write that changes nothing, and'
            . ' four places in the documentation promise the opposite.'
        );

        self::assertGreaterThan(
            strtotime($expiresBefore . ' UTC'),
            $lifetime,
            'The lifetime is not later than the old expiry, so there is no window in'
            . ' which the row is dormant and renewable at all.'
        );
    }

    /**
     * The whole point of the rule, end to end: a migrated row goes DORMANT at its old
     * expiry, and Renew brings the SAME token back.
     *
     * Over real HTTP with the real token, because "the row says a later time" is not the
     * claim - "the credential the client is holding works again" is.
     *
     * @group sprint-7
     */
    public function testAMigratedRowGoesDormantAndItsTokenCanBeRenewed(): void
    {
        $id = Fixtures::tokenIdLabelled(self::dormantLabel());

        self::assertGreaterThan(0, $id, 'The minted fixture row is gone.');

        // Rewrite the real row into the v2 shape: minted two hours ago, expired one hour
        // ago, and no timers. Exactly what dbDelta leaves behind on an upgraded site.
        $this->makeV2Shaped($id, 7200, -3600);

        self::assertNotSame(
            'FALSE',
            WpCli::evaluate('$r = wpmcp_migrate_token_lifetimes(); echo $r === false ? "FALSE" : (int) $r;')
        );

        self::assertSame(
            'dormant',
            $this->stateOf($id),
            'A migrated token whose old expiry has passed is not dormant. If it reads'
            . ' "dead" the migration gave it no renewable lifetime.'
        );

        $refused = $this->call(self::$dormantToken);
        self::assertSame(401, $refused, 'The dormant migrated token was not refused.');

        $renewed = WpCli::evaluate(sprintf(
            '$r = wpmcp_renew(%d); echo is_wp_error($r) ? "ERROR: " . $r->get_error_code() : $r;',
            $id
        ), 1);

        self::assertStringNotContainsString(
            'ERROR',
            $renewed,
            "wpmcp_renew({$id}) refused a migrated dormant row: {$renewed}"
        );

        self::assertSame(
            200,
            $this->call(self::$dormantToken),
            'The same token is still refused after Renew, so a token that existed before'
            . ' the upgrade cannot be brought back without re-minting it - which for a'
            . ' hosted connector means deleting and re-adding the connector.'
        );
    }

    /**
     * A hand-extended row - a longer grant than the model's own ceiling - has its WINDOW
     * capped at WPMCP_MAX_WINDOW, and keeps its old expiry as its lifetime rather than
     * having it shortened.
     *
     * Both halves matter. Without the cap, the first Renew on such a row would hand out
     * a 90-day active window on a model whose ceiling is twelve hours. Without the guard
     * on the lifetime, the migration would move a live token's hard end BACKWARDS and
     * kill it on the spot.
     *
     * @group sprint-7
     */
    public function testALongLivedV2RowIsCappedOnTheWindowAndNotShortenedOnTheLifetime(): void
    {
        // Minted 90 days ago with 90 days still to run: a 180-day grant, and an old
        // expiry 90 days in the future - further out than created_at + the default.
        $ninetyDays = 90 * 86400;
        $grant      = 2 * $ninetyDays;
        $id = $this->insertRow(self::longLabel(), $ninetyDays, $ninetyDays);

        WpCli::evaluate('wpmcp_migrate_token_lifetimes(); echo "done";');

        self::assertSame(
            self::MAX_WINDOW,
            (int) $this->columnOf($id, 'window_secs'),
            'A 180-day grant produced a window longer than WPMCP_MAX_WINDOW.'
        );

        $created  = strtotime($this->columnOf($id, 'created_at') . ' UTC');
        $lifetime = strtotime($this->columnOf($id, 'expires_at') . ' UTC');

        self::assertSame(
            $created + $grant,
            $lifetime,
            'The migration shortened a row whose old expiry was further out than the'
            . ' default lifetime, which would kill a token that works today and would'
            . ' leave active_until past expires_at.'
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
        return $this->insertRow(self::label(), self::CREATED_AGO, self::EXPIRES_IN);
    }

    /** @param int $expiresIn seconds from now; the row is created $createdAgo ago. */
    private function insertRow(string $label, int $createdAgo, int $expiresIn): int
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
            "'" . $label . "'",
            $createdAgo,
            $expiresIn
        ));

        self::assertGreaterThan(0, $id, 'Could not insert the v2-shaped fixture row.');

        return $id;
    }

    /** Rewrite an existing row into the pre-migration shape, keeping its token_hash. */
    private function makeV2Shaped(int $id, int $createdAgo, int $expiresIn): void
    {
        WpCli::evaluate(sprintf(
            'global $wpdb; echo (int) $wpdb->query($wpdb->prepare('
            . '"UPDATE " . wpmcp_table() . " SET created_at = %%s, expires_at = %%s,"'
            . ' . " active_until = %%s, window_secs = 0 WHERE id = %%d",'
            . ' gmdate("Y-m-d H:i:s", time() - %d), gmdate("Y-m-d H:i:s", time() + %d),'
            . ' "1970-01-01 00:00:00", %d));',
            $createdAgo,
            $expiresIn,
            $id
        ));
    }

    private function stateOf(int $id): string
    {
        return WpCli::evaluate(sprintf(
            'global $wpdb; $r = $wpdb->get_row($wpdb->prepare('
            . '"SELECT * FROM " . wpmcp_table() . " WHERE id = %%d", %d));'
            . ' echo $r ? wpmcp_token_state($r) : "no-row";',
            $id
        ));
    }

    /** POST tools/list with this token; returns the HTTP status. */
    private function call(string $token): int
    {
        return $this->client()->post('wp-json/wpmcp/mcp', [
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $token,
            ],
            'body' => '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}',
        ])->getStatusCode();
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
