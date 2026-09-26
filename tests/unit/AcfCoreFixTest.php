<?php
/**
 * SPRINT CORE-FIX, ITEM 7: the two ACF defects, on a site with no ACF.
 *
 * WHY THIS FILE IS IN THE GATE GROUP AT ALL, AND WHERE THE REST IS. A gate group must hold only
 * tests that pass with ACF absent, because CI fails a gate group with even one skip and CI has no
 * ACF. Everything here is about the module's DECLARED FACE and its SOURCE - which symbols it
 * depends on, and that the call sites go through the platform rather than beside it - and none of
 * that needs ACF. The two values a live ACF changes (a filtered layout title, an options id with a
 * language suffix) are asserted in tests/integration/AcfValueReadTest.php under `acf-data`, which
 * is in no gate group on purpose.
 *
 * AND THE FACE HALF IS NOT BOOKKEEPING. tests/unit/ModuleApiFaceTest.php tokenises each module and
 * FAILS when it calls a foreign symbol its face does not declare, so both new ACF symbols had to
 * be declared or this sprint's gate would have gone red with no obvious cause. That gate working
 * is the D30 mechanism doing its job; the assertions below are the other direction - that the
 * declaration is in the RIGHT HALF of the face, because a required METHOD would be probed at
 * `plugins_loaded`, answer "missing", and stop the module registering on a site that has
 * everything.
 *
 * @group sprint-core-fix
 */

declare(strict_types=1);

namespace WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WpMcp\Tests\Support\RepoFile;
use WpMcp\Tests\Support\WordPressRuntime;
use WpMcp\Tests\Support\WordPressStubs;

final class AcfCoreFixTest extends TestCase
{
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
     * ITEM 7(b). Every ACF object id is built by ACF, at all four locations.
     *
     * The four shapes - a bare id, `term_%d`, `user_%d`, `options` - were hand-built, and the
     * shape is not the whole of the identifier: `acf_get_valid_post_id()` appends a LANGUAGE
     * SUFFIX to `options` (includes/api/api-helpers.php:2311-2318) and runs the documented
     * `acf/pre_load_post_id` and `acf/validate_post_id` filters.
     *
     * AND THE COST LANDS ON EXACTLY ONE READER, WHICH IS THE ONE THIS MODULE LEANS ON.
     * `get_field_object()`, `get_field_objects()` and `acf_format_value_for_rest()` normalise on
     * the way in; `acf_get_value()` does NOT (includes/acf-value-functions.php:78-130), and that
     * is the call wpmcp_acf_dropped_row_values() makes for a row ACF dropped. So on a
     * multilingual site a dropped row's values came out of the DEFAULT-language options store
     * while every surviving row came out of the current one - two halves of one answer, from two
     * stores, with nothing saying so.
     *
     * A SOURCE ASSERTION BECAUSE THE FUNCTION BEING CALLED IS ACF'S. wpmcp_acf_resolve() cannot
     * run here: there is no ACF in this process, which is the same reason this file is allowed in
     * the gate group. What can be asserted without ACF is that the four sites go through one
     * helper and the helper goes through ACF - and the VALUE the change produces is asserted over
     * HTTP against real ACF in the `acf-data` group.
     *
     * @group sprint-core-fix
     */
    public function testEveryAcfObjectIdIsNormalisedByAcf(): void
    {
        $source = RepoFile::read('modules/acf.php');

        self::assertStringContainsString(
            'return acf_get_valid_post_id($raw);',
            $source,
            'wpmcp_acf_object_id() no longer puts the identifier through ACF. The shapes are right'
            . " and the shape is not the whole answer: ACF's own normaliser appends the language"
            . ' suffix to `options` and runs two documented filters.'
        );

        // ALL FOUR, AND NAMED, because "the helper exists" and "every call site uses it" are two
        // claims and the second is the one that was wrong.
        foreach ([
            "'acf_id' => wpmcp_acf_object_id('options')",
            "'acf_id' => wpmcp_acf_object_id(\$id)",
            "'acf_id' => wpmcp_acf_object_id('term_' . \$id)",
            "'acf_id' => wpmcp_acf_object_id('user_' . \$id)",
        ] as $site) {
            self::assertStringContainsString(
                $site,
                $source,
                "One of the four object-id sites is hand-built again: {$site} is not there. A"
                . ' location that skips the normaliser reads from a different store than the other'
                . ' three on a multilingual site.'
            );
        }

        // AND NOTHING BUILDS ONE BESIDE IT. The two prefixed shapes must appear ONLY inside the
        // helper's argument - a second `'term_' . $id` anywhere else is the defect coming back.
        foreach (["'term_' . \$id", "'user_' . \$id"] as $shape) {
            self::assertSame(
                1,
                substr_count($source, $shape),
                "The shape {$shape} is built in more than one place, so one of them is not going"
                . ' through ACF.'
            );
        }
    }

