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
 * A THIRD WAY, CLOSED IN THE ACF-READ SPRINT, and it is the one that was neither a call nor a
 * class: a name in NEITHER position fell through in SILENCE. Found by Fable after the seam
 * sprint's gate closed (`analysis/69` round 4; `analysis/BACKLOG.md`). Round 3's unresolved-call
 * assertion did not cover it, because that assertion only reports unresolved names followed by
 * `(`. See testEveryNameOfThisPluginInAModuleIsClaimedByADetector() for the two shapes - a
 * `WPMCP_*` constant read, and a flat `WpMcp_*` class in a type-hint, a return type or a `catch` -
 * and for the STATED decision about what a module may do with a core constant. The assertion is
 * now the positive-marker one: the set of names NEITHER detector claimed is `[]`, so a position
 * nobody enumerated is reported rather than skipped.
 *
 * AND POSITION IS DECIDED IN ONE PLACE, `tests/Support/PhpSymbols.php`, since 1.2.0 - because
 * `ModuleApiFaceTest` asks the same question about ANOTHER plugin's symbols, and two token
 * scanners would be two sets of the holes above to find twice.
 *
 * WHAT THIS GATE CANNOT SEE, AND IT IS SAID HERE BECAUSE A MECHANISM THAT DOES NOT STATE ITS
 * LIMIT INVITES THE EXACT TRUST THIS SPRINT SET OUT TO REMOVE. It resolves names, so it resolves
 * only names that are WRITTEN. An INDIRECT call is outside it:
 *
 *     $fn = 'wpmcp_trace';  $fn($id);          // a variable function
 *     call_user_func('wpmcp_trace', $id);      // a string callable
 *     add_action('x', 'wpmcp_trace');          // a callback handed to WordPress
 *     ['WpMcp_Thing', 'make']()                // a callable array
 *
 * Every one of those reaches a core symbol and none of them is a token this file can attribute.
 * No module reaches the CORE through one today, and the string callables that exist in `modules/`
 * are each a module naming ITS OWN function - a provider handed to wpmcp_register_module(), a face
 * handed to wpmcp_register_module_face(), a filter callback handed to add_filter(), a registration
 * callback handed to add_action(), and a method probe named inside a face. Five of those live in
 * modules/acf.php. Every one resolves inside the module that wrote it, which is why the hand check
 * below is a reading and not a rewrite.
 *
 * SO WHAT A REVIEWER MUST DO BY HAND, and it is one grep per module file: read every string
 * literal that looks like a symbol name. Concretely, in a module diff, look at every
 * `call_user_func`, `call_user_func_array`, `add_action`, `add_filter`, `array_map`, `usort`, a
 * `$variable(` call, and any `[...]` callable array, and ask what the string resolves to. The gate
 * covers the other 99% so that this 1% is a short, bounded reading rather than the whole file.
 *
 * AND IT RUNS IN THE UNIT TIER, on files alone: no WordPress, no site, no subprocess. The
 * boundary is a property of the source.
 *
 * @group sprint-seam
 */

declare(strict_types=1);

