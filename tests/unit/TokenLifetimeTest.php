<?php
/**
 * Two timers per token, and the three states they produce.
 *
 * THE PROBLEM THE SECOND TIMER SOLVES. One expiry has to be both things at once and
 * cannot be: short, so a leaked token dies quickly, and long, so a hosted connector is
 * not deleted and re-added twice a day. claude.ai cannot edit a connector's request
 * header once the connector exists, so "mint a new token" means "delete and re-add the
 * connector" - a 12-hour cap made that a twice-daily chore, and anything longer made
 * every token a twice-yearly liability.
 *
 * So a token carries both:
 *
 *   window_secs  + active_until   the ACTIVE WINDOW. Short (6 h by default, 12 h at
 *                                 most). When it elapses the token goes DORMANT: it is
 *                                 refused, exactly as any other bad credential is, but
 *                                 its row survives and an admin can press Renew, which
 *                                 restarts the window without changing the token. The
 *                                 client never learns anything happened.
 *   expires_at                    the hard LIFETIME, up to 365 days. Past it the token
 *                                 is DEAD: no renew, mint a new one.
 *
 * DORMANT AND DEAD ARE ONE ANSWER ON THE WIRE. Both are the same byte-identical 401 as
 * every other refusal - see OneUnauthorizedTest. They differ only in the auth event's
 * `reason`, which is what tells the operator whether to press Renew or to mint.
 *
 * @group sprint-7
 */

declare(strict_types=1);

namespace WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WpMcp\Tests\Support\FakeWpdb;
use WpMcp\Tests\Support\WordPressRuntime;
use WpMcp\Tests\Support\WordPressStubs;

final class TokenLifetimeTest extends TestCase
{
    private const HOUR = 3600;
    private const DAY  = 86400;

    private const TOKEN = 'abcdef0123456789abcdef0123456789abcdef0123456789abcdef0123456789';

    private FakeWpdb $wpdb;

    protected function setUp(): void
    {
        parent::setUp();

        WordPressStubs::loadPlugin();
        $this->wpdb = WordPressRuntime::install();
        WordPressRuntime::logInAs(3, 'wpmcp-unit-admin');
    }

    /* ---------------------------------------------------------------- the two caps */

    /**
     * @group sprint-7
     */
    public function testTheWindowAndTheLifetimeAreBothWrittenToTheRow(): void
    {
        $before = time();

        $minted = \wpmcp_mint('read', 'both timers', 6 * self::HOUR, 30 * self::DAY);

        self::assertIsArray($minted, 'Minting with a window and a lifetime failed.');

        $row = $this->wpdb->lastInsertData();

        self::assertSame(6 * self::HOUR, (int) $row['window_secs']);
        self::assertEqualsWithDelta(
            $before + 6 * self::HOUR,
            strtotime($row['active_until'] . ' UTC'),
            5,
            'active_until is not now + the window.'
        );
        self::assertEqualsWithDelta(
            $before + 30 * self::DAY,
            strtotime($row['expires_at'] . ' UTC'),
            5,
            'expires_at is not now + the lifetime.'
        );
    }

    /**
     * The window is clamped to [60 s, 12 h] and the lifetime to [window, 365 d].
     *
     * The LOWER bound of the lifetime is the window, not a day: a lifetime shorter than
     * the window would make active_until later than expires_at, i.e. a token that is
     * dead and active at the same time. That is a state the three-way test below could
     * not describe.
     *
     * @group sprint-7
     */
    public function testBothTimersAreClamped(): void
    {
        $cases = [
            'window over the cap'     => [13 * self::HOUR, 30 * self::DAY, 12 * self::HOUR, 30 * self::DAY],
            'window under the floor'  => [10,              30 * self::DAY, 60,              30 * self::DAY],
            'lifetime over the cap'   => [6 * self::HOUR,  400 * self::DAY, 6 * self::HOUR, 365 * self::DAY],
            'lifetime under the window' => [6 * self::HOUR, 60,            6 * self::HOUR,  6 * self::HOUR],
            'both at the cap'         => [12 * self::HOUR, 365 * self::DAY, 12 * self::HOUR, 365 * self::DAY],
        ];

        foreach ($cases as $what => [$window, $lifetime, $expectWindow, $expectLifetime]) {
            $before = time();

            self::assertIsArray(
                \wpmcp_mint('read', $what, $window, $lifetime),
                "Minting the \"{$what}\" case failed."
            );

            $row = $this->wpdb->lastInsertData();

            self::assertSame(
                $expectWindow,
                (int) $row['window_secs'],
                "The \"{$what}\" case did not clamp the window."
            );
            self::assertEqualsWithDelta(
                $before + $expectLifetime,
                strtotime($row['expires_at'] . ' UTC'),
                5,
                "The \"{$what}\" case did not clamp the lifetime."
            );
        }
    }

