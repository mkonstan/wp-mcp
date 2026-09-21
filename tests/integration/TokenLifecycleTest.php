<?php
/**
 * The two timers, Renew, and the cron - on a real site, over real HTTP.
 *
 * WHAT ONLY THIS TIER CAN SHOW. The unit tier proves what wpmcp_validate() and
 * wpmcp_renew() decide when handed a row. It cannot prove the thing an operator
 * actually cares about: that a token whose window has closed is refused with the same
 * anonymous 401 as everything else, that its ROW SURVIVES that refusal, that pressing
 * Renew makes the very same 64-character string work again without the client being
 * touched, and that the hourly cleanup removes dead rows while leaving renewable ones
 * alone. Every one of those is a fact about a database and an HTTP response together.
 *
 * THE TOKEN NEVER CHANGES ACROSS A RENEW, which is the whole design. The same variable
 * is sent before and after; nothing re-mints, and the test would be meaningless if it
 * did.
 *
 * @group sprint-7
 */

declare(strict_types=1);

namespace WpMcp\Tests\Integration;

use Psr\Http\Message\ResponseInterface;
use WpMcp\Tests\Support\Fixtures;
use WpMcp\Tests\Support\FixtureIntegrationTestCase;
use WpMcp\Tests\Support\TestRecorder;
use WpMcp\Tests\Support\WpCli;

final class TokenLifecycleTest extends FixtureIntegrationTestCase
{
    /** The one 401 body every refusal shares; OneUnauthorizedTest pins it too. */
    private const THE_401 = '{"code":"wpmcp_unauthorized","message":"Unauthorized.","data":{"status":401}}';

    private static function dormantLabel(): string { return Fixtures::name('life-dormant'); }
    private static function deadLabel(): string { return Fixtures::name('life-dead'); }
    private static function purgeDeadLabel(): string { return Fixtures::name('life-purge-dead'); }
    private static function purgeDormantLabel(): string { return Fixtures::name('life-purge-dormant'); }
    private static function login(): string { return Fixtures::name('life-author'); }

    private static int $userId = 0;
    private static string $dormantToken = '';

    private static function labels(): array
    {
        return [
            self::dormantLabel(),
            self::deadLabel(),
            self::purgeDeadLabel(),
            self::purgeDormantLabel(),
        ];
    }

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

        self::$userId = Fixtures::createUser(self::login(), 'administrator');

        self::$dormantToken = Fixtures::mintToken('read', self::dormantLabel(), self::$userId);

        // Two more for the cron, which needs one of each on either side of the line.
        Fixtures::mintToken('read', self::purgeDeadLabel(), self::$userId);
        Fixtures::mintToken('read', self::purgeDormantLabel(), self::$userId);

        Fixtures::makeTokensDormantLabelled(self::dormantLabel());

