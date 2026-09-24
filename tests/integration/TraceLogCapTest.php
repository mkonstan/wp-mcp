<?php
/**
 * AN ID ISSUED AFTER THE LOG PASSED ITS CAP IS STILL RESOLVABLE (1.1.1, sprint LOG+FLOOR item 1).
 *
 * THE CLAIM THIS CLASS EXISTS FOR, END TO END, THROUGH THE REAL BOUNDARY. A tool throws; the
 * caller gets `Internal error (trace 1f53b972)` and is told to quote that id; the operator looks
 * it up in the private log. Capping that log puts those two facts in tension, because the file was
 * ALREADY over its cap when the id was issued - and if the cap cut the wrong end, the id the
 * caller is holding is the one entry that is gone. tests/unit/TraceCapTest.php proves the
 * mechanism on a temp file in milliseconds; this proves the whole path, over HTTP, against a real
 * site, with the id read off the wire and looked up in the file afterwards.
 *
 * IT DOES NOT TOUCH THE OPERATOR'S OWN LOG, and that is deliberate rather than polite. The log's
 * file name lives in the `wpmcp_trace_log_name` option (trace.php gives it a random one so its
 * URL cannot be guessed), so this class points the site at a THROWAWAY name for the duration,
 * lets the plugin create, fill and truncate that file, deletes it, and puts the real name back.
 * Truncating the real log would destroy the history the measurement in D24 came from, and would
 * make the test destructive on every developer machine it ever runs on.
 *
 * THE CAP IS FILTERED DOWN TO 64 KiB rather than the shipped 2 MiB, through the same
 * `wpmcp_trace_log_max_bytes` filter an operator would use. Writing two megabytes of synthetic
 * entries through wp-cli to prove an arithmetic property would add a minute to the tier for
 * nothing; what is under test is WHICH END is discarded, and that does not depend on the number.
 *
 * @group sprint-14d
 */

declare(strict_types=1);

namespace WpMcp\Tests\Integration;

use WpMcp\Tests\Support\Fixtures;
use WpMcp\Tests\Support\FixtureIntegrationTestCase;
use WpMcp\Tests\Support\MuPlugin;
use WpMcp\Tests\Support\TraceLog;
use WpMcp\Tests\Support\WpCli;

final class TraceLogCapTest extends FixtureIntegrationTestCase
{
    private const PLUGIN = 'trace-cap';

    /** The option trace.php keeps this site's log file name in. */
    private const NAME_OPTION = 'wpmcp_trace_log_name';

    /** The cap the fixture filters the shipped 2 MiB down to. */
    private const CAP = 65536;

    /** The trace id of the very first padded entry. It must NOT survive. */
    private const OLDEST = 'aaaa0001';

    private static int $userId = 0;
    private static string $token = '';

    /** The site's real log file name, put back in tearDown. */
    private static string $realName = '';

    /** The throwaway name the site writes to while this class runs. */
    private static string $tempName = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::requireSite();

