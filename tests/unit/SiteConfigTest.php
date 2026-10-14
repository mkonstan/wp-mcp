<?php
/** Site settings stay typed; public registration must never grant administrative reach. */
declare(strict_types=1);

namespace WpMcp\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WpMcp\SchemaValidator;
use WpMcp\Tests\Support\WordPressRuntime;
use WpMcp\Tests\Support\WordPressStubs;
use WpMcp\Tests\Support\SiteConfigOptionRows;

final class SiteConfigTest extends TestCase
{
    private const READ = [
        'blogname', 'blogdescription', 'timezone_string', 'gmt_offset', 'date_format',
        'time_format', 'start_of_week', 'permalink_structure', 'siteurl', 'home',
        'blog_public', 'users_can_register', 'default_role', 'WPLANG',
    ];
    private const CONSTANTS = [
        'DISALLOW_FILE_EDIT', 'DISALLOW_FILE_MODS', 'WP_DEBUG', 'WP_DEBUG_LOG',
        'WP_DEBUG_DISPLAY', 'SCRIPT_DEBUG', 'WP_ENVIRONMENT_TYPE', 'MULTISITE', 'WP_POST_REVISIONS',
    ];

    protected function setUp(): void
    {
        WordPressStubs::loadPlugin();
        WordPressRuntime::install();
        require_once WPMCP_PLUGIN_DIR . '/tests/Support/SiteConfigRoleStub.php';
        require_once WPMCP_PLUGIN_DIR . '/tests/Support/SiteConfigStoredOptionStub.php';
        $GLOBALS['wpdb'] = new SiteConfigOptionRows();
        $GLOBALS['wpmcp_site_config_roles'] = [
            'subscriber' => (object) ['name' => 'subscriber', 'capabilities' => ['read' => true]],
        ];
        WordPressRuntime::allowCap('manage_options');
        WordPressRuntime::allowCap('edit_posts');
    }

    private function call(string $tool, array $args = []): mixed
    {
        return wpmcp_inventory_tools()[$tool]['run']($args);
    }

    public static function newReads(): array
    {
        return [
            ['blog_public', '0', 0], ['users_can_register', '1', 1],
            ['default_role', 'subscriber', 'subscriber'], ['WPLANG', 'fr_FR', 'fr_FR'],
        ];
    }

    #[DataProvider('newReads')]
    public function testT1NewReadTypes(string $name, mixed $stored, mixed $expected): void
    {
        WordPressRuntime::setOption($name, $stored);
        self::assertSame(['name' => $name, 'value' => $expected], $this->call('get-option', ['name' => $name]));
    }

    public function testT2ReadRefusalEnumeratesExactlyTheSurface(): void
    {
        self::assertSame(self::READ, array_keys(wpmcp_option_allow_list()));
        $description = wpmcp_inventory_tools()['get-option']['description'];
        foreach (self::READ as $name) { self::assertStringContainsString($name, $description); }
        $error = $this->call('get-option', ['name' => 'DB_PASSWORD']);
        self::assertInstanceOf(\WP_Error::class, $error);
        self::assertStringContainsString(implode(', ', self::READ), $error->get_error_message());
        self::assertSame($error->get_error_message(), $this->call('get-option', ['name' => 'not_a_setting'])->get_error_message());
    }

    public static function writes(): array
    {
        return [
            ['blogname', 'Old name', 'New name'], ['blogdescription', 'Old description', 'New description'],
            ['timezone_string', 'UTC', 'Europe/Paris'], ['date_format', 'Y-m-d', 'd/m/Y'],
            ['time_format', 'H:i', 'g:i a'], ['start_of_week', '0', 1],
            ['WPLANG', '', 'fr_FR'], ['blog_public', '1', 0],
            ['users_can_register', '0', 1], ['default_role', 'author', 'subscriber'],
        ];
    }

    #[DataProvider('writes')]
    public function testT3WritableRoundTripAndNoChange(string $name, mixed $old, mixed $new): void
    {
        WordPressRuntime::setOption('default_role', 'subscriber');
        WordPressRuntime::setOption($name, $old);
        $expectedOld = is_int($new) ? (int) $old : $old;
        self::assertSame(['name' => $name, 'old_value' => $expectedOld, 'new_value' => $new, 'changed' => true],
            $this->call('update-option', ['name' => $name, 'value' => $new]));
        self::assertSame([['option' => $name, 'value' => $new]], WordPressRuntime::optionWrites());
        self::assertSame($new, get_option($name));
        self::assertSame(['name' => $name, 'old_value' => $new, 'new_value' => $new, 'changed' => false],
            $this->call('update-option', ['name' => $name, 'value' => $new]));
        self::assertCount(1, WordPressRuntime::optionWrites());
    }

