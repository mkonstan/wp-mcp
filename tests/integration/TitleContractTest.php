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
 * AND ONE CONFIGURATION, NOT A ROLE: `DISALLOW_UNFILTERED_HTML`. The constant takes
 * `unfiltered_html` from EVERYONE, administrators and super admins included
 * (`capabilities.php`, the `unfiltered_html` case), so `kses_init()` adds the title filter for
 * every user on the site. It is the one live setting that changes what this sprint's title work
 * does, and until round 2 nothing exercised it: both columns of the table above are capability
 * paths, and this constant collapses them into the right-hand one.
 *
 * @group sprint-14d
 */

declare(strict_types=1);

namespace WpMcp\Tests\Integration;

use WpMcp\Tests\Support\Fixtures;
use WpMcp\Tests\Support\FixtureIntegrationTestCase;
use WpMcp\Tests\Support\MuPlugin;

final class TitleContractTest extends FixtureIntegrationTestCase
{
    /** The mu-plugin that defines DISALLOW_UNFILTERED_HTML, for one request at a time. */
    private const CONSTANT_PLUGIN = 'disallow-unfiltered-html';

    /**
     * The request header that arms it.
     *
     * PER REQUEST, AND THAT IS THE WHOLE DESIGN. A constant defined for every request while this
     * run is armed would take `unfiltered_html` away from the administrator half of this class
     * too, and from every other class running against the site - so the fixture reads a header
     * and does nothing without it. wp-cli has no HTTP headers, so the fixture reads and the
     * assertions are unaffected by it.
     */
    private const CONSTANT_HEADER = 'X-Wpmcp-Test-Disallow-Unfiltered-Html';
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

        MuPlugin::drop(self::CONSTANT_PLUGIN, self::constantSource());

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
        MuPlugin::remove(self::CONSTANT_PLUGIN);
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

    /**
     * The control for the class's fourth column: the constant really is defined on a request
     * carrying the header, and really is NOT on one without it.
     *
     * Without this, the two assertions below could both pass on a site where the fixture never
     * loaded - the administrator would simply behave like an administrator, and "kses ran" would
     * be indistinguishable from "nothing ran".
     *
     * @group sprint-14d
     */
    public function testTheConstantIsDefinedOnlyForARequestThatAsksForIt(): void
    {
        self::assertSame(
            'yes',
            $this->probeConstant(true),
            'DISALLOW_UNFILTERED_HTML is not defined on a request carrying the header, so the'
            . ' fixture mu-plugin never loaded and the test below proves nothing.'
        );
        self::assertSame(
            'no',
            $this->probeConstant(false),
            'DISALLOW_UNFILTERED_HTML is defined on a request that did NOT ask for it, so the'
            . ' fixture is armed for the whole site - which would take unfiltered_html away from'
            . ' this class\'s administrator half and from every other class running here.'
        );
    }

    /**
     * WITH `DISALLOW_UNFILTERED_HTML`, an ADMINISTRATOR's title is shaped by kses - the constant
     * collapses the two capability columns into one.
     *
     * MEASURED BEFORE THE SWAP, with `wp_strip_all_tags()` reimplemented ahead of the same
     * slashed `wp_insert_post()` under this constant: `x<y z` stored `x`. So the red half is the
     * markup being destroyed, and the green half is it being ENCODED, which is what wp-admin does
     * on a site that sets this constant.
     *
     * THE OTHER TWO STRINGS MATTER AS MUCH. The apostrophe/quote/backslash value has to survive
     * this path too - it is the slashing trap, and the constant puts an administrator ON the
     * kses branch where the trap lives, so this is the first assertion that exercises the trap
     * for a user who would otherwise never meet it.
     *
     * @group sprint-14d
     */
    public function testWithDisallowUnfilteredHtmlEvenAnAdministratorGetsKses(): void
    {
        $expected = [
            self::ANGLE     => 'x&lt;y z',
            self::SLASHED   => self::SLASHED,
            self::AMPERSAND => 'Arts &amp; Crafts',
        ];

        foreach ($expected as $sent => $stored) {
            $id = $this->createWith(self::$adminToken, (string) $sent, true);

            self::assertSame(
                $stored,
                Fixtures::postField($id, 'post_title'),
                'With DISALLOW_UNFILTERED_HTML set, an administrator\'s title is not stored the'
                . ' way core stores it for a user without unfiltered_html. Sent: ' . $sent
            );

            Fixtures::deletePost($id);
        }
    }

    /**
     * Is DISALLOW_UNFILTERED_HTML defined on a request that does, or does not, ask for it?
     *
     * Read off the RESPONSE's own header, which the fixture sets on `rest_post_dispatch` for
     * every request it sees - so the answer is about that request and not about a later one.
     */
    private function probeConstant(bool $ask): string
    {
        $response = $this->mcp(self::$adminToken)->post(
            'ping',
            [],
            $ask ? [self::CONSTANT_HEADER => '1'] : []
        );

        self::assertTrue(
            $response->hasHeader(self::CONSTANT_HEADER),
            'The fixture mu-plugin reported nothing on this request, so it never loaded.'
        );

        return $response->getHeaderLine(self::CONSTANT_HEADER);
    }

    /**
     * One post with $title, created through the tool surface as $token's user - optionally on a
     * request that has DISALLOW_UNFILTERED_HTML defined.
     */
    private function createWith(string $token, string $title, bool $disallow = false): int
    {
        $result = $this->mcp($token)->callTool(
            'create-post',
            ['title' => $title, 'status' => 'draft'],
            $disallow ? [self::CONSTANT_HEADER => '1'] : []
        );

        self::assertFalse($result->isError, 'create-post refused: ' . $result->text);

        $id = (int) ($result->data()['id'] ?? 0);

        self::assertGreaterThan(0, $id, 'create-post returned no id: ' . $result->text);

        return $id;
    }

    /**
     * The fixture: `DISALLOW_UNFILTERED_HTML`, for one request, and a header saying so.
     *
     * `muplugins_loaded` at priority 0 is early enough - `kses_init()` is on `init` and on
     * `set_current_user`, and `map_meta_cap` reads the constant at call time - and it is where
     * MuPlugin::drop() puts every fixture body anyway.
     */
    private static function constantSource(): string
    {
        $header = 'HTTP_' . strtoupper(str_replace('-', '_', self::CONSTANT_HEADER));
        $out    = self::CONSTANT_HEADER;

        return <<<PHP
\$wpmcpDisallow = (isset(\$_SERVER['{$header}']) && \$_SERVER['{$header}'] === '1');

// SAID ON THE WAY OUT, so the control test can see which branch the request took without
// trusting the fixture's own existence.
add_filter('rest_post_dispatch', static function (\$response) use (\$wpmcpDisallow) {
    if (\$response instanceof WP_REST_Response) {
        \$response->header('{$out}', \$wpmcpDisallow ? 'yes' : 'no');
    }

    return \$response;
}, 9999, 1);

if (\$wpmcpDisallow && !defined('DISALLOW_UNFILTERED_HTML')) {
    define('DISALLOW_UNFILTERED_HTML', true);
}
PHP;
    }
}
