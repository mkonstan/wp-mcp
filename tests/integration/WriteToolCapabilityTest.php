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
use WpMcp\Tests\Support\MuPlugin;
use WpMcp\Tests\Support\WpCli;

final class WriteToolCapabilityTest extends FixtureIntegrationTestCase
{
    /** Per-run fixture names; see Fixtures and CapabilityScopedReadsTest for why methods. */
    private static function label(): string { return Fixtures::name('writecaps'); }
    private static function subscriberLogin(): string { return Fixtures::name('subscriber'); }
    private static function editorLogin(): string { return Fixtures::name('writeeditor'); }
    private static function authorLogin(): string { return Fixtures::name('writeauthor'); }
    private static function title(): string { return Fixtures::name('writetarget'); }
    private static function original(): string { return Fixtures::name('original-body'); }
    private static function overwrite(): string { return Fixtures::name('overwritten-by-a-subscriber'); }

    /** A comment on the Editor's post, so reply-comment has a parent to aim at. */
    private static function parentComment(): string { return Fixtures::name('reply-parent'); }

    /**
     * The file code-write must NOT create. Per-run, because a red run leaves it in the
     * active theme and the debris report has to be able to say whose it was.
     */
    private static function strayCssName(): string { return Fixtures::name('should-not-exist') . '.css'; }

    /** Turns the code tools on for this run only; see codeToolsSource(). */
    private const CODE_SWITCH = 'code-on';

    private static int $subscriberId = 0;
    private static int $editorId     = 0;
    private static int $authorId     = 0;
    private static int $postId       = 0;
    private static int $parentCommentId = 0;
    /** The draft the terms test creates; deleted in teardown. */
    private static int $carrierId    = 0;
    private static string $subscriberToken = '';
    private static string $editorToken     = '';
    private static string $authorToken     = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::requireSite();