        // The DEAD fixture is NOT built here. One of the tests below runs the hourly
        // flush, which deletes dead rows site-wide - including this class's - so a dead
        // token built once in setUp is present or absent depending on the order PHPUnit
        // happens to choose, and the failure reads as "not_found" rather than as a
        // missing fixture. The dead test builds its own, immediately before using it.
    }

    public static function tearDownAfterClass(): void
    {
        self::destroy();

        parent::tearDownAfterClass();
    }

    private static function destroy(): void
    {
        TestRecorder::uninstall();
        Fixtures::deleteUser(self::$userId);

        foreach (self::labels() as $label) {
            Fixtures::deleteTokensLabelled($label);
        }

        Fixtures::purge();
    }

    /**
     * A dormant token is refused with the standard 401 - AND its row is still there.
     *
     * The second half is the one that matters, and is the reason wpmcp_validate() no
     * longer deletes a refused row: without it there is nothing for Renew to act on, and
     * the admin table would be empty exactly when somebody came looking.
     *
     * @group sprint-7
     */
    public function testADormantTokenIsRefusedAndItsRowSurvives(): void
    {
        TestRecorder::reset();

        $response = $this->call(self::$dormantToken);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(
            self::THE_401,
            (string) $response->getBody(),
            'A dormant token gets an answer of its own, which tells a caller holding a'
            . ' stolen token that it is worth keeping until somebody renews it.'
        );

        self::assertSame(
            1,
            Fixtures::countTokensLabelled(self::dormantLabel()),
            'The dormant row was deleted by the refusal, so there is nothing left to renew.'
        );

        $events = TestRecorder::detailsOf(TestRecorder::AUTH . 'validate_fail');

        self::assertCount(1, $events, 'The refusal did not fire exactly one validate_fail.');
        self::assertSame(
            'dormant',
            $events[0]['reason'] ?? null,
            'The log does not distinguish dormant from expired, which is the only place'
            . ' that distinction exists - and it is what tells an admin whether to press'
            . ' Renew or to mint.'
        );
    }

    /**
     * Renew on that same row, then the SAME token works again.
     *
     * @depends testADormantTokenIsRefusedAndItsRowSurvives
     *
     * @group sprint-7
     */
    public function testRenewBringsTheSameTokenBackAndFiresTheEvent(): void
    {
        $id = Fixtures::tokenIdLabelled(self::dormantLabel());

        self::assertGreaterThan(0, $id, 'The dormant fixture row is gone.');

        $result = WpCli::evaluate(
            sprintf(
                '$r = wpmcp_renew(%d); echo is_wp_error($r) ? "ERROR: " . $r->get_error_code() : $r;',
                $id
            ),
            1
        );

        self::assertStringNotContainsString('ERROR', $result, "wpmcp_renew({$id}) refused.");

        $response = $this->call(self::$dormantToken);

        self::assertSame(
            200,
            $response->getStatusCode(),
            'The same token is still refused after Renew. Body: ' . (string) $response->getBody()
        );

        // The event is fired inside the wp-cli process that renewed, so it is observed
        // there rather than by the HTTP recorder - the same split AuthEventsTest uses
        // for mint and revoke.
        $json = WpCli::evaluate(
            sprintf(
                '$seen = array();'
                . ' add_action("wpmcp_auth_event", function ($t, $c) use (&$seen) {'
                . '  $seen[] = array("type" => $t, "context" => $c); }, 10, 2);'
                . ' wpmcp_renew(%d); echo wp_json_encode($seen);',
                $id
            ),
            1
        );

        $seen = json_decode($json, true);

        self::assertIsArray($seen, "The renew snippet did not return JSON: {$json}");
        self::assertCount(1, $seen, 'Renew did not fire exactly one auth event.');
        self::assertSame('renew', $seen[0]['type']);
        self::assertSame($id, (int) $seen[0]['context']['token_id']);
        self::assertSame(self::$userId, (int) $seen[0]['context']['user_id']);
        self::assertSame(
            1,
            (int) $seen[0]['context']['actor'],
            'The event does not name who pressed Renew. The actor and the token owner are'
            . ' different people whenever an admin manages somebody else\'s token.'
        );
        self::assertGreaterThan(0, (int) $seen[0]['context']['window']);
    }

    /**
     * A dead token: the same 401, `expired` in the log, and Renew refuses it.
     *
     * @group sprint-7
     */
    public function testADeadTokenIsRefusedAsExpiredAndCannotBeRenewed(): void
    {
        // Built here, not in setUp: see the note there. The hourly flush is site-wide.
        Fixtures::deleteTokensLabelled(self::deadLabel());
        $deadToken = Fixtures::mintToken('read', self::deadLabel(), self::$userId);
        Fixtures::makeTokensDeadLabelled(self::deadLabel());

        TestRecorder::reset();

        $response = $this->call($deadToken);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(self::THE_401, (string) $response->getBody());

        $events = TestRecorder::detailsOf(TestRecorder::AUTH . 'validate_fail');

        self::assertCount(1, $events);
        self::assertSame('expired', $events[0]['reason'] ?? null);

        $id = Fixtures::tokenIdLabelled(self::deadLabel());

        self::assertGreaterThan(0, $id, 'The dead fixture row is gone before the cron ran.');

        self::assertSame(
            'ERROR: dead',
            WpCli::evaluate(sprintf(
                '$r = wpmcp_renew(%d); echo is_wp_error($r) ? "ERROR: " . $r->get_error_code() : $r;',
                $id
            ), 1),
            'A token past its hard lifetime was renewed. The second timer would then mean'
            . ' nothing at all.'
        );
    }

    /**
     * The cron takes the dead row and leaves the dormant one.
     *
     * BOTH HALVES, because the failure that matters is the false positive: a flush that
     * also swept dormant rows would turn every renewable token into a re-mint, silently,
     * an hour after it went dormant.
     *
     * @group sprint-7
     */
    public function testTheHourlyFlushDeletesDeadRowsAndKeepsDormantOnes(): void
    {
        Fixtures::makeTokensDeadLabelled(self::purgeDeadLabel(), 31 * 86400);
        Fixtures::makeTokensDormantLabelled(self::purgeDormantLabel());

        self::assertSame(1, Fixtures::countTokensLabelled(self::purgeDeadLabel()));
        self::assertSame(1, Fixtures::countTokensLabelled(self::purgeDormantLabel()));

        WpCli::evaluate('wpmcp_flush_expired_cb(); echo "done";');

        self::assertSame(
            0,
            Fixtures::countTokensLabelled(self::purgeDeadLabel()),
            'The flush left a dead row behind.'
        );
        self::assertSame(
            1,
            Fixtures::countTokensLabelled(self::purgeDormantLabel()),
            'The flush deleted a DORMANT row. That row is exactly the one an admin is'
            . ' about to press Renew on, and deleting it turns a renewal into a re-mint'
            . ' plus an edit of every client holding the token.'
        );
    }

    /** POST tools/list with this token. Returns the raw response. */
    private function call(string $token): ResponseInterface
    {
        return $this->client()->post('wp-json/wpmcp/mcp', [
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $token,
            ],
            'body' => '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}',
        ]);
    }
}
