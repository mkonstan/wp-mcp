<?php
/**
 * The tool contract, over real HTTP: arguments are checked before the tool runs, the
 * listing is valid JSON Schema, every tool declares what it does, and `cursor` is read.
 *
 * WHAT "BEFORE THE TOOL RUNS" HAS TO MEAN, and why two of these tests exist rather than
 * one. A refusal that arrives after the side effect is not a refusal. So:
 *
 *   - a fixture tool records into a transient the moment its run callable is entered.
 *     Called with a bad argument, the transient must stay absent. That is the only
 *     assertion in this file that can see the difference between "validated first" and
 *     "validated, then ran anyway, then reported".
 *   - `create-post` with a key nobody declared must leave no post behind. Same claim,
 *     against a real built-in and a real database.
 *
 * AND THE REGRESSION GATE IS NOT IN THIS FILE. "All 20 tools still pass their existing
 * Sprint 1-4 tests under the validator" is asserted by those tests continuing to be
 * green, which is the whole point of running the suite rather than this group alone.
 *
 * @group sprint-5
 */

declare(strict_types=1);

namespace WpMcp\Tests\Integration;

use WpMcp\Tests\Support\Fixtures;
use WpMcp\Tests\Support\FixtureIntegrationTestCase;
use WpMcp\Tests\Support\MuPlugin;
use WpMcp\Tests\Support\TestRecorder;
use WpMcp\Tests\Support\WpCli;

final class ToolContractTest extends FixtureIntegrationTestCase
{
    private static function label(): string { return Fixtures::name('contract'); }
    private static function editorLogin(): string { return Fixtures::name('contract-editor'); }

    /** The mu-plugin that adds the two fixture tools this class needs. */
    private const FIXTURE_TOOLS = 'contract-tools';

    /** A correctly declared tool with a typed argument, which records that it ran. */
    private static function strictTool(): string { return Fixtures::name('tool-strict'); }

    /** A tool that declares `write` but no annotations: the registry must drop it. */
    private static function unannotatedTool(): string { return Fixtures::name('tool-unannotated'); }

    /** Set by strictTool's run callable. Absent means the callable was never entered. */
    private static function ranMarker(): string { return Fixtures::name('tool-strict-ran'); }

    /** The post create-post must NOT create. */
    private static function uncreatablePost(): string { return Fixtures::name('should-never-exist'); }

    private static int $editorId = 0;
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
        MuPlugin::drop(self::FIXTURE_TOOLS, self::fixtureToolsSource());

