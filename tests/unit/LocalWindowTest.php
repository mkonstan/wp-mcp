<?php
/**
 * The active-window cap is 30 days on a site that reports itself `local`, and 12 hours
 * everywhere else (sprint 14b).
 *
 * WHY THE CAP DEPENDS ON THE SITE. The twelve-hour ceiling exists so a human looks at a
 * connector token every day. On a local development site nothing reaches the endpoint
 * but the developer's own clients, and the daily checkpoint only makes Claude's MCP
 * servers fail to connect in the morning. So the ceiling relaxes there, and ONLY there:
 * `wp_get_environment_type()` must answer exactly 'local'. 'development' and 'staging'
 * keep twelve hours, because a development server can face the internet.
 *
 * THIS TIER TESTS BOTH BRANCHES UNDER ONE LOADED PLUGIN, which a real WordPress cannot:
 * core caches its environment answer for the life of the process. The stub reads a
 * global instead (WordPressRuntime::setEnvironmentType()). The integration tier proves
 * the local branch on real sites and the other branch through the narrowing filter.
 *
 * @group sprint-14b
 */

declare(strict_types=1);

namespace WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WpMcp\Tests\Support\FakeWpdb;
use WpMcp\Tests\Support\WordPressRuntime;
use WpMcp\Tests\Support\WordPressStubs;

final class LocalWindowTest extends TestCase
{
    private const HOUR = 3600;
    private const DAY  = 86400;

    private const TOKEN = 'fedcba9876543210fedcba9876543210fedcba9876543210fedcba9876543210';

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
     * The non-local cap keeps its constant, so everything that names WPMCP_MAX_WINDOW
     * still means twelve hours, and the local cap is a constant of its own.
     *
     * @group sprint-14b
     */
    public function testTheTwoCapsAreConstants(): void
    {
        self::assertSame(12 * self::HOUR, \WPMCP_MAX_WINDOW);
        self::assertSame(30 * self::DAY, \WPMCP_LOCAL_MAX_WINDOW);
    }

    /**
     * Exactly 'local' - not 'development', not 'staging', not 'Local', not ''.
     *
     * @group sprint-14b
     */
    public function testOnlyTheExactLocalTypeWidensTheCap(): void
    {
        $expected = [
            'local'       => 30 * self::DAY,
            'development' => 12 * self::HOUR,
            'staging'     => 12 * self::HOUR,
            'production'  => 12 * self::HOUR,
            'Local'       => 12 * self::HOUR,
            ' local'      => 12 * self::HOUR,
            ''            => 12 * self::HOUR,
        ];

        foreach ($expected as $type => $cap) {
            WordPressRuntime::setEnvironmentType($type);

            self::assertSame(
                $cap,
                \wpmcp_max_window(),
                "Environment type '{$type}' produced the wrong window cap."
            );
            self::assertSame($type === 'local', \wpmcp_is_local_environment());
        }
    }

    /**
     * The seam narrows and can never widen. On a local site a filter answering false
     * restores twelve hours; on any other site a filter answering true changes nothing,
     * because the filter is not even asked.
     *
     * @group sprint-14b
     */
    public function testTheFilterCanNarrowTheLocalCapAndCannotWidenAnyOther(): void
    {
        WordPressRuntime::setEnvironmentType('local');
        WordPressRuntime::addFilter('wpmcp_local_environment', static fn () => false);

        self::assertSame(12 * self::HOUR, \wpmcp_max_window(), 'The filter did not narrow a local site.');

        foreach (['production', 'staging', 'development'] as $type) {
            WordPressRuntime::setEnvironmentType($type);
            WordPressRuntime::addFilter('wpmcp_local_environment', static fn () => true);

            self::assertSame(
                12 * self::HOUR,
                \wpmcp_max_window(),
                "A filter answering true widened the cap on a '{$type}' site."
            );
        }
    }

    /**
     * G1 in this tier: mint accepts thirty days on a local site and clamps above it.
     *
     * @group sprint-14b
     */
    public function testMintOnALocalSiteAcceptsThirtyDaysAndClampsAboveIt(): void
    {
        WordPressRuntime::setEnvironmentType('local');

        foreach ([30 * self::DAY => 30 * self::DAY, 40 * self::DAY => 30 * self::DAY] as $asked => $kept) {
            $before = time();

            self::assertIsArray(\wpmcp_mint('admin', 'local window', $asked, 365 * self::DAY));

            $row = $this->wpdb->lastInsertData();

            self::assertSame($kept, (int) $row['window_secs']);
            self::assertEqualsWithDelta($before + $kept, strtotime($row['active_until'] . ' UTC'), 5);
        }
    }

