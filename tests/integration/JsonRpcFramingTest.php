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

final class JsonRpcFramingTest extends FixtureIntegrationTestCase
{
    private static function label(): string { return Fixtures::name('framing'); }
    private static function login(): string { return Fixtures::name('framing-author'); }

    /** The route both verb tests aim at - the header form, no token in the path. */
    private const ROUTE = 'wp-json/wpmcp/mcp';

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
        Fixtures::deleteUser(self::$userId);
        Fixtures::deleteTokensLabelled(self::label());
        Fixtures::purge();
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
     * No `id` key: 202, empty body, nothing dispatched - whatever the method says.
     *
     * Three bodies, because the rule is about the absence of `id` and nothing else: a
     * well-formed notification, one whose method this server does not serve, and one with
     * no method at all. All three are silence.
     *
     * @group sprint-3
     */
    public function testABodyWithNoIdIsAcknowledgedAndNotDispatched(): void
    {
        $bodies = [
            'a real notification'  => '{"jsonrpc":"2.0","method":"notifications/initialized"}',
            'an unknown method'    => '{"jsonrpc":"2.0","method":"does/not/exist"}',
            'no method at all'     => '{"jsonrpc":"2.0","params":{"x":1}}',
        ];

        foreach ($bodies as $what => $json) {
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
     * A 5 MiB body is refused 413 by the plugin, before the token is looked up.
     *
     * THE BODY IS ASSERTED, not only the status. nginx and PHP both answer 413 of their
     * own accord once their own limits are crossed, so a status-only assertion would pass
     * on a host where this gate does not exist at all. The fixed body naming
     * wpmcp_payload_too_large is what proves the refusal is ours.
     *
     * @group sprint-3
     */
    public function testAnOversizedBodyIsRefusedBeforeAnyWork(): void
    {
        $filler  = str_repeat('A', 5 * 1024 * 1024);
        $payload = '{"jsonrpc":"2.0","id":1,"method":"ping","params":{"pad":"' . $filler . '"}}';

        $response = $this->mcp(self::$token)->postRaw($payload);
        $raw      = (string) $response->getBody();

        self::assertSame(
            413,
            $response->getStatusCode(),
            'A ' . strlen($payload) . '-byte body was not refused 413. Body: '
            . substr($raw, 0, 500)
        );
        self::assertStringContainsString(
            'wpmcp_payload_too_large',
            $raw,
            'Something answered 413, but not this plugin - so the cap in'
            . ' wpmcp_authorize() is not what refused it, and on a host with higher'
            . ' server limits the body would go through. Body: ' . substr($raw, 0, 500)
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
