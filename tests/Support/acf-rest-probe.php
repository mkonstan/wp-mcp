<?php
/**
 * THE THREE THINGS `analysis/72` COULD NOT RUN, RUN - inside WordPress, on a site with ACF.
 *
 * `analysis/71` and `analysis/72` are both marked `[reasoned from source]`: neither scout
 * executed a line of PHP, because every route to executing one here boots WordPress and
 * writes. Sprint ACF-READ's design rests on exactly three claims out of those reports, and
 * this file is what turns them from reasoning into a measurement that re-runs:
 *
 *   A1  `acf_format_value_for_rest( $raw, $post_id, $field, 'standard' )` is callable from
 *       OUR code - not only from ACF's own REST controller - and returns what
 *       `acf_format_value()` returns, PLUS the reduction.
 *   A2  the reduction FIRES for a Relationship value whose target the acting user cannot
 *       read, and does NOT fire when they can.
 *   A3  the value store's `"$post_id:$field_name:formatted"` key
 *       (`includes/acf-value-functions.php` around 181-188) never serves a REDUCED value to
 *       an un-reduced caller, nor the reverse.
 *
 * WHY A FILE AND NOT A `wp eval` STRING. The whole measurement has to happen in ONE PHP
 * process, because the field group it measures is a LOCAL one - registered through the
 * documented `acf_add_local_field_group()` and therefore gone when the process ends. That is
 * deliberate: it leaves no `acf-field-group` post, no `acf-field` posts and no option rows on
 * the site, so the only debris a crash can leave is three posts carrying this run's prefix,
 * which `Fixtures::purge()` already knows how to remove. A single process also means the
 * snippet is long, and a long single-quoted one-liner through `wp eval` is unreadable and
 * unreviewable.
 *
 * IT PRINTS JSON AND ASSERTS NOTHING. Every judgement is made by
 * `tests/integration/AcfRestValuePathTest.php`, in PHPUnit, where a failure names itself. A
 * probe that asserted would be a second test suite with no reporting.
 *
 * THE FIXTURES ARE PREFIXED AND REMOVED HERE. The prefix comes from `WPMCP_TEST_RUN_ID`,
 * which `Fixtures::runId()` puts in the environment and `proc_open` hands to this process, so
 * the three posts this creates carry the SAME run id as every other fixture of the run. The
 * last thing it does is delete them and say whether they are gone; the test asserts that too.
 *
 * IT NEEDS A DRAFT TARGET, and that is the only shape that makes A2 observable. ACF's
 * `acf_rest_reference_is_exposable()` delegates to the target post type's REST controller's
 * `check_read_permission()`, which returns true for ANY caller on a published post and
 * requires `read_post` on an unpublished one. So one draft target and one published target,
 * read once as an administrator and once as nobody, is the smallest fixture that shows the
 * reduction both firing and not firing.
 */

if (!function_exists('acf_format_value_for_rest') || !function_exists('get_field_object')) {
    echo json_encode(array('skip' => 'ACF is not present on this site.')) . "\n";
    return;
}

$runId = getenv('WPMCP_TEST_RUN_ID');
$runId = (is_string($runId) && strlen($runId) === 8 && ctype_xdigit($runId)) ? strtolower($runId) : 'noRunId0';
$prefix = 'wpmcp-test-' . $runId . '-acfprobe';

$answer = array('skip' => '', 'prefix' => $prefix);

/* ------------------------------------------------------------------ the fixture */

$draft = wp_insert_post(array(
    'post_type'    => 'post',
    'post_status'  => 'draft',
    'post_title'   => $prefix . '-hidden-target',
    'post_content' => 'probe',
));
$published = wp_insert_post(array(
    'post_type'    => 'post',
    'post_status'  => 'publish',
    'post_title'   => $prefix . '-public-target',
    'post_content' => 'probe',
));
$host = wp_insert_post(array(
    'post_type'    => 'post',
    'post_status'  => 'publish',
    'post_title'   => $prefix . '-host',
    'post_content' => 'probe',
));

$answer['ids'] = array('draft' => (int) $draft, 'published' => (int) $published, 'host' => (int) $host);

acf_add_local_field_group(array(
    'key'    => 'group_' . $runId . '_acfprobe',
    'title'  => $prefix . ' probe',
    'fields' => array(
        array(
            'key'           => 'field_' . $runId . '_hidden',
            'name'          => 'wpmcp_probe_hidden',
            'label'         => 'Points at a draft',
            'type'          => 'relationship',
            'post_type'     => array('post'),
            'return_format' => 'object',
        ),
        array(
            'key'           => 'field_' . $runId . '_public',
            'name'          => 'wpmcp_probe_public',
            'label'         => 'Points at a published post',
            'type'          => 'relationship',
            'post_type'     => array('post'),
            'return_format' => 'object',
        ),
    ),
    'location' => array(array(array('param' => 'post_type', 'operator' => '==', 'value' => 'post'))),
));

