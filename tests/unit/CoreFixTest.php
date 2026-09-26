<?php
/**
 * SPRINT CORE-FIX: six defects that are each the INSTANCE of a class, and the class sweep beside it.
 *
 * WHAT MAKES THIS ONE FILE RATHER THAN SIX. Every item here has the same shape - this plugin
 * restates a decision the platform already makes, and the restatement drifted - so the assertions
 * that matter are the ones that hold the restatement against the platform's own rule. Splitting
 * them by file would hide that they are one finding, and D32 is the finding.
 *
 * THE FOUR SWEEPS ARE TOKENISED, NOT GREPPED, and that is load-bearing: `json_encode` appears in
 * five docblocks of this plugin and in none of its code but one, so a text search over the source
 * answers about prose. `PhpSymbols::scan()` reads the PHP tokens, so a comment cannot fail a sweep
 * and a comment cannot hide a call either.
 *
 * NOTHING HERE SKIPS, WHICH IS THE GATE-GROUP RULE. No test in this file needs a network install,
 * a database, ACF or a site: the multisite branch is executed against a stub that is core's own
 * one-line body, which is the same technique tests/Support/wp-runtime-stubs.php already uses for
 * `wp_is_file_mod_allowed`. Item 7's ACF halves are in tests/unit/AcfCoreFixTest.php (no ACF
 * needed) and tests/integration/AcfValueReadTest.php (`acf-data`, no gate group).
 *
 * @group sprint-core-fix
 */

declare(strict_types=1);

namespace WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WpMcp\SchemaValidator;
use WpMcp\Tests\Support\PhpSymbols;
use WpMcp\Tests\Support\RepoFile;
use WpMcp\Tests\Support\WordPressRuntime;
use WpMcp\Tests\Support\WordPressStubs;