        self::buildFixtures(self::build(...), self::destroy(...));
    }

    private static function build(): void
    {
        Fixtures::purge();

        // READ THE REAL NAME FIRST, and through the plugin's own accessor, so a site that has
        // never written a trace gets one generated and remembered rather than leaving '' here
        // and an empty option behind at the end.
        self::$realName = trim(WpCli::evaluate('echo wpmcp_trace_file_name();'));
        self::$tempName = 'trace-' . bin2hex(random_bytes(16)) . '.log';

        self::assertMatchesRegularExpression(
            '/^trace-[0-9a-f]{32}\.log$/',
            self::$realName,
            'The site did not give back a log file name of the shape trace.php validates, so'
            . ' putting it back afterwards would leave the option in a state the plugin replaces.'
        );

        self::point(self::$tempName);

        MuPlugin::drop(self::PLUGIN, self::source());

        self::$userId = Fixtures::createUser(Fixtures::name('cap-author'), 'author');
        self::$token  = Fixtures::mintToken('read', Fixtures::name('cap'), self::$userId);
    }

    public static function tearDownAfterClass(): void
    {
        self::destroy();

        parent::tearDownAfterClass();
    }

    private static function destroy(): void
    {
        MuPlugin::remove(self::PLUGIN);

        // The throwaway file goes before the option does, because deleting it needs the option
        // to still name it. tryEvaluate: a teardown must not abandon the rest of itself.
        if (self::$tempName !== '') {
            WpCli::tryEvaluate(
                '$f = wpmcp_trace_dir() . "/" . ' . self::phpString(self::$tempName) . ';'
                . ' echo is_file($f) ? (int) unlink($f) : 1;'
            );
        }

        if (self::$realName !== '') {
            self::point(self::$realName);
            self::$realName = '';
        }

        self::$tempName = '';

        Fixtures::deleteUser(self::$userId);
        Fixtures::deleteTokensLabelled(Fixtures::name('cap'));
        Fixtures::purge();
    }

    /** Point the site's trace log at $name. */
    private static function point(string $name): void
    {
        WpCli::evaluate(sprintf(
            'echo (int) update_option(%s, %s);',
            self::phpString(self::NAME_OPTION),
            self::phpString($name)
        ));
    }

    private static function tool(): string
    {
        return Fixtures::name('cap-throw');
    }

    /**
     * The control, and it is two controls in one: the fixture's filter reached the site, and the
     * site is writing to the throwaway file rather than to the operator's.
     *
     * Without the first, every assertion below would be about a 2 MiB cap that 70 KiB of padding
     * never reaches - the log would simply grow and nothing would be truncated, which is the
     * pre-1.1.1 behaviour passing as the post-1.1.1 one. Without the second the class would be
     * quietly destroying the real log.
     *
     * @group sprint-14d
     */
    public function testTheFixtureFilteredTheCapAndTheSiteIsOnAThrowawayLog(): void
    {
        self::assertSame(
            (string) self::CAP,
            trim(WpCli::evaluate('echo wpmcp_trace_log_max_bytes();')),
            'The site does not report the filtered cap, so either the mu-plugin is not loaded or'
            . ' the cap is not filterable.'
        );

        self::assertSame(
            self::$tempName,
            trim(WpCli::evaluate('echo wpmcp_trace_file_name();')),
            'The site is not writing to the throwaway log, which means this class is about to'
            . ' truncate the real one.'
        );
    }

    /**
     * THE ONE THAT MATTERS. Push the file past the cap, then break something, then look the
     * brand-new id up in the file.
     *
     * The order is the point. The padding goes in FIRST, so the file is already over its cap at
     * the moment the boundary generates the id and hands it to the caller. A cap that discarded
     * the newest entries would pass every other test in this sprint and fail exactly here.
     *
     * @group sprint-14d
     */
    public function testATraceIdIssuedAfterTheFilePassedTheCapIsStillInTheLog(): void
    {
        $padded = self::pad();

        self::assertGreaterThan(
            self::CAP,
            $padded,
            'The padding did not put the log over its cap, so nothing below writes PAST the cap'
            . ' and the test would pass on a plugin that never truncates.'
        );
        self::assertStringContainsString(
            'trace=' . self::OLDEST . ' ',
            TraceLog::contents(),
            'The sentinel oldest entry is not in the padded file, so "it is gone afterwards"'
            . ' would prove nothing.'
        );

        $traceId = $this->breakSomething();
        $size    = TraceLog::size();

        self::assertLessThanOrEqual(
            self::CAP,
            $size,
            'The log is still over its cap after a write, so the cap is not enforced on write at'
            . " all and the file goes on growing exactly as it did before. Size: {$size}"
        );

        $entry = TraceLog::entry($traceId);

        self::assertStringContainsString(
            'trace=' . $traceId . ' ',
            $entry,
            'THE TRACE ID THE CALLER WAS JUST HANDED IS NOT IN THE LOG. The file was over its cap'
            . ' when the id was issued, so the cap discarded the entry it had just written - which'
            . ' is worse than no cap: the boundary tells the caller to quote this id to the'
            . " operator and the operator finds nothing. Log is {$size} bytes."
        );
        self::assertStringContainsString(
            'class=RuntimeException',
            $entry,
            'The entry is in the log but is not WHOLE - a cut that lands inside an entry takes'
            . " its class, message, file:line and stack with it. Entry: {$entry}"
        );

        self::assertStringNotContainsString(
            'trace=' . self::OLDEST . ' ',
            TraceLog::contents(),
            'The file came down under its cap with the OLDEST entry still in it, so something'
            . ' other than the oldest was thrown away.'
        );
    }

    /**
     * And the file is still a log afterwards: the next failure appends to it and is findable.
     *
     * THE FAILURE THIS CATCHES is a truncation that leaves the handle or the file in a state the
     * next append cannot use - a stale offset writing at the old end and leaving a hole of NUL
     * bytes, a lost lock, a file whose first entry is now a fragment that swallows the next one.
     * Each of those makes the FIRST id resolvable and the SECOND one not, which is a worse bug
     * than no cap because it only appears on the second failure after a truncation.
     *
     * @group sprint-14d
     */
    public function testTheLogStillTakesEntriesAfterItHasBeenTruncated(): void
    {
        self::pad();

        $first  = $this->breakSomething();
        $second = $this->breakSomething();

        self::assertNotSame($first, $second, 'Two failures were given the same trace id.');

        foreach (['first' => $first, 'second' => $second] as $which => $id) {
            self::assertStringContainsString(
                'trace=' . $id . ' ',
                TraceLog::entry($id),
                "The {$which} trace after the truncation is not in the log."
            );
        }

        $contents = TraceLog::contents();

        self::assertStringNotContainsString(
            "\0",
            $contents,
            'The log contains NUL bytes, which is what an append at a stale offset past the new'
            . ' end of a truncated file leaves behind.'
        );
        self::assertLessThanOrEqual(self::CAP, strlen($contents));
    }

    /**
     * A truncated file says so, at the top, in the log's own shape - and never as a `trace=`.
     *
     * An operator who opens a log that is suddenly shorter than yesterday's needs to be able to
     * tell "the plugin trimmed it" from "something ate it" without asking anybody. The marker is
     * an entry header at column 0 with an indented explanation under it, which is exactly the
     * shape every other entry has, so no reader has to learn a second format - and it carries no
     * `trace=` field, so `grep trace=` can never return it.
     *
     * @group sprint-14d
     */
    public function testTheTruncatedLogSaysItWasTruncatedAndDoesNotReadAsCorruption(): void
    {
        self::pad();
        $this->breakSomething();

        $contents = TraceLog::contents();
        $first    = (string) strtok($contents, "\n");

        self::assertStringContainsString('truncated=1', $first, 'First line: ' . $first);
        self::assertStringContainsString('cap=' . self::CAP, $first);
        self::assertStringNotContainsString('trace=', $first);

        // And nothing at column 0 is anything but an entry header, so the file does not begin
        // with a run of orphan stack frames belonging to an entry that is no longer there.
        foreach (explode("\n", rtrim($contents, "\n")) as $i => $line) {
            $line = rtrim($line, "\r");

            if ($line === '' || $line[0] === ' ') {
                continue;
            }

            self::assertMatchesRegularExpression(
                '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d[+-]\d\d:\d\d /',
                $line,
                'Line ' . ($i + 1) . ' is at column 0 and is not an entry header, so the cut did'
                . ' not land on an entry boundary: ' . $line
            );
        }
    }

    /**
     * Fill the log past the cap with synthetic entries in the log's real shape, oldest first.
     *
     * WRITTEN THROUGH `wp eval`, like everything else that touches the site's filesystem: in CI
     * WordPress is inside a container whose wp-content this runner cannot reach. The payload
     * travels base64-encoded so no quote, backslash or newline in it has to survive wp-env's
     * re-quoting.
     *
     * Returns the log's size afterwards.
     */
    private static function pad(): int
    {
        $sentinel = self::syntheticEntry(self::OLDEST);
        $filler   = self::syntheticEntry('bbbb0002');
        $repeats  = (int) ceil((self::CAP + 4096) / strlen($filler));

        WpCli::evaluate(
            '$s = base64_decode(' . self::phpString(base64_encode($sentinel)) . ');'
            . ' $f = base64_decode(' . self::phpString(base64_encode($filler)) . ');'
            . ' wpmcp_trace_ensure_dir();'
            . ' echo (int) file_put_contents(wpmcp_trace_path(), $s . str_repeat($f, ' . $repeats . '),'
            . ' FILE_APPEND | LOCK_EX);'
        );

        return TraceLog::size();
    }

    /** One entry of about the measured 2.3 KB mean, in the log's own shape. */
    private static function syntheticEntry(string $id): string
    {
        $header = gmdate('c') . ' trace=' . $id . ' method=tools/call tool=wpmcp-test-padding'
            . ' user=0 token=0 class=RuntimeException message=synthetic padding, not a real failure'
            . ' at=/wpmcp/padding.php:1' . "\n";

        $frame = '    #0 /wpmcp/padding.php(1): wpmcp_padding(array{a,b}, string(4))' . "\n";

        return $header . str_repeat($frame, 30) . '    #30 {main}' . "\n";
    }

    /** Call the throwing fixture tool and return the trace id the boundary issued. */
    private function breakSomething(): string
    {
        $response = $this->mcp(self::$token)->post('tools/call', [
            'name'      => self::tool(),
            'arguments' => [],
        ]);

        $raw  = (string) $response->getBody();
        $body = json_decode($raw, true);

        self::assertIsArray($body, 'Not JSON: ' . $raw);
        self::assertSame(-32603, $body['error']['code'] ?? null, $raw);

        $traceId = (string) ($body['error']['data']['trace_id'] ?? '');

        self::assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $traceId, $raw);

        return $traceId;
    }

    /** A tool that throws, plus the cap filtered down to something a test can reach. */
    private static function source(): string
    {
        $tool = self::tool();
        $cap  = self::CAP;

        return <<<PHP
add_filter('wpmcp_trace_log_max_bytes', static function (\$bytes) { return {$cap}; });

add_filter('wpmcp_tools', static function (\$tools) {
    \$tools['{$tool}'] = array(
        'write'       => false,
        'annotations' => array(
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ),
        'inputSchema' => array('type' => 'object', 'properties' => new stdClass()),
        'description' => 'wp-mcp test fixture: throws, so the boundary issues a trace id.',
        'run'         => static function (\$a) {
            throw new RuntimeException('wpmcp fixture: a throw whose trace id must survive the cap');
        },
    );

    return \$tools;
});
PHP;
    }

    /** A PHP single-quoted literal for a `wp eval` snippet. */
    private static function phpString(string $value): string
    {
        return "'" . addcslashes($value, "'\\") . "'";
    }
}
