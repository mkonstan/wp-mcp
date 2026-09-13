<?php
/**
 * reply-comment goes through core's comment pipeline, so the site's own rules apply.
 *
 * WHAT WAS WRONG. The tool called wp_allow_comment() and then wp_insert_comment()
 * directly, and a Sprint 1 commit message claimed "Akismet still applies". It did not.
 * Akismet, and every other spam filter, moderation plugin and notifier, hooks
 * `preprocess_comment` - which is applied by wp_new_comment() and by nothing else - and
 * `comment_post`, which is where the moderator and post-author notification mails come
 * from. wp_allow_comment() on its own gives the blocklist, the moderation option, the
 * duplicate check and the flood check. It does not give the plugins.
 *
 * That mattered most for the path the tool was built for: a token writing comments on
 * somebody's live site, bypassing the spam protection that site chose to install.
 *
 * HOW THIS PROVES IT, AND WHY OBSERVING IS NOT ENOUGH. A test that only watched
 * `preprocess_comment` fire would be satisfied by a call that applied the filter and
 * threw its result away. So the mu-plugin's filter MODIFIES the comment content, and the
 * assertion reads the stored comment back out of the database: the marker can only be
 * there if the filter's return value became the comment. That is the property Akismet
 * depends on - `preprocess_comment` is how it sets `comment_approved` to 'spam'.
 *
 * The reply is made by an EDITOR, who holds moderate_comments. Under the old code that
 * was the branch that skipped wp_allow_comment() entirely and set comment_approved = 1
 * by hand, so it is the branch with the most to prove; core reaches the same approval
 * through wp_check_comment_data(), which respects the post author and any moderator.
 *
 * @group sprint-2
 */

declare(strict_types=1);

namespace WpMcp\Tests\Integration;

use WpMcp\Tests\Support\Fixtures;
use WpMcp\Tests\Support\FixtureIntegrationTestCase;
use WpMcp\Tests\Support\MuPlugin;
use WpMcp\Tests\Support\TestRecorder;
use WpMcp\Tests\Support\WpCli;

final class CommentPipelineTest extends FixtureIntegrationTestCase
{
    private static function label(): string { return Fixtures::name('pipeline'); }
    private static function login(): string { return Fixtures::name('pipeline-editor'); }
    private static function title(): string { return Fixtures::name('pipeline-post'); }
    private static function parentText(): string { return Fixtures::name('pipeline-parent'); }
    private static function replyText(): string { return Fixtures::name('pipeline-reply'); }

    /** What the mu-plugin's preprocess_comment filter appends. */
    private static function marker(): string { return Fixtures::name('marked-by-preprocess'); }

    /** The mu-plugin that proves the filter's RETURN VALUE is used. */
    private const MARKER = 'comment-marker';

    private static int $editorId = 0;
    private static int $postId = 0;
    private static int $parentId = 0;
    private static string $token = '';
    /** Replies created by the tests, so teardown can remove them. */
    private static array $replyIds = [];

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
        MuPlugin::drop(self::MARKER, self::markerSource());

        self::$editorId = Fixtures::createUser(self::login(), 'editor');

        // comment_status=open EXPLICITLY: a closed thread is refused before the
        // pipeline is reached, and the site default is not this test's business.
        self::$postId = Fixtures::createPost(
            self::title(),
            'publish',
            self::$editorId,
            'wpmcp-test-pipeline-body',
            'post',
            0,
            'open'
        );

        self::$parentId = Fixtures::createComment(self::$postId, self::parentText(), true);

