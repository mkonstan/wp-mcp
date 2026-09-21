<?php
/**
 * Copyright (C) 2026 Max Konstantinovski. GPLv2 or later (see LICENSE).
 *
 * WP MCP - DIY MCP-over-HTTP endpoint.
 *
 * Route:
 *   POST /wp-json/wpmcp/mcp           - ONE URL, and the credential is the
 *   `Authorization: Bearer <64 lower-case hex>` header and nothing else. A URL that
 *   carries a token is written into every access log, proxy log and browser history it
 *   passes through, and a hosted connector re-uses it for months; so there is no such
 *   URL any more and a path with a token in it is a plain REST 404.
 * Speaks minimal MCP JSON-RPC 2.0: initialize, notifications/initialized,
 * tools/list, tools/call, ping. Single JSON response per request (no SSE).
 * Handshake: `initialize` echoes a supported protocolVersion and otherwise answers with
 *   the newest one - never an error. Capabilities are `tools` and nothing else, because
 *   tools are all this server serves. STATELESS: no Mcp-Session-Id is ever issued, and
 *   one sent by a client is ignored. See wpmcp_protocol_version_gate().
 * Transport: HTTPS required, Origin checked against the site's own, POST must be
 *   application/json. All three before the token is read - see wpmcp_authorize(),
 *   whose docblock states the full order of the gates.
 * Auth: the token is validated per request (shape, lookup, user, expiry). It is NOT
 *   bound to a client address: an Anthropic-hosted connector calls from a pool of
 *   egress addresses, so there is no single address to hold it to.
 *   Every failure is ONE byte-identical 401; the reason is in the auth event.
 * Identity: the request runs as the WordPress user the token was minted for.
 * Scope: 'read' tokens are refused any tool flagged write=true.
 * Registry: a tool without an explicit boolean `write`, a string description, an array
 *   inputSchema and four boolean annotations is not registered; a built-in's name
 *   cannot be re-declared.
 * Input: every tools/call is validated against the tool's inputSchema BEFORE the tool
 *   runs - see WpMcp\SchemaValidator and wpmcp_dispatch(). Unknown argument keys are
 *   refused: `additionalProperties: false` is the default.
 * Serialization: every schema leaving this endpoint goes through
 *   wpmcp_objectify_schema(), because an empty PHP array encodes as `[]` and JSON
 *   Schema wants `{}` in those positions.
 * Errors: FIVE JSON-RPC codes and no others - see wpmcp_handle(). Anything unexpected
 *   is one generic -32603 carrying a trace id; the throwable goes to trace.php's log.
 */
if (!defined('ABSPATH')) { exit; }

use WpMcp\ProtocolVersion;
use WpMcp\SchemaValidator;

/* Holds the validated token row between permission_callback and the handler. */
$GLOBALS['wpmcp_session'] = null;

/**
 * Largest request body this endpoint will do any work on: 4 MiB.
 *
 * Generous for JSON-RPC - the biggest legitimate body is a code-write, itself capped at
 * 512 KB of content - and small enough that an oversized POST is refused before a token
 * is looked up. See the cap in wpmcp_authorize_now().
 */
define('WPMCP_MAX_BODY', 4 * 1024 * 1024);

add_action('rest_api_init', function () {
    $route = array(
        'methods'             => 'POST',
        'callback'            => 'wpmcp_handle',
        'permission_callback' => 'wpmcp_authorize',
    );
    // ONE route, at a constant URL. The credential is the Authorization header and
    // nothing else - see wpmcp_extract_token(). A route whose path carried the token
    // used to be registered here alongside it; it is gone, because the path form put the
    // credential into every log on the way and a hosted connector cannot be handed a new
    // URL without being deleted and re-added.
    register_rest_route('wpmcp', '/mcp', $route);
});

/* ---------------- the verb gate ---------------- */

/** Is this REST route ours? There is exactly one. */
function wpmcp_is_our_route($route) {
    return (string) $route === '/wpmcp/mcp';
}

/**
 * POST or OPTIONS, and nothing else - decided BEFORE any route handler, and therefore
 * before permission_callback and before the token is read.
 *
 * WHY A rest_pre_dispatch FILTER RATHER THAN MORE REGISTERED METHODS. Registering GET
 * and DELETE with a callback that answers 405 would work, but `rest_send_allow_header`
 * (wp-includes/rest-api.php) then rebuilds the `Allow` header from every registered
 * handler whose permission_callback passes - so the refusal would advertise
 * `Allow: GET, DELETE`, the exact opposite of the truth. A pre-dispatch response has no
 * matched route, so that filter leaves it alone and the header we set is the one sent.
 *
 * OPTIONS IS 204 WITH NO BODY, which core does not do on its own:
 * `rest_handle_options_request` (rest_pre_dispatch, priority 10) answers 200 with the
 * route's whole `help` schema. That is a description of the endpoint handed to anybody who
 * asks, with no credential - small, but it is disclosure, and a CORS preflight has no use
 * for it. This replaces it with an empty 204.
 *
 * GET and DELETE are 405 with `Allow: POST, OPTIONS` and a fixed body. A GET is the shape
 * a browser address bar, a link prefetcher or a crawler produces, and a 404 invites a
 * retry; 405 names the one verb that works.
 *
 * PHP_INT_MAX, AND THAT IS NOT PARANOIA - IT IS MEASURED, TWICE. The first version of this
 * ran at priority 9, before core's OPTIONS handler, and the 405 never reached the client:
 * the site under test has ACF Pro, whose `ACF_Rest_Api::initialize()` is hooked on
 * rest_pre_dispatch at priority 10 and RETURNS NOTHING, and Gravity Forms does the same at
 * priority 99 (`class-gf-rest-authentication.php`). A filter callback that does not return
 * its input replaces the accumulated value with null, so every earlier callback on that
 * hook is silently discarded - our 405 among them, and the request fell through to core's
 * 404 rest_no_route. Two ordinary plugins on one ordinary site, neither of them doing
 * anything exotic.
 *
 * PHP_INT_MAX is THE LAST POSITION ANY PLUGIN GETS, and a theme can still take it: a
 * `functions.php` loads after every plugin and can register at PHP_INT_MAX too, after this.
 * Nothing dangerous comes back if one does - GET falls through to core's 404 and OPTIONS to
 * a 404 as well, since no handler matches the method, so the `help` schema does not
 * reappear - but the gate is best-effort against site code by construction, not absolute.
 * It is also the position that lets this replace core's OPTIONS response.
 *
 * The incoming $result is still honoured for POST: a plugin that legitimately hijacks an
 * MCP request keeps its answer. It is only overridden for a verb this endpoint refuses.
 */
add_filter('rest_pre_dispatch', 'wpmcp_gate_request_method', PHP_INT_MAX, 3);
function wpmcp_gate_request_method($result, $server, $request) {
    if (!wpmcp_is_our_route($request->get_route())) { return $result; }

    $method = strtoupper((string) $request->get_method());

    if ($method === 'POST') { return $result; }

    if ($method === 'OPTIONS') { return new WP_REST_Response(null, 204); }

    $response = new WP_REST_Response(
        array('error' => 'Method not allowed. This endpoint speaks POST.'),
        405
    );
    $response->header('Allow', 'POST, OPTIONS');

    return $response;
}

