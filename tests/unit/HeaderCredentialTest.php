<?php
/**
 * The one way to present a token: `Authorization: Bearer <64 lower-case hex>`.
 *
 * THE APACHE CGI CASE IS NOT TESTED HERE, BECAUSE IT IS NOT THIS PLUGIN'S. Apache running
 * PHP through CGI or FastCGI does not pass `Authorization` into the environment;
 * WordPress's own `.htaccess` block re-exports it as `REDIRECT_HTTP_AUTHORIZATION`, and
 * `WP_REST_Server::get_headers()` maps that back onto AUTHORIZATION when HTTP_AUTHORIZATION
 * is empty. This class briefly carried a test for a copy of that mapping inside
 * wpmcp_extract_token(); the copy could never fire, so the test proved a branch rather
 * than a deployment, and both are gone. What remains is the assertion that matters on
 * such a host and every other: whatever put the value in `get_header('authorization')`,
 * this function reads it from there and from nowhere else.
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
     * The request object is the ONLY source. A server variable does not become a
     * credential on its own - which is the half of the removed fallback worth keeping as
     * an assertion: core decides what `get_header('authorization')` says, and this
     * function does not go looking behind it.
     *
     * @group sprint-7
     */
    public function testAServerVariableAloneIsNotACredential(): void
    {
        $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] = 'Bearer ' . self::TOKEN;
        $_SERVER['HTTP_AUTHORIZATION']          = 'Bearer ' . self::TOKEN;

        self::assertSame(
            '',
            \wpmcp_extract_token(new WP_REST_Request([])),
            'The token was read from $_SERVER rather than from the request. On a real'
            . ' host WordPress has already mapped that variable onto the header; a second'
            . ' read here is a second, unreviewed way in.'
        );
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
