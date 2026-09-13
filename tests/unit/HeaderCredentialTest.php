<?php
/**
 * The one way to present a token: `Authorization: Bearer <64 lower-case hex>`.
 *
 * WHY THE FALLBACK EXISTS, WHICH IS THE ONLY NON-OBVIOUS LINE IN THE FUNCTION.
 * Apache running PHP through CGI or FastCGI does not pass `Authorization` into the
 * environment at all: the header is consumed by the server's own auth machinery and
 * never reaches PHP, so `$_SERVER['HTTP_AUTHORIZATION']` is absent and WordPress's
 * `WP_REST_Request::get_header('authorization')` is therefore empty. WordPress ships
 * with the fix for its own Application Passwords - the `.htaccess` block it writes
 * contains
 *
 *     RewriteRule ^ - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
 *
 * and Apache exports an `E=`-set variable that duplicates a CGI name under the
 * `REDIRECT_` prefix, so the value arrives as `REDIRECT_HTTP_AUTHORIZATION`. Reading
 * that when the header is empty is what makes a header-only credential work on the
 * commonest shared-hosting stack there is. Without it, this sprint would have made
 * the plugin unusable on every such host - a 401 with `reason=missing` and nothing to
 * point at.
 *
 * THE PATH FORM IS GONE, and the test for that is two doors down: the route no longer
 * exists (tests/integration/HeaderOnlyCredentialTest.php asserts the 404) and nothing
 * in the repository still builds one (tests/unit/SurfaceSweepTest.php). What is
 * asserted HERE is the narrower thing this function is responsible for: a `token`
 * value riding on the request is not a credential.
 *
 * @group sprint-7
 */

declare(strict_types=1);

namespace WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WpMcp\Tests\Support\WordPressRuntime;
use WpMcp\Tests\Support\WordPressStubs;
use WP_REST_Request;

final class HeaderCredentialTest extends TestCase
{
    private const TOKEN = 'a1b2c3d4e5f60718293a4b5c6d7e8f90a1b2c3d4e5f60718293a4b5c6d7e8f90';

    protected function setUp(): void
    {
        parent::setUp();

        WordPressStubs::loadPlugin();
        WordPressRuntime::install();

        unset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'], $_SERVER['HTTP_AUTHORIZATION']);
    }

    protected function tearDown(): void
    {
        unset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'], $_SERVER['HTTP_AUTHORIZATION']);

        parent::tearDown();
    }

    /**
     * @group sprint-7
     */
    public function testTheBearerHeaderIsTheCredential(): void
    {
        $request = new WP_REST_Request(['Authorization' => 'Bearer ' . self::TOKEN]);

        self::assertSame(self::TOKEN, \wpmcp_extract_token($request));
    }

    /**
     * The scheme is matched case-insensitively, because clients send `bearer`, `Bearer`
     * and `BEARER` and RFC 7235 says the scheme is case-insensitive.
     *
     * @group sprint-7
     */
    public function testTheSchemeIsCaseInsensitive(): void
    {
        foreach (['bearer', 'Bearer', 'BEARER', 'BeArEr'] as $scheme) {
            $request = new WP_REST_Request(['Authorization' => $scheme . ' ' . self::TOKEN]);

            self::assertSame(
                self::TOKEN,
                \wpmcp_extract_token($request),
                "The `{$scheme}` spelling of the scheme was not accepted."
            );
        }
    }

    /**
     * The Apache CGI case: no `Authorization` visible to PHP, the value re-exported by
     * WordPress's own rewrite block under `REDIRECT_HTTP_AUTHORIZATION`.
     *
     * @group sprint-7
     */
    public function testTheRedirectHttpAuthorizationFallbackIsHonoured(): void
    {
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Bearer ' . self::TOKEN;

        $request = new WP_REST_Request([]); // Apache never handed the header to PHP.

        self::assertSame(
            self::TOKEN,
            \wpmcp_extract_token($request),
            'On Apache under CGI/FastCGI the Authorization header reaches PHP only as'
            . ' REDIRECT_HTTP_AUTHORIZATION. Without this fallback the plugin answers'
            . ' 401 reason=missing on the commonest shared-hosting stack there is.'
        );
    }

    /**
     * The fallback is a FALLBACK: a real header always wins, so a stale or forged
     * server variable cannot displace what the client actually sent.
     *
     * @group sprint-7
     */
    public function testARealHeaderWinsOverTheFallback(): void
    {
        $other = str_repeat('bc', 32);

        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Bearer ' . $other;

        $request = new WP_REST_Request(['Authorization' => 'Bearer ' . self::TOKEN]);

        self::assertSame(self::TOKEN, \wpmcp_extract_token($request));
    }

    /**
     * No header, no fallback, no credential - which is the `reason=missing` 401 and
     * what claude.ai's own uncredentialled probe of a connector URL produces.
     *
     * @group sprint-7
     */
    public function testNoHeaderYieldsNoToken(): void
    {
        self::assertSame('', \wpmcp_extract_token(new WP_REST_Request([])));
    }

    /**
     * A non-Bearer scheme is not a credential this endpoint understands. Basic auth in
     * particular must not be read as a token: `Basic <base64>` trimmed of its first
     * seven characters is not the password, and treating it as one would put whatever
     * it decoded to into a database lookup.
     *
     * @group sprint-7
     */
    public function testANonBearerSchemeIsNotACredential(): void
    {
        foreach (['Basic ' . base64_encode('user:pass'), 'Digest x', self::TOKEN] as $value) {
            self::assertSame(
                '',
                \wpmcp_extract_token(new WP_REST_Request(['Authorization' => $value])),
                "`{$value}` was read as a token."
            );
        }
    }
}
