<?php
/**
 * The handshake: what this server says it is, and what it does with a version it does
 * not know.
 *
 * THE BUG THIS CLOSES IS A SILENT ONE. `initialize` used to echo `params.protocolVersion`
 * back unexamined, so a client asking for `2099-01-01` was told, in a success response,
 * that this server speaks `2099-01-01`. Nothing failed at that point - it failed later,
 * somewhere else, in whatever the client then assumed it could do. Negotiation that always
 * agrees is not negotiation.
 *
 * THE OTHER HALF IS THE CAPABILITY SET, and the failure mode there is `[]` instead of `{}`.
 * `wp_json_encode(array())` is `[]`, and `"tools": []` is not an object: a client that
 * validates the initialize result rejects the whole handshake, and the server looks broken
 * rather than wrong in one character. So the assertion is on the RAW BYTES, not on the
 * decoded value - json_decode makes `[]` and `{}` the same PHP array, which is exactly the
 * trap that made this worth a test.
 *
 * AND THE SESSION HEADER, which is a claim about the architecture: this server is
 * stateless, so it must never issue `Mcp-Session-Id`. If one ever appears, a client will
 * start sending it back and expecting continuity that does not exist.
 *
 * @group sprint-4
 */

declare(strict_types=1);

namespace WpMcp\Tests\Integration;

use WpMcp\Tests\Support\Fixtures;
use WpMcp\Tests\Support\FixtureIntegrationTestCase;

final class HandshakeTest extends FixtureIntegrationTestCase
{
    /** The revision the build plan settled on (decisions log, 2026-09-12). */
    private const LATEST = '2025-11-25';

    /** Every revision this server speaks, for the "name them all" assertions. */
    private const SUPPORTED = ['2025-11-25', '2025-06-18', '2025-03-26'];

