<?php
/**
 * One token, many client addresses, every call served.
 *
 * THE FACT THIS EXISTS FOR, measured on a public test site on 2026-09-13: an
 * Anthropic-hosted connector - claude.ai on the web, and Claude Desktop - does not call
 * from one address. Four were observed inside a single minute:
 *
 *     160.79.106.164   160.79.106.185   160.79.106.186   160.79.106.187
 *
 * The plugin used to bind a token to the address of its first tool call and refuse every
 * later request from anywhere else, so the connector authenticated once and was refused
 * for the rest of the session. The pin is gone, and this is the black-box proof: the same
 * token, the same URL, four different client addresses, four served tool calls.
 *
 * HOW THE ADDRESS IS CHANGED. Not by calling from four machines, which a test cannot do.
 * `wpmcp_client_ip()` ends in `apply_filters('wpmcp_client_ip', $ip)` - the documented
 * hook a site behind a CDN uses to read the real client address out of a forwarded header
 * - and this test's mu-plugin does exactly that, taking the value from a header, for this
 * run's requests only. So the server genuinely believes each call came from a different
 * place: the value reaches wpmcp_authorize(), wpmcp_validate() and the auth events by the
 * ordinary path, not by a shortcut past the code under test.
 *
 * @group sprint-7
 */

declare(strict_types=1);

namespace WpMcp\Tests\Integration;

use WpMcp\Tests\Support\Fixtures;
use WpMcp\Tests\Support\FixtureIntegrationTestCase;
use WpMcp\Tests\Support\IntegrationTestCase;
use WpMcp\Tests\Support\MuPlugin;
use WpMcp\Tests\Support\TestRecorder;

final class ConnectorAddressPoolTest extends FixtureIntegrationTestCase
{
    /** Observed 2026-09-13, all four within one minute, from one connector. */
    private const CONNECTOR_POOL = [
        '160.79.106.164',
        '160.79.106.185',
        '160.79.106.186',
        '160.79.106.187',
    ];

    /** The header this test's mu-plugin turns into the client address. */
    private const IP_HEADER = 'X-Wpmcp-Test-Client-Ip';

    private const SLUG = 'client-ip';

    private static function label(): string { return Fixtures::name('pool'); }
    private static function freshLabel(): string { return Fixtures::name('pool-fresh'); }
    private static function login(): string { return Fixtures::name('pool-author'); }

    private static int $userId = 0;
    private static string $token = '';

    /**
     * A token NO other test in this class has called a tool with.
     *
     * The binding it is watching for was created once per token, by the first tool call
     * ever made with it - so a token the class has already used cannot produce the event,
     * and the test that looks for its absence would be green against the very code it
     * rules out. PHPUnit is free to reorder these methods, so this cannot be left to
     * declaration order.
     */
    private static string $freshToken = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::requireSite();

