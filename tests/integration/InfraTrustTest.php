<?php
/**
 * The two properties that only a host with real TLS can be asked about.
 *
 * Both are about the HTTPS gate, and neither can be asked of a container published on
 * plain http with WPMCP_ALLOW_INSECURE set, which is what the rest of the integration
 * tier runs against in CI. So they live here, behind their own command, and a host that
 * cannot answer them FAILS rather than skips. A skip would read as a pass.
 *
 *   1. A valid token over plain http is refused 403 before the token is read.
 *   2. The same request carrying `X-Forwarded-Proto: https` is still refused.
 *
 * The first is the gate working. The second is whether your proxy lets a client lie
 * about it, and it is EXPECTED TO FAIL ON Local by Flywheel, which is the point. Both
 * are excluded from `composer test` and from CI, and they have their own command:
 *
 *     source bin/local-env.sh && composer test:infra
 *
 * Run it against the host you actually serve from, after any change to your proxy, CDN
 * or web server. A green run means that host's HTTPS enforcement is real. A red run
 * means it is advisory and a token can be given away in cleartext.
 *
 * WHAT IT CHECKS. wpmcp_request_is_secure() reads is_ssl(), which reads $_SERVER, which
 * the web server filled in from - on a proxied deployment - a header. WordPress cannot
 * tell a header its proxy set from one a client sent. So a request over plain HTTP
 * carrying `X-Forwarded-Proto: https` is accepted by any stack that forwards the
 * client's value instead of overwriting it. No plugin code can close that: the only fix
 * is `proxy_set_header X-Forwarded-Proto $scheme;` (or the equivalent) in the proxy.
 *
 * VERIFIED ON THE SITE THIS WAS DEVELOPED AGAINST, which is why it exists:
 *
 *     POST http://example.local/wp-json/wpmcp/mcp  + Bearer + JSON         -> 403
 *     ... the same request plus `X-Forwarded-Proto: https`                 -> 200
 *
 * Local's router maps the client's header through
 * (`…/Local/run/router/nginx/conf/nginx.conf`:
 * `map $http_x_forwarded_proto $protocol { default $scheme; https https; }`,
 * then `proxy_set_header X-Forwarded-Proto $protocol`) and the site's nginx maps that
 * into the FastCGI `HTTPS` parameter. Fixing it means editing that router config, which
 * lives on the workstation and not in this repository.
 *
 * A red test that names an infrastructure fact is worth more than a green one that hides
 * it, so there is no skip and no environment flag that makes this pass. If you have
 * fixed your proxy, this goes green on its own.
 *
 * @group infra-trust
 */

declare(strict_types=1);

namespace WpMcp\Tests\Integration;

use WpMcp\Tests\Support\Fixtures;
use WpMcp\Tests\Support\FixtureIntegrationTestCase;
use WpMcp\Tests\Support\TestRecorder;

final class InfraTrustTest extends FixtureIntegrationTestCase
{
    private static function label(): string { return Fixtures::name('infra'); }
    private static function login(): string { return Fixtures::name('infra-author'); }

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

        // After purge(), which removes this run's mu-plugins. The plaintext test below
        // asserts on the events the refusal fired, not only on the status code.
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
     * The gate itself: a valid token over plain http is refused 403, before the token is
     * read, with a body that says nothing about it.
     *
     * HERE RATHER THAN WITH THE OTHER TRANSPORT GATES because it needs a host that has
     * TLS. `insecureBaseUrl()` is the test URL with its scheme swapped, so on a host that
     * is already http there is no downgrade to attempt and nothing to prove. That used to
     * be a skip in the sprint-2 group, which made the CI gate red for a reason that had
     * nothing to do with the code: the gate step counts a skipped test as a gate that did
     * not run, correctly. A failure on a host that cannot answer is the honest version.
     *
     * @group infra-trust
     */
    public function testAValidTokenOverPlainHttpIsRefused(): void
    {
        $insecure = $this->insecureBaseUrl();

        if ($insecure === '') {
            self::fail(
                'WPMCP_TEST_URL is not https, so this host cannot be asked whether the'
                . ' HTTPS gate refuses plaintext: there is no TLS endpoint to downgrade'
                . ' from. Point WPMCP_TEST_URL at the https host you serve from.'
            );
        }

        TestRecorder::reset();

        $response = $this->mcp(self::$token, $insecure)->post('tools/list');

        self::assertSame(
            403,
            $response->getStatusCode(),
            'A valid token over plain HTTP was not refused with 403. Body: '
            . (string) $response->getBody()
        );
        self::assertSame(
            '{"code":"wpmcp_https_required","message":"HTTPS required.","data":{"status":403}}',
            (string) $response->getBody(),
            'The plaintext refusal body is not the fixed generic one.'
        );

        // The token was never looked up, so nothing about it is in the event either.
        self::assertSame(
            1,
            TestRecorder::countOf(TestRecorder::AUTH . 'insecure_deny'),
            'The plaintext refusal did not fire exactly one insecure_deny event.'
        );
        self::assertSame(
            0,
            TestRecorder::countOf(TestRecorder::AUTH . 'validate_fail'),
            'The plaintext request reached token validation. The point of putting the'
            . ' HTTPS gate first is that the credential is never read.'
        );
    }

    /**
     * A plaintext request that claims HTTPS with a forwarded header must still be refused.
     *
     * @group infra-trust
     */
    public function testAPlaintextRequestClaimingHttpsViaAForwardedHeaderIsStillRefused(): void
    {
        $insecure = $this->insecureBaseUrl();

        if ($insecure === '') {
            self::fail(
                'WPMCP_TEST_URL is not https, so this host cannot be asked the question'
                . ' at all: there is no TLS endpoint to compare a plaintext request'
                . ' against. Point WPMCP_TEST_URL at the https host you serve from.'
            );
        }

        $response = $this->mcp(self::$token, $insecure)->post('tools/list', [], [
            'X-Forwarded-Proto' => 'https',
        ]);

        self::assertSame(
            403,
            $response->getStatusCode(),
            'A PLAINTEXT request carrying `X-Forwarded-Proto: https` and a valid token was'
            . ' accepted. The token just travelled in cleartext and this host\'s HTTPS'
            . ' enforcement is advisory, not real. The plugin cannot fix this: your'
            . ' reverse proxy is forwarding the client\'s X-Forwarded-Proto instead of'
            . ' setting its own. In nginx:'
            . ' proxy_set_header X-Forwarded-Proto $scheme;'
            . ' Response body: ' . (string) $response->getBody()
        );
    }
}