    /**
     * ITEM 7(a). The layout label's un-renamed half comes from ACF's own method.
     *
     * `ACF_Field_Flexible_Content::get_layout_title()` is PUBLIC
     * (pro/fields/class-acf-field-flexible-content.php:1367) and delegates to `Layout::get_title()`,
     * which runs `acf/fields/flexible_content/layout_title` and its `/name=` and `/key=` variants.
     * This module read `$layout['label']` out of the field group instead, so on any site using
     * that filter family wp-admin and our `label` DISAGREED - and D29 is precisely "report the
     * label the editor sees".
     *
     * THE RENAME PRECEDENCE STAYS OURS AND THAT IS A LEDGER ROW, NOT AN OMISSION.
     * `get_layout_title()` constructs its Layout with `$renamed = ''`, so it can never return a
     * rename; the precedence is printed in `Layout::action_buttons()`, a PRIVATE render method. So
     * the ternary below is ours because ACF exposes no way to ask for it, and the assertion keeps
     * both halves: the rename wins, and the fallback is ACF's call rather than our reading of
     * ACF's field group.
     *
     * @group sprint-core-fix
     */
    public function testTheLayoutLabelAsksAcfForTheTitleItFilters(): void
    {
        $source = RepoFile::read('modules/acf.php');

        self::assertStringContainsString(
            '->get_layout_title(',
            $source,
            "The layout label is derived from the field group's own `label` again, so a site using"
            . ' the documented acf/fields/flexible_content/layout_title filter family sees one'
            . ' label in wp-admin and another one from this tool.'
        );
        self::assertStringNotContainsString(
            "(isset(\$layouts[\$layoutName]['label']) ? (string) \$layouts[\$layoutName]['label'] : '')",
            $source,
            'The hand-derived fallback is back beside the call.'
        );

        // THE RENAME STILL WINS. Without this, a "fix" that called the method and dropped the
        // rename would pass the assertion above and report the ORIGINAL label for every layout an
        // editor renamed - which is the feature, not a detail.
        self::assertMatchesRegularExpression(
            "/'label'\s*=> isset\(\\\$renamed\[\\\$index\]\) && \\\$renamed\[\\\$index\] !== ''\s*\n\s*\? \(string\) \\\$renamed\[\\\$index\]\s*\n\s*: wpmcp_acf_layout_label\(/",
            $source,
            'The rename no longer takes precedence over the filtered title.'
            . " `get_layout_title()` CANNOT return a rename - it builds its Layout with"
            . ' $renamed = "" - so calling it alone reports the original label for every renamed'
            . ' layout.'
        );

        // AND THE ESCAPING IS UNDONE EXACTLY ONCE. `Layout::get_title()` returns
        // `wp_kses( apply_filters( ..., esc_html( $label ) ), 'acf' )`, because its only caller in
        // ACF echoes it into a wp-admin span. Our `label` goes onto a JSON wire, where `&amp;` is
        // not an ampersand - so shipping ACF's output verbatim is item 5's defect in another file.
        self::assertStringContainsString(
            'wp_specialchars_decode($title, ENT_QUOTES)',
            $source,
            "ACF's HTML escaping is being shipped onto the JSON wire. get_title() applies"
            . ' esc_html() because its own caller prints into wp-admin; a caller of this tool reads'
            . ' `&amp;` where the editor sees `&`.'
        );

        // AND THE FILE NO LONGER CLAIMS THAT DECODE IS AN INVERSE (round 2, review 81 S4).
        // `esc_html()` passes `$double_encode = false`, so an entity already in the stored label
        // survives the escape and is then DECODED here: `Tom &amp;amp; Jerry` comes back as
        // `Tom &amp; Jerry`. MEASURED. The answer is the text an editor sees rendered - which is
        // the D29 answer - and it is not the stored bytes.
        self::assertStringNotContainsString(
            'byte-identical to the',
            $source,
            'The file still claims the decoded title is byte-identical to the stored label. It is'
            . ' not: esc_html() does not double-encode, so an entity already in the label is'
            . ' decoded rather than round-tripped.'
        );
        self::assertStringContainsString(
            'IT IS A RENDERING, NOT AN INVERSE',
            $source,
            'The correction to that claim is gone, so the next author inherits the false version.'
        );
    }