/**
 * The token from `Authorization: Bearer <token>`, and from nowhere else. Empty string
 * when there is none, which is the dormant 401 with reason=missing.
 *
 * THE APACHE CGI CASE IS CORE'S, NOT OURS - and this function briefly carried a copy of
 * core's answer to it, which was dead code. PHP run as CGI or FastCGI never receives the
 * `Authorization` header (Apache consumes it for its own auth machinery and does not
 * export it), so $_SERVER['HTTP_AUTHORIZATION'] is absent; WordPress's own .htaccess
 * block re-exports the value as REDIRECT_HTTP_AUTHORIZATION, and
 * `WP_REST_Server::get_headers()` maps that back onto AUTHORIZATION when, and only when,
 * HTTP_AUTHORIZATION is empty (wp-includes/rest-api/class-wp-rest-server.php - verified
 * on WP 7.0 and 7.1). So `get_header('authorization')` is already correct on such a host
 * and a second read here could never fire.
 *
 * That matters for where somebody debugs: if a real Apache box answers reason=missing,
 * the thing to check is the .htaccess block, not this function.
 *
 * The scheme is matched case-insensitively - RFC 7235 says it is - and only `Bearer`.
 * A `Basic <base64>` with its first seven characters cut off is not a credential and
 * has no business reaching a database lookup.
 */
function wpmcp_extract_token(WP_REST_Request $req) {
    $auth = (string) $req->get_header('authorization');

    if ($auth !== '' && stripos($auth, 'bearer ') === 0) {
        return trim(substr($auth, 7));
    }

    return '';
}

/* ---------------- transport gates ---------------- */

/**
 * Is this request allowed to carry a credential at all?
 *
 * THIS GATE IS ONLY AS STRONG AS THE PROXY IN FRONT OF WORDPRESS. Say that plainly,
 * because the first version of this docblock did not and was wrong.
 *
 * The function reads `is_ssl()` and nothing else. `is_ssl()` reads $_SERVER - HTTPS,
 * SERVER_PORT (wp-includes/load.php) - which PHP got from the web server, which on a
 * proxied deployment got it from a header. WordPress has no way to know whether that
 * header came from the proxy or from the client. So:
 *
 *   - A reverse proxy that SETS `X-Forwarded-Proto` itself, overwriting whatever the
 *     client sent, makes this gate real.
 *   - A reverse proxy that FORWARDS the client's `X-Forwarded-Proto` unchanged makes it
 *     advisory: a client posts over plain HTTP with `X-Forwarded-Proto: https` and the
 *     request is accepted, token in cleartext.
 *
 * MEASURED on the site this was developed against (Local by Flywheel), and it is the
 * second kind. Local's router maps the client's header straight through
 * (`…/Local/run/router/nginx/conf/nginx.conf`
 * `map $http_x_forwarded_proto $protocol { default $scheme; https https; }` then
 * `proxy_set_header X-Forwarded-Proto $protocol`), the site's own nginx maps that into
 * the FastCGI `HTTPS` parameter, and is_ssl() answers true. Verified three ways: https
 * -> 403 never happens (correct), plain http -> 403 (correct), plain http plus
 * `X-Forwarded-Proto: https` -> 200 with tools/list served (NOT correct, and not
 * fixable here). tests/integration/InfraTrustTest.php pins that case; it is red on
 * Local on purpose and is excluded from the default suite for that reason.
 *
 * THE OPERATOR ACTION, which is the only thing that closes it: your reverse proxy must
 * set `X-Forwarded-Proto` from its own view of the connection and must never pass the
 * client's value through. nginx: `proxy_set_header X-Forwarded-Proto $scheme;`.
 *
 * AND THIS FUNCTION STILL DOES NOT READ HTTP_X_FORWARDED_PROTO ITSELF. Reading it would
 * not improve anything - it is the same untrusted header - and would remove the one
 * place an operator can fix this, which is the proxy. A deployment where is_ssl() cannot
 * see the truth must teach WordPress (the documented $_SERVER['HTTPS'] assignment in
 * wp-config.php, behind whatever proxy check that site trusts), which is where
 * home_url() and every cookie already get their answer.
 *
 * WPMCP_ALLOW_INSECURE === true, and only exactly true, is the escape hatch for a
 * local development site with no certificate. It is a constant rather than an option
 * so that turning it on requires filesystem access, not a compromised admin session.
 */
function wpmcp_request_is_secure() {
    if (defined('WPMCP_ALLOW_INSECURE') && WPMCP_ALLOW_INSECURE === true) { return true; }
    return is_ssl();
}

/**
 * scheme://host[:port] of a URL, lower-cased, with the default port dropped - or ''
 * when the input is not a usable absolute URL.
 *
 * '' is what makes `Origin: null` (a sandboxed iframe, a file:// document, some
 * redirects) fail closed rather than match something.
 */
function wpmcp_origin_of($url) {
    $p = wp_parse_url((string) $url);
    if (!is_array($p) || empty($p['scheme']) || empty($p['host'])) { return ''; }

    $scheme  = strtolower((string) $p['scheme']);
    $host    = strtolower((string) $p['host']);
    $port    = isset($p['port']) ? (int) $p['port'] : 0;
    $default = ($scheme === 'https') ? 443 : (($scheme === 'http') ? 80 : 0);

    return $scheme . '://' . $host . (($port && $port !== $default) ? ':' . $port : '');
}

/**
 * Origins a browser may send from. The site's own, plus whatever a site adds.
 *
 * home_url(), site_url() and admin_url() rather than a hand-built string, because a
 * site can serve wp-admin from a different host than the front end, and because
 * WordPress already knows the answer.
 *
 * WORTH KNOWING: get_home_url() returns the option's URL with its scheme REPLACED by
 * https whenever is_ssl() is true (wp-includes/link-template.php). On the site under
 * test the `home` option is `http://example.local` and home_url() still answers
 * `https://example.local` over TLS - verified. Since wpmcp_request_is_secure() runs
 * before this, the allowlist is therefore built from https origins on any request that
 * gets this far, which is exactly what a browser will have sent.
 */
function wpmcp_allowed_origins() {
    $allowed = array();

    foreach (array(home_url(), site_url(), admin_url()) as $url) {
        $origin = wpmcp_origin_of($url);
        if ($origin !== '') { $allowed[$origin] = true; }
    }

    /**
     * Additional origins a browser may post to the MCP endpoint from.
     *
     * @param array $origins list of absolute URLs or scheme://host[:port] strings.
     */
    foreach ((array) apply_filters('wpmcp_allowed_origins', array()) as $extra) {
        $origin = wpmcp_origin_of($extra);
        if ($origin !== '') { $allowed[$origin] = true; }
    }

    return array_keys($allowed);
}

/** Exact scheme+host+port match against the allowlist. */
function wpmcp_origin_allowed($origin) {
    $origin = wpmcp_origin_of($origin);
    if ($origin === '') { return false; }
    return in_array($origin, wpmcp_allowed_origins(), true);
}

/**
 * Is the body declared as JSON? Parameters are allowed, so
 * `application/json; charset=utf-8` passes and `text/plain` does not.
 *
 * Strict on the type itself: no `+json` suffixes, no `application/json-rpc`. A client
 * that cannot set one header correctly is not a client this endpoint should be
 * guessing for.
 */
function wpmcp_content_type_is_json(WP_REST_Request $req) {
    $header = (string) $req->get_header('content-type');
    if ($header === '') { return false; }

    $type = strtolower(trim(explode(';', $header)[0]));
    return $type === 'application/json';
}

/**
 * THE one refusal for every token failure: same status, same code, same message, same
 * bytes. See wpmcp_validate() for why the reason lives only in the auth event.
 */
function wpmcp_unauthorized() {
    return new WP_Error('wpmcp_unauthorized', 'Unauthorized.', array('status' => 401));
}

