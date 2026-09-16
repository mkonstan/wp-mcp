<?php
/**
 * Sprint 10 - "find things". Every list-posts filter, and every new get-post field,
 * against a real site with real roles.
 *
 * THE ONE RULE THESE TESTS EXIST TO PIN. A filter is a way of asking a question about
 * content, and the answer to a question about content the caller may not see has to be
 * the SAME answer as the question about content that does not exist: an empty list.
 * Not an error, not a count, not a "0 of 3". Every filter below therefore lands in a
 * pair - one test that it finds the seeded post and excludes the others, and one that
 * an Author pointing it at the hidden user's private post or draft gets nothing back
 * and no error. A filter with only the first half is a filter nobody has checked.
 *
 * ONLY MALFORMED ARGUMENT SHAPES ERROR. A date that is not a date and an orderby that
 * is not one of three words are the caller's own mistakes about this protocol; they
 * disclose nothing, and an agent answered with an empty list instead would conclude the
 * site is empty and stop looking.
 *
 * THE FIXTURE SET, and why it is shaped this way:
 *
 *   category `set`  is on the five ordering/paging posts and nothing else. The stress
 *                   site is a clone of a real client's database with thousands of
 *                   posts in it, so every count assertion here is scoped by a term that
 *                   was created seconds earlier and cannot hold anything else.
 *   the HIDDEN user owns ONE private post and ONE draft and nothing published. That is
 *                   what makes "an Author filtering by another author's name gets
 *                   nothing" assertable: the EDITOR also owns a published post, so
 *                   filtering by the editor correctly returns something, and using them
 *                   for the negative would have proved the opposite of what it looked
 *                   like.
 *   `dd-delta`      is the Author's OWN draft, inside `set`. It comes from the second,
 *                   author-scoped query, so every ordering and paging assertion here is
 *                   an assertion about the MERGE and not about one WP_Query.
 *   the marker      is in the content of all five, so `search` can select exactly the
 *                   same set as `category` and the two can be compared.
 *
 * THE THREE ORDERINGS ARE DELIBERATELY UNCORRELATED, and the first version of this file
 * got that wrong. The five posts were seeded aa..ee in ascending date order, so
 * `orderby: "title", order: "asc"` and `orderby: "date", order: "asc"` returned the same
 * five ids and every ordering assertion held for either column. A mutation that handed
 * WP_Query the wrong column went undetected. The dates now run
 * cc < ee < aa < dd < bb while the titles run aa < bb < cc < dd < ee, and
 * post_modified runs aa < dd < bb < ee < cc - three different permutations of the same
 * five posts, so an assertion on one of them cannot be satisfied by another.
 *
 * post_modified is WRITTEN, not produced by touching posts in an order: see build(). Two
 * rewrites in the same second tie, and a tie is decided by the ID tie-break rather than by
 * the order the fixture intended.
 *
 * Every fixture is named `wpmcp-test-<run id>-*` and removed in tearDownAfterClass.
 *
 * @group sprint-10
 */

declare(strict_types=1);

namespace WpMcp\Tests\Integration;

use WpMcp\Tests\Support\Fixtures;
use WpMcp\Tests\Support\FixtureIntegrationTestCase;
use WpMcp\Tests\Support\MuPlugin;
use WpMcp\Tests\Support\ToolResult;

final class PostFilterReadsTest extends FixtureIntegrationTestCase
{
    private static function label(): string { return Fixtures::name('find'); }

    /** In the content of all five `set` posts, and of nothing else on the site. */
    private static function marker(): string { return Fixtures::name('marker'); }

    /** In `aa-alpha`'s content alone. */
    private static function needle(): string { return Fixtures::name('needle'); }

    /** In the HIDDEN user's private post alone. An Author must never match it. */
    private static function secret(): string { return Fixtures::name('secret'); }

    private static function excerptText(): string { return Fixtures::name('excerpt-text'); }

    private static function displayName(): string { return Fixtures::name('display-name'); }

    private static function attachedFile(): string { return Fixtures::name('featured') . '.png'; }

    /** An id far past anything the site could hold. */
    private const MISSING_ID = 999999999;

    /**
     * The mu-plugin that registers a PRIVATE taxonomy on `post` for the length of this
     * run, so is_taxonomy_viewable() has something real to refuse.
     *
     * WITHOUT THIS THE VIEWABILITY CHECK IS NOT TESTABLE. Core's only non-viewable
     * taxonomies - nav_menu, link_category, wp_theme - are attached to post types that
     * are not `post`, so a post listing filtered by one of their terms comes back empty
     * whether the check exists or not: the first version of this test pointed at
     * nav_menu and passed with the check deleted. A private taxonomy WITH A PUBLISHED
     * POST IN IT is the only shape where WP_Query would happily return something and
     * the allow-list is the only thing stopping it.
     */
    private const HIDDEN_TAX_MU = 'hidden-tax';

    /** 22 characters, inside register_taxonomy()'s 32-character limit. */
    private static function hiddenTaxonomy(): string { return Fixtures::name('tx'); }

    private static function hiddenTermName(): string { return Fixtures::name('hidden-term'); }

    private static int $editorId = 0;
    private static int $authorId = 0;
    private static int $hiddenId = 0;
    private static int $subId    = 0;

    private static int $catSet    = 0;
    private static int $catA      = 0;
    private static int $catSecret = 0;
    private static int $catDraft  = 0;
    private static int $tagA      = 0;
    private static int $tagB      = 0;
    private static int $navMenu   = 0;
    private static int $hiddenTerm = 0;
    private static int $catTie    = 0;

    /** Two published posts sharing a post_date to the second, in a category of their own. */
    private static int $tieOne = 0;
    private static int $tieTwo = 0;

    // date:     cc < ee < aa < dd < bb        title:    aa < bb < cc < dd < ee
    // modified: aa < dd < bb < ee < cc        (cc is rewritten last, ee twice)
    private static int $alpha   = 0;   // aa, publish, AUTHOR,  2021-07-06
    private static int $bravo   = 0;   // bb, publish, EDITOR,  2022-01-12
    private static int $charlie = 0;   // cc, publish, EDITOR,  2021-03-02
    private static int $delta   = 0;   // dd, DRAFT,   AUTHOR,  2021-09-08  <- the merge
    private static int $echo    = 0;   // ee, publish, AUTHOR,  2021-05-04
    private static int $hiddenPrivate = 0;
    private static int $hiddenDraft   = 0;
    private static int $page          = 0;
    private static int $attachment    = 0;

    /**
     * True once cc-charlie has been stuck, so teardown only unsticks what it stuck.
     *
     * `sticky_posts` IS A SHARED OPTION WITH NO RUN PREFIX ON IT - the same class of thing
     * as `wpmcp_db_ver`, and the one fixture here a killed process could leave behind, since
     * purge() matches names and an option holding ids has none. Two concurrent runs are
     * nonetheless safe: stick_post() appends one id and unstick_post() removes that one id,
     * and the two runs' ids differ. A stale id left in it points at a deleted post and is
     * inert in WordPress, but it is still debris, so destroy() unsticks BEFORE it deletes.
     */
    private static bool $stuck = false;

    private static string $authorToken = '';
    private static string $subToken    = '';
    private static string $editorToken = '';
    private static string $adminToken  = '';

    /** @var list<string> */
    private static array $postTaxonomies = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::requireSite();

