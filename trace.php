<?php
/**
 * Copyright (C) 2026 Max Konstantinovski. GPLv2 or later (see LICENSE).
 *
 * WP MCP - the disclosure boundary's private side.
 *
 * THE RULE THIS FILE IMPLEMENTS. An unexpected failure becomes ONE generic JSON-RPC
 * error on the wire - code -32603, message "Internal error", and an eight-hex trace id -
 * and the whole throwable goes here instead. The client learns that something broke and
 * gets a token it can quote to the operator; the operator gets the class, the message,
 * the file:line, the WP_Error data and the stack. Nothing in between, and no
 * per-error-case wire vocabulary to maintain: see
 * claude_code_memory/fail-loud-explicit-scope.md.
 *
 * THIS IS THE ONLY FILE ALLOWED TO INTROSPECT A THROWABLE. getMessage, getFile, getLine,
 * getTraceAsString, __toString, a (string) cast - all of them put the filesystem layout
 * and often the arguments somewhere, and every one of them is banned from endpoint.php,
 * tools.php and wp-mcp.php by tests/unit/NoDisclosureTest.php. The single thing that may
 * cross back out to a caller is wpmcp_throwable_line(), below, and it is one integer.
 *
 * WHY THE FILE NAME IS RANDOM. The first version wrote wp-content/wpmcp/trace.log behind
 * an .htaccess and checked over HTTP whether that worked. It did not: .htaccess is an
 * APACHE file, nginx neither reads nor serves it, and on the development host
 * `GET /wp-content/wpmcp/trace.log` returned 200 with 14 KB of absolute paths, the OS
 * username, the plugin inventory, tool names, user ids and every stack frame's arguments -
 * to anybody, with no token. The plugin NOTICED and carried on writing, which made the
 * self-check an observation rather than a guard.
 *
 * So the name is now `trace-<32 hex>.log`, generated once from random_bytes and kept in
 * the `wpmcp_trace_log_name` option. The URL is not derivable from anything a client sees:
 * not from the endpoint, not from an error response, not from the directory (index.php is
 * empty), not from a guess. Server configuration becomes belt-and-braces rather than the
 * only thing between a stranger and the log - .htaccess and index.php stay, the self-check
 * stays (it now probes the real name, so it catches a directory listing), and the operator
 * warning is raised site-wide rather than on one settings page nobody has to visit.
 */
if (!defined('ABSPATH')) { exit; }

/** Option holding this site's trace-log file name. See wpmcp_trace_file_name(). */
define('WPMCP_TRACE_NAME_OPTION', 'wpmcp_trace_log_name');

/**
 * Option holding the last self-check's outcome: array('state', 'reason', 'checked_at').
 * The name is historical - it now holds three answers, not a boolean.
 */
define('WPMCP_TRACE_EXPOSED_OPTION', 'wpmcp_trace_log_readable');

/** The three self-check outcomes. See wpmcp_trace_selfcheck(). */
define('WPMCP_TRACE_READABLE', 'readable');
define('WPMCP_TRACE_NOT_READABLE', 'not_readable');
define('WPMCP_TRACE_UNVERIFIED', 'unverified');

/** Option set when a trace could not be written to the log and went to error_log(). */
define('WPMCP_TRACE_UNWRITABLE_OPTION', 'wpmcp_trace_log_unwritable');

/** Transient that keeps the self-check to once a day. */
define('WPMCP_TRACE_CHECK_TRANSIENT', 'wpmcp_trace_checked');

/** The name the log used to have, before it was made unguessable. Deleted on sight. */
define('WPMCP_TRACE_LEGACY_NAME', 'trace.log');

/** The directory the log and its two guard files live in. */
function wpmcp_trace_dir() {
    return WP_CONTENT_DIR . '/wpmcp';
}

/**
 * This site's log file name: `trace-<32 hex>.log`, generated once and remembered.
 *
 * add_option() RATHER THAN update_option(), because two requests can arrive at an empty
 * option at the same time and the second must lose rather than rename the log out from
 * under the first: add_option() returns false when the row already exists, and the value
 * is then re-read. The option is autoloaded, so a normal request pays nothing for it.
 *
 * The stored value is validated on every read. Anything that is not exactly this shape -
 * a hand-edited option, a truncated row, a migration that copied a different site's
 * wp_options - is replaced rather than trusted, because this string becomes a file path.
 */
