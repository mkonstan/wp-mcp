<?php
/**
 * THE ABSENCE IS EXPLICABLE, ON THE SCREEN (D30, point 4).
 *
 * A module that cannot work registers nothing, so its tools do not exist. That is the bare-site
 * rule and it is deliberately silent - a distinct "it exists but is off" would tell an
 * unauthorised caller a fact about this site's configuration for free. The cost of the silence is
 * that "no ACF tools" and "wp-mcp is broken" look identical to the one person who can fix either,
 * which is the vacuous-silence failure this project keeps catching. So Settings > WP MCP prints,
 * per module, whether it is serving and what it is missing.
 *
 * WHAT THIS ASSERTS IS AGREEMENT, not wording. `wpmcp_module_status()` is the seam's own answer,
 * and the screen has to say the same thing: every module named, every missing symbol printed, and
 * "Not serving" against exactly the modules that did not register. A test that hardcoded the
 * sentences would go red on a rewording and stay green on the drift that matters.
 *
 * WHY IT CARRIES THE SPRINT GATE GROUP. It needs a SITE but it does not need ACF: on a site with
 * ACF the ACF row reads "Serving", on a site without it the row names the missing symbols, and
 * both are asserted from the same status. CI has a site and no ACF, so this runs there and takes
 * the branch that matters most - the one an administrator of a bare install sees.
 *
 * @group sprint-acf-read
 */

declare(strict_types=1);

namespace WpMcp\Tests\Integration;

use WpMcp\Tests\Support\FixtureIntegrationTestCase;
use WpMcp\Tests\Support\WpCli;

final class ModuleStatusScreenTest extends FixtureIntegrationTestCase
{
    private static string $html = '';

    /** @var array<string, array<string, mixed>> */
    private static array $status = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::requireSite();
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$html === '') {
            // wp-admin/includes/template.php is what submit_button() and friends live in, and a
            // CLI bootstrap does not load it - the require is the same thing wp-admin itself does
            // before rendering any settings page.
            self::$html = WpCli::evaluate(
                'require_once ABSPATH . "wp-admin/includes/template.php";'
                . ' ob_start(); wpmcp_render_admin(); echo ob_get_clean();',
                1
            );

            $status = json_decode(
                WpCli::evaluate('echo wp_json_encode(wpmcp_module_status());', 1),
                true
            );

            self::assertIsArray($status, 'wpmcp_module_status() did not answer with JSON.');

            self::$status = $status;
        }
    }

    /**
     * Every module the manifest names appears on the screen, and the screen agrees with the seam
     * about whether it is serving.
     *
     * @group sprint-acf-read
     */
    public function testTheScreenNamesEveryModuleAndAgreesAboutWhichAreServing(): void
    {
        self::assertNotSame([], self::$status, 'The site reports no modules at all.');
        self::assertStringContainsString(
            'Feature modules',
            self::$html,
            'The settings screen has no module section, so an administrator has nowhere to find out'
            . ' why a module is serving nothing.'
        );

        foreach (self::$status as $slug => $module) {
            $row = self::row((string) $slug);

            self::assertStringContainsString(
                $module['registered'] ? 'Serving' : 'Not serving',
                $row,
                "The screen disagrees with wpmcp_module_status() about whether {$slug} is serving."
            );

            if (!$module['registered']) {
                self::assertStringContainsString(
                    'Not serving',
                    $row,
                    "{$slug} did not register and the screen does not say so."
                );
            }
        }
    }

    /**
     * A module that is not serving PRINTS THE SYMBOLS IT IS MISSING - by symbol, not by plugin
     * name, because the declaration carries symbols and guessing which plugin owns one would be
     * this screen inventing a fact.
     *
     * ON A SITE WITH ACF THIS ASSERTS THE OTHER HALF: nothing is missing, so nothing is printed,
     * and the optional capabilities are reported instead. Both directions, one test, because
     * either alone is passable by a screen that prints a fixed sentence.
     *
     * @group sprint-acf-read
     */
    public function testAModuleThatIsNotServingPrintsTheSymbolsItNeeds(): void
    {
        $checked = 0;

        foreach (self::$status as $slug => $module) {
            $row = self::row((string) $slug);

            foreach ((array) $module['missing'] as $symbol) {
                ++$checked;

                self::assertStringContainsString(
                    htmlspecialchars((string) $symbol, ENT_QUOTES),
                    $row,
                    "{$slug} is missing {$symbol} and the screen does not name it, so the"
                    . ' administrator is told there are no tools and not why.'
                );
            }

            foreach ((array) $module['capabilities'] as $capability => $on) {
                if ($module['missing'] !== []) {
                    continue;
                }

                ++$checked;

                self::assertStringContainsString(
                    htmlspecialchars((string) $capability, ENT_QUOTES),
                    $row,
                    "{$slug} declares the optional capability {$capability} and the screen does not"
                    . ' report whether this site provides it.'
                );
            }

            self::assertStringNotContainsString(
                'That is a bug in wp-mcp',
                $row,
                "{$slug} has everything it declared and still did not register."
            );
        }

        self::assertGreaterThan(
            0,
            $checked,
            'No module on this site is missing anything and none declares an optional capability,'
            . ' so this test asserted nothing at all. The ACF module declares both, so either the'
            . ' manifest lost it or wpmcp_module_status() stopped reporting.'
        );
    }

    /** One module's row of the Feature modules table. */
    private static function row(string $slug): string
    {
        $needle = '<code>' . $slug . '</code>';
        $start  = strpos(self::$html, $needle);

        self::assertNotFalse($start, "The screen has no row for the module '{$slug}'.");

        $rowStart = strrpos(substr(self::$html, 0, $start), '<tr>');
        $rowEnd   = strpos(self::$html, '</tr>', $start);

        self::assertNotFalse($rowStart, "Could not find the start of {$slug}'s row.");
        self::assertNotFalse($rowEnd, "Could not find the end of {$slug}'s row.");

        return substr(self::$html, (int) $rowStart, (int) $rowEnd - (int) $rowStart);
    }
}
