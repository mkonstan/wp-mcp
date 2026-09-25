<?php
/**
 * THE MODULE BOUNDARY IS A GATE, NOT A CONVENTION.
 *
 * ARCHITECTURE.md's "The module seam" says a module may call WordPress and a named handful of
 * helpers in `tools.php`, and may call nothing in `endpoint.php`, `admin.php` or `trace.php` -
 * a module does not reach into the request path, the settings screen or the log. Until this
 * file, nothing enforced that. **An unenforced rule inside the seam's own contract is the ACF
 * `permission_callback` mistake in our own words** - D23 cites that API because a requirement
 * that is only mandatory when somebody remembers it is not a requirement - so the choice was
 * either to enforce the sentence or to stop writing it. This enforces it.
 *
 * THE ALLOW-LIST IS THE DEFINERS, NOT THE FORBIDDEN FILES, and that is strictly stronger: a
 * module's calls must resolve in `tools.php`, `modules.php` or a module file, so a future core
 * file nobody thought to forbid is forbidden by default. The failure message names the file the
 * symbol actually came from, because "you may not call that" is not actionable and "that lives
 * in trace.php" is.
 *
 * TOKENS, NOT A GREP, and the difference is a test that stays useful. `token_get_all()` sees
 * only real code, so a docblock that MENTIONS `wpmcp_trace()` in prose - which a module's header
 * may legitimately do - is not a call, and a future comment cannot redden this file. A regex
 * over the raw text would have to be weakened the first time somebody wrote a sentence.
 *
 * AND IT RUNS IN THE UNIT TIER, on files alone: no WordPress, no site, no subprocess. The
 * boundary is a property of the source.
 *
 * @group sprint-seam
 */

declare(strict_types=1);

namespace WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ModuleBoundaryTest extends TestCase
{
    /** Files whose functions a module may call. Everything else is out of bounds. */
    private const MAY_CALL = ['tools.php', 'modules.php'];

    /** Files a module may NOT call into, named for the failure message and for the sweep below. */
    private const MAY_NOT_CALL = ['endpoint.php', 'admin.php', 'trace.php', 'wp-mcp.php', 'uninstall.php'];

    /**
     * The `tools.php` helpers a module calls today. A CLOSED LIST, because this is the one
     * direction of traffic ARCHITECTURE.md names one by one - so a sixth is a decision that
     * updates that sentence, not something that arrives unnoticed.
     */
    private const CORE_HELPERS = [
        'wpmcp_cannot',
        'wpmcp_decode_specialchars',
        'wpmcp_listable_statuses',
        'wpmcp_post_type_ok',
        'wpmcp_raw_title',
    ];

    /**
     * @group sprint-seam
     */
    public function testNoModuleCallsIntoTheRequestPathTheSettingsScreenOrTheLog(): void
    {
        $defined  = self::definitions();
        $offences = [];

        foreach (self::moduleFiles() as $module) {
            foreach (self::callsIn((string) file_get_contents($module)) as $fn) {
                $home = $defined[$fn] ?? null;

                if ($home === null) {
                    // Declared nowhere in the plugin: either a WordPress function whose name
                    // happens to start with our prefix (there are none) or a typo. Either way
                    // it is not a boundary violation, and PHP's own "undefined function" is
                    // the loud failure for it.
                    continue;
                }

                if (!in_array($home, self::MAY_CALL, true) && strpos($home, 'modules/') !== 0) {
                    $offences[] = basename($module) . ' calls ' . $fn . '(), which is defined in ' . $home;
                }
            }
        }

        self::assertSame(
            [],
            $offences,
            "A module reached outside the seam:\n" . implode("\n", $offences)
            . "\n\nARCHITECTURE.md's \"The module seam\" says a module may call WordPress and the"
            . ' named helpers in tools.php, and nothing in ' . implode(', ', self::MAY_NOT_CALL)
            . '. Either move what you need into tools.php as a core helper and name it in that'
            . ' section, or do the work inside the module.'
        );
    }