function wpmcp_trace_file_name() {
    $name = (string) get_option(WPMCP_TRACE_NAME_OPTION, '');

    if (preg_match('/^trace-[0-9a-f]{32}\.log$/', $name) === 1) { return $name; }

    $fresh = 'trace-' . bin2hex(random_bytes(16)) . '.log';

    if (!add_option(WPMCP_TRACE_NAME_OPTION, $fresh, '', 'yes')) {
        $stored = (string) get_option(WPMCP_TRACE_NAME_OPTION, '');

        if (preg_match('/^trace-[0-9a-f]{32}\.log$/', $stored) === 1) { return $stored; }

        update_option(WPMCP_TRACE_NAME_OPTION, $fresh);
    }

    return $fresh;
}

/** The log file itself. */
function wpmcp_trace_path() {
    return wpmcp_trace_dir() . '/' . wpmcp_trace_file_name();
}

/** The URL the log WOULD be served at, which is exactly what the self-check asks about. */
function wpmcp_trace_url() {
    return content_url('wpmcp/' . wpmcp_trace_file_name());
}

/**
 * Create the directory, its two guard files, and an empty log.
 *
 * Called on activation and, because activation never fires for a plugin updated in place,
 * before every write.
 *
 * THE EMPTY LOG IS CREATED ON PURPOSE. A self-check that fetches a URL with no file behind
 * it gets 404 from any server, correctly configured or not, so without this the check
 * would be green on every host and would mean nothing. An empty file makes the question
 * answerable.
 *
 * 0600 ON THE LOG. The umask default is 0644 (measured) and wp_mkdir_p gives the directory
 * 0755, so on a shared host whose parent path is traversable another account could read
 * it. chmod costs nothing and is not a substitute for the random name - it is the same
 * belt-and-braces reasoning.
 *
 * THE LEGACY trace.log IS DELETED. A site that ran the first version of this file has a
 * predictably-named, world-readable log already sitting there, full of the traces this
 * change exists to hide. Leaving it would make the fix cosmetic.
 *
 * Returns true when the directory exists and is writable.
 */
function wpmcp_trace_ensure_dir() {
    $dir = wpmcp_trace_dir();

    if (!is_dir($dir) && !wp_mkdir_p($dir)) { return false; }

    // index.php: an empty PHP file, so a server with directory indexes on shows nothing.
    // The only .php file this plugin writes outside the theme, so it is the only other
    // place the "a write is not finished until the opcode cache is told" rule reaches.
    // It matters on a reinstall: uninstall deletes this path, and with
    // opcache.validate_timestamps off the cache can still hold whatever was compiled from
    // it. Invalidated only when we actually wrote, so a request that finds the file
    // already there costs nothing.
    $index = $dir . '/index.php';
    if (!file_exists($index)) {
        @file_put_contents($index, "<?php\n// Silence is golden.\n");
        wpmcp_opcache_invalidate($index);
    }

    // .htaccess: Apache only, and written the way core writes its own.
    //
    // `Deny from all` on its own is an UNKNOWN DIRECTIVE on Apache 2.4 built without
    // mod_access_compat, and an unknown directive in an .htaccess turns the whole
    // directory into a 500 - which is "not readable", but by breaking the server rather
    // than by configuring it. Each spelling therefore sits behind the IfModule that makes
    // it legal, 2.4's first.
    //
    // REWRITTEN WHEN IT IS THE OLD ONE, not only when it is missing. The first version of
    // this file wrote a bare `Deny from all` followed by a 2.4 block, and a site that ran
    // it already has that on disk; "write only if absent" would leave every upgraded site
    // with the unguarded directive and the 500 risk. The marker is the NEGATED block, which
    // only the corrected content has, so a hand-edited file that keeps it is left alone.
    $htaccess = $dir . '/.htaccess';
    $current  = is_file($htaccess) ? (string) @file_get_contents($htaccess) : '';

    if (strpos($current, '<IfModule !mod_authz_core.c>') === false) {
        @file_put_contents(
            $htaccess,
            "<IfModule mod_authz_core.c>\n"
            . "\tRequire all denied\n"
            . "</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n"
            . "\tOrder deny,allow\n"
            . "\tDeny from all\n"
            . "</IfModule>\n"
        );
    }

    $legacy = $dir . '/' . WPMCP_TRACE_LEGACY_NAME;
    if (is_file($legacy)) { @unlink($legacy); }

    $log = wpmcp_trace_path();
    if (!file_exists($log)) {
        @file_put_contents($log, '');
        @chmod($log, 0600);
    }

    return is_writable($dir);
}

