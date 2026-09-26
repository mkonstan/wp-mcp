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
    private static int $protected = 0;
    private static string $skip = '';

    /** The per-request header that makes the mu-plugin warm ACF's value store before we read. */
    private const WARM_HEADER = 'X-Wpmcp-Warm-Acf';

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

        // A PASSWORD-PROTECTED PUBLISHED POST, which is the shape that makes the disclosure
        // question answerable: `post_status` is `publish`, so core's own check_read_permission()
        // says YES to anybody and ACF's 6.8.10 reduction does not fire - the whole WP_Post,
        // `post_password` in plaintext included, is what PHP would serialise.
        self::$protected = Fixtures::createPostWith([
            'post_type'     => 'post',
            'post_status'   => 'publish',
            'post_title'    => Fixtures::name('acf-protected-target'),
            'post_content'  => 'THE PROTECTED BODY',
            'post_password' => 'wpmcp-fixture-secret',
            'post_author'   => self::$adminId,
        ]);

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
            . ' update_field(%s, %d, %d);'
            . ' update_field(%s, %d, %d);'
            . ' update_field(%s, array('
            . '   array("acf_fc_layout" => "hero", "heading" => "Row zero"),'
            . '   array("acf_fc_layout" => "gallery", "heading" => "Row one is switched off",'
            . '         "target" => %d,'
            . '         "meta" => array("caption" => "Caption inside a group"),'
            . '         "inner" => array(array("acf_fc_layout" => "block", "body" => "Body inside a nested block")),'
            // The seamless clone's children are submitted FLAT and prefixed, because that is what
            // ACF flattened them to; the boxed clone's are submitted NESTED under the clone's own
            // name with UNPREFIXED child keys. Measured, not guessed.
            . '         "fields_title" => "Title through a seamless clone",'
            . '         "fields_target" => %d,'
            . '         "boxed" => array("title" => "Title through a boxed clone", "target" => %d),'
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
            self::phpString($prefix . 'protected_ref'),
            self::$protected,
            self::$host,
            self::phpString($prefix . 'user_obj'),
            self::$adminId,
            self::$host,
            self::phpString($prefix . 'blocks'),
            self::$draft,
            self::$draft,
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
            // THE CLONE SOURCE, and its location NEVER MATCHES anything on purpose - a clone
            // resolves by group KEY, so the source's own fields must not also land on the object in
            // their own right.
            . ' acf_add_local_field_group(array('
            . '  "key" => "group_' . $run . '_clonesrc",'
            . '  "title" => "wp-mcp test ' . $run . ' clone source",'
            . '  "fields" => array('
            . '    array("key" => "field_' . $run . '_ct", "name" => "title",'
            . '          "label" => "Cloned title", "type" => "text"),'
            . '    array("key" => "field_' . $run . '_cr", "name" => "target",'
            . '          "label" => "Cloned target", "type" => "post_object",'
            . '          "post_type" => array("post"), "return_format" => "object")'
            . '  ),'
            . '  "location" => array(array(array("param" => "post_type", "operator" => "==",'
            . '                                 "value" => "wpmcp-no-such-post-type")))'
            . ' ));'
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
            . '    array("key" => "field_' . $run . '_prot", "name" => "' . $prefix . 'protected_ref",'
            . '          "label" => "Protected reference", "type" => "post_object",'
            . '          "post_type" => array("post"), "return_format" => "object"),'
            . '    array("key" => "field_' . $run . '_uo", "name" => "' . $prefix . 'user_obj",'
            . '          "label" => "User as an object", "type" => "user",'
            . '          "return_format" => "object"),'
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
            . '                "post_type" => array("post"), "return_format" => "object"),'
            // A GROUP AND A NESTED FLEXIBLE CONTENT, and they are the fixture round 1 did not have -
            // which is the whole reason its blocker passed. For a CONTAINER sub-field the stored meta
            // is a MARKER, not a value: a group stores nothing at its own key, a nested Flexible
            // Content stores its layout-name array. A reconstruction that read the meta directly got
            // `false` for the first and a TypeError for the second.
            . '          array("key" => "field_' . $run . '_gg", "name" => "meta",'
            . '                "label" => "Meta", "type" => "group", "sub_fields" => array('
            . '                  array("key" => "field_' . $run . '_ggc", "name" => "caption",'
            . '                        "label" => "Caption", "type" => "text")'
            . '                )),'
            . '          array("key" => "field_' . $run . '_gn", "name" => "inner",'
            . '                "label" => "Inner blocks", "type" => "flexible_content", "layouts" => array('
            . '                  "layout_' . $run . '_inner" => array("key" => "layout_' . $run . '_inner",'
            . '                    "name" => "block", "label" => "Inner block", "display" => "block",'
            . '                    "sub_fields" => array('
            . '                      array("key" => "field_' . $run . '_gnb", "name" => "body",'
            . '                            "label" => "Body", "type" => "text")'
            . '                    ))'
            . '                )),'
            // THE TWO CLONE SHAPES, AND THEY ARE NOT THE SAME SHAPE (round 3, review 74 S6).
            // MEASURED: a SEAMLESS clone with prefix_name is FLATTENED by ACF into the resolved
            // layout definition - `fields_title` and `fields_target` arrive as ordinary leaf
            // sub-fields and no `clone` entry exists at all - which is jaygroup's shape, 0 clone
            // entries and 189 flattened children across its 36 layouts. A clone with
            // `display: group` stays a CONTAINER: the definition holds `boxed:clone`, the meta row
            // at its own key is '', and a bare meta read gives `false`. Same source group, same
            // storage keys, two different things to get right.
            . '          array("key" => "field_' . $run . '_gsc", "name" => "fields",'
            . '                "label" => "Seamless clone", "type" => "clone",'
            . '                "clone" => array("group_' . $run . '_clonesrc"),'
            . '                "display" => "seamless", "prefix_name" => 1, "prefix_label" => 0),'
            . '          array("key" => "field_' . $run . '_gbc", "name" => "boxed",'
            . '                "label" => "Boxed clone", "type" => "clone",'
            . '                "clone" => array("group_' . $run . '_clonesrc"),'
            . '                "display" => "group", "prefix_name" => 1, "prefix_label" => 0)'
            . '      ))'
            . '    ))'
            . '  ),'
            . '  "location" => array(array(array("param" => "post_type", "operator" => "==", "value" => "post")))'
            . ' ));'
            . '});'
            // AND THE STORE WARMER, which is the only way to reproduce S4 from outside the site.
            // ACF's value store is per-request and `acf_get_value()` returns from it before
            // `acf/load_value` fires, so a disabled row's index reaches the tool only if something
            // put it there. On a real site the warmer is another plugin - jaygroup runs ACF Extended
            // on exactly this field type. Here it is one read on `rest_api_init`, which runs before
            // the route callback and therefore before the tool attaches its own subscriber, and it is
            // gated on a PER-REQUEST header so the same class can read the same object both ways.
            . ' add_action("rest_api_init", function () {'
            . '  if (empty($_SERVER["HTTP_' . str_replace('-', '_', strtoupper(self::WARM_HEADER)) . '"])) { return; }'
            . '  $id = (int) $_SERVER["HTTP_' . str_replace('-', '_', strtoupper(self::WARM_HEADER)) . '"];'
            . '  if ($id > 0 && function_exists("get_field")) { get_field("' . $prefix . 'blocks", $id); }'
            . ' }, 20);';
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
    private function read(string $token, array $arguments = [], array $headers = []): array
    {
        $result = $this->mcp($token)->callTool(
            'get-acf-values',
            array_merge(['object_type' => 'post', 'id' => self::$host], $arguments),
            $headers
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
     * SPRINT CORE-FIX, ITEM 7(a): the un-renamed label is the one ACF'S FILTER produced, and the
     * rename still wins over it.
     *
     * THE DEFECT, AND IT IS ONLY VISIBLE WITH THE FILTER ATTACHED. The label used to be
     * `$layout['label']` read straight out of the field group, while wp-admin prints
     * `ACF_Field_Flexible_Content::get_layout_title()`, which runs
     * `acf/fields/flexible_content/layout_title` and its `/name=` and `/key=` variants - three
     * DOCUMENTED filters. So on any site using them wp-admin and this tool disagreed about the
     * label, and D29 is precisely "report the label the editor sees". The test above proves
     * nothing about it: with no filter attached both routes answer `Hero`, which is exactly why
     * the defect survived a passing suite.
     *
     * A SECOND MU-PLUGIN, DROPPED AND REMOVED INSIDE THIS TEST, because the filter has to run in
     * the REQUEST under test and the class's own mu-plugin is read by every other test here - a
     * filter left in it would change the labels they assert. `finally` removes it whatever
     * happens, and MuPlugin::drop() arms the run transient that makes the file inert anyway.
     *
     * AND THE RENAME STILL WINS, asserted in the same read. `get_layout_title()` CANNOT return a
     * rename - it constructs its Layout with `$renamed = ''`, and the precedence is printed in a
     * private render method - so "call ACF's method" and "report the editor's rename" are two
     * claims, and a fix that only did the first would report `HERO (filtered)` for the row the
     * editor renamed.
     *
     * @group acf-data
     */
    public function testAnUnrenamedLayoutLabelIsTheOneAcfsOwnFilterProduced(): void
    {
        $slug = 'acf-layout-title-filter';

        MuPlugin::drop($slug, self::layoutTitleFilterSource());

        try {
            $rows = self::field($this->read(self::$adminToken), self::fieldPrefix() . 'blocks')['rows'];
        } finally {
            MuPlugin::remove($slug);
        }

        self::assertSame(
            ['Hero :: Row zero & more', 'Editor renamed me', 'Hero :: Row two & more'],
            array_column($rows, 'label'),
            'The label a site\'s own acf/fields/flexible_content/layout_title filter produces is'
            . " not what this tool reports, so wp-admin and get-acf-values disagree about the label"
            . ' the editor sees. `:: NULL` means the filter RAN and could not SEE the row - the row'
            . ' it was given is keyed by sub-field NAME and get_sub_field() resolves by KEY, which'
            . ' is review 81 B1. The renamed row must still report the rename: ACF applies the'
            . ' rename at render time and get_layout_title() cannot return one.'
        );

        // AND THE ENTITY IS DECODED, not shipped as HTML. get_layout_title() returns
        // `wp_kses( apply_filters( ..., esc_html( $label ) ), 'acf' )` because its own caller
        // prints into a wp-admin span; our label goes onto a JSON wire, where `&amp;` is not an
        // ampersand. The filter above appends ` & more` to one variant for exactly this.
        self::assertStringNotContainsString(
            '&amp;',
            implode(' ', array_column($rows, 'label')),
            "ACF's HTML escaping is reaching the wire: the caller reads `&amp;` where the editor"
            . ' sees `&`. wp_specialchars_decode(..., ENT_QUOTES) is the inverse of the'
            . ' _wp_specialchars() esc_html() applied.'
        );
        self::assertStringContainsString(
            '&',
            implode(' ', array_column($rows, 'label')),
            'The ampersand the filter put in the title is gone altogether, so it was stripped'
            . ' rather than decoded.'
        );
    }

    /**
     * SPRINT CORE-FIX ROUND 2: the same fix, on the row ACF DROPPED - which has no raw row to pass
     * and must be REBUILT rather than passed empty.
     *
     * WHY IT NEEDS ITS OWN POST. The class fixture's disabled row is also RENAMED, so its label
     * comes from the rename and never from the filter - the test above cannot reach the dropped-row
     * path at all. This builds one post whose second row is disabled and NOT renamed, so the label
     * is the filtered title and the filter has to see a row `load_value()` skipped.
     *
     * WHAT GOES WRONG WITHOUT THE REBUILD, and it is not "the filter sees nothing":
     * `get_sub_field_object()` falls back to `acf_get_value($row['post_id'], $sub_field)`, and
     * `Layout::get_title()` opens its loop with `post_id => 0` - so `acf_get_valid_post_id(0)`
     * GUESSES from `get_the_ID()` and then the queried object, which in a REST request is not this
     * caller's object. The answer is unpredictable rather than empty, which is worse.
     *
     * @group acf-data
     */
    public function testADroppedRowsLabelIsBuiltFromTheRowAcfSkipped(): void
    {
        $slug   = 'acf-layout-title-filter';
        $prefix = self::fieldPrefix();
        $host   = Fixtures::createPost(Fixtures::name('acf-dropped-label-host'), 'publish', self::$authorId, 'dropped label');

        WpCli::evaluate(sprintf(
            'update_field(%s, array('
            . '   array("acf_fc_layout" => "hero", "heading" => "Kept row"),'
            . '   array("acf_fc_layout" => "hero", "heading" => "Dropped row",'
            . '         "acf_fc_layout_disabled" => 1)'
            . ' ), %d);'
            . ' echo "ok";',
            self::phpString($prefix . 'blocks'),
            $host
        ));

        MuPlugin::drop($slug, self::layoutTitleFilterSource());

        try {
            $rows = self::field($this->read(self::$adminToken, ['id' => $host]), $prefix . 'blocks')['rows'];
        } finally {
            MuPlugin::remove($slug);
        }

        self::assertSame(
            [false, true],
            array_column($rows, 'disabled'),
            'The fixture is not the shape this test is about: it needs a kept row and a DISABLED,'
            . ' un-renamed one.'
        );
        self::assertSame(
            [false, false],
            array_column($rows, 'renamed'),
            'The dropped row is reported as renamed, so its label would come from the rename and'
            . ' this test would prove nothing about the filter.'
        );

        self::assertSame(
            ['Hero :: Kept row & more', 'Hero :: Dropped row & more'],
            array_column($rows, 'label'),
            "The dropped row's label was not built from the dropped row's OWN values. `:: NULL`"
            . ' means it was passed an empty row and ACF\'s fallback found nothing; the other row\'s'
            . ' heading means it was passed the wrong row. `load_value()` skips a disabled row, so'
            . ' the row the filter needs has to be rebuilt the way load_value() would have built it'
            . ' - keyed by sub-field KEY, unformatted.'
        );

        Fixtures::deletePost($host);
    }

    /**
     * The mu-plugin body for the two tests above: one filter on ACF's own documented hook, and it
     * READS THE ROW, because that is the whole question.
     *
     * ROUND 1'S FILTER RETURNED `$title . " [filtered] & more"` and that is why the defect shipped:
     * a filter that ignores the loop proves the filter FIRES and nothing about what it can SEE. ACF
     * documents exactly one example of this filter and it reads a sub field
     * (`if ($text = get_sub_field('text')) { $title .= '<b>' . esc_html($text) . '</b>'; }`), so the
     * documented use is the use the test has to make.
     *
     * NULL IS SPELLED OUT rather than concatenated away, because `$title . null` is `$title` and a
     * missing row would then look like a filter that simply did not append anything.
     *
     * `/name=` RATHER THAN THE BASE HOOK, deliberately: the base hook is the one a reader expects
     * and the `/name=` variant is the one a site actually uses, and both are applied by
     * `Layout::get_title()` in that order. Using the narrow one proves the whole family arrives.
     * The field's `_name` is the fixture's prefixed `..._blocks`.
     *
     * ` & more` STAYS, for the entity half: `wp_kses()` normalises the bare `&` to `&amp;`, so a
     * label that reaches the wire with `&amp;` in it is ACF's escaping shipped undecoded.
     */
    private static function layoutTitleFilterSource(): string
    {
        return 'add_filter('
            . '"acf/fields/flexible_content/layout_title/name=" . ' . self::phpString(self::fieldPrefix() . 'blocks') . ','
            . ' function ($title, $field, $layout, $i) {'
            . ' $heading = get_sub_field("heading");'
            . ' if ($heading === null) { $heading = "NULL"; }'
            . ' if ($heading === "") { $heading = "EMPTY"; }'
            . ' return $title . " :: " . $heading . " & more"; }, 10, 4);';
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
            [
                $prefix . 'blocks_1_heading',
                $prefix . 'blocks_1_target',
                $prefix . 'blocks_1_meta',
                $prefix . 'blocks_1_inner',
                // THE SEAMLESS CLONE IS FLATTENED BY ACF - these two are leaf sub-fields of the
                // layout, not children of a `clone` entry, and no clone entry exists at all.
                $prefix . 'blocks_1_fields_title',
                $prefix . 'blocks_1_fields_target',
                // AND THE BOXED CLONE IS NOT. One entry, holding its children.
                $prefix . 'blocks_1_boxed',
            ],
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
            (int) $values[$prefix . 'blocks_1_target']['id'],
            'The dropped row\'s reference does not point at the fixture target.'
        );

        // THE TWO CONTAINER SUB-FIELDS, WHICH ARE THE ROUND-1 BLOCKER. For a container the stored
        // meta is a MARKER and not a value, so reading the meta directly and formatting it gives
        // `false` for a group and a TypeError for a nested Flexible Content. Only
        // `acf_get_value()` - the call ACF's own load_value() makes for each sub-field of a row -
        // runs the sub-field type's loader and turns the marker into rows. jaygroup's entire layout
        // set is a seamless clone, which fails the same way, so this is the case the feature exists
        // for rather than an edge.
        self::assertSame(
            ['caption' => 'Caption inside a group'],
            $values[$prefix . 'blocks_1_meta'],
            'A GROUP sub-field of a dropped row came back as something other than its own fields.'
            . ' `false` here means the value was read straight out of the meta table, where a'
            . ' group stores nothing at its own key.'
        );
        self::assertIsArray(
            $values[$prefix . 'blocks_1_inner'],
            'A NESTED flexible_content sub-field of a dropped row is not an array of rows. Read'
            . " from the meta table it is the stored layout-name array, and ACF's own"
            . ' format_value() then indexes a string - a TypeError on PHP 8.3 that turns the whole'
            . ' read into -32603.'
        );
        self::assertSame(
            'block',
            $values[$prefix . 'blocks_1_inner'][0]['acf_fc_layout'] ?? null,
            'The nested block did not come back with its layout.'
        );
        self::assertStringContainsString(
            'Body inside a nested block',
            (string) json_encode($values[$prefix . 'blocks_1_inner']),
            "The nested block's own sub-value is missing."
        );

        // THE TWO CLONE SHAPES (round 3, review 74 S6). jaygroup's ONLY Flexible Content field has
        // a seamless prefix_name clone as the sole sub-field of 35 of its 36 layouts, so this is
        // the read a migration of the real data will actually perform - and until round 3 it was
        // held by no test at all.
        //
        // MEASURED, AND IT CORRECTS THE ROUND-1 BLOCKER'S ACCOUNT OF JAYGROUP: a SEAMLESS clone is
        // FLATTENED by ACF into the resolved layout definition, so its children arrive as ordinary
        // LEAF sub-fields and the bare-meta read of round 1 would have answered them correctly.
        // Verified against jaygroup's own field, not only this fixture: 36 layouts, ZERO `clone`
        // entries in the resolved definition, 189 flattened `fields_*` children. The container
        // failure the blocker was really about needs a clone with `display: group`, which is the
        // second one here.
        self::assertSame(
            'Title through a seamless clone',
            $values[$prefix . 'blocks_1_fields_title'],
            "A seamless clone's flattened child did not come back. Its stored key is"
            . ' {parent}_{index}_{clone}_{child}, which is jaygroup\'s content_0_fields_title shape.'
        );
        self::assertSame(
            self::$draft,
            (int) ($values[$prefix . 'blocks_1_fields_target']['id'] ?? 0),
            "A reference inside a seamless clone did not survive, or did not go through the"
            . ' narrowing that gives it an `id`.'
        );

        // AND THE BOXED CLONE IS THE CONTAINER SHAPE, which is where the round-1 read really broke:
        // the meta row at the clone's own key is the empty string, so formatting it returns `false`
        // at Clone::format_value()'s empty() guard. acf_get_value() expands it instead.
        self::assertIsArray(
            $values[$prefix . 'blocks_1_boxed'],
            'A clone with display: group came back as something other than its own fields. `false`'
            . " here means the value was read from the meta table, where that clone's own key holds"
            . ' the empty string.'
        );
        self::assertSame(
            'Title through a boxed clone',
            $values[$prefix . 'blocks_1_boxed']['title'] ?? null,
            "The boxed clone's child is keyed by something other than its unprefixed name."
        );
        self::assertSame(
            self::$draft,
            (int) ($values[$prefix . 'blocks_1_boxed']['target']['id'] ?? 0),
            'A reference inside a boxed clone did not survive.'
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
        self::assertSame(self::$draft, (int) $adminHidden['id']);

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
        self::assertSame(self::$published, (int) $authorOpen['id']);

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
     * The read says which of the guarantees this site provides, in its own output (item 7).
     *
     * THREE NOW, NOT TWO (sprint CORE-FIX round 2, review 81 S3). `layout_title` is a separate
     * capability from `layout_metadata` because the two have separate floors: the
     * `acf/fields/flexible_content/layout_title` filter family and `get_layout_title()` predate the
     * 6.5 disable/rename accessors, so an ACF Pro 5.11-6.4 site answers `layout_title: true,
     * layout_metadata: false` - it filters its layout titles and has no disable feature. Reporting
     * them as one had folded those two facts together and left the label wrong on that whole
     * version range.
     *
     * @group acf-data
     */
    public function testTheReadStatesWhichGuaranteesTheSiteProvides(): void
    {
        $read = $this->read(self::$adminToken);

        self::assertSame(
            ['values' => true, 'layout_metadata' => true, 'layout_title' => true],
            $read['acf'],
            'The read does not state which halves of the ACF face this site provides, so a caller'
            . ' cannot tell "no disabled layouts" from "this ACF cannot report them", or "the label'
            . ' is filtered" from "the label is the stored one".'
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

    /**
     * S4, AND THE QUEEN RANKED IT ABOVE THE BLOCKER: a disabled row survives a WARM value store.
     *
     * `acf_get_value()` returns from ACF's per-request values store BEFORE `acf/load_value` fires,
     * so the priority-9 subscriber that captures the raw layout-name array never runs if anything
     * read the field earlier in the same request. The first version built the row index set from the
     * formatted keys plus that capture, so the disabled index was in NEITHER and the row simply was
     * not there - no marker, no error, nothing. That is worse than a wrong value: a wrong value is
     * visible and a missing row is not, and D29 exists for exactly this.
     *
     * THE WARMING IS REAL AND NOT SIMULATED. The mu-plugin reads the field on `rest_api_init`, which
     * runs before the route callback and therefore before the tool attaches its own subscriber - the
     * same position another plugin occupies. jaygroup runs ACF Extended on this field type.
     *
     * A TEST THAT ONLY EVER RUNS COLD CANNOT FAIL ON THIS, which is why the header exists.
     *
     * @group acf-data
     */
    public function testADisabledRowSurvivesAWarmValueStore(): void
    {
        $prefix = self::fieldPrefix();
        $rows   = self::field(
            $this->read(self::$adminToken, [], [self::WARM_HEADER => (string) self::$host]),
            $prefix . 'blocks'
        )['rows'];

        self::assertSame(
            [0, 1, 2],
            array_column($rows, 'index'),
            'With ACF\'s value store already warm, a row is missing. The union of row indices must'
            . ' include the disabled ones from get_disabled_layouts(), which reads meta and never'
            . ' the store, or a row can vanish with nothing anywhere saying it was there.'
        );
        self::assertSame([false, true, false], array_column($rows, 'disabled'));

        // AND IT IS STILL FULLY DESCRIBED, because the tool flushes that one field's three store
        // keys and reads it again when the capture came back empty - so the layout NAME, the
        // editor's label and the row's own values all survive a warm store too.
        self::assertSame(
            ['hero', 'gallery', 'hero'],
            array_column($rows, 'layout'),
            "A warm store cost the dropped row its layout NAME. acf_flush_value_cache() on that one"
            . ' field is what makes acf/load_value fire on the second read.'
        );
        self::assertSame('Editor renamed me', $rows[1]['label']);
        self::assertSame(
            'Row one is switched off',
            $rows[1]['values'][$prefix . 'blocks_1_heading'] ?? null,
            "A warm store cost the dropped row its values."
        );
    }

    /**
     * NO LIVE WORDPRESS OBJECT REACHES THE WIRE - measured on the bytes, not on the shape.
     *
     * `wp_json_encode()` on a `WP_Post` serialises all 24 properties, `post_password` in PLAINTEXT
     * among them; on a `WP_User` it serialises `data`, which carries `user_pass` and
     * `user_activation_key`. Both are values ACF's `'standard'` formatter really produces for
     * `return_format: object`, and neither is stopped by ACF's own reduction - a password-protected
     * post is `publish`, so core's `check_read_permission()` says yes to anybody, and the 6.8.7 user
     * sanitiser short-circuits for a caller with `list_users`.
     *
     * ASSERTED ON THE SERIALISED TEXT because that is what the caller receives: a decoded-structure
     * assertion cannot tell a property that is absent from one nested three levels down.
     * `get-user`'s own description promises "Never returns passwords, keys, sessions or user meta",
     * so this is a promise this server already makes.
     *
     * @group acf-data
     */
    public function testNoPasswordHashOrPostPasswordReachesTheWire(): void
    {
        $prefix = self::fieldPrefix();
        $result = $this->mcp(self::$adminToken)->callTool(
            'get-acf-values',
            ['object_type' => 'post', 'id' => self::$host]
        );

        self::assertFalse($result->isError, $result->text);

        foreach ([
            'post_password'        => 'a post password field',
            'wpmcp-fixture-secret' => "the protected post's password in plaintext",
            'THE PROTECTED BODY'   => "the protected post's body",
            'user_pass'            => "a user's password hash",
            'user_activation_key'  => 'a user activation key',
            'allcaps'              => "a user's whole capability map",
        ] as $needle => $what) {
            self::assertStringNotContainsString(
                $needle,
                $result->text,
                "The read put {$what} on the wire. wp/v2 serves none of these and neither does this"
                . " plugin's get-post or get-user, so an ACF field must not be the way round them."
            );
        }

        $read = $result->data();

        // AND THE FIELDS ARE STILL THERE AND STILL USEFUL, or this test would pass on a tool that
        // returned nothing at all.
        $protected = self::field($read, $prefix . 'protected_ref')['value'];

        self::assertSame(self::$protected, (int) $protected['id']);
        self::assertTrue(
            (bool) $protected['password_protected'],
            'A password-protected target is not marked as one, so its missing body reads as an'
            . ' empty post rather than as a withheld one.'
        );
        self::assertArrayNotHasKey('content', $protected, "A protected post's body was returned.");

        $open = self::field($read, $prefix . 'open_ref')['value'];

        self::assertFalse((bool) $open['password_protected']);
        self::assertArrayHasKey(
            'content',
            $open,
            'An unprotected post lost its body too, so the withholding is not conditional.'
        );

        $user = self::field($read, $prefix . 'user_obj')['value'];

        self::assertSame(self::$adminId, (int) $user['id']);
        self::assertArrayHasKey(
            'login',
            $user,
            'An administrator reading a user field did not get the privileged fields get-user gives'
            . ' the same caller.'
        );
    }

    /**
     * AND A USER FIELD IS ALREADY REDUCED BY ACF ITSELF for a caller without `list_users` - which is
     * the 6.8.7 sanitiser, and it is why this module's own user narrowing is defence in depth rather
     * than the only gate.
     *
     * MEASURED, AND IT CORRECTED MY EXPECTATION: the Author gets a bare integer, not a narrowed
     * object. `acf_rest_apply_user_data_sanitizer()` short-circuits for
     * `current_user_can('list_users')` and otherwise reduces the user to its ID, so the only caller
     * who reaches `wpmcp_acf_user_out()`'s privileged branch is one who HAS `list_users` - exactly
     * the caller `get-user` gives those four fields to. The narrowing still matters for the case
     * ACF's sanitiser does not cover: any other field type, now or later, that returns a `WP_User`.
     *
     * @group acf-data
     */
    public function testAUserFieldIsReducedByAcfForACallerWithoutListUsers(): void
    {
        $user = self::field(
            $this->read(self::$authorToken),
            self::fieldPrefix() . 'user_obj'
        )['value'];

        self::assertSame(
            self::$adminId,
            $user,
            "An Author was handed something other than the user's bare ID. ACF's own 6.8.7 user"
            . ' sanitiser reduces a user field for a caller without list_users, and this read goes'
            . ' through it.'
        );

        // AND THE ADMINISTRATOR IS NOT REDUCED, or the assertion above would pass on a tool that
        // returned an ID to everybody - and the shape it gets must still be get-user's field set
        // rather than the whole WP_User row.
        $asAdmin = self::field($this->read(self::$adminToken), self::fieldPrefix() . 'user_obj')['value'];

        self::assertIsArray($asAdmin, 'An administrator was reduced to an ID too.');
        self::assertSame(
            ['id', 'name', 'login', 'email', 'roles', 'registered'],
            array_keys($asAdmin),
            "A user reaching a caller with list_users does not carry get-user's own field set."
        );
    }

    /** Single-quoted for `wp eval`, which carries the snippet as one argv element. */
    private static function phpString(string $value): string
    {
        return "'" . addcslashes($value, "'\\") . "'";
    }
}
