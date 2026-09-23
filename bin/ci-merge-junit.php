<?php
/**
 * MERGE THE SHARDS' JUNIT LOGS INTO ONE, SO THE GATE NEVER SEES A FRAGMENT.
 *
 * This is the risky half of D18. The per-group check (bin/ci-group-counts.php) is what catches a
 * gate group that silently did not run, and it answers by comparing the map's row count against
 * the log's. Hand it one shard's log and it will correctly report seven eighths of the suite as
 * missing; hand it seven logs out of eight and it will report the eighth shard's classes as
 * missing, which is exactly right and exactly why the merge must be strict rather than
 * forgiving. **A merge that quietly skips an unreadable input turns a dead shard into a green
 * run.**
 *
 * So: `--expect=<n>` is required, every named file must exist and parse, and the count of files
 * merged must equal it. Anything else exits 1 saying which file and why.
 *
 *   php bin/ci-merge-junit.php --out=junit-all.xml --expect=8 --map=junit-map.xml \
 *       --config=phpunit.xml.dist shards/junit-shard-*.xml
 *
 * WITH `--map`, ONE MORE REFUSAL, and it is the one that does not depend on luck. Everything else
 * here notices a missing FILE; this notices a missing CLASS. The plan can lose a class without
 * losing a log: a filter that does not match what the planner meant produces a shard that runs its
 * other classes perfectly and never mentions this one. The first version of the planner did exactly
 * that for a global-namespace class, and the only thing that would have caught it was the
 * per-group check - and only if the lost class happened to carry a gate group. So every class the
 * map names must appear in the merged log, unless every one of its methods is in a group the
 * PHPUnit config EXCLUDES, which is read from `--config` rather than guessed: `--list-tests-xml`
 * ignores the exclusion and puts `InfraTrustTest` in the map on every run, and it runs on none.
 *
 * WHAT IT KEEPS. Only `<testcase>` elements, copied whole - their `class`, `name` and `file`
 * attributes and their `<skipped>`, `<failure>` and `<error>` children. That is precisely what
 * the group check reads. It deliberately does NOT try to merge the `<testsuite>` tree: PHPUnit
 * 10.5 nests those elements oddly (a reader who sums `testsuite[@file]/@time` across the tree
 * double-counts), the shards' trees describe different subsets, and a merged tree would be a
 * plausible-looking object that nothing consumes. The aggregate counts on the root are
 * recomputed from the testcases themselves so the file is self-consistent for a human reading it.
 *
 * A DUPLICATE IS AN ERROR, not something to de-duplicate. Two shards reporting the same
 * `Class::method` means the partition broke - the planner assigns each class to exactly one bin -
 * and silently keeping one copy would hide it while keeping the counts right.
 */

declare(strict_types=1);

if (PHP_SAPI === 'cli' && isset($argv) && realpath($argv[0]) === realpath(__FILE__)) {
    $out    = '';
    $map    = '';
    $config = '';
    $expect = -1;
    $inputs = [];

    foreach (array_slice($argv, 1) as $arg) {
        if (preg_match('/^--out=(.+)$/', $arg, $m) === 1) {
            $out = $m[1];
        } elseif (preg_match('/^--map=(.+)$/', $arg, $m) === 1) {
            $map = $m[1];
        } elseif (preg_match('/^--config=(.+)$/', $arg, $m) === 1) {
            $config = $m[1];
        } elseif (preg_match('/^--expect=(\d+)$/', $arg, $m) === 1) {
            $expect = (int) $m[1];
        } elseif (strpos($arg, '--') === 0) {
            fwrite(STDERR, "Unrecognised argument: {$arg}\n");
            exit(2);
        } else {
            $inputs[] = $arg;
        }
    }

    if ($out === '' || $expect < 0) {
        fwrite(
            STDERR,
            "Usage: php bin/ci-merge-junit.php --out=<file> --expect=<n> [--map=<list-tests-xml>]"
            . " [--config=<phpunit.xml>] <log> [<log> …]\n"
        );
        exit(2);
    }

    exit(wpmcp_ci_merge_junit($inputs, $out, $expect, $map, $config) ? 0 : 1);
}

/**
 * @param string[] $inputs
 */