    private static function label(): string { return Fixtures::name('handshake'); }
    private static function login(): string { return Fixtures::name('handshake-author'); }

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
        Fixtures::deleteUser(self::$userId);
        Fixtures::deleteTokensLabelled(self::label());
        Fixtures::purge();
    }

    /**
     * A revision from the future is answered with SUCCESS carrying ours.
     *
     * Not an error. An error would make every client newer than this plugin a hard
     * failure against every installed copy, which is the opposite of what a version
     * negotiation is for.
     *
     * @group sprint-4
     */
    public function testAnUnknownProtocolVersionIsAnsweredWithTheNewestWeSpeak(): void
    {
        $response = $this->mcp(self::$token)->post('initialize', [
            'protocolVersion' => '2099-01-01',
            'capabilities'    => [],
            'clientInfo'      => ['name' => 'wp-mcp-tests', 'version' => '1.0'],
        ]);
        $raw  = (string) $response->getBody();
        $body = json_decode($raw, true);

        self::assertSame(
            200,
            $response->getStatusCode(),
            'An unknown protocolVersion must not be an HTTP failure. Body: ' . $raw
        );
        self::assertArrayNotHasKey(
            'error',
            (array) $body,
            'initialize answered an unknown protocolVersion with a JSON-RPC error. It must'
            . ' answer success, stating the version it does speak. Body: ' . $raw
        );
        self::assertSame(
            self::LATEST,
            $body['result']['protocolVersion'] ?? null,
            'The server echoed the version the client asked for instead of negotiating.'
            . ' Body: ' . $raw
        );
    }

    /**
     * A revision we DO speak is echoed, unchanged - the other direction, and the one an
     * "always answer latest" shortcut would get wrong.
     *
     * @group sprint-4
     */
    public function testASupportedProtocolVersionIsEchoedBack(): void
    {
        foreach (self::SUPPORTED as $asked) {
            $raw  = (string) $this->mcp(self::$token)
                ->post('initialize', ['protocolVersion' => $asked])->getBody();
            $body = json_decode($raw, true);

            self::assertSame(
                $asked,
                $body['result']['protocolVersion'] ?? null,
                "initialize did not echo the supported revision {$asked}. Body: " . $raw
            );
        }
    }

    /**
     * `capabilities` is EXACTLY `{"tools":{}}` - asserted on the raw bytes.
     *
     * Two things are being nailed down here. That `tools` serializes as an OBJECT: json_decode turns
     * `[]` and `{}` into the same PHP array, so a decoded assertion cannot see the bug at
     * all. And that nothing else is claimed: no `listChanged` (this server cannot notify),
     * no `prompts`, `resources` or `logging` (it serves none). A capability the server does
     * not honour is a promise a client will wait on.
     *
     * @group sprint-4
     */
    public function testCapabilitiesAreToolsOnlyAndSerializeAsAnObject(): void
    {
        $raw = (string) $this->mcp(self::$token)
            ->post('initialize', ['protocolVersion' => self::LATEST])->getBody();

        // Whitespace-insensitive: WordPress serializes compactly, but a host with
        // JSON_PRETTY_PRINT forced on would make a byte-exact needle fail for a reason
        // that has nothing to do with the claim.
        $compact = (string) preg_replace('/\s+/', '', $raw);

        self::assertStringContainsString(
            '"capabilities":{"tools":{}}',
            $compact,
            'capabilities is not exactly {"tools":{}}. If it reads "tools":[] the empty'
            . ' array was serialized as a JSON array and a strict client rejects the whole'
            . ' initialize result - endpoint.php must pass new stdClass(). Body: ' . $raw
        );
        self::assertStringNotContainsString(
            '"tools":[]',
            $compact,
            'The tools capability serialized as [] instead of {}. Body: ' . $raw
        );

        $capabilities = json_decode($raw, true)['result']['capabilities'] ?? null;

        self::assertSame(
            ['tools'],
            array_keys((array) $capabilities),
            'The capability set must be derived from what this server actually serves:'
            . ' tools, and nothing else. Body: ' . $raw
        );
    }

    /**
     * serverInfo names the plugin and reports the version from its own header.
     *
     * THE HEADER IS READ FROM THIS WORKING TREE, which makes this two assertions in one:
     * the wire value matches the source, and the site under test is really serving the
     * code in this checkout. A stale symlink or a forgotten `Version:` bump both show up
     * here rather than as a confusing failure three tests later.
     *
     * @group sprint-4
     */
    public function testServerInfoCarriesThePluginNameAndHeaderVersion(): void
    {
        $raw  = (string) $this->mcp(self::$token)
            ->post('initialize', ['protocolVersion' => self::LATEST])->getBody();
        $body = json_decode($raw, true);

        self::assertSame(
            'wp-mcp',
            $body['result']['serverInfo']['name'] ?? null,
            "serverInfo.name is the server's identifier, not a display string. Body: " . $raw
        );
        self::assertSame(
            self::pluginHeaderVersion(),
            $body['result']['serverInfo']['version'] ?? null,
            'serverInfo.version does not match the `Version:` line of wp-mcp.php in this'
            . ' checkout. Either WPMCP_VER drifted from the header (tests/unit/'
            . 'VersionConsistencyTest.php would also be red) or the site under test is'
            . ' serving a different copy of the plugin than this one. Body: ' . $raw
        );
    }

    /**
     * `instructions` is present and says the three things that change what an agent does.
     *
     * Not a prose assertion - three substrings, each a fact: the subject is WordPress,
     * reach is one user's capabilities, writes need a different token. A rewrite that keeps
     * those is fine; one that drops one of them is the regression.
     *
     * @group sprint-4
     */
    public function testInstructionsStateIdentityAndTheWriteScopeRule(): void
    {
        $raw          = (string) $this->mcp(self::$token)
            ->post('initialize', ['protocolVersion' => self::LATEST])->getBody();
        $instructions = json_decode($raw, true)['result']['instructions'] ?? null;

        self::assertIsString($instructions, 'initialize carried no instructions: ' . $raw);
        self::assertNotSame('', trim($instructions), 'instructions is empty.');

        foreach (['WordPress', 'capabilities', 'admin-scope'] as $fact) {
            self::assertStringContainsString(
                $fact,
                $instructions,
                "instructions no longer mention '{$fact}'. An agent that does not know its"
                . " reach is one WordPress user's treats a capability refusal as a bug and"
                . ' retries it. Instructions: ' . $instructions
            );
        }
    }

    /**
     * An `MCP-Protocol-Version` naming a revision we do not speak: HTTP 400, -32600, and
     * the message lists what we do speak so the client's next try can be right.
     *
     * THE STATUS MATTERS AS MUCH AS THE CODE. Every other JSON-RPC error this endpoint
     * produces is HTTP 200, because a JSON-RPC error is a successful exchange carrying an
     * application failure. This one is not: it is a fact about the HTTP request, and a
     * client speaking a revision we do not know may not be able to parse our body at all.
     *
     * @group sprint-4
     */
    public function testAnUnsupportedProtocolVersionHeaderIsRefused(): void
    {
        $response = $this->mcp(self::$token)
            ->post('tools/list', [], ['MCP-Protocol-Version' => '1999-01-01']);
        $raw  = (string) $response->getBody();
        $body = json_decode($raw, true);

        self::assertSame(
            400,
            $response->getStatusCode(),
            'A request declaring a protocol revision this server does not speak must be'
            . ' refused 400. A 200 means the header is not read at all and the client'
            . ' carries on believing its revision was accepted. Body: ' . $raw
        );
        self::assertSame(-32600, $body['error']['code'] ?? null, $raw);

        $message = (string) ($body['error']['message'] ?? '');

        self::assertStringContainsString('1999-01-01', $message, $raw);

        foreach (self::SUPPORTED as $supported) {
            self::assertStringContainsString(
                $supported,
                $message,
                'The refusal must name every version this server speaks, or the client is'
                . ' left guessing. Message: ' . $message
            );
        }
    }

    /**
     * No header at all is ACCEPTED - the spec says treat it as `2025-03-26`, the revision
     * that predates the header, which is what a client old enough to omit it is speaking.
     *
     * @group sprint-4
     */
    public function testAnAbsentProtocolVersionHeaderIsAccepted(): void
    {
        $response = $this->mcp(self::$token)->post('tools/list');
        $raw      = (string) $response->getBody();

        self::assertSame(
            200,
            $response->getStatusCode(),
            'A request with no MCP-Protocol-Version header was refused. Absent is not'
            . ' unsupported: it means 2025-03-26. Body: ' . $raw
        );
        self::assertIsArray(
            json_decode($raw, true)['result']['tools'] ?? null,
            'tools/list without the header did not serve a tool list: ' . $raw
        );
    }

    /**
     * And the header naming a revision we do speak is accepted, on every one of them -
     * the control for the refusal above.
     *
     * @group sprint-4
     */
    public function testASupportedProtocolVersionHeaderIsAccepted(): void
    {
        foreach (self::SUPPORTED as $supported) {
            $response = $this->mcp(self::$token)
                ->post('tools/list', [], ['MCP-Protocol-Version' => $supported]);

            self::assertSame(
                200,
                $response->getStatusCode(),
                "A supported revision ({$supported}) in the header was refused. Body: "
                . (string) $response->getBody()
            );
        }
    }

    /**
     * `initialize` is exempt from the header gate, and has to be: the header cannot carry
     * a negotiated version before negotiation. A client that sends a stale one anyway -
     * Claude Desktop reconnecting, say - must still be able to handshake.
     *
     * @group sprint-4
     */
    public function testInitializeIsNotRefusedForAnUnsupportedHeader(): void
    {
        $response = $this->mcp(self::$token)->post(
            'initialize',
            ['protocolVersion' => self::LATEST],
            ['MCP-Protocol-Version' => '1999-01-01']
        );
        $raw = (string) $response->getBody();

        self::assertSame(
            200,
            $response->getStatusCode(),
            'initialize was refused over the MCP-Protocol-Version header. That header is'
            . ' the OUTCOME of initialize; gating the handshake on it deadlocks any client'
            . ' whose previous negotiation is stale. Body: ' . $raw
        );
        self::assertSame(
            self::LATEST,
            json_decode($raw, true)['result']['protocolVersion'] ?? null,
            'The params decide the handshake, not the header: ' . $raw
        );
    }

    /**
     * This server is stateless: a client's `Mcp-Session-Id` is ignored, and none is ever
     * issued back.
     *
     * @group sprint-4
     */
    public function testASessionIdIsIgnoredAndNeverIssued(): void
    {
        $response = $this->mcp(self::$token)
            ->post('tools/list', [], ['Mcp-Session-Id' => 'anything-at-all']);
        $raw = (string) $response->getBody();

        self::assertSame(
            200,
            $response->getStatusCode(),
            'A client-sent Mcp-Session-Id must be ignored, not refused. Body: ' . $raw
        );
        self::assertFalse(
            $response->hasHeader('Mcp-Session-Id'),
            'The response carried an Mcp-Session-Id. This server keeps no session state,'
            . ' so issuing one makes a client send it back and expect continuity that does'
            . ' not exist. Header: ' . $response->getHeaderLine('Mcp-Session-Id')
        );
    }

    /**
     * `notifications/initialized` - what a client sends right after the handshake - is the
     * no-id path: 202, empty body. Confirmed here because the handshake is the one place a
     * regression in it would strand every client before its first tool call.
     *
     * @group sprint-4
     */
    public function testTheInitializedNotificationIsAcknowledgedWithAnEmpty202(): void
    {
        $response = $this->mcp(self::$token)
            ->postRaw('{"jsonrpc":"2.0","method":"notifications/initialized"}');

        self::assertSame(202, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('', (string) $response->getBody());
    }

    /**
     * `ping` still answers an EMPTY OBJECT, raw-bytes checked for the same `[]`/`{}`
     * reason as the capability set: the spec says the result is an empty object, and
     * `"result":[]` is not one.
     *
     * @group sprint-4
     */
    public function testPingAnswersAnEmptyObject(): void
    {
        $response = $this->mcp(self::$token)->post('ping');
        $raw      = (string) $response->getBody();
        $compact  = (string) preg_replace('/\s+/', '', $raw);

        self::assertSame(200, $response->getStatusCode(), $raw);
        self::assertStringContainsString(
            '"result":{}',
            $compact,
            'ping must answer an empty OBJECT. "result":[] is an empty array and is not'
            . ' what the spec asks for. Body: ' . $raw
        );
    }

    /** The `Version:` line of wp-mcp.php in this checkout. */
    private static function pluginHeaderVersion(): string
    {
        $path   = WPMCP_PLUGIN_DIR . '/wp-mcp.php';
        $source = file_get_contents($path);

        self::assertIsString($source, "Could not read {$path}");

        // WordPress itself scans only the first 8 KiB of a plugin file for headers.
        self::assertSame(
            1,
            preg_match('/^[ \t\/*#@]*Version:\s*(.+)$/mi', substr($source, 0, 8192), $m),
            "No 'Version:' header found in {$path}"
        );

        return trim($m[1]);
    }
}