/**
 * Validate the token against this request. Stash the row on success.
 *
 * THE ORDER OF THE GATES - this is the whole security contract of the endpoint:
 *
 *   1. HTTPS            is_ssl(), unless WPMCP_ALLOW_INSECURE === true -> 403
 *   2. Origin           present and not ours -> 403; absent -> allowed (non-browser)
 *   3. Content-Type     not application/json -> 415
 *   3b. body size       CONTENT_LENGTH > WPMCP_MAX_BODY                -> 413
 *   4. token shape      64 lower-case hex, from Authorization: Bearer -> 401
 *   5. token lookup     by SHA-256 hash                            -> 401
 *   6. user exists      get_userdata(user_id)                      -> 401
 *   7. lifetime timers  window closed -> 401 (dormant); past the hard
 *                       end -> 401 (dead). Same 401 either way.     -> 401
 *   8. scope            enforced at dispatch, in wpmcp_handle()
 *   9. protocol version enforced at dispatch, in wpmcp_dispatch()
 *
 * THERE IS NO ADDRESS GATE, and its absence is a decision rather than an omission. A
 * token used to lock to the address of its first tool call and be refused from anywhere
 * else. Measured on a public test site on 2026-09-13, an Anthropic-hosted connector
 * (claude.ai, Claude Desktop) calls from a POOL - 160.79.106.164, .185, .186 and .187
 * inside one minute - so that lock held the token to whichever came first and refused
 * the rest of the session. There is no single address to hold a token to, so none is
 * used; the caller's address is still recorded on every auth event, where it informs an
 * operator without deciding anything.
 *
 * WHY THIS ORDER. 1-3 are properties of the envelope and cost nothing, so they run
 * before the credential is even read: a request refused for being plaintext must not
 * first have its token looked up in the database, or the refusal becomes a token
 * oracle with a timing side channel. 4-7 narrow from "is this string even a token" to
 * "is it this token, and is it still alive", cheapest first and each one a precondition
 * of the next. 6 precedes 7 so a dead token's row is not even touched.
 *
 * 3b IS A CAP ON WORK, NOT ON MEMORY, and the difference matters. By the time any of
 * this runs, WordPress core has already read the whole body and json_decode'd it - see
 * the paragraph below - so the bytes are in memory whatever this gate says. What it does
 * stop is the plugin spending anything on an oversized body: no token lookup, no
 * database write, no tool dispatch, no second decode. A host that wants the bytes refused
 * before PHP sees them sets client_max_body_size / post_max_size; that is the same
 * division of labour as the HTTPS gate. CONTENT_LENGTH is the client's claim rather than
 * a measurement, which is fine in this direction: a client that understates it gets its
 * body truncated by the server instead.
 *
 * THIS PLUGIN READS NO PART OF THE REQUEST BODY AT ALL in this function - but WordPress
 * does, and an earlier version of this comment claimed otherwise. `WP_REST_Server::dispatch()` calls
 * `$request->has_valid_params()`, which calls `parse_json_params()` for any
 * application/json body, and only then does `respond_to_request()` reach the
 * permission_callback. So a malformed body is answered by core with 400
 * `rest_invalid_json` before gate 1 runs at all: no auth event fires for it, and the
 * JSON is decoded before the caller is authenticated (its size is Sprint 3's business).
 * That refusal leaks nothing about the token - core never looks at one - but it is not
 * this function's refusal and it is not in this order.
 *
 * The plugin's own read of the body is gone from this function entirely. It existed for
 * one reason - the address pin needed to know whether the request was a tools/call, so a
 * helper json_decode'd the body on every request, valid token or not - and the pin is
 * gone with it.
 *
 * 8 AND 9 ARE THE GATES NOT IN THIS FUNCTION, and deliberately - both need the parsed
 * body, so neither can run here without reading it, which is the thing this ordering was
 * rearranged to avoid.
 *
 * 8, scope: the decision needs the tool name, which only exists once the body is parsed,
 * and its refusal is an MCP tool error (200 with isError) rather than an HTTP status,
 * because a read token calling a write tool is a correctly authenticated request asking
 * for something it may not have. It fires the scope_deny event from wpmcp_handle().
 *
 * 9, MCP-Protocol-Version: the gate needs to know whether the method is `initialize`,
 * which is exempt, and its refusal is a JSON-RPC -32600 body on HTTP 400 - a shape this
 * function cannot produce at all, since a permission_callback's WP_Error becomes WP's own
 * `{"code","message","data"}` REST error and never a JSON-RPC envelope. That is what
 * decided the placement rather than taste. See wpmcp_protocol_version_gate().
 *
 * Returns true, or a WP_Error - which also makes the endpoint effectively dormant when
 * no valid token exists.
 *
 * DECIDED ONCE PER REQUEST, and it has to be, because WordPress calls a
 * permission_callback TWICE. `rest_send_allow_header()`, hooked on `rest_post_dispatch`
 * by rest_api_default_filters (wp-includes/rest-api.php:253, :883-900 on the site under
 * test), calls every matched handler's permission_callback again purely to work out the
 * `Allow` header - after the response has been produced. MEASURED, not assumed: the
 * first version of the sprint-2 tests found every auth event firing exactly twice, and
 * that is where the second one came from.
 *
 * Without the memo that second call would re-run wpmcp_validate(), which means the
 * use_count of every token would advance by two per request and last_used_at would be
 * written twice - a pre-existing inaccuracy in the admin table, not something this
 * sprint introduced - and every auth event would be a duplicate.
 *
 * A WeakMap KEYED ON THE REQUEST OBJECT, not spl_object_id(). PHP reuses object ids as
 * soon as an object is freed - `$a = new stdClass; unset($a); $b = new stdClass;` gives
 * $b the id $a had - so an id-keyed memo is only safe while exactly one request object
 * is alive, which is true under PHP-FPM and not true under a worker SAPI (FrankenPHP
 * worker mode, Swoole, RoadRunner all run WordPress) or an in-process sequence of
 * rest_do_request() calls. There a freed request's id could be reissued to a new
 * request, which would then inherit the previous decision - including `true`, with
 * $GLOBALS['wpmcp_session'] still holding the previous token's row. A WeakMap holds the
 * object itself, drops the entry when the object is collected, and cannot confuse two
 * requests. PHP 8.0+; this plugin requires 8.1.
 */
function wpmcp_authorize(WP_REST_Request $req) {
    static $decided = null;

    if ($decided === null) { $decided = new WeakMap(); }
    if (isset($decided[$req])) { return $decided[$req]; }

    $decision      = wpmcp_authorize_now($req);
    $decided[$req] = $decision;

    return $decision;
}

/** The gates themselves. Call wpmcp_authorize(); this is the uncached body. */
function wpmcp_authorize_now(WP_REST_Request $req) {
    $ip = wpmcp_client_ip();

    // 1. HTTPS. Before the token is read, so nothing about it leaks - not even the
    // time it takes to look one up.
    if (!wpmcp_request_is_secure()) {
        wpmcp_auth_event('insecure_deny', array('ip' => $ip));
        return new WP_Error('wpmcp_https_required', 'HTTPS required.', array('status' => 403));
    }

    // 2. Origin. Absent means a non-browser client - curl, an MCP server, a CLI - and
    // is allowed: the header is a browser's honest statement about who opened the
    // page, and its absence is not a claim at all. Present and foreign is a browser
    // being driven by somebody else's page, which is the CSRF this closes.
    $origin = (string) $req->get_header('origin');
    if ($origin !== '' && !wpmcp_origin_allowed($origin)) {
        wpmcp_auth_event('origin_deny', array('origin' => $origin, 'ip' => $ip));
        return new WP_Error('wpmcp_forbidden_origin', 'Forbidden.', array('status' => 403));
    }

    // 3. Content-Type. A form-encoded or text/plain POST is what a cross-origin
    // <form> can send without a preflight, so requiring JSON is what makes the
    // browser ask permission first.
    if (!wpmcp_content_type_is_json($req)) {
        wpmcp_auth_event('content_type_deny', array(
            'content_type' => (string) $req->get_header('content-type'),
            'ip'           => $ip,
        ));
        return new WP_Error(
            'wpmcp_unsupported_media_type',
            'Content-Type must be application/json.',
            array('status' => 415)
        );
    }

    // 3b. Body size. Before the token lookup, so an oversized POST costs one integer
    // comparison rather than a database round trip.
    $length = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
    if ($length > WPMCP_MAX_BODY) {
        wpmcp_auth_event('body_too_large', array('length' => $length, 'ip' => $ip));
        return new WP_Error(
            'wpmcp_payload_too_large',
            'Request body too large.',
            array('status' => 413)
        );
    }

    // 4-7.
    $row = wpmcp_validate(wpmcp_extract_token($req), $ip);
    if (is_wp_error($row)) {
        // The reason is already in the validate_fail event. One answer on the wire.
        return wpmcp_unauthorized();
    }

    // Identity: run as the user the token was minted for, so every capability check
    // inside the tools is that user's. scope still gates write tools on top.
    $GLOBALS['wpmcp_session'] = $row;
    wp_set_current_user((int) $row->user_id);
    return true;
}

