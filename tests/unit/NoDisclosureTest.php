<?php
/**
 * A throwable's message never leaves the boundary. Enforced by grep, because nothing
 * else can enforce it.
 *
 * WHAT THIS DEFENDS. Sprint 3 replaced every ad-hoc "report what went wrong" path with
 * one catch-all: a generic -32603, message "Internal error (trace <id>)", the same eight-hex
 * id in `error.data.trace_id`, and
 * the whole throwable in wp-content/wpmcp/trace.log. That holds exactly as long as
 * nobody adds `$e->getMessage()` back into the request path - and the next person to do
 * it will be trying to be helpful, in a catch block, with a tool that is failing in
 * production and no log access. A runtime test cannot see that coming: it would have to
 * provoke the specific new failure. A grep can.
 *
 * THE SIGNATURE IS EVERY ACCESSOR, NOT JUST getMessage(). The first version of this test
 * matched `->getMessage()` and called it "the only way to get a throwable's message",
 * which was wrong: `(string) $e`, `"$e"`, `$e->__toString()`, `$e->getTraceAsString()`,
 * `$e->getFile()`, `$e->getLine()` and `$e->getPrevious()->getMessage()` put the same or
 * strictly more on the wire, and none of them matched. They are all banned here, and the
 * ONE safe fact a caller may want - the line a ParseError names, for code-write - is asked
 * for through trace.php's wpmcp_throwable_line() instead of read off the object.
 *
 * WP_Error's `get_error_message()` is NOT this: a tool's own WP_Error is a sentence its
 * author wrote for the caller, the whole point of the `wpmcp_` prefix allow-list in
 * endpoint.php, and it stays.
 *
 * wp-mcp.php IS GUARDED TOO. wpmcp_validate() and wpmcp_auth_event() run inside the
 * permission_callback, which is on the request path and OUTSIDE wpmcp_handle()'s try - a
 * disclosure there does not even have a catch-all above it.
 *
 * THE DETECTOR IS ITSELF TESTED, twice: against synthetic strings that must match and ones
 * that must not, and against trace.php, which must match because that is where every
 * legitimate call lives. Without those two, a typo'd pattern would make this file a green
 * no-op - exactly the "test that cannot fail" the build plan forbids.
 *
 * @group sprint-3
 */

declare(strict_types=1);

namespace WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class NoDisclosureTest extends TestCase
{
    /**
     * Files on the request path. No throwable may be introspected in any of them.
     *
     * THE MODULES ARE ON IT TOO (1.1.2). A module builds its results inside a `run` closure
     * the dispatcher calls, which is the position tools.php is in, so the same rule applies.
     * They are added from the DIRECTORY rather than listed here - see guardedFiles().
     */
    private const GUARDED = ['endpoint.php', 'tools.php', 'wp-mcp.php', 'modules.php'];

    /** The one file allowed to, because it writes the result to the private log. */
    private const LOGGER = 'trace.php';

    /**
     * Every accessor that yields a throwable's message, path, line or stack, with any
     * amount of whitespace around the arrow, plus a (string) cast of a variable named like
     * a throwable. `getMessage` is matched without requiring `->` so that
     * `call_user_func([$e, 'getMessage'])` is caught too.
     *
     * The cast half is deliberately limited to `$e`, `$ex`, `$t`, `$throwable` - the names
     * a catch block actually uses. `$error` is NOT in it: in this codebase that name means
     * a WP_Error, and `(string) $error->get_error_code()` in endpoint.php is the boundary
     * working rather than leaking. A pattern wide enough to catch every conceivable cast
     * would flag that line and get itself weakened; this one flags the shape that appears
     * in a catch block and nowhere else.
     */
    private const PATTERN = '/(getMessage|getTraceAsString|getTrace|getFile|getLine|getPrevious|__toString)\s*[(\'"]|\(\s*string\s*\)\s*\$(e|ex|t|throwable)\b/';

