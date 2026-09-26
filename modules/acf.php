<?php
/**
 * Copyright (C) 2026 Max Konstantinovski. GPLv2 or later (see LICENSE).
 *
 * WP MCP MODULE: Advanced Custom Fields, READ ONLY. get-acf-values.
 *
 * ONE TOOL, VALUES ONLY, NO SCHEMA TOOLS (D9's 2026-09-23 scope cut). D9's older sprint-one
 * line listed discovery tools - `acf_get_field_groups`, `acf_get_fields` - and the later cut
 * governs: ACF's own abilities already describe field groups, and on jaygroup's seven live
 * groups every one carries `show_in_rest: 0`, so a schema tool of ours would describe fields no
 * value tool of ACF's could read. Field STRUCTURE therefore reaches the caller as METADATA ON A
 * VALUES READ - key, name, type, label, and per-row layout metadata - and never as a catalogue
 * of what this site COULD hold. That line is deliberate: "what is in this object" is about a
 * value; "what layouts exist" is a schema, and a read that dumped all 36 of jaygroup's layouts
 * would be a schema tool wearing a value tool's name.
 *
 * ------------------------------------------------------------------------------
 * WHY THE VALUES DO NOT COME FROM get_field(), WHICH IS THE ENTIRE SECURITY STORY.
 *
 * ACF checks NO capability on the value path (`analysis/71` §4), and since 6.8.7 / 6.8.10 it
 * reduces User, Relationship, Post Object, Image, Gallery, File and Icon Picker values to bare
 * IDs when the caller cannot read the referenced object - but ONLY in its REST path.
 * `get_field()` inherits none of it and hands back fully expanded post objects, attachment
 * arrays and user data. ACF's own Security Principles page says this in as many words:
 * `get_field()` is a trusted-context accessor and REST is the permission-checked surface. Our
 * tools are a REST surface with an untrusted caller, so every value here goes through
 *
 *     acf_format_value_for_rest( $raw, $object_id, $field, 'standard' )
 *
 * with `$raw` and `$field` from the one documented call `get_field_object($sel, $id, false, true)`.
 * MEASURED AT RUNTIME on genuine ACF Pro 6.8.10 before any of this was written - see
 * tests/integration/AcfRestValuePathTest.php and tests/Support/acf-rest-probe.php: in
 * `'standard'` mode the call returns byte-for-byte what `acf_format_value()` returns, plus the
 * reduction; the reduction fires for a Relationship pointing at a draft and does NOT fire for
 * one pointing at a published post; and the value store's `"$post_id:$name:formatted"` key
 * cannot leak the reduction in either direction.
 *
 * ALWAYS `'standard'`, NEVER `'light'`. `'light'` reduces everything unconditionally and calls a
 * method on `acf_get_field_type($field['type'])`, which is NULL for a type nothing registered -
 * measured, same test. `'standard'` never touches the type object.
 *
 * `escape_html` IS NOT A PARAMETER AND MUST NOT BECOME ONE (`analysis/71` §2c-2d).
 * `acf_format_value_for_rest()` calls `acf_format_value()` with three arguments, so escaping is
 * always off - which is what this plugin's contract wants: ACF escapes on the way IN, in
 * `acf-form-functions.php`, exactly as D5 has us do. And `escape_html = true` is illegal with
 * `format_value = false` (ACF withholds the value entirely) and, for the 21 of 27 field types
 * that do not declare `escaping_html`, runs `map_deep($value, 'acf_esc_html')`, which
 * string-casts every leaf and turns a non-scalar one into literal `false`.
 *
 * ------------------------------------------------------------------------------
 * A READ MIRRORS THE WP-ADMIN SCREEN (D29), WHICH IS WHY THE DISABLED ROWS ARE HERE.
 *
 * ACF 6.5 let an editor switch a Flexible Content layout OFF. The implementation is in
 * `load_value`, BEFORE any formatting, and `should_disable_layout()` returns false only when
 * `is_admin()` - which is every request of ours. So wp-admin shows four blocks with one greyed
 * out and ACF hands us three, with nothing saying a fourth exists. The driving use case is
 * moving jaygroup posts into a new template site, so a read feeds a write: a silently dropped
 * block becomes a silently deleted one.
 *
 * So every row is reported: its index, its layout name, the label the editor sees after a
 * rename, and `disabled: true` for the ones ACF dropped. The state comes from ACF's own PUBLIC
 * accessors on `acf_get_field_type('flexible_content')` - `get_disabled_layouts()` and
 * `get_renamed_layouts()`, both `@since 6.5`, both taking `$post_id` and `$field` as parameters -
 * and NOT from reading `_{field_name}_layout_meta` ourselves. D29 priced that exception in;
 * `analysis/72` made it unnecessary for the STATE, and the accessors additionally handle the
 * term / user / options locations and share ACF's own per-request cache.
 *
 * THE DROPPED ROW'S LAYOUT NAMES come from a priority-9 subscriber on the DOCUMENTED filter
 * `acf/load_value/type=flexible_content`, which sees the raw layout-name array before the drop.
 * It is attached inside the tool's own run and detached again, because a module file is loaded on
 * every request to the site and a filter left attached would run on every front-end page load
 * for the benefit of a tool nobody called.
 *
 * AND THE DROPPED ROW'S VALUES ARE READ THE WAY ACF READS THEM, which took two rounds to get
 * right and the first round was wrong in the way that mattered.
 *
 * THE SPRINT BRIEF'S ROUTE - `get_field("{$fc}_{$i}_{$sub}", $id)` - IS A TRAP, and that part of
 * round 1 stands. MEASURED on jaygroup, which is 71.5% clone composites:
 *
 *   - `get_field_object("content_0_fields_title", $page)` returns **false**. The flattened
 *     reference row `_content_0_fields_title` holds `field_69d89b38e8de9_field_69d89b0959509` -
 *     a clone COMPOSITE key, not a field key - and `acf_get_field()` cannot resolve it, so
 *     `acf_get_meta_field()` gives up. `analysis/72` §2c concluded that `acf_maybe_get_field()`
 *     "resolves exactly those"; it does not, and the reference row the scout itself quoted is a
 *     composite too.
 *   - `get_field("content_0_fields_title", $page)` DOES answer - but only because `get_field`
 *     falls back to a DUMMY text field and returns the RAW meta with `$format_value` forced to
 *     false (`analysis/71` §6.3b). So an Image sub-field comes back as a bare ID with no
 *     formatting AND no permission reduction: the exact disclosure this module exists to avoid,
 *     arriving through the one route that looked safe.
 *
 * WHAT ROUND 1 THEN DID WAS ALSO WRONG, and it failed at the only thing the feature does. It read
 * each sub-field's stored meta directly and formatted that. For a LEAF sub-field the stored meta IS
 * the value and the answer was right. For a CONTAINER the stored meta is a MARKER:
 *
 *   - a `group`, and a `clone` whose `display` is `group`, store NOTHING at their own key, so the
 *     read was `''` and `Group::format_value('')` / `Clone::format_value('')` returned `false` at
 *     their `empty()` guard - which reads to a caller as "the row was empty";
 *   - a `repeater` stores its row COUNT, which fails `is_array` and returns `false`;
 *   - a nested `flexible_content` stores its layout-NAME array, and
 *     `Flexible_Content::format_value()` then indexes a string. MEASURED at runtime: `TypeError:
 *     Cannot access offset of type string on string` at
 *     `pro/fields/class-acf-field-flexible-content.php:696`, which the error boundary turns into
 *     `-32603` - so ONE disabled row with a nested block cost the whole object read.
 *
 * AND A SEAMLESS CLONE IS NOT ONE OF THOSE SHAPES, which is worth stating because both the review
 * and this file's own first draft said it was. MEASURED on jaygroup's real field in round 3: ACF
 * FLATTENS a seamless `prefix_name` clone into the resolved layout definition, so 36 layouts carry
 * ZERO `clone` entries and 189 flattened `fields_*` children, and those children are LEAVES whose
 * stored meta IS their value. jaygroup's own 35 seamless-clone layouts were therefore never affected
 * by the defect above. Both clone shapes are now in the fixture - the seamless one because it is the
 * read a migration of the real data performs, and the `display: group` one because it is the clone
 * shape that actually broke.
 *
 * THERE IS AN ACCESSOR AND ACF USES IT ITSELF. `analysis/72` §2b says "there is no supported
 * accessor" for these values and that claim, carried into the sprint brief and then into this
 * file's own comments as measured fact, is FALSE. `Flexible_Content::load_value()` builds a
 * surviving row's values with exactly two lines:
 *
 *     $sub_field['name'] = "{$field['name']}_{$i}_{$sub_field['name']}";   // :590
 *     $sub_value         = acf_get_value( $post_id, $sub_field );          // :593
 *
 * and this module now makes the same two calls for a row ACF dropped. `acf_get_value()` runs the
 * sub-field type's own `load_value`, so a clone expands by prefixed name, a repeater and a nested
 * Flexible Content expand their rows, and the nested one drops ITS disabled rows exactly as ACF
 * would. The result still goes through the one `wpmcp_acf_format()` primitive, so the permission
 * reduction cannot be skipped for the rows ACF hid.
 *
 * SO D29'S AUTHORISED EXCEPTION IS NOT USED, and the module has no meta-layout assumption left.
 * D29 priced in reading a protected meta key directly; `analysis/72`'s public layout accessors
 * removed that for the STATE, and `acf_get_value()` removes it for the VALUES, because ACF
 * resolves the meta through its own per-location classes.
 *
 * THE MULTILINGUAL-OPTIONS CAVEAT DID NOT GO WITH IT, AND THIS PARAGRAPH SAID IT DID (sprint
 * CORE-FIX). That was FALSE, and it was false in the one direction that costs a caller a wrong
 * answer rather than a missing one. `acf_get_value()` is the ONE reader among ACF's that does NOT
 * normalise its `$post_id` (`includes/acf-value-functions.php:78-130`) - `get_field_object()`,
 * `get_field_objects()` and `acf_format_value_for_rest()` all do - and the identifier this module
 * handed it was hand-built. So on a site whose translation plugin sets ACF's `current_language`,
 * a DROPPED row's values came out of the DEFAULT-language options store while every surviving
 * row came out of the current one: two halves of one answer, from two stores, with nothing
 * saying so. `wpmcp_acf_object_id()` now puts every identifier through
 * `acf_get_valid_post_id()`, which is where the language suffix is applied, so the caveat is
 * closed by a call rather than by a sentence.
 *
 * WHAT REMAINS IS ONE UNDOCUMENTED DEPENDENCY AND IT IS A NAMING
 * CONVENTION, NOT A STORAGE LAYOUT: that a Flexible Content sub-field's name is
 * `{$parent}_{$index}_{$sub}`. It is the line above ACF's own `acf_get_value()` call, copied.
 *
 * ------------------------------------------------------------------------------
 * WHAT THIS FILE MAY CALL: WordPress, two core helpers in tools.php - wpmcp_cannot() and
 * wpmcp_post_type_ok() - the seam's own functions in modules.php, and the ACF symbols named in
 * wpmcp_acf_api_face(). It calls nothing in endpoint.php, admin.php or trace.php.
 * tests/unit/ModuleBoundaryTest.php enforces the first half and
 * tests/unit/ModuleApiFaceTest.php the second: it tokenises this file, collects every symbol it
 * actually calls that is neither this plugin's nor WordPress's nor PHP's, and fails unless the
 * face below names every one.
 *
 * THE GUARD IS EVALUATED ON `plugins_loaded` AND NOT AT FILE SCOPE, and that is not tidiness.
 * wpmcp_bootstrap() runs at wp-mcp's own plugin file scope, so at the moment this file is loaded
 * "is ACF here" is a question about PLUGIN ORDER rather than about the site. MEASURED on
 * jaygroup: `active_plugins` is not alphabetical - it opens with gravityforms - so the order is
 * whatever activation left behind, and a site with wp-mcp as an mu-plugin, or with ACF in a
 * differently-named directory, would load us first and answer "no ACF" on a site that has it.
 * `plugins_loaded` is the earliest hook at which the question is about the site, and it is still
 * long before anything reads the registry: wpmcp_module_tools() is only reached from
 * wpmcp_tools(), inside a REST request.
 *
 * AND THE REQUIRED HALF - AND ONLY THE REQUIRED HALF - IS ANSWERABLE THERE. This paragraph used to
 * claim that ACF registers its field types at its own load time. IT DOES NOT, and the correction
 * matters to the next author (review 74, S3). `acf_format_value_for_rest()` and the two documented
 * getters ARE defined at load: `acf.php:261` includes `includes/rest-api.php` from `initialize()`,
 * which runs at ACF's file scope. But the FIELD TYPES arrive on `acf/include_field_types`, fired
 * from `ACF::init()`, which is hooked to `init` at priority 5 (`acf.php:315`, `:410`; PRO hooks
 * the same action at 5 in `pro/acf-pro.php:64`). So at `plugins_loaded` priority 0,
 * `acf_get_field_type('flexible_content')` is NULL.
 *
 * That is harmless BECAUSE OF WHERE EACH HALF IS ASKED, which is the thing to keep true rather
 * than the hook number: `wpmcp_module_face_missing()` reads the REQUIRED block only, and the
 * OPTIONAL block - the one with the method probe - is asked by
 * wpmcp_acf_layout_metadata_available() inside the tool's own run, and by the settings screen,
 * both long after `init`. **So a future author must not promote a method entry to `required`**:
 * it would be checked at `plugins_loaded`, answer "missing", and the module would never register
 * on a site that has everything. If a required method ever becomes necessary, move the
 * registration to `init` - nothing reads the registry before then.
 */
