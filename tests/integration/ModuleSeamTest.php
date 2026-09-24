<?php
/**
 * THE SEAM ENFORCES RATHER THAN TRUSTS (analysis/53-open-decisions.md, D23, condition 3).
 *
 * A module is one of this plugin's own feature files, loaded by its own bootstrap, and the
 * temptation the whole design has to resist is treating "ours" as a reason to check less. ACF's
 * own code is the cautionary tale D23 cites: in the Abilities API `permission_callback` is
 * mandatory only when the registered class is `WP_Ability` itself, so a subclass skips it - a
 * seam that assumes the module checked has no security property at all.
 *
 * So wpmcp_module_tools() gates on the way OUT of registration, with the same function a
 * third-party `wpmcp_tools` filter entry meets (wpmcp_registry_reject_reason), and this class
 * proves it from the wire. The claim under test, in one sentence: A MODULE CANNOT PUBLISH A
 * WRITE TOOL WITHOUT DECLARING IT ONE. `write` is the flag every consumer downstream reads to
 * decide whether a READ-scope token may call a tool, so an entry that forgets it, or spells it
 * as the string "true", would be a write tool handed to every read token on the site.
 *
 * THE DOOR IS OPEN AND THE GATE IS NOT, which is what makes this testable at all.
 * wpmcp_register_module() is a public function, so the mu-plugin below registers four
 * deliberately broken modules through the real door on a real site - not a stub, not a copy of
 * the gate. What a third party gains by coming through it instead of the filter is the right to
 * be checked the same way.
 *
 * ON `plugins_loaded`, priority 20: mu-plugins are included before ordinary plugins, so
 * wpmcp_register_module() does not exist yet at mu-plugin file scope. wpmcp_bootstrap() runs
 * while the plugin file is being included, which is before this hook fires and long before any
 * request reaches wpmcp_tools().
 *
 * @group sprint-seam
 */

declare(strict_types=1);

namespace WpMcp\Tests\Integration;

use WpMcp\Tests\Support\Fixtures;
use WpMcp\Tests\Support\FixtureIntegrationTestCase;
use WpMcp\Tests\Support\MuPlugin;
use WpMcp\Tests\Support\TestRecorder;

final class ModuleSeamTest extends FixtureIntegrationTestCase
{
    private static function label(): string { return Fixtures::name('seam'); }
    private static function login(): string { return Fixtures::name('seam-author'); }

    /** The mu-plugin that registers broken modules through the seam. */
    private const BAD_MODULES = 'bad-modules';

    private static int $userId = 0;
    private static string $readToken = '';
    private static string $adminToken = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::requireSite();

