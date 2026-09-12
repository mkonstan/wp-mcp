<?php
/**
 * Global-namespace WordPress stubs needed to *call* plugin functions, as opposed to
 * merely loading the plugin. Required by WpMcp\Tests\Support\WordPressRuntime; do not
 * include it directly.
 *
 * Kept separate from wp-stubs.php on purpose. That file is the load-time surface, and
 * its shortness is load-bearing: if a future sprint adds a load-time call to a real
 * WordPress function, `require wp-mcp.php` fails and names it instead of being masked
 * by a blanket stub. Everything here is only reached from inside a function body, so
 * it is defined after the plugin is loaded and cannot hide that drift.
 *
 * No `namespace` declaration, for the same reason as wp-stubs.php: the plugin calls
 * these by their unqualified global names.
 *
 * Behaviour is controlled through $GLOBALS['wpmcp_test_wp'], which
 * WordPressRuntime::install() resets between tests:
 *
 *   current_user_id  int         what get_current_user_id() returns
 *   users            array<int,  string>  id => user_login; get_userdata() returns an
 *                                object for these ids and false for every other
 *   caps             list<string> capabilities current_user_can() grants
 *   actions          list<array>  every do_action() firing, [hook, ...args]
 *
 * ADDED FOR SPRINT 1 (user-bound tokens). wpmcp_mint() is the first plugin function a
 * unit test invokes, and it needs exactly: WP_Error/is_wp_error to report a bad user,
 * get_current_user_id + get_userdata to resolve and validate one, current_time and
 * sanitize_text_field for the row it writes. Nothing here is speculative.
 *
 * ADDED FOR SPRINT 2 (auth events). wpmcp_mint() now fires one, which reaches
 * do_action through wpmcp_auth_event() and apply_filters through wpmcp_client_ip().
 * do_action RECORDS rather than ignoring, because "the event fired" is the claim.
 */

if (!isset($GLOBALS['wpmcp_test_wp'])) {
    $GLOBALS['wpmcp_test_wp'] = array('current_user_id' => 0, 'users' => array(), 'caps' => array(), 'actions' => array());
}

if (!class_exists('WP_Error')) {
    /**
     * The subset of WP_Error the plugin uses: a code, a message, and the $data array
     * that carries an HTTP status on the REST path.
     */
    class WP_Error
    {
        /** @var string */
        public $code;
        /** @var string */
        public $message;
        /** @var mixed */
        public $data;

        public function __construct($code = '', $message = '', $data = '')
        {
            $this->code    = (string) $code;
            $this->message = (string) $message;
            $this->data    = $data;
        }

        public function get_error_code()
        {
            return $this->code;
        }

        public function get_error_message()
        {
            return $this->message;
        }

        public function get_error_data()
        {
            return $this->data;
        }
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing)
    {
        return $thing instanceof WP_Error;
    }
}

if (!function_exists('get_current_user_id')) {
    function get_current_user_id()
    {
        return (int) $GLOBALS['wpmcp_test_wp']['current_user_id'];
    }
}

if (!function_exists('get_userdata')) {
    /**
     * Real WordPress returns a WP_User object, or false when no such user exists.
     * The false branch is the one Sprint 1 depends on, in two places: wpmcp_mint()
     * refusing a bad owner, and wpmcp_authorize() failing a token whose user was
     * deleted. Only ->ID and ->user_login are ever read.
     */
    function get_userdata($user_id)
    {
        $users = $GLOBALS['wpmcp_test_wp']['users'];
        $id    = (int) $user_id;

        if (!isset($users[$id])) {
            return false;
        }

        return (object) array('ID' => $id, 'user_login' => (string) $users[$id]);
    }
}

if (!function_exists('current_user_can')) {
    /**
     * Deny by default. Everything Sprint 1 added is a refusal that has to happen when
     * a capability is ABSENT, so a stub that returned true would make every one of
     * those tests pass without the code under test doing anything.
     *
     * Granted capabilities are listed as 'cap' or, for a meta cap with an object id,
     * 'cap:id' - e.g. 'edit_user:9'. Real map_meta_cap resolves far more than this;
     * the stub only has to distinguish "allowed for this object" from "not".
     */
    function current_user_can($capability, ...$args)
    {
        $caps = $GLOBALS['wpmcp_test_wp']['caps'];

        if (in_array((string) $capability, $caps, true)) {
            return true;
        }

        foreach ($args as $arg) {
            if (is_scalar($arg) && in_array($capability . ':' . $arg, $caps, true)) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('apply_filters')) {
    /**
     * No filters are registered in the unit tier, so this returns the value unchanged
     * - which is what real WordPress does with no callbacks attached. Reached from
     * wpmcp_client_ip() (the wpmcp_client_ip filter) via wpmcp_auth_event().
     */
    function apply_filters($hook, $value, ...$args)
    {
        return $value;
    }
}

if (!function_exists('do_action')) {
    /**
     * Records every fired action in $GLOBALS['wpmcp_test_wp']['actions'] as
     * [hook, args...], so a unit test can assert that wpmcp_mint() fired exactly one
     * mint event with the right context. A no-op stub would let "the event fires" pass
     * without an event.
     */
    function do_action($hook, ...$args)
    {
        $GLOBALS['wpmcp_test_wp']['actions'][] = array_merge([(string) $hook], $args);
    }
}

if (!function_exists('current_time')) {
    function current_time($type, $gmt = false)
    {
        return gmdate('Y-m-d H:i:s');
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str)
    {
        return trim(strip_tags((string) $str));
    }
}
