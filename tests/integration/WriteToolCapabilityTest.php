<?php
/**
 * An admin-SCOPE token is still bounded by its USER's capabilities.
 *
 * Scope and identity are two different gates and this is the test that keeps them
 * apart. `scope: admin` decides which tools a token may call at all; the token's
 * user decides what those tools may then do. Before this, only the first gate
 * existed on the write path: every low-level WordPress function these tools call -
 * wp_insert_post, wp_update_post, wp_delete_post, wp_insert_term, wp_delete_term,
 * media_handle_sideload, wp_delete_attachment, wp_set_comment_status,
 * wp_insert_comment - performs no capability check whatsoever, because wp-admin and
 * the REST controllers do the checking before calling them.
 *
 * The consequence was precise and bad: an admin-scope token minted with "Runs as:
 * some-subscriber" - exactly what the new dropdown invites an operator to do - could
 * publish, rewrite and delete any post on the site, while the mint form told that
 * operator the user's capabilities were the ceiling. This test is the difference
 * between that sentence being a claim and being true.
 *
 * Both halves matter. A Subscriber is refused; an Editor is not. Without the second
 * half "refused" could just mean the write tools are broken for everyone.
 *
 * @group sprint-1
 */

declare(strict_types=1);

namespace WpMcp\Tests\Integration;

use WpMcp\Tests\Support\Fixtures;
use WpMcp\Tests\Support\FixtureIntegrationTestCase;
use WpMcp\Tests\Support\WpCli;

final class WriteToolCapabilityTest extends FixtureIntegrationTestCase
{
    private const LABEL            = Fixtures::PREFIX . 'writecaps';
    private const SUBSCRIBER_LOGIN = Fixtures::PREFIX . 'subscriber';
    private const EDITOR_LOGIN     = Fixtures::PREFIX . 'writeeditor';
    private const TITLE            = Fixtures::PREFIX . 'writetarget';
    private const ORIGINAL         = 'wpmcp-test-original-body';
    private const OVERWRITE        = 'wpmcp-test-overwritten-by-a-subscriber';

    private static int $subscriberId = 0;
    private static int $editorId     = 0;
    private static int $postId       = 0;
    private static string $subscriberToken = '';
    private static string $editorToken     = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::requireSite();

        self::buildFixtures(self::build(...), self::destroy(...));
    }

    private static function build(): void
    {
        Fixtures::purge();

        self::$subscriberId = Fixtures::createUser(self::SUBSCRIBER_LOGIN, 'subscriber');
        self::$editorId     = Fixtures::createUser(self::EDITOR_LOGIN, 'editor');

        // Published and owned by the Editor: the Subscriber has no claim on it at all.
        self::$postId = Fixtures::createPost(
            self::TITLE,
            'publish',
            self::$editorId,
            self::ORIGINAL
        );

        // admin SCOPE on both, so the scope gate lets every write tool through and the
        // only thing left standing between the call and the database is the user.
        self::$subscriberToken = Fixtures::mintToken('admin', self::LABEL, self::$subscriberId);
        self::$editorToken     = Fixtures::mintToken('admin', self::LABEL, self::$editorId);
    }

    public static function tearDownAfterClass(): void
    {
        self::destroy();

        parent::tearDownAfterClass();
    }

    private static function destroy(): void
    {
        Fixtures::deletePost(self::$postId);
        Fixtures::deleteUser(self::$subscriberId);
        Fixtures::deleteUser(self::$editorId);
        Fixtures::deleteTokensLabelled(self::LABEL);
        Fixtures::purge();
    }

    /**
     * The refusal, and - the part that actually matters - the post is untouched
     * afterwards. Asserting only on the response would pass even if the write had
     * gone through and the error came later.
     *
     * @group sprint-1
     */
    public function testASubscriberBoundTokenCannotUpdateAPost(): void
    {
        $result = $this->mcp(self::$subscriberToken)->callTool('update-post', [
            'id'      => self::$postId,
            'content' => self::OVERWRITE,
        ]);

        self::assertRefused($result, 'update-post');

        self::assertSame(
            self::ORIGINAL,
            self::postContent(self::$postId),
            'The post content changed despite the refusal.'
        );
    }

    /**
     * Nor delete it, nor publish new content, nor touch the taxonomy.
     *
     * @group sprint-1
     */
    public function testASubscriberBoundTokenCannotDeletePublishOrCreateTerms(): void
    {
        $mcp = $this->mcp(self::$subscriberToken);

        $deleted = $mcp->callTool('delete-post', ['id' => self::$postId]);
        self::assertRefused($deleted, 'delete-post');
        self::assertSame(
            'publish',
            self::postStatus(self::$postId),
            'The post was trashed despite the refusal.'
        );

        $created = $mcp->callTool('create-post', [
            'title'  => Fixtures::PREFIX . 'subscriber-should-not-create',
            'status' => 'publish',
        ]);
        self::assertRefused($created, 'create-post');

        $term = $mcp->callTool('create-term', [
            'taxonomy' => 'category',
            'name'     => Fixtures::PREFIX . 'subscriber-should-not-create',
        ]);
        self::assertRefused($term, 'create-term');

        // The URL is unresolvable on purpose: if upload-media ever checks the
        // capability AFTER download_url, this call fails on DNS instead and the
        // refusal message is the only thing that can tell the two apart. Asserting
        // isError alone passed with the capability check deleted - verified.
        $upload = $mcp->callTool('upload-media', [
            'source_url' => 'https://wpmcp-test.invalid/wpmcp-test.png',
        ]);
        self::assertRefused($upload, 'upload-media');
    }

    /**
     * A write refusal, and specifically a CAPABILITY refusal.
     *
     * isError on its own is not enough: every one of these tools has other ways to
     * fail - a bad id, an unresolvable host, a validation error - and a test that
     * accepts any of them passes with the capability check removed.
     */
    private static function assertRefused(\WpMcp\Tests\Support\ToolResult $result, string $tool): void
    {
        self::assertTrue($result->isError, "{$tool} succeeded for a Subscriber: " . $result->text);
        self::assertStringContainsString(
            'is not allowed to',
            $result->text,
            "{$tool} failed, but not because of a capability check - so this test"
            . ' would pass with the check removed. Message: ' . $result->text
        );
    }

    /**
     * The same token shape bound to an Editor works. This is what stops the fix from
     * being "write tools are broken now".
     *
     * @group sprint-1
     */
    public function testAnEditorBoundTokenStillUpdatesThePost(): void
    {
        $updated = 'wpmcp-test-updated-by-the-editor';

        $result = $this->mcp(self::$editorToken)->callTool('update-post', [
            'id'      => self::$postId,
            'content' => $updated,
        ]);

        self::assertFalse(
            $result->isError,
            'An admin-scope token bound to an Editor was refused: ' . $result->text
        );
        self::assertSame($updated, self::postContent(self::$postId));

        // Put it back, so the Subscriber assertions above do not depend on order.
        $restored = $this->mcp(self::$editorToken)->callTool('update-post', [
            'id'      => self::$postId,
            'content' => self::ORIGINAL,
        ]);
        self::assertFalse($restored->isError, 'Could not restore the fixture: ' . $restored->text);
    }

    /** Read straight from the database, not through a tool that could also be broken. */
    private static function postContent(int $id): string
    {
        return WpCli::evaluate(sprintf('echo get_post(%d)->post_content;', $id));
    }

    private static function postStatus(int $id): string
    {
        return WpCli::evaluate(sprintf('echo get_post_status(%d);', $id));
    }
}