    /**
     * ITEM 7(a), THE HALF ROUND 1 GOT BACKWARDS: the row handed to the filter is the RAW,
     * KEY-KEYED one, and a DROPPED row is rebuilt rather than passed empty.
     *
     * WHY THIS IS THE ASSERTION THAT MATTERS. `Layout::get_title()` opens an `acf_add_loop()` so
     * the filter can read the row, and ACF's documentation gives exactly one example of the filter
     * doing so with `get_sub_field()`. `get_sub_field_object()` resolves through
     * `get_row_sub_value($sub_field['KEY'])`, so the row must be keyed by sub-field KEY - which is
     * what `load_value()` builds and what both of ACF's own callers pass. Round 1 passed
     * `acf_format_value_for_rest()`'s output, keyed by NAME, so every `get_sub_field()` answered
     * NULL and the label came back EMPTY on exactly the sites the fix was for: WORSE than the
     * stored label it replaced. MEASURED against ACF Pro 6.8.10: `'Hero :: NULL'` name-keyed
     * against `'Hero :: ROW ZERO'` key-keyed.
     *
     * AND AN EMPTY ROW IS NOT NEUTRAL EITHER. `get_sub_field_object()` falls back to
     * `acf_get_value($row['post_id'], $sub_field)` and the loop's `post_id` is 0, so
     * `acf_get_valid_post_id(0)` guesses from `get_the_ID()` and the queried object - not this
     * caller's object in a REST request. MEASURED: `'Hero :: NULL'` for a dropped row passed
     * `array()`, `'Hero :: ROW ONE'` for the same row rebuilt.
     *
     * A SOURCE ASSERTION HERE, and the behaviour over HTTP against real ACF in
     * tests/integration/AcfValueReadTest.php, whose filter now READS `get_sub_field()` - because a
     * filter that ignores the loop proves the filter fires and nothing about what it can see, which
     * is why round 1's integration test was green on the defect.
     *
     * @group sprint-core-fix
     */
    public function testTheLayoutTitleFilterIsGivenTheRawKeyKeyedRow(): void
    {
        $source = RepoFile::read('modules/acf.php');

        self::assertStringContainsString(
            "\$loopRow = \$field['value'][\$index];",
            $source,
            "A surviving row's loop row is not `\$field['value'][\$index]`, the load_value() output"
            . ' keyed by sub-field KEY. If it is the formatted value again, every get_sub_field() in'
            . ' a layout_title filter returns NULL and the label comes back empty.'
        );
        self::assertStringNotContainsString(
            'isset($formatted[$index]) ? $formatted[$index] : array()',
            $source,
            'The formatted, NAME-keyed row is being handed to the filter again. That is the defect:'
            . ' get_sub_field() resolves by sub-field KEY.'
        );

        // A DROPPED ROW IS REBUILT, NOT PASSED EMPTY - and it is rebuilt keyed by KEY.
        self::assertStringContainsString(
            "\$loopRow = \$dropped['raw'];",
            $source,
            'A dropped row is handed something other than the rebuilt raw row, so the filter falls'
            . " back to acf_get_value() with the loop's post_id of 0 and ACF guesses which object it"
            . ' is on.'
        );
        self::assertStringContainsString(
            "\$out['raw'][(string) \$sub['key']] = \$raw;",
            $source,
            "The rebuilt row is not keyed by sub-field KEY, or is not unformatted - load_value()"
            . ' stores the bare acf_get_value() result under $sub_field[\'key\'] and the filter'
            . ' reads it by that key.'
        );

        // AND `values` STILL GOES THROUGH THE ONE READ PRIMITIVE. The raw shape exists for the
        // filter's loop only; a caller must never receive an unreduced value.
        self::assertStringContainsString(
            "\$out['values'][(string) \$sub['name']] = wpmcp_acf_format(\$raw, \$object['acf_id'], \$sub);",
            $source,
            "The dropped row's wire values no longer go through wpmcp_acf_format(), so the"
            . ' permission reduction is skipped on the one path that was added for D29.'
        );
    }

