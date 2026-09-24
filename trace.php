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
 * AND EVERY FIGURE BELOW HAS A CONDITION: the sweep has to keep up. The row pass removes at
 * most WPMCP_TRACE_SWEEP_ROW_ROUNDS x WPMCP_TRACE_SWEEP_BATCH rows an hour - 100,000, or 27 a
 * second - and above that rate more traces arrive than leave, the table grows, and none of these
 * numbers bounds anything until the rate drops. That condition is stated beside the figure in
 * README.md, CHANGELOG.md and ARCHITECTURE.md, because a ceiling with an unstated condition is
 * the same defect as a mean presented as a maximum.
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
 * rows, about 1.1 MB.
 *
 * AND A ROW CAP IS NOT A SIZE CAP UNLESS THE ROW IS BOUNDED TOO. This is the correction that
 * round 2 of this sprint owes: 2,000 rows at the measured MEAN is 4.6 MB, and the mean is not
 * a ceiling. `stack` is a `longtext`, and until round 2 it was bounded only in FRAMES - so the
 * runaway recursion a frame cap exists for wrote 40-400 KB rows, and 2,000 of those is 80-800
 * MB, not 4.6 MB. Every field now has a BYTE cap as well, so the row itself has a ceiling:
 * see WPMCP_TRACE_STACK_BYTES and wpmcp_trace_record()'s per-column caps. At most 12,960
 * bytes of COLUMN DATA per row, which is 2,000 rows under 26 MB of it.
 *
 * AND COLUMN DATA IS NOT WHAT A DISK CARRIES, which is round 3's correction to round 2's.
 * MEASURED 2026-09-24 on the bare development site (MySQL 8.4.0, InnoDB `ROW_FORMAT=Dynamic`)
 * by planting 500 rows at exactly these caps, with a path-heavy stack so the escaping costs
 * what it really costs:
 *
 *   23,888 bytes of TABLESPACE per worst-case row - 1.84x the column data, because an 8 KiB
 *                  `stack` does not fit in half a 16 KB page and InnoDB puts it off-page into
 *                  a page of its own. At the row cap that is about **48 MB**.
 *   13,502 bytes per row in a `mysqldump` - only 4.2% over the column data, not the 10-15% a
 *                  first estimate assumed: the escaping is real but it is a few hundred
 *                  backslashes and newlines in 8 KiB, not a constant factor. About **27 MB**
 *                  at the cap.
 *
 * AND THE TABLESPACE IS A HIGH-WATER MARK. Also measured: after deleting those 500 rows the
 * file stayed at 12,075,008 bytes and only `OPTIMIZE TABLE` returned it to 114,688. The sweep
 * frees rows for REUSE, which is what bounds the table; it does not hand the space back to the
 * filesystem, so an operator who has once had a bug storm keeps the file size until they
 * rebuild the table. Said in the README, because it is the kind of thing found at 2 a.m.
 *
 * THE TYPICAL FIGURE IS UNCHANGED and is the one a real site meets: the measured mean entry is
 * 2,283 bytes, which is small enough to live entirely inside its page, so 486 rows in seven
 * days is still about 1.1 MB however the worst case is counted.
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
 * How many rows one DELETE removes, and how many DELETEs each of the two passes runs.
 *
 * AN UNBOUNDED DELETE IS ONE TRANSACTION, AND THE SITE THAT NEEDS THE SWEEP IS THE SITE THAT
 * CANNOT AFFORD IT (round 2). WP-Cron is driven by page loads, so a quiet or broken-cron site
 * does not sweep for a month and then sweeps everything at once: `DELETE FROM ... WHERE
 * logged_at < ...` over a hundred thousand rows is one statement holding one transaction, with
 * a `longtext` per row in the undo log, inside an ordinary front-end request. Five hundred rows
 * a statement keeps each one short, and the loop keeps the total work per sweep bounded.
 *
 * AND THE ROUND CAP IS WHAT DECIDES WHETHER THE ROW CEILING IS TRUE (round 3). A row cap that
 * removes fewer rows per hour than arrive is not a cap, it is a lag - so the two passes get
 * different round caps, because they answer different questions.
 *
 * THE ROW PASS: 200 ROUNDS, 100,000 ROWS AN HOUR. It is a PRIMARY KEY range delete
 * (`WHERE id <= <cut>`), the cheapest shape MySQL has for this - a clustered-index range scan,
 * no secondary lookup, no sort - so rounds are close to free and the sensible number is the one
 * that makes the documented ceiling hold at a rate worth defending. Hourly, 100,000 rows is
 * **27 traced failures a second sustained**. The rate to beat is an AI client in a retry loop
 * against a throwing tool: at ~350 ms a call that is ~2.8 a second, and an AI client is the
 * entire user base, so this is ten times the fastest thing we can name. ABOVE 27 a second more
 * arrive than leave and the table grows until the rate drops - that condition is stated beside
 * the 26 MB figure in README.md, CHANGELOG.md and ARCHITECTURE.md, because a ceiling with an
 * unstated condition is the same defect as a mean presented as a maximum, one layer down.
 *
 * THE AGE PASS: 20 ROUNDS, 10,000 ROWS AN HOUR, and it needs no more. It runs FIRST, and when
 * the row cap is holding there are at most 2,000 rows in the table for it to consider - so 20
 * rounds is five times the most it can ever have to do. On a site that is over the row cap the
 * ROW pass is the binding one anyway, and it runs second.
 *
 * THE LOOP EXITS ON THE FIRST SHORT BATCH, so a healthy site runs exactly ONE statement per
 * pass and the 200 is never reached. The bound is deliberately on the WORK and not on the
 * outcome: the sweep is idempotent and the hook fires again in an hour, so stopping early costs
 * nothing but a little more retention than the policy names, and the alternative is a cron
 * callback whose cost has no upper bound at all.
 */