    public static function readOnly(): array
    {
        return [['siteurl', 'https://example.test'], ['home', 'https://example.test'],
            ['permalink_structure', '/%postname%/'], ['gmt_offset', 2.0]];
    }

    #[DataProvider('readOnly')]
    public function testT4ReadOnlyRemainsReadableAndUnwritten(string $name, mixed $value): void
    {
        WordPressRuntime::setOption($name, $value);
        self::assertSame($value, $this->call('get-option', ['name' => $name])['value']);
        $error = $this->call('update-option', ['name' => $name, 'value' => 'replacement']);
        self::assertSame('wpmcp_option_read_only', $error->get_error_code());
        self::assertStringContainsString('read-only', $error->get_error_message());
        self::assertSame([], WordPressRuntime::optionWrites());
        self::assertSame($value, get_option($name));
    }

    public static function administrativeCapabilities(): array
    {
        return array_map(static fn (string $cap): array => [$cap],
            ['create_users', 'edit_users', 'promote_users', 'manage_options', 'delete_users', 'remove_users',
                'install_plugins', 'activate_plugins', 'install_themes', 'switch_themes', 'edit_plugins', 'edit_themes', 'edit_files',
                'update_plugins', 'update_themes', 'update_core', 'unfiltered_html', 'manage_network',
                'manage_network_users', 'manage_network_plugins', 'manage_network_themes',
                'manage_network_options', 'manage_network_custom',
                'manage_sites', 'create_sites', 'delete_sites', 'upgrade_network']);
    }

    #[DataProvider('administrativeCapabilities')]
    public function testT5CustomRoleWithAdministrativeCapabilityIsRefused(string $capability): void
    {
        $GLOBALS['wpmcp_site_config_roles']['friendly_helper'] = (object) ['name' => 'friendly_helper', 'capabilities' => [$capability => true]];
        WordPressRuntime::setOption('default_role', 'subscriber');
        $error = $this->call('update-option', ['name' => 'default_role', 'value' => 'friendly_helper']);
        self::assertInstanceOf(\WP_Error::class, $error);
        self::assertSame('wpmcp_unsafe_role', $error->get_error_code());
        self::assertStringContainsString($capability, $error->get_error_message());
        self::assertSame([], WordPressRuntime::optionWrites());
        self::assertSame('subscriber', get_option('default_role'));
    }

    public function testT6NonAdministrativeRoleWithExplicitFalseCapabilitiesIsAccepted(): void
    {
        $GLOBALS['wpmcp_site_config_roles']['writer'] = (object) ['name' => 'writer', 'capabilities' => [
            'read' => true, 'edit_posts' => true, 'manage_options' => false, 'create_users' => false, 'unfiltered_html' => false,
        ]];
        WordPressRuntime::setOption('default_role', 'subscriber');
        self::assertSame('writer', $this->call('update-option', ['name' => 'default_role', 'value' => 'writer'])['new_value']);
        self::assertSame('writer', WordPressRuntime::optionWrite('default_role'));
    }

    public function testT7UnknownRoleIsRefused(): void
    {
        $error = $this->call('update-option', ['name' => 'default_role', 'value' => 'missing_role']);
        self::assertSame('wpmcp_invalid_role', $error->get_error_code());
        self::assertStringContainsString('missing_role', $error->get_error_message());
        self::assertSame([], WordPressRuntime::optionWrites());
    }

    public static function unfilteredHtmlPaths(): array
    {
        $paths = [];
        foreach (['newsletter_helper', 'editor'] as $role) {
            $paths[$role . ' default change'] = [$role, 'default_role', $role, 'subscriber', 0];
            $paths[$role . ' default no-op'] = [$role, 'default_role', $role, $role, 0];
            foreach ([1, '1'] as $value) {
                foreach ([0, 1] as $registered) {
                    $paths[$role . ' enable ' . gettype($value) . ' from ' . $registered]
                        = [$role, 'users_can_register', $value, $role, $registered];
                }
            }
        }
        return $paths;
    }

