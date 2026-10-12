<?php
/**
 * Order 003 live-site checks. Authored only; the orchestrator runs this tier.
 * Only fixture users/tokens are created. Every changed option is restored in finally,
 * including whether it existed before the test, so a failed assertion cannot poison a run.
 */
declare(strict_types=1);

namespace WpMcp\Tests\Integration;

use WpMcp\Tests\Support\FixtureIntegrationTestCase;
use WpMcp\Tests\Support\Fixtures;
use WpMcp\Tests\Support\MuPlugin;
use WpMcp\Tests\Support\WpCli;

final class SiteConfigTest extends FixtureIntegrationTestCase
{
    private static int $user = 0;
    private static string $token = '';
    private const CONSTANTS = [
        'DISALLOW_FILE_EDIT', 'DISALLOW_FILE_MODS', 'WP_DEBUG', 'WP_DEBUG_LOG',
        'WP_DEBUG_DISPLAY', 'SCRIPT_DEBUG', 'WP_ENVIRONMENT_TYPE', 'MULTISITE', 'WP_POST_REVISIONS',
    ];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::requireSite();
        self::buildFixtures(static function (): void {
            MuPlugin::drop('site-config-probe', self::constantsProbeSource());
            self::$user = Fixtures::createUser(Fixtures::name('site-config-admin'), 'administrator');
            self::$token = Fixtures::mintToken('admin', Fixtures::name('site-config-token'), self::$user);
        }, self::destroy(...));
    }

    public static function tearDownAfterClass(): void
    {
        self::destroy();
        parent::tearDownAfterClass();
    }

    private static function destroy(): void
    {
        MuPlugin::remove('site-config-probe');
        Fixtures::deleteTokensLabelled(Fixtures::name('site-config-token'));
        Fixtures::deleteUser(self::$user);
        self::$user = 0;
        self::$token = '';
    }

    /** Snapshot the stored row, including absence; do not assume any site defaults. */
    private static function stored(string $name): array
    {
        return json_decode(WpCli::evaluate(
            '$name = ' . var_export($name, true) . '; global $wpdb;'
            . '$row = $wpdb->get_row($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name));'
            . 'echo json_encode(["exists" => (bool) $row, "value" => $row ? maybe_unserialize($row->option_value) : null], JSON_THROW_ON_ERROR);'
        ), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testI1WritableRoundTripRestoresItsOption(): void
    {
        $snapshot = self::stored('blogdescription');
        $client = $this->mcp(self::$token);
        $old = $snapshot['exists'] ? (string) $snapshot['value'] : null;
        $fixture = Fixtures::name('site-config-description');
        try {
            $written = $client->callTool('update-option', ['name' => 'blogdescription', 'value' => $fixture]);
            self::assertFalse($written->isError, $written->text);
            self::assertSame(['name' => 'blogdescription', 'old_value' => $old, 'new_value' => $fixture, 'changed' => true], $written->data());
            self::assertSame($fixture, $client->callTool('get-option', ['name' => 'blogdescription'])->data()['value']);
            self::assertSame($fixture, self::stored('blogdescription')['value']);
        } finally {
            // Restore via core, not via the tool under test: even a broken tool cannot leave
            // this site's setting changed. Failure to restore is a test failure, never swallowed.
            WpCli::evaluate($snapshot['exists']
                ? 'update_option("blogdescription", ' . var_export($snapshot['value'], true) . ');'
                : 'delete_option("blogdescription");');
            self::assertSame($snapshot, self::stored('blogdescription'), 'Failed to restore blogdescription.');
        }
    }

    public function testI2ReadOnlyWriteLeavesAllFourStoredValuesUntouched(): void
    {
        foreach (['siteurl', 'home', 'permalink_structure', 'gmt_offset'] as $name) {
            $snapshot = self::stored($name);
            try {
                $result = $this->mcp(self::$token)->callTool('update-option', ['name' => $name, 'value' => 'refused-fixture']);
                self::assertTrue($result->isError);
                self::assertStringContainsString('read-only', $result->text);
                self::assertSame($snapshot, self::stored($name));
            } finally {
                // Even if the refusal regresses, wp-cli can restore URLs after HTTP is lost.
                if (self::stored($name) !== $snapshot) {
                    WpCli::evaluate($snapshot['exists']
                        ? 'update_option(' . var_export($name, true) . ', ' . var_export($snapshot['value'], true) . ');'
                        : 'delete_option(' . var_export($name, true) . ');');
                }
                self::assertSame($snapshot, self::stored($name), 'Failed to restore read-only option ' . $name . '.');
            }
        }
    }

    public function testStoredWriteAndRepeatSucceedWhenEffectiveValueIsForced(): void
    {
        $snapshot = self::stored('blogdescription');
        $fixture = Fixtures::name('site-config-stored-description');
        $forced = Fixtures::name('site-config-filtered-description');
        try {
            MuPlugin::drop('site-config-option-pin',
                'if (($_SERVER["HTTP_X_WPMCP_SITE_CONFIG_OPTION_PIN"] ?? "") !== '
                . var_export(Fixtures::runId(), true) . ') { return; }'
                . 'add_filter("option_blogdescription", static function ($value) { return '
                . var_export($forced, true) . '; });');
            $client = $this->mcp(self::$token);
            $call = static function (string $tool, array $args) use ($client): array {
                $response = $client->post('tools/call', ['name' => $tool, 'arguments' => $args],
                    ['X-Wpmcp-Site-Config-Option-Pin' => Fixtures::runId()]);
                self::assertSame(200, $response->getStatusCode());
                $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
                self::assertFalse($body['result']['isError'] ?? false, json_encode($body));
                return json_decode($body['result']['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
            };
            self::assertSame($forced, $call('get-option', ['name' => 'blogdescription'])['value'], 'Pin fixture must be active.');
            self::assertSame(['name' => 'blogdescription',
                'old_value' => $snapshot['exists'] ? (string) $snapshot['value'] : null,
                'new_value' => $fixture, 'changed' => true],
                $call('update-option', ['name' => 'blogdescription', 'value' => $fixture]));
            self::assertSame($fixture, self::stored('blogdescription')['value']);
            self::assertSame($forced, $call('get-option', ['name' => 'blogdescription'])['value']);
            self::assertSame(['name' => 'blogdescription', 'old_value' => $fixture, 'new_value' => $fixture, 'changed' => false],
                $call('update-option', ['name' => 'blogdescription', 'value' => $fixture]));
        } finally {
            MuPlugin::remove('site-config-option-pin');
            WpCli::evaluate($snapshot['exists']
                ? 'update_option("blogdescription", ' . var_export($snapshot['value'], true) . ');'
                : 'delete_option("blogdescription");');
            self::assertSame($snapshot, self::stored('blogdescription'), 'Failed to restore forced option fixture.');
        }
    }

    public function testEnablingRegistrationRefusesAStoredCustomAdminRole(): void
    {
        $registration = self::stored('users_can_register');
        $default = self::stored('default_role');
        $role = Fixtures::name('newsletter-helper');
        $created = false;
        try {
            WpCli::evaluate('$name = ' . var_export($role, true) . ';'
                . 'if (get_role($name)) { throw new RuntimeException("Fixture role already exists"); }'
                . 'if (!add_role($name, "Newsletter helper", ["read" => true, "install_plugins" => true])) {'
                . 'throw new RuntimeException("Could not create fixture role"); }');
            $created = true;
            // Keep registration closed while planting the dormant privileged default.
            WpCli::evaluate('update_option("users_can_register", 0); update_option("default_role", '
                . var_export($role, true) . ');');
            $before = [self::stored('users_can_register'), self::stored('default_role')];
            self::assertSame('0', (string) $before[0]['value']);
            self::assertSame($role, $before[1]['value']);
            $result = $this->mcp(self::$token)->callTool('update-option', ['name' => 'users_can_register', 'value' => 1]);
            self::assertTrue($result->isError);
            self::assertStringContainsString($role, $result->text);
            self::assertStringContainsString('install_plugins', $result->text);
            self::assertStringContainsString('Fix default_role first', $result->text);
            self::assertSame($before, [self::stored('users_can_register'), self::stored('default_role')]);
        } finally {
            if ($created) {
                // Restore the old policy before restoring whether registration was open.
                WpCli::evaluate($default['exists']
                    ? 'update_option("default_role", ' . var_export($default['value'], true) . ');'
                    : 'delete_option("default_role");');
                WpCli::evaluate($registration['exists']
                    ? 'update_option("users_can_register", ' . var_export($registration['value'], true) . ');'
                    : 'delete_option("users_can_register");');
                WpCli::evaluate('remove_role(' . var_export($role, true) . ');');
                self::assertSame($default, self::stored('default_role'));
                self::assertSame($registration, self::stored('users_can_register'));
            }
        }
    }

    public function testI3ConstantsMatchTheRealSiteConfiguration(): void
    {
        // wp-cli can define debug constants differently from an HTTP request. The
        // fixture reports the actual constants of THIS request in a response header.
        $response = $this->mcp(self::$token)->post('tools/call', ['name' => 'get-constants', 'arguments' => new \stdClass()],
            ['X-Wpmcp-Site-Config-Probe' => Fixtures::runId()]);
        self::assertSame(200, $response->getStatusCode());
        $header = $response->getHeaderLine('X-Wpmcp-Site-Config-Constants');
        self::assertNotSame('', $header, 'The constants fixture did not run.');
        $expected = json_decode(base64_decode($header, true), true, 512, JSON_THROW_ON_ERROR);
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($body['result']['isError'] ?? false);
        $text = $body['result']['content'][0]['text'];
        $data = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($expected, $data['constants']);
        self::assertSame(self::CONSTANTS, array_keys($data['constants']));
        foreach (['DB_PASSWORD', 'DB_USER', 'DB_HOST', 'AUTH_KEY', 'SECURE_AUTH_SALT'] as $name) {
            self::assertFalse(str_contains($text, $name));
        }
    }

    private static function constantsProbeSource(): string
    {
        // Per-run and per-request gate: no ordinary site visitor receives this header.
        return 'if (($_SERVER["HTTP_X_WPMCP_SITE_CONFIG_PROBE"] ?? "") !== '
            . var_export(Fixtures::runId(), true) . ') { return; }'
            . 'add_filter("rest_post_dispatch", function ($response) {'
            . '$constants = []; foreach (' . var_export(self::CONSTANTS, true) . ' as $name) {'
            . '$defined = defined($name); $constants[$name] = ["defined" => $defined, "value" => $defined ? constant($name) : null];}'
            . '$response->header("X-Wpmcp-Site-Config-Constants", base64_encode(wp_json_encode($constants)));'
            . 'return $response;}, 10, 1);';
    }
}
