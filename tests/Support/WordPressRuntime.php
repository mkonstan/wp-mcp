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

        $GLOBALS['wpmcp_test_wp'] = ['current_user_id' => 0, 'users' => []];

        $wpdb = new FakeWpdb();
        $GLOBALS['wpdb'] = $wpdb;

        return $wpdb;
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

    /** Register $id as an existing user and make it the current one. */
    public static function logInAs(int $id, string $login): void
    {
        self::addUser($id, $login);
        self::setCurrentUserId($id);
    }
}
