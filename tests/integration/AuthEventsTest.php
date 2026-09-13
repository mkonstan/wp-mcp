<?php
/**
 * The events an operator sees. Each one, at its own trigger, exactly once.
 *
 * Item 5. The Sprint 1 review's S8 said it plainly: nothing in the plugin logged
 * anything at all - no error_log, no do_action, no trigger_error outside one wp_die - so
 * an operator watching a site had no way to know a token had been refused, let alone why.
 * Item 4 made that worse on purpose by collapsing six distinguishable refusals into one,
 * which is only acceptable if the distinction moved somewhere the operator can reach.
 * This class is the proof that it did.
 *
 * EACH EVENT IS OBSERVED AT ITS OWN TRIGGER, and counted. "An event fired" is a weak
 * claim; "exactly one fired, for this trigger, with this context" is the claim that
 * catches both a missing event and a duplicated one. The duplicate was real: WordPress
 * calls a permission_callback twice per request (rest_send_allow_header, on
 * rest_post_dispatch, to compute the Allow header), and the first version of these tests
 * found every event firing twice.
 *
 * HOW THE OBSERVATION WORKS. mint and revoke happen under wp-cli, so those two are
 * observed by a listener registered inside the same `wp eval` - one process, no shared
 * state, nothing another runner can pollute. Everything else happens inside an HTTP
 * request, so it is observed by the per-run mu-plugin recorder, which only records
 * requests carrying this run's header.
 *
 * @group sprint-2
 */

declare(strict_types=1);

namespace WpMcp\Tests\Integration;

use WpMcp\Tests\Support\Fixtures;
use WpMcp\Tests\Support\FixtureIntegrationTestCase;
use WpMcp\Tests\Support\TestRecorder;
use WpMcp\Tests\Support\WpCli;

final class AuthEventsTest extends FixtureIntegrationTestCase
{
    private static function readLabel(): string { return Fixtures::name('events-read'); }
    private static function mintLabel(): string { return Fixtures::name('events-mint'); }
    private static function login(): string { return Fixtures::name('events-author'); }

    private static int $userId = 0;
    private static string $readToken = '';

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

        self::$userId = Fixtures::createUser(self::login(), 'author');