if (!defined('ABSPATH')) { exit; }

/**
 * THE API FACE: every ACF symbol this module depends on, declared as data (D30).
 *
 * `function_exists('acf')` is not sufficient - it proves ACF is there, not that the face we need
 * is there. This is what wpmcp_module_face_missing('acf') checks at registration AND again inside
 * the tool's own run, and what tests/unit/ModuleApiFaceTest.php holds against the symbols this
 * file actually calls.
 *
 * REQUIRED IS THE VALUES HALF, AND ITS FLOOR IS A DETECTED ACF 5.11.
 * `acf_format_value_for_rest()` arrived in 5.11 (10 Nov 2021) with today's signature and carries
 * no `@since` tag, so its presence is the only honest test for it. `get_field_object()` and
 * `get_field_objects()` are ancient. Below 5.11 the module does not register and says so.
 *
 * OPTIONAL IS THE LAYOUT-METADATA HALF, AND ITS FLOOR IS ACF **PRO** 6.5 - and the degradation is
 * clean rather than awkward, because the requirement is co-extensive with the problem existing.
 * Flexible Content is a PRO field type, the disable and rename features are 6.5, and so are the
 * three accessors. Below Pro 6.5 there are no disabled layouts to report, so "no disabled, no
 * renamed" is the RIGHT answer and not a degraded one. There is no middle state to invent. The
 * tool says which of the two halves the host site provides, in its own output, so neither gap is
 * silent.
 *
 * @return array
 */
function wpmcp_acf_api_face() {
    return array(
        'required' => array(
            'functions' => array(
                // The formatter, and the two documented reads that feed it.
                'acf_format_value_for_rest',
                'get_field_object',
                'get_field_objects',
                // ADDED IN ROUND 2, and both are on the value path rather than beside it.
                // acf_get_value() is what ACF's own Flexible_Content::load_value() calls for each
                // sub-field of a row, and what the documented get_field_object() is built on; it
                // is `@since 5.0.0`, so it is present wherever the three above are.
                // acf_flush_value_cache() is `@since 5.7.10` and is how the value store is made to
                // miss, which is what forces acf/load_value to fire - see
                // wpmcp_acf_reload_gapped_rows() for the one thing that depends on it.
                'acf_get_value',
                'acf_flush_value_cache',
                // ADDED IN SPRINT CORE-FIX, and it is REQUIRED because every read goes through
                // it: wpmcp_acf_object_id() puts the object identifier through ACF's own
                // normaliser before anything is read with it. `@since 5.0.0`
                // (includes/api/api-helpers.php:2258), so it is present wherever the four above
                // are and the 5.11 floor is unchanged.
                'acf_get_valid_post_id',
            ),
        ),
        'optional' => array(
            // The name is reported verbatim in the tool's `acf` object, so it is the caller's
            // word for the guarantee as well as ours.
            'layout_metadata' => array(
                'functions' => array('acf_get_field_type'),
                'methods'   => array(
                    array(
                        'probe' => 'wpmcp_acf_flexible_content',
                        'names' => array('get_disabled_layouts', 'get_renamed_layouts'),
                    ),
                ),
            ),
            // THE FILTERED LAYOUT TITLE, AND IT IS ITS OWN CAPABILITY BECAUSE ITS FLOOR IS ITS OWN
            // (sprint CORE-FIX round 2, review 81 S3). Round 1 declared `get_layout_title` inside
            // `layout_metadata` and argued that where the disable/rename accessors are missing
            // there are no rows to label. FALSE: Flexible Content rows exist in every ACF Pro,
            // and 6.5 brought the disable and rename FEATURE, not the field type. The filter
            // family is "Added in version 5.3.6" and `get_layout_title()` is older than 6.5, so
            // folding the two together left the label wrong on Pro 5.11-6.4 by construction.
            //
            // D30's rule is that a face gates on the SYMBOL, so the symbol gets its own entry and
            // its own reported name. A Pro 6.0 site now answers `layout_title: true,
            // layout_metadata: false`: the label runs the filter family, and there are genuinely
            // no disabled or renamed rows to report. The two facts are independent and the tool
            // says both.
            //
            // STILL OPTIONAL, NEVER REQUIRED, for the reason the file header gives: a required
            // METHOD is probed at `plugins_loaded`, where `acf_get_field_type()` is NULL, and the
            // module would never register on a site that has everything.
            'layout_title' => array(
                'functions' => array('acf_get_field_type'),
                'methods'   => array(
                    array(
                        'probe' => 'wpmcp_acf_flexible_content',
                        'names' => array('get_layout_title'),
                    ),
                ),
            ),
        ),
    );
}

