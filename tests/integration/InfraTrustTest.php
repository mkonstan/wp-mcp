<?php
/**
 * Does the deployment in front of WordPress actually tell the truth about TLS?
 *
 * THIS TEST IS EXPECTED TO FAIL ON Local by Flywheel, AND THAT IS THE POINT. It is not
 * in the sprint-2 group, it is excluded from `composer test` and from CI, and it has its
 * own command:
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
 *     POST http://jaygroup.local/wp-json/wpmcp/mcp  + Bearer + JSON        -> 403
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
