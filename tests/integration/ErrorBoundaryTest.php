<?php
/**
 * A tool that throws reaches the client as one generic error, and the operator as a
 * whole stack trace.
 *
 * WHAT WENT WRONG BEFORE. `call_user_func($tools[$name]['run'], $args)` had nothing
 * around it. A TypeError inside a tool - the commonest real failure in PHP 8, because
 * every loose argument that used to be coerced now throws - became a WordPress fatal:
 * HTTP 500, and with display_errors on (every default PHP install, and most shared
 * hosts) the class, the message, the absolute path and the line went straight to a
 * caller holding a read-scope token. That is a filesystem layout and a plugin inventory,
 * handed over by the least privileged credential the plugin issues.
 *
 * THE TEST TOOL THROWS A REAL TypeError, registered through the public `wpmcp_tools`
 * filter from a per-run mu-plugin - the same door a third-party plugin uses, so the
 * boundary is tested where tools actually come from rather than by patching the plugin.
 *
 * AND BOTH HALVES ARE ASSERTED. A boundary that swallows the throwable and writes
 * nothing is not a boundary, it is a silence: the client learns nothing AND the operator
 * learns nothing, and that is strictly worse than the 500 it replaced. So the wire is
 * checked for the absence of detail, and the log on the site is then read back and
 * checked for its presence, keyed by the trace id the wire handed over.
 *
 * @group sprint-3
 */

declare(strict_types=1);

namespace WpMcp\Tests\Integration;

use WpMcp\Tests\Support\Fixtures;
use WpMcp\Tests\Support\FixtureIntegrationTestCase;
use WpMcp\Tests\Support\MuPlugin;
use WpMcp\Tests\Support\TraceLog;

final class ErrorBoundaryTest extends FixtureIntegrationTestCase
{
    private static function label(): string { return Fixtures::name('boundary'); }
    private static function login(): string { return Fixtures::name('boundary-author'); }

    /** The mu-plugin that registers the throwing tool. */
    private const THROWING = 'throwing-tool';

    /** The message the TypeError carries. It must NOT appear on the wire. */
    private const THROWN_MESSAGE = 'wpmcp trace probe: this message must not reach a client';

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

        MuPlugin::drop(self::THROWING, self::throwingToolSource());

