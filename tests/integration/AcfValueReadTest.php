<?php
/**
 * THE SPRINT'S GATE SENTENCE, over real HTTP, on a real site with real ACF.
 *
 * Three behaviours, and each one is a thing D29 or D30 decided rather than a detail:
 *
 *   1. A Flexible Content layout an editor SWITCHED OFF comes back MARKED, not missing - with its
 *      index, its layout name, and its own values. ACF's `load_value` drops it for every caller
 *      where `is_admin()` is false, which is every request of ours, so without this the read
 *      returns three blocks where wp-admin shows four and nothing says a fourth exists. The read
 *      feeds a write, so a silently dropped block becomes a silently deleted one.
 *   2. A RENAMED layout reports the label the editor sees, not the original.
 *   3. A reference value comes back REDUCED TO BARE IDS for a token whose user cannot read the
 *      referenced post, and EXPANDED for one who can - inherited from ACF's own 6.8.10 REST
 *      reduction rather than written by us.
 *
 * WHY THE FIELD GROUP IS A LOCAL ONE IN A MU-PLUGIN. The read happens over HTTP, in a different
 * process from the fixture build, so a field group registered in a `wp eval` would not exist for
 * the request under test. A mu-plugin is the one hook point every request loads, and
 * `acf_add_local_field_group()` on `acf/init` is ACF's own documented way in - so the group leaves
 * no `acf-field-group` post, no `acf-field` posts and no option rows behind, and MuPlugin's armed
 * transient makes the file inert the moment this run stops being live.
 *
 * AND THE FIXTURE IS BUILT SO THE THREE ANSWERS DIFFER FROM THE WRONG ONES. The disabled row is
 * in the MIDDLE, so a read that returned "every row ACF gave us" has a visible gap rather than a
 * short list; the renamed label is different from the layout's own; and the reference target is a
 * DRAFT owned by somebody else, which is the only shape where two tokens on the same site get two
 * different answers - ACF's reduction delegates to the target post type's own REST controller,
 * which says yes to anybody on a published post.
 *
 * THE ACTING USERS ARE AN ADMINISTRATOR AND AN AUTHOR, and the Author is the interesting one: the
 * host post is the Author's own, so they may edit it and the tool's capability gate lets them in,
 * and they may NOT read another user's draft, so the reduction fires. An Editor would not do -
 * `edit_others_posts` lets an Editor read anybody's draft, and the reduction would never fire.
 *
 * WHY THIS CARRIES `acf-data` AND NO SPRINT GATE GROUP. It cannot run without ACF, and a sprint
 * gate group must hold only tests that pass on a site with no ACF, because CI fails a gate group
 * with even one skip and CI has no ACF. Run it with `--group acf-data` against a site that has
 * ACF Pro 6.5 or newer.
 *
 * @group acf-data
 */

declare(strict_types=1);

namespace WpMcp\Tests\Integration;

use WpMcp\Tests\Support\Fixtures;
use WpMcp\Tests\Support\FixtureIntegrationTestCase;
use WpMcp\Tests\Support\MuPlugin;
use WpMcp\Tests\Support\WpCli;

final class AcfValueReadTest extends FixtureIntegrationTestCase
{
    private const MU_SLUG = 'acf-local-group';

    private static int $adminId = 0;
    private static int $authorId = 0;
    private static string $adminToken = '';
    private static string $authorToken = '';
    private static int $host = 0;
    private static int $draft = 0;
    private static int $published = 0;
    private static int $emptyHost = 0;
    private static string $skip = '';

    /** The ACF field names this run registers. Underscores: they become meta keys. */
    private static function fieldPrefix(): string
    {
        return 'wpmcp_test_' . Fixtures::runId() . '_';
    }

    private static function label(): string
    {
        return Fixtures::name('acf-read');
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::requireSite();

        self::$skip = self::whyNot();

        if (self::$skip !== '') {
            return;
        }

        self::buildFixtures(self::build(...), self::destroy(...));
    }