    /**
     * The two caps are constants, so the form and the docs can name them, and the old
     * single cap is gone rather than renamed.
     *
     * @group sprint-7
     */
    public function testTheCapsAreTheDocumentedConstants(): void
    {
        self::assertSame(12 * self::HOUR, \WPMCP_MAX_WINDOW);
        self::assertSame(365 * self::DAY, \WPMCP_MAX_LIFETIME);
        self::assertFalse(
            defined('WPMCP_MAX_TTL'),
            'WPMCP_MAX_TTL still exists. One cap cannot describe two timers, and leaving'
            . ' it defined invites code that reads the wrong one.'
        );
    }

    /* ---------------------------------------------------------------- the three states */

    /**
     * @group sprint-7
     */
    public function testTheThreeStates(): void
    {
        $now = time();

        $cases = [
            'active'  => [$now + self::HOUR, $now + 30 * self::DAY],
            'dormant' => [$now - self::HOUR, $now + 30 * self::DAY],
            'dead'    => [$now - 30 * self::DAY, $now - self::HOUR],
        ];

        foreach ($cases as $expected => [$activeUntil, $expiresAt]) {
            self::assertSame(
                $expected,
                \wpmcp_token_state($this->row($activeUntil, $expiresAt)),
                "A row whose window ended at {$activeUntil} and lifetime at {$expiresAt}"
                . " was not read as {$expected}."
            );
        }
    }

    /**
     * The boundaries, both of them inclusive of the refusal: a token whose window ended
     * exactly now is dormant, and one whose lifetime ended exactly now is dead. The
     * alternative is a one-second hole in which a token is neither.
     *
     * @group sprint-7
     */
    public function testTheBoundariesRefuseRatherThanAllow(): void
    {
        $now = time();

        self::assertSame('dormant', \wpmcp_token_state($this->row($now, $now + 30 * self::DAY)));
        self::assertSame('dead', \wpmcp_token_state($this->row($now - self::HOUR, $now)));
    }

    /**
     * wpmcp_token_status() is the timer state PLUS the one fact the timers cannot see:
     * whether the user the token runs as still exists.
     *
     * A token whose owner was deleted is refused on every request with
     * reason=user_missing, and yet its timers can say `active` for a year. The admin
     * table showed exactly that - `active`, with a Renew button - which is a status
     * column telling an operator a broken token is fine.
     *
     * @group sprint-7
     */
    public function testTheDisplayStatusReportsAMissingOwner(): void
    {
        $now = time();

        // Nobody is registered, so user 12 does not exist.
        self::assertSame(
            'owner_missing',
            \wpmcp_token_status($this->row($now + self::HOUR, $now + 30 * self::DAY)),
            'A token running as a deleted user still reads as usable.'
        );
        self::assertSame(
            'owner_missing',
            \wpmcp_token_status($this->row($now - self::HOUR, $now + 30 * self::DAY))
        );

        WordPressRuntime::addUser(12, 'wpmcp-unit-owner');

        self::assertSame('active', \wpmcp_token_status($this->row($now + self::HOUR, $now + 30 * self::DAY)));
        self::assertSame('dormant', \wpmcp_token_status($this->row($now - self::HOUR, $now + 30 * self::DAY)));
    }

    /**
     * Dead wins over a missing owner: the row is dead either way and the cron is about to
     * remove it, so there is nothing an operator can do about either fact - and the
     * dimming and the withheld Renew button are the same for both.
     *
     * @group sprint-7
     */
    public function testDeadOutranksAMissingOwner(): void
    {
        $now = time();

        self::assertSame(
            'dead',
            \wpmcp_token_status($this->row($now - 30 * self::DAY, $now - self::HOUR))
        );
    }

    /**
     * And the timer function itself stays about the timers. It runs on every request and
     * must not start asking WordPress whether a user exists - wpmcp_validate() already
     * checks that, earlier and for a different reason.
     *
     * @group sprint-7
     */
    public function testTheTimerStateDoesNotAskAboutTheOwner(): void
    {
        $now = time();

        self::assertSame(
            'active',
            \wpmcp_token_state($this->row($now + self::HOUR, $now + 30 * self::DAY)),
            'wpmcp_token_state() changed its answer because of the owner. That is'
            . ' wpmcp_token_status()\'s job; this one is called on every request.'
        );
    }

    /* ---------------------------------------------------------------- validation */

