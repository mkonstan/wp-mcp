<?php
/**
 * A MODULE'S AVAILABILITY GUARD IS A GATE, NOT A CONVENTION (D30).
 *
 * `modules.php` rule (1) - what a module may call inside this plugin - has been enforced since
 * 1.1.2 by `ModuleBoundaryTest`. Rule (2) - a module's own availability guard - was a SENTENCE.
 * Nothing proved a guard was present, and nothing proved it was COMPLETE: `function_exists('acf')`
 * stays green forever while the module starts calling a function ACF added two releases later,
 * and the site that pays for the difference is somebody's production install taking a PHP fatal
 * on a tool call.
 *
 * THIS IS THE POSITIVE-MARKER SHAPE, and it is the same fix Fable prescribed for the boundary
 * detector's silent position, for the same reason: a check that reports nothing when it sees
 * nothing is indistinguishable from a check that ran and was satisfied. So the assertion is not
 * "no module calls a forbidden symbol" - it is "the set of foreign symbols a module calls that
 * its face does NOT declare is `[]`". A module cannot pass this by being quiet.
 *
 * GENERALISED ON PURPOSE, AND IT IS NOT GOLD PLATING. A test that knew about `acf` would have to
 * be rewritten by the author of the next module - who is the author least likely to know it
 * exists - and that is how a gate dies. This one walks `wpmcp_module_manifest()`, so a module
 * added without a declaration fails HERE, on the day it is added, naming the symbol.
 *
 * THE FOUR BUCKETS EVERY NAME LANDS IN, and the fourth is the one that matters:
 *
 *   1. this plugin's own - a function, class or `define()`d constant declared under the plugin
 *      root, `modules/` or `src/`. `ModuleBoundaryTest` owns whether reaching for it is allowed;
 *      this file only needs to know it is not another plugin's.
 *   2. PHP's own - `get_defined_functions()['internal']`, the declared internal classes, and
 *      every non-`user` group of `get_defined_constants(true)`. Read from the runtime rather
 *      than listed, so a PHP upgrade cannot make this test wrong.
 *   3. WORDPRESS's - the three lists below. WordPress is not optional: the plugin does not run
 *      without it, so a module calling `get_post()` needs no guard and declaring one would be
 *      noise. The lists are the honest cost of having no machine-readable WordPress symbol
 *      table in the unit tier, and they FAIL SAFE - a WordPress function nobody has added yet
 *      is reported as undeclared, which is a one-line fix with a message that says so, rather
 *      than a hole.
 *   4. the module's DECLARED FACE, required or optional. Anything left over is the answer.
 *
 * WHY IT IS IN THE SPRINT GATE GROUP. Every assertion here is about SOURCE and about the seam's
 * own functions. No WordPress, no site, no ACF - so it passes identically on a site with ACF and
 * on a site without one, which is what a gate group is allowed to contain.
 *
 * @group sprint-acf-read
 */

declare(strict_types=1);

namespace WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WpMcp\Tests\Support\PhpSymbols;
use WpMcp\Tests\Support\WordPressStubs;

final class ModuleApiFaceTest extends TestCase
{
    /**
     * The WordPress functions the shipped modules call. NOT a curated subset of WordPress - it
     * is exactly what `modules/` reaches for today, sorted, and a name that leaves the modules
     * has to leave this list with it.
     *
     * DERIVED, THEN READ ONE BY ONE. The list was produced by running this file's own classifier
     * over `modules/` with an empty WordPress list and reading every name it reported; each was
     * confirmed to be core's by finding it in WordPress's own source tree rather than by
     * recognising the prefix. `update_menu_item_cache()` and `wp_setup_nav_menu_item()` are the
     * two that a prefix rule would have argued about, and both are core
     * (`wp-includes/nav-menu.php`).
     */
    private const PLATFORM_FUNCTIONS = [
        'add_action',
        'add_filter',
        'current_user_can',
        'esc_url_raw',
        'get_nav_menu_locations',
        'get_object_taxonomies',
        'get_option',
        'get_post',
        'get_post_meta',
        'get_post_types',
        'get_posts',
        'get_registered_nav_menus',
        'get_taxonomies',
        'get_term',
        'get_term_meta',
        'get_user_meta',
        'get_userdata',
        'is_post_type_viewable',
        'is_taxonomy_viewable',
        'is_wp_error',
        'post_password_required',
        'post_type_exists',
        'remove_filter',
        'sanitize_key',
        'taxonomy_exists',
        'update_menu_item_cache',
        'update_post_meta',
        'wp_count_posts',
        'wp_count_terms',
        'wp_delete_post',
        'wp_get_nav_menus',
        'wp_get_object_terms',
        'wp_is_block_theme',
        'wp_setup_nav_menu_item',
        'wp_slash',
        'wp_specialchars_decode',
        'wp_update_nav_menu_item',
        'wp_update_post',
    ];

