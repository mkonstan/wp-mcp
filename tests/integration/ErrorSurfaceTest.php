<?php
/**
 * What a person MEETS when something breaks (1.1.1, sprint SWAP item 1 and item 5a).
 *
 * THE MEASUREMENT THAT STARTED IT, 2026-09-21: `upload-media` on a Wikimedia thumbnail URL
 * answered a bare `Internal error`, and the trace log held the whole story -
 * `class=WP_Error:http_404 message=Bad Request data={"code":400,...}` - because the CDN had
 * refused WordPress's user agent. Nothing the caller was told could be acted on, the failure
 * was not this site's fault, and the trace id that would have found the log line was in
 * `error.data` where the client showed only `error.message`.
 *
 * THREE ANSWERS ARE HELD HERE, and they are the three a remote fetch can give:
 *
 *   an HTTP 400 or 403   the remote server answered and refused. Relayed, naming the status,
 *                        and NEVER the response body - core attaches a kilobyte of it, which
 *                        in the measured case was a full HTML error page.
 *   no connection        generic, because the transport's own sentence names the host it
 *                        could not reach, which on a proxied site is the operator's proxy.
 *                        The trace id is in the MESSAGE, and the sentence is in the log.
 *
 * WHY FIXTURE TOOLS AND NOT A REAL FETCH. `download_url()` goes through
 * `wp_safe_remote_get()`, which refuses a loopback address outright, so a test site cannot
 * fetch anything it is allowed to reach (KB 0.13). The fixture tools hand
 * wpmcp_fetch_error() exactly the WP_Error shapes core builds - read off
 * wp-admin/includes/file.php:1193-1219 - and the assertions are about the BOUNDARY, which is
 * the part this sprint changed.
 *
 * @group sprint-14d
 */

declare(strict_types=1);

namespace WpMcp\Tests\Integration;

use WpMcp\Tests\Support\Fixtures;
use WpMcp\Tests\Support\FixtureIntegrationTestCase;
use WpMcp\Tests\Support\MuPlugin;
use WpMcp\Tests\Support\TraceLog;

final class ErrorSurfaceTest extends FixtureIntegrationTestCase
{
    private const PLUGIN = 'error-surface';

    /** The body core attaches to a non-2xx. It must reach nobody. */
    private const BODY = '<html><body>wpmcp: this response body must not reach a client</body></html>';

    /** A transport failure's message, the shape cURL gives it. Must reach nobody either. */
    private const TRANSPORT = 'cURL error 7: Failed to connect to proxy.internal port 8080';

    /** A tool argument's VALUE. The trace log may name the key; it may not print this. */
    private const ARGUMENT = 'SECRET-ARGUMENT-VALUE-MUST-NOT-REACH-THE-LOG';

    private static int $userId = 0;
    private static string $token = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::requireSite();