    #[DataProvider('unfilteredHtmlPaths')]
    public function testR3UnfilteredHtmlOnlyRoleIsRefusedWithWpAdminAlternative(
        string $role, string $name, mixed $value, string $oldRole, int $registered
    ): void {
        // A lone grant proves classification, independently of editor's other capabilities.
        $GLOBALS['wpmcp_site_config_roles'][$role] = (object) [
            'name' => $role, 'capabilities' => ['unfiltered_html' => true],
        ];
        WordPressRuntime::setOption('default_role', $oldRole);
        WordPressRuntime::setOption('users_can_register', $registered);
        $error = $this->call('update-option', ['name' => $name, 'value' => $value]);
        self::assertInstanceOf(\WP_Error::class, $error);
        self::assertSame('wpmcp_unsafe_role', $error->get_error_code());
        self::assertStringContainsString($role, $error->get_error_message());
        self::assertStringContainsString('unfiltered_html', $error->get_error_message());
        self::assertStringContainsString('agent-driven tool', $error->get_error_message());
        self::assertStringContainsString('invisible at the call site', $error->get_error_message());
        self::assertStringContainsString('You can still make the change in wp-admin.', $error->get_error_message());
        self::assertSame([], WordPressRuntime::optionWrites());
        self::assertSame($oldRole, get_option('default_role'));
        self::assertSame($registered, get_option('users_can_register'));
    }

    public function testB2EnablingRegistrationRefusesCurrentHarmlessNamedAdminRoleWithoutWriting(): void
    {
        $GLOBALS['wpmcp_site_config_roles']['newsletter_helper'] = (object) [
            'name' => 'newsletter_helper', 'capabilities' => ['manage_options' => true],
        ];
        WordPressRuntime::setOption('default_role', 'newsletter_helper');
        WordPressRuntime::setOption('users_can_register', '0');
        $error = $this->call('update-option', ['name' => 'users_can_register', 'value' => '1']);
        self::assertSame([], WordPressRuntime::optionWrites(), 'Refusal must precede every write.');
        self::assertSame('0', get_option('users_can_register'));
        self::assertSame('newsletter_helper', get_option('default_role'));
        self::assertInstanceOf(\WP_Error::class, $error);
        self::assertSame('wpmcp_unsafe_role', $error->get_error_code());
        self::assertStringContainsString('newsletter_helper', $error->get_error_message());
        self::assertStringContainsString('manage_options', $error->get_error_message());
        self::assertStringContainsString('Fix default_role first', $error->get_error_message());
        self::assertStringContainsString('subscriber', $error->get_error_message());
    }

    #[DataProvider('administrativeCapabilities')]
    public function testEnablingRegistrationSweepsTheSameCapabilityClass(string $capability): void
    {
        $GLOBALS['wpmcp_site_config_roles']['newsletter_helper'] = (object) [
            'name' => 'newsletter_helper', 'capabilities' => [$capability => true],
        ];
        WordPressRuntime::setOption('default_role', 'newsletter_helper');
        WordPressRuntime::setOption('users_can_register', 0);
        $error = $this->call('update-option', ['name' => 'users_can_register', 'value' => 1]);
        self::assertSame('wpmcp_unsafe_role', $error->get_error_code());
        self::assertStringContainsString($capability, $error->get_error_message());
        self::assertSame([], WordPressRuntime::optionWrites());
        self::assertSame(0, get_option('users_can_register'));
    }

    public function testEnablingRegistrationWithUnknownRoleFailsWithoutWriting(): void
    {
        WordPressRuntime::setOption('default_role', 'missing_role');
        WordPressRuntime::setOption('users_can_register', 0);
        $error = $this->call('update-option', ['name' => 'users_can_register', 'value' => 1]);
        self::assertSame('wpmcp_invalid_role', $error->get_error_code());
        self::assertStringContainsString('missing_role', $error->get_error_message());
        self::assertStringContainsString('Fix default_role first', $error->get_error_message());
        self::assertSame([], WordPressRuntime::optionWrites());
    }

    public function testDisablingRegistrationWithUnsafeRoleRemainsPossible(): void
    {
        $GLOBALS['wpmcp_site_config_roles']['newsletter_helper'] = (object) [
            'capabilities' => ['unfiltered_html' => true],
        ];
        WordPressRuntime::setOption('default_role', 'newsletter_helper');
        WordPressRuntime::setOption('users_can_register', 1);
        self::assertSame(['name' => 'users_can_register', 'old_value' => 1, 'new_value' => 0, 'changed' => true],
            $this->call('update-option', ['name' => 'users_can_register', 'value' => '0']));
        self::assertSame([['option' => 'users_can_register', 'value' => 0]], WordPressRuntime::optionWrites());
    }

