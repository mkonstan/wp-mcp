<?php
/**
 * A token is not bound to an address, and calling it from a second one is not a refusal.
 *
 * WHAT THIS REPLACES AND WHY. The plugin used to pin a token to the address of its first
 * tool call and refuse every later request from anywhere else - trust on first use, one
 * address per token. That is incompatible with the clients this server exists for.
 * Measured on a public test site on 2026-09-13: an Anthropic-hosted connector
 * (claude.ai web, Claude Desktop) reaches the server from a POOL of egress addresses -
 * 160.79.106.164, .185, .186 and .187 all inside one minute - so the pin bound the token
 * to whichever one happened to be first and answered 401 to every call after it. No
 * amount of address bookkeeping fixes that; there is no single address to pin.
 *
 * THE ASSERTION IS "BOTH ARE ACCEPTED", not "no comparison is made". A test that read
 * the source, or counted queries, would pass on a version that still compared and
 * happened not to refuse. Two calls, two addresses, two accepted rows back.
 *
 * The `ip` argument is still THERE, and still reaches the auth events - an operator
 * reading the log wants to know where a refusal came from. It simply no longer decides
 * anything.
 *
 * @group sprint-7
 */

declare(strict_types=1);

namespace WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WpMcp\Tests\Support\FakeWpdb;
use WpMcp\Tests\Support\WordPressRuntime;
use WpMcp\Tests\Support\WordPressStubs;

final class NoAddressBindingTest extends TestCase
{
    /** Four addresses one Anthropic-hosted connector was observed using in one minute. */
    private const CONNECTOR_POOL = [
        '160.79.106.164',
        '160.79.106.185',
        '160.79.106.186',
        '160.79.106.187',
    ];

    private const TOKEN = 'f0e1d2c3b4a5968778695a4b3c2d1e0ff0e1d2c3b4a5968778695a4b3c2d1e0f';

    private FakeWpdb $wpdb;

    protected function setUp(): void
    {
        parent::setUp();

        WordPressStubs::loadPlugin();
        $this->wpdb = WordPressRuntime::install();
        WordPressRuntime::addUser(12, 'wpmcp-unit-owner');

        $this->wpdb->row = $this->liveRow();
    }

    /**
     * A row carrying the column the old pin lived in, set to ONE of the four addresses.
     *
     * This is what makes the test able to fail. On the pre-sprint code the first address
     * matches and is accepted, and the other three are refused with `ip_mismatch`; the
     * value has to be present, or the branch is never reached and the test is green
     * against the very code it exists to rule out. A live site's table no longer has
     * this column at all - the v3 migration drops it - so a row still carrying it is
     * also the honest worst case: a stale row from a site mid-upgrade must not revive
     * the pin.
     */
    private function rowCarryingTheOldPinColumn(): object
    {
        $row = $this->liveRow();
        $row->bound_ip = self::CONNECTOR_POOL[0];

        return $row;
    }

    /**
     * The whole sprint in one assertion: the same live token, four addresses, four
     * acceptances.
     *
     * @group sprint-7
     */
    public function testTheSameTokenIsAcceptedFromEveryAddressInAConnectorsPool(): void
    {
        $this->wpdb->row = $this->rowCarryingTheOldPinColumn();

        foreach (self::CONNECTOR_POOL as $ip) {
            $row = \wpmcp_validate(self::TOKEN, $ip);

            self::assertFalse(
                \is_wp_error($row),
                "wpmcp_validate() refused the token from {$ip}: "
                . (\is_wp_error($row) ? $row->get_error_code() : 'unknown')
                . '. An Anthropic-hosted connector calls from all four of these inside'
                . ' one minute, so any per-address rule breaks it on the second call.'
            );
            self::assertSame(12, (int) $row->user_id);
        }
    }

    /**
     * Nothing is written back that could become a binding. The use counters are the only
     * thing validation updates, and a column recording an address would be the pin
     * growing back under another name.
     *
     * @group sprint-7
     */
    public function testValidationWritesNothingThatRecordsTheCallersAddress(): void
    {
        \wpmcp_validate(self::TOKEN, self::CONNECTOR_POOL[1]);

        self::assertNotSame([], $this->wpdb->updates, 'The use counters were not updated.');

        foreach ($this->wpdb->updates as $update) {
            self::assertSame(
                ['last_used_at', 'use_count'],
                array_keys($update['data']),
                'wpmcp_validate() wrote a column other than the two use counters.'
            );

            foreach ($update['data'] as $value) {
                self::assertNotContains(
                    $value,
                    self::CONNECTOR_POOL,
                    'The caller\'s address was written into the token row.'
                );
            }
        }
    }

    /**
     * The refusal that IS still address-independent stays address-independent: a token
     * whose user has been deleted is refused from every address, not just some. Without
     * this, "accepted from four addresses" could be satisfied by a validate() that had
     * stopped refusing anything at all.
     *
     * @group sprint-7
     */
    public function testARealRefusalStillRefuses(): void
    {
        $this->wpdb->row = $this->rowCarryingTheOldPinColumn();
        $this->wpdb->row->user_id = 4242; // no such user in the runtime stubs

        foreach (self::CONNECTOR_POOL as $ip) {
            $result = \wpmcp_validate(self::TOKEN, $ip);

            self::assertTrue(\is_wp_error($result), "The dead token was accepted from {$ip}.");
            self::assertSame('user_missing', $result->get_error_code());
        }
    }

    /**
     * The address still reaches the auth event, because the log is where an operator
     * finds out where a refusal came from. Removing the pin removed the decision, not
     * the record.
     *
     * @group sprint-7
     */
    public function testTheAddressIsStillRecordedOnARefusal(): void
    {
        \wpmcp_validate('not-a-token', '198.51.100.9');

        $fired = WordPressRuntime::firedActions('wpmcp_auth_event');

        self::assertNotSame([], $fired, 'No auth event fired for a malformed token.');

        [$type, $context] = $fired[0];

        self::assertSame('validate_fail', $type);
        self::assertSame('malformed', $context['reason']);
        self::assertSame('198.51.100.9', $context['ip']);
    }

    /** A row exactly as the table holds one: live, owned by an existing user. */
    private function liveRow(): object
    {
        return (object) [
            'id'           => 77,
            'token_hash'   => hash('sha256', self::TOKEN),
            'scope'        => 'read',
            'label'        => 'wpmcp-unit-pool',
            'created_at'   => gmdate('Y-m-d H:i:s', time() - 60),
            'active_until' => gmdate('Y-m-d H:i:s', time() + 3600),
            'window_secs'  => 3600,
            'expires_at'   => gmdate('Y-m-d H:i:s', time() + 30 * 86400),
            'last_used_at' => null,
            'use_count'    => 3,
            'created_by'   => 1,
            'user_id'      => 12,
        ];
    }
}
