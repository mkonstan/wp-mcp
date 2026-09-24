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
 * AND SINCE 1.1.2 IT PINS THE SPLIT. bin/code-fingerprint.sh hashes the shipped PHP three
 * times: `core` over everything but modules/, `modules` over modules/ alone, and `code` over
 * the union - which is what the reuse decision still reads, so splitting the halves changed no
 * verdict (D23 condition 4). The failure the split could introduce is a path that falls in
 * NEITHER half, or in both, and the union assertion below is what catches it: `code_paths` must
 * be exactly the two arrays concatenated, spelled as a concatenation rather than typed out a
 * third time, or the number the reuse is decided on could quietly stop covering a file.
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
use WpMcp\Tests\Support\RepoFile;

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
     * The code half is the two halves, spelled as their concatenation.
     *
     * A THIRD LIST WOULD BE THE BUG. If `code_paths` were typed out again, a path could be added
     * to `core_paths` and forgotten here - and the number D17's reuse is decided on would stop
     * covering a shipped file while both halves looked right. So this asserts the literal
     * concatenation, and that nothing appears in both halves.
     *
     * @group sprint-seam
     */
    public function testTheCodeHalfIsExactlyTheCoreHalfPlusTheModuleHalf(): void
    {
        $script = self::read('bin/code-fingerprint.sh');

        self::assertStringContainsString(
            'code_paths=("${core_paths[@]}" "${module_paths[@]}")',
            $script,
            'bin/code-fingerprint.sh does not build code_paths as the concatenation of the two'
            . ' halves. A third hand-written list can drift from them, and the number the reuse'
            . ' decision reads is the one that would stop covering a file.'
        );

        $core    = self::arrayLiteral($script, 'core_paths');
        $modules = self::arrayLiteral($script, 'module_paths');

        self::assertSame(
            ['modules'],
            $modules,
            'The module half is meant to be the modules/ directory and nothing else.'
        );
        self::assertSame(
            [],
            array_intersect($core, $modules),
            'A path is in both halves, so it is hashed twice and "the core did not change" can'
            . ' be true and false at once: ' . implode(', ', array_intersect($core, $modules))
        );
        self::assertNotContains(
            'modules',
            $core,
            'The core half lists modules/, so every module edit reads as a core edit and the'
            . ' split says nothing.'
        );
        self::assertContains(
            'modules.php',
            $core,
            'modules.php is the seam itself and belongs in the CORE half: an edit to it is a core'
            . ' edit, whatever it does to the modules it loads.'
        );
    }

    /**
     * Every module the manifest names is staged into the zip, and the manifest is what the
     * plugin loads - so a module that ships without being loaded, or is loaded without being
     * shipped, is red here rather than at somebody's first request.
     *
     * @group sprint-seam
     */
    public function testEveryModuleInTheManifestShipsAndExists(): void
    {
        $yaml = self::read('.github/workflows/release.yml');

        self::assertStringContainsString(
            'cp modules/*.php dist/wp-mcp/modules/',
            $yaml,
            'release.yml does not stage modules/*.php, so the zip would install a plugin whose'
            . ' manifest names files it does not carry.'
        );

        foreach (\wpmcp_module_manifest() as $slug => $relative) {
            self::assertSame(
                1,
                preg_match('#^modules/[a-z0-9-]+\.php$#', $relative),
                "The manifest entry for '{$slug}' is '{$relative}'. `cp modules/*.php` is flat, so"
                . ' a module outside modules/, or in a subdirectory of it, would not be staged.'
            );
            self::assertFileExists(
                WPMCP_PLUGIN_DIR . '/' . $relative,
                "The manifest names '{$relative}' and it is not on disk."
            );
        }
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
     * Every path the script hashes as CODE: the two halves, together, sorted.
     *
     * Read from the halves rather than from `code_paths`, because since 1.1.2 that variable is a
     * concatenation of them and holds no literal paths at all. The test above is what holds it
     * to being exactly that concatenation.
     *
     * @return string[]
     */
    private static function hashedCodePaths(): array
    {
        $script = self::read('bin/code-fingerprint.sh');
        $paths  = array_merge(
            self::arrayLiteral($script, 'core_paths'),
            self::arrayLiteral($script, 'module_paths')
        );

        sort($paths);

        return $paths;
    }

    /**
     * One `name=(a b c)` array literal out of the script, as a list of its words.
     *
     * @return string[]
     */
    private static function arrayLiteral(string $script, string $name): array
    {
        self::assertMatchesRegularExpression(
            '/' . preg_quote($name, '/') . '=\((.+?)\)/s',
            $script,
            "bin/code-fingerprint.sh has no {$name} list."
        );

        preg_match('/' . preg_quote($name, '/') . '=\((.+?)\)/s', $script, $m);

        return preg_split('/\s+/', trim($m[1])) ?: [];
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
        self::assertStringContainsString(
            'cp modules/*.php dist/wp-mcp/modules/',
            $yaml,
            'release.yml no longer stages modules/*.php, so no module would ship and every'
            . ' module tool would vanish from an installed zip.'
        );

        $files[] = 'src';
        $files[] = 'modules';
        sort($files);

        return $files;
    }

    /**
     * Through RepoFile: the staging-step assertion below is anchored on a line end, and a CRLF
     * working copy would fail it on a laptop while passing in CI. See RepoFile.
     */
    private static function read(string $relative): string
    {
        return RepoFile::read($relative);
    }
}