    /**
     * BOTH NEW SYMBOLS ARE IN THE FACE, AND EACH IS IN THE HALF THAT CAN CARRY IT.
     *
     * `acf_get_valid_post_id` is REQUIRED: every read goes through it, and it is `@since 5.0.0`,
     * so it is present wherever the 5.11 floor is and the floor does not move.
     *
     * `get_layout_title` is OPTIONAL, and in ITS OWN capability rather than in `layout_metadata`
     * (round 2, review 81 S3). Round 1 folded it into `layout_metadata`, whose floor is ACF Pro 6.5,
     * on the premise that "where these two accessors are missing there are no rows to label" -
     * FALSE: Flexible Content rows exist in every Pro version, and 6.5 brought the disable/rename
     * FEATURE. The filter family is documented as "Added in version 5.3.6", so folding them together
     * left the label wrong on Pro 5.11-6.4 by construction. D30's rule is to gate on the SYMBOL, so
     * the symbol gets its own entry and its own reported name.
     *
     * STILL OPTIONAL, NEVER REQUIRED, and that is the part a future author must not "tidy". A
     * required METHOD is probed by wpmcp_module_face_missing() at `plugins_loaded`, where ACF's
     * field types have not been registered yet (`acf/include_field_types` fires from `init`
     * priority 5) - so `acf_get_field_type('flexible_content')` is NULL, the probe reports the
     * method missing, and the module never registers on a site that has everything. The module's
     * own header says this; this asserts it.
     *
     * @group sprint-core-fix
     */
    public function testTheTwoNewAcfSymbolsAreDeclaredInTheRightHalfOfTheFace(): void
    {
        $face = \wpmcp_module_face('acf');

        self::assertContains(
            'acf_get_valid_post_id',
            $face['required']['functions'],
            'The object-id normaliser is not in the required half of the face, so a site whose ACF'
            . ' does not have it would register the module and take a PHP fatal on the first tool'
            . ' call instead of being told which symbol is missing.'
        );

        self::assertNotEmpty($face['optional']['layout_metadata']['methods'], 'The layout-metadata methods went.');
        self::assertSame(
            ['get_disabled_layouts', 'get_renamed_layouts'],
            $face['optional']['layout_metadata']['methods'][0]['names'],
            'layout_metadata declares something other than the two 6.5 accessors it is named for.'
        );

        // AND get_layout_title IS ITS OWN CAPABILITY, WITH ITS OWN FLOOR. Folding it back into
        // layout_metadata would re-open the defect on every ACF Pro 5.11-6.4 site: the filter
        // family predates 6.5, so a site with the filter and without the disable feature would
        // silently keep the stored label while wp-admin shows the filtered title.
        self::assertArrayHasKey(
            'layout_title',
            $face['optional'],
            'get_layout_title has no capability of its own, so it is gated on a floor that is not'
            . ' its own. It and the acf/fields/flexible_content/layout_title filter family predate'
            . ' the 6.5 disable/rename accessors.'
        );
        self::assertSame(
            ['get_layout_title'],
            $face['optional']['layout_title']['methods'][0]['names'],
            'The layout_title capability does not declare exactly the one method it is for, so'
            . ' either ModuleApiFaceTest reports a foreign symbol the module reaches for undeclared,'
            . ' or the capability answers about a symbol nobody calls.'
        );

        // AND IT IS NOT IN THE REQUIRED HALF. This is the assertion the module header's warning
        // is about, and it is the one that would let a green-looking "tidy-up" break every site.
        self::assertSame(
            [],
            $face['required']['methods'],
            'The required half of the ACF face declares a METHOD. It is checked at plugins_loaded,'
            . ' where acf_get_field_type() is still NULL because ACF registers its field types on'
            . ' init priority 5 - so the probe answers "missing" and the module never registers on'
            . ' a site that has everything. If a required method ever becomes necessary, move the'
            . ' registration to init.'
        );
    }

