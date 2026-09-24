<?php
/**
 * THE TRACE LOG'S SIZE CAP, AND THE ONE DIRECTION IT MUST CUT IN (1.1.1, sprint LOG+FLOOR item 1).
 *
 * MEASURED FIRST, 2026-09-24, on the two development sites: 1,743,937 bytes over 763 entries and
 * 1,504,358 over 660, which is a mean entry of 2,283 bytes and eleven days of suite runs. Nothing
 * rotated, truncated or aged any of it out, and on a customer host the same file only grows. So a
 * cap, and D24 chose truncation over rotate-N and age-out.
 *
 * THE CONSTRAINT THAT DECIDES THE DESIGN IS NOT THE CAP. The boundary hands a caller an eight-hex
 * trace id and tells it to quote that id to the operator. **A cap that discarded the NEWEST
 * entries would be worse than no cap at all** - it would throw away precisely the entry somebody
 * is about to ask about, and it would do it silently. So the newest survives and the oldest goes,
 * and that is what these tests are about. The end-to-end half of the same claim - an id issued
 * over HTTP AFTER the file passed the cap is still resolvable in the file - is
 * tests/integration/TraceLogCapTest.php; this one holds the mechanism, in milliseconds, on a
 * temp file.
 *
 * WHY THE MECHANISM IS A HANDLE FUNCTION AND NOT A PATH ONE. wpmcp_trace_cap_file() takes the
 * handle the append already opened and locked, so enforcing the cap costs no second open, no
 * second lock and no path resolution - and it makes the mechanism callable here without a
 * WP_CONTENT_DIR, which a unit test has no business inventing.
 *
 * @group sprint-14d
 */

declare(strict_types=1);

namespace WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WpMcp\Tests\Support\WordPressRuntime;
use WpMcp\Tests\Support\WordPressStubs;

final class TraceCapTest extends TestCase
{
    /** The measured mean entry on the two development sites, to the byte. */
    private const MEASURED_MEAN_ENTRY = 2283;