define('WPMCP_TRACE_SWEEP_BATCH', 500);
define('WPMCP_TRACE_SWEEP_ROUNDS', 20);
define('WPMCP_TRACE_SWEEP_ROW_ROUNDS', 200);

/**
 * TWO BOUNDS ON THE STACK, AND THEY BOUND DIFFERENT THINGS. Round 1 of this sprint shipped
 * only the first and then claimed a size ceiling the second is what actually provides.
 *
 * WPMCP_TRACE_STACK_FRAMES bounds the WORK. A runaway recursion produces tens of thousands of
 * frames, and building a string for each one - with its argument shapes - costs CPU and memory
 * on a request that has already failed. Two hundred is far past the depth anybody reads.
 *
 * WPMCP_TRACE_STACK_BYTES bounds the ROW, which is the thing that rides in every database
 * backup. A frame line carries an absolute path, a class, a function and up to eight argument
 * keys of forty characters each, so a frame is not a fixed size: 200 frames of a deep
 * WordPress stack measured ~80 bytes each, and 200 frames of a pathological one reach two
 * kilobytes each. A count cap over a variable-width row is not a size cap - the arithmetic in
 * wpmcp_trace_keep_rows()'s docblock says what that cost.
 *
 * EIGHT KIBIBYTES, and the number is the measurement: the measured MEAN ENTRY is 2,283 bytes
 * in total, of which the stack is the bulk, so 8 KiB is roughly four times a normal stack and
 * keeps every frame of one intact. It is the largest of the eleven caps by an order of
 * magnitude, which is right - the stack is the field an operator actually reads.
 *
 * WHAT IS DROPPED IS THE MIDDLE, under both bounds, and for the same reason: the frames worth
 * reading are the ENDS. The innermost say where it broke; the outermost say how the request
 * got there; a recursion storm is the same few frames repeated in between. Each bound writes
 * its own line into the stack saying what it took, so a shortened stack is never mistaken for
 * a complete one - see wpmcp_trace_stack_fit().
 */
define('WPMCP_TRACE_STACK_FRAMES', 200);
define('WPMCP_TRACE_STACK_BYTES', 8192);

/**
 * The bytes reserved inside WPMCP_TRACE_STACK_BYTES for the line that says what was dropped.
 *
 * RESERVED RATHER THAN MEASURED, because the line's own length depends on the numbers it has
 * not counted yet - how many frames went and how many bytes they were. The reserve is
 * comfortably over the longest form of either sentence, and spending it means the budget is
 * never exceeded by the explanation of the budget.
 *
 * RAISED FROM 120 IN 1.1.2, because the single-oversized-frame sentence gained a third fact: it
 * now says that the REST of the stack is gone, which it did not (see wpmcp_trace_stack_fit()).
 * The longest form of it is about 130 bytes with every number at its widest, so 176 keeps the
 * same comfortable margin the original 120 had over the shorter sentence. The price is 56 fewer
 * bytes of the frame that is being cut, out of 8,192.
 */
