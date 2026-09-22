<?php
/**
 * THE FINGERPRINT HASHES EXACTLY WHAT SHIPS - AND THAT IS THE ONE WAY THE SPEED-UP CAN
 * BECOME UNSAFE.
 *
 * bin/code-fingerprint.sh decides whether a commit gets the full suite or inherits an
 * earlier green run (analysis/53-open-decisions.md, D17). The decision is only sound while
 * the set of files it hashes is the set of PHP files the zip carries. A shipped PHP file
 * that the script does not hash could be changed, the fingerprint would not move, and the
 * change would inherit a green run that never saw it. Nothing else in the repo would notice:
 * release.yml's `cmp` loop would still pass, because it compares the zip against the same
 * changed source.
 *
 * So this test reads both lists - the script's `code_paths` and release.yml's staging step -
 * and refuses to let them differ. It is a text comparison of two files on purpose: it must
 * hold on a laptop with no git, no bash and no site, which is what the unit tier is for.
 *
 * IT ALSO PINS THE ENVIRONMENT HALF, for the opposite failure: a fingerprint over the
 * shipped code alone would let a commit that rewrites the test suite, the container or the
 * workflow reuse a verdict taken under the previous one. "The code is identical" and "the
 * environment is identical" are two statements, and only the second one is about the thing
 * that ran.
 *
 * @group sprint-0
 */

declare(strict_types=1);

namespace WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class CodeFingerprintTest extends TestCase
{
    /**
     * @group sprint-0
     */
    public function testTheHashedFileSetIsTheStagedFileSet(): void
    {
        $hashed = self::hashedCodePaths();
        $staged = self::stagedShippedPhp();

        self::assertSame(
            $staged,
            $hashed,
            'bin/code-fingerprint.sh hashes a different set of PHP files than'
            . " release.yml stages into the zip.\n"
            . "  staged but not hashed: " . implode(', ', array_diff($staged, $hashed)) . "\n"
            . "  hashed but not staged: " . implode(', ', array_diff($hashed, $staged)) . "\n"
            . 'A shipped file the fingerprint does not see can be changed and still inherit'
            . ' somebody else\'s green run.'
        );
    }

    /**
     * Every PHP file the plugin loads at runtime is in the hashed set. The staging step and
     * the script could agree with each other and both be missing a file, which this catches
     * from a third direction: the filesystem.
     *
     * @group sprint-0
     */
    public function testNoShippedPhpFileIsMissingFromTheHashedSet(): void
    {
        $hashed = self::hashedCodePaths();

        $onDisk = array_map(
            static function ($path) { return basename($path); },
            (array) glob(WPMCP_PLUGIN_DIR . '/*.php')
        );

        sort($onDisk);

        foreach ($onDisk as $file) {
            self::assertContains(
                $file,
                $hashed,
                "{$file} sits in the plugin root and bin/code-fingerprint.sh does not hash it."
                . ' Either it ships and belongs in the list, or it does not ship and belongs'
                . ' somewhere other than the plugin root.'
            );
        }

        self::assertContains(
            'src',
            $hashed,
            'The src/ tree is not in the hashed set, so a class added there would be'
            . ' invisible to the fingerprint.'
        );
    }

    /**
     * A change to the suite, the container or the workflow must force a full run. These are
     * the paths D17 names as "the environment", and every one of them has to be in the ENV
     * half or a commit that rewrites the tests can reuse a verdict taken under the old ones.
     *
     * @group sprint-0
     */
    public function testTheEnvironmentHalfCoversWhatDecidesTheTestsAndTheContainer(): void
    {
        $script = self::read('bin/code-fingerprint.sh');

        self::assertMatchesRegularExpression(
            '/env_paths=\(\s*(.+?)\)/s',
            $script,
            'bin/code-fingerprint.sh has no env_paths list.'
        );

        preg_match('/env_paths=\(\s*(.+?)\)/s', $script, $m);
        $envPaths = preg_split('/\s+/', trim($m[1])) ?: [];

        foreach ([
            '.github/workflows',              // the jobs themselves, and the PHP matrix
            '.github/sprint-gate-groups.txt', // which gates are asserted at all
            '.wp-env.json',                   // the WordPress version and the container config
            'composer.json',
            'composer.lock',                  // PHPUnit's and Guzzle's own versions
            'phpunit.xml.dist',               // the suites, the strictness, the exclusions
            'tests',
            'bin',
        ] as $path) {
            self::assertContains(
                $path,
                $envPaths,
                "{$path} is not in the fingerprint's environment half. A commit that changes it"
                . ' would keep the same run key and could reuse a green verdict produced before'
                . ' the change.'
            );
        }
    }

    /**
     * @return string[] the entries of `code_paths=(...)`, sorted
     */
    private static function hashedCodePaths(): array
    {
        $script = self::read('bin/code-fingerprint.sh');

        self::assertMatchesRegularExpression(
            '/code_paths=\((.+?)\)/s',
            $script,
            'bin/code-fingerprint.sh has no code_paths list.'
        );

        preg_match('/code_paths=\((.+?)\)/s', $script, $m);

        $paths = preg_split('/\s+/', trim($m[1])) ?: [];
        sort($paths);

        return $paths;
    }

    /**
     * The PHP files release.yml copies into the zip, as `src` plus the flat names - the same
     * spelling code_paths uses, so the two can be compared without either side inventing a
     * normalisation.
     *
     * @return string[]
     */
    private static function stagedShippedPhp(): array
    {
        $yaml = self::read('.github/workflows/release.yml');

        self::assertMatchesRegularExpression(
            '/^\s*cp ((?:\S+\.php )+)dist\/wp-mcp\/$/m',
            $yaml,
            'release.yml no longer has a `cp <files>.php dist/wp-mcp/` staging line.'
        );

        preg_match('/^\s*cp ((?:\S+\.php )+)dist\/wp-mcp\/$/m', $yaml, $m);
        $files = preg_split('/\s+/', trim($m[1])) ?: [];

        self::assertStringContainsString(
            'cp src/*.php dist/wp-mcp/src/',
            $yaml,
            'release.yml no longer stages src/*.php, so the src/ tree may not ship at all.'
        );

        $files[] = 'src';
        sort($files);

        return $files;
    }

    private static function read(string $relative): string
    {
        $path     = WPMCP_PLUGIN_DIR . '/' . $relative;
        $contents = is_file($path) ? file_get_contents($path) : false;

        self::assertIsString($contents, "Could not read {$relative} at {$path}.");

        return (string) $contents;
    }
}
