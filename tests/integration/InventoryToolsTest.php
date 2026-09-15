<?php
/**
 * Sprint 14: admin inventory - list-users, get-user, get-option, list-plugins and
 * list-themes. All five only read.
 *
 * WHAT IS OURS TO TEST. Not whether WordPress can list its users - it can. What is ours:
 *
 *   - THE CEILING, FIELD BY FIELD. Every field a tool returns is one core's REST API shows
 *     the same caller. A caller without list_users sees only users who have published
 *     posts in a REST-visible post type, as id and display name
 *     (class-wp-rest-users-controller.php:321-322); login, email, roles and the
 *     registered date are for list_users. A user such a caller may not see is the SAME
 *     answer as an id nobody used (:495).
 *   - A FIXED OPTION ALLOW-LIST, whose refusal is one sentence for a secret option, one of
 *     this plugin's own, one that does not exist and one that exists and is not listed -
 *     so it cannot be used to learn what a site has installed.
 *   - NO OUTBOUND HTTP. wp-admin's plugin and theme screens refresh update data from
 *     api.wordpress.org; these tools read what is stored and must not. A header-gated
 *     mu-plugin counts every pre_http_request inside the MCP request, blocks it, and makes
 *     the stored update data look stale so that any refresh WOULD go out.
 *   - NO SECRETS. Fixture users carry a password hash, an activation key and a live
 *     session; no response of any of the five carries any of them.
 *
 * FIXTURE USERS ONLY. The stress site's users are real people's accounts: no assertion
 * names one, and no assertion message prints a response that could contain one - every
 * check on a users response is a str_contains inside assertFalse, never a string
 * assertion that echoes its haystack.
 *
 * NOTHING HERE CAN SKIP on a bare site: every expectation about plugins, themes, options
 * and published authors is read from the site through wp-cli, not assumed.
 *
 * @group sprint-14
 */

declare(strict_types=1);

namespace WpMcp\Tests\Integration;

use WpMcp\Tests\Support\FixtureIntegrationTestCase;
use WpMcp\Tests\Support\Fixtures;
use WpMcp\Tests\Support\IntegrationTestCase;
use WpMcp\Tests\Support\MuPlugin;
use WpMcp\Tests\Support\ToolResult;
use WpMcp\Tests\Support\WpCli;

final class InventoryToolsTest extends FixtureIntegrationTestCase
{
    /** No site this suite runs against has a user id anywhere near this. */
    private const MISSING_ID = 2000000000;

    /** The mu-plugin that counts and blocks outbound HTTP for this run's watched requests. */
    private const HTTP_PLUGIN = 'httpcount';

    /**
     * The mu-plugin that plays a third-party plugin: for this run's watched requests it hooks
     * every filter a plugin or theme listing could run - update data, auto-update state, the
     * option and header reads, the theme lookups - and makes an outbound request from inside
     * each one, as Gravity Forms, LiteSpeed Cache and Rank Math do from theirs (review round 1).
     */
    private const HOOKS_PLUGIN = 'httphooks';

    /** Every hook the third-party fixture makes a request from. Its host names the hook. */
    private const HOOKED = [
        'pre_site_transient_update_plugins',
        'site_transient_update_plugins',
        'pre_site_transient_update_themes',
        'site_transient_update_themes',
        'auto_update_plugin',
        'auto_update_theme',
        'plugins_auto_update_enabled',
        'themes_auto_update_enabled',
        'automatic_updater_disabled',
        'file_mod_allowed',
        'extra_plugin_headers',
        'pre_option_active_plugins',
        'option_active_plugins',
        'pre_site_option_active_sitewide_plugins',
        'site_option_active_sitewide_plugins',
        'pre_site_option_auto_update_plugins',
        'site_option_auto_update_plugins',
        'pre_option_auto_update_plugins',
        'option_auto_update_plugins',
        'extra_theme_headers',
        'theme_file_path',
        'stylesheet',
        'template',
        'pre_option_stylesheet',
        'option_stylesheet',
        'pre_option_template',
        'option_template',
        'wp_cache_themes_persistently',
        'pre_site_transient_theme_roots',
        'site_transient_theme_roots',
        'theme_root',
        'pre_kses',
    ];

    private const WATCH_HEADER = 'X-Wpmcp-Test-Http-Watch';
    private const PROBE_HEADER = 'X-Wpmcp-Test-Http-Probe';
    private const COUNT_HEADER = 'X-Wpmcp-Test-Http-Count';
    private const HOSTS_HEADER = 'X-Wpmcp-Test-Http-Hosts';

    /** A host that cannot resolve anywhere, for the counter's own control. */
    private const PROBE_HOST = 'wpmcp-test-probe.invalid';

    private const ALLOWED_OPTIONS = [
        'blogname', 'blogdescription', 'timezone_string', 'gmt_offset', 'date_format',
        'time_format', 'start_of_week', 'permalink_structure', 'siteurl', 'home',
    ];

    /** role key => WordPress role */
    private const ROLES = [
        'admin'      => 'administrator',
        'editor'     => 'editor',
        'author'     => 'author',
        'quiet'      => 'contributor',
        'subscriber' => 'subscriber',
    ];