/**
 * ACF's registered Flexible Content field type, or null.
 *
 * THE SEAM'S FACE CHECK NEEDS AN OBJECT AND ONLY THIS MODULE KNOWS HOW TO REACH ONE, which is
 * why a method entry in a face carries a probe. `acf_get_field_type()` returns ACF's own
 * singleton - we instantiate nothing and share its per-request layout-meta cache - and it returns
 * NULL on ACF free, where Flexible Content does not exist at all.
 */
function wpmcp_acf_flexible_content() {
    return function_exists('acf_get_field_type') ? acf_get_field_type('flexible_content') : null;
}

/** True when this site provides the layout-metadata half of the face. */
function wpmcp_acf_layout_metadata_available() {
    $capabilities = wpmcp_module_face_capabilities('acf');

    return !empty($capabilities['layout_metadata']);
}

/**
 * True when this site provides `get_layout_title()` - a SEPARATE question from the one above,
 * because the two have different floors (review 81, S3).
 *
 * `get_layout_title()` and the `acf/fields/flexible_content/layout_title` filter family it runs
 * predate the 6.5 disable/rename feature, so a Pro 6.0 site answers true here and false there.
 */
function wpmcp_acf_layout_title_available() {
    $capabilities = wpmcp_module_face_capabilities('acf');

    return !empty($capabilities['layout_title']);
}

/**
 * The object kinds this tool reads, and the ACF object id each one maps to.
 *
 * ONE PLACE, because the enum in the inputSchema and the mapping in the run closure are two views
 * of the same list and a third kind added to one of them would otherwise be missing from the
 * other.
 *
 * THE SHAPES ARE NOT THE WHOLE OF THE IDENTIFIER, AND SAYING THEY WERE WAS THE DEFECT
 * (sprint CORE-FIX). This paragraph used to say the shapes `acf_get_valid_post_id()` produces -
 * the bare id, `term_%s`, `user_%s` - were what it produces, so "nothing here reaches for that
 * internal normaliser". Two things were wrong: that function is PUBLIC, not internal
 * (`includes/api/api-helpers.php:2258`, `@since 5.0.0`), and the shape is not the whole answer -
 * it appends a LANGUAGE SUFFIX to `options` (`:2311-2318`), so on a multilingual site the string
 * ACF's own readers use is `options_fr` and the one this file built was `options`. See
 * wpmcp_acf_object_id().
 *
 * COMMENTS ARE ABSENT ON PURPOSE. ACF supports a comment location and this plugin has no
 * comment-editing surface to mirror; adding one would be a disclosure decision nobody has made.
 *
 * @return list<string>
 */
function wpmcp_acf_object_types() {
    return array('post', 'term', 'user', 'options');
}

/**
 * THE ACF OBJECT ID, NORMALISED BY ACF (sprint CORE-FIX).
 *
 * `acf_get_valid_post_id()` is the public function every ACF reader puts its `$post_id` through
 * (`includes/api/api-helpers.php:2258-2326`, `@since 5.0.0`), and this module used to build the
 * four shapes by hand instead. The shapes were right; the normalisation is more than the shape:
 *
 *   - `options` GETS A LANGUAGE SUFFIX when a translation plugin has set ACF's
 *     `current_language` different from its `default_language` (`:2311-2318`), so the store
 *     ACF's own readers use on a French request is `options_fr`.
 *   - `acf/pre_load_post_id` and `acf/validate_post_id` are documented filters a translation or
 *     multi-context plugin uses to redirect a whole location, and a hand-built id runs neither.
 *
 * AND THE ONE READER THAT DOES NOT DO IT FOR US IS THE ONE THIS MODULE LEANS ON.
 * `get_field_object()`, `get_field_objects()` and `acf_format_value_for_rest()` all normalise on
 * the way in; `acf_get_value()` is the exception - it takes `$post_id` and goes straight to
 * `acf_get_reference()`/the store (`includes/acf-value-functions.php:78-130`). That is exactly
 * the call wpmcp_acf_dropped_row_values() makes for a row ACF dropped, so before this a
 * multilingual site read a dropped row's values out of the DEFAULT-language options store while
 * every surviving row came from the current one. Two halves of one answer, from two stores.
 *
 * NEVER CALLED WITH A FALSY ID. `acf_get_valid_post_id(0)` falls back to `get_the_ID()` and then
 * to the queried object - a guess about the current screen, which in a REST request is not this
 * caller's object. Every call site below has already refused an id of 0.
 *
 * @param int|string $raw the shape this module built: an int, `term_%d`, `user_%d` or `options`
 * @return int|string whatever ACF's own readers would use for it
 */
function wpmcp_acf_object_id($raw) {
    return acf_get_valid_post_id($raw);
}

/**
 * Resolve the caller's object and APPLY OUR OWN CAPABILITY GATE, which is mandatory because ACF
 * applies none (`analysis/71` §4: the only `current_user_can` on ACF's read path sits inside the
 * revision-substitution filter and is unreachable outside `is_preview()`).
 *
 * THE RULE, AND IT IS ONE SENTENCE: the capability required is the one that opens the wp-admin
 * screen ACF renders these fields on. D29 says a read MIRRORS that screen, so the gate has to be
 * the screen's own - `edit_post` for a post, `edit_term` for a term, `edit_user` for a user,
 * `manage_options` for an options page. `read_post` would be wrong and looser: a subscriber can
 * read a published post and cannot open its editor, where every one of these fields is rendered.
 *
 * AND A POST THE CALLER MAY NOT READ ANSWERS IDENTICALLY TO A POST THAT IS NOT THERE, byte for
 * byte with get-post and get-post-meta, so a caller cannot learn from this tool what those two
 * refuse to tell it. The `edit_post` refusal is a DIFFERENT message on purpose: by then the
 * caller has already been told the post exists by every other read tool, so naming the missing
 * capability discloses nothing new and is the only actionable thing we can say.
 *
 * @return array{type: string, id: int, acf_id: int|string}|WP_Error
 */
function wpmcp_acf_resolve($type, $id) {
    $notFound = new WP_Error('wpmcp_not_found', 'No post with that ID.');

    if ($type === 'options') {
        if (!current_user_can('manage_options')) {
            return wpmcp_cannot('read an ACF options page');
        }

        return array('type' => 'options', 'id' => 0, 'acf_id' => wpmcp_acf_object_id('options'));
    }

    if ($id <= 0) {
        return new WP_Error('wpmcp_bad_request', 'id is required for object_type ' . $type . '.');
    }

    if ($type === 'post') {
        $post = get_post($id);

        if (!$post) { return $notFound; }
        if (!current_user_can('read_post', $id)) { return $notFound; }
        if (!wpmcp_post_type_ok($post->post_type)) { return $notFound; }
        if (!current_user_can('edit_post', $id)) {
            return wpmcp_cannot("read this post's ACF fields, which needs permission to edit it");
        }

        return array('type' => 'post', 'id' => $id, 'acf_id' => wpmcp_acf_object_id($id));
    }

    if ($type === 'term') {
        $term = get_term($id);

        if (!$term || is_wp_error($term)) {
            return new WP_Error('wpmcp_not_found', 'Term not found.');
        }
        if (!current_user_can('edit_term', $id)) {
            return wpmcp_cannot("read this term's ACF fields, which needs permission to edit it");
        }

        return array('type' => 'term', 'id' => $id, 'acf_id' => wpmcp_acf_object_id('term_' . $id));
    }

    $user = get_userdata($id);

    if (!$user) { return new WP_Error('wpmcp_not_found', 'No user with that ID.'); }
    if (!current_user_can('edit_user', $id)) {
        return wpmcp_cannot("read this user's ACF fields, which needs permission to edit them");
    }

    return array('type' => 'user', 'id' => $id, 'acf_id' => wpmcp_acf_object_id('user_' . $id));
}

