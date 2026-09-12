<?php
/**
 * The read tools see what the token's user may see - and nothing else.
 *
 * This is the sprint's central claim, and it is only testable here: WordPress
 * capability resolution needs real roles, a real database, and a request that
 * actually went through wpmcp_authorize(). Each test is a real HTTPS POST carrying a
 * real token in an `Authorization: Bearer` header.
 *
 * THE ROLES ARE NOT THE ONES THE SPRINT BRIEF ASSUMED. The brief expected an Editor
 * to be unable to read another author's private post. Editors *can*: the default
 * editor role holds `read_private_posts`, and map_meta_cap('read_post') on a private
 * post asks for exactly that capability. Verified on the site under test:
 *
 *     editor read_private_posts: true
 *     author read_private_posts: false
 *
 * So the roles are swapped relative to the brief. The EDITOR owns the private post
 * and the draft; the AUTHOR is the unprivileged reader whose token must be refused.
 * That still exercises the thing that matters - a token whose user lacks the
 * capability - and it does so with a role that genuinely lacks it, rather than
 * asserting a restriction WordPress does not impose.
 *
 * Every fixture is named `wpmcp-test-*` and removed in tearDownAfterClass.
 *
 * @group sprint-1
 */

declare(strict_types=1);

namespace WpMcp\Tests\Integration;

use WpMcp\Tests\Support\Fixtures;
use WpMcp\Tests\Support\FixtureIntegrationTestCase;

final class CapabilityScopedReadsTest extends FixtureIntegrationTestCase
{
    private const LABEL          = Fixtures::PREFIX . 'caps';
    private const PRIVATE_TITLE  = Fixtures::PREFIX . 'private';
    private const DRAFT_TITLE    = Fixtures::PREFIX . 'draft';
    private const SECRET         = 'wpmcp-test-secret-body';
    private const APPROVED_TEXT  = Fixtures::PREFIX . 'approved-comment';
    private const HELD_TEXT      = Fixtures::PREFIX . 'held-comment';

    /** An id far past anything the site could hold, for the "really missing" case. */
    private const MISSING_ID = 999999999;

    private static int $editorId  = 0;
    private static int $authorId  = 0;
    private static int $privateId = 0;
    private static int $draftId   = 0;
    private static string $authorToken = '';
    private static string $adminToken  = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::requireSite();

        // A previous run killed halfway would leave these names taken.
        Fixtures::purge();

        self::$editorId = Fixtures::createUser(Fixtures::PREFIX . 'editor', 'editor');
        self::$authorId = Fixtures::createUser(Fixtures::PREFIX . 'author', 'author');

        self::$privateId = Fixtures::createPost(
            self::PRIVATE_TITLE,
            'private',
            self::$editorId,
            self::SECRET
        );
        self::$draftId = Fixtures::createPost(
            self::DRAFT_TITLE,
            'draft',
            self::$editorId,
            'wpmcp-test-draft-body'
        );

        // One approved and one held-for-moderation comment, so "approved only" is a
        // real filter rather than an empty result that would pass either way.
        Fixtures::createComment(self::$draftId, self::APPROVED_TEXT, true);
        Fixtures::createComment(self::$draftId, self::HELD_TEXT, false);

