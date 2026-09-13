<?php
/**
 * The admin table, rendered by the real page, on a real site.
 *
 * WHY THIS IS WORTH A TEST. Everything else this sprint added is invisible: dormant and
 * dead are the same 401 on the wire, and the reason lives in a log line. The Settings
 * page is therefore the ONLY place a human can find out which of the two happened - and
 * the only place Renew exists. A Status column that says the wrong word, or a Renew
 * button offered on a row where it cannot work, is the whole feature failing silently.
 *
 * RENDERED THROUGH wp-cli, NOT OVER HTTP, and that is a limitation rather than a
 * preference: this harness is a Guzzle client with no WordPress session, so it cannot
 * log in to wp-admin, and a page that requires manage_options cannot be fetched. What it
 * CAN do is call wpmcp_render_admin() inside a real WordPress, as user 1, on the real
 * database - which exercises the same function, the same rows and the same markup. What
 * it does not exercise is the HTTP layer around it: the capability check on a real
 * request, and the nonce round trip of an actual button press. Those two are
 * uncovered, and are stated here rather than assumed.
 *
 * @group sprint-7
 */

declare(strict_types=1);

namespace WpMcp\Tests\Integration;

use WpMcp\Tests\Support\Fixtures;
use WpMcp\Tests\Support\FixtureIntegrationTestCase;
use WpMcp\Tests\Support\WpCli;

final class AdminTokenTableTest extends FixtureIntegrationTestCase
{
    private static function activeLabel(): string { return Fixtures::name('table-active'); }
    private static function dormantLabel(): string { return Fixtures::name('table-dormant'); }
    private static function deadLabel(): string { return Fixtures::name('table-dead'); }
    private static function orphanLabel(): string { return Fixtures::name('table-orphan'); }
    private static function login(): string { return Fixtures::name('table-author'); }
    private static function doomedLogin(): string { return Fixtures::name('table-doomed-author'); }

    private static int $userId = 0;
    private static int $doomedUserId = 0;
    private static string $html = '';

    private static function labels(): array
    {
        return [self::activeLabel(), self::dormantLabel(), self::deadLabel(), self::orphanLabel()];
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

        self::$userId       = Fixtures::createUser(self::login(), 'administrator');
        self::$doomedUserId = Fixtures::createUser(self::doomedLogin(), 'administrator');

        foreach ([self::activeLabel(), self::dormantLabel(), self::deadLabel()] as $label) {
            Fixtures::mintToken('read', $label, self::$userId);
        }

        // A live token whose OWNER is about to stop existing. Its timers will read
        // `active` indefinitely; the endpoint refuses it on every request.
        Fixtures::mintToken('read', self::orphanLabel(), self::$doomedUserId);

        Fixtures::makeTokensDormantLabelled(self::dormantLabel());
        Fixtures::makeTokensDeadLabelled(self::deadLabel());

        Fixtures::deleteUser(self::$doomedUserId);
        self::$doomedUserId = 0;

        self::$html = self::render();
    }

    public static function tearDownAfterClass(): void
    {
        self::destroy();

        parent::tearDownAfterClass();
    }

    private static function destroy(): void
    {
        Fixtures::deleteUser(self::$userId);
        Fixtures::deleteUser(self::$doomedUserId);

        foreach (self::labels() as $label) {
            Fixtures::deleteTokensLabelled($label);
        }

        Fixtures::purge();
    }

    /**
     * The control: the page rendered at all, and the three fixture rows are on it.
     * Without this, every "contains" assertion below would also pass on a blank string.
     *
     * @group sprint-7
     */
    public function testThePageRendersWithTheThreeFixtureRows(): void
    {
        self::assertStringContainsString('Active &amp; recent tokens', self::$html);

        foreach (self::labels() as $label) {
            self::assertStringContainsString(
                $label,
                self::$html,
                "The fixture row {$label} is not on the page."
            );
        }
    }

    /**
     * Each row says which of the three states it is in.
     *
     * @group sprint-7
     */
    public function testEachRowRendersItsOwnState(): void
    {
        $expected = [
            self::activeLabel()  => 'active',
            self::dormantLabel() => 'dormant',
            self::deadLabel()    => 'dead',
            self::orphanLabel()  => 'owner missing',
        ];

        foreach ($expected as $label => $state) {
            $row = self::rowFor($label);

            self::assertStringContainsString(
                '<td>' . $state . '</td>',
                $row,
                "The row for {$label} does not report the state '{$state}'. Its cells: {$row}"
            );
        }
    }