        // read scope, so the scope gate has something to refuse.
        self::$readToken = Fixtures::mintToken('read', self::readLabel(), self::$userId);
    }

    public static function tearDownAfterClass(): void
    {
        self::destroy();

        parent::tearDownAfterClass();
    }

    private static function destroy(): void
    {
        TestRecorder::uninstall();
        Fixtures::deleteUser(self::$userId);

        foreach ([self::readLabel(), self::mintLabel()] as $label) {
            Fixtures::deleteTokensLabelled($label);
        }

        Fixtures::purge();
    }

    /**
     * mint and revoke, both observed inside the one process that performs them.
     *
     * Two events, in order, each once. The mint event carries the new row's id and the
     * user it was bound to; the revoke event carries the same id. Neither carries the
     * token - asserted by checking that the raw value the snippet saw never appears in
     * what the listener recorded.
     *
     * @group sprint-2
     */
    public function testMintAndRevokeEachFireExactlyOneEvent(): void
    {
        $json = WpCli::evaluate(
            '$seen = array();'
            . ' add_action("wpmcp_auth_event", function ($type, $context) use (&$seen) {'
            . '  $seen[] = array("type" => $type, "context" => $context);'
            . ' }, 10, 2);'
            . ' $minted = wpmcp_mint("read", ' . self::phpString(self::mintLabel())
            . ', 3600, 30 * DAY_IN_SECONDS, ' . (int) self::$userId . ');'
            . ' if (is_wp_error($minted)) { echo wp_json_encode(array("error" => $minted->get_error_message())); return; }'
            . ' $revoked = wpmcp_revoke($minted["id"]);'
            . ' echo wp_json_encode(array('
            . '  "id" => (int) $minted["id"],'
            . '  "raw_length" => strlen($minted["raw"]),'
            . '  "raw_seen_in_events" => (strpos(wp_json_encode($seen), $minted["raw"]) !== false),'
            . '  "hash_seen_in_events" => (strpos(wp_json_encode($seen), wpmcp_hash($minted["raw"])) !== false),'
            . '  "revoked" => (bool) $revoked,'
            . '  "seen" => $seen'
            . ' ));',
            1
        );

        $result = json_decode($json, true);

        self::assertIsArray($result, "The mint/revoke snippet did not return JSON: {$json}");
        self::assertArrayNotHasKey('error', $result, 'Minting failed: ' . ($result['error'] ?? ''));
        self::assertTrue($result['revoked'], 'The token was not revoked, so no revoke event could fire.');
        self::assertSame(64, $result['raw_length'], 'The snippet did not mint a real token.');

        $types = array_column($result['seen'], 'type');

        self::assertSame(
            ['mint', 'revoke'],
            $types,
            'Minting and revoking one token did not fire exactly one mint event followed'
            . ' by exactly one revoke event.'
        );

        self::assertSame($result['id'], $result['seen'][0]['context']['token_id']);
        self::assertSame(self::$userId, $result['seen'][0]['context']['user_id']);
        self::assertSame('read', $result['seen'][0]['context']['scope']);
        self::assertSame($result['id'], $result['seen'][1]['context']['token_id']);
        self::assertSame(self::$userId, $result['seen'][1]['context']['user_id']);

        self::assertFalse(
            $result['raw_seen_in_events'],
            'The raw token appears somewhere in the mint/revoke event contexts.'
        );
        self::assertFalse(
            $result['hash_seen_in_events'],
            'The token hash appears somewhere in the mint/revoke event contexts.'
        );
    }

    /**
     * An ACCEPTED request fires nothing at all.
     *
     * REPLACES the two address-binding tests that stood here until Sprint 7. Those
     * asserted that a token's first tool call fired exactly one binding event and that
     * discovery fired none; the binding they described does not exist any more (see
     * tests/integration/ConnectorAddressPoolTest.php for why), so they are not
     * rewritten - the claim they made is gone.
     *
     * What is left is the claim that outlived them and that the README states: there is
     * NO success event, so an audit listener waiting for an "ok" waits forever. It was
     * true before and it is stricter now, because the one exception has been removed.
     *
     * @group sprint-7
     */
    public function testAnAcceptedRequestFiresNoAuthEventAtAll(): void
    {
        TestRecorder::reset();

        $mcp = $this->mcp(self::$readToken);

        $discovery = $mcp->post('tools/list');
        self::assertSame(200, $discovery->getStatusCode(), (string) $discovery->getBody());

        $call = $mcp->callTool('site-info');
        self::assertFalse($call->isError, 'The fixture token could not call a tool: ' . $call->text);

        $types = array_map(
            static fn (array $e): string => (string) ($e['event'] ?? ''),
            TestRecorder::events()
        );

        self::assertSame(
            [],
            array_values(array_filter(
                $types,
                static fn (string $t) => str_starts_with($t, TestRecorder::AUTH)
            )),
            'An accepted discovery and an accepted tool call fired auth events: '
            . implode(', ', $types)
        );
    }

    /**
     * scope_deny: a read-scope token asking for a write tool.
     *
     * The refusal itself is a 200 with isError, not an HTTP status, because the caller
     * IS authenticated and is asking for something it may not have - so the event is
     * the only place this shows up for an operator.
     *
     * @group sprint-2
     */
    public function testAScopeRefusalFiresExactlyOneScopeDenyEvent(): void
    {
        TestRecorder::reset();

        $result = $this->mcp(self::$readToken)->callTool('create-post', [
            'title' => Fixtures::name('read-token-should-not-create'),
        ]);

        self::assertTrue($result->isError, 'A read-scope token was allowed to call create-post.');
        self::assertStringContainsString('admin-scope token', $result->text);

        self::assertSame(
            1,
            TestRecorder::countOf(TestRecorder::AUTH . 'scope_deny'),
            'The scope refusal did not fire exactly one scope_deny event.'
        );

        $context = TestRecorder::detailsOf(TestRecorder::AUTH . 'scope_deny')[0];

        self::assertSame('create-post', $context['tool'] ?? null, 'The event does not name the tool.');
        self::assertSame('read', $context['scope'] ?? null);
        self::assertSame(self::$userId, (int) $context['user_id']);
    }

    /**
     * The default listener is attached, and a site can take it off.
     *
     * Both halves matter. Attached, or an operator who installs the plugin and looks at
     * the error log sees nothing. Removable, or a site that ships its own listener -
     * or that must not write auth decisions to a shared log file - cannot say so.
     *
     * @group sprint-2
     */
    public function testTheDefaultListenerIsAttachedAndCanBeRemoved(): void
    {
        $out = WpCli::evaluate(
            'echo wp_json_encode(array('
            . '  "attached" => has_action("wpmcp_auth_event", "wpmcp_log_auth_event"),'
            . '  "after_remove" => (remove_action("wpmcp_auth_event", "wpmcp_log_auth_event")'
            . '    ? has_action("wpmcp_auth_event", "wpmcp_log_auth_event") : "remove_failed")'
            . '));'
        );

        $result = json_decode($out, true);

        self::assertIsArray($result, "The listener snippet did not return JSON: {$out}");
        self::assertSame(
            10,
            $result['attached'],
            'wpmcp_log_auth_event is not attached to wpmcp_auth_event at priority 10,'
            . ' so nothing is written to the log by default.'
        );
        self::assertFalse(
            $result['after_remove'],
            'remove_action() did not detach the default listener, so a site cannot opt'
            . ' out of it. Got: ' . json_encode($result['after_remove'])
        );
    }


    private static function phpString(string $value): string
    {
        return "'" . addcslashes($value, "'\\") . "'";
    }
}