    public function testEnablingRegistrationAcceptsSafeRoleWithFalseAdministrativeGrants(): void
    {
        $GLOBALS['wpmcp_site_config_roles']['newsletter_helper'] = (object) [
            'capabilities' => ['read' => true, 'install_plugins' => false, 'manage_network_options' => false, 'unfiltered_html' => false],
        ];
        WordPressRuntime::setOption('default_role', 'newsletter_helper');
        WordPressRuntime::setOption('users_can_register', 0);
        self::assertSame(['name' => 'users_can_register', 'old_value' => 0, 'new_value' => 1, 'changed' => true],
            $this->call('update-option', ['name' => 'users_can_register', 'value' => '1']));
        self::assertSame([['option' => 'users_can_register', 'value' => 1]], WordPressRuntime::optionWrites());
    }

    public function testAlreadyEnabledUnsafeRegistrationIsRefusedBeforeNoop(): void
    {
        $GLOBALS['wpmcp_site_config_roles']['newsletter_helper'] = (object) [
            'capabilities' => ['activate_plugins' => true],
        ];
        WordPressRuntime::setOption('default_role', 'newsletter_helper');
        WordPressRuntime::setOption('users_can_register', 1);
        self::assertSame('wpmcp_unsafe_role',
            $this->call('update-option', ['name' => 'users_can_register', 'value' => 1])->get_error_code());
        self::assertSame([], WordPressRuntime::optionWrites());
    }

    public static function deniedPaths(): array
    {
        $paths = array_map(static fn (array $row): array => ['update-option', ['name' => $row[0], 'value' => $row[2]]], self::writes());
        foreach (self::readOnly() as [$name]) { $paths[] = ['update-option', ['name' => $name, 'value' => 'x']]; }
        foreach ([['name' => 'unknown', 'value' => 'x'], [], ['name' => 'blogname'],
            ['name' => 'blog_public', 'value' => []], ['name' => 'blog_public', 'value' => 2],
            ['name' => 'default_role', 'value' => 'missing_role'], ['name' => 'default_role', 'value' => 'friendly_helper']] as $args) {
            $paths[] = ['update-option', $args];
        }
        $paths[] = ['get-constants', []];
        $paths[] = ['get-constants', ['name' => 'DB_PASSWORD']];
        $paths[] = ['update-option', ['name' => 'blogname', 'value' => 'Same'], true];
        return $paths;
    }

    #[DataProvider('deniedPaths')]
    public function testT8T11EveryPathRequiresManageOptions(string $tool, array $args, bool $unchanged = false): void
    {
        WordPressRuntime::install();
        WordPressRuntime::allowCap('edit_posts');
        if ($unchanged) { WordPressRuntime::setOption('blogname', 'Same'); }
        $error = $this->call($tool, $args);
        self::assertInstanceOf(\WP_Error::class, $error);
        self::assertSame('wpmcp_forbidden', $error->get_error_code());
        self::assertSame([], WordPressRuntime::optionWrites());
        if ($unchanged) { self::assertSame('Same', get_option('blogname')); }
    }

    public function testT9ConstantsCannotLeakCredentialNamesOrSerializedValues(): void
    {
        $probe = $this->constantsProbe("define('DB_PASSWORD', 'fixture-db-password-unique');"
            . "define('AUTH_KEY', 'fixture-auth-key-unique');"
            . "define('SECURE_AUTH_SALT', 'fixture-salt-unique');"
            . "define('WP_ENVIRONMENT_TYPE', 'staging');");
        $result = $probe['result'];
        self::assertSame(self::CONSTANTS, array_keys($result['constants']));
        self::assertSame(['defined' => true, 'value' => 'staging'], $result['constants']['WP_ENVIRONMENT_TYPE']);
        $serialized = json_encode($result, JSON_THROW_ON_ERROR);
        // Positive control: this serializer/search really sees a value known to be present.
        self::assertStringContainsString('staging', $serialized);
        foreach (['DB_PASSWORD' => 'fixture-db-password-unique', 'AUTH_KEY' => 'fixture-auth-key-unique',
            'SECURE_AUTH_SALT' => 'fixture-salt-unique'] as $name => $secret) {
            self::assertTrue($probe['defined'][$name]);
            self::assertFalse(str_contains($serialized, $name));
            self::assertFalse(str_contains($serialized, $secret));
        }
    }

