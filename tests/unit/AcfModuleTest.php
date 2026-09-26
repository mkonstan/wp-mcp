<?php
/**
 * THE ACF MODULE ON A SITE WITH NO ACF - which is the half of its behaviour that a gate group is
 * allowed to contain, and the half that costs somebody's site money if it is wrong.
 *
 * WHY THIS IS THE GATE-GROUP HALF AND THE VALUE READS ARE NOT. A sprint gate group must contain
 * only tests that pass on a site with no ACF, because CI fails any gate group with even one skip
 * and CI has no ACF. Every assertion here is about the module's SOURCE, its declared face, its
 * registration decision and its refusal - none of which needs ACF, WordPress or a site. The reads
 * themselves live in tests/integration/AcfValueReadTest.php under `acf-data`, with explicit
 * skips.
 *
 * AND THE UNIT TIER IS NOT A SIMULATION OF THE BARE SITE - IT IS ONE. There is no ACF in this
 * process, so `wpmcp_module_face_missing('acf')` answers here for the same reason it answers on a
 * WordPress install without ACF: the functions are not defined. Nothing is mocked and nothing is
 * arranged, which is why these assertions are worth more than their length suggests.
 *
 * @group sprint-acf-read
 */

declare(strict_types=1);

namespace WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WpMcp\Tests\Support\WordPressRuntime;
use WpMcp\Tests\Support\WordPressStubs;

