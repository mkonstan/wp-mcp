<?php
/**
 * `update-post` says two things that are not true, measured against what it does
 * (sprint 14c, G5).
 *
 * BOTH WERE FOUND BY A CLIENT READING THE TOOL'S OWN CONTRACT, on 2026-09-18, not by
 * this suite - the same way the texturized title was found in September. The tool did
 * the right thing in both cases and described it wrongly, which no behavioural test can
 * see and every agent reads.
 *
 *   1. "Only the fields you send change" is false for a draft nobody dated. WordPress
 *      re-dates a date-floating draft (post_date_gmt still 0000-00-00) to now on ANY
 *      update, so editing the title alone moves `date`. Measured on both Local sites.
 *      A dated draft and a published post are untouched.
 *
 *   2. "The current title, content and excerpt are saved as a revision first, for
 *      restore-revision" reads backwards. The revision an edit CREATES holds the NEW
 *      text; the pre-edit text is the one BELOW it. A reader who follows the sentence
 *      literally restores what they just wrote and thinks the undo did nothing.
 *
 * WHAT THIS ASSERTS. The behaviour, on a real site, through the tool over HTTP - and
 * the descriptions AS THE WIRE CARRIES THEM, because tools/list is what an agent reads
 * and a description is only true where it is served.
 *
 * @group sprint-14c
 */

declare(strict_types=1);

namespace WpMcp\Tests\Integration;

use RuntimeException;
use WpMcp\Tests\Support\Fixtures;
use WpMcp\Tests\Support\FixtureIntegrationTestCase;
use WpMcp\Tests\Support\WpCli;

final class UpdatePostContractTest extends FixtureIntegrationTestCase
{
    private const ZERO_DATE = '0000-00-00 00:00:00';

    private static function login(): string { return Fixtures::name('updpost-admin'); }
    private static function label(): string { return Fixtures::name('updpost-token'); }

    private static int $userId = 0;
    private static string $token = '';

    /** @var list<int> every post this class created, for teardown. */
    private static array $posts = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::requireSite();

