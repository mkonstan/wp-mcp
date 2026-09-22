<?php
/**
 * THE SCRIPT THAT REPLACED A GATE, TESTED LIKE ONE.
 *
 * bin/ci-group-counts.php is what now answers "did every test in every closed sprint group
 * actually execute", in place of eighteen `phpunit --group sprint-N` re-runs that cost
 * 1 h 38 m 28 s on run 35514259397 (analysis/53-open-decisions.md, D11). CI proves it agrees
 * with the old step on real logs; this tier proves the cases a real log does not contain,
 * because a green suite cannot demonstrate what the check does when something skips.
 *
 * The fixtures are hand-written XML in the two shapes PHPUnit really emits:
 * `--list-tests-xml` (`groups=`, one row per data set) and `--log-junit`
 * (`<testcase class= name=>`, with `<skipped/>` as a child).
 *
 * @group sprint-0
 */

declare(strict_types=1);

namespace WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class CiGroupCountsTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        require_once WPMCP_PLUGIN_DIR . '/bin/ci-group-counts.php';

        $this->dir = sys_get_temp_dir() . '/wpmcp-gate-' . bin2hex(random_bytes(6));

        self::assertTrue(mkdir($this->dir, 0777, true), 'Could not make a temp directory.');
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->dir . '/*') as $file) {
            @unlink((string) $file);
        }

        @rmdir($this->dir);
    }

    /**
     * The happy path, and the exact line the old step printed - because acceptance (a) is a
     * diff of the two steps' output and a different wording would fail it even when the
     * numbers agree.
     *
     * @group sprint-0
     */
    public function testAGroupThatRanEverythingPrintsTheOldStepSWordsAndPasses(): void
    {
        $map = $this->map([
            ['Alpha', 'testOne', 'sprint-1'],
            ['Alpha', 'testTwo', 'sprint-1'],
            ['Beta',  'testThree', 'sprint-1'],
        ]);

        $log = $this->log([
            ['Alpha', 'testOne'],
            ['Alpha', 'testTwo'],
            ['Beta',  'testThree'],
        ]);

        [$ok, $output] = $this->check($map, $log, ['sprint-1']);

        self::assertTrue($ok, $output);
        self::assertStringContainsString(
            'sprint-1: testcases=3 classes-that-ran-nothing=0 skipped-tests=0',
            $output
        );
    }

    /**
     * ACCEPTANCE (b), in the tier that can stage it deterministically: one test in one sprint
     * group skips, and the check is red with the SAME sentence the old step used. CI proves
     * this too, on a throwaway commit, because a claim about CI has to be made in CI - but a
     * three-hour run is a poor place to iterate on the wording.
     *
     * @group sprint-0
     */
    public function testOneSkippedTestTurnsTheGroupRedWithTheOldStepSError(): void
    {
        $map = $this->map([
            ['Alpha', 'testOne', 'sprint-1'],
            ['Alpha', 'testTwo', 'sprint-1'],
        ]);

        $log = $this->log([
            ['Alpha', 'testOne'],
            ['Alpha', 'testTwo', true],
        ]);

        [$ok, $output] = $this->check($map, $log, ['sprint-1']);

        self::assertFalse($ok, 'A skipped test in a gate group has to be red. A skip is green.');
        self::assertStringContainsString('skipped-tests=1', $output);
        self::assertStringContainsString(
            '::error::sprint-1 did not execute every one of its tests.'
            . ' A gate that skips is not a gate.',
            $output
        );
        self::assertStringContainsString('skipped: Alpha::testTwo', $output);
    }

    /**
     * A whole class that contributed nothing - what a class-level markTestSkipped looks like
     * from outside, and the case that made sprint-1 green in CI without running a single one
     * of its integration tests.
     *
     * @group sprint-0
     */
    public function testAClassThatContributedNothingIsNamed(): void
    {
        $map = $this->map([
            ['Alpha', 'testOne', 'sprint-2'],
            ['Ghost', 'testTwo', 'sprint-2'],
        ]);

        $log = $this->log([['Alpha', 'testOne']]);

        [$ok, $output] = $this->check($map, $log, ['sprint-2']);

        self::assertFalse($ok);
        self::assertStringContainsString('classes-that-ran-nothing=1', $output);
        self::assertStringContainsString('class that ran nothing: Ghost', $output);
    }

    /**
     * THE CHECK THE OLD STEP COULD NOT MAKE. A `--group` re-run counts what it produced, so a
     * test PHPUnit never reached was invisible: zero in, zero expected, green. The map says
     * how many rows the group HAS, so a log short by one row is red and the id is named.
     *
     * @group sprint-0
     */
    public function testATestThatNeverReachedTheRunnerIsNamedRatherThanIgnored(): void
    {
        $map = $this->map([
            ['Alpha', 'testOne', 'sprint-3'],
            ['Alpha', 'testGone', 'sprint-3'],
        ]);

        $log = $this->log([['Alpha', 'testOne']]);

        [$ok, $output] = $this->check($map, $log, ['sprint-3']);

        self::assertFalse($ok);
        self::assertStringContainsString('has 2 tests in the map and 1 in the log', $output);
        self::assertStringContainsString('missing: Alpha::testGone (0 of 1 rows in the log)', $output);
    }

    /**
     * A data-provider test is one method with many rows. The map spells them
     * `Class::method#plain`; the log spells the same row `method with data set "plain"`.
     * Groups belong to the METHOD, so all six rows count towards it - and a provider whose
     * rows went missing is caught by the count, not waved through by a name that matched.
     *
     * @group sprint-0
     */
    public function testEveryRowOfADataProviderIsCounted(): void
    {
        $map = $this->map([
            ['Alpha', 'testRows', 'sprint-4', 'plain'],
            ['Alpha', 'testRows', 'sprint-4', 'doubled separator'],
            ['Alpha', 'testRows', 'sprint-4', 'backslashes'],
        ]);

        $log = $this->log([
            ['Alpha', 'testRows with data set "plain"'],
            ['Alpha', 'testRows with data set "doubled separator"'],
            ['Alpha', 'testRows with data set "backslashes"'],
        ]);

        [$ok, $output] = $this->check($map, $log, ['sprint-4']);

        self::assertTrue($ok, $output);
        self::assertStringContainsString('sprint-4: testcases=3', $output);

        // And one row short is red, with the method named once rather than three times.
        $short = $this->log([
            ['Alpha', 'testRows with data set "plain"'],
            ['Alpha', 'testRows with data set "backslashes"'],
        ]);

        [$okShort, $shortOutput] = $this->check($map, $short, ['sprint-4']);

        self::assertFalse($okShort);
        self::assertStringContainsString('missing: Alpha::testRows (2 of 3 rows in the log)', $shortOutput);
    }

    /**
     * A gate group that no test carries. This is what a renamed or deleted group looks like,
     * and the old step read it as "zero testcases", which it already refused - but only
     * because zero is also what a total skip looks like. Here it has its own sentence.
     *
     * @group sprint-0
     */
    public function testAGateGroupNoTestCarriesIsRed(): void
    {
        $map = $this->map([['Alpha', 'testOne', 'sprint-1']]);
        $log = $this->log([['Alpha', 'testOne']]);

        [$ok, $output] = $this->check($map, $log, ['sprint-1', 'sprint-99']);

        self::assertFalse($ok);
        self::assertStringContainsString('sprint-99 is not a group any test carries', $output);
    }

    /**
     * The `--out` file is what CI diffs the two steps with, so it has to hold exactly the
     * lines that were printed, one per group, in the order asked for.
     *
     * @group sprint-0
     */
    public function testTheOutFileHoldsOneLinePerGroupInTheOrderAsked(): void
    {
        $map = $this->map([
            ['Alpha', 'testOne', 'sprint-1'],
            ['Beta',  'testTwo', 'sprint-2'],
        ]);

        $log = $this->log([
            ['Alpha', 'testOne'],
            ['Beta',  'testTwo'],
        ]);

        $out = $this->dir . '/counts.txt';

        [$ok] = $this->check($map, $log, ['sprint-2', 'sprint-1'], $out);

        self::assertTrue($ok);
        self::assertSame(
            "sprint-2: testcases=1 classes-that-ran-nothing=0 skipped-tests=0\n"
            . "sprint-1: testcases=1 classes-that-ran-nothing=0 skipped-tests=0\n",
            (string) file_get_contents($out)
        );
    }

    /**
     * @param string[] $groups
     *
     * @return array{0: bool, 1: string}
     */
    private function check(string $mapPath, string $logPath, array $groups, string $out = ''): array
    {
        // ob_start, because phpunit.xml.dist sets beStrictAboutOutputDuringTests and the
        // script's whole interface is what it prints.
        ob_start();
        $ok = \wpmcp_ci_group_counts($mapPath, $logPath, $groups, $out);

        return [$ok, (string) ob_get_clean()];
    }

    /**
     * @param array<int, array{0: string, 1: string, 2: string, 3?: string}> $rows
     *                class, method, group, optional data-set name
     */
    private function map(array $rows): string
    {
        $byClass = [];

        foreach ($rows as $row) {
            $byClass[$row[0]][] = $row;
        }

        $xml = "<?xml version=\"1.0\"?>\n<tests>\n";

        foreach ($byClass as $class => $methods) {
            $xml .= ' <testCaseClass name="' . $class . "\">\n";

            foreach ($methods as $row) {
                $id  = $class . '::' . $row[1] . (isset($row[3]) ? '#' . $row[3] : '');
                $xml .= '  <testCaseMethod id="' . htmlspecialchars($id, ENT_QUOTES)
                    . '" name="' . $row[1] . '" groups="' . $row[2] . '"'
                    . (isset($row[3]) ? ' dataSet="' . htmlspecialchars('"' . $row[3] . '"', ENT_QUOTES) . '"' : '')
                    . "/>\n";
            }

            $xml .= " </testCaseClass>\n";
        }

        return $this->write('map.xml', $xml . "</tests>\n");
    }

    /**
     * @param array<int, array{0: string, 1: string, 2?: bool}> $cases
     *                class, testcase name, skipped?
     */
    private function log(array $cases): string
    {
        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<testsuites>\n  <testsuite name=\"all\">\n";

        foreach ($cases as $case) {
            $open = '    <testcase name="' . htmlspecialchars($case[1], ENT_QUOTES)
                . '" class="' . $case[0] . '" classname="' . str_replace('\\', '.', $case[0]) . '"';

            $xml .= isset($case[2]) && $case[2]
                ? $open . ">\n      <skipped/>\n    </testcase>\n"
                : $open . "/>\n";
        }

        return $this->write('log-' . bin2hex(random_bytes(4)) . '.xml', $xml . "  </testsuite>\n</testsuites>\n");
    }

    private function write(string $name, string $contents): string
    {
        $path = $this->dir . '/' . $name;

        self::assertNotFalse(file_put_contents($path, $contents), "Could not write {$path}.");

        return $path;
    }
}
