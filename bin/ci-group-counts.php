<?php
/**
 * THE SPRINT GATES, COUNTED FROM ONE RUN INSTEAD OF EIGHTEEN.
 *
 * The build plan defines a gate as `phpunit --group sprint-N` green in CI, and green is
 * not enough: PHPUnit reports a wholly skipped group as green too, which is exactly what
 * happened to sprint-1 before WPMCP_WP_ENV existed. So CI has always asked a second
 * question - did every test in that group actually EXECUTE - and it used to answer it by
 * re-running PHPUnit once per group. Measured on run 35514259397 that cost 1 h 38 m 28 s
 * on top of a 1 h 35 m 23 s suite, to re-execute the same tests in the same container
 * minutes later (analysis/53-open-decisions.md, D11 and D16).
 *
 * This script answers the same question from the ONE full run. Two inputs:
 *
 *   --map=<file>   `phpunit --list-tests-xml` - PHPUnit's OWN answer to "which group is
 *                  each test in", including one row per data set. It executes nothing and
 *                  takes about a second.
 *   --log=<file>   `phpunit --log-junit` from the full run.
 *
 * WHY A MAP IS NEEDED AT ALL. JUnit XML carries no group attribute - `@group` reaches it
 * only through class and method names, which cannot be read back. `phpunit --group X
 * --list-tests` does not work either: PHPUnit 10.5 prints "The --group and --list-tests
 * options cannot be combined, --group is ignored" and lists every test in the suite.
 * `--list-tests-xml` is the one form that carries `groups=`, so it is the authority here.
 *
 * ATTRIBUTION IS BY (class, method), NOT BY TESTCASE NAME. A data-provider test is one
 * method with many rows: the map spells them `Class::method#plain`, and the log spells the
 * same row `name="method with data set &quot;plain&quot;"`. Groups are a property of the
 * METHOD, so every row of a provider shares them, and counting log testcases per
 * (class, base method name) is exact without reconstructing either spelling.
 *
 * THE THREE NUMBERS ARE THE OLD STEP'S THREE NUMBERS, printed in the old step's words, so
 * that one CI run can carry both steps and a machine can compare them line for line -
 * which is the acceptance test Max set for this change:
 *
 *     sprint-7: testcases=31 classes-that-ran-nothing=0 skipped-tests=0
 *
 * A FOURTH CHECK THE OLD STEP COULD NOT MAKE. The old step counted what a `--group` run
 * produced, so a test PHPUnit never reached was invisible to it: zero tests in, zero
 * expected. Here the map says how many rows the group HAS, so a group whose log is short
 * by one row is red with the missing ids named. That is strictly stronger, and it is the
 * only number that is not a copy of the old step.
 *
 *   php bin/ci-group-counts.php --map=junit-map.xml --log=junit-all.xml \
 *       --groups=sprint-0,sprint-1,... [--out=counts.txt]
 *
 * Exit 0 when every named group executed every one of its tests; 1 otherwise, with a
 * GitHub `::error::` line per failing group. tests/unit/CiGroupCountsTest.php drives it
 * over hand-written fixtures, including the skip that acceptance (b) forces.
 */

declare(strict_types=1);

if (PHP_SAPI === 'cli' && isset($argv) && realpath($argv[0]) === realpath(__FILE__)) {
    $parsed = [];

    foreach (array_slice($argv, 1) as $arg) {
        if (preg_match('/^--([a-z-]+)=(.*)$/', $arg, $m) !== 1) {
            fwrite(STDERR, "Unrecognised argument: {$arg}\n");
            exit(2);
        }
        $parsed[$m[1]] = $m[2];
    }

    foreach (['map', 'log', 'groups'] as $required) {
        if (!isset($parsed[$required]) || $parsed[$required] === '') {
            fwrite(
                STDERR,
                "Usage: php bin/ci-group-counts.php --map=<list-tests-xml> --log=<junit-xml>"
                . " --groups=a,b,c [--out=file]\n"
            );
            exit(2);
        }
    }

    $wanted = array_values(array_filter(
        array_map('trim', explode(',', $parsed['groups'])),
        static function ($g) { return $g !== ''; }
    ));

    exit(wpmcp_ci_group_counts(
        $parsed['map'],
        $parsed['log'],
        $wanted,
        isset($parsed['out']) ? $parsed['out'] : ''
    ) ? 0 : 1);
}

/**
 * @param string[] $groups
 */
