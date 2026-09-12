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
 */

declare(strict_types=1);

namespace WpMcp\Tests\Support;

abstract class FixtureIntegrationTestCase extends IntegrationTestCase
{
    /**
     * Skip the whole class unless there is a site AND a way to seed it. Two distinct
     * messages, because the two causes need different fixes.
     */
    protected static function requireSite(): string
    {
        $url = self::envString('WPMCP_TEST_URL');

        if ($url === '') {
            self::markTestSkipped(
                'Fixture-bearing integration tests skipped: WPMCP_TEST_URL is not set.'
                . ' e.g. WPMCP_TEST_URL=https://jaygroup.local composer test:integration'
            );
        }

        $reason = WpCli::unavailableReason();

        if ($reason !== '') {
            self::markTestSkipped('Fixture-bearing integration tests skipped: ' . $reason);
        }

        return rtrim($url, '/');
    }

    protected function mcp(string $token): McpClient
    {
        return new McpClient($this->client(), $token);
    }
}