final class AcfModuleTest extends TestCase
{
    /**
     * The tool list this plugin serves with every opt-in switch off and no ACF present. The ACF
     * tool is ABSENT and every other module's tools are there, which is what makes the absence
     * the guard's doing rather than a module that failed to load.
     */
    private const TOOLS_WITHOUT_ACF = [
        'site-info', 'list-posts', 'get-post',
        'create-post', 'update-post', 'delete-post',
        'list-revisions', 'get-revision', 'restore-revision',
        'list-terms', 'create-term', 'delete-term',
        'list-media', 'get-media', 'upload-media', 'delete-media',
        'list-comments', 'moderate-comment', 'reply-comment',
        'list-users', 'get-user', 'get-option', 'list-plugins', 'list-themes',
        'list-menus', 'get-menu', 'add-menu-item', 'update-menu-item', 'remove-menu-item',
        'list-content-types',
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

    /**
     * WITH NO ACF THE TOOL LIST IS BYTE-IDENTICAL TO THE LIST WITHOUT THIS MODULE, and the
     * comparison is on the BYTES because that is what the sprint's gate sentence says and because
     * a decoded array cannot tell an added key at the end from no change at all when a test only
     * checks a count.
     *
     * @group sprint-acf-read
     */
    public function testWithNoAcfTheToolListIsByteIdenticalToTheListWithoutThisModule(): void
    {
        $served = array_keys(\wpmcp_tools());

        self::assertSame(
            json_encode(self::TOOLS_WITHOUT_ACF),
            json_encode($served),
            'A site with no ACF is being served a different tool list from the one it was served'
            . ' before this module existed. The bare-site rule is that a module which cannot work'
            . ' registers nothing, so its tools do not exist at all - not "exist but refuse".'
        );

        self::assertArrayNotHasKey(
            'get-acf-values',
            \wpmcp_tools(),
            'get-acf-values is registered on a site with no ACF, so every call to it would reach'
            . ' ACF functions that are not there.'
        );
    }

    /**
     * AND THE ABSENCE IS THE GUARD'S DOING, NOT A TYPO. The provider function exists and does
     * define the tool, so the assertion above is about the registration decision and not about a
     * misspelled name or a file that failed to load.
     *
     * @group sprint-acf-read
     */
    public function testTheProviderDefinesTheToolTheRegistryDoesNotHave(): void
    {
        self::assertTrue(function_exists('wpmcp_acf_tools'), 'The ACF module file did not load.');

        $tools = \wpmcp_acf_tools();

        self::assertArrayHasKey('get-acf-values', $tools);
        self::assertFalse($tools['get-acf-values']['write'], 'get-acf-values is a read tool.');
        self::assertIsCallable($tools['get-acf-values']['run']);

        // AND IT IS IN THE CATALOG EVERY CONTRACT TEST IN THIS TIER READS, which is what puts the
        // description limits, the first-fifty-characters rule, the four annotations and the schema
        // dialect on this tool without a site. A tool whose contract were only checkable with ACF
        // installed would be a tool nothing checks.
        self::assertArrayHasKey(
            'get-acf-values',
            WireSerializationTest::catalog(),
            'get-acf-values is not in the unit tier catalog, so no contract test sees it.'
        );
    }

    /**
     * BOTH FLOORS ARE DETECTED RATHER THAN ASSUMED (item 7), and here they are both detected as
     * ABSENT, which is the direction a bare site exercises.
     *
     * THE VALUES FLOOR is a detected ACF 5.11: `acf_format_value_for_rest()` arrived in 5.11 and
     * carries no `@since` tag, so its presence is the only honest test. It is named in the missing
     * list here, by name, so an operator reads which symbol rather than "ACF".
     *
     * THE LAYOUT-METADATA FLOOR is ACF Pro 6.5 and it is OPTIONAL, so its absence must not appear
     * in the missing list at all - or a site running ACF free would be refused values over a
     * feature that does not exist there to be reported.
     *
     * @group sprint-acf-read
     */
    public function testBothVersionFloorsAreDetectedAndOnlyTheValuesOneGates(): void
    {
        $missing = \wpmcp_module_face_missing('acf');

        self::assertContains(
            'acf_format_value_for_rest()',
            $missing,
            'The values floor is not detected by name, so a site below ACF 5.11 would be told'
            . ' nothing useful about why it has no ACF tools.'
        );
        self::assertContains('get_field_object()', $missing);
        self::assertContains('get_field_objects()', $missing);

        // THE OPTIONAL HALF IS NOT IN THE REQUIRED LIST. Every name in it is a name the layout
        // capability needs, and none of them may gate registration.
        foreach (['acf_get_field_type()', '->get_disabled_layouts()', '->get_renamed_layouts()'] as $optional) {
            self::assertNotContains(
                $optional,
                $missing,
                "{$optional} is being treated as required. Flexible Content is a PRO field type"
                . ' and the disable feature is 6.5, so below that there are no disabled layouts to'
                . ' report - refusing to serve values over it invents a middle state.'
            );
        }

        self::assertSame(
            ['layout_metadata' => false],
            \wpmcp_module_face_capabilities('acf'),
            'The optional capability is not reported, so the tool cannot state which of the two'
            . ' guarantees the host site provides.'
        );
    }

    /**
     * THE CALL-TIME REFUSAL (D30, point 3): the run closure asks the SAME face check and refuses
     * before it touches a single ACF symbol.
     *
     * THIS IS REACHABLE, and saying how matters more than the assertion. `wpmcp_acf_tools()` is a
     * public function, so anything on the site can hand its output to the `wpmcp_tools` filter and
     * publish the tool whatever the guard decided; and the guard answers at `plugins_loaded` while
     * this closure runs inside a later request. Registration-time gating alone is not enough for
     * the measured reason D30 gives: clients cache tool lists at connect time.
     *
     * @group sprint-acf-read
     */
    public function testTheRunClosureRefusesWithoutTouchingAcf(): void
    {
        $run   = \wpmcp_acf_tools()['get-acf-values']['run'];
        $error = $run(['object_type' => 'post', 'id' => 1]);

        self::assertInstanceOf(\WP_Error::class, $error, 'The ACF tool did not refuse with ACF absent.');
        self::assertStringContainsString(
            'acf_format_value_for_rest()',
            $error->get_error_message(),
            'The refusal does not name the symbol that is missing, so the private log entry it'
            . ' produces tells the operator nothing they could act on.'
        );
    }

    /**
     * AND THE REFUSAL IS THE ONE THAT CARRIES A TRACE ID, decided by its CODE and nothing else.
     *
     * endpoint.php relays a WP_Error whose code begins `wpmcp_`, or is on
     * wpmcp_relayable_core_error_codes(), as `isError` text with no trace id; everything else
     * becomes the generic -32603 with a trace id on the wire and the whole message in the private
     * trace table. This refusal wants the second: the operator needs the missing symbols, and the
     * caller must not be handed a fact about which plugins this site has - `list-plugins` is an
     * admin-scope tool precisely because a plugin inventory is not public.
     *
     * HELD ON THE CODE RATHER THAN ON THE WIRE because the classification IS the code. A future
     * author renaming it `wpmcp_acf_unavailable` would silently move it to the relayed path and
     * lose both properties at once, and this is the assertion that says so.
     *
     * @group sprint-acf-read
     */
    public function testTheRefusalIsClassifiedAsTheGenericGoesToTheTraceLogKind(): void
    {
        $run   = \wpmcp_acf_tools()['get-acf-values']['run'];
        $code  = (string) $run([])->get_error_code();

        self::assertStringStartsNotWith(
            'wpmcp_',
            $code,
            "The face-missing refusal is coded {$code}, which endpoint.php relays to the caller as"
            . ' text with no trace id - so the operator gets no id to look up and the caller is'
            . ' told which plugin this site is missing.'
        );
        self::assertNotContains(
            $code,
            \wpmcp_relayable_core_error_codes(),
            "{$code} is on the relayable list, which has the same effect as a wpmcp_ prefix."
        );

        // AND THE ORDINARY REFUSALS STILL ARE relayable, or every capability refusal in this tool
        // would reach the caller as "Internal error" and tell them nothing they can act on.
        self::assertStringStartsWith(
            'wpmcp_',
            (string) \wpmcp_cannot('do the thing')->get_error_code()
        );
    }

    /**
     * ONE LIST OF OBJECT KINDS, read by the schema's `enum` and by the run closure's own check -
     * so a kind added to one of them cannot be missing from the other.
     *
     * @group sprint-acf-read
     */
    public function testTheObjectTypeEnumIsTheListTheToolActuallyAccepts(): void
    {
        $tool = \wpmcp_acf_tools()['get-acf-values'];

        self::assertSame(
            \wpmcp_acf_object_types(),
            $tool['inputSchema']['properties']['object_type']['enum'],
            'The schema advertises a different set of object kinds from the one the tool checks.'
        );
        self::assertSame(['post', 'term', 'user', 'options'], \wpmcp_acf_object_types());
        self::assertSame('post', $tool['inputSchema']['properties']['object_type']['default']);
    }

    /**
     * THE REQUIRED HALF OF THE FACE DECLARES NO METHOD, and this is a GATE rather than the comment
     * it replaces (review 74, S3).
     *
     * MEASURED: ACF's field types arrive on `acf/include_field_types`, fired from `ACF::init()`,
     * which is hooked to `init` at priority 5 - NOT at ACF's load time, which the module's docblock
     * used to claim. So at `plugins_loaded` priority 0, where this module decides whether to
     * register, `acf_get_field_type('flexible_content')` is null and every method probe answers
     * "missing". That is harmless only while the required block has no methods in it, and the next
     * author promoting one would get a module that never registers on a site with everything
     * present, while the docblock told them it could not happen.
     *
     * So the rule is asserted instead of described. The fix, if a required method is ever genuinely
     * needed, is in the failure message: move the registration to `init` - nothing reads the tool
     * registry before then.
     *
     * @group sprint-acf-read
     */
    public function testTheRequiredHalfOfTheAcfFaceDeclaresNoMethod(): void
    {
        $face = \wpmcp_module_face('acf');

        self::assertSame(
            [],
            $face['required']['methods'],
            'The ACF face requires a METHOD, and the guard that reads the required block runs on'
            . ' plugins_loaded - where ACF has not yet fired acf/include_field_types (it is on init'
            . ' priority 5), so acf_get_field_type() is null and the probe reports the method'
            . ' missing. The module would never register on a site that has everything. Either keep'
            . " the method in the OPTIONAL block, or move this module's registration to `init`."
        );

        // AND THE OPTIONAL BLOCK IS WHERE THE METHODS ARE, or this assertion is about an empty face.
        //
        // get_layout_title JOINED THEM IN SPRINT CORE-FIX, and it is here for the same reason they
        // are: the layout label now comes from ACF's own method, which runs the documented
        // acf/fields/flexible_content/layout_title filter family, and a method has to be declared
        // or ModuleApiFaceTest reports it undeclared. Below ACF Pro 6.5 the whole capability is
        // absent and the label falls back to the layout's own stored one.
        self::assertSame(
            ['get_disabled_layouts', 'get_renamed_layouts', 'get_layout_title'],
            $face['optional']['layout_metadata']['methods'][0]['names'],
            'The layout-metadata capability no longer declares the three methods it needs.'
        );
    }

    /**
     * THE USER FIELDS THIS MODULE PUBLISHES ARE `get-user`'s, EXACTLY - so a `WP_User` arriving
     * through an ACF field cannot disclose more than the tool whose whole job is users.
     *
     * WHY THE LIST IS REPEATED AT ALL: a module may not call into `tools.php` beyond the five named
     * helpers, so `wpmcp_get_user_shape()` is out of reach and the field names live in the module.
     * A repetition nothing checks is a drift waiting to happen; this is the check. It runs in the
     * unit tier because both are plain functions - no ACF, no WordPress, no site.
     *
     * THE REASON IT MATTERS IS MEASURED. `wp_json_encode()` on a `WP_User` serialises its `data`
     * property, which carries `user_pass` and `user_activation_key`, plus `allcaps`. `get-user`'s
     * own description promises "Never returns passwords, keys, sessions or user meta", and before
     * round 2 this module would have broken that promise on the same server.
     *
     * @group sprint-acf-read
     */
    public function testTheModulesUserFieldsAreTheOnesGetUserPublishes(): void
    {
        $fields = \wpmcp_acf_user_fields();

        self::assertSame(
            array_keys(\wpmcp_get_user_shape()),
            array_merge($fields['always'], $fields['privileged']),
            "The ACF module publishes a different set of user fields from get-user. The two are the"
            . ' same decision about what a user looks like, and this module cannot call the other'
            . " one's shape function, so they can only be kept together here."
        );

        // AND THE SPLIT IS THE SAME SPLIT: the four that get-user gives only to a privileged caller
        // are the four this module gates. get-user marks them with a `when` closure.
        $privileged = [];

        foreach (\wpmcp_get_user_shape() as $name => $field) {
            if (isset($field['when'])) { $privileged[] = $name; }
        }

        self::assertSame(
            $privileged,
            $fields['privileged'],
            'The ACF module gates a different subset of the user fields than get-user does, so one'
            . ' of the two hands a caller something the other withholds.'
        );
    }

    /**
     * THE MODULE'S REGISTRATION IS DEFERRED TO `plugins_loaded`, and that is a property of the
     * SOURCE worth pinning rather than a detail.
     *
     * wpmcp_bootstrap() runs at wp-mcp's own plugin file scope, so at the moment the module file
     * is loaded "is ACF here" is a question about plugin ORDER. MEASURED on jaygroup:
     * `active_plugins` is not alphabetical - it opens with gravityforms - so a site with wp-mcp as
     * an mu-plugin, or ACF in a differently-named directory, would answer "no ACF" on a site that
     * has it, and the module would serve nothing with nothing wrong.
     *
     * @group sprint-acf-read
     */
    public function testTheGuardIsEvaluatedOnPluginsLoadedRatherThanAtFileScope(): void
    {
        $source = (string) file_get_contents(\WPMCP_PLUGIN_DIR . '/modules/acf.php');

        self::assertStringContainsString(
            "add_action('plugins_loaded', 'wpmcp_acf_register_module', 0);",
            $source,
            'The ACF module decides whether to register at file scope, which answers about plugin'
            . ' load order rather than about the site.'
        );

        // THE FACE IS DECLARED UNCONDITIONALLY, which is what lets a site WITHOUT ACF still be
        // told what the module needed - wpmcp_module_status() reads the declaration.
        $status = \wpmcp_module_status()['acf'];

        self::assertTrue($status['present'], 'The manifest names modules/acf.php and it is not there.');
        self::assertTrue($status['declares_face'], 'The ACF module did not declare its face on a site with no ACF.');
        self::assertFalse($status['registered'], 'The ACF module registered with no ACF present.');
        self::assertNotSame(
            [],
            $status['missing'],
            'The module is not registered and cannot say what it is missing, so an administrator'
            . ' cannot tell "this needs ACF" from "wp-mcp is broken".'
        );
    }
}
