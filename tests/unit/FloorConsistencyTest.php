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
 * THE FLOOR IS NOW A CHOICE AND NOT A DERIVATION (1.1.1, D2). 6.4 was the oldest version the
 * code would run on; 6.9 is where the Abilities API begins, which is what the ecosystem has
 * converged on, and supporting below it bought a version question on every feature and a
 * 91-minute CI job for sites unlikely to run an agent. Nothing about THIS test changes: it
 * reads the number out of the header and holds the other three places to it, which is exactly
 * what makes moving the floor a four-line change instead of a hunt.
 *
 * So the floor now has to agree with itself in four places, and the fourth is the one that
 * makes the other three true:
 *
 *   1. `Requires at least:` in the plugin header  - what WordPress enforces on activation
 *   2. the README's Requirements table            - what a reader is promised
 *   3. ci.yml's floor leg                         - what is actually executed
 *   4. release.yml's floor job                    - what blocks a publish
 *
 * AND THE PAIR, NOT ONLY THE WORDPRESS NUMBER (sprint LOG+FLOOR round 2). The floor is a
 * WordPress version ON a PHP version, and the PHP half used to be read out of `ci.yml` alone -
 * which is how `release.yml` came to run the floor on PHP 8.2 under a job named 8.4, with a guard
 * that accepted 8.2 and an error message about 8.4. Both workflows are now held to it, and in each
 * one the variable, the guard's accepted case, the job name and the guard's error string must all
 * name the same pair. See testTheFloorIsClaimedAtThePhpVersionItIsTestedOn.
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
     * BOTH WORKFLOWS, AND FOUR PLACES IN EACH, SINCE SPRINT LOG+FLOOR ROUND 2 - and the reason is
     * a defect this test WATCHED HAPPEN. It used to read `ci.yml` alone, and take the first
     * `WP_ENV_PHP_VERSION` it found. `14741eb` moved the floor to 6.9 / PHP 8.4 and updated
     * `ci.yml`; `release.yml` kept `WP_ENV_PHP_VERSION: '8.2'` AND a guard accepting `8.2|8.2.*`,
     * while its job name and its own error string both said 8.4. So the release gate - the one
     * thing standing between a red floor and a published zip - ran the floor on a PHP the README
     * does not claim, and the guard written to catch precisely that went green on it, because the
     * guard and the variable were wrong in the same direction. Three months of green ticks.
     *
     * So the pair is now held in every place that states it, in BOTH files:
     *
     *   1. `WP_ENV_PHP_VERSION` - what the container is ASKED for. Every occurrence in a file
     *      must be the same value, so a partial edit is red rather than half-applied.
     *   2. the `case "$php_version" in` guard - what the job ACCEPTS. This is the one the old
     *      test missed entirely, and a guard that accepts a value the variable no longer sets is
     *      worse than no guard: it reports that it checked.
     *   3. the job NAME, `WP <floor> / PHP <php>` - what a reader of the Actions tab is told.
     *   4. the guard's error STRING, `This leg claims WordPress <floor> on PHP <php>` - the
     *      sentence that was telling the truth while the code around it did not.
     *
     * No file is privileged: the value is read from the two workflows and they must agree with
     * each other before either is compared to the README. A test that trusted `ci.yml` would have
     * been satisfied by exactly the state that shipped.
     *
     * @group sprint-14d
     */
    public function testTheFloorIsClaimedAtThePhpVersionItIsTestedOn(): void
    {
        $floor  = self::headerField('Requires at least');
        $chosen = [];

        foreach (['.github/workflows/ci.yml', '.github/workflows/release.yml'] as $workflow) {
            $yaml = self::read($workflow);

            self::assertMatchesRegularExpression(
                "/WP_ENV_PHP_VERSION: '(\\d+\\.\\d+)'/",
                $yaml,
                "{$workflow}'s floor job does not pin the container's PHP at all, so the pair it"
                . ' proves is whatever wp-env defaults to on the day.'
            );

            preg_match_all("/WP_ENV_PHP_VERSION: '(\\d+\\.\\d+)'/", $yaml, $set);

            self::assertCount(
                1,
                array_unique($set[1]),
                "{$workflow} sets WP_ENV_PHP_VERSION to more than one value ("
                . implode(', ', array_unique($set[1])) . '), so at least one floor job is running'
                . ' a PHP nothing claims.'
            );

            $php = $set[1][0];

            // (2) THE GUARD MUST ACCEPT WHAT THE VARIABLE SETS. This is the assertion whose
            // absence let release.yml ship a guard accepting 8.2 under an error message about
            // 8.4 - the one shape that passes while reporting that it verified something.
            self::assertMatchesRegularExpression(
                '/case "\$php_version" in\s*\n\s*' . preg_quote($php, '/')
                . '\|' . preg_quote($php, '/') . '\.\*\)/',
                $yaml,
                "{$workflow} sets WP_ENV_PHP_VERSION to {$php} and its container-PHP guard does"
                . " not accept {$php}. Either the guard passes on a PHP nothing claims, or it"
                . ' fails on the one the job asked for; the first is how a false README sentence'
                . ' stays green through a release.'
            );

            // (3) and (4): the two sentences a human reads, which were the only true things in
            // release.yml's floor job and were therefore no help at all on their own.
            self::assertStringContainsString(
                "WP {$floor} / PHP {$php}",
                $yaml,
                "{$workflow}'s floor job is not NAMED for the pair it runs (WP {$floor} / PHP"
                . " {$php}), so the Actions tab describes a leg that is not the one executing."
            );
            self::assertStringContainsString(
                "This leg claims WordPress {$floor} on PHP {$php}",
                $yaml,
                "{$workflow}'s guard failure message does not name the pair the job actually"
                . ' pins. That message was the only correct thing in release.yml while the'
                . ' variable and the guard beside it were both stale.'
            );

            $chosen[$workflow] = $php;
        }

        self::assertCount(
            1,
            array_unique($chosen),
            'ci.yml and release.yml run the floor on DIFFERENT PHP versions ('
            . implode(', ', array_map(
                static fn ($w, $v) => basename($w) . '=' . $v,
                array_keys($chosen),
                $chosen
            )) . '). The release gate must execute the same pair CI does, or a publish is blocked'
            . ' by a leg that proved something else.'
        );

        $php = reset($chosen);

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