        self::buildFixtures(self::build(...), self::destroy(...));
    }

    private static function build(): void
    {
        Fixtures::purge();

        MuPlugin::drop(self::PLUGIN, self::source());

        self::$userId = Fixtures::createUser(Fixtures::name('surface-author'), 'author');
        self::$token  = Fixtures::mintToken('read', Fixtures::name('surface'), self::$userId);
    }

    public static function tearDownAfterClass(): void
    {
        self::destroy();

        parent::tearDownAfterClass();
    }

    private static function destroy(): void
    {
        MuPlugin::remove(self::PLUGIN);
        Fixtures::deleteUser(self::$userId);
        Fixtures::deleteTokensLabelled(Fixtures::name('surface'));
        Fixtures::purge();
    }

    private static function tool(string $what): string
    {
        return Fixtures::name('surface-' . $what);
    }

    /**
     * The control: the fixture tools are registered, so a failure below is the boundary
     * talking and not a filter that never ran.
     *
     * @group sprint-14d
     */
    public function testTheFixtureToolsAreRegistered(): void
    {
        $body = (string) $this->mcp(self::$token)->post('tools/list')->getBody();

        foreach (['400', '403', 'transport', 'argument'] as $what) {
            self::assertStringContainsString(
                self::tool($what),
                $body,
                'The ' . $what . ' fixture tool is not in tools/list, so every assertion about'
                . ' it would pass vacuously.'
            );
        }
    }

    /**
     * A 400 and a 403 both come back naming the STATUS, and neither carries the body.
     *
     * WHY THE STATUS IS THE WHOLE POINT. It is the one fact that decides what an agent does
     * next: 403 means this site is not allowed to fetch that URL and a different one is
     * needed; 404 means the URL is wrong; 429 means wait. `Internal error` means retry the
     * same call forever, which is what the measured case actually did.
     *
     * @group sprint-14d
     */
    public function testARemoteRefusalNamesItsStatusAndNeverItsBody(): void
    {
        // THE KEY IS CAST, because PHP turns the array key '400' into the integer 400 - and a
        // status is a label here, not a number.
        foreach (['400' => 'Bad Request', '403' => 'Forbidden'] as $status => $reason) {
            $status = (string) $status;
            $before = strlen(TraceLog::contents());
            $result = $this->mcp(self::$token)->callTool(self::tool($status));

            self::assertTrue(
                $result->isError,
                'A refused fetch must come back as a tool error, not as a result: ' . $result->text
            );
            self::assertStringContainsString(
                'HTTP ' . $status,
                $result->text,
                'The relayed message does not name the status the remote server answered with,'
                . ' which is the one fact an agent can act on. Got: ' . $result->text
            );
            self::assertStringContainsString(
                $reason,
                $result->text,
                'The remote server\'s reason phrase is not relayed. Got: ' . $result->text
            );
            self::assertStringNotContainsString(
                'wpmcp: this response body must not reach a client',
                $result->text,
                'THE RESPONSE BODY REACHED THE CALLER. core attaches up to a kilobyte of it to'
                . ' the WP_Error, and in the measured case it was a whole HTML page.'
            );

            self::assertSame(
                $before,
                strlen(TraceLog::contents()),
                'A relayed, caller-actionable refusal wrote a trace. The log is for bugs, and a'
                . ' CDN refusing a URL is not one - it is the answer the caller now gets.'
            );
        }
    }

    /**
     * A fetch that never connected stays GENERIC - and says the trace id in the message.
     *
     * TWO CLAIMS IN ONE TEST BECAUSE THEY ARE ONE DECISION. `http_request_failed` came off
     * the relay list precisely because its message is the transport's, and a transport
     * message can name `WP_PROXY_HOST`; the trace id moved into the message so that making it
     * generic costs the operator nothing. Assert them apart and either could regress while
     * the other looked fine.
     *
     * @group sprint-14d
     */
    public function testAConnectionFailureIsGenericAndTheTraceIdIsInTheMessage(): void
    {
        $response = $this->mcp(self::$token)->post('tools/call', [
            'name'      => self::tool('transport'),
            'arguments' => [],
        ]);

        $raw  = (string) $response->getBody();
        $body = json_decode($raw, true);

        self::assertIsArray($body, 'Not JSON: ' . $raw);
        self::assertSame(-32603, $body['error']['code'] ?? null, $raw);

        $message = (string) ($body['error']['message'] ?? '');
        $traceId = (string) ($body['error']['data']['trace_id'] ?? '');

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}$/',
            $traceId,
            'error.data.trace_id is not eight lower-case hex digits. Body: ' . $raw
        );
        self::assertSame(
            'Internal error (trace ' . $traceId . ')',
            $message,
            'The message does not carry the trace id. A cold client rendered ONLY the message,'
            . ' so an id that lives in `data` alone cannot be quoted to the operator and the'
            . ' event cannot be found. Body: ' . $raw
        );

        self::assertStringNotContainsString(
            'proxy.internal',
            $raw,
            'THE TRANSPORT MESSAGE REACHED THE CALLER, and it names the host WordPress could'
            . ' not reach - on a proxied site, the operator\'s own proxy.'
        );

        $entry = TraceLog::entry($traceId);

        self::assertStringContainsString('class=WP_Error:http_request_failed', $entry, $entry);
        self::assertStringContainsString(
            self::TRANSPORT,
            $entry,
            'The transport\'s own sentence is not in the private log either, so it is nowhere'
            . ' and the operator has a trace id pointing at nothing useful. Entry: ' . $entry
        );
    }

    /**
     * The trace log records an argument's KEY and never its VALUE (item 5a).
     *
     * MEASURED FIRST: PHP's `getTraceAsString()` prints the first 15 characters of every
     * string argument - VERIFIED on this PHP (8.2.29, `zend.exception_ignore_args=0`):
     * `f('SECRETSECRETSEC...', Array, Object(stdClass))`. Fifteen characters is a value. The
     * fixture's argument is longer than that and distinctive, so a regression to PHP's
     * formatter fails this assertion rather than sliding under it.
     *
     * @group sprint-14d
     */
    public function testTheTraceStackCarriesArgumentKeysAndNotArgumentValues(): void
    {
        $response = $this->mcp(self::$token)->post('tools/call', [
            'name'      => self::tool('argument'),
            'arguments' => ['secret' => self::ARGUMENT],
        ]);

        $raw     = (string) $response->getBody();
        $body    = json_decode($raw, true);
        $traceId = (string) ($body['error']['data']['trace_id'] ?? '');

        self::assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $traceId, $raw);

        $entry = TraceLog::entry($traceId);

        self::assertStringNotContainsString(
            substr(self::ARGUMENT, 0, 15),
            $entry,
            'THE ARGUMENT\'S VALUE IS IN THE TRACE LOG. Even fifteen characters of it is the'
            . ' start of a URL, a title or a password somebody typed. Entry: ' . $entry
        );
        self::assertStringContainsString(
            'array{secret}',
            $entry,
            'The stack does not name the argument KEYS the call carried, so a frame now says'
            . ' less than PHP\'s own formatter did and the operator gained nothing for the'
            . ' redaction. Entry: ' . $entry
        );
        self::assertStringContainsString(
            '#0 ',
            $entry,
            'The stack has no frames at all, so the replacement formatter is producing nothing.'
            . ' Entry: ' . $entry
        );
    }

    /**
     * Four tools: a 400, a 403, a transport failure, and one that throws with an argument.
     *
     * The first three return what core's own download_url() returns, read off
     * wp-admin/includes/file.php:1193-1219 - `http_404` with the reason phrase as the message
     * and `['code' => N, 'body' => ...]` as the data for any non-2xx, `http_request_failed`
     * with the transport's sentence when there was no response at all - and hand it to
     * wpmcp_fetch_error(), which is exactly what upload-media does with it.
     */
    private static function source(): string
    {
        $body      = self::BODY;
        $transport = self::TRANSPORT;

        $four00    = self::tool('400');
        $four03    = self::tool('403');
        $transportTool = self::tool('transport');
        $argument  = self::tool('argument');

        return <<<PHP
add_filter('wpmcp_tools', static function (\$tools) {
    \$ann = array(
        'readOnlyHint'    => true,
        'destructiveHint' => false,
        'idempotentHint'  => true,
        'openWorldHint'   => false,
    );
    \$empty = array('type' => 'object', 'properties' => new stdClass());

    // core's download_url() for ANY non-2xx: code http_404 whatever the status really was,
    // the reason phrase as the message, and the status plus a body sample as the data.
    \$fetch = static function (\$status, \$reason) {
        return new WP_Error('http_404', \$reason, array(
            'code' => \$status,
            'body' => '{$body}',
        ));
    };

    \$tools['{$four00}'] = array(
        'write' => false, 'annotations' => \$ann, 'inputSchema' => \$empty,
        'description' => 'wp-mcp test fixture: a remote server answering 400.',
        'run' => static function (\$a) use (\$fetch) {
            return wpmcp_fetch_error(\$fetch(400, 'Bad Request'));
        },
    );

    \$tools['{$four03}'] = array(
        'write' => false, 'annotations' => \$ann, 'inputSchema' => \$empty,
        'description' => 'wp-mcp test fixture: a remote server answering 403.',
        'run' => static function (\$a) use (\$fetch) {
            return wpmcp_fetch_error(\$fetch(403, 'Forbidden'));
        },
    );

    \$tools['{$transportTool}'] = array(
        'write' => false, 'annotations' => \$ann, 'inputSchema' => \$empty,
        'description' => 'wp-mcp test fixture: a fetch that never connected.',
        'run' => static function (\$a) {
            return wpmcp_fetch_error(new WP_Error('http_request_failed', '{$transport}'));
        },
    );

    \$tools['{$argument}'] = array(
        'write' => false, 'annotations' => \$ann,
        'inputSchema' => array('type' => 'object', 'properties' => array(
            'secret' => array('type' => 'string'),
        )),
        'description' => 'wp-mcp test fixture: throws, with one string argument.',
        'run' => static function (\$args) {
            throw new RuntimeException('wpmcp fixture: a throw that carries an argument');
        },
    );

    return \$tools;
});
PHP;
    }
}
