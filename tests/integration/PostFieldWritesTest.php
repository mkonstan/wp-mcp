<?php
/**
 * The three post fields sprint 11 added to create-post and update-post, and the
 * capability WordPress itself puts in front of each one.
 *
 * WHAT IS ACTUALLY UNDER TEST. Not "does wp_update_post store a date" - it does, and a
 * test of that would be a test of WordPress. What is ours is the SHAPE and the GATE:
 *
 *   date            core silently re-dates a draft unless `edit_date` is passed, silently
 *                   turns publish+future into `future`, and silently turns future+past
 *                   into `publish`. All three are measured here through the tool, because
 *                   all three are things an agent would otherwise get wrong and not know.
 *                   The GMT column is asserted against get_gmt_from_date() read off the
 *                   site, never against a constant: both Local sites happen to sit at UTC
 *                   and a hard-coded pair would prove nothing here and be wrong elsewhere.
 *                   One case sends an explicit +05:30 offset precisely so that the local
 *                   and GMT columns cannot be the same string on a UTC site.
 *   author          edit_others_posts to set it, and the target must be able to write
 *                   this post type. A Subscriber is refused with a sentence that says
 *                   nothing else about the account.
 *   featured_image  edit_post ON THE ATTACHMENT, which resolves through the attachment's
 *                   own author and its parent. Measured on WP 7.1: an Author holds it on
 *                   their own upload and not on another user's; an Editor holds it on
 *                   every one.
 *
 * EVERY REFUSAL ASSERTS THE SITE TOO. A refusal that did not happen and a refusal that
 * happened after the write both come back as `isError: true`, so each one reads the
 * stored row back through wp-cli and asserts it did not move.
 *
 * @group sprint-11
 */

declare(strict_types=1);

namespace WpMcp\Tests\Integration;

use WpMcp\Tests\Support\Fixtures;
use WpMcp\Tests\Support\FixtureIntegrationTestCase;
use WpMcp\Tests\Support\ToolResult;

final class PostFieldWritesTest extends FixtureIntegrationTestCase
{
    private static function label(): string { return Fixtures::name('fieldwrites'); }

    private static function editorLogin(): string { return Fixtures::name('fweditor'); }
    private static function authorLogin(): string { return Fixtures::name('fwauthor'); }
    private static function otherAuthorLogin(): string { return Fixtures::name('fwother'); }
    private static function contributorLogin(): string { return Fixtures::name('fwcontrib'); }
    private static function subscriberLogin(): string { return Fixtures::name('fwsub'); }

    /** A display name that is NOT the login, or the author assertion proves nothing. */
    private static function authorDisplayName(): string { return Fixtures::name('Fw Author Display'); }

    private static function editorPostTitle(): string { return Fixtures::name('fw-editor-post'); }
    private static function authorPostTitle(): string { return Fixtures::name('fw-author-post'); }
    private static function draftTitle(): string { return Fixtures::name('fw-draft'); }

    private static function editorImageFile(): string { return Fixtures::name('fw-editor-image') . '.png'; }
    private static function authorImageFile(): string { return Fixtures::name('fw-author-image') . '.png'; }
    private static function documentFile(): string { return Fixtures::name('fw-document') . '.pdf'; }

    private static int $editorId = 0;
    private static int $authorId = 0;
    private static int $otherAuthorId = 0;
    private static int $contributorId = 0;
    private static int $subscriberId = 0;

    private static int $editorPostId = 0;
    private static int $authorPostId = 0;
    private static int $draftId = 0;

    private static int $editorImageId = 0;
    private static int $authorImageId = 0;
    private static int $documentId = 0;

    private static string $editorToken = '';
    private static string $authorToken = '';
    private static string $contributorToken = '';

    /** Posts created BY the tools during the run, so teardown can take them away. */
    private static array $created = [];

    /** What this site calls its timezone, for a failure message somebody can act on. */
    private static string $timezone = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::requireSite();

