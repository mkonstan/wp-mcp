<?php
/**
 * Six ways for a token to fail. One answer.
 *
 * Missing, malformed, never minted, expired, pinned to another IP, bound to a user who
 * has been deleted - each of those is a different fact about the caller's credential,
 * and every one of them used to be distinguishable from the wire:
 *
 *   missing / malformed   401 wpmcp_not_found  "Invalid token."
 *   never minted          401 wpmcp_not_found  "Token not found."
 *   expired               401 wpmcp_expired    "Token expired - regenerate in ..."
 *   IP mismatch           403 wpmcp_ip_mismatch "Token is bound to a different IP."
 *   deleted user          401 wpmcp_not_found  "Token not found."
 *
 * Each distinction is an oracle. "Expired" confirms the string WAS a real token and
 * tells the holder to go looking for a newer one. "Bound to a different IP" confirms it
 * is real AND currently in use from somewhere else, which is precisely what somebody
 * who found it in a log wants to know. The 403 gave that away by status code alone,
 * before anything read the body.
 *
 * So all six are now byte-identical, and the test fetches all six bodies in one run and
 * compares them to each other rather than to a remembered string - a claim about
 * equality has to be asserted as equality, or the next change to the message passes.
 *
 * The reason survives in the validate_fail auth event, which is the whole point of
 * item 5: the operator can tell the six apart, the caller cannot.
 *
 * @group sprint-2
 */

declare(strict_types=1);

namespace WpMcp\Tests\Integration;

use Psr\Http\Message\ResponseInterface;
use WpMcp\Tests\Support\Fixtures;
use WpMcp\Tests\Support\FixtureIntegrationTestCase;
use WpMcp\Tests\Support\TestRecorder;

final class OneUnauthorizedTest extends FixtureIntegrationTestCase
{
    private static function liveLabel(): string { return Fixtures::name('401-live'); }
    private static function expiredLabel(): string { return Fixtures::name('401-expired'); }
    private static function pinnedLabel(): string { return Fixtures::name('401-pinned'); }
    private static function doomedLabel(): string { return Fixtures::name('401-doomed'); }

    private static function login(): string { return Fixtures::name('401-author'); }
    private static function doomedLogin(): string { return Fixtures::name('401-doomed-author'); }

    /** An address the test process certainly is not calling from. */
    private const SOMEBODY_ELSES_IP = '203.0.113.7';

    private static int $userId = 0;
    private static int $doomedUserId = 0;
    private static string $liveToken = '';
    private static string $expiredToken = '';
    private static string $pinnedToken = '';
    private static string $doomedToken = '';

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

        self::$userId       = Fixtures::createUser(self::login(), 'author');
        self::$doomedUserId = Fixtures::createUser(self::doomedLogin(), 'author');

        self::$liveToken    = Fixtures::mintToken('read', self::liveLabel(), self::$userId);
        self::$expiredToken = Fixtures::mintToken('read', self::expiredLabel(), self::$userId);
        self::$pinnedToken  = Fixtures::mintToken('read', self::pinnedLabel(), self::$userId);
        self::$doomedToken  = Fixtures::mintToken('read', self::doomedLabel(), self::$doomedUserId);

        // Real rows, written by the real mint, with exactly one column moved each.
        Fixtures::expireTokensLabelled(self::expiredLabel());
        Fixtures::bindTokensLabelled(self::pinnedLabel(), self::SOMEBODY_ELSES_IP);

        // And the one whose user stops existing.
        Fixtures::deleteUser(self::$doomedUserId);
        self::$doomedUserId = 0;
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
        Fixtures::deleteUser(self::$doomedUserId);

        foreach ([self::liveLabel(), self::expiredLabel(), self::pinnedLabel(), self::doomedLabel()] as $label) {
            Fixtures::deleteTokensLabelled($label);
        }

