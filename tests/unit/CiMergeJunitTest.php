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
     * @param string[] $inputs
     *
     * @return array{0: bool, 1: string}
     */
    private function merge(array $inputs, string $out, int $expect): array
    {
        ob_start();
        $ok = \wpmcp_ci_merge_junit($inputs, $out, $expect);

        return [$ok, (string) ob_get_clean()];
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

        $path = $this->dir . '/' . $name;

        self::assertNotFalse(
            file_put_contents($path, $xml . "  </testsuite>\n</testsuites>\n"),
            "Could not write {$path}."
        );

        return $path;
    }
}
