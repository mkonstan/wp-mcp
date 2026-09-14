<?php
/**
 * get-post-meta and set-post-meta: two tools whose whole surface is an operator's
 * declaration.
 *
 * WHY THE ALLOW-LIST IS THE SUBJECT. Post meta is where a WordPress site keeps everything
 * that is not a post field - an ACF value, a page-builder payload, a plugin's private
 * state, `_edit_lock` - and WordPress has no capability that separates the first from the
 * last. So these tools discover nothing: an administrator writes the exact key names into
 * Settings > WP MCP, and those are the only keys that exist for MCP. With an empty list
 * neither tool is in tools/list at all, which is the invariant sql-select and the code
 * tools already follow.
 *
 * THE LIST IS ARMED PER REQUEST, not per class, and that is a decision about these SITES
 * rather than about the tests. A mu-plugin answering `pre_option_wpmcp_meta_keys` for the
 * whole class would expose those keys to every token on the site while it ran - on the
 * stress site, a real client's. The fixture answers for exactly the requests carrying this
 * run's id AND the arming header, and for this run's OTHER requests it answers with an
 * EMPTY list: that is what makes "with no allow-list the tools do not exist" a deterministic
 * assertion rather than one that depends on what the operator of this particular site has
 * configured.
 *
 * NOTHING WRITES THE OPTION except the settings round trip at the bottom, which reads the
 * operator's value first and puts it back in the same process - an option is a shared
 * value with no room for a run prefix. Its KEYS carry the run prefix, so
 * Fixtures::switchesLeftOn() can report a leftover even though the option itself cannot
 * be attributed.
 *
 * @group sprint-11
 */

declare(strict_types=1);

namespace WpMcp\Tests\Integration;

use WpMcp\Tests\Support\Fixtures;
use WpMcp\Tests\Support\FixtureIntegrationTestCase;
use WpMcp\Tests\Support\IntegrationTestCase;
use WpMcp\Tests\Support\MuPlugin;
use WpMcp\Tests\Support\ToolResult;
use WpMcp\Tests\Support\WpCli;

final class PostMetaToolsTest extends FixtureIntegrationTestCase
{
    /** The mu-plugin slug: the per-request allow-list. */
    private const ALLOW = 'meta-keys';

    /** Sent to put this run's keys on the allow-list for one request. */
    private const ARM_HEADER = 'X-Wpmcp-Test-Meta';

    private static function label(): string { return Fixtures::name('metatools'); }

    private static function editorLogin(): string { return Fixtures::name('metaeditor'); }
    private static function authorLogin(): string { return Fixtures::name('metaauthor'); }
    private static function subscriberLogin(): string { return Fixtures::name('metasub'); }

    /** The two allow-listed keys, per run, so a leftover in the option is attributable. */
    private static function colourKey(): string { return Fixtures::name('colour'); }
    private static function sizesKey(): string { return Fixtures::name('sizes'); }

    /** A key nobody allowed. Prefixed too, so a stray row is still findable. */
    private static function strayKey(): string { return Fixtures::name('stray'); }

    private static function publishedTitle(): string { return Fixtures::name('meta-published'); }
    private static function draftTitle(): string { return Fixtures::name('meta-draft'); }

    private static int $editorId = 0;
    private static int $authorId = 0;
    private static int $subscriberId = 0;

    private static int $publishedId = 0;
    private static int $draftId = 0;

    private static string $editorToken = '';
    private static string $authorToken = '';
    private static string $subscriberToken = '';

    /** The stored option before this class touched anything. Asserted unchanged after. */
    private static string $optionBefore = '';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::requireSite();