        // An Editor, so create-post is refused by the VALIDATOR rather than by a
        // capability - otherwise "no post was created" would prove nothing.
        self::$editorId   = Fixtures::createUser(self::editorLogin(), 'editor');
        self::$readToken  = Fixtures::mintToken('read', self::label(), self::$editorId);
        self::$adminToken = Fixtures::mintToken('admin', self::label(), self::$editorId);
    }

    public static function tearDownAfterClass(): void
    {
        self::destroy();

        parent::tearDownAfterClass();
    }

    private static function destroy(): void
    {
        MuPlugin::remove(self::FIXTURE_TOOLS);
        TestRecorder::uninstall();
        self::forgetRanMarker();
        Fixtures::deleteUser(self::$editorId);
        Fixtures::deleteTokensLabelled(self::label());
        Fixtures::purge();
    }

    /**
     * `list-posts {limit: "twenty"}` is refused, the message points at `/limit`, and the
     * tool does not run.
     *
     * THE STRING IS THE POINT. Before the validator the tool cast it - `(int) "twenty"`
     * is 0, clamped to 1 - so the call SUCCEEDED and returned one post. Nothing was
     * refused, nothing was logged, and the agent's next reasoning step was built on a
     * list it had not asked for. Disable the validator and this test goes green in
     * exactly that way, which is how it was checked.
     *
     * @group sprint-5
     */
    public function testAStringWhereAnIntegerBelongsIsRefusedWithAPointer(): void
    {
        $result = $this->mcp(self::$readToken)->callTool('list-posts', ['limit' => 'twenty']);

        self::assertTrue(
            $result->isError,
            'list-posts accepted limit: "twenty". The tool casts, and a cast cannot fail:'
            . ' (int) "twenty" is 0 and the caller gets a list it did not ask for. Got: '
            . $result->text
        );
        self::assertStringContainsString(
            '/limit',
            $result->text,
            'The refusal must name WHERE the problem is, as a JSON pointer, or an agent'
            . ' has nothing to correct. Got: ' . $result->text
        );
        self::assertStringContainsString(
            'expected integer, got string',
            $result->text,
            'The refusal must name what was wanted and what arrived. Got: ' . $result->text
        );
        // And it is a TOOL error, not -32602: the envelope was fine, the arguments were
        // not, and an agent recovers from the first by fixing the call.
        self::assertStringNotContainsString('items', $result->text, $result->text);
    }

    /**
     * THE RUN CALLABLE IS NEVER ENTERED. The assertion the one above cannot make.
     *
     * A fixture tool writes a transient as its first statement. Called with a bad
     * argument the transient stays absent; called correctly it appears - the second half
     * is what stops this passing because the tool is broken or unregistered.
     *
     * @group sprint-5
     */
    public function testTheToolIsNotEnteredWhenValidationFails(): void
    {
        self::forgetRanMarker();

        $refused = $this->mcp(self::$readToken)->callTool(self::strictTool(), ['limit' => '20']);

        self::assertTrue($refused->isError, 'The fixture tool accepted a string: ' . $refused->text);
        self::assertSame(
            '0',
            self::ranMarkerState(),
            'The tool\'s run callable executed despite the validation failure. A refusal'
            . ' that arrives after the side effect is not a refusal.'
        );

        // The control: the same tool, called correctly, does run.
        $accepted = $this->mcp(self::$readToken)->callTool(self::strictTool(), ['limit' => 20]);

        self::assertFalse($accepted->isError, 'The fixture tool refused a valid call: ' . $accepted->text);
        self::assertSame(
            '1',
            self::ranMarkerState(),
            'The fixture tool never ran at all, so the assertion above proves nothing.'
        );
    }

    /**
     * An argument key nobody declared is refused, and no post is created.
     *
     * `additionalProperties: false` is the default, which is Max's explicit-scope rule on
     * the one input dimension that had no allow-list. The failure mode it closes is
     * quiet: `stauts` is ignored, the post is created as a DRAFT because `status` was
     * never seen, and the caller believes it published something.
     *
     * @group sprint-5
     */
    public function testAnUndeclaredArgumentKeyIsRefusedAndNothingIsWritten(): void
    {
        $result = $this->mcp(self::$adminToken)->callTool('create-post', [
            'title'  => self::uncreatablePost(),
            'stauts' => 'publish',
        ]);

        self::assertTrue(
            $result->isError,
            'create-post accepted an argument key it does not declare. Got: ' . $result->text
        );
        self::assertStringContainsString(
            '/stauts',
            $result->text,
            'The refusal must name the key it did not recognise: ' . $result->text
        );

        self::assertNotContains(
            self::uncreatablePost(),
            Fixtures::leftoverPosts(),
            'create-post created the post anyway. The validator runs before the tool, or'
            . ' it is not a validator.'
        );
    }

    /**
     * A tool added through the `wpmcp_tools` filter without annotations is not registered,
     * and a registry_reject event says why.
     *
     * Same rule as `write`, one level out: a client reads `destructiveHint` to decide
     * whether to ask the human first, so a tool that declares nothing is a tool presented
     * as safe. The correctly annotated fixture tool next to it is the control.
     *
     * @group sprint-5
     */
    public function testAToolWithoutAnnotationsIsNotRegistered(): void
    {
        TestRecorder::reset();

        $body = (string) $this->mcp(self::$readToken)->post('tools/list')->getBody();

        self::assertStringContainsString(
            self::strictTool(),
            $body,
            'The annotated fixture tool is absent, so the wpmcp_tools filter never ran and'
            . ' this test proves nothing.'
        );
        self::assertStringNotContainsString(
            self::unannotatedTool(),
            $body,
            'A filter-added tool with no annotations was registered. A client that reads'
            . ' only what is present treats a missing destructiveHint as "not destructive".'
        );

        $rejected = [];

        foreach (TestRecorder::detailsOf(TestRecorder::AUTH . 'registry_reject') as $context) {
            $rejected[(string) ($context['tool'] ?? '')] = (string) ($context['reason'] ?? '');
        }

        self::assertSame(
            'annotations_incomplete',
            $rejected[self::unannotatedTool()] ?? null,
            'No registry_reject event named the unannotated tool. Events: ' . json_encode($rejected)
        );
        self::assertArrayNotHasKey(
            self::strictTool(),
            $rejected,
            'The correctly annotated control tool was rejected too.'
        );
    }

    /**
     * Every tool in the listing carries all four annotations, as JSON booleans.
     *
     * ASSERTED ON THE DECODED VALUE *AND* ITS TYPE: `"destructiveHint": "false"` is a
     * truthy string and would pass an `isset` check while meaning the opposite.
     *
     * @group sprint-5
     */
    public function testEveryListedToolCarriesFourBooleanAnnotations(): void
    {
        $raw   = (string) $this->mcp(self::$adminToken)->post('tools/list')->getBody();
        $tools = json_decode($raw, true)['result']['tools'] ?? null;

        self::assertIsArray($tools, 'tools/list served no tool list: ' . $raw);
        self::assertGreaterThan(10, count($tools), 'Suspiciously short listing: ' . $raw);

        foreach ($tools as $tool) {
            $name        = (string) ($tool['name'] ?? '?');
            $annotations = $tool['annotations'] ?? null;

            self::assertIsArray($annotations, "{$name} carries no annotations: " . $raw);

            foreach (['readOnlyHint', 'destructiveHint', 'idempotentHint', 'openWorldHint'] as $hint) {
                self::assertArrayHasKey($hint, $annotations, "{$name} is missing {$hint}.");
                self::assertIsBool(
                    $annotations[$hint],
                    "{$name}'s {$hint} is not a JSON boolean: " . json_encode($annotations)
                );
            }
        }
    }

    /**
     * site-info's empty `properties` is `{}` on the wire, and no schema anywhere is `[]`.
     *
     * ASSERTED ON THE RAW BYTES, because json_decode makes `[]` and `{}` the same PHP
     * value - the trap that made this worth a test in the first place, and the same one
     * Sprint 4's capability assertion is built around.
     *
     * @group sprint-5
     */
    public function testAnEmptySchemaObjectSerializesAsAnObject(): void
    {
        $raw     = (string) $this->mcp(self::$adminToken)->post('tools/list')->getBody();
        $compact = (string) preg_replace('/\s+/', '', $raw);

        self::assertStringContainsString(
            '"name":"site-info","description"',
            $compact,
            'site-info is not in the listing, so the assertion below would hold vacuously.'
            . ' Body: ' . $raw
        );
        self::assertStringContainsString(
            '"properties":{}',
            $compact,
            'No schema in the listing has an empty `properties` object. site-info has no'
            . ' arguments, so its properties must be `{}`. Body: ' . $raw
        );
        self::assertStringNotContainsString(
            '"properties":[]',
            $compact,
            'A schema serialized `properties` as an EMPTY ARRAY. `"properties": []` is not'
            . ' a JSON Schema and a validating client rejects the whole listing. Body: ' . $raw
        );
        self::assertStringNotContainsString(
            '"inputSchema":[]',
            $compact,
            'A tool\'s whole inputSchema serialized as an empty array. Body: ' . $raw
        );
    }

    /**
     * `cursor` is accepted and validated: a cursor this server issued serves the page, one
     * it did not is -32602.
     *
     * THERE IS NO SECOND PAGE TODAY - the page size is larger than the surface on purpose,
     * so `nextCursor` is absent and that absence is asserted rather than assumed. What is
     * being pinned is that the parameter is READ: a server that ignores `cursor` silently
     * re-serves page one forever, and a client paginating against it never terminates.
     *
     * @group sprint-5
     */
    public function testTheCursorIsAcceptedAndValidated(): void
    {
        $mcp = $this->mcp(self::$adminToken);

        $first = json_decode((string) $mcp->post('tools/list')->getBody(), true);

        self::assertArrayNotHasKey(
            'nextCursor',
            (array) ($first['result'] ?? []),
            'tools/list offered a nextCursor. The page size is bigger than the whole tool'
            . ' surface, so there is no second page - a cursor here sends a client after a'
            . ' page that does not exist.'
        );

        // A cursor this server WOULD issue: base64 of the offset. Offset 0 is page one.
        $paged = json_decode(
            (string) $mcp->post('tools/list', ['cursor' => base64_encode('0')])->getBody(),
            true
        );

        self::assertSame(
            $first['result']['tools'] ?? null,
            $paged['result']['tools'] ?? null,
            'A cursor for offset 0 did not serve the same page as no cursor at all.'
        );

        // Past the end: an empty page, not an error. The client has simply finished.
        $beyond = json_decode(
            (string) $mcp->post('tools/list', ['cursor' => base64_encode('500')])->getBody(),
            true
        );

        self::assertSame([], $beyond['result']['tools'] ?? null, 'A cursor past the end was not an empty page.');

        foreach (['garbage', 'MA', base64_encode('-1'), base64_encode('two')] as $bad) {
            $body = json_decode(
                (string) $mcp->post('tools/list', ['cursor' => $bad])->getBody(),
                true
            );

            self::assertSame(
                -32602,
                $body['error']['code'] ?? null,
                "The cursor '{$bad}' was not refused -32602. A cursor is opaque: a value"
                . ' this server did not issue is a malformed request, not an empty page.'
                . ' Body: ' . json_encode($body)
            );
        }
    }

    /**
     * A `params.arguments` that is not an object is -32602, not a silent empty argument
     * list.
     *
     * This is the one new -32602 case the sprint adds, and it is about the REQUEST's shape
     * rather than the arguments' contents: `"arguments": "limit=20"` used to become
     * `array()` and the tool ran on its defaults.
     *
     * @group sprint-5
     */
    public function testAMalformedCallToolRequestShapeIsInvalidParams(): void
    {
        $mcp = $this->mcp(self::$readToken);

        foreach (['a string', ['a', 'list']] as $arguments) {
            $body = json_decode(
                (string) $mcp->post('tools/call', ['name' => 'site-info', 'arguments' => $arguments])->getBody(),
                true
            );

            self::assertSame(
                -32602,
                $body['error']['code'] ?? null,
                'params.arguments that is not an object must be -32602. Silently treating'
                . ' it as no arguments runs the tool on its defaults. Sent: '
                . json_encode($arguments) . ' Body: ' . json_encode($body)
            );
        }

        $body = json_decode(
            (string) $mcp->post('tools/call', ['name' => ['site-info'], 'arguments' => []])->getBody(),
            true
        );

        self::assertSame(-32602, $body['error']['code'] ?? null, json_encode($body));
    }

    /** '1' when strictTool's run callable has been entered since the last forget, '0' otherwise. */
    private static function ranMarkerState(): string
    {
        return WpCli::evaluate(sprintf(
            'echo (int) (bool) get_transient(%s);',
            self::phpString(self::ranMarker())
        ));
    }

    private static function forgetRanMarker(): void
    {
        WpCli::tryEvaluate(sprintf(
            'echo (int) delete_transient(%s);',
            self::phpString(self::ranMarker())
        ));
    }

    private static function phpString(string $value): string
    {
        return "'" . addcslashes($value, "'\\") . "'";
    }

    /**
     * Two fixture tools: one correctly declared with a typed argument that records having
     * run, one that declares everything EXCEPT annotations.
     *
     * Unconditional, like the sprint-2 bad-tools fixture: the filter runs on every request
     * to the endpoint, a rejected tool is harmless to a concurrent runner, and the marker
     * transient is fixture-named so purge() takes it away.
     */
    private static function fixtureToolsSource(): string
    {
        $strict      = self::strictTool();
        $unannotated = self::unannotatedTool();
        $marker      = self::ranMarker();

        return <<<PHP
add_filter('wpmcp_tools', static function (\$tools) {
    // A TYPED argument and nothing else, so "was this validated" is the only question
    // the call can be asking. The marker is the FIRST statement of the callable: if the
    // validator runs after the tool, the transient is there and the test says so.
    \$tools['{$strict}'] = array(
        'write'       => false,
        'annotations' => array(
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ),
        'description' => 'wp-mcp test fixture: records that it ran; takes one integer.',
        'inputSchema' => array(
            'type'       => 'object',
            'properties' => array('limit' => array('type' => 'integer')),
        ),
        'run'         => static function (\$args) {
            set_transient('{$marker}', 1, 600);

            return array('limit' => isset(\$args['limit']) ? \$args['limit'] : null);
        },
    );

    // Everything a pre-Sprint-5 tool declared, and nothing more. It must not register.
    \$tools['{$unannotated}'] = array(
        'write'       => false,
        'description' => 'wp-mcp test fixture: no annotations.',
        'inputSchema' => array('type' => 'object', 'properties' => new stdClass()),
        'run'         => static function (\$args) { return array('ran' => 'I-SHOULD-NOT-HAVE-RUN'); },
    );

    return \$tools;
});
PHP;
    }
}