    /**
     * Renew is offered where it can work and withheld where it cannot.
     *
     * BOTH HALVES. Offering Renew on a dead row would be offering an action whose only
     * possible answer is an error message; withholding it from a dormant row would hide
     * the one thing that fixes the situation the admin came to the page about.
     *
     * @group sprint-7
     */
    public function testRenewIsOfferedOnActiveAndDormantRowsAndNotOnDeadOnes(): void
    {
        foreach ([self::activeLabel(), self::dormantLabel()] as $label) {
            self::assertStringContainsString(
                'value="renew"',
                self::rowFor($label),
                "The row for {$label} has no Renew button, so a dormant token can only be"
                . ' replaced - which for a hosted connector means deleting and re-adding it.'
            );
        }

        self::assertStringNotContainsString(
            'value="renew"',
            self::rowFor(self::deadLabel()),
            'A dead row offers Renew. wpmcp_renew() refuses one, so the button can only'
            . ' ever produce an error notice.'
        );

        self::assertStringNotContainsString(
            'value="renew"',
            self::rowFor(self::orphanLabel()),
            'A row whose owner was deleted offers Renew. Its timers say active and every'
            . ' request is refused with reason=user_missing, so pressing it used to post'
            . ' a green "the client needs no edit" notice about a token that does not'
            . ' work.'
        );
    }

    /**
     * And `wpmcp_renew()` refuses that row, rather than the button merely being hidden.
     * The button is a courtesy; the function is the guard.
     *
     * @group sprint-7
     */
    public function testRenewRefusesARowWhoseOwnerIsGone(): void
    {
        $id = Fixtures::tokenIdLabelled(self::orphanLabel());

        self::assertGreaterThan(0, $id, 'The orphaned fixture row is gone.');

        self::assertSame(
            'ERROR: user_missing',
            WpCli::evaluate(sprintf(
                '$r = wpmcp_renew(%d); echo is_wp_error($r) ? "ERROR: " . $r->get_error_code() : $r;',
                $id
            ), 1)
        );
    }

    /**
     * Every row keeps its Revoke button - the state machine did not quietly take the
     * one action that always applies.
     *
     * @group sprint-7
     */
    public function testEveryRowStillOffersRevoke(): void
    {
        foreach (self::labels() as $label) {
            self::assertStringContainsString('value="revoke"', self::rowFor($label));
        }
    }

    /**
     * A dead row is dimmed, which is how the table has always marked a row that no
     * longer works.
     *
     * @group sprint-7
     */
    public function testTheDeadRowIsDimmed(): void
    {
        self::assertStringContainsString('opacity:.5', self::rowFor(self::deadLabel()));
        self::assertStringNotContainsString('opacity:.5', self::rowFor(self::activeLabel()));
        self::assertStringNotContainsString('opacity:.5', self::rowFor(self::dormantLabel()));
    }

    /** The `<tr>...</tr>` containing $label. */
    private static function rowFor(string $label): string
    {
        $at = strpos(self::$html, $label);

        self::assertNotFalse($at, "No row on the page contains {$label}.");

        $start = strrpos(substr(self::$html, 0, $at), '<tr');
        $end   = strpos(self::$html, '</tr>', $at);

        self::assertNotFalse($start, "Could not find the start of the row for {$label}.");
        self::assertNotFalse($end, "Could not find the end of the row for {$label}.");

        return substr(self::$html, $start, $end - $start);
    }

    /**
     * wpmcp_render_admin() as user 1, with the wp-admin template helpers loaded.
     *
     * `submit_button()` and friends live in wp-admin/includes/template.php, which a CLI
     * bootstrap does not load - so the require is not a workaround, it is the same thing
     * wp-admin itself does before rendering any settings page.
     */
    private static function render(): string
    {
        return WpCli::evaluate(
            'require_once ABSPATH . "wp-admin/includes/template.php";'
            . ' ob_start(); wpmcp_render_admin(); echo ob_get_clean();',
            1
        );
    }
}