    /**
     * G2 in this tier: every other type keeps twelve hours for mint.
     *
     * @group sprint-14b
     */
    public function testMintEverywhereElseClampsToTwelveHours(): void
    {
        foreach (['production', 'staging', 'development'] as $type) {
            WordPressRuntime::setEnvironmentType($type);

            self::assertIsArray(\wpmcp_mint('admin', $type, 30 * self::DAY, 365 * self::DAY));

            self::assertSame(
                12 * self::HOUR,
                (int) $this->wpdb->lastInsertData()['window_secs'],
                "Mint on a '{$type}' site kept a window over twelve hours."
            );
        }
    }

    /**
     * Renew resets to now + the stored window on a local site, and clamps that same row
     * to twelve hours anywhere else - a database copied from a local site to a public one
     * brings its thirty-day rows with it, and Renew there must not honour them.
     *
     * @group sprint-14b
     */
    public function testRenewHonoursAThirtyDayRowOnlyOnALocalSite(): void
    {
        foreach (['local' => 30 * self::DAY, 'production' => 12 * self::HOUR, 'development' => 12 * self::HOUR] as $type => $window) {
            WordPressRuntime::setEnvironmentType($type);

            $now = time();
            $this->wpdb->row = $this->row($now - self::HOUR, $now + 300 * self::DAY, 30 * self::DAY);

            $until = \wpmcp_renew(77);

            self::assertIsString($until, "Renew refused the row on a '{$type}' site.");
            self::assertEqualsWithDelta(
                $now + $window,
                strtotime($until . ' UTC'),
                5,
                "Renew on a '{$type}' site did not set now + the capped stored window."
            );
        }
    }

    /**
     * The lifetime still bounds the window: a thirty-day renew with ten days of lifetime
     * left ends at the lifetime.
     *
     * @group sprint-14b
     */
    public function testTheLifetimeStillBoundsALocalWindow(): void
    {
        WordPressRuntime::setEnvironmentType('local');

        $now = time();
        $this->wpdb->row = $this->row($now - self::HOUR, $now + 10 * self::DAY, 30 * self::DAY);

        self::assertEqualsWithDelta($now + 10 * self::DAY, strtotime(\wpmcp_renew(77) . ' UTC'), 5);

        $before = time();
        \wpmcp_mint('admin', 'short life', 30 * self::DAY, 5 * self::DAY);
        $row = $this->wpdb->lastInsertData();

        self::assertEqualsWithDelta(
            $before + 30 * self::DAY,
            strtotime($row['expires_at'] . ' UTC'),
            5,
            'A lifetime shorter than the window is raised to the window, as it always was.'
        );
    }

    /**
     * The form's hours field follows the same cap: 720 hours on a local site, 12 elsewhere.
     *
     * @group sprint-14b
     */
    public function testTheMintFormFieldFollowsTheCap(): void
    {
        WordPressRuntime::setEnvironmentType('local');
        self::assertSame(720 * self::HOUR, \wpmcp_form_window_secs('720'));
        self::assertSame(720 * self::HOUR, \wpmcp_form_window_secs('9999'));
        self::assertSame(6 * self::HOUR, \wpmcp_form_window_secs(null));

        WordPressRuntime::setEnvironmentType('development');
        self::assertSame(12 * self::HOUR, \wpmcp_form_window_secs('720'));
    }

    private function row(int $activeUntil, int $expiresAt, int $window): object
    {
        return (object) [
            'id'           => 77,
            'token_hash'   => hash('sha256', self::TOKEN),
            'scope'        => 'admin',
            'label'        => 'wpmcp-unit-local',
            'created_at'   => gmdate('Y-m-d H:i:s', time() - 600),
            'active_until' => gmdate('Y-m-d H:i:s', $activeUntil),
            'window_secs'  => $window,
            'expires_at'   => gmdate('Y-m-d H:i:s', $expiresAt),
            'last_used_at' => null,
            'use_count'    => 0,
            'created_by'   => 1,
            'user_id'      => 12,
        ];
    }
}
