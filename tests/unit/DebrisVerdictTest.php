<?php
/**
 * The debris check tells an operator's switch from a test run's leftover (sprint 14b).
 *
 * WHY. `wpmcp_sql_enabled` is one shared option with no room for a run prefix, and no
 * test in this suite writes it: SqlSelectTest arms sql-select through a per-request
 * filter. So when the option is ON, an operator switched it on - both local test sites
 * keep SQL reads on for their dev servers - and a report that called that "debris" was
 * red after every clean run. It is a NOTICE now: printed, and the report still says
 * clean. A `wpmcp-test-` key left in the post-meta allow-list is still debris, because
 * that key can only have come from a run.
 *
 * Pure functions, no site: both branches run everywhere, CI included.
 *
 * @group sprint-14b
 */

declare(strict_types=1);

namespace WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WpMcp\Tests\Support\Fixtures;

final class DebrisVerdictTest extends TestCase
{
    /**
     * SQL reads ON with nothing else left: a notice line, the clean sentence, exit 0.
     *
     * @group sprint-14b
     */
    public function testSqlReadsOnIsANoticeAndTheReportIsStillClean(): void
    {
        $switches = Fixtures::switchReports(true, ['_price', 'subtitle']);

        self::assertSame('', $switches['debris'], 'SQL reads ON was reported as debris.');
        self::assertStringStartsWith('NOTICE:', $switches['notices']);
        self::assertStringContainsString('wpmcp_sql_enabled', $switches['notices']);

        [$code, $out] = Fixtures::debrisVerdict('', $switches['notices'], $switches['debris']);

        self::assertSame(0, $code, 'The debris check fails on an operator\'s switch.');
        self::assertStringContainsString('NOTICE:', $out);
        self::assertStringContainsString('debris-check: clean', $out);
        self::assertLessThan(
            strpos($out, 'debris-check: clean'),
            strpos($out, 'NOTICE:'),
            'The notice should come before the verdict it does not change.'
        );
    }

    /**
     * SQL reads OFF: no notice at all, clean.
     *
     * @group sprint-14b
     */
    public function testSqlReadsOffPrintsNoNotice(): void
    {
        $switches = Fixtures::switchReports(false, []);

        self::assertSame('', $switches['notices']);
        self::assertSame('', $switches['debris']);

        [$code, $out] = Fixtures::debrisVerdict('', '', '');

        self::assertSame(0, $code);
        self::assertStringNotContainsString('NOTICE', $out);
        self::assertStringStartsWith('debris-check: clean', $out);
    }

    /**
     * A prefixed key in the meta allow-list still fails the check, with SQL reads on or
     * off, and the clean sentence is not printed.
     *
     * @group sprint-14b
     */
    public function testALeftoverPrefixedMetaKeyStillFailsIt(): void
    {
        foreach ([true, false] as $sqlOn) {
            $switches = Fixtures::switchReports($sqlOn, ['subtitle', Fixtures::PREFIX . 'abcd1234-meta']);

            self::assertStringContainsString(Fixtures::PREFIX . 'abcd1234-meta', $switches['debris']);

            [$code, $out] = Fixtures::debrisVerdict('', $switches['notices'], $switches['debris']);

            self::assertSame(1, $code, 'A leftover test meta key no longer fails the debris check.');
            self::assertStringNotContainsString('debris-check: clean', $out);
            self::assertStringContainsString('FIXTURE META KEYS LEFT IN THE ALLOW-LIST', $out);
        }
    }

    /**
     * Foreign fixture debris fails it too, notice or not - the notice never masks a
     * real leftover.
     *
     * @group sprint-14b
     */
    public function testForeignDebrisStillFailsItWithTheNoticePresent(): void
    {
        $switches = Fixtures::switchReports(true, []);

        [$code, $out] = Fixtures::debrisVerdict("FOREIGN FIXTURE DEBRIS on the site under test.\n  user 9 wpmcp-test-x\n", $switches['notices'], '');

        self::assertSame(1, $code);
        self::assertStringContainsString('FOREIGN FIXTURE DEBRIS', $out);
        self::assertStringNotContainsString('debris-check: clean', $out);
    }