        self::$userId = Fixtures::createUser(self::login(), 'author');
        self::$token  = Fixtures::mintToken('read', self::label(), self::$userId);
    }

    public static function tearDownAfterClass(): void
    {
        self::destroy();

        parent::tearDownAfterClass();
    }

    private static function destroy(): void
    {
        MuPlugin::remove(self::THROWING);
        Fixtures::deleteUser(self::$userId);
        Fixtures::deleteTokensLabelled(self::label());
        Fixtures::purge();
    }

    /** The name of the throwing tool, namespaced to this run. */
    private static function toolName(): string
    {
        return Fixtures::name('tool-throws');
    }

    /**
     * The control: the tool IS registered and callable, so a failure below is the
     * boundary talking and not a filter that never ran.
     *
     * @group sprint-3
     */
    public function testTheThrowingToolIsRegistered(): void
    {
        $body = (string) $this->mcp(self::$token)->post('tools/list')->getBody();

        self::assertStringContainsString(
            self::toolName(),
            $body,
            'The throwing fixture tool is not in tools/list, so the wpmcp_tools filter'
            . ' never ran and every assertion in this class would pass vacuously.'
        );
    }

    /**
     * tools/call on the throwing tool: -32603, "Internal error", an eight-hex trace id,
     * and nothing else at all.
     *
     * @group sprint-3
     */
    public function testAThrowingToolBecomesAGenericInternalError(): array
    {
        $response = $this->mcp(self::$token)->post('tools/call', [
            'name'      => self::toolName(),
            'arguments' => [],
        ]);

        $raw = (string) $response->getBody();

        self::assertSame(
            200,
            $response->getStatusCode(),
            'A thrown TypeError did not come back as a JSON-RPC error inside 200. A 500'
            . ' here means the throwable escaped wpmcp_handle() into WordPress, which is'
            . ' the fatal-error page the boundary exists to prevent. Body: ' . $raw
        );

        $body = json_decode($raw, true);

        self::assertIsArray($body, 'Not JSON: ' . $raw);
        self::assertSame(-32603, $body['error']['code'] ?? null, $raw);
        self::assertSame(
            'Internal error',
            $body['error']['message'] ?? null,
            'The JSON-RPC message must be exactly "Internal error" - one string for every'
            . ' unexpected failure, so the message itself discloses nothing. Body: ' . $raw
        );

        $traceId = (string) ($body['error']['data']['trace_id'] ?? '');

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}$/',
            $traceId,
            'error.data.trace_id is not eight lower-case hex digits. Without it the'
            . ' generic message is a dead end for the operator. Body: ' . $raw
        );

        // Now the absence assertions, on the WHOLE body rather than on one field: the
        // detail must not be anywhere, including in a field nobody thought to check.
        foreach ([
            'TypeError'            => 'the throwable\'s class name',
            self::THROWN_MESSAGE   => 'the throwable\'s message',
            'mu-plugins'           => 'a filesystem path from the stack',
            '.php'                 => 'a file name',
            '#0 '                  => 'a stack frame',
        ] as $needle => $what) {
            self::assertStringNotContainsString(
                $needle,
                $raw,
                'The response body leaks ' . $what . ' (' . $needle . '). The only thing'
                . ' that may cross the boundary is the trace id. Body: ' . $raw
            );
        }

        return ['trace_id' => $traceId];
    }

    /**
     * The same failure, in the private log: the trace id, the class, the message and a
     * file:line, plus an indented stack.
     *
     * @depends testAThrowingToolBecomesAGenericInternalError
     *
     * @group sprint-3
     */
    public function testTheThrowableIsWrittenToThePrivateTraceLog(array $wire): void
    {
        $traceId = $wire['trace_id'];
        $entry   = TraceLog::entry($traceId);

        self::assertNotSame(
            '',
            $entry,
            'No line in wp-content/wpmcp/trace.log carries trace=' . $traceId . '. The'
            . ' client was handed a trace id that leads nowhere, which is worse than the'
            . ' 500 this replaced: nobody learns anything. Log: ' . TraceLog::contents()
        );

        self::assertStringContainsString('class=TypeError', $entry, $entry);
        self::assertStringContainsString('message=' . self::THROWN_MESSAGE, $entry, $entry);
        self::assertStringContainsString('method=tools/call', $entry, $entry);
        self::assertStringContainsString('tool=' . self::toolName(), $entry, $entry);
        self::assertStringContainsString('user=' . self::$userId, $entry, $entry);

        // file:line. The path may contain spaces - "C:\Users\x\Local Sites\..." on the
        // machine this was developed against - so it is matched up to the `.php:<line>`
        // rather than as a run of non-whitespace.
        self::assertMatchesRegularExpression(
            '/ at=.+\.php:\d+$/m',
            $entry,
            'The trace entry has no file:line, so an operator holding the trace id still'
            . ' cannot find the failure. Entry: ' . $entry
        );

        // The stack, indented under the header line.
        self::assertMatchesRegularExpression(
            '/\n {4}#0 /',
            $entry,
            'The trace entry has no indented stack trace. Entry: ' . $entry
        );

        // And the log still does not carry the credential.
        self::assertStringNotContainsString(
            self::$token,
            TraceLog::contents(),
            'The raw token is in the trace log. Tokens are identified by their row id.'
        );
        self::assertStringContainsString(
            'token=',
            $entry,
            'The trace entry does not say which token row the call came from.'
        );
    }

    /**
     * Is the trace log readable over HTTP? MEASURED, not assumed, and the answer is a
     * property of the host rather than of this repository.
     *
     * On Local by Flywheel the answer is YES: nginx never reads .htaccess and its config
     * has no rule for /wp-content/, so the file is served with 200 and its full contents.
     * That IS the finding, and it is the reason the plugin asks the question itself
     * instead of shipping three lines of Apache syntax and calling the log private. So
     * this test asserts the ONE thing that must be true either way: either the host
     * refuses the file, or the plugin's own self-check noticed and raised the admin
     * warning. A host that serves it AND says nothing is the failure.
     *
     * @group sprint-3
     */
    public function testTheTraceLogIsEitherUnreachableOrLoudlyFlagged(): void
    {
        // The self-check also creates the directory and the empty log, so the URL has a
        // file behind it - a 404 from a missing file would answer a different question.
        $selfCheck = TraceLog::selfCheck();

        self::assertNotSame(
            'null',
            $selfCheck,
            'The plugin could not fetch its own log URL at all, so it cannot know whether'
            . ' the log is public. wp_remote_get to ' . TraceLog::url() . ' failed.'
        );

        $response = $this->client()->get('wp-content/wpmcp/trace.log');
        $status   = $response->getStatusCode();

        if ($status !== 200) {
            self::assertFalse(
                TraceLog::exposedOptionIsSet(),
                'This host refuses the trace log (' . $status . ') but the plugin\'s'
                . ' self-check still has the admin warning raised, so the admin page is'
                . ' crying wolf.'
            );

            return;
        }

        self::assertTrue(
            TraceLog::exposedOptionIsSet(),
            'THIS HOST SERVES wp-content/wpmcp/trace.log WITH 200 - stack traces, absolute'
            . ' file paths and SQL, to anybody - AND the plugin\'s self-check did not'
            . ' notice. A silent exposure is the one outcome that is not allowed: the'
            . ' admin page must carry the red warning naming the server config to add.'
        );
    }

    /**
     * A tool registered through the filter whose `run` throws a TypeError on the way in.
     *
     * A declared `int` parameter against an array argument is a real TypeError from the
     * engine rather than a hand-thrown one, but the message would then name the fixture's
     * own signature and be less readable in the log; an explicit throw keeps the message
     * under the test's control so the absence assertion above can name exactly what must
     * not appear.
     */
    private static function throwingToolSource(): string
    {
        $name    = self::toolName();
        $message = self::THROWN_MESSAGE;

        return <<<PHP
add_filter('wpmcp_tools', static function (\$tools) {
    \$tools['{$name}'] = array(
        'write'       => false,
        'description' => 'wp-mcp test fixture: throws a TypeError.',
        'inputSchema' => array('type' => 'object', 'properties' => new stdClass()),
        'run'         => static function (\$args) {
            throw new TypeError('{$message}');
        },
    );

    return \$tools;
});
PHP;
    }
}