/**
 * THE ONE READ PRIMITIVE. Everything this module returns comes through here, so the permission
 * reduction cannot be skipped for one code path.
 *
 * @param mixed $raw   the stored value, unformatted
 * @param array $field an ACF field array, its `name` already the selector the value is stored under
 * @return mixed the formatted, permission-reduced value
 */
function wpmcp_acf_format($raw, $acfId, $field) {
    // TWO STEPS, AND THE SECOND IS A DISCLOSURE GATE RATHER THAN A FORMATTER. ACF decides WHICH
    // objects this caller may see expanded; wpmcp_acf_no_objects() decides what an expanded one is
    // allowed to contain, because wp_json_encode() on a WP_Post or a WP_User serialises the whole
    // database row - post_password in plaintext, user_pass as a hash. See that function.
    return wpmcp_acf_no_objects(acf_format_value_for_rest($raw, $acfId, $field, 'standard'));
}

/**
 * One field of one object, as this tool reports it.
 *
 * `label` IS ACF'S OWN, NOT OURS. D29 asks for metadata about what is being shown and says to
 * prefer what ACF already exposes; `key`, `name`, `type` and `label` are four of the keys
 * `get_field_object()` hands back and cost nothing to pass on.
 *
 * @return array<string, mixed>
 */
function wpmcp_acf_field_entry($field, $object) {
    $entry = array(
        'key'   => isset($field['key']) ? (string) $field['key'] : '',
        'name'  => isset($field['name']) ? (string) $field['name'] : '',
        'type'  => isset($field['type']) ? (string) $field['type'] : '',
        'label' => isset($field['label']) ? (string) $field['label'] : '',
        'value' => wpmcp_acf_format(isset($field['value']) ? $field['value'] : null, $object['acf_id'], $field),
    );

    if ($entry['type'] === 'flexible_content') {
        $entry['rows'] = wpmcp_acf_layout_rows($field, $object, $entry['value']);
    }

    return $entry;
}

/**
 * THE LABEL THE EDITOR SEES FOR ONE LAYOUT, FROM ACF'S OWN METHOD (sprint CORE-FIX).
 *
 * The precedence is ACF'S OWN, READ OFF ITS RENDERER, AND IT STAYS OURS BECAUSE ACF DOES NOT
 * EXPOSE IT. `Layout::action_buttons()` prints
 * `! empty($this->renamed) ? esc_html($this->renamed) : $title`
 * (`src/Pro/Fields/FlexibleContent/Layout.php`), and `$title` is `$this->get_title()`. The rename
 * half is therefore in a PRIVATE render method: `ACF_Field_Flexible_Content::get_layout_title()`
 * constructs its Layout with `$renamed = ''` (`pro/fields/class-acf-field-flexible-content.php:1367`),
 * so it can never return a rename. That is a real gap and it is written down as a ledger row - the
 * ledger's own summary said the public method "delegates to Layout::get_title(), which runs the
 * documented filter family", which is true, and implied the precedence came with it, which is not.
 *
 * WHAT THE CALL DOES BUY, AND IT IS THE HALF THAT WAS WRONG: the un-renamed title now runs
 * `acf/fields/flexible_content/layout_title` and its `/name=` and `/key=` variants, three
 * DOCUMENTED filters. Before this the label was `$layout['label']` straight out of the field
 * group, so on any site using that filter family wp-admin and our `label` DISAGREED - and D29 is
 * precisely "report the label the editor sees". Our old comment admitted the method existed: it
 * said the rule had been "read off ACF's own renderer rather than guessed". It read the logic
 * instead of calling it.
 *
 * THE ROW MUST BE THE RAW, KEY-KEYED ONE, AND ROUND 1 PASSED THE FORMATTED, NAME-KEYED ONE -
 * WHICH INVERTED THE WHOLE POINT (review 81, B1; MEASURED, twice, against ACF Pro 6.8.10).
 *
 * `get_title()` opens an `acf_add_loop()` around the row so that the filter can read the row's own
 * content, and ACF's documentation gives exactly one example of the filter, which does that:
 * `if ($text = get_sub_field('text')) { $title .= '<b>' . esc_html($text) . '</b>'; }`.
 * `get_sub_field_object()` resolves the value with `get_row_sub_value($sub_field['KEY'])`
 * (`includes/api/api-template.php:932-951`, `:773-790`), so the row has to be keyed by sub-field
 * KEY - which is what `Flexible_Content::load_value()` builds
 * (`pro/fields/class-acf-field-flexible-content.php:544-598`: `$rows[$i][$sub_field['key']]`) and
 * what BOTH of ACF's own callers pass: the wp-admin renderer iterates `$this->field['value']`
 * (`src/Pro/Fields/FlexibleContent/Render.php:159-172`) and the AJAX title handler passes
 * `$_POST['value']` (`:1320-1356`).
 *
 * Round 1 passed `acf_format_value_for_rest()`'s output, which is keyed by sub-field NAME. So every
 * `get_sub_field()` inside the filter returned NULL, and on a site using the filter the documented
 * way the label came back EMPTY - worse than the stored label it replaced, on the exact sites the
 * fix was written for. MEASURED: `'Hero :: NULL'` name-keyed against `'Hero :: ROW ZERO'` key-keyed.
 *
 * THE RAW ROW COSTS NO EXTRA ACF CALL. `get_field_object($name, $id, false, true)` leaves the
 * `load_value()` output in `$field['value']` - MEASURED identical to `acf_get_value($id, $field)` -
 * so a surviving row is `$field['value'][$index]` and nothing new is read.
 *
 * AND A DROPPED ROW IS NOT `array()`, WHICH WAS THE OTHER HALF OF THE SAME MISTAKE. `load_value()`
 * skips a disabled row, so `$field['value']` has a gap there, and an empty row makes every
 * `get_sub_field()` in the filter answer NOTHING: `get_sub_field_object()` finds no value under the
 * key, falls back to `acf_get_value($row['post_id'], $sub_field)`, and the loop's `post_id` is 0 -
 * so `acf_get_valid_post_id(0)` looks for `get_the_ID()` and then the queried object, and in a REST
 * request neither exists because the main query never runs before the route dispatches.
 * MEASURED: `'Hero :: NULL'` for a dropped row passed `array()`, and
 * `acf_get_valid_post_id(0)` is NULL rather than some other object's id. EMPTY AND WRONG, then -
 * round 2 wrote "unpredictable rather than empty, which is worse", which was the hazard feared and
 * not the one measured (review 81, S6). Empty is enough: a disabled row would report a label built
 * from no values while wp-admin shows one built from its own. So the row is REBUILT the way
 * `load_value()` would have built it, by wpmcp_acf_dropped_row(), and the filter then sees the
 * dropped row's OWN values - `'Hero :: ROW ONE'`, which is what wp-admin shows for a disabled row.
 *
 * WHAT THIS HANDS THE SITE'S OWN FILTER, AND IT IS A DELIBERATE EXCEPTION TO THE ONE-READ-PRIMITIVE
 * RULE. The row is UNFORMATTED and NOT permission-reduced, because that is the row ACF's renderer
 * passes and a reduced one would answer differently from wp-admin - which is the defect, again. So a
 * site-author filter that composes a title out of a sub-value can put a value on the wire that did
 * not come through `wpmcp_acf_format()`, and that is not theoretical: MEASURED in-process, a `user`
 * sub-field pointing at an administrator came back as the bare id `2` in `value` for a low-privilege
 * caller, while a filter calling `get_sub_field('author')['user_email']` put that administrator's
 * e-mail into `label` for the same caller (review 81, pressure point 1). **The label CAN carry what
 * the reduction withholds.**
 *
 * WHAT A CALLER CANNOT DO IS REGISTER THE FILTER; WHAT BOUNDS THE VALUE IS THE RESOLVE GATE, AND
 * SAYING ONLY THE FIRST WAS HALF AN ANSWER (review 81, S7). The bound is NOT the reduction - the
 * measurement above is exactly the reduction being bypassed. It is `wpmcp_acf_resolve()`, which
 * refuses anyone without EDIT rights on the object before a single row is built:
 * `manage_options` for an options page, `read_post` and then `edit_post` for a post, `edit_term`
 * for a term, `edit_user` for a user. MEASURED: that same low-privilege caller is refused on all
 * four object types. So every reader who reaches this function can open the wp-admin screen where
 * ACF renders the identical string, from the identical filter, on the identical raw row
 * (`Render.php:159-172` passes `load_value()`'s row; `Layout.php` opens the loop with `post_id => 0`
 * exactly as we do). The label is at parity with wp-admin FOR ITS READER, which is what D29 asks
 * for. There is no anonymous caller: every token is minted for a user and endpoint.php runs the
 * request as that user.
 *
 * **SO LOOSENING ANY OF THOSE FOUR CHECKS BREAKS THIS, AND NOTHING ELSE HERE WILL STOP IT.** Turning
 * `edit_post` into `read_post` would hand a filter's output to readers wp-admin never shows it to.
 * tests/unit/AcfResolveGateTest.php holds all four, in the gate group, for exactly that reason - a
 * future sprint will have a good reason to loosen one, and that is the moment this paragraph has to
 * be read again rather than discovered.
 *
 * And the module has no write surface, so nothing read here is written back. A site that does not
 * want a sub-value in a layout title does not put one there.
 *
 * @param array      $field  the Flexible Content field array
 * @param array|null $layout the layout definition, or null when the stored name has none
 * @param int        $index  the row's ORIGINAL index
 * @param mixed      $value  the RAW, key-keyed row - never the formatted one
 * @return string
 */
