<?php
/**
 * THE SHARD PLAN MUST BE A PARTITION, AND THAT IS THE ONLY THING WORTH TESTING HERE.
 *
 * Sharding the integration tier (D18) is a speed-up, and a speed-up that loses a class is not a
 * slower gate, it is a gate with a hole: the lost tests never run, every shard is green, and the
 * merged log is short by exactly the tests nobody was watching. CI catches that downstream - the
 * merged map-vs-log row count in bin/ci-group-counts.php - but downstream is a seventy-minute
 * round trip, and this property is decidable in a millisecond from the plan itself.
 *
 * So: every class in exactly one shard, no class in none, the same answer on every machine, and
 * a filter that distinguishes two classes whose short names are identical - which this suite
 * really has (`ToolContractTest` exists in both `Unit` and `Integration`).
 *
 * @group sprint-0
 */

declare(strict_types=1);

namespace WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class CiShardsTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        require_once WPMCP_PLUGIN_DIR . '/bin/ci-shards.php';

        $this->dir = sys_get_temp_dir() . '/wpmcp-shards-' . bin2hex(random_bytes(6));

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
     * @group sprint-0
     */
    public function testEveryClassLandsInExactlyOneShard(): void
    {
        $classes = [];

        for ($i = 1; $i <= 40; $i++) {
            $classes['WpMcp\\Tests\\Integration\\Class' . $i] = $i * 3;
        }

        $plan = \wpmcp_ci_shard_plan($this->map(array_keys($classes)), $this->timings($classes), 6);

        $seen = [];

        foreach ($plan['shards'] as $shard => $bin) {
            foreach ($bin['classes'] as $class) {
                self::assertArrayNotHasKey(
                    $class,
                    $seen,
                    "{$class} is in shard {$seen[$class]} and in shard {$shard}. Two shards running"
                    . ' the same class is wasted time; the failure it hides is the opposite one.'
                );
                $seen[$class] = $shard;
            }
        }

        // Sets, not sequences: the shards are sorted within themselves and the plan is free to
        // put any class anywhere. What must hold is that nothing was lost.
        $expected = array_keys($classes);
        $actual   = array_keys($seen);
        sort($expected);
        sort($actual);

        self::assertSame(
            $expected,
            $actual,
            'The union of the shards is not the set of classes. A class in no shard never runs,'
            . ' and every shard is green.'
        );
    }

    /**
     * The plan is computed independently by every shard job, so two machines that disagree would
     * run overlapping or incomplete sets while each believed it was following the plan.
     *
     * @group sprint-0
     */
    public function testThePlanIsTheSameEveryTimeItIsComputed(): void
    {
        $classes = [
            'WpMcp\\Tests\\Integration\\Alpha' => 10,
            'WpMcp\\Tests\\Integration\\Beta'  => 10,
            'WpMcp\\Tests\\Integration\\Gamma' => 10,
            'WpMcp\\Tests\\Unit\\Delta'        => 10,
        ];

        $map     = $this->map(array_keys($classes));
        $timings = $this->timings($classes);

        $first  = \wpmcp_ci_shard_plan($map, $timings, 2);
        $second = \wpmcp_ci_shard_plan($map, $timings, 2);

        self::assertSame(
            array_map(static function ($b) { return $b['classes']; }, $first['shards']),
            array_map(static function ($b) { return $b['classes']; }, $second['shards']),
            'Equal weights are broken by name precisely so the plan does not depend on hash order.'
        );
    }

    /**
     * Longest-processing-time-first: the heaviest class must not end up beside another heavy one
     * while a shard sits idle.
     *
     * @group sprint-0
     */
    public function testTheHeaviestClassesAreSpreadRatherThanStacked(): void
    {
        $classes = [
            'WpMcp\\Tests\\Integration\\Heavy1' => 200,
            'WpMcp\\Tests\\Integration\\Heavy2' => 200,
            'WpMcp\\Tests\\Integration\\Light1' => 1,
            'WpMcp\\Tests\\Integration\\Light2' => 1,
        ];

        $plan = \wpmcp_ci_shard_plan($this->map(array_keys($classes)), $this->timings($classes), 2);

        foreach ($plan['shards'] as $bin) {
            self::assertCount(
                1,
                array_filter($bin['classes'], static function ($c) { return strpos($c, 'Heavy') !== false; }),
                'Both heavy classes landed on one shard, which is the whole failure mode balancing exists to avoid.'
            );
        }
    }

    /**
     * A class the weights file has never heard of still RUNS, at the mean, and is named so the
     * drift is visible. Zero would quietly pile every new class onto one shard.
     *
     * @group sprint-0
     */
    public function testAClassWithNoWeightStillRunsAndIsNamed(): void
    {
        $classes = [
            'WpMcp\\Tests\\Integration\\Known1' => 100,
            'WpMcp\\Tests\\Integration\\Known2' => 100,
        ];

        $map  = $this->map(array_merge(array_keys($classes), ['WpMcp\\Tests\\Integration\\BrandNew']));
        $plan = \wpmcp_ci_shard_plan($map, $this->timings($classes), 2);

        $all = array_merge(...array_map(static function ($b) { return $b['classes']; }, $plan['shards']));

        self::assertContains('WpMcp\\Tests\\Integration\\BrandNew', $all);
        self::assertSame(['WpMcp\\Tests\\Integration\\BrandNew'], $plan['unweighted']);
        self::assertSame(100.0, $plan['mean']);
        self::assertStringContainsString('BrandNew', \wpmcp_ci_shard_plan_report($plan));
    }

    /**
     * More shards than classes leaves an empty bin, which the CLI refuses rather than letting a
     * shard report "no tests executed" as a pass.
     *
     * @group sprint-0
     */
    public function testMoreShardsThanClassesLeavesAnEmptyBinForTheCliToRefuse(): void
    {
        $classes = ['WpMcp\\Tests\\Unit\\Only' => 5];

        $plan = \wpmcp_ci_shard_plan($this->map(array_keys($classes)), $this->timings($classes), 3);

        $empty = array_filter($plan['shards'], static function ($b) { return $b['classes'] === []; });

        self::assertCount(2, $empty);
    }

    /**
     * THE COLLISION THIS SUITE ACTUALLY HAS. `ToolContractTest` exists in `Unit` and in
     * `Integration`; a filter built from short names would run both wherever either was planned,
     * and the merge would then see the same test twice.
     *
     * @group sprint-0
     */
    public function testTheFilterIsNamespaceQualifiedAndUsesTheEscapeThatSurvivesAShell(): void
    {
        $filter = \wpmcp_ci_shard_filter([
            'WpMcp\\Tests\\Unit\\ToolContractTest',
            'WpMcp\\Tests\\Integration\\HandshakeTest',
        ]);

        self::assertStringContainsString('WpMcp\x5cTests\x5cUnit\x5c(ToolContractTest)', $filter);
        self::assertStringContainsString('WpMcp\x5cTests\x5cIntegration\x5c(HandshakeTest)', $filter);
        self::assertStringEndsWith('::', $filter);

        // A literal backslash cannot survive a shell and a regex reliably, and `\T` reads as a
        // PCRE escape. `\x5c` is the spelling that works; a bare one would be a filter that
        // matches nothing, which is a shard that runs nothing and calls itself green.
        self::assertStringNotContainsString(
            '\\\\',
            $filter,
            'The filter carries a literal backslash. See bin/ci-shards.php for why that fails.'
        );

        // And it must really match the fully-qualified name PHPUnit reports.
        self::assertMatchesRegularExpression(
            '#' . str_replace('\x5c', '\\\\', $filter) . '#',
            'WpMcp\\Tests\\Unit\\ToolContractTest::testSomething'
        );
        self::assertDoesNotMatchRegularExpression(
            '#' . str_replace('\x5c', '\\\\', $filter) . '#',
            'WpMcp\\Tests\\Integration\\ToolContractTest::testSomething'
        );
    }

    /**
     * THE TWO PLACES THE SHARD COUNT IS WRITTEN MUST AGREE. The matrix decides how many shard
     * jobs GitHub starts; `WPMCP_SHARDS` decides how the suite is divided and how many logs the
     * merge demands. Drift between them is not a crash: seven jobs dividing the suite six ways
     * means one sixth runs twice and the merge sees a duplicate, and six jobs dividing it seven
     * ways means a seventh of the suite never runs at all. The merge catches both - by filename
     * count and by duplicate id - but it catches them an hour later, and this catches them here.
     *
     * @group sprint-0
     */
    public function testTheShardMatrixAndTheDeclaredShardCountAgree(): void
    {
        $yaml = (string) file_get_contents(WPMCP_PLUGIN_DIR . '/.github/workflows/ci.yml');

        self::assertMatchesRegularExpression('/^        shard: \[([0-9, ]+)\]$/m', $yaml, 'ci.yml has no shard matrix.');
        preg_match('/^        shard: \[([0-9, ]+)\]$/m', $yaml, $m);

        $matrix = array_map('intval', array_map('trim', explode(',', $m[1])));

        self::assertSame(
            range(1, count($matrix)),
            $matrix,
            'The shard matrix must be 1..n with no gaps: bin/ci-shards.php numbers its bins that way.'
        );

        preg_match_all("/WPMCP_SHARDS: '(\\d+)'/", $yaml, $declared);

        self::assertNotEmpty($declared[1], 'ci.yml never declares WPMCP_SHARDS.');
        self::assertSame(
            [(string) count($matrix)],
            array_values(array_unique($declared[1])),
            'WPMCP_SHARDS and the length of the shard matrix disagree, or WPMCP_SHARDS is'
            . ' spelled differently in different jobs.'
        );
    }

    /** @param string[] $classes */
    private function map(array $classes): string
    {
        $xml = "<?xml version=\"1.0\"?>\n<tests>\n";

        foreach ($classes as $class) {
            $xml .= ' <testCaseClass name="' . htmlspecialchars($class, ENT_QUOTES) . "\">\n"
                . '  <testCaseMethod id="' . htmlspecialchars($class . '::testOne', ENT_QUOTES)
                . '" name="testOne" groups="sprint-0"/>' . "\n"
                . " </testCaseClass>\n";
        }

        return $this->write('map.xml', $xml . "</tests>\n");
    }

    /** @param array<string, int|float> $weights */
    private function timings(array $weights): string
    {
        $lines = ['# generated by a test'];

        foreach ($weights as $class => $weight) {
            $lines[] = $class . "\t" . $weight;
        }

        return $this->write('timings.txt', implode("\n", $lines) . "\n");
    }

    private function write(string $name, string $contents): string
    {
        $path = $this->dir . '/' . $name;

        self::assertNotFalse(file_put_contents($path, $contents), "Could not write {$path}.");

        return $path;
    }
}