    private static function label(): string { return Fixtures::name('inventory'); }
    private static function login(string $key): string { return Fixtures::name('inv' . $key); }
    private static function display(string $key): string { return Fixtures::name('Shown ' . ucfirst($key)); }
    private static function email(string $key): string { return self::login($key) . '@example.invalid'; }

    /** @var array<string, int> role key => user id */
    private static array $users = [];

    private static int $publishedPostId = 0;

    private static string $adminToken = '';
    private static string $adminReadToken = '';
    private static string $editorToken = '';
    private static string $editorReadToken = '';
    private static string $subscriberToken = '';

    /** @var array<string, array<string, string>> role key => kind => secret */
    private static array $secrets = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::requireSite();

        self::buildFixtures(self::build(...), self::destroy(...));
    }

    private static function build(): void
    {
        Fixtures::purge();

        foreach (self::ROLES as $key => $role) {
            self::$users[$key] = Fixtures::createUser(self::login($key), $role);
            // A display name that is NOT the login, or "no login in the response" could
            // not be told apart from "the name is shown".
            Fixtures::setDisplayName(self::$users[$key], self::display($key));
        }

        // The one fixture user with a published post: the one an Editor may see.
        self::$publishedPostId = Fixtures::createPost(
            Fixtures::name('inventory-published'), 'publish', self::$users['author'], 'x'
        );

        foreach (self::$users as $key => $id) {
            self::$secrets[$key] = self::giveSecrets($id);
        }

        MuPlugin::drop(self::HTTP_PLUGIN, self::httpSource());
        MuPlugin::drop(self::HOOKS_PLUGIN, self::hooksSource());

        self::$adminToken      = Fixtures::mintToken('admin', self::label(), self::$users['admin']);
        self::$adminReadToken  = Fixtures::mintToken('read', self::label(), self::$users['admin']);
        self::$editorToken     = Fixtures::mintToken('admin', self::label(), self::$users['editor']);
        self::$editorReadToken = Fixtures::mintToken('read', self::label(), self::$users['editor']);
        self::$subscriberToken = Fixtures::mintToken('admin', self::label(), self::$users['subscriber']);
    }

    public static function tearDownAfterClass(): void
    {
        self::destroy();

        parent::tearDownAfterClass();
    }

    private static function destroy(): void
    {
        MuPlugin::remove(self::HTTP_PLUGIN);
        MuPlugin::remove(self::HOOKS_PLUGIN);

        Fixtures::deletePost(self::$publishedPostId);
        self::$publishedPostId = 0;

        // Sessions and the activation key go with the user row and its meta.
        foreach (self::$users as $id) { Fixtures::deleteUser((int) $id); }
        self::$users = [];

        Fixtures::deleteTokensLabelled(self::label());
        Fixtures::purge();
    }

    /* ------------------------------------------------------------------
     * G1 - list-users, as core's REST endpoint draws the line
     * ---------------------------------------------------------------- */

    /**
     * G1. An Editor's list-users is exactly the users core shows a caller without
     * list_users - those with published posts in a REST-visible post type - each as id
     * and name, and nothing in any page names a login, an email or a role.
     *
     * @group sprint-14
     */
    public function testAnEditorListsOnlyUsersWithPublishedPostsAsIdAndName(): void
    {
        self::assertFalse(Fixtures::userCan(self::$users['editor'], 'list_users'), 'Premise: an Editor here holds list_users.');

        $expected = self::publishedAuthorIds();
        self::assertContains(self::$users['author'], $expected, 'Premise: core does not count the fixture Author, who has a published post.');
        foreach (['admin', 'editor', 'quiet', 'subscriber'] as $key) {
            self::assertNotContains(self::$users[$key], $expected, "Premise: core counts the {$key} fixture, who has no post, as a published author.");
        }

        [$items, $raw] = $this->everyUser(self::$editorReadToken);

        foreach ($items as $item) {
            self::assertSame(['id', 'name'], array_keys($item), 'An Editor was shown more than id and name for user ' . (int) ($item['id'] ?? 0) . '.');
        }

        $ids = array_map('intval', array_column($items, 'id'));
        sort($ids);
        self::assertSame($expected, $ids, 'An Editor\'s list-users is not the set core shows a caller without list_users.');

        $names = array_column($items, 'name', 'id');
        self::assertSame(self::display('author'), $names[self::$users['author']] ?? null, 'The fixture Author is not shown by display name.');

        foreach (self::ROLES as $key => $role) {
            self::assertFalse(str_contains($raw, self::login($key)), "An Editor's list-users carries the {$key} fixture's login.");
        }
        foreach (['@example.invalid', '"email"', '"login"', '"roles"', '"registered"', 'administrator', 'contributor', 'subscriber'] as $needle) {
            self::assertFalse(str_contains($raw, $needle), "An Editor's list-users carries {$needle}.");
        }
    }

    /**
     * G1. The role and search filters are list_users filters in core (roles: :203); an
     * Editor asking for either is refused by name, the same sentence whether the role
     * exists or not, and the refusal names no user.
     *
     * @group sprint-14
     */
    public function testAnEditorIsRefusedTheRoleAndSearchFilters(): void
    {
        foreach ([
            'role'   => [['role' => 'administrator'], ['role' => Fixtures::name('no-such-role')]],
            'search' => [['search' => self::login('quiet')], ['search' => Fixtures::name('nobody')]],
        ] as $argument => $calls) {
            $texts = [];

            foreach ($calls as $arguments) {
                $result = $this->mcp(self::$editorReadToken)->callTool('list-users', $arguments);

                self::assertTrue($result->isError, "An Editor was allowed to filter list-users by {$argument}.");
                self::assertStringContainsString($argument, $result->text, "The refusal does not name the {$argument} argument.");
                self::assertStringContainsString('list_users', $result->text, 'The refusal does not say what capability it needs.');
                self::assertFalse(str_contains($result->text, self::login('quiet')), 'The refusal carries a login.');

                $texts[] = $result->text;
            }

            self::assertCount(1, array_unique($texts), "The {$argument} refusal differs between a value that exists and one that does not.");
        }
    }

    /**
     * G1. An Administrator gets login, email, roles and the registered date - what
     * wp-admin's Users screen shows them - and may filter by role and search.
     *
     * @group sprint-14
     */
    public function testAnAdministratorGetsLoginEmailAndRolesForFixtureUsers(): void
    {
        $result = $this->mcp(self::$adminReadToken)->callTool('list-users', ['search' => Fixtures::runPrefix(), 'limit' => 100]);
        self::assertFalse($result->isError, 'An Administrator\'s list-users was refused.');

        $items = array_column($result->items(), null, 'id');
        self::assertCount(count(self::ROLES), $items, 'A search for this run\'s prefix did not return exactly this run\'s fixture users.');

        foreach (self::ROLES as $key => $role) {
            $item = $items[self::$users[$key]] ?? null;
            self::assertIsArray($item, "The {$key} fixture is missing from an Administrator's list-users.");
            self::assertSame(['id', 'name', 'login', 'email', 'roles', 'registered'], array_keys($item));
            self::assertSame(self::display($key), $item['name']);
            self::assertSame(self::login($key), $item['login']);
            self::assertSame(self::email($key), $item['email']);
            self::assertSame([$role], $item['roles']);
            self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', (string) $item['registered']);
        }

        $filtered = $this->mcp(self::$adminReadToken)->callTool('list-users', ['search' => Fixtures::runPrefix(), 'role' => 'contributor']);
        self::assertFalse($filtered->isError, 'The role filter was refused to an Administrator.');
        self::assertSame([self::$users['quiet']], array_map('intval', $filtered->column('id')), 'The role filter did not narrow to the one fixture Contributor.');

        $unknown = $this->mcp(self::$adminReadToken)->callTool('list-users', ['search' => Fixtures::runPrefix(), 'role' => Fixtures::name('no-such-role')]);
        self::assertFalse($unknown->isError, 'An unknown role is an empty list, not an error.');
        self::assertSame([], $unknown->items());
    }

    /**
     * G1. A caller holding neither list_users nor edit_posts on any REST post type is
     * refused by the user tools (core: :237-251), and by get-option (edit_posts).
     *
     * @group sprint-14
     */
    public function testASubscriberIsRefusedByTheUserAndOptionTools(): void
    {
        self::assertFalse(Fixtures::userCan(self::$users['subscriber'], 'edit_posts'), 'Premise: a Subscriber here holds edit_posts.');

        foreach ([
            ['list-users', []],
            ['get-user', ['id' => self::$users['author']]],
            ['get-option', ['name' => 'blogname']],
        ] as [$tool, $arguments]) {
            $result = $this->mcp(self::$subscriberToken)->callTool($tool, $arguments);

            self::assertTrue($result->isError, "A Subscriber was answered by {$tool}.");
            self::assertStringContainsString('not allowed', $result->text, "{$tool} refused a Subscriber for another reason.");
            self::assertFalse(str_contains($result->text, self::display('author')), "{$tool}'s refusal names a user.");
        }
    }

    /* ------------------------------------------------------------------
     * G2 - get-user: a user the caller may not see is not there
     * ---------------------------------------------------------------- */

    /**
     * G2. For an Editor, a fixture user with no published posts is the same answer as an
     * id nobody used; the published Author is id and name; the Editor's own record is
     * theirs to read in full (edit_user on oneself); an Administrator reads anyone.
     *
     * @group sprint-14
     */
    public function testGetUserOnAUserWithoutPublishedPostsIsTheMissingIdAnswerForAnEditor(): void
    {
        self::assertFalse(Fixtures::userCan(self::$users['editor'], 'edit_user', self::$users['quiet']), 'Premise: an Editor here may edit the Contributor.');
        self::assertSame(0, self::publishedCount(self::$users['quiet']), 'Premise: the quiet fixture has a published post.');

        $editor  = $this->mcp(self::$editorReadToken);
        $missing = $editor->callTool('get-user', ['id' => self::MISSING_ID]);
        self::assertTrue($missing->isError, 'A missing id was answered.');

        foreach (['quiet', 'subscriber', 'admin'] as $key) {
            $hidden = $editor->callTool('get-user', ['id' => self::$users[$key]]);

            self::assertTrue($hidden->isError, "An Editor was shown the {$key} fixture, who has no published post.");
            self::assertSame($missing->text, $hidden->text, "An Editor can tell the {$key} fixture from a missing id.");
        }

        $author = $editor->callTool('get-user', ['id' => self::$users['author']]);
        self::assertFalse($author->isError, $author->text);
        self::assertSame(['id' => self::$users['author'], 'name' => self::display('author')], $author->data());

        $self = $editor->callTool('get-user', ['id' => self::$users['editor']]);
        self::assertFalse($self->isError, $self->text);
        self::assertSame(self::login('editor'), $self->data()['login'] ?? null, 'An Editor cannot read their own record in full, which core allows.');

        $admin = $this->mcp(self::$adminReadToken)->callTool('get-user', ['id' => self::$users['quiet']]);
        self::assertFalse($admin->isError, $admin->text);
        self::assertSame(
            [
                'id'    => self::$users['quiet'],
                'name'  => self::display('quiet'),
                'login' => self::login('quiet'),
                'email' => self::email('quiet'),
                'roles' => ['contributor'],
            ],
            array_intersect_key($admin->data(), array_flip(['id', 'name', 'login', 'email', 'roles']))
        );
        self::assertArrayHasKey('registered', $admin->data());

        $adminMissing = $this->mcp(self::$adminReadToken)->callTool('get-user', ['id' => self::MISSING_ID]);
        self::assertTrue($adminMissing->isError);
        self::assertSame($missing->text, $adminMissing->text, 'The missing-id answer differs by caller.');
    }

    /* ------------------------------------------------------------------
     * G3 - get-option: a fixed allow-list, one refusal
     * ---------------------------------------------------------------- */

    /**
     * G3. Every allow-listed option comes back as the site stores it, to an Editor on a
     * read token as to an Administrator.
     *
     * @group sprint-14
     */
    public function testGetOptionReturnsTheAllowListedValues(): void
    {
        $site = self::decode(WpCli::evaluate(
            '$o = array(); foreach (' . var_export(self::ALLOWED_OPTIONS, true) . ' as $n) { $o[$n] = get_option($n); }'
            . ' echo wp_json_encode($o);'
        ));

        foreach ([self::$editorReadToken, self::$adminReadToken] as $token) {
            foreach (self::ALLOWED_OPTIONS as $name) {
                $result = $this->mcp($token)->callTool('get-option', ['name' => $name]);
                self::assertFalse($result->isError, "get-option refused {$name}: " . $result->text);

                $data = $result->data();
                self::assertSame($name, $data['name'] ?? null);
                self::assertArrayHasKey('value', $data);

                if ($name === 'start_of_week') {
                    self::assertIsInt($data['value'], 'start_of_week is not an integer, as core registers it.');
                    self::assertSame((int) $site[$name], $data['value']);
                } elseif ($name === 'gmt_offset') {
                    self::assertTrue(is_int($data['value']) || is_float($data['value']), 'gmt_offset is not a number.');
                    self::assertEquals((float) $site[$name], (float) $data['value']);
                } else {
                    self::assertSame((string) $site[$name], $data['value'], "{$name} is not the stored value.");
                }
            }
        }
    }

    /**
     * G3. admin_email, one of this plugin's own options, a name that exists nowhere, one
     * that exists and is not listed, and a listed name in other letters all get the
     * identical refusal - to the most privileged caller there is, and to an Editor.
     *
     * @group sprint-14
     */
    public function testEveryNameOffTheListGetsTheIdenticalRefusal(): void
    {
        $nowhere = Fixtures::name('no-such-option');
        $exists  = self::decode(WpCli::evaluate(sprintf(
            'global $wpdb; $o = array(); foreach (array("admin_email", "wpmcp_db_ver", "users_can_register", "active_plugins", %s) as $n) {'
            . ' $o[$n] = (bool) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %%s", $n)); }'
            . ' echo wp_json_encode($o);',
            var_export($nowhere, true)
        )));
        self::assertTrue($exists['admin_email'], 'Premise: admin_email is not stored on this site.');
        self::assertTrue($exists['wpmcp_db_ver'], 'Premise: wpmcp_db_ver is not stored on this site.');
        self::assertTrue($exists['users_can_register'], 'Premise: users_can_register is not stored on this site.');
        self::assertTrue($exists['active_plugins'], 'Premise: active_plugins is not stored on this site.');
        self::assertFalse($exists[$nowhere], 'Premise: the made-up option name exists.');

        $adminEmail = trim(WpCli::evaluate('echo get_option("admin_email");'));

        $texts = [];
        foreach (['admin_email', 'wpmcp_db_ver', $nowhere, 'users_can_register', 'active_plugins', 'BlogName'] as $name) {
            foreach (['admin' => self::$adminToken, 'editor' => self::$editorReadToken] as $who => $token) {
                $result = $this->mcp($token)->callTool('get-option', ['name' => $name]);

                self::assertTrue($result->isError, "get-option answered {$name} to the {$who}.");
                self::assertFalse($adminEmail !== '' && str_contains($result->text, $adminEmail), "The refusal of {$name} carries the admin email.");
                $texts["{$name} as {$who}"] = $result->text;
            }
        }

        self::assertCount(1, array_unique($texts), 'The refusals differ, so they tell a caller which names exist: ' . print_r($texts, true));
    }

    /* ------------------------------------------------------------------
     * G4 - list-plugins and list-themes: admin scope, activate_plugins / switch_themes,
     * and not one outbound request
     * ---------------------------------------------------------------- */

    /**
     * G4. Refused for an Editor on an admin-scope token (capability) and for an
     * Administrator on a read-scope token (scope). Listed by scope, as every tool is:
     * the read token does not see them, the Editor's admin token does.
     *
     * @group sprint-14
     */
    public function testThePluginAndThemeListsAreRefusedForAnEditorAndForAReadScopeToken(): void
    {
        self::assertFalse(Fixtures::userCan(self::$users['editor'], 'activate_plugins'), 'Premise: an Editor here holds activate_plugins.');
        self::assertFalse(Fixtures::userCan(self::$users['editor'], 'switch_themes'), 'Premise: an Editor here holds switch_themes.');

        foreach (['list-plugins', 'list-themes'] as $tool) {
            $editor = $this->mcp(self::$editorToken)->callTool($tool);
            self::assertTrue($editor->isError, "An Editor was answered by {$tool}.");
            self::assertStringContainsString('not allowed', $editor->text, "{$tool} refused an Editor for another reason.");

            $read = $this->mcp(self::$adminReadToken)->callTool($tool);
            self::assertTrue($read->isError, "A read-scope token was answered by {$tool}.");
            self::assertSame('This tool requires an admin-scope token.', $read->text);
        }

        $readList  = $this->toolNames(self::$adminReadToken);
        $adminList = $this->toolNames(self::$adminToken);
        $editList  = $this->toolNames(self::$editorToken);

        foreach (['list-users', 'get-user', 'get-option'] as $tool) {
            self::assertContains($tool, $readList, "{$tool} is not listed to a read-scope token.");
        }
        foreach (['list-plugins', 'list-themes'] as $tool) {
            self::assertNotContains($tool, $readList, "{$tool} is listed to a read-scope token.");
            self::assertContains($tool, $adminList, "{$tool} is not listed to an admin-scope token.");
            self::assertContains($tool, $editList, "{$tool} is hidden from an Editor's admin token; tools are listed by scope, refused by capability.");
        }
    }

    /**
     * G4, the counter's own control: a request made while a tool runs IS counted, so a
     * zero below means zero and not a counter that was never armed.
     *
     * @group sprint-14
     */
    public function testTheOutboundCounterCountsARequestMadeWhileAToolRuns(): void
    {
        [$result, $count, $hosts] = $this->watched(self::$adminToken, 'site-info', [], true);

        self::assertFalse($result->isError, $result->text);
        self::assertGreaterThanOrEqual(1, $count, 'The probe request was not counted, so a zero from this counter proves nothing.');
        self::assertStringContainsString(self::PROBE_HOST, $hosts);
    }

    /**
     * G4, the third-party fixture's own control: site-info reads active_plugins through
     * get_option(), so the fixture's request from option_active_plugins is counted. A zero
     * from list-plugins or list-themes below therefore means they ran none of the hooked
     * filters, not that the fixture was never loaded.
     *
     * @group sprint-14
     */
    public function testTheThirdPartyFixtureRequestsFromInsideAHookedFilter(): void
    {
        [$result, $count, $hosts] = $this->watched(self::$adminToken, 'site-info');

        self::assertFalse($result->isError, $result->text);
        self::assertGreaterThanOrEqual(1, $count, 'The third-party fixture made no request from option_active_plugins, so it proves nothing.');
        self::assertStringContainsString(self::hookHost('option_active_plugins'), $hosts);
    }

    /**
     * G4. An Administrator's list-plugins matches get_plugins() on the site, file by
     * file, and makes no outbound request while it runs - with a third-party fixture making a
     * request from inside every update, auto-update, option and header filter it could run.
     *
     * @group sprint-14
     */
    public function testAnAdministratorListsPluginsWithoutOneOutboundRequest(): void
    {
        [$result, $count, $hosts] = $this->watched(self::$adminToken, 'list-plugins');

        self::assertFalse($result->isError, $result->text);
        self::assertSame(0, $count, "list-plugins made {$count} outbound HTTP request(s) while it ran, to: {$hosts}");

        $site = self::decode(WpCli::evaluate(
            'require_once ABSPATH . "wp-admin/includes/plugin.php"; $o = array();'
            . ' foreach (get_plugins() as $f => $d) { $o[$f] = array("name" => $d["Name"], "version" => $d["Version"], "active" => is_plugin_active($f)); }'
            . ' echo wp_json_encode(array("multisite" => is_multisite(), "plugins" => $o));'
        ));

        $data    = $result->data();
        $plugins = $data['plugins'] ?? null;
        self::assertIsArray($plugins, 'list-plugins returned no plugins list: ' . $result->text);
        self::assertSame(count($plugins), $data['count'] ?? null, 'count is not the number of plugins listed.');

        $byFile = array_column($plugins, null, 'file');
        $files  = array_keys($byFile);
        $want   = array_keys($site['plugins']);
        sort($files);
        sort($want);
        self::assertSame($want, $files, 'list-plugins does not list exactly get_plugins().');

        foreach ($site['plugins'] as $file => $plugin) {
            $item = $byFile[$file];
            self::assertSame($plugin['name'], $item['name'], "{$file}: name");
            self::assertSame($plugin['version'], $item['version'], "{$file}: version");
            self::assertSame($plugin['active'], $item['active'], "{$file}: active");
            self::assertArrayHasKey('auto_update', $item, "{$file}: auto_update is missing.");
            self::assertTrue($item['auto_update'] === null || is_bool($item['auto_update']), "{$file}: auto_update is neither a boolean nor null.");
            self::assertSame((bool) $site['multisite'], array_key_exists('network_active', $item), "{$file}: network_active is reported on a single site, or missing on a network.");
        }
    }

    /**
     * G4. An Administrator's list-themes makes no outbound request while it runs, with the
     * same third-party fixture hooked on every theme, option and update filter.
     *
     * @group sprint-14
     */
    public function testAnAdministratorListsThemesWithoutOneOutboundRequest(): void
    {
        [$result, $count, $hosts] = $this->watched(self::$adminToken, 'list-themes');

        self::assertFalse($result->isError, $result->text);
        self::assertSame(0, $count, "list-themes made {$count} outbound HTTP request(s) while it ran, to: {$hosts}");
        self::assertNotEmpty($result->data()['themes'] ?? [], 'list-themes listed no themes.');
    }

    /* ------------------------------------------------------------------
     * G5 - list-themes, as the site reports its themes
     * ---------------------------------------------------------------- */

    /**
     * G5. Every theme, the active one marked, block_theme per theme, the parent, and the
     * active theme's registered menu locations - each equal to what the site reports
     * through wp-cli. On the stress site that is a classic theme with five locations; on
     * the bare site a block theme with none (measured); the test assumes neither.
     *
     * @group sprint-14
     */
    public function testListThemesMarksTheActiveThemeAndBlockThemeAsTheSiteReportsThem(): void
    {
        $site = self::decode(WpCli::evaluate(
            '$o = array(); foreach (wp_get_themes() as $s => $t) { $o[$s] = array("name" => $t->get("Name"),'
            . ' "version" => $t->get("Version"), "template" => $t->get_template(), "block" => $t->is_block_theme()); }'
            . ' $l = array(); foreach (get_registered_nav_menus() as $k => $d) { $l[] = array("location" => (string) $k, "description" => (string) $d); }'
            . ' echo wp_json_encode(array("active" => get_stylesheet(), "is_block" => wp_is_block_theme(), "themes" => $o, "locations" => $l));'
        ));

        $result = $this->mcp(self::$adminToken)->callTool('list-themes');
        self::assertFalse($result->isError, $result->text);

        $data = $result->data();
        self::assertSame($site['active'], $data['active'] ?? null, 'list-themes names another active theme.');

        $byStylesheet = array_column($data['themes'] ?? [], null, 'stylesheet');
        $listed = array_keys($byStylesheet);
        $want   = array_keys($site['themes']);
        sort($listed);
        sort($want);
        self::assertSame($want, $listed, 'list-themes does not list exactly wp_get_themes().');

        $active = array_keys(array_filter($byStylesheet, static fn (array $t) => ($t['active'] ?? null) === true));
        self::assertSame([$site['active']], $active, 'Not exactly the active theme is marked active.');

        foreach ($site['themes'] as $stylesheet => $theme) {
            $item = $byStylesheet[$stylesheet];
            self::assertSame($theme['name'], $item['name'], "{$stylesheet}: name");
            self::assertSame($theme['version'], $item['version'], "{$stylesheet}: version");
            self::assertSame($theme['block'], $item['block_theme'], "{$stylesheet}: block_theme");
            self::assertSame($theme['template'] !== $stylesheet ? $theme['template'] : null, $item['parent'], "{$stylesheet}: parent");
            self::assertArrayHasKey('menu_locations', $item, "{$stylesheet}: menu_locations is missing.");

            if ($stylesheet === $site['active']) {
                self::assertSame($site['locations'], $item['menu_locations'], 'The active theme\'s menu locations are not the ones it registers.');
            } else {
                self::assertNull($item['menu_locations'], "{$stylesheet} is not active and still reports menu locations.");
            }
        }

        self::assertSame($site['is_block'], $byStylesheet[$site['active']]['block_theme'], 'The active theme\'s block_theme disagrees with wp_is_block_theme().');
    }

    /* ------------------------------------------------------------------
     * G6 - no secrets, from any of the five
     * ---------------------------------------------------------------- */

    /**
     * G6. Every fixture user has a password hash, an activation key and a live session.
     * No response of any of the five tools - to an Administrator or to an Editor - carries
     * any of them, in the form stored or the form handed out.
     *
     * @group sprint-14
     */
    public function testNoInventoryResponseCarriesAPasswordHashActivationKeyOrSessionToken(): void
    {
        foreach (self::$secrets as $key => $secret) {
            foreach (['hash', 'activation', 'session', 'verifier'] as $kind) {
                self::assertGreaterThan(8, strlen($secret[$kind] ?? ''), "Premise: the {$key} fixture has no {$kind}.");
            }
        }

        $responses = [];
        $admin     = $this->mcp(self::$adminToken);
        $editor    = $this->mcp(self::$editorReadToken);

        $responses['list-users as admin']  = $admin->callTool('list-users', ['search' => Fixtures::runPrefix(), 'limit' => 100])->text;
        $responses['list-users as editor'] = $this->everyUser(self::$editorReadToken)[1];

        foreach (self::$users as $key => $id) {
            $responses["get-user {$key} as admin"]  = $admin->callTool('get-user', ['id' => $id])->text;
            $responses["get-user {$key} as editor"] = $editor->callTool('get-user', ['id' => $id])->text;
        }

        foreach (self::ALLOWED_OPTIONS as $name) {
            $responses["get-option {$name}"] = $admin->callTool('get-option', ['name' => $name])->text;
        }

        $responses['list-plugins'] = $admin->callTool('list-plugins')->text;
        $responses['list-themes']  = $admin->callTool('list-themes')->text;

        foreach ($responses as $what => $raw) {
            foreach (['user_pass', 'user_activation_key', 'session_tokens'] as $column) {
                self::assertFalse(str_contains($raw, $column), "{$what} carries {$column}.");
            }

            foreach (self::$secrets as $key => $secret) {
                foreach ($secret as $kind => $value) {
                    self::assertFalse($value !== '' && str_contains($raw, $value), "{$what} carries the {$key} fixture's {$kind}.");
                }
            }
        }
    }

    /* ------------------------------------------------------------------
     * helpers
     * ---------------------------------------------------------------- */

    /**
     * Give a fixture user an activation key and a live session, and read back every
     * secret it now has: the stored hash, the stored activation key and its hash part,
     * the key handed out, the session token and the verifier core stores it under.
     *
     * @return array<string, string>
     */
    private static function giveSecrets(int $userId): array
    {
        return self::decode(WpCli::evaluate(sprintf(
            '$u = get_userdata(%1$d); $key = get_password_reset_key($u);'
            . ' $token = WP_Session_Tokens::get_instance(%1$d)->create(time() + HOUR_IN_SECONDS);'
            . ' clean_user_cache(%1$d); $u = get_userdata(%1$d);'
            . ' $stored = (string) $u->user_activation_key; $parts = explode(":", $stored, 2);'
            . ' echo wp_json_encode(array("hash" => (string) $u->user_pass, "activation" => $stored,'
            . ' "activation_hash" => isset($parts[1]) ? $parts[1] : "", "reset_key" => is_wp_error($key) ? "" : (string) $key,'
            . ' "session" => (string) $token, "verifier" => hash("sha256", (string) $token)));',
            $userId
        )));
    }

    /** @return list<int> sorted ids core shows a caller without list_users */
    private static function publishedAuthorIds(): array
    {
        $ids = array_map('intval', self::decode(WpCli::evaluate(
            'echo wp_json_encode(get_users(array("has_published_posts" => array_values(get_post_types(array("show_in_rest" => true), "names")),'
            . ' "fields" => "ID", "number" => -1)));'
        )));
        sort($ids);

        return $ids;
    }

    private static function publishedCount(int $userId): int
    {
        return (int) trim(WpCli::evaluate(sprintf(
            'echo (int) count_user_posts(%d, array_values(get_post_types(array("show_in_rest" => true), "names")), true);',
            $userId
        )));
    }

    /**
     * Every page of list-users for this token.
     *
     * @return array{0: list<array<string, mixed>>, 1: string} the items, and every page's raw text
     */
    private function everyUser(string $token): array
    {
        $items = [];
        $raw   = '';

        for ($page = 1; $page <= 100; $page++) {
            $result = $this->mcp($token)->callTool('list-users', ['limit' => 100, 'page' => $page]);
            self::assertFalse($result->isError, 'list-users page ' . $page . ' was refused.');

            $raw  .= $result->text . "\n";
            $data  = $result->data();
            $items = array_merge($items, $data['items']);

            if (empty($data['has_more'])) {
                return [$items, $raw];
            }
        }

        self::fail('list-users still has more after 100 pages of 100.');
    }

    /** @return list<string> */
    private function toolNames(string $token): array
    {
        $names  = [];
        $cursor = null;

        do {
            $params = $cursor === null ? [] : ['cursor' => $cursor];
            $body   = json_decode((string) $this->mcp($token)->post('tools/list', $params)->getBody(), true);
            $names  = array_merge($names, array_column($body['result']['tools'] ?? [], 'name'));
            $cursor = $body['result']['nextCursor'] ?? null;
        } while ($cursor !== null);

        return $names;
    }

    /**
     * Call a tool with the outbound-HTTP counter armed.
     *
     * @return array{0: ToolResult, 1: int, 2: string} the result, requests counted, hosts
     */
    private function watched(string $token, string $tool, array $arguments = [], bool $probe = false): array
    {
        $headers = [self::WATCH_HEADER => '1'];
        if ($probe) { $headers[self::PROBE_HEADER] = '1'; }

        $response = $this->mcp($token)->post('tools/call', ['name' => $tool, 'arguments' => $arguments], $headers);
        self::assertSame(200, $response->getStatusCode(), "tools/call {$tool} was not answered 200.");
        self::assertTrue(
            $response->hasHeader(self::COUNT_HEADER),
            'The outbound-HTTP counter did not report on this call, so the call was not watched.'
            . ' That is a broken harness, not a pass.'
        );

        $body = json_decode((string) $response->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('result', $body, "tools/call {$tool} returned no result.");

        return [
            new ToolResult((bool) ($body['result']['isError'] ?? false), (string) ($body['result']['content'][0]['text'] ?? '')),
            (int) $response->getHeaderLine(self::COUNT_HEADER),
            $response->getHeaderLine(self::HOSTS_HEADER),
        ];
    }

    /** The host the third-party fixture requests from inside $hook. */
    private static function hookHost(string $hook): string
    {
        return str_replace('_', '-', $hook) . '.hook.wpmcp-test.invalid';
    }

    /**
     * The third-party fixture: for this run's watched requests, a wp_remote_get() from
     * inside each hook in HOOKED every time it runs, to a host that names the hook.
     * The counter blocks every one of them. Nothing is stored.
     */
    private static function hooksSource(): string
    {
        $run    = Fixtures::runId();
        $header = 'HTTP_' . strtoupper(str_replace('-', '_', IntegrationTestCase::RUN_HEADER));
        $watch  = 'HTTP_' . strtoupper(str_replace('-', '_', self::WATCH_HEADER));
        $hooks  = var_export(self::HOOKED, true);

        return <<<PHP
/**
 * wp-mcp sprint-14 third-party hook fixture for run {$run}. Dropped and removed by
 * tests/integration/InventoryToolsTest.php. Gated on this run's request header; stores nothing.
 */
if (!isset(\$_SERVER['{$header}']) || \$_SERVER['{$header}'] !== '{$run}' || empty(\$_SERVER['{$watch}'])) {
    return;
}

\$inside = new ArrayObject();

foreach ({$hooks} as \$hook) {
    add_filter(\$hook, static function (\$value) use (\$hook, \$inside) {
        // Every time the hook runs, as a real plugin's callback would - a once-per-request
        // guard fired during bootstrap, before the counter arms, and hid the in-tool calls.
        // Only re-entry is guarded: the request itself may run the same hook.
        if (empty(\$inside['busy'])) {
            \$inside['busy'] = true;
            wp_remote_get('http://' . str_replace('_', '-', \$hook) . '.hook.wpmcp-test.invalid/');
            \$inside['busy'] = false;
        }
        return \$value;
    }, 10, 1);
}
PHP;
    }

    /** @return array<mixed> */
    private static function decode(string $json): array
    {
        $decoded = json_decode(trim($json), true);
        self::assertIsArray($decoded, 'wp-cli did not return JSON.');

        return $decoded;
    }

    /**
     * The counter: for this run's requests that ask to be watched, every outbound HTTP
     * request is BLOCKED (nothing leaves the machine) and counted from rest_dispatch_request,
     * after the permission callback, to the first rest_request_after_callbacks callback - the
     * tool's own run - and the count goes back in a response header. The stored update data
     * is made to look never checked for that request, so a tool that refreshed it would
     * have to go out: with a fresh transient wp_update_plugins() returns before any request
     * (measured on the bare site). Nothing is stored.
     */
    private static function httpSource(): string
    {
        $run    = Fixtures::runId();
        $header = 'HTTP_' . strtoupper(str_replace('-', '_', IntegrationTestCase::RUN_HEADER));
        $watch  = 'HTTP_' . strtoupper(str_replace('-', '_', self::WATCH_HEADER));
        $probe  = 'HTTP_' . strtoupper(str_replace('-', '_', self::PROBE_HEADER));
        $count  = self::COUNT_HEADER;
        $hosts  = self::HOSTS_HEADER;
        $host   = self::PROBE_HOST;

        return <<<PHP
/**
 * wp-mcp sprint-14 outbound-HTTP counter for run {$run}. Dropped and removed by
 * tests/integration/InventoryToolsTest.php. Gated on this run's request header; stores nothing.
 */
if (!isset(\$_SERVER['{$header}']) || \$_SERVER['{$header}'] !== '{$run}' || empty(\$_SERVER['{$watch}'])) {
    return;
}

\$state = (object) array('armed' => false, 'count' => 0, 'hosts' => array());

add_filter('pre_http_request', static function (\$pre, \$args, \$url) use (\$state) {
    if (\$state->armed) {
        \$state->count++;
        \$state->hosts[] = (string) wp_parse_url((string) \$url, PHP_URL_HOST);
    }
    return new WP_Error('wpmcp_test_blocked', 'Outbound HTTP is blocked by the sprint-14 test fixture.');
}, PHP_INT_MAX, 3);

foreach (array('update_plugins', 'update_themes', 'update_core') as \$name) {
    add_filter('site_transient_' . \$name, static function (\$value) {
        if (is_object(\$value)) {
            \$value = clone \$value;
            \$value->last_checked = 0;
        }
        return \$value;
    }, PHP_INT_MAX);
}

add_filter('rest_dispatch_request', static function (\$result) use (\$state) {
    \$state->armed = true;
    return \$result;
}, -100000);

add_filter('rest_dispatch_request', static function (\$result) {
    if (!empty(\$_SERVER['{$probe}'])) {
        wp_remote_head('http://{$host}/');
    }
    return \$result;
}, 100000);

add_filter('rest_request_after_callbacks', static function (\$response) use (\$state) {
    \$state->armed = false;
    return \$response;
}, -100000);

add_filter('rest_post_dispatch', static function (\$response) use (\$state) {
    if (\$response instanceof WP_HTTP_Response) {
        \$response->header('{$count}', (string) \$state->count);
        \$response->header('{$hosts}', implode(',', array_unique(\$state->hosts)));
    }
    return \$response;
}, PHP_INT_MAX);
PHP;
    }
}