function wpmcp_acf_layout_label($field, $layout, $index, $value) {
    $own = is_array($layout) && isset($layout['label']) ? (string) $layout['label'] : '';

    // A layout name in the stored value with no matching definition - a layout the editor
    // DELETED from the field group - has no label anywhere to ask for.
    //
    // AND THE GATE IS THIS METHOD'S OWN CAPABILITY, NOT THE LAYOUT-METADATA ONE (review 81, S3).
    // Round 1 gated it on `layout_metadata`, whose floor is ACF Pro 6.5, and justified that with
    // "Flexible Content is a PRO field type, so where these two accessors are missing there are no
    // rows to label". FALSE: Flexible Content rows exist in every Pro version - what arrived in 6.5
    // is the DISABLE AND RENAME feature. The filter family is "Added in version 5.3.6" (ACF's own
    // docs), so on Pro 5.11-6.4 the defect this fix exists to close was still open, by construction,
    // under a docblock saying there was nothing to close. D30's answer is to gate on the SYMBOL, so
    // `get_layout_title` has its own optional capability and this asks for that one.
    if (!is_array($layout) || !wpmcp_acf_layout_title_available()) { return $own; }

    // Same object and the same absence of a second check as the two accessors above: the face is
    // what proves `get_layout_title` is there.
    $title = wpmcp_acf_flexible_content()->get_layout_title(
        $field,
        $layout,
        $index,
        is_array($value) ? $value : array()
    );

    // DECODED ONCE, BECAUSE get_title() ESCAPED FOR HTML AND THIS IS NOT HTML. `Layout::get_title()`
    // returns `wp_kses( apply_filters( ..., esc_html( $label ) ), 'acf' )`, because its only caller
    // in ACF echoes it into a wp-admin span. Our `label` goes onto a JSON wire, where `&amp;` is
    // not an ampersand - shipping ACF's output verbatim would put entity garbage in front of the
    // caller, which is the same defect as double-escaping an admin notice.
    //
    // IT IS A RENDERING, NOT AN INVERSE, AND ROUND 1 CLAIMED "BYTE-IDENTICAL TO THE STORED LABEL"
    // (review 81, S4 - MEASURED). `esc_html()` calls `_wp_specialchars($text, ENT_QUOTES)` with
    // `$double_encode = false` (`wp-includes/formatting.php:945`), so an entity ALREADY in the
    // stored label is not re-encoded on the way out and this decode then decodes it: a label stored
    // as `Tom &amp;amp; Jerry` comes back as `Tom &amp; Jerry`. What the wire carries is the TEXT AN
    // EDITOR SEES RENDERED, which is the D29 answer and is the point - but it is not the stored
    // bytes, so a client comparing this against a field-group JSON export can find a difference.
    //
    // WHAT IS NOT UNDONE, and it is the seam to watch: a filter that deliberately returns MARKUP
    // gets its markup through `wp_kses`'s `acf` allow-list and onto the wire as markup. That is
    // what the filter told wp-admin the label is, and stripping it here would be this module
    // deciding something the site's author already decided.
    return is_string($title) ? wp_specialchars_decode($title, ENT_QUOTES) : $own;
}

/**
 * EVERY ROW OF A FLEXIBLE CONTENT FIELD, INCLUDING THE ONES ACF DROPPED (D29).
 *
 * The row indices are the UNION of what ACF handed back and what the raw layout-name array holds,
 * because the two differ exactly by the disabled rows - and ACF keys its rows by the ORIGINAL
 * index, so the difference is a gap rather than a shorter list. MEASURED: three rows with the
 * middle one switched off come back keyed [0, 2].
 *
 * A CORRECTION TO `analysis/71` §6.1, MEASURED because that report asked for it by name. It
 * reasoned from `api-template.php:636` that a gapped value makes `have_rows()` stop at the gap,
 * and that a disabled row 0 makes it "yield nothing". Neither happens on 6.8.10: with row 1
 * disabled `have_rows()` yielded both survivors, and with row 0 disabled it yielded both of the
 * other two. So the gap is a reporting problem and not a traversal one.
 *
 * ALSO MEASURED, and it matters to anything that writes: `get_layout_meta()` caches per request
 * in the field type's own `$layout_meta` and is NOT invalidated by a write in the same request.
 * This module never writes, so it cannot be bitten; a future write sprint can.
 *
 * @return list<array<string, mixed>>
 */
