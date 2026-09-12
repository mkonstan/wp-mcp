<?php
/**
 * The envelope, not the payload: which verbs, which body shapes, which size, and what
 * counts as a notification.
 *
 * EVERY ONE OF THESE WAS WRONG IN A WAY A CLIENT WOULD HIT BY ACCIDENT.
 *
 *   Batch. A JSON array body decoded to a PHP list, `isset($body['method'])` was false,
 *     and the endpoint answered -32601 "Method not found: " with an empty method name.
 *     A client that tried a batch was told its method did not exist, which sends an
 *     implementer looking in entirely the wrong place. One named refusal ends that.
 *
 *   Notifications. The test was `strpos($method, 'notifications/') === 0` - a METHOD
 *     NAME PREFIX - and it was wrong in both directions. `{"id": 7, "method":
 *     "notifications/tools/list_changed"}` is a request: it has an id, so JSON-RPC says
 *     it must be answered, and the old code answered 202 with no body, leaving the client
 *     waiting for a reply that was never coming. And a notification with an id-less body
 *     whose method was anything else - a typo, a method from a newer revision - fell
 *     through to the switch and got an error response to a request that must not be
 *     answered at all. The key is the presence of `id`, which is what the specification
 *     actually says.
 *
 *   Verbs. Only POST was registered, so a GET - an address bar, a link prefetcher, an
 *     uptime monitor, a crawler that found the URL in a log - got 404 rest_no_route,
 *     which says "no such endpoint" about an endpoint that exists. And OPTIONS got core's
 *     200 plus the route's whole `help` schema, handed to an unauthenticated caller.
 *
 *   Size. Nothing capped the body. WordPress core json_decode's it before the
 *     permission_callback runs, so the plugin cannot stop the parse - but it can refuse
 *     to do any of its own work on it, which is one comparison against CONTENT_LENGTH.
 *
 * @group sprint-3
 */

declare(strict_types=1);

namespace WpMcp\Tests\Integration;

use WpMcp\Tests\Support\Fixtures;
use WpMcp\Tests\Support\FixtureIntegrationTestCase;
use WpMcp\Tests\Support\MuPlugin;
use WpMcp\Tests\Support\TestRecorder;
use WpMcp\Tests\Support\WpCli;

final class JsonRpcFramingTest extends FixtureIntegrationTestCase
{
    private static function label(): string { return Fixtures::name('framing'); }
    private static function login(): string { return Fixtures::name('framing-author'); }

    /** The route both verb tests aim at - the header form, no token in the path. */
    private const ROUTE = 'wp-json/wpmcp/mcp';

    /** The mu-plugin carrying the tool that records having run. */
    private const MARKER_TOOL = 'marker-tool';

    private static int $userId = 0;
    private static string $token = '';

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
        MuPlugin::drop(self::MARKER_TOOL, self::markerToolSource());

