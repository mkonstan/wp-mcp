<?php
/**
 * The thirty-day window on a real site that reports itself `local`, and twelve hours
 * on the same site once the narrowing filter says otherwise (sprint 14b, G1-G3).
 *
 * THE SITE MUST BE LOCAL, AND THIS FAILS RATHER THAN SKIPS WHEN IT IS NOT. Both Local
 * sites define WP_ENVIRONMENT_TYPE 'local' in wp-config.php, and so does wp-env: its
 * start step runs `wp config set WP_ENVIRONMENT_TYPE "local"` for both of its instances
 * (measured in CI run 35180486900). A site that answers anything else cannot prove G1,
 * and a gate that skips is not a gate.
 *
 * THE OTHER BRANCH GOES THROUGH THE SEAM, not through a second site. Core caches
 * wp_get_environment_type() for the process, so each `wp eval` below that wants the
 * non-local answer attaches `wpmcp_local_environment` => false first. That filter can
 * only NARROW: wpmcp_is_local_environment() never asks it on a site that is not local.
 *
 * @group sprint-14b
 */

declare(strict_types=1);

namespace WpMcp\Tests\Integration;

use WpMcp\Tests\Support\Fixtures;
use WpMcp\Tests\Support\FixtureIntegrationTestCase;
use WpMcp\Tests\Support\WpCli;

final class LocalWindowSiteTest extends FixtureIntegrationTestCase
{
    private const DAY  = 86400;
    private const HOUR = 3600;

    /** Prepended to a snippet to take the non-local branch on a local site. */
    private const NOT_LOCAL = 'add_filter("wpmcp_local_environment", "__return_false");';

    /** The sentence the mint form shows on a local site, as plain text. */
    private const LOCAL_SENTENCE = 'This site reports environment type "local", so tokens may stay active up to 30 days.';

    private static function login(): string { return Fixtures::name('localwin-admin'); }
    private static function localLabel(): string { return Fixtures::name('localwin-local'); }
    private static function remoteLabel(): string { return Fixtures::name('localwin-notlocal'); }
    private static function useLabel(): string { return Fixtures::name('localwin-onuse'); }

