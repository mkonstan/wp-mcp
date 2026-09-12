<?php
/**
 * The plugin is reachable at all: the REST API index advertises the `wpmcp`
 * namespace.
 *
 * This is the harness's smoke test and the foundation every later integration test
 * stands on. It fails if the site is down, if the plugin is deactivated, if
 * `rest_api_init` never fired, or if the namespace is renamed - and it needs no
 * token, so it cannot be broken by the identity work in Sprint 1.
 *
 * Read-only: a GET against a discovery endpoint, no database writes.
 *
 * @group sprint-0
 */

declare(strict_types=1);

namespace WpMcp\Tests\Integration;

use WpMcp\Tests\Support\IntegrationTestCase;

final class RestNamespaceTest extends IntegrationTestCase
{
    /**
     * @group sprint-0
     */
    public function testRestIndexAdvertisesTheWpmcpNamespace(): void
    {
        $response = $this->client()->get('wp-json/');

        self::assertSame(
            200,
            $response->getStatusCode(),
            "GET {$this->baseUrl}/wp-json/ did not return 200. Is the site running?"
        );

        $body = (string) $response->getBody();
        $index = json_decode($body, true);

        self::assertIsArray($index, 'The REST index did not decode as JSON.');
        self::assertArrayHasKey('namespaces', $index, 'The REST index has no `namespaces` key.');
        self::assertIsArray($index['namespaces']);

        self::assertContains(
            'wpmcp',
            $index['namespaces'],
            'The REST index does not list the `wpmcp` namespace. The plugin is probably'
            . ' not active on ' . $this->baseUrl . '. Namespaces seen: '
            . implode(', ', array_map('strval', $index['namespaces']))
        );
    }
}