    public function testT10DefinedTrueFalseAndAbsentAreDifferentAnswers(): void
    {
        $probe = $this->constantsProbe("define('DISALLOW_FILE_EDIT', true);define('DISALLOW_FILE_MODS', false);");
        self::assertFalse($probe['defined']['SCRIPT_DEBUG']);
        $constants = $probe['result']['constants'];
        self::assertSame(['defined' => true, 'value' => true], $constants['DISALLOW_FILE_EDIT']);
        self::assertSame(['defined' => true, 'value' => false], $constants['DISALLOW_FILE_MODS']);
        self::assertSame(['defined' => false, 'value' => null], $constants['SCRIPT_DEBUG']);
    }

    /**
     * Constants cannot be undefined between tests. Use a fresh PHP process, retaining
     * PHPRC through its environment, and inspect its exit code plus a fenced JSON answer.
     * Unlike PHPUnit's process isolation, a harmless startup warning on stderr is not
     * an exception here; missing output or nonzero exit still fails loudly.
     */
    private function constantsProbe(string $fixture): array
    {
        $source = 'require ' . var_export(WPMCP_PLUGIN_DIR . '/tests/bootstrap.php', true) . ';'
            . '\\WpMcp\\Tests\\Support\\WordPressStubs::loadPlugin();'
            . '\\WpMcp\\Tests\\Support\\WordPressRuntime::install();'
            . '\\WpMcp\\Tests\\Support\\WordPressRuntime::allowCap("manage_options");'
            . $fixture
            . '$defined = []; foreach (["DB_PASSWORD", "AUTH_KEY", "SECURE_AUTH_SALT", "SCRIPT_DEBUG"] as $name) {$defined[$name] = defined($name);}'
            . 'echo "-----SITE-CONFIG-----", json_encode(["result" => wpmcp_inventory_tools()["get-constants"]["run"]([]), "defined" => $defined], JSON_THROW_ON_ERROR), "-----END-----";';
        $process = proc_open([PHP_BINARY, '-r', $source], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $stdout . $stderr);
        self::assertSame(1, preg_match('/-----SITE-CONFIG-----(.*?)-----END-----/s', $stdout, $match), $stdout . $stderr);
        return json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
    }

    public function testConstantsRejectArgumentsInsteadOfLookingUpNames(): void
    {
        self::assertSame('wpmcp_invalid_param', $this->call('get-constants', ['name' => 'DB_PASSWORD'])->get_error_code());
    }

    public function testIntegerStringIsCoercedAndMissingOldValueIsNull(): void
    {
        self::assertSame(['name' => 'blog_public', 'old_value' => null, 'new_value' => 0, 'changed' => true],
            $this->call('update-option', ['name' => 'blog_public', 'value' => '0']));
    }

    public static function invalidValues(): array
    {
        return [['blog_public', 2], ['blog_public', 'foo'], ['users_can_register', -1],
            ['start_of_week', 7], ['blogname', []], ['blogname', 1], ['WPLANG', null]];
    }

    #[DataProvider('invalidValues')]
    public function testInvalidValueIsRefusedWithoutWriting(string $name, mixed $value): void
    {
        self::assertSame('wpmcp_invalid_param', $this->call('update-option', ['name' => $name, 'value' => $value])->get_error_code());
        self::assertSame([], WordPressRuntime::optionWrites());
    }

    public function testUnknownWriteAndMissingValueAreRefused(): void
    {
        self::assertSame('wpmcp_option_not_listed', $this->call('update-option', ['name' => 'admin_email', 'value' => 'x'])->get_error_code());
        self::assertSame('wpmcp_invalid_param', $this->call('update-option', ['name' => 'blogname'])->get_error_code());
        self::assertSame([], WordPressRuntime::optionWrites());
    }

    public function testNewToolContractsWithoutTheSharedCatalogCountFixture(): void
    {
        $tools = wpmcp_inventory_tools();
        foreach (['get-option', 'update-option', 'get-constants'] as $name) {
            $tool = $tools[$name];
            self::assertLessThanOrEqual(1000, strlen($tool['description']));
            self::assertSame(1, preg_match('/\.(\s|$)/', $tool['description'], $match, PREG_OFFSET_CAPTURE));
            self::assertLessThan(50, $match[0][1]);
            self::assertStringContainsString('Returns', $tool['description']);
            [$published, $removed] = SchemaValidator::publishable($tool['inputSchema']);
            self::assertSame([], $removed);
            self::assertSame($tool['inputSchema'], $published);
            self::assertNull(SchemaValidator::unreadableConstraint($tool['inputSchema']));
        }
        self::assertSame(['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false],
            $tools['update-option']['annotations']);
        self::assertSame(['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
            $tools['get-constants']['annotations']);
        self::assertTrue($tools['update-option']['write']);
        self::assertTrue($tools['get-constants']['write']);
    }
}
