<?php
/**
 * Sprint 12: list-revisions, get-revision, restore-revision, and the undo baseline
 * every content write now leaves behind.
 *
 * WHAT IS OURS TO TEST. Not whether WordPress can restore a revision - it can. What is
 * ours is the GATE (one class of access, one not_found for every way through it), the two
 * NAMED refusals wp-admin makes before restoring (revisions off, post locked), what the
 * result reports, and the BASELINE: core saves a revision AFTER an update, of the NEW
 * state, so a post with no revisions loses its original text on its first update unless
 * the tool saves the current state first. MEASURED on both sites (WP 7.1): wp_insert_post
 * leaves 0 revisions, one wp_update_post then leaves 1 - holding the new text.
 *
 * EVERY ROUND TRIP ENDS AT A READ TOOL OR AT THE DATABASE, never at the writer's answer.
 * restore-revision re-reads the row it wrote; get-post and wp-cli do not share that path.
 *
 * EVERY REFUSAL ASSERTS THE SITE TOO: the content and the revision count, read in another
 * process, must not have moved.
 *
 * @group sprint-12
 */

declare(strict_types=1);

namespace WpMcp\Tests\Integration;

use RuntimeException;
use WpMcp\Tests\Support\FixtureIntegrationTestCase;
use WpMcp\Tests\Support\Fixtures;
use WpMcp\Tests\Support\IntegrationTestCase;
use WpMcp\Tests\Support\McpClient;
use WpMcp\Tests\Support\MuPlugin;
use WpMcp\Tests\Support\ToolResult;
use WpMcp\Tests\Support\WpCli;

final class RevisionToolsTest extends FixtureIntegrationTestCase
{
    /** No site this suite runs against has a post id anywhere near this. */
    private const MISSING_ID = 2000000000;

    /** The mu-plugin that turns revisions off for ONE fixture post, for this run only. */
    private const NO_REVISIONS = 'norevisions';

    /**
     * Three shapes of backslash - no escape meaning, a regex, a doubled one - which is
     * what a restore must hand back byte for byte. wp_restore_post_revision() slashes its
     * own data (revision.php:498); a plugin that slashed again would store one too many.
     */
    private const BACKSLASHES = 'C:\\Users\\max \\d+ a\\\\b';

    private static function label(): string { return Fixtures::name('revisions'); }

    private static function editorLogin(): string { return Fixtures::name('reveditor'); }
    private static function authorLogin(): string { return Fixtures::name('revauthor'); }
    private static function otherAuthorLogin(): string { return Fixtures::name('revother'); }

    /** Display names that are NOT the logins, or a "names by display name" check is vacuous. */
    private static function editorDisplayName(): string { return Fixtures::name('Rev Editor Display'); }
    private static function otherDisplayName(): string { return Fixtures::name('Rev Lock Holder Display'); }

    private static int $editorId = 0;
    private static int $authorId = 0;
    private static int $otherAuthorId = 0;

    private static string $editorToken = '';
    private static string $otherAuthorToken = '';

    /** The Author's own published post, with two revisions, newest first. */
    private static int $authorPostId = 0;
    /** @var list<int> */
    private static array $authorRevisions = [];

    /** An Editor's post that another user holds the edit lock on. */
    private static int $lockedPostId = 0;
    /** @var list<int> */
    private static array $lockedRevisions = [];

    /** An Editor's post with revisions switched off by the mu-plugin, plus one autosave. */
    private static int $noRevisionsPostId = 0;
    /** @var list<int> */
    private static array $noRevisionsRevisions = [];
    private static int $noRevisionsAutosaveId = 0;

    /** A post whose status, date, author, slug and terms a restore must not touch. */
    private static int $keptPostId = 0;
    /** @var list<int> */
    private static array $keptRevisions = [];
    private static int $categoryId = 0;
    private static int $tagId = 0;

    /** A post with an up-to-date revision, for "exactly one more". */
    private static int $upToDatePostId = 0;

    private static int $attachmentId = 0;

    /** Posts created BY the tools, or by a test, during the run. */
    private static array $created = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::requireSite();

