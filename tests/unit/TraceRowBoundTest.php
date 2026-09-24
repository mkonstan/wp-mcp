<?php
/**
 * A trace ROW has a ceiling, the sweep's DELETEs are bounded, and a file the upgrade could not
 * remove reaches the operator.
 *
 * WHY THIS CLASS EXISTS, AND IT IS A CORRECTION. Round 1 of this sprint capped the trace table
 * at 2,000 ROWS and then wrote in the changelog, the README and ARCHITECTURE that 2,000 rows is
 * "about 4.6 MB, the hard ceiling this feature adds to a backup". 4.6 MB is 2,000 times the
 * measured MEAN entry, and a mean is not a ceiling: `stack` is a `longtext` bounded only in
 * FRAMES, so the runaway recursion a frame cap exists for wrote 40-400 KB rows and the real
 * worst case at the cap was 80-800 MB. **A count cap over a variable-width row is not a size
 * cap.** Every field now carries a BYTE cap keyed to its own column, so the arithmetic in the
 * docs is a maximum and this class is what keeps it one.
 *
 * THE CAPS ARE CHECKED AGAINST THE SCHEMA, not listed here, so a column that is widened and a
 * cap that is not cannot drift apart silently - the caps are the only thing making the ceiling
 * true, so they are the thing that must not rot.
 *
 * AND WHAT THIS CLASS DELIBERATELY DOES NOT CLAIM. The first version of round 2 justified the
 * caps with "a value over its column refuses the INSERT in strict mode", which is false on
 * WordPress: `wpdb::set_sql_mode()` REMOVES `STRICT_TRANS_TABLES` from the session on every
 * connection, and both development sites report
 * `NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION` (MEASURED 2026-09-24).
 * An over-wide value is TRUNCATED with a warning. So the caps are here for the ceiling, and
 * because a cut this code makes ends in `...` while MySQL's arrives looking complete - the same
 * shape of unchecked justification that put the wrong ceiling in three shipped files.
 *
 * @group sprint-14d
 */

declare(strict_types=1);

namespace WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use WpMcp\Tests\Support\WordPressRuntime;
use WpMcp\Tests\Support\WordPressStubs;