/* ---------------- tool registry ---------------- */

/**
 * The tool registry, assembled and then CHECKED.
 *
 * `wpmcp_tools` is a public filter, so a third-party plugin can add a tool. Everything
 * downstream of here asks one question about every entry - `empty($t['write'])` - to
 * decide whether a read-scope token may call it. An entry with no `write` key answers
 * that question "no, this is a read tool", which means a filter that forgets the key,
 * or misspells it, or sets it to the string "true" (truthy, but not a boolean, and
 * therefore an author who did not think about it), silently publishes a write tool to
 * every read-scope token on the site. Absence of a declaration is not a declaration of
 * safety.
 *
 * So an entry without an explicit boolean `write` is NOT REGISTERED: it does not appear
 * in tools/list, it cannot be called, and a registry_reject event says which name was
 * dropped and why. The author of that tool finds out from the log rather than from an
 * incident.
 *
 * Three more shapes are refused for smaller but concrete reasons:
 *
 *   run_not_callable      call_user_func on a non-callable is a TypeError. The boundary
 *                         in wpmcp_handle() now turns that into a generic -32603 rather
 *                         than a 500 carrying filesystem paths, but a tool that cannot
 *                         run is still a registration bug and the author should hear
 *                         about it once, at registration, rather than per call.
 *   description_not_string / schema_not_array
 *                         tools/list reads $t['description'] and $t['inputSchema']
 *                         directly, so a missing one is a PHP warning plus a null on
 *                         the wire - a malformed MCP listing for every client, caused
 *                         by one third-party entry.
 *   annotations_incomplete
 *                         no `annotations`, or one of the four hints missing, or one of
 *                         them not a boolean. The hints are what a client uses to
 *                         decide whether to ask the human first: a destructive tool
 *                         with no `destructiveHint` is presented as safe, and MCP's own
 *                         default for an ABSENT annotations block is
 *                         `readOnlyHint: false, destructiveHint: true` - which a client
 *                         that reads only what is present will not apply. Same rule as
 *                         `write`: absence of a declaration is not a declaration of
 *                         safety. See wpmcp_annotation_hints().
 *   description_too_long  the tool's `description`, or any
 *                         `inputSchema.properties.*.description`, longer than
 *                         WPMCP_MAX_DESCRIPTION characters. Clients cap these, and the
 *                         cap is applied by TRUNCATING: a 4 KB description reaches the
 *                         model as the first 1000 characters of itself, so the sentence
 *                         that said "force: true deletes permanently" is simply gone and
 *                         nothing says so. Refusing the tool is the loud version of a
 *                         failure that is otherwise silent and model-side. Measured on
 *                         characters rather than bytes, which is what a client counts.
 *   name_reserved         a filter entry using a BUILT-IN tool's name. The built-in
 *                         wins and the filter entry is dropped. A same-name entry is
 *                         how the fail-closed rule gets walked around one level up:
 *                         `delete-post` re-declared with `write => false` is a write
 *                         tool published to every read-scope token, and it passes every
 *                         check above because it declares a boolean. Site code may well
 *                         mean to extend a tool, but it cannot do it by overwriting the
 *                         entry whose `write` flag is the gate.
 *
 * The built-in tools all carry `'write' => true|false` explicitly - 25 of them, one
 * per entry - so the checks apply uniformly rather than trusting "ours" over "theirs".
 * If a future built-in forgets the key it disappears from the listing and the log says
 * so, which is the loud failure.
 */
function wpmcp_tools() {
    $tools = array();
    foreach (array('wpmcp_core_tools', 'wpmcp_content_tools', 'wpmcp_revision_tools', 'wpmcp_taxonomy_tools', 'wpmcp_media_tools', 'wpmcp_comment_tools', 'wpmcp_menu_tools', 'wpmcp_inventory_tools') as $fn) {
        if (function_exists($fn)) { $tools = array_merge($tools, $fn()); }
    }
    // Code tools are exposed only when the switch in Settings > WP MCP is on AND the site
    // does not forbid file editing outright. BOTH, because a tool that can never run must
    // not be listed: on a site with the switch on and DISALLOW_FILE_EDIT true in
    // wp-config, tools/list advertised all six and every one of them then refused.
    // Measured on a real public site. wpmcp_code_constants_forbid() is the same check the
    // run closures make through wpmcp_code_forbidden(), which is why it is a function and
    // not two more `defined()` calls here - two copies of a gate drift.
    if (function_exists('wpmcp_code_tools')
        && function_exists('wpmcp_code_enabled')
        && function_exists('wpmcp_code_constants_forbid')
        && wpmcp_code_enabled()
        && !wpmcp_code_constants_forbid()) {
        $tools = array_merge($tools, wpmcp_code_tools());
    }

    // sql-select is exposed only when its own switch in Settings > WP MCP is on, and the
    // same rule applies for the same reason: a tool that cannot run must not be listed.
    // There is no second condition here because there is no site-wide constant that
    // forbids reading the database - the switch is the whole gate, plus admin scope,
    // which endpoint.php applies to every `write` tool.
    //
    // WITH THE SWITCH OFF THE TOOL DOES NOT EXIST. It is absent from tools/list, and
    // tools/call answers the SAME -32602 "Unknown tool: sql-select" it answers for a name
    // nobody ever registered - because that refusal comes from this registry being the
    // only place a tool name is looked up. A distinct "it exists but is disabled" would
    // tell an unauthorised caller a fact about this site's configuration for free.
    if (function_exists('wpmcp_sql_tools')
        && function_exists('wpmcp_sql_enabled')
        && wpmcp_sql_enabled()) {
        $tools = array_merge($tools, wpmcp_sql_tools());
    }

    // The post-meta tools are exposed only when the operator has declared at least one
    // meta key in Settings > WP MCP, and the rule is the third instance of the same one:
    // a tool that cannot run must not be listed. With an empty allow-list every
    // get-post-meta and set-post-meta call is refused by definition, so on a bare site -
    // which is what this plugin is built toward - the pair simply does not exist, and
    // calling either by name answers the "Unknown tool" -32602 an unregistered name gets.
    if (function_exists('wpmcp_meta_tools')
        && function_exists('wpmcp_meta_enabled')
        && wpmcp_meta_enabled()) {
        $tools = array_merge($tools, wpmcp_meta_tools());
    }

    // What the plugin itself registered, to compare the filter's output against.
    $builtin = $tools;

    $tools = apply_filters('wpmcp_tools', $tools);
    $kept  = array();

    foreach ((array) $tools as $name => $tool) {
        $reason = '';

        // An entry the filter left ALONE is identical to the built-in - same array,
        // same closure instances - so identity is what tells "untouched" from
        // "replaced". Anything else under a built-in's name is the filter's, and loses.
        if (array_key_exists($name, $builtin) && $tool !== $builtin[$name]) {
            wpmcp_auth_event('registry_reject', array(
                'tool'   => (string) $name,
                'reason' => 'name_reserved',
            ));
            $kept[$name] = $builtin[$name];
            continue;
        }

        if (!is_array($tool)) {
            $reason = 'not_an_array';
        } elseif (!array_key_exists('write', $tool)) {
            $reason = 'no_write_key';
        } elseif (!is_bool($tool['write'])) {
            $reason = 'write_not_boolean';
        } elseif (!isset($tool['run']) || !is_callable($tool['run'])) {
            $reason = 'run_not_callable';
        } elseif (!isset($tool['description']) || !is_string($tool['description'])) {
            $reason = 'description_not_string';
        } elseif (!isset($tool['inputSchema']) || !is_array($tool['inputSchema'])) {
            $reason = 'schema_not_array';
        } elseif (!wpmcp_annotations_complete($tool)) {
            $reason = 'annotations_incomplete';
        } elseif (!wpmcp_descriptions_within_limit($tool)) {
            // APPENDED, not inserted. Each reason above is the one an entry written
            // against an earlier version of this plugin would already have hit, so a new
            // check goes on the end and no existing rejection changes the reason it
            // reports.
            $reason = 'description_too_long';
        }

        if ($reason !== '') {
            wpmcp_auth_event('registry_reject', array(
                'tool'   => (string) $name,
                'reason' => $reason,
            ));
            continue;
        }

        $kept[$name] = $tool;
    }

    return $kept;
}