    /**
     * @group sprint-3
     */
    public function testNoThrowableMessageIsReadOnTheRequestPath(): void
    {
        foreach (self::guardedFiles() as $file) {
            $hits = self::hits($file);

            self::assertSame(
                [],
                $hits,
                $file . ' introspects a throwable on line(s) ' . implode(', ', array_keys($hits))
                . '. A throwable\'s message, file, line and stack carry the filesystem'
                . ' layout, the plugin inventory and often the call arguments, and'
                . ' everything in this file ends up on the wire. Move the call into '
                . self::LOGGER . ' and put the trace id on the wire instead - see'
                . ' wpmcp_trace(), or wpmcp_throwable_line() if a line number is genuinely'
                . ' what the caller needs. Lines: ' . implode(' | ', $hits)
            );
        }
    }

    /**
     * GUARDED, plus every module file on disk.
     *
     * A DIRECTORY AND NOT A LIST, because the list is the part that gets forgotten: a module
     * added in a later sprint is on the request path the moment its slug reaches the
     * manifest, and a guard that has to be remembered is a guard that covers whatever was in
     * it last.
     *
     * @return list<string>
     */
    private static function guardedFiles(): array
    {
        $files = self::GUARDED;

        foreach (glob(\WPMCP_PLUGIN_DIR . '/modules/*.php') ?: [] as $module) {
            $files[] = 'modules/' . basename($module);
        }

        self::assertGreaterThan(
            count(self::GUARDED),
            count($files),
            'No module file was found, so no module is being guarded at all. If modules/ really'
            . ' is empty, the seam has nothing behind it.'
        );

        return $files;
    }

    /**
     * The detector matches a real call. If this fails, the test above proves nothing.
     *
     * @group sprint-3
     */
    public function testTheDetectorMatchesACallItMustCatch(): void
    {
        $planted = [
            '$e->getMessage()',
            '$e -> getMessage( )',
            'return $error->getMessage();',
            '$this->wrapped->getMessage()',
            'call_user_func([$e, \'getMessage\'])',
            '$out = $e->getTraceAsString();',
            '$e->getFile() . \':\' . $e->getLine()',
            'return $e->getPrevious()->getMessage();',
            '$s = $e->__toString();',
            'return (string) $e;',
            'return (string)$ex;',
            '$msg = ( string ) $throwable;',
        ];

        foreach ($planted as $line) {
            self::assertMatchesRegularExpression(
                self::PATTERN,
                $line,
                'The detector does not match ' . $line . ', so it would not catch it in'
                . ' a guarded file either.'
            );
        }

        // And it is not matching everything. Each of these is allowed on the wire and must
        // survive, or the guard gets weakened by whoever trips over it.
        foreach ([
            '$result->get_error_message()'          => 'WP_Error\'s own accessor',
            '$code = (string) $error->get_error_code();' => 'a WP_Error code cast',
            '$name = (string) $params[\'name\'];'   => 'an unrelated string cast',
            '$tools[$name] = $t;'                   => 'a tool array variable',
        ] as $line => $what) {
            self::assertDoesNotMatchRegularExpression(
                self::PATTERN,
                $line,
                'The detector flags ' . $what . ' (' . $line . '), which is allowed.'
            );
        }
    }

    /**
     * The detector finds the one sanctioned call, in the logging file. This is the
     * end-to-end proof that it works against the real source tree and not only against
     * strings written in this test.
     *
     * @group sprint-3
     */
    public function testTheDetectorFindsTheSanctionedCallInTheLogger(): void
    {
        self::assertNotSame(
            [],
            self::hits(self::LOGGER),
            self::LOGGER . ' contains no ->getMessage() call at all. Either the trace log'
            . ' stopped recording the throwable\'s message - which is the only reason the'
            . ' generic wire error is acceptable - or this detector is broken and the'
            . ' guard above is a no-op.'
        );
    }

    /**
     * @return array<int, string> line number => the line
     */
    private static function hits(string $file): array
    {
        $path   = WPMCP_PLUGIN_DIR . '/' . $file;
        $source = file_get_contents($path);

        self::assertIsString($source, 'Could not read ' . $path);

        $hits = [];

        foreach (explode("\n", $source) as $i => $line) {
            if (preg_match(self::PATTERN, $line) === 1) {
                $hits[$i + 1] = trim($line);
            }
        }

        return $hits;
    }
}
