<?php
/**
 * SPLIT THE SUITE ACROSS SHARDS, BY MEASURED COST, WITHOUT LOSING A CLASS.
 *
 * The integration tier runs on one machine for about seventy minutes. Sharding it is D18: each
 * shard starts its own wp-env container, runs a disjoint set of test CLASSES, and writes its own
 * JUnit log; a final job merges the logs and runs the existing per-group check over the whole
 * picture (bin/ci-group-counts.php). Billed minutes go up, waiting time comes down.
 *
 * THE ONE PROPERTY THAT MATTERS HERE: the shards must PARTITION the suite. Every class in
 * exactly one shard, no class in none. So the class list comes from `phpunit --list-tests-xml`,
 * which is PHPUnit's own answer to "what exists", and NEVER from the weights file - a class
 * absent from the weights still runs, on the mean weight, and is named in the plan. A planner
 * that read its class list from a hand-maintained file would silently stop running whatever
 * somebody forgot to add, and the merged log would be short by exactly the tests nobody missed.
 * That failure is caught downstream too (the group check compares the map's row count against
 * the log's), which is belt and braces on purpose.
 *
 * BALANCING is longest-processing-time-first greedy: sort classes by weight descending, put each
 * on the lightest shard so far. For this suite that is within a few per cent of optimal and the
 * heaviest single class (214 s) is well under a shard's share, so no class decides the wall clock
 * on its own. See tests/class-timings.txt for where the weights come from and why they are a
 * model rather than a measurement.
 *
 * THE FILTER FORM IS NOT OBVIOUS, so it is written down. PHPUnit 10.5's `--filter` is a regex
 * over the fully-qualified `Class::method`, but:
 *   - `/…/` delimiters make it match nothing; bare or `#…#` works.
 *   - a literal backslash cannot survive the trip through a shell and a regex reliably, and
 *     `\T` in `WpMcp\Tests` is read by PCRE as an escape. `\x5c` is the namespace separator that
 *     works everywhere - verified against this suite, including the two DIFFERENT classes both
 *     called `ToolContractTest` (one unit, one integration), which is also why the filter is
 *     built per namespace and never from short names.
 *
 *   php bin/ci-shards.php --map=junit-map.xml --timings=tests/class-timings.txt \
 *       --shards=6 --shard=3          # the --filter for shard 3
 *   php bin/ci-shards.php --map=… --timings=… --shards=6 --plan
 *
 * Exit 0 on success; 1 with an explanation on anything that would make a shard meaningless (a
 * shard with no classes, a shard index out of range, an unreadable map).
 */

declare(strict_types=1);

if (PHP_SAPI === 'cli' && isset($argv) && realpath($argv[0]) === realpath(__FILE__)) {
    $parsed = [];

    foreach (array_slice($argv, 1) as $arg) {
        if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m) !== 1) {
            fwrite(STDERR, "Unrecognised argument: {$arg}\n");
            exit(2);
        }
        $parsed[$m[1]] = $m[2] ?? '1';
    }

    foreach (['map', 'timings', 'shards'] as $required) {
        if (!isset($parsed[$required]) || $parsed[$required] === '') {
            fwrite(
                STDERR,
                "Usage: php bin/ci-shards.php --map=<list-tests-xml> --timings=<file>"
                . " --shards=<n> (--shard=<k> | --plan)\n"
            );
            exit(2);
        }
    }

    $shards = (int) $parsed['shards'];
    $plan   = wpmcp_ci_shard_plan($parsed['map'], $parsed['timings'], $shards);

    if (isset($parsed['plan'])) {
        echo wpmcp_ci_shard_plan_report($plan);
        exit(0);
    }

    if (!isset($parsed['shard'])) {
        fwrite(STDERR, "Give either --shard=<k> or --plan.\n");
        exit(2);
    }

    $k = (int) $parsed['shard'];

    if ($k < 1 || $k > $shards) {
        fwrite(STDERR, "::error::shard {$k} is outside 1..{$shards}.\n");
        exit(1);
    }

    if ($plan['shards'][$k]['classes'] === []) {
        fwrite(
            STDERR,
            "::error::shard {$k} of {$shards} would run NO classes. A shard that runs nothing"
            . " looks green and proves nothing - use fewer shards than there are classes.\n"
        );
        exit(1);
    }

    echo wpmcp_ci_shard_filter($plan['shards'][$k]['classes']) . "\n";
    exit(0);
}

/**
 * @return array{shards: array<int, array{classes: string[], weight: float}>,
 *               unweighted: string[], total: float, mean: float}
 */