        self::buildFixtures(self::build(...), self::destroy(...));
    }

    private static function build(): void
    {
        Fixtures::purge();

        self::$timezone = Fixtures::siteTimezone();

        self::$editorId      = Fixtures::createUser(self::editorLogin(), 'editor');
        self::$authorId      = Fixtures::createUser(self::authorLogin(), 'author');
        self::$otherAuthorId = Fixtures::createUser(self::otherAuthorLogin(), 'author');
        self::$contributorId = Fixtures::createUser(self::contributorLogin(), 'contributor');
        self::$subscriberId  = Fixtures::createUser(self::subscriberLogin(), 'subscriber');

        // `wp user create` leaves display_name equal to the login, so an assertion that
        // get-post returns a display name and not a login would compare two identical
        // strings and prove nothing.
        Fixtures::setDisplayName(self::$authorId, self::authorDisplayName());

        self::$editorPostId = Fixtures::createPost(
            self::editorPostTitle(),
            'publish',
            self::$editorId,
            Fixtures::name('fw-editor-body')
        );
        self::$authorPostId = Fixtures::createPost(
            self::authorPostTitle(),
            'publish',
            self::$authorId,
            Fixtures::name('fw-author-body')
        );
        self::$draftId = Fixtures::createPost(
            self::draftTitle(),
            'draft',
            self::$editorId,
            Fixtures::name('fw-draft-body')
        );

        // THREE ATTACHMENTS, one per cell of the featured_image gate. None of them
        // reaches the filesystem - see Fixtures::createAttachment().
        self::$editorImageId = Fixtures::createAttachment(
            Fixtures::name('fw-editor-image'),
            self::$editorId,
            self::editorImageFile()
        );
        self::$authorImageId = Fixtures::createAttachment(
            Fixtures::name('fw-author-image'),
            self::$authorId,
            self::authorImageFile()
        );
        self::$documentId = Fixtures::createAttachment(
            Fixtures::name('fw-document'),
            self::$editorId,
            self::documentFile(),
            'application/pdf'
        );

        self::$editorToken      = Fixtures::mintToken('admin', self::label(), self::$editorId);
        self::$authorToken      = Fixtures::mintToken('admin', self::label(), self::$authorId);
        self::$contributorToken = Fixtures::mintToken('admin', self::label(), self::$contributorId);
    }

    public static function tearDownAfterClass(): void
    {
        self::destroy();

        parent::tearDownAfterClass();
    }

    private static function destroy(): void
    {
        foreach (self::$created as $id) { Fixtures::deletePost((int) $id); }

        self::$created = [];

        Fixtures::deletePost(self::$editorPostId);
        Fixtures::deletePost(self::$authorPostId);
        Fixtures::deletePost(self::$draftId);
        Fixtures::deletePost(self::$editorImageId);
        Fixtures::deletePost(self::$authorImageId);
        Fixtures::deletePost(self::$documentId);

        Fixtures::deleteUser(self::$editorId);
        Fixtures::deleteUser(self::$authorId);
        Fixtures::deleteUser(self::$otherAuthorId);
        Fixtures::deleteUser(self::$contributorId);
        Fixtures::deleteUser(self::$subscriberId);

        Fixtures::deleteTokensLabelled(self::label());
        Fixtures::purge();
    }

    /* ------------------------------------------------------------------
     * date
     * ---------------------------------------------------------------- */

    /**
     * A future date with status `future` schedules the post: the status comes back
     * `future`, post_date is exactly what was asked for, post_date_gmt is what this
     * site's timezone makes of it, and get-post reads back the same instant.
     *
     * @group sprint-11
     */
    public function testSchedulingLandsFutureWithTheDateAsked(): void
    {
        $local = gmdate('Y-m-d H:i:s', time() + 3 * 86400);
        $asked = str_replace(' ', 'T', $local);

        $result = $this->create(self::$editorToken, Fixtures::name('fw-scheduled'), [
            'status' => 'future',
            'date'   => $asked,
        ]);

        self::assertFalse($result->isError, $result->text);

        $data = $result->data();
        $id   = (int) $data['id'];

        self::assertSame(
            'future',
            $data['status'],
            'A post dated three days out with status "future" did not land scheduled.'
        );
        self::assertContains('date', $data['changed'], 'The result does not report date as changed.');

        // The STORED columns, read in another process.
        self::assertSame(
            $local,
            Fixtures::postField($id, 'post_date'),
            'post_date is not the local time that was asked for.'
        );
        self::assertSame(
            Fixtures::gmtFromDate($local),
            Fixtures::postField($id, 'post_date_gmt'),
            'post_date_gmt does not agree with get_gmt_from_date() on this site, whose'
            . ' timezone is ' . self::$timezone . '. The two columns have to describe one'
            . ' instant or every scheduled post fires at the wrong time.'
        );

        // AND THE READ TOOL AGREES. A write that only the database can confirm is half a
        // round trip; get-post is how an agent would actually check.
        $post = $this->mcp(self::$editorToken)->callTool('get-post', ['id' => $id]);

        self::assertFalse($post->isError, $post->text);
        self::assertSame(
            str_replace(' ', 'T', $local),
            $post->data()['date'],
            'get-post reports a different date from the one create-post stored.'
        );
        self::assertSame('future', $post->data()['status']);
    }

    /**
     * An explicit UTC offset fixes the instant, and the two columns then differ by it.
     *
     * THIS IS THE CASE THAT CANNOT GO VACUOUS. Both sites this suite runs against sit at
     * UTC, so a date sent without an offset stores identical strings in post_date and
     * post_date_gmt and an assertion that they "agree" would hold with the conversion
     * deleted. +05:30 is not a whole number of hours either, so an implementation that
     * dropped the minutes would still be caught.
     *
     * @group sprint-11
     */
    public function testAnOffsetDateFixesTheInstantAndBothColumnsFollow(): void
    {
        $result = $this->create(self::$editorToken, Fixtures::name('fw-offset'), [
            'status' => 'publish',
            'date'   => '2022-04-05T14:30:00+05:30',
        ]);

        self::assertFalse($result->isError, $result->text);

        $id  = (int) $result->data()['id'];
        $gmt = Fixtures::postField($id, 'post_date_gmt');

        self::assertSame(
            '2022-04-05 09:00:00',
            $gmt,
            '14:30 at +05:30 is 09:00 UTC. post_date_gmt says otherwise, so the offset was'
            . ' either ignored or applied the wrong way round.'
        );
        self::assertSame(
            Fixtures::dateFromGmt('2022-04-05 09:00:00'),
            Fixtures::postField($id, 'post_date'),
            'post_date is not the local spelling of 09:00 UTC on this site (timezone '
            . self::$timezone . '), so the two columns describe different instants.'
        );
    }

    /**
     * status `publish` with a PAST date publishes, and keeps that date - back-dating,
     * which is what an import or a migration does.
     *
     * @group sprint-11
     */
    public function testPublishingWithAPastDateKeepsThatDate(): void
    {
        $result = $this->create(self::$editorToken, Fixtures::name('fw-backdated'), [
            'status' => 'publish',
            'date'   => '2019-03-04T05:06:07',
        ]);

        self::assertFalse($result->isError, $result->text);

        $data = $result->data();

        self::assertSame('publish', $data['status'], 'A past-dated publish did not publish.');
        self::assertSame('2019-03-04T05:06:07', $data['date'], 'The result reports a different date.');
        self::assertSame(
            '2019-03-04 05:06:07',
            Fixtures::postField((int) $data['id'], 'post_date'),
            'The stored post_date is not the date that was asked for.'
        );
    }

    /**
     * status `future` with a PAST date publishes NOW - core's own branch - and the result
     * says so rather than claiming a schedule that will never fire.
     *
     * @group sprint-11
     */
    public function testAFutureStatusWithAPastDatePublishesAndSaysSo(): void
    {
        $result = $this->create(self::$editorToken, Fixtures::name('fw-past-future'), [
            'status' => 'future',
            'date'   => '2018-01-02T03:04:05',
        ]);

        self::assertFalse($result->isError, $result->text);
        self::assertSame(
            'publish',
            $result->data()['status'],
            'status "future" with a date in the past is turned into "publish" by'
            . ' wp_insert_post. The tool must report what happened, not what was asked.'
        );
    }

    /**
     * A DRAFT keeps the date it is given on update. Without `edit_date`, WordPress
     * replaces a draft's post_date with the current time and zeroes its GMT column - so
     * this test is red on an implementation that merely passes post_date through.
     *
     * @group sprint-11
     */
    public function testADraftKeepsTheDateItIsGivenOnUpdate(): void
    {
        $result = $this->mcp(self::$editorToken)->callTool('update-post', [
            'id'   => self::$draftId,
            'date' => '2020-07-08T09:10:11',
        ]);

        self::assertFalse($result->isError, $result->text);
        self::assertSame(
            'draft',
            $result->data()['status'],
            'The draft was published by an update that only named a date.'
        );
        self::assertSame(
            '2020-07-08 09:10:11',
            Fixtures::postField(self::$draftId, 'post_date'),
            'A draft given a date came back carrying a different one. wp_update_post'
            . ' replaces a draft\'s post_date with the current time unless edit_date is'
            . ' passed with it, which is the whole reason the tool passes it.'
        );
    }

    /**
     * A malformed date is a wpmcp_bad_arg, and NOTHING is written - not the date, not the
     * title that was sent in the same call.
     *
     * @group sprint-11
     */
    public function testAMalformedDateIsRefusedAndNothingIsWritten(): void
    {
        $before = Fixtures::postField(self::$editorPostId, 'post_date');

        foreach (['2026-13-45', 'next tuesday', '@1700000000', '2026-02-30T10:00:00', ''] as $bad) {
            $result = $this->mcp(self::$editorToken)->callTool('update-post', [
                'id'    => self::$editorPostId,
                'title' => Fixtures::name('fw-should-not-land'),
                'date'  => $bad,
            ]);

            self::assertTrue(
                $result->isError,
                "update-post accepted the date '{$bad}'. strtotime() would have taken all"
                . ' five of these and meant something by four of them.'
            );
            self::assertStringContainsString('date must be an ISO 8601', $result->text);
        }

        self::assertSame(
            $before,
            Fixtures::postField(self::$editorPostId, 'post_date'),
            'A refused update moved the date anyway.'
        );
        self::assertSame(
            self::editorPostTitle(),
            Fixtures::postField(self::$editorPostId, 'post_title'),
            'A refused update wrote the title it was given in the same call. The shared'
            . ' field step has to refuse BEFORE wp_update_post runs.'
        );
    }

    /**
     * A Contributor cannot schedule, because scheduling is publishing.
     *
     * `future` is in wpmcp_publishing_statuses(), so the publish_posts gate that stops a
     * Contributor publishing stops them putting something on the site for next week too.
     *
     * @group sprint-11
     */
    public function testAContributorCannotSchedule(): void
    {
        $title  = Fixtures::name('fw-contrib-schedule');
        $result = $this->create(self::$contributorToken, $title, [
            'status' => 'future',
            'date'   => gmdate('Y-m-d\TH:i:s', time() + 86400),
        ]);

        self::assertTrue($result->isError, 'A Contributor scheduled a post.');
        self::assertStringContainsString('not allowed to publish post content', $result->text);
    }

    /**
     * `changed` names every field the call named, on create as well as on update.
     *
     * IT USED TO BE SEEDED FROM THE SHARED STEP ALONE on create, so a call that set a
     * title, a body, a status and a date reported `["date"]` - while README says `changed`
     * is what the call touched. The two tools now build it the same way and in the same
     * order, which is the whole point of their sharing a step.
     *
     * @group sprint-11
     */
    public function testChangedNamesEveryFieldTheCallNamed(): void
    {
        $created = $this->create(self::$editorToken, Fixtures::name('fw-changed'), [
            'status'         => 'draft',
            'excerpt'        => Fixtures::name('fw-changed-excerpt'),
            'date'           => '2021-05-06T07:08:09',
            'featured_image' => self::$editorImageId,
        ]);

        self::assertFalse($created->isError, $created->text);
        self::assertSame(
            ['title', 'content', 'status', 'excerpt', 'date', 'featured_image'],
            $created->data()['changed'],
            'create-post must report the fields it was given, title and content among'
            . ' them - a client reading `changed` has no other way to know what landed.'
        );

        $id = (int) $created->data()['id'];

        $updated = $this->mcp(self::$editorToken)->callTool('update-post', [
            'id'      => $id,
            'title'   => Fixtures::name('fw-changed-again'),
            'excerpt' => Fixtures::name('fw-changed-excerpt-2'),
        ]);

        self::assertFalse($updated->isError, $updated->text);
        self::assertSame(
            ['title', 'excerpt'],
            $updated->data()['changed'],
            'update-post reports only the fields it was given, and so must create-post.'
        );
    }

    /* ------------------------------------------------------------------
     * author
     * ---------------------------------------------------------------- */

    /**
     * An Editor reassigns a post's author; the result and get-post both report
     * {id, name} with the DISPLAY name.
     *
     * @group sprint-11
     */
    public function testAnEditorReassignsTheAuthor(): void
    {
        $result = $this->mcp(self::$editorToken)->callTool('update-post', [
            'id'     => self::$editorPostId,
            'author' => self::$authorId,
        ]);

        self::assertFalse($result->isError, $result->text);

        $data = $result->data();

        self::assertContains('author', $data['changed']);
        self::assertSame(
            ['id' => self::$authorId, 'name' => self::authorDisplayName()],
            $data['author'],
            'The result must carry the display name and the id, and nothing else about'
            . ' the user - never the login, which is half of a credential.'
        );
        self::assertSame(
            (string) self::$authorId,
            Fixtures::postField(self::$editorPostId, 'post_author'),
            'post_author did not move.'
        );

        $post = $this->mcp(self::$editorToken)->callTool('get-post', ['id' => self::$editorPostId]);

        self::assertFalse($post->isError, $post->text);
        self::assertSame(
            ['id' => self::$authorId, 'name' => self::authorDisplayName()],
            $post->data()['author'],
            'get-post disagrees with what update-post just reported.'
        );

        // Put it back, so the order of the tests in this class cannot matter.
        $this->mcp(self::$editorToken)->callTool('update-post', [
            'id'     => self::$editorPostId,
            'author' => self::$editorId,
        ]);
    }

    /**
     * An Author cannot set the author, even on their own post: the capability is
     * edit_others_posts, which is what wp-admin gates the Author box on.
     *
     * @group sprint-11
     */
    public function testAnAuthorCannotSetTheAuthor(): void
    {
        $result = $this->mcp(self::$authorToken)->callTool('update-post', [
            'id'     => self::$authorPostId,
            'author' => self::$otherAuthorId,
        ]);

        self::assertTrue($result->isError, 'An Author handed their post to somebody else.');
        self::assertStringContainsString('not allowed to set the author of post content', $result->text);
        self::assertSame(
            (string) self::$authorId,
            Fixtures::postField(self::$authorPostId, 'post_author'),
            'The refusal came after the write.'
        );
    }

    /**
     * A Subscriber is not an eligible author, and the refusal says only that.
     *
     * NOTHING ABOUT THE ACCOUNT. `author` takes an id OR a login, which is exactly the
     * shape that would be used to enumerate a site's users one guess at a time, so
     * "no such user" and "that user cannot write here" are one sentence.
     *
     * @group sprint-11
     */
    public function testASubscriberIsNotAnEligibleAuthor(): void
    {
        $before = Fixtures::postField(self::$editorPostId, 'post_author');
        $result = $this->mcp(self::$editorToken)->callTool('update-post', [
            'id'     => self::$editorPostId,
            'author' => self::$subscriberId,
        ]);

        self::assertTrue($result->isError, 'A Subscriber was made the author of a post.');
        self::assertStringContainsString('not an eligible author', $result->text);
        self::assertStringNotContainsString(
            self::subscriberLogin(),
            $result->text,
            'The refusal named the account. An id-or-login argument that confirms a login'
            . ' is a user enumerator.'
        );
        self::assertSame(
            $before,
            Fixtures::postField(self::$editorPostId, 'post_author'),
            'post_author moved despite the refusal.'
        );

        // A login that belongs to nobody gets the SAME sentence as the Subscriber.
        $absent = $this->mcp(self::$editorToken)->callTool('update-post', [
            'id'     => self::$editorPostId,
            'author' => Fixtures::name('fw-nobody'),
        ]);

        self::assertTrue($absent->isError);
        self::assertSame(
            $result->text,
            $absent->text,
            'A user who does not exist and a user who may not write here get different'
            . ' answers, so the argument tells a caller which logins are real.'
        );
    }

    /**
     * `author` that is neither an integer nor a non-empty string is a bad_arg, not a
     * silent cast to user 1.
     *
     * THE SCHEMA CANNOT SAY "INTEGER OR STRING" in the dialect SchemaValidator enforces,
     * so this argument declares no `type` at all and the validator lets a boolean or a
     * float through to the tool. `wpmcp_list_author_id(true)` then read `(string) true`
     * as `'1'` and resolved it to whoever has user id 1 - on most sites the person who
     * installed WordPress. Harmless in reach (the caller could have sent `1`) and wrong
     * in kind: an argument whose type nothing checks has to check its own.
     *
     * @group sprint-11
     */
    public function testAnAuthorThatIsNeitherAnIdNorALoginIsRefused(): void
    {
        $before = Fixtures::postField(self::$editorPostId, 'post_author');

        foreach ([true, false, 1.5, ''] as $bad) {
            $result = $this->mcp(self::$editorToken)->callTool('update-post', [
                'id'     => self::$editorPostId,
                'author' => $bad,
            ]);

            self::assertTrue(
                $result->isError,
                'update-post accepted ' . var_export($bad, true) . ' as an author.'
            );
            self::assertStringContainsString(
                'author must be a user id (integer) or a user login',
                $result->text
            );
        }

        self::assertSame(
            $before,
            Fixtures::postField(self::$editorPostId, 'post_author'),
            'post_author moved on a refused call.'
        );
    }

    /* ------------------------------------------------------------------
     * featured_image
     * ---------------------------------------------------------------- */

    /**
     * An Editor sets a featured image on their own post, and it round-trips through
     * get-post as {id, url}; 0 takes it away again.
     *
     * @group sprint-11
     */
    public function testAFeaturedImageIsSetAndRemoved(): void
    {
        $set = $this->mcp(self::$editorToken)->callTool('update-post', [
            'id'             => self::$editorPostId,
            'featured_image' => self::$editorImageId,
        ]);

        self::assertFalse($set->isError, $set->text);

        $data = $set->data();

        self::assertContains('featured_image', $data['changed']);
        self::assertSame(self::$editorImageId, $data['featured_image']['id']);
        self::assertStringContainsString(
            self::editorImageFile(),
            (string) $data['featured_image']['url'],
            'The reported URL does not point at the attached file.'
        );
        self::assertSame(
            self::$editorImageId,
            Fixtures::thumbnailId(self::$editorPostId),
            'The thumbnail is not on the post.'
        );

        $post = $this->mcp(self::$editorToken)->callTool('get-post', ['id' => self::$editorPostId]);

        self::assertFalse($post->isError, $post->text);
        self::assertSame(
            $data['featured_image'],
            $post->data()['featured_image'],
            'get-post reports a different featured image from the one update-post set.'
        );

        $removed = $this->mcp(self::$editorToken)->callTool('update-post', [
            'id'             => self::$editorPostId,
            'featured_image' => 0,
        ]);

        self::assertFalse($removed->isError, $removed->text);
        self::assertNull($removed->data()['featured_image'], '0 did not remove the image.');
        self::assertSame(0, Fixtures::thumbnailId(self::$editorPostId));

        $after = $this->mcp(self::$editorToken)->callTool('get-post', ['id' => self::$editorPostId]);

        self::assertNull($after->data()['featured_image'], 'get-post still reports an image.');
    }

    /**
     * An Author may use their OWN upload.
     *
     * The other half of the gate. Without it, "an Author is refused" could just mean the
     * argument is broken for Authors.
     *
     * @group sprint-11
     */
    public function testAnAuthorMayUseTheirOwnUpload(): void
    {
        $result = $this->mcp(self::$authorToken)->callTool('update-post', [
            'id'             => self::$authorPostId,
            'featured_image' => self::$authorImageId,
        ]);

        self::assertFalse($result->isError, $result->text);
        self::assertSame(self::$authorImageId, $result->data()['featured_image']['id']);
        self::assertSame(self::$authorImageId, Fixtures::thumbnailId(self::$authorPostId));

        $this->mcp(self::$authorToken)->callTool('update-post', [
            'id'             => self::$authorPostId,
            'featured_image' => 0,
        ]);
    }

    /**
     * An Author may NOT use another user's upload.
     *
     * MEASURED ON WP 7.1: an attachment's edit_post maps through its own author and its
     * parent, so this is `edit_others_posts` in the end - and an Author does not hold it.
     * Without this check the argument is a way to attach any file in the site's media
     * library, including a private client's, to a post the caller controls.
     *
     * @group sprint-11
     */
    public function testAnAuthorCannotUseAnotherUsersUpload(): void
    {
        $result = $this->mcp(self::$authorToken)->callTool('update-post', [
            'id'             => self::$authorPostId,
            'featured_image' => self::$editorImageId,
        ]);

        self::assertTrue($result->isError, 'An Author attached somebody else\'s upload.');
        self::assertStringContainsString(
            'not allowed to use attachment ' . self::$editorImageId,
            $result->text
        );
        self::assertSame(
            0,
            Fixtures::thumbnailId(self::$authorPostId),
            'The refusal came after the write.'
        );
    }

    /**
     * An attachment that is not an image is refused, and with the same sentence as an id
     * that is not an attachment at all.
     *
     * @group sprint-11
     */
    public function testANonImageAttachmentIsRefused(): void
    {
        $document = $this->mcp(self::$editorToken)->callTool('update-post', [
            'id'             => self::$editorPostId,
            'featured_image' => self::$documentId,
        ]);

        self::assertTrue($document->isError, 'A PDF became a featured image.');
        self::assertStringContainsString('id of an image attachment', $document->text);
        self::assertSame(0, Fixtures::thumbnailId(self::$editorPostId));

        // A post id, which is an id of the wrong KIND, gets the same answer - so the
        // argument cannot be used to sort ids into attachments and non-attachments.
        $notAnAttachment = $this->mcp(self::$editorToken)->callTool('update-post', [
            'id'             => self::$editorPostId,
            'featured_image' => self::$authorPostId,
        ]);

        self::assertTrue($notAnAttachment->isError);
        self::assertSame($document->text, $notAnAttachment->text);
    }

    /**
     * A create refused for its featured image leaves NO post behind.
     *
     * This is why wpmcp_post_fields() gates before wp_insert_post() rather than after:
     * the capability question about an attachment does not need the post to exist, so a
     * caller who is refused must not end up with an untitled orphan on the site.
     *
     * @group sprint-11
     */
    public function testACreateRefusedForItsImageWritesNothing(): void
    {
        $title  = Fixtures::name('fw-refused-create');
        $result = $this->create(self::$authorToken, $title, [
            'featured_image' => self::$editorImageId,
        ]);

        self::assertTrue($result->isError, 'An Author created a post with another user\'s image.');
        self::assertStringContainsString('not allowed to use attachment', $result->text);

        // Read from the posts table, not through a listing: a listing answers "nothing
        // you may see", and the question here is "nothing at all, for anybody".
        self::assertNotContains(
            $title,
            array_values(Fixtures::leftoverPosts()),
            'The refused create left a post on the site. The featured-image gate has to'
            . ' run before wp_insert_post, not after it.'
        );
    }

    /* ------------------------------------------------------------------
     * helpers
     * ---------------------------------------------------------------- */

    /**
     * create-post, remembering the id so teardown can take it away even when an
     * assertion further down the test fails.
     */
    private function create(string $token, string $title, array $arguments): ToolResult
    {
        $result = $this->mcp($token)->callTool(
            'create-post',
            array_merge(['title' => $title, 'content' => Fixtures::name('fw-body')], $arguments)
        );

        if (!$result->isError) {
            $data = json_decode($result->text, true);

            if (is_array($data) && isset($data['id'])) { self::$created[] = (int) $data['id']; }
        }

        return $result;
    }
}
