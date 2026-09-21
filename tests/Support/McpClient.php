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
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\TransferStats;
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

    /**
     * The raw HTTP response, for assertions about status codes (401 and friends).
     *
     * NO RETRY, ON PURPOSE. One run of the suite saw a single connection failure that
     * three reruns could not reproduce, and a retry would have hidden it permanently.
     * Instead every transport failure is re-thrown carrying the five numbers that
     * would name the cause: the curl error, how many TCP connections were opened,
     * total time, and how long the TCP and TLS handshakes took. A transparent TLS
     * proxy, a stale keep-alive and a slow site each leave a different signature
     * there. One more occurrence with these numbers settles it.
     */
    public function post(string $method, array $params = [], array $extraHeaders = []): ResponseInterface
    {
        return $this->send(
            (string) json_encode([
                'jsonrpc' => '2.0',
                'id'      => self::$nextId++,
                'method'  => $method,
                'params'  => $params,
            ]),
            $extraHeaders,
            $method
        );
    }

    /**
     * A POST whose body and Content-Type the test chooses.
     *
     * Sprint 2's transport gates are about the envelope, not the JSON-RPC inside it: a
     * `text/plain` body has to be refused before anything parses it, so the test has
     * to be able to send one.
     */
    public function postRaw(string $body, array $extraHeaders = []): ResponseInterface
    {
        return $this->send($body, $extraHeaders, 'raw');
    }

    /**
     * @param array<string, string> $extraHeaders overrides the defaults, key by key
     */
    private function send(string $body, array $extraHeaders, string $what): ResponseInterface
    {
        $stats = null;

        try {
            return $this->http->post('wp-json/wpmcp/mcp', [
                'headers' => array_merge([
                    'Authorization' => 'Bearer ' . $this->token,
                    'Content-Type'  => 'application/json',
                ], $extraHeaders),
                'body'     => $body,
                'on_stats' => static function (TransferStats $s) use (&$stats): void {
                    $stats = $s;
                },
            ]);
        } catch (GuzzleException $e) {
            throw new RuntimeException(
                "MCP {$what} could not complete a request to this site: "
                . $e->getMessage() . ' | ' . self::describe($stats),
                0,
                $e
            );
        }
    }

    /** curl's own account of the attempt, or a note that Guzzle never reported one. */
    private static function describe(?TransferStats $stats): string
    {
        if ($stats === null) {
            return 'no TransferStats (the handler failed before reporting)';
        }

        $handler = $stats->getHandlerStats();

        return sprintf(
            'curl_errno=%s num_connects=%s total_time=%s connect_time=%s appconnect_time=%s',
            $stats->getHandlerErrorData() ?? 'none',
            $handler['num_connects'] ?? '?',
            $handler['total_time'] ?? '?',
            $handler['connect_time'] ?? '?',
            $handler['appconnect_time'] ?? '?'
        );
    }

    /**
     * Call a tool and unwrap the MCP result.
     *
     * $extraHeaders exists for fixtures that have to be armed PER REQUEST rather than per
     * class. Sprint 9's sql-select switch is one: a mu-plugin that answered
     * `pre_option_wpmcp_sql_enabled` with 1 for the whole class would expose every table
     * the database user can read to every admin token on the site for as long as the
     * class ran - on the stress site, a real client's. Gated on a header instead, the
     * switch is on for exactly the requests that ask for it, and the same class can test
     * the tool with the switch off by simply not sending the header.
     *
     * @param array<string, string> $extraHeaders
     */
    public function callTool(string $name, array $arguments = [], array $extraHeaders = []): ToolResult
    {
        $response = $this->post('tools/call', ['name' => $name, 'arguments' => $arguments], $extraHeaders);
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