/**
 * The four MCP tool annotations, as a closed list.
 *
 * They are HINTS, and the specification says so - a client is free to ignore them - but
 * what a client does with them is ask the human first, or not. So the value of each one
 * is a claim this server makes about a tool, and the four are required rather than
 * optional for the same reason `write` is: a missing `destructiveHint` reads, to
 * anything that only looks at what is present, as "not destructive".
 *
 *   readOnlyHint     the tool changes nothing. Derived: !write. NOT authored per tool,
 *                    because `write` is already the gate a read-scope token is refused
 *                    on, and two independent declarations of the same fact drift.
 *                    code-list and code-read therefore carry readOnlyHint: false even
 *                    though they only read - they are admin-scope tools by the `write`
 *                    flag, and the hint erring toward "ask first" is the safe direction.
 *   destructiveHint  the tool can destroy or overwrite data that was there before.
 *                    Authored per tool. DEFAULT true WHEN UNSTATED, matching MCP's own
 *                    default, so a new tool whose author did not think about it is
 *                    treated as dangerous.
 *   idempotentHint   the same call twice has no additional effect.
 *   openWorldHint    the tool reaches outside this site. True for upload-media alone,
 *                    which fetches a URL the caller supplies.
 */
function wpmcp_annotation_hints() {
    return array('readOnlyHint', 'destructiveHint', 'idempotentHint', 'openWorldHint');
}

/** All four hints present and boolean? The registry's check. */
function wpmcp_annotations_complete($tool) {
    if (!isset($tool['annotations']) || !is_array($tool['annotations'])) { return false; }

    foreach (wpmcp_annotation_hints() as $hint) {
        if (!array_key_exists($hint, $tool['annotations'])
            || !is_bool($tool['annotations'][$hint])) {
            return false;
        }
    }

    return true;
}

/**
 * Longest description this server will publish, in characters.
 *
 * 1000 is where clients cut. The number is not in the MCP specification, which says
 * nothing about length; it is a property of the clients that read what this server sends,
 * and they apply it by truncating rather than by complaining. The plugin's own longest
 * description is 267 characters, so the cap costs the built-ins nothing and exists for
 * what a filter adds.
 */
define('WPMCP_MAX_DESCRIPTION', 1000);

/**
 * Is every description in this tool short enough to survive a client intact?
 *
 * Checks the tool's own `description` and the `description` of every top-level property
 * of its `inputSchema`. Those are the two strings that reach a model, and a truncated one
 * is worse than a short one: the caller reads an instruction that stops mid-sentence and
 * has no way to know something was removed.
 *
 * Only top-level properties, deliberately. A nested property description is already
 * inside a parent whose own description this checks, and a recursive walk over a
 * filter-supplied structure of unknown depth is a different kind of risk.
 *
 * A description that is absent or not a string is not this function's business - the
 * description_not_string check ahead of it has already refused that entry.
 */
function wpmcp_descriptions_within_limit($tool) {
    if (isset($tool['description']) && is_string($tool['description'])
        && mb_strlen($tool['description']) > WPMCP_MAX_DESCRIPTION) {
        return false;
    }

    $schema = isset($tool['inputSchema']) ? $tool['inputSchema'] : null;
    $props  = null;

    if (is_object($schema)) { $schema = (array) $schema; }
    if (is_array($schema) && isset($schema['properties'])) { $props = $schema['properties']; }
    if (is_object($props)) { $props = (array) $props; }
    if (!is_array($props)) { return true; }

    foreach ($props as $property) {
        if (is_object($property)) { $property = (array) $property; }
        if (!is_array($property)) { continue; }

        if (isset($property['description']) && is_string($property['description'])
            && mb_strlen($property['description']) > WPMCP_MAX_DESCRIPTION) {
            return false;
        }
    }

    return true;
}

/* ---------------- the `{}` / `[]` serialization guard ---------------- */

/**
 * Empty PHP arrays that sit where JSON Schema expects an OBJECT, turned into `{}`.
 *
 * THE BUG IT CLOSES, in one line: `wp_json_encode(array())` is `[]`, and
 * `"properties": []` is not a JSON Schema - a client that validates the tool list
 * rejects the whole listing, and the server looks broken rather than wrong in one
 * character. PHP has one array type for both of JSON's, so the distinction cannot be
 * carried by the value; it has to be restored from the POSITION, which is what this
 * function is. Sprint 4 already had to solve it once by hand, for `capabilities`, with
 * a `new stdClass()` written inline at the call site - the one-off that this replaces.
 *
 * THE OBJECT POSITIONS ARE ENUMERATED, NOT GUESSED, and a blanket "every empty array
 * becomes an object" would be WRONG: `required` and `enum` are JSON ARRAYS, so an empty
 * one of those must stay `[]`. The positions are:
 *
 *   properties            a map of name => schema. Empty means `{}`.
 *   additionalProperties  a schema, when it is not the boolean false.
 *   items                 a schema.
 *   default               only when the sibling `type` is `object`; a `default` under
 *                         `type: array` is an array and stays one.
 *   _meta, annotations    objects by definition.
 *   the node itself       a schema is an object, so an empty one is `{}`.
 *
 * AT ANY DEPTH: `properties` recurses through every sub-schema, so a nested object
 * property with no members of its own is caught too.
 *
 * Values that are already objects pass through untouched - a schema written with
 * `new stdClass()` is already correct and this must not undo it.
 */
function wpmcp_objectify_schema($node) {
    if (!is_array($node)) { return $node; }
    if ($node === array()) { return new stdClass(); }

    $out = array();

    foreach ($node as $key => $value) {
        switch ($key) {
            case 'properties':
                $out[$key] = wpmcp_objectify_object_map($value);
                break;

            case 'additionalProperties':
            case 'items':
                $out[$key] = is_array($value) ? wpmcp_objectify_schema($value) : $value;
                break;

            case 'default':
                $out[$key] = ($value === array() && isset($node['type']) && $node['type'] === 'object')
                    ? new stdClass()
                    : $value;
                break;

            case '_meta':
            case 'annotations':
                $out[$key] = ($value === array()) ? new stdClass() : $value;
                break;

            default:
                $out[$key] = $value;
        }
    }

    return $out;
}

/**
 * A map whose VALUES are JSON objects - a schema's `properties`, and `initialize`'s
 * `capabilities`.
 *
 * Cast to an object rather than left as an associative array, so that a map whose keys
 * happen to look like integers cannot come out as a JSON array either. The values go
 * through wpmcp_objectify_schema(), which is what makes an empty one `{}` - and is why
 * `capabilities: array('tools' => array())` serializes as `{"tools":{}}`.
 */