/** Eight hex digits. Short enough to read out loud, long enough to grep for. */
function wpmcp_trace_new_id() {
    return bin2hex(random_bytes(4));
}

/**
 * The ONE fact about a throwable that may cross back to a caller: the line it names.
 *
 * `code-write` parse-checks the PHP it was handed and has to be able to say where the
 * syntax error is - that is a line of source the caller itself just sent, and an agent
 * cannot fix its own mistake without it. The line number discloses nothing about this
 * server; the parser's message discloses the absolute path of the file it was given.
 *
 * It lives HERE, not in tools.php, because the rule is that throwable introspection
 * happens in exactly one reviewable file. A caller wanting a safe fact about a throwable
 * asks for the fact, it does not reach into the object.
 */
function wpmcp_throwable_line(Throwable $e) {
    return (int) $e->getLine();
}

/**
 * Record a throwable and return its trace id.
 *
 * The id is what goes on the wire; everything else stays here.
 *
 * @param Throwable $e      the failure.
 * @param string    $method the JSON-RPC method being served.
 * @param string    $tool   the tool name, for a tools/call.
 */
function wpmcp_trace(Throwable $e, $method = '', $tool = '') {
    return wpmcp_trace_record(get_class($e), $e, $method, $tool, '');
}

/**
 * Record a WP_Error that WordPress CORE produced inside a tool, and return its trace id.
 *
 * A tool's OWN WP_Error is the author's message and goes to the client as `isError` text,
 * and so does a short allow-list of core codes the caller can act on - endpoint.php's
 * wpmcp_tool_error_response() owns that decision. Everything else is the same kind of
 * event as a throwable - unexpected, and potentially full of SQL and paths - so it is
 * treated the same way.
 *
 * THE `data` IS LOGGED, AND IT IS THE WHOLE REASON CORE ERRORS COME HERE. wpdb does not
 * put the failing query in the message: `db_insert_error`'s message is "Could not insert
 * term into the database." and `$wpdb->last_error` is in the error DATA. A log that
 * recorded only the message therefore missed the one detail the docblock used to cite as
 * the justification for treating core errors as throwables. json_encode'd, bounded, and
 * private - allowed here and nowhere else.
 *
 * There is no throwable to take a file:line and a stack from, so one is made here. It
 * names the place the error SURFACED, which for a core WP_Error is the only honest answer:
 * the error object does not carry where it was constructed.
 */
function wpmcp_trace_wp_error($error, $method = '', $tool = '') {
    $surfaced = new RuntimeException((string) $error->get_error_message());

    $data = $error->get_error_data();
    $data = ($data === null || $data === '') ? '' : (string) wp_json_encode($data);

    return wpmcp_trace_record(
        'WP_Error:' . (string) $error->get_error_code(),
        $surfaced,
        $method,
        $tool,
        $data
    );
}

/**
 * One trace, one line, plus the stack indented under it.
 *
 * ISO timestamp, trace id, method, tool, user id, token row id, class, message, file:line,
 * WP_Error data - in that order, as `key=value`, so a grep for `trace=1a2b3c4d` finds the
 * whole event and a log shipper can parse the fields. Newlines in a value are flattened;
 * the stack is the only thing allowed more than one line, and every one of its lines is
 * indented four spaces so the "an event starts at column 0" rule holds.
 *
 * NEVER THE RAW TOKEN. The token is identified by its ROW ID, which is what the admin table
 * already shows, exactly as the auth events do.
 *
 * A FAILED WRITE FALLS BACK TO error_log(), and says so. The previous version returned a
 * trace id and wrote nothing whenever the directory was unwritable or file_put_contents
 * failed - both hidden behind `@` - so the client held a reference to an event that existed
 * nowhere. Silence is the one outcome the rule this file implements forbids: error_log() is
 * private on every host by default and is already where the auth events go, so the trace
 * survives, and an option turns the site-wide admin notice on so somebody fixes the
 * directory.
 */