    /**
     * A trace nobody asked for is REPORTED and named, and it does not fail the check.
     *
     * THE BLIND SPOT THIS CLOSES (sprint TRACE-TABLE round 2). Traced failures became rows in
     * 1.1.2, so for the first time a green run's unexpected failures are somewhere a check can
     * read. The suite's own traces are caused on purpose and carry the test prefix; a row
     * without it is a failure something on the site actually hit, and until now nothing looked.
     *
     * IT IS A NOTICE AND NOT A VERDICT, which is the half worth pinning: this script's exit code
     * answers "did the suite leave fixtures behind", a trace is not a fixture, and a check that
     * went red because somebody opened the endpoint in a browser is a check that gets ignored.
     *
     * @group sprint-14d
     */
    public function testAnUnexpectedTraceIsReportedByNameAndDoesNotFailTheCheck(): void
    {
        $listed = ['id 41  2026-09-24 12:00:00 UTC  list-posts  TypeError'];
        $report = Fixtures::traceReport(3, 1, $listed, 7);

        self::assertStringContainsString('were NOT caused by a test', $report);
        // BOUNDED, NOT A SUBSTRING (round 2 of sprint SEAM): `id 41` is a substring of
        // `id 410`. The fixture is synthetic and holds neither, so this is the cheap half of
        // a class rule rather than a bug fix - see ToolResult::mentions().
        self::assertMatchesRegularExpression(
            '/\bid 41\b/',
            $report,
            'The unexpected row is not named, so it cannot be looked up.'
        );
        self::assertStringContainsString('list-posts', $report);
        // BOUNDED, NOT A SUBSTRING (round 2 of sprint SEAM): a COUNT at the start of a
        // sentence is the same defect as an id - '3 trace row(s) ...' is a substring of
        // '13 trace row(s) ...', and this class asserts 3, 13 and 57 in three different
        // tests, so one could pass on another's number. See ToolResult::mentions().
        // BOUNDED ON THE LEFT, NOT A BARE SUBSTRING (round 2 of sprint SEAM): a COUNT at the
        // start of a sentence has the same defect as an id - `3 trace row(s) ...` is a
        // substring of `13 trace row(s) ...`, and this class asserts 3, 13 and 57 in three
        // different tests, so one could pass on another's number. `NOTICE: ` in front of the
        // count is the cheapest boundary there is, and it asserts one more true thing: that
        // the line is a NOTICE. See ToolResult::mentions() for the class.
        self::assertStringContainsString('NOTICE: 3 trace row(s) were caused by this suite on purpose', $report);
        self::assertStringContainsString(
            'KNOWN FALSE POSITIVE',
            $report,
            'The report does not name the case where the split is wrong. The FIRST live run found'
            . ' it: a suite-caused failure through a BUILT-IN tool carries the prefix nowhere, so'
            . ' six sql-select refusals landed in the second bucket. A reader who is not told that'
            . ' goes looking for a bug that is a passing test.'
        );
        self::assertStringContainsString('within 7 days', $report, 'The retention number comes from the site, not from a literal here.');

        [$code, $out] = Fixtures::debrisVerdict('', $report, '');

        self::assertSame(0, $code, 'An unexpected trace failed the debris check. It is a signal to read, not a leftover.');
        self::assertStringContainsString('debris-check: clean', $out);
        self::assertLessThan(
            strpos($out, 'debris-check: clean'),
            strpos($out, 'NOT caused by a test'),
            'The trace report must come before the verdict it does not change.'
        );
    }

