<?php
/**
 * THE DECLARED FLOOR IS ONE NUMBER, STATED IN FOUR PLACES, AND CI EXECUTES IT.
 *
 * `Requires at least:` is not a hint. Core's validate_plugin_requirements()
 * (wp-admin/includes/plugin.php) calls is_wp_version_compatible() on ACTIVATION and
 * returns a WP_Error below the declared version, so WordPress refuses to activate. For
 * five sprints the header said 5.5, the README derived that number from one function
 * argument, and no job had ever run any WordPress but "whatever is current today"
 * (.wp-env.json pins `"core": null`). The number was a claim, and the claim was wrong by
 * one hook argument: `_wp_put_post_revision`'s `$post_id` is `@since 6.4.0` and
 * `restore-revision` filters on it (analysis/52-scout-version-coverage.md §4.1;
 * analysis/53-open-decisions.md D2).
 *
 * So the floor now has to agree with itself in four places, and the fourth is the one that
 * makes the other three true:
 *
 *   1. `Requires at least:` in the plugin header  - what WordPress enforces on activation
 *   2. the README's Requirements table            - what a reader is promised
 *   3. ci.yml's integration-floor job             - what is actually executed
 *   4. release.yml's integration-floor job        - what blocks a publish
 *
 * A grep in the lint job was the other candidate and it is weaker: lint does not run when
 * somebody runs the suite on their laptop, and this tier always does.
 *
 * WHAT THIS TEST DELIBERATELY DOES NOT DO is count the rows of the README's feature table.
 * Today it has one row because, with the Abilities bridge parked, nothing needs anything
 * newer than the floor. A second row is a legitimate future change; a test that forbade it
 * would be a test about product plans.
 *
 * @group sprint-0
 */

declare(strict_types=1);

namespace WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WpMcp\Tests\Support\RepoFile;

final class FloorConsistencyTest extends TestCase
{
    /**
     * The header, the README and both workflows name the same WordPress version.
     *
     * @group sprint-0
     */
    public function testEveryPlaceThatNamesTheWordPressFloorNamesTheSameOne(): void
    {
        $floor = self::headerField('Requires at least');

        self::assertMatchesRegularExpression(
            '/^\d+\.\d+$/',
            $floor,
            'The plugin header\'s "Requires at least" is not a major.minor WordPress version.'
        );

        $readme = self::read('README.md');

        self::assertMatchesRegularExpression(
            '/^\| WordPress \| ' . preg_quote($floor, '/') . ' or newer \|$/m',
            $readme,
            "wp-mcp.php declares WordPress {$floor} and the README's Requirements table does"
            . ' not say the same thing. The header is the version WordPress REFUSES TO'
            . ' ACTIVATE below, so a README that promises less is promising something the'
            . ' site will not allow.'
        );

        foreach (['.github/workflows/ci.yml', '.github/workflows/release.yml'] as $workflow) {
            $yaml = self::read($workflow);

            self::assertStringContainsString(
                'WP_ENV_CORE: WordPress/WordPress#' . $floor,
                $yaml,
                "{$workflow} does not pin the floor job to WordPress {$floor}. A declared floor"
                . ' that no job runs is the defect this whole job exists to end.'
            );

            // The job asserts the container really came up at the floor, because a
            // WP_ENV_CORE wp-env quietly ignored would leave the leg testing current core
            // twice and reporting a floor it never touched.
            self::assertMatchesRegularExpression(
                '/' . preg_quote($floor, '/') . '\|' . preg_quote($floor, '/') . '\.\*\)/',
                $yaml,
                "{$workflow}'s floor job does not check that the container came up at"
                . " {$floor}."
            );
        }
    }

    /**
     * The floor is claimed at a PHP version the pair can actually run, and the README says
     * which one. Claiming an untestable combination is the mistake the old "WordPress 5.5
     * with PHP 8.1" pair made: 5.5 predates PHP 8 entirely.
     *
     * @group sprint-0
     */
    public function testTheFloorIsClaimedAtThePhpVersionItIsTestedOn(): void
    {
        $floor = self::headerField('Requires at least');
        $yaml  = self::read('.github/workflows/ci.yml');

        self::assertMatchesRegularExpression(
            "/WP_ENV_PHP_VERSION: '(\\d+\\.\\d+)'/",
            $yaml,
            'The floor job does not pin the container\'s PHP at all, so the pair it proves is'
            . ' whatever wp-env defaults to on the day.'
        );

        preg_match("/WP_ENV_PHP_VERSION: '(\\d+\\.\\d+)'/", $yaml, $m);
        $php = $m[1];

        // Whitespace is collapsed first: the sentence that names the pair is wrapped in the
        // README, and a test that could be broken by a re-wrap is a test about line widths.
        $prose = trim((string) preg_replace('/\s+/', ' ', self::read('README.md')));

        self::assertStringContainsString(
            "WordPress {$floor} on PHP {$php}",
            $prose,
            "The README does not state the pair the floor is proven at (WordPress {$floor} on"
            . " PHP {$php}). The pair is the claim; the two numbers on their own are not -"
            . ' that is exactly how "WordPress 5.5 with PHP 8.1" came to describe an empty set.'
        );
    }

    /**
     * The PHP floor, the other half of the pair, is stated once and proven by the unit
     * matrix's lowest leg.
     *
     * @group sprint-0
     */
    public function testThePhpFloorIsStatedOnceAndIsTheLowestLegOfTheUnitMatrix(): void
    {
        $php = self::headerField('Requires PHP');

        self::assertMatchesRegularExpression('/^\d+\.\d+$/', $php, 'header "Requires PHP"');

        self::assertMatchesRegularExpression(
            '/^\| PHP \| ' . preg_quote($php, '/') . ' or newer \|$/m',
            self::read('README.md'),
            "wp-mcp.php declares PHP {$php} and the README's Requirements table disagrees."
        );

        foreach (['.github/workflows/ci.yml', '.github/workflows/release.yml'] as $workflow) {
            self::assertMatchesRegularExpression(
                "/php: \\['" . preg_quote($php, '/') . "'/",
                self::read($workflow),
                "{$workflow}'s unit matrix does not start at PHP {$php}, so the declared PHP"
                . ' floor is not the lowest version anything runs on.'
            );
        }
    }

    private static function headerField(string $field): string
    {
        $source = self::read('wp-mcp.php');

        self::assertMatchesRegularExpression(
            '/^ \* ' . preg_quote($field, '/') . ':\s*(\S+)\s*$/m',
            $source,
            "wp-mcp.php has no '{$field}:' line in its plugin header."
        );

        preg_match('/^ \* ' . preg_quote($field, '/') . ':\s*(\S+)\s*$/m', $source, $m);

        return $m[1];
    }

    /**
     * Through RepoFile: the README table rows are asserted with `/…\|$/m`, which a CRLF working
     * copy breaks on a laptop while CI stays green. See RepoFile.
     */
    private static function read(string $relative): string
    {
        return RepoFile::read($relative);
    }
}