    private string $path = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        WordPressStubs::loadPlugin();
    }

    protected function setUp(): void
    {
        parent::setUp();

        WordPressRuntime::install();

        $this->path = sys_get_temp_dir() . '/wpmcp-trace-cap-' . bin2hex(random_bytes(6)) . '.log';
    }

    protected function tearDown(): void
    {
        if ($this->path !== '' && is_file($this->path)) {
            @unlink($this->path);
        }

        parent::tearDown();
    }

    /**
     * THE TEST THIS ITEM EXISTS FOR: writing past the cap keeps the newest and drops the oldest.
     *
     * The entry written last is the one a caller is holding an id for. It is asserted present
     * WHOLE - header line and indented stack together - because half an entry is an entry whose
     * `at=` and stack are gone, which is most of what an operator needs.
     *
     * @group sprint-14d
     */
    public function testTheNEWESTEntrySurvivesAndTheOldestGo(): void
    {
        $cap     = 65536;
        $entries = $this->fill($cap * 2, 'old');
        $newest  = $this->entry('newest', 1);

        file_put_contents($this->path, implode('', $entries) . $newest);

        $removed = $this->cap($cap, strlen($newest));
        $after   = (string) file_get_contents($this->path);

        self::assertGreaterThan(0, $removed, 'A file at twice the cap was left alone.');
        self::assertLessThanOrEqual(
            $cap,
            strlen($after),
            'The log is still over its cap after the truncation, so the cap is not one.'
        );

        self::assertStringContainsString(
            $newest,
            $after,
            'THE ENTRY JUST WRITTEN IS GONE, OR IS NO LONGER WHOLE. That is the entry whose trace'
            . ' id the caller was handed one millisecond ago, and a cap that discards it is worse'
            . ' than no cap: the id is quoted to the operator and resolves to nothing.'
        );
        self::assertStringNotContainsString(
            'trace=old00001 ',
            $after,
            'The OLDEST entry is still there while the file came down under its cap, so something'
            . ' other than the oldest was thrown away.'
        );
    }

    /**
     * Every line at column 0 is an entry header, so the file never begins part-way through one.
     *
     * THE READER IS THE REASON. An entry is a header line at column 0 plus its stack indented
     * four spaces under it, and every consumer - `grep trace=<id>`, a log shipper splitting on
     * `key=value`, tests/Support/TraceLog::entry() - keys on exactly that. A cut at an arbitrary
     * byte leaves a headless run of indented frames at the top of the file, which reads as a
     * corrupted file and attaches orphan frames to whatever entry comes next.
     *
     * @group sprint-14d
     */
    public function testTheCutLandsOnAnEntryBoundaryAndNeverPartWayThroughOne(): void
    {
        $cap = 40960;

        file_put_contents($this->path, implode('', $this->fill($cap * 3, 'old')));

        self::assertGreaterThan(0, $this->cap($cap, self::MEASURED_MEAN_ENTRY));

        $lines = explode("\n", rtrim((string) file_get_contents($this->path), "\n"));

        foreach ($lines as $i => $line) {
            if ($line === '' || $line[0] === ' ') {
                continue; // an indented stack frame, which belongs to the header above it
            }

            self::assertMatchesRegularExpression(
                '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d[+-]\d\d:\d\d /',
                $line,
                'Line ' . ($i + 1) . ' starts at column 0 and is not an ISO timestamp, so the'
                . ' file has something at the top of it that is neither an entry nor part of'
                . ' one: ' . $line
            );
        }
    }

    /**
     * A truncated file SAYS it was truncated, on its first line, and does not look like damage.
     *
     * An operator meeting a log that is suddenly shorter has two candidate explanations - the
     * plugin trimmed it, or something ate it - and only one of them is worth a support ticket.
     * The marker is also deliberately NOT a trace entry: it carries no `trace=` field, so
     * `grep trace=` can never return it and no id can ever collide with it.
     *
     * @group sprint-14d
     */
    public function testATruncatedFileAnnouncesItselfAndIsNotMistakableForAnEntry(): void
    {
        $cap = 65536;

        file_put_contents($this->path, implode('', $this->fill($cap * 2, 'old')));

        $removed = $this->cap($cap, self::MEASURED_MEAN_ENTRY);
        $after   = (string) file_get_contents($this->path);
        $first   = strtok($after, "\n");

        self::assertStringContainsString('truncated=1', (string) $first, $after ? $first : '(empty)');
        self::assertStringContainsString('cap=' . $cap, (string) $first);
        self::assertStringContainsString('removed=' . $removed, (string) $first);
        self::assertStringNotContainsString(
            'trace=',
            (string) $first,
            'The truncation marker carries a `trace=` field, so `grep trace=<id>` can return it'
            . ' and an id could in principle collide with whatever it names.'
        );

        self::assertMatchesRegularExpression(
            '/\n {4}\S/',
            $after,
            'The marker has no indented explanation under it, so the one line an operator reads'
            . ' first says `truncated=1` and nothing about who did it or why.'
        );
        self::assertMatchesRegularExpression(
            '/oldest/i',
            $after,
            'Nothing in the file says which END was discarded, which is the single fact that'
            . ' tells an operator whether the id in front of them can still be looked up.'
        );
    }

    /**
     * Under the cap, nothing happens at all - not a rewrite, not a marker, not a byte.
     *
     * This is the common case: the cap is enforced on every traced failure and fires on one in
     * a few hundred of them. A version that rewrote the file every time would move a megabyte
     * per failure and would put a truncation marker in the middle of a log that was never over.
     *
     * @group sprint-14d
     */
    public function testAFileUnderTheCapIsNotTouched(): void
    {
        $before = implode('', $this->fill(20000, 'old'));

        file_put_contents($this->path, $before);

        self::assertSame(0, $this->cap(65536, self::MEASURED_MEAN_ENTRY));
        self::assertSame(
            $before,
            (string) file_get_contents($this->path),
            'A log under its cap was rewritten anyway.'
        );
    }

    /**
     * One entry bigger than the whole keep window: the newest still survives.
     *
     * THE CASE THAT WOULD OTHERWISE LOSE IT. Every field of an entry is bounded at 2,000
     * characters, but the STACK is not - a runaway recursion produces thousands of frames - so an
     * entry larger than three quarters of the cap is possible. Searching the keep window for the
     * first entry header then finds none, and the honest answer is to keep exactly the entry just
     * written rather than to keep a fragment of it or nothing.
     *
     * @group sprint-14d
     */
    public function testAnEntryLargerThanTheKeepWindowIsStillTheOneThatSurvives(): void
    {
        $cap    = 65536;
        $newest = $this->entry('huge', 1, 60000);

        file_put_contents($this->path, implode('', $this->fill($cap, 'old')) . $newest);

        self::assertGreaterThan(0, $this->cap($cap, strlen($newest)));

        $after = (string) file_get_contents($this->path);

        self::assertStringContainsString(
            'trace=huge0001 ',
            $after,
            'An entry too big for the keep window was discarded, and it is the newest one.'
        );
        self::assertStringNotContainsString('trace=old00001 ', $after);
    }

    /**
     * The cap is 2 MiB by default, filterable, and refuses a value that would defeat it.
     *
     * TWO MEBIBYTES, AND THE NUMBER IS THE MEASUREMENT. At the measured 2,283-byte mean entry it
     * holds about 900 traced failures; the two development sites reached 1.7 MB and 1.5 MB in
     * eleven days of continuous suite runs, so a real site producing traces at that rate has a
     * bug storm and still keeps a week of them. It is also just above both measured files, so
     * upgrading to 1.1.1 does not itself throw away anybody's current log.
     *
     * A FILTER RATHER THAN A CONSTANT, following wpmcp_file_versions_keep: an operator on a busy
     * host can raise it without patching the plugin. A useless value is ignored rather than
     * obeyed, for the same reason "keep zero versions" is - a cap below one entry's worth would
     * make every write truncate the entry it had just written.
     *
     * @group sprint-14d
     */
    public function testTheCapIsTwoMebibytesAndAFilterCanMoveItButNotBreakIt(): void
    {
        self::assertSame(2097152, \wpmcp_trace_log_max_bytes());

        WordPressRuntime::addFilter('wpmcp_trace_log_max_bytes', static fn ($bytes) => 500000);

        self::assertSame(500000, \wpmcp_trace_log_max_bytes());

        foreach ([0, -1, 1024, 'nonsense'] as $useless) {
            WordPressRuntime::addFilter('wpmcp_trace_log_max_bytes', static fn ($b) => $useless);

            self::assertSame(
                2097152,
                \wpmcp_trace_log_max_bytes(),
                'A filter returning ' . var_export($useless, true) . ' was obeyed. A cap below one'
                . " entry's worth truncates the entry that was just written, which is the one"
                . ' outcome this whole item exists to prevent.'
            );
        }
    }

    /** Enforce the cap on the temp file the way wpmcp_trace_append() does. */
    private function cap(int $max, int $newest): int
    {
        $handle = fopen($this->path, 'ab+');

        self::assertNotFalse($handle, 'Could not open ' . $this->path);

        $stat    = fstat($handle);
        $removed = \wpmcp_trace_cap_file($handle, (int) $stat['size'], $max, $newest);

        fclose($handle);

        return $removed;
    }

    /**
     * Entries, oldest first, until they total at least $bytes.
     *
     * @return list<string>
     */
    private function fill(int $bytes, string $prefix): array
    {
        $out  = [];
        $size = 0;

        for ($i = 1; $size < $bytes; $i++) {
            $entry = $this->entry($prefix, $i);
            $out[] = $entry;
            $size += strlen($entry);
        }

        return $out;
    }

    /** One entry in the log's real shape: a header at column 0, its stack indented under it. */
    private function entry(string $prefix, int $n, int $bytes = self::MEASURED_MEAN_ENTRY): string
    {
        $id = substr($prefix . str_pad((string) $n, 8 - strlen($prefix), '0', STR_PAD_LEFT), 0, 8);

        $header = gmdate('c') . ' trace=' . $id . ' method=tools/call tool=wpmcp-test'
            . ' user=1 token=1 class=RuntimeException message=a synthetic entry'
            . ' at=/wpmcp/test.php:1' . "\n";

        $frame = '    #0 /wpmcp/test.php(1): wpmcp_test(array{a,b}, string(4))' . "\n";
        $stack = '';

        while (strlen($header) + strlen($stack) + strlen($frame) < $bytes) {
            $stack .= $frame;
        }

        return $header . $stack . '    #' . $n . ' {main}' . "\n";
    }
}
