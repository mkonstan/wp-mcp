<?php
/**
 * wpmcp_mint() and the user a token is bound to.
 *
 * Identity is decided once, at mint time, and every later capability check depends on
 * it. These are the four things that can go wrong there, all of them invisible until
 * a request arrives as the wrong person:
 *
 *   - the default (0) silently binding to nobody instead of the minter
 *   - an explicit owner being dropped, so the token runs as the admin who minted it
 *   - created_by being overwritten by the owner, losing the audit trail
 *   - a nonexistent owner being accepted, producing a token that authenticates as
 *     nobody and whose every capability check fails as an empty result
 *
 * Unit tier: no WordPress, no database. The insert is recorded by FakeWpdb and read
 * back, so the assertion is on the row that would be written - which is where the
 * identity actually lives.
 *
 * @group sprint-1
 */

declare(strict_types=1);

namespace WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WpMcp\Tests\Support\FakeWpdb;
use WpMcp\Tests\Support\WordPressRuntime;
use WpMcp\Tests\Support\WordPressStubs;

final class MintIdentityTest extends TestCase
{
    private FakeWpdb $wpdb;

    protected function setUp(): void
    {
        parent::setUp();

        WordPressStubs::loadPlugin();
        $this->wpdb = WordPressRuntime::install();
    }

    /**
     * The documented default: 0 means "the user doing the minting".
     *
     * @group sprint-1
     */
    public function testUserIdZeroResolvesToTheCurrentUser(): void
    {
        WordPressRuntime::logInAs(7, 'wpmcp-unit-admin');

        $minted = \wpmcp_mint('read', 'default owner', 3600);

        self::assertIsArray($minted, 'Minting for the current user should succeed.');

        $row = $this->wpdb->lastInsertData();
        self::assertIsArray($row, 'wpmcp_mint() did not insert a row.');
        self::assertArrayHasKey('user_id', $row, 'The token row carries no user_id.');
        self::assertSame(7, $row['user_id'], 'user_id = 0 did not resolve to the current user.');
    }

    /**
     * An explicit owner is honoured, and created_by still records who minted it.
     * Collapsing the two would destroy the only audit trail the table has.
     *
     * @group sprint-1
     */
    public function testAnExplicitOwnerIsRecordedWithoutLosingTheMinter(): void
    {
        WordPressRuntime::logInAs(1, 'wpmcp-unit-admin');
        WordPressRuntime::addUser(9, 'wpmcp-unit-editor');
        WordPressRuntime::allowCap('edit_user:9');

        $minted = \wpmcp_mint('read', 'for the editor', 3600, 9);

        self::assertIsArray($minted, 'Minting for another existing user should succeed.');

        $row = $this->wpdb->lastInsertData();
        self::assertIsArray($row);
        self::assertSame(9, $row['user_id'], 'The token was not bound to the requested user.');
        self::assertSame(1, $row['created_by'], 'created_by must stay the minting admin.');
    }

    /**
     * A user id with no user behind it is refused, and nothing is written.
     *
     * @group sprint-1
     */
    public function testANonexistentUserIsRefused(): void
    {
        WordPressRuntime::logInAs(1, 'wpmcp-unit-admin');

        $result = \wpmcp_mint('read', 'ghost', 3600, 4242);

        self::assertTrue(\is_wp_error($result), 'Minting for user 4242 should be a WP_Error.');
        self::assertSame('wpmcp_no_such_user', $result->get_error_code());
        self::assertSame(
            [],
            $this->wpdb->inserts,
            'A refused mint must not leave a token row behind.'
        );
    }

    /**
     * Minting for somebody else requires authority over that someone, not merely
     * that they exist.
     *
     * On single-site an administrator holds edit_user over everyone, so this is
     * lateral. On multisite it is not: get_userdata() resolves network-wide, so
     * without the check a site administrator could mint an admin-scope token
     * carrying a super admin's ID, and current_user_can() would then answer true for
     * every capability on that site.
     *
     * @group sprint-1
     */
    public function testMintingForAUserYouHaveNoAuthorityOverIsRefused(): void
    {
        WordPressRuntime::logInAs(1, 'wpmcp-unit-admin');
        WordPressRuntime::addUser(9, 'wpmcp-unit-superadmin');
        // No edit_user granted.

        $result = \wpmcp_mint('read', 'escalation attempt', 3600, 9);

        self::assertTrue(\is_wp_error($result), 'Minting for user 9 should be a WP_Error.');
        self::assertSame('wpmcp_not_allowed', $result->get_error_code());
        self::assertSame(
            [],
            $this->wpdb->inserts,
            'A refused mint must not leave a token row behind.'
        );
    }

    /**
     * Minting for YOURSELF needs no edit_user - the common case, and the one the
     * form defaults to. An administrator does hold edit_user over themselves, but
     * relying on that would break the moment a role did not.
     *
     * @group sprint-1
     */
    public function testMintingForYourselfNeedsNoAuthorityOverAnybody(): void
    {
        WordPressRuntime::logInAs(7, 'wpmcp-unit-author');
        // No caps at all.

        $explicit = \wpmcp_mint('read', 'own, by id', 3600, 7);
        self::assertIsArray($explicit, 'Minting for oneself by id was refused.');
        self::assertSame(7, $this->wpdb->lastInsertData()['user_id']);
    }

    /**
     * Fail closed: with nobody logged in, the default owner resolves to 0, which is
     * not a user either. A token bound to 0 would authenticate as nobody.
     *
     * @group sprint-1
     */
    public function testMintingWithNoCurrentUserIsRefused(): void
    {
        WordPressRuntime::setCurrentUserId(0);

        $result = \wpmcp_mint('read', 'nobody', 3600);

        self::assertTrue(\is_wp_error($result), 'Minting with no current user should be a WP_Error.');
        self::assertSame('wpmcp_no_such_user', $result->get_error_code());
        self::assertSame([], $this->wpdb->inserts);
    }
}