    /**
     * When there are more unexpected traces than the report LISTS, it says so.
     *
     * ROUND 2 READ THE NEWEST 200 ROWS AND REPORTED THAT AS THE COUNT, so an older unexpected trace
     * was neither counted nor named - a blind spot inside the check added to remove a blind spot,
     * and the worse of the two because it looks covered. The count is now over the whole table and
     * only the LISTING is bounded, so the one remaining bound has to be visible in the output.
     *
     * @group sprint-14d
     */
    public function testTheReportSaysWhenItIsListingFewerRowsThanItCounted(): void
    {
        $listed = [];

        for ($i = 0; $i < Fixtures::TRACE_ROWS_LISTED; $i++) {
            $listed[] = 'id ' . (900 - $i) . '  2026-09-24 12:00:00 UTC  list-posts  TypeError';
        }

        $report = Fixtures::traceReport(0, 57, $listed, 7);

        // BOUNDED, NOT A SUBSTRING (round 2 of sprint SEAM): a COUNT at the start of a
        // sentence is the same defect as an id - '3 trace row(s) ...' is a substring of
        // '13 trace row(s) ...', and this class asserts 3, 13 and 57 in three different
        // tests, so one could pass on another's number. See ToolResult::mentions().
        // BOUNDED ON THE LEFT, NOT A BARE SUBSTRING (round 2 of sprint SEAM): a COUNT at the
        // start of a sentence has the same defect as an id - `3 trace row(s) ...` is a
        // substring of `13 trace row(s) ...`, and this class asserts 3, 13 and 57 in three
        // different tests, so one could pass on another's number. `NOTICE: ` in front of the
        // count is the cheapest boundary there is, and it asserts one more true thing: that
        // the line is a NOTICE. See ToolResult::mentions() for the class.
        self::assertStringContainsString('NOTICE: 57 trace row(s) on this site were NOT caused by a test', $report);
        self::assertStringContainsString(
            'Showing the newest ' . Fixtures::TRACE_ROWS_LISTED . ' of 57',
            $report,
            'The report lists fewer rows than it counted and does not say so, so a reader does not'
            . ' know what they are not being told - which is the defect being closed.'
        );

        // And when the list IS the whole of it, there is no such line to read past.
        self::assertStringNotContainsString(
            'Showing the newest',
            Fixtures::traceReport(0, 1, ['id 1  2026-09-24 12:00:00 UTC  x  TypeError'], 7)
        );
    }

    /**
     * No traces at all: nothing printed, and the clean sentence is untouched.
     *
     * @group sprint-14d
     */
    public function testNoTracesPrintsNothing(): void
    {
        self::assertSame('', Fixtures::traceReport(0, 0, [], 7));

        [$code, $out] = Fixtures::debrisVerdict('', Fixtures::traceReport(0, 0, [], 7), '');

        self::assertSame(0, $code);
        self::assertStringStartsWith('debris-check: clean', $out);
    }

    /**
     * The suite's OWN traces alone print a notice that says why they are not debris, and the
     * check still passes - which is the case every green run actually produces.
     *
     * @group sprint-14d
     */
    public function testTheSuitesOwnTracesAreANoticeAndStillClean(): void
    {
        $report = Fixtures::traceReport(13, 0, [], 7);

        self::assertStringNotContainsString('NOT caused by a test', $report);
        // BOUNDED, NOT A SUBSTRING (round 2 of sprint SEAM): a COUNT at the start of a
        // sentence is the same defect as an id - '3 trace row(s) ...' is a substring of
        // '13 trace row(s) ...', and this class asserts 3, 13 and 57 in three different
        // tests, so one could pass on another's number. See ToolResult::mentions().
        // BOUNDED ON THE LEFT, NOT A BARE SUBSTRING (round 2 of sprint SEAM): a COUNT at the
        // start of a sentence has the same defect as an id - `3 trace row(s) ...` is a
        // substring of `13 trace row(s) ...`, and this class asserts 3, 13 and 57 in three
        // different tests, so one could pass on another's number. `NOTICE: ` in front of the
        // count is the cheapest boundary there is, and it asserts one more true thing: that
        // the line is a NOTICE. See ToolResult::mentions() for the class.
        self::assertStringContainsString('NOTICE: 13 trace row(s) were caused by this suite on purpose', $report);

        [$code, $out] = Fixtures::debrisVerdict('', $report, '');

        self::assertSame(0, $code, 'Traces the suite caused on purpose failed the debris check.');
        self::assertStringContainsString('debris-check: clean', $out);
    }
}