function wpmcp_acf_layout_rows($field, $object, $formatted) {
    $name    = isset($field['name']) ? (string) $field['name'] : '';
    $layouts = array();

    foreach ((array) (isset($field['layouts']) ? $field['layouts'] : array()) as $layout) {
        if (isset($layout['name'])) { $layouts[(string) $layout['name']] = $layout; }
    }

    // AND `(array) $formatted` IS NOT GOOD ENOUGH, which is a defect this file shipped for about
    // an hour. An EMPTY Flexible Content field formats to `''` or `false`, and `(array) ''` is
    // `array('')` - one element at index 0 - so an object with the field present and no rows at
    // all would have reported a phantom row 0 with a blank layout name. `is_array()` is the whole
    // fix and the empty-value case now has a test.
    $formatted = is_array($formatted) ? $formatted : array();
    $raw       = wpmcp_acf_captured_rows($object['acf_id'], $name);
    $disabled  = array();
    $renamed   = array();

    if (wpmcp_acf_layout_metadata_available()) {
        $type     = wpmcp_acf_flexible_content();
        $disabled = array_map('intval', (array) $type->get_disabled_layouts($object['acf_id'], $field));
        $renamed  = (array) $type->get_renamed_layouts($object['acf_id'], $field);
    }

    // THE UNION IS THREE SETS AND THE THIRD IS NOT OPTIONAL (review 74, S4 - and the queen ranked
    // it above the blocker, correctly). The first version used the formatted keys plus the
    // priority-9 capture, and the capture is the one that can be EMPTY: acf_get_value() returns
    // from the values store before `acf/load_value` ever fires, so any earlier read of this field
    // in the same request - another plugin, ACF Extended, a theme - meant the subscriber never ran,
    // the disabled index was in neither set, and the row VANISHED WITH NO MARKER. That is the exact
    // silence D29 exists to prevent, and it is worse than a loud wrong value: nothing anywhere says
    // a row was there. `get_disabled_layouts()` reads meta and never the value store, so adding it
    // to the union means the row cannot disappear however warm the cache is. Held by a test that
    // WARMS the store first; a test that only ever runs cold cannot fail on this.
    $indices = array_map('intval', array_keys($formatted));

    foreach (array_merge(array_keys((array) $raw), $disabled) as $index) {
        if (!in_array((int) $index, $indices, true)) { $indices[] = (int) $index; }
    }

    sort($indices);

    $rows = array();

    foreach ($indices as $index) {
        // THE LAYOUT NAME, FROM WHICHEVER SOURCE HAS IT. A surviving row carries it in the
        // formatted value; a dropped one is only in the raw array the priority-9 subscriber saw.
        $layoutName = '';

        if (isset($raw[$index]) && is_string($raw[$index])) {
            $layoutName = $raw[$index];
        } elseif (isset($formatted[$index]['acf_fc_layout'])) {
            $layoutName = (string) $formatted[$index]['acf_fc_layout'];
        }

        $isDisabled = in_array($index, $disabled, true);

        // A DROPPED ROW IS LOADED ONCE AND USED TWICE, because both uses want the same
        // `acf_get_value()` per sub-field and doing it twice would double the reads on the one
        // path that is already the most expensive: `values` wants it FORMATTED and name-keyed for
        // the wire, and the layout-title filter wants it RAW and key-keyed, the way
        // `load_value()` would have built the row ACF skipped. See wpmcp_acf_dropped_row().
        $dropped = $isDisabled
            ? wpmcp_acf_dropped_row($field, $object, $index, $layouts, $layoutName)
            : null;

        // THE ROW THE LAYOUT-TITLE FILTER GETS, AND IT IS THE RAW ONE (review 81, B1). A surviving
        // row is `$field['value'][$index]` - `get_field_object()`'s `load_value()` output, keyed by
        // sub-field KEY, which is what ACF's own callers pass and what `get_sub_field()` can read.
        // Round 1 passed `$formatted[$index]`, keyed by NAME, and every `get_sub_field()` in the
        // filter answered NULL.
        $loopRow = array();

        if ($dropped !== null) {
            $loopRow = $dropped['raw'];
        } elseif (isset($field['value'][$index]) && is_array($field['value'][$index])) {
            $loopRow = $field['value'][$index];
        }

        $row = array(
            'index'    => $index,
            'layout'   => $layoutName,
            // THE LABEL THE EDITOR SEES, and the fallback half is now ACF'S OWN CALL rather than
            // our reading of it (sprint CORE-FIX) - see wpmcp_acf_layout_label().
            'label'    => isset($renamed[$index]) && $renamed[$index] !== ''
                ? (string) $renamed[$index]
                : wpmcp_acf_layout_label($field, isset($layouts[$layoutName]) ? $layouts[$layoutName] : null, $index, $loopRow),
            'renamed'  => isset($renamed[$index]) && $renamed[$index] !== '',
            'disabled' => $isDisabled,
        );

        // ONLY A DROPPED ROW CARRIES `values`, because a surviving row's values are already in
        // the field's own `value` under this index and repeating them would double the payload of
        // every read on jaygroup's 20,638 repeater rows.
        if ($dropped !== null) {
            $row['values'] = $dropped['values'];
        }

        $rows[] = $row;
    }

    return $rows;
}

/**
 * A row ACF dropped, in the TWO SHAPES its two consumers need, from one read per sub-field.
 *
 *   `values`  formatted, permission-reduced, keyed by the sub-field NAME a caller sees. The wire.
 *   `raw`     unformatted, keyed by sub-field KEY, plus `acf_fc_layout` - exactly the row
 *             `Flexible_Content::load_value()` would have built had it not skipped this one, which
 *             is what the layout-title filter's loop has to be given (review 81, B1). NOT for the
 *             caller and never returned to one; see wpmcp_acf_layout_label().
 *
 * ONE FUNCTION RATHER THAN TWO because both shapes come from the same `acf_get_value()` per
 * sub-field, and a dropped row is already the most expensive path this module has.
 *
 * THE KEY SET IS COMPLETE BY CONSTRUCTION, and the VALUES are as complete as ACF's own row loading
 * - which is a narrower claim than round 1 made and the only one this code can support. The keys
 * come from the LAYOUT DEFINITION rather than from what happens to be stored, so a sub-field with
 * nothing saved is present and empty instead of absent, which is the whole point of reporting a
 * dropped row at all. A tab or a message sub-field has no name and holds no value, so it is skipped
 * rather than reported as an empty one. Each value then comes from `acf_get_value()` - the same call
 * `Flexible_Content::load_value()` makes for a row it kept - so a container sub-field is expanded by
 * its own type's loader rather than read as the marker its meta row actually holds.
 *
 * WHAT IS STILL NOT INHERITED, because `acf_get_value()` is where the value path starts and not
 * where it ends: nothing here re-enters the PARENT field's `format_value`, so a sub-value is
 * formatted by `wpmcp_acf_format()` on its own rather than as part of a row. For every field type
 * measured that is the same answer; it is written down because it is the seam where a future ACF
 * change would show up first.
 *
 * @param array<string, array> $layouts layout name => the layout definition
 * @return array{values: array<string, mixed>, raw: array<string, mixed>}
 */
function wpmcp_acf_dropped_row($field, $object, $index, $layouts, $layoutName) {
    $parent = isset($field['name']) ? (string) $field['name'] : '';
    $out    = array('values' => array(), 'raw' => array('acf_fc_layout' => (string) $layoutName));

    if ($parent === '' || !isset($layouts[$layoutName]['sub_fields'])) {
        return $out;
    }

    foreach ((array) $layouts[$layoutName]['sub_fields'] as $sub) {
        if (!is_array($sub) || !isset($sub['name']) || (string) $sub['name'] === '') { continue; }

        // RENAMED AND THEN LOADED EXACTLY AS ACF'S OWN load_value() DOES IT, two lines apart:
        //
        //     $sub_field['name'] = "{$field['name']}_{$i}_{$sub_field['name']}";   // :590
        //     $sub_value         = acf_get_value( $post_id, $sub_field );          // :593
        //
        // (pro/fields/class-acf-field-flexible-content.php). READING THE META DIRECTLY WAS THE
        // BLOCKER OF ROUND 1 and it was wrong in the only way that mattered: acf_get_value() runs
        // the SUB-FIELD TYPE'S OWN load_value, and for every container type the stored meta is a
        // MARKER rather than a value. jaygroup's one Flexible Content field has a seamless clone
        // (`prefix_name = 1`) as the sole sub-field of all 35 layouts, and there is no
        // `content_N_fields` meta row on any of its 294 posts - so a bare get_post_meta() returned
        // '' for every disabled row, Clone::format_value('') returned false at its empty() guard,
        // and the feature reported `values: {..: false}` on the only site it was built for. A
        // repeater's stored row COUNT and a nested Flexible Content's stored LAYOUT-NAME ARRAY fail
        // the same way, the second as a TypeError on PHP 8.3 that turns the whole read into -32603.
        //
        // acf_get_value() is not on D9's fenced list (acf_update_value, acf_validate_value,
        // acf_*_metadata, acf_get_valid_post_id), is `@since 5.0.0`, is the function the documented
        // get_field_object() is built on, and it resolves the meta through ACF's own per-location
        // classes - which also removed this module's multilingual-options caveat.
        $sub['name'] = $parent . '_' . (int) $index . '_' . (string) $sub['name'];
        $raw         = acf_get_value($object['acf_id'], $sub);

        // BOTH SHAPES, FROM ONE READ. `values` is formatted and permission-reduced through the one
        // read primitive, keyed by the name a caller sees. `raw` is keyed by sub-field KEY and left
        // unformatted, because it is not for the caller: it is the row the layout-title filter is
        // given, and `load_value()` builds that from the same `acf_get_value()` result with no
        // formatting (`pro/fields/class-acf-field-flexible-content.php:593-598`). See
        // wpmcp_acf_layout_label() for what handing it to a site's own filter does and does not
        // expose.
        $out['values'][(string) $sub['name']] = wpmcp_acf_format($raw, $object['acf_id'], $sub);

        if (isset($sub['key']) && (string) $sub['key'] !== '') {
            $out['raw'][(string) $sub['key']] = $raw;
        }
    }

    return $out;
}

/**
 * The raw layout-name array of a Flexible Content field, as ACF loaded it BEFORE dropping the
 * disabled rows - recorded by a priority-9 subscriber on the documented
 * `acf/load_value/type=flexible_content` filter.
 *
 * WHY A FILTER AND NOT A META READ. The raw array is the only place a DROPPED row's layout name
 * exists: `get_disabled_layouts()` gives indices and nothing else, and the formatted value has a
 * gap where the row was. Reading `_{field_name}` ourselves would hardcode ACF's storage for the
 * one thing ACF does expose - the filter is documented, `@since 5.0.0`, and it answers for the
 * post, term, user and options locations alike because it sits on the VALUE pipeline rather than
 * on storage.
 *
 * KEYED BY OBJECT AND FIELD NAME, because one read can touch many fields and ACF's value cache
 * means `load_value` fires at most once per field per request.
 *
 * @param int|string|null $acfId
 * @param string|null     $name
 * @param mixed           $value
 * @return array
 */
