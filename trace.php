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
 * getTrace, getTraceAsString, __toString, a (string) cast - all of them put the filesystem
 * layout and often the arguments somewhere, and every one of them is banned from endpoint.php,
 * tools.php and wp-mcp.php by tests/unit/NoDisclosureTest.php. The single thing that may
 * cross back out to a caller is wpmcp_throwable_line(), below, and it is one integer.
 *
 * AND THIS FILE DOES NOT USE getTraceAsString() EITHER, since 1.1.1: PHP's own formatter prints
 * the first fifteen characters of every string argument, which is a value. wpmcp_trace_stack()
 * builds the stack from getTrace() and writes each argument's SHAPE instead - see there.
 *
 * SINCE 1.1.2 A TRACE IS A ROW, NOT A LINE IN A FILE, and the whole reason is that a file
 * could not be made private on the hosts most sites run on. `wp-content/wpmcp/trace.log`
 * sat behind an `.htaccess`, which is an APACHE file: nginx has no per-directory config and
 * never reads it. MEASURED on the development host - `GET /wp-content/wpmcp/trace.log`
 * returned 200 with 14 KB of absolute paths, the OS username, the plugin inventory, tool
 * names, user ids and every stack frame - to anybody, with no token. Randomising the file
 * name bought secrecy and nothing more: the URL still existed and a directory listing still
 * handed it over. A TABLE CANNOT BE SERVED OVER HTTP AT ALL, so the whole class of failure
 * is gone rather than mitigated, and with it two sprints of machinery: the guard files, the
 * daily HTTP self-check, both admin notices, the size cap, the keep-newest-75% rewrite, the
 * absorb-and-retry trim, the short-write retry, flock, fstat and ftruncate. Atomicity is the
 * database's problem now - one INSERT either happens or does not. See analysis/53 D25.
 *
 * THE OBJECTION THAT DID NOT SURVIVE, recorded so it is not re-made: a trace must survive a
 * broken database, since database failures are among the things it records. It cannot happen.
 * If the database is genuinely down WordPress never boots, the visitor gets "Error
 * establishing a database connection" and this plugin is not running to log anything. The
 * realistic case is a SINGLE query failing - bad SQL in `sql-select`, a missing table, a
 * deadlock - where the connection is fine and an INSERT succeeds.
 *
 * THE TWO COSTS OF A TABLE, DESIGNED AGAINST RATHER THAN DISCOVERED. (1) A trace now rides
 * in every database backup, export and staging clone, where a file did not - so retention is
 * DAYS, and both caps are below. (2) `sql-select` must refuse this table the way it already
 * refuses the token table, or a caller reads straight through the boundary the rest of the
 * plugin maintains: see wpmcp_sql_denied_identifiers() in tools.php.
 *
 * THE ONE PROMISE, UNCHANGED FROM THE FILE ERA: the boundary hands a caller an eight-hex
 * trace id and tells it to quote that id, so the id must still resolve for the person
 * supporting the site. That is why `trace_id` is INDEXED and why the settings screen has a
 * single lookup on it - see wpmcp_trace_find() and admin.php.
 */
if (!defined('ABSPATH')) { exit; }

