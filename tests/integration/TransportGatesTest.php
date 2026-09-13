<?php
/**
 * The three gates that run BEFORE the token is read: HTTPS, Origin, Content-Type.
 *
 * This is the tier the plan says Sprint 2 lives in, and the reason is visible here:
 * none of these can be tested through rest_do_request(), because none of them exists
 * until there is a real TLS connection, a real Origin header and a real Content-Type.
 * Every test below is an actual POST over the wire, with a VALID token - so a refusal
 * can only have come from the gate under test, never from the credential.
 *
 * WHY THESE THREE, AND WHY FIRST.
 *
 *   HTTPS - the token travels in a URL path or an Authorization header. Over plaintext
 *     it is simply given away, to anyone on the path and to every proxy log in
 *     between. A refusal that happens AFTER the token is looked up has already put the
 *     credential in the hands of whoever was listening, so the gate has to be first.
 *
 *     THE PLAINTEXT REFUSAL ITSELF IS NOT IN THIS CLASS. It needs a host that has TLS,
 *     so that a downgrade to http is a different scheme on the same site, and it needs
 *     the gate to be on. A container published on plain http with WPMCP_ALLOW_INSECURE
 *     set, which is what this suite runs against in CI, has neither, and the question
 *     cannot be asked there rather than answered wrongly. It lives in InfraTrustTest
 *     alongside the X-Forwarded-Proto property, where a host without TLS FAILS the test
 *     instead of skipping it. Only the control below, that the same token works, stays
 *     here.
 *
 *   Origin - WordPress REST inherits cookie+nonce assumptions and never looks at
 *     Origin. The MCP specification says MUST validate it and MUST reject with 403.
 *     An absent Origin is allowed on purpose: it is what curl, an MCP server and a CLI
 *     send, and a browser's Origin is a statement the browser makes, so its absence is
 *     not a claim that can be trusted or distrusted.
 *
 *   Content-Type - `text/plain` and `application/x-www-form-urlencoded` are what a
 *     cross-origin <form> can POST with no preflight at all. Requiring
 *     application/json is what makes a browser ask permission before it can even try.
 *
 * @group sprint-2
 */

declare(strict_types=1);

namespace WpMcp\Tests\Integration;

use WpMcp\Tests\Support\Fixtures;
use WpMcp\Tests\Support\FixtureIntegrationTestCase;
use WpMcp\Tests\Support\TestRecorder;

final class TransportGatesTest extends FixtureIntegrationTestCase
{
    private static function label(): string { return Fixtures::name('transport'); }
    private static function login(): string { return Fixtures::name('transport-author'); }

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

