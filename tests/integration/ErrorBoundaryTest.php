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
     * A core WP_Error code on the relay allow-list reaches the caller as a tool error, and
     * is NOT logged.
     *
     * WHY THE LIST EXISTS. Wrapping every core WP_Error in "Internal error" made the
     * agent's own mistakes unreadable: `term_exists` means "use the term, do not create
     * it", `comment_flood` means "wait and retry", `http_request_failed` on a source_url
     * means "you typed the URL wrong". An agent cannot self-correct on "Internal error", so
     * it repeats the call, and each repeat writes a stack trace for something that is not a
     * bug. Five codes, all of them the caller's own doing, all of them actionable.
     *
     * @group sprint-3
     */
    public function testARelayableCoreErrorReachesTheCallerAndIsNotLogged(): void
    {
        $before = strlen(TraceLog::contents());

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
            strlen(TraceLog::contents()),
            'A relayed, caller-caused error wrote a trace. The log is for bugs; five codes'
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
        self::assertSame('Internal error', $body['error']['message'] ?? null, $raw);

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
     * B1. The old, guessable URL is gone: `GET /wp-content/wpmcp/trace.log` is not served.
     *
     * MEASURED BEFORE THE FIX: that exact URL returned 200 with 14 KB of absolute Windows
     * paths including the OS username, the plugin's development checkout path, mu-plugin
     * file names, tool names, user ids, token row ids and every stack frame's arguments -
     * to anybody, with no token, on nginx, Caddy and LiteSpeed alike, because `.htaccess`
     * is an Apache file those servers neither read nor serve. The plugin's self-check
     * noticed and the plugin carried on writing, which made it an observation rather than a
     * guard. The memory rule this sprint implements says the log must be VERIFIED
     * UNREACHABLE, so the name is now unguessable and the legacy file is deleted on sight.
     *
     * @group sprint-3
     */
    public function testTheOldGuessableLogUrlIsNotServed(): void
    {
        // Provoke a write, so a 404 below cannot be "the directory does not exist yet".
        self::assertNotSame('', TraceLog::contents(), 'The log is empty, so nothing has'
            . ' been written and this test would pass on an absent directory.');

        $response = $this->client()->get('wp-content/wpmcp/' . TraceLog::LEGACY_NAME);

        self::assertNotSame(
            200,
            $response->getStatusCode(),
            'wp-content/wpmcp/' . TraceLog::LEGACY_NAME . ' is still served. Either the'
            . ' log is still written under its old fixed name, or a file left by the'
            . ' previous version was not removed - and a stranger reads the stack traces.'
        );
    }

    /**
     * The real name is not derivable from anything a client sees.
     *
     * The guard is the secrecy of one string, so the test is that the string does not
     * appear in any response a caller can obtain: the generic error, a tools/list, a
     * tools/call, the directory itself. The 32 hex digits are searched for on their own as
     * well as the whole file name, because half a name is a name.
     *
     * @group sprint-3
     */
    public function testTheRealLogNameIsNotDerivableFromAnythingAClientSees(): void
    {
        $name = TraceLog::fileName();

        self::assertMatchesRegularExpression(
            '/^trace-[0-9a-f]{32}\.log$/',
            $name,
            'The log file name is not the unguessable shape, so the URL is derivable.'
        );

        $secret = substr($name, strlen('trace-'), 32);

        $surfaces = [
            'the generic error' => (string) $this->mcp(self::$token)->post('tools/call', [
                'name'      => self::toolName(),
                'arguments' => [],
            ])->getBody(),
            'tools/list'        => (string) $this->mcp(self::$token)->post('tools/list')->getBody(),
            'initialize'        => (string) $this->mcp(self::$token)->post('initialize')->getBody(),
            'the directory'     => (string) $this->client()->get('wp-content/wpmcp/')->getBody(),
        ];

        foreach ($surfaces as $what => $body) {
            self::assertStringNotContainsString($name, $body, $what . ' names the log file.');
            self::assertStringNotContainsString(
                $secret,
                $body,
                $what . ' contains the log name\'s random half, so the URL is derivable.'
            );
        }
    }

    /**
     * Belt and braces, all three of which are now secondary to the random name: the
     * self-check still runs against the REAL file name, the file is 0600, and the
     * `.htaccess` is written the way Apache 2.4 can actually parse.
     *
     * THE SELF-CHECK IS NOT REDUNDANT. It catches the one thing the secret name does not:
     * a directory listing, which hands the name to everybody. A 200 on the real URL
     * therefore still has to raise the site-wide warning.
     *
     * S9: `Deny from all` on its own is an unknown directive on an Apache 2.4 without
     * mod_access_compat, and an unknown directive in an .htaccess turns the directory into
     * a 500 - "not readable", but by breaking the server. Each spelling sits behind the
     * IfModule that makes it legal.
     *
     * @group sprint-3
     */
    public function testTheRemainingGuardsAreInPlace(): void
    {
        $selfCheck = TraceLog::selfCheck();

        self::assertNotSame(
            'null',
            $selfCheck,
            'The plugin could not fetch its own log URL at all, so it cannot tell whether a'
            . ' directory listing has exposed the name. wp_remote_get to ' . TraceLog::url()
            . ' failed.'
        );

        $status = $this->client()->get('wp-content/wpmcp/' . TraceLog::fileName())->getStatusCode();

        self::assertSame(
            $status === 200,
            TraceLog::exposedOptionIsSet(),
            'The self-check and reality disagree: the real log URL answered ' . $status
            . ' and the site-wide warning is '
            . (TraceLog::exposedOptionIsSet() ? 'raised' : 'down')
            . '. Either a readable log is silent, or the admin screens cry wolf.'
        );

        // 0600, WHERE THE FILESYSTEM CAN SAY SO. MEASURED on the development site: it is
        // Windows, fileperms() answers 0666 whatever the file is, and chmod() returns true
        // while changing nothing - NTFS ACLs are not POSIX mode bits. So the assertion is
        // "0600, or a host that cannot express it", which has real teeth in CI (wp-env is
        // Linux) and states the local fact instead of skipping and going quietly green.
        $mode = TraceLog::mode();
        $os   = TraceLog::osFamily();

        self::assertTrue(
            $mode === '0600' || $os === 'Windows',
            'The trace log is ' . $mode . ' on a ' . $os . ' host, not 0600. On a shared'
            . ' host whose parent path is traversable, another account reads it.'
        );

        $htaccess = TraceLog::htaccess();

        self::assertStringContainsString('<IfModule mod_authz_core.c>', $htaccess, $htaccess);
        self::assertStringContainsString('Require all denied', $htaccess, $htaccess);
        self::assertStringContainsString('<IfModule !mod_authz_core.c>', $htaccess, $htaccess);

        // Every directive sits inside a block, so the file's first non-blank line is an
        // IfModule and no bare directive can be met by an Apache that does not know it.
        self::assertSame(
            '<IfModule mod_authz_core.c>',
            trim((string) strtok($htaccess, "\n")),
            'The .htaccess opens with a bare directive rather than an IfModule. On Apache'
            . ' 2.4 without mod_access_compat an unknown directive turns the whole directory'
            . ' into a 500 - "not readable", but by breaking the server. Content: ' . $htaccess
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
        $relay   = self::relayToolName();
        $opaque  = self::opaqueToolName();
        $data    = self::OPAQUE_DATA;

        return <<<PHP
add_filter('wpmcp_tools', static function (\$tools) {
    \$schema = array('type' => 'object', 'properties' => new stdClass());

    \$tools['{$name}'] = array(
        'write'       => false,
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