        self::$authorToken = Fixtures::mintToken('read', self::LABEL, self::$authorId);
        // User 1 is the site's original administrator: the "unchanged behaviour" case.
        self::$adminToken = Fixtures::mintToken('read', self::LABEL, 1);
    }

    public static function tearDownAfterClass(): void
    {
        // Comments go with the post (force delete removes them), users are reassigned
        // to user 1, tokens go by label. purge() is the net underneath all of it.
        Fixtures::deletePost(self::$privateId);
        Fixtures::deletePost(self::$draftId);
        Fixtures::deleteUser(self::$editorId);
        Fixtures::deleteUser(self::$authorId);
        Fixtures::deleteTokensLabelled(self::LABEL);
        Fixtures::purge();

        parent::tearDownAfterClass();
    }

    /**
     * (a) get-post refuses a post the token's user cannot read - with the SAME error
     * a genuinely missing id produces. The identical-message assertion is the point:
     * a distinct "forbidden" would turn get-post into an existence oracle for every
     * private draft on the site.
     *
     * @group sprint-1
     */
    public function testGetPostRefusesAPrivatePostTheUserCannotReadAndDoesNotLeakItsExistence(): void
    {
        $author = $this->mcp(self::$authorToken);

        $refused = $author->callTool('get-post', ['id' => self::$privateId]);
        $missing = $author->callTool('get-post', ['id' => self::MISSING_ID]);

        self::assertTrue(
            $refused->isError,
            'An Author read another user\'s private post. Response: ' . $refused->text
        );
        self::assertStringContainsString('No post with that ID.', $refused->text);
        self::assertStringNotContainsString(
            self::SECRET,
            $refused->text,
            'The refusal leaked the post content.'
        );
        self::assertSame(
            $missing->text,
            $refused->text,
            'The refusal for an unreadable post differs from the one for a missing post,'
            . ' so get-post can be used to prove a post exists.'
        );
    }

    /**
     * (b) The private post is absent from a private-status listing for that user.
     *
     * @group sprint-1
     */
    public function testListPostsOmitsAPrivatePostTheUserCannotRead(): void
    {
        $result = $this->mcp(self::$authorToken)
            ->callTool('list-posts', ['status' => 'private', 'limit' => 100]);

        self::assertFalse($result->isError, 'list-posts failed: ' . $result->text);
        self::assertNotContains(
            self::$privateId,
            $result->column('id'),
            'An Author saw another user\'s private post in a private-status listing.'
        );
    }

    /**
     * (c) The admin token behaves exactly as it did before identity existed: it reads
     * the private post and sees it listed. Without this, every assertion above could
     * be passing because the tools broke for everyone.
     *
     * @group sprint-1
     */
    public function testTheAdminTokenStillReadsAndListsThePrivatePost(): void
    {
        $admin = $this->mcp(self::$adminToken);

        $post = $admin->callTool('get-post', ['id' => self::$privateId]);
        self::assertFalse($post->isError, 'The admin token was refused: ' . $post->text);
        self::assertSame(self::SECRET, $post->data()['content']);
        self::assertSame('private', $post->data()['status']);

        $listed = $admin->callTool('list-posts', ['status' => 'private', 'limit' => 100]);
        self::assertFalse($listed->isError, 'list-posts failed: ' . $listed->text);
        self::assertContains(
            self::$privateId,
            $listed->column('id'),
            'The admin token no longer sees the private post it used to see.'
        );
    }

    /**
     * (d) list-comments with no arguments returns approved comments only. The held
     * comment is unreviewed, attacker-supplied text; the old default handed it over
     * unasked.
     *
     * @group sprint-1
     */
    public function testListCommentsReturnsApprovedCommentsOnlyByDefault(): void
    {
        $author = $this->mcp(self::$authorToken);

        $all = $author->callTool('list-comments');
        self::assertFalse($all->isError, 'list-comments failed: ' . $all->text);

        $statuses = array_values(array_unique($all->column('status')));
        self::assertSame(
            ['approved'],
            $statuses,
            'list-comments returned a non-approved comment by default. Statuses seen: '
            . implode(', ', array_map('strval', $statuses))
        );
        self::assertStringNotContainsString(
            self::HELD_TEXT,
            $all->text,
            'The held-for-moderation comment was returned by a default list-comments.'
        );

        // Narrowed to the post that holds both comments: the approved one is still
        // returned, so the filter is a filter and not a blanket empty result.
        $onDraft = $author->callTool('list-comments', ['post' => self::$draftId]);
        self::assertFalse($onDraft->isError, 'list-comments failed: ' . $onDraft->text);
        self::assertStringContainsString(self::APPROVED_TEXT, $onDraft->text);
        self::assertStringNotContainsString(self::HELD_TEXT, $onDraft->text);
    }

    /**
     * list-posts is bounded to the allow-listed post types, so a token cannot walk
     * revisions or internal types through it. `revision` exists as a post type and is
     * not viewable, which is exactly the case wpmcp_post_type_ok() rejects.
     *
     * @group sprint-1
     */
    public function testListPostsRefusesANonAllowListedPostType(): void
    {
        $result = $this->mcp(self::$authorToken)
            ->callTool('list-posts', ['post_type' => 'revision']);

        self::assertTrue(
            $result->isError,
            'list-posts listed the `revision` post type. Response: ' . $result->text
        );
        self::assertStringContainsString('revision', $result->text);
    }
}
