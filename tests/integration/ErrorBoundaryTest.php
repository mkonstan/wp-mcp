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

    /** A tool returning a core WP_Error code the caller can act on: message relays. */
    private static function relayToolName(): string
    {
        return Fixtures::name('tool-core-relay');
    }

    /** A tool returning a core WP_Error nobody wrote for a client: goes generic. */
    private static function opaqueToolName(): string
    {
        return Fixtures::name('tool-core-opaque');
    }

    /** The SQL-shaped string the opaque tool puts in its WP_Error DATA. */
    private const OPAQUE_DATA = 'INSERT INTO wp_terms SECRET-SQL-MUST-NOT-REACH-A-CLIENT';

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
     * tools/call on the throwing tool: -32603, "Internal error (trace <id>)", the same id in
     * `data.trace_id`, and nothing else at all.
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
        $traceId = (string) ($body['error']['data']['trace_id'] ?? '');

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}$/',
            $traceId,
            'error.data.trace_id is not eight lower-case hex digits. Without it the'
            . ' generic message is a dead end for the operator. Body: ' . $raw
        );

        // ONE STRING FOR EVERY UNEXPECTED FAILURE, plus the trace id (1.1.1). A cold client
        // rendered `error.message` and nothing else, so an id that lived only in `data` could
        // not be quoted to the operator and the log line could not be found. The message is
        // still built from a template and a random eight-hex id, so it discloses nothing.
        self::assertSame(
            'Internal error (trace ' . $traceId . ')',
            $body['error']['message'] ?? null,
            'The JSON-RPC message is not the generic sentence carrying this event\'s own trace'
            . ' id. Body: ' . $raw
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
            'No row in the traces table carries trace=' . $traceId . '. The client was'
            . ' handed a trace id that leads nowhere, which is worse than the 500 this'
            . ' replaced: nobody learns anything. The site holds '
            . TraceLog::count() . ' traces.'
        );

        self::assertStringContainsString('class=TypeError', $entry, $entry);
        self::assertStringContainsString('message=' . self::THROWN_MESSAGE, $entry, $entry);
        self::assertStringContainsString('method=tools/call', $entry, $entry);
        self::assertStringContainsString('tool=' . self::toolName(), $entry, $entry);
        // BOUNDED, NOT A SUBSTRING (round 2 of sprint SEAM). `user=1` is a substring of
        // `user=10`, so a plain substring check on a key=value rendering can pass for the
        // wrong user. See ToolResult::mentions() for the class this belongs to.
        self::assertMatchesRegularExpression('/\buser=' . self::$userId . '\b/', $entry, $entry);

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

        // And the store still does not carry the credential.
        self::assertFalse(
            TraceLog::contains(self::$token),
            'The raw token is in the traces table. Tokens are identified by their row id.'
        );
        self::assertStringContainsString(
            'token=',
            $entry,
            'The trace entry does not say which token row the call came from.'
        );
    }

    /**
     * A core WP_Error code on the relay allow-list reaches the caller as a tool error, and
     * is NOT logged.
     *
     * WHY THE LIST EXISTS. Wrapping every core WP_Error in "Internal error" made the
     * agent's own mistakes unreadable: `term_exists` means "use the term, do not create
     * it", `comment_flood` means "wait and retry", `empty_content` means "fix the arguments".
     * An agent cannot self-correct on "Internal error", so it repeats the call, and each repeat
     * writes a stack trace for something that is not a bug. Four codes, all of them the
     * caller's own doing, all of them actionable.
     *
     * FOUR, AND NOT FIVE, SINCE 1.1.1: `http_request_failed` came off the list because its
     * message is the HTTP transport's, and cURL's names the host it could not reach - on a site
     * with WP_PROXY_HOST set, the operator's own proxy. The case it was really protecting - a
     * remote server that ANSWERS and refuses - is upload-media's, and it now gets a relayable
     * `wpmcp_fetch_failed` naming the status instead (tests/integration/ErrorSurfaceTest.php).
     *
     * @group sprint-3
     */
    public function testARelayableCoreErrorReachesTheCallerAndIsNotLogged(): void
    {
        $before = TraceLog::count();

        $result = $this->mcp(self::$token)->callTool(self::relayToolName());

        self::assertTrue($result->isError, 'A WP_Error must come back as isError: ' . $result->text);
        self::assertStringContainsString(
            'already exists',
            $result->text,
            'term_exists is on the relay allow-list, so core\'s own sentence must reach the'
            . ' caller - "Internal error" leaves an agent with nothing to act on. Got: '
            . $result->text
        );

        self::assertSame(
            $before,
            TraceLog::count(),
            'A relayed, caller-caused error wrote a trace. The store is for bugs; four codes'
            . ' were allow-listed precisely so a mistyped argument does not fill it with'
            . ' stacks.'
        );
    }

    /**
     * Any other core WP_Error stays generic - and its DATA goes to the log, which is the
     * whole reason core errors come here at all.
     *
     * S4. wpdb does not put the failing query in the error MESSAGE: `db_insert_error`'s
     * message is "Could not insert term into the database." and `$wpdb->last_error` is in
     * the error DATA. The first version logged only the message, so the one case the
     * docblock cited as justification for treating core errors as throwables was the case
     * whose detail never reached the log.
     *
     * @group sprint-3
     */
    public function testAnOpaqueCoreErrorIsGenericOnTheWireAndCompleteInTheLog(): void
    {
        $response = $this->mcp(self::$token)->post('tools/call', [
            'name'      => self::opaqueToolName(),
            'arguments' => [],
        ]);

        $raw  = (string) $response->getBody();
        $body = json_decode($raw, true);

        self::assertIsArray($body, 'Not JSON: ' . $raw);
        self::assertSame(-32603, $body['error']['code'] ?? null, $raw);
        self::assertStringStartsWith('Internal error (trace ', (string) ($body['error']['message'] ?? ''), $raw);

        self::assertStringNotContainsString(
            self::OPAQUE_DATA,
            $raw,
            'The WP_Error data - where wpdb puts the failing query - reached the client.'
        );
        self::assertStringNotContainsString(
            'Could not insert term',
            $raw,
            'Core\'s message for a code that is NOT on the relay allow-list reached the'
            . ' client. Only the five allow-listed codes may speak. Body: ' . $raw
        );

        $traceId = (string) ($body['error']['data']['trace_id'] ?? '');

        self::assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $traceId, $raw);

        $entry = TraceLog::entry($traceId);

        self::assertStringContainsString('class=WP_Error:db_insert_error', $entry, $entry);
        self::assertStringContainsString(
            self::OPAQUE_DATA,
            $entry,
            'The WP_Error DATA is not in the trace entry, so the SQL that caused the failure'
            . ' is nowhere at all - which was the one thing the private log was for.'
            . ' Entry: ' . $entry
        );
    }

    /**
     * THE THREE FILE-ERA GUARDS ARE GONE FROM THIS CLASS AS OF 1.1.2, and their absence is
     * the sprint. They asserted that `wp-content/wpmcp/trace.log` was not served, that the
     * random log name appeared in no response a caller can obtain, and that the `.htaccess`
     * beside the log was one Apache 2.4 can parse. There is no log file, no directory and no
     * `.htaccess` any more - a trace is a row, and no web server serves a row. What replaced
     * them is tests/integration/TraceTableTest.php: the id still resolves, `sql-select` is
     * refused the table, and the upgrade deletes the file an existing site still has.
     */

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
        $relay   = self::relayToolName();
        $opaque  = self::opaqueToolName();
        $data    = self::OPAQUE_DATA;

        return <<<PHP