function wpmcp_ci_merge_junit(
    array $inputs,
    string $outPath,
    int $expect,
    string $mapPath = '',
    string $configPath = ''
): bool {
    sort($inputs);

    if (count($inputs) !== $expect) {
        echo "::error::expected {$expect} shard logs and was given " . count($inputs) . ".\n";
        echo "  A shard that produced no log did not run its tests, and a merge that went ahead\n";
        echo "  without it would report a smaller suite as a complete one.\n";
        foreach ($inputs as $path) {
            echo "  given: {$path}\n";
        }

        return false;
    }

    $doc  = new DOMDocument('1.0', 'UTF-8');
    $doc->formatOutput = true;
    $root = $doc->createElement('testsuites');
    $doc->appendChild($root);

    $merged = $doc->createElement('testsuite');
    $merged->setAttribute('name', 'merged-shards');
    $root->appendChild($merged);

    $seen     = [];
    $counts   = [];
    $failures = 0;
    $errors   = 0;
    $skipped  = 0;
    $time     = 0.0;
    $ok       = true;

    foreach ($inputs as $path) {
        if (!is_file($path)) {
            echo "::error::{$path} is not there. A shard that wrote no log ran no tests.\n";

            return false;
        }

        $xml = simplexml_load_file($path);

        if ($xml === false) {
            echo "::error::{$path} is not parseable XML. A truncated log is a shard that died mid-write.\n";

            return false;
        }

        $cases = $xml->xpath('//testcase');
        $cases = $cases === false ? [] : $cases;
        $counts[$path] = count($cases);

        if ($counts[$path] === 0) {
            echo "::error::{$path} contains no testcase at all. A shard that executed nothing is"
                . " not a shard that passed.\n";
            $ok = false;
        }

        foreach ($cases as $case) {
            $id = (string) $case['class'] . '::' . (string) $case['name'];

            if (isset($seen[$id])) {
                echo "::error::{$id} appears in {$seen[$id]} and again in {$path}."
                    . " The shards are supposed to partition the suite, so a test in two of them"
                    . " means the plan is broken.\n";
                $ok = false;
                continue;
            }

            $seen[$id] = $path;

            $node = dom_import_simplexml($case);
            $merged->appendChild($doc->importNode($node, true));

            $time += (float) $case['time'];

            if (isset($case->failure)) { $failures++; }
            if (isset($case->error))   { $errors++; }
            if (isset($case->skipped)) { $skipped++; }
        }
    }

    $total = count($seen);

    if ($mapPath !== '' && !wpmcp_ci_merge_every_class_ran($mapPath, $configPath, $seen)) {
        $ok = false;
    }

    foreach ([$root, $merged] as $element) {
        $element->setAttribute('tests', (string) $total);
        $element->setAttribute('failures', (string) $failures);
        $element->setAttribute('errors', (string) $errors);
        $element->setAttribute('skipped', (string) $skipped);
        $element->setAttribute('time', sprintf('%.6f', $time));
    }

    foreach ($counts as $path => $n) {
        echo sprintf("  %-40s %5d testcases\n", basename($path), $n);
    }

    echo sprintf(
        "merged %d shard logs into %s: %d testcases, %d failures, %d errors, %d skipped\n",
        count($inputs),
        $outPath,
        $total,
        $failures,
        $errors,
        $skipped
    );

    if (!$ok) {
        return false;
    }

    if ($doc->save($outPath) === false) {
        echo "::error::could not write {$outPath}.\n";

        return false;
    }

    return true;
}

/**
 * Every class the map names appears in the merged log, or the reason it may not is on the record.
 *
 * @param array<string, string> $seen `Class::method` => the log it came from
 */
function wpmcp_ci_merge_every_class_ran(string $mapPath, string $configPath, array $seen): bool
{
    $excluded = $configPath === '' ? [] : wpmcp_ci_merge_excluded_groups($configPath);

    $ranClasses = [];

    foreach (array_keys($seen) as $id) {
        $pos = strrpos($id, '::');
        $ranClasses[$pos === false ? $id : substr($id, 0, $pos)] = true;
    }

    if (!is_file($mapPath)) {
        echo "::error::the test map is not at {$mapPath}, so class coverage cannot be checked.\n";

        return false;
    }

    $xml = simplexml_load_file($mapPath);

    if ($xml === false) {
        echo "::error::the test map at {$mapPath} is not parseable XML.\n";

        return false;
    }

    $missing = [];

    foreach ($xml->testCaseClass as $class) {
        $name = (string) $class['name'];

        if ($name === '' || isset($ranClasses[$name])) {
            continue;
        }

        // A class whose every method is in an excluded group cannot appear, and that is not a
        // fault: `--list-tests-xml` ignores the config's exclusions, so InfraTrustTest is in the
        // map on every run and runs on none of them. A method with NO group at all is not
        // excluded by anything, so one of those makes the class expected.
        $allExcluded = true;

        foreach ($class->testCaseMethod as $method) {
            $groups = array_values(array_filter(array_map('trim', explode(',', (string) $method['groups']))));

            if ($groups === [] || array_diff($groups, $excluded) !== []) {
                $allExcluded = false;
                break;
            }
        }

        if ($allExcluded) {
            echo "  in the map, excluded by config, correctly absent: {$name}\n";
            continue;
        }

        $missing[] = $name;
    }

    if ($missing === []) {
        echo "every class in the map appears in the merged log\n";

        return true;
    }

    echo '::error::' . count($missing) . " class(es) are in the test map and in no shard's log."
        . " A class the plan lost runs nowhere and every shard is still green, which is the one way"
        . " sharding can quietly shrink the suite.\n";

    foreach ($missing as $name) {
        echo "  never ran: {$name}\n";
    }

    return false;
}

/**
 * The groups a PHPUnit config's `<groups><exclude>` names.
 *
 * @return string[]
 */
function wpmcp_ci_merge_excluded_groups(string $configPath): array
{
    if (!is_file($configPath)) {
        echo "::error::the PHPUnit config is not at {$configPath}, so no group can be treated as excluded.\n";

        return [];
    }

    $xml = simplexml_load_file($configPath);

    if ($xml === false) {
        echo "::error::the PHPUnit config at {$configPath} is not parseable XML.\n";

        return [];
    }

    $out   = [];
    $nodes = $xml->xpath('//groups/exclude/group');

    foreach ($nodes === false ? [] : $nodes as $group) {
        $name = trim((string) $group);

        if ($name !== '') {
            $out[] = $name;
        }
    }

    return $out;
}
