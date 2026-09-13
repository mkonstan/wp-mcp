<?php
/**
 * The version number is stated in three places that must never disagree:
 *
 *   1. the `Version:` line of the plugin header in wp-mcp.php  (what WordPress shows,
 *      and what the update/release tooling reads)
 *   2. the WPMCP_VER constant                                  (what the code uses)
 *   3. the top `## x.y.z` heading in CHANGELOG.md               (what humans read)
 *
 * Releases are cut from a tag, so a stale header ships a plugin that lies about
 * itself and a changelog that documents a version nobody can install. Nothing else
 * in the repo enforces this; a single sed on one of the three is all it takes to
 * drift. This is the cheapest real invariant in the codebase.
 *
 * @group sprint-0
 */

declare(strict_types=1);

namespace WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WpMcp\Tests\Support\WordPressStubs;

final class VersionConsistencyTest extends TestCase
{
    private const SEMVER = '/^\d+\.\d+\.\d+$/';

    /**
     * @group sprint-0
     */
    public function testPluginHeaderVersionMatchesTopChangelogEntry(): void
    {
        $header    = self::pluginHeaderVersion();
        $changelog = self::topChangelogVersion();

        self::assertMatchesRegularExpression(self::SEMVER, $header, 'plugin header Version');
        self::assertMatchesRegularExpression(self::SEMVER, $changelog, 'top CHANGELOG heading');

        self::assertSame(
            $changelog,
            $header,
            "wp-mcp.php declares 'Version: {$header}' but the newest CHANGELOG.md entry is"
            . " '## {$changelog}'. Bump both, or move the changelog entry."
        );
    }

    /**
     * The constant the running code reports is the same string as the header.
     * This also proves the plugin loads under the minimal WordPress stub set.
     *
     * @group sprint-0
     */
    public function testRuntimeVersionConstantMatchesPluginHeader(): void
    {
        WordPressStubs::loadPlugin();

        self::assertTrue(defined('WPMCP_VER'), 'wp-mcp.php did not define WPMCP_VER');
        self::assertSame(
            self::pluginHeaderVersion(),
            constant('WPMCP_VER'),
            "The WPMCP_VER constant and the plugin header 'Version:' line disagree."
        );
    }

    /** The `Version:` line of the plugin header block in wp-mcp.php. */
    private static function pluginHeaderVersion(): string
    {
        $path   = WPMCP_PLUGIN_DIR . '/wp-mcp.php';
        $source = self::read($path);

        // WordPress itself only scans the first 8 KiB of the file for headers.
        $head = substr($source, 0, 8192);

        $matched = preg_match('/^[ \t\/*#@]*Version:\s*(.+)$/mi', $head, $m);
        self::assertSame(1, $matched, "No 'Version:' header found in {$path}");

        return trim($m[1]);
    }

    /** The first `## x.y.z` heading in CHANGELOG.md. */
    private static function topChangelogVersion(): string
    {
        $path   = WPMCP_PLUGIN_DIR . '/CHANGELOG.md';
        $source = self::read($path);

        $matched = preg_match('/^##\s+v?(\d+\.\d+\.\d+)/m', $source, $m);
        self::assertSame($matched, 1, "No '## x.y.z' heading found in {$path}");

        return $m[1];
    }

    private static function read(string $path): string
    {
        self::assertFileExists($path);
        $source = file_get_contents($path);
        self::assertIsString($source, "Could not read {$path}");

        return $source;
    }
}