    /**
     * A dormant token is refused, the reason says `dormant`, and THE ROW SURVIVES -
     * which is the whole point, because Renew has to have something to renew.
     *
     * @group sprint-7
     */
    public function testADormantTokenIsRefusedAndItsRowIsKept(): void
    {
        WordPressRuntime::addUser(12, 'wpmcp-unit-owner');

        $now = time();
        $this->wpdb->row = $this->row($now - self::HOUR, $now + 30 * self::DAY);

        $result = \wpmcp_validate(self::TOKEN, '203.0.113.5');

        self::assertTrue(\is_wp_error($result), 'A dormant token was accepted.');
        self::assertSame('dormant', $result->get_error_code());

        self::assertSame(
            [],
            $this->wpdb->deletes,
            'The dormant row was deleted. Renew exists precisely so that a token whose'
            . ' window has closed can be brought back without touching the client.'
        );

        $events = WordPressRuntime::firedActions('wpmcp_auth_event');
        self::assertCount(1, $events);
        self::assertSame('validate_fail', $events[0][0]);
        self::assertSame('dormant', $events[0][1]['reason']);
        self::assertSame(77, (int) $events[0][1]['token_id']);
    }

    /**
     * A dead token is refused with `expired`, and its row is not deleted here either -
     * the hourly cron is what removes dead rows, and a refusal is not the place to do
     * database housekeeping.
     *
     * @group sprint-7
     */
    public function testADeadTokenIsRefusedAndValidationDeletesNothing(): void
    {
        WordPressRuntime::addUser(12, 'wpmcp-unit-owner');

        $now = time();
        $this->wpdb->row = $this->row($now - 30 * self::DAY, $now - self::HOUR);

        $result = \wpmcp_validate(self::TOKEN, '203.0.113.5');

        self::assertTrue(\is_wp_error($result), 'A dead token was accepted.');
        self::assertSame('expired', $result->get_error_code());
        self::assertSame([], $this->wpdb->deletes, 'wpmcp_validate() deleted a row.');

        $events = WordPressRuntime::firedActions('wpmcp_auth_event');
        self::assertSame('expired', $events[0][1]['reason']);
    }

    /**
     * And an active token is still accepted, so the two refusals above are not simply
     * "everything is refused now".
     *
     * @group sprint-7
     */
    public function testAnActiveTokenIsStillAccepted(): void
    {
        WordPressRuntime::addUser(12, 'wpmcp-unit-owner');

        $now = time();
        $this->wpdb->row = $this->row($now + self::HOUR, $now + 30 * self::DAY);

        self::assertFalse(
            \is_wp_error(\wpmcp_validate(self::TOKEN, '203.0.113.5')),
            'A token inside its active window was refused.'
        );
    }

    /* ---------------------------------------------------------------- the form path */

    /**
     * The admin form's own defaults and clamps, which is where a human actually chooses
     * these numbers. Asserted separately from wpmcp_mint()'s clamp because the form has
     * its own units - hours and days - and its own answer for a field left empty.
     *
     * @group sprint-7
     */
    public function testTheMintFormDefaultsToSixHoursAndThirtyDays(): void
    {
        self::assertSame(6 * self::HOUR, \wpmcp_form_window_secs(null));
        self::assertSame(30 * self::DAY, \wpmcp_form_lifetime_secs(null));
        self::assertSame(6 * self::HOUR, \wpmcp_form_window_secs(''));
        self::assertSame(30 * self::DAY, \wpmcp_form_lifetime_secs(''));
    }

    /**
     * @group sprint-7
     */
    public function testTheMintFormClampsWhatAHumanCanType(): void
    {
        self::assertSame(12 * self::HOUR, \wpmcp_form_window_secs('13'));
        self::assertSame(12 * self::HOUR, \wpmcp_form_window_secs('9999'));
        self::assertSame((int) (0.5 * self::HOUR), \wpmcp_form_window_secs('0.25'));
        self::assertSame((int) (7.5 * self::HOUR), \wpmcp_form_window_secs('7.5'));

        self::assertSame(365 * self::DAY, \wpmcp_form_lifetime_secs('400'));
        self::assertSame(self::DAY, \wpmcp_form_lifetime_secs('0'));
        self::assertSame(self::DAY, \wpmcp_form_lifetime_secs('-5'));
        self::assertSame(90 * self::DAY, \wpmcp_form_lifetime_secs('90'));
    }

    /** A token row with the two timers at the given UNIX times. */
    private function row(int $activeUntil, int $expiresAt): object
    {
        return (object) [
            'id'           => 77,
            'token_hash'   => hash('sha256', self::TOKEN),
            'scope'        => 'read',
            'label'        => 'wpmcp-unit-lifetime',
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
