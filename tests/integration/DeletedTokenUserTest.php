<?php
/**
 * A token whose user is deleted stops working.
 *
 * Binding a token to a user creates a dangling reference nobody had to think about
 * before: revoking a person's WordPress account has to revoke their MCP access too,
 * or the account deletion is theatre. The dangerous failure is not a crash, it is the
 * quiet one - running the request unauthenticated, where current_user_can() is false
 * for everything and the caller gets empty results instead of a refusal.
 *
 * The test proves the token worked FIRST. Without that, a 401 after the deletion
 * could mean the fixture was never valid, the IP pin, an expiry, or a typo.
 *
 * Its own fixture user, deliberately: this test destroys the user it is given, and
 * sharing one with another class would make the suite order-dependent.
 *
 * @group sprint-1
 */

declare(strict_types=1);

namespace WpMcp\Tests\Integration;

use WpMcp\Tests\Support\Fixtures;
use WpMcp\Tests\Support\FixtureIntegrationTestCase;

final class DeletedTokenUserTest extends FixtureIntegrationTestCase
{
    private const LABEL = Fixtures::PREFIX . 'deleted-user';
    private const LOGIN = Fixtures::PREFIX . 'doomed';

    private static int $userId  = 0;
    private static string $token = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::requireSite();

        self::buildFixtures(self::build(...), self::destroy(...));
    }

    private static function build(): void
    {
        // purge(), not just the label: with executionOrder="depends,defects" this
        // class can run before the one that purges, and a crashed earlier run would
        // otherwise fail createUser() on a name that is already taken.
        Fixtures::purge();

        self::$userId = Fixtures::createUser(self::LOGIN, 'author');
        self::$token  = Fixtures::mintToken('read', self::LABEL, self::$userId);
    }

    public static function tearDownAfterClass(): void
    {
        self::destroy();

        parent::tearDownAfterClass();
    }

    private static function destroy(): void
    {
        // Tolerant by design: the test deletes this user itself, so by the time
        // teardown runs the `wp user delete` is expected to fail.
        Fixtures::deleteUser(self::$userId);
        Fixtures::deleteTokensLabelled(self::LABEL);
    }

    /**
     * (e) Works, then the user is deleted, then 401 - and the body is the one a bogus
     * token gets, so the deletion is not announced on the wire either.
     *
     * @group sprint-1
     */
    public function testTheTokenStopsWorkingWhenItsUserIsDeleted(): void
    {
        $mcp = $this->mcp(self::$token);

        $before = $mcp->callTool('site-info');
        self::assertFalse(
            $before->isError,
            'The fixture token did not work before the deletion, so this test would'
            . ' prove nothing. Response: ' . $before->text
        );

        Fixtures::deleteUser(self::$userId);
        self::assertArrayNotHasKey(
            self::$userId,
            Fixtures::leftoverUsers(),
            'The fixture user was not actually deleted.'
        );

        $after = $mcp->post('tools/call', ['name' => 'site-info', 'arguments' => []]);

        self::assertSame(
            401,
            $after->getStatusCode(),
            'A token whose user no longer exists was not refused. Body: '
            . (string) $after->getBody()
        );

        $body = json_decode((string) $after->getBody(), true);
        self::assertIsArray($body);
        self::assertSame(
            'wpmcp_not_found',
            $body['code'] ?? null,
            'The refusal used a distinct error code, which tells a caller that the'
            . ' token exists but its user is gone. Body: ' . (string) $after->getBody()
        );
    }
}
