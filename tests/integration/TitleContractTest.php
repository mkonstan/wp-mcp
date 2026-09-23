<?php
/**
 * THE TITLE CONTRACT (1.1.1, decision D5): hand core the title as typed and let
 * `title_save_pre` decide - which means the SAME wire value stores different bytes depending
 * on what the token's user may do, exactly as it does in wp-admin.
 *
 * WHAT WAS WRONG. `create-post` and `update-post` ran `wp_strip_all_tags()` on the title, so a
 * title typed `x<y z` was stored as `x`. Neither wp-admin nor WP_REST_Posts_Controller does
 * that: core puts only `trim` on `title_save_pre` (default-filters.php:328) and adds
 * `wp_filter_kses` ONLY for a user without `unfiltered_html` (kses.php:2548).
 *
 * BOTH CAPABILITY PATHS, AND THAT IS THE POINT OF THE CLASS. An administrator has
 * `unfiltered_html`; an author does not. A fix verified against one role would miss the other,
 * and this is the file where the two are asserted side by side against the DATABASE - read in
 * another process, because the write tool re-reads the row it wrote and therefore agrees with
 * itself about a value that was never what the caller sent (KB 0.27).
 *
 * THE TWO STRINGS ARE THE MEASURED ONES, not decoration:
 *
 *   x<y z                 the stray `<` the old rule destroyed. kses encodes it to `x&lt;y z`
 *                         for a user without unfiltered_html, and that is a FIXED POINT - a
 *                         second and third pass change nothing, which is why wp-admin never
 *                         compounds an encoding.
 *   Tom's "quoted" A\B    the slashing trap. kses is `addslashes(wp_kses(stripslashes($data)))`
 *                         and `wp_insert_post()` unslashes at post.php:4981, so an UNSLASHED
 *                         title would come out `Tom\'s \"quoted\" AB` - the backslash eaten,
 *                         the quotes mangled. Both write tools slash the whole postarr once at
 *                         the boundary, so the pair cancels and this survives byte for byte
 *                         for BOTH roles. It is the regression test for the fix that is
 *                         already in place, and it has to name these exact characters.
 *
 * @group sprint-14d
 */

declare(strict_types=1);

namespace WpMcp\Tests\Integration;

use WpMcp\Tests\Support\Fixtures;
use WpMcp\Tests\Support\FixtureIntegrationTestCase;

final class TitleContractTest extends FixtureIntegrationTestCase
{
    /** The stray `<`. Stored as typed with unfiltered_html, kses-encoded without it. */
    private const ANGLE = 'x<y z';

    /** Quotes, an apostrophe and a backslash. Stored byte for byte either way. */
    private const SLASHED = 'Tom\'s "quoted" A\\B';

    /** A bare ampersand: exact for an administrator, `&amp;` for an author. */
    private const AMPERSAND = 'Arts & Crafts';

    private static int $adminId = 0;
    private static int $authorId = 0;
    private static string $adminToken = '';
    private static string $authorToken = '';

    private static function label(): string { return Fixtures::name('title-contract'); }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::requireSite();