/**
 * How many days of traces are kept, and how many rows at most. Filterable; both floored.
 *
 * SEVEN DAYS, AND THE NUMBER IS THE FIRST COST. A trace now sits in every database backup
 * and every staging clone, so "for ever" is not available - but a support case opened on
 * Monday still has to be answerable on Friday, and one working week is the shortest span
 * that is true of. Past that the id a caller was given stops resolving, which is the price
 * of not carrying stack traces into every copy of the database anybody ever takes.
 *
 * TWO THOUSAND ROWS AS WELL AS SEVEN DAYS, because the age cap alone is unbounded in SIZE:
 * a site in a retry loop can write a hundred thousand rows inside its window and the
 * operator's next backup carries all of them. MEASURED 2026-09-24 (analysis/62): mean entry
 * 2,283 bytes, and 763 entries in eleven days on a development site under continuous suite
 * load - 69 failures a day, far more than a real site sees. Seven days at that rate is 486
 * rows, about 1.1 MB. The row cap bites at 286 failures a day, four times that rate, and
 * holds the worst case at 2,000 rows - about 4.6 MB, which is the hard ceiling this feature
 * adds to a backup.
 *
 * AND IT IS THE OLDEST ROWS THAT GO, for the reason the file's cap was cut the same way: the
 * newest entry is the one whose id was issued a moment ago and is about to be quoted.
 *
 * A USELESS FILTERED VALUE IS IGNORED RATHER THAN OBEYED, which is the wpmcp_file_versions_keep
 * rule: zero days or ten rows would throw away the entry whose id the caller is holding, and
 * that is far likelier to be a mistake than a decision.
 */
define('WPMCP_TRACE_KEEP_DAYS', 7);
define('WPMCP_TRACE_KEEP_ROWS', 2000);

/**
 * The most stack frames one row stores.
 *
 * THE ONE VALUE THE FILE'S BYTE CAP USED TO BOUND AND A COLUMN DOES NOT. Every other field
 * is cut at 2,000 characters by wpmcp_trace_field(); the stack was not, because the file had
 * a 2 MiB ceiling over the whole thing. A runaway recursion produces thousands of frames, so
 * without this one row could be a megabyte - and it would ride in every backup 2,000 times
 * over. Two hundred frames is far past the depth anybody reads and is about 30 KB at the
 * measured frame length; what is dropped is the MIDDLE of the call chain, and the line saying
 * so is in the stack itself.
 */
define('WPMCP_TRACE_STACK_FRAMES', 200);

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
 * One trace, one row in `wp_wpmcp_traces`. Returns the trace id either way.
 *
 * The fields are the file era's, unchanged: time, trace id, method, tool, user id, token
 * row id, class, message, file:line, WP_Error data, stack.
 *
 * SEPARATE COLUMNS RATHER THAN ONE JSON PAYLOAD, and the reason is the sweep and the
 * lookup. Both caps below key on `logged_at`, and the lookup keys on `trace_id`, so at
 * least two fields have to be columns whatever else happens - and once a row is half
 * columns and half payload, every reader needs to know which half a field is in. They are
 * also each a natural width: `trace_id` is eight characters, the two ids are integers, and
 * `message`, `data` and `stack` are the only three that can be long. A payload would buy
 * nothing but a json_decode on the one screen that reads a row.
 *
 * `trace_id` IS INDEXED AND NOT UNIQUE. Indexed because looking one up by id is the only
 * read that matters - it is what the boundary's promise reduces to. Not unique, because a
 * duplicate would then make the INSERT fail and the entry would be lost at the exact moment
 * its id went out on the wire; eight hex is 4.3 billion values against at most 2,000 rows,
 * so a collision is vanishingly unlikely, and if one ever happens the lookup shows both
 * rows rather than hiding the one being asked about.
 *
 * NEVER THE RAW TOKEN. The token is identified by its ROW ID, which is what the admin table
 * already shows, exactly as the auth events do.
 *
 * A FAILED INSERT FALLS BACK TO error_log(), unchanged from the file era and for the same
 * reason: the client is holding a reference to this event, and silence is the one outcome
 * the rule this file implements forbids. error_log() is private on every host by default
 * and is already where the auth events go. There is no admin notice behind it any more -
 * the two the file needed were about the file, and the one case left is a missing table,
 * which the installer retries on the next request.
 */
