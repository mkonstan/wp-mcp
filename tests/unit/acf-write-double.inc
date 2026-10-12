<?php
/** Test-local ACF API answers, loaded only by isolated AcfWriteTest children. */
function acf_get_valid_post_id($id) {
    return in_array($id, ['options', 'option'], true) ? 'options_fr' : $id;
}
function acf_format_value_for_rest($raw, $id, $field, $mode) { return 'DISPLAY:' . $raw; }
function get_field_objects($id, $format = true) { return []; }
function acf_get_value($id, $field) { return null; }
function acf_flush_value_cache($id, $name) {}
function acf_get_field_type($type) { return $GLOBALS['acf_write_test']['pro'] ? new stdClass() : null; }
if (empty($GLOBALS['acf_write_test']['omit_options_pages'])) {
    function acf_get_options_pages() { return $GLOBALS['acf_write_test']['pages']; }
}
function acf_get_field_groups($args) {
    $GLOBALS['acf_write_test']['groups'][] = $args;
    // ACF location rules refuse absent screen keys; an acf_id alone is not a term/user screen.
    $locations = $GLOBALS['acf_write_test']['locations'];
    $groups = [];
    foreach ($locations as $rule) {
        $visible = match ($rule['param']) {
            'post' => isset($args['post_id']) && is_int($args['post_id']) && $args['post_id'] === $rule['value'],
            'taxonomy' => isset($args['taxonomy']) && $args['taxonomy'] === $rule['value'],
            'user_form' => isset($args['user_form']) && $args['user_form'] === $rule['value'],
            'user_role' => isset($args['user_id']) && in_array($rule['value'],
                $GLOBALS['acf_write_test']['user_roles'][$args['user_id']] ?? [], true),
            'options_page' => isset($args['options_page']) && $args['options_page'] === $rule['value'],
            default => throw new InvalidArgumentException('Unknown test location rule: ' . $rule['param']),
        };
        if ($visible) { $groups = [['key' => 'group_a'], ['key' => 'group_b']]; break; }
    }
    return $groups;
}
function acf_get_fields($group) {
    $fields = array_values($GLOBALS['acf_write_test']['fields']);
    return $group['key'] === 'group_a' ? array_slice($fields, 0, 1) : array_slice($fields, 1);
}
function get_field_object($key, $id, $format = true, $load = true) {
    $GLOBALS['acf_write_test']['reads'][] = [$key, $id, $format, $load];
    $value = $GLOBALS['acf_write_test']['values'][$id . ':' . $key] ?? null;
    return array_merge($GLOBALS['acf_write_test']['fields'][$key], ['value' => $format ? 'DISPLAY' : $value]);
}
if (empty($GLOBALS['acf_write_test']['omit_update'])) {
function update_field($key, $value, $id) {
    $GLOBALS['acf_write_test']['writes'][] = [$key, $value, $id];
    if ($GLOBALS['acf_write_test']['persist']) {
        $field = $GLOBALS['acf_write_test']['fields'][$key];
        // Model the measured ACF difference rather than assigning the requested object.
        // Clone and group skip null via isset; a repeater row writes it via array_key_exists.
        $old = $GLOBALS['acf_write_test']['values'][$id . ':' . $key] ?? [];
        if ($field['type'] === 'clone' || $field['type'] === 'group') {
            $value = acf_write_test_row($value, $old, $field, true);
        } elseif ($field['type'] === 'repeater' && !empty($GLOBALS['acf_write_test']['model_rows'])) {
            $value = array_map(static fn($row, $index) => acf_write_test_row($row, $old[$index] ?? [], $field, false),
                $value, array_keys($value));
        }
        $GLOBALS['acf_write_test']['values'][$id . ':' . $key] = $GLOBALS['acf_write_test']['stored_answer'] ?? $value;
    }
    return $GLOBALS['acf_write_test']['return'];
}
}

/** Small row model: preserve skipped children; return ACF's raw key-based storage. */
function acf_write_test_row($value, $old, $field, $clone) {
    $row = $old;
    foreach ($field['sub_fields'] as $child) {
        $key = $child['key'];
        $name = $clone ? ($child['_name'] ?? $child['name']) : $child['name'];
        // Keep these branches distinct: isset(null) is false, array_key_exists(null) true.
        if ($clone ? isset($value[$key]) : array_key_exists($key, $value)) {
            $row[$key] = $value[$key];
        } elseif ($clone ? isset($value[$name]) : array_key_exists($name, $value)) {
            $row[$key] = $value[$name];
        }
    }
    return $row;
}