    /**
     * THE FACE STILL REFUSES REGISTRATION WHEN A SYMBOL IS MISSING - with the two new names in it.
     *
     * The brief calls this out because it is the half that a declaration cannot prove on its own:
     * adding a name to the face is worth nothing if the check that reads the face has stopped
     * answering. There is no ACF in this process, so the required half IS missing, and the
     * assertion is that the module says so by name rather than registering and failing later.
     *
     * @group sprint-core-fix
     */
    public function testTheFaceStillRefusesRegistrationWhenASymbolIsMissing(): void
    {
        $missing = \wpmcp_module_face_missing('acf');

        self::assertContains(
            'acf_get_valid_post_id()',
            $missing,
            'The new required symbol is not reported missing on a site with no ACF, so the face'
            . ' check is not reading it and adding it to the declaration proved nothing.'
        );

        // AND NEITHER OPTIONAL CAPABILITY GATES REGISTRATION. A name in an optional methods list
        // must not turn up here, or an ACF Pro site below 6.5 loses its VALUES over a layout
        // feature it was never going to use.
        self::assertNotContains(
            '->get_layout_title()',
            $missing,
            'get_layout_title is being treated as required. It refines a layout LABEL, and refusing'
            . ' to serve values over its absence invents a middle state.'
        );
        self::assertNotContains('->get_disabled_layouts()', $missing);

        self::assertSame(
            ['layout_metadata' => false, 'layout_title' => false],
            \wpmcp_module_face_capabilities('acf'),
            'The two optional capabilities are not reported separately, so the tool cannot tell a'
            . ' caller that this site filters its layout titles but has no disable/rename feature -'
            . ' which is every ACF Pro between 5.11 and 6.4.'
        );
    }

    /**
     * THE PROSE THIS SPRINT CORRECTED STAYS CORRECTED.
     *
     * A false justification ships at the same confidence as the fix and is never re-read as a new
     * claim (`claude_code_memory/a-justification-ships-unreviewed.md`), which is why the two
     * sentences below are held by an assertion rather than by having been fixed once. Both were
     * the JUSTIFICATION for a defect in this sprint: the first said the multilingual caveat had
     * been closed by the raw-meta read, and item 7(b) is that caveat still being open; the second
     * named core as the source of a gate that is looser than core's.
     *
     * @group sprint-core-fix
     */
    public function testTheTwoFalseClaimsInTheModulesProseAreGone(): void
    {
        $source = RepoFile::read('modules/acf.php');

        self::assertStringNotContainsString(
            'multilingual-options caveat round 1 had to write down',
            $source,
            'The file claims the multilingual-options caveat was removed with the raw-meta read.'
            . ' It was not: acf_get_value() is the one ACF reader that does not normalise its'
            . ' $post_id, which is exactly the call this module makes for a dropped row.'
        );
        self::assertStringContainsString(
            'THE MULTILINGUAL-OPTIONS CAVEAT DID NOT GO WITH IT',
            $source,
            'The correction to that claim is gone, so the next author reads the false version.'
        );

        self::assertStringNotContainsString(
            "That is core's own line and it is",
            $source,
            "The user-shape docblock names core as the source of its privileged-field gate. It is"
            . " get-user's gate and it is LOOSER than core's: WP_REST_Users_Controller puts those"
            . ' four fields in the `edit` context and refuses an edit-context read of another user'
            . ' without edit_user (class-wp-rest-users-controller.php:487), which list_users does'
            . ' not satisfy.'
        );
        self::assertStringContainsString(
            "THAT IS `get-user`'s LINE, NOT CORE'S",
            $source,
            'The correction to that claim is gone.'
        );

        // AND THE THIRD, IN tools.php, FOR THE SAME REASON: list-comments said WP_Comment_Query's
        // search columns cannot be narrowed. They can - get_search_sql($search, $columns) takes
        // the column list and the class's __call() proxy forwards exactly that one method name
        // (class-wp-comment-query.php:132-134, :1169).
        //
        // SPRINT DELETIONS THEN WALKED THROUGH THE DOOR, so the assertion moved from the prose to
        // the CALL. A comment saying the method is reachable can go stale silently; a call to it
        // cannot, and it carries the correction with it. The "cannot be narrowed" claim is still
        // asserted absent, because a rewrite could reintroduce it beside a hand-built clause.
        $tools = RepoFile::read('tools.php');

        self::assertStringNotContainsString(
            'with no filter to narrow them',
            $tools,
            "list-comments still says WP_Comment_Query's search columns cannot be narrowed."
        );
        self::assertStringContainsString(
            '->get_search_sql($search,',
            $tools,
            'list-comments no longer CALLS the method whose reachability this sprint corrected, so'
            . ' the next author reads a closed door where there is an open one.'
        );
    }
}
