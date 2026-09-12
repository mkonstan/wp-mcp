<?php
/**
 * The tool registry fails closed: no explicit boolean `write`, no registration.
 *
 * Item 6. `wpmcp_tools` is a public filter, which is the right thing for a plugin to
 * offer and also the one place a third party can hand this endpoint a tool nobody here
 * reviewed. Everything downstream asks exactly one question about each entry -
 * `empty($t['write'])` - to decide whether a READ-scope token may call it. An entry with
 * no `write` key answers that question "no, this is a read tool". So a filter that
 * forgets the key, or misspells it, or writes the string "true" because PHP let it,
 * silently publishes a write tool to every read-scope token on the site.
 *
 * Absence of a declaration is not a declaration of safety. The registry therefore drops
 * the entry rather than guessing, and fires registry_reject so the tool's author learns
 * it from a log line instead of from an incident.
 *
 * The mu-plugin adds three tools: one with no `write` key, one whose `write` is the
 * string "true", and one correctly declared control. The control is what stops this
 * class from passing because the filter never ran at all.
 *
 * @group sprint-2
 */

declare(strict_types=1);

namespace WpMcp\Tests\Integration;

use WpMcp\Tests\Support\Fixtures;
use WpMcp\Tests\Support\FixtureIntegrationTestCase;
use WpMcp\Tests\Support\MuPlugin;
use WpMcp\Tests\Support\TestRecorder;

final class ToolRegistryTest extends FixtureIntegrationTestCase
{
    private static function label(): string { return Fixtures::name('registry'); }
    private static function login(): string { return Fixtures::name('registry-author'); }

    /** The mu-plugin that adds tools through the public filter. */
    private const BAD_TOOLS = 'bad-tools';

    private static int $userId = 0;
    private static string $token = '';

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
        MuPlugin::drop(self::BAD_TOOLS, self::badToolsSource());

        self::$userId = Fixtures::createUser(self::login(), 'author');
        self::$token  = Fixtures::mintToken('read', self::label(), self::$userId);
    }

    public static function tearDownAfterClass(): void
    {
        self::destroy();

        parent::tearDownAfterClass();
    }

    private static function destroy(): void
    {
        MuPlugin::remove(self::BAD_TOOLS);
        TestRecorder::uninstall();
        Fixtures::deleteUser(self::$userId);
        Fixtures::deleteTokensLabelled(self::label());
        Fixtures::purge();
    }

    /**
     * The two undeclared tools are absent from tools/list, the control is present, and
     * a registry_reject event names each rejection and its reason.
     *
     * @group sprint-2
     */
    public function testAToolWithoutAnExplicitWriteFlagIsNotRegistered(): void
    {
        TestRecorder::reset();

        $response = $this->mcp(self::$token)->post('tools/list');
        $body     = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode(), $body);

        self::assertStringContainsString(
            self::toolName('control'),
            $body,
            'The control tool is not in the listing, so the wpmcp_tools filter never ran'
            . ' and this test proves nothing.'
        );
        self::assertStringNotContainsString(
            self::toolName('no-write-key'),
            $body,
            'A filter-added tool with no `write` key was registered. Every consumer reads'
            . ' empty($t["write"]), so that tool is published to every read-scope token.'
        );
        self::assertStringNotContainsString(
            self::toolName('write-is-a-string'),
            $body,
            'A filter-added tool whose `write` is the string "true" was registered.'
            . ' Truthy-but-not-boolean is the signature of an author who did not think'
            . ' about it, which is exactly when failing closed matters.'
        );

        $rejected = [];

        foreach (TestRecorder::detailsOf(TestRecorder::AUTH . 'registry_reject') as $context) {
            $rejected[(string) ($context['tool'] ?? '')] = (string) ($context['reason'] ?? '');
        }

        self::assertSame(
            'no_write_key',
            $rejected[self::toolName('no-write-key')] ?? null,
            'No registry_reject event named the tool with the missing key. Events: '
            . json_encode($rejected)
        );
        self::assertSame(
            'write_not_boolean',
            $rejected[self::toolName('write-is-a-string')] ?? null,
            'No registry_reject event named the tool whose `write` is not a boolean.'
        );
        self::assertArrayNotHasKey(
            self::toolName('control'),
            $rejected,
            'The correctly declared control tool was rejected too.'
        );
    }

    /**
     * A rejected tool cannot be called either, not even by name. The listing is a
     * courtesy; the registry is the gate.
     *
     * @group sprint-2
     */
    public function testARejectedToolCannotBeCalled(): void
    {
        $response = $this->mcp(self::$token)->post('tools/call', [
            'name'      => self::toolName('no-write-key'),
            'arguments' => [],
        ]);

        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode(), $body);
        self::assertStringContainsString(
            'Unknown tool',
            $body,
            'A tool that was refused at registration was still callable by name.'
        );
        self::assertStringNotContainsString(
            'I-SHOULD-NOT-HAVE-RUN',
            $body,
            'The rejected tool\'s run callback executed.'
        );
    }

    /** The name of one of the mu-plugin's filter-added tools. */
    private static function toolName(string $what): string
    {
        return Fixtures::name('tool-' . $what);
    }

    /**
     * Unconditional, because the filter runs on every request to the endpoint and a
     * rejected tool is harmless to anyone else - including a concurrent runner, whose
     * registry rejects it just the same and whose recorder ignores the event.
     */
    private static function badToolsSource(): string
    {
        $noWrite = self::toolName('no-write-key');
        $string  = self::toolName('write-is-a-string');
        $control = self::toolName('control');

        return <<<PHP
add_filter('wpmcp_tools', static function (\$tools) {
    \$schema = array('type' => 'object', 'properties' => new stdClass());
    \$run    = static function (\$args) { return array('ran' => 'I-SHOULD-NOT-HAVE-RUN'); };

    // No `write` key at all.
    \$tools['{$noWrite}'] = array(
        'description' => 'wp-mcp test fixture: no write key.',
        'inputSchema' => \$schema,
        'run'         => \$run,
    );

    // Truthy, but not a boolean.
    \$tools['{$string}'] = array(
        'write'       => 'true',
        'description' => 'wp-mcp test fixture: write is a string.',
        'inputSchema' => \$schema,
        'run'         => \$run,
    );

    // Declared properly; must survive.
    \$tools['{$control}'] = array(
        'write'       => false,
        'description' => 'wp-mcp test fixture: correctly declared.',
        'inputSchema' => \$schema,
        'run'         => static function (\$args) { return array('ok' => true); },
    );

    return \$tools;
});
PHP;
    }
}
