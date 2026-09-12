<?php
/**
 * Copyright (C) 2026 Max Konstantinovski. GPLv2 or later (see LICENSE).
 *
 * WP MCP - DIY MCP-over-HTTP endpoint.
 *
 * Routes:
 *   POST /wp-json/wpmcp/mcp/{token}   (token in the path)
 *   POST /wp-json/wpmcp/mcp           (token in Authorization: Bearer header)
 * Speaks minimal MCP JSON-RPC 2.0: initialize, notifications/initialized,
 * tools/list, tools/call, ping. Single JSON response per request (no SSE).
 * Transport: HTTPS required, Origin checked against the site's own, POST must be
 *   application/json. All three before the token is read - see wpmcp_authorize(),
 *   whose docblock states the full order of the gates.
 * Auth: the token is validated per request (shape, lookup, user, expiry, TOFU IP).
 *   Every failure is ONE byte-identical 401; the reason is in the auth event.
 * Identity: the request runs as the WordPress user the token was minted for.
 * Scope: 'read' tokens are refused any tool flagged write=true.
 * Registry: a tool without an explicit boolean `write`, a string description and an
 *   array inputSchema is not registered; a built-in's name cannot be re-declared.
 * Errors: FIVE JSON-RPC codes and no others - see wpmcp_handle(). Anything unexpected
 *   is one generic -32603 carrying a trace id; the throwable goes to trace.php's log.
 */
if (!defined('ABSPATH')) { exit; }

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
    // Token in the URL path: convenient, but the path lands in server access logs.
    register_rest_route('wpmcp', '/mcp/(?P<token>[a-f0-9]{64})', $route);
    // Token in an Authorization: Bearer header against a constant URL: kept out of logs.
    register_rest_route('wpmcp', '/mcp', $route);
});

/* ---------------- the verb gate ---------------- */

