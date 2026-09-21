<?php
/**
 * Loads the plugin under a minimal WordPress stand-in.
 *
 * The stub list (tests/Support/wp-stubs.php) is the minimum WordPress surface the
 * four plugin files touch *at load time*, discovered empirically rather than guessed:
 * requiring wp-mcp.php with a stub set, reading the fatal error, adding exactly the
 * symbol it named, repeating until the require succeeded. It converged on
 *
 *   constants  ABSPATH, HOUR_IN_SECONDS, DAY_IN_SECONDS
 *   functions  register_activation_hook, register_deactivation_hook,
 *              add_action, plugin_dir_path
 *
 * With those seven, `require wp-mcp.php` succeeds from an unrelated working directory
 * and every plugin function is declared (wpmcp_mint, wpmcp_validate, wpmcp_tools,
 * wpmcp_handle, wpmcp_render_admin, wpmcp_core_tools ... wpmcp_code_tools), because
 * wpmcp_bootstrap() pulls in tools.php, admin.php and endpoint.php.
 *
 * Why nothing else is needed: the plugin's only top-level statements are the ABSPATH
 * guard, three define()s, the two hook registrations, four add_action() calls, and
 * wpmcp_bootstrap(). apply_filters, register_rest_route, __(), esc_html() and $wpdb
 * all sit inside function bodies or closures that no unit test invokes, and PHP
 * resolves parameter type hints such as WP_REST_Request lazily, so WordPress classes
 * need no stubs either.
 *
 * Stubs are deliberately NOT defined in tests/bootstrap.php. If a future sprint adds
 * a load-time call to a real WordPress function, the require fails loudly and names
 * it, instead of being masked by a blanket stub file. Add the one stub the failure
 * names, and record it here.
 *
 * CALLING a plugin function needs more than loading it - get_userdata, WP_Error, a
 * $wpdb and so on. Those live in tests/Support/wp-runtime-stubs.php behind
 * WordPressRuntime::install(), which a test calls AFTER loadPlugin(). Keeping the two
 * sets apart is what preserves the property above: nothing a function body needs can
 * quietly satisfy a new load-time dependency.
 */

declare(strict_types=1);

namespace WpMcp\Tests\Support;

final class WordPressStubs
{
    private static bool $loaded = false;

    /** Define the stubs and require wp-mcp.php exactly once per process. */
    public static function loadPlugin(): void
    {
        if (self::$loaded) {
            return;
        }

        self::$loaded = true;
        self::define();

        require WPMCP_PLUGIN_DIR . '/wp-mcp.php';
    }

    /** Define the stubs without loading any plugin code. */
    public static function define(): void
    {
        require_once __DIR__ . '/wp-stubs.php';
    }

    /**
     * The LOAD-TIME stub names, so a test can assert the list has not quietly grown.
     * The runtime set is WordPressRuntime's business, not this list's.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return [
            'ABSPATH',
            'HOUR_IN_SECONDS',
            'DAY_IN_SECONDS',
            'register_activation_hook',
            'register_deactivation_hook',
            'add_action',
            'plugin_dir_path',
        ];
    }
}