function wpmcp_objectify_object_map($map) {
    if (!is_array($map)) { return $map; }
    if ($map === array()) { return new stdClass(); }

    $out = array();

    foreach ($map as $name => $value) {
        $out[$name] = wpmcp_objectify_schema($value);
    }

    return (object) $out;
}

/* ---------------- tools/list pagination ---------------- */

/**
 * How many tools one `tools/list` page carries.
 *
 * LARGER THAN THE SURFACE, deliberately: 20 built-ins plus anything a filter adds, so
 * there is no second page today and `nextCursor` is absent. The parameter still has to
 * be accepted and validated, because a client is entitled to send one back and a server
 * that ignores `cursor` silently re-serves page one forever.
 */
define('WPMCP_TOOLS_PAGE_SIZE', 50);

/** An offset as the opaque cursor a client sees. */
function wpmcp_cursor_encode($offset) {
    return base64_encode((string) (int) $offset);
}

/**
 * A cursor back to an offset, or null when it is not one of ours.
 *
 * STRICT, AND CANONICAL: base64 in strict mode, decimal digits only, and the value has
 * to re-encode to the exact string that was sent. Without that last test `MA==`, `MA=`
 * and `MA` would all decode to 0, so three different cursors would address one page and
 * "opaque" would mean "guessable". An absent cursor is offset 0; anything else is
 * -32602 at the call site.
 */
function wpmcp_cursor_decode($cursor) {
    if ($cursor === null || $cursor === '') { return 0; }
    if (!is_string($cursor)) { return null; }

    $decoded = base64_decode($cursor, true);

    if ($decoded === false || $decoded === '' || !ctype_digit($decoded)) { return null; }
    if (wpmcp_cursor_encode((int) $decoded) !== $cursor) { return null; }

    return (int) $decoded;
}

/* ---------------- the handshake ---------------- */

/** Every revision this server speaks, newest first. The wire form, for a message. */
function wpmcp_supported_protocol_versions() {
    return array_map(
        static function (ProtocolVersion $v) { return $v->value; },
        ProtocolVersion::cases()
    );
}

/**
 * What `initialize` answers with, given the client's `params.protocolVersion`.
 *
 * A VERSION WE DO NOT KNOW IS NOT AN ERROR. The client asked for `2099-01-01`; the
 * correct answer is "I speak 2025-11-25", sent as a SUCCESS, and the client then decides
 * whether it can work with that - MCP's basic lifecycle says so, and it is also the only
 * behaviour that does not break the day a newer client appears. An error here would turn
 * every future client into a hard failure against every installed copy of this plugin.
 *
 * An ABSENT protocolVersion is the same case: nothing to echo, so we state ours.
 */
function wpmcp_negotiated_protocol_version($params) {
    $asked = isset($params['protocolVersion']) && is_scalar($params['protocolVersion'])
        ? (string) $params['protocolVersion']
        : null;

    $matched = ProtocolVersion::tryFromString($asked);

    return ($matched ?? ProtocolVersion::latest())->value;
}

/**
 * `serverInfo`: who is answering, which version, and which BUILD of it.
 *
 * NAME AND VERSION STAY EXACTLY WHAT THE SPEC ASKS FOR. `name` is an identifier and
 * `version` a clean semantic version, because a client may display or compare them and
 * MCP's Implementation object says so. The build is a THIRD key beside them, never a
 * suffix on the version: the spec's objects carry keys a client does not know through
 * untouched, so a client that ignores `build` is unaffected, and a client that wants to
 * report which build a site is running has it without a tool call.
 *
 * `source` here means the site is running the plugin out of a git checkout rather than
 * from a built zip. See wpmcp_build_label().
 *
 * @return array{name:string,version:string,build:string}
 */
function wpmcp_server_info() {
    return array(
        'name'    => 'wp-mcp',
        'version' => WPMCP_VER,
        'build'   => wpmcp_build_label(),
    );
}

/**
 * What this server tells a client it is, once, at the handshake.
 *
 * THREE FACTS, AND THEY ARE THE THREE THAT CHANGE WHAT AN AGENT DOES. Not a feature
 * list: an agent that knows its reach is one WordPress user's stops treating a refusal as
 * a bug to retry, and an agent that knows write tools need a different token stops
 * looking for the write tool it cannot see. Everything else it can learn from tools/list.
 *
 * The last clause is the one corollary of the third fact a caller gets wrong (sprint 14d):
 * a read-scope token is not a privacy boundary. It reads everything its user can, which for
 * an administrator includes every user's email. The mint form says the same to the operator.
 */
function wpmcp_server_instructions() {
    return 'This is a WordPress site exposed as MCP tools: posts, pages, taxonomies,'
        . ' media and comments, plus (when the operator enables it) files in the active'
        . " theme.\n"
        . 'Every tool runs as the WordPress user this token was minted for, so that'
        . " user's own capabilities are the ceiling on what you can see or change. A"
        . " refusal is usually that ceiling, not a malformed call.\n"
        . 'Tools that write - create, update, delete, upload - need an admin-scope token.'
        . ' With a read-scope token they are not listed at all, so the tool list you get'
        . " is already what this token may do. Scope gates writing only: a read-scope token"
        . " reads everything its user can.";
}

/**
 * The `MCP-Protocol-Version` REQUEST header, checked on everything but `initialize`.
 *
 * Returns null when the request may proceed, or the 400 to send instead.
 *
 * ABSENT IS ACCEPTED, and specifically means `2025-03-26`: that revision predates the
 * header, so a request without one is by definition from a client that old - the MCP
 * transport spec states exactly this fallback. Since all three supported revisions behave
 * identically across everything this server serves, the assumption costs nothing and it
 * is what keeps an older client working. It is NOT treated as "unspecified, refuse".
 *
 * PRESENT AND UNKNOWN IS HTTP 400, and the body names every version we speak so the
 * client's next attempt can be right rather than another guess. -32600 Invalid Request,
 * because the envelope is wrong rather than the method or the params - no sixth code.
 *
 * THIS IS THE ONE ERROR ON A NON-200 STATUS, and that is deliberate: a protocol-version
 * mismatch is a transport-layer fact about the whole request, which the MCP spec puts at
 * the HTTP layer, and a client that cannot parse our JSON-RPC - plausible, since it is
 * speaking a revision we do not know - must still be able to see the refusal.
 *
 * `initialize` IS EXEMPT BY THE SPEC, and has to be: the header cannot carry a negotiated
 * version before negotiation has happened. The version in that request's *params* is the
 * one that matters, and wpmcp_negotiated_protocol_version() never refuses it.
 */
function wpmcp_protocol_version_gate(WP_REST_Request $req, $id, $method) {
    if ($method === 'initialize') { return null; }

    $header = (string) $req->get_header('mcp-protocol-version');

    if ($header === '' || ProtocolVersion::isSupported($header)) { return null; }

    // The rejected value is echoed so a client can see WHICH of its headers was wrong,
    // and truncated because it is an attacker-controlled request header: without this, a
    // 100 KB MCP-Protocol-Version would come straight back out as a 100 KB error message.
    $seen = strlen($header) > 32 ? substr($header, 0, 32) . '...' : $header;

    return wpmcp_rpc_err(
        $id,
        -32600,
        'Unsupported MCP-Protocol-Version: ' . $seen . '. This server speaks '
            . implode(', ', wpmcp_supported_protocol_versions()) . '.',
        null,
        400
    );
}

/* ---------------- JSON-RPC dispatch ---------------- */