/** Is this REST route one of ours? Both forms, token or no token. */
function wpmcp_is_our_route($route) {
    return (bool) preg_match('#^/wpmcp/mcp(/[a-f0-9]{64})?$#', (string) $route);
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

/** The JSON-RPC method on this request (read from the POST body), or '' if none. */
function wpmcp_request_method(WP_REST_Request $req) {
    $b = json_decode($req->get_body(), true);
    return (is_array($b) && isset($b['method'])) ? (string) $b['method'] : '';
}

/**
 * Token from either the URL path segment or an "Authorization: Bearer <token>"
 * header. Path wins if both are present. Empty string if neither (-> dormant 401).
 */
function wpmcp_extract_token(WP_REST_Request $req) {
    $tok = (string) $req['token'];
    if ($tok !== '') { return $tok; }
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
 * test the `home` option is `http://jaygroup.local` and home_url() still answers
 * `https://jaygroup.local` over TLS - verified. Since wpmcp_request_is_secure() runs
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
 *   4. token shape      64 lower-case hex, from the path or Bearer -> 401
 *   5. token lookup     by SHA-256 hash                            -> 401
 *   6. user exists      get_userdata(user_id)                      -> 401
 *   7. expiry           expires_at <= now                          -> 401
 *   8. IP pin           bound_ip set and different                 -> 401
 *      ... then the pin is CREATED here, for a tools/call on an unbound token
 *   9. scope            enforced at dispatch, in wpmcp_handle()
 *
 * WHY THIS ORDER. 1-3 are properties of the envelope and cost nothing, so they run
 * before the credential is even read: a request refused for being plaintext must not
 * first have its token looked up in the database, or the refusal becomes a token
 * oracle with a timing side channel. 4-8 narrow from "is this string even a token" to
 * "is it this token, still alive, from the right place", cheapest first and each one a
 * precondition of the next. 6 precedes 7 so a dead token's row is not even touched.
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
 * THIS PLUGIN READS NO PART OF THE REQUEST BODY BEFORE 8 - but WordPress does, and the
 * earlier version of this comment claimed otherwise. `WP_REST_Server::dispatch()` calls
 * `$request->has_valid_params()`, which calls `parse_json_params()` for any
 * application/json body, and only then does `respond_to_request()` reach the
 * permission_callback. So a malformed body is answered by core with 400
 * `rest_invalid_json` before gate 1 runs at all: no auth event fires for it, and the
 * JSON is decoded before the caller is authenticated (its size is Sprint 3's business).
 * That refusal leaks nothing about the token - core never looks at one - but it is not
 * this function's refusal and it is not in this order.
 *
 * What this function changed is the plugin's own read: the TOFU pin needs to know whether
 * this is a tools/call, so wpmcp_request_method() used to json_decode() the body at the
 * TOP of the callback, on every request, valid token or not. It now runs after gate 8.
 *
 * 9 IS THE ONE GATE NOT IN THIS FUNCTION, and deliberately. The scope decision needs
 * the tool name, which only exists once the body is parsed, and its refusal is an MCP
 * tool error (200 with isError) rather than an HTTP status, because a read token
 * calling a write tool is a correctly authenticated request asking for something it
 * may not have. It fires the scope_deny event from wpmcp_handle().
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

    // 4-8.
    $row = wpmcp_validate(wpmcp_extract_token($req), $ip);
    if (is_wp_error($row)) {
        // The reason is already in the validate_fail event. One answer on the wire.
        return wpmcp_unauthorized();
    }

    // The TOFU pin, now that the token has passed: only a tool call creates it, so
    // discovery and the handshake can happen from anywhere. This is the first thing
    // on the request path that looks at the body.
    if (wpmcp_request_method($req) === 'tools/call') {
        wpmcp_bind_token_ip($row, $ip);
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
 *   name_reserved         a filter entry using a BUILT-IN tool's name. The built-in
 *                         wins and the filter entry is dropped. A same-name entry is
 *                         how the fail-closed rule gets walked around one level up:
 *                         `delete-post` re-declared with `write => false` is a write
 *                         tool published to every read-scope token, and it passes every
 *                         check above because it declares a boolean. Site code may well
 *                         mean to extend a tool, but it cannot do it by overwriting the
 *                         entry whose `write` flag is the gate.
 *
 * The built-in tools all carry `'write' => true|false` explicitly - 20 of them, one
 * per entry - so the checks apply uniformly rather than trusting "ours" over "theirs".
 * If a future built-in forgets the key it disappears from the listing and the log says
 * so, which is the loud failure.
 */
function wpmcp_tools() {
    $tools = array();
    foreach (array('wpmcp_core_tools', 'wpmcp_content_tools', 'wpmcp_taxonomy_tools', 'wpmcp_media_tools', 'wpmcp_comment_tools') as $fn) {
        if (function_exists($fn)) { $tools = array_merge($tools, $fn()); }
    }
    // Code tools are exposed only when explicitly enabled in Settings > WP MCP.
    if (function_exists('wpmcp_code_tools') && function_exists('wpmcp_code_enabled') && wpmcp_code_enabled()) {
        $tools = array_merge($tools, wpmcp_code_tools());
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
 *   -32600  Invalid Request  a JSON array body - a batch, which this endpoint does not
 *                            support. id: null.
 *   -32601  Method not found an unknown JSON-RPC method, `notifications/anything`
 *                            included once it carries an id.
 *   -32602  Invalid params   an unknown tool name on tools/call.
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
        return wpmcp_dispatch($raw, $body, $id, $method);
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
 * @param string     $raw    the request body as sent.
 * @param mixed      $body   json_decode($raw, true).
 * @param mixed      $id     the request id, or null when there is none.
 * @param string     $method the JSON-RPC method, or ''.
 */
function wpmcp_dispatch($raw, $body, $id, $method) {
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

    $params = isset($body['params']) && is_array($body['params']) ? $body['params'] : array();

    switch ($method) {
        case 'initialize':
            $pv = isset($params['protocolVersion']) ? (string) $params['protocolVersion'] : '2025-06-18';
            return wpmcp_rpc_ok($id, array(
                'protocolVersion' => $pv,
                'capabilities'    => array('tools' => new stdClass()),
                'serverInfo'      => array('name' => 'WP MCP', 'version' => WPMCP_VER),
            ));

        case 'ping':
            return wpmcp_rpc_ok($id, new stdClass());

        case 'tools/list':
            // Read tokens see only read tools; write/code tools appear for admin scope.
            $session  = $GLOBALS['wpmcp_session'];
            $is_admin = ($session && $session->scope === 'admin');
            $out = array();
            foreach (wpmcp_tools() as $name => $t) {
                if (!empty($t['write']) && !$is_admin) { continue; }
                $out[] = array('name' => $name, 'description' => $t['description'], 'inputSchema' => $t['inputSchema']);
            }
            return wpmcp_rpc_ok($id, array('tools' => $out));

        case 'tools/call':
            $name  = isset($params['name']) ? (string) $params['name'] : '';
            $args  = isset($params['arguments']) && is_array($params['arguments']) ? $params['arguments'] : array();
            $tools = wpmcp_tools();
            if (!isset($tools[$name])) {
                return wpmcp_rpc_err($id, -32602, 'Unknown tool: ' . $name);
            }
            // Scope gate - gate 9 of wpmcp_authorize()'s sequence, enforced here
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
 */
function wpmcp_rpc_err($id, $code, $message, $data = null) {
    $error = array('code' => $code, 'message' => $message);

    if ($data !== null) { $error['data'] = $data; }

    return new WP_REST_Response(array('jsonrpc' => '2.0', 'id' => $id, 'error' => $error), 200);
}