function wpmcp_ci_group_counts(string $mapPath, string $logPath, array $groups, string $outPath = ''): bool
{
    $map = wpmcp_ci_load_map($mapPath);
    $ran = wpmcp_ci_load_log($logPath);

    $ok    = true;
    $lines = [];

    foreach ($groups as $group) {
        if (!isset($map[$group])) {
            echo "::error::{$group} is not a group any test carries. A gate group no test is"
                . " in is not a gate - either the group was renamed or its tests were deleted.\n";
            $lines[] = "{$group}: testcases=0 classes-that-ran-nothing=0 skipped-tests=0";
            $ok = false;
            continue;
        }

        $cases        = 0;
        $skipped      = 0;
        $expected     = 0;
        $emptyClasses = [];
        $missing      = [];

        foreach ($map[$group] as $class => $methods) {
            $classCases = 0;

            foreach ($methods as $method => $rows) {
                $expected += $rows;

                $seen = isset($ran[$class][$method]) ? $ran[$class][$method] : ['cases' => 0, 'skipped' => 0];

                $cases      += $seen['cases'];
                $skipped    += $seen['skipped'];
                $classCases += $seen['cases'];

                if ($seen['cases'] < $rows) {
                    $missing[] = $class . '::' . $method
                        . ' (' . $seen['cases'] . ' of ' . $rows . ' rows in the log)';
                }
            }

            if ($classCases === 0) {
                $emptyClasses[] = $class;
            }
        }

        $empty = count($emptyClasses);
        $line  = "{$group}: testcases={$cases} classes-that-ran-nothing={$empty} skipped-tests={$skipped}";

        echo $line . "\n";
        $lines[] = $line;

        if ($cases === 0 || $empty !== 0 || $skipped !== 0) {
            // The wording is the old step's, word for word, because acceptance (b) asks
            // that a forced skip turn THIS step red with the SAME error.
            echo "::error::{$group} did not execute every one of its tests. A gate that skips is not a gate.\n";

            foreach ($emptyClasses as $class) {
                echo "  class that ran nothing: {$class}\n";
            }

            foreach (wpmcp_ci_skipped_ids($map[$group], $ran) as $id) {
                echo "  skipped: {$id}\n";
            }

            $ok = false;
        }

        if ($cases !== $expected) {
            echo "::error::{$group} has {$expected} tests in the map and {$cases} in the log."
                . " A test that never reached the runner was invisible to the old step, which"
                . " counted only what a --group run produced.\n";

            foreach ($missing as $id) {
                echo "  missing: {$id}\n";
            }

            $ok = false;
        }
    }

    if ($outPath !== '') {
        file_put_contents($outPath, implode("\n", $lines) . "\n");
    }

    return $ok;
}

/**
 * group => class => method => number of rows (1, or one per data set).
 *
 * @return array<string, array<string, array<string, int>>>
 */
function wpmcp_ci_load_map(string $path): array
{
    $xml = wpmcp_ci_read($path, 'test map');
    $out = [];

    foreach ($xml->testCaseClass as $class) {
        $className = (string) $class['name'];

        foreach ($class->testCaseMethod as $method) {
            $name = (string) $method['name'];
            $raw  = trim((string) $method['groups']);

            if ($name === '' || $raw === '') {
                continue;
            }

            foreach (explode(',', $raw) as $group) {
                $group = trim($group);

                if ($group === '') {
                    continue;
                }

                $seen = isset($out[$group][$className][$name]) ? $out[$group][$className][$name] : 0;
                $out[$group][$className][$name] = $seen + 1;
            }
        }
    }

    return $out;
}

/**
 * class => method => ['cases' => int, 'skipped' => int], read from a JUnit log.
 *
 * The method name is the log's testcase name with PHPUnit's data-set suffix removed, so a
 * provider's six rows land on the one method the map knows about.
 *
 * @return array<string, array<string, array{cases:int, skipped:int}>>
 */
function wpmcp_ci_load_log(string $path): array
{
    $xml = wpmcp_ci_read($path, 'JUnit log');
    $out = [];

    $cases = $xml->xpath('//testcase');

    foreach ($cases === false ? [] : $cases as $case) {
        $class = (string) $case['class'];
        $name  = (string) $case['name'];

        if ($class === '' || $name === '') {
            continue;
        }

        $method = preg_replace('/ with data set .*$/s', '', $name);

        if (!isset($out[$class][$method])) {
            $out[$class][$method] = ['cases' => 0, 'skipped' => 0];
        }

        $out[$class][$method]['cases']++;

        if (isset($case->skipped)) {
            $out[$class][$method]['skipped']++;
        }
    }

    return $out;
}

/**
 * @param array<string, array<string, int>>                            $classes
 * @param array<string, array<string, array{cases:int, skipped:int}>>   $ran
 *
 * @return string[]
 */
function wpmcp_ci_skipped_ids(array $classes, array $ran): array
{
    $ids = [];

    foreach ($classes as $class => $methods) {
        foreach (array_keys($methods) as $method) {
            if (isset($ran[$class][$method]) && $ran[$class][$method]['skipped'] > 0) {
                $ids[] = $class . '::' . $method;
            }
        }
    }

    return $ids;
}

function wpmcp_ci_read(string $path, string $what): SimpleXMLElement
{
    if (!is_file($path)) {
        fwrite(STDERR, "::error::The {$what} is not at {$path}. Whatever was supposed to write it did not.\n");
        exit(2);
    }

    $xml = simplexml_load_file($path);

    if ($xml === false) {
        fwrite(STDERR, "::error::The {$what} at {$path} is not parseable XML.\n");
        exit(2);
    }

    return $xml;
}
