<?php
/**
 * SPRINT DELETIONS: the two places where a hand-rolled mechanism was replaced by core's, proved
 * against REAL core rather than a stub.
 *
 * WHY BOTH OF THESE HAVE TO BE INTEGRATION TESTS. Each claim is about what a WordPress function
 * that ships in wp-includes actually does - `WP_Comment_Query::get_search_sql()` and
 * `wp_get_admin_notice()` - and a stub of either would only assert what this file already
 * believes. The first runs a real tool call over HTTP against a real database; the second calls
 * core's own function in the site's own PHP and reads back what it produced.
 *
 * THE FIXTURES ARE NON-EMPTY AND EACH ASSERTION HAS A VISIBLE POSITIVE. Twice in recent sprints
 * a row asserted emptiness over a collection whose loop never ran, so every "must not match"
 * below is paired with a "must match" over the SAME fixture set: if the three comments were
 * absent the positive assertions would fail first and the negative ones could not go green on
 * nothing.
 *
 * WHAT THESE CANNOT CATCH:
 *   - They do not prove the generated SQL is byte-identical to the clause it replaced; they prove
 *     the three observable outcomes (content matches, author name matches, email does not) and
 *     that LIKE wildcards are still escaped. A rewrite that produced different SQL with the same
 *     four outcomes would pass.
 *   - The notice test proves core does not escape. It does not prove admin.php escapes: that is
 *     a source assertion in tests/unit/CoreFixTest.php, which owns the once-and-at-output rule.
 *
 * @group sprint-deletions
 */

declare(strict_types=1);

namespace WpMcp\Tests\Integration;

use RuntimeException;
use WpMcp\Tests\Support\Fixtures;
use WpMcp\Tests\Support\FixtureIntegrationTestCase;
use WpMcp\Tests\Support\RepoFile;
use WpMcp\Tests\Support\WpCli;

final class PlatformDeletionsTest extends FixtureIntegrationTestCase
{
    private static function label(): string { return Fixtures::name('deletions'); }
    private static function login(): string { return Fixtures::name('deletions-admin'); }
    private static function postTitle(): string { return Fixtures::name('deletions-post'); }

    /** The comment whose CONTENT carries the search term. */
    private static function byContentText(): string { return Fixtures::name('needle-in-content'); }

    /** The comment whose AUTHOR NAME carries it; its content deliberately does not. */
    private static function byAuthorText(): string { return Fixtures::name('body-without-the-needle'); }
    private static function authorName(): string { return Fixtures::name('needle-in-author'); }

    /**
     * The comment whose content holds a LITERAL UNDERSCORE, which is what gives the
     * wildcard-escaping assertion a positive side: searching "_" must return THIS comment and
     * only this comment. Without it the assertion would be "an empty result", which passes
     * whether esc_like() ran or the fixture was simply missing.
     *
     * AN UNDERSCORE RATHER THAN A PER-CENT SIGN, and the reason is the harness: a `%` in a
     * wp-cli argument on Windows is a character cmd.exe reads, and a fixture that fails to be
     * created is indistinguishable here from a clause that matches nothing. `_` is MySQL's
     * single-character wildcard, so it discriminates exactly as well - unescaped, `LIKE '%_%'`
     * matches every comment with at least one character in it - and it survives every shell.
     * No other fixture name can contain one: the prefix is `wpmcp-test-<8 hex digits>-`.
     */
    private static function underscoreText(): string { return Fixtures::name('literal_underscore'); }

    /** On the by-content comment. Never returned by any tool; used to probe `search`. */
    private static function commenterEmail(): string
    {
        return Fixtures::name('deletions-commenter') . '@example.invalid';
    }

    private static int $userId = 0;
    private static int $postId = 0;
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

        self::$userId = Fixtures::createUser(self::login(), 'administrator');
        self::$token  = Fixtures::mintToken('admin', self::label(), self::$userId);
        self::$postId = Fixtures::createPost(
            self::postTitle(),
            'publish',
            self::$userId,
            'A post that exists to hold three comments.',
            'post',
            0,
            'open'
        );

