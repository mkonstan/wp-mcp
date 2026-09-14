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
        ];

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