        self::buildFixtures(self::build(...), self::destroy(...));
    }

    private static function build(): void
    {
        Fixtures::purge();

        self::$subscriberId = Fixtures::createUser(self::subscriberLogin(), 'subscriber');
        self::$editorId     = Fixtures::createUser(self::editorLogin(), 'editor');
        // An Author has assign_terms on category (it maps to edit_posts) but not
        // edit_terms (manage_categories) - exactly the split R3 is about.
        self::$authorId     = Fixtures::createUser(self::authorLogin(), 'author');

        // Published and owned by the Editor: the Subscriber has no claim on it at all.
        //
        // comment_status=open EXPLICITLY. The reply-comment refusal below is only RED
        // on an open thread - with comments closed the tool refuses for the wrong
        // reason and the test would pass with the capability check deleted. Taking
        // that from the site's default_comment_status made the red case a property of
        // somebody's site instead of a property of the code.
        self::$postId = Fixtures::createPost(
            self::title(),
            'publish',
            self::$editorId,
            self::original(),
            'post',
            0,
            'open'
        );

        self::$parentCommentId = Fixtures::createComment(self::$postId, self::parentComment(), true);

        // The code tools are only registered when wpmcp_code_enabled is on, so a
        // refusal test for them needs it on.
        //
        // A FILTER, NOT THE OPTION. This used to read the option, write 1, and restore
        // the old value in teardown - which cannot survive two runners on one site: the
        // second run reads the first run's 1 as "the operator's setting", and the first
        // run's teardown switches the tools off underneath the second run's test. A
        // pre_option_ filter in a per-run mu-plugin turns them on for exactly as long
        // as this class needs them, writes nothing to the database, and needs no
        // restore - so there is nothing left to race over.
        //
        // After purge(), which removes this run's mu-plugins.
        MuPlugin::drop(self::CODE_SWITCH, self::codeToolsSource());

        // admin SCOPE on both, so the scope gate lets every write tool through and the
        // only thing left standing between the call and the database is the user.
        self::$subscriberToken = Fixtures::mintToken('admin', self::label(), self::$subscriberId);
        self::$editorToken     = Fixtures::mintToken('admin', self::label(), self::$editorId);
        self::$authorToken     = Fixtures::mintToken('admin', self::label(), self::$authorId);
    }

    public static function tearDownAfterClass(): void
    {
        self::destroy();

        parent::tearDownAfterClass();
    }

    private static function destroy(): void
    {
        MuPlugin::remove(self::CODE_SWITCH);

        // A red run of the code-write refusal leaves this in the active theme, and
        // nothing else on the site would ever clean it.
        WpCli::tryEvaluate(sprintf(
            '$f = get_stylesheet_directory() . "/" . %s;'
            . ' echo file_exists($f) ? (int) unlink($f) : 1;',
            "'" . addcslashes(self::strayCssName(), "'\\") . "'"
        ));

        Fixtures::deletePost(self::$postId);
        Fixtures::deletePost(self::$carrierId);
        Fixtures::deleteUser(self::$subscriberId);
        Fixtures::deleteUser(self::$editorId);
        Fixtures::deleteUser(self::$authorId);
        Fixtures::deleteTokensLabelled(self::label());
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
            'content' => self::overwrite(),
        ]);

        self::assertRefused($result, 'update-post');

        self::assertSame(
            self::original(),
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
            'title'  => Fixtures::name('subscriber-should-not-create'),
            'status' => 'publish',
        ]);
        self::assertRefused($created, 'create-post');

        $term = $mcp->callTool('create-term', [
            'taxonomy' => 'category',
            'name'     => Fixtures::name('subscriber-should-not-create'),
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
     * R1. The four code tools are the largest write on the surface - they read,
     * rewrite and delete PHP in the active theme - and they checked no capability at
     * all, only admin scope plus the wpmcp_code_enabled option. code-read and
     * code-list are in scope too: theme PHP is source code, not content.
     *
     * @group sprint-1
     */
    public function testASubscriberBoundTokenCannotTouchThemeFiles(): void
    {
        $mcp = $this->mcp(self::$subscriberToken);

        // The option really is on, so a refusal cannot be "the tool is not registered".
        $listedTools = $mcp->post('tools/list');
        self::assertStringContainsString(
            'code-read',
            (string) $listedTools->getBody(),
            'The code tools are not registered, so this test would prove nothing.'
            . ' wpmcp_code_enabled should have been turned on in setUpBeforeClass.'
        );

        self::assertRefused($mcp->callTool('code-list', ['path' => '']), 'code-list');
        self::assertRefused($mcp->callTool('code-read', ['path' => 'style.css']), 'code-read');
        self::assertRefused(
            $mcp->callTool('code-write', [
                'path'    => self::strayCssName(),
                'content' => '/* wpmcp-test */',
            ]),
            'code-write'
        );
        self::assertRefused($mcp->callTool('code-delete', ['path' => 'style.css']), 'code-delete');

        // And nothing was written: code-write is the one that leaves evidence.
        self::assertSame(
            '0',
            WpCli::evaluate(
                'echo (int) file_exists(get_stylesheet_directory()'
                . ' . "/' . self::strayCssName() . '");'
            ),
            'code-write was refused but still created the file.'
        );
    }

    /**
     * R2. reply-comment was gated on read_post - a READ capability on a tool that
     * writes - and inserted with comment_approved => 1, so a Subscriber-bound token
     * posted pre-approved comments on any post it could see, moderation queue and
     * all. wp-admin requires edit_post on the post being replied on.
     *
     * @group sprint-1
     */
    public function testASubscriberBoundTokenCannotReplyToComments(): void
    {
        $before = self::commentCount(self::$postId);

        $result = $this->mcp(self::$subscriberToken)->callTool('reply-comment', [
            'id'      => self::$parentCommentId,
            'content' => Fixtures::name('reply-from-a-subscriber'),
        ]);

        self::assertRefused($result, 'reply-comment');
        self::assertSame(
            $before,
            self::commentCount(self::$postId),
            'reply-comment was refused but a comment was still inserted.'
        );
    }

    /**
     * R2, the other half: an Editor CAN reply, and the reply is approved because an
     * Editor holds moderate_comments. Without this the refusal above could just mean
     * reply-comment is broken.
     *
     * @group sprint-1
     */
    public function testAnEditorBoundTokenRepliesAndTheReplyIsApproved(): void
    {
        $result = $this->mcp(self::$editorToken)->callTool('reply-comment', [
            'id'      => self::$parentCommentId,
            'content' => Fixtures::name('reply-from-the-editor'),
        ]);

        self::assertFalse($result->isError, 'An Editor was refused: ' . $result->text);
        self::assertSame(
            'approved',
            $result->data()['status'],
            'A moderator\'s reply should be approved on insert.'
        );
    }

    /**
     * R3. create-post's `terms` reached wp_insert_term through wpmcp_apply_terms with
     * no capability check, so the edit_terms gate that create-term just gained was one
     * argument away from being bypassed - an Author could create categories.
     *
     * An Author, not the Subscriber: a Subscriber cannot create a post at all, so the
     * request would be refused before it ever reached the terms.
     *
     * @group sprint-1
     */
    public function testAnAuthorBoundTokenCannotCreateCategoriesThroughPostTerms(): void
    {
        $newName = Fixtures::name('term-via-create-post');

        $result = $this->mcp(self::$authorToken)->callTool('create-post', [
            'title' => Fixtures::name('terms-carrier'),
            'terms' => ['category' => [$newName]],
        ]);

        self::assertFalse($result->isError, 'create-post failed outright: ' . $result->text);
        $data = $result->data();
        self::$carrierId = (int) $data['id'];

        // Refused, and SAID so - a silent drop would leave the caller believing the
        // category was applied.
        self::assertArrayHasKey(
            'terms_refused',
            $data,
            'The unknown category was not reported as refused: ' . $result->text
        );
        self::assertContains($newName, $data['terms_refused']['category']);

        self::assertSame(
            '0',
            WpCli::evaluate(sprintf(
                'echo (int) (bool) get_term_by("name", %s, "category");',
                "'" . addcslashes($newName, "'\\") . "'"
            )),
            'An Author created a category through create-post {terms}.'
        );
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
        $updated = Fixtures::name('updated-by-the-editor');

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
            'content' => self::original(),
        ]);
        self::assertFalse($restored->isError, 'Could not restore the fixture: ' . $restored->text);
    }

    /**
     * A mu-plugin that answers `wpmcp_code_enabled` with 1 without touching the option.
     *
     * pre_option_, not option_: for an option that does not exist in the database,
     * `option_wpmcp_code_enabled` never fires at all, so the filter has to be the one
     * that runs BEFORE the lookup. Verified against get_option() on the site under test.
     */
    private static function codeToolsSource(): string
    {
        return "add_filter('pre_option_wpmcp_code_enabled', static function () { return 1; });\n";
    }

    /** Comments on a post, straight from the database. */
    private static function commentCount(int $postId): string
    {
        return WpCli::evaluate(sprintf(
            'global $wpdb; echo (int) $wpdb->get_var($wpdb->prepare('
            . '"SELECT COUNT(*) FROM $wpdb->comments WHERE comment_post_ID = %%d", %d));',
            $postId
        ));
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