function wpmcp_trace_record($class, Throwable $e, $method = '', $tool = '', $data = '') {
    $id      = wpmcp_trace_new_id();
    $session = isset($GLOBALS['wpmcp_session']) ? $GLOBALS['wpmcp_session'] : null;

    $fields = array(
        gmdate('c'),
        'trace=' . $id,
        'method=' . wpmcp_trace_field($method),
        'tool=' . wpmcp_trace_field($tool),
        'user=' . ($session ? (int) $session->user_id : 0),
        'token=' . ($session ? (int) $session->id : 0),
        'class=' . wpmcp_trace_field($class),
        'message=' . wpmcp_trace_field($e->getMessage()),
        'at=' . wpmcp_trace_field($e->getFile() . ':' . $e->getLine()),
    );

    if ($data !== '') { $fields[] = 'data=' . wpmcp_trace_field($data); }

    $line  = implode(' ', $fields);
    $stack = '';

    foreach (explode("\n", $e->getTraceAsString()) as $frame) {
        $stack .= '    ' . $frame . "\n";
    }

    $entry   = $line . "\n" . $stack;
    $written = false;

    if (wpmcp_trace_ensure_dir()) {
        $written = (@file_put_contents(wpmcp_trace_path(), $entry, FILE_APPEND | LOCK_EX) !== false);
    }

    if ($written) {
        if (get_option(WPMCP_TRACE_UNWRITABLE_OPTION, 0)) {
            delete_option(WPMCP_TRACE_UNWRITABLE_OPTION);
        }
    } else {
        // The trace still exists somewhere, and the operator is told where.
        error_log('wp-mcp trace (log file unwritable) ' . $entry);
        update_option(WPMCP_TRACE_UNWRITABLE_OPTION, 1);
    }

    return $id;
}

/** One field: newlines flattened, bounded, empty written as "" so the shape stays parseable. */
function wpmcp_trace_field($value) {
    $value = str_replace(array("\r", "\n"), ' ', (string) $value);

    // A WP_Error's data, and a throwable's message, can both be arbitrarily long. One
    // event must not be able to become a megabyte of log.
    if (strlen($value) > 2000) { $value = substr($value, 0, 2000) . '...'; }

    return $value === '' ? '""' : $value;
}

/* ---------------- is the log actually private? ---------------- */

/**
 * Ask this host whether it serves the trace log, and remember which of THREE answers it gave.
 *
 * STILL WORTH ASKING NOW THAT THE NAME IS SECRET, for one case: a directory listing. If
 * `wp-content/wpmcp/` is indexable the name stops being secret, and this probe - which
 * fetches the REAL file name, the same URL a stranger would have to find - is what notices.
 * A 200 here means the log is reachable by someone who knows the name, which after a
 * listing is everyone.
 *
 * THREE OUTCOMES, NAMED, BECAUSE "COULD NOT TELL" IS NOT "FINE". The first version returned
 * true/false/null and treated null as "leave the option alone", which is silent - and silent
 * is the one thing the rule this file implements forbids. CI found the case: inside a
 * @wordpress/env container the site's own URL (`http://localhost:8888/...`) is a Docker port
 * mapping that does not exist from within, so wp_remote_get returns a WP_Error and the plugin
 * knew nothing about its own log while saying nothing about it either. Managed hosts block
 * loopback the same way, so this is a production shape, not a CI artifact.
 *
 *   readable      200, and the body IS the log. Someone who knows the name can read it.
 *   not_readable  403, 404, anything else - the server refuses it. What we want.
 *   unverified    wp_remote_get failed, OR a 200 whose body is not the log (a captive
 *                 portal, a proxy error page, a WP 404 template served with 200). The
 *                 question is unanswered and the admin notice says so, with the reason.
 *
 * WHY THE BODY IS COMPARED AND NOT JUST THE STATUS. A 200 from something that is not this
 * file proves nothing in either direction: calling it `readable` raises a false alarm the
 * operator cannot act on, and calling it `not_readable` clears a warning that may be real.
 * The comparison is against the head of the file on disk - and an EMPTY log demands an empty
 * body, because a zero-length prefix matches everything.
 *
 * sslverify IS OFF, deliberately. This is a request to ourselves asking for a status code,
 * carrying no credential and trusting no content, and a development site's certificate is
 * self-signed - with verification on, the one host where the answer is "yes, it is
 * readable" would answer `unverified` instead.
 *
 * NOT ON A REST REQUEST. It is wired to admin_init, so an MCP call never pays for an
 * outbound HTTP request, and the answer is computed where it is displayed.
 *
 * Returns the state string it stored.
 */