        self::buildFixtures(self::build(...), self::destroy(...));
    }

    private static function build(): void
    {
        Fixtures::purge();

        self::$optionBefore = self::storedAllowList();

        self::$editorId     = Fixtures::createUser(self::editorLogin(), 'editor');
        self::$authorId     = Fixtures::createUser(self::authorLogin(), 'author');
        self::$subscriberId = Fixtures::createUser(self::subscriberLogin(), 'subscriber');

        self::$publishedId = Fixtures::createPost(
            self::publishedTitle(),
            'publish',
            self::$editorId,
            Fixtures::name('meta-published-body')
        );
        self::$draftId = Fixtures::createPost(
            self::draftTitle(),
            'draft',
            self::$editorId,
            Fixtures::name('meta-draft-body')
        );

        // After purge(), which removes this run's mu-plugins.
        MuPlugin::drop(self::ALLOW, self::allowListSource());

        self::$editorToken     = Fixtures::mintToken('admin', self::label(), self::$editorId);
        self::$authorToken     = Fixtures::mintToken('admin', self::label(), self::$authorId);
        // READ scope: get-post-meta carries `write => false`, so a read token is exactly
        // the right shape for "what may a Subscriber see".
        self::$subscriberToken = Fixtures::mintToken('read', self::label(), self::$subscriberId);
    }

    public static function tearDownAfterClass(): void
    {
        self::destroy();

        parent::tearDownAfterClass();
    }

    private static function destroy(): void
    {
        MuPlugin::remove(self::ALLOW);

        Fixtures::deletePost(self::$publishedId);
        Fixtures::deletePost(self::$draftId);

        Fixtures::deleteUser(self::$editorId);
        Fixtures::deleteUser(self::$authorId);
        Fixtures::deleteUser(self::$subscriberId);

        Fixtures::deleteTokensLabelled(self::label());
        Fixtures::purge();
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Every test starts from "no rows under either key", so no test depends on the
        // order the runner happens to choose.
        Fixtures::deletePostMeta(self::$publishedId, self::colourKey());
        Fixtures::deletePostMeta(self::$publishedId, self::sizesKey());
        Fixtures::deletePostMeta(self::$publishedId, self::strayKey());
        Fixtures::deletePostMeta(self::$draftId, self::colourKey());
    }

    /* ------------------------------------------------------------------
     * the allow-list decides whether the tools exist
     * ---------------------------------------------------------------- */

    /**
     * With an empty allow-list neither tool is listed, and calling either by name is
     * refused with the SAME code and the SAME sentence as a name nobody registered.
     *
     * NO ORACLE. "These tools exist but this site has declared no keys" is a fact about
     * somebody's configuration. The assertion is therefore not "it was refused" but "the
     * refusal is byte-for-byte the one a nonexistent name gets, with only the name
     * differing".
     *
     * @group sprint-11
     */
    public function testWithNoAllowListNeitherToolExists(): void
    {
        $listed = $this->listedTools(self::$editorToken, false);

        self::assertNotContains('get-post-meta', $listed, 'get-post-meta is advertised with an empty allow-list.');
        self::assertNotContains('set-post-meta', $listed, 'set-post-meta is advertised with an empty allow-list.');

        $absent  = Fixtures::name('no-such-tool');
        $control = $this->rawCall(self::$editorToken, $absent, ['id' => self::$publishedId], false);

        self::assertSame(
            ['code' => -32602, 'message' => 'Unknown tool: ' . $absent],
            $control,
            'The control refusal changed shape, so the assertions below compare against'
            . ' nothing.'
        );

        foreach (['get-post-meta', 'set-post-meta'] as $name) {
            self::assertSame(
                ['code' => -32602, 'message' => 'Unknown tool: ' . $name],
                $this->rawCall(self::$editorToken, $name, [
                    'id'    => self::$publishedId,
                    'key'   => self::colourKey(),
                    'value' => 'teal',
                ], false),
                "Calling {$name} with an empty allow-list must be refused exactly the way a"
                . ' tool that does not exist is refused.'
            );
        }

        self::assertSame(
            [],
            Fixtures::postMetaRows(self::$publishedId, self::colourKey()),
            'The unlisted set-post-meta wrote a row anyway.'
        );
    }

    /**
     * With keys declared, both tools appear - for a write token and for a read one, each
     * according to its `write` flag.
     *
     * @group sprint-11
     */
    public function testWithKeysDeclaredBothToolsAreListed(): void
    {
        $admin = $this->listedTools(self::$editorToken, true);

        self::assertContains('get-post-meta', $admin);
        self::assertContains('set-post-meta', $admin);

        $read = $this->listedTools(self::$subscriberToken, true);

        self::assertContains(
            'get-post-meta',
            $read,
            'get-post-meta is a read tool, so a read-scope token must see it.'
        );
        self::assertNotContains(
            'set-post-meta',
            $read,
            'set-post-meta carries `write => true`, which is the admin-scope gate.'
        );
    }

    /* ------------------------------------------------------------------
     * the round trips
     * ---------------------------------------------------------------- */

    /**
     * A scalar goes in, comes back as re-read, and get-post-meta reports it - both in the
     * whole-post form and when the one key is named.
     *
     * @group sprint-11
     */
    public function testAScalarRoundTrips(): void
    {
        $written = $this->set(self::$editorToken, self::$publishedId, self::colourKey(), 'teal');

        self::assertFalse($written->isError, $written->text);
        self::assertSame(
            ['id' => self::$publishedId, 'key' => self::colourKey(), 'value' => 'teal'],
            $written->data()
        );
        self::assertSame(
            ['teal'],
            Fixtures::postMetaRows(self::$publishedId, self::colourKey()),
            'The row on the site is not what the tool said it wrote.'
        );

        $all = $this->get(self::$editorToken, self::$publishedId);

        self::assertFalse($all->isError, $all->text);
        self::assertSame(
            ['id' => self::$publishedId, 'meta' => [self::colourKey() => 'teal']],
            $all->data(),
            'get-post-meta must report every allowed key that HAS a value, and only those.'
        );

        $one = $this->get(self::$editorToken, self::$publishedId, self::colourKey());

        self::assertSame([self::colourKey() => 'teal'], $one->data()['meta']);
    }

    /**
     * A flat list becomes several rows, and comes back as a list.
     *
     * REPLACE, NOT APPEND. The key is given three values, then two, and the answer is two
     * - update_post_meta() cannot express "these N rows" at all, so a tool that reached
     * for it would leave the third behind.
     *
     * @group sprint-11
     */
    public function testAListRoundTripsAndReplaces(): void
    {
        $three = $this->set(self::$editorToken, self::$publishedId, self::sizesKey(), ['s', 'm', 'l']);

        self::assertFalse($three->isError, $three->text);
        self::assertSame(['s', 'm', 'l'], $three->data()['value']);
        self::assertSame(['s', 'm', 'l'], Fixtures::postMetaRows(self::$publishedId, self::sizesKey()));

        $two = $this->set(self::$editorToken, self::$publishedId, self::sizesKey(), ['xs', 'xl']);

        self::assertFalse($two->isError, $two->text);
        self::assertSame(['xs', 'xl'], $two->data()['value']);
        self::assertSame(
            ['xs', 'xl'],
            Fixtures::postMetaRows(self::$publishedId, self::sizesKey()),
            'Writing two values over three left one of the old rows behind.'
        );

        self::assertSame(
            ['xs', 'xl'],
            $this->get(self::$editorToken, self::$publishedId, self::sizesKey())->data()['meta'][self::sizesKey()]
        );
    }

    /**
     * `null` deletes the key: the tool answers null, the rows are gone, and get-post-meta
     * no longer names the key at all.
     *
     * @group sprint-11
     */
    public function testNullDeletesTheKey(): void
    {
        $this->set(self::$editorToken, self::$publishedId, self::colourKey(), 'teal');

        $deleted = $this->set(self::$editorToken, self::$publishedId, self::colourKey(), null);

        self::assertFalse($deleted->isError, $deleted->text);
        self::assertNull($deleted->data()['value']);
        self::assertSame([], Fixtures::postMetaRows(self::$publishedId, self::colourKey()));

        $all = $this->get(self::$editorToken, self::$publishedId);

        self::assertSame(
            [],
            $all->data()['meta'],
            'A key with no rows must be absent from `meta`, not present as null - and with'
            . ' no key left at all the object has to serialise as {}, which decodes here'
            . ' as an empty array rather than as a list.'
        );
    }

    /**
     * An object, and a list holding one, are refused - and nothing is written.
     *
     * Post meta has no schema, so a nested structure would be stored as PHP-serialised
     * text that only this site can read back. That is a shape a tool should refuse rather
     * than accept and misreport.
     *
     * @group sprint-11
     */
    public function testAnObjectValueIsRefused(): void
    {
        $this->set(self::$editorToken, self::$publishedId, self::colourKey(), 'teal');

        foreach ([['shade' => 'teal'], [['s'], ['m']], [['nested' => 1]]] as $bad) {
            $result = $this->set(self::$editorToken, self::$publishedId, self::colourKey(), $bad);

            self::assertTrue(
                $result->isError,
                'set-post-meta accepted ' . json_encode($bad) . ' as a value.'
            );
            self::assertStringContainsString(
                'value must be a JSON scalar, a flat list of scalars, or null',
                $result->text
            );
        }

        self::assertSame(
            ['teal'],
            Fixtures::postMetaRows(self::$publishedId, self::colourKey()),
            'A refused write changed the stored value anyway.'
        );
    }

    /* ------------------------------------------------------------------
     * the refusals
     * ---------------------------------------------------------------- */

    /**
     * A key outside the allow-list is refused by BOTH tools, and the refusal names that
     * key and nothing else about this site.
     *
     * @group sprint-11
     */
    public function testAKeyOutsideTheAllowListIsRefusedAndNamesOnlyItself(): void
    {
        Fixtures::setPostMeta(self::$publishedId, self::strayKey(), 'private-value');

        $read  = $this->get(self::$editorToken, self::$publishedId, self::strayKey());
        $write = $this->set(self::$editorToken, self::$publishedId, self::strayKey(), 'overwritten');

        foreach (['get-post-meta' => $read, 'set-post-meta' => $write] as $tool => $result) {
            self::assertTrue($result->isError, "{$tool} served a key nobody allowed.");
            self::assertStringContainsString(self::strayKey(), $result->text);
            self::assertStringContainsString("is not on this site's allow-list", $result->text);

            foreach ([self::colourKey(), self::sizesKey()] as $allowed) {
                self::assertStringNotContainsString(
                    $allowed,
                    $result->text,
                    "The refusal named {$allowed}. The allow-list is the operator's"
                    . ' configuration, and a caller that guessed wrong has no business'
                    . ' learning what the right answers are.'
                );
            }
        }

        self::assertSame(
            ['private-value'],
            Fixtures::postMetaRows(self::$publishedId, self::strayKey()),
            'The refused write changed the row anyway.'
        );

        // And the whole-post read does not smuggle it in either.
        $all = $this->get(self::$editorToken, self::$publishedId);

        self::assertArrayNotHasKey(
            self::strayKey(),
            $all->data()['meta'],
            'A key outside the allow-list came back in the un-keyed listing.'
        );
    }

    /**
     * A protected key is refused at call time with its own sentence, whatever the
     * allow-list says - and the settings form drops it on save, which
     * testTheSettingsSaveRoundTripsTheTextarea asserts separately.
     *
     * TWO PLACES, BECAUSE THE OPTION IS AN ORDINARY ROW. wp-cli, another plugin or a
     * restored backup can write `_secret` into it without ever passing through the form.
     *
     * @group sprint-11
     */
    public function testAProtectedKeyIsRefusedAtCall(): void
    {
        foreach (['_secret', '_thumbnail_id', '_edit_lock'] as $key) {
            $result = $this->set(self::$editorToken, self::$publishedId, $key, 'x');

            self::assertTrue($result->isError, "set-post-meta wrote the protected key {$key}.");
            self::assertStringContainsString(
                'is protected by WordPress',
                $result->text,
                'A protected key gets its own answer rather than being folded into "not'
                . ' on the allow-list": it is a different fact and the honest sentence'
                . ' says so.'
            );
        }

        self::assertTrue(
            $this->get(self::$editorToken, self::$publishedId, '_thumbnail_id')->isError,
            'get-post-meta read a protected key.'
        );
    }

    /**
     * An Author cannot write meta on another user's post: the first gate is edit_post,
     * and it refuses before the key is even considered.
     *
     * @group sprint-11
     */
    public function testAnAuthorCannotWriteMetaOnAnotherUsersPost(): void
    {
        Fixtures::setPostMeta(self::$publishedId, self::colourKey(), 'teal');

        $result = $this->set(self::$authorToken, self::$publishedId, self::colourKey(), 'magenta');

        self::assertTrue($result->isError, 'An Author rewrote a field on the Editor\'s post.');
        self::assertStringContainsString(
            'not allowed to edit post ' . self::$publishedId,
            $result->text
        );
        self::assertSame(
            ['teal'],
            Fixtures::postMetaRows(self::$publishedId, self::colourKey()),
            'The refusal came after the write.'
        );
    }

    /**
     * A Subscriber READS the allow-listed values of a published post.
     *
     * That is the operator's declaration and not an accident: a key on the list is a key
     * the operator has said MCP may serve, and read_post on a published post is the same
     * gate get-post answers. Without this half, "a Subscriber is refused" elsewhere could
     * just mean the tool is broken for Subscribers.
     *
     * @group sprint-11
     */
    public function testASubscriberReadsTheAllowListedValuesOfAPublishedPost(): void
    {
        Fixtures::setPostMeta(self::$publishedId, self::colourKey(), 'teal');

        $result = $this->get(self::$subscriberToken, self::$publishedId);

        self::assertFalse($result->isError, $result->text);
        self::assertSame(
            ['id' => self::$publishedId, 'meta' => [self::colourKey() => 'teal']],
            $result->data()
        );
    }

    /**
     * A draft the Subscriber may not read answers exactly what get-post answers for it,
     * and exactly what an id that is not there answers.
     *
     * THREE ANSWERS THAT MUST BE ONE. Otherwise the meta tool is a way to learn that a
     * post exists by probing ids - which is the disclosure get-post was built to avoid,
     * and it would be undone by a second tool reading the same object.
     *
     * @group sprint-11
     */
    public function testADraftTheSubscriberCannotReadAnswersLikeGetPost(): void
    {
        Fixtures::setPostMeta(self::$draftId, self::colourKey(), 'teal');

        $meta = $this->get(self::$subscriberToken, self::$draftId);
        $post = $this->mcp(self::$subscriberToken)->callTool('get-post', ['id' => self::$draftId]);

        self::assertTrue($meta->isError, 'A Subscriber read the meta of a draft they cannot see.');
        self::assertTrue($post->isError, 'The control changed: get-post now serves that draft.');
        self::assertSame(
            $post->text,
            $meta->text,
            'get-post-meta answers a draft the caller cannot read differently from'
            . ' get-post. The difference is what tells a caller the post is there.'
        );

        $absent = $this->get(self::$subscriberToken, 99999999);

        self::assertTrue($absent->isError);
        self::assertSame(
            $absent->text,
            $meta->text,
            'A post that is not there and a post that is hidden get different answers.'
        );

        Fixtures::deletePostMeta(self::$draftId, self::colourKey());
    }

    /* ------------------------------------------------------------------
     * the settings form
     * ---------------------------------------------------------------- */

    /**
     * The settings save normalises the textarea and round-trips it: blanks and duplicates
     * go, protected keys go, and what is left is what the tools then read.
     *
     * THE ONE TEST THAT WRITES THE OPTION, and it does the whole thing - read, write,
     * assert, restore - inside ONE `wp eval`, wrapped in try/finally, so the operator's
     * value is back even when an assertion below would have failed. The two switches in
     * the same form are written back with the values they already hold, so
     * wpmcp_save_settings() is exercised in full without changing what either says.
     *
     * @group sprint-11
     */
    public function testTheSettingsSaveRoundTripsTheTextarea(): void
    {
        $textarea = implode("\n", [
            '  ' . self::colourKey() . '  ',
            '',
            self::sizesKey(),
            self::colourKey(),
            '_secret',
            '   ',
        ]);

        $report = json_decode(trim(WpCli::evaluate(sprintf(
            '$before = get_option("wpmcp_meta_keys", array());'
            . ' $code = get_option("wpmcp_code_enabled");'
            . ' $sql = get_option("wpmcp_sql_enabled");'
            . ' $deny = get_option("wpmcp_code_denylist", array());'
            . ' try {'
            . '  wpmcp_save_settings(array('
            . '   "meta_keys" => %s,'
            . '   "denylist" => implode("\n", (array) $deny),'
            . '   "code_enabled" => $code ? 1 : 0,'
            . '   "sql_enabled" => $sql ? 1 : 0,'
            . '  ));'
            . '  echo wp_json_encode(array('
            . '   "stored" => array_values((array) get_option("wpmcp_meta_keys", array())),'
            . '   "read" => wpmcp_meta_keys(),'
            . '   "enabled" => wpmcp_meta_enabled() ? 1 : 0,'
            . '   "code" => get_option("wpmcp_code_enabled") ? 1 : 0,'
            . '   "sql" => get_option("wpmcp_sql_enabled") ? 1 : 0,'
            . '   "code_was" => $code ? 1 : 0,'
            . '   "sql_was" => $sql ? 1 : 0,'
            . '  ));'
            . ' } finally {'
            . '  update_option("wpmcp_meta_keys", $before);'
            . ' }',
            self::phpString($textarea)
        ))), true);

        self::assertIsArray($report, 'The settings round trip produced no report.');

        self::assertSame(
            [self::colourKey(), self::sizesKey()],
            $report['stored'],
            'The saved allow-list is not the normalised textarea: leading and trailing'
            . ' space trimmed, blank lines dropped, the duplicate dropped, _secret dropped'
            . ' because WordPress calls it protected, and the order kept.'
        );
        self::assertSame($report['stored'], $report['read'], 'wpmcp_meta_keys() disagrees with the option.');
        self::assertSame(1, $report['enabled'], 'A non-empty list did not switch the tools on.');

        self::assertSame($report['code_was'], $report['code'], 'The save moved the code-editing switch.');
        self::assertSame($report['sql_was'], $report['sql'], 'The save moved the SQL switch.');

        self::assertSame(
            self::$optionBefore,
            self::storedAllowList(),
            'The operator\'s allow-list was not put back.'
        );
    }

    /**
     * The stored option is the same after this class as before it.
     *
     * @group sprint-11
     */
    public function testTheStoredAllowListIsNeverWritten(): void
    {
        self::assertSame(
            self::$optionBefore,
            self::storedAllowList(),
            'Something in this class wrote wpmcp_meta_keys and left it written. Every test'
            . ' but the settings round trip arms the list through a pre_option_ filter,'
            . ' and that one restores.'
        );
    }

    /* ------------------------------------------------------------------
     * helpers
     * ---------------------------------------------------------------- */

    /** get-post-meta with the allow-list armed for that request. */
    private function get(string $token, int $id, ?string $key = null): ToolResult
    {
        $arguments = ['id' => $id];

        if ($key !== null) { $arguments['key'] = $key; }

        return $this->mcp($token)->callTool('get-post-meta', $arguments, [self::ARM_HEADER => 'on']);
    }

    /** set-post-meta with the allow-list armed for that request. $value may be null. */
    private function set(string $token, int $id, string $key, $value): ToolResult
    {
        return $this->mcp($token)->callTool(
            'set-post-meta',
            ['id' => $id, 'key' => $key, 'value' => $value],
            [self::ARM_HEADER => 'on']
        );
    }

    /** Every tool name in tools/list, with the allow-list armed or not. */
    private function listedTools(string $token, bool $armed): array
    {
        $response = $this->mcp($token)->post(
            'tools/list',
            [],
            $armed ? [self::ARM_HEADER => 'on'] : []
        );

        $body = json_decode((string) $response->getBody(), true);

        self::assertIsArray($body['result']['tools'] ?? null, 'tools/list returned no tools.');

        return array_column($body['result']['tools'], 'name');
    }

    /**
     * A tools/call that is expected to be refused at the JSON-RPC level, as
     * ['code' => ..., 'message' => ...].
     */
    private function rawCall(string $token, string $name, array $arguments, bool $armed): array
    {
        $response = $this->mcp($token)->post(
            'tools/call',
            ['name' => $name, 'arguments' => $arguments],
            $armed ? [self::ARM_HEADER => 'on'] : []
        );

        $body = json_decode((string) $response->getBody(), true);

        self::assertIsArray(
            $body['error'] ?? null,
            "tools/call {$name} was not refused at all: " . (string) $response->getBody()
        );

        return ['code' => $body['error']['code'], 'message' => $body['error']['message']];
    }

    /** The stored option, as JSON, so "unchanged" is one string comparison. */
    private static function storedAllowList(): string
    {
        return trim(WpCli::evaluate(
            'echo wp_json_encode(get_option("wpmcp_meta_keys", array()));'
        ));
    }

    /**
     * The fixture: this run's allow-list, for this run's armed requests only.
     *
     * `false` from a `pre_option_` filter means "no short-circuit", so every request that
     * is not this run's reads the stored option and is unaffected. This run's OWN unarmed
     * requests are answered with an EMPTY list rather than being left alone, which is what
     * makes testWithNoAllowListNeitherToolExists independent of whatever the operator of
     * this particular site has configured.
     */
    private static function allowListSource(): string
    {
        $run    = Fixtures::runId();
        $header = 'HTTP_' . strtoupper(str_replace('-', '_', IntegrationTestCase::RUN_HEADER));
        $arm    = 'HTTP_' . strtoupper(str_replace('-', '_', self::ARM_HEADER));
        $keys   = var_export([self::colourKey(), self::sizesKey()], true);

        return <<<PHP
/**
 * wp-mcp sprint-11 post-meta fixture for run {$run}. Dropped and removed by
 * tests/integration/PostMetaToolsTest.php. IT ANSWERS ONLY FOR THIS RUN'S REQUESTS, so
 * it changes nothing for anybody else. If you are reading this on a live site, the run
 * that wrote it crashed; deleting the file is safe.
 */
add_filter('pre_option_wpmcp_meta_keys', static function (\$pre) {
    \$mine = isset(\$_SERVER['{$header}']) && \$_SERVER['{$header}'] === '{$run}';

    if (!\$mine) {
        return \$pre;
    }

    \$armed = isset(\$_SERVER['{$arm}']) && \$_SERVER['{$arm}'] === 'on';

    return \$armed ? {$keys} : array();
});
PHP;
    }

    /** $value as a single-quoted PHP literal, for embedding in `wp eval` source. */
    private static function phpString(string $value): string
    {
        return "'" . addcslashes($value, "'\\") . "'";
    }
}