namespace WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WpMcp\Tests\Support\PhpSymbols;

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
            foreach (self::classRefsIn((string) file_get_contents($module)) as $key => $name) {
                $home = $declared[$key] ?? null;

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

            foreach (self::classRefsIn((string) file_get_contents($path)) as $key => $name) {
                if (strpos($declared[$key] ?? '', 'modules/') === 0) {
                    $fromCore[] = $core . ' references ' . $name . ', declared in ' . $declared[$key];
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

        self::assertContains('wpmcp_cannot', $found, 'A real call was missed.');
        self::assertContains('wpmcp_raw_title', $found, 'A real call was missed.');

        // A CALL IS CASE-INSENSITIVE IN PHP, so the detector lower-cases both sides. Without
        // this, `WPMCP_Cannot()` would resolve to null and - before round 3 - be skipped.
        self::assertContains('wpmcp_uppercase_call', $found, 'A differently-cased call was missed.');

        // AND `WpMcp\` IN PROSE IS NOT A REFERENCE, the same rule the call detector follows.
        self::assertSame([], self::classRefsIn($source), 'A docblock mention was read as a reference.');

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
     * A LEADING BACKSLASH HID A CALL AND A CLASS FROM BOTH DETECTORS UNTIL ROUND 4.
     *
     * MEASURED on PHP 8.2.29: `\wpmcp_cannot` and `\WpMcp_Menu_Collector` each arrive as ONE
     * `T_NAME_FULLY_QUALIFIED` token, so a detector reading `T_STRING` sees neither, and one
     * matching a literal `WpMcp\` prefix does not see the second either - its name ltrims to
     * `WpMcp_Menu_Collector`, which carries no namespace separator at all. A module could have
     * reached any core symbol by typing one extra character. No module does, and the whole point
     * of this file is that nobody has to check that by hand.
     *
     * HELD BY A FIXTURE RATHER THAN ASSERTED IN PROSE, which is the same standard the `T_NEW`
     * case is held to: the test states the shape is covered by covering it.
     *
     * @group sprint-seam
     */
    public function testAFullyQualifiedNameIsSeenByBothDetectors(): void
    {
        // A fully-qualified CALL. One token, and callsIn() must still report it.
        self::assertSame(
            ['wpmcp_cannot'],
            self::callsIn('<?php \wpmcp_cannot("x");'),
            'A fully-qualified function call is invisible, so a module could reach the core by'
            . ' writing a leading backslash.'
        );

        // A fully-qualified FLAT class. One token, no namespace separator, and classRefsIn() must
        // still report it - this is the shape the `WpMcp\` prefix test could never have matched.
        self::assertSame(
            ['wpmcp_menu_collector'],
            array_keys(self::classRefsIn('<?php $w = new \WpMcp_Menu_Collector();')),
            'A fully-qualified flat class reference is invisible.'
        );

        // A fully-qualified NAMESPACED class, both as a constructor and as a static call.
        self::assertSame(
            ['wpmcp\protocolversion', 'wpmcp\schemavalidator'],
            array_keys(self::classRefsIn(
                '<?php $v = new \WpMcp\SchemaValidator(); \WpMcp\ProtocolVersion::latest();'
            )),
            'A fully-qualified namespaced class reference is invisible.'
        );

        // AND THE TWO DETECTORS DO NOT OVERLAP on either shape: a call is not a class, and a
        // constructor is not a call, however the name is qualified.
        self::assertSame([], array_keys(self::classRefsIn('<?php \wpmcp_cannot("x");')));
        self::assertSame([], self::callsIn('<?php $w = new \WpMcp_Menu_Collector();'));
    }

    /**
     * CLASS NAMES ARE CASE-INSENSITIVE IN PHP AND THE SCAN WAS NOT, until round 4.
     *
     * `new wpmcp\schemavalidator()` resolves to exactly the class `new WpMcp\SchemaValidator()`
     * does, and `new WPMCP_MENU_COLLECTOR()` to exactly the class the menus module declares. Both
     * slipped a case-sensitive scan. No shipped class exercises it, which is precisely why it
     * would be written one day by somebody who did not know.
     *
     * Both sides are now lower-cased - the reference and the declaration map - as the function
     * detector already did.
     *
     * @group sprint-seam
     */
    public function testAClassReferenceIsFoundWhateverItsCase(): void
    {
        self::assertSame(
            ['wpmcp\schemavalidator'],
            array_keys(self::classRefsIn('<?php $v = new wpmcp\schemavalidator();')),
            'A lower-cased namespaced class reference slipped the scan.'
        );
        self::assertSame(
            ['wpmcp_menu_collector'],
            array_keys(self::classRefsIn('<?php $w = new WPMCP_MENU_COLLECTOR();')),
            'An upper-cased flat class reference slipped the scan.'
        );

        // And the declaration map answers to the same lower-cased key, or the reference above
        // would be reported as "declared nowhere" instead of as a boundary violation.
        $classes = self::classDefinitions();

        self::assertSame('modules/menus.php', $classes['wpmcp_menu_collector'] ?? null);
        self::assertSame('src/SchemaValidator.php', $classes['wpmcp\schemavalidator'] ?? null);
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
            ['wpmcp\protocolversion', 'wpmcp\schemavalidator', 'wpmcp_menu_collector'],
            array_keys($refs),
            'A real class reference was missed, so the assertion that no module has one is a'
            . ' no-op. Both shapes must be seen - the namespaced src/ one and the flat WpMcp_ one'
            . " - and WordPress's own classes and our functions must not be swept up."
        );

        // The name AS WRITTEN is kept beside the key, so a failure can quote what the author
        // actually typed rather than a normalised form they will not recognise.
        self::assertSame('WpMcp_Menu_Collector', $refs['wpmcp_menu_collector'] ?? null);

        // AND A CONSTRUCTOR IS NOT A FUNCTION CALL, which is the false positive the round-3
        // lower-casing produced: `WpMcp_Menu_Collector` lower-cases into the `wpmcp_` prefix.
        self::assertSame(
            ['wpmcp_cannot'],
            self::callsIn('<?php $w = new WpMcp_Menu_Collector(); wpmcp_cannot("x");'),
            'callsIn() reported a constructor, a method, or nothing at all.'
        );
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

    /**
     * THE POSITION THAT USED TO FALL BETWEEN THIS FILE AND SILENCE (the sprint ACF-READ rider).
     *
     * FOUND BY FABLE, SEAM ROUND 4, AFTER THAT SPRINT'S GATE HAD CLOSED (`analysis/69`, round 4;
     * `analysis/BACKLOG.md`). Everything above decides by POSITION - `(` means a call, and
     * `new` / `::` / `->` / `instanceof` / `extends` / `implements` / `use` mean a class - and
     * Fable confirmed the rule is sound and that every MISATTRIBUTION errs loud. But a name in
     * NEITHER position falls through in silence, and round 3's unresolved-call assertion does not
     * cover it, because that assertion only reports unresolved names followed by `(`. Two shapes:
     *
     *   1. a `WPMCP_*` CONSTANT read from a module - 3 are defined in endpoint.php, 13 in
     *      trace.php, 5 in tools.php and the rest in wp-mcp.php;
     *   2. a flat `WpMcp_*` class in a TYPE-HINT, a RETURN TYPE or a `catch` clause - none of
     *      which is `new`, `::` or `(`.
     *
     * Both were empty when Fable found them, which is the argument for closing them rather than
     * against it: the trigger recorded in the backlog was "the ACF read-only sprint, whose module
     * is this detector's FIRST new consumer", so the hole and its first possible exploiter arrive
     * together.
     *
     * THE FIX IS THE POSITIVE-MARKER SHAPE, the same one `ModuleApiFaceTest` uses and for the same
     * reason: assert that the set of names NEITHER detector claimed is `[]`. A check that reports
     * nothing when it sees nothing is indistinguishable from a check that ran and was satisfied.
     *
     * AND THE DECISION THE BACKLOG ASKED FOR, STATED. A constant read is NOT simply permitted. It
     * is held to the SAME boundary as a call: the constant must be defined in a file a module may
     * call into. `WPMCP_DB_VER` would be harmless, and `WPMCP_TRACE_TEXT_BYTES` is exactly the
     * coupling to the log that rule (1) exists to prevent - and no rule can tell those two apart
     * by name. So the rule is the one that is already enforced for functions, applied to the other
     * kind of symbol, and a module that genuinely needs a core constant moves it to tools.php or
     * is admitted here on purpose.
     *
     * @group sprint-seam
     * @group sprint-acf-read
     */
    public function testEveryNameOfThisPluginInAModuleIsClaimedByADetector(): void
    {
        $constants = self::constantDefinitions();
        $unclaimed = [];
        $offences  = [];

        foreach (self::moduleFiles() as $module) {
            foreach (self::pluginSymbols((string) file_get_contents($module)) as $symbol) {
                $where = basename($module) . ' line ' . $symbol['line'] . ': ' . $symbol['name'];

                switch ($symbol['kind']) {
                    // CLAIMED by an assertion above: a call is resolved to its defining file, a
                    // class reference to the file that declares it.
                    case 'call':
                    case 'class':
                        break;

                    // A DECLARATION OF THE MODULE'S OWN is not a reference to anything.
                    case 'function_declaration':
                    case 'class_declaration':
                    case 'const_declaration':
                        break;

                    // REACHED THROUGH A CLASS, and the class is a name in the source that the
                    // class detector already owns - `WpMcp_Thing::make()` names WpMcp_Thing, and
                    // `$thing->wpmcp_x()` needed a `new` or a type-hint to get that object, both
                    // of which are class positions.
                    case 'method':
                    case 'static_call':
                        break;

                    case 'constant':
                        $home = $constants[$symbol['lower']] ?? null;

                        if ($home === null) {
                            $unclaimed[] = $where . ' is read as a constant and this test cannot'
                                . ' find where it is defined';
                        } elseif (!in_array($home, self::MAY_CALL, true) && strpos($home, 'modules/') !== 0) {
                            $offences[] = $where . ' is a constant defined in ' . $home;
                        }
                        break;

                    default:
                        $unclaimed[] = $where . ' appears in ' . $symbol['kind'] . ' position,'
                            . ' which neither detector in this file claims';
                }
            }
        }

        self::assertSame(
            [],
            $unclaimed,
            "A module names one of this plugin's symbols in a position no detector here claims:\n"
            . implode("\n", $unclaimed)
            . "\n\nThat is the shape that used to pass in silence. Either the name belongs in a"
            . ' position the detectors read - a call or a class reference - or this file needs a'
            . ' bucket for the position it is in, decided on purpose rather than left open.'
        );

        self::assertSame(
            [],
            $offences,
            "A module reads a constant the core defines outside the seam:\n" . implode("\n", $offences)
            . "\n\nA constant read is held to the same boundary as a call, because no rule can tell"
            . ' WPMCP_DB_VER from WPMCP_TRACE_TEXT_BYTES by name. Move it into tools.php as part of'
            . " the seam's contract, or do without it."
        );
    }

    /**
     * THE NEW BUCKETS ARE REAL, held by a fixture for the reason every other shape in this file
     * is: a test that states a position is covered, without covering it, is the thing this sprint
     * is removing.
     *
     * @group sprint-seam
     * @group sprint-acf-read
     */
    public function testAConstantReadAndATypeHintAreBothSeen(): void
    {
        $source = <<<'PHP'
        <?php
        function wpmcp_fixture_thing(WpMcp_Menu_Collector $c): WpMcp_Menu_Collector {
            try { $n = WPMCP_TRACE_TEXT_BYTES + WPMCP_PAGE_CAP; }
            catch (WpMcp_Menu_Collector $e) { $n = 0; }
            return $c;
        }
        PHP;

        $kinds = [];

        foreach (self::pluginSymbols($source) as $symbol) {
            $kinds[$symbol['name'] . '#' . $symbol['kind']] = true;
        }

        self::assertArrayHasKey(
            'WPMCP_TRACE_TEXT_BYTES#constant',
            $kinds,
            'A constant read is not seen as one, so the boundary assertion above never fires.'
        );
        self::assertArrayHasKey(
            'WpMcp_Menu_Collector#type',
            $kinds,
            'A flat class in a type-hint, a return type or a catch clause is not seen, which is'
            . ' the second half of the position that used to fall through.'
        );

        // AND NEITHER OF THE TWO ORIGINAL DETECTORS CLAIMS THEM, which is the fact that makes the
        // new bucket necessary rather than decorative.
        self::assertSame([], self::callsIn($source), 'callsIn() claimed a type-hint or a constant.');
        self::assertSame([], array_keys(self::classRefsIn($source)), 'classRefsIn() claimed a type-hint.');

        // The constant map can answer for both, and it knows which file each came from - the
        // half that turns "unclaimed" into "out of bounds".
        $constants = self::constantDefinitions();

        self::assertSame('trace.php', $constants['wpmcp_trace_text_bytes'] ?? null);
        self::assertSame('tools.php', $constants['wpmcp_page_cap'] ?? null);
    }

    /* ------------------------------------------------------------------ the detector */

    /**
     * Every name of THIS PLUGIN in $source, with the POSITION it appears in.
     *
     * ONE SCANNER, IN tests/Support/PhpSymbols.php, AND NOT A SECOND COPY HERE. Two gates now ask
     * the same question of the same source - this file about the plugin's own symbols,
     * ModuleApiFaceTest about another plugin's - and the one thing seam round 4 measured about
     * token scanning is that its holes are in the positions nobody enumerated. Two scanners would
     * be two sets of those holes to find twice. So position is decided in one place and this file
     * only says which names it cares about.
     *
     * CASE-INSENSITIVE, because PHP resolves BOTH function and class names that way.
     * `new wpmcp\schemavalidator()` is the same class as `new WpMcp\SchemaValidator()`. The name
     * is carried AS WRITTEN so a failure can quote what the author typed; every comparison
     * downstream lower-cases.
     *
     * @return list<array{name: string, lower: string, kind: string, line: int}>
     */
    private static function pluginSymbols(string $source): array
    {
        $mine = [];

        foreach (PhpSymbols::scan($source) as $symbol) {
            if (strpos($symbol['lower'], 'wpmcp_') === 0 || strpos($symbol['lower'], 'wpmcp\\') === 0) {
                $mine[] = $symbol;
            }
        }

        return $mine;
    }

    /**
     * Every `wpmcp_*` function CALLED in $source - so a name in a comment or a string is not one,
     * and neither is a declaration, a constructor, a method or a type-hint.
     *
     * A NAMESPACED NAME IS NEVER A GLOBAL FUNCTION HERE. This plugin declares no function inside a
     * namespace, so `WpMcp\Something` can only be a class - classRefsIn()'s business, and leaving
     * it out here is what keeps the two detectors from double-counting.
     *
     * @return list<string>
     */
    private static function callsIn(string $source): array
    {
        $found = [];

        foreach (self::pluginSymbols($source) as $symbol) {
            if ($symbol['kind'] === 'call' && strpos($symbol['name'], '\\') === false) {
                $found[$symbol['lower']] = true;
            }
        }

        return array_keys($found);
    }

    /**
     * Every `wpmcp_*` function DECLARED in $source, lower-cased.
     *
     * TOKENISED, NOT `^function` (round 3). The regex it replaced anchored on column 0, so an
     * indented declaration - inside an `if`, or a conditionally defined helper - was invisible,
     * and a module calling it resolved to null, which is the fail-open path round 3 closed. PHP
     * function names are case-insensitive, so the names are lower-cased on both sides.
     *
     * @return list<string>
     */
    private static function definitionsIn(string $source): array
    {
        $found = [];

        foreach (self::pluginSymbols($source) as $symbol) {
            if ($symbol['kind'] === 'function_declaration') {
                $found[$symbol['lower']] = true;
            }
        }

        return array_keys($found);
    }

    /**
     * Every class of THIS PLUGIN referenced in $source: `lower-cased name => name as written`.
     *
     * POSITION DECIDES, NOT THE NAME'S SHAPE, and that is round 4's correction. The plugin's flat
     * class convention is `WpMcp_Something` and its functions are `wpmcp_something`, which are the
     * SAME STRING once case is ignored - and case has to be ignored, because PHP ignores it. So a
     * shape test cannot tell a class from a function, and PhpSymbols reads the CONTEXT instead.
     *
     * A TYPE-HINT IS DELIBERATELY NOT ONE OF THESE, and that is not an oversight: a name in type
     * position is reported by testEveryNameOfThisPluginInAModuleIsClaimedByADetector() above, with
     * its own message, because folding it in here would have changed what three round-4 fixtures
     * assert about this method while silently widening it.
     *
     * `Walker`, `WP_Error`, `stdClass` and the rest are WordPress's or PHP's, and out of scope.
     *
     * @return array<string, string>
     */
    private static function classRefsIn(string $source): array
    {
        $found = [];

        foreach (self::pluginSymbols($source) as $symbol) {
            if ($symbol['kind'] === 'class') {
                $found[$symbol['lower']] = $symbol['name'];
            }
        }

        ksort($found);

        return $found;
    }

    /**
     * `wpmcp_*` function name => the plugin-relative file it is declared in.
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
     * LOWER-CASED `WPMCP_*` constant name => the plugin-relative file that defines it.
     *
     * READ FROM `define()`'s STRING ARGUMENT, because that is where this plugin's constants are:
     * all forty-odd arrive through `define('WPMCP_X', ...)`, whose name is a string literal and
     * therefore not a token at all. A `const X = 1` would be a token and is collected too, so the
     * map does not depend on which of the two a future author reaches for.
     *
     * @return array<string, string>
     */
    private static function constantDefinitions(): array
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
            $source   = (string) file_get_contents($path);
            $relative = self::relative($path);

            foreach (PhpSymbols::defineNames($source) as $name) {
                if (strpos($name, 'wpmcp_') === 0) { $map[$name] = $relative; }
            }

            foreach (PhpSymbols::of($source, 'const_declaration') as $lower => $name) {
                if (strpos($lower, 'wpmcp_') === 0) { $map[$lower] = $relative; }
            }
        }

        return $map;
    }

    /**
     * LOWER-CASED class name => the plugin-relative file it is declared in, for both shapes.
     *
     * A namespaced class is keyed by its FULL name, because that is how a module would write it.
     * `src/` is one class per file by the autoloader's own rule, which is what makes deriving the
     * name from the path correct rather than a guess. Keys are lower-cased for the reason
     * classRefsIn() lower-cases: PHP resolves a class name case-insensitively, so a map keyed on
     * the declaration's own casing would miss `new wpmcp\schemavalidator()`.
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
            $map[strtolower('WpMcp\\' . basename((string) $class, '.php'))] = 'src/' . basename((string) $class);
        }

        $flat = array_merge(
            (array) glob(\WPMCP_PLUGIN_DIR . '/*.php'),
            (array) glob(\WPMCP_PLUGIN_DIR . '/modules/*.php')
        );

        foreach ($flat as $path) {
            $path     = (string) $path;
            $relative = self::relative($path);

            if (preg_match_all(
                '/^\s*(?:final |abstract )?class (WpMcp_[A-Za-z0-9_]+)/mi',
                (string) file_get_contents($path),
                $m
            ) === 0) {
                continue;
            }

            foreach ($m[1] as $class) {
                $map[strtolower($class)] = $relative;
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