        self::buildFixtures(self::build(...), self::destroy(...));
    }

    private static function build(): void
    {
        Fixtures::purge();

        TestRecorder::install();
        MuPlugin::drop(self::SLUG, self::clientIpOverride());

        self::$userId     = Fixtures::createUser(self::login(), 'administrator');
        self::$token      = Fixtures::mintToken('read', self::label(), self::$userId);
        self::$freshToken = Fixtures::mintToken('read', self::freshLabel(), self::$userId);
    }

    public static function tearDownAfterClass(): void
    {
        self::destroy();

        parent::tearDownAfterClass();
    }

    private static function destroy(): void
    {
        MuPlugin::remove(self::SLUG);
        TestRecorder::uninstall();
        Fixtures::deleteUser(self::$userId);
        Fixtures::deleteTokensLabelled(self::label());
        Fixtures::deleteTokensLabelled(self::freshLabel());
        Fixtures::purge();
    }

    /**
     * The control for the whole class: the override really does change what the server
     * thinks the client address is. Without it, four identical requests would trivially
     * all succeed and this class would assert nothing.
     *
     * The observation is an auth event, because that is where the plugin records the
     * address it decided on - so this reads the value out of the code path under test
     * rather than out of the mu-plugin that set it.
     *
     * @group sprint-7
     */
    public function testTheHarnessCanActuallyChangeTheClientAddress(): void
    {
        TestRecorder::reset();

        // A malformed credential, so the refusal fires validate_fail with the address.
        $this->callFrom(self::CONNECTOR_POOL[2], 'Bearer not-a-token');

        $events = TestRecorder::detailsOf(TestRecorder::AUTH . 'validate_fail');

        self::assertCount(1, $events, 'The refusal did not fire exactly one validate_fail.');
        self::assertSame(
            self::CONNECTOR_POOL[2],
            $events[0]['ip'] ?? null,
            'The server did not see the address this test set, so the four calls below'
            . ' would all come from the same place and prove nothing.'
        );
    }

    /**
     * The sprint: four tool calls, four addresses, one token, all served.
     *
     * tools/call and not tools/list, because the lock used to be created by the FIRST
     * TOOL CALL specifically - discovery was exempt. A version that still locked would
     * serve four tools/list requests happily and refuse the second tools/call.
     *
     * @group sprint-7
     */
    public function testOneTokenServesToolCallsFromEveryAddressInThePool(): void
    {
        foreach (self::CONNECTOR_POOL as $index => $ip) {
            $response = $this->callFrom($ip, 'Bearer ' . self::$token);

            self::assertSame(
                200,
                $response,
                sprintf(
                    'tools/call number %d, from %s, was refused with HTTP %d. The token'
                    . ' was accepted from %s moments earlier, so something is still'
                    . ' binding it to one address - which is exactly what breaks a'
                    . ' claude.ai or Claude Desktop connector.',
                    $index + 1,
                    $ip,
                    $response,
                    self::CONNECTOR_POOL[0]
                )
            );
        }
    }

    /**
     * And no event describing a binding was fired along the way. The refusal is gone from
     * the wire; this is the other half - it is gone from the log too, so an operator is
     * not left reading about a mechanism that no longer exists.
     *
     * @group sprint-7
     */
    public function testNoBindingEventIsFiredByAnyOfThoseCalls(): void
    {
        TestRecorder::reset();

        foreach (self::CONNECTOR_POOL as $ip) {
            $this->callFrom($ip, 'Bearer ' . self::$freshToken);
        }

        $types = [];

        foreach (TestRecorder::events() as $entry) {
            $types[] = (string) ($entry['event'] ?? '');
        }

        self::assertSame(
            [],
            array_values(array_filter(
                $types,
                static fn (string $t) => str_contains($t, 'bind') || str_contains($t, 'mismatch')
            )),
            'A binding or mismatch event still fires: ' . implode(', ', $types)
        );
    }

    /** POST tools/call with the given credential, as if from $ip. Returns the status. */
    private function callFrom(string $ip, string $authorization): int
    {
        return $this->client()->post('wp-json/wpmcp/mcp', [
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => $authorization,
                self::IP_HEADER => $ip,
            ],
            'body' => '{"jsonrpc":"2.0","id":1,"method":"tools/call",'
                . '"params":{"name":"site-info","arguments":{}}}',
        ])->getStatusCode();
    }

    /**
     * The mu-plugin body: `wpmcp_client_ip` answers with this run's header, for this
     * run's requests, and is untouched for everybody else's.
     */
    private static function clientIpOverride(): string
    {
        $run       = Fixtures::runId();
        $runHeader = 'HTTP_' . strtoupper(str_replace('-', '_', IntegrationTestCase::RUN_HEADER));
        $ipHeader  = 'HTTP_' . strtoupper(str_replace('-', '_', self::IP_HEADER));

        return <<<PHP
/**
 * wp-mcp integration-suite client-address override for run {$run}. Dropped and removed
 * by tests/integration/ConnectorAddressPoolTest.php. If you are reading this on a live
 * site, the run that wrote it crashed; deleting the file is safe.
 *
 * It uses the plugin's own documented `wpmcp_client_ip` filter - the one a site behind a
 * CDN uses - so the value travels the ordinary path into wpmcp_authorize(),
 * wpmcp_validate() and the auth events.
 */
add_filter('wpmcp_client_ip', static function (\$ip) {
    if (!isset(\$_SERVER['{$runHeader}']) || \$_SERVER['{$runHeader}'] !== '{$run}') {
        return \$ip;
    }

    if (!isset(\$_SERVER['{$ipHeader}'])) {
        return \$ip;
    }

    \$claimed = (string) \$_SERVER['{$ipHeader}'];

    return filter_var(\$claimed, FILTER_VALIDATE_IP) ? \$claimed : \$ip;
});
PHP;
    }
}
