<?php
/** Child-only filtered-read / write-outcome probe; approved by mailbox answer 12. */
declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
\WpMcp\Tests\Support\WordPressStubs::loadPlugin();

// Declare these after plugin loading, before runtime installation. They must not mask
// an accidental load-time dependency, and the shared runtime file stays untouched.
if (!function_exists('get_option')) {
    function get_option($name, $default = false)
    {
        $options = $GLOBALS['wpmcp_test_wp']['options'];
        $value = array_key_exists($name, $options) ? $options[$name] : $default;
        return apply_filters('option_' . $name, $value);
    }
}
if (!function_exists('update_option')) {
    function update_option($name, $value, $autoload = null)
    {
        $case = $GLOBALS['wpmcp_site_config_probe_case'];
        // Model the relevant core no-op: an effective old value equal to the request
        // stops core before storage. Other outcomes explicitly separate saving from return.
        if (get_option($name) === $value || $case['mode'] === 'failed') { return false; }
        $actual = $case['saved'] ?? $value;
        $GLOBALS['wpmcp_test_wp']['option_writes'][] = ['option' => $name, 'value' => $actual];
        $GLOBALS['wpmcp_test_wp']['options'][$name] = $actual;
        return $case['mode'] === 'success';
    }
}

if (count($argv) !== 2) {
    throw new InvalidArgumentException('scenario is required as one JSON argument; e.g. {"mode":"success","requested":"After"}.');
}
$case = json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
if (!is_array($case) || !in_array($case['mode'] ?? null, ['success', 'failed', 'false_saved'], true)
    || !is_string($case['requested'] ?? null)) {
    throw new InvalidArgumentException('scenario requires mode success/failed/false_saved and a string requested, e.g. "After".');
}
\WpMcp\Tests\Support\WordPressRuntime::install();
require __DIR__ . '/SiteConfigStoredOptionStub.php';
\WpMcp\Tests\Support\WordPressRuntime::allowCap('manage_options');
\WpMcp\Tests\Support\WordPressRuntime::allowCap('edit_posts');
$GLOBALS['wpmcp_site_config_probe_case'] = $case;
if (array_key_exists('old', $case)) {
    \WpMcp\Tests\Support\WordPressRuntime::setOption('blogname', $case['old']);
}
if (array_key_exists('effective', $case)) {
    \WpMcp\Tests\Support\WordPressRuntime::addFilter('option_blogname', static fn ($value) => $case['effective']);
}
$rows = new \WpMcp\Tests\Support\SiteConfigOptionRows();
$rows->failRead = $case['fail_read'] ?? null;
$GLOBALS['wpdb'] = $rows;
$result = wpmcp_inventory_tools()['update-option']['run'](['name' => 'blogname', 'value' => $case['requested']]);
if ($result instanceof WP_Error) {
    $result = ['error' => $result->get_error_code(), 'message' => $result->get_error_message()];
}
echo '-----SITE-OPTION-----', json_encode([
    'result' => $result, 'writes' => \WpMcp\Tests\Support\WordPressRuntime::optionWrites(),
    'stored' => $GLOBALS['wpmcp_test_wp']['options']['blogname'] ?? null,
    'effective' => get_option('blogname'), 'reads' => $rows->queries,
], JSON_THROW_ON_ERROR), '-----END-----';
