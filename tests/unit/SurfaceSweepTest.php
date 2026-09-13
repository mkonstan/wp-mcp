<?php
/**
 * The two surfaces Sprint 7 deleted, swept for by the same greps the sprint brief
 * used - run over the whole repository, every time the suite runs.
 *
 * WHY A GREP IS A TEST HERE. Both removals are the kind that a behavioural test
 * cannot finish. "The path route is gone" is proved by one 404; "no file in this
 * repository still tells somebody to build that URL" is not, and the places that
 * still would - a README paragraph, a docblock, a shell script's comment, an admin
 * page's hint - are exactly where a reader learns the wrong thing and then reports
 * the plugin as broken. The same is true of the IP pin: the code can be gone while
 * SECURITY.md still promises pinning.
 *
 * THE ALLOW LIST IS SHORT AND EACH ENTRY IS A REASON, not an exemption:
 *
 *   CHANGELOG.md                      history. It has to say what was removed.
 *   wpmcp_migrate_drop_bound_ip()     the migration that drops the column has to
 *                                     name the column. Only that function's source
 *                                     is cut out of wp-mcp.php, not the file.
 *   this file                         it carries the patterns.
 *   TokenLifetimeMigrationTest.php    it builds a v2-shaped table, bound_ip and all,
 *                                     so the drop has something to drop.
 *
 * THIS TEST CAN FAIL. Put the word `bound_ip` in README.md, or `/mcp/<token>` in
 * docs/CONNECT-CLIENTS.md, and it goes red naming the file and line.
 *
 * @group sprint-7
 */

declare(strict_types=1);

namespace WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class SurfaceSweepTest extends TestCase
{
    /** Directories never swept: not ours, or generated. */
    private const SKIP_DIRS = ['vendor', '.git', '.phpunit.cache', '.local-bin', 'node_modules'];

    /** Files whose every line is exempt, and why - see the class docblock. */
    private const SKIP_FILES = [
        'CHANGELOG.md',
        'tests/unit/SurfaceSweepTest.php',
        'tests/integration/TokenLifetimeMigrationTest.php',
    ];

    /**
     * Extensions swept. Everything a human or a machine reads: code, prose, config.
     * A binary is not swept, because a match in one would be noise.
     */
    private const SWEEP_EXTENSIONS = ['php', 'md', 'sh', 'yml', 'yaml', 'json', 'txt', 'dist', 'xml'];

    /**
     * Item 1: the token no longer travels in a URL.
     *
     * The three shapes are the literal route, the placeholder spelling used in prose,
     * and the admin page's way of building one.
     *
     * @group sprint-7
     */
    public function testNothingStillBuildsOrDocumentsATokenInTheUrl(): void
    {
        $hits = self::sweep(
            '#mcp/[a-f0-9]{64}|/mcp/\{token\}|mcp/<token>|\$res\[.raw.\]\s*\)|wpmcp_endpoint_url\(./.#'
        );

        self::assertSame(
            [],
            $hits,
            "The path-URL form of the credential survives in:\n" . implode("\n", $hits)
        );
    }

    /**
     * Item 2: there is no IP pin, in code or in prose.
     *
     * @group sprint-7
     */
    public function testNothingStillMentionsTheIpPin(): void
    {
        $hits = self::sweep('#bound_ip|pin_bind|ip_mismatch|tofu|bind_token_ip|pinned|IP pin#i');

        self::assertSame(
            [],
            $hits,
            "The IP pin survives in:\n" . implode("\n", $hits)
        );
    }

    /**
     * The sweep itself is checked against a string it must find, because a sweep that
     * silently walks nothing passes both tests above and proves nothing at all.
     *
     * @group sprint-7
     */
    public function testTheSweepActuallyReadsTheRepository(): void
    {
        // The plugin header's own name, which cannot vanish while this plugin exists.
        $hits = self::sweep('#Plugin Name: WP MCP#');

        self::assertNotSame([], $hits, 'The sweep read no files, so it can never fail.');
        self::assertStringStartsWith('wp-mcp.php:', $hits[0]);
    }

    /**
     * Every `file:line: text` in the repository matching $pattern, allow list applied.
     *
     * @return list<string>
     */
    private static function sweep(string $pattern): array
    {
        $root = WPMCP_PLUGIN_DIR;
        $hits = [];

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));

            if ($file->isDir()) {
                continue;
            }

            if (self::isSkipped($relative, $file)) {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if ($relative === 'wp-mcp.php') {
                $source = self::withoutMigration($source);
            }

            foreach (explode("\n", $source) as $index => $line) {
                if (preg_match($pattern, $line)) {
                    $hits[] = $relative . ':' . ($index + 1) . ': ' . trim($line);
                }
            }
        }

        sort($hits);

        return $hits;
    }

    private static function isSkipped(string $relative, SplFileInfo $file): bool
    {
        foreach (self::SKIP_DIRS as $dir) {
            if ($relative === $dir || str_starts_with($relative, $dir . '/')) {
                return true;
            }
        }

        if (in_array($relative, self::SKIP_FILES, true)) {
            return true;
        }

        return !in_array(strtolower($file->getExtension()), self::SWEEP_EXTENSIONS, true);
    }

    /**
     * wp-mcp.php with the body of wpmcp_migrate_drop_bound_ip() replaced by blank
     * lines, so line numbers in a hit still point at the real line.
     *
     * Balanced-brace scan from the function's opening brace rather than a regex over
     * the whole file: a regex that stops at the first `}` would cut the function in
     * half and let the rest of it through the sweep, which is the failure mode that
     * would make this exemption a hole.
     */
    private static function withoutMigration(string $source): string
    {
        $start = strpos($source, 'function wpmcp_migrate_drop_bound_ip(');

        if ($start === false) {
            return $source;
        }

        $brace = strpos($source, '{', $start);

        if ($brace === false) {
            return $source;
        }

        $depth = 0;
        $end   = $brace;

        for ($i = $brace, $len = strlen($source); $i < $len; $i++) {
            if ($source[$i] === '{') { $depth++; }
            if ($source[$i] === '}') {
                $depth--;
                if ($depth === 0) { $end = $i; break; }
            }
        }

        $body = substr($source, $start, $end - $start + 1);

        return substr_replace(
            $source,
            str_repeat("\n", substr_count($body, "\n")),
            $start,
            strlen($body)
        );
    }
}