        self::buildFixtures(self::build(...), self::destroy(...));
    }

    private static function build(): void
    {
        Fixtures::purge();

        self::$editorId = Fixtures::createUser(Fixtures::name('editor'), 'editor');
        self::$authorId = Fixtures::createUser(Fixtures::name('author'), 'author');
        self::$hiddenId = Fixtures::createUser(Fixtures::name('hidden'), 'author');
        self::$subId    = Fixtures::createUser(Fixtures::name('sub'), 'subscriber');

        // `wp user create` leaves display_name equal to the login, so without this the
        // "get-post returns a display name and NOT a login" assertion would compare two
        // identical strings and pass for the wrong reason.
        Fixtures::setDisplayName(self::$authorId, self::displayName());

        self::$catSet    = Fixtures::createTerm('category', Fixtures::name('set'), Fixtures::name('set'));
        self::$catA      = Fixtures::createTerm('category', Fixtures::name('cat-a'), Fixtures::name('cat-a'));
        self::$catSecret = Fixtures::createTerm('category', Fixtures::name('cat-secret'), Fixtures::name('cat-secret'));
        self::$catDraft  = Fixtures::createTerm('category', Fixtures::name('cat-draft'), Fixtures::name('cat-draft'));
        self::$tagA      = Fixtures::createTerm('post_tag', Fixtures::name('tag-a'), Fixtures::name('tag-a'));
        self::$tagB      = Fixtures::createTerm('post_tag', Fixtures::name('tag-b'), Fixtures::name('tag-b'));
        self::$catTie    = Fixtures::createTerm('category', Fixtures::name('cat-tie'), Fixtures::name('cat-tie'));
        // A term in a taxonomy is_taxonomy_viewable() refuses. nav_menu is registered
        // by core with public => false on every site, so this case does not depend on
        // the stress site's plugins.
        self::$navMenu = Fixtures::createTerm('nav_menu', Fixtures::name('menu'), Fixtures::name('menu'));

        // A PRIVATE TAXONOMY ON `post`, registered by a mu-plugin for this run only and
        // inert the moment the run's armed transient expires. Its term goes on aa-alpha,
        // a PUBLISHED post every token here may read - so a listing filtered by it is
        // empty because the taxonomy is not viewable, and for no other reason.
        MuPlugin::drop(self::HIDDEN_TAX_MU, self::hiddenTaxonomySource());
        self::$hiddenTerm = Fixtures::createTerm(
            self::hiddenTaxonomy(),
            self::hiddenTermName(),
            self::hiddenTermName()
        );

        self::$alpha = Fixtures::createPostWith([
            'post_title'   => Fixtures::name('aa-alpha'),
            'post_status'  => 'publish',
            'post_author'  => self::$authorId,
            'post_content' => self::marker() . ' ' . self::needle(),
            'post_date'    => '2021-07-06 10:00:00',
        ]);
        self::$bravo = Fixtures::createPostWith([
            'post_title'   => Fixtures::name('bb-bravo'),
            'post_status'  => 'publish',
            'post_author'  => self::$editorId,
            'post_content' => self::marker(),
            'post_date'    => '2022-01-12 10:00:00',
        ]);
        self::$charlie = Fixtures::createPostWith([
            'post_title'   => Fixtures::name('cc-charlie'),
            'post_status'  => 'publish',
            'post_author'  => self::$editorId,
            'post_content' => self::marker(),
            'post_date'    => '2021-03-02 10:00:00',
        ]);
        // The Author's OWN draft. Fourth by title, second by date, and - after the
        // content rewrite at the bottom of this method - first by modified.
        self::$delta = Fixtures::createPostWith([
            'post_title'   => Fixtures::name('dd-delta'),
            'post_status'  => 'draft',
            'post_author'  => self::$authorId,
            'post_content' => self::marker(),
            'post_date'    => '2021-09-08 10:00:00',
        ]);
        self::$echo = Fixtures::createPostWith([
            'post_title'   => Fixtures::name('ee-echo'),
            'post_status'  => 'publish',
            'post_author'  => self::$authorId,
            'post_content' => self::marker(),
            'post_excerpt' => self::excerptText(),
            'post_date'    => '2021-05-04 10:00:00',
        ]);

        foreach ([self::$alpha, self::$bravo, self::$charlie, self::$delta, self::$echo] as $id) {
            Fixtures::setPostTerms($id, 'category', [self::$catSet]);
        }
        Fixtures::setPostTerms(self::$alpha, 'category', [self::$catSet, self::$catA]);
        Fixtures::setPostTerms(self::$echo, 'category', [self::$catSet, self::$catA]);
        Fixtures::setPostTerms(self::$alpha, 'post_tag', [self::$tagA]);
        Fixtures::setPostTerms(self::$echo, 'post_tag', [self::$tagA]);
        Fixtures::setPostTerms(self::$alpha, self::hiddenTaxonomy(), [self::$hiddenTerm]);

        // THE HIDDEN USER: one private post, one draft, nothing published. Its category
        // and its tag hold nothing else, so an Author filtering on either has to come
        // back empty - and an admin filtering on either has to come back with one, or
        // the "empty" above would be proving that the filter is broken.
        self::$hiddenPrivate = Fixtures::createPostWith([
            'post_title'   => Fixtures::name('hidden-private'),
            'post_status'  => 'private',
            'post_author'  => self::$hiddenId,
            'post_content' => self::secret(),
            'post_date'    => '2021-02-01 09:00:00',
        ]);
        self::$hiddenDraft = Fixtures::createPostWith([
            'post_title'   => Fixtures::name('hidden-draft'),
            'post_status'  => 'draft',
            'post_author'  => self::$hiddenId,
            'post_content' => Fixtures::name('hidden-draft-body'),
            'post_date'    => '2021-02-02 09:00:00',
        ]);
        Fixtures::setPostTerms(self::$hiddenPrivate, 'category', [self::$catSecret]);
        Fixtures::setPostTerms(self::$hiddenPrivate, 'post_tag', [self::$tagB]);
        Fixtures::setPostTerms(self::$hiddenDraft, 'category', [self::$catDraft]);

        // TWO POSTS TIED ON post_date, TO THE SECOND, in a category nothing else is in.
        // Ordinary data: an import, or anything scripted, shares a second routinely. They are
        // the EDITOR's so that no `author`-filtered assertion elsewhere has to know about
        // them, and their content carries no marker so no `search` assertion does either.
        self::$tieOne = Fixtures::createPostWith([
            'post_title'   => Fixtures::name('gg-tie-one'),
            'post_status'  => 'publish',
            'post_author'  => self::$editorId,
            'post_content' => Fixtures::name('tie-body'),
            'post_date'    => '2019-04-05 12:00:00',
        ]);
        self::$tieTwo = Fixtures::createPostWith([
            'post_title'   => Fixtures::name('hh-tie-two'),
            'post_status'  => 'publish',
            'post_author'  => self::$editorId,
            'post_content' => Fixtures::name('tie-body'),
            'post_date'    => '2019-04-05 12:00:00',
        ]);
        Fixtures::setPostTerms(self::$tieOne, 'category', [self::$catTie]);
        Fixtures::setPostTerms(self::$tieTwo, 'category', [self::$catTie]);

        // A PAGE, for the taxonomy-not-attached-to-this-post-type case. Measured: WP_Query
        // does NOT ignore `cat` on a post type with no category taxonomy - it builds the
        // term_relationships join anyway and returns nothing - so the allow-list is belt and
        // braces there. What this fixture catches is the OTHER shape: a filter that cannot be
        // resolved being silently DROPPED, which answers a question about one category with
        // every page on the site.
        self::$page = Fixtures::createPostWith([
            'post_title'   => Fixtures::name('ff-page'),
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_author'  => self::$authorId,
            'post_content' => Fixtures::name('page-body'),
            'post_date'    => '2021-06-01 10:00:00',
        ]);

        // THE FEATURED IMAGE. An attachment row with `_wp_attached_file` pointing at a
        // name that is never written to disk: wp_get_attachment_url() reads the meta and
        // builds the URL from it without touching the filesystem, so the assertion is
        // real and nothing lands in anybody's uploads directory.
        self::$attachment = Fixtures::createPost(
            Fixtures::name('featured-image'),
            'inherit',
            self::$authorId,
            Fixtures::name('featured-body'),
            'attachment',
            self::$echo
        );
        Fixtures::setPostMeta(self::$attachment, '_wp_attached_file', self::attachedFile());
        Fixtures::setPostMeta(self::$echo, '_thumbnail_id', (string) self::$attachment);

        // TWO REVISIONS on ee-echo: wp_update_post stores the pre-update state whenever
        // the content changes, so two rewrites leave two revisions.
        Fixtures::updatePostContent(self::$echo, self::marker() . ' ' . Fixtures::name('rev-1'));
        Fixtures::updatePostContent(self::$echo, self::marker() . ' ' . Fixtures::name('rev-2'));

        // cc-charlie is rewritten too, so it has a revision of its own and is the most
        // recently modified post in `set` while being the OLDEST by post_date.
        //
        // THE DRAFT IS DELIBERATELY NOT TOUCHED. Measured on WP 7.1: wp_update_post on a
        // date-floating post with post_date_gmt = '0000-00-00 00:00:00' takes core's
        // "drafts shouldn't be assigned a date" branch, which RESETS post_date to now -
        // and the same update populates post_modified_gmt with a real date while
        // post_date_gmt stays zero. Rewriting the draft here would therefore have moved
        // it to the top of every date ordering and quietly destroyed the GMT-null case
        // two tests below.
        Fixtures::updatePostContent(self::$charlie, self::marker() . ' ' . Fixtures::name('touched'));

        // AND THEN post_modified IS STATED, NOT RACED. Leaving it to the touch order means
        // leaving it to the CLOCK: wp_update_post() writes current_time('mysql') to the
        // second, so "charlie was touched after echo" only produces a different
        // post_modified if the two wp-cli calls land in different seconds. On the stress
        // site they do; on the bare one they do not, echo and charlie TIE, and the ID
        // tie-break - correctly - puts the higher id first, which is not the order these
        // expectations were written for. That is the same class of bug the tie-break exists
        // to fix, found in this file's own fixture, and the fix is to say what the data is.
        //
        // post_modified_gmt is left alone throughout: dd-delta's is '0000-00-00 00:00:00'
        // and get-post maps that to null, which a later test asserts.
        foreach ([
            [self::$alpha, '2021-07-06 10:00:00'],
            [self::$delta, '2021-09-08 10:00:00'],
            [self::$bravo, '2022-01-12 10:00:00'],
            [self::$echo, '2026-01-01 10:00:00'],
            [self::$charlie, '2026-01-02 10:00:00'],
        ] as [$id, $modified]) {
            Fixtures::setPostModified($id, $modified);
        }

        // STICK cc-charlie. A sticky post is spliced into the front of any query WP_Query
        // considers a home query - which is any listing filtered only by after/before, status
        // or orderby, because none of those set a query flag - with `post_status => 'publish'`
        // and none of the original query's conditions. Nothing in the fixture set was sticky
        // before, so all thirty tests were blind to it.
        Fixtures::stickPost(self::$charlie);
        self::$stuck = true;

        self::$postTaxonomies = Fixtures::viewableTaxonomies('post');

        self::$authorToken = Fixtures::mintToken('read', self::label(), self::$authorId);
        self::$subToken    = Fixtures::mintToken('read', self::label(), self::$subId);
        // A REAL EDITOR, not the administrator standing in for one. An Editor holds
        // read_private_posts and edit_others_posts and an Author holds neither, which is
        // the whole of why the two see different things - but "an Editor holds them" is a
        // claim about core's role map, and the capability sweep in the commit message is
        // supposed to report what was measured rather than what was reasoned.
        self::$editorToken = Fixtures::mintToken('read', self::label(), self::$editorId);
        self::$adminToken  = Fixtures::mintToken('read', self::label(), 1);
    }

    public static function tearDownAfterClass(): void
    {
        self::destroy();

        parent::tearDownAfterClass();
    }

    /**
     * The mu-plugin body. MuPlugin::drop() wraps this in a `muplugins_loaded` guard that
     * returns unless this run is armed, so the taxonomy exists only while the run is
     * live - and `init` is where register_taxonomy() has to be called from.
     */
    private static function hiddenTaxonomySource(): string
    {
        $name = var_export(self::hiddenTaxonomy(), true);

        return "add_action('init', static function () {\n"
            . "    register_taxonomy({$name}, array('post'), array(\n"
            . "        'public'             => false,\n"
            . "        'publicly_queryable' => false,\n"
            . "        'show_ui'            => false,\n"
            . "        'show_in_rest'       => false,\n"
            . "        'hierarchical'       => false,\n"
            . "        'label'              => {$name},\n"
            . "    ));\n"
            . "}, 5);\n";
    }

    private static function destroy(): void
    {
        // BEFORE the posts are deleted: unsticking reads nothing but the id, yet leaving it
        // until after the delete would mean the id is all that is left to go on.
        if (self::$stuck) {
            Fixtures::unstickPost(self::$charlie);
            self::$stuck = false;
        }

        // The hidden term goes FIRST, while its taxonomy is still registered: purge()
        // walks get_taxonomies(), so once the mu-plugin is gone the term is invisible to
        // every cleanup path there is.
        Fixtures::deleteTerm(self::hiddenTaxonomy(), self::$hiddenTerm);
        MuPlugin::remove(self::HIDDEN_TAX_MU);

        foreach ([
            self::$attachment, self::$alpha, self::$bravo, self::$charlie, self::$delta,
            self::$echo, self::$hiddenPrivate, self::$hiddenDraft, self::$page,
            self::$tieOne, self::$tieTwo,
        ] as $id) {
            Fixtures::deletePost($id);
        }

        foreach ([self::$catSet, self::$catA, self::$catSecret, self::$catDraft, self::$catTie] as $id) {
            Fixtures::deleteTerm('category', $id);
        }
        Fixtures::deleteTerm('post_tag', self::$tagA);
        Fixtures::deleteTerm('post_tag', self::$tagB);
        Fixtures::deleteTerm('nav_menu', self::$navMenu);

        foreach ([self::$editorId, self::$authorId, self::$hiddenId, self::$subId] as $id) {
            Fixtures::deleteUser($id);
        }

        Fixtures::deleteTokensLabelled(self::label());
        Fixtures::purge();
    }

    /* ========================================================================
     * Helpers
     * ==================================================================== */

    /** The fixture titles of a listing, in the order it returned them. */
    private function titles(ToolResult $result): array
    {
        return array_map(
            static fn ($t) => str_replace(Fixtures::runPrefix(), '', (string) $t),
            $result->column('title')
        );
    }

    /** A copy of $ids, sorted ascending - for "the same set, whatever the order". */
    private function sorted(array $ids): array
    {
        sort($ids);

        return array_values($ids);
    }

    /** list-posts as one token, asserting only that it did not fail. */
    /**
     * B-DATE. list-posts offers orderby: date, so its items carry date and modified, in
     * get-post's ISO convention and with get-post's values.
     *
     * @group sprint-10
     */
    public function testListPostsItemsCarryTheDateAndModifiedGetPostReports(): void
    {
        $listed = $this->listing(self::$adminToken, ['search' => self::marker(), 'limit' => 100]);
        $items  = [];

        foreach ($listed->items() as $row) { $items[(int) $row['id']] = $row; }

        foreach ([self::$alpha, self::$delta] as $id) {
            self::assertArrayHasKey($id, $items, 'A fixture post is missing from the listing.');
            self::assertArrayHasKey('date', $items[$id], 'list-posts items carry no date.');
            self::assertArrayHasKey('modified', $items[$id], 'list-posts items carry no modified.');

            $post = $this->mcp(self::$adminToken)->callTool('get-post', ['id' => $id]);
            self::assertFalse($post->isError, $post->text);

            self::assertSame($post->data()['date'], $items[$id]['date'], 'list-posts and get-post disagree about date.');
            self::assertSame($post->data()['modified'], $items[$id]['modified'], 'list-posts and get-post disagree about modified.');
            self::assertMatchesRegularExpression('/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}/', (string) $items[$id]['date']);
        }
    }

    private function listing(string $token, array $args): ToolResult
    {
        $result = $this->mcp($token)->callTool('list-posts', $args);

        self::assertFalse(
            $result->isError,
            'list-posts(' . json_encode($args) . ') failed: ' . $result->text
        );

        return $result;
    }

    /**
     * A filter that must find NOTHING: no error, no items, count 0, and no trace of the
     * thing it was pointed at anywhere in the response.
     */
    private function assertFindsNothing(string $token, array $args, string $mustNotAppear = ''): void
    {
        $result = $this->mcp($token)->callTool('list-posts', $args);

        self::assertFalse(
            $result->isError,
            'A filter naming something the caller may not see was REFUSED rather than'
            . ' answered with an empty list, which tells the caller it exists. Args: '
            . json_encode($args) . ' Response: ' . $result->text
        );
        self::assertSame(
            [],
            $result->items(),
            'A filter naming something the caller may not see returned it. Args: '
            . json_encode($args)
        );
        self::assertSame(0, $result->data()['count']);
        self::assertFalse($result->data()['has_more'], 'An empty page claimed there was more.');

        if ($mustNotAppear !== '') {
            self::assertStringNotContainsString($mustNotAppear, $result->text);
        }
    }

    /* ========================================================================
     * search
     * ==================================================================== */

    /**
     * `search` finds the one seeded post that carries the needle, and leaves the four
     * that carry only the shared marker behind.
     *
     * @group sprint-10
     */
    public function testSearchMatchesContentAndExcludesTheRest(): void
    {
        $result = $this->listing(self::$authorToken, ['search' => self::needle(), 'limit' => 100]);

        self::assertSame([self::$alpha], $result->column('id'));

        // And the marker selects the whole set, so the search above narrowed rather
        // than simply failing to match anything.
        $all = $this->listing(self::$authorToken, ['search' => self::marker(), 'limit' => 100]);
        self::assertCount(5, $all->items(), 'The shared marker should select all five.');
    }

    /**
     * `search` also matches the TITLE, which is the half a content-only search would
     * quietly lose. No post's content contains the title fragment.
     *
     * @group sprint-10
     */
    public function testSearchMatchesTitlesToo(): void
    {
        $result = $this->listing(self::$authorToken, ['search' => 'aa-alpha', 'limit' => 100]);

        self::assertContains(self::$alpha, $result->column('id'));
        self::assertNotContains(self::$bravo, $result->column('id'));
    }

    /**
     * THE CAPABILITY NEGATIVE. The secret appears in exactly one post's content: the
     * hidden user's PRIVATE one. An Author searching for it gets an empty list - not a
     * refusal, which would confirm that a post containing it exists.
     *
     * @group sprint-10
     */
    public function testSearchDoesNotReachAnotherUsersPrivatePost(): void
    {
        $this->assertFindsNothing(
            self::$authorToken,
            ['search' => self::secret(), 'limit' => 100],
            (string) self::$hiddenPrivate
        );
        $this->assertFindsNothing(self::$subToken, ['search' => self::secret(), 'limit' => 100]);

        // The control: an admin token searching the same string finds it, so the
        // emptiness above is the capability gate and not a broken search.
        $admin = $this->listing(self::$adminToken, ['search' => self::secret(), 'limit' => 100]);
        self::assertSame([self::$hiddenPrivate], $admin->column('id'));
    }

    /* ========================================================================
     * category / tag
     * ==================================================================== */

    /**
     * `category` by slug and by id select the same two posts, and exclude the three in
     * the wider set.
     *
     * @group sprint-10
     */
    public function testCategoryFiltersBySlugAndById(): void
    {
        $bySlug = $this->listing(self::$authorToken, ['category' => Fixtures::name('cat-a'), 'limit' => 100]);
        $byId   = $this->listing(self::$authorToken, ['category' => (string) self::$catA, 'limit' => 100]);

        self::assertSame([self::$alpha, self::$echo], $bySlug->column('id'));
        self::assertSame($bySlug->column('id'), $byId->column('id'));
        self::assertNotContains(self::$bravo, $bySlug->column('id'));
    }

    /**
     * `tag` by slug and by id, the same way.
     *
     * @group sprint-10
     */
    public function testTagFiltersBySlugAndById(): void
    {
        $bySlug = $this->listing(self::$authorToken, ['tag' => Fixtures::name('tag-a'), 'limit' => 100]);
        $byId   = $this->listing(self::$authorToken, ['tag' => (string) self::$tagA, 'limit' => 100]);

        self::assertSame([self::$alpha, self::$echo], $bySlug->column('id'));
        self::assertSame($bySlug->column('id'), $byId->column('id'));
    }

    /**
     * THE CAPABILITY NEGATIVE for both. `cat-secret` holds one post - the hidden user's
     * private one - and `cat-draft` holds one draft. An Author filtering on either gets
     * nothing, with no hint that the category is not simply empty.
     *
     * @group sprint-10
     */
    public function testACategoryHoldingOnlyAnotherUsersUnreadablePostIsEmpty(): void
    {
        foreach ([self::$authorToken, self::$subToken] as $token) {
            $this->assertFindsNothing($token, ['category' => Fixtures::name('cat-secret'), 'limit' => 100]);
            $this->assertFindsNothing($token, ['category' => Fixtures::name('cat-draft'), 'limit' => 100]);
            $this->assertFindsNothing($token, ['tag' => Fixtures::name('tag-b'), 'limit' => 100]);
        }

        $admin = $this->listing(self::$adminToken, ['category' => Fixtures::name('cat-secret'), 'limit' => 100]);
        self::assertSame([self::$hiddenPrivate], $admin->column('id'));

        $adminDraft = $this->listing(self::$adminToken, ['category' => Fixtures::name('cat-draft'), 'limit' => 100]);
        self::assertSame([self::$hiddenDraft], $adminDraft->column('id'));
    }

    /**
     * A category that does not exist is an empty list and not an error - the same
     * answer a real category holding nothing gives, which is the whole point.
     *
     * @group sprint-10
     */
    public function testAnUnknownCategoryIsEmptyRatherThanAnError(): void
    {
        $this->assertFindsNothing(self::$authorToken, ['category' => Fixtures::name('no-such-category')]);
        $this->assertFindsNothing(self::$authorToken, ['tag' => Fixtures::name('no-such-tag')]);
    }

    /**
     * A CATEGORY ON A POST TYPE THAT HAS NO CATEGORIES is an empty list, and NOT a
     * dropped filter.
     *
     * The tempting claim - that WP_Query walks past `cat` here, so an unchecked filter
     * returns every page on the site - is false, and was measured: WP_Query builds the
     * term_relationships join regardless and matches nothing. What this pins is the shape
     * that IS a real failure and that the `catmiss` mutation produces: resolving the term,
     * failing, and carrying on WITHOUT the filter, which turns a narrow question into the
     * whole unfiltered listing.
     *
     * The control is the same page listing WITHOUT the category, which does find the
     * seeded page - so the empty result is the filter and not a broken post_type.
     *
     * @group sprint-10
     */
    public function testACategoryOnAPostTypeThatHasNoneFindsNothingRatherThanEverything(): void
    {
        $control = $this->listing(self::$authorToken, [
            'post_type' => 'page',
            'search'    => Fixtures::name('ff-page'),
            'limit'     => 100,
        ]);
        self::assertSame([self::$page], $control->column('id'), 'The page fixture is not listable at all.');

        $filtered = $this->mcp(self::$authorToken)->callTool('list-posts', [
            'post_type' => 'page',
            'search'    => Fixtures::name('ff-page'),
            'category'  => Fixtures::name('cat-a'),
            'limit'     => 100,
        ]);

        self::assertFalse($filtered->isError, $filtered->text);
        self::assertSame(
            [],
            $filtered->items(),
            'A category filter on a post type with no category taxonomy returned posts.'
            . ' WP_Query ignores `cat` there, so an unchecked filter answers with the'
            . ' whole unfiltered listing and the caller believes it was narrowed.'
        );
    }

    /* ========================================================================
     * term (any taxonomy)
     * ==================================================================== */

    /**
     * `term` as "taxonomy:slug" reaches a taxonomy that has no shorthand of its own,
     * and selects the same posts the shorthand does.
     *
     * @group sprint-10
     */
    public function testTermSelectsByTaxonomyAndSlug(): void
    {
        $result = $this->listing(self::$authorToken, [
            'term'  => 'post_tag:' . Fixtures::name('tag-a'),
            'limit' => 100,
        ]);

        self::assertSame([self::$alpha, self::$echo], $result->column('id'));
    }

    /**
     * A NON-VIEWABLE TAXONOMY IS AN EMPTY LIST, and this is the shape where that costs
     * something: the private taxonomy is on `post`, its term is on aa-alpha, and
     * aa-alpha is PUBLISHED. WP_Query would return it without hesitating. The ADMIN
     * token asks, so no capability anywhere is doing the refusing - only
     * is_taxonomy_viewable() is.
     *
     * The control reads the term's members straight out of the database through
     * get_objects_in_term(), so "the listing was empty" cannot be the seeding having
     * quietly failed.
     *
     * Four more shapes with the same answer: a core taxonomy that is not viewable
     * (nav_menu), one that is viewable but not attached to this post type, one nobody
     * registered, and a `term` with no colon in it at all.
     *
     * @group sprint-10
     */
    public function testATermInANonViewableOrUnattachedTaxonomyIsEmpty(): void
    {
        self::assertSame(
            [self::$alpha],
            Fixtures::postIdsInTerm(self::hiddenTaxonomy(), self::$hiddenTerm),
            'The private-taxonomy term does not hold the post it is supposed to hold, so'
            . ' the assertions below would come back empty for the wrong reason.'
        );
        $visible = $this->listing(self::$adminToken, ['category' => Fixtures::name('cat-a'), 'limit' => 100]);
        self::assertContains(
            self::$alpha,
            $visible->column('id'),
            'The admin token cannot see aa-alpha at all, so refusing to find it through a'
            . ' private taxonomy proves nothing.'
        );

        $this->assertFindsNothing(
            self::$adminToken,
            ['term' => self::hiddenTaxonomy() . ':' . self::hiddenTermName(), 'limit' => 100],
            (string) self::$alpha
        );
        $this->assertFindsNothing(
            self::$authorToken,
            ['term' => self::hiddenTaxonomy() . ':' . self::hiddenTermName(), 'limit' => 100]
        );

        $this->assertFindsNothing(self::$authorToken, ['term' => 'nav_menu:' . Fixtures::name('menu')]);
        $this->assertFindsNothing(self::$adminToken, ['term' => 'nav_menu:' . Fixtures::name('menu')]);

        // Viewable, but not attached to `page`.
        $this->assertFindsNothing(self::$authorToken, [
            'post_type' => 'page',
            'term'      => 'category:' . Fixtures::name('cat-a'),
        ]);

        $this->assertFindsNothing(self::$authorToken, ['term' => 'not_a_taxonomy:' . Fixtures::name('cat-a')]);
        $this->assertFindsNothing(self::$authorToken, ['term' => Fixtures::name('cat-a')]);
        $this->assertFindsNothing(self::$authorToken, ['term' => 'category:' . Fixtures::name('no-such-term')]);
    }

    /**
     * THE CAPABILITY NEGATIVE for `term`: the tag that holds only the hidden user's
     * private post, reached through the generic argument rather than the shorthand.
     *
     * @group sprint-10
     */
    public function testTermDoesNotReachAnotherUsersPrivatePost(): void
    {
        $this->assertFindsNothing(
            self::$authorToken,
            ['term' => 'post_tag:' . Fixtures::name('tag-b'), 'limit' => 100],
            (string) self::$hiddenPrivate
        );

        $admin = $this->listing(self::$adminToken, [
            'term'  => 'post_tag:' . Fixtures::name('tag-b'),
            'limit' => 100,
        ]);
        self::assertSame([self::$hiddenPrivate], $admin->column('id'));
    }

    /* ========================================================================
     * author
     * ==================================================================== */

    /**
     * `author` by login and by id select that author's posts - and NOT the caller's own
     * draft.
     *
     * THE SECOND HALF IS THE ONE THAT BITES. list-posts runs a second query scoped to
     * the current user to reach their own unpublished work. If an `author` filter
     * naming somebody else does not switch that query off, an Author asking for the
     * Editor's posts is handed their own drafts as part of the answer - a filter
     * returning what was not asked for, which is the same failure as a leak seen from
     * the other side.
     *
     * @group sprint-10
     */
    public function testAuthorFiltersByLoginAndByIdAndDoesNotAddTheCallersOwnDrafts(): void
    {
        $byLogin = $this->listing(self::$authorToken, [
            'author'   => Fixtures::name('editor'),
            'category' => Fixtures::name('set'),
            'limit'    => 100,
        ]);
        $byId = $this->listing(self::$authorToken, [
            'author'   => (string) self::$editorId,
            'category' => Fixtures::name('set'),
            'limit'    => 100,
        ]);

        self::assertSame([self::$bravo, self::$charlie], $byLogin->column('id'));
        self::assertSame($byLogin->column('id'), $byId->column('id'));
        self::assertNotContains(
            self::$delta,
            $byLogin->column('id'),
            'Filtering by another author returned the CALLER\'s own draft. The'
            . ' author-scoped second query has to switch itself off when the filter'
            . ' names somebody else.'
        );
    }

    /**
     * Filtering by the caller's own login still reaches their own draft, so the rule
     * above is a guard and not a blanket "no drafts when an author filter is present".
     *
     * @group sprint-10
     */
    public function testAuthorFilteringByOneselfStillIncludesOnesOwnDraft(): void
    {
        $result = $this->listing(self::$authorToken, [
            'author'   => Fixtures::name('author'),
            'category' => Fixtures::name('set'),
            'limit'    => 100,
        ]);

        self::assertSame([self::$delta, self::$alpha, self::$echo], $result->column('id'));
    }

    /**
     * THE CAPABILITY NEGATIVE. The hidden user has published nothing; an Author
     * filtering by their login gets an empty list, and the login is not echoed back.
     *
     * An unknown login answers identically, which is what stops list-posts being a
     * user-enumeration oracle.
     *
     * @group sprint-10
     */
    public function testAuthorFilterOnAUserWithNothingVisibleIsEmptyAndSoIsAnUnknownOne(): void
    {
        foreach ([self::$authorToken, self::$subToken] as $token) {
            $this->assertFindsNothing($token, ['author' => Fixtures::name('hidden'), 'limit' => 100]);
            $this->assertFindsNothing($token, ['author' => Fixtures::name('no-such-user'), 'limit' => 100]);
            $this->assertFindsNothing($token, ['author' => '88888888', 'limit' => 100]);
        }

        $admin = $this->listing(self::$adminToken, ['author' => Fixtures::name('hidden'), 'limit' => 100]);
        self::assertSame(
            [self::$hiddenDraft, self::$hiddenPrivate],
            $admin->column('id'),
            'The admin token cannot see the hidden user\'s posts either, so the empty'
            . ' results above prove nothing.'
        );
    }

    /* ========================================================================
     * after / before
     * ==================================================================== */

    /**
     * A date window selects the posts inside it, and both bounds are INCLUSIVE to the
     * whole day when only a date is given.
     *
     * @group sprint-10
     */
    public function testAfterAndBeforeSelectAWindowAndAreInclusive(): void
    {
        $window = $this->listing(self::$authorToken, [
            'category' => Fixtures::name('set'),
            'after'    => '2021-05-01',
            'before'   => '2021-08-01',
            'limit'    => 100,
        ]);
        self::assertSame([self::$alpha, self::$echo], $window->column('id'));

        // cc-charlie is the oldest of the five, stored at 2021-03-02 10:00:00. A
        // date-only bound on its own day has to contain it from BOTH directions, or
        // `before` silently means "before midnight that morning" and drops a whole day
        // the caller asked for.
        $onFrom = $this->listing(self::$authorToken, [
            'category' => Fixtures::name('set'),
            'after'    => '2021-03-02',
            'limit'    => 100,
        ]);
        self::assertContains(self::$charlie, $onFrom->column('id'));
        self::assertCount(5, $onFrom->items());

        $onTo = $this->listing(self::$authorToken, [
            'category' => Fixtures::name('set'),
            'before'   => '2021-03-02',
            'limit'    => 100,
        ]);
        self::assertSame([self::$charlie], $onTo->column('id'));
    }

    /**
     * A full datetime bound cuts inside a day - the part a date-only bound cannot
     * express, and the part that proves the time is not being thrown away.
     *
     * @group sprint-10
     */
    public function testADatetimeBoundCutsInsideTheDay(): void
    {
        $before = $this->listing(self::$authorToken, [
            'category' => Fixtures::name('set'),
            'before'   => '2021-03-02T09:59:59',
            'limit'    => 100,
        ]);
        self::assertSame([], $before->items(), 'A bound one second before the post still matched it.');

        $after = $this->listing(self::$authorToken, [
            'category' => Fixtures::name('set'),
            'before'   => '2021-03-02T10:00:00',
            'limit'    => 100,
        ]);
        self::assertSame([self::$charlie], $after->column('id'));
    }

    /**
     * A DATE THAT IS NOT A DATE IS AN ERROR, and that is the one place list-posts is
     * allowed to be loud: the caller has misused the protocol, the message discloses
     * nothing about the site, and an agent answered with an empty list would conclude
     * the site is empty and stop.
     *
     * `2021-13-45` is the case a naive strtotime() would silently roll over into 2022,
     * and `yesterday` is the case it would silently accept.
     *
     * @group sprint-10
     */
    public function testAMalformedDateIsRefusedRatherThanSilentlyMeaningSomethingElse(): void
    {
        foreach (['2021-13-45', 'yesterday', '03/02/2021', '2021-03-02T25:00:00', 'now'] as $bad) {
            $result = $this->mcp(self::$authorToken)->callTool('list-posts', ['after' => $bad]);

            self::assertTrue(
                $result->isError,
                "list-posts accepted after: '{$bad}'. A date filter that silently means"
                . ' something else is worse than no filter. Response: ' . $result->text
            );
            self::assertStringContainsString('ISO 8601', $result->text);
        }

        $before = $this->mcp(self::$authorToken)->callTool('list-posts', ['before' => 'tomorrow']);
        self::assertTrue($before->isError, $before->text);
        self::assertStringContainsString('before', $before->text);
    }

    /* ========================================================================
     * orderby / order, across the merge
     * ==================================================================== */

    /**
     * `orderby: "title", order: "asc"` ACROSS THE MERGE, with the caller's own draft in
     * the middle of the set.
     *
     * dd-delta comes from the second, author-scoped query. The merge comparator used to
     * be hard-coded to post_date descending, which was right only because there was
     * nothing else to ask for; with it hard-coded, dd-delta lands second here instead of
     * fourth and this assertion is red.
     *
     * @group sprint-10
     */
    public function testOrderByTitleAscendingHoldsAcrossTheMerge(): void
    {
        $result = $this->listing(self::$authorToken, [
            'category' => Fixtures::name('set'),
            'orderby'  => 'title',
            'order'    => 'asc',
            'limit'    => 100,
        ]);

        self::assertSame(
            ['aa-alpha', 'bb-bravo', 'cc-charlie', 'dd-delta', 'ee-echo'],
            $this->titles($result)
        );

        $descending = $this->listing(self::$authorToken, [
            'category' => Fixtures::name('set'),
            'orderby'  => 'title',
            'order'    => 'desc',
            'limit'    => 100,
        ]);

        self::assertSame(
            ['ee-echo', 'dd-delta', 'cc-charlie', 'bb-bravo', 'aa-alpha'],
            $this->titles($descending)
        );
    }

    /**
     * `orderby: "modified"` is a DIFFERENT answer from `orderby: "date"` on this set:
     * cc-charlie's post_modified is set latest and its post_date is the earliest, so it
     * sorts FIRST by modified and LAST by date, and the Author's own draft moves from second
     * to fourth between the two. Without that difference an orderby test proves only that
     * the argument was accepted.
     *
     * @group sprint-10
     */
    public function testOrderByModifiedIsNotTheSameAsOrderByDate(): void
    {
        $byModified = $this->listing(self::$authorToken, [
            'category' => Fixtures::name('set'),
            'orderby'  => 'modified',
            'limit'    => 100,
        ]);
        $byDate = $this->listing(self::$authorToken, [
            'category' => Fixtures::name('set'),
            'limit'    => 100,
        ]);

        self::assertSame(
            ['cc-charlie', 'ee-echo', 'bb-bravo', 'dd-delta', 'aa-alpha'],
            $this->titles($byModified),
            'orderby: "modified" did not follow post_modified across the merge.'
        );
        self::assertSame(
            ['bb-bravo', 'dd-delta', 'aa-alpha', 'ee-echo', 'cc-charlie'],
            $this->titles($byDate),
            'The default ordering is post_date descending.'
        );
    }

    /**
     * A SEARCH MUST NOT SILENTLY SWITCH THE ORDERING TO RELEVANCE.
     *
     * Asserted with the ADMIN token on purpose: an admin sees every status, so the
     * own-status query does not run and there is no merge to re-sort the rows
     * afterwards. What comes back is WP_Query's own ordering and nothing else. WP_Query
     * switches to relevance the moment `s` is present unless it is given an explicit
     * orderby, and relevance ties break on post_date DESC - which is the exact reverse
     * of the order asserted here.
     *
     * @group sprint-10
     */
    public function testASearchDoesNotSwitchTheOrderingToRelevance(): void
    {
        $result = $this->listing(self::$adminToken, [
            'search'  => self::marker(),
            'orderby' => 'title',
            'order'   => 'asc',
            'limit'   => 100,
        ]);

        self::assertSame(
            ['aa-alpha', 'bb-bravo', 'cc-charlie', 'dd-delta', 'ee-echo'],
            $this->titles($result)
        );
    }

    /**
     * An orderby or an order this tool does not offer is refused, and the refusal names
     * the argument.
     *
     * The `enum` in the inputSchema answers first over the wire, which is why the
     * message is the validator's; the tool's own guard behind it is what keeps the
     * promise true for a caller that reaches the run callable another way.
     *
     * @group sprint-10
     */
    public function testAnUnknownOrderByIsRefused(): void
    {
        foreach (['id', 'rand', 'meta_value', 'post_author'] as $bad) {
            $result = $this->mcp(self::$authorToken)->callTool('list-posts', ['orderby' => $bad]);

            self::assertTrue(
                $result->isError,
                "list-posts accepted orderby: '{$bad}'. Response: " . $result->text
            );
            self::assertStringContainsString('orderby', $result->text);
        }

        $order = $this->mcp(self::$authorToken)->callTool('list-posts', ['order' => 'sideways']);
        self::assertTrue($order->isError, $order->text);
        self::assertStringContainsString('order', $order->text);

        // THE CONTROL, and it is not decoration. Before this sprint `orderby` was not an
        // argument at all, so `additionalProperties: false` refused every value above -
        // including the three that are now legal - and the assertions passed while the
        // feature did not exist. This half is what makes the refusals mean "not one of
        // the three" rather than "not a thing you can ask for".
        foreach (['date', 'modified', 'title'] as $good) {
            $accepted = $this->listing(self::$authorToken, [
                'category' => Fixtures::name('set'),
                'orderby'  => $good,
                'order'    => 'asc',
                'limit'    => 100,
            ]);
            self::assertCount(5, $accepted->items(), "orderby: '{$good}' was refused.");
        }
    }

    /* ========================================================================
     * page / has_more
     * ==================================================================== */

    /**
     * `page` walks the merged set two at a time, and `has_more` flips on the last page.
     *
     * The set is five posts, one of which is the caller's own draft and therefore comes
     * from the second query, so this is paging ACROSS the merge: page 2 has to begin
     * where page 1 stopped even though the rows came from two places.
     *
     * @group sprint-10
     */
    public function testPageWalksTheMergedSetAndHasMoreFlipsAtTheEnd(): void
    {
        $expected = [
            1 => ['bb-bravo', 'dd-delta'],
            2 => ['aa-alpha', 'ee-echo'],
            3 => ['cc-charlie'],
        ];
        $seen = [];

        foreach ($expected as $page => $titles) {
            $result = $this->listing(self::$authorToken, [
                'category' => Fixtures::name('set'),
                'limit'    => 2,
                'page'     => $page,
            ]);

            self::assertSame($titles, $this->titles($result), "Page {$page} is wrong.");
            self::assertSame($page, $result->data()['page'], 'The result does not echo the page asked for.');
            self::assertSame(2, $result->data()['limit']);
            self::assertSame(
                $page !== 3,
                $result->data()['has_more'],
                "has_more is wrong on page {$page}."
            );

            $seen = array_merge($seen, $result->column('id'));
        }

        self::assertSame(
            $seen,
            array_values(array_unique($seen)),
            'A post appeared on two pages, so the slice and the ordering disagree.'
        );

        // AND WITH ONE QUERY BEHIND IT. The Author's listing is two queries whose rows
        // together over-supply the page, so it cannot see whether each query fetched the
        // extra row that `has_more` is read from. A Subscriber's listing is the
        // permitted-status query ALONE, and there are exactly four posts in `set` they
        // may see: at limit 2 the first page is full, and only that extra row can tell it
        // apart from the last one. Fetch page*limit rows instead of page*limit+1 and a
        // full first page reports has_more: false, so a client stops a page early with
        // half the answer and no way to know.
        $firstOfTwo = $this->listing(self::$subToken, [
            'category' => Fixtures::name('set'),
            'limit'    => 2,
            'page'     => 1,
        ]);
        self::assertCount(2, $firstOfTwo->items());
        self::assertTrue(
            $firstOfTwo->data()['has_more'],
            'A full first page of a four-post listing said there was nothing after it.'
        );

        $lastOfTwo = $this->listing(self::$subToken, [
            'category' => Fixtures::name('set'),
            'limit'    => 2,
            'page'     => 2,
        ]);
        self::assertCount(2, $lastOfTwo->items());
        self::assertFalse(
            $lastOfTwo->data()['has_more'],
            'The last full page claimed there was another one.'
        );
        self::assertSame(
            [],
            array_intersect($firstOfTwo->column('id'), $lastOfTwo->column('id')),
            'The two pages of a single-query listing overlap.'
        );

        // Past the end: an empty page, and nothing claiming to follow it.
        $beyond = $this->listing(self::$authorToken, [
            'category' => Fixtures::name('set'),
            'limit'    => 2,
            'page'     => 9,
        ]);
        self::assertSame([], $beyond->items());
        self::assertFalse($beyond->data()['has_more']);
    }

    /**
     * NO TOTAL IS RETURNED, on any page. A total is a count of posts the caller has not
     * been shown, and on the own-status side it would be a count of somebody's drafts.
     *
     * @group sprint-10
     */
    public function testTheListingReportsHasMoreAndNeverATotal(): void
    {
        $data = $this->listing(self::$authorToken, [
            'category' => Fixtures::name('set'),
            'limit'    => 2,
        ])->data();

        self::assertSame(
            ['count', 'page', 'limit', 'has_more', 'items'],
            array_keys($data),
            'list-posts returned a field nobody asked for. A `total` or `found` here is'
            . ' a count of posts the caller cannot see.'
        );
        self::assertSame(2, $data['count'], 'count is the size of THIS page.');
    }

    /**
     * A STICKY POST IS NOT SPLICED PAST A DATE WINDOW OR A STATUS.
     *
     * WP_Query decides `is_home` from the QUERY VARS: `s`, `cat`, `tag_id`, `tax_query` and
     * `author` all mark a query as something else, but `date_query`, `post_status`, `orderby`
     * and `posts_per_page` mark NOTHING. So a listing filtered only by `after`/`before`,
     * `status` or `orderby` is a home query - and on a home query at page one, core fetches
     * every sticky post by `post__in` with `post_status => 'publish'` and splices it in at
     * the front, carrying none of the original query's conditions. list-posts never passes
     * `paged`, so page one is every page.
     *
     * The answer that came back was not a permission leak - stickies are published - it was
     * a FALSE ANSWER TO THE QUESTION ASKED, which is the failure this whole tool is built
     * not to have: `after: "2030-01-01"` returned posts from 2021, and `status: "draft"`
     * returned published ones.
     *
     * Three shapes, all with cc-charlie stuck: an empty window, a NON-empty window that
     * cc-charlie is outside of, and a status it does not have. The middle one is the
     * strongest - it proves the filter still returns things while not returning that.
     *
     * @group sprint-10
     */
    public function testAStickyPostIsNotInjectedPastADateWindowOrAStatus(): void
    {
        self::assertContains(
            self::$charlie,
            Fixtures::stickyIds(),
            'The fixture is not actually sticky, so nothing below is being tested.'
        );

        // (a) An empty window. Every one of these listings is a home query.
        $this->assertFindsNothing(
            self::$subToken,
            ['after' => '2030-01-01', 'limit' => 100],
            (string) self::$charlie
        );

        // (b) A window with content in it that the sticky post is OUTSIDE of. ee-echo is
        // 2021-05-04 and cc-charlie is 2021-03-02, so the answer must hold one and not the
        // other - a filter that returns nothing would pass (a) and fail here. Three days
        // wide, and the listing is NOT scoped by a category on purpose (a category would
        // make it an archive query and the splice would never have fired); three days keeps
        // the stress site's own content inside the row limit so ee-echo cannot fall off.
        $may = $this->listing(self::$subToken, [
            'after'  => '2021-05-03',
            'before' => '2021-05-05',
            'limit'  => 100,
        ]);
        self::assertContains(self::$echo, $may->column('id'), 'The date window returned nothing of ours.');
        self::assertNotContains(
            self::$charlie,
            $may->column('id'),
            'A sticky post dated outside the window was returned inside it.'
        );

        // (c) A status it does not have, on a token that may see drafts. The sticky post is
        // published; before this it arrived anyway, in front of the drafts that were asked
        // for.
        $drafts = $this->listing(self::$adminToken, ['status' => 'draft', 'limit' => 100]);
        self::assertNotContains(
            self::$charlie,
            $drafts->column('id'),
            'A published sticky post was returned by status: "draft".'
        );

        // And it is still perfectly findable, in its natural date position - last of the four
        // a Subscriber may see in `set`, not first because it is stuck.
        $set = $this->listing(self::$subToken, ['category' => Fixtures::name('set'), 'limit' => 100]);
        self::assertSame(
            [self::$bravo, self::$alpha, self::$echo, self::$charlie],
            $set->column('id'),
            'The sticky post is not in its date position in an ordinary listing.'
        );
    }

    /**
     * PAGING OVER ROWS TIED ON THE SORT COLUMN IS STABLE, because the SQL tie-breaks on ID.
     *
     * The merge comparator has always tie-broken on ID, but the comparator only ever sees
     * the rows the `LIMIT` already chose. Page one runs `LIMIT 2` and page two `LIMIT 3`
     * (the depth fetch is page * limit + 1): with `ORDER BY post_date DESC` alone, those are
     * two statements MySQL may answer with a different relative order - and a different
     * SUBSET - of any group of rows tied on that column, so a caller paging through an
     * import could see one row twice and another never.
     *
     * Two posts share a post_date to the second here. The assertion is the ORDER, in both
     * directions: `ID` is unique, so `post_date DESC, ID DESC` puts the later-created post
     * first and `ASC, ASC` puts it last. Without the secondary key the two calls have no
     * reason to differ, and the tied group's order is whatever the access path yields.
     *
     * @group sprint-10
     */
    public function testPagingOverTiedDatesIsStableInBothDirections(): void
    {
        self::assertGreaterThan(
            self::$tieOne,
            self::$tieTwo,
            'The tie fixtures were not created in ascending id order, so the expectations'
            . ' below are the wrong way round.'
        );

        $descending = [];
        $ascending  = [];

        for ($page = 1; $page <= 2; $page++) {
            $desc = $this->listing(self::$subToken, [
                'category' => Fixtures::name('cat-tie'),
                'limit'    => 1,
                'page'     => $page,
            ]);
            $asc = $this->listing(self::$subToken, [
                'category' => Fixtures::name('cat-tie'),
                'order'    => 'asc',
                'limit'    => 1,
                'page'     => $page,
            ]);

            self::assertCount(1, $desc->items(), "Descending page {$page} is not one item.");
            self::assertCount(1, $asc->items(), "Ascending page {$page} is not one item.");

            $descending[] = $desc->column('id')[0];
            $ascending[]  = $asc->column('id')[0];
        }

        self::assertSame(
            [self::$tieTwo, self::$tieOne],
            $descending,
            'Two posts tied on post_date did not page in descending ID order. Either a row'
            . ' appeared on both pages or the pages disagree about which row is first.'
        );
        self::assertSame(
            [self::$tieOne, self::$tieTwo],
            $ascending,
            'The same two rows did not reverse when the direction did, so the ordering is'
            . ' not being decided by the tie-break at all.'
        );

        // Neither direction lost a row or showed one twice.
        self::assertSame([self::$tieOne, self::$tieTwo], $this->sorted($descending));
        self::assertSame([self::$tieOne, self::$tieTwo], $this->sorted($ascending));
    }

    /* ========================================================================
     * The subscriber sweep
     * ==================================================================== */

    /**
     * EVERY FILTER, AS A SUBSCRIBER. A subscriber holds no editing capability at all,
     * so the author-scoped second query never runs for them: the only thing standing
     * between them and the four unpublished fixtures is the status split, and every one
     * of these filters has to leave it alone.
     *
     * The published posts still come back, so this is not passing because the token is
     * broken.
     *
     * @group sprint-10
     */
    public function testEveryFilterAsASubscriberShowsPublishedPostsAndNoUnpublishedOnes(): void
    {
        $hidden = [self::$delta, self::$hiddenPrivate, self::$hiddenDraft];

        // EVERY CALL CARRIES THE IDS IT MUST RETURN, not just the ids it must not. The
        // sweep-table row for a Subscriber says "4 published, no draft"; asserting only the
        // second half would let a filter that returns NOTHING pass every line of it.
        $four = [self::$bravo, self::$alpha, self::$echo, self::$charlie];

        $calls = [
            [['category' => Fixtures::name('set'), 'limit' => 100], $four],
            [['search' => self::marker(), 'limit' => 100], $four],
            [['term' => 'category:' . Fixtures::name('set'), 'limit' => 100], $four],
            [['category' => Fixtures::name('set'), 'orderby' => 'title', 'order' => 'asc', 'limit' => 100],
                [self::$alpha, self::$bravo, self::$charlie, self::$echo]],
            [['category' => Fixtures::name('set'), 'orderby' => 'modified', 'limit' => 100],
                [self::$charlie, self::$echo, self::$bravo, self::$alpha]],
            [['category' => Fixtures::name('set'), 'after' => '2020-01-01', 'limit' => 100], $four],
            [['category' => Fixtures::name('set'), 'before' => '2030-01-01', 'limit' => 100], $four],
            [['category' => Fixtures::name('set'), 'limit' => 2, 'page' => 2], [self::$echo, self::$charlie]],
            [['author' => Fixtures::name('author'), 'limit' => 100], [self::$alpha, self::$echo]],
            [['tag' => Fixtures::name('tag-a'), 'limit' => 100], [self::$alpha, self::$echo]],
            [['term' => 'post_tag:' . Fixtures::name('tag-a'), 'limit' => 100], [self::$alpha, self::$echo]],
        ];

        foreach ($calls as [$args, $expected]) {
            $result = $this->listing(self::$subToken, $args);

            self::assertSame(
                $expected,
                $result->column('id'),
                'A Subscriber\'s list-posts(' . json_encode($args) . ') returned the wrong set.'
            );

            foreach ($hidden as $id) {
                self::assertNotContains(
                    $id,
                    $result->column('id'),
                    'A Subscriber was shown an unpublished post through list-posts('
                    . json_encode($args) . ').'
                );
            }
        }

        // The refusals, as a Subscriber: the sweep table claims the same error for every
        // token, and until now only the Author's was measured.
        foreach ([['after' => 'yesterday'], ['orderby' => 'rand']] as $bad) {
            $refused = $this->mcp(self::$subToken)->callTool('list-posts', $bad);
            self::assertTrue($refused->isError, 'A Subscriber was not refused ' . json_encode($bad));
        }

        // And the empty answers, as a Subscriber.
        $this->assertFindsNothing(self::$subToken, ['term' => self::hiddenTaxonomy() . ':' . self::hiddenTermName()]);
        $this->assertFindsNothing(self::$subToken, ['category' => Fixtures::name('no-such-category')]);
    }

    /**
     * THE OTHER END OF EVERY NEGATIVE ABOVE, WITH AN EDITOR'S TOKEN. Each filter that
     * answers an Author with nothing answers an Editor with the post, because an Editor
     * holds `read_private_posts` and `edit_others_posts` and an Author holds neither.
     *
     * This is the column of the capability sweep that keeps the rest of the table
     * honest: without it every "empty" above is equally consistent with the filter
     * being broken.
     *
     * @group sprint-10
     */
    public function testAnEditorSeesEverythingTheSameFiltersHideFromAnAuthor(): void
    {
        $cases = [
            'category'       => [['category' => Fixtures::name('cat-secret'), 'limit' => 100], [self::$hiddenPrivate]],
            'category-draft' => [['category' => Fixtures::name('cat-draft'), 'limit' => 100], [self::$hiddenDraft]],
            'tag'            => [['tag' => Fixtures::name('tag-b'), 'limit' => 100], [self::$hiddenPrivate]],
            'term'           => [['term' => 'post_tag:' . Fixtures::name('tag-b'), 'limit' => 100], [self::$hiddenPrivate]],
            'search'         => [['search' => self::secret(), 'limit' => 100], [self::$hiddenPrivate]],
            'author'         => [['author' => Fixtures::name('hidden'), 'limit' => 100], [self::$hiddenDraft, self::$hiddenPrivate]],
        ];

        foreach ($cases as $what => [$args, $expected]) {
            $result = $this->listing(self::$editorToken, $args);

            self::assertSame(
                $expected,
                $result->column('id'),
                "An Editor's {$what} filter did not return what an Author is refused."
            );
        }

        // And another author's DRAFT is in an Editor's ordinary filtered listing - the
        // one thing the Author's own token sees only for its own drafts.
        $set = $this->listing(self::$editorToken, ['category' => Fixtures::name('set'), 'limit' => 100]);
        self::assertSame(
            ['bb-bravo', 'dd-delta', 'aa-alpha', 'ee-echo', 'cc-charlie'],
            $this->titles($set)
        );

        // A DATE WINDOW AND A PAGE, as an Editor. Both were sweep-table cells this test
        // could have filled and did not; an Editor sees the other author's draft in both,
        // which is the whole difference from the Subscriber row.
        $window = $this->listing(self::$editorToken, [
            'category' => Fixtures::name('set'),
            'after'    => '2021-05-01',
            'before'   => '2021-10-01',
            'limit'    => 100,
        ]);
        self::assertSame([self::$delta, self::$alpha, self::$echo], $window->column('id'));

        $firstPage = $this->listing(self::$editorToken, [
            'category' => Fixtures::name('set'),
            'limit'    => 2,
            'page'     => 1,
        ]);
        self::assertSame([self::$bravo, self::$delta], $firstPage->column('id'));
        self::assertTrue($firstPage->data()['has_more']);

        // The empty answers and the refusals, as an Editor. Same three rows of the table.
        $this->assertFindsNothing(self::$editorToken, ['term' => self::hiddenTaxonomy() . ':' . self::hiddenTermName()]);
        $this->assertFindsNothing(self::$editorToken, ['author' => Fixtures::name('no-such-user')]);
        $this->assertFindsNothing(self::$editorToken, ['category' => Fixtures::name('no-such-category')]);

        foreach ([['after' => '2021-13-45'], ['orderby' => 'meta_value']] as $bad) {
            $refused = $this->mcp(self::$editorToken)->callTool('list-posts', $bad);
            self::assertTrue($refused->isError, 'An Editor was not refused ' . json_encode($bad));
        }

        // get-post's editorial field, for the same reason: an Editor may edit somebody
        // else's post, so they get the revision count a Subscriber is refused - and the
        // rest of the fields, which the sweep table claimed and nothing measured.
        $post = $this->mcp(self::$editorToken)->callTool('get-post', ['id' => self::$echo]);
        self::assertFalse($post->isError, $post->text);
        $data = $post->data();
        self::assertSame(Fixtures::revisionCount(self::$echo), $data['revisions']);
        self::assertSame(self::excerptText(), $data['excerpt']);
        self::assertSame(self::displayName(), $data['author']['name']);
        self::assertSame('2021-05-04T10:00:00', $data['date']);
        self::assertIsString($data['modified']);
        self::assertSame(self::$attachment, $data['featured_image']['id']);
        self::assertSame(self::$postTaxonomies, array_keys($data['terms']));
    }

    /* ========================================================================
     * get-post
     * ==================================================================== */

    /**
     * Every new get-post field on the seeded post that has all of them: an excerpt, a
     * category, a tag, a featured image and two revisions.
     *
     * @group sprint-10
     */
    public function testGetPostReturnsTheNewFields(): void
    {
        $data = $this->mcp(self::$authorToken)->callTool('get-post', ['id' => self::$echo]);
        self::assertFalse($data->isError, 'get-post failed: ' . $data->text);
        $post = $data->data();

        self::assertSame(self::excerptText(), $post['excerpt']);

        // The link is the same one list-posts gives for this id - two code paths, one
        // permalink, so a change to either is visible here.
        $listed = $this->listing(self::$authorToken, ['search' => 'ee-echo', 'limit' => 100]);
        self::assertSame($listed->items()[0]['link'], $post['link']);

        self::assertSame(self::$authorId, $post['author']['id']);
        self::assertSame(self::displayName(), $post['author']['name']);
        self::assertStringNotContainsString(
            Fixtures::name('author'),
            $data->text,
            'get-post returned the author LOGIN. A login is half of a credential and'
            . ' wp-admin shows a display name for exactly that reason.'
        );

        self::assertSame(self::$attachment, $post['featured_image']['id']);
        self::assertStringContainsString(
            self::attachedFile(),
            (string) $post['featured_image']['url'],
            'The featured image URL does not point at the attached file.'
        );

        self::assertSame(
            self::$postTaxonomies,
            array_keys($post['terms']),
            'get-post reported a taxonomy that is not both attached to this post type'
            . ' and viewable, or lost one that is.'
        );
        self::assertSame(
            [Fixtures::name('cat-a'), Fixtures::name('set')],
            array_column($post['terms']['category'], 'slug')
        );
        self::assertSame(
            [['id' => self::$catA, 'name' => Fixtures::name('cat-a'), 'slug' => Fixtures::name('cat-a')]],
            array_values(array_filter(
                $post['terms']['category'],
                static fn (array $t) => $t['slug'] === Fixtures::name('cat-a')
            )),
            'A term entry is not {id, name, slug}.'
        );
        self::assertSame(
            [Fixtures::name('tag-a')],
            array_column($post['terms']['post_tag'], 'slug')
        );

        self::assertSame(
            Fixtures::revisionCount(self::$echo),
            $post['revisions'],
            'The revision count disagrees with the site\'s own.'
        );
        self::assertGreaterThanOrEqual(
            2,
            $post['revisions'],
            'The fixture was rewritten twice, so there should be revisions to count.'
        );
    }

    /**
     * A post with no featured image says so, rather than leaving the field out or
     * inventing a zero.
     *
     * @group sprint-10
     */
    public function testAPostWithNoFeaturedImageReportsNull(): void
    {
        $post = $this->mcp(self::$authorToken)->callTool('get-post', ['id' => self::$alpha]);
        self::assertFalse($post->isError, $post->text);

        self::assertArrayHasKey('featured_image', $post->data());
        self::assertNull($post->data()['featured_image']);
        self::assertSame('', $post->data()['excerpt'], 'An unset excerpt should be empty, not absent.');
    }

    /**
     * REVISIONS ARE EDITORIAL DATA. A Subscriber may read the published post and gets
     * null; the author of it may edit it and gets the number. Null rather than 0: a 0
     * would be a claim about a post's history that the caller has no standing to be
     * told, and one that happens to be false here.
     *
     * @group sprint-10
     */
    public function testRevisionsAreNullForACallerWhoMayReadButNotEdit(): void
    {
        $reader = $this->mcp(self::$subToken)->callTool('get-post', ['id' => self::$echo]);
        self::assertFalse($reader->isError, 'A Subscriber could not read a published post: ' . $reader->text);

        self::assertArrayHasKey('revisions', $reader->data());
        self::assertNull(
            $reader->data()['revisions'],
            'A Subscriber was told how many times a post had been rewritten.'
        );

        // AND THE REST OF THE ROW. The sweep table says a Subscriber gets every other new
        // field on a post they may read; `revisions` alone was the only thing measured, so
        // "returned" was a claim about six fields that no assertion touched.
        $data = $reader->data();
        self::assertSame(self::excerptText(), $data['excerpt']);
        self::assertStringContainsString('http', (string) $data['link']);
        self::assertSame(self::$authorId, $data['author']['id']);
        self::assertSame(self::displayName(), $data['author']['name']);
        self::assertSame('2021-05-04T10:00:00', $data['date']);
        self::assertIsString($data['date_gmt']);
        self::assertIsString($data['modified']);
        self::assertSame(self::$attachment, $data['featured_image']['id']);
        self::assertSame(self::$postTaxonomies, array_keys($data['terms']));
        self::assertSame(
            [Fixtures::name('cat-a'), Fixtures::name('set')],
            array_column($data['terms']['category'], 'slug')
        );

        $editor = $this->mcp(self::$authorToken)->callTool('get-post', ['id' => self::$echo]);
        self::assertIsInt(
            $editor->data()['revisions'],
            'The post\'s own author gets no revision count either, so the null above is'
            . ' not a capability decision.'
        );
    }

    /**
     * A DRAFT'S GMT COLUMNS ARE NOT DATES, and are reported as null rather than as the
     * year 1 BC.
     *
     * Every date-floating status is stored with post_date_gmt AND post_modified_gmt =
     * '0000-00-00 00:00:00'; only the non-GMT columns are populated. Formatting that as
     * a date produces '-0001-11-30T00:00:00', which a client parses without complaint.
     *
     * @group sprint-10
     */
    public function testADraftsGmtDatesAreNullAndItsLocalDatesAreNot(): void
    {
        $draft = $this->mcp(self::$authorToken)->callTool('get-post', ['id' => self::$delta]);
        self::assertFalse($draft->isError, 'The Author could not read their own draft: ' . $draft->text);
        $data = $draft->data();

        self::assertNull($data['date_gmt'], 'A draft reported a GMT date. Got: ' . var_export($data['date_gmt'], true));
        self::assertNull($data['modified_gmt']);
        self::assertSame('2021-09-08T10:00:00', $data['date'], 'The local post_date is wrong or missing.');
        self::assertIsString($data['modified']);
        self::assertStringStartsNotWith('-', (string) $data['modified']);

        // A published post has all four, so the nulls above are the columns and not the
        // formatter giving up.
        $published = $this->mcp(self::$authorToken)->callTool('get-post', ['id' => self::$alpha]);
        $pub       = $published->data();
        self::assertSame('2021-07-06T10:00:00', $pub['date']);
        self::assertIsString($pub['date_gmt']);
        self::assertIsString($pub['modified_gmt']);
    }

    /**
     * THE THREE REFUSALS ARE STILL ONE REFUSAL, and they still come first - before any
     * of the new fields are assembled. A missing id, a post the caller may not read,
     * and an id of a type this tool does not serve all answer identically, or get-post
     * is an existence oracle for every private draft on the site.
     *
     * @group sprint-10
     */
    public function testTheThreeRefusalsRemainByteIdentical(): void
    {
        $author = $this->mcp(self::$authorToken);

        $missing      = $author->callTool('get-post', ['id' => self::MISSING_ID]);
        $forbidden    = $author->callTool('get-post', ['id' => self::$hiddenPrivate]);
        $othersDraft  = $author->callTool('get-post', ['id' => self::$hiddenDraft]);
        $wrongType    = $this->mcp(self::$adminToken)->callTool('get-post', ['id' => self::$attachment]);

        $cases = [
            'missing'            => $missing,
            'forbidden private'  => $forbidden,
            "another's draft"    => $othersDraft,
            'wrong type'         => $wrongType,
        ];

        foreach ($cases as $what => $result) {
            self::assertTrue($result->isError, "get-post answered the {$what} case: " . $result->text);
            self::assertSame(
                $missing->text,
                $result->text,
                "get-post answers the {$what} case differently from a missing id, so ids"
                . ' can be probed for what is behind them.'
            );
        }
        self::assertStringNotContainsString(self::secret(), $forbidden->text);
        self::assertStringNotContainsString('revisions', $forbidden->text);
        self::assertStringNotContainsString('attachment', $wrongType->text);
    }
}
