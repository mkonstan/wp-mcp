<?php
/**
 * Renew: the same token, a new window.
 *
 * WHAT IT IS FOR. A claude.ai or Claude Desktop custom connector carries its credential
 * in a request header that cannot be edited once the connector has been added, so
 * "issue a new token" is really "delete the connector and add it again". That made a
 * short expiry a twice-daily chore and a long one a standing liability. Renew moves the
 * ACTIVE WINDOW and leaves the token untouched: the client keeps sending the same string
 * and never learns anything happened.
 *
 * THE THREE THINGS THAT CAN GO WRONG, and each has a test here:
 *
 *   - renewing a dormant row has to WORK. It is the case the feature exists for, and it
 *     only works because wpmcp_validate() stopped deleting refused rows.
 *   - renewing must not reach past the hard lifetime, or the second timer is decorative:
 *     a six-hour window renewed every six hours would run forever.
 *   - a dead row must be REFUSED rather than clamped to its own end, which would
 *     "succeed", change nothing, and leave an admin reading a success notice while the
 *     client kept failing.
 *
 * @group sprint-7
 */

declare(strict_types=1);

namespace WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WpMcp\Tests\Support\FakeWpdb;
use WpMcp\Tests\Support\WordPressRuntime;
use WpMcp\Tests\Support\WordPressStubs;

final class TokenRenewTest extends TestCase
{
    private const HOUR = 3600;
    private const DAY  = 86400;

    private const TOKEN = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    private FakeWpdb $wpdb;

    protected function setUp(): void
    {
        parent::setUp();

        WordPressStubs::loadPlugin();
        $this->wpdb = WordPressRuntime::install();
        WordPressRuntime::logInAs(3, 'wpmcp-unit-admin');
        WordPressRuntime::addUser(12, 'wpmcp-unit-owner');
    }

    /**
     * A dormant token validates again afterwards, which is the whole feature in one
     * assertion - and it is asserted through wpmcp_validate(), not by reading the column
     * back, because "the row says a later time" is not the same claim as "the token
     * works".
     *
     * @group sprint-7
     */
    public function testRenewingADormantTokenMakesTheSameTokenValidateAgain(): void
    {
        $now = time();
        $this->wpdb->row = $this->row($now - self::HOUR, $now + 30 * self::DAY);

        $refused = \wpmcp_validate(self::TOKEN, '203.0.113.5');
        self::assertTrue(\is_wp_error($refused), 'The fixture row is not dormant.');
        self::assertSame('dormant', $refused->get_error_code());

        $until = \wpmcp_renew(77);

        self::assertIsString($until, 'wpmcp_renew() refused a dormant row.');

        // The row the next validate() reads is the one renew just wrote.
        $this->wpdb->row->active_until = $until;

        $accepted = \wpmcp_validate(self::TOKEN, '203.0.113.5');

        self::assertFalse(
            \is_wp_error($accepted),
            'The same token is still refused after Renew: '
            . (\is_wp_error($accepted) ? $accepted->get_error_code() : '')
        );
    }

    /**
     * The new window is now + window_secs, and the token itself is not rewritten - a
     * renew that touched token_hash would be a re-mint wearing a different name, and
     * every client would break on the one operation that exists to avoid that.
     *
     * @group sprint-7
     */
    public function testRenewSetsNowPlusTheWindowAndTouchesNothingElse(): void
    {
        $now = time();
        $this->wpdb->row = $this->row($now - self::HOUR, $now + 30 * self::DAY);

        $until = \wpmcp_renew(77);

        self::assertEqualsWithDelta(
            $now + 6 * self::HOUR,
            strtotime($until . ' UTC'),
            5,
            'Renew did not set the window to now + window_secs.'
        );

        self::assertCount(1, $this->wpdb->updates, 'Renew wrote more than one row.');
        self::assertSame(
            ['active_until'],
            array_keys($this->wpdb->updates[0]['data']),
            'Renew wrote a column other than active_until. The token, its scope, its'
            . ' owner and its hard lifetime are all supposed to survive untouched.'
        );
        self::assertSame(['id' => 77], $this->wpdb->updates[0]['where']);
    }

    /**
     * Renewing an ACTIVE token extends it. No failure has to happen first: "I will be
     * away tomorrow" is a legitimate reason to press the button.
     *
     * @group sprint-7
     */
    public function testRenewingAnActiveTokenExtendsIt(): void
    {
        $now = time();
        $this->wpdb->row = $this->row($now + 60, $now + 30 * self::DAY);

        $until = \wpmcp_renew(77);

        self::assertIsString($until);
        self::assertGreaterThan(
            $now + 60,
            strtotime($until . ' UTC'),
            'Renewing a token that was still active moved its window backwards or not at all.'
        );
    }

