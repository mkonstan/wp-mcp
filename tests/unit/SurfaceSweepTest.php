<?php
/**
 * The surfaces a sprint DELETED, swept for by the same greps the sprint brief used - run
 * over the whole repository, every time the suite runs. Sprint 7 removed two; sprint 8
 * removed a third.
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
 *   this file                         it carries the patterns.
 *   wpmcp_migrate_drop_address_column()  the migration that drops the column has to
 *                                     name the column. Only that function's source is
 *                                     cut out of wp-mcp.php, not the file - which is
 *                                     also why the function is not named after it.
 *   BoundIpDropMigrationTest.php      it puts the v2 column back so the migration has
 *                                     something to drop, and then checks it is gone.
 *   NoAddressBindingTest.php          it hands wpmcp_validate() a row still carrying
 *                                     the old column, which is the only way that test
 *                                     can fail against the code it rules out.
 *   wpmcp_migrate_sweep_stale_backups()  the sweep that collects the files the old code
 *                                     tools left on disk has to name the extension it
 *                                     looks for. Cut the same way, which is why that
 *                                     suffix is a local inside one function rather than
 *                                     a constant the whole plugin can reach.
 *   the zip line in release.yml       the exclusion keeps a stray backup out of a
 *                                     release and costs nothing now that the plugin
 *                                     writes none. ONE LINE of that file, not the file.
 *   tests/Support/Fixtures.php        the suite has to name the thing it proves gone.
 *                                     One support method spells it and every sprint-8
 *                                     test calls that, so this is one entry, not four.
 *
 * THIS TEST CAN FAIL. Put the word `bound_ip` in README.md, `/mcp/<token>` in
 * docs/CONNECT-CLIENTS.md, or a sentence about a backup file back in SECURITY.md, and it
 * goes red naming the file and line.
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

    /**
     * Files whose every line is exempt from EVERY pattern, and why - see the class
     * docblock. A pattern that needs one more says so itself, in its own test, so an
     * exemption earned by one sweep is never handed to the others.
     */
    private const SKIP_FILES = [
        'CHANGELOG.md',
        'tests/unit/SurfaceSweepTest.php',
        'tests/integration/BoundIpDropMigrationTest.php',
        'tests/unit/NoAddressBindingTest.php',
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
     * Sprint 8: nothing writes, reads, or promises a backup file beside a theme file.
     *
     * WHY THIS IS A GREP AND NOT A BEHAVIOURAL TEST. The integration tier proves that
     * code-write, code-delete and the parse-error revert leave no such file - it reads
     * the theme directory afterwards. What it cannot prove is that no OTHER path still
     * makes one, and it cannot prove anything at all about the prose. SECURITY.md
     * promising "the backup", or README describing the path it was written to, is how an
     * operator comes to believe their previous theme file is on disk where they can find
     * it. It is not; it is in the database, and looking in the wrong place for the only
     * copy of something is a worse failure than not knowing there was one.
     *
     * `bak_ok` is in the pattern because wpmcp_bak_ok() was the jail check on that path,
     * and both CI workflows asserted its occurrence count - a guard that would have gone
     * on passing over a function with nothing left to guard.
     *
     * @group sprint-8
     */
    public function testNothingStillWritesOrPromisesABackupFileBesideATheme(): void
    {
        $hits = self::sweep(
            '#[.]bak|bak_ok#i',
            [
                'tests/Support/Fixtures.php' => true,
                '.github/workflows/release.yml' => ['zip -r wp-mcp.zip'],
            ],
            ['wp-mcp.php' => ['wpmcp_migrate_sweep_stale_backups']]
        );

        self::assertSame(
            [],
            $hits,
            "A backup file beside a theme file survives in:\n" . implode("\n", $hits)
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
    private static function sweep(
        string $pattern,
        array $exemptions = [],
        array $cutFunctions = ['wp-mcp.php' => ['wpmcp_migrate_drop_address_column']]
    ): array {
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

            if (($exemptions[$relative] ?? null) === true) {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            foreach ($cutFunctions[$relative] ?? [] as $function) {
                $source = self::withoutFunction($source, $function);
            }

            $allowedLines = is_array($exemptions[$relative] ?? null)
                ? $exemptions[$relative]
                : [];

            foreach (explode("\n", $source) as $index => $line) {
                if (!preg_match($pattern, $line)) {
                    continue;
                }

                foreach ($allowedLines as $allowed) {
                    if (str_contains($line, $allowed)) {
                        continue 2;
                    }
                }

                $hits[] = $relative . ':' . ($index + 1) . ': ' . trim($line);
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
     * $source with one named function - ITS DOCBLOCK INCLUDED - replaced by blank lines,
     * so line numbers in a hit still point at the real line.
     *
     * THE DOCBLOCK IS PART OF THE CUT, because a migration that has to name a thing has
     * to explain why it names it, and an exemption covering the code but not the
     * explanation would push the explanation out of the file. The scan walks back from
     * the `function` keyword to the docblock immediately above it, and takes it only when
     * nothing but whitespace lies between the two.
     *
     * Balanced-brace scan from the function's opening brace rather than a regex over
     * the whole file: a regex that stops at the first `}` would cut the function in
     * half and let the rest of it through the sweep, which is the failure mode that
     * would make this exemption a hole.
     */
    private static function withoutFunction(string $source, string $name): string
    {
        $keyword = strpos($source, 'function ' . $name . '(');

        if ($keyword === false) {
            return $source;
        }

        // THE BRACE IS FOUND FROM THE KEYWORD, NOT FROM THE CUT'S START, and that order
        // is load-bearing: a docblock can contain braces of its own - `@return
        // array{found:int}` does - and a scan begun inside one balances on the wrong pair
        // and cuts the docblock alone, leaving the function's body in the sweep.
        $brace = strpos($source, '{', $keyword);

        if ($brace === false) {
            return $source;
        }

        $start    = $keyword;
        $docblock = strrpos(substr($source, 0, $keyword), '/**');

        if ($docblock !== false) {
            $between = substr($source, $docblock, $keyword - $docblock);

            if (str_ends_with(rtrim($between), '*/')) {
                $start = $docblock;
            }
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
