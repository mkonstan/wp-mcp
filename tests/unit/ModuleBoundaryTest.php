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
 * TWO WAYS IT COULD FAIL OPEN, BOTH CLOSED IN ROUND 3, and neither had an instance - which is
 * the argument for closing them rather than against it, because the whole point of this file is
 * that nobody has to remember the rule. (a) A `wpmcp_*` call whose definition the scanner could
 * not find was SKIPPED; an unresolved call is now reported, and `definitionsIn()` is tokenised so
 * an indented or differently-cased declaration cannot hide. (b) `src/` holds CLASSES, which a
 * `wpmcp_*(` detector cannot see at all, so a module could have reached into the transport layer
 * unchecked; `classRefsIn()` now refuses any `WpMcp\` reference from a module.
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
        $defined    = self::definitions();
        $offences   = [];
        $unresolved = [];

        foreach (self::moduleFiles() as $module) {
            foreach (self::callsIn((string) file_get_contents($module)) as $fn) {
                $home = $defined[$fn] ?? null;

                if ($home === null) {
                    $unresolved[] = basename($module) . ' calls ' . $fn . '()';
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

        // AND AN UNRESOLVED CALL IS REPORTED, NOT SKIPPED, which closes the one way this gate
        // could FAIL OPEN. The first version skipped a `wpmcp_*` call whose definition it could
        // not find, on the reasoning that an undefined function is PHP's own loud failure. True,
        // and it is not the risk: the risk is a definition the SCANNER missed - indented, or
        // spelled with different case - because then a module could call straight into
        // endpoint.php and resolve to null. Nothing is unresolved today, so this costs nothing
        // and the hole closes before anyone has to remember it. definitions() is tokenised for
        // the same reason.
        self::assertSame(
            [],
            $unresolved,
            "A module calls a wpmcp_* function this test cannot locate:\n"
            . implode("\n", $unresolved)
            . "\n\nEither it is a typo - PHP will say so at runtime - or definitions() failed to"
            . ' see a declaration that exists, in which case this gate was about to let a call'
            . ' into the core through unchecked. Find the declaration before assuming the first.'
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
     * THE SAME BOUNDARY, FOR CLASSES - which a `wpmcp_*(` detector cannot see at all.
     *
     * THE SECOND WAY THIS GATE COULD FAIL OPEN. Everything above reasons about FUNCTION names,
     * and the plugin has classes too: two namespaced ones in `src/`, and the flat
     * `WpMcp_Menu_Collector`. A module writing `new WpMcp\SchemaValidator()` would have been
     * invisible to every assertion in this file while reaching straight into the transport layer.
     * No module does today, which is the argument for closing it now rather than later: the test
     * exists so that nobody has to remember the rule.
     *
     * `src/` IS CORE, so the answer is the same as for endpoint.php - a module may not - and the
     * rule is expressed the same way as for functions: a class a module references must be
     * DECLARED in a module. If a module ever genuinely needs one of this plugin's types, that is a
     * change to the seam's contract: name it in ARCHITECTURE.md's "The module seam" and admit it
     * here on purpose.
     *
     * AND THE REVERSE, in the same test and for the function direction's reason: a core file that
     * instantiated a module's class would stop working the moment that module was deleted.
     *
     * @group sprint-seam
     */
    public function testTheClassBoundaryHoldsInBothDirections(): void
    {
        $declared   = self::classDefinitions();
        $fromModule = [];
        $fromCore   = [];

        foreach (self::moduleFiles() as $module) {
            foreach (self::classRefsIn((string) file_get_contents($module)) as $name) {
                $home = $declared[$name] ?? null;

                if ($home === null || strpos($home, 'modules/') !== 0) {
                    $fromModule[] = basename($module) . ' references ' . $name
                        . ' (declared in ' . ($home ?? 'nowhere this test can see') . ')';
                }
            }
        }

        self::assertSame(
            [],
            $fromModule,
            "A module reached outside the seam for a class:\n" . implode("\n", $fromModule)
            . "\n\nA module may call WordPress and the named helpers in tools.php. If it genuinely"
            . " needs one of this plugin's types, say so in ARCHITECTURE.md and admit it here."
        );

        foreach (array_merge(self::MAY_CALL, self::MAY_NOT_CALL) as $core) {
            $path = \WPMCP_PLUGIN_DIR . '/' . $core;

            if (!is_file($path)) {
                continue;
            }

            foreach (self::classRefsIn((string) file_get_contents($path)) as $name) {
                if (strpos($declared[$name] ?? '', 'modules/') === 0) {
                    $fromCore[] = $core . ' references ' . $name . ', declared in ' . $declared[$name];
                }
            }
        }

        self::assertSame(
            [],
            $fromCore,
            'The core references a class a module declares, so it stops working the moment that'
            . " module is deleted:\n" . implode("\n", $fromCore)
        );
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
         * A docblock that MENTIONS wpmcp_trace() and wpmcp_render_admin() in prose, and
         * WpMcp\SchemaValidator by name.
         * See also wpmcp_auth_event().
         */
        function wpmcp_example_thing() {
            $s = 'wpmcp_in_a_string()';
            // wpmcp_in_a_comment()
            return wpmcp_cannot('do the thing') . wpmcp_raw_title($p) . WPMCP_UPPERCASE_CALL();
        }
        PHP;

        $found = self::callsIn($source);

        // A CALL IS CASE-INSENSITIVE IN PHP, so the detector lower-cases both sides. Without
        // this, `WPMCP_Cannot()` would resolve to null and - before round 3 - be skipped.
        self::assertContains('wpmcp_uppercase_call', $found, 'A differently-cased call was missed.');

        // AND `WpMcp\` IN PROSE IS NOT A REFERENCE, the same rule the call detector follows.
        self::assertSame([], self::classRefsIn($source), 'A docblock mention was read as a reference.');

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
     * The two detectors find what they are for, or the two assertions built on them are no-ops.
     *
     * An INDENTED declaration is the case the previous, regex-based definitions() could not see -
     * it anchored on `^function` - and a definition the scanner misses is a call that resolves to
     * null, which is the fail-open path round 3 closed.
     *
     * @group sprint-seam
     */
    public function testTheDetectorsSeeAnIndentedDefinitionAndARealClassReference(): void
    {
        $source = <<<'PHP'
        <?php
        if (true) {
            function wpmcp_indented_helper() { return 1; }
        }
        function WPMCP_Mixed_Case() { return 2; }
        PHP;

        $defined = self::definitionsIn($source);

        self::assertContains('wpmcp_indented_helper', $defined, 'An indented declaration was missed.');
        self::assertContains('wpmcp_mixed_case', $defined, 'A mixed-case declaration was missed.');

        $refs = self::classRefsIn(
            '<?php use WpMcp\ProtocolVersion; $v = new \WpMcp\SchemaValidator();'
            . ' WpMcp\ProtocolVersion::latest(); $w = new WpMcp_Menu_Collector();'
            . ' $x = new WP_Error(); wpmcp_cannot("x");'
        );

        self::assertSame(
            ['WpMcp\ProtocolVersion', 'WpMcp\SchemaValidator', 'WpMcp_Menu_Collector'],
            $refs,
            'A real class reference was missed, so the assertion that no module has one is a'
            . ' no-op. Both shapes must be seen - the namespaced src/ one and the flat WpMcp_ one'
            . " - and WordPress's own classes and our functions must not be swept up."
        );

        // AND A CONSTRUCTOR IS NOT A FUNCTION CALL, which is the false positive the round-3
        // lower-casing produced: `WpMcp_Menu_Collector` lower-cases into the `wpmcp_` prefix.
        self::assertSame(
            ['wpmcp_cannot'],
            self::callsIn('<?php $w = new WpMcp_Menu_Collector(); wpmcp_cannot("x");'),
            'callsIn() reported a constructor, a method, or nothing at all.'
        );

        // And the class map finds both shapes where they actually live.
        $classes = self::classDefinitions();

        self::assertSame('modules/menus.php', $classes['WpMcp_Menu_Collector'] ?? null);
        self::assertSame('src/SchemaValidator.php', $classes['WpMcp\SchemaValidator'] ?? null);
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
            if (!is_array($token) || $token[0] !== T_STRING
                || strpos(strtolower($token[1]), 'wpmcp_') !== 0) {
                continue;
            }

            // WALK BACK OVER WHITESPACE, AND REFUSE FOUR SHAPES THAT ARE NOT A FUNCTION CALL.
            // `function foo(` is a declaration. `new Foo(` is a CONSTRUCTOR - which the round-3
            // lower-casing made visible, because `WpMcp_Menu_Collector` lower-cases into the
            // `wpmcp_` prefix and the menus module instantiates one, so this detector reported a
            // call that does not exist. A class is classRefsIn()'s business. `Foo::bar(` and
            // `$foo->bar(` are methods, reached through a class, so the same detector owns them.
            for ($b = $i - 1; $b >= 0; $b--) {
                if (is_array($tokens[$b]) && $tokens[$b][0] === T_WHITESPACE) {
                    continue;
                }
                if (is_array($tokens[$b]) && in_array(
                    $tokens[$b][0],
                    [T_FUNCTION, T_NEW, T_DOUBLE_COLON, T_OBJECT_OPERATOR],
                    true
                )) {
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
                    // Lower-cased because PHP resolves a function name case-insensitively, so
                    // `WPMCP_Cannot()` and `wpmcp_cannot()` are one call. definitions() does the
                    // same, and without both a differently-cased call resolves to null.
                    $found[strtolower($token[1])] = true;
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
            $relative = self::relative($path);

            foreach (self::definitionsIn((string) file_get_contents($path)) as $fn) {
                $map[$fn] = $relative;
            }
        }

        return $map;
    }

    /**
     * Every `wpmcp_*` function DECLARED in $source, lower-cased.
     *
     * TOKENISED, NOT `^function` (round 3). The regex it replaces anchored on column 0, so an
     * indented declaration - inside an `if`, or a conditionally defined helper - was invisible,
     * and a module calling it resolved to null. PHP function names are case-insensitive, so the
     * names are lower-cased on both sides of the comparison.
     *
     * @return list<string>
     */
    private static function definitionsIn(string $source): array
    {
        $tokens = token_get_all($source);
        $found  = [];

        foreach ($tokens as $i => $token) {
            if (!is_array($token) || $token[0] !== T_FUNCTION) {
                continue;
            }

            for ($f = $i + 1; $f < count($tokens); $f++) {
                if (is_array($tokens[$f]) && $tokens[$f][0] === T_WHITESPACE) {
                    continue;
                }
                if (is_array($tokens[$f]) && $tokens[$f][0] === T_STRING
                    && strpos(strtolower($tokens[$f][1]), 'wpmcp_') === 0) {
                    $found[strtolower($tokens[$f][1])] = true;
                }
                break;
            }
        }

        return array_keys($found);
    }

    /**
     * Every class of THIS PLUGIN referenced in $source, sorted and unique - from the tokeniser,
     * so a name in a docblock or a string is not a reference.
     *
     * BOTH SHAPES, because the plugin has two: the namespaced `src/` one, which PHP 8 emits as a
     * single `T_NAME_QUALIFIED` / `T_NAME_FULLY_QUALIFIED` token, and the flat `WpMcp_Something`,
     * which is a plain `T_STRING`. Missing the second is how a detector like this lets a module
     * instantiate a core class: the only flat class the plugin has happens to live in a module,
     * and "happens to" is what this file exists to replace.
     *
     * `Walker`, `WP_Error`, `stdClass` and the rest are WordPress's or PHP's, and out of scope.
     *
     * @return list<string>
     */
    private static function classRefsIn(string $source): array
    {
        $found = [];

        foreach (token_get_all($source) as $token) {
            if (!is_array($token)) {
                continue;
            }

            if (in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                $name = ltrim($token[1], '\\');

                if (strpos($name, 'WpMcp\\') === 0) {
                    $found[$name] = true;
                }

                continue;
            }

            // The flat convention. Underscore-suffixed, so `WpMcp` alone and every `wpmcp_*`
            // FUNCTION name stay out of it.
            if ($token[0] === T_STRING && preg_match('/^WpMcp_[A-Za-z0-9_]+$/', $token[1]) === 1) {
                $found[$token[1]] = true;
            }
        }

        $names = array_keys($found);
        sort($names);

        return $names;
    }

    /**
     * class name => the plugin-relative file it is declared in, for both shapes.
     *
     * A namespaced class is keyed by its FULL name, because that is how a module would write it.
     * `src/` is one class per file by the autoloader's own rule, which is what makes deriving the
     * name from the path correct rather than a guess.
     *
     * @return array<string, string>
     */
    private static function classDefinitions(): array
    {
        static $map = null;

        if ($map !== null) {
            return $map;
        }

        $map = [];

        foreach ((array) glob(\WPMCP_PLUGIN_DIR . '/src/*.php') as $class) {
            $map['WpMcp\\' . basename((string) $class, '.php')] = 'src/' . basename((string) $class);
        }

        $flat = array_merge(
            (array) glob(\WPMCP_PLUGIN_DIR . '/*.php'),
            (array) glob(\WPMCP_PLUGIN_DIR . '/modules/*.php')
        );

        foreach ($flat as $path) {
            $path     = (string) $path;
            $relative = self::relative($path);

            if (preg_match_all(
                '/^\s*(?:final |abstract )?class (WpMcp_[A-Za-z0-9_]+)/m',
                (string) file_get_contents($path),
                $m
            ) === 0) {
                continue;
            }

            foreach ($m[1] as $class) {
                $map[$class] = $relative;
            }
        }

        return $map;
    }

    /** An absolute plugin path as the plugin-relative one this test reports. */
    private static function relative(string $path): string
    {
        $root = str_replace('\\', '/', \WPMCP_PLUGIN_DIR) . '/';

        return str_replace($root, '', str_replace('\\', '/', $path));
    }

    /** @return list<string> absolute paths */
    private static function moduleFiles(): array
    {
        $files = (array) glob(\WPMCP_PLUGIN_DIR . '/modules/*.php');

        self::assertNotSame([], $files, 'There are no module files, so nothing here is asserted.');

        return array_map('strval', $files);
    }
}