    /**
     * The clamp that keeps the hard lifetime meaningful: a window that would run past
     * expires_at stops at expires_at.
     *
     * @group sprint-7
     */
    public function testRenewNeverReachesPastTheHardLifetime(): void
    {
        $now      = time();
        $lifetime = $now + 900; // fifteen minutes left, against a six-hour window

        $this->wpdb->row = $this->row($now - self::HOUR, $lifetime);

        $until = \wpmcp_renew(77);

        self::assertIsString($until);
        self::assertSame(
            gmdate('Y-m-d H:i:s', $lifetime),
            $until,
            'Renew pushed the active window past the token\'s hard lifetime. A six-hour'
            . ' window renewed every six hours would then live forever, one press at a'
            . ' time, and expires_at would mean nothing.'
        );
    }

    /**
     * A row carrying a window LONGER than the ceiling cannot renew past the ceiling.
     *
     * Mint clamps and the form clamps, so no row this plugin writes can exceed
     * WPMCP_MAX_WINDOW - but a row migrated from a hand-extended v2 token can, and the
     * one on the bare test site carries ninety days. The `min(..., expires_at)` cap
     * hides it only while the lifetime happens to be near: the day anything moves
     * expires_at forward, an unclamped Renew hands out a ninety-day active window on a
     * model whose entire point is a twelve-hour ceiling.
     *
     * @group sprint-7
     */
    public function testRenewCannotHandOutAWindowLongerThanTheCeiling(): void
    {
        $now = time();

        $this->wpdb->row = $this->row($now - self::HOUR, $now + 365 * self::DAY);
        $this->wpdb->row->window_secs = 90 * self::DAY;

        $until = \wpmcp_renew(77);

        self::assertIsString($until);
        self::assertEqualsWithDelta(
            $now + \WPMCP_MAX_WINDOW,
            strtotime($until . ' UTC'),
            5,
            'Renew honoured a window longer than WPMCP_MAX_WINDOW. Every other path into'
            . ' the model clamps; this one has to as well, or a single hand-edited row'
            . ' becomes a way around the ceiling.'
        );
    }

    /**
     * A dead row is refused, and nothing is written.
     *
     * @group sprint-7
     */
    public function testRenewingADeadTokenIsRefusedAndChangesNothing(): void
    {
        $now = time();
        $this->wpdb->row = $this->row($now - 30 * self::DAY, $now - self::HOUR);

        $result = \wpmcp_renew(77);

        self::assertTrue(\is_wp_error($result), 'A dead token was renewed.');
        self::assertSame('dead', $result->get_error_code());
        self::assertSame([], $this->wpdb->updates, 'A refused renew still wrote to the row.');
        self::assertSame(
            [],
            WordPressRuntime::firedActions('wpmcp_auth_event'),
            'A refused renew fired a renew event, so the log would report a renewal that'
            . ' did not happen.'
        );
    }

    /**
     * A row that is not there is refused too, with its own code - the admin table and
     * the POST that renews from it are two separate requests, so a row can be revoked
     * in between.
     *
     * @group sprint-7
     */
    public function testRenewingAMissingRowIsRefused(): void
    {
        $this->wpdb->row = null;

        $result = \wpmcp_renew(4242);

        self::assertTrue(\is_wp_error($result));
        self::assertSame('not_found', $result->get_error_code());
        self::assertSame([], $this->wpdb->updates);
    }

    /**
     * The renew event, with the ACTOR - who pressed the button - alongside the token's
     * own owner. Those are different people whenever an administrator manages somebody
     * else's token, and an audit log that cannot tell them apart is not an audit log.
     *
     * @group sprint-7
     */
    public function testTheRenewEventNamesTheActorTheOwnerAndTheWindow(): void
    {
        $now = time();
        $this->wpdb->row = $this->row($now - self::HOUR, $now + 30 * self::DAY);

        \wpmcp_renew(77);

        $fired = WordPressRuntime::firedActions('wpmcp_auth_event');

        self::assertCount(1, $fired, 'Renew did not fire exactly one auth event.');

        [$type, $context] = $fired[0];

        self::assertSame('renew', $type);
        self::assertSame(77, (int) $context['token_id']);
        self::assertSame(12, (int) $context['user_id'], 'The event does not name the token\'s owner.');
        self::assertSame(3, (int) $context['actor'], 'The event does not name who pressed Renew.');
        self::assertSame(6 * self::HOUR, (int) $context['window']);
        self::assertArrayHasKey('ip', $context, 'Every auth event carries the caller\'s address.');

        $flat = strtolower((string) json_encode($context));

        self::assertStringNotContainsString(
            hash('sha256', self::TOKEN),
            $flat,
            'The token hash appears in the renew event context.'
        );
    }

    private function row(int $activeUntil, int $expiresAt): object
    {
        return (object) [
            'id'           => 77,
            'token_hash'   => hash('sha256', self::TOKEN),
            'scope'        => 'read',
            'label'        => 'wpmcp-unit-renew',
            'created_at'   => gmdate('Y-m-d H:i:s', time() - 600),
            'active_until' => gmdate('Y-m-d H:i:s', $activeUntil),
            'window_secs'  => 6 * self::HOUR,
            'expires_at'   => gmdate('Y-m-d H:i:s', $expiresAt),
            'last_used_at' => null,
            'use_count'    => 3,
            'created_by'   => 1,
            'user_id'      => 12,
        ];
    }
}