    /**
     * And the traffic that IS allowed is the list ARCHITECTURE.md prints, exactly.
     *
     * This is the half that catches prose drift rather than a violation: round 1 of this sprint
     * wrote "four helpers" in four places while the discovery module was already calling a
     * fifth, `wpmcp_listable_statuses()`. Nothing was wrong with the code; the contract had
     * stopped describing it.
     *
     * @group sprint-seam
     */
    public function testTheCoreHelpersAModuleCallsAreTheOnesTheContractNames(): void
    {
        $defined = self::definitions();
        $used    = [];

        foreach (self::moduleFiles() as $module) {
            foreach (self::callsIn((string) file_get_contents($module)) as $fn) {
                if (($defined[$fn] ?? null) === 'tools.php') {
                    $used[$fn] = true;
                }
            }
        }

        $used = array_keys($used);
        sort($used);

        self::assertSame(
            self::CORE_HELPERS,
            $used,
            'The set of tools.php helpers the modules call has changed. That is a change to the'
            . " seam's contract, so update this list AND the sentence in ARCHITECTURE.md's \"The"
            . ' module seam", modules.php\'s header and modules/menus.php\'s header, which all'
            . ' name them. Got: ' . implode(', ', $used)
        );

        // And each one really is in tools.php, so the assertion above is not comparing two
        // empty sets under a different name.
        foreach (self::CORE_HELPERS as $fn) {
            self::assertSame('tools.php', $defined[$fn] ?? null, "{$fn}() is not defined in tools.php.");
        }
    }

    /**
     * THE OTHER DIRECTION: no core file calls a module's function.
     *
     * `BareCoreTest` proves this by LOADING a core with no modules and watching it work, which
     * is the stronger evidence. This proves it per symbol and names the offender, in
     * milliseconds, without a subprocess - and it catches the case a load cannot: a call on a
     * path no request in that test happens to take.
     *
     * @group sprint-seam
     */
    public function testNoCoreFileCallsAModulesFunction(): void
    {
        $defined  = self::definitions();
        $offences = [];

        foreach (array_merge(self::MAY_CALL, self::MAY_NOT_CALL) as $core) {
            $path = \WPMCP_PLUGIN_DIR . '/' . $core;

            if (!is_file($path)) {
                continue;
            }

            foreach (self::callsIn((string) file_get_contents($path)) as $fn) {
                $home = $defined[$fn] ?? null;

                if ($home !== null && strpos($home, 'modules/') === 0) {
                    $offences[] = $core . ' calls ' . $fn . '(), which is defined in ' . $home;
                }
            }
        }

        self::assertSame(
            [],
            $offences,
            "The core reached into a module, so it no longer works with that module absent:\n"
            . implode("\n", $offences)
        );
    }

    /**
     * THE DETECTOR IS ITSELF TESTED, in both directions, or a broken `callsIn()` would make
     * every assertion above a green no-op - the test that cannot fail this project forbids.
     *
     * @group sprint-seam
     */
    public function testTheDetectorFindsCallsAndOnlyCalls(): void
    {
        $source = <<<'PHP'
        <?php
        /**
         * A docblock that MENTIONS wpmcp_trace() and wpmcp_render_admin() in prose.
         * See also wpmcp_auth_event().
         */
        function wpmcp_example_thing() {
            $s = 'wpmcp_in_a_string()';
            // wpmcp_in_a_comment()
            return wpmcp_cannot('do the thing') . wpmcp_raw_title($p);
        }
        PHP;

        $found = self::callsIn($source);

        self::assertContains('wpmcp_cannot', $found, 'A real call was missed.');
        self::assertContains('wpmcp_raw_title', $found, 'A real call was missed.');

        foreach (['wpmcp_trace', 'wpmcp_render_admin', 'wpmcp_auth_event', 'wpmcp_in_a_string', 'wpmcp_in_a_comment'] as $notACall) {
            self::assertNotContains(
                $notACall,
                $found,
                "{$notACall} is named in a comment or a string, not called. A detector that"
                . ' counted those would have to be weakened the first time a module docblock'
                . ' referred to core.'
            );
        }

        // A definition is not a call, so a module's own function does not report itself.
        self::assertNotContains('wpmcp_example_thing', $found);
    }