function wpmcp_trace_record($class, Throwable $e, $method = '', $tool = '', $data = '') {
    global $wpdb;

    $id      = wpmcp_trace_new_id();
    $session = isset($GLOBALS['wpmcp_session']) ? $GLOBALS['wpmcp_session'] : null;

    $row = array(
        'trace_id'  => $id,
        'logged_at' => gmdate('Y-m-d H:i:s'),
        'method'    => wpmcp_trace_field($method),
        'tool'      => wpmcp_trace_field($tool),
        'user_id'   => $session ? (int) $session->user_id : 0,
        'token_id'  => $session ? (int) $session->id : 0,
        'class'     => wpmcp_trace_field($class),
        'message'   => wpmcp_trace_field($e->getMessage()),
        'at'        => wpmcp_trace_field($e->getFile() . ':' . $e->getLine()),
        'data'      => $data === '' ? '' : wpmcp_trace_field($data),
        'stack'     => implode("\n", wpmcp_trace_stack($e)),
    );

    // $wpdb->insert() rather than a prepared INSERT: it is the platform API for exactly
    // this, it escapes every value under the connection's charset, and the format list
    // makes the two integer columns integers rather than quoted strings.
    $written = $wpdb->insert(
        wpmcp_traces_table(),
        $row,
        array('%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s')
    );

    if (!$written) {
        error_log('wp-mcp trace (could not be stored) ' . wpmcp_trace_entry($row));
    }

    return $id;
}

/**
 * One stored trace as the text the file used to hold: a `key=value` header line, with the
 * stack indented four spaces under it.
 *
 * THE SHAPE IS KEPT ON PURPOSE. It is what the error_log() fallback writes, what the
 * settings screen prints, and what every operator who has read this plugin's log before
 * already knows how to read - `grep trace=1a2b3c4d` still finds the event. An empty value
 * is written `""` so the line stays parseable; that is a property of the LINE and not of
 * the column, which simply holds ''.
 *
 * @param array|object $row a row of `wp_wpmcp_traces`, or the array about to become one.
 */
function wpmcp_trace_entry($row) {
    $row = (array) $row;

    $value = static function ($key) use ($row) {
        $v = isset($row[$key]) ? (string) $row[$key] : '';

        return $v === '' ? '""' : $v;
    };

    $fields = array(
        isset($row['logged_at']) ? gmdate('c', strtotime((string) $row['logged_at'] . ' UTC')) : gmdate('c'),
        'trace=' . $value('trace_id'),
        'method=' . $value('method'),
        'tool=' . $value('tool'),
        'user=' . (int) ($row['user_id'] ?? 0),
        'token=' . (int) ($row['token_id'] ?? 0),
        'class=' . $value('class'),
        'message=' . $value('message'),
        'at=' . $value('at'),
    );

    if ((string) ($row['data'] ?? '') !== '') { $fields[] = 'data=' . $value('data'); }

    $entry = implode(' ', $fields) . "\n";

    foreach (explode("\n", (string) ($row['stack'] ?? '')) as $frame) {
        if ($frame !== '') { $entry .= '    ' . $frame . "\n"; }
    }

    return $entry;
}

/**
 * The rows carrying this trace id, newest first. Empty when there are none.
 *
 * THE ID IS CHECKED AGAINST ITS OWN SHAPE BEFORE IT REACHES A QUERY, which is what keeps
 * this a LOOKUP and not a search: eight lower-case hex digits or nothing at all. A caller
 * cannot widen it into `%`, into an empty string that would match everything, or into a
 * second condition. Five rows at most, because an id is one event - the limit exists only
 * so a collision cannot turn one paste into an unbounded read.
 *
 * @return array<int, object>
 */
function wpmcp_trace_find($traceId) {
    global $wpdb;

    $traceId = strtolower(trim((string) $traceId));

    if (preg_match('/^[0-9a-f]{8}$/', $traceId) !== 1) { return array(); }

    $rows = $wpdb->get_results($wpdb->prepare(
        'SELECT * FROM ' . wpmcp_traces_table() . ' WHERE trace_id = %s ORDER BY id DESC LIMIT 5',
        $traceId
    ));

    return is_array($rows) ? $rows : array();
}

