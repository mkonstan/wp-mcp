<?php
/**
 * Per-test control of the runtime WordPress stubs (tests/Support/wp-runtime-stubs.php)
 * and of the fake $wpdb.
 *
 * WordPressStubs::loadPlugin() gets the plugin *loaded*. This class makes its
 * functions *callable*: it installs the runtime stubs, hands the plugin a recording
 * $wpdb, and resets the whole lot in setUp() so no test inherits another's users.
 *
 * Call it after loadPlugin(), never before - see wp-runtime-stubs.php for why the two
 * stub sets are kept apart.
 */

declare(strict_types=1);

namespace WpMcp\Tests\Support;

final class WordPressRuntime
{
    /**
     * Define the runtime stubs (once per process) and reset all mutable state.
     * Returns the fresh $wpdb double, which is also installed as $GLOBALS['wpdb'].
     */
    public static function install(): FakeWpdb
    {
        require_once __DIR__ . '/wp-runtime-stubs.php';

        $GLOBALS['wpmcp_test_wp'] = [
            'current_user_id' => 0,
            'users'           => [],
            'caps'            => [],
            'actions'         => [],
            'options'         => [],
            'stylesheet_dir'  => '',
            'stylesheet'      => '',
            'environment'     => 'production',
            'filters'         => [],
            'option_writes'   => [],
            'option_deletes'  => [],
            'dbdelta'         => [],
            // SPRINT CORE-FIX: core denies edit_themes and update_plugins on
            // `is_multisite() && ! is_super_admin( $user_id )`, and this plugin reproduces both,
            // so the unit tier has to be able to stand on both sides of that line. Reset to a
            // single site with no super admins - WordPress's own answer on an ordinary install.
            'multisite'       => false,
            'super_admins'    => [],
            // What core's own `map_meta_cap` FILTER answers for a capability, when a test sets one.
            // Empty means "nobody filtered it", which is every ordinary site.
            'map_meta_cap'    => [],
        ];

        // plugin_basename()'s symlink map. A global rather than a key of the array above, because
        // that is where WordPress itself keeps it and the stub is core's body.
        $GLOBALS['wp_plugin_paths'] = [];

        $wpdb = new FakeWpdb();
        $GLOBALS['wpdb'] = $wpdb;

        return $wpdb;
    }

    /** Make get_option($name) return $value. Anything else keeps the caller's default. */
    public static function setOption(string $name, $value): void
    {
        $GLOBALS['wpmcp_test_wp']['options'][$name] = $value;
    }

    /**
     * Point the code tools' jail at $directory and name the active theme $slug.
     *
     * The directory is a real one the test built, because the jail is realpath() and
     * is_link() and file_exists() - every one of which answers about the filesystem and
     * cannot be faked by a return value.
     */
    public static function setTheme(string $directory, string $slug = 'wpmcp-test-theme'): void
    {
        $GLOBALS['wpmcp_test_wp']['stylesheet_dir'] = $directory;
        $GLOBALS['wpmcp_test_wp']['stylesheet']     = $slug;
    }

    /**
     * Make wp_get_environment_type() return $type. install() resets it to 'production',
     * WordPress's own answer when nothing is configured (sprint 14b).
     */
    public static function setEnvironmentType(string $type): void
    {
        $GLOBALS['wpmcp_test_wp']['environment'] = $type;
    }

    /**
     * Attach ONE callback to $hook for apply_filters(). The unit tier had no filters at
     * all until sprint 14b, whose narrowing seam is a filter; nothing else registers one.
     */
    public static function addFilter(string $hook, callable $callback): void
    {
        $GLOBALS['wpmcp_test_wp']['filters'][$hook] = $callback;
    }

    /**
     * Put the site on a NETWORK, optionally with the given user ids as super admins.
     *
     * Both halves in one call because they are one decision: `is_multisite()` alone is not a
     * denial and `is_super_admin()` alone is not a network. install() resets to a single site.
     *
     * @param list<int> $superAdmins user ids is_super_admin() should answer true for
     */
    public static function setMultisite(bool $on, array $superAdmins = []): void
    {
        $GLOBALS['wpmcp_test_wp']['multisite']    = $on;
        $GLOBALS['wpmcp_test_wp']['super_admins'] = array_map('intval', $superAdmins);
    }

    /**
     * Make `map_meta_cap($cap, ...)` answer $caps, the way a plugin on core's own `map_meta_cap`
     * filter would.
     *
     * THE ONLY WAY TO TELL A DELEGATION FROM A COPY in this tier: the runtime stub carries core's
     * own `edit_themes` branches, so a gate that asks core and a gate that restates core's branches
     * answer identically on every ordinary state. Forcing the answer to something the branches
     * would not produce is what makes the difference observable - and it is not a synthetic case,
     * because a hardening plugin denying a capability on that filter is exactly what it models.
     *
     * @param list<string> $caps e.g. ['do_not_allow']
     */
    public static function setMetaCap(string $capability, array $caps): void
    {
        $GLOBALS['wpmcp_test_wp']['map_meta_cap'][$capability] = $caps;
    }

    /** Make get_userdata($id) return a user. */
    public static function addUser(int $id, string $login): void
    {
        $GLOBALS['wpmcp_test_wp']['users'][$id] = $login;
    }

    /** Make get_current_user_id() return $id. 0 means "nobody is logged in". */
    public static function setCurrentUserId(int $id): void
    {
        $GLOBALS['wpmcp_test_wp']['current_user_id'] = $id;
    }

    /**
     * Grant a capability to the current user. Nothing is granted by default.
     *
     * @param string $capability e.g. 'edit_user', or 'edit_user:9' for one object id
     */
    public static function allowCap(string $capability): void
    {
        $GLOBALS['wpmcp_test_wp']['caps'][] = $capability;
    }

    /**
     * Every update_option() since install(): [['option' => ..., 'value' => ...], ...].
     *
     * @return list<array{option: string, value: mixed}>
     */
    public static function optionWrites(): array
    {
        return $GLOBALS['wpmcp_test_wp']['option_writes'] ?? [];
    }

    /** The value the last update_option() wrote for $option, or null when it never did. */
    public static function optionWrite(string $option)
    {
        $found = null;

        foreach (self::optionWrites() as $write) {
            if ($write['option'] === $option) { $found = $write['value']; }
        }

        return $found;
    }

    /**
     * The arguments of every do_action($hook, ...) fired since install().
     *
     * @return list<array<int, mixed>> one entry per firing, arguments in order
     */
    public static function firedActions(string $hook): array
    {
        $found = [];

        foreach ($GLOBALS['wpmcp_test_wp']['actions'] as $fired) {
            if (($fired[0] ?? null) === $hook) {
                $found[] = array_slice($fired, 1);
            }
        }

        return $found;
    }

    /** Register $id as an existing user and make it the current one. */
    public static function logInAs(int $id, string $login): void
    {
        self::addUser($id, $login);
        self::setCurrentUserId($id);
    }
}