        self::buildFixtures(self::build(...), self::destroy(...));
    }

    private static function build(): void
    {
        Fixtures::purge();

        self::$editorId      = Fixtures::createUser(self::editorLogin(), 'editor');
        self::$authorId      = Fixtures::createUser(self::authorLogin(), 'author');
        self::$otherAuthorId = Fixtures::createUser(self::otherAuthorLogin(), 'author');

        Fixtures::setDisplayName(self::$editorId, self::editorDisplayName());
        Fixtures::setDisplayName(self::$otherAuthorId, self::otherDisplayName());

        self::$authorPostId    = self::postWithTwoRevisions('rev-author', self::$authorId);
        self::$authorRevisions = self::revisionIds(self::$authorPostId);

        self::$lockedPostId    = self::postWithTwoRevisions('rev-locked', self::$editorId);
        self::$lockedRevisions = self::revisionIds(self::$lockedPostId);

        self::$noRevisionsPostId     = self::postWithTwoRevisions('rev-off', self::$editorId);
        self::$noRevisionsRevisions  = self::revisionIds(self::$noRevisionsPostId);
        self::$noRevisionsAutosaveId = self::autosave(self::$noRevisionsPostId, Fixtures::name('rev-off-autosave'));

        self::$upToDatePostId = self::postWithTwoRevisions('rev-uptodate', self::$editorId);

        // THE KEPT POST carries everything a restore must leave alone, each set to a value
        // a fresh post would not get: a back-dated date, an author who is not the
        // restoring Editor, a chosen slug, a category and a tag.
        self::$categoryId = Fixtures::createTerm('category', Fixtures::name('rev-cat'));
        self::$tagId      = Fixtures::createTerm('post_tag', Fixtures::name('rev-tag'));
        self::$keptPostId = Fixtures::createPostWith([
            'post_title'   => Fixtures::name('rev-kept-a'),
            'post_status'  => 'publish',
            'post_author'  => self::$authorId,
            'post_date'    => '2021-05-06 07:08:09',
            'post_name'    => Fixtures::name('rev-kept-slug'),
            'post_content' => Fixtures::name('rev-kept-body-a'),
        ]);
        Fixtures::setPostTerms(self::$keptPostId, 'category', [self::$categoryId]);
        Fixtures::setPostTerms(self::$keptPostId, 'post_tag', [self::$tagId]);
        self::saveRevision(self::$keptPostId);
        self::rewrite(self::$keptPostId, Fixtures::name('rev-kept-b'), Fixtures::name('rev-kept-body-b'));
        self::$keptRevisions = self::revisionIds(self::$keptPostId);

        // PARENTED to a post the Editor may edit, so a gate that took any post for a revision
        // would find an editable parent and let the attachment through - an unparented one
        // would be refused by the parent check and hide that mistake.
        self::$attachmentId = Fixtures::createAttachment(
            Fixtures::name('rev-attachment'),
            self::$editorId,
            Fixtures::name('rev-attachment') . '.png',
            'image/png',
            self::$authorPostId
        );

        // REVISIONS OFF FOR ONE POST, for this run's requests only. wp_revisions_to_keep()
        // is what wp_revisions_enabled() answers through for a post type without
        // `revisions` support AND for WP_POST_REVISIONS false, so this reaches exactly the
        // branch wp-admin/revision.php:52 takes - without registering a public post type on
        // somebody's site for the length of the class, and without leaving a post of an
        // unregistered type that `wp post list --post_type=any` (and so purge) cannot see.
        MuPlugin::drop(self::NO_REVISIONS, self::noRevisionsSource(self::$noRevisionsPostId));

        self::$editorToken      = Fixtures::mintToken('admin', self::label(), self::$editorId);
        self::$otherAuthorToken = Fixtures::mintToken('admin', self::label(), self::$otherAuthorId);
    }

    public static function tearDownAfterClass(): void
    {
        self::destroy();

        parent::tearDownAfterClass();
    }

    private static function destroy(): void
    {
        MuPlugin::remove(self::NO_REVISIONS);

        // wp_delete_post() takes a post's revisions and autosaves with it.
        foreach (self::$created as $id) { Fixtures::deletePost((int) $id); }

        self::$created = [];

        foreach ([self::$authorPostId, self::$lockedPostId, self::$noRevisionsPostId,
                  self::$upToDatePostId, self::$keptPostId, self::$attachmentId] as $id) {
            Fixtures::deletePost($id);
        }

        if (self::$categoryId > 0) { Fixtures::deleteTerm('category', self::$categoryId); }
        if (self::$tagId > 0) { Fixtures::deleteTerm('post_tag', self::$tagId); }

        Fixtures::deleteUser(self::$editorId);
        Fixtures::deleteUser(self::$authorId);
        Fixtures::deleteUser(self::$otherAuthorId);

        Fixtures::deleteTokensLabelled(self::label());
        Fixtures::purge();
    }

    /* ------------------------------------------------------------------
     * G1, G2, G3 - restore, the baseline, and the revision a restore leaves
     * ---------------------------------------------------------------- */

    /**
     * G1. create-post, update-post, restore the pre-update revision: get-post returns the
     * original title, content and excerpt EXACTLY, backslashes and HTML included.
     *
     * @group sprint-12
     */
    public function testRestoreRoundTripsTheOriginalExactly(): void
    {
        $original = self::originalFields('rt');
        $id       = $this->createThroughTheTool($original);

        $this->updateThroughTheTool($id, 'rt');

        $revisionId = $this->revisionHolding($id, $original['title']);
        $restore    = $this->mcp(self::$editorToken)->callTool('restore-revision', ['revision_id' => $revisionId]);

        self::assertFalse($restore->isError, $restore->text);

        $post = $this->mcp(self::$editorToken)->callTool('get-post', ['id' => $id]);

        self::assertFalse($post->isError, $post->text);
        self::assertSame($original['title'], $post->data()['title'], 'The restored title is not the original.');
        self::assertSame($original['content'], $post->data()['content'], 'The restored content is not the original, byte for byte.');
        self::assertSame($original['excerpt'], $post->data()['excerpt'], 'The restored excerpt is not the original.');

        // AND THE DATABASE, in another process.
        self::assertSame($original['content'], Fixtures::postField($id, 'post_content'));
    }

    /**
     * G2. A post create-post made has NO revisions, and after ONE update-post its
     * pre-update state is a revision that can be read and restored.
     *
     * Red without the baseline: core saves its revision after the update, of the new
     * text, so the only revision on the post would hold what update-post wrote.
     *
     * @group sprint-12
     */
    public function testAPostCreatedByCreatePostIsRestorableAfterOneUpdate(): void
    {
        $original = self::originalFields('first');
        $id       = $this->createThroughTheTool($original);

        self::assertSame(0, Fixtures::revisionCount($id), 'wp_insert_post saved a revision on create; the premise of this test moved.');

        $this->updateThroughTheTool($id, 'first');

        self::assertSame(
            2,
            Fixtures::revisionCount($id),
            'One update-post on a post with no revisions must leave two: the pre-update'
            . ' state the tool saved first, and the new state core saved after.'
        );

        $revisionId = $this->revisionHolding($id, $original['title']);
        $revision   = $this->mcp(self::$editorToken)->callTool('get-revision', ['revision_id' => $revisionId]);

        self::assertFalse($revision->isError, $revision->text);
        self::assertSame($original['title'], $revision->data()['title']);
        self::assertSame($original['content'], $revision->data()['content']);
        self::assertSame($original['excerpt'], $revision->data()['excerpt']);
        self::assertSame($id, $revision->data()['parent']);
        self::assertFalse($revision->data()['autosave']);

        $restore = $this->mcp(self::$editorToken)->callTool('restore-revision', ['revision_id' => $revisionId]);

        self::assertFalse($restore->isError, $restore->text);
        self::assertSame($original['content'], Fixtures::postField($id, 'post_content'));
    }

    /**
     * G3. The restored state is itself a new revision: list-revisions grows by exactly
     * one, and its newest item is the restored text and the id the result named.
     *
     * @group sprint-12
     */
    public function testTheRestoredStateIsItselfANewRevision(): void
    {
        $original = self::originalFields('grow');
        $id       = $this->createThroughTheTool($original);

        $this->updateThroughTheTool($id, 'grow');

        $before     = $this->listRevisions($id, ['limit' => 100])->items();
        $revisionId = $this->revisionHolding($id, $original['title']);

        $restore = $this->mcp(self::$editorToken)->callTool('restore-revision', ['revision_id' => $revisionId]);

        self::assertFalse($restore->isError, $restore->text);

        $result = $restore->data();

        self::assertSame($id, $result['id']);
        self::assertSame($revisionId, $result['restored_from']);
        self::assertSame(['title', 'content', 'excerpt'], $result['fields']);
        self::assertIsInt($result['new_revision_id'], 'The result does not name the revision the restore created.');

        $after = $this->listRevisions($id, ['limit' => 100])->items();

        self::assertCount(count($before) + 1, $after, 'list-revisions did not grow by exactly one.');
        self::assertSame(count($before) + 1, Fixtures::revisionCount($id));
        self::assertSame($result['new_revision_id'], $after[0]['id'], 'The newest revision is not the one the result named.');
        self::assertSame($original['title'], $after[0]['title']);
        self::assertFalse($after[0]['autosave']);
        self::assertSame(self::$editorId, $after[0]['author']['id']);
        self::assertSame(self::editorDisplayName(), $after[0]['author']['name']);

        $newest = $this->mcp(self::$editorToken)->callTool('get-revision', ['revision_id' => $after[0]['id']]);

        self::assertFalse($newest->isError, $newest->text);
        self::assertSame($original['content'], $newest->data()['content']);
        self::assertSame($original['excerpt'], $newest->data()['excerpt']);
    }

    /**
     * Every update-post still makes a revision - and on a post whose latest revision
     * already matches it, the baseline costs NOTHING: exactly one more, not two.
     *
     * @group sprint-12
     */
    public function testUpdatePostAddsExactlyOneRevisionToAnUpToDatePost(): void
    {
        $before = Fixtures::revisionCount(self::$upToDatePostId);

        self::assertGreaterThan(0, $before, 'The fixture post has no revisions; the premise moved.');

        $result = $this->mcp(self::$editorToken)->callTool('update-post', [
            'id'      => self::$upToDatePostId,
            'content' => Fixtures::name('rev-uptodate-body-c'),
        ]);

        self::assertFalse($result->isError, $result->text);
        self::assertSame(
            $before + 1,
            Fixtures::revisionCount(self::$upToDatePostId),
            'An update-post on a post whose latest revision matched it must add exactly one'
            . ' revision: none means core stopped saving, two means the baseline saved a'
            . ' duplicate.'
        );
    }

    /**
     * list-revisions pages the way list-posts does, newest first, flags an autosave, and
     * carries no content.
     *
     * @group sprint-12
     */
    public function testListIsNewestFirstPagedAndFlagsAutosaves(): void
    {
        $postId          = self::postWithTwoRevisions('rev-paged', self::$editorId);
        self::$created[] = $postId;
        $autosaveId      = self::autosave($postId, Fixtures::name('rev-paged-autosave'));
        $all             = self::revisionIds($postId);

        self::assertCount(3, $all, 'The fixture post does not hold two revisions and an autosave.');
        self::assertContains($autosaveId, $all);

        $seen = [];

        foreach ([1 => true, 2 => true, 3 => false] as $page => $hasMore) {
            $data = $this->listRevisions($postId, ['limit' => 1, 'page' => $page])->data();

            self::assertCount(1, $data['items'], "Page {$page} does not hold one item.");
            self::assertSame($hasMore, $data['has_more'], "has_more is wrong on page {$page}.");

            $item   = $data['items'][0];
            $seen[] = $item['id'];

            self::assertArrayNotHasKey('content', $item, 'The list carries revision content.');
            self::assertSame($item['id'] === $autosaveId, $item['autosave'], "autosave is wrong for revision {$item['id']}.");
            self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/', (string) $item['date']);
            self::assertSame(['id', 'name'], array_keys($item['author']));
        }

        self::assertSame($all, $seen, 'Paging did not walk the revisions newest first.');
    }

    /* ------------------------------------------------------------------
     * G4 - one not_found for every way through the gate
     * ---------------------------------------------------------------- */

    /**
     * G4. Another Author may READ this published post but not edit it, and every revision
     * tool answers them exactly what it answers for an id that does not exist.
     *
     * @group sprint-12
     */
    public function testAnotherAuthorGetsTheMissingIdAnswerFromEveryRevisionTool(): void
    {
        $other = $this->mcp(self::$otherAuthorToken);

        // THE PREMISE: they can read it. Without this the test is "a stranger is refused".
        $read = $other->callTool('get-post', ['id' => self::$authorPostId]);
        self::assertFalse($read->isError, 'The other Author cannot read the post, so this test proves nothing: ' . $read->text);
        self::assertNull($read->data()['revisions'], 'get-post shows revisions to a caller who may not edit.');

        $content = Fixtures::postField(self::$authorPostId, 'post_content');
        $count   = Fixtures::revisionCount(self::$authorPostId);

        $this->assertSameNotFound($other, 'list-revisions', ['id' => self::$authorPostId], ['id' => self::MISSING_ID]);
        $this->assertSameNotFound($other, 'get-revision', ['revision_id' => self::$authorRevisions[1]], ['revision_id' => self::MISSING_ID]);
        $this->assertSameNotFound($other, 'restore-revision', ['revision_id' => self::$authorRevisions[1]], ['revision_id' => self::MISSING_ID]);

        self::assertSame($content, Fixtures::postField(self::$authorPostId, 'post_content'), 'A refused restore changed the post.');
        self::assertSame($count, Fixtures::revisionCount(self::$authorPostId), 'A refused restore changed the revisions.');
    }

    /**
     * G4. An id that is not a revision - a normal post, an attachment - is not_found to
     * get-revision and restore-revision, even for an Editor who may edit both; and an id
     * that is not a content post - an attachment, a revision - is not_found to
     * list-revisions.
     *
     * @group sprint-12
     */
    public function testAnIdThatIsNotARevisionIsTheMissingIdAnswer(): void
    {
        $editor  = $this->mcp(self::$editorToken);
        $content = Fixtures::postField(self::$authorPostId, 'post_content');

        foreach ([self::$authorPostId, self::$attachmentId] as $id) {
            $this->assertSameNotFound($editor, 'get-revision', ['revision_id' => $id], ['revision_id' => self::MISSING_ID]);
            $this->assertSameNotFound($editor, 'restore-revision', ['revision_id' => $id], ['revision_id' => self::MISSING_ID]);
        }

        $this->assertSameNotFound($editor, 'list-revisions', ['id' => self::$attachmentId], ['id' => self::MISSING_ID]);
        $this->assertSameNotFound($editor, 'list-revisions', ['id' => self::$authorRevisions[0]], ['id' => self::MISSING_ID]);

        self::assertSame($content, Fixtures::postField(self::$authorPostId, 'post_content'));
    }

    /* ------------------------------------------------------------------
     * G5 - the two named refusals
     * ---------------------------------------------------------------- */

    /**
     * G5. A post another user is editing right now is not restored, the refusal names the
     * lock holder by DISPLAY NAME only, and nothing changes.
     *
     * Also red without the require of wp-admin/includes/post.php: a REST request does not
     * load wp_check_post_lock(), and calling it there is a fatal, not a refusal. wp-cli
     * DOES load it (measured), which is why this can only be tested over HTTP.
     *
     * @group sprint-12
     */
    public function testALockedPostIsRefusedByNameAndNothingChanges(): void
    {
        WpCli::evaluate(sprintf(
            'echo (int) update_post_meta(%d, "_edit_lock", time() . ":" . %d);',
            self::$lockedPostId,
            self::$otherAuthorId
        ));

        $content = Fixtures::postField(self::$lockedPostId, 'post_content');
        $count   = Fixtures::revisionCount(self::$lockedPostId);

        try {
            $result = $this->mcp(self::$editorToken)->callTool('restore-revision', [
                'revision_id' => self::$lockedRevisions[1],
            ]);
        } finally {
            Fixtures::deletePostMeta(self::$lockedPostId, '_edit_lock');
        }

        self::assertTrue($result->isError, 'A post locked by another user was restored.');
        self::assertStringContainsString('being edited by ' . self::otherDisplayName(), $result->text);
        self::assertStringNotContainsString(self::otherAuthorLogin(), $result->text, 'The refusal names the lock holder by login.');
        self::assertSame($content, Fixtures::postField(self::$lockedPostId, 'post_content'), 'A refused restore changed the post.');
        self::assertSame($count, Fixtures::revisionCount(self::$lockedPostId), 'A refused restore changed the revisions.');
    }

    /**
     * G5. With revisions off for the post, a revision that is not an autosave is refused,
     * naming why, and nothing changes.
     *
     * @group sprint-12
     */
    public function testRevisionsOffRefusesARevisionAndNothingChanges(): void
    {
        $content = Fixtures::postField(self::$noRevisionsPostId, 'post_content');
        $count   = Fixtures::revisionCount(self::$noRevisionsPostId);

        $result = $this->mcp(self::$editorToken)->callTool('restore-revision', [
            'revision_id' => self::$noRevisionsRevisions[1],
        ]);

        self::assertTrue($result->isError, 'A revision was restored on a post with revisions turned off.');
        self::assertStringContainsString('Revisions are turned off', $result->text);
        self::assertSame($content, Fixtures::postField(self::$noRevisionsPostId, 'post_content'), 'A refused restore changed the post.');
        self::assertSame($count, Fixtures::revisionCount(self::$noRevisionsPostId), 'A refused restore changed the revisions.');
    }

    /**
     * G5's exception, wp-admin/revision.php:52: with revisions off, an AUTOSAVE may still
     * be restored.
     *
     * @group sprint-12
     */
    public function testRevisionsOffStillRestoresAnAutosave(): void
    {
        $result = $this->mcp(self::$editorToken)->callTool('restore-revision', [
            'revision_id' => self::$noRevisionsAutosaveId,
        ]);

        self::assertFalse($result->isError, 'An autosave was refused on a post with revisions off: ' . $result->text);
        self::assertTrue($result->data()['autosave']);
        self::assertSame(
            Fixtures::name('rev-off-autosave'),
            Fixtures::postField(self::$noRevisionsPostId, 'post_content'),
            'The autosave was reported restored and its content is not on the post.'
        );
    }

    /* ------------------------------------------------------------------
     * G6 - what a restore must not touch
     * ---------------------------------------------------------------- */

    /**
     * G6. Restore copies only the revisioned fields: status, date, author, slug and terms
     * come out exactly as they went in.
     *
     * @group sprint-12
     */
    public function testRestoreLeavesStatusDateAuthorSlugAndTermsAlone(): void
    {
        $editor = $this->mcp(self::$editorToken);
        $before = $editor->callTool('get-post', ['id' => self::$keptPostId])->data();

        $restore = $editor->callTool('restore-revision', ['revision_id' => self::$keptRevisions[1]]);

        self::assertFalse($restore->isError, $restore->text);

        $after = $editor->callTool('get-post', ['id' => self::$keptPostId])->data();

        // THE RESTORE HAPPENED, or every assertion below holds for a no-op.
        self::assertSame(Fixtures::name('rev-kept-body-a'), $after['content']);
        self::assertSame(Fixtures::name('rev-kept-a'), $after['title']);

        foreach (['status', 'date', 'date_gmt', 'author', 'slug', 'terms'] as $field) {
            self::assertSame($before[$field], $after[$field], "restore-revision changed {$field}.");
        }

        // AND THE BEFORE WAS WHAT THE FIXTURE SET, so "unchanged" is not "unchanged default".
        self::assertSame('publish', $after['status']);
        self::assertSame('2021-05-06T07:08:09', $after['date']);
        self::assertSame(self::$authorId, $after['author']['id']);
        self::assertSame(Fixtures::name('rev-kept-slug'), $after['slug']);
        self::assertSame([self::$categoryId], array_column($after['terms']['category'], 'id'));
        self::assertSame([self::$tagId], array_column($after['terms']['post_tag'], 'id'));
    }

    /* ------------------------------------------------------------------
     * helpers
     * ---------------------------------------------------------------- */

    /** @return array{title: string, content: string, excerpt: string} */
    private static function originalFields(string $what): array
    {
        return [
            'title'   => Fixtures::name("rev-{$what}-title ") . self::BACKSLASHES,
            'content' => '<p class="' . Fixtures::name("rev-{$what}") . '"><strong>' . self::BACKSLASHES . '</strong></p>',
            'excerpt' => Fixtures::name("rev-{$what}-excerpt ") . self::BACKSLASHES,
        ];
    }

    private function createThroughTheTool(array $fields): int
    {
        $result = $this->mcp(self::$editorToken)->callTool('create-post', $fields + ['status' => 'publish']);

        self::assertFalse($result->isError, $result->text);

        $id              = (int) $result->data()['id'];
        self::$created[] = $id;

        return $id;
    }

    private function updateThroughTheTool(int $id, string $what): void
    {
        $result = $this->mcp(self::$editorToken)->callTool('update-post', [
            'id'      => $id,
            'title'   => Fixtures::name("rev-{$what}-title-updated"),
            'content' => '<p>' . Fixtures::name("rev-{$what}-body-updated") . '</p>',
            'excerpt' => Fixtures::name("rev-{$what}-excerpt-updated"),
        ]);

        self::assertFalse($result->isError, $result->text);
    }

    private function listRevisions(int $id, array $extra = []): ToolResult
    {
        $result = $this->mcp(self::$editorToken)->callTool('list-revisions', ['id' => $id] + $extra);

        self::assertFalse($result->isError, $result->text);

        return $result;
    }

    /** The id of the revision whose title is $title, found through list-revisions. */
    private function revisionHolding(int $postId, string $title): int
    {
        foreach ($this->listRevisions($postId, ['limit' => 100])->items() as $item) {
            if ($item['title'] === $title) { return (int) $item['id']; }
        }

        self::fail(
            "No revision of post {$postId} holds the pre-update title. The first update"
            . ' of a post with no revisions lost its original text.'
        );
    }

    private function assertSameNotFound(McpClient $client, string $tool, array $refused, array $missing): void
    {
        $answer  = $client->callTool($tool, $refused);
        $control = $client->callTool($tool, $missing);

        self::assertTrue($control->isError, "{$tool} did not refuse a missing id.");
        self::assertTrue($answer->isError, "{$tool} answered " . json_encode($refused) . ': ' . $answer->text);
        self::assertSame(
            $control->text,
            $answer->text,
            "{$tool} answers " . json_encode($refused) . ' differently from a missing id, so'
            . ' probing ids tells a caller something exists.'
        );
    }

    /** A published post with content A, a revision of A, then rewritten to B. */
    private static function postWithTwoRevisions(string $what, int $author): int
    {
        $id = Fixtures::createPost(Fixtures::name("{$what}-a"), 'publish', $author, Fixtures::name("{$what}-body-a"));

        self::saveRevision($id);
        self::rewrite($id, Fixtures::name("{$what}-b"), Fixtures::name("{$what}-body-b"));

        return $id;
    }

    private static function saveRevision(int $postId): void
    {
        WpCli::evaluate(sprintf('echo (int) wp_save_post_revision(%d);', $postId));
    }

    private static function rewrite(int $postId, string $title, string $content): void
    {
        $out = WpCli::evaluate(sprintf(
            '$r = wp_update_post(wp_slash(array("ID" => %d, "post_title" => %s, "post_content" => %s)), true);'
            . ' echo is_wp_error($r) ? "ERROR:" . $r->get_error_message() : (int) $r;',
            $postId,
            var_export($title, true),
            var_export($content, true)
        ));

        if (!preg_match('/(^|\s)' . $postId . '\s*$/', $out)) {
            throw new RuntimeException("Could not rewrite fixture post {$postId}: {$out}");
        }
    }

    /** An autosave of $postId holding $content, as wp-admin's autosave would store it. */
    private static function autosave(int $postId, string $content): int
    {
        $out = WpCli::evaluate(sprintf(
            '$p = get_post(%d, ARRAY_A); $p["post_content"] = %s;'
            . ' echo "ID:" . (int) _wp_put_post_revision(wp_slash($p), true);',
            $postId,
            var_export($content, true)
        ));

        if (!preg_match('/ID:(\d+)/', $out, $m) || (int) $m[1] <= 0) {
            throw new RuntimeException("Could not create an autosave of fixture post {$postId}: {$out}");
        }

        return (int) $m[1];
    }

    /** @return list<int> newest first, autosaves included */
    private static function revisionIds(int $postId): array
    {
        $out = WpCli::evaluate(sprintf(
            'echo "IDS:" . implode(",", wp_get_post_revisions(%d, array("fields" => "ids")));',
            $postId
        ));

        if (!preg_match('/IDS:([\d,]*)/', $out, $m)) {
            throw new RuntimeException("Could not list the revisions of fixture post {$postId}: {$out}");
        }

        return array_values(array_map('intval', array_filter(explode(',', $m[1]))));
    }

    private static function noRevisionsSource(int $postId): string
    {
        $run    = Fixtures::runId();
        $header = 'HTTP_' . strtoupper(str_replace('-', '_', IntegrationTestCase::RUN_HEADER));

        return <<<PHP
/**
 * wp-mcp sprint-12 fixture for run {$run}: revisions OFF for post {$postId}, in this run's
 * requests only. Dropped and removed by tests/integration/RevisionToolsTest.php. If you
 * are reading this on a live site, the run that wrote it crashed; deleting it is safe.
 */
add_filter('wp_revisions_to_keep', static function (\$num, \$post) {
    \$mine = isset(\$_SERVER['{$header}']) && \$_SERVER['{$header}'] === '{$run}';

    return (\$mine && \$post && (int) \$post->ID === {$postId}) ? 0 : \$num;
}, 10, 2);
PHP;
    }
}
