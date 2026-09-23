<?php
/**
 * The swaps and the small wins that only a real site can answer (1.1.1, sprint SWAP).
 *
 * FOUR CLAIMS, AND EACH ONE NEEDS SOMETHING A STUB CANNOT PROVIDE:
 *
 *   1. A CUSTOM POST STATUS is listable, and only by somebody who may see it. `get_post_stati()`
 *      replaced five hard-coded statuses, which WIDENS what list-posts can return - so the
 *      per-status capability check is proved here rather than assumed. The status is registered
 *      by a fixture mu-plugin exactly as a workflow plugin registers one.
 *   2. THE CLIENT'S NAME AND VERSION land on the token row, from `initialize`'s own clientInfo.
 *   3. `do_action('wpmcp_tool_call')` fires once per call, for a refusal as well as a success,
 *      and carries argument KEYS and no argument values.
 *   4. THE OUTPUT-SCHEMA PILOT is on exactly four tools, and `structuredContent` is the text
 *      block - which is the property that makes the two copies unable to disagree.
 *
 * @group sprint-14d
 */

declare(strict_types=1);

namespace WpMcp\Tests\Integration;

use WpMcp\Tests\Support\Fixtures;
use WpMcp\Tests\Support\FixtureIntegrationTestCase;
use WpMcp\Tests\Support\MuPlugin;
use WpMcp\Tests\Support\WpCli;

final class PlatformSwapTest extends FixtureIntegrationTestCase
{
    private const PLUGIN = 'platform-swap';

    /** The four tools the pilot covers, and nothing else may carry a schema. */
    private const PILOTED = ['site-info', 'get-post', 'get-media', 'get-user'];

    /** A workflow plugin's status: NOT public, so seeing somebody else's needs the edit cap. */
    private const PROTECTED_STATUS = 'wpmcp-test-archived';

    /** And one that IS public, so the front end shows it and so does every token. */
    private const PUBLIC_STATUS = 'wpmcp-test-listed';

    private static int $editorId = 0;
    private static int $authorId = 0;
    private static string $editorToken = '';
    private static string $authorToken = '';
    private static int $othersArchived = 0;
    private static int $ownArchived = 0;
    private static int $publicPost = 0;

    private static function label(): string { return Fixtures::name('platform-swap'); }

    /**
     * The EDITOR's token gets a label of its own, because tokenIdLabelled() answers with one
     * row and two tokens share the class label - so the clientInfo assertion would otherwise
     * read the author's row and find it empty, which is exactly what it did the first time.
     */
    private static function editorLabel(): string { return Fixtures::name('platform-swap-editor'); }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::requireSite();

