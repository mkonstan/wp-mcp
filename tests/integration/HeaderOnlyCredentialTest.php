<?php
/**
 * There is one URL and one way to present a credential to it.
 *
 * THE PATH ROUTE IS GONE, and that is a deletion with a wire-visible consequence
 * worth pinning rather than assuming. `/wp-json/wpmcp/mcp/<64 hex>` used to be a
 * registered REST route; it is now an address WordPress has never heard of, so the
 * answer is core's own 404 `rest_no_route` - not this plugin's 401. The distinction
 * matters in both directions:
 *
 *   - a 401 would mean the route still exists and something is refusing the token,
 *     which is how a half-done removal looks;
 *   - the 404 is also the honest answer to somebody pasting an old URL: the address
 *     is wrong, not the token.
 *
 * The token used for that request is a LIVE one from the same fixture set, and the
 * test proves it is live in the same run (testTheBearerHeaderWorksOnTheConstantUrl).
 * Without that, "the path URL 404s" would also be satisfied by a dead token.
 *
 * AND THE 401 BODY IS COMPARED TO OneUnauthorizedTest's LITERAL. Sprint 2's contract
 * is that every token refusal is one byte-identical answer; this sprint changed which
 * refusals exist, so the two cases that survive are checked against that same string
 * here rather than against each other, which would drift together.
 *
 * @group sprint-7
 */

declare(strict_types=1);

namespace WpMcp\Tests\Integration;

use Psr\Http\Message\ResponseInterface;
use WpMcp\Tests\Support\Fixtures;
use WpMcp\Tests\Support\FixtureIntegrationTestCase;

final class HeaderOnlyCredentialTest extends FixtureIntegrationTestCase
{
    /** The one 401 body, spelled out. Sprint 2's OneUnauthorizedTest pins it too. */
    private const THE_401 = '{"code":"wpmcp_unauthorized","message":"Unauthorized.","data":{"status":401}}';

    private static function label(): string { return Fixtures::name('header-only'); }
    private static function login(): string { return Fixtures::name('header-only-author'); }

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

        self::$userId = Fixtures::createUser(self::login(), 'administrator');
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
     * The control AND half the sprint: the constant URL plus a Bearer header serves
     * both discovery and a real call.
     *
     * @group sprint-7
     */
    public function testTheBearerHeaderWorksOnTheConstantUrl(): void
    {
        $mcp = $this->mcp(self::$token);

        $response = $mcp->post('tools/list');

        self::assertSame(
            200,
            $response->getStatusCode(),
            'tools/list over the header form was refused. Body: ' . (string) $response->getBody()
        );

        $body = json_decode((string) $response->getBody(), true);

        self::assertIsArray($body['result']['tools'] ?? null, 'tools/list returned no tool list.');
        self::assertNotSame([], $body['result']['tools'], 'The tool list is empty.');

        $result = $mcp->callTool('site-info');

        self::assertFalse(
            $result->isError,
            'tools/call site-info over the header form failed: ' . $result->text
        );
    }

    /**
     * The path form is not a route any more, so it is a 404 and not a 401.
     *
     * @group sprint-7
     */
    public function testThePathUrlWithAValidTokenIsA404AndNotA401(): void
    {
        $response = $this->postTo('wp-json/wpmcp/mcp/' . self::$token, []);

        self::assertSame(
            404,
            $response->getStatusCode(),
            'A token in the URL path still reaches a registered route. Body: '
            . (string) $response->getBody()
        );

        self::assertStringContainsString(
            'rest_no_route',
            (string) $response->getBody(),
            'The path URL is answered by something other than core\'s "no such route",'
            . ' which means the plugin still registers it.'
        );
    }

    /**
     * The path form does not become a credential when the header is absent either -
     * the same URL, no Authorization at all, still 404. Without this the previous
     * test would pass on an endpoint that read the path token and refused it for some
     * unrelated reason.
     *
     * @group sprint-7
     */
    public function testThePathUrlIsA404EvenWithNoHeaderAtAll(): void
    {
        $response = $this->client()->post('wp-json/wpmcp/mcp/' . self::$token, [
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}',
        ]);

        self::assertSame(404, $response->getStatusCode());
    }

    /**
     * Missing and malformed are the two refusals a header-only credential can still
     * produce on the constant URL, and both are the one 401.
     *
     * @group sprint-7
     */
    public function testMissingAndMalformedBearerAreTheOne401(): void
    {
        $cases = [
            'no Authorization header'  => [],
            'empty Bearer'             => ['Authorization' => 'Bearer '],
            'not 64 hex digits'        => ['Authorization' => 'Bearer not-a-token'],
            'upper-case hex'           => ['Authorization' => 'Bearer ' . strtoupper(self::$token)],
            'Basic instead of Bearer'  => ['Authorization' => 'Basic ' . base64_encode('a:b')],
        ];

        foreach ($cases as $what => $headers) {
            $response = $this->postTo('wp-json/wpmcp/mcp', $headers);

            self::assertSame(
                401,
                $response->getStatusCode(),
                "The \"{$what}\" case was not answered with 401."
            );
            self::assertSame(
                self::THE_401,
                (string) $response->getBody(),
                "The \"{$what}\" refusal body is not the one 401 every token failure shares."
            );
        }
    }

    /** @param array<string, string> $headers */
    private function postTo(string $path, array $headers): ResponseInterface
    {
        return $this->client()->post($path, [
            'headers' => array_merge(['Content-Type' => 'application/json'], $headers),
            'body'    => '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}',
        ]);
    }
}