update_field('wpmcp_probe_hidden', array((int) $draft), (int) $host);
update_field('wpmcp_probe_public', array((int) $published), (int) $host);

/**
 * A value reduced to a comparable shape: a WP_Post becomes the string "post:<id>", an
 * integer stays an integer. Two reads are "the same answer" when their shapes match, and a
 * reduced read is recognisable by its leaves being integers rather than posts.
 */
$shape = function ($value) use (&$shape) {
    if ($value instanceof WP_Post) { return 'post:' . (int) $value->ID; }
    if (is_array($value)) {
        $out = array();
        foreach ($value as $key => $item) { $out[$key] = $shape($item); }
        return $out;
    }
    if (is_object($value)) { return 'object:' . get_class($value); }
    return $value;
};

$store       = acf_get_store('values');
$administrator = 0;

foreach (get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ID')) as $candidate) {
    $administrator = (int) $candidate;
}

$answer['administrator'] = $administrator;

/* ------------------------------------------------------- A1 and A2, per caller, per field */

$answer['reads'] = array();

foreach (array('administrator' => $administrator, 'nobody' => 0) as $who => $userId) {
    wp_set_current_user($userId);

    foreach (array('wpmcp_probe_hidden' => (int) $draft, 'wpmcp_probe_public' => (int) $published) as $selector => $target) {
        $store->reset();

        // The ONE documented call the whole design rests on: the field array plus its RAW
        // value, because $format_value is false.
        $field = get_field_object($selector, (int) $host, false, true);
        $raw   = isset($field['value']) ? $field['value'] : null;

        $store->reset();
        $plain = acf_format_value($raw, (int) $host, $field);

        $store->reset();
        $rest = acf_format_value_for_rest($raw, (int) $host, $field, 'standard');

        $answer['reads'][$who . '.' . $selector] = array(
            'target'          => $target,
            'raw'             => $raw,
            'acf_format_value'=> $shape($plain),
            'for_rest'        => $shape($rest),
            'bare_ids'        => array_map('intval', (array) $raw),
            'exposable'       => (bool) acf_rest_reference_is_exposable($target, $field),
            'can_read_target' => (bool) current_user_can('read_post', $target),
        );
    }
}

/* ------------------------------------------------------------------ A3, the cache key */

$key = (int) $host . ':wpmcp_probe_hidden:formatted';

wp_set_current_user(0);

// (a) our path first. Does the store end up holding the REDUCED value, and does a later
//     get_field() see it?
$store->reset();
$fieldA   = get_field_object('wpmcp_probe_hidden', (int) $host, false, true);
$ours     = acf_format_value_for_rest($fieldA['value'], (int) $host, $fieldA, 'standard');
$storedA  = $store->has($key) ? $store->get($key) : null;
$laterGet = get_field('wpmcp_probe_hidden', (int) $host);

// (b) get_field() first, so the store already holds the UN-reduced value. Does the
//     reduction still fire?
$store->reset();
$firstGet = get_field('wpmcp_probe_hidden', (int) $host);
$fieldB   = get_field_object('wpmcp_probe_hidden', (int) $host, false, true);
$oursB    = acf_format_value_for_rest($fieldB['value'], (int) $host, $fieldB, 'standard');

$answer['cache'] = array(
    'key'                    => $key,
    'a_our_call'             => $shape($ours),
    'a_store_after_our_call' => $shape($storedA),
    'a_get_field_after'      => $shape($laterGet),
    'b_get_field_first'      => $shape($firstGet),
    'b_store_after_get_field'=> $shape($store->has($key) ? $store->get($key) : null),
    'b_our_call_after'       => $shape($oursB),
);

/* -------------------------------------- why 'light' is never passed: the type can be null */

$answer['unregistered_field_type_is_null']
    = acf_get_field_type('wpmcp-no-such-field-type-9f2a') === null;

/* ------------------------------------------------------------------ clean up */

wp_set_current_user($administrator);

wp_delete_post((int) $draft, true);
wp_delete_post((int) $published, true);
wp_delete_post((int) $host, true);

$answer['cleaned'] = array(
    'draft'     => get_post((int) $draft) === null,
    'published' => get_post((int) $published) === null,
    'host'      => get_post((int) $host) === null,
);

echo json_encode($answer) . "\n";
