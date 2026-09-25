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
 * AND THE DROPPED ROW'S VALUES ARE NOT READ THE WAY THE SPRINT BRIEF SAID, because the brief's
 * route is both broken and unsafe here. MEASURED on jaygroup, which is 71.5% clone composites:
 *
 *   - `get_field_object("content_0_fields_title", $page)` returns **false**. The flattened
 *     reference row `_content_0_fields_title` holds `field_69d89b38e8de9_field_69d89b0959509` -
 *     a clone COMPOSITE key, not a field key - and `acf_get_field()` cannot resolve it, so
 *     `acf_get_meta_field()` gives up. `analysis/72` §2c concluded that
 *     `acf_maybe_get_field()` "resolves exactly those"; it does not, and the reference row the
 *     scout itself quoted is a composite too.
 *   - `get_field("content_0_fields_title", $page)` DOES return "Zero is off" - but only because
 *     `get_field` falls back to a DUMMY text field and returns the RAW meta with `$format_value`
 *     forced to false (`analysis/71` §6.3b). So an Image sub-field comes back as a bare ID with
 *     no formatting AND no permission reduction. That is the exact disclosure this module exists
 *     to avoid, arriving through the one route that looked safe.
 *
 * So a dropped row's values are rebuilt from the parent field's OWN layout definition - which
 * `get_field_object()` already handed us - with each sub-field's name rewritten to
 * `{$parent}_{$index}_{$sub}` exactly as ACF's `load_value()` rewrites it, the stored value read
 * with core's own meta API, and the result handed to the SAME
 * `acf_format_value_for_rest(..., 'standard')` every other value in this file goes through. One
 * read primitive, so the reduction cannot be skipped for the rows ACF hid.
 *
 * THE ONE ASSUMPTION THAT IS OURS AND NOT ACF'S, stated because it is the module's only
 * undocumented storage dependency: that ACF stores a flattened sub-field under
 * `{$parent}_{$index}_{$sub}` in the object's own meta. D29 authorised exactly this class of
 * narrow, module-scoped, READ-ONLY exception, and `analysis/72` §2b establishes that there is no
 * accessor at all for these values - `Flexible_Content::load_value()` drops them with no filter
 * of any kind in a 1,700-line class whose only `apply_filters` is unrelated.
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
 * wpmcp_tools(), inside a REST request. ACF registers its field types at its own load time -
 * `acf()` is called at its file scope and `initialize()` includes every field class - so
 * `acf_get_field_type('flexible_content')` is answerable by then too.
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
                'acf_format_value_for_rest',
                'get_field_object',
                'get_field_objects',
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
 * The object kinds this tool reads, and the ACF object id each one maps to.
 *
 * ONE PLACE, because the enum in the inputSchema, the mapping in the run closure and the meta
 * read in wpmcp_acf_raw_meta() are three views of the same list and a fourth kind added to one of
 * them would otherwise be missing from the others. The identifiers are the ones public
 * `acf_get_valid_post_id()` already produces and accepts - the bare id for a post, and
 * `term_%s` / `user_%s` otherwise - so nothing here reaches for that internal normaliser.
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

        return array('type' => 'options', 'id' => 0, 'acf_id' => 'options');
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

        return array('type' => 'post', 'id' => $id, 'acf_id' => $id);
    }

    if ($type === 'term') {
        $term = get_term($id);

        if (!$term || is_wp_error($term)) {
            return new WP_Error('wpmcp_not_found', 'Term not found.');
        }
        if (!current_user_can('edit_term', $id)) {
            return wpmcp_cannot("read this term's ACF fields, which needs permission to edit it");
        }

        return array('type' => 'term', 'id' => $id, 'acf_id' => 'term_' . $id);
    }

    $user = get_userdata($id);

    if (!$user) { return new WP_Error('wpmcp_not_found', 'No user with that ID.'); }
    if (!current_user_can('edit_user', $id)) {
        return wpmcp_cannot("read this user's ACF fields, which needs permission to edit them");
    }

    return array('type' => 'user', 'id' => $id, 'acf_id' => 'user_' . $id);
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
    return acf_format_value_for_rest($raw, $acfId, $field, 'standard');
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

    $raw      = wpmcp_acf_captured_rows($object['acf_id'], $name);
    $disabled = array();
    $renamed  = array();

    if (wpmcp_acf_layout_metadata_available()) {
        $type     = wpmcp_acf_flexible_content();
        $disabled = array_map('intval', (array) $type->get_disabled_layouts($object['acf_id'], $field));
        $renamed  = (array) $type->get_renamed_layouts($object['acf_id'], $field);
    }

    $indices = array_map('intval', array_keys((array) $formatted));

    foreach (array_keys((array) $raw) as $index) {
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
        $row        = array(
            'index'    => $index,
            'layout'   => $layoutName,
            // THE LABEL THE EDITOR SEES, which is the rename when there is one and the layout's
            // own label otherwise - read off ACF's own renderer rather than guessed
            // (src/Pro/Fields/FlexibleContent/Layout.php: `! empty($this->renamed) ? $this->renamed : $title`).
            'label'    => isset($renamed[$index]) && $renamed[$index] !== ''
                ? (string) $renamed[$index]
                : (isset($layouts[$layoutName]['label']) ? (string) $layouts[$layoutName]['label'] : ''),
            'renamed'  => isset($renamed[$index]) && $renamed[$index] !== '',
            'disabled' => $isDisabled,
        );

        // ONLY A DROPPED ROW CARRIES `values`, because a surviving row's values are already in
        // the field's own `value` under this index and repeating them would double the payload of
        // every read on jaygroup's 20,638 repeater rows.
        if ($isDisabled) {
            $row['values'] = wpmcp_acf_dropped_row_values($field, $object, $index, $layouts, $layoutName);
        }

        $rows[] = $row;
    }

    return $rows;
}