function wpmcp_trace_selfcheck() {
    wpmcp_trace_ensure_dir();

    $response = wp_remote_get(wpmcp_trace_url(), array(
        'timeout'     => 5,
        'sslverify'   => false,
        'redirection' => 0,
    ));

    if (is_wp_error($response)) {
        return wpmcp_trace_store_selfcheck(
            WPMCP_TRACE_UNVERIFIED,
            $response->get_error_message()
        );
    }

    if ((int) wp_remote_retrieve_response_code($response) !== 200) {
        return wpmcp_trace_store_selfcheck(WPMCP_TRACE_NOT_READABLE, '');
    }

    if (!wpmcp_trace_body_is_the_log((string) wp_remote_retrieve_body($response))) {
        return wpmcp_trace_store_selfcheck(
            WPMCP_TRACE_UNVERIFIED,
            'the URL answered 200 with something that is not the log file'
        );
    }

    return wpmcp_trace_store_selfcheck(WPMCP_TRACE_READABLE, '');
}

/**
 * Is this response body the log file?
 *
 * Compared against the head of the file rather than all of it, because the log is unbounded
 * and this runs on an admin page load. An empty log is the case a prefix check gets wrong -
 * every string starts with '' - so it is required to be exactly empty.
 */
function wpmcp_trace_body_is_the_log($body) {
    $path = wpmcp_trace_path();

    if (!is_file($path)) { return false; }

    $head = (string) @file_get_contents($path, false, null, 0, 1024);

    return $head === '' ? $body === '' : strncmp($body, $head, strlen($head)) === 0;
}

/**
 * Write the outcome to the option the admin notices read. Returns the state.
 *
 * The reason is flattened and BOUNDED before it is stored: it comes from a WP_Error the
 * HTTP layer produced, it is printed on every admin screen, and a cURL error carrying a
 * whole response body would otherwise become the page. Not wpmcp_trace_field(), which
 * writes an empty value as `""` for the log's key=value shape - here empty means empty.
 */
function wpmcp_trace_store_selfcheck($state, $reason) {
    $reason = str_replace(array("\r", "\n"), ' ', (string) $reason);

    if (strlen($reason) > 300) { $reason = substr($reason, 0, 300) . '...'; }

    update_option(WPMCP_TRACE_EXPOSED_OPTION, array(
        'state'      => (string) $state,
        'reason'     => $reason,
        'checked_at' => gmdate('c'),
    ));

    return (string) $state;
}

/**
 * The last self-check's outcome: one of the three states, or '' if it has never run.
 *
 * A SCALAR VALUE IN THE OPTION IS THE OLD SHAPE, and a site upgrading from it has `1`
 * sitting there meaning "readable". Read it rather than discarding it: dropping the value
 * would silently clear a real warning on exactly the sites that had one.
 */
function wpmcp_trace_selfcheck_state() {
    $stored = get_option(WPMCP_TRACE_EXPOSED_OPTION, '');

    if (is_array($stored)) {
        $state = isset($stored['state']) ? (string) $stored['state'] : '';

        return in_array($state, array(
            WPMCP_TRACE_READABLE,
            WPMCP_TRACE_NOT_READABLE,
            WPMCP_TRACE_UNVERIFIED,
        ), true) ? $state : '';
    }

    return ((int) $stored === 1) ? WPMCP_TRACE_READABLE : '';
}

/** Why the last self-check could not answer, or '' when it could. */
function wpmcp_trace_selfcheck_reason() {
    $stored = get_option(WPMCP_TRACE_EXPOSED_OPTION, '');

    return (is_array($stored) && isset($stored['reason'])) ? (string) $stored['reason'] : '';
}

/** Is the trace log known to be readable from the web? */
function wpmcp_trace_log_is_exposed() {
    return wpmcp_trace_selfcheck_state() === WPMCP_TRACE_READABLE;
}

