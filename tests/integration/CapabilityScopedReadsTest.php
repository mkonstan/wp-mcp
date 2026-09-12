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
 * THE DEFAULT LISTING IS COVERED SEPARATELY, and it is the case that was broken.
 * `perm => 'readable'` alone does not fix it: WP_Query only consults `perm` when
 * `post_status` is an explicit list, and even then it scopes only the `private`
 * bucket. `status: "any"` takes a different branch entirely. So list-posts now
 * decides the statuses from capabilities, and these tests pin all three sides of it -
 * another author's private post and draft hidden, the user's OWN draft still shown,
 * and the admin token's listing unchanged.
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
    private const PUBLIC_TITLE   = Fixtures::PREFIX . 'public';
    private const OWN_DRAFT_TITLE = Fixtures::PREFIX . 'own-draft';
    private const SECRET         = 'wpmcp-test-secret-body';
    private const APPROVED_TEXT  = Fixtures::PREFIX . 'approved-comment';
    private const HELD_TEXT      = Fixtures::PREFIX . 'held-comment';
    private const PRIVATE_COMMENT_TEXT = Fixtures::PREFIX . 'comment-on-private';

    /** On the approved comment. Never returned by any tool; used to probe `search`. */
    private const COMMENT_EMAIL = Fixtures::PREFIX . 'commenter@example.invalid';

    /** An id far past anything the site could hold, for the "really missing" case. */
    private const MISSING_ID = 999999999;

    private static int $editorId  = 0;
    private static int $authorId  = 0;
    private static int $privateId = 0;
    private static int $draftId   = 0;
    private static int $ownDraftId = 0;
    private static int $publicId  = 0;
    private static string $authorToken = '';
    private static string $adminToken  = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::requireSite();

        // PHPUnit never calls tearDownAfterClass when setUpBeforeClass throws, so a
        // failure partway through would leave users, posts and a live token behind.
        self::buildFixtures(self::build(...), self::destroy(...));
    }

    private static function build(): void
    {
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

        // The author's OWN draft. Without it, "the author sees no drafts" would pass
        // for the wrong reason - a listing that hides everyone's drafts, their own
        // included, is a regression, not a fix.
        self::$ownDraftId = Fixtures::createPost(
            self::OWN_DRAFT_TITLE,
            'draft',
            self::$authorId,
            'wpmcp-test-own-draft-body'
        );

        // The comment fixtures are split across two posts so each assertion isolates
        // exactly one mechanism.
        //
        // On a PUBLISHED post, which the Author may read: one approved and one held
        // comment. Only the status default can separate those two, so "approved only"
        // is tested there and cannot pass because of the read_post filter instead.
        self::$publicId = Fixtures::createPost(
            self::PUBLIC_TITLE,
            'publish',
            self::$editorId,
            'wpmcp-test-public-body'
        );
        Fixtures::createComment(self::$publicId, self::APPROVED_TEXT, true, self::COMMENT_EMAIL);
        Fixtures::createComment(self::$publicId, self::HELD_TEXT, false);

        // On the Editor's PRIVATE post: an APPROVED comment. Approved, so the status
        // default cannot hide it - only a read_post check on comment_post_ID can.
        // Without that check this hands an Author the private post's id plus the
        // comment's author, text and date.
        Fixtures::createComment(self::$privateId, self::PRIVATE_COMMENT_TEXT, true);

        self::$authorToken = Fixtures::mintToken('read', self::LABEL, self::$authorId);
        // User 1 is the site's original administrator: the "unchanged behaviour" case.
        self::$adminToken = Fixtures::mintToken('read', self::LABEL, 1);
    }

    public static function tearDownAfterClass(): void
    {
        self::destroy();

        parent::tearDownAfterClass();
    }

    private static function destroy(): void
    {
        // Comments go with the post (force delete removes them), users are reassigned
        // to user 1, tokens go by label. purge() is the net underneath all of it.
        Fixtures::deletePost(self::$privateId);
        Fixtures::deletePost(self::$draftId);
        Fixtures::deletePost(self::$ownDraftId);
        Fixtures::deletePost(self::$publicId);
        Fixtures::deleteUser(self::$editorId);
        Fixtures::deleteUser(self::$authorId);
        Fixtures::deleteTokensLabelled(self::LABEL);
        Fixtures::purge();
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
     * (g) The DEFAULT listing - no arguments at all - is the one that mattered most
     * and the one that leaked. WP_Query's `status: "any"` takes a branch that never
     * consults `perm`, so before this the Author's own token listed the Editor's
     * private post and draft: id, title, status, slug and link. A title and a link
     * are a read. Same call, and it must now show neither.
     *
     * @group sprint-1
     */
    public function testTheDefaultListingHidesAnotherAuthorsPrivateAndDraftPosts(): void
    {
        $result = $this->mcp(self::$authorToken)->callTool('list-posts', ['limit' => 100]);

        self::assertFalse($result->isError, 'list-posts failed: ' . $result->text);

        $ids = $result->column('id');
        self::assertNotContains(
            self::$privateId,
            $ids,
            'The default list-posts still shows another author\'s private post.'
        );
        self::assertNotContains(
            self::$draftId,
            $ids,
            'The default list-posts still shows another author\'s draft.'
        );
        self::assertStringNotContainsString(self::PRIVATE_TITLE, $result->text);
        self::assertStringNotContainsString(self::DRAFT_TITLE, $result->text);
    }

    /**
     * The other half of (g): the Author's OWN draft is still listed. WordPress lets
     * an author edit their own drafts, so hiding them would be a regression dressed
     * up as a fix - and it is what the capability-gated status list does on its own,
     * measured, which is why list-posts runs a second author-scoped query.
     *
     * @group sprint-1
     */
    public function testTheDefaultListingStillShowsTheUsersOwnDraft(): void
    {
        $result = $this->mcp(self::$authorToken)->callTool('list-posts', ['limit' => 100]);

        self::assertFalse($result->isError, 'list-posts failed: ' . $result->text);
        self::assertContains(
            self::$ownDraftId,
            $result->column('id'),
            'The Author cannot see their own draft. The status list is gated on'
            . ' edit_others_posts, so own unpublished work needs the second,'
            . ' author-scoped query - see wpmcp_own_listable_statuses().'
        );
    }

    /**
     * (h) Asking for a status you may not see is an empty list, not an error. A
     * refusal would confirm that something is being withheld, which is exactly the
     * disclosure get-post goes out of its way to avoid.
     *
     * @group sprint-1
     */
    public function testAskingForAStatusTheUserMayNotSeeIsEmptyRatherThanAnError(): void
    {
        $result = $this->mcp(self::$authorToken)
            ->callTool('list-posts', ['status' => 'private', 'limit' => 100]);

        self::assertFalse(
            $result->isError,
            'An unpermitted status was refused instead of returning nothing: ' . $result->text
        );
        self::assertSame([], $result->items(), 'Expected no items for an Author asking for private posts.');
        self::assertSame(0, $result->data()['count']);
    }

    /**
     * (i) The admin token's DEFAULT listing is unchanged: both the private post and
     * the draft are there. This is the assertion that stops the fix above from being
     * "hide unpublished posts from everyone".
     *
     * @group sprint-1
     */
    public function testTheAdminTokensDefaultListingStillShowsPrivateAndDraftPosts(): void
    {
        $result = $this->mcp(self::$adminToken)->callTool('list-posts', ['limit' => 100]);

        self::assertFalse($result->isError, 'list-posts failed: ' . $result->text);

        $ids = $result->column('id');
        self::assertContains(self::$privateId, $ids, 'The admin token lost sight of the private post.');
        self::assertContains(self::$draftId, $ids, 'The admin token lost sight of the draft.');
        self::assertContains(self::$ownDraftId, $ids, 'The admin token lost sight of the Author\'s draft.');
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

        // Narrowed to the PUBLISHED post that holds both comments - one the Author may
        // read, so the only thing separating them is the status default. The approved
        // one is still returned, so this is a filter and not a blanket empty result.
        $onPublic = $author->callTool('list-comments', ['post' => self::$publicId]);
        self::assertFalse($onPublic->isError, 'list-comments failed: ' . $onPublic->text);
        self::assertStringContainsString(self::APPROVED_TEXT, $onPublic->text);
        self::assertStringNotContainsString(self::HELD_TEXT, $onPublic->text);
    }

    /**
     * A comment on a post the caller cannot read is a read of that post. The comment
     * here is APPROVED, so the status default does nothing: only the read_post check
     * on comment_post_ID keeps the Editor's private post out of an Author's reach.
     *
     * @group sprint-1
     */
    public function testListCommentsHidesCommentsOnPostsTheUserCannotRead(): void
    {
        $author = $this->mcp(self::$authorToken);

        $all = $author->callTool('list-comments', ['per_page' => 100]);
        self::assertFalse($all->isError, 'list-comments failed: ' . $all->text);
        self::assertStringNotContainsString(
            self::PRIVATE_COMMENT_TEXT,
            $all->text,
            'An Author was given a comment on another user\'s private post.'
        );
        self::assertNotContains(
            self::$privateId,
            $all->column('post'),
            'list-comments leaked the id of a post the Author cannot read.'
        );

        // Asking for that post directly must not help either.
        $targeted = $author->callTool('list-comments', ['post' => self::$privateId]);
        self::assertFalse($targeted->isError, 'list-comments failed: ' . $targeted->text);
        self::assertSame(
            [],
            $targeted->items(),
            'Naming the private post returned its comments anyway.'
        );

        // The admin token still sees it, so the filter is a filter.
        $admin = $this->mcp(self::$adminToken)
            ->callTool('list-comments', ['post' => self::$privateId]);
        self::assertFalse($admin->isError, 'list-comments failed: ' . $admin->text);
        self::assertStringContainsString(self::PRIVATE_COMMENT_TEXT, $admin->text);
    }

    /**
     * `status: "hold"` is one argument away from every token. wp-admin needs
     * moderate_comments to see held or spam comments, and an Author does not have it;
     * before this the new "approved by default" was true and meaningless.
     *
     * Silently narrowed to `approve`, not refused: an error would confirm that held
     * comments exist and are being withheld.
     *
     * @group sprint-1
     */
    public function testAskingForHeldCommentsWithoutModerateCommentsReturnsApprovedOnly(): void
    {
        $result = $this->mcp(self::$authorToken)
            ->callTool('list-comments', ['status' => 'hold', 'per_page' => 100]);

        self::assertFalse(
            $result->isError,
            'status:"hold" was refused rather than narrowed: ' . $result->text
        );
        self::assertStringNotContainsString(
            self::HELD_TEXT,
            $result->text,
            'An Author read a held-for-moderation comment by asking for status:"hold".'
        );

        $statuses = array_values(array_unique($result->column('status')));
        self::assertNotContains('unapproved', $statuses, 'Statuses seen: ' . implode(', ', array_map('strval', $statuses)));

        // The admin token, which does hold moderate_comments, still gets it.
        $admin = $this->mcp(self::$adminToken)
            ->callTool('list-comments', ['status' => 'hold', 'post' => self::$publicId]);
        self::assertFalse($admin->isError, 'list-comments failed: ' . $admin->text);
        self::assertStringContainsString(
            self::HELD_TEXT,
            $admin->text,
            'status:"hold" stopped working for a user who may moderate.'
        );
    }

    /**
     * `search` matches comment text and author name, and nothing else.
     *
     * WP_Comment_Query hard-codes its search columns to include
     * comment_author_email and comment_author_IP, with no filter to narrow them. A
     * tool that advertises "emails omitted" while letting a caller confirm an address
     * one prefix at a time does not omit them, so list-comments builds its own
     * clause. The email here is on the comment the Author CAN read, so nothing but
     * the column list can be what hides it.
     *
     * @group sprint-1
     */
    public function testSearchDoesNotMatchCommentAuthorEmails(): void
    {
        $author = $this->mcp(self::$authorToken);

        $byContent = $author->callTool('list-comments', [
            'search'   => 'wpmcp-test-approved',
            'per_page' => 100,
        ]);
        self::assertFalse($byContent->isError, 'list-comments failed: ' . $byContent->text);
        self::assertStringContainsString(
            self::APPROVED_TEXT,
            $byContent->text,
            'Searching comment text stopped working.'
        );

        $byEmail = $author->callTool('list-comments', [
            'search'   => 'wpmcp-test-commenter@',
            'per_page' => 100,
        ]);
        self::assertFalse($byEmail->isError, 'list-comments failed: ' . $byEmail->text);
        self::assertSame(
            [],
            $byEmail->items(),
            'A search on an author email matched, so emails can be probed one prefix'
            . ' at a time despite the tool saying they are omitted.'
        );
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
