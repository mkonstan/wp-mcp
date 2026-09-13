<?php
/**
 * Five ways for a token to fail. One answer.
 *
 * Missing, malformed, never minted, expired, bound to a user who has been deleted - each
 * of those is a different fact about the caller's credential, and every one of them used
 * to be distinguishable from the wire:
 *
 *   missing / malformed   401 wpmcp_not_found  "Invalid token."
 *   never minted          401 wpmcp_not_found  "Token not found."
 *   expired               401 wpmcp_expired    "Token expired - regenerate in ..."
 *   deleted user          401 wpmcp_not_found  "Token not found."
 *
 * Each distinction is an oracle. "Expired" confirms the string WAS a real token and
 * tells the holder to go looking for a newer one.
 *
 * SIX UNTIL SPRINT 7, and the sixth is gone rather than rewritten: a token used to be
 * locked to one client address and refused - at first with a 403 of its own, later with
 * this same 401 - from anywhere else. That lock is removed, so there is no such refusal
 * left to compare. Its two tests went with it; what they were really guarding, that no
 * refusal is distinguishable from another, is still asserted below over the five that
 * remain.
 *
 * So all five are byte-identical, and the test fetches all five bodies in one run and
 * compares them to each other rather than to a remembered string - a claim about
 * equality has to be asserted as equality, or the next change to the message passes.
 *
 * The reason survives in the validate_fail auth event, which is the whole point of
 * item 5: the operator can tell the five apart, the caller cannot.
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
    private static function doomedLabel(): string { return Fixtures::name('401-doomed'); }

    private static function login(): string { return Fixtures::name('401-author'); }
    private static function doomedLogin(): string { return Fixtures::name('401-doomed-author'); }

    private static int $userId = 0;
    private static int $doomedUserId = 0;
    private static string $liveToken = '';
    private static string $expiredToken = '';
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
        self::$doomedToken  = Fixtures::mintToken('read', self::doomedLabel(), self::$doomedUserId);

        // A real row, written by the real mint, with exactly one column moved.
        Fixtures::expireTokensLabelled(self::expiredLabel());

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

        foreach ([self::liveLabel(), self::expiredLabel(), self::doomedLabel()] as $label) {
            Fixtures::deleteTokensLabelled($label);
        }

        Fixtures::purge();
    }

    /**
     * The control: the live token from the same fixture set works. Without it, "all five
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
            'The live fixture token was refused, so the comparison below is between five'
            . ' broken things. Body: ' . (string) $response->getBody()
        );
    }

    /**
     * Item 4. All five, compared against each other.
     *
     * @group sprint-2
     */
    public function testAllFiveTokenFailuresAreByteIdentical(): void
    {
        $cases = [
            'missing (no Authorization header at all)' => null,
            'malformed (not 64 hex digits)'            => 'Bearer not-a-token',
            'never minted'                             => 'Bearer ' . str_repeat('ab', 32),
            'expired'                                  => 'Bearer ' . self::$expiredToken,
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
     * The expired case specifically has no status of its own.
     *
     * Called out on its own because it is the one the byte-equality test above would
     * still pass if every case moved together: a status that differs from the others is
     * what used to single a refusal out, and a reader of the suite should be able to see
     * that without reconstructing it from the equality assertion.
     *
     * @group sprint-2
     */
    public function testTheExpiredRefusalHasNoStatusOfItsOwn(): void
    {
        $response = $this->refusal('Bearer ' . self::$expiredToken);

        self::assertSame(
            401,
            $response->getStatusCode(),
            'An expired token is answered with a status of its own, which confirms to a'
            . ' caller that the string WAS a real token.'
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

        $this->refusal('Bearer ' . self::$doomedToken);

        $events = TestRecorder::detailsOf(TestRecorder::AUTH . 'validate_fail');

        self::assertCount(
            1,
            $events,
            'The refusal did not fire exactly one validate_fail event.'
        );

        $context = $events[0];

        self::assertSame(
            'user_missing',
            $context['reason'] ?? null,
            'The validate_fail context does not carry the internal reason, which is the'
            . ' only place it exists now that the wire answer is one 401.'
        );
        self::assertArrayHasKey(
            'ip',
            $context,
            'The event does not say where the call came from. The address decides nothing'
            . ' any more, which is exactly why the log has to keep reporting it.'
        );
        self::assertArrayHasKey('token_id', $context, 'The event cannot be tied to a token row.');
        self::assertGreaterThan(0, (int) $context['token_id']);

        $flat = strtolower((string) json_encode($context));

        self::assertStringNotContainsString(
            strtolower(self::$doomedToken),
            $flat,
            'The raw token appears in the auth event context.'
        );
        self::assertStringNotContainsString(
            hash('sha256', self::$doomedToken),
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