    /** The WordPress classes the modules name, in any position. */
    private const PLATFORM_CLASSES = ['Walker', 'WP_Error', 'WP_Post', 'WP_Term', 'WP_User'];

    /**
     * Methods the modules call on a WordPress object.
     *
     * THIS BUCKET IS THE WEAKEST ONE AND SAYS SO. A method name carries no hint of whose object
     * it is on, so `->walk()` on core's `Walker` and `->get_disabled_layouts()` on ACF's field
     * type arrive identically. The gate therefore holds the two apart by LIST rather than by
     * derivation, and errs the safe way: a method not named here must be declared in a face, so
     * the failure is a demand for a declaration rather than a silent pass.
     */
    private const PLATFORM_METHODS = ['walk'];

    /** WordPress constants the modules read. Empty today, and a real bucket all the same. */
    private const PLATFORM_CONSTANTS = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        WordPressStubs::loadPlugin();
    }

    /* ------------------------------------------------------------------ the gate */

    /**
     * THE ASSERTION THE SPRINT EXISTS FOR: every module's face declares every foreign symbol
     * that module actually calls.
     *
     * @group sprint-acf-read
     */
    public function testEveryForeignSymbolAModuleCallsIsDeclaredInItsFace(): void
    {
        $unclaimed = [];

        foreach (\wpmcp_module_manifest() as $slug => $relative) {
            $path = \WPMCP_PLUGIN_DIR . '/' . $relative;

            self::assertFileExists($path, "The manifest names {$relative} and the plugin does not have it.");

            foreach (self::undeclared((string) file_get_contents($path), self::declaredFace((string) $slug)) as $report) {
                $unclaimed[] = $relative . ' ' . $report;
            }
        }

        self::assertSame(
            [],
            $unclaimed,
            "A module reaches for a symbol of another plugin that its API face does not declare:\n"
            . implode("\n", $unclaimed)
            . "\n\nEither add it to the module's wpmcp_register_module_face() declaration - which"
            . ' also makes the module refuse to register on a site that does not have it - or, if'
            . " it is WordPress's own, add it to this file's PLATFORM_* list beside the others."
            . ' A guard that does not name what the code calls is not a guard.'
        );
    }

    /**
     * AND THE ASSERTION ABOVE CAN FAIL. A synthetic module that calls, type-hints, reads and
     * invokes four different flavours of undeclared foreign symbol is reported, one report per
     * symbol - so the empty set above is an answer and not an artefact of a classifier that
     * finds nothing.
     *
     * @group sprint-acf-read
     */
    public function testAnUndeclaredForeignSymbolInEveryPositionIsReported(): void
    {
        $source = <<<'PHP'
        <?php
        /** A docblock naming other_plugin_function() and Other_Plugin_Class in prose. */
        function wpmcp_pretend_tools($x) {
            $s = 'other_plugin_in_a_string()';
            // other_plugin_in_a_comment()
            $thing = new Other_Plugin_Class();
            $v = $thing->other_plugin_method();
            $w = Other_Plugin_Static::make();
            return other_plugin_function($x) . OTHER_PLUGIN_CONSTANT . get_post($x) . $v . $w;
        }
        function wpmcp_pretend_hint(Other_Plugin_Hinted $h): Other_Plugin_Returned { return $h; }
        PHP;

        $reported = self::undeclared($source, []);
        $names    = array_map(
            static fn (string $r): string => (string) preg_replace('/ .*/', '', $r),
            $reported
        );

        sort($names);

        self::assertSame(
            [
                'OTHER_PLUGIN_CONSTANT',
                'Other_Plugin_Class',
                'Other_Plugin_Hinted',
                'Other_Plugin_Returned',
                'Other_Plugin_Static',
                'other_plugin_function',
                'other_plugin_method',
            ],
            $names,
            'The classifier missed a position, so a module could reach another plugin through it'
            . ' with the gate green. Got: ' . implode(', ', $names)
        );

        // A NAME IN PROSE OR A STRING IS NOT A REFERENCE, or a module docblock that mentioned
        // ACF would have to be rewritten to keep this file green.
        foreach (['other_plugin_in_a_string', 'other_plugin_in_a_comment'] as $notAReference) {
            self::assertStringNotContainsString(
                $notAReference,
                implode(' ', $reported),
                "{$notAReference} is named in a string or a comment, not called."
            );
        }

        // WordPress's own is not reported, and neither is the module's own declaration.
        self::assertStringNotContainsString('get_post', implode(' ', $reported));
        self::assertStringNotContainsString('wpmcp_pretend', implode(' ', $reported));

        // A FOREIGN METHOD WHOSE NAME COLLIDES WITH A PLUGIN FUNCTION IS STILL FOREIGN, and this
        // fixture is the one that was missing. `wpmcp_module_face_missing` is a plugin FUNCTION,
        // never a method of anything - but the exemption list was filled from every `function name(`
        // in the plugin, free functions included, so `$thing->wpmcp_module_face_missing()` was
        // excused, and so was every other function name in the plugin. An exemption that wide means
        // the guard CAN fall behind the code it guards, which is the one thing D30 exists to
        // prevent. `get_disabled_layouts` is here beside it because it is ACF's - a name the face
        // DOES declare for the ACF module, and which must still be reported for a module that does
        // not declare it.
        self::assertSame(
            ['wpmcp_module_face_missing (method, line 2)', 'get_disabled_layouts (method, line 2)'],
            self::undeclared(
                "<?php\n" . '$x = $thing->wpmcp_module_face_missing() . $other->get_disabled_layouts();',
                []
            ),
            'A foreign method was excused because this plugin happens to declare a FUNCTION of the'
            . " same name. The exemption must be the methods of the plugin's own CLASSES and"
            . ' nothing else.'
        );

        // AND A METHOD OF THIS PLUGIN'S OWN CLASS IS STILL EXCUSED, or a module calling its own
        // collector would be told to declare a dependency on itself.
        self::assertSame(
            [],
            self::undeclared('<?php $w = new WpMcp_Menu_Collector(); $w->start_el($a, $b, $c);', []),
            "A method of this plugin's own class was reported as another plugin's."
        );

        // THE RESIDUAL LIMIT, HELD BY AN ASSERTION RATHER THAN DESCRIBED. A method name cannot say whose object
        // it is on, so "declared by one of our own classes" is the best excuse rule available - and
        // it means every name below is a name a module could call on a FOREIGN object with no
        // declaration. Thirteen names is a limit a reviewer can hold in their head; the five hundred
        // free-function names it used to be were not. Adding a method to a plugin class WIDENS this
        // gate, so it goes red here and the author has to notice.
        //
        // SPRINT VALIDATOR MOVED IT BY ONE, AND THE AUTHOR NOTICED HERE. `aslist` and `length` went
        // when SchemaValidator stopped implementing `enum` and the length keywords; `askcore`,
        // `dialect` and `unknownkeyword` arrived with the delegation and the registry's
        // unknown-keyword refusal. Net +1, and every new name is distinctive enough that a module
        // calling it on a foreign object is improbable - which is the only thing this list is
        // trading away.
        self::assertSame(
            [
                'askcore', 'asmap', 'check', 'checkobject', 'dialect', 'escape', 'failure',
                'matches', 'start_el', 'typename', 'unknownkeyword', 'validate', 'validatearguments',
            ],
            self::sorted(array_keys(self::pluginDeclarations()['methods'])),
            'The set of method names the face gate excuses has changed. Every name in it is one a'
            . " module may call on ANOTHER plugin's object without declaring it, so growing the list"
            . ' is a deliberate widening of this gate and not a detail.'
        );

        // AND A NAMED ARGUMENT IS NOT A CONSTANT READ, which it was until this assertion existed:
        // `str_contains(haystack: $a, needle: $b)` would have been reported as two undeclared
        // constants, and the first module to use named arguments would have had to weaken the gate
        // to get a green run - the failure mode this whole file is written against.
        self::assertSame(
            [],
            self::undeclared('<?php function wpmcp_named($a, $b) { return str_contains(haystack: $a, needle: $b); }', []),
            'A named argument was reported as an undeclared symbol.'
        );

        // AND THE SAME SOURCE GOES QUIET once the face declares them - which is the half that
        // proves the gate reads the declaration rather than ignoring it.
        self::assertSame(
            [],
            self::undeclared($source, [
                'other_plugin_function', 'other_plugin_method', 'OTHER_PLUGIN_CONSTANT',
                'Other_Plugin_Class', 'Other_Plugin_Static', 'Other_Plugin_Hinted',
                'Other_Plugin_Returned',
            ]),
            'The face was declared and the symbols were still reported.'
        );
    }

    /**
     * A MODULE THAT IS NOT SERVING TOOLS CAN SAY WHY (D30, point 4).
     *
     * Every manifest entry is either registered, or has a non-empty list of missing symbols. The
     * third state - present on disk, not registered, and nothing to point at - is the vacuous
     * silence this project keeps catching: an administrator seeing no tools from a module would
     * have no way to tell it from a broken plugin.
     *
     * In the unit tier no other plugin is loaded, so this runs in the interesting direction
     * without any arrangement: a module with a face is UNREGISTERED here, and the assertion is
     * that it accounts for itself.
     *
     * @group sprint-acf-read
     */
    public function testEveryModuleIsEitherRegisteredOrCanSayWhatItIsMissing(): void
    {
        $silent = [];
        $status = \wpmcp_module_status();

        self::assertSame(
            array_keys(\wpmcp_module_manifest()),
            array_keys($status),
            'wpmcp_module_status() does not report on every module the manifest names.'
        );

        foreach ($status as $slug => $row) {
            self::assertTrue($row['present'], "The manifest names {$slug} and its file is not there.");

            if (!$row['registered'] && $row['missing'] === []) {
                $silent[] = $slug;
            }

            if ($row['registered']) {
                self::assertSame(
                    [],
                    $row['missing'],
                    "{$slug} registered while its own face reports missing symbols, so the guard"
                    . ' is not the thing that decided.'
                );
            }
        }

        self::assertSame(
            [],
            $silent,
            'These modules are on disk, are not serving tools, and cannot say why: '
            . implode(', ', $silent)
            . '. An administrator then cannot tell "this feature needs a plugin you do not have"'
            . ' from "wp-mcp is broken". Declare the face the module needs.'
        );
    }

    /* ------------------------------------------------------- the check behind the gate */

    /**
     * THE FLOORS ARE DETECTED, NOT ASSUMED (item 7): the seam's own check reports a missing
     * function, class, constant and METHOD, and reports nothing when all four are present.
     *
     * THE METHOD CASE IS THE ONE WORTH THE FIXTURE. It is the only kind that needs an OBJECT
     * rather than a name, so it is the only kind whose check could be silently vacuous - a probe
     * returning null must report the METHOD as missing, not shrug. Both shapes are here: a probe
     * that hands back a real object, and one that hands back nothing.
     *
     * @group sprint-acf-read
     */
    public function testTheFaceCheckFindsEachKindOfMissingSymbol(): void
    {
        $present = \wpmcp_module_face_part([
            'functions' => ['strlen'],
            'classes'   => ['ArrayObject'],
            'constants' => ['PHP_INT_MAX'],
            'methods'   => [['probe' => static fn () => new \ArrayObject(), 'names' => ['count']]],
        ]);

        self::assertSame(
            [],
            \wpmcp_module_face_part_missing($present),
            'A face whose every symbol exists was reported as incomplete, so this check refuses'
            . ' faces that are fine and no module could ever register.'
        );

        $absent = \wpmcp_module_face_part([
            'functions' => ['wpmcp_no_such_function_4f1a'],
            'classes'   => ['Wpmcp_No_Such_Class_4f1a'],
            'constants' => ['WPMCP_NO_SUCH_CONSTANT_4F1A'],
            'methods'   => [['probe' => static fn () => new \ArrayObject(), 'names' => ['noSuchMethod4f1a']]],
        ]);

        self::assertSame(
            [
                'wpmcp_no_such_function_4f1a()',
                'class Wpmcp_No_Such_Class_4f1a',
                'constant WPMCP_NO_SUCH_CONSTANT_4F1A',
                '->noSuchMethod4f1a()',
            ],
            \wpmcp_module_face_part_missing($absent),
            'A missing symbol of some kind is not reported, so a module could register without it'
            . ' and fatal on the first call.'
        );

        // A MALFORMED METHOD ENTRY IS REPORTED, NOT IGNORED. `'methods' => ['get_disabled_layouts']`
        // is the shape a future author will write - a flat list where the group shape was meant -
        // and an earlier version of this check silently verified NOTHING for it, so the module
        // registered with the methods it needs unchecked. Absence of a declaration is not a
        // declaration of safety, which is the rule the tool registry already applies to `write`.
        foreach ([['get_disabled_layouts'], [['probe' => 'strlen']], [['names' => []]], ['nonsense']] as $malformed) {
            self::assertSame(
                ['a malformed method declaration in this face'],
                \wpmcp_module_face_part_missing(\wpmcp_module_face_part(['methods' => $malformed])),
                'A malformed method declaration checked nothing and reported nothing: '
                . json_encode($malformed)
            );
        }

        // A PROBE THAT CANNOT REACH ITS OBJECT REPORTS THE METHOD, which is what an operator can
        // look up. Three ways to fail to reach it, one answer.
        foreach ([static fn () => null, static fn () => false, 'wpmcp_no_such_probe_4f1a'] as $probe) {
            self::assertSame(
                ['->get_disabled_layouts()'],
                \wpmcp_module_face_part_missing(\wpmcp_module_face_part([
                    'methods' => [['probe' => $probe, 'names' => ['get_disabled_layouts']]],
                ])),
                'A method whose probe returned nothing was not reported as missing.'
            );
        }
    }

    /**
     * An OPTIONAL capability is detected and reported, and never demanded - which is what makes
     * "values work, layout metadata does not" expressible instead of "no tools at all"
     * (item 7: do not invent a middle state, and do not let either gap be silent).
     *
     * @group sprint-acf-read
     */
    public function testAnOptionalCapabilityIsReportedAndNeverDemanded(): void
    {
        \wpmcp_register_module_face('wpmcp-face-fixture', static fn (): array => [
            'required' => ['functions' => ['strlen']],
            'optional' => [
                'here'    => ['functions' => ['strtolower']],
                'not_here' => ['functions' => ['wpmcp_no_such_function_4f1a']],
            ],
        ]);

        self::assertSame(
            [],
            \wpmcp_module_face_missing('wpmcp-face-fixture'),
            'A missing OPTIONAL symbol was reported as a missing required one, so a module would'
            . ' refuse to register over a feature it can do without.'
        );
        self::assertSame(
            ['here' => true, 'not_here' => false],
            \wpmcp_module_face_capabilities('wpmcp-face-fixture'),
            'The optional half of a face is not reported capability by capability, so the tool'
            . ' cannot say which of its guarantees this site provides.'
        );

        // FIRST DECLARATION OF A SLUG WINS, the same rule the provider registry follows - so a
        // later caller cannot replace a module's face with a weaker one.
        \wpmcp_register_module_face('wpmcp-face-fixture', static fn (): array => [
            'required' => ['functions' => ['wpmcp_no_such_function_4f1a']],
        ]);

        self::assertSame(
            [],
            \wpmcp_module_face_missing('wpmcp-face-fixture'),
            'A second declaration of a slug replaced the first.'
        );
    }

    /**
     * A slug that declared nothing has an empty face, and a face that declares nonsense is
     * normalised rather than fatal - because this runs at PLUGIN LOAD on somebody's site.
     *
     * @group sprint-acf-read
     */
    public function testAnUndeclaredOrMalformedFaceIsEmptyRatherThanFatal(): void
    {
        self::assertSame(
            ['required' => ['functions' => [], 'classes' => [], 'constants' => [], 'methods' => []], 'optional' => []],
            \wpmcp_module_face('wpmcp-no-such-module-4f1a')
        );
        self::assertSame([], \wpmcp_module_face_missing('wpmcp-no-such-module-4f1a'));

        \wpmcp_register_module_face('wpmcp-face-junk', static fn () => 'not an array');

        self::assertSame([], \wpmcp_module_face_missing('wpmcp-face-junk'));
        self::assertSame([], \wpmcp_module_face_capabilities('wpmcp-face-junk'));

        \wpmcp_register_module_face('wpmcp-face-notcallable', 'wpmcp_no_such_face_provider_4f1a');

        self::assertSame([], \wpmcp_module_face_missing('wpmcp-face-notcallable'));
    }

    /* ------------------------------------------------------------------ the classifier */

    /** @param list<string> $names @return list<string> */
    private static function lowered(array $names): array
    {
        return array_map('strtolower', $names);
    }

    /** @param list<string> $names @return list<string> */
    private static function sorted(array $names): array
    {
        sort($names);

        return $names;
    }

    /**
     * Every foreign symbol in $source that $declared does not name, as a readable report.
     *
     * @param list<string> $declared every name the module's face declares, required or optional
     * @return list<string>
     */
    private static function undeclared(string $source, array $declared): array
    {
        $face   = array_flip(array_map('strtolower', $declared));
        $mine   = self::pluginDeclarations();
        $php    = self::phpDeclarations();
        $report = [];

        foreach (PhpSymbols::scan($source) as $symbol) {
            $lower = $symbol['lower'];

            if (isset($face[$lower])) {
                continue;
            }

            switch ($symbol['kind']) {
                case 'call':
                    $known = isset($mine['functions'][$lower]) || isset($php['functions'][$lower])
                        || in_array($lower, self::lowered(self::PLATFORM_FUNCTIONS), true);
                    break;

                case 'class':
                case 'type':
                    $known = isset($mine['classes'][$lower]) || isset($php['classes'][$lower])
                        || in_array($lower, self::lowered(self::PLATFORM_CLASSES), true);
                    break;

                case 'constant':
                    $known = isset($mine['constants'][$lower]) || isset($php['constants'][$lower])
                        || in_array($lower, self::lowered(self::PLATFORM_CONSTANTS), true);
                    break;

                case 'method':
                    // A METHOD IS NEVER "THIS PLUGIN'S" BY NAME ALONE. Two classes may declare
                    // the same method, so the name cannot say whose object it is on - which is
                    // why the platform list is explicit and why anything outside it must be
                    // declared. A method this plugin's own classes declare is excused, because
                    // a module calling its own collector is not reaching for another plugin.
                    $known = in_array($lower, self::lowered(self::PLATFORM_METHODS), true)
                        || isset($mine['methods'][$lower]);
                    break;

                case 'static_call':
                    // `Foo::bar()` ALREADY NAMED ITS CLASS in the source, and that name is
                    // classified `class` and checked above. Reporting the member as well would
                    // demand two declarations for one dependency and say the same thing twice.
                    $known = true;
                    break;

                default:
                    // A declaration of the module's own. Nothing foreign about it.
                    $known = true;
            }

            if (!$known) {
                $report[] = $symbol['name'] . ' (' . $symbol['kind'] . ', line ' . $symbol['line'] . ')';
            }
        }

        return $report;
    }

    /**
     * What this plugin declares: functions, classes and `define()`d constants, plus the methods
     * of the classes it declares.
     *
     * @return array{functions: array<string, true>, classes: array<string, true>, constants: array<string, true>, methods: array<string, true>}
     */
    private static function pluginDeclarations(): array
    {
        static $map = null;

        if ($map !== null) {
            return $map;
        }

        $map = ['functions' => [], 'classes' => [], 'constants' => [], 'methods' => []];

        $files = array_merge(
            (array) glob(\WPMCP_PLUGIN_DIR . '/*.php'),
            (array) glob(\WPMCP_PLUGIN_DIR . '/modules/*.php'),
            (array) glob(\WPMCP_PLUGIN_DIR . '/src/*.php')
        );

        foreach ($files as $path) {
            $source = (string) file_get_contents((string) $path);

            foreach (PhpSymbols::of($source, 'function_declaration') as $lower => $name) {
                $map['functions'][$lower] = true;
            }
            foreach (PhpSymbols::of($source, 'class_declaration') as $lower => $name) {
                $map['classes'][$lower] = true;
            }
            foreach (PhpSymbols::of($source, 'const_declaration') as $lower => $name) {
                $map['constants'][$lower] = true;
            }
            foreach (PhpSymbols::defineNames($source) as $lower) {
                $map['constants'][$lower] = true;
            }
            // A method DECLARED BY ONE OF THIS PLUGIN'S OWN CLASSES - a `function name(` INSIDE a
            // class body, which is what PhpSymbols::methodDeclarations() counts by brace depth.
            //
            // THIS WAS A REGEX OVER EVERY `function name(` IN THE FILE, free functions included,
            // AND IT WAS THE WIDEST HOLE IN THIS GATE. The plugin declares hundreds of functions,
            // so the excuse set was every one of their names: a module writing `$acf->validate()`,
            // `->check()`, `->escape()`, `->matches()`, `->latest()` or `->wpmcp_anything()` passed
            // with no declaration at all. methodDeclarations() was written for exactly this in the
            // same commit and was then never called - the helper was right and nothing used it,
            // which is the two-things-agreeing-by-construction failure with one of them absent.
            foreach (PhpSymbols::methodDeclarations($source) as $lower) {
                $map['methods'][$lower] = true;
            }
        }

        // `src/` is one namespaced class per file by the autoloader's own rule.
        foreach ((array) glob(\WPMCP_PLUGIN_DIR . '/src/*.php') as $class) {
            $map['classes'][strtolower('WpMcp\\' . basename((string) $class, '.php'))] = true;
        }

        return $map;
    }

    /**
     * What PHP itself declares, read from the runtime rather than listed - so a PHP upgrade
     * cannot make this test wrong in either direction.
     *
     * @return array{functions: array<string, true>, classes: array<string, true>, constants: array<string, true>}
     */
    private static function phpDeclarations(): array
    {
        static $map = null;

        if ($map !== null) {
            return $map;
        }

        $map = ['functions' => [], 'classes' => [], 'constants' => []];

        foreach (get_defined_functions()['internal'] as $name) {
            $map['functions'][strtolower($name)] = true;
        }

        foreach (array_merge(get_declared_classes(), get_declared_interfaces()) as $name) {
            $map['classes'][strtolower($name)] = true;
        }

        foreach (get_defined_constants(true) as $group => $constants) {
            if ($group === 'user') {
                continue;
            }

            foreach (array_keys($constants) as $name) {
                $map['constants'][strtolower($name)] = true;
            }
        }

        return $map;
    }

    /**
     * Every name one module's face declares, required and optional together.
     *
     * OPTIONAL COUNTS AS DECLARED, because the module DOES call it - behind a detection, which
     * is the point of the optional half. The registration gate treats the two differently; the
     * honesty check does not.
     *
     * @return list<string>
     */
    private static function declaredFace(string $slug): array
    {
        $face  = \wpmcp_module_face($slug);
        $names = [];

        foreach (array_merge([$face['required']], array_values($face['optional'])) as $part) {
            foreach (array_merge($part['functions'], $part['classes'], $part['constants']) as $name) {
                $names[] = $name;
            }

            foreach ($part['methods'] as $group) {
                foreach ((array) ($group['names'] ?? []) as $name) {
                    $names[] = (string) $name;
                }

                // THE PROBE ITSELF IS A CALL THE MODULE MAKES, so naming it in the face is what
                // keeps the probe's own dependency honest - ACF's is
                // acf_get_field_type('flexible_content'), which is a symbol that can be absent.
                if (is_string($group['probe'] ?? null)) {
                    $names[] = (string) $group['probe'];
                }
            }
        }

        return $names;
    }
}