        // All three APPROVED, so list-comments' status default cannot be what separates them -
        // only the search clause can be.
        Fixtures::createComment(self::$postId, self::byContentText(), true, self::commenterEmail());
        Fixtures::createComment(self::$postId, self::byAuthorText(), true, '', self::authorName());
        Fixtures::createComment(self::$postId, self::underscoreText(), true);
    }

    public static function tearDownAfterClass(): void
    {
        self::destroy();

        parent::tearDownAfterClass();
    }

    private static function destroy(): void
    {
        Fixtures::deleteTokensLabelled(self::label());
        Fixtures::deletePost(self::$postId);
        Fixtures::deleteUser(self::$userId);
        Fixtures::purge();
    }

    /** Every `content` value list-comments returned for this search, on this post. */
    private function searchContents(string $term): array
    {
        $result = $this->mcp(self::$token)->callTool('list-comments', [
            'search' => $term,
            'post'   => self::$postId,
            'limit'  => 100,
        ]);

        self::assertFalse($result->isError, 'list-comments failed: ' . $result->text);

        return $result->column('content');
    }

    /**
     * ITEM 4. The search still covers comment_content and comment_author and still does NOT
     * cover comment_author_email, now that the clause is core's own builder.
     *
     * THE NARROWING IS THE SECURITY PROPERTY, not a nicety. `WP_Comment_Query`'s `search` query
     * var hard-codes five columns including comment_author_email and comment_author_IP, and a
     * tool that advertises "emails omitted" while letting a caller confirm an address one prefix
     * at a time does not omit them. `get_search_sql($search, $columns)` takes the column list as
     * a parameter, so the narrowing survives the swap - and this test is what says so.
     *
     * THE THIRD COMMENT IS THE CONTROL: it matches none of the three searches, so a clause that
     * had quietly become "match everything" fails here rather than passing three times.
     *
     * @group sprint-deletions
     */
    public function testCommentSearchCoversContentAndAuthorNameButNotAuthorEmail(): void
    {
        $needle = Fixtures::name('needle');

        // comment_content: the first comment only.
        self::assertSame(
            [self::byContentText()],
            array_values(array_filter(
                $this->searchContents(self::byContentText()),
                static fn ($c) => $c !== null
            )),
            'Searching comment content no longer finds the comment whose content holds the term.'
        );

        // Both columns at once: the term is in comment 1's CONTENT and comment 2's AUTHOR, and
        // in neither part of comment 3. Two of three is what proves both columns are in the
        // clause AND that the clause still filters.
        $both = $this->searchContents($needle);
        sort($both);
        $expected = [self::byAuthorText(), self::byContentText()];
        sort($expected);
        self::assertSame(
            $expected,
            $both,
            'A search on a term that is in one comment\'s content and another\'s author name did'
            . ' not return exactly those two. comment_author has dropped out of the clause, or'
            . ' the clause stopped filtering.'
        );

        // comment_author_email: nothing. The address is on comment 1, which the caller CAN
        // read, so the column list is the only thing that can hide it.
        self::assertSame(
            [],
            $this->searchContents(Fixtures::name('deletions-commenter') . '@'),
            'A search on an author email matched, so emails can be probed one prefix at a time'
            . ' despite the tool saying they are omitted.'
        );
    }

    /**
     * ITEM 4, the part the swap moved into core: a LIKE wildcard in the search term is ESCAPED.
     *
     * `get_search_sql()` runs `$wpdb->esc_like()` itself, which is the whole reason calling it is
     * worth anything - the escaping is no longer ours to remember. Three comments are in range,
     * one of which literally contains `_`: escaped, the search returns that one; unescaped,
     * `LIKE '%_%'` returns all three.
     *
     * @group sprint-deletions
     */
    public function testALikeWildcardInTheSearchTermIsEscapedAndMatchesLiterally(): void
    {
        self::assertSame(
            [self::underscoreText()],
            $this->searchContents('_'),
            'Searching for "_" did not return exactly the one comment containing a literal'
            . ' underscore. An empty result means the fixture is missing; all three means the'
            . ' wildcard reached the LIKE unescaped and the search now matches everything.'
        );
    }

    /**
     * ITEM 4, round 2: `__call()` ANSWERS `false`, so the clause can VANISH - and it must not
     * vanish silently.
     *
     * THIS IS THE PROJECT'S MOST-REPEATED DEFECT CLASS. `WP_Comment_Query::__call()` returns
     * `false` for every name but `get_search_sql`, and `false` concatenates to the empty string -
     * so a rename, a visibility change, or the proxy's removal would leave list-comments answering
     * with EVERY comment the caller may read, looking exactly like a search that matched them all.
     * An instrument that answers with nothing, where nothing reads as success.
     *
     * TWO HALVES, AND NEITHER IS A SOURCE ASSERTION ABOUT THE OTHER. The first EXECUTES core's
     * proxy with a name it does not handle and asserts the answer really is `false` - so if core
     * ever starts throwing instead, this goes red and the guard in tools.php can be simplified
     * rather than quietly doing nothing. The second asserts that tools.php refuses on a non-string
     * instead of concatenating it, which is the only half a unit-tier pattern could reach.
     *
     * WHAT THIS CANNOT CATCH: the live rename itself. A test cannot rename a core method, so the
     * end-to-end proof - that the rename produces a traced `-32603` refusal rather than an
     * unfiltered list - is a mutation run, recorded in analysis/86. What IS permanent is that the
     * failure mode is real (half one) and that we check for it (half two).
     *
     * @group sprint-deletions
     */
    public function testAVanishedSearchClauseIsRefusedRatherThanAnsweredWithEveryComment(): void
    {
        $answer = WpCli::evaluate(
            '$q = new WP_Comment_Query();'
            . ' echo "\n", wp_json_encode(array('
            . '   "unknown" => $q->get_search_sql_that_core_does_not_have("x", array("c")),'
            . '   "known"   => $q->get_search_sql("x", array("c")),'
            . ' ));'
        );

        $lines = preg_split('/\r?\n/', trim($answer));
        $got   = json_decode((string) end($lines), true);

        if (!is_array($got) || !array_key_exists('unknown', $got)) {
            throw new RuntimeException('Could not exercise __call(): ' . substr($answer, 0, 300));
        }

        self::assertFalse(
            $got['unknown'],
            'WP_Comment_Query::__call() no longer answers false for a name it does not handle, so'
            . ' the non-string guard in tools.php is now guarding against something else. Read what'
            . ' it does answer and decide whether the guard should throw or be removed.'
        );

        // The POSITIVE side, over the same object: the name we DO use answers a string. Without
        // this the assertion above would pass on a proxy that answered false to everything -
        // including our call - which is the failure the guard exists for.
        self::assertIsString(
            $got['known'],
            'The name list-comments actually calls no longer answers a string either, so the search'
            . ' clause is vanishing on every request right now.'
        );

        // And ours refuses rather than concatenating. `$clauses['where'] .= false` is the silent
        // path; the guard has to sit between the call and the concatenation, which is why both
        // tokens are asserted and not just the is_string().
        $tools = RepoFile::read('tools.php');

        self::assertStringContainsString('if (!is_string($sql)) {', $tools);
        self::assertStringContainsString("\$clauses['where'] .= \$sql;", $tools);
        self::assertStringNotContainsString(
            "\$clauses['where'] .= (new WP_Comment_Query())",
            $tools,
            'The search clause is concatenated straight off the __call() proxy again, so a `false`'
            . ' would append nothing and list-comments would answer every comment to a search.'
        );
    }

    /**
     * ITEM 5. `wp_get_admin_notice()` interpolates the MESSAGE RAW, so admin.php's `esc_html()`
     * is still required and removing it would be an injection.
     *
     * THIS IS THE TRAP THE SPRINT WAS WARNED ABOUT, INVERTED. Sprint CORE-FIX removed a DOUBLE
     * escape from the mint-failure branch; the obvious next move on adopting `wp_admin_notice()`
     * is to assume core escapes and drop ours, which would turn an operator-visible error
     * message into markup. Core does not escape: `wp_get_admin_notice()` builds
     * `"<p>$message</p>"` (wp-includes/functions.php) and its `wp_admin_notice_markup` filter
     * receives it already interpolated.
     *
     * If core ever starts escaping, THIS TEST GOES RED and the fix is to delete our esc_html()
     * - which is exactly the signal a future author needs and cannot get from our own source.
     *
     * @group sprint-deletions
     */
    public function testCoreAdminNoticeDoesNotEscapeTheMessageSoOursMust(): void
    {
        $marker = Fixtures::name('notice');
        $out    = WpCli::evaluate(sprintf(
            'echo "\n", base64_encode(wp_get_admin_notice("<b>" . %s . "</b> & \'q\'",'
            . ' array("type" => "info", "dismissible" => true)));',
            var_export($marker, true)
        ));

        $lines  = preg_split('/\r?\n/', trim($out));
        $markup = (string) base64_decode((string) end($lines), true);

        if ($markup === '' || !str_contains($markup, $marker)) {
            throw new RuntimeException(
                'wp_get_admin_notice() produced nothing usable: ' . substr($out, 0, 300)
            );
        }

        self::assertStringContainsString(
            '<b>' . $marker . '</b> & \'q\'',
            $markup,
            'wp_get_admin_notice() now escapes the message it is handed. admin.php escapes it'
            . ' too, so the operator is reading &lt;b&gt; and &#039; - delete the esc_html()'
            . ' around $notice at the wp_admin_notice() call and update this assertion.'
        );

        // The classes admin.php asks for, assembled by core rather than typed out. A positive
        // assertion on each, so a `type` or `dismissible` that stopped being honoured is red.
        self::assertStringContainsString('class="notice notice-info is-dismissible"', $markup);
    }
}