function wpmcp_ci_shard_plan(string $mapPath, string $timingsPath, int $shards): array
{
    if ($shards < 1) {
        fwrite(STDERR, "::error::--shards must be at least 1.\n");
        exit(1);
    }

    $classes = wpmcp_ci_shard_classes($mapPath);
    $weights = wpmcp_ci_shard_weights($timingsPath);

    if ($classes === []) {
        fwrite(STDERR, "::error::{$mapPath} names no test classes at all.\n");
        exit(1);
    }

    $known = array_filter(
        array_map(static function ($c) use ($weights) { return $weights[$c] ?? null; }, $classes),
        static function ($w) { return $w !== null; }
    );

    // A class nobody weighed is given the mean rather than zero: zero would quietly pile every
    // new class onto one shard, which is the failure that looks like bad luck.
    $mean = $known === [] ? 1.0 : array_sum($known) / count($known);

    $unweighted = [];
    $weighted   = [];

    foreach ($classes as $class) {
        if (isset($weights[$class])) {
            $weighted[$class] = $weights[$class];
        } else {
            $weighted[$class] = $mean;
            $unweighted[]     = $class;
        }
    }

    // Longest processing time first, ties broken by name so the plan is the same on every
    // machine and every shard computes the identical partition.
    uksort($weighted, static function ($a, $b) use ($weighted) {
        return $weighted[$b] <=> $weighted[$a] ?: strcmp($a, $b);
    });

    $bins = [];
    for ($i = 1; $i <= $shards; $i++) {
        $bins[$i] = ['classes' => [], 'weight' => 0.0];
    }

    foreach ($weighted as $class => $weight) {
        $lightest = 1;
        for ($i = 2; $i <= $shards; $i++) {
            if ($bins[$i]['weight'] < $bins[$lightest]['weight']) {
                $lightest = $i;
            }
        }
        $bins[$lightest]['classes'][] = $class;
        $bins[$lightest]['weight']   += $weight;
    }

    foreach ($bins as $i => $bin) {
        sort($bins[$i]['classes']);
    }

    return [
        'shards'     => $bins,
        'unweighted' => $unweighted,
        'total'      => array_sum($weighted),
        'mean'       => $mean,
    ];
}

/**
 * The PHPUnit `--filter` for one shard: an alternation per namespace, anchored, with `\x5c` for
 * every namespace separator. Short names are never used - two classes in this suite share one.
 *
 * @param string[] $classes
 */
function wpmcp_ci_shard_filter(array $classes): string
{
    $byNamespace = [];

    foreach ($classes as $class) {
        $pos       = strrpos($class, '\\');
        $namespace = $pos === false ? '' : substr($class, 0, $pos);
        $short     = $pos === false ? $class : substr($class, $pos + 1);

        $byNamespace[$namespace][] = preg_quote($short, '#');
    }

    $parts = [];

    foreach ($byNamespace as $namespace => $shorts) {
        sort($shorts);

        // Each SEGMENT is quoted on its own and the separators are put back as `\x5c`. Quoting
        // the whole namespace and replacing afterwards does not work: preg_quote turns one
        // backslash into two, and the replacement then emits `\x5c\x5c`, which matches a
        // namespace nobody has. That was the first version, and it matched no tests at all -
        // which the shard would have reported as a green run of nothing, if the merged
        // map-vs-log check downstream did not count rows.
        $segments = array_map(
            static function ($segment) { return preg_quote($segment, '#'); },
            explode('\\', $namespace)
        );

        $parts[] = implode('\x5c', $segments) . '\x5c(' . implode('|', $shorts) . ')';
    }

    sort($parts);

    return '^(' . implode('|', $parts) . ')::';
}

/**
 * @param array{shards: array<int, array{classes: string[], weight: float}>,
 *              unweighted: string[], total: float, mean: float} $plan
 */
function wpmcp_ci_shard_plan_report(array $plan): string
{
    $out    = '';
    $count  = count($plan['shards']);
    $ideal  = $plan['total'] / max(1, $count);
    $slowest = 0.0;

    foreach ($plan['shards'] as $i => $bin) {
        $slowest = max($slowest, $bin['weight']);
        $out .= sprintf(
            "shard %d/%d: %2d classes, modelled %5.0f s (ideal %.0f s)\n",
            $i,
            $count,
            count($bin['classes']),
            $bin['weight'],
            $ideal
        );
        foreach ($bin['classes'] as $class) {
            $out .= '    ' . $class . "\n";
        }
    }

    $out .= sprintf(
        "total modelled %.0f s; slowest shard %.0f s; imbalance %+.1f%% against the ideal\n",
        $plan['total'],
        $slowest,
        $ideal > 0 ? ($slowest - $ideal) / $ideal * 100 : 0
    );

    if ($plan['unweighted'] !== []) {
        $out .= sprintf(
            "%d class(es) have no weight and were placed at the mean (%.0f s) - refresh"
            . " tests/class-timings.txt when convenient:\n",
            count($plan['unweighted']),
            $plan['mean']
        );
        foreach ($plan['unweighted'] as $class) {
            $out .= '    ' . $class . "\n";
        }
    }

    return $out;
}

/** @return string[] every class the map names, in the map's order */
function wpmcp_ci_shard_classes(string $path): array
{
    $xml = wpmcp_ci_shard_read($path);
    $out = [];

    foreach ($xml->testCaseClass as $class) {
        $name = (string) $class['name'];

        if ($name !== '') {
            $out[] = $name;
        }
    }

    return array_values(array_unique($out));
}

/** @return array<string, float> class => weight in seconds */
function wpmcp_ci_shard_weights(string $path): array
{
    if (!is_file($path)) {
        fwrite(STDERR, "::error::the weights file is not at {$path}.\n");
        exit(1);
    }

    $out = [];

    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line, "\r\n");

        if ($line === '' || $line[0] === '#') {
            continue;
        }

        $parts = explode("\t", $line);

        if (count($parts) !== 2 || !is_numeric($parts[1])) {
            continue;
        }

        $out[trim($parts[0])] = (float) $parts[1];
    }

    return $out;
}

function wpmcp_ci_shard_read(string $path): SimpleXMLElement
{
    if (!is_file($path)) {
        fwrite(STDERR, "::error::the test map is not at {$path}. Whatever writes it did not.\n");
        exit(1);
    }

    $xml = simplexml_load_file($path);

    if ($xml === false) {
        fwrite(STDERR, "::error::the test map at {$path} is not parseable XML.\n");
        exit(1);
    }

    return $xml;
}