/**
 * THE ERROR BOUNDARY. Every response this endpoint produces comes through here.
 *
 * FIVE CODES, AND NO SIXTH. That is the whole wire vocabulary, and it is an allow-list
 * rather than a catalogue of cases:
 *
 *   -32700  Parse error      the body is valid JSON but not a JSON object, so it cannot
 *                            be a request and cannot carry an id. (Genuinely broken JSON
 *                            never reaches us: core answers it 400 rest_invalid_json.)
 *   -32600  Invalid Request  the envelope is wrong. Two cases, and only two: a JSON array
 *                            body - a batch, which this endpoint does not support, id null
 *                            - and an `MCP-Protocol-Version` header naming a revision this
 *                            server does not speak, which is the one error sent on HTTP
 *                            400 rather than 200 (see wpmcp_protocol_version_gate()).
 *   -32601  Method not found an unknown JSON-RPC method, `notifications/anything`
 *                            included once it carries an id.
 *   -32602  Invalid params   THE REQUEST'S OWN SHAPE is wrong, and nothing else: an
 *                            unknown tool name, a `params.name` that is not a string, a
 *                            `params.arguments` that is not an object, a `tools/list`
 *                            cursor this server did not issue. An argument that fails
 *                            the tool's inputSchema is NOT this - it is a tool error
 *                            (`isError: true`, the failures as text), because the
 *                            envelope was fine and the agent's recovery is to fix the
 *                            call. See the validation block in wpmcp_dispatch().
 *   -32603  Internal error   ANYTHING unexpected. One message, "Internal error", plus
 *                            data.trace_id. The detail is in trace.php's log.
 *
 * Adding a sixth code means a client would have to act differently on it. None does, so
 * there is no sixth - see claude_code_memory/fail-loud-explicit-scope.md.
 *
 * WHY THE CATCH IS HERE AND NOWHERE ELSE. Before this, a TypeError inside a tool became
 * a WordPress fatal: HTTP 500, and whatever display_errors was set to - on a default
 * install, the class, the message, the absolute file path and the line, handed to a
 * caller holding a read-scope token. One catch-all at the boundary closes every one of
 * those paths at once, including the ones nobody enumerated, which is the point.
 *
 * The id, the method and the tool name are read BEFORE the try, because the error
 * response needs them and the log line wants them. json_decode cannot throw without
 * JSON_THROW_ON_ERROR, so that read is itself safe.
 */
function wpmcp_handle(WP_REST_Request $req) {
    $raw  = (string) $req->get_body();
    $body = json_decode($raw, true);

    $id     = (is_array($body) && array_key_exists('id', $body)) ? $body['id'] : null;
    $method = (is_array($body) && isset($body['method'])) ? (string) $body['method'] : '';
    $tool   = '';

    if ($method === 'tools/call' && isset($body['params']['name'])) {
        $tool = is_scalar($body['params']['name']) ? (string) $body['params']['name'] : '';
    }

    try {
        return wpmcp_dispatch($req, $raw, $body, $id, $method);
    } catch (\Throwable $e) {
        // Nothing from $e reaches the wire. The trace id is the only thing that crosses.
        return wpmcp_rpc_err($id, -32603, 'Internal error', array(
            'trace_id' => wpmcp_trace($e, $method, $tool),
        ));
    }
}

/**
 * The framing decisions, in the order they have to happen.
 *
 * BATCH IS REJECTED EXPLICITLY, FIRST, and on the RAW BODY rather than the decoded one.
 * `json_decode('[]', true)` and `json_decode('{}', true)` are both the empty PHP array,
 * so the decoded value cannot tell a zero-length batch from an empty object. The first
 * non-whitespace byte can, exactly, and costs nothing.
 *
 * A NOTIFICATION IS A REQUEST WITH NO `id` KEY. Not a method name starting with
 * `notifications/` - that was the old test and it was wrong in both directions. A client
 * that sends `{"id": 7, "method": "notifications/tools/list_changed"}` has asked a
 * question and is owed an answer (-32601, since we serve no such method), and a client
 * that sends any method at all without an id has asked for silence and gets 202 with an
 * empty body - whatever else is wrong with the body, because there is no id to answer to.
 * JSON-RPC 2.0 section 4.1: a server MUST NOT reply to a notification.
 *
 * THE PROTOCOL-VERSION HEADER IS CHECKED AFTER THE NOTIFICATION TEST, not before, so a
 * notification carrying a bad header is still answered with silence. The two rules would
 * otherwise contradict each other, and "MUST NOT reply" is the stronger one: a client that
 * sent no id cannot read a 400 anyway.
 *
 * @param WP_REST_Request $req    the request, for the MCP-Protocol-Version header.
 * @param string     $raw    the request body as sent.
 * @param mixed      $body   json_decode($raw, true).
 * @param mixed      $id     the request id, or null when there is none.
 * @param string     $method the JSON-RPC method, or ''.
 */
function wpmcp_dispatch(WP_REST_Request $req, $raw, $body, $id, $method) {
    // A JSON array body: a batch. One refusal, named, so a client stops guessing.
    if (substr(ltrim($raw), 0, 1) === '[') {
        return wpmcp_rpc_err(null, -32600, 'Batch requests are not supported');
    }

    // Valid JSON that is not an object: no id can exist, so it is answerable but not
    // dispatchable.
    if (!is_array($body)) {
        return wpmcp_rpc_err(null, -32700, 'Parse error');
    }

    // No id key -> a notification. Nothing is dispatched and nothing is returned.
    if (!array_key_exists('id', $body)) {
        return new WP_REST_Response(null, 202);
    }

    // Gate 10: the negotiated revision, on everything but the negotiation itself.
    $refusal = wpmcp_protocol_version_gate($req, $id, $method);
    if ($refusal !== null) { return $refusal; }

    $params = isset($body['params']) && is_array($body['params']) ? $body['params'] : array();

    switch ($method) {
        case 'initialize':
            // CAPABILITIES ARE DERIVED FROM WHAT IS SERVED, not copied from the spec's
            // example. `tools` and nothing else: no `prompts`, no `resources`, no
            // `logging`, and no `listChanged` inside `tools` - this server has no way to
            // tell a client the tool list changed, so claiming the capability would be a
            // lie a client could wait on.
            //
            // THE EMPTY OBJECT IS ASKED FOR BY POSITION, not by an inline
            // new stdClass(). wp_json_encode() turns an empty PHP array into `[]`, and
            // `"tools": []` is not an object - a strict client rejects the whole
            // initialize result. Sprint 4 fixed that here with a literal stdClass;
            // Sprint 5 replaced it with the guard, so the same rule now holds for every
            // schema too and there is one implementation rather than two.
            return wpmcp_rpc_ok($id, array(
                'protocolVersion' => wpmcp_negotiated_protocol_version($params),
                'capabilities'    => wpmcp_objectify_object_map(array('tools' => array())),
                'serverInfo'      => wpmcp_server_info(),
                'instructions'    => wpmcp_server_instructions(),
            ));

        case 'ping':
            return wpmcp_rpc_ok($id, new stdClass());

        case 'tools/list':
            // PAGINATION, and it is ten lines rather than a framework: an opaque base64
            // offset over the registry's own deterministic order. An unreadable cursor is
            // -32602 - the client sent a parameter this server did not issue, which is a
            // malformed request, not an empty page.
            $offset = wpmcp_cursor_decode(
                array_key_exists('cursor', $params) ? $params['cursor'] : null
            );
            if ($offset === null) {
                return wpmcp_rpc_err($id, -32602, 'Invalid cursor');
            }

            // Read tokens see only read tools; write/code tools appear for admin scope.
            $session  = $GLOBALS['wpmcp_session'];
            $is_admin = ($session && $session->scope === 'admin');
            $out = array();
            foreach (wpmcp_tools() as $name => $t) {
                if (!empty($t['write']) && !$is_admin) { continue; }
                $out[] = array(
                    'name'        => $name,
                    'description' => $t['description'],
                    // The guard, on the way out. See wpmcp_objectify_schema().
                    'inputSchema' => wpmcp_objectify_schema($t['inputSchema']),
                    'annotations' => $t['annotations'],
                );
            }

            $page   = array_slice($out, $offset, WPMCP_TOOLS_PAGE_SIZE);
            $result = array('tools' => $page);

            // ABSENT rather than null when there is no next page: MCP reads the presence
            // of the key, and a `"nextCursor": null` is a key.
            if ($offset + WPMCP_TOOLS_PAGE_SIZE < count($out)) {
                $result['nextCursor'] = wpmcp_cursor_encode($offset + WPMCP_TOOLS_PAGE_SIZE);
            }

            return wpmcp_rpc_ok($id, $result);

        case 'tools/call':
            // THE SHAPE OF THE REQUEST ITSELF IS -32602, which is what that code is
            // reserved for here: an unknown tool, and a CallToolRequest that is not one.
            // `arguments` silently becoming array() when it is a string or a list is how
            // a caller's mistake turns into a tool running on defaults.
            if (isset($params['name']) && !is_string($params['name'])) {
                return wpmcp_rpc_err($id, -32602, 'Invalid CallToolRequest: params.name must be a string');
            }
            if (array_key_exists('arguments', $params)
                && !(is_array($params['arguments'])
                    && ($params['arguments'] === array() || !array_is_list($params['arguments'])))) {
                return wpmcp_rpc_err($id, -32602, 'Invalid CallToolRequest: params.arguments must be an object');
            }

            $name  = isset($params['name']) ? (string) $params['name'] : '';
            $args  = isset($params['arguments']) && is_array($params['arguments']) ? $params['arguments'] : array();
            $tools = wpmcp_tools();
            if (!isset($tools[$name])) {
                return wpmcp_rpc_err($id, -32602, 'Unknown tool: ' . $name);
            }
            // Scope gate - gate 8 of wpmcp_authorize()'s sequence, enforced here
            // because it is the first point at which the tool name exists.
            $session = $GLOBALS['wpmcp_session'];
            if (!empty($tools[$name]['write']) && (!$session || $session->scope !== 'admin')) {
                wpmcp_auth_event('scope_deny', array(
                    'token_id' => $session ? (int) $session->id : 0,
                    'user_id'  => $session ? (int) $session->user_id : 0,
                    'scope'    => $session ? (string) $session->scope : '',
                    'tool'     => $name,
                ));
                return wpmcp_rpc_ok($id, wpmcp_tool_result('This tool requires an admin-scope token.', true));
            }

            // ALWAYS-ON INPUT VALIDATION, and it runs here: after the scope gate, so a
            // token that may not call this tool at all is not handed a critique of its
            // arguments, and before the run, so the tool never sees a value of the wrong
            // type. The refusal is an MCP tool error - `isError: true` with the failures
            // as text - and NOT -32602: the request is well-formed JSON-RPC naming a tool
            // that exists, and an agent recovers from a tool error by fixing the call.
            $failures = SchemaValidator::validateArguments($args, $tools[$name]['inputSchema']);
            if ($failures !== array()) {
                return wpmcp_rpc_ok($id, wpmcp_tool_result(
                    'Invalid arguments for ' . $name . ":\n" . implode("\n", $failures),
                    true
                ));
            }

            $result = call_user_func($tools[$name]['run'], $args);
            if (is_wp_error($result)) {
                return wpmcp_tool_error_response($id, $result, $method, $name);
            }
            return wpmcp_rpc_ok($id, wpmcp_tool_result(wp_json_encode($result), false));

        default:
            return wpmcp_rpc_err($id, -32601, 'Method not found: ' . $method);
    }
}

