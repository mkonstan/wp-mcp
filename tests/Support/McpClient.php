<?php
/**
 * One MCP token, speaking JSON-RPC to the real endpoint over real HTTP.
 *
 * The token travels in `Authorization: Bearer`, one of the two forms the plugin
 * accepts, and the POST goes to the constant `/wp-json/wpmcp/mcp` URL. Nothing is
 * loaded in-process: the whole auth path - TLS, header parsing, token validation,
 * wp_set_current_user, the capability checks in the tool - runs on the server.
 *
 * A tool result is unwrapped into ToolResult because the interesting assertions are
 * two layers down: MCP puts a tool's failure in `result.isError` with the message as
 * TEXT, not in the JSON-RPC `error` object, and a success puts the tool's own JSON in
 * that same text field as a string.
 */

declare(strict_types=1);

namespace WpMcp\Tests\Support;

use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

final class McpClient
{
    private static int $nextId = 1;

    public function __construct(
        private readonly Client $http,
        private readonly string $token
    ) {
    }

    /** The raw HTTP response, for assertions about status codes (401 and friends). */
    public function post(string $method, array $params = []): ResponseInterface
    {
        return $this->http->post('wp-json/wpmcp/mcp', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->token,
                'Content-Type'  => 'application/json',
            ],
            'body' => (string) json_encode([
                'jsonrpc' => '2.0',
                'id'      => self::$nextId++,
                'method'  => $method,
                'params'  => $params,
            ]),
        ]);
    }

    /** Call a tool and unwrap the MCP result. */
    public function callTool(string $name, array $arguments = []): ToolResult
    {
        $response = $this->post('tools/call', ['name' => $name, 'arguments' => $arguments]);
        $status   = $response->getStatusCode();
        $raw      = (string) $response->getBody();

        if ($status !== 200) {
            throw new RuntimeException(
                "tools/call {$name} returned HTTP {$status}, not 200. Body: {$raw}"
            );
        }

        $body = json_decode($raw, true);

        if (!is_array($body) || !isset($body['result']['content'][0]['text'])) {
            throw new RuntimeException(
                "tools/call {$name} did not return an MCP tool result. Body: {$raw}"
            );
        }

        return new ToolResult(
            (bool) ($body['result']['isError'] ?? false),
            (string) $body['result']['content'][0]['text']
        );
    }
}
