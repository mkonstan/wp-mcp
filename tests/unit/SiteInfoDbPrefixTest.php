<?php

declare(strict_types=1);

namespace WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WpMcp\Tests\Support\FakeWpdb;
use WpMcp\Tests\Support\WordPressRuntime;
use WpMcp\Tests\Support\WordPressStubs;

/**
 * site-info declares and publishes the current site's database prefix, and its getter
 * follows the live wpdb rather than assuming wp_. That assumption made a first SQL
 * query fail with "no such table" on the site whose prefix was WsEV9y_.
 *
 * TEST THE FIELD, not the whole tool: this tier deliberately has no get_bloginfo(),
 * home_url() or wp_get_theme() stub. The ordered shape can prove the field sits after
 * active_plugins and before the final wp_mcp block without widening that stub surface.
 * The registry assertion still reads the outputSchema a client actually receives.
 */
final class SiteInfoDbPrefixTest extends TestCase
{
    private FakeWpdb $wpdb;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        WordPressStubs::loadPlugin();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->wpdb = WordPressRuntime::install();
    }

    public function testShapeDeclaresStringPrefixWithDescription(): void
    {
        $shape = wpmcp_site_info_shape();

        self::assertArrayHasKey('db_prefix', $shape);
        self::assertSame('string', $shape['db_prefix']['type']);
        self::assertIsString($shape['db_prefix']['description']);
        self::assertNotSame('', trim($shape['db_prefix']['description']));
    }

    public function testGetterFollowsLiveNonDefaultPrefix(): void
    {
        $wpdb = $this->wpdb;
        $wpdb->prefix = 'WsEV9y_';
        $getter = wpmcp_site_info_shape()['db_prefix']['get'];

        self::assertSame('WsEV9y_', $getter(null));

        // Reuse the SAME closure after a site switch. Hardcoding even the friction
        // log's prefix, or capturing it when the shape was made, must go red here.
        $wpdb->prefix = 'WsEV9y_7_';
        self::assertSame('WsEV9y_7_', $getter(null));
    }

    public function testRegistryPublishesRequiredStringPrefixWithDescription(): void
    {
        $tools = wpmcp_tools();

        self::assertArrayHasKey('site-info', $tools);
        $schema = $tools['site-info']['outputSchema'];
        self::assertArrayHasKey('db_prefix', $schema['properties']);
        self::assertSame('string', $schema['properties']['db_prefix']['type']);
        self::assertIsString($schema['properties']['db_prefix']['description']);
        self::assertNotSame('', trim($schema['properties']['db_prefix']['description']));
        self::assertContains('db_prefix', $schema['required']);
    }

    /** The description must expose the prefix before an agent guesses a table name. */
    public function testToolDescriptionMakesDbPrefixDiscoverable(): void
    {
        $tools = wpmcp_tools();

        self::assertStringContainsString('db_prefix', $tools['site-info']['description']);
    }

    public function testShapeKeepsPrefixBetweenPluginCountAndFinalPluginBlock(): void
    {
        self::assertSame([
            'name', 'url', 'wp_version', 'active_theme', 'active_theme_version',
            'active_plugins', 'db_prefix', 'wp_mcp',
        ], array_keys(wpmcp_site_info_shape()));
    }
}
