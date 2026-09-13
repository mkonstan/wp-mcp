<?php
/**
 * Base class for integration tests that seed fixtures on the site.
 *
 * Adds two things to IntegrationTestCase:
 *
 *   requireSite()  a skip check usable from setUpBeforeClass. Fixtures are created
 *                  once per class, which happens BEFORE setUp() has had a chance to
 *                  skip on a missing WPMCP_TEST_URL - so the check has to be
 *                  available statically, or a machine with no site would fail at
 *                  fixture creation instead of skipping.
 *   mcp()          an McpClient bound to one token.
 *
 * ONE THING ONLY MAY SKIP: an unset WPMCP_TEST_URL, meaning no site exists. If the URL
 * IS set and wp-cli cannot be reached, that FAILS. The first version of this class
 * skipped on both, and the consequence was that every sprint-1 integration test
 * silently skipped in CI - where the gate is supposed to be observed - because CI sets
 * only the URL. A skip is green. A gate that can go green without executing is not a
 * gate, so the harness now has to be loud about its own brokenness.
 */

declare(strict_types=1);

namespace WpMcp\Tests\Support;

abstract class FixtureIntegrationTestCase extends IntegrationTestCase
{
    /**
     * No site -> skip. A site but no wp-cli -> FAIL. See the class docblock: the
     * second case used to skip, which is how the sprint-1 gate came to be green in CI
     * without running a single one of its integration tests.
     */
    protected static function requireSite(): string
    {
        $url = self::envString('WPMCP_TEST_URL');

        if ($url === '') {
            self::markTestSkipped(
                'Fixture-bearing integration tests skipped: WPMCP_TEST_URL is not set.'
                . ' e.g. WPMCP_TEST_URL=https://example.local composer test:integration'
            );
        }

        $reason = WpCli::unavailableReason();

        if ($reason !== '') {
            self::fail(
                'WPMCP_TEST_URL is set to ' . $url . ', so a site exists, but its'
                . ' fixtures cannot be seeded: ' . $reason
                . ' Locally: source bin/local-env.sh. In CI (wp-env): set WPMCP_WP_ENV=1.'
                . ' This is a failure and not a skip on purpose - a gate that skips is'
                . ' a gate that passes without running.'
            );
        }

        return rtrim($url, '/');
    }

    /**
     * Run the class teardown if the fixture build throws.
     *
     * PHPUnit 10.5 returns from TestSuite::run() when setUpBeforeClass throws and
     * never calls tearDownAfterClass, so a failure after the third of five fixtures
     * leaves users, posts and a live token on somebody's site until the next run's
     * purge(). Every setUpBeforeClass wraps its body in this.
     *
     * @param callable():void $build
     * @param callable():void $cleanUp
     */
    protected static function buildFixtures(callable $build, callable $cleanUp): void
    {
        try {
            $build();
        } catch (\Throwable $e) {
            try {
                $cleanUp();
            } catch (\Throwable $ignored) {
                // The build failure is the interesting one; do not mask it.
            }

            throw $e;
        }
    }

    /**
     * @param string|null $baseUrl a different origin for this one client; see
     *                    insecureBaseUrl(). Defaults to WPMCP_TEST_URL.
     */
    protected function mcp(string $token, ?string $baseUrl = null): McpClient
    {
        return new McpClient($this->client($baseUrl), $token);
    }
}