define('WPMCP_TRACE_STACK_NOTE_BYTES', 176);

/**
 * The byte cap on every other field, keyed to the column that holds it.
 *
 * THE FIRST REASON IS THE CEILING, and it is the whole of round 2's blocker: a cap on the
 * NUMBER of rows is not a cap on their SIZE unless every field is bounded too. Round 1 cut
 * every field at 2,000 bytes - under `message` and `data`'s columns, far OVER the four
 * varchars', and absent entirely on `stack` - and then documented 2,000 rows as "about 4.6 MB",
 * which is 2,000 times the measured MEAN. With these caps the row has a maximum and the
 * arithmetic in wpmcp_trace_keep_rows() is a maximum too.
 *
 * THE SECOND REASON IS THAT MYSQL'S OWN TRUNCATION IS SILENT AND OURS IS NOT. A value wider
 * than its column is cut either way; cut here it ends in `...` and the operator can see that
 * something was removed, cut by the server it arrives looking complete. What is NOT the reason,
 * and was written as one before it was checked: a refused INSERT. WordPress's
 * `wpdb::set_sql_mode()` REMOVES `STRICT_TRANS_TABLES` from the session on every connection, so
 * on WordPress an over-wide value is a warning and not an error - MEASURED 2026-09-24 on both
 * development sites, whose `@@SESSION.sql_mode` is
 * `NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION`. On a `$wpdb` replacement
 * that does not strip it, the same value refuses the INSERT and the trace goes to error_log()
 * instead - so the cap covers that host as well, but it is not the case it exists for.
 *
 * The numbers ARE the column widths in wpmcp_install()'s third CREATE TABLE, checked against
 * them by tests/unit/TraceRowBoundTest.php so the two cannot drift. The two `longtext`/`text`
 * fields keep 2,000 - not because the column is that narrow, but because one event must not
 * become a megabyte of backup.
 */
define('WPMCP_TRACE_METHOD_BYTES', 64);
define('WPMCP_TRACE_TOOL_BYTES', 191);
define('WPMCP_TRACE_CLASS_BYTES', 191);
define('WPMCP_TRACE_AT_BYTES', 255);
define('WPMCP_TRACE_TEXT_BYTES', 2000);

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

    // EVERY FIELD CAPPED TO ITS OWN COLUMN, and the caps are what make the row's size a
    // ceiling rather than an average - see WPMCP_TRACE_METHOD_BYTES, which is also where the
    // reason NOT to state (a refused INSERT) is written down, because WordPress strips strict
    // mode from the session and round 2 nearly shipped that sentence.
    //
    // THE TOTAL IS THEREFORE 12,960 BYTES OF COLUMN DATA AT WORST: 8 + 19 + 64 + 191 + 20 +
    // 20 + 191 + 2,000 + 255 + 2,000 + 8,192. That number is quoted in the changelog, the
    // README and wpmcp_trace_keep_rows(), and it is the only honest way to state what this
    // feature costs a backup: 2,000 of them is under 26 MB.
    $row = array(
        'trace_id'  => $id,
        'logged_at' => gmdate('Y-m-d H:i:s'),
        'method'    => wpmcp_trace_field($method, WPMCP_TRACE_METHOD_BYTES),
        'tool'      => wpmcp_trace_field($tool, WPMCP_TRACE_TOOL_BYTES),
        'user_id'   => $session ? (int) $session->user_id : 0,
        'token_id'  => $session ? (int) $session->id : 0,
        'class'     => wpmcp_trace_field($class, WPMCP_TRACE_CLASS_BYTES),
        'message'   => wpmcp_trace_field($e->getMessage(), WPMCP_TRACE_TEXT_BYTES),
        'at'        => wpmcp_trace_field($e->getFile() . ':' . $e->getLine(), WPMCP_TRACE_AT_BYTES),
        'data'      => $data === '' ? '' : wpmcp_trace_field($data, WPMCP_TRACE_TEXT_BYTES),
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

    $gone = wpmcp_trace_sweep_batched(
        $wpdb->prepare(
            'DELETE FROM ' . $table . ' WHERE logged_at < (UTC_TIMESTAMP() - INTERVAL %d DAY)'
            . ' LIMIT %d',
            wpmcp_trace_keep_days(),
            (int) WPMCP_TRACE_SWEEP_BATCH
        ),
        (int) WPMCP_TRACE_SWEEP_ROUNDS
    );

    $newest = (int) $wpdb->get_var('SELECT MAX(id) FROM ' . $table);
    $cut    = $newest - wpmcp_trace_keep_rows();

    if ($cut > 0) {
        // The PK-range pass, and the one the ceiling depends on - see
        // WPMCP_TRACE_SWEEP_ROW_ROUNDS for the rate it holds to and why it gets ten times the
        // age pass's rounds.
        $gone += wpmcp_trace_sweep_batched(
            $wpdb->prepare(
                'DELETE FROM ' . $table . ' WHERE id <= %d LIMIT %d',
                $cut,
                (int) WPMCP_TRACE_SWEEP_BATCH
            ),
            (int) WPMCP_TRACE_SWEEP_ROW_ROUNDS
        );
    }

    return $gone;
}