        self::buildFixtures(self::build(...), self::destroy(...));
    }

    private static function build(): void
    {
        Fixtures::purge();

        self::$userId = Fixtures::createUser(self::login(), 'administrator');
        self::$token  = Fixtures::mintToken('admin', self::label(), self::$userId);
    }

    public static function tearDownAfterClass(): void
    {
        self::destroy();

        parent::tearDownAfterClass();
    }

    private static function destroy(): void
    {
        foreach (self::$posts as $id) { Fixtures::deletePost($id); }

        self::$posts = [];

        Fixtures::deleteTokensLabelled(self::label());
        Fixtures::deleteUser(self::$userId);
        Fixtures::purge();
    }

    /**
     * G5. A title-only update on a draft nobody dated MOVES `date`, and the result says
     * so instead of leaving the caller to find out.
     *
     * @group sprint-14c
     */
    public function testATitleOnlyUpdateOnAnUndatedDraftMovesTheDateAndNamesIt(): void
    {
        $id = $this->newPost([
            'post_title'   => Fixtures::name('updpost-floating'),
            'post_status'  => 'draft',
            'post_content' => 'body',
        ]);

        self::assertSame(
            self::ZERO_DATE,
            Fixtures::postField($id, 'post_date_gmt'),
            'The fixture draft was created with a date, so the case under test - a draft'
            . ' nobody dated - is not the one being measured.'
        );

        // THE DATE IS PUSHED INTO THE PAST FIRST, and post_date_gmt is left at the zero
        // date, which is what "nobody dated it" means to core (wp_update_post's
        // "drafts shouldn't be assigned a date" branch reads the GMT column). Without
        // this the fixture's own creation time and the update are in the same second
        // often enough to make "the date moved" flaky on a fast machine.
        WpCli::evaluate(sprintf(
            'global $wpdb; $wpdb->query($wpdb->prepare('
            . '"UPDATE $wpdb->posts SET post_date = %%s WHERE ID = %%d",'
            . ' "2020-01-01 00:00:00", %d)); clean_post_cache(%d); echo "ok";',
            $id,
            $id
        ));

        self::assertSame('2020-01-01 00:00:00', Fixtures::postField($id, 'post_date'));
        self::assertSame(self::ZERO_DATE, Fixtures::postField($id, 'post_date_gmt'));

        $before = Fixtures::postField($id, 'post_date');
        $data   = $this->update($id, ['title' => Fixtures::name('updpost-floating-2')]);

        $after = Fixtures::postField($id, 'post_date');

        self::assertNotSame(
            $before,
            $after,
            'A title-only update did NOT move the date of a date-floating draft. If core'
            . ' stopped doing this, the description sentence this test guards should go'
            . ' back to the simple form - deliberately, not by accident.'
        );

        self::assertContains('title', $data['changed'] ?? [], 'changed');
        self::assertContains(
            'date',
            $data['changed'] ?? [],
            'The date moved and `changed` did not name it, so a caller reading the result'
            . ' has no way to learn that the post it just edited is now dated today.'
            . ' changed: ' . implode(', ', (array) ($data['changed'] ?? []))
        );

        self::assertSame(
            $after,
            str_replace('T', ' ', (string) ($data['date'] ?? '')),
            'The result reports a `date` that is not the one now stored.'
        );
    }

    /**
     * G5, the other branch: a draft that HAS a date, and a published post, are not
     * re-dated - so the sentence must name the case rather than warn about everything.
     *
     * @group sprint-14c
     */
    public function testATitleOnlyUpdateLeavesADatedPostAlone(): void
    {
        $cases = [
            // post_date_gmt AND post_date, because the GMT column is what core reads to
            // decide whether anybody dated this draft. MEASURED: `wp post create
            // --post_date=...` alone leaves post_date_gmt at the zero date, so the draft
            // is still date-floating and this "dated" case would silently be the other
            // one - which is how a test can assert the opposite of what it claims.
            'a dated draft' => $this->newPost([
                'post_title'    => Fixtures::name('updpost-dated'),
                'post_status'   => 'draft',
                'post_date'     => '2026-01-02 03:04:05',
                'post_date_gmt' => Fixtures::gmtFromDate('2026-01-02 03:04:05'),
            ]),
            'a published post' => $this->newPost([
                'post_title'  => Fixtures::name('updpost-published'),
                'post_status' => 'publish',
            ]),
        ];

        foreach ($cases as $what => $id) {
            $before = Fixtures::postField($id, 'post_date');
            $data   = $this->update($id, ['title' => Fixtures::name('updpost-renamed-' . crc32($what))]);

            self::assertSame($before, Fixtures::postField($id, 'post_date'), "{$what} was re-dated");
            self::assertNotContains(
                'date',
                $data['changed'] ?? [],
                "changed named `date` on {$what}, where nothing moved it."
            );
        }
    }

    /**
     * G5. The revision an edit creates holds the NEW text; the pre-edit text is the one
     * below it, and that is the one restore-revision undoes to.
     *
     * THE CROSS-CHECK BETWEEN THE TWO DESCRIPTIONS IS A MEASUREMENT, not a reading:
     * restoring the newest revision is a no-op, restoring the one below it puts the
     * previous text back. If the two tools disagreed about which revision is the undo
     * copy, one of those two assertions would be red.
     *
     * @group sprint-14c
     */
    public function testTheRevisionAnEditCreatesHoldsTheNewTextAndTheOneBelowIsTheUndo(): void
    {
        $id = $this->newPost([
            'post_title'   => Fixtures::name('updpost-revisions'),
            'post_status'  => 'publish',
            'post_content' => 'text-one',
        ]);

        $this->update($id, ['content' => 'text-two']);

        $revisions = $this->mcp(self::$token)->callTool('list-revisions', ['id' => $id])->items();

        self::assertGreaterThanOrEqual(
            2,
            count($revisions),
            'An edit of a post with no revisions must leave two: the text as it was, and'
            . ' the text as it now is. Revisions found: ' . count($revisions)
        );

        $newest = (int) $revisions[0]['id'];
        $below  = (int) $revisions[1]['id'];

        self::assertSame(
            'text-two',
            $this->revisionContent($newest),
            'The NEWEST revision does not hold the text the edit wrote. That is the'
            . ' revision the old description told a reader to restore.'
        );
        self::assertSame(
            'text-one',
            $this->revisionContent($below),
            'The revision below the newest does not hold the pre-edit text, so there is'
            . ' nothing for a reader to undo to.'
        );

        // Restoring the newest is a no-op: it is what the post already says.
        $this->mcp(self::$token)->callTool('restore-revision', ['revision_id' => $newest]);
        self::assertSame('text-two', Fixtures::postField($id, 'post_content'));

        // Restoring the one below it is the undo the reader wanted.
        $this->mcp(self::$token)->callTool('restore-revision', ['revision_id' => $below]);
        self::assertSame(
            'text-one',
            Fixtures::postField($id, 'post_content'),
            'Restoring the revision BELOW the newest did not undo the edit, so the two'
            . ' descriptions cannot both be describing this behaviour.'
        );
    }

    /**
     * G5. The wire carries the corrected sentences, and carries neither of the two the
     * cold-client test found.
     *
     * ON THE WIRE, not in the source: tools/list is what an agent reads, and a
     * description corrected in tools.php but shadowed by a filter would still mislead.
     *
     * @group sprint-14c
     */
    public function testTheServedDescriptionsSayWhatWasMeasuredAndAgreeWithEachOther(): void
    {
        $update  = $this->servedDescription('update-post');
        $restore = $this->servedDescription('restore-revision');

        self::assertStringNotContainsString(
            'Only the fields you send change, and each REPLACES what was there.',
            $update,
            'The unqualified claim is back on the wire.'
        );
        self::assertStringNotContainsString(
            'saved as a revision first',
            $update,
            'The reversed revision sentence is back on the wire.'
        );

        self::assertStringContainsString('re-dates', $update, 'update-post no longer names the re-dating case.');
        self::assertStringContainsString('NEWEST revision', $update, 'update-post no longer says which revision is which.');
        self::assertStringContainsString('pre-edit', $update, 'update-post no longer names the undo copy.');
        self::assertStringContainsString('restore-revision', $update);

        self::assertStringContainsString(
            'undo copy',
            $restore,
            'restore-revision no longer names the undo copy, so the two tools describe'
            . ' the same revision in two vocabularies.'
        );

        foreach (['update-post' => $update, 'restore-revision' => $restore] as $name => $text) {
            self::assertLessThanOrEqual(
                1000,
                strlen($text),
                "{$name}'s description is over the 1,000-character client limit, which"
                . ' clients truncate silently.'
            );
        }
    }

    /* ----------------------------------------------------------------------- *
     * Helpers
     * ----------------------------------------------------------------------- */

    /** A fixture post this class will delete. */
    private function newPost(array $fields): int
    {
        $id = Fixtures::createPostWith(array_merge(['post_author' => self::$userId], $fields));

        self::$posts[] = $id;

        return $id;
    }

    /** update-post over HTTP, as an agent calls it. */
    private function update(int $id, array $arguments): array
    {
        $result = $this->mcp(self::$token)
            ->callTool('update-post', array_merge(['id' => $id], $arguments));

        if ($result->isError) {
            throw new RuntimeException('update-post failed: ' . $result->text);
        }

        return $result->data();
    }

    /** One revision's content, through get-revision. */
    private function revisionContent(int $revisionId): string
    {
        return (string) $this->mcp(self::$token)
            ->callTool('get-revision', ['revision_id' => $revisionId])->data()['content'];
    }

    /** One tool's description exactly as tools/list serves it. */
    private function servedDescription(string $name): string
    {
        $cursor = null;

        do {
            $params = $cursor === null ? [] : ['cursor' => $cursor];
            $body   = json_decode(
                (string) $this->mcp(self::$token)->post('tools/list', $params)->getBody(),
                true
            );

            foreach ($body['result']['tools'] ?? [] as $tool) {
                if (($tool['name'] ?? '') === $name) {
                    return (string) ($tool['description'] ?? '');
                }
            }

            $cursor = $body['result']['nextCursor'] ?? null;
        } while (is_string($cursor) && $cursor !== '');

        self::fail("tools/list does not serve {$name} to an admin-scope token.");
    }
}
