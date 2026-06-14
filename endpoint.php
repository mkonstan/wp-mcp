<?php
/**
 * WP MCP - DIY MCP-over-HTTP endpoint.
 *
 * Routes:
 *   POST /wp-json/wpmcp/mcp/{token}   (token in the path)
 *   POST /wp-json/wpmcp/mcp           (token in Authorization: Bearer header)
 * Speaks minimal MCP JSON-RPC 2.0: initialize, notifications/initialized,
 * tools/list, tools/call, ping. Single JSON response per request (no SSE).
 * Auth: the token is validated per request (expiry + TOFU IP).
 * Scope: 'read' tokens are refused any tool flagged write=true.
 */
if (!defined('ABSPATH')) { exit; }

/* Holds the validated token row between permission_callback and the handler. */
$GLOBALS['wpmcp_session'] = null;

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

/**
 * Validate the token against this request. Stash the row on success.
 * Returns true, or a WP_Error (becomes 401/403) - which also makes the
 * endpoint effectively dormant when no valid token exists.
 */
function wpmcp_authorize(WP_REST_Request $req) {
    $raw = wpmcp_extract_token($req);
    // Only tool calls create the IP pin; once bound, validation enforces it for all methods.
    $enforce_ip = (wpmcp_request_method($req) === 'tools/call');
    $row = wpmcp_validate($raw, wpmcp_client_ip(), $enforce_ip);
    if (is_wp_error($row)) {
        $code = $row->get_error_code();
        $status = ($code === 'ip_mismatch') ? 403 : 401;
        return new WP_Error('wpmcp_' . $code, $row->get_error_message(), array('status' => $status));
    }
    $GLOBALS['wpmcp_session'] = $row;
    // Run as the minting admin so tool capability checks behave; scope still gates.
    if ($row->created_by) { wp_set_current_user((int) $row->created_by); }
    return true;
}

/* ---------------- tool registry ---------------- */
function wpmcp_tools() {
    $tools = array(
        'site-info' => array(
            'write' => false,
            'description' => 'Site name, URL, WordPress version, active theme, active plugin count.',
            'inputSchema' => array('type' => 'object', 'properties' => new stdClass()),
            'run' => function ($args) {
                $theme = wp_get_theme();
                return array(
                    'name'           => get_bloginfo('name'),
                    'url'            => home_url(),
                    'wp_version'     => get_bloginfo('version'),
                    'active_theme'   => $theme ? ($theme->get('Name') . ' ' . $theme->get('Version')) : null,
                    'active_plugins' => count((array) get_option('active_plugins', array())),
                );
            },
        ),
        'list-posts' => array(
            'write' => false,
            'description' => 'List recent content. Args: post_type (default "post"), status (default "any"), limit (default 20, max 100).',
            'inputSchema' => array('type' => 'object', 'properties' => array(
                'post_type' => array('type' => 'string'),
                'status'    => array('type' => 'string'),
                'limit'     => array('type' => 'integer'),
            )),
            'run' => function ($args) {
                $q = new WP_Query(array(
                    'post_type'      => isset($args['post_type']) ? sanitize_key($args['post_type']) : 'post',
                    'post_status'    => isset($args['status']) ? sanitize_key($args['status']) : 'any',
                    'posts_per_page' => isset($args['limit']) ? min(100, max(1, (int) $args['limit'])) : 20,
                    'no_found_rows'  => true,
                ));
                $items = array();
                foreach ($q->posts as $p) {
                    $items[] = array(
                        'id' => $p->ID, 'title' => get_the_title($p), 'type' => $p->post_type,
                        'status' => $p->post_status, 'slug' => $p->post_name, 'link' => get_permalink($p),
                    );
                }
                return array('count' => count($items), 'items' => $items);
            },
        ),
        'get-post' => array(
            'write' => false,
            'description' => 'Get title/status/raw content for a post or page. Args: id (integer, required).',
            'inputSchema' => array('type' => 'object',
                'properties' => array('id' => array('type' => 'integer')),
                'required' => array('id')),
            'run' => function ($args) {
                $id = isset($args['id']) ? (int) $args['id'] : 0;
                $p = $id ? get_post($id) : null;
                if (!$p) { return new WP_Error('not_found', 'No post with that ID.'); }
                return array(
                    'id' => $p->ID, 'title' => get_the_title($p), 'type' => $p->post_type,
                    'status' => $p->post_status, 'slug' => $p->post_name, 'content' => $p->post_content,
                );
            },
        ),
    );
    if (function_exists('wpmcp_extra_tools')) {
        $tools = array_merge($tools, wpmcp_extra_tools());
    }
    // Code tools are exposed only when explicitly enabled in Settings > WP MCP.
    if (function_exists('wpmcp_code_tools') && function_exists('wpmcp_code_enabled') && wpmcp_code_enabled()) {
        $tools = array_merge($tools, wpmcp_code_tools());
    }
    return $tools;
}

/* ---------------- JSON-RPC dispatch ---------------- */
function wpmcp_handle(WP_REST_Request $req) {
    $body = json_decode($req->get_body(), true);
    if (!is_array($body)) {
        return wpmcp_rpc_err(null, -32700, 'Parse error'); // already a WP_REST_Response
    }
    // Notifications (no id) - ack with 202, no body.
    $id     = array_key_exists('id', $body) ? $body['id'] : null;
    $method = isset($body['method']) ? (string) $body['method'] : '';
    $params = isset($body['params']) && is_array($body['params']) ? $body['params'] : array();

    if ($method === 'notifications/initialized' || (strpos($method, 'notifications/') === 0)) {
        return new WP_REST_Response(null, 202);
    }

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
            // Scope gate: read tokens cannot call write tools.
            $session = $GLOBALS['wpmcp_session'];
            if (!empty($tools[$name]['write']) && (!$session || $session->scope !== 'admin')) {
                return wpmcp_rpc_ok($id, wpmcp_tool_result('This tool requires an admin-scope token.', true));
            }
            $result = call_user_func($tools[$name]['run'], $args);
            if (is_wp_error($result)) {
                return wpmcp_rpc_ok($id, wpmcp_tool_result('Error: ' . $result->get_error_message(), true));
            }
            return wpmcp_rpc_ok($id, wpmcp_tool_result(wp_json_encode($result), false));

        default:
            return wpmcp_rpc_err($id, -32601, 'Method not found: ' . $method);
    }
}

function wpmcp_tool_result($text, $isError) {
    return array('content' => array(array('type' => 'text', 'text' => (string) $text)), 'isError' => (bool) $isError);
}
function wpmcp_rpc_ok($id, $result) {
    return new WP_REST_Response(array('jsonrpc' => '2.0', 'id' => $id, 'result' => $result), 200);
}
function wpmcp_rpc_err($id, $code, $message) {
    return new WP_REST_Response(array('jsonrpc' => '2.0', 'id' => $id, 'error' => array('code' => $code, 'message' => $message)), 200);
}