/**
 * The values of a row ACF dropped, keyed by sub-field name.
 *
 * COMPLETE BY CONSTRUCTION. The keys come from the LAYOUT DEFINITION rather than from what
 * happens to be stored, so a sub-field with nothing saved is present and null instead of absent -
 * which is the whole point of reporting a dropped row at all. A tab or a message sub-field has no
 * name and holds no value, so it is skipped rather than reported as an empty one.
 *
 * @param array<string, array> $layouts layout name => the layout definition
 * @return array<string, mixed>
 */
function wpmcp_acf_dropped_row_values($field, $object, $index, $layouts, $layoutName) {
    $parent = isset($field['name']) ? (string) $field['name'] : '';
    $values = array();

    if ($parent === '' || !isset($layouts[$layoutName]['sub_fields'])) {
        return $values;
    }

    foreach ((array) $layouts[$layoutName]['sub_fields'] as $sub) {
        if (!is_array($sub) || !isset($sub['name']) || (string) $sub['name'] === '') { continue; }

        $selector = $parent . '_' . (int) $index . '_' . (string) $sub['name'];

        // RENAMED THE WAY ACF'S OWN load_value() RENAMES IT, which is what makes the stored meta
        // key and the sub-field array agree - and what makes the formatter produce the same value
        // it would have produced for a row ACF had not dropped.
        $sub['name'] = $selector;

        $values[(string) $sub['name']] = wpmcp_acf_format(
            wpmcp_acf_raw_meta($object, $selector),
            $object['acf_id'],
            $sub
        );
    }

    return $values;
}

/**
 * The stored value of one flattened selector, read with CORE's own meta API.
 *
 * THIS IS THE MODULE'S ONE UNDOCUMENTED STORAGE DEPENDENCY and it is here rather than spread
 * about, so a reviewer can see all of it at once: ACF stores a Flexible Content sub-field under
 * `{$parent}_{$index}_{$sub}` on the object itself, and the options location prefixes the key
 * with `options_` (measured: ACF's own `src/Meta/Option.php`). `analysis/72` §2b establishes that
 * there is no accessor for these values at all, and D29 authorised exactly this narrow,
 * module-scoped, read-only exception.
 *
 * @return mixed
 */
function wpmcp_acf_raw_meta($object, $selector) {
    if ($object['type'] === 'term')    { return get_term_meta($object['id'], $selector, true); }
    if ($object['type'] === 'user')    { return get_user_meta($object['id'], $selector, true); }
    if ($object['type'] === 'options') { return get_option('options_' . $selector); }

    return get_post_meta($object['id'], $selector, true);
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
            . ' (optional list of field names; omit for every field the object has saved).'
            . ' Returns object, acf (which guarantees this site\'s ACF provides) and fields -'
            . ' each with key, name, type, label and value. Values come from ACF\'s own REST'
            . ' path, so a Relationship, Post Object, Image, Gallery, File or User you may not'
            . ' read arrives as bare IDs rather than expanded, exactly as wp/v2 would answer.'
            . ' A flexible_content field also returns rows: one per layout ROW, with index,'
            . ' layout, the label the editor sees after a rename, renamed, and disabled. A'
            . ' layout an editor switched OFF is hidden by ACF from every read outside'
            . ' wp-admin; it is reported here with disabled true and its own values, never'
            . ' dropped. Only fields the object has already saved are listed. Needs permission'
            . ' to edit the object - edit_post, edit_term, edit_user or manage_options.',
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

            $fields  = wpmcp_acf_field_objects($object, $selectors);
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