    public static function tearDownAfterClass(): void
    {
        self::destroy();

        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$skip !== '') {
            self::markTestSkipped(self::$skip);
        }
    }

    /**
     * Why this class cannot run here, or ''.
     *
     * TWO SEPARATE REASONS AND TWO SEPARATE SENTENCES, because "no ACF at all" and "ACF without
     * the layout-metadata half" are different facts about the site and only the first is the
     * common one. The second is what ACF free, or any Pro below 6.5, looks like - and per D30 that
     * is a CLEAN degradation rather than a middle state: below Pro 6.5 there are no disabled
     * layouts to report, so there is nothing here to assert.
     */
    private static function whyNot(): string
    {
        $status = json_decode(
            WpCli::evaluate('echo wp_json_encode(wpmcp_module_status()["acf"]);'),
            true
        );

        if (!is_array($status)) {
            return 'wpmcp_module_status() did not answer about the acf module on this site.';
        }

        if ($status['missing'] !== []) {
            return 'This site has no usable ACF: the module reports '
                . implode(', ', array_map('strval', $status['missing']))
                . ' missing. The gate group covers that half; this class needs ACF present.';
        }

        if (empty($status['capabilities']['layout_metadata'])) {
            return 'This site has ACF values but not the layout-metadata half (ACF Pro 6.5 and up),'
                . ' so it has no disabled or renamed Flexible Content layouts to report. That is a'
                . ' clean degradation per D30, not a gap - there is nothing here to assert.';
        }

        return '';
    }

    private static function build(): void
    {
        Fixtures::purge();

        MuPlugin::drop(self::MU_SLUG, self::localGroupSource());

        self::$adminId  = Fixtures::createUser(Fixtures::name('acf-admin'), 'administrator');
        self::$authorId = Fixtures::createUser(Fixtures::name('acf-author'), 'author');

        self::$adminToken  = Fixtures::mintToken('read', self::label(), self::$adminId);
        self::$authorToken = Fixtures::mintToken('read', self::label(), self::$authorId);

        // THE TARGET THE REDUCTION TURNS ON: a DRAFT owned by the administrator. The Author may
        // not read it; the administrator may. A published post is the control, because ACF's
        // reduction delegates to core's REST controller, which says yes to anybody on one.
        self::$draft = Fixtures::createPost(
            Fixtures::name('acf-hidden-target'),
            'draft',
            self::$adminId,
            'hidden'
        );
        self::$published = Fixtures::createPost(
            Fixtures::name('acf-public-target'),
            'publish',
            self::$adminId,
            'public'
        );

        // THE HOST IS THE AUTHOR'S OWN, so the Author passes the tool's edit_post gate and the
        // only thing they cannot do is read the draft the field points at.
        self::$host = Fixtures::createPost(
            Fixtures::name('acf-host'),
            'publish',
            self::$authorId,
            'host'
        );

        // A SECOND HOST WITH THE SAME FIELD SAVED AND EMPTY, for the phantom-row case below. It is
        // a separate post because emptying the first one would destroy the fixture every other
        // test in this class reads.
        self::$emptyHost = Fixtures::createPost(
            Fixtures::name('acf-empty-host'),
            'publish',
            self::$authorId,
            'empty host'
        );

        self::writeValues();
    }

    /**
     * The values, written through ACF's OWN save path - `update_field()` with the two row keys the
     * admin form submits, `acf_fc_layout_disabled` and `acf_fc_layout_custom_label`. Nothing here
     * writes `_{field}_layout_meta` by hand: ACF's `update_value()` is what turns those two keys
     * into the layout meta, so the fixture exercises the same storage a human editor produces.
     */
    private static function writeValues(): void
    {
        $prefix = self::fieldPrefix();

        WpCli::evaluate(sprintf(
            'update_field(%s, %d, %d);'
            . ' update_field(%s, %d, %d);'
            . ' update_field(%s, array('
            . '   array("acf_fc_layout" => "hero", "heading" => "Row zero"),'
            . '   array("acf_fc_layout" => "gallery", "heading" => "Row one is switched off",'
            . '         "target" => %d,'
            . '         "acf_fc_layout_disabled" => 1,'
            . '         "acf_fc_layout_custom_label" => %s),'
            . '   array("acf_fc_layout" => "hero", "heading" => "Row two")'
            . ' ), %d);'
            . ' echo "ok";',
            self::phpString($prefix . 'ref'),
            self::$draft,
            self::$host,
            self::phpString($prefix . 'open_ref'),
            self::$published,
            self::$host,
            self::phpString($prefix . 'blocks'),
            self::$draft,
            self::phpString('Editor renamed me'),
            self::$host
        ));

        // SAVED AND EMPTY. ACF's update_value stores '' for an empty Flexible Content value, so the
        // hidden reference row exists - the field IS listed by get_field_objects() - and its value
        // formats to '' rather than to an array.
        WpCli::evaluate(sprintf(
            'update_field(%s, array("acf_fc_layout" => "hero", "heading" => "temporary"), %d);'
            . ' update_field(%s, array(), %d);'
            . ' echo "ok";',
            self::phpString(self::fieldPrefix() . 'blocks'),
            self::$emptyHost,
            self::phpString(self::fieldPrefix() . 'blocks'),
            self::$emptyHost
        ));
    }

    /** The mu-plugin body: one local field group, registered on ACF's own hook. */
    private static function localGroupSource(): string
    {
        $run    = Fixtures::runId();
        $prefix = self::fieldPrefix();

        return 'add_action("acf/init", function () {'
            . ' if (!function_exists("acf_add_local_field_group")) { return; }'
            . ' acf_add_local_field_group(array('
            . '  "key" => "group_' . $run . '_wpmcpacf",'
            . '  "title" => "wp-mcp test ' . $run . '",'
            . '  "fields" => array('
            . '    array("key" => "field_' . $run . '_ref", "name" => "' . $prefix . 'ref",'
            . '          "label" => "Hidden reference", "type" => "post_object",'
            . '          "post_type" => array("post"), "return_format" => "object"),'
            . '    array("key" => "field_' . $run . '_open", "name" => "' . $prefix . 'open_ref",'
            . '          "label" => "Public reference", "type" => "post_object",'
            . '          "post_type" => array("post"), "return_format" => "object"),'
            . '    array("key" => "field_' . $run . '_fc", "name" => "' . $prefix . 'blocks",'
            . '          "label" => "Blocks", "type" => "flexible_content", "layouts" => array('
            . '      "layout_' . $run . '_hero" => array("key" => "layout_' . $run . '_hero",'
            . '        "name" => "hero", "label" => "Hero", "display" => "block", "sub_fields" => array('
            . '          array("key" => "field_' . $run . '_hh", "name" => "heading",'
            . '                "label" => "Heading", "type" => "text")'
            . '      )),'
            . '      "layout_' . $run . '_gal" => array("key" => "layout_' . $run . '_gal",'
            . '        "name" => "gallery", "label" => "Image gallery", "display" => "block", "sub_fields" => array('
            . '          array("key" => "field_' . $run . '_gh", "name" => "heading",'
            . '                "label" => "Heading", "type" => "text"),'
            . '          array("key" => "field_' . $run . '_gt", "name" => "target",'
            . '                "label" => "Target", "type" => "post_object",'
            . '                "post_type" => array("post"), "return_format" => "object")'
            . '      ))'
            . '    ))'
            . '  ),'
            . '  "location" => array(array(array("param" => "post_type", "operator" => "==", "value" => "post")))'
            . ' ));'
            . '});';
    }

    private static function destroy(): void
    {
        MuPlugin::removeOurs();
        MuPlugin::disarm();
        Fixtures::deleteTokensLabelled(self::label());
        Fixtures::purge();
    }

    /* ------------------------------------------------------------------ the reads */

    /**
     * One read, as one token, decoded.
     *
     * @return array<string, mixed>
     */
    private function read(string $token, array $arguments = []): array
    {
        $result = $this->mcp($token)->callTool(
            'get-acf-values',
            array_merge(['object_type' => 'post', 'id' => self::$host], $arguments)
        );

        self::assertFalse(
            $result->isError,
            'get-acf-values refused: ' . $result->text
        );

        return $result->data();
    }

    /** One field entry out of a read, by name. */
    private static function field(array $read, string $name): array
    {
        foreach ($read['fields'] as $entry) {
            if ($entry['name'] === $name) { return $entry; }
        }

        self::fail("The read returned no field named {$name}. It returned: "
            . implode(', ', array_column($read['fields'], 'name')));
    }

    /**
     * ITEM 3, FIRST HALF: the layout an editor switched OFF is returned and MARKED, and the two
     * rows either side of it are not.
     *
     * @group acf-data
     */
    public function testASwitchedOffLayoutIsReturnedAndMarkedRatherThanMissing(): void
    {
        $blocks = self::field($this->read(self::$adminToken), self::fieldPrefix() . 'blocks');

        self::assertSame('flexible_content', $blocks['type']);
        self::assertSame(
            [0, 1, 2],
            array_column($blocks['rows'], 'index'),
            'The read did not return all three rows. ACF hands us rows 0 and 2 and drops 1'
            . ' entirely, so a missing index 1 is the failure this item exists to prevent.'
        );
        self::assertSame(
            [false, true, false],
            array_column($blocks['rows'], 'disabled'),
            'The switched-off row is not marked, or a row that is on is marked as off.'
        );
        self::assertSame(
            ['hero', 'gallery', 'hero'],
            array_column($blocks['rows'], 'layout'),
            "The dropped row's layout NAME is missing - it is not in the value ACF returned, so it"
            . ' has to come from the raw layout list, and a blank here means the priority-9'
            . ' subscriber on acf/load_value/type=flexible_content did not fire.'
        );

        // AND ACF REALLY DID DROP IT, or the assertions above are about a value nothing removed.
        // The field's own `value` is what ACF handed us, and it has a GAP at 1.
        self::assertSame(
            ['0', '2'],
            array_map('strval', array_keys($blocks['value'])),
            "ACF returned row 1 after all, so this site is not exhibiting the behaviour D29 is"
            . ' about and the marking above proves nothing.'
        );
    }

    /**
     * ITEM 3, SECOND HALF: a renamed layout reports the label the editor sees, and an unrenamed
     * one reports the layout's own label.
     *
     * BOTH, because a read that returned the layout's own label for everything would pass the
     * second alone and one that returned the rename for everything would pass neither honestly.
     *
     * @group acf-data
     */
    public function testARenamedLayoutReportsTheLabelTheEditorSees(): void
    {
        $rows = self::field($this->read(self::$adminToken), self::fieldPrefix() . 'blocks')['rows'];

        self::assertSame(
            ['Hero', 'Editor renamed me', 'Hero'],
            array_column($rows, 'label'),
            'The renamed row does not report the editor\'s label, or an unrenamed row does not'
            . " report the layout's own."
        );
        self::assertSame(
            [false, true, false],
            array_column($rows, 'renamed'),
            'Nothing says WHICH label is the editor\'s, so a caller cannot tell a rename from a'
            . ' layout that happens to be called that.'
        );
    }

    /**
     * ITEM 3, THE PART WITH NO ACCESSOR AT ALL: the dropped row's own VALUES come back, formatted
     * and permission-reduced like every other value in the read.
     *
     * @group acf-data
     */
    public function testTheDroppedRowCarriesItsOwnValues(): void
    {
        $prefix = self::fieldPrefix();
        $rows   = self::field($this->read(self::$adminToken), $prefix . 'blocks')['rows'];
        $values = $rows[1]['values'];

        self::assertSame(
            [$prefix . 'blocks_1_heading', $prefix . 'blocks_1_target'],
            array_keys($values),
            "The dropped row's values are keyed by something other than its sub-fields, or a"
            . ' sub-field with nothing stored was omitted instead of reported as empty.'
        );
        self::assertSame('Row one is switched off', $values[$prefix . 'blocks_1_heading']);

        // AND IT IS FORMATTED, NOT RAW. get_field() on a flattened selector reaches these values
        // only by building a dummy TEXT field, which returns the stored ID with formatting forced
        // off and the permission reduction skipped. An administrator must see a post here.
        self::assertIsArray(
            $values[$prefix . 'blocks_1_target'],
            'The dropped row\'s post_object sub-field came back unformatted. That is what'
            . ' get_field() on a flattened selector returns - a raw ID through a dummy text field -'
            . ' and it is also what skips the permission check.'
        );
        self::assertSame(
            self::$draft,
            (int) $values[$prefix . 'blocks_1_target']['ID'],
            'The dropped row\'s reference does not point at the fixture target.'
        );

        // A ROW THAT IS *NOT* DISABLED CARRIES NO `values`, because its values are already in the
        // field's own value under the same index and repeating them would double every read.
        self::assertArrayNotHasKey('values', $rows[0]);
        self::assertArrayNotHasKey('values', $rows[2]);
    }

    /**
     * THE GATE SENTENCE'S LAST CLAUSE: the same field, on the same object, comes back reduced to
     * bare IDs for a token whose user cannot read the referenced post and expanded for one who
     * can.
     *
     * AND THE CONTROL IN THE SAME READ: a second field pointing at a PUBLISHED post stays
     * expanded for both, so this is a permission gate and not a blanket flattening.
     *
     * @group acf-data
     */
    public function testAReferenceIsReducedForATokenThatCannotReadItAndExpandedForOneThatCan(): void
    {
        $prefix = self::fieldPrefix();

        $asAdmin  = $this->read(self::$adminToken);
        $asAuthor = $this->read(self::$authorToken);

        $adminHidden  = self::field($asAdmin, $prefix . 'ref')['value'];
        $authorHidden = self::field($asAuthor, $prefix . 'ref')['value'];

        self::assertIsArray($adminHidden, 'The administrator did not get an expanded post object.');
        self::assertSame(self::$draft, (int) $adminHidden['ID']);

        self::assertSame(
            self::$draft,
            $authorHidden,
            'An Author who cannot read the referenced DRAFT was handed something other than its'
            . ' bare ID. That is the 6.8.10 reduction not reaching our call site, which is the'
            . ' whole reason this module reads through ACF\'s REST formatter instead of get_field.'
        );

        // THE CONTROL: a reference to a PUBLISHED post is expanded for the Author too.
        $authorOpen = self::field($asAuthor, $prefix . 'open_ref')['value'];

        self::assertIsArray(
            $authorOpen,
            'A reference to a PUBLISHED post was reduced for the Author, so the reduction is a'
            . ' blanket flattening rather than a permission gate.'
        );
        self::assertSame(self::$published, (int) $authorOpen['ID']);

        // AND INSIDE THE DROPPED ROW, which is the path that does NOT go through ACF's own
        // container recursion - we rebuild it - so it needs its own assertion.
        $authorRows = self::field($asAuthor, $prefix . 'blocks')['rows'];

        self::assertSame(
            self::$draft,
            $authorRows[1]['values'][$prefix . 'blocks_1_target'],
            'The reference inside a DROPPED row was not reduced for the Author. Those rows are'
            . ' rebuilt by this module rather than handed over by ACF, so a read that reduced the'
            . ' top-level fields and not these would disclose exactly what ACF 6.8.10 closed.'
        );
    }

    /**
     * The read says which of the two guarantees this site provides, in its own output (item 7).
     *
     * @group acf-data
     */
    public function testTheReadStatesWhichGuaranteesTheSiteProvides(): void
    {
        $read = $this->read(self::$adminToken);

        self::assertSame(
            ['values' => true, 'layout_metadata' => true],
            $read['acf'],
            'The read does not state which halves of the ACF face this site provides, so a caller'
            . ' cannot tell "no disabled layouts" from "this ACF cannot report them".'
        );
        self::assertSame(
            ['type' => 'post', 'id' => self::$host, 'acf_id' => self::$host],
            $read['object']
        );
    }

    /**
     * The capability gate is OURS, because ACF has none: a token whose user cannot EDIT the object
     * is refused, and a post the caller cannot read at all is indistinguishable from one that is
     * not there.
     *
     * @group acf-data
     */
    public function testTheCapabilityGateIsOurs(): void
    {
        // The administrator's DRAFT is not the Author's to edit, and not theirs to read either -
        // so it answers exactly as a missing post does, byte for byte with get-post.
        $refused = $this->mcp(self::$authorToken)->callTool(
            'get-acf-values',
            ['object_type' => 'post', 'id' => self::$draft]
        );

        self::assertTrue($refused->isError, 'An Author read the ACF fields of somebody else\'s draft.');
        self::assertStringContainsString('No post with that ID', $refused->text);

        $missing = $this->mcp(self::$authorToken)->callTool(
            'get-acf-values',
            ['object_type' => 'post', 'id' => 99999999]
        );

        self::assertSame(
            $refused->text,
            $missing->text,
            'A post the caller may not read answers differently from one that does not exist, so'
            . ' the tool can be used to probe for posts.'
        );

        // AND AN OPTIONS READ NEEDS manage_options, which an Author does not have.
        $options = $this->mcp(self::$authorToken)->callTool('get-acf-values', ['object_type' => 'options']);

        self::assertTrue($options->isError, 'An Author read this site\'s ACF options page.');
        self::assertStringContainsString('not allowed to', $options->text);
    }

    /**
     * The `fields` argument narrows the read, and a name the object has never saved is simply not
     * returned - which is `get_field_object()`'s own rule and is stated in the description.
     *
     * @group acf-data
     */
    public function testTheFieldsArgumentNarrowsTheReadAndAnUnsavedNameIsAbsent(): void
    {
        $prefix = self::fieldPrefix();
        $read   = $this->read(self::$adminToken, ['fields' => [$prefix . 'ref', 'wpmcp_no_such_field_9f2a']]);

        self::assertSame(
            [$prefix . 'ref'],
            array_column($read['fields'], 'name'),
            'The fields argument returned something other than the one field that exists.'
        );
    }

    /**
     * AN EMPTY FLEXIBLE CONTENT FIELD HAS NO ROWS - and `rows: []` rather than one phantom row.
     *
     * THIS IS A DEFECT THIS MODULE SHIPPED AND THIS TEST CAUGHT. The row indices were taken from
     * `array_keys((array) $formatted)`, and an empty Flexible Content value formats to the empty
     * STRING, not to an array - `(array) ''` is `array('')`, one element at index 0. So an object
     * with the field saved and no rows reported a row 0 with a blank layout name and a blank label,
     * which reads to a caller as "there is a block here and we could not identify it". A cast is
     * not a guard.
     *
     * @group acf-data
     */
    public function testAnEmptyFlexibleContentFieldHasNoRowsRatherThanOnePhantomRow(): void
    {
        $blocks = self::field(
            $this->read(self::$adminToken, ['id' => self::$emptyHost]),
            self::fieldPrefix() . 'blocks'
        );

        self::assertSame(
            [],
            $blocks['rows'],
            'An empty Flexible Content field reported a row. Its value is the empty STRING, and'
            . " (array) '' is array('') - one element at index 0."
        );

        // AND THE FIELD IS REALLY THERE, or this test is about a field nothing returned.
        self::assertSame('flexible_content', $blocks['type']);
    }

    /** Single-quoted for `wp eval`, which carries the snippet as one argv element. */
    private static function phpString(string $value): string
    {
        return "'" . addcslashes($value, "'\\") . "'";
    }
}