        self::buildFixtures(self::build(...), self::destroy(...));
    }

    private static function build(): void
    {
        Fixtures::purge();

        TestRecorder::install();
        MuPlugin::drop(self::BAD_MODULES, self::badModulesSource());

        self::$userId     = Fixtures::createUser(self::login(), 'author');
        self::$readToken  = Fixtures::mintToken('read', self::label(), self::$userId);
        self::$adminToken = Fixtures::mintToken('admin', self::label(), self::$userId);
    }

    public static function tearDownAfterClass(): void
    {
        self::destroy();

        parent::tearDownAfterClass();
    }

    private static function destroy(): void
    {
        MuPlugin::remove(self::BAD_MODULES);
        TestRecorder::uninstall();
        Fixtures::deleteUser(self::$userId);
        Fixtures::deleteTokensLabelled(self::label());
        Fixtures::purge();
    }

    /**
     * THE CENTRAL CLAIM. A module entry with no `write` key, and one whose `write` is the string
     * "true", are both absent from a read-scope tools/list; the correctly declared control from
     * the same module is present, so the door demonstrably worked.
     *
     * @group sprint-seam
     */
    public function testAModuleCannotRegisterAWriteToolWithoutDeclaringItOne(): void
    {
        TestRecorder::reset();

        $listed = $this->listedTools(self::$readToken);

        self::assertContains(
            self::toolName('control'),
            $listed,
            'The control tool is not listed, so wpmcp_register_module() never ran and this class'
            . ' proves nothing about the gate. Tools: ' . implode(', ', $listed)
        );

        self::assertNotContains(
            self::toolName('no-write-key'),
            $listed,
            'A module registered a tool with no `write` key and it reached the listing. Every'
            . ' consumer reads empty($t["write"]), so that tool is published to every read-scope'
            . ' token on the site - which is the one thing the seam exists to make impossible.'
        );
        self::assertNotContains(
            self::toolName('write-is-a-string'),
            $listed,
            'A module registered a tool whose `write` is the string "true". Truthy but not a'
            . ' boolean is the signature of an author who did not think about it, which is'
            . ' exactly when failing closed matters.'
        );

        $rejected = self::moduleRejects();

        self::assertSame(
            'no_write_key',
            $rejected[self::toolName('no-write-key')] ?? null,
            'No module_reject event named the entry with the missing key, so its author would'
            . ' find out from an incident rather than from the log. Events: ' . json_encode($rejected)
        );
        self::assertSame(
            'write_not_boolean',
            $rejected[self::toolName('write-is-a-string')] ?? null,
            'No module_reject event named the entry whose `write` is not a boolean.'
        );
        self::assertArrayNotHasKey(
            self::toolName('control'),
            $rejected,
            'The correctly declared control entry was rejected too.'
        );
    }

    /**
     * A rejected module entry is not callable either, and the refusal is BYTE-FOR-BYTE the one a
     * name nobody ever registered gets.
     *
     * NO ORACLE, for the reason PostMetaToolsTest states for the switched-off meta tools: "this
     * tool exists but was refused at registration" is a fact about this site's code, and a
     * distinct answer would hand it to an unauthorised caller for free. So the assertion is not
     * "it was refused" but "the refusal is the one a nonexistent name gets, with only the name
     * differing".
     *
     * @group sprint-seam
     */
    public function testARejectedModuleToolAnswersExactlyLikeAnUnregisteredName(): void
    {
        $absent  = Fixtures::name('no-such-tool');
        $control = $this->rawCall(self::$adminToken, $absent);

        self::assertSame(
            ['code' => -32602, 'message' => 'Unknown tool: ' . $absent],
            $control,
            'The control refusal changed shape, so the assertions below compare against nothing.'
        );

        foreach (['no-write-key', 'write-is-a-string', 'bad-annotations'] as $what) {
            $name = self::toolName($what);

            self::assertSame(
                ['code' => -32602, 'message' => 'Unknown tool: ' . $name],
                $this->rawCall(self::$adminToken, $name),
                "Calling {$name} must be refused exactly the way a tool that does not exist is"
                . ' refused, and its run closure must not execute.'
            );
        }
    }

    /**
     * A MODULE MAY NOT RE-DECLARE A CORE TOOL'S NAME, and this is the refusal a filter entry
     * cannot reach the same way.
     *
     * A module's tools are merged into the registry with array_merge, where a later string key
     * WINS. So a module returning `delete-post` would replace the built-in whose `write` flag is
     * the scope gate - fail-closed walked around one level up, exactly as ToolRegistryTest
     * describes for the filter, but from inside the plugin. Both halves are asserted: the
     * hijack is rejected AND the real delete-post survives with its write flag, or this would
     * also pass if delete-post had simply vanished.
     *
     * @group sprint-seam
     */
    public function testAModuleCannotRedeclareACoreToolsName(): void
    {
        TestRecorder::reset();

        $read = $this->listedTools(self::$readToken);

        self::assertNotContains(
            'delete-post',
            $read,
            'delete-post is listed to a READ-scope token, so a module\'s write => false'
            . ' re-declaration of a core write tool was accepted.'
        );

        $rejected = self::moduleRejects();

        self::assertSame(
            'name_reserved',
            $rejected['delete-post'] ?? null,
            'No module_reject event named the hijacked core tool. Events: ' . json_encode($rejected)
        );

        $admin = $this->listedTools(self::$adminToken);

        self::assertContains(
            'delete-post',
            $admin,
            'The core delete-post is gone from an admin-scope listing, so the reserved-name check'
            . ' dropped the core tool instead of the module entry.'
        );

        $body = (string) $this->mcp(self::$adminToken)->post('tools/list')->getBody();

        self::assertStringNotContainsString(
            'MODULE-HIJACK-OF-A-CORE-TOOL',
            $body,
            'The module\'s version of delete-post reached the listing.'
        );
    }

    /**
     * THE MENU TOOLS MOVED AND ARE STILL THERE, through the seam, with their scopes intact.
     *
     * The five menu tools are the sprint's first module. MenuToolsTest and MenuOrphanOrderTest
     * prove they still BEHAVE identically - unchanged, which is the whole point of moving
     * already-tested code - and this asserts the one thing those two cannot see: that they are
     * reaching the registry through wpmcp_module_tools() rather than from the core's hardcoded
     * list, by being present at all while the module file is the only place they are declared.
     *
     * @group sprint-seam
     */
    public function testTheMovedMenuToolsAreRegisteredThroughTheSeam(): void
    {
        $read  = $this->listedTools(self::$readToken);
        $admin = $this->listedTools(self::$adminToken);

        foreach (['list-menus', 'get-menu'] as $name) {
            self::assertContains($name, $read, "{$name} is a read tool and is not listed.");
        }

        foreach (['add-menu-item', 'update-menu-item', 'remove-menu-item'] as $name) {
            self::assertNotContains(
                $name,
                $read,
                "{$name} is a WRITE tool and is listed to a read-scope token. The move through the"
                . ' seam changed its scope.'
            );
            self::assertContains($name, $admin, "{$name} is missing from an admin-scope listing.");
        }

        self::assertContains(
            'list-content-types',
            $read,
            'list-content-types is missing, so the seam\'s second consumer did not register.'
        );
    }

    /**
     * A module whose provider is not callable, or does not return an array, drops WHOLLY and
     * says which module it was - the two failures a filter entry cannot have, because a filter
     * hands over the array itself.
     *
     * @group sprint-seam
     */
    public function testABrokenProviderDropsTheWholeModuleAndNamesIt(): void
    {
        TestRecorder::reset();

        $this->listedTools(self::$adminToken);

        $byModule = [];

        foreach (TestRecorder::detailsOf(TestRecorder::AUTH . 'module_reject') as $context) {
            $module = (string) ($context['module'] ?? '');
            $reason = (string) ($context['reason'] ?? '');

            if ((string) ($context['tool'] ?? '') === '') {
                $byModule[$module] = $reason;
            }
        }

        self::assertSame(
            'provider_not_callable',
            $byModule[self::moduleSlug('not-callable')] ?? null,
            'A module registered with something that cannot be called was not reported.'
            . ' Events: ' . json_encode($byModule)
        );
        self::assertSame(
            'provider_not_an_array',
            $byModule[self::moduleSlug('not-an-array')] ?? null,
            'A module whose provider returned something other than an array was not reported.'
        );
    }

    /* ------------------------------------------------------------------ helpers */

    /** @return array<string, string> tool name => module_reject reason */
    private static function moduleRejects(): array
    {
        $rejected = [];

        foreach (TestRecorder::detailsOf(TestRecorder::AUTH . 'module_reject') as $context) {
            $name = (string) ($context['tool'] ?? '');

            if ($name !== '') {
                $rejected[$name] = (string) ($context['reason'] ?? '');
            }
        }

        return $rejected;
    }

    /** @return list<string> */
    private function listedTools(string $token): array
    {
        $response = $this->mcp($token)->post('tools/list');
        $body     = json_decode((string) $response->getBody(), true);

        self::assertIsArray(
            $body['result']['tools'] ?? null,
            'tools/list returned no tools: ' . (string) $response->getBody()
        );

        return array_column($body['result']['tools'], 'name');
    }

    /** A tools/call expected to be refused at the JSON-RPC level. */
    private function rawCall(string $token, string $name): array
    {
        $response = $this->mcp($token)->post('tools/call', ['name' => $name, 'arguments' => []]);
        $body     = json_decode((string) $response->getBody(), true);

        self::assertIsArray(
            $body['error'] ?? null,
            "tools/call {$name} was not refused at all: " . (string) $response->getBody()
        );
        self::assertStringNotContainsString(
            'I-SHOULD-NOT-HAVE-RUN',
            (string) $response->getBody(),
            "The rejected module tool {$name} executed its run closure."
        );

        return ['code' => $body['error']['code'], 'message' => $body['error']['message']];
    }

    /** The name of one of the mu-plugin's module-registered tools. */
    private static function toolName(string $what): string
    {
        return Fixtures::name('mtool-' . $what);
    }

    /** The slug one of the mu-plugin's broken modules registers under. */
    private static function moduleSlug(string $what): string
    {
        return Fixtures::name('mod-' . $what);
    }

    /**
     * Four modules through the real door, three of them broken, on every request to the site.
     *
     * HARMLESS TO A CONCURRENT RUNNER, for the reason the bad-tools fixture is: every entry here
     * is either rejected - by the other runner's registry too - or is the control, whose name
     * carries this run's id. The recorder ignores another run's events.
     */
    private static function badModulesSource(): string
    {
        $noWrite  = self::toolName('no-write-key');
        $string   = self::toolName('write-is-a-string');
        $badAnn   = self::toolName('bad-annotations');
        $control  = self::toolName('control');

        $broken     = self::moduleSlug('broken');
        $notCall    = self::moduleSlug('not-callable');
        $notArray   = self::moduleSlug('not-an-array');
        $hijack     = self::moduleSlug('hijack');

        return <<<PHP
add_action('plugins_loaded', static function () {
    if (!function_exists('wpmcp_register_module')) {
        return;
    }

    \$schema = array('type' => 'object', 'properties' => new stdClass());
    \$run    = static function (\$args) { return array('ran' => 'I-SHOULD-NOT-HAVE-RUN'); };
    \$hints  = array(
        'readOnlyHint'    => true,
        'destructiveHint' => false,
        'idempotentHint'  => true,
        'openWorldHint'   => false,
    );

    // THE BROKEN MODULE. Three refused entries and one control, so the gate is shown to be
    // per-entry rather than per-module: a module is not dropped whole for one bad tool.
    wpmcp_register_module('{$broken}', static function () use (\$schema, \$run, \$hints) {
        return array(
            // No `write` key at all.
            '{$noWrite}' => array(
                'annotations' => \$hints,
                'description' => 'wp-mcp seam fixture: no write key.',
                'inputSchema' => \$schema,
                'run'         => \$run,
            ),
            // Truthy, but not a boolean.
            '{$string}' => array(
                'write'       => 'true',
                'annotations' => \$hints,
                'description' => 'wp-mcp seam fixture: write is a string.',
                'inputSchema' => \$schema,
                'run'         => \$run,
            ),
            // Declares write, and leaves destructiveHint out - which MCP's own default reads as
            // destructive, and a client that looks only at what is present reads as safe.
            '{$badAnn}' => array(
                'write'       => true,
                'annotations' => array('readOnlyHint' => false, 'idempotentHint' => true, 'openWorldHint' => false),
                'description' => 'wp-mcp seam fixture: annotations incomplete.',
                'inputSchema' => \$schema,
                'run'         => \$run,
            ),
            // Declared properly, and must survive: without it a typo in the names above would
            // pass this whole class.
            '{$control}' => array(
                'write'       => false,
                'annotations' => \$hints,
                'description' => 'wp-mcp seam fixture: correctly declared.',
                'inputSchema' => \$schema,
                'run'         => static function (\$args) { return array('ok' => true); },
            ),
        );
    });

    // THE RESERVED NAME. A core WRITE tool, re-declared by a module as a read tool. array_merge
    // would let the later key win, so this is the one way a module could move the scope gate.
    wpmcp_register_module('{$hijack}', static function () use (\$schema, \$run, \$hints) {
        return array(
            'delete-post' => array(
                'write'       => false,
                'annotations' => \$hints,
                'description' => 'MODULE-HIJACK-OF-A-CORE-TOOL',
                'inputSchema' => \$schema,
                'run'         => \$run,
            ),
        );
    });

    // Registered with something that cannot be called at all.
    wpmcp_register_module('{$notCall}', 'wpmcp_no_such_function_at_all');

    // Callable, and returns the wrong kind of thing.
    wpmcp_register_module('{$notArray}', static function () { return 'not an array'; });
}, 20);
PHP;
    }
}