        self::buildFixtures(self::build(...), self::destroy(...));
    }

    private static function build(): void
    {
        Fixtures::purge();

        self::$adminId  = Fixtures::createUser(Fixtures::name('title-admin'), 'administrator');
        self::$authorId = Fixtures::createUser(Fixtures::name('title-author'), 'author');

        self::$adminToken  = Fixtures::mintToken('admin', self::label(), self::$adminId);
        self::$authorToken = Fixtures::mintToken('admin', self::label(), self::$authorId);
    }

    public static function tearDownAfterClass(): void
    {
        self::destroy();

        parent::tearDownAfterClass();
    }

    private static function destroy(): void
    {
        Fixtures::deleteTokensLabelled(self::label());
        Fixtures::deleteUser(self::$adminId);
        Fixtures::deleteUser(self::$authorId);
        Fixtures::purge();
    }

    /**
     * The control: the two tokens really do differ in `unfiltered_html`, so the two halves
     * below are two capability paths and not the same one twice.
     *
     * @group sprint-14d
     */
    public function testTheTwoRolesDifferInUnfilteredHtml(): void
    {
        self::assertTrue(
            Fixtures::userCan(self::$adminId, 'unfiltered_html'),
            'The administrator fixture does not hold unfiltered_html, so the "stored as typed"'
            . ' half of this class is asserting nothing. On multisite only a super admin holds'
            . ' it, and DISALLOW_UNFILTERED_HTML takes it from everybody.'
        );
        self::assertFalse(
            Fixtures::userCan(self::$authorId, 'unfiltered_html'),
            'The author fixture holds unfiltered_html, so the kses half of this class is'
            . ' asserting nothing.'
        );
    }

    /**
     * WITH unfiltered_html: every one of the three strings is stored byte for byte, and
     * `x<y z` is the one that used to be stored as `x`.
     *
     * @group sprint-14d
     */
    public function testAnAdministratorsTitleIsStoredExactlyAsTyped(): void
    {
        foreach ([self::ANGLE, self::SLASHED, self::AMPERSAND] as $title) {
            $id = $this->createWith(self::$adminToken, $title);

            self::assertSame(
                $title,
                Fixtures::postField($id, 'post_title'),
                'The stored post_title is not what was sent. A caller with unfiltered_html gets'
                . ' `trim` and nothing else on title_save_pre, so this is the one role for which'
                . ' the round trip is byte-exact - and `' . self::ANGLE . '` is the case the old'
                . ' wp_strip_all_tags() rule stored as `x`.'
            );

            Fixtures::deletePost($id);
        }
    }

    /**
     * WITHOUT unfiltered_html: core's kses runs, and what it does is exactly what it does in
     * wp-admin - the markup is ENCODED, never stripped, and the backslash and the quotes are
     * untouched.
     *
     * THE THREE EXPECTATIONS ARE DIFFERENT ON PURPOSE. `x<y z` is encoded, `Arts & Crafts` is
     * encoded, and `Tom's "quoted" A\B` is not touched at all - kses normalises entities and
     * refuses tags; it has no opinion about a quote or a backslash, PROVIDED the data reaching
     * it was slashed. If the slashing ever comes off the write path, the third assertion is the
     * one that fails, with `Tom\'s \"quoted\" AB`.
     *
     * @group sprint-14d
     */
    public function testAnAuthorsTitleIsShapedByCoresKsesAndNothingElse(): void
    {
        $expected = [
            self::ANGLE     => 'x&lt;y z',
            self::SLASHED   => self::SLASHED,
            self::AMPERSAND => 'Arts &amp; Crafts',
        ];

        foreach ($expected as $sent => $stored) {
            $id = $this->createWith(self::$authorToken, (string) $sent);

            self::assertSame(
                $stored,
                Fixtures::postField($id, 'post_title'),
                'A title sent by a user without unfiltered_html is not stored the way core'
                . ' stores it. Sent: ' . $sent
            );

            Fixtures::deletePost($id);
        }
    }

    /**
     * READ IT, WRITE IT BACK, AND THE BYTES DO NOT MOVE - for both roles.
     *
     * THIS IS THE ASSERTION THAT MATTERS TO A CLIENT, and a read-only one never catches it: an
     * agent that edits one field of a post sends the whole post back, so what it READ has to be
     * writable. It holds here for two different reasons at once, and both are the contract: for
     * an administrator the stored bytes and the typed bytes are the same, and for an author the
     * value it read back is `x&lt;y z`, which is EQUAL to what is stored - so the round trip
     * writes nothing at all, `changed` is empty, and kses never runs a second time.
     *
     * @group sprint-14d
     */
    public function testReadingATitleAndWritingItBackChangesNothing(): void
    {
        foreach (['admin' => self::$adminToken, 'author' => self::$authorToken] as $who => $token) {
            foreach ([self::ANGLE, self::SLASHED, self::AMPERSAND] as $title) {
                $id     = $this->createWith($token, $title);
                $stored = Fixtures::postField($id, 'post_title');

                $read = $this->mcp($token)->callTool('get-post', ['id' => $id]);
                self::assertFalse($read->isError, $read->text);

                $wasRead = (string) $read->data()['title'];

                self::assertSame(
                    $stored,
                    $wasRead,
                    'get-post does not return the stored column for ' . $who . ': ' . $title
                );

                $back = $this->mcp($token)->callTool('update-post', [
                    'id'    => $id,
                    'title' => $wasRead,
                ]);
                self::assertFalse($back->isError, $back->text);

                self::assertSame(
                    $stored,
                    Fixtures::postField($id, 'post_title'),
                    'Writing back the title that was just read changed the stored bytes for '
                    . $who . '. Sent and read: ' . $wasRead
                );
                self::assertNotContains(
                    'title',
                    (array) ($back->data()['changed'] ?? []),
                    'An unchanged title was reported as changed for ' . $who . ', which means it'
                    . ' was written - and a write is where the shaping happens. Sent: ' . $wasRead
                );

                Fixtures::deletePost($id);
            }
        }
    }

    /** One post with $title, created through the tool surface as $token's user. */
    private function createWith(string $token, string $title): int
    {
        $result = $this->mcp($token)->callTool('create-post', [
            'title'  => $title,
            'status' => 'draft',
        ]);

        self::assertFalse($result->isError, 'create-post refused: ' . $result->text);

        $id = (int) ($result->data()['id'] ?? 0);

        self::assertGreaterThan(0, $id, 'create-post returned no id: ' . $result->text);

        return $id;
    }
}