        self::buildFixtures(self::build(...), self::destroy(...));
    }

    private static function build(): void
    {
        Fixtures::purge();

        MuPlugin::drop(self::PLUGIN, self::source());

        self::$editorId = Fixtures::createUser(Fixtures::name('swap-editor'), 'editor');
        self::$authorId = Fixtures::createUser(Fixtures::name('swap-author'), 'author');

        self::$editorToken = Fixtures::mintToken('read', self::editorLabel(), self::$editorId);
        self::$authorToken = Fixtures::mintToken('read', self::label(), self::$authorId);

        // The editor's post in the protected custom status, the author's own post in the same
        // status, and one in the public custom status. createPostExact() so the status reaches
        // wp_insert_post() unchanged.
        self::$othersArchived = Fixtures::createPostExact([
            'post_title'  => Fixtures::name('swap-others-archived'),
            'post_status' => self::PROTECTED_STATUS,
            'post_author' => self::$editorId,
            'post_type'   => 'post',
        ]);
        self::$ownArchived = Fixtures::createPostExact([
            'post_title'  => Fixtures::name('swap-own-archived'),
            'post_status' => self::PROTECTED_STATUS,
            'post_author' => self::$authorId,
            'post_type'   => 'post',
        ]);
        self::$publicPost = Fixtures::createPostExact([
            'post_title'  => Fixtures::name('swap-public-listed'),
            'post_status' => self::PUBLIC_STATUS,
            'post_author' => self::$editorId,
            'post_type'   => 'post',
        ]);
    }

    public static function tearDownAfterClass(): void
    {
        self::destroy();

        parent::tearDownAfterClass();
    }

    private static function destroy(): void
    {
        foreach ([self::$othersArchived, self::$ownArchived, self::$publicPost] as $id) {
            Fixtures::deletePost($id);
        }

        WpCli::tryEvaluate('delete_transient("' . Fixtures::PREFIX . 'tool-calls");');

        MuPlugin::remove(self::PLUGIN);
        Fixtures::deleteTokensLabelled(self::label());
        Fixtures::deleteTokensLabelled(self::editorLabel());
        Fixtures::deleteUser(self::$editorId);
        Fixtures::deleteUser(self::$authorId);
        Fixtures::purge();
    }

    /**
     * The control: the fixture statuses really are registered, with the flags this class
     * reasons about. Without it every capability assertion below could pass because the
     * status does not exist at all.
     *
     * @group sprint-14d
     */
    public function testTheFixtureStatusesAreRegisteredWithTheFlagsThisClassAssumes(): void
    {
        $flags = json_decode(trim(WpCli::evaluate(
            '$o = array();'
            . ' foreach (array("' . self::PROTECTED_STATUS . '", "' . self::PUBLIC_STATUS . '") as $s) {'
            . '  $obj = get_post_status_object($s);'
            . '  $o[$s] = $obj ? array("public" => (bool) $obj->public, "protected" => (bool) $obj->protected,'
            . '   "private" => (bool) $obj->private, "internal" => (bool) $obj->internal) : null; }'
            . ' echo wp_json_encode($o);'
        )), true);

        self::assertSame(
            ['public' => false, 'protected' => true, 'private' => false, 'internal' => false],
            $flags[self::PROTECTED_STATUS] ?? null,
            'The protected fixture status is not registered the way this class reasons about it.'
        );
        self::assertSame(
            ['public' => true, 'protected' => false, 'private' => false, 'internal' => false],
            $flags[self::PUBLIC_STATUS] ?? null,
            'The public fixture status is not registered the way this class reasons about it.'
        );
    }

    /**
     * A PROTECTED custom status is listable by an editor and not by an author - except for the
     * author's OWN post, which they may always see.
     *
     * THIS IS THE WATCH ITEM OF THE SWAP, in D6's own words: `get_post_stati()` widens what
     * list-posts can return, so the capability check per status must be PROVEN. Three
     * assertions, because there are three answers and only the middle one is a refusal.
     *
     * @group sprint-14d
     */
    public function testAProtectedCustomStatusFollowsTheEditorialCapability(): void
    {
        $asEditor = $this->mcp(self::$editorToken)->callTool('list-posts', [
            'status' => self::PROTECTED_STATUS,
            'limit'  => 100,
        ]);
        self::assertFalse($asEditor->isError, $asEditor->text);

        $editorIds = array_map('intval', $asEditor->column('id'));

        self::assertContains(
            self::$othersArchived,
            $editorIds,
            'An editor cannot list a post in a plugin\'s custom status. Before this swap NO'
            . ' custom status was listable at all, which is the coverage the swap buys; the'
            . ' status is `protected`, so edit_others_posts is the capability that admits it.'
        );
        self::assertContains(self::$ownArchived, $editorIds, 'The editor cannot see the author\'s archived post.');

        $asAuthor = $this->mcp(self::$authorToken)->callTool('list-posts', [
            'status' => self::PROTECTED_STATUS,
            'limit'  => 100,
        ]);
        self::assertFalse($asAuthor->isError, $asAuthor->text);

        $authorIds = array_map('intval', $asAuthor->column('id'));

        self::assertNotContains(
            self::$othersArchived,
            $authorIds,
            'AN AUTHOR CAN SEE SOMEBODY ELSE\'S POST IN A PROTECTED CUSTOM STATUS. The status'
            . ' flags are what WP_Query itself consults (class-wp-query.php:3538-3553): a'
            . ' protected status needs the edit capability, and an author holds neither'
            . ' edit_others_posts nor read_private_posts. This is the widening going wrong.'
        );
        self::assertContains(
            self::$ownArchived,
            $authorIds,
            'An author cannot see their OWN post in a custom status, so the own-status'
            . ' complement is not taken over the same registry as the main list.'
        );
    }

    /**
     * A PUBLIC custom status is listable by everybody, because it is on the front end already.
     *
     * @group sprint-14d
     */
    public function testAPublicCustomStatusIsListableByAnybody(): void
    {
        foreach (['editor' => self::$editorToken, 'author' => self::$authorToken] as $who => $token) {
            $result = $this->mcp($token)->callTool('list-posts', [
                'status' => self::PUBLIC_STATUS,
                'limit'  => 100,
            ]);
            self::assertFalse($result->isError, $result->text);

            self::assertContains(
                self::$publicPost,
                array_map('intval', $result->column('id')),
                'A ' . $who . ' cannot list a post in a PUBLIC custom status, which the site'
                . ' already shows to anonymous visitors.'
            );
        }
    }

    /**
     * The client's name and version reach the token row, from initialize's own clientInfo.
     *
     * @group sprint-14d
     */
    public function testTheConnectedClientIsRecordedOnTheTokenRow(): void
    {
        $tokenId = Fixtures::tokenIdLabelled(self::editorLabel());

        self::assertGreaterThan(0, $tokenId, 'No fixture token row to read.');

        $this->mcp(self::$editorToken)->post('initialize', [
            'protocolVersion' => '2025-06-18',
            'capabilities'    => [],
            'clientInfo'      => ['name' => 'wpmcp-test Desktop', 'version' => '1.4'],
        ]);

        $row = json_decode(trim(WpCli::evaluate(sprintf(
            'global $wpdb; $r = $wpdb->get_row($wpdb->prepare("SELECT client_name, client_version,'
            . ' last_used_at, use_count FROM " . wpmcp_table() . " WHERE id = %%d", %d));'
            . ' echo wp_json_encode($r);',
            $tokenId
        ))), true);

        self::assertIsArray($row, 'Could not read the token row.');
        self::assertSame(
            'wpmcp-test Desktop',
            $row['client_name'] ?? null,
            'The client name from initialize is not on the token row, so the settings screen'
            . ' cannot say what is connected - which is the whole of this feature.'
        );
        self::assertSame('1.4', $row['client_version'] ?? null);
        self::assertNotNull($row['last_used_at'] ?? null, 'The row has no last_used_at to sit beside.');

        // AND IT IS WRITTEN ONLY WHEN IT CHANGED: a second identical initialize must not be a
        // second UPDATE. use_count still advances, because that is the credential's counter.
        $this->mcp(self::$editorToken)->post('initialize', [
            'protocolVersion' => '2025-06-18',
            'capabilities'    => [],
            'clientInfo'      => ['name' => 'wpmcp-test Desktop', 'version' => '1.4'],
        ]);

        $again = json_decode(trim(WpCli::evaluate(sprintf(
            'global $wpdb; $r = $wpdb->get_row($wpdb->prepare("SELECT client_name, client_version'
            . ' FROM " . wpmcp_table() . " WHERE id = %%d", %d)); echo wp_json_encode($r);',
            $tokenId
        ))), true);

        self::assertSame($row['client_name'], $again['client_name'] ?? null);
        self::assertSame($row['client_version'], $again['client_version'] ?? null);
    }

    /**
     * The settings screen turns those two columns into a sentence an operator can read.
     *
     * NOT A RENDER TEST - the page body cannot run outside an admin request - but the two
     * helpers that decide what it prints can, and they are where the formatting lives.
     *
     * @group sprint-14d
     */
    public function testTheSettingsScreenLabelsTheClientAndWhenItWasLastSeen(): void
    {
        $labels = json_decode(trim(WpCli::evaluate(
            '$row = (object) array("client_name" => "Claude Desktop", "client_version" => "1.4",'
            . ' "last_used_at" => gmdate("Y-m-d H:i:s", time() - 180));'
            . ' $empty = (object) array("client_name" => "", "client_version" => "", "last_used_at" => null);'
            . ' echo wp_json_encode(array('
            . '  "client" => wpmcp_client_label($row), "seen" => wpmcp_last_used_label($row),'
            . '  "no_client" => wpmcp_client_label($empty), "never" => wpmcp_last_used_label($empty),'
            . ' ));'
        )), true);

        self::assertSame('Claude Desktop 1.4', $labels['client'] ?? null);
        self::assertStringContainsString(
            'ago)',
            (string) ($labels['seen'] ?? ''),
            'The last-used cell gives no relative time, which is the part that answers "is it'
            . ' still there?". Got: ' . ($labels['seen'] ?? '')
        );
        self::assertSame('-', $labels['no_client'] ?? null, 'A token nothing negotiated with should show a dash.');
        self::assertSame('-', $labels['never'] ?? null, 'A token nothing used should show a dash.');
    }

    /**
     * One `wpmcp_tool_call` per call, for a SUCCESS and for a REFUSAL, carrying argument keys
     * and no argument values.
     *
     * THE REFUSAL IS THE HALF THAT MATTERS. An operator asking "what is this token doing" is
     * usually asking because something is not working, and an action that fired only on success
     * would answer with silence exactly then. `ok` is read off the response, so a scope refusal,
     * a schema failure, a tool error and a crash all report false without four call sites having
     * to remember to.
     *
     * @group sprint-14d
     */
    public function testEveryToolCallFiresTheObservationActionWithKeysAndNotValues(): void
    {
        WpCli::evaluate('delete_transient("' . Fixtures::PREFIX . 'tool-calls");');

        // A success, with one argument whose VALUE must not be recorded anywhere.
        $ok = $this->mcp(self::$editorToken)->callTool('get-post', ['id' => self::$othersArchived]);
        self::assertFalse($ok->isError, $ok->text);

        // A refusal: a read-scope token calling a write tool. The tool never runs.
        $refused = $this->mcp(self::$editorToken)->callTool('create-post', ['title' => 'wpmcp-test-refused']);
        self::assertTrue($refused->isError, 'A read-scope token was allowed to call create-post.');

        $fired = json_decode(trim(WpCli::evaluate(
            '$v = get_transient("' . Fixtures::PREFIX . 'tool-calls"); echo wp_json_encode($v ? $v : array());'
        )), true);

        self::assertIsArray($fired, 'The listener recorded nothing, so the action never fired.');
        self::assertCount(
            2,
            $fired,
            'The action did not fire exactly once per call. Twice for one call would be the'
            . ' permission_callback running twice (KB 0.1); once for two calls would be a'
            . ' refusal going unobserved. Got: ' . json_encode($fired)
        );

        [$first, $second] = $fired;

        self::assertSame('get-post', $first['tool'] ?? null);
        self::assertTrue($first['ok'] ?? null, 'A successful call was reported as not ok.');
        self::assertSame(['id'], $first['arg_keys'] ?? null, 'The argument keys are not what was sent.');
        self::assertSame((int) self::$editorId, (int) ($first['user_id'] ?? 0));
        self::assertSame('read', $second['scope'] ?? null);

        self::assertSame('create-post', $second['tool'] ?? null);
        self::assertFalse(
            $second['ok'] ?? null,
            'A refused call was reported as ok, so an operator watching this action cannot tell'
            . ' a working token from a token being refused every time.'
        );
        self::assertSame(['title'], $second['arg_keys'] ?? null);

        $recorded = (string) json_encode($fired);

        self::assertStringNotContainsString(
            'wpmcp-test-refused',
            $recorded,
            'AN ARGUMENT VALUE REACHED THE ACTION. A listener is an ordinary plugin callback and'
            . ' this is somebody\'s content; the keys say which arguments a call used, which is'
            . ' what a usage question is about.'
        );
        self::assertStringNotContainsString(
            (string) self::$othersArchived,
            $recorded,
            'An argument value (the post id) reached the action.'
        );
    }

    /**
     * The pilot is on FOUR tools and no others, and each one's schema describes what it sends.
     *
     * @group sprint-14d
     */
    public function testExactlyTheFourPilotedToolsDeclareAnOutputSchema(): void
    {
        $withSchema = [];

        foreach ($this->allListedTools(self::$editorToken) as $tool) {
            if (isset($tool['outputSchema'])) { $withSchema[] = (string) $tool['name']; }

            self::assertArrayHasKey('inputSchema', $tool, (string) $tool['name'] . ' lost its inputSchema.');
        }

        sort($withSchema);
        $expected = self::PILOTED;
        sort($expected);

        self::assertSame(
            $expected,
            $withSchema,
            'The output-schema pilot is not on exactly the four small-payload read tools D19'
            . ' names. Every tool that has one sends its data TWICE, so the set is the cost.'
        );
    }

    /**
     * `structuredContent` IS the text block, decoded - for every piloted tool, on real data.
     *
     * THE ASSERTION IS THE RE-ENCODE, not a field-by-field walk: if `json_encode` of the
     * structured half is byte-identical to the text block, the two copies cannot differ about a
     * value, a type, or whether an empty field is an object or a list. And a tool without a
     * schema must carry no structured half at all, or the pilot's cost is being paid
     * everywhere.
     *
     * @group sprint-14d
     */
    public function testTheStructuredHalfIsExactlyTheTextBlockAndOnlyOnPilotedTools(): void
    {
        $media = Fixtures::createAttachment(
            Fixtures::name('swap-media'),
            self::$editorId,
            Fixtures::name('swap-media') . '.png'
        );

        try {
            $calls = [
                'site-info' => [],
                'get-post'  => ['id' => self::$othersArchived],
                'get-media' => ['id' => $media],
                'get-user'  => ['id' => self::$editorId],
            ];

            foreach ($calls as $name => $arguments) {
                $result = $this->callRaw(self::$editorToken, $name, $arguments);

                self::assertArrayHasKey(
                    'structuredContent',
                    $result,
                    $name . ' declares an outputSchema and sent no structuredContent, so a'
                    . ' client that reads only the structured half gets nothing.'
                );

                $text = (string) $result['content'][0]['text'];

                self::assertSame(
                    $text,
                    (string) json_encode($result['structuredContent']),
                    $name . '\'s structured half does not re-encode to its own text block, so the'
                    . ' two copies of the same data disagree. Text: ' . $text
                );
                self::assertIsArray(
                    json_decode($text, true),
                    $name . '\'s text block is not a JSON object.'
                );
            }

            // A READ tool without a schema: list-themes is admin-scope, and this token is
            // read-scope on purpose.
            $plain = $this->callRaw(self::$editorToken, 'list-posts', ['limit' => 1]);

            self::assertArrayNotHasKey(
                'structuredContent',
                $plain,
                'A tool with no outputSchema is sending its data twice anyway, which is the cost'
                . ' the pilot exists to keep off the other 32 tools.'
            );
        } finally {
            Fixtures::deletePost($media);
        }
    }

    /** Every tool a token is shown, across every page of tools/list. */
    private function allListedTools(string $token): array
    {
        $tools  = [];
        $cursor = null;

        do {
            $params   = $cursor === null ? [] : ['cursor' => $cursor];
            $body     = json_decode((string) $this->mcp($token)->post('tools/list', $params)->getBody(), true);
            $result   = $body['result'] ?? [];
            $tools    = array_merge($tools, (array) ($result['tools'] ?? []));
            $cursor   = $result['nextCursor'] ?? null;
        } while ($cursor !== null);

        self::assertNotSame([], $tools, 'tools/list returned nothing.');

        return $tools;
    }

    /** One tools/call, as the raw `result` member - structuredContent included. */
    private function callRaw(string $token, string $name, array $arguments): array
    {
        $raw  = (string) $this->mcp($token)->post('tools/call', [
            'name'      => $name,
            'arguments' => $arguments,
        ])->getBody();
        $body = json_decode($raw, true);

        self::assertIsArray($body['result'] ?? null, $name . ' did not answer with a result: ' . $raw);
        self::assertFalse((bool) ($body['result']['isError'] ?? false), $name . ' refused: ' . $raw);

        return $body['result'];
    }

    /**
     * Two custom post statuses and a listener for the usage action.
     *
     * `register_post_status` on `init`, which is where a plugin registers one, and the two
     * flag sets this class reasons about: `protected` (draft's own flag - seeing somebody
     * else's needs the edit capability) and `public` (on the front end, so everybody).
     *
     * The listener keeps its records in a transient carrying the fixture prefix, so a run that
     * dies mid-class leaves something the debris check names rather than something silent.
     */
    private static function source(): string
    {
        $protected = self::PROTECTED_STATUS;
        $public    = self::PUBLIC_STATUS;
        $transient = Fixtures::PREFIX . 'tool-calls';

        return <<<PHP
add_action('init', static function () {
    register_post_status('{$protected}', array(
        'label'     => 'wpmcp test archived',
        'protected' => true,
        'internal'  => false,
    ));
    register_post_status('{$public}', array(
        'label'    => 'wpmcp test listed',
        'public'   => true,
        'internal' => false,
    ));
});

add_action('wpmcp_tool_call', static function (\$tool, \$ok, \$context) {
    \$log   = get_transient('{$transient}');
    \$log   = is_array(\$log) ? \$log : array();
    \$log[] = array(
        'tool'     => \$tool,
        'ok'       => (bool) \$ok,
        'arg_keys' => isset(\$context['arg_keys']) ? \$context['arg_keys'] : null,
        'user_id'  => isset(\$context['user_id']) ? (int) \$context['user_id'] : 0,
        'scope'    => isset(\$context['scope']) ? \$context['scope'] : '',
    );
    set_transient('{$transient}', \$log, 300);
}, 10, 3);
PHP;
    }
}
