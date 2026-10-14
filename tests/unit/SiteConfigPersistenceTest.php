<?php
/** Stored changes and effective reads answer different questions. */
declare(strict_types=1);

namespace WpMcp\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WpMcp\Tests\Support\SiteConfigOptionRows;
use WpMcp\Tests\Support\WordPressRuntime;
use WpMcp\Tests\Support\WordPressStubs;

final class SiteConfigPersistenceTest extends TestCase
{
    protected function setUp(): void
    {
        WordPressStubs::loadPlugin();
        WordPressRuntime::install();
        require_once WPMCP_PLUGIN_DIR . '/tests/Support/SiteConfigStoredOptionStub.php';
        $GLOBALS['wpdb'] = new SiteConfigOptionRows();
    }

    public function testRawRowsDistinguishNamesAndRefuseAnUnknownName(): void
    {
        WordPressRuntime::setOption('blogname', 'First');
        WordPressRuntime::setOption('blogdescription', 'Second');
        self::assertSame('First', wpmcp_raw_option('blogname'));
        self::assertSame('Second', wpmcp_raw_option('blogdescription'));
        self::assertNull(wpmcp_raw_option('missing_fixture'));
        self::assertSame('', $GLOBALS['wpdb']->last_error);
        self::assertStringContainsString("option_name = 'missing_fixture'", $GLOBALS['wpdb']->queries[2]);
    }

    public function testRowDoubleRejectsAnUnsupportedQuery(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('required: exact options-row SELECT by option_name');
        $GLOBALS['wpdb']->get_row('SELECT nonexistent_column FROM wp_options');
    }

    public function testQueryFailureIsDifferentFromAnAbsentRow(): void
    {
        self::assertNull(wpmcp_raw_option('missing_fixture'));
        self::assertSame('', $GLOBALS['wpdb']->last_error);
        $GLOBALS['wpdb']->failRead = 2;
        self::assertNull(wpmcp_raw_option('missing_fixture'));
        self::assertSame('fixture database read failure', $GLOBALS['wpdb']->last_error);
    }

    public static function unsupportedSerializedFixtures(): array
    {
        return [['a:0:{}'], ['O:8:"stdClass":0:{}'], ['s:3:"abc";'], ['N;'], [1], [[]], [false]];
    }

    #[DataProvider('unsupportedSerializedFixtures')]
    public function testRestrictedUnserializeDoubleRefusesUnsupportedValues(mixed $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('required: a plain scalar-row string');
        \maybe_unserialize($value);
    }

    public static function storedWrites(): array
    {
        return [
            'filtered effective value stays fixed' => [
                ['mode' => 'success', 'old' => 'Before', 'effective' => 'Forced', 'requested' => 'After'],
                'Before', 'After', true, 1, 'Forced',
            ],
            'false after saving is still a change' => [
                ['mode' => 'false_saved', 'old' => 'Before', 'effective' => 'Forced', 'requested' => 'After'],
                'Before', 'After', true, 1, 'Forced',
            ],
            'false after storing normalized value is still a change' => [
                ['mode' => 'false_saved', 'old' => 'Before', 'effective' => 'Forced', 'requested' => 'After', 'saved' => 'Normalized'],
                'Before', 'Normalized', true, 1, 'Forced',
            ],
            'successful sanitizer reports actual storage' => [
                ['mode' => 'success', 'old' => 'Before', 'effective' => 'Forced', 'requested' => 'After', 'saved' => 'Normalized'],
                'Before', 'Normalized', true, 1, 'Forced',
            ],
            'stored no-op ignores a different effective value' => [
                ['mode' => 'success', 'old' => 'After', 'effective' => 'Forced', 'requested' => 'After'],
                'After', 'After', false, 0, 'Forced',
            ],
            'absent string row retains null old value' => [
                ['mode' => 'success', 'requested' => 'After'], null, 'After', true, 1, 'After',
            ],
        ];
    }

    #[DataProvider('storedWrites')]
    public function testS3ReportsActualStoredChangesDespiteFilteredValuesOrFalseReturns(
        array $case, ?string $old, string $new, bool $changed, int $writes, string $effective
    ): void {
        $probe = $this->probe($case);
        self::assertSame(['name' => 'blogname', 'old_value' => $old, 'new_value' => $new, 'changed' => $changed], $probe['result']);
        self::assertCount($writes, $probe['writes']);
        self::assertSame($new, $probe['stored']);
        self::assertSame($effective, $probe['effective'], 'The effective-value fixture must really be active.');
        if ($writes) { self::assertSame([['option' => 'blogname', 'value' => $new]], $probe['writes']); }
        self::assertCount($writes ? 2 : 1, $probe['reads']);
    }

    public static function failedWrites(): array
    {
        return [
            [['mode' => 'failed', 'old' => 'Before', 'effective' => 'Forced', 'requested' => 'After']],
            [['mode' => 'success', 'old' => 'Before', 'effective' => 'After', 'requested' => 'After']],
        ];
    }

    #[DataProvider('failedWrites')]
    public function testUnchangedStorageDifferentFromRequestIsARealWriteFailure(array $case): void
    {
        $probe = $this->probe($case);
        self::assertSame('wpmcp_option_update_failed', $probe['result']['error']);
        self::assertSame('Before', $probe['stored']);
        self::assertSame([], $probe['writes']);
        self::assertSame($case['effective'], $probe['effective']);
        self::assertCount(2, $probe['reads']);
    }

    public static function readFailures(): array { return [[1], [2]]; }

    #[DataProvider('readFailures')]
    public function testReadFailureDistinguishesNoWriteFromUnknownAttemptedOutcome(int $read): void
    {
        $probe = $this->probe(['mode' => 'success', 'old' => 'Before', 'requested' => 'After', 'fail_read' => $read]);
        self::assertSame('wpmcp_option_read_failed', $probe['result']['error']);
        self::assertCount($read - 1, $probe['writes']);
        self::assertSame($read === 1 ? 'Before' : 'After', $probe['stored']);
        self::assertStringContainsString($read === 1 ? 'nothing was written' : 'it may have saved', $probe['result']['message']);
        if ($read === 2) { self::assertStringContainsString('before retrying', $probe['result']['message']); }
    }

    private function probe(array $case): array
    {
        $process = proc_open([PHP_BINARY, WPMCP_PLUGIN_DIR . '/tests/Support/SiteConfigOptionProbe.php',
            json_encode($case, JSON_THROW_ON_ERROR)], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $stdout . $stderr);
        self::assertSame(1, preg_match('/-----SITE-OPTION-----(.*?)-----END-----/s', $stdout, $match), $stdout . $stderr);
        return json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
    }
}