/** Did the self-check fail to get an answer at all? */
function wpmcp_trace_log_is_unverified() {
    return wpmcp_trace_selfcheck_state() === WPMCP_TRACE_UNVERIFIED;
}

/** Did a trace have to go to error_log() because the log file could not be written? */
function wpmcp_trace_log_is_unwritable() {
    return (int) get_option(WPMCP_TRACE_UNWRITABLE_OPTION, 0) === 1;
}

/**
 * Once a day, on an admin page load.
 *
 * admin_init rather than cron or init: the check costs an outbound HTTP request, the only
 * consumer of its answer is the admin notice, and WP-Cron on a low-traffic site is driven
 * by page loads anyway. The transient is set BEFORE the request, so a host where the fetch
 * hangs for the full timeout does that once a day rather than once a page load.
 */
add_action('admin_init', 'wpmcp_trace_selfcheck_daily');
function wpmcp_trace_selfcheck_daily() {
    if (get_transient(WPMCP_TRACE_CHECK_TRANSIENT)) { return; }

    set_transient(WPMCP_TRACE_CHECK_TRANSIENT, 1, DAY_IN_SECONDS);
    wpmcp_trace_selfcheck();
}

/**
 * SITE-WIDE, not on the plugin's own settings page.
 *
 * Both of these say that the thing holding the stack traces is not behaving: one that a
 * stranger can read it, one that nobody can. An operator who never opens Settings > WP MCP
 * would never have seen either, which is how the first version managed to detect a public
 * log and tell nobody. `admin_notices` puts it on every screen, for anyone who could act
 * on it (manage_options), and neither is dismissible - dismissing would not fix it.
 */
add_action('admin_notices', 'wpmcp_trace_admin_notices');
function wpmcp_trace_admin_notices() {
    if (!current_user_can('manage_options')) { return; }

    if (wpmcp_trace_log_is_exposed()) {
        echo '<div class="notice notice-error"><p><strong>WP MCP: the trace log is'
            . ' readable from the web.</strong></p><p>This plugin fetched its own log file'
            . ' over HTTP and got <code>200</code>. That file holds stack traces, absolute'
            . ' file paths and, when a database call fails, SQL. Its name is random, so the'
            . ' likeliest cause is directory listing being on. Deny'
            . ' <code>/wp-content/wpmcp/</code> in your server config &mdash; nginx:'
            . ' <code>location ^~ /wp-content/wpmcp/ { deny all; }</code></p></div>';
    }

    // UNVERIFIED IS NOT SILENT. The plugin does not know whether its log is public, and the
    // operator is the only one who can find out. A warning rather than an error: nothing is
    // known to be wrong, but nothing is known to be right either.
    if (wpmcp_trace_log_is_unverified()) {
        $reason = wpmcp_trace_selfcheck_reason();

        echo '<div class="notice notice-warning"><p><strong>WP MCP: could not check whether'
            . ' the trace log is readable from the web.</strong></p><p>The plugin tried to'
            . ' fetch its own log file over HTTP and got no usable answer'
            . ($reason === '' ? '' : ' &mdash; <code>' . esc_html($reason) . '</code>')
            . '. A host that blocks requests from itself to itself (a container, or a'
            . ' managed host with loopback closed) does this, and it is not in itself a'
            . ' problem &mdash; but it means the check below is yours to make.</p>'
            . '<p><strong>Check manually:</strong> open'
            . ' <code>' . esc_html(wpmcp_trace_url()) . '</code> in a browser. It must NOT'
            . ' return the file. If it does, deny <code>/wp-content/wpmcp/</code> in your'
            . ' server config &mdash; nginx:'
            . ' <code>location ^~ /wp-content/wpmcp/ { deny all; }</code>, Apache:'
            . ' the <code>.htaccess</code> beside the log already does it.</p></div>';
    }

    if (wpmcp_trace_log_is_unwritable()) {
        echo '<div class="notice notice-error"><p><strong>WP MCP: the trace log could not'
            . ' be written.</strong></p><p>A failure was recorded to the PHP error log'
            . ' instead, because <code>' . esc_html(wpmcp_trace_dir()) . '</code> is not'
            . ' writable. Trace ids handed to clients are still findable there, but fix the'
            . ' directory so they go back to one file.</p></div>';
    }
}
