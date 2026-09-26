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
            . ' `&amp;` where the editor sees `&`. wp_specialchars_decode(..., ENT_QUOTES) is the'
            . " exact inverse of the _wp_specialchars() that esc_html() applied."
        );
    }

    /**
     * BOTH NEW SYMBOLS ARE IN THE FACE, AND EACH IS IN THE HALF THAT CAN CARRY IT.
     *
     * `acf_get_valid_post_id` is REQUIRED: every read goes through it, and it is `@since 5.0.0`,
     * so it is present wherever the 5.11 floor is and the floor does not move.
     *
     * `get_layout_title` is OPTIONAL, in the existing `layout_metadata` capability, and that is
     * the part a future author must not "tidy". A required METHOD is probed by
     * wpmcp_module_face_missing() at `plugins_loaded`, where ACF's field types have not been
     * registered yet (`acf/include_field_types` fires from `init` priority 5) - so
     * `acf_get_field_type('flexible_content')` is NULL, the probe reports the method missing, and
     * the module never registers on a site that has everything. The module's own header says this;
     * this asserts it.
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
        self::assertContains(
            'get_layout_title',
            $face['optional']['layout_metadata']['methods'][0]['names'],
            'get_layout_title is not declared, so ModuleApiFaceTest reports it as a foreign symbol'
            . ' the module reaches for undeclared - and on ACF Pro below 6.5 the module would call'
            . ' a method that is not there.'
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

        // AND THE OPTIONAL HALF STILL DOES NOT GATE REGISTRATION. A new name in the optional
        // methods list must not turn up here, or every ACF Pro site below 6.5 loses its values.
        self::assertNotContains(
            '->get_layout_title()',
            $missing,
            'get_layout_title is being treated as required. Flexible Content is a PRO field type,'
            . ' so where the layout accessors are missing there are no layout rows to label and'
            . ' refusing to serve VALUES over it invents a middle state.'
        );

        self::assertSame(
            ['layout_metadata' => false],
            \wpmcp_module_face_capabilities('acf'),
            'The optional capability is no longer reported as one capability, so the tool cannot'
            . ' state which of the two guarantees the host site provides.'
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
        // (class-wp-comment-query.php:132-134, :1169). Replacing our clause with it is a refactor
        // and out of this sprint's scope; shipping a false reason for not doing so is not.
        $tools = RepoFile::read('tools.php');

        self::assertStringNotContainsString(
            'with no filter to narrow them',
            $tools,
            "list-comments still says WP_Comment_Query's search columns cannot be narrowed."
        );
        self::assertStringContainsString(
            'get_search_sql( $search, $columns )',
            $tools,
            'The correction naming the reachable method is gone, so the next author reads a closed'
            . ' door where there is an open one.'
        );
    }
}