/**
 * Core WP_Error codes whose MESSAGE may be relayed to the caller as a tool error.
 *
 * A SHORT, CLOSED LIST, and every entry earns its place the same way: the failure is the
 * CALLER'S OWN MISTAKE, core's sentence says what the mistake was, and the caller can act
 * on it. "Internal error" for any of these is actively worse than useless - an agent
 * cannot self-correct on it, so it retries the same call, and each attempt writes a stack
 * trace to the log for something that is not a bug.
 *
 *   term_exists        create-term on a term that is already there. The agent should use
 *                      it, not create it, and core's message names the existing term.
 *   comment_duplicate  the same comment body, twice. Stop, do not resend.
 *   comment_flood      too fast. Wait, then resend - the one case where retrying IS right,
 *                      and the agent cannot know that from "Internal error".
 *   empty_content      an empty comment. Fix the arguments.
 *   http_request_failed  upload-media could not fetch `source_url`. Overwhelmingly a URL
 *                      the agent typed wrong, and the message says what went wrong with it.
 *
 * This does not reopen the "no named error cases" rule - it applies it. The rule is "do
 * not add a named error case unless the client must act differently on it", and the client
 * acts differently on all five. Everything else core produces stays generic.
 *
 * These are relayed and NOT logged: a non-bug does not belong in a log of bugs.
 */
function wpmcp_relayable_core_error_codes() {
    return array(
        'term_exists',
        'comment_duplicate',
        'comment_flood',
        'empty_content',
        'http_request_failed',
    );
}

/**
 * A WP_Error that came back from a tool: whose is it?
 *
 * THREE ANSWERS, DECIDED BY THE CODE AND NOTHING ELSE.
 *
 * Every WP_Error this plugin constructs carries a code beginning `wpmcp_`. That is a
 * sentence the tool's author wrote for the caller - "No post with that ID", "That file is
 * on the denylist" - and it belongs on the wire as an MCP tool error: `isError: true` with
 * the message as text, which is what an agent needs in order to do something else instead
 * of retrying.
 *
 * A code on wpmcp_relayable_core_error_codes() is core's, but it describes the caller's own
 * mistake in words the caller can act on, so its message is relayed the same way. See that
 * function for why each one is there.
 *
 * ANYTHING ELSE came out of WordPress and was not written for a client: wpdb's last_error
 * with the SQL in the error DATA, wp_insert_post's, wp_handle_upload's, an HTTP API failure
 * naming an internal host. Those are exactly as unpredictable as a throwable, so they are
 * treated as one: generic -32603, trace id on the wire, the whole thing - data included -
 * in the private log.
 *
 * The default is the safe one, which means a tool added through the `wpmcp_tools` filter has
 * to opt in to speaking to the client rather than opt out of leaking.
 */
function wpmcp_tool_error_response($id, $error, $method, $tool) {
    $code = (string) $error->get_error_code();

    if (strpos($code, 'wpmcp_') === 0
        || in_array($code, wpmcp_relayable_core_error_codes(), true)) {
        return wpmcp_rpc_ok($id, wpmcp_tool_result('Error: ' . $error->get_error_message(), true));
    }

    return wpmcp_rpc_err($id, -32603, 'Internal error', array(
        'trace_id' => wpmcp_trace_wp_error($error, $method, $tool),
    ));
}

function wpmcp_tool_result($text, $isError) {
    return array('content' => array(array('type' => 'text', 'text' => (string) $text)), 'isError' => (bool) $isError);
}
function wpmcp_rpc_ok($id, $result) {
    return new WP_REST_Response(array('jsonrpc' => '2.0', 'id' => $id, 'result' => $result), 200);
}

/**
 * A JSON-RPC error response. $code must be one of the five in wpmcp_handle()'s docblock.
 *
 * $data is the optional `error.data` member - used for exactly one thing, the trace id on
 * a -32603, and nothing else ever goes in it.
 *
 * $status IS 200 FOR EVERY JSON-RPC ERROR BUT ONE. A JSON-RPC error is a successful HTTP
 * exchange carrying an application-level failure, and a client that reads the status
 * instead of the body must not be told the transport broke. The exception is the
 * MCP-Protocol-Version refusal, which is a fact about the HTTP request rather than about
 * the JSON-RPC inside it - see wpmcp_protocol_version_gate().
 */
function wpmcp_rpc_err($id, $code, $message, $data = null, $status = 200) {
    $error = array('code' => $code, 'message' => $message);

    if ($data !== null) { $error['data'] = $data; }

    return new WP_REST_Response(array('jsonrpc' => '2.0', 'id' => $id, 'error' => $error), (int) $status);
}
