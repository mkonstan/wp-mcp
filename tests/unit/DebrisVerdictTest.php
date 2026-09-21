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
}
