<?php
/**
 * The catalog's own contract: every schema stays inside the validator's dialect, and
 * every tool carries four boolean annotations.
 *
 * WHY THE DIALECT NEEDS A TEST AT ALL. An unsupported keyword is not enforced - the
 * validator walks past `oneOf` without a word, because refusing a legal input over a
 * schema it cannot read would be a decision for the schema's author, not for the caller.
 * That silence is a hole, and the only place it can be closed is here: this plugin's own
 * schemas are held to SchemaValidator::KEYWORDS and ::TYPES, so a tool author who
 * reaches for `$ref` finds out from a red test instead of from an input that was never
 * checked. A filter-added tool is still free to use anything; its unsupported keywords
 * simply do not constrain, and that is said out loud in SchemaValidator's docblock.
 *
 * WHY THE ANNOTATIONS NEED ONE. `wpmcp_tools()` drops an entry whose annotations are
 * incomplete, so a built-in that forgot them would VANISH from the listing - loud, but
 * loud in the wrong place: nothing fails until somebody notices a missing tool. Checked
 * here instead, per tool, by name.
 *
 * @group sprint-5
 */

declare(strict_types=1);

namespace WpMcp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WpMcp\SchemaValidator;
use WpMcp\Tests\Support\WordPressStubs;

final class ToolContractTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        WordPressStubs::loadPlugin();
    }

    /**
     * Every keyword of every built-in schema, at every depth, is one the validator
     * enforces - and every `type` is one it knows.
     *
     * @group sprint-5
     */
    public function testEveryBuiltInSchemaStaysInsideTheDialect(): void
    {
        foreach (WireSerializationTest::catalog() as $name => $tool) {
            foreach (self::keywordsIn($tool['inputSchema']) as $pointer => $keyword) {
                self::assertContains(
                    $keyword,
                    SchemaValidator::KEYWORDS,
                    "The inputSchema of {$name} uses '{$keyword}' at {$pointer}, which"
                    . ' SchemaValidator does not enforce - so that constraint is'
                    . ' decoration. Either implement the keyword or stop using it.'
                );
            }

            foreach (self::typesIn($tool['inputSchema']) as $pointer => $type) {
                self::assertContains(
                    $type,
                    SchemaValidator::TYPES,
                    "The inputSchema of {$name} declares type '{$type}' at {$pointer},"
                    . ' which SchemaValidator cannot check.'
                );
            }
        }
    }

    /**
     * All four hints, on every tool in the catalog, as real booleans.
     *
     * @group sprint-5
     */
    public function testEveryToolCarriesFourBooleanAnnotations(): void
    {
        foreach (WireSerializationTest::catalog() as $name => $tool) {
            self::assertArrayHasKey(
                'annotations',
                $tool,
                "The built-in tool {$name} has no annotations, so wpmcp_tools() drops it"
                . ' and it is absent from every tools/list.'
            );

            foreach (wpmcp_annotation_hints() as $hint) {
                self::assertArrayHasKey(
                    $hint,
                    $tool['annotations'],
                    "{$name} is missing the {$hint} annotation."
                );
                self::assertIsBool(
                    $tool['annotations'][$hint],
                    "{$name}'s {$hint} is not a boolean. A client reads these to decide"
                    . ' whether to ask the human first; a string "false" is truthy.'
                );
            }

            self::assertSame(
                [],
                array_diff(array_keys($tool['annotations']), wpmcp_annotation_hints()),
                "{$name} carries an annotation this server does not define: "
                . implode(', ', array_keys($tool['annotations']))
            );
        }
    }

    /**
     * `readOnlyHint` is `!write` on every tool, without exception.
     *
     * ONE FACT, ONE DECLARATION. `write` is the gate a read-scope token is refused on, so
     * a readOnlyHint authored independently is a second copy of that decision that can
     * disagree with it - and the disagreement would be invisible: the scope gate would
     * refuse a tool the listing had announced as read-only, or worse, announce a write
     * tool as safe.
     *
     * code-list and code-read are the honest cost of the rule: they only read, and they
     * are flagged `write` because they are admin-scope tools, so they carry
     * readOnlyHint: false. That errs toward "ask the human", which is the safe direction.
     *
     * @group sprint-5
     */
    public function testReadOnlyHintIsDerivedFromTheWriteFlag(): void
    {
        foreach (WireSerializationTest::catalog() as $name => $tool) {
            self::assertSame(
                !$tool['write'],
                $tool['annotations']['readOnlyHint'],
                "{$name}'s readOnlyHint disagrees with its `write` flag. Those are the"
                . ' same fact, and the flag is the one the scope gate reads.'
            );
        }
    }

    /**
     * The authored hints, named tool by tool, exactly as the build plan's Sprint 5 row
     * specifies them.
     *
     * A TABLE RATHER THAN A RULE, because there is no rule: whether `update-post` is
     * destructive is a judgement, and the point of writing it down here is that changing
     * the judgement has to be deliberate. A tool added to the catalog without a row goes
     * red.
     *
     * @group sprint-5
     */
    public function testTheAuthoredHintsAreTheOnesDecided(): void
    {
        // name => [destructiveHint, idempotentHint, openWorldHint]
        $decided = [
            'site-info'        => [false, true,  false],
            'list-posts'       => [false, true,  false],
            'get-post'         => [false, true,  false],
            'create-post'      => [false, false, false],
            // TRUE, and the row that was wrong: update-post replaces every field it
            // touches and replaces the post's terms in any taxonomy it names.
            'update-post'      => [true,  true,  false],
            'delete-post'      => [true,  true,  false],
            'list-terms'       => [false, true,  false],
            'create-term'      => [false, false, false],
            'delete-term'      => [true,  true,  false],
            'list-media'       => [false, true,  false],
            'get-media'        => [false, true,  false],
            'upload-media'     => [false, false, true],
            'delete-media'     => [true,  true,  false],
            'list-comments'    => [false, true,  false],
            'moderate-comment' => [true,  true,  false],
            'reply-comment'    => [false, false, false],
            'code-list'        => [false, true,  false],
            'code-read'        => [false, true,  false],
            'code-write'       => [true,  false, false],
            'code-delete'      => [true,  true,  false],
            // Sprint 8. code-history only reads, and reads nothing it could destroy.
            'code-history'     => [false, true,  false],
            // code-restore overwrites a theme file with something else, which is the act
            // code-write performs and carries the same judgement. NOT idempotent for
            // code-write's reason: the file ends up the same, the history does not - a
            // second call stores another version of what it replaced.
            'code-restore'     => [true,  false, false],
            // Sprint 9. sql-select cannot write - the server refuses it, not a
            // filter - so it destroys nothing, and running the same SELECT twice
            // has no additional effect because it has no effect at all. Its
            // readOnlyHint is false like every other admin-scope tool's, which
            // testReadOnlyHintIsDerivedFromTheWriteFlag above asserts on its own.
            'sql-select'       => [false, true,  false],
            // Sprint 11. get-post-meta reads one post's allow-listed keys and nothing
            // else. set-post-meta REPLACES the key - every row under it goes and what
            // was sent is written - which is update-post's judgement for update-post's
            // reason: destructiveHint: false is MCP's promise that an update is
            // additive, and replacing three rows with one is not additive. Idempotent
            // because writing the same value twice leaves the same rows.
            'get-post-meta'    => [false, true,  false],
            'set-post-meta'    => [true,  true,  false],
            // Sprint 12. list-revisions and get-revision only read. restore-revision
            // REPLACES the post's title, content and excerpt with the revision's - saved
            // first, so it is undoable, but undoable is not additive, which is
            // update-post's judgement for update-post's reason. Idempotent because a
            // second restore of the same revision finds the post already holding it, and
            // core stores no revision for an unchanged post.
            'list-revisions'   => [false, true,  false],
            'get-revision'     => [false, true,  false],
            'restore-revision' => [true,  true,  false],
            // Sprint 13. list-menus and get-menu only read. add-menu-item brings a new
            // item into being and replaces none - it renumbers its siblings' menu_order,
            // which keeps the order they had - so it is create-post's judgement, and not
            // idempotent for the same reason: a second call adds a second item.
            // update-menu-item REPLACES the label, link, target and classes it is sent,
            // update-post's judgement. remove-menu-item deletes outright; menu items have
            // no trash.
            'list-menus'       => [false, true,  false],
            'get-menu'         => [false, true,  false],
            'add-menu-item'    => [false, false, false],
            'update-menu-item' => [true,  true,  false],
            'remove-menu-item' => [true,  true,  false],
            // Sprint 14. All five only read: nothing destroyed, the same answer twice, and
            // no other server reached - list-plugins and list-themes read the update data
            // as stored and never refresh it. list-plugins and list-themes are admin-scope
            // readers, so their readOnlyHint is false like code-list's and sql-select's,
            // which testReadOnlyHintIsDerivedFromTheWriteFlag asserts on its own.
            'list-users'       => [false, true,  false],
            'get-user'         => [false, true,  false],
            'get-option'       => [false, true,  false],
            'list-plugins'     => [false, true,  false],
            'list-themes'      => [false, true,  false],
            // Sprint SEAM. list-content-types reads the post-type and taxonomy registries and
            // counts rows. Nothing destroyed, the same answer twice for the same site, and no
            // other server reached.
            'list-content-types' => [false, true,  false],
        ];

        $catalog = WireSerializationTest::catalog();

        self::assertSame(
            [],
            array_diff(array_keys($catalog), array_keys($decided)),
            'A tool in the catalog has no row in this table. Its annotations are a'
            . ' judgement somebody has to make on purpose.'
        );
        self::assertSame(
            [],
            array_diff(array_keys($decided), array_keys($catalog)),
            'This table names a tool the catalog no longer has.'
        );

        foreach ($decided as $name => [$destructive, $idempotent, $openWorld]) {
            self::assertSame(
                [
                    'destructiveHint' => $destructive,
                    'idempotentHint'  => $idempotent,
                    'openWorldHint'   => $openWorld,
                ],
                [
                    'destructiveHint' => $catalog[$name]['annotations']['destructiveHint'],
                    'idempotentHint'  => $catalog[$name]['annotations']['idempotentHint'],
                    'openWorldHint'   => $catalog[$name]['annotations']['openWorldHint'],
                ],
                "{$name}'s annotations changed. If that was deliberate, change this row"
                . ' and say why in the commit; see tools.php\'s header for the reasoning'
                . ' behind each judgement.'
            );
        }
    }

    /**
     * The registry's completeness check, in both directions, without a WordPress runtime.
     *
     * This is the function wpmcp_tools() rejects a filter-added tool on, so it is worth
     * asserting directly as well as through the integration tier: the integration test
     * proves it is WIRED, this proves it is RIGHT.
     *
     * @group sprint-5
     */
    public function testTheAnnotationCompletenessCheck(): void
    {
        $complete = [
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ];

        self::assertTrue(wpmcp_annotations_complete(['annotations' => $complete]));

        self::assertFalse(
            wpmcp_annotations_complete([]),
            'A tool with no annotations at all must be refused.'
        );
        self::assertFalse(
            wpmcp_annotations_complete(['annotations' => 'readOnlyHint']),
            'A non-array annotations member must be refused.'
        );

        foreach (array_keys($complete) as $missing) {
            $partial = $complete;
            unset($partial[$missing]);

            self::assertFalse(
                wpmcp_annotations_complete(['annotations' => $partial]),
                "A tool missing {$missing} was accepted. Absence of a declaration is not"
                . ' a declaration of safety.'
            );

            $stringy           = $complete;
            $stringy[$missing] = 'true';

            self::assertFalse(
                wpmcp_annotations_complete(['annotations' => $stringy]),
                "A tool whose {$missing} is the string \"true\" was accepted. Truthy but"
                . ' not boolean is the signature of an author who did not think about it.'
            );
        }
    }

    /**
     * No built-in description is long enough for a client to cut it.
     *
     * Clients cap a tool description, and a parameter description, at 1000 characters,
     * and they enforce the cap by TRUNCATING. A description that runs over does not
     * produce an error anywhere: it reaches the model as its own first 1000 characters,
     * missing whatever the author put at the end, which in this catalog is routinely the
     * part that matters (`force=false trashes; force=true permanently deletes`). Nothing
     * on the server side would notice.
     *
     * Today's longest is 267 characters, so this has 700-odd characters of headroom. It
     * goes red when somebody grows a description past the point a client keeps it.
     *
     * @group sprint-6
     */
    public function testNoDescriptionReachesTheClientCap(): void
    {
        $longest = 0;

        foreach (WireSerializationTest::catalog() as $name => $tool) {
            $length  = mb_strlen($tool['description']);
            $longest = max($longest, $length);

            self::assertLessThanOrEqual(
                WPMCP_MAX_DESCRIPTION,
                $length,
                "{$name}'s description is {$length} characters. A client truncates at "
                . WPMCP_MAX_DESCRIPTION . ' and says nothing, so the end of it would'
                . ' simply not reach the model.'
            );

            $properties = $tool['inputSchema']['properties'] ?? [];

            foreach ((array) self::asMap($properties) as $argument => $property) {
                $map = self::asMap($property);

                if ($map === null || !isset($map['description'])
                    || !is_string($map['description'])) {
                    continue;
                }

                $length  = mb_strlen($map['description']);
                $longest = max($longest, $length);

                self::assertLessThanOrEqual(
                    WPMCP_MAX_DESCRIPTION,
                    $length,
                    "The description of {$name}'s {$argument} argument is {$length}"
                    . ' characters, past the point a client keeps it.'
                );
            }
        }

        self::assertGreaterThan(
            0,
            $longest,
            'No description was measured at all, so this test proved nothing.'
        );
    }

    /**
     * EVERY listing query merges wpmcp_list_query_guards(), and this is a GREP on purpose.
     *
     * The three arguments in that array are the ones no caller may touch and every listing
     * query must carry - and two of them are invisible from outside:
     *
     *   ignore_sticky_posts  The integration tier proves it, with a stuck fixture: without
     *                        it a listing filtered only by after/before, status or orderby
     *                        is a HOME query, and core splices every sticky post into the
     *                        front of one with post_status => 'publish' and none of the
     *                        original conditions.
     *   update_post_meta_cache  NOTHING ELSE CHECKS THIS AT ALL. It is not observable on the
     *                        wire: the listing returns the same six fields either way. What
     *                        it changes is that the depth fetch - up to 10,001 rows a query
     *                        at the page cap - stops priming the postmeta cache for rows
     *                        whose meta nobody reads, which on a site carrying ACF or SEO
     *                        meta is the difference between slow and fatal. A behavioural
     *                        test would have to measure memory; this asserts the argument.
     *
     * The grep is what catches the failure the guards exist to prevent: a THIRD listing
     * query, added later, written without them. A behavioural test would only cover the two
     * queries that exist today.
     *
     * SCOPED TO wpmcp_core_tools(), and the reason is a measurement rather than laziness.
     * list-media also runs a WP_Query, it also passes no search term by default, and it is
     * therefore also a home query - but core scopes the sticky fetch to the QUERY'S OWN
     * post_type (`$post_type = $query_vars['post_type']`, class-wp-query.php:2012, used at
     * :3612), and list-media asks for `attachment` while a sticky post is a `post`, so the
     * splice returns nothing there. It must also KEEP the meta cache, because it returns
     * file URLs and `wp_get_attachment_url()` reads `_wp_attached_file`. Widening this grep
     * to the whole file would be demanding the wrong thing of it.
     *
     * The same scoping is why list-posts is only exposed on `post_type: "post"` - its
     * default, and so the ordinary call.
     *
     * @group sprint-10
     */
    public function testEveryListingQueryCarriesTheSharedQueryGuards(): void
    {
        $source = file_get_contents(WPMCP_PLUGIN_DIR . '/tools.php');

        self::assertIsString($source, 'tools.php could not be read, so this proves nothing.');

        $start = strpos($source, 'function wpmcp_core_tools()');
        self::assertNotFalse($start, 'wpmcp_core_tools() is gone from tools.php.');

        $end = strpos($source, "\nfunction wpmcp_content_tools()", $start);
        self::assertNotFalse($end, 'wpmcp_content_tools() no longer follows wpmcp_core_tools().');

        $source  = substr($source, $start, $end - $start);
        $queries = preg_match_all('/new WP_Query\(/', $source);
        $guarded = preg_match_all(
            '/new WP_Query\(\s*array_merge\(\s*\$filters,\s*wpmcp_list_query_guards\(\)/',
            $source
        );

        self::assertSame(2, $queries, 'list-posts is meant to run exactly two queries (KB 0.4).');
        self::assertSame(
            $queries,
            $guarded,
            "{$queries} WP_Query calls in wpmcp_core_tools(), {$guarded} of them merging"
            . ' wpmcp_list_query_guards(). A listing query written without the guards is a'
            . ' listing that answers a date window with posts outside it, and primes the'
            . ' postmeta cache for ten thousand rows nobody reads.'
        );

        // The one value nothing else in the suite can see. `true` for the other two is
        // asserted behaviourally: no_found_rows by the has_more paging tests,
        // ignore_sticky_posts by the stuck fixture in PostFilterReadsTest.
        self::assertFalse(
            wpmcp_list_query_guards()['update_post_meta_cache'],
            'The listing primes the postmeta cache again. Nothing on the wire changes when'
            . ' it does, which is exactly why it needs an assertion here.'
        );
    }

    /**
     * The first sentence of every description says what the tool does, in under 50
     * characters, starting with a verb.
     *
     * WHY 50. A client loads tools lazily, and until a tool is fully loaded the model is
     * shown roughly the first 50 characters of its description. That prefix is therefore
     * the whole of what the model knows when it decides whether this tool is the one it
     * wants. `Site name, URL, WordPress version, active theme, a` was the old site-info
     * and it arrives as a list of nouns with the verb cut off.
     *
     * The three banned openers are the ways a description wastes that prefix on
     * ceremony: `This tool lists...`, `Allows you to list...`, `Use this to list...`. The
     * verb goes first.
     *
     * Unlike the 1000-character cap, nothing is rejected at registration for this. It is
     * a rule about the catalog's prose, enforced on the catalog, and a filter-added tool
     * is its author's business.
     *
     * @group sprint-6
     */
    public function testEveryDescriptionOpensWithAShortVerbFirstSentence(): void
    {
        $ceremony = ['This', 'Allows', 'Use '];

        foreach (WireSerializationTest::catalog() as $name => $tool) {
            $description = $tool['description'];

            // A PERIOD FOLLOWED BY A SPACE OR THE END OF THE STRING, not any period.
            // The first version of this test looked for any '.' and code-delete passed on
            // the dot inside a file extension at index 44, while its real first sentence
            // ran to 63.
            $sentenceEnd = preg_match('/\.(\s|$)/', $description, $match, PREG_OFFSET_CAPTURE) === 1
                ? $match[0][1]
                : false;

            self::assertNotFalse(
                $sentenceEnd,
                "{$name}'s description has no sentence end in it at all, so a client"
                . ' showing a prefix has nothing to cut on.'
            );
            self::assertLessThan(
                50,
                $sentenceEnd,
                "{$name}'s first sentence ends at character {$sentenceEnd}. A client shows"
                . ' the model about the first 50 characters until the tool is fully'
                . " loaded, so the summary has to fit inside that. It reads: '"
                . substr($description, 0, 50) . "'"
            );

            foreach ($ceremony as $opener) {
                self::assertStringStartsNotWith(
                    $opener,
                    $description,
                    "{$name}'s description opens with '{$opener}', which spends the only"
                    . ' characters the model is guaranteed to see on ceremony. Start with'
                    . ' the verb.'
                );
            }
        }
    }

    /**
     * The length check itself, on both strings it reads.
     *
     * The test above says the catalog is inside the cap; this says the registry would
     * actually refuse an entry that is not. A filter-added tool is where a 4 KB
     * description comes from.
     *
     * @group sprint-6
     */
    public function testTheDescriptionLengthCheck(): void
    {
        $atTheLimit = str_repeat('a', WPMCP_MAX_DESCRIPTION);
        $overIt     = $atTheLimit . 'a';

        $tool = [
            'description' => $atTheLimit,
            'inputSchema' => [
                'type'       => 'object',
                'properties' => ['id' => ['type' => 'integer', 'description' => $atTheLimit]],
            ],
        ];

        self::assertTrue(
            wpmcp_descriptions_within_limit($tool),
            'Exactly at the limit is inside it; a client keeps 1000 characters.'
        );

        $longTool                = $tool;
        $longTool['description'] = $overIt;

        self::assertFalse(
            wpmcp_descriptions_within_limit($longTool),
            'A tool description one character over the cap was accepted.'
        );

        $longArgument = $tool;
        $longArgument['inputSchema']['properties']['id']['description'] = $overIt;

        self::assertFalse(
            wpmcp_descriptions_within_limit($longArgument),
            'A parameter description over the cap was accepted. The cap applies to every'
            . ' string a client shows the model, not only the tool-level one.'
        );

        self::assertTrue(
            wpmcp_descriptions_within_limit(['description' => 'short']),
            'A tool with no inputSchema at all must not be refused by this check.'
        );

        // The empty-object form the serializer produces for a no-argument tool.
        self::assertTrue(
            wpmcp_descriptions_within_limit([
                'description' => 'short',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
            ]),
            'A stdClass properties map must not trip the check.'
        );
    }

    /**
     * Every keyword used anywhere in a schema, pointer => keyword.
     *
     * Recurses through `properties` and `items` only, because those are the two positions
     * that hold sub-schemas in this dialect. A keyword hiding under something else would
     * not be enforced either, and would be flagged at its own level.
     *
     * @return array<string, string>
     */
    private static function keywordsIn($schema, string $pointer = ''): array
    {
        $map = self::asMap($schema);

        if ($map === null) {
            return [];
        }

        $found = [];

        foreach ($map as $keyword => $value) {
            $found[$pointer . '/' . $keyword] = (string) $keyword;
        }

        foreach ((array) self::asMap($map['properties'] ?? null) as $name => $sub) {
            $found = array_merge(
                $found,
                self::keywordsIn($sub, $pointer . '/properties/' . $name)
            );
        }

        if (isset($map['items'])) {
            $found = array_merge($found, self::keywordsIn($map['items'], $pointer . '/items'));
        }

        return $found;
    }

    /**
     * Every `type` value used anywhere in a schema, pointer => type.
     *
     * @return array<string, string>
     */
    private static function typesIn($schema, string $pointer = ''): array
    {
        $map = self::asMap($schema);

        if ($map === null) {
            return [];
        }

        $found = [];

        if (isset($map['type']) && is_string($map['type'])) {
            $found[$pointer . '/type'] = $map['type'];
        }

        foreach ((array) self::asMap($map['properties'] ?? null) as $name => $sub) {
            $found = array_merge($found, self::typesIn($sub, $pointer . '/properties/' . $name));
        }

        if (isset($map['items'])) {
            $found = array_merge($found, self::typesIn($map['items'], $pointer . '/items'));
        }

        return $found;
    }

    /** @return array<string, mixed>|null */
    private static function asMap($schema): ?array
    {
        if (is_object($schema)) {
            return (array) $schema;
        }

        return is_array($schema) ? $schema : null;
    }
}
