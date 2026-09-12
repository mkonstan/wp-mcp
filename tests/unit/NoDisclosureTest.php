<?php
/**
 * A throwable's message never leaves the boundary. Enforced by grep, because nothing
 * else can enforce it.
 *
 * WHAT THIS DEFENDS. Sprint 3 replaced every ad-hoc "report what went wrong" path with
 * one catch-all: a generic -32603, message "Internal error", an eight-hex trace id, and
 * the whole throwable in wp-content/wpmcp/trace.log. That holds exactly as long as
 * nobody adds `$e->getMessage()` back into the request path - and the next person to do
 * it will be trying to be helpful, in a catch block, with a tool that is failing in
 * production and no log access. A runtime test cannot see that coming: it would have to
 * provoke the specific new failure. A grep can.
 *
 * `->getMessage()` IS THE WHOLE SIGNATURE, and deliberately a blunt one. It is the only
 * way to get a throwable's message in PHP, it is three characters longer than anything
 * that would hide it, and a false positive is fixed by moving the call into trace.php -
 * which is where it belongs anyway. WP_Error's `get_error_message()` is NOT this: a
 * tool's own WP_Error is a sentence its author wrote for the caller, the whole point of
 * the `wpmcp_` prefix allow-list in endpoint.php, and it stays.
 *
 * THE DETECTOR IS ITSELF TESTED, twice: against a synthetic string that must match, and
 * against trace.php, which must match because that is where the one legitimate call
 * lives. Without those two, a typo'd pattern would make this file a green no-op -
 * exactly the "test that cannot fail" the build plan forbids.
 *
 * @group sprint-3
 */

declare(strict_types=1);

namespace WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class NoDisclosureTest extends TestCase
{
    /** Files on the request path. No throwable message may be read in any of them. */
    private const GUARDED = ['endpoint.php', 'tools.php'];

    /** The one file allowed to read one, because it writes it to the private log. */
    private const LOGGER = 'trace.php';

    /**
     * `->getMessage()` with any amount of whitespace around the arrow or inside the
     * parentheses, so reformatting cannot slip one past.
     */
    private const PATTERN = '/->\s*getMessage\s*\(\s*\)/';

    /**
     * @group sprint-3
     */
    public function testNoThrowableMessageIsReadOnTheRequestPath(): void
    {
        foreach (self::GUARDED as $file) {
            $hits = self::hits($file);

            self::assertSame(
                [],
                $hits,
                $file . ' calls ->getMessage() on line(s) ' . implode(', ', array_keys($hits))
                . '. A throwable\'s message carries the class, the absolute file path and'
                . ' often the arguments, and everything in this file ends up on the wire.'
                . ' Move the call into ' . self::LOGGER . ' and put the trace id on the'
                . ' wire instead - see wpmcp_trace(). Lines: ' . implode(' | ', $hits)
            );
        }
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
        ];

        foreach ($planted as $line) {
            self::assertMatchesRegularExpression(
                self::PATTERN,
                $line,
                'The detector does not match ' . $line . ', so it would not catch it in'
                . ' endpoint.php or tools.php either.'
            );
        }

        // And it is not matching everything: WP_Error's accessor must survive.
        self::assertDoesNotMatchRegularExpression(
            self::PATTERN,
            '$result->get_error_message()',
            'The detector matches WP_Error::get_error_message(), which is an author\'s'
            . ' message written for the caller and is allowed on the wire.'
        );
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