    private static int $userId = 0;

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
    }

    public static function tearDownAfterClass(): void
    {
        self::destroy();

        parent::tearDownAfterClass();
    }

    private static function destroy(): void
    {
        Fixtures::deleteUser(self::$userId);
        Fixtures::deleteTokensLabelled(self::localLabel());
        Fixtures::deleteTokensLabelled(self::remoteLabel());
        Fixtures::deleteTokensLabelled(self::useLabel());
        Fixtures::purge();
    }

    /**
     * The precondition, asserted: this site answers exactly 'local'.
     *
     * @group sprint-14b
     */
    public function testTheSiteUnderTestReportsLocal(): void
    {
        self::assertSame(
            'local',
            WpCli::evaluate('echo wp_get_environment_type();'),
            'The site under test is not a local environment, so the sprint-14b gate cannot'
            . ' prove the thirty-day branch. Local sites and wp-env both set'
            . ' WP_ENVIRONMENT_TYPE to local; this one does not.'
        );
        self::assertSame((string) (30 * self::DAY), WpCli::evaluate('echo wpmcp_max_window();'));
        self::assertSame((string) (12 * self::HOUR), WpCli::evaluate(self::NOT_LOCAL . ' echo wpmcp_max_window();'));
    }

    /**
     * G1. Mint accepts thirty days, active_until lands thirty days out, and renew resets
     * it to now plus the stored window.
     *
     * @group sprint-14b
     */
    public function testALocalSiteMintsAndRenewsAThirtyDayWindow(): void
    {
        $minted = $this->mint(self::localLabel(), '');

        self::assertSame(30 * self::DAY, $minted['window_secs'], 'Mint on a local site did not keep a 30-day window.');
        self::assertEqualsWithDelta($minted['now'] + 30 * self::DAY, $minted['active_until'], 60);
        self::assertEqualsWithDelta($minted['now'] + 365 * self::DAY, $minted['expires_at'], 60);
        self::assertSame('active', $minted['state']);

        Fixtures::makeTokensDormantLabelled(self::localLabel());

        $renewed = $this->renew($minted['id'], '');

        self::assertEqualsWithDelta(
            $renewed['now'] + 30 * self::DAY,
            $renewed['returned'],
            60,
            'Renew on a local site did not reset the window to now + the stored 30 days.'
        );
        self::assertSame($renewed['returned'], $renewed['active_until'], 'Renew returned one time and stored another.');
        self::assertSame(30 * self::DAY, $renewed['window_secs'], 'Renew rewrote the stored window.');
    }

    /**
     * G2. Anywhere else the cap is twelve hours for mint AND for renew - including renew
     * of a row that was minted with thirty days, which is what a database copied from a
     * local site to a public one carries.
     *
     * @group sprint-14b
     */
    public function testANonLocalSiteKeepsTwelveHoursForMintAndRenew(): void
    {
        $minted = $this->mint(self::remoteLabel(), self::NOT_LOCAL);

        self::assertSame(12 * self::HOUR, $minted['window_secs'], 'Mint off a local site kept a window over 12 hours.');
        self::assertEqualsWithDelta($minted['now'] + 12 * self::HOUR, $minted['active_until'], 60);

        // A thirty-day row, minted on the local branch, then renewed on the other one.
        Fixtures::deleteTokensLabelled(self::remoteLabel());
        $wide = $this->mint(self::remoteLabel(), '');
        self::assertSame(30 * self::DAY, $wide['window_secs']);

        Fixtures::makeTokensDormantLabelled(self::remoteLabel());

        $renewed = $this->renew($wide['id'], self::NOT_LOCAL);

        self::assertEqualsWithDelta(
            $renewed['now'] + 12 * self::HOUR,
            $renewed['returned'],
            60,
            'Renew off a local site honoured a stored window over 12 hours.'
        );
    }

    /**
     * G1b / B1. The cap holds ON USE, not only at mint and renew: the very row minted
     * here with thirty days is active on this local site and DORMANT through the seam,
     * which is the site the same database would be served from after a copy. Renew there
     * brings it back for twelve hours and narrows the stored grant to match.
     *
     * @group sprint-14b
     */
    public function testAThirtyDayRowIsActiveHereAndDormantOnASiteThatIsNotLocal(): void
    {
        $minted = $this->mint(self::useLabel(), '');

        self::assertSame(30 * self::DAY, $minted['window_secs']);
        self::assertSame('active', $minted['state']);

        // Twenty days into its window: still ten days from its own active_until, and long
        // past the twelve hours any other site would have granted it. A row minted a
        // moment ago is inside BOTH windows, so it could not tell the two apart.
        WpCli::evaluate(sprintf(
            'global $wpdb; echo (int) $wpdb->query($wpdb->prepare("UPDATE " . wpmcp_table()'
            . ' . " SET active_until = %%s WHERE id = %%d", gmdate("Y-m-d H:i:s", time() + 10 * DAY_IN_SECONDS), %d));',
            $minted['id']
        ));

        self::assertSame(
            'dormant',
            $this->stateOf($minted['id'], self::NOT_LOCAL),
            'A row carrying a 30-day window still answers on a site that is not local, so'
            . ' a database copied from here keeps answering for the rest of that window.'
        );
        self::assertSame('active', $this->stateOf($minted['id'], ''), 'The local site stopped honouring its own window.');

        // The admin table tells an operator the same story, on either site.
        $here = $this->adminRowFor(self::useLabel(), '');
        self::assertStringContainsString('<td>active</td>', $here);
        self::assertStringContainsString('(30 d)', $here);
        self::assertStringNotContainsString('capped', $here);

        $there = $this->adminRowFor(self::useLabel(), self::NOT_LOCAL);
        self::assertStringContainsString('<td>dormant</td>', $there, 'The admin table calls a capped row active.');
        self::assertStringContainsString('capped from 30 d', $there, 'The table does not say the window was capped.');

        $renewed = $this->renew($minted['id'], self::NOT_LOCAL);

        self::assertEqualsWithDelta($renewed['now'] + 12 * self::HOUR, $renewed['returned'], 60);
        self::assertSame(12 * self::HOUR, $renewed['window_secs'], 'Renew clamped the window without storing the clamp.');
        self::assertSame(
            'active',
            $this->stateOf($minted['id'], self::NOT_LOCAL),
            'The row Renew just wrote is dormant, so Renew is a button that does nothing.'
        );

        $row = $this->adminRowFor(self::useLabel(), self::NOT_LOCAL);

        self::assertStringContainsString('<td>active</td>', $row);
        self::assertStringContainsString('(12 h)', $row, 'The window cell does not show the window this site honours.');
        self::assertStringNotContainsString('capped', $row, 'The renewed row is still reported as capped.');
    }

    /**
     * G3. The mint form states the thirty-day cap on a local site, and only there.
     *
     * @group sprint-14b
     */
    public function testTheMintFormStatesTheThirtyDayCapOnlyOnALocalSite(): void
    {
        $local = $this->renderForm('');

        self::assertStringContainsString(self::LOCAL_SENTENCE, $local, 'The local sentence is not on the mint form.');
        self::assertMatchesRegularExpression('/name="window_hours"[^>]*max="720"/', $local);

        $other = $this->renderForm(self::NOT_LOCAL);

        self::assertStringNotContainsString('environment type', $other, 'The local sentence shows off a local site.');
        self::assertStringNotContainsString('30 days', $other);
        self::assertMatchesRegularExpression('/name="window_hours"[^>]*max="12"/', $other);
        self::assertStringContainsString('Hard cap 12 h.', $other);
    }

    /**
     * @return array{id:int, window_secs:int, active_until:int, expires_at:int, state:string, now:int}
     */
    private function mint(string $label, string $prefix): array
    {
        $json = WpCli::evaluate(sprintf(
            '%s $r = wpmcp_mint("admin", %s, 30 * DAY_IN_SECONDS, 365 * DAY_IN_SECONDS, %d);'
            . ' if (is_wp_error($r)) { echo "MINT-ERROR: " . $r->get_error_message(); return; }'
            . ' global $wpdb; $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . wpmcp_table() . " WHERE id = %%d", $r["id"]));'
            . ' echo wp_json_encode(array("id" => (int) $row->id, "window_secs" => (int) $row->window_secs,'
            . ' "active_until" => strtotime($row->active_until . " UTC"), "expires_at" => strtotime($row->expires_at . " UTC"),'
            . ' "state" => wpmcp_token_state($row), "now" => time()));',
            $prefix,
            self::literal($label),
            self::$userId
        ), 1);

        $data = json_decode($json, true);

        self::assertIsArray($data, 'Mint did not answer with a row: ' . substr($json, 0, 200));

        return $data;
    }

    /**
     * @return array{returned:int, active_until:int, window_secs:int, now:int}
     */
    private function renew(int $id, string $prefix): array
    {
        $json = WpCli::evaluate(sprintf(
            '%s $now = time(); $r = wpmcp_renew(%d);'
            . ' if (is_wp_error($r)) { echo "RENEW-ERROR: " . $r->get_error_code(); return; }'
            . ' global $wpdb; $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . wpmcp_table() . " WHERE id = %%d", %d));'
            . ' echo wp_json_encode(array("returned" => strtotime($r . " UTC"), "active_until" => strtotime($row->active_until . " UTC"),'
            . ' "window_secs" => (int) $row->window_secs, "now" => $now));',
            $prefix,
            $id,
            $id
        ), 1);

        $data = json_decode($json, true);

        self::assertIsArray($data, 'Renew did not answer with a row: ' . substr($json, 0, 200));

        return $data;
    }

    /** wpmcp_token_state() for one row, with or without the narrowing filter. */
    private function stateOf(int $id, string $prefix): string
    {
        return WpCli::evaluate(sprintf(
            '%s global $wpdb; $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM " . wpmcp_table() . " WHERE id = %%d", %d));'
            . ' echo $row ? wpmcp_token_state($row) : "NO-ROW";',
            $prefix,
            $id
        ));
    }

    /** The admin table's `<tr>` for a labelled row, rendered as user 1. */
    private function adminRowFor(string $label, string $prefix): string
    {
        $html = WpCli::evaluate(
            $prefix . ' require_once ABSPATH . "wp-admin/includes/template.php";'
            . ' ob_start(); wpmcp_render_admin(); $h = ob_get_clean();'
            . ' $at = strpos($h, ' . self::literal($label) . ');'
            . ' if ($at === false) { echo "NO-ROW"; return; }'
            . ' $s = strrpos(substr($h, 0, $at), "<tr"); $e = strpos($h, "</tr>", $at);'
            . ' echo substr($h, $s, $e - $s);',
            1
        );

        self::assertStringNotContainsString('NO-ROW', $html, 'The fixture row is not on the admin page.');

        return html_entity_decode($html, ENT_QUOTES | ENT_HTML5);
    }

    /** The mint form's HTML as user 1, entity-decoded so the sentence reads as text. */
    private function renderForm(string $prefix): string
    {
        $html = WpCli::evaluate(
            $prefix . ' require_once ABSPATH . "wp-admin/includes/template.php";'
            . ' ob_start(); wpmcp_render_admin(); $h = ob_get_clean();'
            . ' $a = strpos($h, "<h2>Generate a token</h2>"); $b = strpos($h, "</form>", (int) $a);'
            . ' echo ($a === false || $b === false) ? "NO-FORM" : substr($h, $a, $b - $a);',
            1
        );

        self::assertStringStartsWith('<h2>Generate a token</h2>', $html, 'The mint form did not render.');

        return html_entity_decode($html, ENT_QUOTES | ENT_HTML5);
    }

    private static function literal(string $value): string
    {
        return "'" . addcslashes($value, "'\\") . "'";
    }
}