/** Days of traces kept. Filterable; a value under one day is ignored. */
function wpmcp_trace_keep_days() {
    $days = (int) apply_filters('wpmcp_trace_keep_days', WPMCP_TRACE_KEEP_DAYS);

    return $days >= 1 ? $days : WPMCP_TRACE_KEEP_DAYS;
}

/** Rows of traces kept. Filterable; a value under a hundred is ignored. */
function wpmcp_trace_keep_rows() {
    $rows = (int) apply_filters('wpmcp_trace_keep_rows', WPMCP_TRACE_KEEP_ROWS);

    return $rows >= 100 ? $rows : WPMCP_TRACE_KEEP_ROWS;
}

/**
 * Drop traces past either cap. Returns how many rows went.
 *
 * CALLED FROM THE HOURLY HOOK THAT ALREADY EXISTS, `wpmcp_flush_expired`, beside the dead
 * token rows - see wpmcp_flush_expired_cb() in wp-mcp.php. The file era had no clean-up
 * hook at all and needed a rewrite inside every write to stay bounded; a table is swept
 * once an hour by two DELETEs and costs a traced failure nothing.
 *
 * THE ROW CAP IS CUT BY id AND NOT BY A SUBQUERY WITH A LIMIT. `id` is AUTO_INCREMENT, so
 * it orders the rows exactly as `logged_at` does and more finely - two traces in the same
 * second are still ordered - and `DELETE ... WHERE id <= n` needs no sort, no temporary
 * table and no `DELETE FROM t WHERE id IN (SELECT ... FROM t)`, which MySQL refuses
 * outright. MAX(id) is read from the index.
 */
function wpmcp_trace_sweep() {
    global $wpdb;

    $table = wpmcp_traces_table();

    $gone = (int) $wpdb->query($wpdb->prepare(
        'DELETE FROM ' . $table . ' WHERE logged_at < (UTC_TIMESTAMP() - INTERVAL %d DAY)',
        wpmcp_trace_keep_days()
    ));

    $newest = (int) $wpdb->get_var('SELECT MAX(id) FROM ' . $table);
    $cut    = $newest - wpmcp_trace_keep_rows();

    if ($cut > 0) {
        $gone += (int) $wpdb->query($wpdb->prepare(
            'DELETE FROM ' . $table . ' WHERE id <= %d',
            $cut
        ));
    }

    return $gone;
}

/**
 * The stack, one line per frame, with each argument reduced to its SHAPE.
 *
 * WHY NOT getTraceAsString(), WHICH THIS REPLACES (1.1.1). PHP's own formatter prints the
 * first 15 characters of every string argument and `Array` for every array - VERIFIED on the
 * PHP this project runs (8.2.29, `zend.exception_ignore_args=0`):
 * `#0 file(3): f('SECRETSECRETSEC...', Array, Object(stdClass))`. Fifteen characters is
 * plenty to be a value: the start of a `source_url`, of a post title, of an SQL statement, of
 * whatever a caller passed. The trace is private, and it is still not the place for caller
 * data - what an operator needs from a frame is which call it was and what SORT of thing it
 * was given, and that is all this writes.
 *
 * AN ARRAY'S KEYS ARE THE ONE EXCEPTION, and they are what makes a frame readable: the tool
 * closure's frame is `{closure}(array{source_url,filename,title})`, which names the arguments
 * the caller actually sent without printing one of them. Keys are bounded in number, and
 * anything outside word characters is dropped, because a key can be caller-supplied too (a
 * meta key, a taxonomy name).
 *
 * BOUNDED AT WPMCP_TRACE_STACK_FRAMES, AND THE MIDDLE IS WHAT GOES. The frames worth reading
 * are the innermost ones, where the failure is, and the outermost ones, which say how the
 * request got there; a runaway recursion is a repeat of the same few frames in between. The
 * gap says how many it dropped, so nobody reads a shortened stack as a complete one.
 *
 * The frame numbering, the `{main}` sentinel and the `[internal function]` marker are PHP's
 * own spellings, kept so that a stack a reader has seen before still reads the same.
 *
 * @return list<string>
 */
