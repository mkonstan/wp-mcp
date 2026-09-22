<?php
/**
 * MERGE THE SHARDS' JUNIT LOGS INTO ONE, SO THE GATE NEVER SEES A FRAGMENT.
 *
 * This is the risky half of D18. The per-group check (bin/ci-group-counts.php) is what catches a
 * gate group that silently did not run, and it answers by comparing the map's row count against
 * the log's. Hand it one shard's log and it will correctly report five sixths of the suite as
 * missing; hand it five logs out of six and it will report the sixth shard's classes as missing,
 * which is exactly right and exactly why the merge must be strict rather than forgiving. **A
 * merge that quietly skips an unreadable input turns a dead shard into a green run.**
 *
 * So: `--expect=<n>` is required, every named file must exist and parse, and the count of files
 * merged must equal it. Anything else exits 1 saying which file and why.
 *
 *   php bin/ci-merge-junit.php --out=junit-all.xml --expect=6 shards/junit-shard-*.xml
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
    $expect = -1;
    $inputs = [];

    foreach (array_slice($argv, 1) as $arg) {
        if (preg_match('/^--out=(.+)$/', $arg, $m) === 1) {
            $out = $m[1];
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
        fwrite(STDERR, "Usage: php bin/ci-merge-junit.php --out=<file> --expect=<n> <log> [<log> …]\n");
        exit(2);
    }

    exit(wpmcp_ci_merge_junit($inputs, $out, $expect) ? 0 : 1);
}

/**
 * @param string[] $inputs
 */
function wpmcp_ci_merge_junit(array $inputs, string $outPath, int $expect): bool
{
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