final class TraceRowBoundTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        WordPressStubs::loadPlugin();
    }

    protected function setUp(): void
    {
        WordPressRuntime::install();
    }

    /* ------------------------------------------------------------------
     * The row's ceiling
     * ---------------------------------------------------------------- */

    /**
     * Every per-column cap equals the width of the column it writes to, read out of the
     * plugin's own CREATE TABLE.
     *
     * @group sprint-14d
     */
    public function testEveryCapMatchesItsColumnWidth(): void
    {
        $widths = self::traceColumnWidths();

        foreach ([
            'method' => WPMCP_TRACE_METHOD_BYTES,
            'tool'   => WPMCP_TRACE_TOOL_BYTES,
            'class'  => WPMCP_TRACE_CLASS_BYTES,
            'at'     => WPMCP_TRACE_AT_BYTES,
        ] as $column => $cap) {
            self::assertArrayHasKey(
                $column,
                $widths,
                "The scan found no varchar width for `{$column}`, so it is reading the CREATE"
                . ' TABLE wrongly and would not notice a drift either.'
            );
            self::assertSame(
                $widths[$column],
                (int) $cap,
                "The cap on `{$column}` is {$cap} but the column is varchar({$widths[$column]})."
                . ' A value between the two is cut by MySQL instead of by us - silently, with no'
                . ' `...` to say so - and the per-row ceiling the documentation states stops'
                . ' being the sum of these caps.'
            );
        }
    }

    /**
     * The stated ceiling is the sum of the caps, so the number in the docs has a test behind it.
     *
     * 12,960 bytes appears in CHANGELOG.md, README.md, ARCHITECTURE.md and two docblocks. It is
     * the one number this sprint got wrong once already.
     *
     * @group sprint-14d
     */
    public function testTheStatedRowCeilingIsTheSumOfTheCaps(): void
    {
        $ceiling = 8   // trace_id char(8)
            + 19       // logged_at datetime
            + (int) WPMCP_TRACE_METHOD_BYTES
            + (int) WPMCP_TRACE_TOOL_BYTES
            + 20       // user_id, as wide as bigint prints
            + 20       // token_id
            + (int) WPMCP_TRACE_CLASS_BYTES
            + (int) WPMCP_TRACE_TEXT_BYTES   // message
            + (int) WPMCP_TRACE_AT_BYTES
            + (int) WPMCP_TRACE_TEXT_BYTES   // data
            + (int) WPMCP_TRACE_STACK_BYTES;

        self::assertSame(
            12960,
            $ceiling,
            'The per-row ceiling is no longer 12,960 bytes, which is the number CHANGELOG.md,'
            . ' README.md and ARCHITECTURE.md all quote as what 2,000 rows can cost a backup.'
            . ' Move the caps and move the documented arithmetic with them.'
        );

        // And the sentence the docs actually make: 2,000 rows is under 26 MB.
        self::assertLessThan(
            26 * 1000 * 1000,
            $ceiling * (int) WPMCP_TRACE_KEEP_ROWS,
            'The row cap times the row ceiling is over the 26 MB the documentation promises.'
        );
    }

    /**
     * A field longer than its cap is cut TO the cap, ellipsis included - not to the cap plus
     * three, which would still be too long for the column.
     *
     * @group sprint-14d
     */
    public function testAFieldIsCutToItsCapIncludingTheEllipsis(): void
    {
        $long = str_repeat('m', 500);

        $cut = wpmcp_trace_field($long, WPMCP_TRACE_METHOD_BYTES);

        self::assertSame(
            (int) WPMCP_TRACE_METHOD_BYTES,
            strlen($cut),
            'The cut value is longer than the column, so the `...` was added PAST the cap.'
        );
        self::assertStringEndsWith('...', $cut, 'A truncated field does not say it was cut.');

        // Newlines still go, because the rendered entry is one line per event. A CRLF becomes
        // TWO spaces - both characters are replaced rather than the pair collapsed - which is
        // recorded here as the shipped behaviour rather than changed: this is an opaque diagnostic
        // string, and collapsing runs of whitespace is the first step towards parsing it.
        self::assertSame(
            'a b  c',
            wpmcp_trace_field("a\nb\r\nc", 64),
            'Newlines are no longer flattened, so one event can become several log lines.'
        );

        // A value inside its cap is untouched.
        self::assertSame('tools/call', wpmcp_trace_field('tools/call', WPMCP_TRACE_METHOD_BYTES));
    }

    /* ------------------------------------------------------------------
     * The stack: two bounds, and the one that bounds bytes
     * ---------------------------------------------------------------- */

    /**
     * A pathological stack is held under WPMCP_TRACE_STACK_BYTES, keeps both ENDS, and says
     * what it dropped.
     *
     * @group sprint-14d
     */
    public function testTheStackIsHeldUnderItsByteCapAndKeepsBothEnds(): void
    {
        // Each line is far too wide to be a real frame, which is the point: the frame cap
        // cannot bound this and only a byte cap can.
        $lines = [];

        for ($i = 0; $i < 200; $i++) {
            $lines[] = '#' . $i . ' ' . str_repeat('x', 2000) . '(): frame' . $i;
        }

        $lines[] = '#200 {main}';

        $fitted = wpmcp_trace_stack_fit($lines);
        $bytes  = strlen(implode("\n", $fitted));

        self::assertLessThanOrEqual(
            (int) WPMCP_TRACE_STACK_BYTES,
            $bytes,
            'The stack is ' . $bytes . ' bytes, over the ' . WPMCP_TRACE_STACK_BYTES
            . '-byte cap. 2,000 rows of that is what made the documented ceiling a fiction.'
        );

        self::assertStringContainsString(
            'frame0',
            $fitted[0],
            'The INNERMOST frame did not survive. It is where the failure is.'
        );
        self::assertSame(
            '#200 {main}',
            $fitted[count($fitted) - 1],
            'The `{main}` sentinel did not survive, so a reader cannot tell a stack that reached'
            . ' the bottom from one that was cut off there.'
        );

        $note = implode("\n", $fitted);

        self::assertStringContainsString(
            'frames omitted',
            $note,
            'The stack was shortened and does not say so, so a partial stack reads as a complete'
            . ' one.'
        );
        self::assertStringContainsString(
            'stack cap',
            $note,
            'The note does not say WHY frames went, so an operator cannot tell a byte cap from a'
            . ' frame cap.'
        );
        self::assertStringContainsString(
            'bytes dropped',
            $note,
            'The note says the bytes were "over" the cap. They were REMOVED, and the two are'
            . ' different numbers whenever the surviving ends do not fill the budget - so the'
            . ' word has to name what went, not by how much the cap was exceeded.'
        );
        self::assertStringNotContainsString(
            'trace=',
            $note,
            'The note carries a `trace=` field, so a grep by id can return the marker.'
        );
    }

    /**
     * A stack already inside the budget is returned untouched - no note, no reordering.
     *
     * @group sprint-14d
     */
    public function testAnOrdinaryStackIsNotTouched(): void
    {
        $lines = ['#0 /a/b.php(3): f(int)', '#1 /a/c.php(9): g(string(4))', '#2 {main}'];

        self::assertSame($lines, wpmcp_trace_stack_fit($lines));
    }

    /**
     * Across the whole boundary - just under the cap to just over it - the stack is either
     * returned untouched or says truthfully what it dropped. Never "0 frames omitted".
     *
     * A MARKER READING "0 FRAMES OMITTED" IS WORSE THAN NO MARKER: it tells an operator the stack
     * is incomplete when it is whole. Round 3 also fixed the measurement this sweep depends on -
     * `implode` puts count-1 separators in, not count, and round 2 counted one byte too many, which
     * is harmless in the middle of the range and exactly wrong at its edge.
     *
     * @group sprint-14d
     */
    public function testNoSizeNearTheCapProducesAnEmptyOrUntruthfulMarker(): void
    {
        $budget = (int) WPMCP_TRACE_STACK_BYTES;

        for ($total = $budget - 200; $total <= $budget + 200; $total++) {
            // Three lines whose imploded length is exactly $total: two separators, so the body
            // must come to $total - 2.
            $body  = $total - 2 - strlen('#0 a') - strlen('#2 {main}');
            $lines = ['#0 a', '#1 ' . str_repeat('b', max(0, $body - 3)), '#2 {main}'];

            $fitted = wpmcp_trace_stack_fit($lines);
            $out    = implode("\n", $fitted);

            self::assertStringNotContainsString(
                '0 frames omitted',
                $out,
                'A stack of ' . strlen(implode("\n", $lines)) . ' bytes against a ' . $budget
                . '-byte cap produced a marker claiming nothing was dropped.'
            );

            self::assertLessThanOrEqual(
                $budget,
                strlen($out),
                'A stack of ' . strlen(implode("\n", $lines)) . ' bytes came back at '
                . strlen($out) . ' bytes, over the cap.'
            );

            if (strlen(implode("\n", $lines)) <= $budget) {
                self::assertSame(
                    $lines,
                    $fitted,
                    'A stack already inside the cap was rewritten anyway.'
                );
            }
        }
    }

    /**
     * One frame bigger than the whole budget is CUT and says so, rather than being dropped.
     *
     * The file:line is in another column, but the frame is the only thing that says which call
     * it was - so an empty stack would be worse than a cut one.
     *
     * @group sprint-14d
     */
    public function testASingleOversizedFrameIsCutRatherThanDropped(): void
    {
        $huge   = '#0 ' . str_repeat('y', 20000) . '()';
        $fitted = wpmcp_trace_stack_fit([$huge, '#1 {main}']);
        $bytes  = strlen(implode("\n", $fitted));

        self::assertLessThanOrEqual((int) WPMCP_TRACE_STACK_BYTES, $bytes);
        self::assertStringStartsWith('#0 ', $fitted[0]);

        // AND THE NUMBER IT REPORTS IS THE ONE IT DID, which round 2's wording was not: it said
        // the cap, 8192, while it actually cut at the cap less the reserved note. An operator can
        // check that number with `strlen`, so it has to be the number of bytes KEPT.
        $kept = (int) WPMCP_TRACE_STACK_BYTES - (int) WPMCP_TRACE_STACK_NOTE_BYTES;

        self::assertStringContainsString(
            'frame cut, ' . $kept . ' of ' . strlen($huge) . ' bytes kept',
            implode("\n", $fitted),
            'The single-frame marker does not name the bytes it actually kept: ' . $fitted[0]
        );
    }

    /**
     * And the REAL builder is bounded too, on a real deep throwable - both caps together.
     *
     * @group sprint-14d
     */
    public function testAThrowableFromADeepRecursionProducesABoundedStack(): void
    {
        try {
            self::recurse(400);
            self::fail('The fixture did not throw.');
        } catch (RuntimeException $e) {
            $stack = implode("\n", wpmcp_trace_stack($e));
        }

        self::assertLessThanOrEqual(
            (int) WPMCP_TRACE_STACK_BYTES,
            strlen($stack),
            'A 400-frame recursion produced a ' . strlen($stack) . '-byte stack column.'
        );
        self::assertStringContainsString('#0 ', $stack, 'The stack has no frames at all.');
        self::assertStringContainsString(
            'frames omitted',
            $stack,
            'A 400-frame stack under a 200-frame cap said nothing about what it dropped.'
        );
    }

    private static function recurse(int $depth): void
    {
        if ($depth <= 0) {
            throw new RuntimeException('wpmcp unit: a deep stack');
        }

        self::recurse($depth - 1);
    }

    /* ------------------------------------------------------------------
     * The sweep's bound
     * ---------------------------------------------------------------- */

    /**
     * The sweep runs its DELETE again while it removes a FULL batch, and stops when it does not.
     *
     * AN UNBOUNDED DELETE IS ONE TRANSACTION, and the site that needs the sweep is the site that
     * cannot afford it: a broken-cron site accumulates for a month and then deletes everything in
     * one statement, with a `longtext` per row in the undo log, inside an ordinary front-end
     * request.
     *
     * @group sprint-14d
     */
    public function testTheSweepDeletesInBatchesAndStopsOnAShortOne(): void
    {
        $wpdb = WordPressRuntime::install();
        $batch = (int) WPMCP_TRACE_SWEEP_BATCH;

        // Two full batches then a short one for the age cap; MAX(id) then answers 0 so the row
        // cap has nothing to do and the count below is the age cap's alone.
        $wpdb->queryReturns = [$batch, $batch, 12];
        $wpdb->defaultVar   = '0';

        $gone = wpmcp_trace_sweep();

        self::assertSame(
            2 * $batch + 12,
            $gone,
            'The sweep did not keep going while each DELETE removed a full batch, so a backlog'
            . ' is left behind for ever.'
        );

        $deletes = array_values(array_filter(
            $wpdb->queries,
            static fn ($q) => is_string($q) && str_starts_with($q, 'DELETE FROM')
        ));

        self::assertCount(3, $deletes, 'The sweep ran ' . count($deletes) . ' DELETEs, not three.');

        foreach ($deletes as $sql) {
            self::assertStringContainsString(
                'LIMIT ' . $batch,
                $sql,
                'A sweep DELETE carries no LIMIT, so it is one unbounded transaction: ' . $sql
            );
        }
    }

    /**
     * And the loop has an end even when every batch is full, so one cron run cannot become
     * unbounded work.
     *
     * @group sprint-14d
     */
    public function testTheSweepStopsAtItsRoundCap(): void
    {
        $wpdb = WordPressRuntime::install();
        $batch = (int) WPMCP_TRACE_SWEEP_BATCH;

        $wpdb->queryReturns = [$batch]; // the last entry repeats: always a full batch
        $wpdb->defaultVar   = '0';

        $gone = wpmcp_trace_sweep();

        self::assertSame(
            (int) WPMCP_TRACE_SWEEP_ROUNDS * $batch,
            $gone,
            'The sweep did not stop at ' . WPMCP_TRACE_SWEEP_ROUNDS . ' rounds, so a site with a'
            . ' month of backlog does all of it inside one request.'
        );
    }

    /**
     * The ROW pass gets its own, larger round cap - and that number is what decides whether the
     * documented 26 MB ceiling is true.
     *
     * A ROW CAP THAT REMOVES FEWER ROWS PER HOUR THAN ARRIVE IS A LAG, NOT A CAP. Round 2 gave both
     * passes 20 rounds, so the ceiling silently held only below 10,000 traced failures an hour -
     * about 2.8 a second, which is exactly where an AI client retry-looping against a throwing tool
     * at ~350 ms a call sits. The row pass is a PRIMARY KEY range delete, the cheapest shape there
     * is, so rounds are close to free and the cap is set by the rate worth defending.
     *
     * @group sprint-14d
     */
    public function testTheRowPassClearsFarMorePerSweepThanTheAgePass(): void
    {
        $wpdb  = WordPressRuntime::install();
        $batch = (int) WPMCP_TRACE_SWEEP_BATCH;

        // Always a full batch, and a MAX(id) high enough that the row cap has work to do.
        $wpdb->queryReturns = [$batch];
        $wpdb->vars         = ['SELECT MAX(id) FROM wp_wpmcp_traces' => '500000'];

        $gone = wpmcp_trace_sweep();

        self::assertSame(
            ((int) WPMCP_TRACE_SWEEP_ROUNDS + (int) WPMCP_TRACE_SWEEP_ROW_ROUNDS) * $batch,
            $gone,
            'The two passes did not each stop at their own round cap, so either the age pass is'
            . ' doing the row pass\'s work or the row pass is capped at the age pass\'s rounds.'
        );

        self::assertGreaterThanOrEqual(
            10,
            (int) WPMCP_TRACE_SWEEP_ROW_ROUNDS / (int) WPMCP_TRACE_SWEEP_ROUNDS,
            'The row pass no longer has ten times the age pass\'s rounds. It is the pass the'
            . ' ceiling depends on and it is the cheap one.'
        );
    }

    /**
     * The rate the ceiling holds to, as arithmetic rather than as a sentence in a README.
     *
     * The hourly sweep removes ROW_ROUNDS x BATCH rows. Above that rate more arrive than leave and
     * "2,000 rows" stops bounding anything - so the number the documentation states as the
     * condition on its own ceiling is asserted here.
     *
     * @group sprint-14d
     */
    public function testTheCeilingHoldsToTheRateTheDocumentationClaims(): void
    {
        $perSweep  = (int) WPMCP_TRACE_SWEEP_ROW_ROUNDS * (int) WPMCP_TRACE_SWEEP_BATCH;
        $perSecond = $perSweep / 3600;

        self::assertSame(
            100000,
            $perSweep,
            'The row pass no longer clears 100,000 rows an hour, which is the number README.md,'
            . ' CHANGELOG.md and ARCHITECTURE.md state as the condition on the 26 MB ceiling.'
        );

        self::assertGreaterThanOrEqual(
            27,
            (int) $perSecond,
            'The ceiling now holds only below ' . (int) $perSecond . ' traced failures a second.'
            . ' The documented figure is 27, and the rate to beat is an AI client in a retry loop'
            . ' at ~350 ms a call, which is ~2.8 a second.'
        );
    }

    /**
     * A query that FAILS stops the loop rather than being retried twenty times.
     *
     * @group sprint-14d
     */
    public function testAFailedDeleteStopsTheLoop(): void
    {
        $wpdb = WordPressRuntime::install();

        $wpdb->queryReturns = [false];
        $wpdb->defaultVar   = '0';

        self::assertSame(0, wpmcp_trace_sweep());

        $deletes = array_values(array_filter(
            $wpdb->queries,
            static fn ($q) => is_string($q) && str_starts_with($q, 'DELETE FROM')
        ));

        self::assertCount(
            1,
            $deletes,
            'A failing DELETE was retried. Twenty broken statements inside a front-end request is'
            . ' the cost the batching exists to avoid.'
        );
    }

    /* ------------------------------------------------------------------
     * A file the upgrade could not remove
     * ---------------------------------------------------------------- */

    /**
     * A failed removal is recorded and raises the notice; a clean one clears it.
     *
     * ROUND 1 SENT THIS ONLY TO THE PHP ERROR LOG, on the same commit that deleted the notice
     * machinery - so on the nginx host this whole revision exists for, the file survived, the
     * exposure persisted, and nobody was told.
     *
     * @group sprint-14d
     */
    public function testAFileTheUpgradeCouldNotRemoveReachesTheOperator(): void
    {
        WordPressRuntime::install();

        self::assertFalse(
            wpmcp_note_trace_file_left(['trace-abc.log'], '/var/www/wp-content/wpmcp'),
            'A failed removal reported success.'
        );
        self::assertSame(
            'trace-abc.log',
            WordPressRuntime::optionWrite(WPMCP_TRACE_FILE_OPTION),
            'What was left behind was not recorded, so the notice has nothing to print and the'
            . ' operator is never told the log is still public.'
        );

        WordPressRuntime::setOption(WPMCP_TRACE_FILE_OPTION, 'trace-abc.log');
        $GLOBALS['wpmcp_test_wp']['caps'] = ['manage_options'];

        ob_start();
        wpmcp_trace_file_notice();
        $html = (string) ob_get_clean();

        self::assertStringContainsString('notice-error', $html, 'The notice is not an error.');
        self::assertStringContainsString('trace-abc.log', $html, 'The notice does not name the file.');
        self::assertStringContainsString('nginx', $html, 'The notice does not say why it matters.');

        // And nobody without the capability sees it.
        $GLOBALS['wpmcp_test_wp']['caps'] = [];

        ob_start();
        wpmcp_trace_file_notice();

        self::assertSame(
            '',
            (string) ob_get_clean(),
            'A user without manage_options was shown the path of a file full of stack traces.'
        );
    }

    /**
     * And the option is taken back down when the file is gone, so an operator who deletes it by
     * hand and reactivates stops being warned.
     *
     * @group sprint-14d
     */
    public function testTheWarningClearsOnceTheFileIsGone(): void
    {
        WordPressRuntime::install();
        WordPressRuntime::setOption(WPMCP_TRACE_FILE_OPTION, 'trace-abc.log');

        self::assertTrue(wpmcp_note_trace_file_left([], '/var/www/wp-content/wpmcp'));
        self::assertContains(
            WPMCP_TRACE_FILE_OPTION,
            $GLOBALS['wpmcp_test_wp']['option_deletes'],
            'The warning stays up after the file went, so it becomes noise nobody reads.'
        );

        $GLOBALS['wpmcp_test_wp']['caps'] = ['manage_options'];
        WordPressRuntime::install();
        $GLOBALS['wpmcp_test_wp']['caps'] = ['manage_options'];

        ob_start();
        wpmcp_trace_file_notice();

        self::assertSame('', (string) ob_get_clean(), 'The notice prints with no option set.');
    }

    /**
     * The varchar widths of the traces table, by column name, out of wpmcp_install()'s own
     * CREATE TABLE.
     *
     * @return array<string, int>
     */
    private static function traceColumnWidths(): array
    {
        $source = (string) file_get_contents(WPMCP_PLUGIN_DIR . '/wp-mcp.php');
        $start  = strpos($source, 'WPMCP_TRACES_TABLE . " (');

        self::assertNotFalse($start, 'The traces table CREATE TABLE is not in wp-mcp.php.');

        $end = strpos($source, ') $charset;', $start);

        self::assertNotFalse($end, 'The traces CREATE TABLE has no end.');

        preg_match_all(
            '/^\s*([a-z_]+) varchar\((\d+)\)/m',
            substr($source, $start, $end - $start),
            $matches,
            PREG_SET_ORDER
        );

        $widths = [];

        foreach ($matches as $m) {
            $widths[$m[1]] = (int) $m[2];
        }

        return $widths;
    }
}