        self::$userId = Fixtures::createUser(self::login(), 'author');
        self::$token  = Fixtures::mintToken('read', self::label(), self::$userId);
    }

    public static function tearDownAfterClass(): void
    {
        self::destroy();

        parent::tearDownAfterClass();
    }

    private static function destroy(): void
    {
        MuPlugin::remove(self::MARKER_TOOL);
        TestRecorder::uninstall();
        Fixtures::deleteUser(self::$userId);
        Fixtures::deleteTokensLabelled(self::label());
        Fixtures::purge();
    }

    /** The tool that leaves a marker behind when its run callback executes. */
    private static function markerTool(): string
    {
        return Fixtures::name('tool-marker');
    }

    /** The transient that tool sets. Fixture-named, so purge() and the debris check see it. */
    private static function markerTransient(): string
    {
        return Fixtures::name('dispatched');
    }

    private static function markerWasSet(): bool
    {
        return WpCli::evaluate(sprintf(
            'echo get_transient(%s) ? "1" : "0";',
            "'" . addcslashes(self::markerTransient(), "'\\") . "'"
        )) === '1';
    }

    private static function clearMarker(): void
    {
        WpCli::tryEvaluate(sprintf(
            'echo (int) delete_transient(%s);',
            "'" . addcslashes(self::markerTransient(), "'\\") . "'"
        ));
    }

    /**
     * A JSON array body is refused by name, with id null.
     *
     * @group sprint-3
     */
    public function testAnArrayBodyIsRefusedAsAnUnsupportedBatch(): void
    {
        $raw = (string) $this->mcp(self::$token)->postRaw(
            '[{"jsonrpc":"2.0","id":1,"method":"ping"},{"jsonrpc":"2.0","id":2,"method":"ping"}]'
        )->getBody();

        $body = json_decode($raw, true);

        self::assertIsArray($body, 'Not JSON: ' . $raw);
        self::assertSame(-32600, $body['error']['code'] ?? null, $raw);
        self::assertSame('Batch requests are not supported', $body['error']['message'] ?? null, $raw);
        self::assertArrayHasKey('id', $body, $raw);
        self::assertNull($body['id'], 'A batch refusal has no request id to echo: ' . $raw);
    }

    /**
     * An EMPTY array is a batch too. json_decode makes `[]` and `{}` the same PHP value,
     * so this is the case a decoded-value check cannot get right and the raw-body check
     * can - which is why the endpoint looks at the first byte.
     *
     * @group sprint-3
     */
    public function testAnEmptyArrayBodyIsAlsoABatch(): void
    {
        $raw = (string) $this->mcp(self::$token)->postRaw('[]')->getBody();

        self::assertSame(
            -32600,
            json_decode($raw, true)['error']['code'] ?? null,
            'An empty JSON array is a zero-length batch and must be refused the same way.'
            . ' Body: ' . $raw
        );
    }

    /**
     * The control for the test below: called WITH an id, the marker tool runs and leaves
     * its marker. Without this, "the marker is absent" would also be satisfied by a tool
     * that never worked at all.
     *
     * @group sprint-3
     */
    public function testTheMarkerToolRunsWhenItIsActuallyCalled(): void
    {
        self::clearMarker();

        $result = $this->mcp(self::$token)->callTool(self::markerTool());

        self::assertFalse($result->isError, $result->text);
        self::assertTrue(
            self::markerWasSet(),
            'The marker tool was called with an id and did not leave its marker, so the'
            . ' absence assertion in the notification test proves nothing.'
        );
    }

    /**
     * No `id` key: 202, empty body, and - the part the name claims - NOTHING DISPATCHED.
     *
     * THE MARKER IS WHAT MAKES THIS REAL. The first version asserted only the 202 and the
     * empty body, which a regression that dispatched the call and then returned 202 would
     * have passed: the side effect would have happened and the test would have been green.
     * So one of the three bodies is an id-less `tools/call` naming a tool whose run
     * callback sets a transient, and the transient must not be there afterwards.
     *
     * Three bodies, because the rule is about the absence of `id` and nothing else: a
     * well-formed notification, one whose method this server does not serve, and an id-less
     * call to a real tool. All three are silence.
     *
     * @depends testTheMarkerToolRunsWhenItIsActuallyCalled
     *
     * @group sprint-3
     */
    public function testABodyWithNoIdIsAcknowledgedAndNotDispatched(): void
    {
        $call = json_encode([
            'jsonrpc' => '2.0',
            'method'  => 'tools/call',
            'params'  => ['name' => self::markerTool(), 'arguments' => []],
        ]);

        $bodies = [
            'a real notification'     => '{"jsonrpc":"2.0","method":"notifications/initialized"}',
            'an unknown method'       => '{"jsonrpc":"2.0","method":"does/not/exist"}',
            'an id-less tools/call'   => (string) $call,
        ];

        foreach ($bodies as $what => $json) {
            self::clearMarker();

            $response = $this->mcp(self::$token)->postRaw($json);
            $raw      = (string) $response->getBody();

            self::assertSame(
                202,
                $response->getStatusCode(),
                'A body with no id (' . $what . ') must be acknowledged with 202 and'
                . ' nothing dispatched. JSON-RPC 2.0 4.1: a server MUST NOT reply to a'
                . ' notification. Body: ' . $raw
            );
            self::assertSame(
                '',
                $raw,
                'The 202 for a notification (' . $what . ') carried a body.'
            );
            self::assertFalse(
                self::markerWasSet(),
                'The id-less request (' . $what . ') was DISPATCHED: the marker tool\'s run'
                . ' callback executed and left its transient behind. A 202 after the side'
                . ' effect has already happened is not "nothing dispatched".'
            );
        }
    }

    /**
     * The other half, and the one the old method-prefix test got backwards: a request
     * WITH an id whose method happens to start with `notifications/` is a question, and
     * gets an answer.
     *
     * @group sprint-3
     */
    public function testAnIdMakesANotificationsMethodAnAnsweredRequest(): void
    {
        $response = $this->mcp(self::$token)->postRaw(
            '{"jsonrpc":"2.0","id":77,"method":"notifications/anything"}'
        );

        $raw  = (string) $response->getBody();
        $body = json_decode($raw, true);

        self::assertSame(
            200,
            $response->getStatusCode(),
            'A request carrying an id was answered 202 with no body, so the endpoint is'
            . ' still deciding by method name instead of by the presence of id. The client'
            . ' is left waiting for a reply it will never get. Body: ' . $raw
        );
        self::assertIsArray($body, 'Not JSON: ' . $raw);
        self::assertSame(-32601, $body['error']['code'] ?? null, $raw);
        self::assertSame(77, $body['id'] ?? null, 'The answer must echo the request id: ' . $raw);
    }

    /**
     * A 5 MiB body is refused 413 by the plugin, BEFORE the token is looked up.
     *
     * THE TOKEN IS DELIBERATELY MALFORMED, and that is the whole ordering claim. The first
     * version sent a VALID token, which cannot distinguish "the cap ran first" from "the
     * cap ran after a successful lookup" - both are 413. A malformed token would be 401 if
     * anything looked at it, so a 413 here can only mean the cap came first.
     *
     * THE BODY IS ASSERTED TOO, not only the status: nginx and PHP each answer 413 of their
     * own accord once their own limits are crossed, so a status-only assertion would pass
     * on a host where this gate does not exist at all.
     *
     * AND THE AUTH EVENTS ARE READ BACK. `body_too_large` must have fired exactly once and
     * `validate_fail` not at all - the positive proof that the token was never examined.
     *
     * @group sprint-3
     */
    public function testAnOversizedBodyIsRefusedBeforeTheTokenIsLookedUp(): void
    {
        $filler  = str_repeat('A', 5 * 1024 * 1024);
        $payload = '{"jsonrpc":"2.0","id":1,"method":"ping","params":{"pad":"' . $filler . '"}}';

        // 64 characters, so it passes the shape gate's length check, and not hex, so the
        // lookup would refuse it: a 401 here would mean the cap runs too late.
        $malformed = str_repeat('z', 64);

        TestRecorder::reset();

        $response = $this->mcp($malformed)->postRaw($payload);
        $raw      = (string) $response->getBody();

        self::assertSame(
            413,
            $response->getStatusCode(),
            'A ' . strlen($payload) . '-byte body with a MALFORMED token was not refused'
            . ' 413. A 401 means the token is examined before the size is, so an oversized'
            . ' body still costs a database round trip. Body: ' . substr($raw, 0, 500)
        );
        self::assertStringContainsString(
            'wpmcp_payload_too_large',
            $raw,
            'Something answered 413, but not this plugin - so the cap in wpmcp_authorize()'
            . ' is not what refused it, and on a host with higher server limits the body'
            . ' would go through. Body: ' . substr($raw, 0, 500)
        );

        self::assertSame(
            1,
            TestRecorder::countOf(TestRecorder::AUTH . 'body_too_large'),
            'The body_too_large auth event did not fire exactly once, so the 413 did not'
            . ' come from the cap. Events: ' . json_encode(TestRecorder::events())
        );
        self::assertSame(
            0,
            TestRecorder::countOf(TestRecorder::AUTH . 'validate_fail'),
            'A validate_fail event fired, so the malformed token WAS looked up before the'
            . ' size was checked. Events: ' . json_encode(TestRecorder::events())
        );
    }

    /**
     * GET is 405 with `Allow: POST, OPTIONS`, and NO TOKEN is sent - the verb gate runs
     * before any token logic, so the refusal must not be a 401.
     *
     * @group sprint-3
     */
    public function testGetIsRefusedWithAnAllowHeader(): void
    {
        $this->assertVerbIsRefused('GET');
    }

    /**
     * @group sprint-3
     */
    public function testDeleteIsRefusedWithAnAllowHeader(): void
    {
        $this->assertVerbIsRefused('DELETE');
    }

    /**
     * OPTIONS is 204 with an empty body - not core's 200 carrying the route's whole
     * `help` schema to an unauthenticated caller.
     *
     * @group sprint-3
     */
    public function testOptionsIsAnEmpty204(): void
    {
        $response = $this->client()->request('OPTIONS', self::ROUTE);
        $raw      = (string) $response->getBody();

        self::assertSame(204, $response->getStatusCode(), 'Body: ' . $raw);
        self::assertSame('', $raw, 'The 204 for OPTIONS carried a body: ' . $raw);
        self::assertStringNotContainsString(
            'inputSchema',
            $raw,
            'The OPTIONS response describes the endpoint to an unauthenticated caller.'
        );
    }

    /**
     * A tool whose run callback leaves a transient behind, so "was this dispatched?" is an
     * observable question rather than an inference from a status code.
     *
     * The transient is fixture-named, so Fixtures::purge() removes it and the debris check
     * would report it if a crashed run left one.
     */
    private static function markerToolSource(): string
    {
        $name      = self::markerTool();
        $transient = self::markerTransient();

        return <<<PHP
add_filter('wpmcp_tools', static function (\$tools) {
    \$tools['{$name}'] = array(
        'write'       => false,
        'description' => 'wp-mcp test fixture: records that it ran.',
        'inputSchema' => array('type' => 'object', 'properties' => new stdClass()),
        'run'         => static function (\$args) {
            set_transient('{$transient}', 1, 600);

            return array('ran' => true);
        },
    );

    return \$tools;
});
PHP;
    }

    /** 405, `Allow: POST, OPTIONS`, and not a 401 - no token was sent. */
    private function assertVerbIsRefused(string $verb): void
    {
        $response = $this->client()->request($verb, self::ROUTE);
        $raw      = (string) $response->getBody();

        self::assertSame(
            405,
            $response->getStatusCode(),
            $verb . ' on the endpoint must be 405, not 404 (which says the endpoint does'
            . ' not exist) and not 401 (which means the verb gate runs after the token'
            . ' logic). Body: ' . $raw
        );
        self::assertSame(
            'POST, OPTIONS',
            $response->getHeaderLine('Allow'),
            'The Allow header on a ' . $verb . ' refusal must name exactly the verbs that'
            . ' work. WordPress core rebuilds this header from every registered handler'
            . ' whose permission_callback passes, which is why the gate answers before'
            . ' route dispatch rather than by registering more methods.'
        );
    }
}
