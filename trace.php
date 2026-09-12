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
 * the file:line and the stack. Nothing in between, and no per-error-case wire vocabulary
 * to maintain: see claude_code_memory/fail-loud-explicit-scope.md.
 *
 * THE LOG IS THE ONLY PLACE getMessage() IS ALLOWED TO BE CALLED. endpoint.php and
 * tools.php are held to that by a unit test (tests/unit/NoDisclosureTest.php) which
 * greps both files for that call. This file is the exception, and the only one.
 *
 * WHERE IT LIVES, AND WHY THAT IS NOT ENOUGH. wp-content/wpmcp/trace.log, with an
 * index.php and an .htaccess beside it. .htaccess is an APACHE file: nginx does not read
 * it, so on an nginx host those lines buy nothing at all. Rather than pretend, the
 * plugin ASKS - it fetches its own log URL over HTTP and, on a 200, sets an option that
 * turns the admin page's notice red with the server-config line an operator needs. The
 * answer on the site this was developed against is 200; see wpmcp_trace_selfcheck().
 */
if (!defined('ABSPATH')) { exit; }

/** Option set when the self-check found the trace log readable over HTTP. */
define('WPMCP_TRACE_EXPOSED_OPTION', 'wpmcp_trace_log_readable');

/** Transient that keeps the self-check to once a day. */
define('WPMCP_TRACE_CHECK_TRANSIENT', 'wpmcp_trace_checked');

/** The directory the log and its two guard files live in. */
function wpmcp_trace_dir() {
    return WP_CONTENT_DIR . '/wpmcp';
}

/** The log file itself. */
function wpmcp_trace_path() {
    return wpmcp_trace_dir() . '/trace.log';
}

/** The URL the log WOULD be served at, which is exactly what the self-check asks about. */
function wpmcp_trace_url() {
    return content_url('wpmcp/trace.log');
}

/**
 * Create the directory, its two guard files, and an empty log.
 *
 * Called on activation and, because activation never fires for a plugin updated in
 * place, before every write.
 *
 * THE EMPTY LOG IS CREATED ON PURPOSE. A self-check that fetches a URL with no file
 * behind it gets 404 from any server, correctly configured or not, so without this the
 * check would be green on every host and would mean nothing. An empty file makes the
 * question answerable.
 *
 * Returns true when the directory exists and is writable.
 */
function wpmcp_trace_ensure_dir() {
    $dir = wpmcp_trace_dir();

    if (!is_dir($dir) && !wp_mkdir_p($dir)) { return false; }

    // index.php: an empty PHP file, so a server with directory indexes on shows nothing.
    $index = $dir . '/index.php';
    if (!file_exists($index)) { @file_put_contents($index, "<?php\n// Silence is golden.\n"); }

    // .htaccess: Apache only. Both spellings, because 2.2 and 2.4 differ.
    $htaccess = $dir . '/.htaccess';
    if (!file_exists($htaccess)) {
        @file_put_contents(
            $htaccess,
            "Deny from all\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n"
        );
    }

    $log = wpmcp_trace_path();
    if (!file_exists($log)) { @file_put_contents($log, ''); }

    return is_writable($dir);
}

/** Eight hex digits. Short enough to read out loud, long enough to grep for. */
function wpmcp_trace_new_id() {
    return bin2hex(random_bytes(4));
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
    return wpmcp_trace_record(get_class($e), $e, $method, $tool);
}

/**
 * Record a WP_Error that WordPress CORE produced inside a tool, and return its trace id.
 *
 * A tool's OWN WP_Error is the author's message and goes to the client as `isError` text
 * (endpoint.php decides which is which by the `wpmcp_` prefix on the error code). One
 * that came out of wpdb, wp_insert_post or any other core call is the same kind of event
 * as a throwable - unexpected, and potentially full of SQL and paths - so it is treated
 * the same way.
 *
 * There is no throwable to take a file:line and a stack from, so one is made here. It
 * names the place the error SURFACED, which for a core WP_Error is the only honest
 * answer: the error object does not carry where it was constructed.
 */
function wpmcp_trace_wp_error($error, $method = '', $tool = '') {
    $surfaced = new RuntimeException((string) $error->get_error_message());

    return wpmcp_trace_record(
        'WP_Error:' . (string) $error->get_error_code(),
        $surfaced,
        $method,
        $tool
    );
}

