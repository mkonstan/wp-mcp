<?php
/**
 * Base class for the black-box HTTP tier.
 *
 * Integration tests talk to a real WordPress over real HTTP - locally the Local by
 * Flywheel site, in CI a @wordpress/env container - because that is the only place
 * TLS, Origin, Content-Type, capability checks and the path-URL route actually exist.
 * No plugin code is loaded in-process here.
 *
 * WPMCP_TEST_URL is the single switch. Unset means "no site available": every test
 * skips with a message naming the variable, so a CI job that runs only the unit tier
 * still reports honestly instead of failing or pretending to pass.
 *
 * TLS verification is off by default: Local issues a self-signed certificate for
 * *.local. Set WPMCP_TEST_VERIFY_TLS=1 against a site with a real certificate.
 */

declare(strict_types=1);

namespace WpMcp\Tests\Support;

use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;

abstract class IntegrationTestCase extends TestCase
{
    protected string $baseUrl;

    protected function setUp(): void
    {
        parent::setUp();

        $url = self::envString('WPMCP_TEST_URL');

        if ($url === '') {
            self::markTestSkipped(
                'Integration tier skipped: WPMCP_TEST_URL is not set. Point it at a running'
                . ' WordPress with the plugin active, e.g.'
                . ' WPMCP_TEST_URL=https://jaygroup.local composer test:integration'
            );
        }

        $this->baseUrl = rtrim($url, '/');
    }

    protected function client(): Client
    {
        return new Client([
            'base_uri'        => $this->baseUrl . '/',
            'verify'          => self::envString('WPMCP_TEST_VERIFY_TLS') === '1',
            'http_errors'     => false,
            'timeout'         => 20,
            'connect_timeout' => 10,
            'headers'         => ['Accept' => 'application/json'],
        ]);
    }

    /** getenv() and $_ENV disagree depending on how PHPUnit was launched; check both. */
    protected static function envString(string $name): string
    {
        $value = getenv($name);

        if ($value === false || $value === '') {
            $value = $_ENV[$name] ?? $_SERVER[$name] ?? '';
        }

        return trim((string) $value);
    }
}