        // After purge(), which removes this run's mu-plugins.
        TestRecorder::install();

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
        TestRecorder::uninstall();
        Fixtures::deleteUser(self::$userId);
        Fixtures::deleteTokensLabelled(self::label());
        Fixtures::purge();
    }

    /**
     * The control. Everything below sends the same valid token, so this establishes
     * that the token itself is fine and that a refusal is the gate talking.
     *
     * NOT named "over https": the scheme is whatever WPMCP_TEST_URL is, which in CI is
     * plain http with WPMCP_ALLOW_INSECURE set. What it proves either way is that the
     * token is accepted, so a 403 below came from the gate.
     *
     * @group sprint-2
     */
    public function testTheFixtureTokenIsAccepted(): void
    {
        $response = $this->mcp(self::$token)->post('tools/list');

        self::assertSame(
            200,
            $response->getStatusCode(),
            'The fixture token is not accepted at all, so every refusal below would'
            . ' prove nothing. Body: ' . (string) $response->getBody()
        );
    }

    /**
     * Item 3. An Origin from somewhere else is refused 403 - with a body that is the
     * same for every foreign origin, so it cannot be used to enumerate the allowlist.
     *
     * @group sprint-2
     */
    public function testAForeignOriginIsRefused(): void
    {
        TestRecorder::reset();

        $response = $this->mcp(self::$token)->post('tools/list', [], [
            'Origin' => 'https://evil.example',
        ]);

        self::assertSame(
            403,
            $response->getStatusCode(),
            'A cross-origin POST was not refused. Body: ' . (string) $response->getBody()
        );
        self::assertSame(
            '{"code":"wpmcp_forbidden_origin","message":"Forbidden.","data":{"status":403}}',
            (string) $response->getBody()
        );
        self::assertSame(
            1,
            TestRecorder::countOf(TestRecorder::AUTH . 'origin_deny'),
            'The cross-origin refusal did not fire exactly one origin_deny event.'
        );
        self::assertSame(
            0,
            TestRecorder::countOf(TestRecorder::AUTH . 'validate_fail'),
            'A foreign Origin reached token validation.'
        );
    }

    /**
     * A second foreign origin gets the byte-identical body. One constant answer, so
     * "is THIS origin on the list" cannot be asked one request at a time.
     *
     * @group sprint-2
     */
    public function testEveryForeignOriginGetsTheSameBody(): void
    {
        $mcp = $this->mcp(self::$token);

        $first  = $mcp->post('tools/list', [], ['Origin' => 'https://evil.example']);
        $second = $mcp->post('tools/list', [], ['Origin' => 'http://localhost:3000']);

        self::assertSame($first->getStatusCode(), $second->getStatusCode());
        self::assertSame((string) $first->getBody(), (string) $second->getBody());
    }

    /**
     * The site's own Origin proceeds. Without this half, "cross-origin is refused"
     * could just mean the Origin header is refused.
     *
     * The site's own origin is WPMCP_TEST_URL itself - which is what home_url()
     * answers on a request over TLS, verified on the site under test: the `home`
     * option there is http:// and home_url() returns https:// whenever is_ssl(),
     * because get_home_url() replaces the scheme. Since the HTTPS gate runs first, the
     * allowlist is always built from https origins by the time this matters.
     *
     * @group sprint-2
     */
    public function testTheSitesOwnOriginProceeds(): void
    {
        $response = $this->mcp(self::$token)->post('tools/list', [], [
            'Origin' => $this->baseUrl,
        ]);

        self::assertSame(
            200,
            $response->getStatusCode(),
            'A POST carrying the site\'s own Origin was refused. Body: '
            . (string) $response->getBody()
        );
    }

    /**
     * No Origin at all proceeds: that is every non-browser client there is.
     *
     * @group sprint-2
     */
    public function testAnAbsentOriginProceeds(): void
    {
        $response = $this->client()->post('wp-json/wpmcp/mcp', [
            'headers' => [
                'Authorization' => 'Bearer ' . self::$token,
                'Content-Type'  => 'application/json',
            ],
            'body' => '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}',
        ]);

        self::assertSame(
            200,
            $response->getStatusCode(),
            'A request with no Origin header was refused, which would break every'
            . ' non-browser client. Body: ' . (string) $response->getBody()
        );
    }

    /**
     * Item 3, second half. A text/plain body is 415 - the status that says "I will not
     * read this", rather than a parse error, which would mean it had been read.
     *
     * @group sprint-2
     */
    public function testATextPlainBodyIsRefusedWith415(): void
    {
        TestRecorder::reset();

        $response = $this->mcp(self::$token)->postRaw(
            '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}',
            ['Content-Type' => 'text/plain']
        );

        self::assertSame(
            415,
            $response->getStatusCode(),
            'A text/plain POST was not refused with 415. Body: '
            . (string) $response->getBody()
        );
        self::assertSame(
            '{"code":"wpmcp_unsupported_media_type","message":"Content-Type must be'
            . ' application\/json.","data":{"status":415}}',
            (string) $response->getBody()
        );
        self::assertSame(
            1,
            TestRecorder::countOf(TestRecorder::AUTH . 'content_type_deny'),
            'The 415 did not fire exactly one content_type_deny event.'
        );
    }

    /**
     * A missing Content-Type is refused too. This is the shape an old or sloppy client
     * sends, and guessing for it is how the form-POST hole stays open.
     *
     * @group sprint-2
     */
    public function testAnAbsentContentTypeIsRefusedWith415(): void
    {
        $response = $this->client()->post('wp-json/wpmcp/mcp', [
            'headers' => ['Authorization' => 'Bearer ' . self::$token],
            'body'    => '',
        ]);

        self::assertSame(
            415,
            $response->getStatusCode(),
            'A POST with no Content-Type was accepted. Body: ' . (string) $response->getBody()
        );
    }

    /**
     * Parameters on the Content-Type are allowed: `application/json; charset=utf-8` is
     * what several HTTP clients send by default and is the same media type.
     *
     * @group sprint-2
     */
    public function testAJsonContentTypeWithACharsetParameterProceeds(): void
    {
        $response = $this->mcp(self::$token)->post('tools/list', [], [
            'Content-Type' => 'application/json; charset=utf-8',
        ]);

        self::assertSame(
            200,
            $response->getStatusCode(),
            'application/json with a charset parameter was refused. Body: '
            . (string) $response->getBody()
        );
    }
}