function wpmcp_trace_stack(Throwable $e) {
    $lines  = array();
    $frames = $e->getTrace();
    $total  = count($frames);
    $max    = (int) WPMCP_TRACE_STACK_FRAMES;
    $head   = (int) ($max / 2);
    $tail   = $max - $head;

    foreach ($frames as $i => $frame) {
        if ($total > $max && $i === $head) {
            $lines[] = '#' . (int) $i . ' ... ' . ($total - $max) . ' frames omitted ('
                . $total . ' in all, ' . $max . ' kept)';
        }

        if ($total > $max && $i >= $head && $i < $total - $tail) { continue; }

        $where = isset($frame['file'])
            ? $frame['file'] . '(' . (isset($frame['line']) ? (int) $frame['line'] : 0) . ')'
            : '[internal function]';

        $call = (isset($frame['class']) ? (string) $frame['class'] . (isset($frame['type']) ? (string) $frame['type'] : '::') : '')
            . (isset($frame['function']) ? (string) $frame['function'] : '{closure}');

        // `args` is ABSENT, not empty, on a host with zend.exception_ignore_args=1 (PHP's own
        // production default), so "no arguments" and "arguments withheld by php.ini" are two
        // different frames and the stack says which.
        if (!array_key_exists('args', $frame)) {
            $args = '...';
        } else {
            $shapes = array();
            foreach ((array) $frame['args'] as $arg) { $shapes[] = wpmcp_trace_arg_shape($arg); }
            $args = implode(', ', $shapes);
        }

        $lines[] = '#' . (int) $i . ' ' . $where . ': ' . $call . '(' . $args . ')';
    }

    $lines[] = '#' . $total . ' {main}';

    return $lines;
}

/**
 * One argument, as its shape and never as its value.
 *
 * A scalar becomes its type - a string also its length, which is the one number worth having
 * when a payload turns out to be a megabyte. An array becomes its KEYS, at most eight of them,
 * each stripped to word characters and 40 characters; a list's integer keys say nothing, so a
 * list is reported by its size instead. An object becomes its class name, which is code rather
 * than data.
 */
function wpmcp_trace_arg_shape($arg) {
    if (is_array($arg)) {
        if ($arg !== array() && array_keys($arg) === range(0, count($arg) - 1)) {
            return 'list(' . count($arg) . ')';
        }

        $keys = array();

        foreach (array_keys($arg) as $key) {
            if (count($keys) >= 8) { $keys[] = '...'; break; }
            $keys[] = substr(preg_replace('/[^A-Za-z0-9_-]/', '', (string) $key), 0, 40);
        }

        return 'array{' . implode(',', $keys) . '}';
    }

    if (is_object($arg))   { return get_class($arg); }
    if ($arg === null)     { return 'null'; }
    if (is_bool($arg))     { return 'bool'; }
    if (is_int($arg))      { return 'int'; }
    if (is_float($arg))    { return 'float'; }
    if (is_string($arg))   { return 'string(' . strlen($arg) . ')'; }
    if (is_resource($arg)) { return 'resource'; }

    return gettype($arg);
}

/**
 * One field: newlines flattened, bounded.
 *
 * A WP_Error's data, and a throwable's message, can both be arbitrarily long, and every one
 * of these now rides in a database backup. One event must not be able to become a megabyte
 * of it. Newlines go because the rendered entry is one line per event and every consumer
 * keys on that - see wpmcp_trace_entry().
 */
function wpmcp_trace_field($value) {
    $value = str_replace(array("\r", "\n"), ' ', (string) $value);

    if (strlen($value) > 2000) { $value = substr($value, 0, 2000) . '...'; }

    return $value;
}