    /**
     * And the definition map is real: it finds the seam's own entry point where it lives.
     *
     * @group sprint-seam
     */
    public function testTheDefinitionMapIsReal(): void
    {
        $defined = self::definitions();

        self::assertSame('modules.php', $defined['wpmcp_register_module'] ?? null);
        self::assertSame('modules.php', $defined['wpmcp_module_tools'] ?? null);
        self::assertSame('endpoint.php', $defined['wpmcp_registry_reject_reason'] ?? null);
        self::assertSame('modules/menus.php', $defined['wpmcp_menu_tools'] ?? null);
        self::assertSame('modules/discovery.php', $defined['wpmcp_discovery_tools'] ?? null);
        self::assertGreaterThan(100, count($defined), 'The definition map is implausibly small.');
    }

    /* ------------------------------------------------------------------ the detector */

    /**
     * Every `wpmcp_*` function CALLED in $source, from PHP's own tokeniser - so a name in a
     * comment or a string is not one.
     *
     * A `T_STRING` immediately followed by `(` is a call; a `T_STRING` preceded by `function`
     * is a declaration and is skipped, so a module's own definitions do not count as calls to
     * themselves.
     *
     * @return list<string>
     */
    private static function callsIn(string $source): array
    {
        $tokens = token_get_all($source);
        $found  = [];

        foreach ($tokens as $i => $token) {
            if (!is_array($token) || $token[0] !== T_STRING || strpos($token[1], 'wpmcp_') !== 0) {
                continue;
            }

            // Walk back over whitespace: `function foo(` is a declaration.
            for ($b = $i - 1; $b >= 0; $b--) {
                if (is_array($tokens[$b]) && $tokens[$b][0] === T_WHITESPACE) {
                    continue;
                }
                if (is_array($tokens[$b]) && $tokens[$b][0] === T_FUNCTION) {
                    continue 2;
                }
                break;
            }

            // Walk forward over whitespace to the `(`.
            for ($f = $i + 1; $f < count($tokens); $f++) {
                if (is_array($tokens[$f]) && $tokens[$f][0] === T_WHITESPACE) {
                    continue;
                }
                if ($tokens[$f] === '(') {
                    $found[$token[1]] = true;
                }
                break;
            }
        }

        return array_keys($found);
    }

    /**
     * function name => the plugin-relative file it is declared in.
     *
     * @return array<string, string>
     */
    private static function definitions(): array
    {
        static $map = null;

        if ($map !== null) {
            return $map;
        }

        $map   = [];
        $files = array_merge(
            (array) glob(\WPMCP_PLUGIN_DIR . '/*.php'),
            (array) glob(\WPMCP_PLUGIN_DIR . '/modules/*.php'),
            (array) glob(\WPMCP_PLUGIN_DIR . '/src/*.php')
        );

        foreach ($files as $path) {
            $path     = (string) $path;
            $relative = str_replace(str_replace('\\', '/', \WPMCP_PLUGIN_DIR) . '/', '', str_replace('\\', '/', $path));

            if (preg_match_all('/^function (wpmcp_[a-z0-9_]+)\(/m', (string) file_get_contents($path), $m) === 0) {
                continue;
            }

            foreach ($m[1] as $fn) {
                $map[$fn] = $relative;
            }
        }

        return $map;
    }

    /** @return list<string> absolute paths */
    private static function moduleFiles(): array
    {
        $files = (array) glob(\WPMCP_PLUGIN_DIR . '/modules/*.php');

        self::assertNotSame([], $files, 'There are no module files, so nothing here is asserted.');

        return array_map('strval', $files);
    }
}