        Fixtures::purge();
    }

    /**
     * The control: the live token from the same fixture set works. Without it, "all six
     * refusals are identical" would also be satisfied by an endpoint that refuses
     * everything.
     *
     * @group sprint-2
     */
    public function testTheLiveTokenStillWorks(): void
    {
        $response = $this->refusal('Bearer ' . self::$liveToken);

        self::assertSame(
            200,
            $response->getStatusCode(),
            'The live fixture token was refused, so the comparison below is between six'
            . ' broken things. Body: ' . (string) $response->getBody()
        );
    }

    /**
     * Item 4. All six, compared against each other.
     *
     * @group sprint-2
     */
    public function testAllSixTokenFailuresAreByteIdentical(): void
    {
        $cases = [
            'missing (no Authorization header at all)' => null,
            'malformed (not 64 hex digits)'            => 'Bearer not-a-token',
            'never minted'                             => 'Bearer ' . str_repeat('ab', 32),
            'expired'                                  => 'Bearer ' . self::$expiredToken,
            'pinned to another IP'                     => 'Bearer ' . self::$pinnedToken,
            'bound user deleted'                       => 'Bearer ' . self::$doomedToken,
        ];

        $seen = [];

        foreach ($cases as $what => $header) {
            $response = $this->refusal($header);

            $seen[$what] = [
                'status' => $response->getStatusCode(),
                'body'   => (string) $response->getBody(),
            ];
        }

        $first      = array_key_first($seen);
        $expected   = $seen[$first];

        foreach ($seen as $what => $got) {
            self::assertSame(
                $expected['status'],
                $got['status'],
                "The \"{$what}\" refusal has a different HTTP status from \"{$first}\"."
                . ' Any difference tells a caller holding a bad token something about it.'
            );
            self::assertSame(
                $expected['body'],
                $got['body'],
                "The \"{$what}\" refusal body differs from \"{$first}\"'s."
                . " Got: {$got['body']}"
            );
        }

        // And the shared answer is a 401 that says nothing.
        self::assertSame(401, $expected['status']);
        self::assertSame(
            '{"code":"wpmcp_unauthorized","message":"Unauthorized.","data":{"status":401}}',
            $expected['body']
        );
    }

    /**
     * The IP-mismatch case specifically is no longer a 403.
     *
     * Called out on its own because it is the one the byte-equality test above would
     * still pass if every case became 403: a status that differs from the other five is
     * what used to single this one out, and a reader of the suite should be able to see
     * that the old 403 is gone without reconstructing it from the equality assertion.
     *
     * @group sprint-2
     */
    public function testTheIpMismatchRefusalIsNoLongerA403(): void
    {
        $response = $this->refusal('Bearer ' . self::$pinnedToken);

        self::assertSame(
            401,
            $response->getStatusCode(),
            'A token pinned to another IP is still answered with its own status code,'
            . ' which identifies it as a real token in use elsewhere.'
        );
    }

    /**
     * Item 5, the half that makes item 4 acceptable: the reason IS recorded, with the
     * token's row id, and without any token material.
     *
     * @group sprint-2
     */
    public function testTheValidateFailEventCarriesTheReasonAndNoTokenMaterial(): void
    {
        TestRecorder::reset();

        $this->refusal('Bearer ' . self::$pinnedToken);

        $events = TestRecorder::detailsOf(TestRecorder::AUTH . 'validate_fail');

        self::assertCount(
            1,
            $events,
            'The refusal did not fire exactly one validate_fail event.'
        );

        $context = $events[0];

        self::assertSame(
            'ip_mismatch',
            $context['reason'] ?? null,
            'The validate_fail context does not carry the internal reason, which is the'
            . ' only place it exists now that the wire answer is one 401.'
        );
        self::assertSame(self::SOMEBODY_ELSES_IP, $context['bound_ip'] ?? null);
        self::assertArrayHasKey('token_id', $context, 'The event cannot be tied to a token row.');
        self::assertGreaterThan(0, (int) $context['token_id']);

        $flat = strtolower((string) json_encode($context));

        self::assertStringNotContainsString(
            strtolower(self::$pinnedToken),
            $flat,
            'The raw token appears in the auth event context.'
        );
        self::assertStringNotContainsString(
            hash('sha256', self::$pinnedToken),
            $flat,
            'The token hash appears in the auth event context. That string IS the'
            . ' credential as far as the lookup is concerned.'
        );
    }

    /**
     * One request with an arbitrary Authorization header - or none at all.
     *
     * Not McpClient, because McpClient always sends a Bearer token and the "missing"
     * case is precisely the absence of that header.
     */
    private function refusal(?string $authorization): ResponseInterface
    {
        $headers = ['Content-Type' => 'application/json'];

        if ($authorization !== null) {
            $headers['Authorization'] = $authorization;
        }

        return $this->client()->post('wp-json/wpmcp/mcp', [
            'headers' => $headers,
            'body'    => '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}',
        ]);
    }
}
