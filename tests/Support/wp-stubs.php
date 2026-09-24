<?php
/**
 * Global-namespace WordPress stubs. Required by WpMcp\Tests\Support\WordPressStubs;
 * do not include it directly.
 *
 * This file has no `namespace` declaration on purpose: a function declared inside a
 * namespaced file becomes a namespaced function, and the plugin calls these by their
 * unqualified global names.
 *
 * THE COMPLETE LIST. See WordPressStubs for how it was discovered and why it is this
 * short. Add nothing speculatively.
 */

if (!defined('ABSPATH')) {
    // Every plugin file opens with `if (!defined('ABSPATH')) { exit; }`. The value is
    // never dereferenced on any path a unit test reaches, so point it at nothing.
    define('ABSPATH', __DIR__ . '/no-such-wp-root/');
}

if (!defined('HOUR_IN_SECONDS')) {
    // Needed at load time by define('WPMCP_MAX_WINDOW', 12 * HOUR_IN_SECONDS).
    define('HOUR_IN_SECONDS', 3600);
}

if (!defined('DAY_IN_SECONDS')) {
    // Sprint 7: define('WPMCP_MAX_LIFETIME', 365 * DAY_IN_SECONDS).
    define('DAY_IN_SECONDS', 86400);
}

if (!function_exists('register_activation_hook')) {
    function register_activation_hook($file, $callback) {}
}

if (!function_exists('register_deactivation_hook')) {
    function register_deactivation_hook($file, $callback) {}
}

if (!function_exists('add_action')) {
    function add_action($hook, $callback, $priority = 10, $accepted_args = 1)
    {
        return true;
    }
}

if (!function_exists('add_filter')) {
    // endpoint.php registers the verb gate on rest_pre_dispatch at file scope (sprint 3).
    function add_filter($hook, $callback, $priority = 10, $accepted_args = 1)
    {
        return true;
    }
}

if (!class_exists('Walker')) {
    /**
     * A LOAD-TIME CLASS DEPENDENCY, and the first one: tools.php declares
     * `class WpMcp_Menu_Collector extends Walker` at file scope (1.1.1, the menu-tree swap
     * to core's walker), so `require wp-mcp.php` fatals without a parent class to extend.
     *
     * Empty on purpose. The stub has to satisfy the EXTENDS and nothing more: no unit test
     * calls walk(), because what the walker does with a menu is a fact about WordPress and
     * is measured against a real site (tests/integration/MenuToolsTest.php). A stub that
     * reimplemented walk() would be a second implementation of the very thing the swap
     * exists to stop maintaining.
     */
    class Walker {}
}

if (!function_exists('plugin_dir_path')) {
    function plugin_dir_path($file)
    {
        return rtrim(dirname($file), '/' . DIRECTORY_SEPARATOR) . '/';
    }
}
