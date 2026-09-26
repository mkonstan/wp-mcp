<?php
/**
 * NO FILE UNDER tests/ IMPORTS A CLASS FROM `WpMcp\Tests\Unit\` OR `WpMcp\Tests\Integration\`.
 *
 * THE DEFECT THIS EXISTS FOR, measured in CI and nowhere else (run 36229392831, `Floor shard 4 of 6`):
 * `tests/integration/SchemaKeywordsTest.php` carried `use WpMcp\Tests\Unit\SchemaValidatorTest;` to
 * reach a constant. `composer.json` maps PSR-4 `WpMcp\Tests\` to `tests/`, so that name resolves to
 * `tests/`**U**`nit/SchemaValidatorTest.php` - and the directory is `tests/unit/`, lower case. Windows
 * resolves it, Linux does not: `Error: Class "WpMcp\Tests\Unit\SchemaValidatorTest" not found`.
 *
 * WHY THE TIER ITSELF IS FINE AND ONLY AN IMPORT IS NOT. A class PHPUnit discovers through a
 * `phpunit.xml` testsuite directory is loaded BY PATH and never reaches the autoloader, so its
 * namespace case is irrelevant - which is why `WpMcp\Tests\Unit\…` works for 396 tests. An explicit
 * cross-file `use` is the one form that goes through PSR-4, and it must match the directory's real
 * case. That is also why sharding is what exposed it: the shard's filter ran the integration class
 * without the unit class, so nothing had loaded it by path first.
 *
 * THE LEDGER ROW (D32): this is a GAP, not an inherited decision. PHP and Composer both behave
 * correctly - PSR-4 is case-sensitive by specification and the filesystem answers as it answers. What
 * does not arrive here is the FAILURE: we develop on a case-insensitive filesystem and ship to a
 * case-sensitive one, so the platform's own error is unreachable on the machine where the code is
 * written. Five rounds of green runs on two local sites never saw it. A rule cannot close that, and a
 * rule is exactly what was tried: this project already recorded "tests/Unit/ capital-U autoload works
 * on Windows, not Linux; neither machine decides" during the CI sprint, and it recurred anyway, because
 * what was recorded was the rule and not a mechanism. This is the mechanism.
 *
 * WHY THE PATTERN IS BOTH TIERS RATHER THAN CROSS-TIER ONLY. Neither `tests/unit/` nor
 * `tests/integration/` matches the capitalised namespace segment, so `use WpMcp\Tests\Unit\X` fails on
 * Linux from ANY file, including another unit test. `WpMcp\Tests\Support\` is the one test namespace
 * whose directory matches (capital S), so shared fixture data belongs there - see
 * tests/Support/CoreSchemaKeywords.php, which is where the constant that caused this now lives.
 *
 * WHAT THIS DELIBERATELY DOES NOT FORBID: a reference to a class in the SAME namespace with no `use`
 * at all, such as ToolContractTest reading `WireSerializationTest::catalog()`. That is a weaker risk of
 * the same family - it needs the other class already loaded, which holds because PHPUnit loads every
 * file of a testsuite before running it - and forbidding it would break a documented, deliberate
 * arrangement. Named here so the limit is known rather than assumed.
 *
 * @group sprint-validator
 */

declare(strict_types=1);

namespace WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class TierImportTest extends TestCase
{
    /**
     * The import form that only a case-sensitive filesystem refuses.
     *
     * Matched on the `use` STATEMENT rather than on the namespace anywhere in the file, because this
     * file and the docblocks that explain the defect have to be able to name it. A `use` is the thing
     * that reaches the autoloader.
     */
    private const FORBIDDEN = '/^\s*use\s+WpMcp\\\\Tests\\\\(Unit|Integration)\\\\/mi';

    /**
     * @group sprint-validator
     */
    public function testNoTestImportsAClassFromATierNamespace(): void
    {
        $root  = WPMCP_PLUGIN_DIR . '/tests';
        $hits  = [];
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            $source   = (string) file_get_contents($file->getPathname());

            if (preg_match(self::FORBIDDEN, $source, $match)) {
                $hits[] = $relative . ': ' . trim($match[0]);
            }
        }

        sort($hits);

        self::assertSame(
            [],
            $hits,
            "A test imports a class from a tier namespace, which PSR-4 resolves to tests/Unit or\n"
            . "tests/Integration - directories that do not exist. This passes on Windows and fails on\n"
            . "Linux with `Class ... not found`, so no local run can catch it and CI is the only thing\n"
            . "that will. Put the shared thing in tests/Support/ instead; that directory's case matches\n"
            . "its namespace. Found:\n  " . implode("\n  ", $hits)
        );
    }

    /**
     * THE INSTRUMENT PROVES IT RAN, which matters more here than usual: the assertion above is a sweep
     * for emptiness, and a sweep for emptiness passes when the pattern is broken, when the directory is
     * wrong, and when the iterator finds nothing. All three are silent.
     *
     * So the pattern is run against a fixture carrying exactly what it claims to forbid, and against
     * one carrying the `Support` form it must NOT forbid - the distinction the whole fix rests on.
     *
     * @group sprint-validator
     */
    public function testThePatternMatchesWhatItClaimsToAndNotTheSupportForm(): void
    {
        foreach ([
            "<?php\nuse WpMcp\\Tests\\Unit\\SchemaValidatorTest;",
            "<?php\nuse WpMcp\\Tests\\Integration\\SchemaKeywordsTest;",
            "<?php\n    use WpMcp\\Tests\\Unit\\Thing as Other;",
        ] as $forbidden) {
            self::assertSame(
                1,
                preg_match(self::FORBIDDEN, $forbidden),
                'The pattern does not match an import it exists to forbid, so the sweep above is'
                . ' passing for the wrong reason: ' . $forbidden
            );
        }

        foreach ([
            "<?php\nuse WpMcp\\Tests\\Support\\Fixtures;",
            "<?php\nuse WpMcp\\SchemaValidator;",
            "<?php\n// prose naming WpMcp\\Tests\\Unit\\SchemaValidatorTest in a docblock",
            "<?php\n\$x = WireSerializationTest::catalog();",
        ] as $allowed) {
            self::assertSame(
                0,
                preg_match(self::FORBIDDEN, $allowed),
                'The pattern forbids something legitimate, which would make tests/Support unusable or'
                . ' stop a docblock naming the defect: ' . $allowed
            );
        }

        // AND THE SWEEP REALLY WALKED tests/, or an empty result would mean nothing. This file is
        // under it, so the count can never legitimately be zero.
        $found = 0;
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(WPMCP_PLUGIN_DIR . '/tests', RecursiveDirectoryIterator::SKIP_DOTS)
        );

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $found++;
            }
        }

        self::assertGreaterThan(
            50,
            $found,
            "The sweep walked {$found} PHP files under tests/, which is too few to be the suite - so"
            . ' the empty result above is about the walk and not about the tree.'
        );
    }
}
