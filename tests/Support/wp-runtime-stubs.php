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
 *
 * ADDED FOR SPRINT 8 ROUND 2 (the path jail). wpmcp_code_resolve() is the first plugin
 * function a unit test drives against the FILESYSTEM, and it needs exactly two more
 * things: get_stylesheet_directory() to say where the jail is, and get_option() for the
 * denylist. Both are backed by $GLOBALS['wpmcp_test_wp'] like everything else here, and
 * a unit test points the first at a scratch directory it built itself - which is what
 * lets "a denied directory reached through ./ is still denied" be a two-millisecond
 * assertion over the real function rather than a live-site probe.
 *
 *   options          array<string, mixed>  what get_option() returns, by name
 *   stylesheet_dir   string                what get_stylesheet_directory() returns
 *   stylesheet       string                what get_stylesheet() returns
 */

if (!isset($GLOBALS['wpmcp_test_wp'])) {
    $GLOBALS['wpmcp_test_wp'] = array('current_user_id' => 0, 'users' => array(), 'caps' => array(), 'actions' => array());
}

// ADDED FOR SPRINT 9. `$wpdb->get_results($sql, ARRAY_N)` is the first call in this
// plugin to name one of wpdb's output constants, and without it the call raises an
// "Undefined constant" Error that sql-select's own catch swallows into a trace - so the
// unit tier reported every green path as a server failure. Core defines it in
// wp-includes/wp-db.php; the value is core's.
if (!defined('ARRAY_N')) { define('ARRAY_N', 'ARRAY_N'); }
if (!defined('ARRAY_A')) { define('ARRAY_A', 'ARRAY_A'); }

// ADDED FOR SPRINT TRACE-TABLE. wpmcp_install() now calls wpmcp_migrate_remove_trace_file(),
// which is where 1.1.1's trace log is deleted, and that names WP_CONTENT_DIR - so the two
// PlatformApiTest cases that drive the installer raised "Undefined constant" instead of
// asserting. It points at a path that DOES NOT EXIST on purpose: the migration then finds no
// directory and no file, takes only the three option deletes, and touches nothing on the
// machine running the suite. Core defines the real one in wp-includes/default-constants.php.
if (!defined('WP_CONTENT_DIR')) {
    define('WP_CONTENT_DIR', sys_get_temp_dir() . '/wpmcp-unit-no-such-content-dir');
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
     * Returns the value unchanged unless a test attached a callback to $hook through
     * WordPressRuntime::addFilter() - which is what real WordPress does with no
     * callbacks attached. Reached from wpmcp_client_ip() (the wpmcp_client_ip filter)
     * via wpmcp_auth_event(), and since sprint 14b from wpmcp_is_local_environment().
     */
    function apply_filters($hook, $value, ...$args)
    {
        $callback = $GLOBALS['wpmcp_test_wp']['filters'][$hook] ?? null;

        return $callback === null ? $value : $callback($value, ...$args);
    }
}

if (!function_exists('update_option')) {
    /**
     * RECORDS, and also answers a later get_option() - which the get_option stub above
     * deliberately does not do on its own.
     *
     * ADDED IN 1.1.1 ROUND 3, for the one test that runs the INSTALLER: the claim is that the
     * schema revision is recorded even when the two optional columns are missing, and that claim
     * is a write. The stub above says a store that pretended to persist would make a test of the
     * real option pass without one; this one is the same store, but a test has to opt into it by
     * asserting on WordPressRuntime::optionWrites(), so nothing passes by accident.
     */
    function update_option($option, $value, $autoload = null)
    {
        $GLOBALS['wpmcp_test_wp']['option_writes'][] = array('option' => $option, 'value' => $value);
        $GLOBALS['wpmcp_test_wp']['options'][$option] = $value;

        return true;
    }
}

if (!function_exists('delete_option')) {
    function delete_option($option)
    {
        $GLOBALS['wpmcp_test_wp']['option_deletes'][] = $option;
        unset($GLOBALS['wpmcp_test_wp']['options'][$option]);

        return true;
    }
}