function wpmcp_acf_captured_rows($acfId = null, $name = null, $value = null) {
    static $seen = array();

    if ($acfId === null) { return $seen; }

    $key = (string) $acfId . ':' . (string) $name;

    if ($value !== null) { $seen[$key] = is_array($value) ? $value : array(); }

    return isset($seen[$key]) ? $seen[$key] : array();
}

/**
 * The subscriber itself. It records and returns the value untouched.
 *
 * @param mixed $value
 * @param int|string $post_id
 * @param array $field
 * @return mixed
 */
function wpmcp_acf_capture_rows($value, $post_id, $field) {
    wpmcp_acf_captured_rows(
        $post_id,
        isset($field['name']) ? (string) $field['name'] : '',
        is_array($value) ? $value : array()
    );

    return $value;
}

/**
 * Every field of one object that this tool will report, as `name => field array with its RAW
 * value`.
 *
 * `get_field_objects($id, false, true)` IS THE ENUMERATOR, and its own rules are the tool's
 * boundary rather than something we add: it skips any field whose hidden `_$key` reference row is
 * missing, and any field whose `name` does not equal the meta key - which is what suppresses the
 * clone and sub-field noise that would otherwise make 338 fields out of 7 groups. The
 * consequence, stated in the tool's description: a field the object has never saved is not
 * listed. That is also why a named `fields` selector only resolves once the object has saved it -
 * `get_field_object()` finds a field BY NAME through that same reference row.
 *
 * @return array<string, array>
 */
function wpmcp_acf_field_objects($object, $selectors) {
    if ($selectors === array()) {
        $objects = get_field_objects($object['acf_id'], false, true);

        return is_array($objects) ? $objects : array();
    }

    $fields = array();

    foreach ($selectors as $selector) {
        $field = get_field_object((string) $selector, $object['acf_id'], false, true);

        if (is_array($field) && isset($field['type'])) {
            $fields[(string) $selector] = $field;
        }
    }

    return $fields;
}

/**
 * MAKE THE VALUE STORE MISS FOR A FLEXIBLE CONTENT FIELD WHOSE ROW LIST WE DID NOT SEE.
 *
 * `acf_get_value()` returns from the values store BEFORE `acf/load_value` runs, so any earlier read
 * of the same field in the same request - another plugin, ACF Extended (which jaygroup runs on this
 * very field), a theme - means our priority-9 subscriber never fires and the raw layout-name array
 * is not captured. The row still cannot vanish, because the disabled indices go into the union on
 * their own; but WITHOUT the raw array a dropped row has no layout NAME, with no name there is no
 * layout definition, and with no definition there are no sub-field values to recover. The read
 * would mark the row and then say nothing about it.
 *
 * So: for each Flexible Content field whose capture is empty, flush that field's three store keys
 * and read it once more. The second read misses the store, `load_value` runs, the subscriber fires.
 * It costs one extra read per affected field, and only when the store was already warm.
 *
 * `acf_flush_value_cache()` IS THE NARROW TOOL ON PURPOSE. It removes `"$id:$name"`,
 * `"$id:$name:formatted"` and `"$id:$name:escaped"` and nothing else, where
 * `acf_get_store('values')->reset()` - which is what ACF's own REST controller calls - would throw
 * away every field of every object this request has read. A memo we did not fill is not ours to
 * empty.
 *
 * @param array<string, array> $fields name => field array, as get_field_object(s) returned them
 * @return array<string, array>
 */
function wpmcp_acf_reload_gapped_rows($fields, $object) {
    foreach ($fields as $key => $field) {
        if (!isset($field['type'], $field['name']) || $field['type'] !== 'flexible_content') { continue; }
        if (wpmcp_acf_captured_rows($object['acf_id'], (string) $field['name']) !== array()) { continue; }

        acf_flush_value_cache($object['acf_id'], (string) $field['name']);

        $again = get_field_object((string) $field['name'], $object['acf_id'], false, true);

        if (is_array($again) && isset($again['type'])) { $fields[$key] = $again; }
    }

    return $fields;
}

/**
 * NO LIVE WORDPRESS OBJECT REACHES THE WIRE, and this is a DISCLOSURE fix rather than a tidying
 * one (review 74, S5 - which under-rated it; measured in round 2).
 *
 * `wp_json_encode()` on an object serialises its PUBLIC PROPERTIES, which for WordPress's own
 * classes is the whole database row. MEASURED on jaygroup, on the values ACF's `'standard'`
 * formatter actually produces:
 *
 *   - a Post Object / Relationship field with `return_format: object` gives a `WP_Post`, whose 24
 *     properties include `post_password` - the PLAINTEXT password - and `post_content`. A
 *     password-protected post is `post_status = publish`, and core's own
 *     `check_read_permission()` returns TRUE on a published post for anybody, so ACF's 6.8.10
 *     reduction does not stop it: measured, an anonymous caller was handed `hunter2` and
 *     `THE SECRET BODY`. `wp/v2` serves neither - it has no password field at all and answers
 *     `content: {protected: true, rendered: ""}` - and neither does this plugin's own `get-post`.
 *   - a User field with `return_format: object` gives a `WP_User`, whose `data` property carries
 *     **`user_pass`** (the hash) and `user_activation_key`, plus `allcaps`. ACF's 6.8.7 user
 *     sanitiser short-circuits for a caller with `list_users`, which every administrator-bound
 *     token has. `get-user`'s own description promises, in writing, *"Never returns passwords,
 *     keys, sessions or user meta."* This module would have broken that promise on the same server.
 *
 * ACF's `return_format: array` for a User, and its Image / File arrays, are clean - so the defect
 * is not ACF's formatter. It is that a SITE MAY CHOOSE `object`, and PHP's default object
 * serialisation then decides what a token holder receives.
 *
 * SO THE RULE IS AN ALLOW-LIST, NOT A DENYLIST. A denylist leaks the next property WordPress adds.
 * Every object is replaced by a named subset, and anything this function does not know is replaced
 * by its class name and its id - which is the shape ACF's own `'light'` format would have returned,
 * so nothing unknown is ever serialised and nothing is silently dropped either.
 *
 * IT RUNS ON THE FORMATTED, ALREADY-REDUCED VALUE, so it composes with ACF's permission reduction
 * rather than replacing it: a reference this caller may not read is a bare ID long before it reaches
 * here, and this narrows what is left.
 *
 * @return mixed
 */
function wpmcp_acf_no_objects($value) {
    if (is_array($value)) {
        $out = array();

        foreach ($value as $key => $item) { $out[$key] = wpmcp_acf_no_objects($item); }

        return $out;
    }

    if (!is_object($value)) { return $value; }

    if ($value instanceof WP_Post) { return wpmcp_acf_post_out($value); }
    if ($value instanceof WP_User) { return wpmcp_acf_user_out($value); }

    if ($value instanceof WP_Term) {
        return array(
            'id'       => (int) $value->term_id,
            'taxonomy' => (string) $value->taxonomy,
            'name'     => (string) $value->name,
            'slug'     => (string) $value->slug,
            'parent'   => (int) $value->parent,
            'count'    => (int) $value->count,
        );
    }

    // ANYTHING ELSE: named, with its id if it has one, and never serialised. A future ACF field
    // type that returns some other object arrives here rather than on the wire.
    $id = null;

    foreach (array('ID', 'id', 'term_id', 'comment_ID') as $property) {
        if (isset($value->$property)) { $id = (int) $value->$property; break; }
    }

    return array('object' => get_class($value), 'id' => $id);
}

/**
 * One `WP_Post` as this module reports it.
 *
 * `post_password` IS ABSENT AND `content` IS WITHHELD FOR A PROTECTED POST, which is core's own rule
 * rather than ours: `post_password_required()` is the function `wp/v2` reasons with, and in a REST
 * request there is no password cookie, so it answers true for every protected post and this caller.
 * The withholding is REPORTED - `password_protected: true` - because a silently empty body is the
 * failure this whole module is written against.
 *
 * @return array<string, mixed>
 */