        // admin scope so the write tool is reachable; the Editor's capabilities are what
        // let it through the edit_post gate in front of the pipeline.
        self::$token = Fixtures::mintToken('admin', self::label(), self::$editorId);
    }

    public static function tearDownAfterClass(): void
    {
        self::destroy();

        parent::tearDownAfterClass();
    }

    private static function destroy(): void
    {
        foreach (self::$replyIds as $id) {
            WpCli::tryRun(['comment', 'delete', (string) $id, '--force']);
        }

        self::$replyIds = [];

        MuPlugin::remove(self::MARKER);
        TestRecorder::uninstall();

        // The comments cascade with the post.
        Fixtures::deletePost(self::$postId);
        Fixtures::deleteUser(self::$editorId);
        Fixtures::deleteTokensLabelled(self::label());
        Fixtures::purge();
    }

    /**
     * Item 1. The reply goes through wp_new_comment(), so `preprocess_comment` runs,
     * its return value is what gets stored, and `comment_post` fires afterwards.
     *
     * @group sprint-2
     */
    public function testTheReplyGoesThroughCoresCommentPipeline(): void
    {
        TestRecorder::reset();

        $result = $this->mcp(self::$token)->callTool('reply-comment', [
            'id'      => self::$parentId,
            'content' => self::replyText(),
        ]);

        self::assertFalse($result->isError, 'reply-comment failed: ' . $result->text);

        $id = (int) ($result->data()['id'] ?? 0);
        self::assertGreaterThan(0, $id, 'reply-comment returned no comment id: ' . $result->text);
        self::$replyIds[] = $id;

        // 1. The filter ran, once, and saw this reply.
        $seen = TestRecorder::detailsOf('preprocess_comment');

        self::assertCount(
            1,
            $seen,
            'preprocess_comment did not fire exactly once for one reply. This is the'
            . ' filter Akismet and every other spam plugin hooks; wp_insert_comment does'
            . ' not apply it.'
        );
        self::assertStringContainsString(
            self::replyText(),
            (string) $seen[0]['comment_content'],
            'preprocess_comment fired, but not for this reply.'
        );
        self::assertSame(self::$postId, (int) $seen[0]['comment_post_ID']);

        // 2. Its RETURN VALUE became the comment. Observation alone would not show this.
        $stored = WpCli::evaluate(sprintf('echo get_comment(%d)->comment_content;', $id));

        self::assertStringContainsString(
            self::marker(),
            $stored,
            'The comment was stored WITHOUT what the preprocess_comment filter added, so'
            . ' the filter is being applied and discarded. A plugin that sets'
            . ' comment_approved to "spam" there would be ignored exactly this way.'
        );

        // 3. comment_post fired, which is what the notification mails hang off.
        self::assertSame(
            1,
            TestRecorder::countOf('comment_post'),
            'comment_post did not fire. The moderator and post-author notifications hook'
            . ' it, so without it a reply is invisible to the people who own the site.'
        );
        self::assertSame(
            $id,
            (int) TestRecorder::detailsOf('comment_post')[0]['id'],
            'comment_post fired for a different comment.'
        );
    }

    /**
     * Core's own approval decision is reached, and it is the right one: a moderator's
     * reply is approved.
     *
     * The old code hard-coded this for a moderate_comments holder and never consulted
     * core at all. Now wp_check_comment_data() decides, and it says 1 for a moderator or
     * the post's author - so the outcome is unchanged while the decision has moved to
     * the place every other comment on the site goes through.
     *
     * @group sprint-2
     */
    public function testAModeratorsReplyIsStillApproved(): void
    {
        $result = $this->mcp(self::$token)->callTool('reply-comment', [
            'id'      => self::$parentId,
            'content' => self::replyText() . '-second',
        ]);

        self::assertFalse($result->isError, 'reply-comment failed: ' . $result->text);

        $data = $result->data();
        $id   = (int) ($data['id'] ?? 0);
        self::assertGreaterThan(0, $id);
        self::$replyIds[] = $id;

        self::assertSame(
            'approved',
            $data['status'] ?? null,
            'An Editor\'s reply was not approved. Core approves a moderator through'
            . ' wp_check_comment_data(); if this is "unapproved" the user_id is not'
            . ' reaching that decision.'
        );

        // And it really is a reply to the parent, not a top-level comment: core resets
        // comment_parent unless the parent is approved or unapproved, so this also pins
        // that the parent fixture survived the pipeline.
        self::assertSame(
            (string) self::$parentId,
            WpCli::evaluate(sprintf('echo (int) get_comment(%d)->comment_parent;', $id)),
            'The reply was stored with no parent.'
        );
    }

    /**
     * A mu-plugin whose preprocess_comment filter APPENDS a marker - the minimum that
     * distinguishes "the filter ran" from "the filter's result was used".
     *
     * Gated on this run's header for the same reason the recorder is: a mu-plugin is
     * loaded by every request the site serves, and silently editing a concurrent
     * runner's - or the operator's - comments would be a real change to the site.
     */
    private static function markerSource(): string
    {
        $run    = Fixtures::runId();
        $marker = self::marker();
        $header = 'HTTP_' . strtoupper(str_replace('-', '_', \WpMcp\Tests\Support\IntegrationTestCase::RUN_HEADER));

        return <<<PHP
add_filter('preprocess_comment', static function (\$commentdata) {
    \$ours = isset(\$_SERVER['{$header}']) && \$_SERVER['{$header}'] === '{$run}';

    if (\$ours && isset(\$commentdata['comment_content'])) {
        \$commentdata['comment_content'] .= ' {$marker}';
    }

    return \$commentdata;
}, 5);
PHP;
    }
}