/**
 * One trace, one line, plus the stack indented under it.
 *
 * ISO timestamp, trace id, method, tool, user id, token row id, class, message,
 * file:line - in that order, as `key=value`, so a grep for `trace=1a2b3c4d` finds the
 * whole event and a log shipper can parse the fields. Newlines in the message are
 * flattened; the stack is the only thing allowed more than one line, and every one of
 * its lines is indented four spaces so the "an event starts at column 0" rule holds.
 *
 * NEVER THE RAW TOKEN. The token is identified by its ROW ID, which is what the admin
 * table already shows, exactly as the auth events do.
 *
 * Returns the trace id even when the write failed - the client still gets an id, and an
 * id with no line behind it is itself a finding ("your log directory is not writable").
 */
function wpmcp_trace_record($class, Throwable $e, $method = '', $tool = '') {
    $id      = wpmcp_trace_new_id();
    $session = isset($GLOBALS['wpmcp_session']) ? $GLOBALS['wpmcp_session'] : null;

    $line = implode(' ', array(
        gmdate('c'),
        'trace=' . $id,
        'method=' . wpmcp_trace_field($method),
        'tool=' . wpmcp_trace_field($tool),
        'user=' . ($session ? (int) $session->user_id : 0),
        'token=' . ($session ? (int) $session->id : 0),
        'class=' . wpmcp_trace_field($class),
        'message=' . wpmcp_trace_field($e->getMessage()),
        'at=' . wpmcp_trace_field($e->getFile() . ':' . $e->getLine()),
    ));

    $stack = '';

    foreach (explode("\n", $e->getTraceAsString()) as $frame) {
        $stack .= '    ' . $frame . "\n";
    }

    if (wpmcp_trace_ensure_dir()) {
        @file_put_contents(wpmcp_trace_path(), $line . "\n" . $stack, FILE_APPEND | LOCK_EX);
    }

    return $id;
}

/** One field: newlines flattened, empty written as "" so the shape stays parseable. */
function wpmcp_trace_field($value) {
    $value = str_replace(array("\r", "\n"), ' ', (string) $value);

    return $value === '' ? '""' : $value;
}

/* ---------------- is the log actually private? ---------------- */

/**
 * Ask this host whether it serves the trace log, and remember the answer.
 *
 * .htaccess is Apache's. nginx, Caddy and LiteSpeed's own server mode ignore it, and a
 * log of stack traces under a public URL is worth more to an attacker than most of what
 * the endpoint guards. Guessing the server from $_SERVER would be a guess; one HTTP
 * request to our own URL is the measurement.
 *
 * 200 -> set the option, and the admin page turns red with the server line to add.
 * Anything else -> delete it. A transport failure is INCONCLUSIVE and leaves the option
 * exactly as it was: a DNS hiccup must not clear a real warning, and must not raise one.
 *
 * sslverify IS OFF, deliberately. This is a request to ourselves asking for a status
 * code, carrying no credential and trusting no content, and a development site's
 * certificate is self-signed - with verification on, the one host where the answer is
 * "yes, it is readable" would answer "inconclusive" instead.
 *
 * NOT ON A REST REQUEST. It is wired to admin_init, so an MCP call never pays for an
 * outbound HTTP request, and the answer is computed where it is displayed.
 *
 * Returns true (readable), false (not readable) or null (could not tell).
 */
function wpmcp_trace_selfcheck() {
    wpmcp_trace_ensure_dir();

    $response = wp_remote_get(wpmcp_trace_url(), array(
        'timeout'     => 5,
        'sslverify'   => false,
        'redirection' => 0,
    ));

    if (is_wp_error($response)) { return null; }

    $readable = ((int) wp_remote_retrieve_response_code($response) === 200);

    if ($readable) {
        update_option(WPMCP_TRACE_EXPOSED_OPTION, 1);
    } else {
        delete_option(WPMCP_TRACE_EXPOSED_OPTION);
    }

    return $readable;
}

/** Is the trace log known to be readable from the web? What the admin notice reads. */
function wpmcp_trace_log_is_exposed() {
    return (int) get_option(WPMCP_TRACE_EXPOSED_OPTION, 0) === 1;
}

/**
 * Once a day, on an admin page load.
 *
 * admin_init rather than cron or init: the check costs an outbound HTTP request, the only
 * consumer of its answer is the admin page, and WP-Cron on a low-traffic site is driven
 * by page loads anyway. The transient is set BEFORE the request, so a host where the
 * fetch hangs for the full timeout does that once a day rather than once a page load.
 */
add_action('admin_init', 'wpmcp_trace_selfcheck_daily');
function wpmcp_trace_selfcheck_daily() {
    if (get_transient(WPMCP_TRACE_CHECK_TRANSIENT)) { return; }

    set_transient(WPMCP_TRACE_CHECK_TRANSIENT, 1, DAY_IN_SECONDS);
    wpmcp_trace_selfcheck();
}
