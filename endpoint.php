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
    $tools = array();
    foreach (array('wpmcp_core_tools', 'wpmcp_content_tools', 'wpmcp_taxonomy_tools', 'wpmcp_media_tools', 'wpmcp_comment_tools') as $fn) {
        if (function_exists($fn)) { $tools = array_merge($tools, $fn()); }
    }
    // Code tools are exposed only when explicitly enabled in Settings > WP MCP.
    if (function_exists('wpmcp_code_tools') && function_exists('wpmcp_code_enabled') && wpmcp_code_enabled()) {
        $tools = array_merge($tools, wpmcp_code_tools());
    }
    return apply_filters('wpmcp_tools', $tools);
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