add_filter('wpmcp_tools', static function (\$tools) {
    \$schema = array('type' => 'object', 'properties' => new stdClass());

    // Sprint 5 made the four annotations a registration requirement, so a fixture tool
    // needs them too or the registry drops it and these tests lose their subject. Read
    // tools that do nothing: read-only, harmless, repeatable, local.
    \$ann = array(
        'readOnlyHint'    => true,
        'destructiveHint' => false,
        'idempotentHint'  => true,
        'openWorldHint'   => false,
    );

    \$tools['{$name}'] = array(
        'write'       => false,
        'annotations' => \$ann,
        'description' => 'wp-mcp test fixture: throws a TypeError.',
        'inputSchema' => \$schema,
        'run'         => static function (\$args) {
            throw new TypeError('{$message}');
        },
    );

    // A core code on the relay allow-list: the caller's own mistake, in words it can
    // act on. term_exists is what create-term gets when the term is already there.
    \$tools['{$relay}'] = array(
        'write'       => false,
        'annotations' => \$ann,
        'description' => 'wp-mcp test fixture: a relayable core WP_Error.',
        'inputSchema' => \$schema,
        'run'         => static function (\$args) {
            return new WP_Error('term_exists', 'A term with the name provided already exists.', 42);
        },
    );

    // A core code nobody wrote for a client, with the failing query in the DATA, which is
    // exactly where wpdb puts it.
    \$tools['{$opaque}'] = array(
        'write'       => false,
        'annotations' => \$ann,
        'description' => 'wp-mcp test fixture: an opaque core WP_Error.',
        'inputSchema' => \$schema,
        'run'         => static function (\$args) {
            return new WP_Error(
                'db_insert_error',
                'Could not insert term into the database.',
                '{$data}'
            );
        },
    );

    return \$tools;
});
PHP;
    }
}