if (!function_exists('esc_html')) {
    /**
     * ADDED FOR SPRINT TRACE-TABLE ROUND 2. wpmcp_trace_file_notice() is the first admin notice
     * the unit tier renders, and the claim under test is that the path it prints is ESCAPED - so
     * the stub has to escape, not merely pass through. Core's own implementation is
     * `_wp_specialchars($text, ENT_QUOTES)` after the translation filter; this is that, without
     * the filter, which is the part no unit test here has an opinion about.
     */
    function esc_html($text)
    {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('delete_transient')) {
    /**
     * ADDED FOR SPRINT TRACE-TABLE, for the same reason WP_CONTENT_DIR above was: revision 7's
     * migration deletes 1.1.1's `wpmcp_trace_checked` transient, and the installer is driven
     * from the unit tier. Recorded rather than ignored, so "it was deleted" stays assertable.
     */
    function delete_transient($transient)
    {
        $GLOBALS['wpmcp_test_wp']['transient_deletes'][] = $transient;

        return true;
    }
}

if (!function_exists('dbDelta')) {
    /**
     * A NO-OP, AND THAT IS THE POINT: it is exactly what a host whose ALTER is refused does.
     * dbDelta never throws and returns a report rather than a status, so the installer cannot
     * tell the difference either - which is why the column probes exist and why what they gate
     * matters.
     */
    function dbDelta($queries = '', $execute = true)
    {
        $GLOBALS['wpmcp_test_wp']['dbdelta'][] = $queries;

        return array();
    }
}

if (!function_exists('wp_is_file_mod_allowed')) {
    /**
     * ADDED FOR 1.1.1, the file-mod swap. wpmcp_code_constants_forbid() now asks the platform
     * instead of reading DISALLOW_FILE_MODS itself, so the listing follows a hardening
     * plugin's `file_mod_allowed` filter.
     *
     * CORE'S OWN BODY, one line: the constant, passed through the filter
     * (wp-includes/load.php:1838). Written out rather than hard-coded to true so that a unit
     * test can still exercise BOTH branches - through the constant, as it always could, and
     * through the filter, which is the new half - with the stubbed apply_filters above.
     */
    function wp_is_file_mod_allowed($context)
    {
        return apply_filters(
            'file_mod_allowed',
            !defined('DISALLOW_FILE_MODS') || !DISALLOW_FILE_MODS,
            $context
        );
    }
}

if (!function_exists('wp_get_environment_type')) {
    /**
     * ADDED FOR SPRINT 14B. The window cap depends on it. Real WordPress caches its
     * answer for the process; this one reads the global each time, which is what lets a
     * unit test put both branches under the same loaded plugin. Defaults to
     * 'production', core's answer when nothing is configured.
     */
    function wp_get_environment_type()
    {
        return (string) ($GLOBALS['wpmcp_test_wp']['environment'] ?? 'production');
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

if (!function_exists('wpmcp_test_json_sanity')) {
    /**
     * `_wp_json_sanity_check()` reduced to the half that has an observable effect here: every
     * string in the structure is put through UTF-8 conversion, which DROPS the bytes that are not
     * valid UTF-8 (wp-includes/functions.php, `_wp_json_convert_string()`).
     *
     * Core's other half - the depth counter that throws, so wp_json_encode() can return false on
     * a structure nested past $depth - is left out because nothing in this plugin builds one, and
     * a stub that pretended to implement it would be a stub asserting its own behaviour.
     */
    function wpmcp_test_json_sanity($value)
    {
        if (is_string($value)) {
            return function_exists('mb_convert_encoding')
                ? (string) mb_convert_encoding($value, 'UTF-8', 'UTF-8')
                : (string) iconv('UTF-8', 'UTF-8//IGNORE', $value);
        }

        if (is_array($value)) {
            $out = array();
            foreach ($value as $k => $v) {
                $out[is_string($k) ? wpmcp_test_json_sanity($k) : $k] = wpmcp_test_json_sanity($v);
            }

            return $out;
        }

        return $value;
    }
}

if (!function_exists('wp_json_encode')) {
    /**
     * CORE'S OWN SHAPE (wp-includes/functions.php): try json_encode, and on failure sanity-check
     * the value and try again.
     *
     * THIS USED TO BE A BARE json_encode, with a docblock saying the two were "the same answer"
     * for the one place the plugin called it. Sprint CORE-FIX made that FALSE: `SchemaValidator`
     * now renders a non-scalar enum value through this function, and the whole reason it does is
     * that bare json_encode returns FALSE on a value that is not valid UTF-8 - which concatenates
     * into the message as the empty string, so a permitted value vanishes from the list of
     * permitted values. A stub that returned false here would let the pre-fix code pass.
     */
    function wp_json_encode($data, $options = 0, $depth = 512)
    {
        $json = json_encode($data, $options, $depth);

        if ($json !== false) {
            return $json;
        }

        return json_encode(wpmcp_test_json_sanity($data), $options, $depth);
    }
}

if (!function_exists('is_multisite')) {
    /**
     * ADDED FOR SPRINT CORE-FIX. Core's `map_meta_cap` denies `edit_themes` and `update_plugins`
     * on `is_multisite() && ! is_super_admin( $user_id )`, and this plugin reproduces both
     * decisions - so the unit tier has to be able to stand on both sides of that line. Real
     * WordPress answers from a constant fixed at load; this one reads the global each time, which
     * is what lets one loaded plugin be tested on a network and on a single site.
     */
    function is_multisite()
    {
        return (bool) ($GLOBALS['wpmcp_test_wp']['multisite'] ?? false);
    }
}

if (!function_exists('is_super_admin')) {
    /**
     * ADDED FOR SPRINT CORE-FIX, beside is_multisite() and for the same gate.
     *
     * CORE'S TWO ARMS, NOT ONE (review 81, S5). Round 1 answered from a list of ids on any site,
     * and the report called that "core's own one-line body" - it is neither core's nor one line.
     * Core (`wp-includes/capabilities.php:1177-1198`) answers from `get_super_admins()` on a
     * NETWORK and from `has_cap('delete_users')` on a SINGLE SITE, and both arms are here because
     * the second is reachable: a caller with no `is_multisite()` guard in front of it would
     * otherwise be tested against a stub that says false where core says true.
     *
     * BY ID RATHER THAN BY LOGIN on the network arm, and that IS a divergence: core matches
     * `$user->user_login` against `get_super_admins()`. The unit tier's users are an id and a login
     * string with no object behind them, so membership of a set is the closest honest model; the
     * DECISION - is this user in the network's admin set - is the same, and nothing in this plugin
     * reads the login.
     */
    function is_super_admin($user_id = false)
    {
        $id = $user_id ? (int) $user_id : (int) ($GLOBALS['wpmcp_test_wp']['current_user_id'] ?? 0);

        if (!$id) {
            return false;
        }

        if (is_multisite()) {
            return in_array($id, (array) ($GLOBALS['wpmcp_test_wp']['super_admins'] ?? []), true);
        }

        // Core's single-site arm, verbatim in substance: `$user->has_cap('delete_users')`.
        return (bool) current_user_can('delete_users');
    }
}

/*
 * ------------------------------------------------------------------------------------------------
 * THE FIVE BELOW EXIST FOR ONE REASON: tests/unit/AcfResolveGateTest.php.
 *
 * `wpmcp_acf_resolve()` is the capability boundary the ACF module's whole disclosure argument rests
 * on - the layout-title filter can put an un-reduced sub-value on the wire, and what stops that
 * reaching a reader wp-admin would not show it to is those four `current_user_can()` calls and
 * nothing else (review 81, pressure point 1). A boundary described in a review file is not enforced;
 * one EXECUTED by a test is. Executing it needs the object lookups the function makes on its way to
 * each check, so they are here - each one core's answer in a line, driven by the test globals, and
 * not one of them speculative.
 * ------------------------------------------------------------------------------------------------
 */

if (!function_exists('get_post')) {
    /**
     * A post, or null. The test says which ids exist and what post_type each one has; anything else
     * is "no such post", which is the first refusal wpmcp_acf_resolve() makes.
     */
    function get_post($post = null, $output = 'OBJECT', $filter = 'raw')
    {
        $posts = (array) ($GLOBALS['wpmcp_test_wp']['posts'] ?? array());
        $id    = (int) (is_object($post) ? ($post->ID ?? 0) : $post);

        if (!isset($posts[$id])) {
            return null;
        }

        return (object) array('ID' => $id, 'post_type' => (string) $posts[$id]);
    }
}

if (!function_exists('get_term')) {
    /** A term, or null. Same shape of arrangement as get_post() above. */
    function get_term($term, $taxonomy = '', $output = 'OBJECT', $filter = 'raw')
    {
        $terms = (array) ($GLOBALS['wpmcp_test_wp']['terms'] ?? array());
        $id    = (int) (is_object($term) ? ($term->term_id ?? 0) : $term);

        if (!in_array($id, array_map('intval', $terms), true)) {
            return null;
        }

        return (object) array('term_id' => $id, 'taxonomy' => 'category');
    }
}

if (!function_exists('post_type_exists')) {
    /** True for the post types the test registered. */
    function post_type_exists($post_type)
    {
        return in_array((string) $post_type, (array) ($GLOBALS['wpmcp_test_wp']['post_types'] ?? array()), true);
    }
}

if (!function_exists('is_post_type_viewable')) {
    /**
     * Every post type the test registered is viewable. The distinction between registered and
     * viewable is `wpmcp_post_type_ok()`'s business and has its own tests; this file only has to get
     * the ACF gate as far as its capability checks.
     */
    function is_post_type_viewable($post_type)
    {
        return post_type_exists(is_object($post_type) ? ($post_type->name ?? '') : $post_type);
    }
}

if (!function_exists('sanitize_key')) {
    /** Core's body (wp-includes/formatting.php): lowercase, and only [a-z0-9_-] survive. */
    function sanitize_key($key)
    {
        return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $key));
    }
}

if (!function_exists('map_meta_cap')) {
    /**
     * CORE'S `edit_themes` CASE, AND ONLY THAT CASE (sprint CORE-FIX round 2, review 81 S2).
     *
     * `wpmcp_code_constants_forbid()` no longer copies core's three site-level deny branches - it
     * asks `map_meta_cap('edit_themes', $user_id)` whether any of them denies. So the unit tier has
     * to answer that question, and the only honest way to do it is with core's own body for the one
     * capability the plugin asks about (`wp-includes/capabilities.php:607-618`).
     *
     * A STUB OF A DELEGATION IS A WEAKER PROOF THAN A STUB OF A BRANCH, AND THAT IS THE POINT.
     * The branch set now lives in ONE place - core's - so what the unit tier can still prove is
     * that the plugin asks, and that it converts `do_not_allow` into a refusal and anything else
     * into null. The four states the test drives exercise all three of core's branches THROUGH this
     * function, so a plugin that stopped asking, or that inverted the answer, goes red.
     *
     * EVERY OTHER CAPABILITY ANSWERS `array($cap)` rather than guessing. Core's real function maps
     * dozens of meta capabilities and reproducing them here would be this file inventing WordPress;
     * nothing in the plugin passes anything else to it, and a future caller that did would get a
     * permissive answer and should add its arm here deliberately.
     */
    function map_meta_cap($cap, $user_id, ...$args)
    {
        // CORE ENDS WITH `apply_filters( 'map_meta_cap', $caps, $cap, $user_id, $args )`, and this
        // is that filter - the one hook a hardening plugin uses to deny a capability outright. It
        // is here rather than left out because it is the only way the unit tier can make this
        // function's answer DIVERGE from the three branches below: with core's own body on both
        // sides, a gate that asks and a gate that hand-copies are indistinguishable. Forcing the
        // answer is what proves the plugin asks.
        if (isset($GLOBALS['wpmcp_test_wp']['map_meta_cap'][$cap])) {
            return (array) $GLOBALS['wpmcp_test_wp']['map_meta_cap'][$cap];
        }

        if ($cap !== 'edit_themes' && $cap !== 'edit_files' && $cap !== 'edit_plugins') {
            return array($cap);
        }

        if (defined('DISALLOW_FILE_EDIT') && DISALLOW_FILE_EDIT) {
            return array('do_not_allow');
        }

        if (!wp_is_file_mod_allowed('capability_edit_themes')) {
            return array('do_not_allow');
        }

        if (is_multisite() && !is_super_admin($user_id)) {
            return array('do_not_allow');
        }

        return array($cap);
    }
}

if (!function_exists('wp_normalize_path')) {
    /**
     * CORE'S OWN BODY (wp-includes/functions.php): backslashes become slashes, repeated slashes
     * collapse, and a Windows drive letter is upper-cased. Needed only because plugin_basename()
     * below is core's body too, and this is what core's body calls.
     */
    function wp_normalize_path($path)
    {
        $wrapper = '';
        $path    = str_replace('\\', '/', (string) $path);
        $path    = preg_replace('|(?<=.)/+|', '/', $path);

        if (substr($path, 1, 1) === ':') {
            $path = ucfirst($path);
        }

        return $wrapper . $path;
    }
}

if (!function_exists('plugin_basename')) {
    /**
     * CORE'S OWN BODY (wp-admin/includes/plugin.php), minus the mu-plugins arm it has no
     * WPMU_PLUGIN_DIR to take. ADDED FOR SPRINT CORE-FIX: wpmcp_scan_plugins() now keys its
     * array through this function, because get_plugins() keys through it and the two key sets are
     * compared with each other.
     *
     * WRITTEN OUT RATHER THAN RETURNING $file, for the reason FakeWpdb::esc_like() gives: a stub
     * that was the identity function would make "keyed through plugin_basename" and "keyed by a
     * raw readdir path" the same assertion.
     */
    function plugin_basename($file)
    {
        $paths = isset($GLOBALS['wp_plugin_paths']) ? (array) $GLOBALS['wp_plugin_paths'] : array();
        $file  = wp_normalize_path($file);

        arsort($paths);

        foreach ($paths as $dir => $realdir) {
            if (str_starts_with($file, (string) $realdir)) {
                $file = $dir . substr($file, strlen((string) $realdir));
            }
        }

        $plugin_dir = wp_normalize_path(WP_PLUGIN_DIR);
        $file       = preg_replace('#^' . preg_quote($plugin_dir, '#') . '/#', '', $file);

        return trim($file, '/');
    }
}

if (!function_exists('get_file_data')) {
    /**
     * CORE'S OWN SHAPE (wp-includes/functions.php): read the first 8 KB, then one regex per
     * requested header over that text, with the value stripped of a trailing comment. The
     * `$context` arm - which is where the `extra_{$context}_headers` filter lives - is absent
     * because wpmcp_scan_plugins() deliberately passes no context (see its docblock), so a stub
     * that had one could not be exercised.
     */
    function get_file_data($file, $default_headers, $context = '')
    {
        $fp = @fopen($file, 'r');

        if (!$fp) {
            return array_fill_keys(array_keys((array) $default_headers), '');
        }

        $data = fread($fp, 8 * 1024);
        fclose($fp);
        $data = str_replace("\r", "\n", (string) $data);

        $out = array();

        foreach ((array) $default_headers as $field => $regex) {
            if (preg_match('/^(?:[ \t]*<\?php)?[ \t\/*#@]*' . preg_quote($regex, '/') . ':(.*)$/mi', $data, $m) && $m[1]) {
                $out[$field] = trim(preg_replace('/\s*(?:\*\/|\?>).*/', '', $m[1]));
            } else {
                $out[$field] = '';
            }
        }

        return $out;
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

if (!function_exists('get_option')) {
    /**
     * Only what the test set, and the caller's default otherwise. Deliberately NOT a
     * store that remembers writes: nothing in the unit tier calls update_option, and a
     * stub that pretended to persist would make a test of the real option pass without
     * one.
     */
    function get_option($option, $default = false)
    {
        $options = $GLOBALS['wpmcp_test_wp']['options'] ?? array();

        return array_key_exists($option, $options) ? $options[$option] : $default;
    }
}

if (!function_exists('get_stylesheet_directory')) {
    /** The jail's root. A unit test points it at a scratch directory it built. */
    function get_stylesheet_directory()
    {
        return (string) ($GLOBALS['wpmcp_test_wp']['stylesheet_dir'] ?? '');
    }
}

if (!function_exists('get_stylesheet')) {
    /** The active theme's slug, which sprint 8 writes into every version row. */
    function get_stylesheet()
    {
        return (string) ($GLOBALS['wpmcp_test_wp']['stylesheet'] ?? '');
    }
}

/**
 * ADDED FOR SPRINT 7 (header-only credential). wpmcp_extract_token() type-hints
 * WP_REST_Request and reads exactly one thing from it - the `authorization` header.
 * PHP resolves the hint at CALL time, so this class only has to exist for a unit
 * test to hand the function something; it is not a WordPress stand-in and is
 * deliberately not growing past what is read.
 */
if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request
    {
        /** @var array<string, string> header name (lower case) => value */
        private $headers = array();

        /** @param array<string, string> $headers */
        public function __construct($headers = array())
        {
            foreach ((array) $headers as $name => $value) {
                $this->headers[strtolower((string) $name)] = (string) $value;
            }
        }

        /** Real WP_REST_Request returns null for a header it does not have. */
        public function get_header($name)
        {
            $name = strtolower((string) $name);

            return isset($this->headers[$name]) ? $this->headers[$name] : null;
        }

        public function get_body()
        {
            return '';
        }
    }
}