/**
 * Run one bounded DELETE until it stops removing a full batch, or until the round cap.
 *
 * The statement is prepared by the caller and already carries its own `LIMIT`, and the caller
 * also says how many rounds it gets - the two passes differ by a factor of ten, because only one
 * of them decides whether the documented row ceiling is true. See WPMCP_TRACE_SWEEP_BATCH.
 *
 * A round that removes FEWER than the batch has reached the end of what matches, so the loop
 * stops rather than running one more statement to be told nothing is left. A `false` return -
 * the table is missing, the query failed - stops it too: retrying a broken statement twenty
 * times inside a front-end request is the cost this function exists to avoid.
 */
function wpmcp_trace_sweep_batched($sql, $rounds) {
    global $wpdb;

    $batch = (int) WPMCP_TRACE_SWEEP_BATCH;
    $gone  = 0;

    for ($round = 0; $round < (int) $rounds; $round++) {
        $removed = $wpdb->query($sql);

        if ($removed === false) { break; }

        $gone += (int) $removed;

        if ((int) $removed < $batch) { break; }
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

    return wpmcp_trace_stack_fit($lines);
}

/**
 * Hold the built stack under WPMCP_TRACE_STACK_BYTES, dropping frames from the MIDDLE.
 *
 * THE SECOND OF THE TWO BOUNDS, AND THE ONE THAT MAKES THE ROW A CEILING. The frame cap above
 * bounds how many lines get built; this bounds how many BYTES are stored, which is what a
 * database backup carries. Without it a count cap over variable-width lines is not a size cap
 * at all, and the size this sprint claimed in its own changelog was a mean rather than a
 * maximum.
 *
 * THE ENDS SURVIVE AND THE MIDDLE GOES, exactly as the frame cap cuts, and frames are taken
 * ALTERNATELY - innermost, outermost, next innermost, next outermost - so a budget that only
 * fits three lines spends them on the two most useful frames rather than on three consecutive
 * ones. `{main}` is the last line and is therefore among the first kept: it is how a reader
 * knows the stack reached the bottom rather than being cut off there.
 *
 * AND IT SAYS WHAT IT TOOK, in a line that carries no `trace=` field for the reason the file's
 * truncation marker carried none: a marker that reads like an event could come back from a grep
 * by id. The line names the frames dropped, the bytes they were and the cap, so a stack that
 * is shorter than a reader expects is never mistaken for a complete one.
 *
 * ONE FRAME CAN BE BIGGER THAN THE WHOLE BUDGET - a single call with eight long argument keys
 * under a deep path. Then there is nothing to keep whole, so that line is cut and says so.
 * Answering with an empty stack would be worse: the file:line is in another column, but the
 * frame is the only thing that says which call it was.
 *
 * @param list<string> $lines the frame lines, innermost first, `{main}` last.
 * @return list<string>
 */
function wpmcp_trace_stack_fit(array $lines) {
    if ($lines === array()) { return $lines; }

    $budget = (int) WPMCP_TRACE_STACK_BYTES;

    // EXACTLY WHAT implode("\n", $lines) WILL BE, which is one byte less than a `strlen + 1` per
    // line: there are count-1 separators, not count. Round 2 measured the wrong thing by one byte,
    // which is harmless for the cap and not harmless at the boundary, where being one byte out is
    // the difference between returning a stack untouched and rewriting it to say nothing was
    // dropped.
    $bytes = count($lines) - 1;

    foreach ($lines as $line) { $bytes += strlen($line); }

    if ($bytes <= $budget) { return $lines; }

    $room = $budget - (int) WPMCP_TRACE_STACK_NOTE_BYTES;
    $head = array();
    $tail = array();
    $used = 0;
    $i    = 0;
    $j    = count($lines) - 1;

    while ($i <= $j) {
        $len = strlen($lines[$i]) + 1;

        if ($used + $len > $room) { break; }

        $head[] = $lines[$i];
        $used  += $len;
        $i++;

        if ($i > $j) { break; }

        $len = strlen($lines[$j]) + 1;

        if ($used + $len > $room) { break; }

        array_unshift($tail, $lines[$j]);
        $used += $len;
        $j--;
    }

    if ($head === array() && $tail === array()) {
        // The innermost frame alone is over budget. Keep as much of it as fits and say so - with
        // the number of bytes KEPT, which is the cap less the reserved note and not the cap
        // itself. Round 2's wording said 8192 while it cut at 8,072, which is a number an
        // operator can check and find wrong.
        //
        // AND SAY THAT EVERY OTHER FRAME IS GONE, which until 1.1.2 it did not. This branch
        // returns a ONE-LINE array: `{main}` and every frame between it and the innermost one are
        // dropped, and the old marker mentioned only the bytes cut off the frame it kept. An
        // operator reading `#0 ...[frame cut, 8072 of 20003 bytes kept]` has every reason to read
        // it as "one long frame, nothing else to see" - the marker said the stack was trimmed
        // where it was actually reduced to a fragment of its innermost call. The other branch
        // below has always counted what it dropped; this one now does too.
        $others = count($lines) - 1;

        return array(
            substr($lines[0], 0, $room)
            . ' ...[frame cut, ' . $room . ' of ' . strlen($lines[0]) . ' bytes kept'
            . ($others > 0
                ? '; ' . $others . ' further frame' . ($others === 1 ? '' : 's')
                    . ' dropped, {main} included'
                : '')
            . ', under the ' . $budget . '-byte stack cap]'
        );
    }

    $dropped = $j - $i + 1;

    // NOTHING WAS DROPPED, SO NOTHING IS SAID. A marker reading "0 frames omitted" is worse than
    // no marker: it tells an operator the stack is incomplete when it is whole. This is a guard
    // rather than a fix for a reachable case - every line fitting inside `$room` implies the
    // whole stack was under `$budget`, which is the branch that already returned above - but the
    // arithmetic that makes it unreachable is three variables wide, and a wrong sentence on an
    // admin screen is not the place to rely on that.
    if ($dropped <= 0) { return $lines; }

    $lost = $dropped - 1;

    for ($k = $i; $k <= $j; $k++) { $lost += strlen($lines[$k]); }

    return array_merge(
        $head,
        // "dropped", not "over": the number names what was REMOVED, not by how much the cap was
        // exceeded, and those are different numbers whenever the ends do not fill the budget.
        array('#.. ' . $dropped . ' frames omitted, ' . $lost . ' bytes dropped to hold the '
            . $budget . '-byte stack cap'),
        $tail
    );
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
 * One field: newlines flattened, cut to $max BYTES.
 *
 * A WP_Error's data, and a throwable's message, can both be arbitrarily long, and every one
 * of these now rides in a database backup. One event must not be able to become a megabyte
 * of it. Newlines go because the rendered entry is one line per event and every consumer
 * keys on that - see wpmcp_trace_entry().
 *
 * $max IS THE COLUMN'S OWN WIDTH, AND IT IS REQUIRED RATHER THAN DEFAULTED (round 2). One
 * shared cap of 2,000 was over four of the six columns and absent on the seventh, which is what
 * made the documented row ceiling an average rather than a maximum. A value cut HERE says it was
 * cut; the same value cut by MySQL arrives looking complete. See WPMCP_TRACE_METHOD_BYTES.
 *
 * THE CUT IS IN BYTES, and the `...` goes INSIDE the budget rather than past it, because the
 * point of the number is that the column can hold the result. A byte cut can land mid-sequence
 * in UTF-8; that is why the three dots are ASCII and why the value is not re-validated as
 * text - it is an opaque diagnostic string, not something a client parses.
 */
function wpmcp_trace_field($value, $max) {
    $value = str_replace(array("\r", "\n"), ' ', (string) $value);
    $max   = (int) $max;

    if ($max > 3 && strlen($value) > $max) {
        $value = substr($value, 0, $max - 3) . '...';
    }

    return $value;
}
