<?php
/**
 * THE MERGE IS WHERE SHARDING CAN QUIETLY WEAKEN THE GATE, so it is strict and this proves it.
 *
 * The per-group check reads ONE log and asks whether every test in every closed gate group is in
 * it. Sharding means that log is now assembled, and every way of assembling it wrongly looks like
 * a smaller suite passing rather than like a failure: a shard that died, a shard that wrote a
 * truncated file, a shard that ran nothing because its filter matched nothing. So the merge
 * refuses all of them by name, and never "does its best with what arrived".
 *
 * @group sprint-0
 */

declare(strict_types=1);

namespace WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class CiMergeJunitTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        require_once WPMCP_PLUGIN_DIR . '/bin/ci-merge-junit.php';

        $this->dir = sys_get_temp_dir() . '/wpmcp-merge-' . bin2hex(random_bytes(6));

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
    public function testTwoShardsBecomeOneLogTheGroupCheckCanRead(): void
    {
        $a = $this->log('a.xml', [['Alpha', 'testOne'], ['Alpha', 'testTwo']]);
        $b = $this->log('b.xml', [['Beta', 'testThree', true]]);

        $out = $this->dir . '/merged.xml';
        [$ok, $printed] = $this->merge([$a, $b], $out, 2);

        self::assertTrue($ok, $printed);

        $xml   = simplexml_load_file($out);
        $cases = $xml->xpath('//testcase');

        self::assertCount(3, $cases);

        // The `class` attribute and the `<skipped>` child are exactly what
        // bin/ci-group-counts.php reads, so the merge has to carry them across intact.
        $ids = array_map(
            static function ($c) { return (string) $c['class'] . '::' . (string) $c['name']; },
            $cases
        );
        sort($ids);

        self::assertSame(['Alpha::testOne', 'Alpha::testTwo', 'Beta::testThree'], $ids);
        self::assertSame('3', (string) $xml['tests']);
        self::assertSame('1', (string) $xml['skipped']);

        $skipped = $xml->xpath('//testcase[@name="testThree"]/skipped');
        self::assertCount(1, $skipped, 'The skip did not survive the merge, so the gate would not see it.');
    }

    /**
     * ACCEPTANCE 3, in the tier that can stage it in a millisecond: a shard that died contributes
     * no log, and the merge must refuse rather than report a smaller suite as a complete one. CI
     * proves it too, on a real killed shard, because a claim about CI has to be made in CI.
     *
     * @group sprint-0
     */
    public function testAMissingShardLogIsRefusedRatherThanMergedAround(): void
    {
        $a = $this->log('a.xml', [['Alpha', 'testOne']]);

        [$ok, $printed] = $this->merge([$a], $this->dir . '/merged.xml', 6);

        self::assertFalse($ok, 'Five shards out of six must not merge into a green run.');
        self::assertStringContainsString('expected 6 shard logs and was given 1', $printed);
        self::assertFileDoesNotExist(
            $this->dir . '/merged.xml',
            'A refused merge must not leave a log behind for the next step to read.'
        );
    }

    /**
     * A shard whose file is there and whose content is not - a truncated write, a runner killed
     * mid-upload.
     *
     * @group sprint-0
     */
    public function testATruncatedShardLogIsRefused(): void
    {
        $a = $this->log('a.xml', [['Alpha', 'testOne']]);
        $b = $this->dir . '/b.xml';
        file_put_contents($b, "<?xml version=\"1.0\"?>\n<testsuites><testsuite name=\"x\"><testc");

        [$ok, $printed] = $this->merge([$a, $b], $this->dir . '/merged.xml', 2);

        self::assertFalse($ok);
        self::assertStringContainsString('is not parseable XML', $printed);
    }

    /**
     * A shard that ran NOTHING. This is what a filter that matched nothing looks like from the
     * merge's side, and it is the failure the `\x5c` escape in the planner exists to prevent -
     * so it must not be mistaken for a shard with no work to do.
     *
     * @group sprint-0
     */
    public function testAShardThatExecutedNothingIsRefused(): void
    {
        $a = $this->log('a.xml', [['Alpha', 'testOne']]);
        $b = $this->log('b.xml', []);

        [$ok, $printed] = $this->merge([$a, $b], $this->dir . '/merged.xml', 2);

        self::assertFalse($ok);
        self::assertStringContainsString('contains no testcase at all', $printed);
    }

    /**
     * The same test in two shards means the partition broke. Keeping one copy would hide it while
     * leaving every count correct.
     *
     * @group sprint-0
     */
    public function testATestInTwoShardsIsAnErrorRatherThanADeDuplication(): void
    {
        $a = $this->log('a.xml', [['Alpha', 'testOne']]);
        $b = $this->log('b.xml', [['Alpha', 'testOne']]);

        [$ok, $printed] = $this->merge([$a, $b], $this->dir . '/merged.xml', 2);

        self::assertFalse($ok);
        self::assertStringContainsString('appears in', $printed);
        self::assertStringContainsString('The shards are supposed to partition the suite', $printed);
    }

    /**
     * A CLASS THE PLAN LOST DOES NOT LOSE A LOG, and that is why this check exists. Every other
     * refusal here notices a missing FILE; a shard whose filter quietly failed to match one class
     * runs its other classes perfectly, uploads a log that parses, and never mentions the one it
     * dropped. Before this, the only thing that would have noticed was the per-group check, and
     * only if the lost class happened to carry a gate group.
     *
     * @group sprint-0
     */
    public function testAClassInTheMapAndInNoLogIsRefused(): void
    {
        $log = $this->log('a.xml', [['Alpha', 'testOne']]);
        $map = $this->map([['Alpha', 'testOne', 'sprint-0'], ['Lost', 'testTwo', 'sprint-0']]);

        [$ok, $printed] = $this->merge([$log], $this->dir . '/merged.xml', 1, $map);

        self::assertFalse($ok, 'A class that ran nowhere must not pass as a complete suite.');
        self::assertStringContainsString('are in the test map and in no shard', $printed);
        self::assertStringContainsString('never ran: Lost', $printed);
    }

    /**
     * The one class that is legitimately in the map and in no log: `--list-tests-xml` ignores the
     * config's group exclusions, so `InfraTrustTest` is listed on every run and runs on none. The
     * exclusion is READ from the config rather than guessed, so adding a second excluded group
     * needs no change here.
     *
     * @group sprint-0
     */
    public function testAClassWhoseEveryMethodIsInAnExcludedGroupMayBeAbsent(): void
    {
        $log    = $this->log('a.xml', [['Alpha', 'testOne']]);
        $map    = $this->map([['Alpha', 'testOne', 'sprint-0'], ['OnlyInfra', 'testTwo', 'infra-trust']]);
        $config = $this->config(['infra-trust']);

        [$ok, $printed] = $this->merge([$log], $this->dir . '/merged.xml', 1, $map, $config);

        self::assertTrue($ok, $printed);
        self::assertStringContainsString('excluded by config, correctly absent: OnlyInfra', $printed);

        // Without the config it has no way to know, so it refuses - which is the safe direction.
        [$blind] = $this->merge([$log], $this->dir . '/merged2.xml', 1, $map);

        self::assertFalse($blind, 'With no config, an absent class must be refused rather than assumed excluded.');
    }

    /**
     * A class with one excluded method and one ordinary one is EXPECTED - it runs, minus that
     * method. Getting this backwards would exempt half the suite from the check.
     *
     * @group sprint-0
     */
    public function testAClassWithOnlySomeMethodsExcludedIsStillExpected(): void
    {
        $log    = $this->log('a.xml', [['Alpha', 'testOne']]);
        $map    = $this->map([
            ['Alpha', 'testOne', 'sprint-0'],
            ['Mixed', 'testInfra', 'infra-trust'],
            ['Mixed', 'testNormal', 'sprint-0'],
        ]);
        $config = $this->config(['infra-trust']);

        [$ok, $printed] = $this->merge([$log], $this->dir . '/merged.xml', 1, $map, $config);

        self::assertFalse($ok);
        self::assertStringContainsString('never ran: Mixed', $printed);
    }

    /**
     * @param string[] $inputs
     *
     * @return array{0: bool, 1: string}
     */
    private function merge(array $inputs, string $out, int $expect, string $map = '', string $config = ''): array
    {
        ob_start();
        $ok = \wpmcp_ci_merge_junit($inputs, $out, $expect, $map, $config);

        return [$ok, (string) ob_get_clean()];
    }

    /**
     * A `--list-tests-xml` map.
     *
     * @param array<int, array{0: string, 1: string, 2: string}> $rows class, method, group
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
                $xml .= '  <testCaseMethod id="' . $class . '::' . $row[1]
                    . '" name="' . $row[1] . '" groups="' . $row[2] . "\"/>\n";
            }

            $xml .= " </testCaseClass>\n";
        }

        return $this->write('map-' . bin2hex(random_bytes(4)) . '.xml', $xml . "</tests>\n");
    }

    /**
     * A PHPUnit config carrying only the group exclusions, which is all the merge reads from it.
     *
     * @param string[] $excluded
     */
    private function config(array $excluded): string
    {
        $xml = "<?xml version=\"1.0\"?>\n<phpunit>\n  <groups>\n    <exclude>\n";

        foreach ($excluded as $group) {
            $xml .= '      <group>' . $group . "</group>\n";
        }

        return $this->write(
            'phpunit-' . bin2hex(random_bytes(4)) . '.xml',
            $xml . "    </exclude>\n  </groups>\n</phpunit>\n"
        );
    }

    /**
     * @param array<int, array{0: string, 1: string, 2?: bool}> $cases
     */
    private function log(string $name, array $cases): string
    {
        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<testsuites>\n  <testsuite name=\"shard\">\n";

        foreach ($cases as $case) {
            $open = '    <testcase name="' . htmlspecialchars($case[1], ENT_QUOTES)
                . '" class="' . $case[0] . '" time="0.5"';

            $xml .= isset($case[2]) && $case[2]
                ? $open . ">\n      <skipped/>\n    </testcase>\n"
                : $open . "/>\n";
        }

        return $this->write($name, $xml . "  </testsuite>\n</testsuites>\n");
    }

    private function write(string $name, string $contents): string
    {
        $path = $this->dir . '/' . $name;

        self::assertNotFalse(file_put_contents($path, $contents), "Could not write {$path}.");

        return $path;
    }
}