final class CoreFixTest extends TestCase
{
    /** Every file of shipped PHP, which is what a class sweep has to cover to be one. */
    private const SHIPPED = [
        'wp-mcp.php',
        'endpoint.php',
        'tools.php',
        'admin.php',
        'trace.php',
        'uninstall.php',
        'modules.php',
        'modules/acf.php',
        'modules/discovery.php',
        'modules/menus.php',
        'src/ProtocolVersion.php',
        'src/SchemaValidator.php',
    ];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        WordPressStubs::loadPlugin();
    }

    protected function setUp(): void
    {
        parent::setUp();

        WordPressRuntime::install();
    }

    /* ==============================================================================
     * ITEM 1. A capability gate we hand-copied from core must match core's FULL branch set.
     * ============================================================================ */

    /**
     * On a NETWORK, a caller who is not a super admin does not see the code tools listed.
     *
     * WHY THIS IS THE DEFECT AND NOT A DETAIL. `map_meta_cap`'s `edit_themes` case has THREE deny
     * branches (wp-includes/capabilities.php:607-618) and this plugin's copy had two, so on every
     * multisite install a Site Administrator - who holds `edit_themes` in their role - was shown
     * all six code tools in `tools/list` and refused every one of them at call time by
     * `current_user_can`. "Advertised and refused" is the exact state
     * wpmcp_code_constants_forbid() was split out to prevent, measured on seosemia.net and named
     * in that function's own docblock, so the copy reproduced the defect it documents fixing.
     *
     * PROVED WITHOUT A MULTISITE INSTALL, AND NOT BY READING THE SOURCE. `is_multisite()` and
     * `is_super_admin()` are stubbed with core's own bodies (tests/Support/wp-runtime-stubs.php),
     * so all four combinations of the two are EXECUTED. What a live network would add is
     * confidence that core's own branch is still there - which is a fact about WordPress, not
     * about this plugin, and the integration tier has no network either.
     *
     * @group sprint-core-fix
     */
    public function testTheCodeListingFollowsCoresThirdDenyBranchOnANetwork(): void
    {
        WordPressRuntime::logInAs(7, 'site-admin');

        self::assertNull(
            wpmcp_code_constants_forbid(),
            'Nothing forbids theme editing on a single site in the default stub state, so the'
            . ' assertions below could not tell the network branch from the other two.'
        );

        // A network, and this caller is NOT one of its super admins.
        WordPressRuntime::setMultisite(true, [1]);

        $refusal = wpmcp_code_constants_forbid();

        self::assertNotNull(
            $refusal,
            'On a network install a caller who is not a super admin still passes the LISTING gate,'
            . ' so all six code tools are advertised to somebody core refuses every one of them'
            . ' to. That is the defect this function exists to prevent.'
        );
        self::assertSame('wpmcp_forbidden', $refusal->get_error_code());
        self::assertStringContainsString(
            'multisite',
            $refusal->get_error_message(),
            'The refusal does not say WHICH of the three switched theme editing off, so an'
            . ' operator cannot tell a network rule from a constant they set. Got: '
            . $refusal->get_error_message()
        );

        // AND THE BRANCH IS BOTH HALVES. A super admin on the same network is not refused, and a
        // non-super-admin on a single site is not refused - without these two the assertion above
        // would also pass on a function that simply refused everybody.
        WordPressRuntime::setMultisite(true, [7]);
        self::assertNull(
            wpmcp_code_constants_forbid(),
            'A NETWORK ADMINISTRATOR is refused the listing. Core allows edit_themes for a super'
            . ' admin on a network, so this gate is now stricter than the capability it copies and'
            . ' the tools are hidden from the one person who may use them.'
        );

        WordPressRuntime::setMultisite(false, []);
        self::assertNull(
            wpmcp_code_constants_forbid(),
            'The gate refuses on a SINGLE SITE, so `is_multisite()` is not being asked and every'
            . ' ordinary install just lost its code tools.'
        );
    }

    /**
     * THE LISTING ASKS `map_meta_cap()` AND NO LONGER RESTATES CORE'S BRANCH SET (round 2, S2).
     *
     * ROUND 1 FIXED THE COPY BY COMPLETING IT, WHICH LEFT A COPY. D32's argument is not "our branch
     * set is wrong", it is "a branch set of our own can go wrong", and the mechanism that removes it
     * has a name: `map_meta_cap('edit_themes', $user_id)` (wp-includes/capabilities.php:45) runs
     * core's whole `edit_themes` case (`:607-618`) and answers `['do_not_allow']` on any of its three
     * site-level branches, WITHOUT testing whether the user holds the capability - which is exactly
     * the question the listing asks and the reason `current_user_can()` was rejected.
     *
     * HOW A DELEGATION IS TOLD FROM A COPY IN THIS TIER, and it is the whole design of this test.
     * The runtime stub for `map_meta_cap` carries core's own three branches, so on every ordinary
     * state a gate that asks and a gate that restates answer identically - a behavioural test would
     * be green either way. So the answer is FORCED to something the branches would not produce: a
     * plugin denying `edit_themes` on core's own `map_meta_cap` FILTER, on a single site with no
     * constant set. A gate that reads the constants concludes "nothing forbids this" and lists six
     * tools that `current_user_can()` then refuses - the advertised-and-refused defect, arriving
     * through the one route the hand-copy could never see.
     *
     * @group sprint-core-fix
     */
    public function testTheCodeListingFollowsAPluginsCapabilityFilterRatherThanOurCopyOfCore(): void
    {
        WordPressRuntime::logInAs(7, 'an-admin');

        self::assertNull(
            wpmcp_code_constants_forbid(),
            'Nothing forbids theme editing in the default stub state - a single site, no constants,'
            . ' no filter - so the assertion below could not tell the filter from the constants.'
        );

        // A hardening plugin on core's own map_meta_cap filter. No constant is set and this is not
        // a network, so NONE of the three branches a hand-copy would test is true.
        WordPressRuntime::setMetaCap('edit_themes', ['do_not_allow']);

        $refusal = wpmcp_code_constants_forbid();

        self::assertNotNull(
            $refusal,
            'A plugin that denies edit_themes on core\'s map_meta_cap filter does not stop the code'
            . ' tools being LISTED, so this gate is reading its own copy of core\'s branches instead'
            . ' of asking core. Every such site advertises six tools and refuses all six.'
        );
        self::assertSame('wpmcp_forbidden', $refusal->get_error_code());
        self::assertStringContainsString(
            'edit_themes',
            $refusal->get_error_message(),
            'The refusal does not say that something denied the capability, so an operator whose'
            . ' hardening plugin did this has nothing at all to go on. Got: '
            . $refusal->get_error_message()
        );

        // AND THE ANSWER IS STILL CORE'S WHEN CORE ALLOWS IT: a filter that returns the capability
        // does not refuse. Without this, a gate that refused whenever it saw a filtered answer -
        // or simply always - would pass the assertion above.
        WordPressRuntime::setMetaCap('edit_themes', ['edit_themes']);
        self::assertNull(
            wpmcp_code_constants_forbid(),
            'The gate refuses on a site where core answers that theme editing is allowed, so it is'
            . ' inverting map_meta_cap rather than reading it.'
        );
    }

    /**
     * And the REASON is still ours, naming which of the site's refusals it was.
     *
     * `map_meta_cap()` answers `do_not_allow` and does not say why, and the listing docblock's whole
     * argument is that an operator who set DISALLOW_FILE_EDIT deliberately and one whose host set
     * DISALLOW_FILE_MODS need different sentences. So the branches survive as an EXPLANATION, where
     * a drift costs a slightly wrong reason rather than a hidden or falsely advertised tool.
     *
     * @group sprint-core-fix
     */
    public function testTheRefusalStillNamesWhichOfTheSitesRefusalsItWas(): void
    {
        WordPressRuntime::logInAs(7, 'an-admin');

        WordPressRuntime::addFilter('file_mod_allowed', static fn ($allowed, $context = '') => false);

        self::assertStringContainsString(
            'file_mod_allowed',
            wpmcp_code_forbidden_reason(),
            'A site whose hardening plugin answers false from file_mod_allowed is not told which of'
            . ' the three switched theme editing off.'
        );

        WordPressRuntime::install();
        WordPressRuntime::logInAs(7, 'an-admin');
        WordPressRuntime::setMultisite(true, [1]);

        self::assertStringContainsString(
            'multisite',
            wpmcp_code_forbidden_reason(),
            'A network install is not named as the reason, so a Site Administrator reading the'
            . ' refusal cannot tell it from a constant somebody set.'
        );

        // AND THE FALL-THROUGH IS NOT SILENT. Reached when a map_meta_cap filter denied the
        // capability for a reason of its own, which no branch here can name - but a refusal with no
        // reason at all is what sends an operator to a support forum.
        WordPressRuntime::install();
        WordPressRuntime::logInAs(7, 'an-admin');

        self::assertStringContainsString(
            'edit_themes',
            wpmcp_code_forbidden_reason(),
            'With nothing on this site forbidding theme editing, the reason function answers'
            . " something that does not name the capability - so a plugin's own denial arrives as a"
            . ' refusal with no cause named.'
        );
    }

    /**
     * THE CLASS SWEEP. Both places this plugin reproduces a core file-modification deny decision
     * carry core's multisite branch, and the second one is in the same file as the first.
     *
     * `list-plugins`' `auto_update` is core's `update_plugins` decision, restated rather than
     * asked for - deliberately, because the tool's contract is that no update or auto-update
     * filter runs, and `current_user_can('update_plugins')` goes through
     * `wp_is_file_mod_allowed()`, whose `file_mod_allowed` filter is exactly such a hook. But
     * core's `update_plugins` case is THREE branches too (capabilities.php:619-641) and this copy
     * had one, so a Site Administrator on a network was told true or false where core says "you
     * cannot update plugins at all", which is what `auto_update: null` means.
     *
     * A SOURCE ASSERTION FOR THE SECOND SITE, and the precedent is tests/unit/PlatformApiTest.php's
     * own: the value under test is built inside a tool closure from `wp_get_current_user()`'s
     * `allcaps`, and driving it in the unit tier would mean a WP_User double whose only job is to
     * carry a capability array - a stub for the sake of a test rather than for the sake of loading
     * the plugin. The DESCRIPTION half below is not a source assertion: it is the sentence the
     * caller reads, and it was false.
     *
     * @group sprint-core-fix
     */
    public function testEveryCopyOfCoresFileModDenialCarriesItsMultisiteBranch(): void
    {
        $tools = RepoFile::read('tools.php');

        self::assertStringContainsString(
            "\$fileMods = !(defined('DISALLOW_FILE_MODS') && DISALLOW_FILE_MODS)\n                && !(is_multisite() && !is_super_admin());",
            $tools,
            "list-plugins' auto_update gate is core's update_plugins decision with core's"
            . ' multisite branch missing, so on a network it answers true or false where core'
            . ' answers "you cannot update plugins". Same class as the code-listing gate, same'
            . ' file, one copy of core each.'
        );

        // AND THE SENTENCE THE CALLER READS SAYS SO. `auto_update: null` with no reason given is
        // a caller guessing, and the description named only two of the three reasons.
        $description = (string) WireSerializationTest::catalog()['list-plugins']['description'];

        self::assertStringContainsString(
            'network',
            $description,
            'list-plugins still tells the caller that auto_update is null only for a role without'
            . ' update_plugins or a site with DISALLOW_FILE_MODS. On a network it is also null for'
            . ' everyone who is not a network administrator, and a description that names two of'
            . ' three reasons is a caller reading a false one.'
        );
    }

    /* ==============================================================================
     * ITEM 2. Key normalisation where we restate a core scanner.
     * ============================================================================ */

    /**
     * THE SCAN'S KEYS ARE plugin_basename()'s, BECAUSE get_plugins()'s ARE.
     *
     * `get_plugins()` writes `$wp_plugins[ plugin_basename( $plugin_file ) ]`
     * (wp-admin/includes/plugin.php:346) and `activate_plugin()` writes `active_plugins` through
     * the same function - so the keys of this scan and the values it is compared against were
     * matched by both HAPPENING to be a raw readdir path rather than by construction.
     *
     * AND THE HONEST LIMIT OF THIS TEST, WRITTEN DOWN BECAUSE THE LEDGER OVERSTATED IT.
     * `analysis/79` TIER 1 item 4 says the omission means `active` "can read false for a plugin
     * that is genuinely active". On a relative path from `readdir()` that is NOT reachable:
     * `plugin_basename()` reduces to `wp_normalize_path()` plus `trim($file, '/')`, and a
     * directory entry cannot contain a slash, a backslash or a leading separator - so core's own
     * call is a no-op on the same input. The value of the fix is therefore that the two key sets
     * are identical BY CONSTRUCTION and stay identical if core's keying rule ever changes; there
     * is no live wrong answer to make go red. That makes this a source assertion of the kind
     * tests/unit/PlatformApiTest.php's docblock defends - "what can go red is the double parse
     * coming back, and the only place that fact lives is the file" - plus the executing test
     * below, which is the first coverage this scanner has had in the unit tier at all.
     *
     * @group sprint-core-fix
     */
    public function testTheInstalledPluginScanIsKeyedTheWayCoreKeysIt(): void
    {
        $tools = RepoFile::read('tools.php');

        self::assertStringContainsString(
            '$plugins[plugin_basename($file)] = $headers;',
            $tools,
            'wpmcp_scan_plugins() keys its array with a raw readdir path again. get_plugins() keys'
            . ' through plugin_basename() and activate_plugin() writes active_plugins through it,'
            . ' so a key built any other way is a key set that CAN diverge from the one this'
            . " tool's `active` flag compares it with."
        );
    }

    /**
     * And the scan still finds what core finds, over a real directory: a folder plugin, a
     * single-file plugin, a file with no Plugin Name header, and the sort order.
     *
     * WHY THIS IS HERE. The source assertion above cannot tell `plugin_basename($file)` from
     * `plugin_basename(basename($file))` or from anything else that compiles, and a fix that
     * broke every key would still pass it. This runs the function.
     *
     * THE DIRECTORY IS REAL, not a stub, because every decision the scanner makes is `opendir`,
     * `is_dir`, `is_readable` and a header read - none of which answers from a return value.
     * WP_PLUGIN_DIR is a constant, so it is defined once for the process and points at a
     * per-run directory this test builds under `.phpunit.cache/` and removes.
     *
     * UNDER `.phpunit.cache/` AND NOT `sys_get_temp_dir()`, for the reason
     * tests/unit/CodePathCanonicalTest.php measured and wrote down: on the Windows workstation
     * this suite is developed on, PHP's temp directory is `C:\Windows\TEMP`, where `mkdir()`
     * succeeds and `is_dir()` is true while `opendir()` and `scandir()` both return FALSE - so
     * every scan over it reported no plugins at all. Measured again here on the first run of this
     * test.
     *
     * @group sprint-core-fix
     */
    public function testTheInstalledPluginScanFindsWhatCoreFinds(): void
    {
        $root = self::pluginRoot();

        self::assertSame(
            [
                'alpha/alpha.php'   => ['Name' => 'Alpha Plugin', 'Version' => '2.0'],
                'single-file.php'   => ['Name' => 'Single File', 'Version' => '0.1'],
                'zeta/entry.php'    => ['Name' => 'Zeta Plugin', 'Version' => '1.4'],
            ],
            wpmcp_scan_plugins(),
            'The scan does not report the plugins in ' . $root . ' the way get_plugins() reports'
            . ' them: keyed `folder/file.php` or `file.php`, headers Name and Version only, a file'
            . ' with no Plugin Name header skipped, and sorted by Name with strnatcasecmp (core'
            . " uses _sort_uname_callback, which is that comparison). The order is Alpha, Single,"
            . ' Zeta - so a scan sorted by KEY rather than by name would fail here too.'
        );
    }

    /* ==============================================================================
     * ITEM 3. Every JSON encode in shipped code goes through wp_json_encode().
     * ============================================================================ */

    /**
     * WHERE THE UTF-8 ENUM CASE WENT, AND WHY IT COULD NOT STAY HERE (sprint VALIDATOR).
     *
     * This item's instance was `SchemaValidator::asList()` calling bare `json_encode()`, which
     * answers FALSE on JSON_ERROR_UTF8 - and `false` concatenates as `''`, so the one permitted
     * value the caller needed to see vanished from the message that lists permitted values. The
     * fix was `wp_json_encode()`, whose `_wp_json_sanity_check()` strips the bad bytes.
     *
     * `asList()` NO LONGER EXISTS. `enum` is core's now, and `rest_validate_enum()` builds the
     * same list with the same rule - `is_scalar($v) ? $v : wp_json_encode($v)`
     * (wp-includes/rest-api.php:2148-2151) - so the guarantee is unchanged and is no longer a
     * restatement, which is what D32 asks for. The test moved to
     * tests/integration/SchemaKeywordsTest::testAnEnumValueCannotVanishFromTheListOfPermittedValues(),
     * still carrying `@group sprint-core-fix`, because this tier has no WordPress and would
     * otherwise be asserting the behaviour of a recording double rather than core's.
     *
     * THE CLASS SWEEP BELOW IS UNTOUCHED AND IS WHAT STILL COVERS THIS FILE: no shipped PHP calls
     * `json_encode` directly, `src/SchemaValidator.php` included. The instance moved tiers; the
     * class did not move anywhere.
     */

    /**
     * THE CLASS SWEEP. No shipped file calls `json_encode` or `serialize` directly.
     *
     * `serialize` is in the sweep because it is the same class of decision one layer down: the
     * value that reaches `wp_options` is what `maybe_serialize()` decides it is, and a hand-rolled
     * `serialize()` beside it produces a row core's own reader would treat differently. There is
     * none today and this is what keeps it that way.
     *
     * TOKENISED. `json_encode` appears in five docblocks of this plugin, so a text search would
     * report prose as code; PhpSymbols::scan() reads tokens, so only a CALL counts - and only a
     * call counts as hidden, too.
     *
     * @group sprint-core-fix
     */
    public function testNoShippedFileEncodesJsonOrSerialisesWithoutThePlatform(): void
    {
        // THE SWEEP HAS A POSITIVE CONTROL, and it is here because the first version of this test
        // did not: it filtered on the kind `function`, which PhpSymbols uses for a DECLARATION,
        // so it scanned every shipped file, found nothing, and was green on the pre-fix code that
        // still had the bare call. A sweep that cannot find the thing it is looking for is a
        // silent position, which is precisely what this project keeps catching.
        self::assertSame(
            ['json_encode (line 2)'],
            self::callsTo("<?php\n\$x = json_encode(['a' => 1]);", ['json_encode', 'serialize']),
            'The sweep below cannot see a call to json_encode at all, so its empty result means'
            . ' nothing. Check the symbol kind PhpSymbols::scan() reports for a function call.'
        );

        $found = [];

        foreach (self::SHIPPED as $relative) {
            foreach (self::callsTo(RepoFile::read($relative), ['json_encode', 'serialize']) as $call) {
                $found[] = $relative . ' ' . $call;
            }
        }

        self::assertSame(
            [],
            $found,
            "Shipped code calls PHP's own encoder where WordPress has a wrapper:\n  "
            . implode("\n  ", $found)
            . "\n\nwp_json_encode() sanity-checks UTF-8 and bounds depth, so a value that is not"
            . ' valid UTF-8 comes back stripped instead of turning the whole encode into false;'
            . ' maybe_serialize() is what every core reader of an option expects to have written'
            . ' it. Use those.'
        );
    }

    /* ==============================================================================
     * ITEM 4. Every LIKE we build escapes its wildcards.
     * ============================================================================ */

    /**
     * THE TABLE PROBE ASKS FOR ITS OWN NAME, NOT FOR A PATTERN THAT MATCHES IT.
     *
     * `_` is a LIKE wildcard matching any one character and every table name this plugin has
     * carries three of them, so `SHOW TABLES LIKE 'wp_wpmcp_file_versions'` also matches
     * `wpXwpmcpXfile_versions`. `get_var()` returns the FIRST row of the FIRST column, the gate
     * compares it with `=== $table`, and a neighbour matched first therefore reads as "our table
     * is missing" - which sends wpmcp_install() through dbDelta on EVERY REQUEST, for ever, on a
     * site where nothing is wrong.
     *
     * HOW THE DATABASE IS SIMULATED, and it is the whole test: FakeWpdb answers get_var() BY
     * EXACT QUERY STRING, with a fallback for anything else. So the escaped question gets our
     * table's name and every other question gets the neighbour's - which is precisely the
     * database a same-shaped neighbour produces. Pre-fix the function asks the unescaped
     * question, gets the neighbour, and reports the table missing.
     *
     * @group sprint-core-fix
     */
    public function testTheTableProbeEscapesItsWildcardsAndSoCannotMatchANeighbour(): void
    {
        $wpdb  = WordPressRuntime::install();
        $table = wpmcp_versions_table();

        $wpdb->vars      = ["SHOW TABLES LIKE '" . addcslashes($table, '_%\\') . "'" => $table];
        $wpdb->defaultVar = $table . 'x_backup';

        self::assertTrue(
            wpmcp_versions_table_exists(),
            'The file-versions table is reported MISSING on a database that has it, because the'
            . ' probe asked with `_` left as a LIKE wildcard and a same-shaped neighbour answered'
            . ' first. The plugin would then run dbDelta on every request for ever. Queries asked:'
            . "\n  " . implode("\n  ", $wpdb->queries)
        );

        // AND THE PROBE STILL SAYS NO WHEN THE TABLE IS ABSENT - without this, a function that
        // returned true unconditionally would pass the assertion above.
        $wpdb             = WordPressRuntime::install();
        $wpdb->vars       = [];
        $wpdb->defaultVar = null;

        self::assertFalse(
            wpmcp_versions_table_exists(),
            'The probe reports the table present on a database that answered nothing, so the'
            . ' upgrade gate has stopped being one.'
        );
    }

    /**
     * THE CLASS SWEEP. Every `LIKE %s` in shipped SQL takes its value from `esc_like()`.
     *
     * THREE LIKE CLAUSES SHIP, IN ONE FILE, and the count is asserted below because "every LIKE
     * is escaped" is only a sweep if the list is the whole list. This docblock said "six, in two
     * files" and that was never true - MEASURED 2026-09-26: `grep -c 'LIKE %s'` was 3 in
     * wp-mcp.php and 2 in tools.php, five in two files, and the sweep-for-emptiness below passed
     * either way. The two in tools.php then went with sprint DELETIONS, which replaced
     * list-comments' hand-built clause with `WP_Comment_Query::get_search_sql()` - core's builder
     * calls `esc_like()` itself, so that clause is no longer one this plugin has to escape and
     * tests/integration/PlatformDeletionsTest.php proves the escaping still happens.
     *
     * @group sprint-core-fix
     */
    public function testEveryLikeInShippedSqlEscapesItsWildcards(): void
    {
        $unescaped = [];

        foreach (self::SHIPPED as $relative) {
            $lines = explode("\n", RepoFile::read($relative));

            // The tokeniser is no use here: a LIKE lives inside a string literal. So the unit of
            // the sweep is the LINE plus a window, which is what a $wpdb->prepare() call spanning
            // several lines actually looks like in this plugin - the widest is five lines. The
            // window is deliberately small: a LIKE whose escaping is ten lines away is a LIKE
            // whose escaping a reader cannot see either.
            foreach ($lines as $number => $line) {
                if (stripos($line, 'LIKE %s') === false) {
                    continue;
                }

                $window = implode("\n", array_slice($lines, max(0, $number - 5), 11));

                if (strpos($window, 'esc_like(') === false) {
                    $unescaped[] = $relative . ':' . ($number + 1) . ' ' . trim($line);
                }
            }
        }

        self::assertSame(
            [],
            $unescaped,
            "A LIKE pattern in shipped SQL carries its caller's wildcards:\n  "
            . implode("\n  ", $unescaped)
            . "\n\n`_` matches any one character and is in every table and column name this plugin"
            . ' has, so an unescaped pattern can match a name nobody asked about. $wpdb->esc_like()'
            . ' and prepare() are complementary: neither substitutes for the other.'
        );

        // AND THE SWEEP FOUND SOMETHING TO LOOK AT. A pattern that matched nothing would report
        // an empty list of failures for ever, which is the silent-position failure this project
        // keeps catching.
        // THE COUNT IS OVER EVERY SHIPPED FILE, not just the one that happens to hold them all
        // today. A count stated in prose beside an assertion that checks a narrower thing is how
        // "six, in two files" survived being false; this one makes the docblock's number the
        // number under test, so moving a LIKE between shipped files is red rather than invisible.
        $shipped = 0;

        foreach (self::SHIPPED as $relative) {
            $shipped += preg_match_all('/LIKE %s/', RepoFile::read($relative));
        }

        self::assertSame(
            3,
            $shipped,
            'The shipped plugin no longer has exactly the three schema probes the sweep above is'
            . ' about (it has ' . $shipped . '), so an empty failure list no longer means the'
            . ' sweep passed. If a LIKE was added, name it in the docblock and re-count.'
        );
        // list-comments' clause is THE ONE PLACE the pattern comes from a CALLER rather than from
        // a name this plugin chose, so it is the one that must not stop being escaped. It no
        // longer escapes anything itself: it hands the term to core's builder, which does. A
        // rewrite that went back to a hand-built clause has to come back through the sweep above,
        // and this assertion is what says which of the two shapes is shipping.
        self::assertStringContainsString(
            '->get_search_sql($search,',
            RepoFile::read('tools.php'),
            "list-comments' search no longer goes through WP_Comment_Query::get_search_sql(), so"
            . ' the esc_like() that clause relies on is not core\'s any more. Either restore the'
            . ' call or put the escaping back and let the sweep above cover it.'
        );
    }

    /* ==============================================================================
     * ITEM 5. A value is escaped exactly once, at output.
     * ============================================================================ */

    /**
     * THE ADMIN NOTICE IS ESCAPED WHERE IT IS PRINTED AND NOWHERE ELSE.
     *
     * `$notice` is rendered through `esc_html($notice)`. The mint-failure branch escaped the same
     * string a second time on the way in, so an operator whose mint was refused read
     * `&lt;`, `&amp;` and `&#039;` at the one moment the message mattered - and the three other
     * writers of that variable, including the renew failure ten lines below, always passed raw
     * text. One value, two escapes, and the wrong one is the one furthest from the output.
     *
     * A SOURCE ASSERTION OVER THE FOUR ASSIGNMENTS, because the value is built inside
     * wpmcp_render_admin(), which `wp_die()`s on a missing capability and then prints a whole
     * settings page: driving it would mean stubbing the page, not the decision. What the
     * assertion says is the decision - no writer of $notice escapes, exactly one reader does.
     *
     * THE READER IS NOW `wp_admin_notice(esc_html($notice), ...)` RATHER THAN `echo esc_html(...)`
     * (sprint DELETIONS), so the pattern below matches the escape and not the statement around
     * it. The invariant is unchanged and so is the count: core's wp_get_admin_notice()
     * interpolates the message RAW, which tests/integration/PlatformDeletionsTest.php pins
     * against the real function - so this esc_html() is still the only one, and still required.
     *
     * @group sprint-core-fix
     */
    public function testTheAdminNoticeIsEscapedOnceAndAtOutput(): void
    {
        $admin  = RepoFile::read('admin.php');
        $writes = [];

        self::assertSame(
            1,
            preg_match_all('/esc_html\(\$notice\)/', $admin),
            'The admin notice is no longer escaped exactly once where it is printed, so every'
            . ' assertion below is about a different value than the one the operator reads.'
        );

        preg_match_all('/\$notice\s*(?:=|\.=)\s*(.*?);\n/s', $admin, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            if (strpos($match[1], 'esc_') !== false) {
                $writes[] = preg_replace('/\s+/', ' ', trim($match[1]));
            }
        }

        self::assertSame(
            [],
            $writes,
            "A value written into \$notice is escaped before it reaches the output that escapes"
            . " it:\n  " . implode("\n  ", $writes)
            . "\n\nThe operator then reads `&amp;` for an ampersand and `&#039;` for an"
            . ' apostrophe. Escape at output, once.'
        );

        self::assertGreaterThanOrEqual(
            4,
            count($matches),
            'The sweep found fewer than the four places that write $notice, so it is matching'
            . ' something narrower than the assignments it is supposed to cover.'
        );
    }

    /* ==============================================================================
     * ITEM 6. Every event we schedule is removed by the same enumeration that registers it.
     * ============================================================================ */

    /**
     * REGISTRATION AND REMOVAL WALK ONE LIST, AND REMOVAL TAKES EVERY EVENT.
     *
     * Three sites spelled the same job three ways: activation scheduled through a literal,
     * deactivation hand-rolled `wp_next_scheduled()` + `wp_unschedule_event()`, and uninstall
     * called `wp_clear_scheduled_hook()`. The first two are the asymmetry that matters -
     * `wp_unschedule_event()` removes ONE event, so a site that had accumulated two for the hook
     * (a restored database, a copied cron array, a `pre_schedule_event` filter) was left with a
     * scheduled job firing against a plugin that is not loaded.
     *
     * THE ASSERTIONS ARE ABOUT THE ENUMERATION, NOT ABOUT CRON. `wp_schedule_event()` and
     * `wp_clear_scheduled_hook()` are WordPress's, and whether WordPress removes what it
     * scheduled is not this plugin's claim to prove. What is this plugin's claim is that every
     * hook it schedules is in one list and that both paths read that list, which is what makes a
     * SECOND hook added in 2027 removable without anybody remembering to remove it.
     *
     * @group sprint-core-fix
     */
    public function testEveryScheduledHookIsRemovedByTheListThatRegistersIt(): void
    {
        $hooks = wpmcp_cron_hooks();

        self::assertNotEmpty($hooks, 'The plugin schedules nothing, so this test asserts nothing.');

        $plugin = RepoFile::read('wp-mcp.php');

        // (1) THERE IS ONE SCHEDULER AND IT TAKES ITS HOOK FROM THE LIST. Two assertions rather
        // than a pattern that tries to find a literal hook name in an arbitrary call: the count
        // is what makes the second assertion cover every scheduler, and a second one added later
        // fails here rather than passing unseen.
        self::assertSame(
            1,
            preg_match_all('/wp_schedule_\w+\(/', $plugin),
            'The plugin has more than one place that schedules cron, so the assertion below no'
            . ' longer covers every one of them. Put the new one inside the wpmcp_cron_hooks()'
            . ' loop.'
        );
        self::assertStringContainsString(
            "wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', \$wpmcp_hook);",
            $plugin,
            'The scheduler no longer takes its hook name from wpmcp_cron_hooks(). A hook scheduled'
            . ' by a literal is a hook the removal list does not have to know about, which is'
            . ' exactly the asymmetry this item is.'
        );

        // (2) DEACTIVATION REMOVES EVERY EVENT OF EVERY HOOK IN THE LIST.
        self::assertStringContainsString(
            'foreach (wpmcp_cron_hooks() as $hook) { wp_clear_scheduled_hook($hook); }',
            $plugin,
            'The deactivation hook does not clear the list. wp_next_scheduled() +'
            . ' wp_unschedule_event() removes the NEXT event and leaves any others behind, and a'
            . ' second hook added later would not be removed at all.'
        );
        // TOKENISED, not searched: `wp_unschedule_event()` is named in two comments of this file -
        // one of them explaining why it is not called - so a text search would fail on the prose
        // that records the fix.
        self::assertSame(
            [],
            self::callsTo($plugin, ['wp_unschedule_event']),
            'wp_unschedule_event() is being CALLED again. It removes one event by timestamp; the'
            . ' plugin wants every event of the hook gone, which is wp_clear_scheduled_hook().'
        );

        // (3) AND UNINSTALL NAMES EVERY ONE OF THEM. It cannot call wpmcp_cron_hooks() -
        // WordPress includes that file in a request where wp-mcp.php has not run - so the names
        // are literals there and this is what holds the two lists together, exactly as
        // UninstallTest does for the option names.
        $uninstall = RepoFile::read('uninstall.php');

        foreach ($hooks as $hook) {
            self::assertStringContainsString(
                "'" . $hook . "'",
                $uninstall,
                "The plugin schedules '{$hook}' and uninstall.php does not name it, so deleting"
                . ' the plugin would leave a cron event behind on every site that installed it.'
                . ' Add it to $wpmcp_cron_hooks.'
            );
        }

        self::assertStringContainsString(
            'foreach ($cronHooks as $hook) { wp_clear_scheduled_hook($hook); }',
            $uninstall,
            'uninstall.php clears a hook by literal name again rather than walking the list it'
            . ' declares, so the list and what is actually cleared can differ.'
        );
    }

    /* ==============================================================================
     * Helpers.
     * ============================================================================ */

    /**
     * A real plugins directory, built once for the process because WP_PLUGIN_DIR is a constant.
     *
     * Four entries, each a case the scanner decides differently: a folder plugin, a folder plugin
     * whose file is not named after the folder, a single-file plugin, and a .php file with no
     * Plugin Name header at all (core skips it, and so must this).
     */
    /**
     * Every CALL to one of $names in $source, as `name (line N)`. Tokenised, so a name in a
     * comment or a docblock is not a call and a name in code cannot hide in one.
     *
     * @param list<string> $names
     * @return list<string>
     */
    private static function callsTo(string $source, array $names): array
    {
        $wanted = array_map('strtolower', $names);
        $found  = [];

        foreach (PhpSymbols::scan($source) as $symbol) {
            // `call` is what PhpSymbols names a free-function call; `function` is a DECLARATION.
            if ($symbol['kind'] === 'call' && in_array($symbol['lower'], $wanted, true)) {
                $found[] = $symbol['name'] . ' (line ' . $symbol['line'] . ')';
            }
        }

        return $found;
    }

    private static function pluginRoot(): string
    {
        static $root = null;

        if ($root !== null) {
            return $root;
        }

        $root = WPMCP_PLUGIN_DIR . '/.phpunit.cache/wpmcp-corefix-plugins-' . bin2hex(random_bytes(6));

        mkdir($root . '/alpha', 0777, true);
        mkdir($root . '/zeta', 0777, true);

        file_put_contents($root . '/alpha/alpha.php', "<?php\n/**\n * Plugin Name: Alpha Plugin\n * Version: 2.0\n */\n");
        file_put_contents($root . '/zeta/entry.php', "<?php\n/**\n * Plugin Name: Zeta Plugin\n * Version: 1.4\n */\n");
        file_put_contents($root . '/single-file.php', "<?php\n/**\n * Plugin Name: Single File\n * Version: 0.1\n */\n");
        file_put_contents($root . '/not-a-plugin.php', "<?php\n// A library file somebody dropped in wp-content/plugins.\n");

        if (!defined('WP_PLUGIN_DIR')) {
            define('WP_PLUGIN_DIR', $root);
        }

        register_shutdown_function(static function () use ($root): void {
            foreach (['alpha/alpha.php', 'zeta/entry.php', 'single-file.php', 'not-a-plugin.php'] as $file) {
                @unlink($root . '/' . $file);
            }
            @rmdir($root . '/alpha');
            @rmdir($root . '/zeta');
            @rmdir($root);
        });

        return $root;
    }
}