function wpmcp_acf_post_out($post) {
    $protected = post_password_required($post);

    $out = array(
        'id'                 => (int) $post->ID,
        'type'               => (string) $post->post_type,
        'status'             => (string) $post->post_status,
        'title'              => (string) $post->post_title,
        'slug'               => (string) $post->post_name,
        'author'             => (int) $post->post_author,
        'parent'             => (int) $post->post_parent,
        'menu_order'         => (int) $post->menu_order,
        'date'               => (string) $post->post_date,
        'date_gmt'           => (string) $post->post_date_gmt,
        'modified'           => (string) $post->post_modified,
        'mime_type'          => (string) $post->post_mime_type,
        'password_protected' => $protected,
    );

    if (!$protected) {
        $out['excerpt'] = (string) $post->post_excerpt;
        $out['content'] = (string) $post->post_content;
    }

    return $out;
}

/**
 * The fields `get-user` publishes, and the four it publishes only to a privileged caller.
 *
 * DECLARED AS DATA so a test can hold it against `wpmcp_get_user_shape()` in `tools.php`. A module
 * may not reach into `tools.php` beyond the five named helpers, so the list is repeated here - and
 * a repetition nothing checks is a drift waiting to happen, which is what the test is for.
 *
 * @return array{always: list<string>, privileged: list<string>}
 */
function wpmcp_acf_user_fields() {
    return array(
        'always'     => array('id', 'name'),
        'privileged' => array('login', 'email', 'roles', 'registered'),
    );
}

/**
 * One `WP_User` as this module reports it - THE SAME FIELDS AND THE SAME GATE `get-user` USES.
 *
 * `id` and `name` always; `login`, `email`, `roles` and `registered` only for a caller with
 * `list_users`, or `edit_user` on that user, or themselves (`edit_user` is how core spells "or
 * themselves" - map_meta_cap allows `edit_user` on your own id).
 *
 * THAT IS `get-user`'s LINE, NOT CORE'S, AND THIS DOCBLOCK USED TO SAY IT WAS CORE'S (sprint
 * CORE-FIX). It is LOOSER than `WP_REST_Users_Controller`'s: core puts those four fields in the
 * `edit` CONTEXT, and its `get_item_permissions_check()` refuses an `edit`-context read of
 * ANOTHER user outright unless the caller can `edit_user` them
 * (`class-wp-rest-users-controller.php:487`). `list_users` does not satisfy that rule, and here
 * it does. The reason to keep ours is that a repetition must match the thing it repeats: this is
 * `get-user`'s gate, `wpmcp_user_out()`'s `$full`, and tests hold the two together - a module
 * that quietly tightened it would make the same id answer differently through two tools of the
 * same server. Widening or narrowing it is a decision about `get-user`, in `tools.php`, and it
 * would have to move both.
 *
 * @return array<string, mixed>
 */
function wpmcp_acf_user_out($user) {
    $id  = (int) $user->ID;
    $out = array('id' => $id, 'name' => (string) $user->display_name);

    if (!current_user_can('list_users') && !current_user_can('edit_user', $id)) {
        return $out;
    }

    $out['login']      = (string) $user->user_login;
    $out['email']      = (string) $user->user_email;
    $out['roles']      = array_values(array_map('strval', (array) $user->roles));
    $out['registered'] = (string) $user->user_registered;

    return $out;
}

/** The module's tools. Registered through the seam at the foot of this file. */
function wpmcp_acf_tools() {
    return array(

    'get-acf-values' => array(
        'write' => false,
        'annotations' => array(
            'readOnlyHint' => true,
            'destructiveHint' => false,
            'idempotentHint' => true,
            'openWorldHint' => false,
        ),
        'description' => 'Read ACF field values on one object. Args: object_type (post, term,'
            . ' user or options; default post), id (required except for options), fields'
            . ' (optional field names; omit for every field the object has saved). Returns'
            . ' object, acf (the guarantees this site\'s ACF provides) and fields - each with'
            . ' key, name, type, label, value. Values come from ACF\'s own REST path, so a'
            . ' Relationship, Post Object, Image, Gallery, File or User you may not read arrives'
            . ' as bare IDs, as wp/v2 does. An expanded post or user is a NAMED SUBSET: never'
            . ' post_password or a password hash, and no content for a password-protected post,'
            . ' which is marked password_protected. A flexible_content field also returns rows:'
            . ' one per layout ROW, with index, layout, the label the editor sees after a rename,'
            . ' renamed, disabled. A layout switched OFF is hidden by ACF outside wp-admin; it is'
            . ' reported with disabled true and its own values, never dropped. Only fields the'
            . ' object has saved are listed. Needs permission to edit the object.',
        'inputSchema' => array('type' => 'object', 'properties' => array(
            'object_type' => array(
                'type' => 'string',
                'enum' => wpmcp_acf_object_types(),
                'default' => 'post',
                'description' => 'What the fields are attached to. "options" reads an ACF options page and takes no id.',
            ),
            'id' => array(
                'type' => 'integer',
                'description' => 'The post, term or user ID. Required unless object_type is options.',
            ),
            'fields' => array(
                'type' => 'array',
                'items' => array('type' => 'string'),
                'description' => 'Field names to read. Omit for every field the object has saved. A name the object has never saved is not returned.',
            ),
        )),
        'run' => function ($a) {
            // THE CALL-TIME HALF OF THE GUARD (D30, point 3). The same function the registration
            // guard asks, so there is no second copy to drift - and it is reachable, because
            // wpmcp_acf_tools() is a public function anything on this site can hand to the
            // wpmcp_tools filter, and because registration answered at plugins_loaded while this
            // runs inside a request. The code is deliberately NOT `wpmcp_`-prefixed: the error
            // boundary then turns it into the ordinary generic refusal with a trace id, puts the
            // missing symbols in the private trace log where the operator reads them, and tells
            // the caller nothing about which plugins this site has.
            $missing = wpmcp_module_face_missing('acf');

            if ($missing !== array()) {
                return new WP_Error(
                    'acf_face_unavailable',
                    'The ACF module was called with its API face incomplete. Missing: '
                    . implode(', ', $missing)
                );
            }

            $type = isset($a['object_type']) ? (string) $a['object_type'] : 'post';

            if (!in_array($type, wpmcp_acf_object_types(), true)) {
                return new WP_Error(
                    'wpmcp_bad_request',
                    'object_type must be one of ' . implode(', ', wpmcp_acf_object_types()) . '.'
                );
            }

            $object = wpmcp_acf_resolve($type, isset($a['id']) ? (int) $a['id'] : 0);

            if (is_wp_error($object)) { return $object; }

            $selectors = array();

            foreach ((array) (isset($a['fields']) ? $a['fields'] : array()) as $selector) {
                if (is_string($selector) && $selector !== '') { $selectors[] = $selector; }
            }

            // ATTACHED HERE AND DETACHED BELOW, not at file scope: this module file is loaded on
            // every request to the site, and a subscriber left attached would run on every
            // front-end page load for the benefit of a tool nobody called.
            add_filter('acf/load_value/type=flexible_content', 'wpmcp_acf_capture_rows', 9, 3);

            $fields  = wpmcp_acf_reload_gapped_rows(
                wpmcp_acf_field_objects($object, $selectors),
                $object
            );
            $entries = array();

            foreach ($fields as $field) {
                $entries[] = wpmcp_acf_field_entry($field, $object);
            }

            remove_filter('acf/load_value/type=flexible_content', 'wpmcp_acf_capture_rows', 9);

            return array(
                'object' => array(
                    'type'   => $object['type'],
                    'id'     => $object['id'],
                    'acf_id' => $object['acf_id'],
                ),
                // WHICH GUARANTEES THIS SITE PROVIDES, reported rather than assumed (item 7).
                // `values` is always true here - the module would not have registered otherwise -
                // and it is stated anyway, because a caller reading one field of this object
                // should not have to know that to interpret the other.
                'acf' => array_merge(
                    array('values' => true),
                    wpmcp_module_face_capabilities('acf')
                ),
                'fields' => $entries,
            );
        },
    ),

    );
}

/**
 * Declare the face, then register only if every required symbol is present.
 *
 * TWO STATEMENTS, AND THE ORDER MATTERS. The face is declared UNCONDITIONALLY, at file scope, so
 * that a site WITHOUT ACF can still be told what this module needed - wpmcp_module_status() reads
 * the declaration, and Settings > WP MCP prints it. A face declared inside the guard would be
 * invisible on exactly the site whose administrator has to be told why there are no ACF tools.
 */
wpmcp_register_module_face('acf', 'wpmcp_acf_api_face');

/** The guard, on plugins_loaded for the reason this file's header gives. */
function wpmcp_acf_register_module() {
    if (wpmcp_module_face_missing('acf') === array()) {
        wpmcp_register_module('acf', 'wpmcp_acf_tools');
    }
}

add_action('plugins_loaded', 'wpmcp_acf_register_module', 0);
